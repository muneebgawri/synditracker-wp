<?php
/**
 * Alerts Class.
 *
 * Handles all notification and alerting functionality.
 *
 * @package Synditracker
 * @since   1.0.0
 */

namespace Synditracker\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Alerts Class.
 *
 * @since 1.0.0
 */
class Alerts {

    /**
     * Singleton instance of this class.
     *
     * @since 1.0.0
     * @var Alerts|null
     */
    private static $instance = null;

    /**
     * Constructor.
     *
     * @since 1.0.0
     */
    public function __construct() {
        add_action( 'synditracker_heartbeat_event', array( $this, 'send_heartbeat_summaries' ) );
    }

    /**
     * Get the singleton instance.
     *
     * @since  1.0.0
     * @return Alerts
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Send heartbeat summaries (scheduled cron job).
     *
     * @since  1.0.0
     * @return void
     */
    public function send_heartbeat_summaries() {
        $db              = DB::get_instance();
        $options         = get_option( 'synditracker_alert_settings', array() );
        $default_threshold = defined( 'SYNDITRACKER_DEFAULT_THRESHOLD' ) ? SYNDITRACKER_DEFAULT_THRESHOLD : 5;
        $threshold       = get_option( 'synditracker_spike_threshold', $default_threshold );
        $window          = isset( $options['scanning_window'] ) ? intval( $options['scanning_window'] ) : 1;
        $frequency       = isset( $options['alert_frequency'] ) ? $options['alert_frequency'] : 'immediate';

        // Get metrics for the specific window.
        $metrics = $db->get_metrics_for_window( $window );

        if ( $metrics['duplicates'] >= $threshold ) {
            // Send Email.
            if ( ! empty( $options['email_enabled'] ) ) {
                $this->send_heartbeat_email( $metrics, $threshold, $window, $frequency );
            }

            // Send Discord.
            if ( ! empty( $options['discord_enabled'] ) && ! empty( $options['discord_webhook'] ) ) {
                $this->send_heartbeat_discord( $metrics, $threshold, $window, $frequency );
            }

            /**
             * Fires after heartbeat summaries are sent.
             *
             * @since 1.0.6
             * @param array  $metrics   The metrics data.
             * @param int    $threshold The configured threshold.
             * @param string $frequency The alert frequency setting.
             */
            do_action( 'synditracker_heartbeat_sent', $metrics, $threshold, $frequency );
        }
    }

    /**
     * Send heartbeat email summary.
     *
     * @since  1.0.0
     * @param  array  $metrics   The metrics data.
     * @param  int    $threshold The threshold value.
     * @param  int    $window    The scanning window in hours.
     * @param  string $frequency The alert frequency.
     * @return void
     */
    private function send_heartbeat_email( $metrics, $threshold, $window, $frequency ) {
        $options        = get_option( 'synditracker_alert_settings', array() );
        $recipients_str = isset( $options['email_recipients'] ) ? $options['email_recipients'] : get_option( 'admin_email' );
        $recipients     = array_filter( array_map( 'trim', preg_split( '/[,\n\r]+/', $recipients_str ) ) );

        if ( empty( $recipients ) ) {
            $recipients = array( get_option( 'admin_email' ) );
        }

        /* translators: %s: frequency name */
        $subject = sprintf( __( '[Synditracker Heartbeat] %s Summary', 'synditracker' ), ucfirst( $frequency ) );

        $rate = $metrics['total'] > 0 ? ( $metrics['duplicates'] / $metrics['total'] ) * 100 : 0;

        /* translators: 1: frequency, 2: window hours, 3: total events, 4: duplicates, 5: rate, 6: threshold, 7: dashboard URL */
        $message = sprintf(
            __(
                "Hello,\n\nThis is your scheduled %1\$s heartbeat summary for Synditracker.\n\nSummary for last %2\$d hours:\n- Total Events: %3\$d\n- Duplicates: %4\$d\n- Violation Rate: %5\$.2f%%\n\nThreshold: %6\$d\n\nView full stream: %7\$s\n\nRegards,\nSynditracker Core",
                'synditracker'
            ),
            $frequency,
            $window,
            $metrics['total'],
            $metrics['duplicates'],
            $rate,
            $threshold,
            admin_url( 'admin.php?page=synditracker' )
        );

        wp_mail( $recipients, $subject, $message );
    }

