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
function ncp_settings_menu() {
    add_options_page(
        __( 'Nginx Cache Purger', 'nginx-cache-purger' ),
        __( 'Nginx Cache Purger', 'nginx-cache-purger' ),
        'manage_options',
        'nginx-cache-purger',
        'ncp_settings_render'
    );
}
add_action( 'admin_menu', 'ncp_settings_menu' );

/**
 * Register the option and its sanitiser.
 */
function ncp_settings_register() {
    register_setting(
        'ncp_settings_group',
        NCP_OPTION,
        array(
            'type'              => 'array',
            'sanitize_callback' => 'ncp_settings_sanitize',
            'default'           => ncp_default_options(),
        )
    );
}
add_action( 'admin_init', 'ncp_settings_register' );

/**
 * Sanitise submitted settings.
 *
 * @param array $input
 * @return array
 */
function ncp_settings_sanitize( $input ) {
    $out = ncp_default_options();

    $out['warmer_enabled']  = ! empty( $input['warmer_enabled'] );
    $out['purge_sslverify'] = ! empty( $input['purge_sslverify'] );

    $max = isset( $input['warm_max_urls'] ) ? (int) $input['warm_max_urls'] : 15;
    $out['warm_max_urls'] = min( 200, max( 1, $max ) );

    $endpoint = isset( $input['purge_endpoint'] ) ? trim( (string) $input['purge_endpoint'] ) : '';
    if ( '' !== $endpoint ) {
        $endpoint = esc_url_raw( $endpoint, array( 'http', 'https' ) );
        // Strip any path/query — we only want scheme://host[:port].
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
function ncp_settings_assets( $hook ) {
    if ( 'settings_page_nginx-cache-purger' !== $hook ) {
        return;
    }
    wp_enqueue_script(
        'ncp-settings',
        plugins_url( 'settings.js', __FILE__ ),
        array( 'jquery' ),
        NCP_VERSION,
        true
    );
    wp_localize_script(
        'ncp-settings',
        'ncp_settings',
        array(
            'ajax_url'   => admin_url( 'admin-ajax.php' ),
            'test_nonce' => wp_create_nonce( 'ncp_cache_test' ),
            'cron_nonce' => wp_create_nonce( 'ncp_cron_setup' ),
            'testing'    => __( 'Testing…', 'nginx-cache-purger' ),
            'working'    => __( 'Working…', 'nginx-cache-purger' ),
        )
    );
}
add_action( 'admin_enqueue_scripts', 'ncp_settings_assets' );

/**
 * Locate wp-config.php (same search WordPress core uses).
 *
 * @return string|false Absolute path, or false if not found.
 */
function ncp_locate_wp_config() {
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
function ncp_wp_cron_disabled() {
    return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
}

/**
 * Try to add `define( 'DISABLE_WP_CRON', true );` to wp-config.php.
 *
 * @return array { 'ok' => bool, 'message' => string }
 */
function ncp_write_disable_wp_cron() {
    if ( ncp_wp_cron_disabled() ) {
        return array( 'ok' => true, 'message' => __( 'DISABLE_WP_CRON is already set.', 'nginx-cache-purger' ) );
    }

    $file = ncp_locate_wp_config();
    if ( ! $file ) {
        return array( 'ok' => false, 'message' => __( 'Could not locate wp-config.php.', 'nginx-cache-purger' ) );
    }

    // Use the WP_Filesystem API rather than raw PHP file calls. When the files
    // are owned by the web user it resolves to the 'direct' method and writes
    // silently; otherwise it cannot get credentials in this AJAX context and we
    // fall back to telling the user to add the line by hand.
    require_once ABSPATH . 'wp-admin/includes/file.php';
    global $wp_filesystem;
    if ( ! WP_Filesystem() || ! $wp_filesystem ) {
        return array( 'ok' => false, 'message' => __( 'Cannot write to the filesystem automatically. Add the line manually (shown below).', 'nginx-cache-purger' ) );
    }

    if ( ! $wp_filesystem->is_writable( $file ) ) {
        return array( 'ok' => false, 'message' => __( 'wp-config.php is not writable. Add the line manually (shown below).', 'nginx-cache-purger' ) );
    }

    $contents = $wp_filesystem->get_contents( $file );
    if ( false === $contents ) {
        return array( 'ok' => false, 'message' => __( 'Could not read wp-config.php.', 'nginx-cache-purger' ) );
    }
    if ( false !== strpos( $contents, 'DISABLE_WP_CRON' ) ) {
        return array( 'ok' => false, 'message' => __( 'wp-config.php already mentions DISABLE_WP_CRON; edit it by hand to avoid a conflict.', 'nginx-cache-purger' ) );
    }

    $line = "define( 'DISABLE_WP_CRON', true ); // Added by Nginx Cache Purger\n";

    // Insert right after the opening PHP tag, like WP_CACHE-writing plugins do.
    $new = preg_replace( '/^<\?php\s*\n/', "<?php\n" . $line, $contents, 1, $count );
    if ( ! $count ) {
        return array( 'ok' => false, 'message' => __( 'Unexpected wp-config.php format; add the line manually.', 'nginx-cache-purger' ) );
    }

    // Back up before writing.
    $wp_filesystem->copy( $file, $file . '.ncp-bak', true );

    if ( ! $wp_filesystem->put_contents( $file, $new, FS_CHMOD_FILE ) ) {
        return array( 'ok' => false, 'message' => __( 'Write failed. Add the line manually.', 'nginx-cache-purger' ) );
    }
    return array( 'ok' => true, 'message' => __( 'Added DISABLE_WP_CRON to wp-config.php. Now add the system cron line below.', 'nginx-cache-purger' ) );
}

/**
 * AJAX: attempt the wp-config write.
 */
function ncp_ajax_cron_setup() {
    check_ajax_referer( 'ncp_cron_setup', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'nginx-cache-purger' ) ) );
    }
    $result = ncp_write_disable_wp_cron();
    if ( $result['ok'] ) {
        wp_send_json_success( array( 'message' => $result['message'] ) );
    }
    wp_send_json_error( array( 'message' => $result['message'] ) );
}
add_action( 'wp_ajax_ncp_cron_setup', 'ncp_ajax_cron_setup' );

