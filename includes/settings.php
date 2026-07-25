<?php
/**
 * Settings page (Settings -> Nginx Cache Purger).
 *
 * Every option is optional; the plugin works with none of this touched. The page
 * adds: the warmer toggle, the purge endpoint / SSL-verify overrides, a WP-Cron
 * panel (detect + optionally write DISABLE_WP_CRON, show last worker run), and a
 * one-click cache self-test built on the X-FastCGI-Cache debug header.
 *
 * @package Nginx_Cache_Purger
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register the settings menu entry.
 */
function ngxcp_settings_menu() {
    add_options_page(
        __( 'Reqad Cache Purger for Nginx', 'reqad-cache-purger' ),
        __( 'Reqad Cache Purger', 'reqad-cache-purger' ),
        'manage_options',
        'reqad-cache-purger',
        'ngxcp_settings_render'
    );
}
add_action( 'admin_menu', 'ngxcp_settings_menu' );

/**
 * Register the option and its sanitiser.
 */
function ngxcp_settings_register() {
    register_setting(
        'ngxcp_settings_group',
        NGXCP_OPTION,
        array(
            'type'              => 'array',
            'sanitize_callback' => 'ngxcp_settings_sanitize',
            'default'           => ngxcp_default_options(),
        )
    );
}
add_action( 'admin_init', 'ngxcp_settings_register' );

/**
 * Sanitise submitted settings.
 *
 * @param array $input
 * @return array
 */
function ngxcp_settings_sanitize( $input ) {
    $out = ngxcp_default_options();

    $out['warmer_enabled']  = ! empty( $input['warmer_enabled'] );
    $out['purge_sslverify'] = ! empty( $input['purge_sslverify'] );

    $max = isset( $input['warm_max_urls'] ) ? (int) $input['warm_max_urls'] : 15;
    $out['warm_max_urls'] = min( 200, max( 1, $max ) );

    $endpoint = isset( $input['purge_endpoint'] ) ? trim( (string) $input['purge_endpoint'] ) : '';
    if ( '' !== $endpoint ) {
        $endpoint = esc_url_raw( $endpoint, array( 'http', 'https' ) );
        // Strip any path/query - we only want scheme://host[:port].
        $parts = wp_parse_url( $endpoint );
        if ( ! empty( $parts['scheme'] ) && ! empty( $parts['host'] ) ) {
            $endpoint = $parts['scheme'] . '://' . $parts['host'] . ( ! empty( $parts['port'] ) ? ':' . $parts['port'] : '' );
        } else {
            $endpoint = '';
        }
    }
    $out['purge_endpoint'] = $endpoint;

    return $out;
}

/**
 * Enqueue the tiny settings-page script (AJAX buttons).
 *
 * @param string $hook
 */
function ngxcp_settings_assets( $hook ) {
    if ( 'settings_page_reqad-cache-purger' !== $hook ) {
        return;
    }
    // Replace WordPress's "Thank you for creating with WordPress." on this page.
    add_filter( 'admin_footer_text', 'ngxcp_settings_footer_text' );
    wp_enqueue_script(
        'ngxcp-settings',
        plugins_url( 'settings.js', __FILE__ ),
        array( 'jquery' ),
        NGXCP_VERSION,
        true
    );
    wp_localize_script(
        'ngxcp-settings',
        'ngxcp_settings',
        array(
            'ajax_url'   => admin_url( 'admin-ajax.php' ),
            'test_nonce' => wp_create_nonce( 'ngxcp_cache_test' ),
            'cron_nonce' => wp_create_nonce( 'ngxcp_cron_setup' ),
            'testing'    => __( 'Testing…', 'reqad-cache-purger' ),
            'working'    => __( 'Working…', 'reqad-cache-purger' ),
        )
    );
}
add_action( 'admin_enqueue_scripts', 'ngxcp_settings_assets' );

/**
 * Footer text shown at the bottom of the settings page, in place of WordPress's
 * default "Thank you for creating with WordPress." - a link to the nginx setup
 * guide and a nudge to star the project on GitHub.
 *
 * @param string $text
 * @return string
 */