    /**
     * Send heartbeat Discord summary.
     *
     * @since  1.0.0
     * @param  array  $metrics   The metrics data.
     * @param  int    $threshold The threshold value.
     * @param  int    $window    The scanning window in hours.
     * @param  string $frequency The alert frequency.
     * @return void
     */
    private function send_heartbeat_discord( $metrics, $threshold, $window, $frequency ) {
        $options     = get_option( 'synditracker_alert_settings', array() );
        $webhook_url = isset( $options['discord_webhook'] ) ? $options['discord_webhook'] : '';

        if ( empty( $webhook_url ) ) {
            return;
        }

        $rate = $metrics['total'] > 0 ? ( $metrics['duplicates'] / $metrics['total'] ) * 100 : 0;

        $data = array(
            'username' => 'Synditracker Heartbeat',
            'embeds'   => array(
                array(
                    'title'       => sprintf( '💓 %s PULSE SUMMARY', strtoupper( $frequency ) ),
                    'description' => sprintf( 'Aggregated network performance for the last %d hour(s).', $window ),
                    'color'       => 3447003, // Blue.
                    'fields'      => array(
                        array(
                            'name'   => __( 'Total Events', 'synditracker' ),
                            'value'  => (string) $metrics['total'],
                            'inline' => true,
                        ),
                        array(
                            'name'   => __( 'Duplicates', 'synditracker' ),
                            'value'  => (string) $metrics['duplicates'],
                            'inline' => true,
                        ),
                        array(
                            'name'   => __( 'Intensity', 'synditracker' ),
                            'value'  => sprintf( '%.2f%%', $rate ),
                            'inline' => true,
                        ),
                        array(
                            'name'   => __( 'Registry Status', 'synditracker' ),
                            'value'  => __( 'Operational', 'synditracker' ),
                            'inline' => false,
                        ),
                    ),
                    'footer'      => array( 'text' => 'Branded Monitoring by Muneeb Gawri' ),
                    'timestamp'   => gmdate( 'c' ),
                ),
            ),
        );

        wp_remote_post(
            $webhook_url,
            array(
                'method'   => 'POST',
                'headers'  => array( 'Content-Type' => 'application/json' ),
                'body'     => wp_json_encode( $data ),
                'blocking' => false,
            )
        );
    }

    /**
     * Trigger a spike alert.
     *
     * @since  1.0.0
     * @param  int $count The duplicate count.
     * @return void
     */
    public function trigger_spike_alert( $count ) {
        $options           = get_option( 'synditracker_alert_settings', array() );
        $default_threshold = defined( 'SYNDITRACKER_DEFAULT_THRESHOLD' ) ? SYNDITRACKER_DEFAULT_THRESHOLD : 5;
        $threshold         = get_option( 'synditracker_spike_threshold', $default_threshold );
        $window            = isset( $options['scanning_window'] ) ? $options['scanning_window'] : 1;

        // Email Alert.
        if ( ! empty( $options['email_enabled'] ) ) {
            $this->send_email_alert( $count, $threshold, $window );
        }

        // Discord Alert.
        if ( ! empty( $options['discord_enabled'] ) && ! empty( $options['discord_webhook'] ) ) {
            $this->send_discord_alert( $count, $threshold, $window );
        }

        /**
         * Fires after a spike alert is triggered.
         *
         * @since 1.0.6
         * @param int $count     The duplicate count.
         * @param int $threshold The configured threshold.
         * @param int $window    The scanning window.
         */
        do_action( 'synditracker_spike_alert_sent', $count, $threshold, $window );
    }

