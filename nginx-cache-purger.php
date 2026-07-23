<?php
/**
 * Plugin Name: Nginx Cache Purger
 * Plugin URI:  https://github.com/wbdv/nginx-cache-purger
 * Description: Manages Nginx FastCGI cache for WordPress with global and automatic purging for posts, pages, and WooCommerce products/categories.
 * Version:     1.0.1
 * Author:      wbdv
 * Author URI:  https://github.com/wbdv/nginx-cache-purger
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: nginx-cache-purger
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

define( 'NCP_VERSION', '1.0.1' );

/**
 * Add the "Purge Nginx Cache" button to the WordPress admin bar.
 */
function ncp_add_purge_button_to_admin_bar( $wp_admin_bar ) {
    // Check if the current user can manage options (typically administrators)
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Add the main purge button
    $wp_admin_bar->add_node( array(
        'id'    => 'ncp-purge-nginx-cache',
        'title' => '<span class="ab-icon dashicons-before dashicons-image-rotate"></span> ' . __( 'Purge Nginx Cache', 'nginx-cache-purger' ),
        'href'  => '#', // This will be handled by JavaScript
        'meta'  => array(
            'title' => __( 'Purge All Nginx Cache', 'nginx-cache-purger' ),
            'class' => 'ncp-purge-button', // Add a class for JavaScript targeting
        ),
    ) );
}
add_action( 'admin_bar_menu', 'ncp_add_purge_button_to_admin_bar', 999 ); // High priority to appear towards the right

/**
 * Enqueue JavaScript and localize script for AJAX handling.
 */
function ncp_enqueue_scripts() {
    /*
     * The button lives in the admin bar, which also renders on the front end.
     * Testing is_admin() here would have loaded the script only in wp-admin,
     * leaving the front-end button inert. Gate on the admin bar instead.
     */
    if ( ! current_user_can( 'manage_options' ) || ! is_admin_bar_showing() ) {
        return;
    }

    wp_enqueue_style(
        'ncp-purge-style',
        plugins_url( 'purge-style.css', __FILE__ ),
        array(),
        NCP_VERSION
    );

    wp_enqueue_script(
        'ncp-purge-script',
        plugins_url( 'purge-script.js', __FILE__ ),
        array( 'jquery' ),
        NCP_VERSION,
        true // Load in footer
    );

    // Pass necessary data to the JavaScript file
    wp_localize_script(
        'ncp-purge-script',
        'ncp_ajax_object',
        array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'ncp_purge_nonce' ), // Create a security nonce
            'purging_message' => __( 'Purging Nginx cache...', 'nginx-cache-purger' ),
            'success_message' => __( 'Nginx cache purged successfully!', 'nginx-cache-purger' ),
            'error_message'   => __( 'Error purging Nginx cache. Check server logs.', 'nginx-cache-purger' ),
            'permission_error' => __( 'You do not have permission to purge the cache.', 'nginx-cache-purger' ),
            'dismiss_label'   => __( 'Dismiss this notice.', 'nginx-cache-purger' ),
        )
    );
}
add_action( 'admin_enqueue_scripts', 'ncp_enqueue_scripts' );
add_action( 'wp_enqueue_scripts', 'ncp_enqueue_scripts' ); // Also for frontend admin bar

/**
 * Helper function for logging messages only when WP_DEBUG_LOG is enabled.
 *
 * @param string $message The message to log.
 */
