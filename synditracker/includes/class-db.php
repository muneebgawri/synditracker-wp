<?php
/**
 * Database Helper Class.
 *
 * Handles all database operations for the Synditracker system.
 *
 * @package Synditracker
 * @since   1.0.0
 */

namespace Synditracker\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Database Helper Class.
 *
 * @since 1.0.0
 */
class DB {

    /**
     * Singleton instance of this class.
     *
     * @since 1.0.0
     * @var DB|null
     */
    private static $instance = null;

    /**
     * Logs table name.
     *
     * @since 1.0.6
     * @var string
     */
    private $table_logs;

    /**
     * Keys table name.
     *
     * @since 1.0.6
     * @var string
     */
    private $table_keys;

    /**
     * Alerts table name.
     *
     * @since 1.0.7
     * @var string
     */
    private $table_alerts;

    /**
     * Constructor.
     *
     * @since 1.0.6
     */
    public function __construct() {
        global $wpdb;
        $logs_table         = defined( 'SYNDITRACKER_TABLE_LOGS' ) ? SYNDITRACKER_TABLE_LOGS : 'synditracker_logs';
        $keys_table         = defined( 'SYNDITRACKER_TABLE_KEYS' ) ? SYNDITRACKER_TABLE_KEYS : 'synditracker_keys';
        $alerts_table       = defined( 'SYNDITRACKER_TABLE_ALERTS' ) ? SYNDITRACKER_TABLE_ALERTS : 'synditracker_alerts';
        $this->table_logs   = $wpdb->prefix . $logs_table;
        $this->table_keys   = $wpdb->prefix . $keys_table;
        $this->table_alerts = $wpdb->prefix . $alerts_table;
    }

