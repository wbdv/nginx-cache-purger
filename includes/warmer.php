<?php
/**
 * Cache warmer.
 *
 * After a URL is purged its next visitor pays for regenerating it (a MISS).
 * When enabled, the warmer re-fetches purged URLs in the background so that cost
 * is paid by an anonymous bot request instead of a real visitor.
 *
 * Design notes:
 *  - Queue lives in a custom table with a UNIQUE hash column, so duplicate
 *    paths are dropped atomically (INSERT IGNORE) - that also makes concurrent
 *    writers safe without locking.
 *  - The worker drains the queue in small batches on a cron tick, so one run
 *    never fires dozens of blocking HTTP requests.
 *  - A full purge (/*) does NOT warm the whole sitemap - that would be a
 *    self-inflicted load spike. It warms a bounded set: the home page plus the
 *    most recent posts, capped by the warm_max_urls setting.
 *  - Fetches go through the same endpoint resolution as purges, so behind a
 *    proxy the localhost target warms nginx directly (see ngxcp_purge_endpoint).
 *
 * @package Nginx_Cache_Purger
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const NGXCP_WARM_CRON      = 'ngxcp_warm_event';
const NGXCP_WARM_SCHEDULE  = 'ngxcp_minute';
const NGXCP_WARM_BATCH     = 5;   // URLs warmed per cron tick.
const NGXCP_WARM_DB_VERSION = 1;

/**
 * Queue table name.
 *
 * @return string
 */
function ngxcp_warm_table() {
    global $wpdb;
    return $wpdb->prefix . 'ngxcp_warm_queue';
}

/**
 * Create the queue table. Called on activation.
 */
function ngxcp_warm_install_table() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table   = ngxcp_warm_table();
    $charset = $wpdb->get_charset_collate();

    // url_hash is md5(path): a fixed 32-char column so it can carry a UNIQUE
    // index without hitting MySQL's index-length limit on the full path.
    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        url_hash CHAR(32) NOT NULL,
        path TEXT NOT NULL,
        added_at INT UNSIGNED NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY url_hash (url_hash)
    ) {$charset};";

    dbDelta( $sql );
    update_option( 'ngxcp_warm_db_version', NGXCP_WARM_DB_VERSION );
}

/**
 * Drop the queue table. Called on uninstall (not deactivation).
 */
function ngxcp_warm_drop_table() {
    global $wpdb;
    $table = ngxcp_warm_table();
    // Table name is built from $wpdb->prefix, not user input; DDL on a custom
    // table cannot be cached or parameterised.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
    delete_option( 'ngxcp_warm_db_version' );
}

/**
 * Is the warmer switched on?
 *
 * @return bool
 */
function ngxcp_warm_enabled() {
    return (bool) ngxcp_get_option( 'warmer_enabled' );
}

/**
 * Number of URLs currently waiting in the warm queue.
 *
 * @return int
 */
function ngxcp_warm_queue_count() {
    global $wpdb;
    $table = ngxcp_warm_table();
    // Custom queue table; name from $wpdb->prefix, not object-cacheable.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
}

/**
 * Would this path be cached at all? Skip the obvious non-cacheable ones so we
 * do not queue a fetch that nginx will only BYPASS. This mirrors the common
 * bypass rules; it does not need to be exhaustive, just avoid clear waste.
 *
 * @param string $path
 * @return bool
 */
function ngxcp_warm_is_cacheable_path( $path ) {
    if ( '' === $path || '/' !== substr( $path, 0, 1 ) ) {
        return false;
    }
    // A query string is never cached (and never warmable to a stable key).
    if ( false !== strpos( $path, '?' ) ) {
        return false;
    }
    $skip = array(
        '#^/wp-admin#i',
        '#^/wp-login\.php#i',
        '#^/wp-json#i',
        '#^/xmlrpc\.php#i',
        '#^/feed#i',
        '#^/purge#i',
        '#^/(cart|checkout|my-account)#i',
        '#sitemap.*\.xml#i',
    );
    foreach ( $skip as $re ) {
        if ( preg_match( $re, $path ) ) {
            return false;
        }
    }
    return true;
}

/**
 * Add a path to the warm queue. INSERT IGNORE on a UNIQUE hash silently drops
 * duplicates and races.
 *
 * @param string $path
 */
function ngxcp_warm_enqueue( $path ) {
    if ( ! ngxcp_warm_enabled() || ! ngxcp_warm_is_cacheable_path( $path ) ) {
        return;
    }
    global $wpdb;
    $table = ngxcp_warm_table();
    // The table name comes from $wpdb->prefix (not user input) and cannot be a
    // bound parameter; the values are prepared. A work queue is not object-cache
    // material, so the caching sniffs do not apply.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $wpdb->query(
        $wpdb->prepare(
            "INSERT IGNORE INTO {$table} (url_hash, path, added_at) VALUES (%s, %s, %d)",
            md5( $path ),
            $path,
            time()
        )
    );
    // phpcs:enable
}

/**
 * Enqueue several paths.
 *
 * @param string[] $paths
 */
function ngxcp_warm_enqueue_bulk( $paths ) {
    foreach ( (array) $paths as $path ) {
        ngxcp_warm_enqueue( $path );
    }
}
// Every single-URL purge offers its path to the warmer (no-op if disabled).
add_action( 'ngxcp_purged_path', 'ngxcp_warm_enqueue', 10, 1 );

