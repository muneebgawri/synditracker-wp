<?php
/**
 * Plugin Name: Synditracker Agent
 * Plugin URI: https://muneebgawri.com
 * Description: A lightweight agent for partner sites to report syndicated content back to the Synditracker Core.
 * Version: 1.0.5
 * Author: Muneeb Gawri
 * Author URI: https://muneebgawri.com
 * Text Domain: synditracker-agent
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @package Synditracker
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define constants.
define( 'SYNDITRACKER_AGENT_VERSION', '1.0.5' );
define( 'SYNDITRACKER_AGENT_PATH', plugin_dir_path( __FILE__ ) );
define( 'SYNDITRACKER_AGENT_URL', plugin_dir_url( __FILE__ ) );
define( 'SYNDITRACKER_REST_NAMESPACE', 'synditracker/v1' );

/**
 * Main Synditracker Agent Class.
 *
 * @since 1.0.0
 */
class Synditracker_Agent {

    /**
     * Instance of this class.
     *
     * @since 1.0.0
     * @var Synditracker_Agent|null
     */
    private static $instance = null;

    /**
     * Get singleton instance.
     *
     * @since  1.0.0
     * @return Synditracker_Agent
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
        $this->load_textdomain();
        $this->init();
    }

    /**
     * Load plugin textdomain for translations.
     *
     * @since  1.0.5
     * @return void
     */
    private function load_textdomain() {
        load_plugin_textdomain(
            'synditracker-agent',
            false,
            dirname( plugin_basename( __FILE__ ) ) . '/languages'
        );
    }

    /**
     * Initialize the plugin.
     *
     * @since  1.0.0
     * @return void
     */
    private function init() {
        add_action( 'wp_insert_post', array( $this, 'handle_new_post' ), 10, 3 );

        // Load required classes.
        require_once SYNDITRACKER_AGENT_PATH . 'includes/class-github-updater.php';
        require_once SYNDITRACKER_AGENT_PATH . 'includes/class-logger.php';

        new \Synditracker_GitHub_Updater( __FILE__, 'muneebgawri/synditracker-wp', SYNDITRACKER_AGENT_VERSION, 'Synditracker Agent' );

        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_st_agent_save_settings', array( $this, 'ajax_save_settings' ) );

        // Clear logs action.
        add_action( 'admin_post_st_agent_clear_logs', array( $this, 'handle_clear_logs' ) );

        /**
         * Fires after Synditracker Agent is fully initialized.
         *
         * @since 1.0.5
         */
        do_action( 'synditracker_agent_initialized' );
    }