function _ncp_log( $message ) {
    if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG === true ) {
        /*
         * Diagnostic logging, and the only way to see why a purge failed: the
         * requests are fired from hooks with no UI to report back to. It is
         * unreachable unless the site owner has explicitly turned WP_DEBUG_LOG
         * on, so it never writes anything on a production install.
         */
        error_log( 'NCP: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    }
}

/**
 * Scheme + host of the purge endpoint, derived from the site's own configuration.
 *
 * Never build this from $_SERVER['HTTP_HOST']: that value is supplied by the
 * client and is only trustworthy if the web server rejects unknown Host
 * headers. Using it to construct an outbound request URL is the classic SSRF
 * pattern — a spoofed Host during any request that triggers a purge would make
 * the site fire an authenticated-looking request at an attacker's server.
 * home_url() comes from the database and cannot be influenced by the request.
 *
 * Filter 'ncp_purge_endpoint' if purges must go somewhere else than the public
 * hostname — e.g. when the site sits behind a CDN/proxy and the purge has to be
 * delivered to the origin directly.
 *
 * @return string Scheme and host, no trailing slash (e.g. https://example.com).
 */
function ncp_purge_endpoint() {
    $parts = wp_parse_url( home_url( '/' ) );

    if ( empty( $parts['host'] ) ) {
        return '';
    }

    $scheme = ( ! empty( $parts['scheme'] ) ) ? $parts['scheme'] : 'https';
    $host   = $parts['host'];

    if ( ! empty( $parts['port'] ) ) {
        $host .= ':' . $parts['port'];
    }

    return apply_filters( 'ncp_purge_endpoint', $scheme . '://' . $host );
}

/**
 * Build the purge URL for a site path.
 *
 * @param string $path Path to purge, e.g. '/hello-world/' or '/*' for everything.
 * @return string Full purge URL, or '' when the endpoint could not be determined.
 */
function ncp_purge_url( $path ) {
    $endpoint = ncp_purge_endpoint();

    if ( empty( $endpoint ) ) {
        return '';
    }

    if ( '' === $path || '/' !== substr( $path, 0, 1 ) ) {
        $path = '/' . $path;
    }

    return $endpoint . '/purge' . $path;
}

/**
 * Handles sending the purge request to Nginx and logging the response.
 * This function is internal to the plugin and prefixed with _ncp_
 * to indicate it's not meant for direct external use.
 *
 * @param string $purge_url The full URL to send the purge request to.
 * @param string $context_type A string describing the context (e.g., 'post', 'term', 'all').
 * @param int|string $context_id The ID of the item being purged (e.g., post ID, term ID, or 'all').
 * @return array An array with 'success' (bool) and 'message' (string).
 */
function _ncp_send_purge_request( $purge_url, $context_type, $context_id ) {
    if ( empty( $purge_url ) ) {
        _ncp_log( 'Could not determine host for purging ' . $context_type . ' cache for ID ' . $context_id );
        return array( 'success' => false, 'message' => __( 'Could not determine host.', 'nginx-cache-purger' ) );
    }

    _ncp_log( 'Attempting to purge URL: ' . $purge_url . ' for ' . $context_type . ' (ID: ' . $context_id . ')' );

    /*
     * SSL verification stays ON. The purge goes to the site's own public
     * hostname, so the certificate that serves the site validates it. Only
     * disable this (via the filter) if the purge endpoint is reached by IP or
     * over a hostname the certificate does not cover — and understand that
     * doing so means the purge request can be intercepted.
     */
    $response = wp_remote_get( $purge_url, array(
        'timeout'     => 10,
        'sslverify'   => apply_filters( 'ncp_purge_sslverify', true ),
        'redirection' => 0,
        'user-agent'  => 'nginx-cache-purger/' . NCP_VERSION . '; ' . home_url( '/' ),
    ) );

    if ( is_wp_error( $response ) ) {
        _ncp_log( 'Error purging ' . $context_type . ' cache for ID ' . $context_id . ': ' . $response->get_error_message() );
        return array( 'success' => false, 'message' => $response->get_error_message() );
    } else {
        $response_code = wp_remote_retrieve_response_code( $response );
        /*
         * 412 is ngx_cache_purge's "this key was not in the cache". That is a
         * normal outcome (the page simply had not been cached yet), not a
         * failure worth surfacing to the user.
         */
        if ( 412 === (int) $response_code ) {
            _ncp_log( 'Nothing cached for ' . $context_type . ' ID ' . $context_id . ' (URL: ' . $purge_url . ')' );
            return array( 'success' => true, 'message' => __( 'Nothing cached for that URL.', 'nginx-cache-purger' ) );
        }
        if ( $response_code >= 200 && $response_code < 300 ) {
            _ncp_log( 'Successfully purged cache for ' . $context_type . ' ID ' . $context_id . ' (URL: ' . $purge_url . ')' );
            return array( 'success' => true, 'message' => __( 'Cache purged successfully!', 'nginx-cache-purger' ) );
        } else {
            $body = wp_remote_retrieve_body( $response );
            _ncp_log( 'Error purging ' . $context_type . ' cache for ID ' . $context_id . '. HTTP Code: ' . $response_code . ' Body: ' . $body );
            return array( 'success' => false, 'message' => __( 'HTTP Error: ', 'nginx-cache-purger' ) . $response_code . ' - ' . $body );
        }
    }
}


/**
 * Handle the AJAX request to purge Nginx cache.
 */
function ncp_handle_purge_request() {
    // Define messages directly in PHP for server-side use
    $permission_error_msg = __( 'You do not have permission to purge the cache.', 'nginx-cache-purger' );
    $host_error_msg       = __( 'Could not determine host for purge URL.', 'nginx-cache-purger' );
    $purge_error_msg      = __( 'Error purging Nginx cache. Check server logs.', 'nginx-cache-purger' );
    $success_msg          = __( 'Nginx cache purged successfully!', 'nginx-cache-purger' );

    // Sanitize and unslash the nonce before verification to satisfy static analysis tools
    $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

    // Verify nonce for security
    if ( ! wp_verify_nonce( $nonce, 'ncp_purge_nonce' ) ) {
        wp_send_json_error( array( 'message' => $permission_error_msg ) );
        wp_die();
    }

    // Check if the user can manage options
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => $permission_error_msg ) );
        wp_die();
    }

    // Wildcard purge of the whole site. Requires the maintained ngx_cache_purge
    // fork (nginx-modules/ngx_cache_purge); the original FRiCKLE module can only
    // purge one exact key per request.
    $purge_url = ncp_purge_url( '/*' );

    if ( empty( $purge_url ) ) {
        wp_send_json_error( array( 'message' => $host_error_msg ) );
        wp_die();
    }

    // Call the helper function to send the purge request
    $result = _ncp_send_purge_request( $purge_url, 'all', 'all' );

    if ( $result['success'] ) {
        wp_send_json_success( array( 'message' => $success_msg ) );
    } else {
        wp_send_json_error( array( 'message' => $purge_error_msg . ' ' . $result['message'] ) );
    }

    wp_die(); // Always die at the end of an AJAX handler
}
add_action( 'wp_ajax_ncp_purge_nginx_cache', 'ncp_handle_purge_request' ); // For logged-in users
// add_action( 'wp_ajax_nopriv_ncp_purge_nginx_cache', 'ncp_handle_purge_request' ); // Uncomment if you need to allow non-logged-in users (NOT RECOMMENDED for cache purging)