/**
 * After a full purge: the per-URL queue is meaningless, so empty it and instead
 * queue a bounded, high-value set - the home page and the most recent posts.
 */
function ngxcp_warm_after_full_purge() {
    if ( ! ngxcp_warm_enabled() ) {
        return;
    }
    global $wpdb;
    $table = ngxcp_warm_table();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $wpdb->query( "TRUNCATE TABLE {$table}" );

    $paths = array( '/' );

    $limit = max( 0, (int) ngxcp_get_option( 'warm_max_urls' ) - 1 ); // -1 for the home page.
    if ( $limit > 0 ) {
        $recent = get_posts(
            array(
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'posts_per_page' => $limit,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            )
        );
        foreach ( $recent as $id ) {
            $link = get_permalink( $id );
            if ( $link ) {
                $parsed = wp_parse_url( $link );
                if ( ! empty( $parsed['path'] ) ) {
                    $paths[] = $parsed['path'];
                }
            }
        }
    }

    ngxcp_warm_enqueue_bulk( array_unique( $paths ) );
}
add_action( 'ngxcp_purged_all', 'ngxcp_warm_after_full_purge', 10, 0 );

/**
 * Fetch one URL so nginx caches it. Anonymous GET, no cookies, through the same
 * endpoint the purger uses (so a localhost endpoint warms nginx directly).
 *
 * @param string $path
 * @return string Cache status header value, for logging ('' if unknown).
 */
function ngxcp_warm_fetch( $path ) {
    $endpoint = ngxcp_purge_endpoint();
    if ( '' === $endpoint ) {
        return '';
    }

    $url  = $endpoint . $path;
    $args = array(
        'timeout'     => 15,
        'redirection' => 0,
        'sslverify'   => apply_filters( 'ngxcp_purge_sslverify', (bool) ngxcp_get_option( 'purge_sslverify' ) ),
        'blocking'    => true,
        'cookies'     => array(), // Must be anonymous, or nginx BYPASSes it.
        'user-agent'  => 'nginx-cache-purger-warmer/' . NGXCP_VERSION . '; ' . home_url( '/' ),
    );

    // When the endpoint is a loopback address, nginx still needs the site's real
    // host to route to the right server block.
    $home_host     = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
    $endpoint_host = wp_parse_url( $endpoint, PHP_URL_HOST );
    if ( $home_host && $endpoint_host && $home_host !== $endpoint_host ) {
        $args['headers'] = array( 'Host' => $home_host );
    }

    $response = wp_remote_get( $url, $args );
    if ( is_wp_error( $response ) ) {
        ngxcp_log( 'Warm failed for ' . $path . ': ' . $response->get_error_message() );
        return '';
    }

    $status = ngxcp_read_cache_status( $response );
    ngxcp_log( 'Warmed ' . $path . ' (' . wp_remote_retrieve_response_code( $response ) . ', cache: ' . ( $status ? $status : 'n/a' ) . ')' );
    return $status;
}

/**
 * Cron worker: warm one batch and delete those rows.
 */
function ngxcp_warm_run() {
    if ( ! ngxcp_warm_enabled() ) {
        return;
    }
    global $wpdb;
    $table = ngxcp_warm_table();

    // Custom queue table: name from $wpdb->prefix, values prepared, not
    // object-cacheable.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $rows = $wpdb->get_results(
        $wpdb->prepare( "SELECT id, path FROM {$table} ORDER BY id ASC LIMIT %d", NGXCP_WARM_BATCH )
    );
    if ( empty( $rows ) ) {
        return;
    }

    $ids = array();
    foreach ( $rows as $row ) {
        ngxcp_warm_fetch( $row->path );
        $ids[] = (int) $row->id;
    }

    // $in is a comma-joined list of absint()-ed ids - integers only.
    $in = implode( ',', array_map( 'absint', $ids ) );
    $wpdb->query( "DELETE FROM {$table} WHERE id IN ({$in})" );
    // phpcs:enable
}
add_action( NGXCP_WARM_CRON, 'ngxcp_warm_run' );

/**
 * Register a one-minute cron schedule for the worker.
 *
 * @param array $schedules
 * @return array
 */
function ngxcp_warm_cron_schedule( $schedules ) {
    if ( ! isset( $schedules[ NGXCP_WARM_SCHEDULE ] ) ) {
        $schedules[ NGXCP_WARM_SCHEDULE ] = array(
            'interval' => 60,
            'display'  => __( 'Every minute (Nginx Cache Purger)', 'reqad-cache-purger' ),
        );
    }
    return $schedules;
}
add_filter( 'cron_schedules', 'ngxcp_warm_cron_schedule' ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected

/**
 * Ensure the recurring worker event exists (idempotent). Hooked on init so it
 * survives even if the activation hook was missed (e.g. mu-loaded).
 */
function ngxcp_warm_maybe_schedule() {
    if ( ! wp_next_scheduled( NGXCP_WARM_CRON ) ) {
        wp_schedule_event( time() + 60, NGXCP_WARM_SCHEDULE, NGXCP_WARM_CRON );
    }
}
add_action( 'init', 'ngxcp_warm_maybe_schedule' );

/**
 * Timestamp of the last worker run - used by the Settings page cron canary.
 * Recorded whether or not the queue had anything, so it reflects cron health.
 */
function ngxcp_warm_record_heartbeat() {
    update_option( 'ngxcp_cron_last_run', time(), false );
}
add_action( NGXCP_WARM_CRON, 'ngxcp_warm_record_heartbeat', 1 );
