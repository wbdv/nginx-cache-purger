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

delete_option( 'ncp_options' );
delete_option( 'ncp_cron_last_run' );
delete_option( 'ncp_warm_db_version' );

global $wpdb;
$table = $wpdb->prefix . 'ncp_warm_queue';
// Table name from $wpdb->prefix, not user input; DDL cannot be cached/parameterised.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
