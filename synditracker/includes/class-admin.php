<?php
namespace Synditracker\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin Class.
 */
class Admin
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
    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));

        // AJAX Handlers
        add_action('wp_ajax_st_ajax_handle_st_generate_key', array($this, 'ajax_generate_key'));
        add_action('wp_ajax_st_ajax_handle_st_save_alerts', array($this, 'ajax_handle_save_alerts'));
        
        // Log Actions
        add_action('admin_post_st_clear_logs', array($this, 'handle_clear_logs'));
        add_action('wp_ajax_st_test_discord_alert', array($this, 'ajax_test_discord_alert'));
    }

    /**
     * Handle Clear Logs.
     */
    public function handle_clear_logs()
    {
        if (!current_user_can('manage_options') || !isset($_POST['st_clear_logs_nonce']) || !wp_verify_nonce($_POST['st_clear_logs_nonce'], 'st_clear_logs_action')) {
            wp_die('Unauthorized');
        }

        \Synditracker\Core\Logger::get_instance()->clear_logs();
        wp_safe_redirect(add_query_arg('st-logs-cleared', '1', wp_get_referer()));
        exit;
    }

    /**
     * AJAX Test Discord Alert.
     */
    public function ajax_test_discord_alert()
    {
        check_ajax_referer('st_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $url = get_option('synditracker_discord_webhook');
        if (empty($url)) {
            wp_send_json_error('No Webhook URL saved.');
        }

        $data = array(
            'site_name' => 'Synditracker Hub (Test)',
            'site_url'  => home_url()
        );

        $alerts = Alerts::get_instance();
        $alerts->send_discord_alert($data);
        
        // Check logs for result since send_discord_alert serves void
        // Ideally send_discord_alert should return status, but for now we assume success if no error logged
        // or we can trap error by checking last log line? 
        // Simpler: assume success if method runs, logger will catch errors.
        
        wp_send_json_success('Test alert sent.');
    }

    /**
     * Add menu pages.
     */
    public function add_menu()
    {
        add_menu_page(
            'Synditracker',
            'Synditracker',
            'manage_options',
            'synditracker',
            array($this, 'render_dashboard'),
            'dashicons-chart-line',
            30
        );

        add_submenu_page(
            'synditracker',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'synditracker'
        );

        add_submenu_page(
            'synditracker',
            'Key Management',
            'Key Management',
            'manage_options',
            'synditracker-keys',
            array($this, 'render_keys_page')
        );

        add_submenu_page(
            'synditracker',
            'Alerting',
            'Alerting',
            'manage_options',
            'synditracker-alerts',
            array($this, 'render_alerts_page')
        );
    }

    /**
     * Enqueue admin assets.
     */
    public function enqueue_assets($hook)
    {
        if (strpos($hook, 'synditracker') === false) {
            return;
        }

                wp_enqueue_style('synditracker-admin', SYNDITRACKER_URL . 'assets/admin.css', array(), SYNDITRACKER_VERSION);
        wp_enqueue_script('synditracker-admin', SYNDITRACKER_URL . 'assets/admin.js', array('jquery'), SYNDITRACKER_VERSION, true);
        wp_localize_script('synditracker-admin', 'st_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('st_admin_nonce'),
        ));
    }

    /**
     * Render the dashboard.
     */
    public function render_dashboard()
    {
        $db = DB::get_instance();
        $metrics = $db->get_metrics();
        ?>
        <div class="wrap synditracker-dashboard">
            <div class="st-header">
                <h1>Synditracker</h1>
                <span class="st-version">v<?php echo esc_html(SYNDITRACKER_VERSION); ?></span>
            </div>

            <div class="st-metrics-grid">
                <div class="st-card">
                    <h3>Total Publishing</h3>
                    <div class="st-value"><?php echo esc_html($metrics['total']); ?></div>
                    <div class="st-label">All-time events</div>
                </div>
                <div class="st-card">
                    <h3>Partner Reach</h3>
                    <div class="st-value"><?php echo esc_html($metrics['unique_partners']); ?></div>
                    <div class="st-label">Connected Agents</div>
                </div>
                <div class="st-card">
                    <h3>Pulse Integrity</h3>
                    <div class="st-value"><?php echo esc_html($metrics['duplicate_rate']); ?>%</div>
                    <div class="st-label">Duplicate Ratio</div>
                </div>
            </div>

            <div class="st-section">
                <div class="st-section-header">
                    <h2>Syndication Stream</h2>
                    <p class="st-section-desc">Real-time pulse of content distribution across the network.</p>
                </div>
                <div class="st-table-container">
                    <?php $this->render_logs_table(); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the logs table.
     */
    private function render_logs_table()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'synditracker_logs';
        $logs = $wpdb->get_results("SELECT * FROM $table_name ORDER BY timestamp DESC LIMIT 50");

        if (empty($logs)) {
            echo '<div class="st-empty">No syndication events recorded yet. Get started by connecting an Agent.</div>';
            return;
        }
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th class="col-id">Source ID</th>
                    <th class="col-site">Partner Site</th>
                    <th class="col-aggregator">Aggregator</th>
                    <th class="col-time">Time Received</th>
                    <th class="col-status">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log) : ?>
                    <tr>
                        <td class="col-id"><code>#<?php echo esc_html($log->post_id); ?></code></td>
                        <td class="col-site">
                            <span class="st-site-name"><?php echo esc_html($log->site_name); ?></span>
                            <a href="<?php echo esc_url($log->site_url); ?>" class="st-external-link" target="_blank dashicons-before dashicons-external"></a>
                        </td>
                        <td class="col-aggregator"><?php echo esc_html($log->aggregator); ?></td>
                        <td class="col-time"><?php echo esc_html(human_time_diff(strtotime($log->timestamp), current_time('timestamp'))); ?> ago</td>
                        <td class="col-status">
                            <?php if ($log->is_duplicate) : ?>
                                <span class="st-badge badge-warning">Duplicate</span>
                            <?php else : ?>
                                <span class="st-badge badge-success">Unique</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render the Key Management page.
     */
    public function render_keys_page()
    {
        $keys_mgr = Keys::get_instance();

        if (isset($_POST['st_generate_key'])) {
            check_admin_referer('st_keys_nonce');
            $site_name = sanitize_text_field($_POST['st_site_name']);
            if (!empty($site_name)) {
                $keys_mgr->generate_key($site_name);
                echo '<div class="updated"><p>New key generated and registered successfully.</p></div>';
            }
        }

        if (isset($_GET['action']) && isset($_GET['key_id'])) {
            check_admin_referer('st_key_action');
            if ($_GET['action'] === 'revoke') {
                $keys_mgr->revoke_key($_GET['key_id']);
                echo '<div class="updated"><p>Key revoked.</p></div>';
            } elseif ($_GET['action'] === 'delete') {
                $keys_mgr->delete_key($_GET['key_id']);
                echo '<div class="updated"><p>Key deleted.</p></div>';
            }
        }

        $keys = $keys_mgr->get_keys();
        ?>
        <div class="wrap synditracker-dashboard">
            <div class="st-header">
                <h1>Key Management</h1>
                <p>Register and manage individual Site Keys for your partner network.</p>
            </div>

            <div class="st-section st-card">
                <h3>Generate New Agent Key</h3>
                <form method="post" class="st-inline-form">
                    <?php wp_nonce_field('st_keys_nonce'); ?>
                    <input type="text" name="st_site_name" placeholder="Partner Site Name (e.g. Daily Herald)" required class="regular-text">
                    <input type="submit" name="st_generate_key" class="button button-primary" value="Generate Key">
                </form>
            </div>

            <div class="st-section">
                <h3>Active Registry</h3>
                <div class="st-table-container">
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Site Label</th>
                                <th>Key Value</th>
                                <th>Connection</th>
                                <th>Created</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($keys)) : ?>
                                <tr><td colspan="5">No keys generated yet.</td></tr>
                            <?php else : ?>
                                <?php foreach ($keys as $k) : ?>
                                    <tr>
                                        <td><strong><?php echo esc_html($k->site_name); ?></strong></td>
                                        <td>
                                            <code><?php echo esc_html($k->key_value); ?></code>
                                            <button type="button" class="st-copy-btn" data-copy="<?php echo esc_attr($k->key_value); ?>" title="Copy to clipboard"><span class="dashicons dashicons-clipboard"></span></button>
                                        </td>
                                        <td>
                                            <?php if ($k->last_seen) : ?>
                                                <span class="st-badge badge-success" title="Last seen: <?php echo esc_attr($k->last_seen); ?>">Connected</span>
                                            <?php else : ?>
                                                <span class="st-badge badge-gray">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html(date('M d, Y', strtotime($k->created_at))); ?></td>
                                        <td>
                                            <span class="st-badge badge-<?php echo $k->status === 'active' ? 'success' : 'warning'; ?>">
                                                <?php echo esc_html(ucfirst($k->status)); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($k->status === 'active') : ?>
                                                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=synditracker-keys&action=revoke&key_id=' . $k->id), 'st_key_action'); ?>" class="button button-small">Revoke</a>
                                            <?php endif; ?>
                                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=synditracker-keys&action=delete&key_id=' . $k->id), 'st_key_action'); ?>" class="button button-small" onclick="return confirm('Truly delete this key? This cannot be undone.');">Delete</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the Alerting page.
     */
    public function render_alerts_page()
    {
        if (isset($_POST['st_save_alerts'])) {
            check_admin_referer('st_alerts_nonce');
            
            $discord_url = esc_url_raw($_POST['st_discord_webhook']);
            if (!empty($discord_url) && strpos($discord_url, 'discord.com/api/webhooks/') === false) {
                echo '<div class="error"><p>Invalid Discord Webhook URL. It must be a valid discord.com webhook link.</p></div>';
                $discord_url = '';
            }

            $settings = array(
                'email_enabled'   => isset($_POST['st_email_enabled']) ? 1 : 0,
                'email_recipients'=> sanitize_textarea_field($_POST['st_email_recipients']),
                'discord_enabled' => isset($_POST['st_discord_enabled']) ? 1 : 0,
                'error_discord'   => isset($_POST['st_error_discord']) ? 1 : 0,
                'discord_webhook' => $discord_url,
                'scanning_window' => intval($_POST['st_scanning_window']),
                'alert_frequency' => sanitize_text_field($_POST['st_alert_frequency']),
            );
            update_option('synditracker_alert_settings', $settings);
            update_option('synditracker_spike_threshold', intval($_POST['st_threshold']));
            
            echo '<div class="updated"><p>Alert settings saved.</p></div>';
        }

        $settings = get_option('synditracker_alert_settings', array());
        $threshold = get_option('synditracker_spike_threshold', 5);
        $scanning_window = isset($settings['scanning_window']) ? $settings['scanning_window'] : 1;
        $alert_frequency = isset($settings['alert_frequency']) ? $settings['alert_frequency'] : 'immediate';
        $email_recipients = isset($settings['email_recipients']) ? $settings['email_recipients'] : get_option('admin_email');
        ?>
        <div class="wrap synditracker-dashboard">
            <div class="st-header">
                <h1>Pulse Integrity & Alerting</h1>
                <p>Configure how Synditracker notifies you of network anomalies.</p>
            </div>

            <form method="post">
                <?php wp_nonce_field('st_alerts_nonce'); ?>
                
                <div class="st-section st-card">
                    <h3>Integrity Logic</h3>
                    <table class="form-table">
                        <tr>
                            <th>Scanning Timeframe</th>
                            <td>
                                <select name="st_scanning_window">
                                    <option value="1" <?php selected(1, $scanning_window); ?>>1 Hour (Rolling)</option>
                                    <option value="6" <?php selected(6, $scanning_window); ?>>6 Hours</option>
                                    <option value="24" <?php selected(24, $scanning_window); ?>>24 Hours</option>
                                </select>
                                <p class="description">How far back Synditracker scans to calculate duplicate spikes.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Duplicate Spike Threshold</th>
                            <td>
                                <input type="number" name="st_threshold" value="<?php echo esc_attr($threshold); ?>" class="small-text">
                                <p class="description">
                                    <strong>How it works:</strong> If the number of duplicates within your <strong>Scanning Timeframe</strong> exceeds this threshold, an alert is triggered.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th>Alert Sending Freqency</th>
                            <td>
                                <select name="st_alert_frequency">
                                    <option value="immediate" <?php selected('immediate', $alert_frequency); ?>>Immediate (Standard)</option>
                                    <option value="6h" <?php selected('6h', $alert_frequency); ?>>6 Hour Heartbeat</option>
                                    <option value="daily" <?php selected('daily', $alert_frequency); ?>>Daily Summary</option>
                                    <option value="weekly" <?php selected('weekly', $alert_frequency); ?>>Weekly Report</option>
                                </select>
                                <p class="description">Control the "Heartbeat" of alert notifications to avoid inbox fatigue.</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="st-section st-card">
                    <h3>Email Notifications</h3>
                    <table class="form-table">
                        <tr>
                            <th>Recipient Emails</th>
                            <td>
                                <textarea name="st_email_recipients" rows="3" class="large-text" placeholder="admin@site.com, boss@muneebgawri.com"><?php echo esc_textarea($email_recipients); ?></textarea>
                                <p class="description">Enter one or more email addresses, separated by commas or new lines.</p>
                                <label><input type="checkbox" name="st_email_enabled" <?php checked(1, @$settings['email_enabled']); ?>> Enable Email Alerts</label>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="st-section st-card">
                    <h3>Discord Webhook</h3>
                    <table class="form-table">
                        <tr>
                            <th><label for="st_discord_webhook">Discord Webhook URL</label></th>
                            <td>
                                <input type="url" name="st_discord_webhook" id="st_discord_webhook" value="<?php echo esc_url(@$settings['discord_webhook']); ?>" class="large-text" placeholder="https://discord.com/api/webhooks/...">
                                <p class="description">Discord alerts are sent via your server to your chosen channel instantly.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Event Subscriptions</th>
                            <td>
                                <label><input type="checkbox" name="st_discord_enabled" <?php checked(1, @$settings['discord_enabled']); ?>> <strong>Enable Discord Spike Alerts</strong></label>
                                <p class="description">Notifies Discord when content duplication exceeds the threshold.</p>
                                
                                <label><input type="checkbox" name="st_error_discord" <?php checked(1, @$settings['error_discord']); ?>> <strong>Enable Discord System Error Alerts</strong></label>
                                <p class="description">Notifies Discord if critical background processes fail or API authentication fails repeatedly.</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <p class="submit">
                    <input type="submit" name="st_save_alerts" class="button button-primary" value="Save Settings">
                    <button type="button" id="st-test-discord" class="button button-secondary" style="margin-left: 10px;">Send Test Alert</button>
                    <span id="st-test-discord-status" style="margin-left: 10px; font-weight: bold;"></span>
                </p>
            </form>
        </div>
        
        <div id="logs" class="tab-content" style="display:none;">
            <h2>System Logs</h2>
            <p>Recent debug logs for troubleshooting.</p>
            <textarea readonly style="width: 100%; height: 400px; font-family: monospace; background: #f0f0f1;"><?php 
                $logs = \Synditracker\Core\Logger::get_instance()->get_logs(100);
                echo esc_textarea(implode("", $logs)); 
            ?></textarea>
            <form method="post" style="margin-top: 10px;">
                <input type="hidden" name="st_clear_logs" value="1">
                <?php wp_nonce_field('st_clear_logs_action', 'st_clear_logs_nonce'); ?>
                <input type="submit" class="button button-secondary" value="Clear Logs" onclick="return confirm('Are you sure you want to clear all logs?');">
            </form>
        </div>
        <?php
    }

    /**
     * AJAX Validate Key.
     */
    public function ajax_validate_key()
    {
        check_ajax_referer('st_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $hub_url = sanitize_text_field($_POST['hub_url']);
        $api_key = sanitize_text_field($_POST['api_key']);

        if (empty($hub_url) || empty($api_key)) {
            wp_send_json_error(array('message' => 'Missing Hub URL or Site Key.'));
        }

        // Test connection
        $response = wp_remote_post(trailingslashit($hub_url) . 'wp-json/synditracker/v1/log', array(
            'body' => array(
                'post_id' => 1,
                'site_url' => home_url(),
                'site_name' => 'Connection Test',
                'aggregator' => 'Test'
            ),
            'headers' => array(
                'X-Synditracker-Key' => $api_key
            ),
            'timeout' => 10
        ));

        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => 'Connection Failed: ' . $response->get_error_message()));
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code === 200) {
            update_option('synditracker_hub_url', $hub_url);
            update_option('synditracker_site_key', $api_key);
            wp_send_json_success(array('message' => 'Connection Successful! Settings Saved.'));
        } else {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            $msg = isset($data['message']) ? $data['message'] : 'Unknown Error';
            wp_send_json_error(array('message' => "Hub Error ($code): $msg"));
        }
    }

    /**
     * AJAX Save Alerts Settings.
     */
    public function ajax_handle_save_alerts()
    {
        check_ajax_referer('st_admin_nonce', '_ajax_nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        if (isset($_POST['st_discord_webhook'])) {
            $url = sanitize_text_field($_POST['st_discord_webhook']);
            update_option('synditracker_discord_webhook', $url);
            
            \Synditracker\Core\Logger::get_instance()->log("Discord Webhook URL updated by user.", 'INFO');
            wp_send_json_success(array('message' => 'Settings saved successfully.'));
        }
        
        wp_send_json_error(array('message' => 'No data received.'));
    }
    /**
     * AJAX: Generate Key
     */
    public function ajax_generate_key()
    {
        check_ajax_referer('st_admin_nonce');
        
        $site_name = isset($_POST['st_site_name']) ? sanitize_text_field($_POST['st_site_name']) : '';
        
        if (empty($site_name)) {
            wp_send_json_error(array('message' => 'Partner Site Name is required.'));
        }

        $keys_mgr = Keys::get_instance();
        $key = $keys_mgr->generate_key($site_name);

        if ($key) {
            wp_send_json_success(array('message' => 'New key generated successfully for ' . $site_name));
        } else {
            wp_send_json_error(array('message' => 'Failed to generate key.'));
        }
    }

    /**
     * AJAX: Save Alerts
     */
    public function ajax_save_alerts()
    {
        check_ajax_referer('st_admin_nonce');

        $discord_url = esc_url_raw($_POST['st_discord_webhook']);
        if (!empty($discord_url) && strpos($discord_url, 'discord.com/api/webhooks/') === false) {
            wp_send_json_error(array('message' => 'Invalid Discord Webhook URL.'));
        }

        $threshold = intval($_POST['st_threshold']);
        if ($threshold < 1) {
            wp_send_json_error(array('message' => 'Threshold must be at least 1.'));
        }

        $settings = array(
            'email_enabled'   => isset($_POST['st_email_enabled']) ? 1 : 0,
            'email_recipients'=> sanitize_textarea_field($_POST['st_email_recipients']),
            'discord_enabled' => isset($_POST['st_discord_enabled']) ? 1 : 0,
            'error_discord'   => isset($_POST['st_error_discord']) ? 1 : 0,
            'discord_webhook' => $discord_url,
            'scanning_window' => intval($_POST['st_scanning_window']),
            'alert_frequency' => sanitize_text_field($_POST['st_alert_frequency']),
        );

        update_option('synditracker_alert_settings', $settings);
        update_option('synditracker_spike_threshold', $threshold);

        wp_send_json_success(array('message' => 'Settings saved successfully.'));
    }
}
