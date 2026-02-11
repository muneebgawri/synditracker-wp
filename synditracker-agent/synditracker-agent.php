<?php
/**
 * Plugin Name: Synditracker Agent
 * Plugin URI: https://muneebgawri.com
 * Description: A lightweight agent for partner sites to report syndicated content back to the Synditracker Core.
 * Version: 1.0.4
 * Author: Muneeb Gawri
 * Author URI: https://muneebgawri.com
 * Text Domain: synditracker-agent
 *
 * @package Synditracker
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define constants.
define('SYNDITRACKER_AGENT_VERSION', '1.0.4');
define('SYNDITRACKER_AGENT_PATH', plugin_dir_path(__FILE__));

/**
 * Main Synditracker Agent Class.
 */
class Synditracker_Agent
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
        add_action('wp_insert_post', array($this, 'handle_new_post'), 10, 3);
        
        // Add settings for Hub URL and Site Key.
        require_once plugin_dir_path(__FILE__) . 'includes/class-github-updater.php';
        require_once plugin_dir_path(__FILE__) . 'includes/class-logger.php';

        new \Synditracker_GitHub_Updater(__FILE__, 'muneebgawri/synditracker-wp', SYNDITRACKER_AGENT_VERSION, 'Synditracker Agent');

        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_ajax_st_agent_save_settings', array($this, 'ajax_save_settings'));
        
        // Clear logs action
        add_action('admin_post_st_agent_clear_logs', array($this, 'handle_clear_logs'));
    }

    /**
     * Handle Clear Logs.
     */
    public function handle_clear_logs()
    {
        if (!current_user_can('manage_options') || !isset($_POST['st_agent_clear_logs_nonce']) || !wp_verify_nonce($_POST['st_agent_clear_logs_nonce'], 'st_agent_clear_logs_action')) {
            wp_die('Unauthorized');
        }

        if (class_exists('\Synditracker\Core\Logger')) {
            \Synditracker\Core\Logger::get_instance()->clear_logs();
        }
        wp_safe_redirect(add_query_arg('st-logs-cleared', '1', wp_get_referer()));
        exit;
    }

    /**
     * Enqueue assets.
     */
    public function enqueue_assets($hook)
    {
        if (strpos($hook, 'synditracker-agent') === false) {
            return;
        }

        wp_enqueue_style('st-agent-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', array(), SYNDITRACKER_AGENT_VERSION);
        wp_enqueue_script('st-agent-admin', plugin_dir_url(__FILE__) . 'assets/agent-admin.js', array('jquery'), SYNDITRACKER_AGENT_VERSION, true);
        
        wp_localize_script('st-agent-admin', 'st_agent', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('st_agent_nonce'),
        ));
    }

    /**
     * Add settings page.
     */
    public function add_settings_page()
    {
        add_menu_page(
            'Synditracker Agent',
            'Synditracker Agent',
            'manage_options',
            'synditracker-agent',
            array($this, 'render_settings'),
            'dashicons-rss',
            81
        );
    }

    /**
     * Render settings page.
     */
    public function render_settings()
    {
        $hub_url = get_option('st_agent_hub_url', '');
        $site_key = get_option('st_agent_site_key', '');
        ?>
        <div class="wrap synditracker-dashboard">
            <div class="st-header">
                <h1>Synditracker Agent</h1>
                <span class="st-version">v<?php echo esc_html(SYNDITRACKER_AGENT_VERSION); ?></span>
            </div>
            
            <div id="st-agent-message"></div>

            <div class="st-card" style="max-width: 600px;">
                <p>Configure this agent to report syndication events back to your <strong>Synditracker Core</strong> hub.</p>
                
                <div id="st-connection-status" class="st-agent-status <?php echo ($hub_url && $site_key) ? 'st-status-disconnected' : ''; ?>">
                    <strong>Status:</strong> Unknown (Click Save & Test)
                </div>

                <form id="st-agent-form">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="st_agent_hub_url">Hub URL</label></th>
                            <td>
                                <input type="url" name="st_agent_hub_url" id="st_agent_hub_url" value="<?php echo esc_url($hub_url); ?>" class="large-text" placeholder="https://main-hub-site.com" required>
                                <p class="description">The URL where Synditracker Core is installed.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="st_agent_site_key">Site Key</label></th>
                            <td>
                                <input type="text" name="st_agent_site_key" id="st_agent_site_key" value="<?php echo esc_attr($site_key); ?>" class="large-text" required>
                                <p class="description">Your unique key generated in the Hub's Key Management registry.</p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <input type="submit" name="st_agent_save" class="button button-primary" value="Save & Test Connection">
                    </p>
                </form>
            </div>

            <div class="st-card" style="max-width: 600px; margin-top: 20px;">
                <h2>System Logs</h2>
                <textarea readonly style="width: 100%; height: 300px; font-family: monospace; background: #f0f0f1;"><?php 
                    if (class_exists('\Synditracker\Core\Logger')) {
                        $logs = \Synditracker\Core\Logger::get_instance()->get_logs(100);
                        echo esc_textarea(implode("", $logs));
                    }
                ?></textarea>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin-top: 10px;">
                    <input type="hidden" name="action" value="st_agent_clear_logs">
                    <?php wp_nonce_field('st_agent_clear_logs_action', 'st_agent_clear_logs_nonce'); ?>
                    <input type="submit" class="button button-secondary" value="Clear Logs" onclick="return confirm('Are you sure you want to clear all logs?');">
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Save & Test Settings
     */
    public function ajax_save_settings()
    {
        check_ajax_referer('st_agent_nonce');

        $hub_url = esc_url_raw($_POST['st_agent_hub_url']);
        $site_key = sanitize_text_field($_POST['st_agent_site_key']);

        update_option('st_agent_hub_url', $hub_url);
        update_option('st_agent_site_key', $site_key);

        $logger = \Synditracker\Core\Logger::get_instance();
        $logger->log("Settings updated. Testing connection to $hub_url", 'INFO');

        // Test Connection
        $response = wp_remote_post(trailingslashit($hub_url) . 'wp-json/synditracker/v1/log', array(
            'body' => array(
                'post_id' => 1,
                'site_url' => home_url(),
                'site_name' => 'Connection Test',
                'aggregator' => 'Test'
            ),
            'headers' => array(
                'X-Synditracker-Key' => $site_key
            ),
            'timeout' => 5
        ));

        $connected = false;
        $msg = 'Settings saved, but connection failed.';

        if (!is_wp_error($response)) {
            $code = wp_remote_retrieve_response_code($response);
            if ($code === 200) {
                $connected = true;
                $msg = 'Settings saved and connection verified!';
                $logger->log("Connection verification successful.", 'INFO');
            } elseif ($code === 401 || $code === 403) {
                $msg = 'Settings saved, but Authentication Failed (Invalid Key).';
                $logger->log("Connection verification failed: Invalid Key.", 'ERROR');
            } else {
                $msg = 'Settings saved, but Hub returned error: ' . $code;
                $logger->log("Connection verification failed. Hub Code: $code", 'ERROR');
            }
        } else {
             $msg = 'Settings saved, but could not reach Hub: ' . $response->get_error_message();
             $logger->log("Connection verification failed: " . $response->get_error_message(), 'ERROR');
        }

        if ($connected) {
            wp_send_json_success(array('message' => $msg, 'connected' => true));
        } else {
            wp_send_json_error(array('message' => $msg, 'connected' => false));
        }
    }

    /**
     * Handle new post insertion.
     */
    public function handle_new_post($post_ID, $post, $update)
    {
        if ($update) {
            return;
        }

        $guid = get_the_guid($post_ID);
        $original_post_id = $this->extract_post_id_from_guid($guid);

        if (!$original_post_id) {
            return;
        }

        $this->report_to_hub($original_post_id, $post_ID);
    }

    /**
     * Extract original Post ID from GUID.
     */
    private function extract_post_id_from_guid($guid)
    {
        if (preg_match('/[?&]p=(\d+)/', $guid, $matches)) {
            return (int) $matches[1];
        }
        return false;
    }

    /**
     * Report syndication to the Hub.
     */
    private function report_to_hub($original_post_id, $local_post_id)
    {
        $hub_url = get_option('st_agent_hub_url');
        $site_key = get_option('st_agent_site_key');
        // Ensure Logger class is loaded (might be redundant but safe)
        if (class_exists('\Synditracker\Core\Logger')) {
             $logger = \Synditracker\Core\Logger::get_instance();
        } else {
             // Fallback if class not loaded yet (hook timing)
             return; 
        }

        if (empty($hub_url) || empty($site_key)) {
            $logger->log("Syndication Report Skipped: Missing settings.", 'WARNING');
            return;
        }

        $endpoint = trailingslashit($hub_url) . 'wp-json/synditracker/v1/log';

        $body = array(
            'post_id'    => $original_post_id,
            'site_url'   => home_url(),
            'site_name'  => get_bloginfo('name'),
            'aggregator' => $this->detect_aggregator($local_post_id),
        );
        
        $logger->log("Reporting syndication for Post ID $local_post_id (Original: $original_post_id) to $hub_url", 'INFO');

        $response = wp_remote_post($endpoint, array(
            'method'      => 'POST',
            'timeout'     => 15,
            'blocking'    => true,
            'headers'     => array(
                'X-Synditracker-Key' => $site_key,
                'Content-Type'       => 'application/json',
            ),
            'body'        => wp_json_encode($body),
        ));

        if (is_wp_error($response)) {
            $logger->log("Syndication Report Failed: " . $response->get_error_message(), 'ERROR');
        } else {
            $code = wp_remote_retrieve_response_code($response);
            if ($code === 200) {
                $logger->log("Syndication Reported Successfully.", 'INFO');
            } else {
                $logger->log("Syndication Report Failed. Hub responded with code: $code", 'ERROR');
            }
        }
    }

    /**
     * Detect which aggregator was used.
     */
    private function detect_aggregator($post_id)
    {
        if (get_post_meta($post_id, 'feedzy_item_url', true)) {
            return 'Feedzy';
        }
        if (get_post_meta($post_id, 'wpe_feed_id', true)) {
            return 'WPeMatico';
        }
        return 'Unknown';
    }
}

// Start the agent.
Synditracker_Agent::get_instance();
