<?php
namespace Synditracker\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST API Class.
 */
class REST_API
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
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    /**
     * Register REST API routes.
     */
    public function register_routes()
    {
        register_rest_route('synditracker/v1', '/log', array(
            'methods'  => 'POST',
            'callback' => array($this, 'log_handler'),
            'permission_callback' => array($this, 'check_auth'),
        ));
    }

    /**
     * Check authentication via Site Key.
     */
    public function check_auth(\WP_REST_Request $request)
    {
        $site_key = $request->get_header('X-Synditracker-Key');
        if (empty($site_key)) {
            return new \WP_Error('no_key', 'Unauthorized: Missing Site Key', array('status' => 401));
        }

        // Validate site key and get its ID.
        $key_id = Keys::get_instance()->is_valid_key($site_key);
        if (!$key_id) {
            return new \WP_Error('invalid_key', 'Unauthorized: Invalid Site Key', array('status' => 403));
        }

        // Refresh last_seen for connectivity tracking.
        Keys::get_instance()->refresh_last_seen($key_id);

        return true;
    }

    /**
     * Handle logs from agents.
     */
    public function log_handler($request) {
        $params = $request->get_params();
        $logger = Logger::get_instance();

        // Log incoming request
        $logger->log("Incoming REST Request from " . ($params['site_url'] ?? 'Unknown'), 'INFO');
        $logger->log("Params: " . json_encode($params), 'DEBUG');

        // Intercept Connection Tests (Pings)
        if (isset($params['aggregator']) && $params['aggregator'] === 'Test') {
            $logger->log("Connection Test received from " . ($params['site_url'] ?? 'Unknown'), 'INFO');
            return new \WP_REST_Response(array('message' => 'Connection successful'), 200);
        }

        // Basic validation.
        if (empty($params['post_id']) || empty($params['site_url'])) {
            $logger->log("Validation Failed: Missing required fields.", 'ERROR');
            return new \WP_REST_Response(array('message' => 'Missing required fields'), 400);
        }

        // Verify key.
        $db = DB::get_instance();
        $key_value = $request->get_header('X-Synditracker-Key');

        if (!$key_value) {
            $logger->log("Validation Failed: Missing X-Synditracker-Key header.", 'ERROR');
            return new \WP_REST_Response(array('message' => 'Missing API Key'), 401);
        }

        $is_valid = $db->validate_key($key_value, $params['site_url']);

        if (!$is_valid) {
            $logger->log("Validation Failed: Invalid Key or URL mismatch. Key: $key_value, URL: {$params['site_url']}", 'ERROR');
            return new \WP_REST_Response(array('message' => 'Invalid API Key'), 403);
        }

        // Store log.
        $result = $db->insert_log($params);

        if ($result) {
            // Updated to handle both 'alert' and 'slack'
            $alerts = Alerts::get_instance();
            $alerts->check_and_send($params);
            
            $logger->log("Log stored successfully for Post ID: {$params['post_id']}", 'INFO');
            return new \WP_REST_Response(array('message' => 'Log stored successfully'), 200);
        }

        $logger->log("Database Error: Failed to insert log.", 'ERROR');
        return new \WP_REST_Response(array('message' => 'Failed to store log'), 500);
    }

    /**
     * Get authorized keys (managed via settings).
     */
    private function get_authorized_keys()
    {
        $keys = get_option('synditracker_site_keys', array());
        // For testing, if empty, you might want to provide a default or instructions.
        return is_array($keys) ? $keys : array();
    }
}