/**
 * Collect every cached URL that a change to $post invalidates.
 *
 * Purging only the post's own permalink leaves the home page, the archives and
 * (for products) the shop page serving the old content until they expire.
 *
 * @param WP_Post $post
 * @return string[] List of site paths.
 */
function ncp_paths_for_post( $post ) {
    $paths = array( '/' );

    $permalink = get_permalink( $post->ID );
    if ( ! empty( $permalink ) ) {
        $parsed = wp_parse_url( $permalink );
        if ( ! empty( $parsed['path'] ) ) {
            $paths[] = $parsed['path'];
        }
    }

    // Archives of every public taxonomy the post belongs to.
    $taxonomies = get_object_taxonomies( $post->post_type, 'objects' );
    foreach ( $taxonomies as $taxonomy ) {
        if ( empty( $taxonomy->public ) ) {
            continue;
        }
        $terms = get_the_terms( $post->ID, $taxonomy->name );
        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            continue;
        }
        foreach ( $terms as $term ) {
            $link = get_term_link( $term, $taxonomy->name );
            if ( is_wp_error( $link ) || empty( $link ) ) {
                continue;
            }
            $parsed = wp_parse_url( $link );
            if ( ! empty( $parsed['path'] ) ) {
                $paths[] = $parsed['path'];
            }
        }
    }

    // WooCommerce shop page, when a product changed.
    if ( 'product' === $post->post_type && function_exists( 'wc_get_page_permalink' ) ) {
        $shop = wc_get_page_permalink( 'shop' );
        if ( ! empty( $shop ) ) {
            $parsed = wp_parse_url( $shop );
            if ( ! empty( $parsed['path'] ) ) {
                $paths[] = $parsed['path'];
            }
        }
    }

    /**
     * Filter the list of paths purged when a post changes.
     *
     * @param string[] $paths
     * @param WP_Post  $post
     */
    $paths = apply_filters( 'ncp_paths_for_post', array_unique( $paths ), $post );

    return $paths;
}

/**
 * Purge Nginx cache when a post's published state changes.
 *
 * Hooked to transition_post_status rather than save_post so that unpublishing
 * and trashing also purge: the old code required the *new* status to be publish,
 * which meant a page pulled offline kept being served from cache.
 *
 * @param string  $new_status
 * @param string  $old_status
 * @param WP_Post $post
 */
function ncp_purge_on_transition_post_status( $new_status, $old_status, $post ) {
    // Bail if it's an autosave or a revision
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( wp_is_post_revision( $post->ID ) ) {
        return;
    }

    // Only post types that have a front-end URL can be in the page cache.
    // Without this the plugin fires a live HTTP round-trip for menu items,
    // ACF field groups, WooCommerce orders and every other internal post type.
    if ( ! is_post_type_viewable( $post->post_type ) ) {
        return;
    }

    // Something must have been publicly visible before or after the change,
    // otherwise there is nothing cached to invalidate (e.g. draft -> draft).
    $was_public = in_array( $old_status, array( 'publish', 'future' ), true );
    $is_public  = in_array( $new_status, array( 'publish', 'future' ), true );
    if ( ! $was_public && ! $is_public ) {
        _ncp_log( 'Post ' . $post->ID . ' went ' . $old_status . ' -> ' . $new_status . '. Nothing to purge.' );
        return;
    }

    foreach ( ncp_paths_for_post( $post ) as $path ) {
        _ncp_send_purge_request( ncp_purge_url( $path ), $post->post_type, $post->ID );
    }
}
add_action( 'transition_post_status', 'ncp_purge_on_transition_post_status', 10, 3 );