    /**
     * Handle clear logs action.
     *
     * @since  1.0.0
     * @return void
     */
    public function handle_clear_logs() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'synditracker-agent' ) );
        }

        if ( ! isset( $_POST['st_agent_clear_logs_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['st_agent_clear_logs_nonce'] ), 'st_agent_clear_logs_action' ) ) {
            wp_die( esc_html__( 'Security check failed', 'synditracker-agent' ) );
        }

        if ( class_exists( '\Synditracker\Core\Logger' ) ) {
            \Synditracker\Core\Logger::get_instance()->clear_logs();
        }
        wp_safe_redirect( add_query_arg( 'st-logs-cleared', '1', wp_get_referer() ) );
        exit;
    }

    /**
     * Enqueue admin assets.
     *
     * @since  1.0.0
     * @param  string $hook Current admin page hook.
     * @return void
     */
    public function enqueue_assets( $hook ) {
        if ( false === strpos( $hook, 'synditracker-agent' ) ) {
            return;
        }

        wp_enqueue_style( 'st-agent-admin', SYNDITRACKER_AGENT_URL . 'assets/admin.css', array(), SYNDITRACKER_AGENT_VERSION );
        wp_enqueue_script( 'st-agent-admin', SYNDITRACKER_AGENT_URL . 'assets/agent-admin.js', array( 'jquery' ), SYNDITRACKER_AGENT_VERSION, true );

        wp_localize_script(
            'st-agent-admin',
            'st_agent',
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'st_agent_nonce' ),
            )
        );
    }

    /**
     * Add settings page to admin menu.
     *
     * @since  1.0.0
     * @return void
     */
    public function add_settings_page() {
        add_menu_page(
            __( 'Synditracker Agent', 'synditracker-agent' ),
            __( 'Synditracker Agent', 'synditracker-agent' ),
            'manage_options',
            'synditracker-agent',
            array( $this, 'render_settings' ),
            'dashicons-rss',
            81
        );
    }

    /**
     * Render the settings page.
     *
     * @since  1.0.0
     * @return void
     */
    public function render_settings() {
        $hub_url  = get_option( 'st_agent_hub_url', '' );
        $site_key = get_option( 'st_agent_site_key', '' );
        ?>
        <div class="wrap synditracker-dashboard">
            <div class="st-header">
                <h1><?php esc_html_e( 'Synditracker Agent', 'synditracker-agent' ); ?></h1>
                <span class="st-version">v<?php echo esc_html( SYNDITRACKER_AGENT_VERSION ); ?></span>
            </div>

            <div id="st-agent-message"></div>

            <div class="st-card" style="max-width: 600px;">
                <p><?php esc_html_e( 'Configure this agent to report syndication events back to your Synditracker Core hub.', 'synditracker-agent' ); ?></p>

                <div id="st-connection-status" class="st-agent-status <?php echo ( $hub_url && $site_key ) ? 'st-status-disconnected' : ''; ?>">
                    <strong><?php esc_html_e( 'Status:', 'synditracker-agent' ); ?></strong> <?php esc_html_e( 'Unknown (Click Save & Test)', 'synditracker-agent' ); ?>
                </div>

                <form id="st-agent-form">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="st_agent_hub_url"><?php esc_html_e( 'Hub URL', 'synditracker-agent' ); ?></label></th>
                            <td>
                                <input type="url" name="st_agent_hub_url" id="st_agent_hub_url" value="<?php echo esc_url( $hub_url ); ?>" class="large-text" placeholder="https://main-hub-site.com" required>
                                <p class="description"><?php esc_html_e( 'The URL where Synditracker Core is installed.', 'synditracker-agent' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="st_agent_site_key"><?php esc_html_e( 'Site Key', 'synditracker-agent' ); ?></label></th>
                            <td>
                                <input type="text" name="st_agent_site_key" id="st_agent_site_key" value="<?php echo esc_attr( $site_key ); ?>" class="large-text" required>
                                <p class="description"><?php esc_html_e( "Your unique key generated in the Hub's Key Management registry.", 'synditracker-agent' ); ?></p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <input type="submit" name="st_agent_save" class="button button-primary" value="<?php esc_attr_e( 'Save & Test Connection', 'synditracker-agent' ); ?>">
                    </p>
                </form>
            </div>

            <div class="st-card" style="max-width: 600px; margin-top: 20px;">
                <h2><?php esc_html_e( 'System Logs', 'synditracker-agent' ); ?></h2>
                <textarea readonly style="width: 100%; height: 300px; font-family: monospace; background: #f0f0f1;"><?php
                    if ( class_exists( '\Synditracker\Core\Logger' ) ) {
                        $logs = \Synditracker\Core\Logger::get_instance()->get_logs( 100 );
                        echo esc_textarea( implode( '', $logs ) );
                    }
                ?></textarea>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 10px;">
                    <input type="hidden" name="action" value="st_agent_clear_logs">
                    <?php wp_nonce_field( 'st_agent_clear_logs_action', 'st_agent_clear_logs_nonce' ); ?>
                    <input type="submit" class="button button-secondary" value="<?php esc_attr_e( 'Clear Logs', 'synditracker-agent' ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to clear all logs?', 'synditracker-agent' ) ); ?>');">
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX handler for saving and testing settings.
     *
     * @since  1.0.0
     * @return void
     */
    public function ajax_save_settings() {
        check_ajax_referer( 'st_agent_nonce' );

        $hub_url  = isset( $_POST['st_agent_hub_url'] ) ? esc_url_raw( wp_unslash( $_POST['st_agent_hub_url'] ) ) : '';
        $site_key = isset( $_POST['st_agent_site_key'] ) ? sanitize_text_field( wp_unslash( $_POST['st_agent_site_key'] ) ) : '';

        update_option( 'st_agent_hub_url', $hub_url );
        update_option( 'st_agent_site_key', $site_key );

        $logger = \Synditracker\Core\Logger::get_instance();
        /* translators: %s: Hub URL */
        $logger->log( sprintf( __( 'Settings updated. Testing connection to %s', 'synditracker-agent' ), $hub_url ), 'INFO' );

        // Test connection.
        $response = wp_remote_post(
            trailingslashit( $hub_url ) . 'wp-json/' . SYNDITRACKER_REST_NAMESPACE . '/log',
            array(
                'body'    => array(
                    'post_id'    => 1,
                    'site_url'   => home_url(),
                    'site_name'  => 'Connection Test',
                    'aggregator' => 'Test',
                ),
                'headers' => array(
                    'X-Synditracker-Key' => $site_key,
                ),
                'timeout' => 5,
            )
        );

        $connected = false;
        $msg       = __( 'Settings saved, but connection failed.', 'synditracker-agent' );

        if ( ! is_wp_error( $response ) ) {
            $code = wp_remote_retrieve_response_code( $response );
            if ( 200 === $code ) {
                $connected = true;
                $msg       = __( 'Settings saved and connection verified!', 'synditracker-agent' );
                $logger->log( __( 'Connection verification successful.', 'synditracker-agent' ), 'INFO' );
            } elseif ( 401 === $code || 403 === $code ) {
                $msg = __( 'Settings saved, but Authentication Failed (Invalid Key).', 'synditracker-agent' );
                $logger->log( __( 'Connection verification failed: Invalid Key.', 'synditracker-agent' ), 'ERROR' );
            } else {
                /* translators: %d: HTTP status code */
                $msg = sprintf( __( 'Settings saved, but Hub returned error: %d', 'synditracker-agent' ), $code );
                /* translators: %d: HTTP status code */
                $logger->log( sprintf( __( 'Connection verification failed. Hub Code: %d', 'synditracker-agent' ), $code ), 'ERROR' );
            }
        } else {
            /* translators: %s: error message */
            $msg = sprintf( __( 'Settings saved, but could not reach Hub: %s', 'synditracker-agent' ), $response->get_error_message() );
            /* translators: %s: error message */
            $logger->log( sprintf( __( 'Connection verification failed: %s', 'synditracker-agent' ), $response->get_error_message() ), 'ERROR' );
        }

        if ( $connected ) {
            wp_send_json_success( array( 'message' => $msg, 'connected' => true ) );
        } else {
            wp_send_json_error( array( 'message' => $msg, 'connected' => false ) );
        }
    }

    /**
     * Handle new post insertion and report syndication.
     *
     * @since  1.0.0
     * @param  int     $post_ID Post ID.
     * @param  WP_Post $post    Post object.
     * @param  bool    $update  Whether this is an update.
     * @return void
     */
    public function handle_new_post( $post_ID, $post, $update ) {
        if ( $update ) {
            return;
        }

        $guid             = get_the_guid( $post_ID );
        $original_post_id = $this->extract_post_id_from_guid( $guid );

        if ( ! $original_post_id ) {
            return;
        }

        $this->report_to_hub( $original_post_id, $post_ID );
    }

    /**
     * Extract original Post ID from GUID.
     *
     * @since  1.0.0
     * @param  string $guid Post GUID.
     * @return int|false Original post ID or false if not found.
     */
    private function extract_post_id_from_guid( $guid ) {
        if ( preg_match( '/[?&]p=(\d+)/', $guid, $matches ) ) {
            return (int) $matches[1];
        }
        return false;
    }

    /**
     * Report syndication event to the Hub.
     *
     * @since  1.0.0
     * @param  int $original_post_id Original post ID from the hub.
     * @param  int $local_post_id    Local post ID on this site.
     * @return void
     */
    private function report_to_hub( $original_post_id, $local_post_id ) {
        $hub_url  = get_option( 'st_agent_hub_url' );
        $site_key = get_option( 'st_agent_site_key' );

        // Ensure Logger class is loaded.
        if ( ! class_exists( '\Synditracker\Core\Logger' ) ) {
            return;
        }

        $logger = \Synditracker\Core\Logger::get_instance();

        if ( empty( $hub_url ) || empty( $site_key ) ) {
            $logger->log( __( 'Syndication Report Skipped: Missing settings.', 'synditracker-agent' ), 'WARNING' );
            return;
        }

        $endpoint = trailingslashit( $hub_url ) . 'wp-json/' . SYNDITRACKER_REST_NAMESPACE . '/log';

        $body = array(
            'post_id'    => $original_post_id,
            'site_url'   => home_url(),
            'site_name'  => get_bloginfo( 'name' ),
            'aggregator' => $this->detect_aggregator( $local_post_id ),
        );

        /* translators: 1: local post ID, 2: original post ID, 3: hub URL */
        $logger->log( sprintf( __( 'Reporting syndication for Post ID %1$d (Original: %2$d) to %3$s', 'synditracker-agent' ), $local_post_id, $original_post_id, $hub_url ), 'INFO' );

        $response = wp_remote_post(
            $endpoint,
            array(
                'method'   => 'POST',
                'timeout'  => 15,
                'blocking' => true,
                'headers'  => array(
                    'X-Synditracker-Key' => $site_key,
                    'Content-Type'       => 'application/json',
                ),
                'body'     => wp_json_encode( $body ),
            )
        );

        if ( is_wp_error( $response ) ) {
            /* translators: %s: error message */
            $logger->log( sprintf( __( 'Syndication Report Failed: %s', 'synditracker-agent' ), $response->get_error_message() ), 'ERROR' );
        } else {
            $code = wp_remote_retrieve_response_code( $response );
            if ( 200 === $code ) {
                $logger->log( __( 'Syndication Reported Successfully.', 'synditracker-agent' ), 'INFO' );
            } else {
                /* translators: %d: HTTP status code */
                $logger->log( sprintf( __( 'Syndication Report Failed. Hub responded with code: %d', 'synditracker-agent' ), $code ), 'ERROR' );
            }
        }
    }

    /**
     * Detect which aggregator plugin was used for the post.
     *
     * @since  1.0.0
     * @param  int $post_id Post ID.
     * @return string Aggregator name.
     */
    private function detect_aggregator( $post_id ) {
        if ( get_post_meta( $post_id, 'feedzy_item_url', true ) ) {
            return 'Feedzy';
        }
        if ( get_post_meta( $post_id, 'wpe_feed_id', true ) ) {
            return 'WPeMatico';
        }

        /**
         * Filter the detected aggregator name.
         *
         * @since 1.0.5
         * @param string $aggregator The detected aggregator name.
         * @param int    $post_id    The post ID being checked.
         */
        return apply_filters( 'synditracker_agent_detected_aggregator', 'Unknown', $post_id );
    }
}

// Start the agent.
Synditracker_Agent::get_instance();
