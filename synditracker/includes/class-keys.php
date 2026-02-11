<?php
namespace Synditracker\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Keys Management Class.
 */
class Keys
{
    /**
     * Instance of this class.
     */
    private static $instance = null;

    /**
     * Get instance.
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Generate a new unique key.
     */
    public function generate_key($site_name)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'synditracker_keys';

        $key_value = 'MG-' . strtoupper(wp_generate_password(16, false));

        $wpdb->insert(
            $table_name,
            array(
                'key_value' => $key_value,
                'site_name' => sanitize_text_field($site_name),
                'status'    => 'active',
            ),
            array('%s', '%s', '%s')
        );

        return $key_value;
    }

    /**
     * Revoke a key.
     */
    public function revoke_key($id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'synditracker_keys';

        $wpdb->update(
            $table_name,
            array('status' => 'revoked'),
            array('id' => (int) $id),
            array('%s'),
            array('%d')
        );
    }

    /**
     * Delete a key.
     */
    public function delete_key($id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'synditracker_keys';

        $wpdb->delete($table_name, array('id' => (int) $id), array('%d'));
    }

    /**
     * Validate a key.
     */
    public function is_valid_key($key_value)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'synditracker_keys';

        $query = $wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE key_value = %s AND status = 'active'",
            $key_value
        );

        return (int) $wpdb->get_var($query) > 0;
    }

    /**
     * Get all keys.
     */
    public function get_keys()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'synditracker_keys';

        return $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC");
    }
}
