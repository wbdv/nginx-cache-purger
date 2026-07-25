<?php
/**
 * Interoperability with other caching layers and with core Site Health.
 *
 * Two unrelated jobs live here because both are about how this plugin is seen
 * from the outside:
 *
 * 1. Detecting other full-page caches. nginx caches what PHP returns, so a
 *    second plugin that also stores rendered HTML means two caches with two
 *    lifetimes and two purge mechanisms - neither of which knows about the
 *    other. Stale pages follow. We warn; we never deactivate anything.
 * 2. Telling Site Health that the page cache it cannot see is in fact there.
 *
 * @package Nginx_Cache_Purger
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Response headers that carry an nginx cache status, most specific first.
 *
 * The README asks for X-FastCGI-Cache, but plenty of vhosts in the wild use the
 * more generic names, so read all three before concluding the cache is absent.
 *
 * @return string[] Lower-case header names.
 */
function ngxcp_cache_status_headers() {
    return (array) apply_filters(
        'ngxcp_cache_status_headers',
        array( 'x-fastcgi-cache', 'x-cache-status', 'x-proxy-cache' )
    );
}

/**
 * Pull the cache status out of a response, whichever header carries it.
 *
 * @param array|WP_Error $response Response from wp_remote_get().
 * @return string Status such as HIT, MISS, BYPASS; '' when no header was sent.
 */
function ngxcp_read_cache_status( $response ) {
    foreach ( ngxcp_cache_status_headers() as $header ) {
        $value = wp_remote_retrieve_header( $response, $header );
        if ( is_array( $value ) ) {
            $value = reset( $value );
        }
        $value = trim( (string) $value );
        if ( '' !== $value ) {
            return $value;
        }
    }
    return '';
}

/**
 * Teach Site Health to recognise our cache headers.
 *
 * Core's page-cache test makes three anonymous requests to the home page and
 * looks for a known caching header. It already knows x-cache-status and
 * x-proxy-cache but not x-fastcgi-cache, so a correctly configured site was
 * reported as having no page cache at all. Registering the header fixes both
 * complaints at once: once any header is found, core also stops saying "a page
 * cache plugin was not detected", because that line is suppressed whenever an
 * external caching layer is visible.
 *
 * @param array $headers Header name => validation callback (or null).
 * @return array
 */
function ngxcp_site_status_cache_headers( $headers ) {
    // Same matcher core uses for x-cache-status: the value must contain HIT.
    $is_hit = static function ( $value ) {
        return 1 === preg_match( '/(^| |,)HIT(,| |$)/i', (string) $value );
    };

    foreach ( ngxcp_cache_status_headers() as $header ) {
        if ( ! isset( $headers[ $header ] ) ) {
            $headers[ $header ] = $is_hit;
        }
    }

    return $headers;
}
add_filter( 'site_status_page_cache_supported_cache_headers', 'ngxcp_site_status_cache_headers' );

/**
 * Plugins that cache rendered pages themselves, keyed by directory slug.
 *
 * Object caches (Redis Object Cache, Docket Cache), asset optimisers and CDN
 * plugins are deliberately absent: they do not store HTML, so they coexist
 * fine. Only whole-page caches and rival nginx purgers belong here.
 *
 * @return array<string, array{name: string, type: string}>
 */
function ngxcp_known_cache_plugins() {
    return array(
        // Full-page caches - these store rendered HTML of their own.
        'wp-rocket'               => array( 'name' => 'WP Rocket',              'type' => 'page-cache' ),
        'w3-total-cache'          => array( 'name' => 'W3 Total Cache',         'type' => 'page-cache' ),
        'wp-super-cache'          => array( 'name' => 'WP Super Cache',         'type' => 'page-cache' ),
        'litespeed-cache'         => array( 'name' => 'LiteSpeed Cache',        'type' => 'page-cache' ),
        'wp-fastest-cache'        => array( 'name' => 'WP Fastest Cache',       'type' => 'page-cache' ),
        'cache-enabler'           => array( 'name' => 'Cache Enabler',          'type' => 'page-cache' ),
        'comet-cache'             => array( 'name' => 'Comet Cache',            'type' => 'page-cache' ),
        'comet-cache-pro'         => array( 'name' => 'Comet Cache Pro',        'type' => 'page-cache' ),
        'hummingbird-performance' => array( 'name' => 'Hummingbird',            'type' => 'page-cache' ),
        'breeze'                  => array( 'name' => 'Breeze',                 'type' => 'page-cache' ),
        'swift-performance'       => array( 'name' => 'Swift Performance',      'type' => 'page-cache' ),
        'swift-performance-lite'  => array( 'name' => 'Swift Performance Lite', 'type' => 'page-cache' ),
        'sg-cachepress'           => array( 'name' => 'SiteGround Optimizer',   'type' => 'page-cache' ),
        'wp-optimize'             => array( 'name' => 'WP-Optimize',            'type' => 'page-cache' ),
        'powered-cache'           => array( 'name' => 'Powered Cache',          'type' => 'page-cache' ),
        'surge'                   => array( 'name' => 'Surge',                  'type' => 'page-cache' ),
        'cachify'                 => array( 'name' => 'Cachify',                'type' => 'page-cache' ),
        'simple-cache'            => array( 'name' => 'Simple Cache',           'type' => 'page-cache' ),

        // Rival purgers - harmless but redundant, and they can fight over rules.
        'nginx-helper'            => array( 'name' => 'Nginx Helper',           'type' => 'purger' ),
        'nginx-cache'             => array( 'name' => 'Nginx Cache',            'type' => 'purger' ),
        'varnish-http-purge'      => array( 'name' => 'Proxy Cache Purge',      'type' => 'purger' ),
    );
}

