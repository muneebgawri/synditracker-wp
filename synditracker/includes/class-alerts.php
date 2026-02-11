<?php
namespace Synditracker\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Alerts Class.
 */
class Alerts
{
    /**
     * Instance of this class.
     */
    private static $instance = null;

    /**
     * Constructor.
     */
    public function __construct()
    {
        add_action('synditracker_heartbeat_event', array($this, 'send_heartbeat_summaries'));
    }

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
     * Send heartbeat summaries (scheduled).
     */
    public function send_heartbeat_summaries()
    {
        $db = DB::get_instance();
        $options = get_option('synditracker_alert_settings', array());
        $threshold = get_option('synditracker_spike_threshold', 5);
        $window = isset($options['scanning_window']) ? intval($options['scanning_window']) : 1;
        $frequency = isset($options['alert_frequency']) ? $options['alert_frequency'] : 'immediate';

        // Get metrics for the specific window.
        $metrics = $db->get_metrics_for_window($window);

        if ($metrics['duplicates'] >= $threshold) {
            // Send Email.
            if (!empty($options['email_enabled'])) {
                $this->send_heartbeat_email($metrics, $threshold, $window, $frequency);
            }

            // Send Discord.
            if (!empty($options['discord_enabled']) && !empty($options['discord_webhook'])) {
                $this->send_heartbeat_discord($metrics, $threshold, $window, $frequency);
            }
        }
    }

    /**
     * Send heartbeat email summary.
     */
    private function send_heartbeat_email($metrics, $threshold, $window, $frequency)
    {
        $recipients_str = get_option('synditracker_alert_settings')['email_recipients'];
        $recipients = array_filter(array_map('trim', preg_split('/[,\n\r]+/', $recipients_str)));
        
        $subject = sprintf('[Synditracker Heartbeat] %s Summary', ucfirst($frequency));
        $message = sprintf(
            "Hello,\n\nThis is your scheduled %s heartbeat summary for Synditracker.\n\nSummary for last %d hours:\n- Total Events: %d\n- Duplicates: %d\n- Violation Rate: %.2f%%\n\nThreshold: %d\n\nView full stream: %s\n\nRegards,\nSynditracker Core",
            $frequency,
            $window,
            $metrics['total'],
            $metrics['duplicates'],
            ($metrics['total'] > 0 ? ($metrics['duplicates'] / $metrics['total']) * 100 : 0),
            $threshold,
            admin_url('admin.php?page=synditracker')
        );

        wp_mail($recipients, $subject, $message);
    }

    /**
     * Send heartbeat Discord summary.
     */
    private function send_heartbeat_discord($metrics, $threshold, $window, $frequency)
    {
        $webhook_url = get_option('synditracker_alert_settings')['discord_webhook'];
        $data = array(
            'username' => 'Synditracker Heartbeat',
            'embeds'   => array(
                array(
                    'title'       => sprintf('💓 %s PULSE SUMMARY', strtoupper($frequency)),
                    'description' => sprintf('Aggregated network performance for the last %d hour(s).', $window),
                    'color'       => 3447003, // Blue
                    'fields'      => array(
                        array('name' => 'Total Events', 'value' => (string) $metrics['total'], 'inline' => true),
                        array('name' => 'Duplicates', 'value' => (string) $metrics['duplicates'], 'inline' => true),
                        array('name' => 'Intensity', 'value' => sprintf('%.2f%%', ($metrics['total'] > 0 ? ($metrics['duplicates'] / $metrics['total']) * 100 : 0)), 'inline' => true),
                        array('name' => 'Registry Status', 'value' => 'Operational', 'inline' => false),
                    ),
                    'footer'      => array('text' => 'Branded Monitoring by Muneeb Gawri'),
                    'timestamp'   => date('c'),
                ),
            ),
        );
        wp_remote_post($webhook_url, array(
            'method'      => 'POST',
            'headers'     => array('Content-Type' => 'application/json'),
            'body'        => wp_json_encode($data),
            'blocking'    => false,
        ));
    }