    /**
     * Get the singleton instance.
     *
     * @since  1.0.0
     * @return DB
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Log a syndication event.
     *
     * @since  1.0.0
     * @since  1.0.6 Added return value validation and hooks.
     * @param  array $data The syndication data to log.
     * @return int|false The inserted log ID on success, false on failure.
     */
    public function log_syndication( $data ) {
        global $wpdb;

        // Check for duplicates in the last 24 hours.
        $is_duplicate = $this->check_is_duplicate( $data['post_id'], $data['site_url'] );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->insert(
            $this->table_logs,
            array(
                'post_id'      => $data['post_id'],
                'site_url'     => $data['site_url'],
                'site_name'    => $data['site_name'],
                'aggregator'   => $data['aggregator'],
                'is_duplicate' => $is_duplicate ? 1 : 0,
                'timestamp'    => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%s', '%s', '%d', '%s' )
        );

        if ( false === $result ) {
            Logger::get_instance()->log(
                sprintf( 'Database insert failed: %s', $wpdb->last_error ),
                'ERROR'
            );
            return false;
        }

        $log_id = $wpdb->insert_id;

        if ( $is_duplicate ) {
            $this->check_for_spikes();
        }

        return $log_id;
    }

    /**
     * Check if an entry is a duplicate (within 24 hours).
     *
     * @since  1.0.0
     * @param  int    $post_id  The original post ID.
     * @param  string $site_url The site URL.
     * @return bool True if duplicate, false otherwise.
     */
    public function check_is_duplicate( $post_id, $site_url ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_logs}
                 WHERE post_id = %d
                 AND site_url = %s
                 AND timestamp >= DATE_SUB(%s, INTERVAL 24 HOUR)",
                $post_id,
                $site_url,
                current_time( 'mysql' )
            )
        );

        return (int) $count > 0;
    }

    /**
     * Get syndication metrics.
     *
     * @since  1.0.0
     * @since  1.0.6 Optimized with single query aggregation.
     * @return array The metrics array.
     */
    public function get_metrics() {
        global $wpdb;

        // Use single query with conditional aggregation for efficiency.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $metrics = $wpdb->get_row(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN is_duplicate = 1 THEN 1 ELSE 0 END) as duplicates,
                COUNT(DISTINCT site_url) as unique_partners
             FROM {$this->table_logs}"
        );

        $total      = $metrics ? (int) $metrics->total : 0;
        $duplicates = $metrics ? (int) $metrics->duplicates : 0;

        return array(
            'total'           => $total,
            'duplicates'      => $duplicates,
            'unique_partners' => $metrics ? (int) $metrics->unique_partners : 0,
            'duplicate_rate'  => $total > 0 ? round( ( $duplicates / $total ) * 100, 2 ) : 0,
        );
    }

    /**
     * Check for duplicate spikes and send alert.
     *
     * @since  1.0.0
     * @return void
     */
    private function check_for_spikes() {
        global $wpdb;

        $default_threshold = defined( 'SYNDITRACKER_DEFAULT_THRESHOLD' ) ? SYNDITRACKER_DEFAULT_THRESHOLD : 5;
        $threshold         = get_option( 'synditracker_spike_threshold', $default_threshold );
        $settings          = get_option( 'synditracker_alert_settings', array() );

        $window    = isset( $settings['scanning_window'] ) ? intval( $settings['scanning_window'] ) : 1;
        $frequency = isset( $settings['alert_frequency'] ) ? $settings['alert_frequency'] : 'immediate';

        // Count duplicates in the last X hours.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $spike_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_logs}
                 WHERE is_duplicate = 1
                 AND timestamp >= DATE_SUB(%s, INTERVAL %d HOUR)",
                current_time( 'mysql' ),
                $window
            )
        );

        if ( $spike_count >= $threshold ) {
            /**
             * Fires when a duplicate spike is detected.
             *
             * @since 1.0.6
             * @param int $spike_count The number of duplicates detected.
             * @param int $threshold   The configured threshold.
             * @param int $window      The scanning window in hours.
             */
            do_action( 'synditracker_spike_detected', $spike_count, $threshold, $window );

            // If frequency is immediate, send now.
            if ( 'immediate' === $frequency ) {
                Alerts::get_instance()->trigger_spike_alert( $spike_count );
            }
        }
    }

    /**
     * Get metrics for a specific hour window.
     *
     * @since  1.0.0
     * @param  int $hours The number of hours to look back.
     * @return array The metrics for the window.
     */
    public function get_metrics_for_window( $hours ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $metrics = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN is_duplicate = 1 THEN 1 ELSE 0 END) as duplicates
                 FROM {$this->table_logs}
                 WHERE timestamp >= DATE_SUB(%s, INTERVAL %d HOUR)",
                current_time( 'mysql' ),
                $hours
            )
        );

        return array(
            'total'      => $metrics ? (int) $metrics->total : 0,
            'duplicates' => $metrics ? (int) $metrics->duplicates : 0,
        );
    }

    /**
     * Get recent logs with pagination.
     *
     * @since  1.0.6
     * @param  int $limit  Number of logs to retrieve.
     * @param  int $offset Offset for pagination.
     * @return array Array of log objects.
     */
    public function get_logs( $limit = 50, $offset = 0 ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_logs} ORDER BY timestamp DESC LIMIT %d OFFSET %d",
                $limit,
                $offset
            )
        );
    }

    /**
     * Get total log count.
     *
     * @since  1.0.6
     * @return int Total number of logs.
     */
    public function get_total_logs_count() {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_logs}" );
    }

    /**
     * Insert an alert record.
     *
     * @since  1.0.7
     * @param  string $type    Alert type (spike, error, heartbeat).
     * @param  string $message Alert message.
     * @param  int    $count   Duplicate count (for spike alerts).
     * @param  int    $threshold Threshold value.
     * @param  int    $window  Scanning window in hours.
     * @return int|false The inserted alert ID on success, false on failure.
     */
    public function insert_alert( $type, $message, $count = 0, $threshold = 0, $window = 1 ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->insert(
            $this->table_alerts,
            array(
                'alert_type'      => $type,
                'message'         => $message,
                'duplicate_count' => $count,
                'threshold'       => $threshold,
                'window_hours'    => $window,
                'created_at'      => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%d', '%d', '%d', '%s' )
        );

        if ( false === $result ) {
            Logger::get_instance()->log(
                sprintf( 'Failed to insert alert: %s', $wpdb->last_error ),
                'ERROR'
            );
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * Get recent alerts with pagination.
     *
     * @since  1.0.7
     * @param  int    $limit  Number of alerts to retrieve.
     * @param  int    $offset Offset for pagination.
     * @param  string $type   Optional. Filter by alert type.
     * @return array Array of alert objects.
     */
    public function get_alerts( $limit = 20, $offset = 0, $type = '' ) {
        global $wpdb;

        if ( ! empty( $type ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$this->table_alerts}
                     WHERE alert_type = %s
                     ORDER BY created_at DESC LIMIT %d OFFSET %d",
                    $type,
                    $limit,
                    $offset
                )
            );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_alerts} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $limit,
                $offset
            )
        );
    }

    /**
     * Get total alerts count.
     *
     * @since  1.0.7
     * @param  string $type Optional. Filter by alert type.
     * @return int Total number of alerts.
     */
    public function get_alerts_count( $type = '' ) {
        global $wpdb;

        if ( ! empty( $type ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->table_alerts} WHERE alert_type = %s",
                    $type
                )
            );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_alerts}" );
    }

    /**
     * Clear all alerts.
     *
     * @since  1.0.7
     * @return bool True on success, false on failure.
     */
    public function clear_alerts() {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->query( "TRUNCATE TABLE {$this->table_alerts}" );

        return false !== $result;
    }
}
