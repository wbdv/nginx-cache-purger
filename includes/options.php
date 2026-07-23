<?php
/**
 * Plugin options.
 *
 * Everything here is optional: the plugin works with no options set at all, the
 * defaults below reproduce the original zero-config behaviour. The Settings page
 * (includes/settings.php) writes these; the purge and warm code reads them.
 *
 * @package Nginx_Cache_Purger
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Option key holding the settings array.
 */
const NCP_OPTION = 'ncp_options';

/**
 * Default settings. Chosen so a fresh install behaves exactly as 1.0.x did.
 *
 * @return array
 */
function ncp_default_options() {
    return array(
        'warmer_enabled'  => false, // Cache warming off by default.
        'warm_max_urls'   => 15,    // Cap on URLs warmed after a full purge.
        'purge_endpoint'  => '',    // Empty = derive from home_url().
        'purge_sslverify' => true,  // Verify TLS on the purge/warm request.
    );
}

/**
 * All settings, saved values merged over the defaults.
 *
 * @return array
 */
function ncp_get_options() {
    $saved = get_option( NCP_OPTION, array() );
    if ( ! is_array( $saved ) ) {
        $saved = array();
    }
    return array_merge( ncp_default_options(), $saved );
}

/**
 * A single setting.
 *
 * @param string $key
 * @return mixed
 */
function ncp_get_option( $key ) {
    $options = ncp_get_options();
    return isset( $options[ $key ] ) ? $options[ $key ] : null;
}