function ngxcp_settings_footer_text( $text ) {
    $guide = '<a href="https://github.com/wbdv/reqad-cache-purger#readme" target="_blank" rel="noopener noreferrer">'
        . esc_html__( 'Read the nginx configuration guide', 'reqad-cache-purger' ) . '</a>';
    $star  = '<a href="https://github.com/wbdv/reqad-cache-purger" target="_blank" rel="noopener noreferrer">'
        . esc_html__( 'star it on GitHub', 'reqad-cache-purger' ) . ' &#9733;</a>';

    $line = sprintf(
        /* translators: 1: link to the nginx configuration guide, 2: link to star the repo on GitHub */
        esc_html__( '%1$s, and, if you like it, %2$s.', 'reqad-cache-purger' ),
        $guide,
        $star
    );

    return '<div style="padding-left:15px;">' . $line . '</div>';
}

/**
 * Locate wp-config.php (same search WordPress core uses).
 *
 * @return string|false Absolute path, or false if not found.
 */
function ngxcp_locate_wp_config() {
    if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
        return ABSPATH . 'wp-config.php';
    }
    // One directory up, but not if wp-settings.php also lives there.
    if ( file_exists( dirname( ABSPATH ) . '/wp-config.php' ) && ! file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ) {
        return dirname( ABSPATH ) . '/wp-config.php';
    }
    return false;
}

/**
 * Is DISABLE_WP_CRON defined and true?
 *
 * @return bool
 */
function ngxcp_wp_cron_disabled() {
    return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
}

/**
 * Try to add `define( 'DISABLE_WP_CRON', true );` to wp-config.php.
 *
 * @return array { 'ok' => bool, 'message' => string }
 */
function ngxcp_write_disable_wp_cron() {
    if ( ngxcp_wp_cron_disabled() ) {
        return array( 'ok' => true, 'message' => __( 'DISABLE_WP_CRON is already set.', 'reqad-cache-purger' ) );
    }

    $file = ngxcp_locate_wp_config();
    if ( ! $file ) {
        return array( 'ok' => false, 'message' => __( 'Could not locate wp-config.php.', 'reqad-cache-purger' ) );
    }

    // Use the WP_Filesystem API rather than raw PHP file calls. When the files
    // are owned by the web user it resolves to the 'direct' method and writes
    // silently; otherwise it cannot get credentials in this AJAX context and we
    // fall back to telling the user to add the line by hand.
    require_once ABSPATH . 'wp-admin/includes/file.php';
    global $wp_filesystem;
    if ( ! WP_Filesystem() || ! $wp_filesystem ) {
        return array( 'ok' => false, 'message' => __( 'Cannot write to the filesystem automatically. Add the line manually (shown below).', 'reqad-cache-purger' ) );
    }

    if ( ! $wp_filesystem->is_writable( $file ) ) {
        return array( 'ok' => false, 'message' => __( 'wp-config.php is not writable. Add the line manually (shown below).', 'reqad-cache-purger' ) );
    }

    $contents = $wp_filesystem->get_contents( $file );
    if ( false === $contents ) {
        return array( 'ok' => false, 'message' => __( 'Could not read wp-config.php.', 'reqad-cache-purger' ) );
    }
    if ( false !== strpos( $contents, 'DISABLE_WP_CRON' ) ) {
        return array( 'ok' => false, 'message' => __( 'wp-config.php already mentions DISABLE_WP_CRON; edit it by hand to avoid a conflict.', 'reqad-cache-purger' ) );
    }

    $line = "define( 'DISABLE_WP_CRON', true ); // Added by Nginx Cache Purger\n";

    // Insert right after the opening PHP tag, like WP_CACHE-writing plugins do.
    $new = preg_replace( '/^<\?php\s*\n/', "<?php\n" . $line, $contents, 1, $count );
    if ( ! $count ) {
        return array( 'ok' => false, 'message' => __( 'Unexpected wp-config.php format; add the line manually.', 'reqad-cache-purger' ) );
    }

    if ( ! $wp_filesystem->put_contents( $file, $new, FS_CHMOD_FILE ) ) {
        return array( 'ok' => false, 'message' => __( 'Write failed. Add the line manually.', 'reqad-cache-purger' ) );
    }

    // Drop the cached bytecode of wp-config.php so the new define takes effect on
    // the very next request. Without this, OPcache keeps running the old file for
    // up to opcache.revalidate_freq seconds and the page looks unchanged.
    if ( function_exists( 'opcache_invalidate' ) ) {
        opcache_invalidate( $file, true );
    }

    return array( 'ok' => true, 'message' => __( 'Added DISABLE_WP_CRON to wp-config.php. Now add the system cron line below.', 'reqad-cache-purger' ) );
}