    /**
     * Send email alert for duplicate spike.
     *
     * @since  1.0.0
     * @param  int $count     The duplicate count.
     * @param  int $threshold The threshold value.
     * @param  int $window    The scanning window in hours.
     * @return void
     */
    private function send_email_alert( $count, $threshold, $window ) {
        $options        = get_option( 'synditracker_alert_settings', array() );
        $recipients_str = isset( $options['email_recipients'] ) ? $options['email_recipients'] : get_option( 'admin_email' );

        // Parse recipients (comma or newline).
        $recipients = array_filter( array_map( 'trim', preg_split( '/[,\n\r]+/', $recipients_str ) ) );

        if ( empty( $recipients ) ) {
            $recipients = array( get_option( 'admin_email' ) );
        }

        $subject = __( '[Synditracker Alert] Duplicate Syndication Spike Detected', 'synditracker' );

        /* translators: 1: window hours, 2: duplicate count, 3: threshold, 4: dashboard URL */
        $message = sprintf(
            __(
                "Hello,\n\nSynditracker has detected a spike in duplicate syndications.\n\nTotal duplicates in the last %1\$d hour(s): %2\$d\nThreshold set at: %3\$d\n\nPlease check the Synditracker dashboard for more details: %4\$s\n\nRegards,\nSynditracker Core",
                'synditracker'
            ),
            $window,
            $count,
            $threshold,
            admin_url( 'admin.php?page=synditracker' )
        );

        wp_mail( $recipients, $subject, $message );
    }

    /**
     * Send Discord alert.
     *
     * Supports both Spike Alerts (3 args) and Generic Messages (1 arg array).
     *
     * @since  1.0.0
     * @param  int|array $arg1 Duplicate count or params array.
     * @param  int|null  $arg2 Threshold (for spike alert).
     * @param  int|null  $arg3 Window (for spike alert).
     * @return void
     */
    public function send_discord_alert( $arg1, $arg2 = null, $arg3 = null ) {
        $options     = get_option( 'synditracker_alert_settings', array() );
        $webhook_url = isset( $options['discord_webhook'] ) ? $options['discord_webhook'] : '';

        if ( empty( $webhook_url ) ) {
            Logger::get_instance()->log( 'Discord Alert Skipped: No webhook URL configured.', 'INFO' );
            return;
        }

        // Validate webhook URL format.
        if ( false === strpos( $webhook_url, 'https://discord.com/api/webhooks/' ) ) {
            Logger::get_instance()->log( 'Discord Alert Skipped: Invalid webhook URL format.', 'WARNING' );
            return;
        }

        // Check if this is a Spike Alert (3 arguments).
        if ( null !== $arg2 && null !== $arg3 ) {
            $count     = $arg1;
            $threshold = $arg2;
            $window    = $arg3;

            $data = array(
                'username' => 'Synditracker Core',
                'embeds'   => array(
                    array(
                        'title'       => '🚀 DUPLICATE SPIKE DETECTED',
                        'description' => __( 'Synditracker has detected a surge in duplicate publishing events within the designated scanning window.', 'synditracker' ),
                        'color'       => 15158332, // Red.
                        'fields'      => array(
                            array(
                                'name'   => __( 'Duplicates Found', 'synditracker' ),
                                'value'  => (string) $count,
                                'inline' => true,
                            ),
                            array(
                                'name'   => __( 'Window', 'synditracker' ),
                                'value'  => $window . ' hour(s)',
                                'inline' => true,
                            ),
                            array(
                                'name'   => __( 'Threshold', 'synditracker' ),
                                'value'  => (string) $threshold,
                                'inline' => true,
                            ),
                            array(
                                'name'   => __( 'Pulse Command', 'synditracker' ),
                                'value'  => sprintf( '[View Dashboard](%s)', admin_url( 'admin.php?page=synditracker' ) ),
                                'inline' => false,
                            ),
                        ),
                        'footer'      => array( 'text' => 'Branded System by Muneeb Gawri' ),
                        'timestamp'   => gmdate( 'c' ),
                    ),
                ),
            );
        } else {
            // Generic / Test Alert (1 argument array).
            $params    = $arg1;
            $site_name = isset( $params['site_name'] ) ? $params['site_name'] : 'Unknown';
            $site_url  = isset( $params['site_url'] ) ? $params['site_url'] : 'N/A';

            $data = array(
                'username' => 'Synditracker Core',
                'embeds'   => array(
                    array(
                        'title'       => '🔔 ' . __( 'Syndication Alert', 'synditracker' ),
                        'description' => sprintf(
                            "**%s**\n\n**%s:** %s\n**URL:** %s",
                            __( 'New Event Reported', 'synditracker' ),
                            __( 'Source', 'synditracker' ),
                            $site_name,
                            $site_url
                        ),
                        'color'       => 3066993, // Green.
                        'timestamp'   => gmdate( 'c' ),
                        'footer'      => array( 'text' => 'Synditracker Notification' ),
                    ),
                ),
            );
        }

        $response = wp_remote_post(
            $webhook_url,
            array(
                'method'   => 'POST',
                'headers'  => array( 'Content-Type' => 'application/json' ),
                'body'     => wp_json_encode( $data ),
                'blocking' => true,
            )
        );

        if ( is_wp_error( $response ) ) {
            Logger::get_instance()->log( 'Discord Alert Failed: ' . $response->get_error_message(), 'ERROR' );
        } else {
            $code = wp_remote_retrieve_response_code( $response );
            if ( $code >= 200 && $code < 300 ) {
                Logger::get_instance()->log( 'Discord Alert Sent Successfully.', 'INFO' );
            } else {
                Logger::get_instance()->log( sprintf( 'Discord Alert Failed with Status Code: %d', $code ), 'ERROR' );
            }
        }
    }

