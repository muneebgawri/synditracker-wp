<?php
/**
 * Fired when the plugin is uninstalled (deleted) from WordPress.
 *
 * This file is executed when the plugin is deleted via the WordPress
 * admin interface. It removes all plugin data including options
 * and log files for a clean uninstall.
 *
 * @package Synditracker
 * @since   1.0.0
 */

// Exit if not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * List of options to remove.
 *
 * @since 1.0.0
 */
$options = array(
    'st_agent_hub_url',
    'st_agent_site_key',
);

// Delete all plugin options.
foreach ( $options as $option ) {
    delete_option( $option );
}

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