/**
 * Directory slugs of all active plugins, including network-activated ones.
 *
 * Matching on the directory rather than the main file avoids having to know
 * each plugin's entry-point filename, which is where such lists usually rot.
 *
 * @return string[]
 */
function ngxcp_active_plugin_slugs() {
    $active = (array) get_option( 'active_plugins', array() );

    if ( is_multisite() ) {
        $active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
    }

    $slugs = array();
    foreach ( $active as $file ) {
        $dir     = dirname( $file );
        $slugs[] = ( '.' === $dir ) ? basename( $file, '.php' ) : $dir;
    }

    return array_unique( $slugs );
}

/**
 * Find caching layers that overlap with this plugin.
 *
 * @return array<int, array{name: string, type: string}>
 */
function ngxcp_detect_cache_conflicts() {
    $known  = ngxcp_known_cache_plugins();
    $found  = array();
    $matched_page_cache = false;

    foreach ( ngxcp_active_plugin_slugs() as $slug ) {
        if ( isset( $known[ $slug ] ) ) {
            $found[] = $known[ $slug ];
            if ( 'page-cache' === $known[ $slug ]['type'] ) {
                $matched_page_cache = true;
            }
        }
    }

    /*
     * Catch page caches we do not have by name. This is exactly the test core's
     * Site Health uses: a drop-in plus the constant that makes WordPress load
     * it. Only report it when no known page cache already explains the drop-in,
     * otherwise every WP Rocket install gets warned about twice.
     */
    if ( ! $matched_page_cache
        && file_exists( WP_CONTENT_DIR . '/advanced-cache.php' )
        && defined( 'WP_CACHE' ) && WP_CACHE
    ) {
        $found[] = array(
            'name' => __( 'an unidentified page cache (advanced-cache.php drop-in)', 'reqad-cache-purger' ),
            'type' => 'page-cache',
        );
    }

    return (array) apply_filters( 'ngxcp_cache_conflicts', $found );
}

/**
 * Render the conflict warning. Called at the top of the settings page.
 */
function ngxcp_render_conflict_notice() {
    $conflicts = ngxcp_detect_cache_conflicts();
    if ( empty( $conflicts ) ) {
        return;
    }

    $page_caches = array();
    $purgers     = array();
    foreach ( $conflicts as $c ) {
        if ( 'page-cache' === $c['type'] ) {
            $page_caches[] = $c['name'];
        } else {
            $purgers[] = $c['name'];
        }
    }

    if ( $page_caches ) {
        ?>
        <div class="notice notice-error is-dismissible" style="padding:4px 15px;font-size:14px;margin:16px 0;">
            <h4 style="font-size:16px;color:red;margin:0px"><?php esc_html_e( 'WARNING', 'reqad-cache-purger' ); ?></h4>
            <p style="font-size:14px;">
                <strong><?php esc_html_e( 'Another page cache is active.', 'reqad-cache-purger' ); ?></strong>
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: %s: comma-separated plugin names */
                        _n(
                            '%s also caches rendered pages.',
                            '%s also cache rendered pages.',
                            count( $page_caches ),
                            'reqad-cache-purger'
                        ),
                        implode( ', ', $page_caches )
                    )
                );
                ?>
            </p>
            <p style="font-size:14px;max-width:70em;">
                <?php esc_html_e( 'nginx caches whatever PHP returns, so its copy is made from the other plugin\'s copy. You end up with two caches, two expiry times and two purge mechanisms that do not know about each other - when that plugin clears its cache, nginx keeps serving the old page, and visitors see stale content.', 'reqad-cache-purger' ); ?>
                <?php esc_html_e( 'Pick one layer. Either turn off page caching in the other plugin (its minification, database and CDN features are fine to keep), or disable the FastCGI cache in your vhost and let that plugin do the caching.', 'reqad-cache-purger' ); ?>
            </p>
        </div>
        <?php
    }

    if ( $purgers ) {
        ?>
        <div class="notice notice-info">
            <p>
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: %s: comma-separated plugin names */
                        _n(
                            '%s also purges a server-side cache.',
                            '%s also purge a server-side cache.',
                            count( $purgers ),
                            'reqad-cache-purger'
                        ),
                        implode( ', ', $purgers )
                    )
                );
                ?>
                <?php esc_html_e( 'That is not harmful, but the two will issue overlapping purges. Running just one purger keeps the behaviour predictable.', 'reqad-cache-purger' ); ?>
            </p>
        </div>
        <?php
    }
}
