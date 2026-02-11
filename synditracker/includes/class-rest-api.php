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
     * Handle the logging request.
     */
    public function log_handler(\WP_REST_Request $request)
    {
        $params = $request->get_params();

        // Intercept Connection Tests (Pings)
        if (isset($params['aggregator']) && $params['aggregator'] === 'Test') {
            return new \WP_REST_Response(array('message' => 'Connection successful'), 200);
        }

        // Basic validation.
        if (empty($params['post_id']) || empty($params['site_url'])) {
            return new \WP_REST_Response(array('message' => 'Missing required fields'), 400);
        }

        $data = array(
            'post_id'    => (int) $params['post_id'],
            'site_url'   => esc_url_raw($params['site_url']),
            'site_name'  => sanitize_text_field($params['site_name']),
            'aggregator' => sanitize_text_field($params['aggregator']),
        );

        $db = DB::get_instance();
        $log_id = $db->log_syndication($data);

        if ($log_id) {
            return new \WP_REST_Response(array('message' => 'Syndication logged successfully', 'id' => $log_id), 200);
        }

        return new \WP_REST_Response(array('message' => 'Failed to log syndication'), 500);
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