/**
 * Purge when a post is permanently deleted (transition_post_status does not fire).
 *
 * @param int $post_id
 */
function ncp_purge_on_delete_post( $post_id ) {
    $post = get_post( $post_id );

    if ( ! $post || wp_is_post_revision( $post_id ) || ! is_post_type_viewable( $post->post_type ) ) {
        return;
    }

    foreach ( ncp_paths_for_post( $post ) as $path ) {
        _ncp_send_purge_request( ncp_purge_url( $path ), $post->post_type, $post_id );
    }
}
add_action( 'before_delete_post', 'ncp_purge_on_delete_post' );


/**
 * Purge Nginx cache for a taxonomy term that was created or edited.
 *
 * @param int    $term_id  The term ID.
 * @param int    $tt_id    The term taxonomy ID.
 * @param string $taxonomy The taxonomy slug.
 */
function ncp_purge_on_edit_term( $term_id, $tt_id, $taxonomy ) {
    $taxonomy_object = get_taxonomy( $taxonomy );

    // Only public taxonomies have cacheable archive pages.
    if ( ! $taxonomy_object || empty( $taxonomy_object->public ) ) {
        _ncp_log( 'edited_term triggered for non-public taxonomy: ' . $taxonomy . '. Skipping purge for ID: ' . $term_id );
        return;
    }

    // Get the term object
    $term = get_term( $term_id, $taxonomy );

    // If term is not found or is a WP_Error, log and exit
    if ( is_wp_error( $term ) || ! $term ) {
        _ncp_log( 'Could not retrieve term object for ID ' . $term_id . ' in taxonomy ' . $taxonomy );
        return;
    }

    // Get the permalink of the term (category)
    $permalink = get_term_link( $term, $taxonomy );

    // If permalink is empty or a WP_Error, log and exit
    if ( is_wp_error( $permalink ) || empty( $permalink ) ) {
        _ncp_log( 'Could not get permalink for term ID ' . $term_id . ' in taxonomy ' . $taxonomy . ': ' . ( is_wp_error( $permalink ) ? $permalink->get_error_message() : 'Empty permalink' ) );
        return;
    }

    // Extract the path from the permalink to use in the purge URL
    $parsed_url = wp_parse_url( $permalink );
    $path = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '/';

    _ncp_send_purge_request( ncp_purge_url( $path ), $taxonomy, $term_id );
    // The term's archive is usually linked from the home page / menus too.
    _ncp_send_purge_request( ncp_purge_url( '/' ), $taxonomy, $term_id );
}
add_action( 'edited_term', 'ncp_purge_on_edit_term', 10, 3 );
add_action( 'create_term', 'ncp_purge_on_edit_term', 10, 3 ); // Also purge on new term creation

/**
 * Purge when a term is deleted.
 *
 * delete_term fires *after* the term is gone, so get_term() returns null and the
 * shared edited_term handler bailed out without ever purging. The deleted term
 * object is passed as the 4th argument — use that instead.
 *
 * @param int     $term_id
 * @param int     $tt_id
 * @param string  $taxonomy
 * @param WP_Term $deleted_term
 */
function ncp_purge_on_delete_term( $term_id, $tt_id, $taxonomy, $deleted_term ) {
    $taxonomy_object = get_taxonomy( $taxonomy );

    if ( ! $taxonomy_object || empty( $taxonomy_object->public ) ) {
        return;
    }

    if ( is_wp_error( $deleted_term ) || ! $deleted_term ) {
        return;
    }

    // get_term_link() would fail for a term that no longer exists, so rebuild
    // the archive path from the taxonomy's rewrite rules and the term slug.
    $base = ! empty( $taxonomy_object->rewrite['slug'] ) ? $taxonomy_object->rewrite['slug'] : $taxonomy;
    $path = '/' . trim( $base, '/' ) . '/' . $deleted_term->slug . '/';

    _ncp_send_purge_request( ncp_purge_url( $path ), $taxonomy, $term_id );
    _ncp_send_purge_request( ncp_purge_url( '/' ), $taxonomy, $term_id );
}
add_action( 'delete_term', 'ncp_purge_on_delete_term', 10, 4 );