/**
 * AJAX: attempt the wp-config write.
 */
function ngxcp_ajax_cron_setup() {
    check_ajax_referer( 'ngxcp_cron_setup', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'reqad-cache-purger' ) ) );
    }
    $result = ngxcp_write_disable_wp_cron();
    if ( $result['ok'] ) {
        wp_send_json_success( array( 'message' => $result['message'] ) );
    }
    wp_send_json_error( array( 'message' => $result['message'] ) );
}
add_action( 'wp_ajax_ngxcp_cron_setup', 'ngxcp_ajax_cron_setup' );

/**
 * AJAX: cache self-test. Fetch the home page twice through the endpoint and
 * report what the X-FastCGI-Cache header said.
 */
function ngxcp_ajax_cache_test() {
    check_ajax_referer( 'ngxcp_cache_test', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'reqad-cache-purger' ) ) );
    }

    $endpoint = ngxcp_purge_endpoint();
    if ( '' === $endpoint ) {
        wp_send_json_error( array( 'message' => __( 'Could not determine the site endpoint.', 'reqad-cache-purger' ) ) );
    }

    $home_host     = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
    $endpoint_host = wp_parse_url( $endpoint, PHP_URL_HOST );
    $args          = array(
        'timeout'     => 15,
        'redirection' => 0,
        'sslverify'   => apply_filters( 'ngxcp_purge_sslverify', (bool) ngxcp_get_option( 'purge_sslverify' ) ),
        'cookies'     => array(),
        'user-agent'  => 'nginx-cache-purger-selftest/' . NGXCP_VERSION,
    );
    if ( $home_host && $endpoint_host && $home_host !== $endpoint_host ) {
        $args['headers'] = array( 'Host' => $home_host );
    }

    $statuses = array();
    for ( $i = 0; $i < 2; $i++ ) {
        $r = wp_remote_get( $endpoint . '/', $args );
        if ( is_wp_error( $r ) ) {
            wp_send_json_error( array( 'message' => sprintf( /* translators: %s: error */ __( 'Request failed: %s', 'reqad-cache-purger' ), $r->get_error_message() ) ) );
        }
        $statuses[] = ngxcp_read_cache_status( $r );
    }

    if ( '' === $statuses[0] && '' === $statuses[1] ) {
        wp_send_json_error( array(
            'message' => __( 'No cache status header seen (X-FastCGI-Cache, X-Cache-Status or X-Proxy-Cache). Either the cache is not configured for this site, or the header is not enabled in the vhost.', 'reqad-cache-purger' ),
        ) );
    }

    $second = strtoupper( $statuses[1] );
    if ( 'HIT' === $second ) {
        $verdict = __( 'Caching is working - the second request was a HIT.', 'reqad-cache-purger' );
    } elseif ( 'BYPASS' === $second ) {
        $verdict = __( 'The home page is being BYPASSED (a cookie, query string or bypass rule matched). That can be normal for a logged-in test.', 'reqad-cache-purger' );
    } else {
        $verdict = sprintf( /* translators: 1,2: cache statuses */ __( 'Cache responded (%1$s then %2$s) but did not settle on HIT. Check the vhost cache rules.', 'reqad-cache-purger' ), $statuses[0], $second );
    }

    wp_send_json_success( array(
        'message' => $verdict,
        'first'   => $statuses[0],
        'second'  => $statuses[1],
    ) );
}
add_action( 'wp_ajax_ngxcp_cache_test', 'ngxcp_ajax_cache_test' );

/**
 * Render the settings page.
 */
