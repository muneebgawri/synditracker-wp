<?php
/**
 * REST API Class.
 *
 * Handles all REST API endpoints for the Synditracker system.
 *
 * @package Synditracker
 * @since   1.0.0
 */

namespace Synditracker\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST API Class.
 *
 * @since 1.0.0
 */
class REST_API {

    /**
     * Singleton instance of this class.
     *
     * @since 1.0.0
     * @var REST_API|null
     */
    private static $instance = null;

    /**
     * Get the singleton instance.
     *
     * @since  1.0.0
     * @return REST_API
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
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /**
     * Register REST API routes.
     *
     * @since  1.0.0
     * @return void
     */
    public function register_routes() {
        $namespace = defined( 'SYNDITRACKER_REST_NAMESPACE' ) ? SYNDITRACKER_REST_NAMESPACE : 'synditracker/v1';

        register_rest_route(
            $namespace,
            '/log',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'log_handler' ),
                'permission_callback' => array( $this, 'check_auth' ),
            )
        );

        register_rest_route(
            $namespace,
            '/health',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'health_check' ),
                'permission_callback' => '__return_true',
            )
        );
    }

    /**
     * Check authentication via Site Key and apply rate limiting.
     *
     * @since  1.0.0
     * @since  1.0.6 Added rate limiting.
     * @param  \WP_REST_Request $request The REST request object.
     * @return bool|\WP_Error True if authorized, WP_Error otherwise.
     */
    public function check_auth( \WP_REST_Request $request ) {
        $site_key = $request->get_header( 'X-Synditracker-Key' );

        if ( empty( $site_key ) ) {
            return new \WP_Error(
                'synditracker_no_key',
                __( 'Unauthorized: Missing Site Key', 'synditracker' ),
                array( 'status' => 401 )
            );
        }

        // Validate site key and get its ID.
        $key_id = Keys::get_instance()->is_valid_key( $site_key );

        if ( ! $key_id ) {
            /**
             * Fires when an invalid key is used for authentication.
             *
             * @since 1.0.6
             * @param string $site_key The attempted key value.
             */
            do_action( 'synditracker_invalid_key_attempt', $site_key );

            return new \WP_Error(
                'synditracker_invalid_key',
                __( 'Unauthorized: Invalid Site Key', 'synditracker' ),
                array( 'status' => 403 )
            );
        }

        // Apply rate limiting.
        $rate_limit_result = $this->check_rate_limit( $key_id );
        if ( is_wp_error( $rate_limit_result ) ) {
            return $rate_limit_result;
        }

        // Refresh last_seen for connectivity tracking.
        Keys::get_instance()->refresh_last_seen( $key_id );

        return true;
    }

    /**
     * Check rate limit for a given key.
     *
     * @since  1.0.6
     * @param  int $key_id The key ID.
     * @return bool|\WP_Error True if within limits, WP_Error if exceeded.
     */
    private function check_rate_limit( $key_id ) {
        $transient_key = 'synditracker_rate_' . $key_id;
        $current_count = (int) get_transient( $transient_key );
        $max_requests  = defined( 'SYNDITRACKER_RATE_LIMIT_MAX' ) ? SYNDITRACKER_RATE_LIMIT_MAX : 60;
        $window        = defined( 'SYNDITRACKER_RATE_LIMIT_WINDOW' ) ? SYNDITRACKER_RATE_LIMIT_WINDOW : 60;

        /**
         * Filter the maximum rate limit requests per window.
         *
         * @since 1.0.6
         * @param int $max_requests Maximum requests allowed.
         * @param int $key_id       The key ID being rate limited.
         */
        $max_requests = apply_filters( 'synditracker_rate_limit_max', $max_requests, $key_id );

        if ( $current_count >= $max_requests ) {
            Logger::get_instance()->log(
                sprintf( 'Rate limit exceeded for key ID: %d', $key_id ),
                'WARNING'
            );

            return new \WP_Error(
                'synditracker_rate_limit',
                __( 'Rate limit exceeded. Please try again later.', 'synditracker' ),
                array( 'status' => 429 )
            );
        }

        // Increment counter.
        if ( false === $current_count || 0 === $current_count ) {
            set_transient( $transient_key, 1, $window );
        } else {
            set_transient( $transient_key, $current_count + 1, $window );
        }

        return true;
    }

    /**
     * Handle incoming syndication log requests from agents.
     *
     * @since  1.0.0
     * @since  1.0.6 Added aggregator whitelist validation.
     * @param  \WP_REST_Request $request The REST request object.
     * @return \WP_REST_Response The REST response.
     */
    public function log_handler( \WP_REST_Request $request ) {
        $params = $request->get_params();
        $logger = Logger::get_instance();

        // Log incoming request.
        $logger->log(
            sprintf(
                'Incoming REST Request from %s',
                isset( $params['site_url'] ) ? $params['site_url'] : 'Unknown'
            ),
            'INFO'
        );
        $logger->log( 'Params: ' . wp_json_encode( $params ), 'DEBUG' );

        // Intercept Connection Tests (Pings).
        if ( isset( $params['aggregator'] ) && 'Test' === $params['aggregator'] ) {
            $logger->log(
                sprintf(
                    'Connection Test received from %s',
                    isset( $params['site_url'] ) ? $params['site_url'] : 'Unknown'
                ),
                'INFO'
            );

            return new \WP_REST_Response(
                array( 'message' => __( 'Connection successful', 'synditracker' ) ),
                200
            );
        }

        // Sanitize and normalize params.
        $data = $this->sanitize_log_data( $params );

        // Validate required fields.
        if ( empty( $data['post_id'] ) || empty( $data['site_url'] ) ) {
            $logger->log( 'Validation Failed: Missing required fields after sanitization.', 'ERROR' );

            return new \WP_REST_Response(
                array( 'message' => __( 'Missing required fields', 'synditracker' ) ),
                400
            );
        }

        // Validate aggregator against whitelist.
        $data['aggregator'] = $this->validate_aggregator( $data['aggregator'] );

        /**
         * Filter the syndication log data before storing.
         *
         * @since 1.0.6
         * @param array $data   The sanitized log data.
         * @param array $params The original request params.
         */
        $data = apply_filters( 'synditracker_before_log_store', $data, $params );

        $db     = DB::get_instance();
        $result = $db->log_syndication( $data );

        if ( $result ) {
            /**
             * Fires after a syndication log is successfully stored.
             *
             * @since 1.0.6
             * @param array $data   The log data.
             * @param int   $log_id The inserted log ID.
             */
            do_action( 'synditracker_after_log_stored', $data, $result );

            // Check and send alerts.
            $alerts = Alerts::get_instance();
            $alerts->check_and_send( $params );

            $logger->log(
                sprintf( 'Log stored successfully for Post ID: %d', $data['post_id'] ),
                'INFO'
            );

            return new \WP_REST_Response(
                array( 'message' => __( 'Log stored successfully', 'synditracker' ) ),
                200
            );
        }

        $logger->log( 'Database Error: Failed to insert log.', 'ERROR' );

        return new \WP_REST_Response(
            array( 'message' => __( 'Failed to store log', 'synditracker' ) ),
            500
        );
    }

    /**
     * Sanitize log data from request params.
     *
     * @since  1.0.6
     * @param  array $params Raw request params.
     * @return array Sanitized data.
     */
    private function sanitize_log_data( $params ) {
        return array(
            'post_id'    => isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0,
            'site_url'   => isset( $params['site_url'] ) ? esc_url_raw( $params['site_url'] ) : '',
            'site_name'  => isset( $params['site_name'] ) ? sanitize_text_field( $params['site_name'] ) : '',
            'aggregator' => isset( $params['aggregator'] ) ? sanitize_text_field( $params['aggregator'] ) : 'Unknown',
        );
    }

    /**
     * Validate aggregator against whitelist.
     *
     * @since  1.0.6
     * @param  string $aggregator The aggregator name.
     * @return string Validated aggregator name or 'Unknown'.
     */
    private function validate_aggregator( $aggregator ) {
        $allowed = defined( 'SYNDITRACKER_ALLOWED_AGGREGATORS' )
            ? SYNDITRACKER_ALLOWED_AGGREGATORS
            : array( 'Feedzy', 'WPeMatico', 'Unknown', 'Test' );

        /**
         * Filter the allowed aggregators list.
         *
         * @since 1.0.6
         * @param array $allowed List of allowed aggregator names.
         */
        $allowed = apply_filters( 'synditracker_allowed_aggregators', $allowed );

        if ( in_array( $aggregator, $allowed, true ) ) {
            return $aggregator;
        }

        return 'Unknown';
    }

    /**
     * Health check endpoint for monitoring.
     *
     * @since  1.0.6
     * @param  \WP_REST_Request $request The REST request object.
     * @return \WP_REST_Response The health status response.
     */
    public function health_check( \WP_REST_Request $request ) {
        global $wpdb;

        $status = array(
            'status'    => 'healthy',
            'version'   => SYNDITRACKER_VERSION,
            'timestamp' => current_time( 'c' ),
            'checks'    => array(),
        );

        // Check database connectivity.
        $table_name = defined( 'SYNDITRACKER_TABLE_LOGS' )
            ? SYNDITRACKER_TABLE_LOGS
            : 'synditracker_logs';
        $table_logs = $wpdb->prefix . $table_name;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $db_check = $wpdb->get_var( "SHOW TABLES LIKE '{$table_logs}'" );

        $status['checks']['database'] = ( null !== $db_check ) ? 'ok' : 'error';

        // Check if cron is scheduled (for non-immediate alerts).
        $settings  = get_option( 'synditracker_alert_settings', array() );
        $frequency = isset( $settings['alert_frequency'] ) ? $settings['alert_frequency'] : 'immediate';

        if ( 'immediate' !== $frequency ) {
            $next_run                = wp_next_scheduled( 'synditracker_heartbeat_event' );
            $status['checks']['cron'] = $next_run ? 'scheduled' : 'not_scheduled';
        } else {
            $status['checks']['cron'] = 'not_required';
        }

        // Check Discord webhook if enabled.
        if ( ! empty( $settings['discord_enabled'] ) && ! empty( $settings['discord_webhook'] ) ) {
            $webhook_url                      = $settings['discord_webhook'];
            $status['checks']['discord_webhook'] = (
                filter_var( $webhook_url, FILTER_VALIDATE_URL ) &&
                false !== strpos( $webhook_url, 'https://discord.com/api/webhooks/' )
            ) ? 'configured' : 'invalid';
        } else {
            $status['checks']['discord_webhook'] = 'not_configured';
        }

        // Set overall status.
        if ( 'error' === $status['checks']['database'] ) {
            $status['status'] = 'unhealthy';
        }

        /**
         * Filter the health check response.
         *
         * @since 1.0.6
         * @param array $status The health status array.
         */
        $status = apply_filters( 'synditracker_health_check', $status );

        $http_status = ( 'healthy' === $status['status'] ) ? 200 : 503;

        return new \WP_REST_Response( $status, $http_status );
    }
}