/**
 * AJAX: cache self-test. Fetch the home page twice through the endpoint and
 * report what the X-FastCGI-Cache header said.
 */
function ncp_ajax_cache_test() {
    check_ajax_referer( 'ncp_cache_test', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'nginx-cache-purger' ) ) );
    }

    $endpoint = ncp_purge_endpoint();
    if ( '' === $endpoint ) {
        wp_send_json_error( array( 'message' => __( 'Could not determine the site endpoint.', 'nginx-cache-purger' ) ) );
    }

    $home_host     = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
    $endpoint_host = wp_parse_url( $endpoint, PHP_URL_HOST );
    $args          = array(
        'timeout'     => 15,
        'redirection' => 0,
        'sslverify'   => apply_filters( 'ncp_purge_sslverify', (bool) ncp_get_option( 'purge_sslverify' ) ),
        'cookies'     => array(),
        'user-agent'  => 'nginx-cache-purger-selftest/' . NCP_VERSION,
    );
    if ( $home_host && $endpoint_host && $home_host !== $endpoint_host ) {
        $args['headers'] = array( 'Host' => $home_host );
    }

    $statuses = array();
    for ( $i = 0; $i < 2; $i++ ) {
        $r = wp_remote_get( $endpoint . '/', $args );
        if ( is_wp_error( $r ) ) {
            wp_send_json_error( array( 'message' => sprintf( /* translators: %s: error */ __( 'Request failed: %s', 'nginx-cache-purger' ), $r->get_error_message() ) ) );
        }
        $h = wp_remote_retrieve_header( $r, 'x-fastcgi-cache' );
        $statuses[] = is_array( $h ) ? reset( $h ) : (string) $h;
    }

    if ( '' === $statuses[0] && '' === $statuses[1] ) {
        wp_send_json_error( array(
            'message' => __( 'No X-FastCGI-Cache header seen. Either the cache is not configured for this site, or the debug header is not enabled in the vhost.', 'nginx-cache-purger' ),
        ) );
    }

    $second = strtoupper( $statuses[1] );
    if ( 'HIT' === $second ) {
        $verdict = __( 'Caching is working — the second request was a HIT.', 'nginx-cache-purger' );
    } elseif ( 'BYPASS' === $second ) {
        $verdict = __( 'The home page is being BYPASSED (a cookie, query string or bypass rule matched). That can be normal for a logged-in test.', 'nginx-cache-purger' );
    } else {
        $verdict = sprintf( /* translators: 1,2: cache statuses */ __( 'Cache responded (%1$s then %2$s) but did not settle on HIT. Check the vhost cache rules.', 'nginx-cache-purger' ), $statuses[0], $second );
    }

    wp_send_json_success( array(
        'message' => $verdict,
        'first'   => $statuses[0],
        'second'  => $statuses[1],
    ) );
}
add_action( 'wp_ajax_ncp_cache_test', 'ncp_ajax_cache_test' );

/**
 * Render the settings page.
 */
