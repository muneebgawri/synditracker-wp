<?php
/**
 * Admin Class.
 *
 * Handles all WordPress admin functionality for Synditracker.
 *
 * @package Synditracker
 * @since   1.0.0
 */

namespace Synditracker\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin Class.
 *
 * @since 1.0.0
 */
class Admin {

    /**
     * Singleton instance of this class.
     *
     * @since 1.0.0
     * @var Admin|null
     */
    private static $instance = null;

    /**
     * Get the singleton instance.
     *
     * @since  1.0.0
     * @return Admin
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
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // AJAX Handlers.
        add_action( 'wp_ajax_st_ajax_handle_st_generate_key', array( $this, 'ajax_generate_key' ) );
        add_action( 'wp_ajax_st_ajax_handle_st_save_alerts', array( $this, 'ajax_handle_save_alerts' ) );

        // Log Actions.
        add_action( 'admin_post_st_clear_logs', array( $this, 'handle_clear_logs' ) );
        add_action( 'wp_ajax_st_test_discord_alert', array( $this, 'ajax_test_discord_alert' ) );
    }

    /**
     * Handle Clear Logs action.
     *
     * @since  1.0.0
     * @return void
     */
    public function handle_clear_logs() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'synditracker' ) );
        }

        if ( ! isset( $_POST['st_clear_logs_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['st_clear_logs_nonce'] ), 'st_clear_logs_action' ) ) {
            wp_die( esc_html__( 'Security check failed', 'synditracker' ) );
        }

        Logger::get_instance()->clear_logs();
        wp_safe_redirect( add_query_arg( 'st-logs-cleared', '1', wp_get_referer() ) );
        exit;
    }

    /**
     * AJAX handler for testing Discord alerts.
     *
     * @since  1.0.0
     * @return void
     */
    public function ajax_test_discord_alert() {
        check_ajax_referer( 'st_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Unauthorized', 'synditracker' ) );
        }

        $settings = get_option( 'synditracker_alert_settings', array() );
        $url      = isset( $settings['discord_webhook'] ) ? $settings['discord_webhook'] : '';

        if ( empty( $url ) ) {
            wp_send_json_error( __( 'No Webhook URL saved.', 'synditracker' ) );
        }

        $data = array(
            'site_name' => 'Synditracker Hub (Test)',
            'site_url'  => home_url(),
        );

        $alerts = Alerts::get_instance();
        $alerts->send_discord_alert( $data );

        wp_send_json_success( __( 'Test alert sent.', 'synditracker' ) );
    }

    /**
     * Add admin menu pages.
     *
     * @since  1.0.0
     * @return void
     */
    public function add_menu() {
        add_menu_page(
            __( 'Synditracker', 'synditracker' ),
            __( 'Synditracker', 'synditracker' ),
            'manage_options',
            'synditracker',
            array( $this, 'render_dashboard' ),
            'dashicons-chart-line',
            30
        );

        add_submenu_page(
            'synditracker',
            __( 'Dashboard', 'synditracker' ),
            __( 'Dashboard', 'synditracker' ),
            'manage_options',
            'synditracker'
        );

        add_submenu_page(
            'synditracker',
            __( 'Key Management', 'synditracker' ),
            __( 'Key Management', 'synditracker' ),
            'manage_options',
            'synditracker-keys',
            array( $this, 'render_keys_page' )
        );

        add_submenu_page(
            'synditracker',
            __( 'Alerting', 'synditracker' ),
            __( 'Alerting', 'synditracker' ),
            'manage_options',
            'synditracker-alerts',
            array( $this, 'render_alerts_page' )
        );
    }

    /**
     * Enqueue admin assets.
     *
     * @since  1.0.0
     * @param  string $hook The current admin page hook.
     * @return void
     */
    public function enqueue_assets( $hook ) {
        if ( false === strpos( $hook, 'synditracker' ) ) {
            return;
        }

        wp_enqueue_style(
            'synditracker-admin',
            SYNDITRACKER_URL . 'assets/admin.css',
            array(),
            SYNDITRACKER_VERSION
        );

        wp_enqueue_script(
            'synditracker-admin',
            SYNDITRACKER_URL . 'assets/admin.js',
            array( 'jquery' ),
            SYNDITRACKER_VERSION,
            true
        );

        wp_localize_script(
            'synditracker-admin',
            'st_admin',
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'st_admin_nonce' ),
            )
        );
    }

    /**
     * Render the dashboard page.
     *
     * @since  1.0.0
     * @return void
     */
    public function render_dashboard() {
        $db      = DB::get_instance();
        $metrics = $db->get_metrics();
        ?>
        <div class="wrap synditracker-dashboard">
            <div class="st-header">
                <h1><?php esc_html_e( 'Synditracker', 'synditracker' ); ?></h1>
                <span class="st-version">v<?php echo esc_html( SYNDITRACKER_VERSION ); ?></span>
            </div>

            <div class="st-metrics-grid">
                <div class="st-card">
                    <h3><?php esc_html_e( 'Total Publishing', 'synditracker' ); ?></h3>
                    <div class="st-value"><?php echo esc_html( $metrics['total'] ); ?></div>
                    <div class="st-label"><?php esc_html_e( 'All-time events', 'synditracker' ); ?></div>
                </div>
                <div class="st-card">
                    <h3><?php esc_html_e( 'Partner Reach', 'synditracker' ); ?></h3>
                    <div class="st-value"><?php echo esc_html( $metrics['unique_partners'] ); ?></div>
                    <div class="st-label"><?php esc_html_e( 'Connected Agents', 'synditracker' ); ?></div>
                </div>
                <div class="st-card">
                    <h3><?php esc_html_e( 'Pulse Integrity', 'synditracker' ); ?></h3>
                    <div class="st-value"><?php echo esc_html( $metrics['duplicate_rate'] ); ?>%</div>
                    <div class="st-label"><?php esc_html_e( 'Duplicate Ratio', 'synditracker' ); ?></div>
                </div>
            </div>

            <div class="st-section">
                <div class="st-section-header">
                    <h2><?php esc_html_e( 'Syndication Stream', 'synditracker' ); ?></h2>
                    <p class="st-section-desc"><?php esc_html_e( 'Real-time pulse of content distribution across the network.', 'synditracker' ); ?></p>
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
     *
     * @since  1.0.0
     * @return void
     */
    private function render_logs_table() {
        $db   = DB::get_instance();
        $logs = $db->get_logs( 50 );

        if ( empty( $logs ) ) {
            echo '<div class="st-empty">' . esc_html__( 'No syndication events recorded yet. Get started by connecting an Agent.', 'synditracker' ) . '</div>';
            return;
        }
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th class="col-id"><?php esc_html_e( 'Source ID', 'synditracker' ); ?></th>
                    <th class="col-site"><?php esc_html_e( 'Partner Site', 'synditracker' ); ?></th>
                    <th class="col-aggregator"><?php esc_html_e( 'Aggregator', 'synditracker' ); ?></th>
                    <th class="col-time"><?php esc_html_e( 'Time Received', 'synditracker' ); ?></th>
                    <th class="col-status"><?php esc_html_e( 'Status', 'synditracker' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $logs as $log ) : ?>
                    <tr>
                        <td class="col-id"><code>#<?php echo esc_html( $log->post_id ); ?></code></td>
                        <td class="col-site">
                            <span class="st-site-name"><?php echo esc_html( $log->site_name ); ?></span>
                            <a href="<?php echo esc_url( $log->site_url ); ?>" class="st-external-link" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-external"></span></a>
                        </td>
                        <td class="col-aggregator"><?php echo esc_html( $log->aggregator ); ?></td>
                        <td class="col-time">
                            <?php
                            /* translators: %s: human-readable time difference */
                            printf( esc_html__( '%s ago', 'synditracker' ), esc_html( human_time_diff( strtotime( $log->timestamp ), current_time( 'timestamp' ) ) ) );
                            ?>
                        </td>
                        <td class="col-status">
                            <?php if ( $log->is_duplicate ) : ?>
                                <span class="st-badge badge-warning"><?php esc_html_e( 'Duplicate', 'synditracker' ); ?></span>
                            <?php else : ?>
                                <span class="st-badge badge-success"><?php esc_html_e( 'Unique', 'synditracker' ); ?></span>
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
     *
     * @since  1.0.0
     * @return void
     */
    public function render_keys_page() {
        $keys_mgr = Keys::get_instance();

        // Handle key generation.
        if ( isset( $_POST['st_generate_key'] ) ) {
            check_admin_referer( 'st_keys_nonce' );
            $site_name = isset( $_POST['st_site_name'] ) ? sanitize_text_field( wp_unslash( $_POST['st_site_name'] ) ) : '';
            if ( ! empty( $site_name ) ) {
                $result = $keys_mgr->generate_key( $site_name );
                if ( $result ) {
                    echo '<div class="updated"><p>' . esc_html__( 'New key generated and registered successfully.', 'synditracker' ) . '</p></div>';
                } else {
                    echo '<div class="error"><p>' . esc_html__( 'Failed to generate key. Please try again.', 'synditracker' ) . '</p></div>';
                }
            }
        }

        // Handle key actions (revoke/delete).
        if ( isset( $_GET['action'], $_GET['key_id'] ) ) {
            check_admin_referer( 'st_key_action' );
            $key_id = absint( $_GET['key_id'] );
            $action = sanitize_key( $_GET['action'] );

            if ( 'revoke' === $action ) {
                $keys_mgr->revoke_key( $key_id );
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
                <h1><?php esc_html_e( 'Key Management', 'synditracker' ); ?></h1>
                <p><?php esc_html_e( 'Register and manage individual Site Keys for your partner network.', 'synditracker' ); ?></p>
            </div>

            <div class="st-section st-card">
                <h3><?php esc_html_e( 'Generate New Agent Key', 'synditracker' ); ?></h3>
                <form method="post" class="st-inline-form">
                    <?php wp_nonce_field( 'st_keys_nonce' ); ?>
                    <input type="text" name="st_site_name" placeholder="<?php esc_attr_e( 'Partner Site Name (e.g. Daily Herald)', 'synditracker' ); ?>" required class="regular-text">
                    <input type="submit" name="st_generate_key" class="button button-primary" value="<?php esc_attr_e( 'Generate Key', 'synditracker' ); ?>">
                </form>
            </div>

            <div class="st-section">
                <h3><?php esc_html_e( 'Active Registry', 'synditracker' ); ?></h3>
                <div class="st-table-container">
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Site Label', 'synditracker' ); ?></th>
                                <th><?php esc_html_e( 'Key Value', 'synditracker' ); ?></th>
                                <th><?php esc_html_e( 'Connection', 'synditracker' ); ?></th>
                                <th><?php esc_html_e( 'Created', 'synditracker' ); ?></th>
                                <th><?php esc_html_e( 'Status', 'synditracker' ); ?></th>
                                <th><?php esc_html_e( 'Actions', 'synditracker' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( empty( $keys ) ) : ?>
                                <tr><td colspan="6"><?php esc_html_e( 'No keys generated yet.', 'synditracker' ); ?></td></tr>
                            <?php else : ?>
                                <?php foreach ( $keys as $k ) : ?>
                                    <tr>
                                        <td><strong><?php echo esc_html( $k->site_name ); ?></strong></td>
                                        <td>
                                            <code><?php echo esc_html( $k->key_value ); ?></code>
                                            <button type="button" class="st-copy-btn" data-copy="<?php echo esc_attr( $k->key_value ); ?>" title="<?php esc_attr_e( 'Copy to clipboard', 'synditracker' ); ?>"><span class="dashicons dashicons-clipboard"></span></button>
                                        </td>
                                        <td>
                                            <?php if ( $k->last_seen ) : ?>
                                                <span class="st-badge badge-success" title="<?php esc_attr_e( 'Last seen:', 'synditracker' ); ?> <?php echo esc_attr( $k->last_seen ); ?>"><?php esc_html_e( 'Connected', 'synditracker' ); ?></span>
                                            <?php else : ?>
                                                <span class="st-badge badge-gray"><?php esc_html_e( 'Pending', 'synditracker' ); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $k->created_at ) ) ); ?></td>
                                        <td>
                                            <span class="st-badge badge-<?php echo 'active' === $k->status ? 'success' : 'warning'; ?>">
                                                <?php echo esc_html( ucfirst( $k->status ) ); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ( 'active' === $k->status ) : ?>
                                                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=synditracker-keys&action=revoke&key_id=' . $k->id ), 'st_key_action' ) ); ?>" class="button button-small"><?php esc_html_e( 'Revoke', 'synditracker' ); ?></a>
                                            <?php endif; ?>
                                            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=synditracker-keys&action=delete&key_id=' . $k->id ), 'st_key_action' ) ); ?>" class="button button-small" onclick="return confirm('<?php echo esc_js( __( 'Truly delete this key? This cannot be undone.', 'synditracker' ) ); ?>');"><?php esc_html_e( 'Delete', 'synditracker' ); ?></a>
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
     *
     * @since  1.0.0
     * @return void
     */
    public function render_alerts_page() {
        if ( isset( $_POST['st_save_alerts'] ) ) {
            check_admin_referer( 'st_alerts_nonce' );

            $discord_url = isset( $_POST['st_discord_webhook'] ) ? esc_url_raw( wp_unslash( $_POST['st_discord_webhook'] ) ) : '';
            if ( ! empty( $discord_url ) && false === strpos( $discord_url, 'discord.com/api/webhooks/' ) ) {
                echo '<div class="error"><p>' . esc_html__( 'Invalid Discord Webhook URL. It must be a valid discord.com webhook link.', 'synditracker' ) . '</p></div>';
                $discord_url = '';
            }

            $settings = array(
                'email_enabled'    => isset( $_POST['st_email_enabled'] ) ? 1 : 0,
                'email_recipients' => isset( $_POST['st_email_recipients'] ) ? sanitize_textarea_field( wp_unslash( $_POST['st_email_recipients'] ) ) : '',
                'discord_enabled'  => isset( $_POST['st_discord_enabled'] ) ? 1 : 0,
                'error_discord'    => isset( $_POST['st_error_discord'] ) ? 1 : 0,
                'discord_webhook'  => $discord_url,
                'scanning_window'  => isset( $_POST['st_scanning_window'] ) ? absint( $_POST['st_scanning_window'] ) : 1,
                'alert_frequency'  => isset( $_POST['st_alert_frequency'] ) ? sanitize_text_field( wp_unslash( $_POST['st_alert_frequency'] ) ) : 'immediate',
            );
            update_option( 'synditracker_alert_settings', $settings );
            update_option( 'synditracker_spike_threshold', isset( $_POST['st_threshold'] ) ? absint( $_POST['st_threshold'] ) : SYNDITRACKER_DEFAULT_THRESHOLD );

            echo '<div class="updated"><p>' . esc_html__( 'Alert settings saved.', 'synditracker' ) . '</p></div>';
        }

        $settings        = get_option( 'synditracker_alert_settings', array() );
        $threshold       = get_option( 'synditracker_spike_threshold', SYNDITRACKER_DEFAULT_THRESHOLD );
        $scanning_window = isset( $settings['scanning_window'] ) ? $settings['scanning_window'] : 1;
        $alert_frequency = isset( $settings['alert_frequency'] ) ? $settings['alert_frequency'] : 'immediate';
        $email_recipients = isset( $settings['email_recipients'] ) ? $settings['email_recipients'] : get_option( 'admin_email' );
        ?>
        <div class="wrap synditracker-dashboard">
            <div class="st-header">
                <h1><?php esc_html_e( 'Pulse Integrity & Alerting', 'synditracker' ); ?></h1>
                <p><?php esc_html_e( 'Configure how Synditracker notifies you of network anomalies.', 'synditracker' ); ?></p>
            </div>

            <form method="post">
                <?php wp_nonce_field( 'st_alerts_nonce' ); ?>

                <div class="st-section st-card">
                    <h3><?php esc_html_e( 'Integrity Logic', 'synditracker' ); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e( 'Scanning Timeframe', 'synditracker' ); ?></th>
                            <td>
                                <select name="st_scanning_window">
                                    <option value="1" <?php selected( 1, $scanning_window ); ?>><?php esc_html_e( '1 Hour (Rolling)', 'synditracker' ); ?></option>
                                    <option value="6" <?php selected( 6, $scanning_window ); ?>><?php esc_html_e( '6 Hours', 'synditracker' ); ?></option>
                                    <option value="24" <?php selected( 24, $scanning_window ); ?>><?php esc_html_e( '24 Hours', 'synditracker' ); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e( 'How far back Synditracker scans to calculate duplicate spikes.', 'synditracker' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Duplicate Spike Threshold', 'synditracker' ); ?></th>
                            <td>
                                <input type="number" name="st_threshold" value="<?php echo esc_attr( $threshold ); ?>" class="small-text" min="1">
                                <p class="description">
                                    <strong><?php esc_html_e( 'How it works:', 'synditracker' ); ?></strong> <?php esc_html_e( 'If the number of duplicates within your Scanning Timeframe exceeds this threshold, an alert is triggered.', 'synditracker' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Alert Sending Frequency', 'synditracker' ); ?></th>
                            <td>
                                <select name="st_alert_frequency">
                                    <option value="immediate" <?php selected( 'immediate', $alert_frequency ); ?>><?php esc_html_e( 'Immediate (Standard)', 'synditracker' ); ?></option>
                                    <option value="6h" <?php selected( '6h', $alert_frequency ); ?>><?php esc_html_e( '6 Hour Heartbeat', 'synditracker' ); ?></option>
                                    <option value="daily" <?php selected( 'daily', $alert_frequency ); ?>><?php esc_html_e( 'Daily Summary', 'synditracker' ); ?></option>
                                    <option value="weekly" <?php selected( 'weekly', $alert_frequency ); ?>><?php esc_html_e( 'Weekly Report', 'synditracker' ); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e( 'Control the "Heartbeat" of alert notifications to avoid inbox fatigue.', 'synditracker' ); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="st-section st-card">
                    <h3><?php esc_html_e( 'Email Notifications', 'synditracker' ); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e( 'Recipient Emails', 'synditracker' ); ?></th>
                            <td>
                                <textarea name="st_email_recipients" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'admin@site.com, boss@muneebgawri.com', 'synditracker' ); ?>"><?php echo esc_textarea( $email_recipients ); ?></textarea>
                                <p class="description"><?php esc_html_e( 'Enter one or more email addresses, separated by commas or new lines.', 'synditracker' ); ?></p>
                                <label><input type="checkbox" name="st_email_enabled" <?php checked( 1, isset( $settings['email_enabled'] ) ? $settings['email_enabled'] : 0 ); ?>> <?php esc_html_e( 'Enable Email Alerts', 'synditracker' ); ?></label>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="st-section st-card">
                    <h3><?php esc_html_e( 'Discord Webhook', 'synditracker' ); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th><label for="st_discord_webhook"><?php esc_html_e( 'Discord Webhook URL', 'synditracker' ); ?></label></th>
                            <td>
                                <input type="url" name="st_discord_webhook" id="st_discord_webhook" value="<?php echo esc_url( isset( $settings['discord_webhook'] ) ? $settings['discord_webhook'] : '' ); ?>" class="large-text" placeholder="https://discord.com/api/webhooks/...">
                                <p class="description"><?php esc_html_e( 'Discord alerts are sent via your server to your chosen channel instantly.', 'synditracker' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Event Subscriptions', 'synditracker' ); ?></th>
                            <td>
                                <label><input type="checkbox" name="st_discord_enabled" <?php checked( 1, isset( $settings['discord_enabled'] ) ? $settings['discord_enabled'] : 0 ); ?>> <strong><?php esc_html_e( 'Enable Discord Spike Alerts', 'synditracker' ); ?></strong></label>
                                <p class="description"><?php esc_html_e( 'Notifies Discord when content duplication exceeds the threshold.', 'synditracker' ); ?></p>

                                <label><input type="checkbox" name="st_error_discord" <?php checked( 1, isset( $settings['error_discord'] ) ? $settings['error_discord'] : 0 ); ?>> <strong><?php esc_html_e( 'Enable Discord System Error Alerts', 'synditracker' ); ?></strong></label>
                                <p class="description"><?php esc_html_e( 'Notifies Discord if critical background processes fail or API authentication fails repeatedly.', 'synditracker' ); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <p class="submit">
                    <input type="submit" name="st_save_alerts" class="button button-primary" value="<?php esc_attr_e( 'Save Settings', 'synditracker' ); ?>">
                    <button type="button" id="st-test-discord" class="button button-secondary" style="margin-left: 10px;"><?php esc_html_e( 'Send Test Alert', 'synditracker' ); ?></button>
                    <span id="st-test-discord-status" style="margin-left: 10px; font-weight: bold;"></span>
                </p>
            </form>
        </div>

        <div id="logs" class="tab-content" style="display:none;">
            <h2><?php esc_html_e( 'System Logs', 'synditracker' ); ?></h2>
            <p><?php esc_html_e( 'Recent debug logs for troubleshooting.', 'synditracker' ); ?></p>
            <textarea readonly style="width: 100%; height: 400px; font-family: monospace; background: #f0f0f1;"><?php
                $logs = \Synditracker\Core\Logger::get_instance()->get_logs( 100 );
                echo esc_textarea( implode( '', $logs ) );
            ?></textarea>
            <form method="post" style="margin-top: 10px;">
                <input type="hidden" name="st_clear_logs" value="1">
                <?php wp_nonce_field( 'st_clear_logs_action', 'st_clear_logs_nonce' ); ?>
                <input type="submit" class="button button-secondary" value="<?php esc_attr_e( 'Clear Logs', 'synditracker' ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to clear all logs?', 'synditracker' ) ); ?>');">
            </form>
        </div>
        <?php
    }

    /**
     * AJAX handler for validating API key.
     *
     * @since  1.0.0
     * @return void
     */
    public function ajax_validate_key() {
        check_ajax_referer( 'st_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'synditracker' ) ) );
        }

        $hub_url = isset( $_POST['hub_url'] ) ? sanitize_text_field( wp_unslash( $_POST['hub_url'] ) ) : '';
        $api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

        if ( empty( $hub_url ) || empty( $api_key ) ) {
            wp_send_json_error( array( 'message' => __( 'Missing Hub URL or Site Key.', 'synditracker' ) ) );
        }

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
                    'X-Synditracker-Key' => $api_key,
                ),
                'timeout' => 10,
            )
        );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'message' => __( 'Connection Failed: ', 'synditracker' ) . $response->get_error_message() ) );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 === $code ) {
            update_option( 'synditracker_hub_url', $hub_url );
            update_option( 'synditracker_site_key', $api_key );
            wp_send_json_success( array( 'message' => __( 'Connection Successful! Settings Saved.', 'synditracker' ) ) );
        } else {
            $body = wp_remote_retrieve_body( $response );
            $data = json_decode( $body, true );
            $msg  = isset( $data['message'] ) ? $data['message'] : __( 'Unknown Error', 'synditracker' );
            /* translators: 1: HTTP status code, 2: error message */
            wp_send_json_error( array( 'message' => sprintf( __( 'Hub Error (%1$d): %2$s', 'synditracker' ), $code, $msg ) ) );
        }
    }

    /**
     * AJAX handler for saving alert settings.
     *
     * @since  1.0.0
     * @return void
     */
    public function ajax_handle_save_alerts() {
        check_ajax_referer( 'st_admin_nonce', '_ajax_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'synditracker' ) ) );
        }

        if ( isset( $_POST['st_discord_webhook'] ) ) {
            $url = isset( $_POST['st_discord_webhook'] ) ? sanitize_text_field( wp_unslash( $_POST['st_discord_webhook'] ) ) : '';
            update_option( 'synditracker_discord_webhook', $url );

            \Synditracker\Core\Logger::get_instance()->log( 'Discord Webhook URL updated by user.', 'INFO' );
            wp_send_json_success( array( 'message' => __( 'Settings saved successfully.', 'synditracker' ) ) );
        }

        wp_send_json_error( array( 'message' => __( 'No data received.', 'synditracker' ) ) );
    }

    /**
     * AJAX handler for generating a new API key.
     *
     * @since  1.0.0
     * @return void
     */
    public function ajax_generate_key() {
        check_ajax_referer( 'st_admin_nonce' );

        $site_name = isset( $_POST['st_site_name'] ) ? sanitize_text_field( wp_unslash( $_POST['st_site_name'] ) ) : '';

        if ( empty( $site_name ) ) {
            wp_send_json_error( array( 'message' => __( 'Partner Site Name is required.', 'synditracker' ) ) );
        }

        $keys_mgr = Keys::get_instance();
        $key      = $keys_mgr->generate_key( $site_name );

        if ( $key ) {
            /* translators: %s: site name */
            wp_send_json_success( array( 'message' => sprintf( __( 'New key generated successfully for %s', 'synditracker' ), $site_name ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Failed to generate key.', 'synditracker' ) ) );
        }
    }

    /**
     * AJAX handler for saving alert settings via AJAX.
     *
     * @since  1.0.0
     * @return void
     */
    public function ajax_save_alerts() {
        check_ajax_referer( 'st_admin_nonce' );

        $discord_url = isset( $_POST['st_discord_webhook'] ) ? esc_url_raw( wp_unslash( $_POST['st_discord_webhook'] ) ) : '';
        if ( ! empty( $discord_url ) && false === strpos( $discord_url, 'discord.com/api/webhooks/' ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid Discord Webhook URL.', 'synditracker' ) ) );
        }

        $threshold = isset( $_POST['st_threshold'] ) ? absint( $_POST['st_threshold'] ) : SYNDITRACKER_DEFAULT_THRESHOLD;
        if ( $threshold < 1 ) {
            wp_send_json_error( array( 'message' => __( 'Threshold must be at least 1.', 'synditracker' ) ) );
        }

        $settings = array(
            'email_enabled'    => isset( $_POST['st_email_enabled'] ) ? 1 : 0,
            'email_recipients' => isset( $_POST['st_email_recipients'] ) ? sanitize_textarea_field( wp_unslash( $_POST['st_email_recipients'] ) ) : '',
            'discord_enabled'  => isset( $_POST['st_discord_enabled'] ) ? 1 : 0,
            'error_discord'    => isset( $_POST['st_error_discord'] ) ? 1 : 0,
            'discord_webhook'  => $discord_url,
            'scanning_window'  => isset( $_POST['st_scanning_window'] ) ? absint( $_POST['st_scanning_window'] ) : 1,
            'alert_frequency'  => isset( $_POST['st_alert_frequency'] ) ? sanitize_text_field( wp_unslash( $_POST['st_alert_frequency'] ) ) : 'immediate',
        );

        update_option( 'synditracker_alert_settings', $settings );
        update_option( 'synditracker_spike_threshold', $threshold );

        wp_send_json_success( array( 'message' => __( 'Settings saved successfully.', 'synditracker' ) ) );
    }
}
