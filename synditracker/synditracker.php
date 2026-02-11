<?php
/**
 * Plugin Name: Synditracker Core
 * Plugin URI: https://muneebgawri.com
 * Description: A professional-grade syndication tracking system to monitor how content spreads across partner sites.
 * Version: 1.0.6
 * Author: Muneeb Gawri
 * Author URI: https://muneebgawri.com
 * Text Domain: synditracker
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @package Synditracker
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Plugin version.
 *
 * @since 1.0.0
 * @var string
 */
define( 'SYNDITRACKER_VERSION', '1.0.6' );

/**
 * Plugin file path.
 *
 * @since 1.0.0
 * @var string
 */
define( 'SYNDITRACKER_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Plugin URL.
 *
 * @since 1.0.0
 * @var string
 */
define( 'SYNDITRACKER_URL', plugin_dir_url( __FILE__ ) );

/**
 * Plugin file.
 *
 * @since 1.0.6
 * @var string
 */
define( 'SYNDITRACKER_FILE', __FILE__ );

/**
 * REST API namespace.
 *
 * @since 1.0.6
 * @var string
 */
define( 'SYNDITRACKER_REST_NAMESPACE', 'synditracker/v1' );

/**
 * Database table name for logs.
 *
 * @since 1.0.6
 * @var string
 */
define( 'SYNDITRACKER_TABLE_LOGS', 'synditracker_logs' );

/**
 * Database table name for keys.
 *
 * @since 1.0.6
 * @var string
 */
define( 'SYNDITRACKER_TABLE_KEYS', 'synditracker_keys' );

/**
 * Default spike threshold.
 *
 * @since 1.0.6
 * @var int
 */
define( 'SYNDITRACKER_DEFAULT_THRESHOLD', 5 );

/**
 * Allowed aggregators whitelist.
 *
 * @since 1.0.6
 * @var array
 */
define( 'SYNDITRACKER_ALLOWED_AGGREGATORS', array( 'Feedzy', 'WPeMatico', 'Unknown', 'Test', 'RSS Autopilot', 'CyberSEO', 'WP RSS Aggregator' ) );

/**
 * Rate limit window in seconds.
 *
 * @since 1.0.6
 * @var int
 */
define( 'SYNDITRACKER_RATE_LIMIT_WINDOW', 60 );

/**
 * Rate limit max requests per window.
 *
 * @since 1.0.6
 * @var int
 */
define( 'SYNDITRACKER_RATE_LIMIT_MAX', 60 );

/**
 * Main Synditracker Core Class.
 *
 * @since   1.0.0
 * @package Synditracker
 */
class Synditracker_Core {

    /**
     * Singleton instance of this class.
     *
     * @since 1.0.0
     * @var Synditracker_Core|null
     */
    private static $instance = null;

    /**
     * Get the singleton instance of this class.
     *
     * @since  1.0.0
     * @return Synditracker_Core
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     *
     * @since 1.0.0
     */
    private function __construct() {
        $this->init();
    }

    /**
     * Initialize the plugin.
     *
     * @since 1.0.0
     * @return void
     */
    private function init() {
        // Load text domain for i18n.
        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ), 5 );

        // Activation hook.
        register_activation_hook( SYNDITRACKER_FILE, array( $this, 'activate' ) );

        // Load dependencies.
        $this->load_dependencies();

        // Initialize components.
        add_action( 'plugins_loaded', array( $this, 'init_components' ) );

        // Cron schedules.
        add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );
    }

    /**
     * Load the plugin text domain for translation.
     *
     * @since  1.0.6
     * @return void
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'synditracker',
            false,
            dirname( plugin_basename( SYNDITRACKER_FILE ) ) . '/languages'
        );
    }

    /**
     * Add custom cron schedules.
     *
     * @since  1.0.0
     * @param  array $schedules Existing cron schedules.
     * @return array Modified cron schedules.
     */
    public function add_cron_schedules( $schedules ) {
        $schedules['6h'] = array(
            'interval' => 6 * HOUR_IN_SECONDS,
            'display'  => esc_html__( 'Every 6 Hours', 'synditracker' ),
        );
        return $schedules;
    }

    /**
     * Activate the plugin.
     *
     * @since  1.0.0
     * @return void
     */
    public function activate() {
        $this->create_db_table();
    }

    /**
     * Create the custom database tables.
     *
     * @since  1.0.0
     * @return void
     */
    private function create_db_table() {
        global $wpdb;
        $table_logs      = $wpdb->prefix . SYNDITRACKER_TABLE_LOGS;
        $table_keys      = $wpdb->prefix . SYNDITRACKER_TABLE_KEYS;
        $charset_collate = $wpdb->get_charset_collate();

        $sql_logs = "CREATE TABLE {$table_logs} (
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
        ) {$charset_collate};";

        $sql_keys = "CREATE TABLE {$table_keys} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            key_value VARCHAR(64) NOT NULL,
            site_name VARCHAR(255) NOT NULL,
            status VARCHAR(20) DEFAULT 'active' NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
            last_seen DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY key_value (key_value)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_logs );
        dbDelta( $sql_keys );
    }

    /**
     * Load required dependency files.
     *
     * @since  1.0.0
     * @return void
     */
    private function load_dependencies() {
        require_once SYNDITRACKER_PATH . 'includes/class-logger.php';
        require_once SYNDITRACKER_PATH . 'includes/class-db.php';
        require_once SYNDITRACKER_PATH . 'includes/class-keys.php';
        require_once SYNDITRACKER_PATH . 'includes/class-alerts.php';
        require_once SYNDITRACKER_PATH . 'includes/class-rest-api.php';
        require_once SYNDITRACKER_PATH . 'includes/class-admin.php';
    }

    /**
     * Initialize plugin components.
     *
     * @since  1.0.0
     * @return void
     */
    public function init_components() {
        // Check for DB updates.
        if ( get_option( 'synditracker_db_version' ) !== SYNDITRACKER_VERSION ) {
            $this->activate();
            update_option( 'synditracker_db_version', SYNDITRACKER_VERSION );
        }

        require_once SYNDITRACKER_PATH . 'includes/class-github-updater.php';
        new \Synditracker_GitHub_Updater(
            SYNDITRACKER_FILE,
            'muneebgawri/synditracker-wp',
            SYNDITRACKER_VERSION,
            'Synditracker Hub'
        );

        Synditracker\Core\DB::get_instance();
        Synditracker\Core\REST_API::get_instance();
        Synditracker\Core\Admin::get_instance();
        Synditracker\Core\Keys::get_instance();
        Synditracker\Core\Alerts::get_instance();

        // Ensure heartbeats are scheduled correctly.
        $this->ensure_heartbeats_scheduled();

        /**
         * Fires after all Synditracker components have been initialized.
         *
         * @since 1.0.6
         */
        do_action( 'synditracker_initialized' );
    }

    /**
     * Ensure heartbeat crons are scheduled based on settings.
     *
     * @since  1.0.0
     * @return void
     */
    private function ensure_heartbeats_scheduled() {
        $settings   = get_option( 'synditracker_alert_settings', array() );
        $frequency  = isset( $settings['alert_frequency'] ) ? $settings['alert_frequency'] : 'immediate';
        $event_name = 'synditracker_heartbeat_event';

        // Clear existing to avoid conflicts on setting change.
        wp_clear_scheduled_hook( $event_name );

        if ( 'immediate' === $frequency ) {
            return;
        }

        $schedule = 'daily';
        if ( '6h' === $frequency ) {
            $schedule = '6h';
        } elseif ( 'weekly' === $frequency ) {
            $schedule = 'weekly';
        }

        if ( ! wp_next_scheduled( $event_name ) ) {
            wp_schedule_event( time(), $schedule, $event_name );
        }
    }
}

// Start the plugin.
Synditracker_Core::get_instance();