function ncp_settings_render() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $o             = ncp_get_options();
    $cron_disabled = ncp_wp_cron_disabled();
    $last_run      = (int) get_option( 'ncp_cron_last_run', 0 );
    $wp_path       = untrailingslashit( ABSPATH );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Nginx Cache Purger', 'nginx-cache-purger' ); ?></h1>
        <p><?php esc_html_e( 'Everything here is optional — the plugin purges correctly with no settings changed.', 'nginx-cache-purger' ); ?></p>

        <form method="post" action="options.php">
            <?php settings_fields( 'ncp_settings_group' ); ?>

            <h2><?php esc_html_e( 'Cache warming', 'nginx-cache-purger' ); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Enable warmer', 'nginx-cache-purger' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr( NCP_OPTION ); ?>[warmer_enabled]" value="1" <?php checked( $o['warmer_enabled'] ); ?> />
                            <?php esc_html_e( 'Re-fetch purged URLs in the background so visitors keep hitting cached pages.', 'nginx-cache-purger' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'Requires a working cron (see below). Warming adds background load, so it is off by default.', 'nginx-cache-purger' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ncp_warm_max"><?php esc_html_e( 'URLs per full purge', 'nginx-cache-purger' ); ?></label></th>
                    <td>
                        <input type="number" min="1" max="200" id="ncp_warm_max" name="<?php echo esc_attr( NCP_OPTION ); ?>[warm_max_urls]" value="<?php echo esc_attr( $o['warm_max_urls'] ); ?>" class="small-text" />
                        <p class="description"><?php esc_html_e( 'After a full purge, warm at most this many URLs (home page + most recent posts). The whole sitemap is never warmed at once.', 'nginx-cache-purger' ); ?></p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e( 'Purge endpoint', 'nginx-cache-purger' ); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="ncp_endpoint"><?php esc_html_e( 'Endpoint override', 'nginx-cache-purger' ); ?></label></th>
                    <td>
                        <input type="text" id="ncp_endpoint" name="<?php echo esc_attr( NCP_OPTION ); ?>[purge_endpoint]" value="<?php echo esc_attr( $o['purge_endpoint'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( home_url( '' ) ); ?>" />
                        <p class="description"><?php esc_html_e( 'Leave blank to use the site address. Behind Cloudflare (orange-cloud) or a proxy, set http://127.0.0.1 so purges reach nginx directly.', 'nginx-cache-purger' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Verify SSL', 'nginx-cache-purger' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr( NCP_OPTION ); ?>[purge_sslverify]" value="1" <?php checked( $o['purge_sslverify'] ); ?> />
                            <?php esc_html_e( 'Verify the TLS certificate on purge/warm requests.', 'nginx-cache-purger' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'Turn off only when using an http://127.0.0.1 endpoint or a hostname the certificate does not cover.', 'nginx-cache-purger' ); ?></p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>

        <hr />

        <h2><?php esc_html_e( 'WP-Cron', 'nginx-cache-purger' ); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Status', 'nginx-cache-purger' ); ?></th>
                <td>
                    <?php if ( $cron_disabled ) : ?>
                        <p><span style="color:#00a32a;">&#10003;</span> <?php esc_html_e( 'DISABLE_WP_CRON is set — WordPress relies on your system cron.', 'nginx-cache-purger' ); ?></p>
                    <?php else : ?>
                        <p><?php esc_html_e( 'WP-Cron runs on page visits. On a quiet site that means warming lags. For reliable warming, set DISABLE_WP_CRON and add a real cron job.', 'nginx-cache-purger' ); ?></p>
                        <p>
                            <button type="button" class="button" id="ncp-cron-setup"><?php esc_html_e( 'Set DISABLE_WP_CRON in wp-config.php', 'nginx-cache-purger' ); ?></button>
                            <span id="ncp-cron-setup-result"></span>
                        </p>
                    <?php endif; ?>
                    <p class="description"><?php esc_html_e( 'Then add this to your server crontab (every minute):', 'nginx-cache-purger' ); ?></p>
                    <p><code>* * * * * cd <?php echo esc_html( $wp_path ); ?> &amp;&amp; wp cron event run --due-now &gt;/dev/null 2&gt;&amp;1</code></p>
                    <p class="description"><?php esc_html_e( 'Or, without WP-CLI:', 'nginx-cache-purger' ); ?> <code>* * * * * curl -s <?php echo esc_html( site_url( 'wp-cron.php?doing_wp_cron' ) ); ?> &gt;/dev/null 2&gt;&amp;1</code></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Last worker run', 'nginx-cache-purger' ); ?></th>
                <td>
                    <?php if ( $last_run ) : ?>
                        <p><?php echo esc_html( sprintf( /* translators: %s: human time diff */ __( '%s ago', 'nginx-cache-purger' ), human_time_diff( $last_run ) ) ); ?>
                        <?php if ( time() - $last_run > 300 ) : ?>
                            <span style="color:#d63638;">&#9888; <?php esc_html_e( 'more than 5 minutes ago — cron may not be firing', 'nginx-cache-purger' ); ?></span>
                        <?php endif; ?>
                        </p>
                    <?php else : ?>
                        <p><?php esc_html_e( 'The worker has not run yet.', 'nginx-cache-purger' ); ?></p>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <hr />

        <h2><?php esc_html_e( 'Cache self-test', 'nginx-cache-purger' ); ?></h2>
        <p><?php esc_html_e( 'Fetches the home page twice and reads the X-FastCGI-Cache header to confirm caching is active.', 'nginx-cache-purger' ); ?></p>
        <p>
            <button type="button" class="button button-secondary" id="ncp-cache-test"><?php esc_html_e( 'Run cache test', 'nginx-cache-purger' ); ?></button>
            <span id="ncp-cache-test-result" style="margin-left:8px;"></span>
        </p>
    </div>
    <?php
}
