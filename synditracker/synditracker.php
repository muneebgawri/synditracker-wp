<?php
/**
 * Plugin Name: Synditracker Core
 * Plugin URI: https://muneebgawri.com
 * Description: A professional-grade syndication tracking system to monitor how content spreads across partner sites.
 * Version: 1.0.2
 * Author: Muneeb Gawri
 * Author URI: https://muneebgawri.com
 * Text Domain: synditracker
 * Domain Path: /languages
 *
 * @package Synditracker
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define constants.
define('SYNDITRACKER_VERSION', '1.0.2');
define('SYNDITRACKER_PATH', plugin_dir_path(__FILE__));
define('SYNDITRACKER_URL', plugin_dir_url(__FILE__));

/**
 * Main Synditracker Core Class.
 */
class Synditracker_Core
{
    /**
     * Instance of this class.
     */
    private static $instance = null;

    /**
     * Get instance of this class.
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct()
    {
        $this->init();
    }

    /**
     * Initialize the plugin.
     */
    private function init()
    {
        // Activation hook.
        register_activation_hook(__FILE__, array($this, 'activate'));

        // Load dependencies.
        $this->load_dependencies();

        // Initialize components.
        add_action('plugins_loaded', array($this, 'init_components'));

        // Cron schedules.
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
    }

    /**
     * Add custom cron schedules.
     */
    public function add_cron_schedules($schedules)
    {
        $schedules['6h'] = array(
            'interval' => 6 * HOUR_IN_SECONDS,
            'display'  => esc_html__('Every 6 Hours'),
        );
        return $schedules;
    }

    /**
     * Activate the plugin.
     */
    public function activate()
    {
        $this->create_db_table();
    }

    /**
     * Create the custom database table.
     */
    private function create_db_table()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'synditracker_logs';
        $charset_collate = $wpdb->get_charset_collate();

        $sql_logs = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT(20) UNSIGNED NOT NULL,
            site_url VARCHAR(255) NOT NULL,
            site_name VARCHAR(255) NOT NULL,
            aggregator VARCHAR(50) NOT NULL,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
            is_duplicate TINYINT(1) DEFAULT 0 NOT NULL,
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY site_url (site_url),
            KEY timestamp (timestamp)
        ) $charset_collate;";

        $table_keys = $wpdb->prefix . 'synditracker_keys';
        $sql_keys = "CREATE TABLE $table_keys (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            key_value VARCHAR(64) NOT NULL,
            site_name VARCHAR(255) NOT NULL,
            status VARCHAR(20) DEFAULT 'active' NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
            last_seen DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY key_value (key_value)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_logs);
        dbDelta($sql_keys);
    }

    /**
     * Load required files.
     */
    private function load_dependencies()
    {
        require_once SYNDITRACKER_PATH . 'includes/class-rest-api.php';
        require_once SYNDITRACKER_PATH . 'includes/class-admin.php';
        require_once SYNDITRACKER_PATH . 'includes/class-db.php';
        require_once SYNDITRACKER_PATH . 'includes/class-keys.php';
        require_once SYNDITRACKER_PATH . 'includes/class-alerts.php';
    }

    /**
     * Initialize components.
     */
    public function init_components()
    {
        // Check for DB updates.
        if (get_option('synditracker_db_version') !== SYNDITRACKER_VERSION) {
            $this->activate();
            update_option('synditracker_db_version', SYNDITRACKER_VERSION);
        }

        require_once SYNDITRACKER_PATH . 'includes/class-github-updater.php';
        new \Synditracker_GitHub_Updater(__FILE__, 'muneebgawri/synditracker-wp', SYNDITRACKER_VERSION, 'Synditracker Hub');

        Synditracker\Core\DB::get_instance();
        Synditracker\Core\REST_API::get_instance();
        Synditracker\Core\Admin::get_instance();
        Synditracker\Core\Keys::get_instance();
        Synditracker\Core\Alerts::get_instance();

        // Ensure heartbeats are scheduled correctly.
        $this->ensure_heartbeats_scheduled();
    }

    /**
     * Ensure heartbeat crons are scheduled based on settings.
     */
    private function ensure_heartbeats_scheduled()
    {
        $settings = get_option('synditracker_alert_settings', array());
        $frequency = isset($settings['alert_frequency']) ? $settings['alert_frequency'] : 'immediate';
        $event_name = 'synditracker_heartbeat_event';

        // Clear existing to avoid conflicts on setting change.
        wp_clear_scheduled_hook($event_name);

        if ($frequency === 'immediate') {
            return;
        }

        $schedule = ($frequency === '6h') ? '6h' : (($frequency === 'weekly') ? 'weekly' : 'daily');

        if (!wp_next_scheduled($event_name)) {
            wp_schedule_event(time(), $schedule, $event_name);
        }
    }
}

// Start the plugin.
Synditracker_Core::get_instance();
