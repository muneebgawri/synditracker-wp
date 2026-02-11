<?php
/**
 * Keys Management Class.
 *
 * Handles API key generation, validation, and management.
 *
 * @package Synditracker
 * @since   1.0.0
 */

namespace Synditracker\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Keys Management Class.
 *
 * @since 1.0.0
 */
class Keys {

    /**
     * Singleton instance of this class.
     *
     * @since 1.0.0
     * @var Keys|null
     */
    private static $instance = null;

    /**
     * Keys table name.
     *
     * @since 1.0.6
     * @var string
     */
    private $table_name;

    /**
     * Constructor.
     *
     * @since 1.0.6
     */
    public function __construct() {
        global $wpdb;
        $table       = defined( 'SYNDITRACKER_TABLE_KEYS' ) ? SYNDITRACKER_TABLE_KEYS : 'synditracker_keys';
        $this->table_name = $wpdb->prefix . $table;
    }

    /**
     * Get the singleton instance.
     *
     * @since  1.0.0
     * @return Keys
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Generate a new unique API key.
     *
     * @since  1.0.0
     * @since  1.0.6 Added return value validation and logging.
     * @param  string $site_name The site name for the key.
     * @return string|false The generated key value on success, false on failure.
     */
    public function generate_key( $site_name ) {
        global $wpdb;

        $key_value = 'MG-' . strtoupper( wp_generate_password( 16, false ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'key_value' => $key_value,
                'site_name' => sanitize_text_field( $site_name ),
                'status'    => 'active',
            ),
            array( '%s', '%s', '%s' )
        );

        if ( false === $result ) {
            Logger::get_instance()->log(
                sprintf( 'Failed to generate key for site: %s. Error: %s', $site_name, $wpdb->last_error ),
                'ERROR'
            );
            return false;
        }

        /**
         * Fires after a new API key is generated.
         *
         * @since 1.0.6
         * @param string $key_value The generated key value.
         * @param string $site_name The associated site name.
         * @param int    $key_id    The database ID of the key.
         */
        do_action( 'synditracker_key_generated', $key_value, $site_name, $wpdb->insert_id );

        Logger::get_instance()->log(
            sprintf( 'New key generated for site: %s', $site_name ),
            'INFO'
        );

        return $key_value;
    }

    /**
     * Revoke a key.
     *
     * @since  1.0.0
     * @since  1.0.6 Added return value and logging.
     * @param  int $id The key ID.
     * @return bool True on success, false on failure.
     */
    public function revoke_key( $id ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->update(
            $this->table_name,
            array( 'status' => 'revoked' ),
            array( 'id' => (int) $id ),
            array( '%s' ),
            array( '%d' )
        );

        if ( false === $result ) {
            Logger::get_instance()->log(
                sprintf( 'Failed to revoke key ID: %d', $id ),
                'ERROR'
            );
            return false;
        }

        /**
         * Fires after a key is revoked.
         *
         * @since 1.0.6
         * @param int $id The revoked key ID.
         */
        do_action( 'synditracker_key_revoked', $id );

        Logger::get_instance()->log(
            sprintf( 'Key ID %d revoked', $id ),
            'INFO'
        );

        return true;
    }

    /**
     * Delete a key.
     *
     * @since  1.0.0
     * @since  1.0.6 Added return value and logging.
     * @param  int $id The key ID.
     * @return bool True on success, false on failure.
     */
    public function delete_key( $id ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->delete(
            $this->table_name,
            array( 'id' => (int) $id ),
            array( '%d' )
        );

        if ( false === $result ) {
            Logger::get_instance()->log(
                sprintf( 'Failed to delete key ID: %d', $id ),
                'ERROR'
            );
            return false;
        }

        /**
         * Fires after a key is deleted.
         *
         * @since 1.0.6
         * @param int $id The deleted key ID.
         */
        do_action( 'synditracker_key_deleted', $id );

        Logger::get_instance()->log(
            sprintf( 'Key ID %d deleted', $id ),
            'INFO'
        );

        return true;
    }

    /**
     * Check if a key is valid and active.
     *
     * @since  1.0.0
     * @param  string $key_value The key value to validate.
     * @return int|false The key ID if valid, false otherwise.
     */
    public function is_valid_key( $key_value ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $key_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$this->table_name} WHERE key_value = %s AND status = 'active'",
                $key_value
            )
        );

        return $key_id ? (int) $key_id : false;
    }

    /**
     * Refresh the last_seen timestamp for a key.
     *
     * @since  1.0.0
     * @param  int $key_id The key ID.
     * @return bool True on success, false on failure.
     */
    public function refresh_last_seen( $key_id ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->update(
            $this->table_name,
            array( 'last_seen' => current_time( 'mysql' ) ),
            array( 'id' => (int) $key_id ),
            array( '%s' ),
            array( '%d' )
        );

        return false !== $result;
    }

    /**
     * Get all keys.
     *
     * @since  1.0.0
     * @return array Array of key objects.
     */
    public function get_keys() {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_results(
            "SELECT * FROM {$this->table_name} ORDER BY created_at DESC"
        );
    }

    /**
     * Get a single key by ID.
     *
     * @since  1.0.6
     * @param  int $id The key ID.
     * @return object|null The key object or null if not found.
     */
    public function get_key( $id ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE id = %d",
                (int) $id
            )
        );
    }

    /**
     * Get active keys count.
     *
     * @since  1.0.6
     * @return int The count of active keys.
     */
    public function get_active_keys_count() {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'active'"
        );
    }
}