    /**
     * Check and send alert (callback for new logs).
     *
     * @since  1.0.0
     * @param  array $params The log parameters.
     * @return void
     */
    public function check_and_send( $params ) {
        // This method exists as a hook point for future extensibility.
        // Currently, spike detection is handled in DB::check_for_spikes().
        /**
         * Fires when checking if an alert should be sent.
         *
         * @since 1.0.6
         * @param array $params The log parameters.
         */
        do_action( 'synditracker_check_alert', $params );
    }

    /**
     * Log and notify of a critical error.
     *
     * @since  1.0.0
     * @param  string $message The error message.
     * @return void
     */
    public function notify_error( $message ) {
        $options = get_option( 'synditracker_alert_settings', array() );

        if ( ! empty( $options['error_discord'] ) && ! empty( $options['discord_webhook'] ) ) {
            $webhook_url = $options['discord_webhook'];

            // Validate webhook URL.
            if ( false === strpos( $webhook_url, 'https://discord.com/api/webhooks/' ) ) {
                return;
            }

            $data = array(
                'username' => 'Synditracker Error Reporter',
                'embeds'   => array(
                    array(
                        'title'       => '⚠️ ' . __( 'SYNDITRACKER SYSTEM ERROR', 'synditracker' ),
                        'description' => esc_html( $message ),
                        'color'       => 16733952, // Orange.
                        'timestamp'   => gmdate( 'c' ),
                        'footer'      => array(
                            'text' => 'Security Audit Hook by Muneeb Gawri',
                        ),
                    ),
                ),
            );

            wp_remote_post(
                $webhook_url,
                array(
                    'method'   => 'POST',
                    'headers'  => array( 'Content-Type' => 'application/json' ),
                    'body'     => wp_json_encode( $data ),
                    'blocking' => false,
                )
            );
        }
    }
}
