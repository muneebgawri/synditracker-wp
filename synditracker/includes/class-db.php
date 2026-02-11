<?php
namespace Synditracker\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Database Helper Class.
 */
class DB
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
     * Log a syndication event.
     */
    public function log_syndication($data)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'synditracker_logs';

        // Check for duplicates in the last 24 hours.
        $is_duplicate = $this->check_is_duplicate($data['post_id'], $data['site_url']);

        $result = $wpdb->insert(
            $table_name,
            array(
                'post_id'      => $data['post_id'],
                'site_url'     => $data['site_url'],
                'site_name'    => $data['site_name'],
                'aggregator'   => $data['aggregator'],
                'is_duplicate' => $is_duplicate ? 1 : 0,
                'timestamp'    => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%s', '%d', '%s')
        );

        if ($result && $is_duplicate) {
            $this->check_for_spikes();
        }

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Check if an entry is a duplicate (within 24 hours).
     */
    public function check_is_duplicate($post_id, $site_url)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'synditracker_logs';

        $query = $wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name 
             WHERE post_id = %d 
             AND site_url = %s 
             AND timestamp >= DATE_SUB(%s, INTERVAL 24 HOUR)",
            $post_id,
            $site_url,
            current_time('mysql')
        );

        return (int) $wpdb->get_var($query) > 0;
    }

    /**
     * Get syndication metrics.
     */
    public function get_metrics()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'synditracker_logs';

        $total = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        $duplicates = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE is_duplicate = 1");
        $unique_partners = $wpdb->get_var("SELECT COUNT(DISTINCT site_url) FROM $table_name");

        return array(
            'total'           => (int) $total,
            'duplicates'      => (int) $duplicates,
            'unique_partners' => (int) $unique_partners,
            'duplicate_rate'  => $total > 0 ? round(($duplicates / $total) * 100, 2) : 0,
        );
    }

    /**
     * Check for duplicate spikes and send alert.
     */
    private function check_for_spikes()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'synditracker_logs';
        $threshold = get_option('synditracker_spike_threshold', 5);
        $settings = get_option('synditracker_alert_settings', array());
        
        $window = isset($settings['scanning_window']) ? intval($settings['scanning_window']) : 1;
        $frequency = isset($settings['alert_frequency']) ? $settings['alert_frequency'] : 'immediate';

        // Count duplicates in the last X hours.
        $query = $wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name 
             WHERE is_duplicate = 1 
             AND timestamp >= DATE_SUB(%s, INTERVAL %d HOUR)",
            current_time('mysql'),
            $window
        );

        $spike_count = (int) $wpdb->get_var($query);

        if ($spike_count >= $threshold) {
            // If frequency is immediate, send now. 
            // Otherwise, cron will handle batching via separate logic.
            if ($frequency === 'immediate') {
                Alerts::get_instance()->trigger_spike_alert($spike_count);
            }
        }
    }

    /**
     * Get metrics for a specific hour window.
     */
    public function get_metrics_for_window($hours)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'synditracker_logs';

        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE timestamp >= DATE_SUB(%s, INTERVAL %d HOUR)",
            current_time('mysql'),
            $hours
        ));

        $duplicates = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE is_duplicate = 1 AND timestamp >= DATE_SUB(%s, INTERVAL %d HOUR)",
            current_time('mysql'),
            $hours
        ));

        return array(
            'total'      => (int) $total,
            'duplicates' => (int) $duplicates,
        );
    }
}
