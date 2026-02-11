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
                                        <td><code><?php echo esc_html($k->key_value); ?></code></td>
                                        <td><?php echo esc_html($k->created_at); ?></td>
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
                    <input type="submit" name="st_save_alerts" class="button button-primary" value="Save Integrity Settings">
                </p>
            </form>
        </div>
        <?php
    }
}