    /**
     * Trigger a spike alert.
     */
    public function trigger_spike_alert($count)
    {
        $options = get_option('synditracker_alert_settings', array());
        $threshold = get_option('synditracker_spike_threshold', 5);
        $window = isset($options['scanning_window']) ? $options['scanning_window'] : 1;

        // Email Alert.
        if (!empty($options['email_enabled'])) {
            $this->send_email_alert($count, $threshold, $window);
        }

        // Discord Alert.
        if (!empty($options['discord_enabled']) && !empty($options['discord_webhook'])) {
            $this->send_discord_alert($count, $threshold, $window);
        }
    }

    /**
     * Send email alert.
     */
    private function send_email_alert($count, $threshold, $window)
    {
        $options = get_option('synditracker_alert_settings', array());
        $recipients_str = isset($options['email_recipients']) ? $options['email_recipients'] : get_option('admin_email');
        
        // Parse recipients (comma or newline).
        $recipients = array_filter(array_map('trim', preg_split('/[,\n\r]+/', $recipients_str)));
        
        if (empty($recipients)) {
            $recipients = array(get_option('admin_email'));
        }

        $subject = '[Synditracker Alert] Duplicate Syndication Spike Detected';
        $message = sprintf(
            "Hello,\n\nSynditracker has detected a spike in duplicate syndications.\n\nTotal duplicates in the last %d hour(s): %d\nThreshold set at: %d\n\nPlease check the Synditracker dashboard for more details: %s\n\nRegards,\nSynditracker Core",
            $window,
            $count,
            $threshold,
            admin_url('admin.php?page=synditracker')
        );

        wp_mail($recipients, $subject, $message);
    }

    /**
     * Send Discord alert.
     */
    private function send_discord_alert($count, $threshold, $window)
    {
        $options = get_option('synditracker_alert_settings', array());
        $webhook_url = $options['discord_webhook'];

        $data = array(
            'username' => 'Synditracker Core',
            'embeds'   => array(
                array(
                    'title'       => '🚀 DUPLICATE SPIKE DETECTED',
                    'description' => sprintf('Synditracker has detected a surge in duplicate publishing events within the designated scanning window.'),
                    'color'       => 15158332, // Red
                    'fields'      => array(
                        array('name' => 'Duplicates Found', 'value' => (string) $count, 'inline' => true),
                        array('name' => 'Window', 'value' => $window . ' hour(s)', 'inline' => true),
                        array('name' => 'Threshold', 'value' => (string) $threshold, 'inline' => true),
                        array('name' => 'Pulse Command', 'value' => sprintf('[View Dashboard](%s)', admin_url('admin.php?page=synditracker')), 'inline' => false),
                    ),
                    'footer'      => array(
                        'text' => 'Branded System by Muneeb Gawri',
                    ),
                    'timestamp'   => date('c'),
                ),
            ),
        );

        wp_remote_post($webhook_url, array(
            'method'      => 'POST',
            'headers'     => array('Content-Type' => 'application/json'),
            'body'        => wp_json_encode($data),
            'blocking'    => false,
        ));
    }

    /**
     * Log and notify of a critical error.
     */
    public function notify_error($message)
    {
        $options = get_option('synditracker_alert_settings', array());
        
        if (!empty($options['error_discord']) && !empty($options['discord_webhook'])) {
            $webhook_url = $options['discord_webhook'];
            $data = array(
                'username' => 'Synditracker Error Reporter',
                'embeds'   => array(
                    array(
                        'title'       => '⚠️ SYNDITRACKER SYSTEM ERROR',
                        'description' => esc_html($message),
                        'color'       => 16733952, // Orange
                        'timestamp'   => date('c'),
                        'footer'      => array(
                            'text' => 'Security Audit Hook by Muneeb Gawri',
                        ),
                    ),
                ),
            );
            wp_remote_post($webhook_url, array(
                'method'      => 'POST',
                'headers'     => array('Content-Type' => 'application/json'),
                'body'        => wp_json_encode($data),
                'blocking'    => false,
            ));
        }
    }
}
