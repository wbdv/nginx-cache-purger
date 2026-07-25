<?php
/**
 * Runs on plugin deletion. Removes the settings option, the cron bookkeeping
 * options, and the warm-queue table.
 *
 * @package Nginx_Cache_Purger
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'ngxcp_options' );
delete_option( 'ngxcp_cron_last_run' );
delete_option( 'ngxcp_warm_db_version' );

global $wpdb;
$ngxcp_table = $wpdb->prefix . 'ngxcp_warm_queue';
// Table name from $wpdb->prefix, not user input; DDL cannot be cached/parameterised.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
$wpdb->query( "DROP TABLE IF EXISTS {$ngxcp_table}" );
