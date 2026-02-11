<?php
/**
 * Fired when the plugin is uninstalled (deleted) from WordPress.
 *
 * This file is executed when the plugin is deleted via the WordPress
 * admin interface. It removes all plugin data including database tables
 * and options for a clean uninstall.
 *
 * @package Synditracker
 * @since   1.0.0
 */

// Exit if not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

/**
 * List of options to remove.
 *
 * @since 1.0.0
 */
$options = array(
    'synditracker_db_version',
    'synditracker_alert_settings',
    'synditracker_spike_threshold',
    'synditracker_discord_webhook',
    'synditracker_log_level',
    'synditracker_site_keys',
);

// Delete all plugin options.
foreach ( $options as $option ) {
    delete_option( $option );
}

/**
 * List of database tables to drop.
 *
 * @since 1.0.0
 */
$tables = array(
    $wpdb->prefix . 'synditracker_logs',
    $wpdb->prefix . 'synditracker_keys',
);

// Drop all plugin tables.
foreach ( $tables as $table ) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
    $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
}

/**
 * Clean up transients.
 *
 * @since 1.0.6
 */
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_synditracker_%' OR option_name LIKE '_transient_timeout_synditracker_%'"
);

/**
 * Clean up scheduled events.
 *
 * @since 1.0.6
 */
wp_clear_scheduled_hook( 'synditracker_heartbeat_event' );

/**
 * Clean up log files.
 *
 * @since 1.0.6
 */
$upload_dir = wp_upload_dir();
$log_dir    = $upload_dir['basedir'] . '/synditracker-logs';

if ( is_dir( $log_dir ) ) {
    $files = glob( $log_dir . '/*' );
    if ( $files ) {
        foreach ( $files as $file ) {
            if ( is_file( $file ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                unlink( $file );
            }
        }
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
    rmdir( $log_dir );
}