function ngxcp_settings_render() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $o             = ngxcp_get_options();
    $cron_disabled = ngxcp_wp_cron_disabled();
    $last_run      = (int) get_option( 'ngxcp_cron_last_run', 0 );
    // The worker running within the last 3 minutes means a real system cron is
    // firing, so the setup instructions become a confirmation instead.
    $cron_ok       = $last_run && ( time() - $last_run <= 180 );
    $wp_path       = untrailingslashit( ABSPATH );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Reqad Cache Purger for Nginx', 'reqad-cache-purger' ); ?></h1>
        <?php ngxcp_render_conflict_notice(); ?>
	<div class="ngxcp-notice notice notice-success" style="padding:5px 15px;background:#FFF;border:2px solid #32669d;margin-bottom:5px;">
        <img src="<?php echo esc_url( NGXCP_URL . 'images/reqad-logo.svg' ); ?>" alt="Reqad" style="float:right;height:44px;margin:6px 4px 6px 20px;" />
        <p style="font-size:14px;max-width:70em;"><strong><?php esc_html_e( 'Requirements:', 'reqad-cache-purger' ); ?></strong> <?php
        printf(
            /* translators: 1: link to the setup guide on GitHub, 2: link to reqad.com */
            esc_html__( 'this plugin does not cache anything itself - it needs nginx with FastCGI caching and the ngx_cache_purge module compiled in (%1$s). It is the cache companion for %2$s, the open-source hosting control panel, which ships nginx already built with that module, so there is nothing to compile. It works exactly the same on any server that has nginx and the module.', 'reqad-cache-purger' ),
            '<a href="https://github.com/wbdv/reqad-cache-purger#readme" target="_blank" rel="noopener noreferrer">' . esc_html__( 'setup guide', 'reqad-cache-purger' ) . '</a>',
            '<a href="https://reqad.com" target="_blank" rel="noopener noreferrer">Reqad</a>'
        );
        ?></p>
	</div>
	
	<br/>

	<div style="padding:15px;background:#FFF;border-top:6px solid #DEF">
        <form method="post" action="options.php">
            <?php settings_fields( 'ngxcp_settings_group' ); ?>

            <h2><?php esc_html_e( 'Cache warming', 'reqad-cache-purger' ); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Enable warmer', 'reqad-cache-purger' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr( NGXCP_OPTION ); ?>[warmer_enabled]" value="1" <?php checked( $o['warmer_enabled'] ); ?> />
                            <?php esc_html_e( 'Re-fetch purged URLs in the background so visitors keep hitting cached pages.', 'reqad-cache-purger' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'Requires a working cron (see below). Warming adds background load, so it is off by default.', 'reqad-cache-purger' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ngxcp_warm_max"><?php esc_html_e( 'URLs per full purge', 'reqad-cache-purger' ); ?></label></th>
                    <td>
                        <input type="number" min="1" max="200" id="ngxcp_warm_max" name="<?php echo esc_attr( NGXCP_OPTION ); ?>[warm_max_urls]" value="<?php echo esc_attr( $o['warm_max_urls'] ); ?>" class="small-text" />
                        <p class="description"><?php esc_html_e( 'After a full purge, warm at most this many URLs (home page + most recent posts). The whole sitemap is never warmed at once.', 'reqad-cache-purger' ); ?></p>
                    </td>
                </tr>
                <?php if ( $o['warmer_enabled'] ) : ?>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Queue', 'reqad-cache-purger' ); ?></th>
                    <td>
                        <?php
                        $queued = ngxcp_warm_queue_count();
                        echo esc_html(
                            sprintf(
                                /* translators: %s: number of URLs */
                                _n( '%s URL waiting to be warmed.', '%s URLs waiting to be warmed.', $queued, 'reqad-cache-purger' ),
                                number_format_i18n( $queued )
                            )
                        );
                        ?>
                        <p class="description"><?php esc_html_e( 'Drained a few URLs at a time on each cron run.', 'reqad-cache-purger' ); ?></p>
                    </td>
                </tr>
                <?php endif; ?>
            </table>

            <h2><?php esc_html_e( 'Purge endpoint', 'reqad-cache-purger' ); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="ngxcp_endpoint"><?php esc_html_e( 'Endpoint override', 'reqad-cache-purger' ); ?></label></th>
                    <td>
                        <input type="text" id="ngxcp_endpoint" name="<?php echo esc_attr( NGXCP_OPTION ); ?>[purge_endpoint]" value="<?php echo esc_attr( $o['purge_endpoint'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( home_url( '' ) ); ?>" />
                        <p class="description"><?php esc_html_e( 'Leave blank to use the site address. Behind Cloudflare (orange-cloud) or a proxy, set http://127.0.0.1 so purges reach nginx directly.', 'reqad-cache-purger' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Verify SSL', 'reqad-cache-purger' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr( NGXCP_OPTION ); ?>[purge_sslverify]" value="1" <?php checked( $o['purge_sslverify'] ); ?> />
                            <?php esc_html_e( 'Verify the TLS certificate on purge/warm requests.', 'reqad-cache-purger' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'Turn off only when using an http://127.0.0.1 endpoint or a hostname the certificate does not cover.', 'reqad-cache-purger' ); ?></p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>
	</div>

        <hr />

	<div style="padding:15px;background:#FFF;border-top:6px solid #DEF;">
        <h2><?php esc_html_e( 'WP-Cron', 'reqad-cache-purger' ); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Status', 'reqad-cache-purger' ); ?></th>
                <td>
                    <?php if ( $cron_disabled ) : ?>
                        <p style="display:inline-block;margin-top:0;padding:8px 12px;border:1px solid #008a20;border-radius:3px;background:#ecf7ed;color:#0a5c22;">
                            <span style="color:#008a20;">&#10003;</span> <?php esc_html_e( 'DISABLE_WP_CRON is set - WordPress relies on your system cron.', 'reqad-cache-purger' ); ?>
                        </p>
                    <?php else : ?>
                        <p><?php esc_html_e( 'WP-Cron runs on page visits. On a quiet site that means warming lags. For reliable warming, set DISABLE_WP_CRON and add a real cron job.', 'reqad-cache-purger' ); ?></p>
                        <p>
                            <button type="button" class="button" id="ngxcp-cron-setup"><?php esc_html_e( 'Set DISABLE_WP_CRON in wp-config.php', 'reqad-cache-purger' ); ?></button>
                            <span id="ngxcp-cron-setup-result"></span>
                        </p>
                    <?php endif; ?>

                    <?php if ( $cron_ok ) : ?>
                        <p style="display:inline-block;padding:8px 12px;border:1px solid #008a20;border-radius:3px;background:#ecf7ed;color:#0a5c22;">
                            <span style="color:#008a20;">&#10003;</span>
                            <?php
                            echo esc_html(
                                sprintf(
                                    /* translators: %s: human-readable time difference, e.g. "40 secs" */
                                    __( 'System cron is running - the worker last ran %s ago.', 'reqad-cache-purger' ),
                                    human_time_diff( $last_run )
                                )
                            );
                            ?>
                        </p>
                    <?php else : ?>
                        <p class="description"><?php esc_html_e( 'Then add this to your server crontab (every minute):', 'reqad-cache-purger' ); ?></p>
                        <p><code>* * * * * cd <?php echo esc_html( $wp_path ); ?> &amp;&amp; wp cron event run --due-now &gt;/dev/null 2&gt;&amp;1</code></p>
                        <p class="description"><?php esc_html_e( 'Or, without WP-CLI:', 'reqad-cache-purger' ); ?></p>
                        <p><code>* * * * * curl -s <?php echo esc_html( site_url( 'wp-cron.php?doing_wp_cron' ) ); ?> &gt;/dev/null 2&gt;&amp;1</code></p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Last worker run', 'reqad-cache-purger' ); ?></th>
                <td>
                    <?php if ( $last_run ) : ?>
                        <p><?php echo esc_html( sprintf( /* translators: %s: human time diff */ __( '%s ago', 'reqad-cache-purger' ), human_time_diff( $last_run ) ) ); ?>
                        <?php if ( time() - $last_run > 300 ) : ?>
                            <span style="color:#d63638;">&#9888; <?php esc_html_e( 'more than 5 minutes ago - cron may not be firing', 'reqad-cache-purger' ); ?></span>
                        <?php endif; ?>
                        </p>
                    <?php else : ?>
                        <p><?php esc_html_e( 'The worker has not run yet.', 'reqad-cache-purger' ); ?></p>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
	</div>

        <hr />

	<div style="padding:15px;background:#FFF;border-top:6px solid #DEF;">
        <h2><?php esc_html_e( 'Cache self-test', 'reqad-cache-purger' ); ?></h2>
        <p><?php esc_html_e( 'Fetches the home page twice and reads the cache status header to confirm caching is active.', 'reqad-cache-purger' ); ?></p>
        <p>
            <button type="button" class="button button-secondary" id="ngxcp-cache-test"><?php esc_html_e( 'Run cache test', 'reqad-cache-purger' ); ?></button>
            <span id="ngxcp-cache-test-result" style="margin-left:8px;"></span>
        </p>
	</div>
    </div>
    <?php
}
