<?php
namespace Synditracker\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Logger Class.
 * Handles debug logging to a secure file.
 */
class Logger
{
    /**
     * Log file path.
     */
    private $log_file;

    /**
     * Instance.
     */
    private static $instance = null;

    /**
     * Constructor.
     */
    public function __construct() {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/synditracker-logs';

        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
            // Secure the directory
            file_put_contents($log_dir . '/index.php', '<?php // Silence is golden');
            file_put_contents($log_dir . '/.htaccess', 'deny from all');
            $this->log("Initialized logging directory.", "SYSTEM");
        }

        $this->log_file = $log_dir . '/synditracker-debug.log';
    }

    /**
     * Get instance.
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Log a message.
     *
     * @param string $message The message to log.
     * @param string $level   Level (INFO, ERROR, DEBUG).
     */
    public function log($message, $level = 'INFO') {
        $timestamp = current_time('mysql');
        $entry = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
        
        // Ensure file exists or create it
        if (!file_exists($this->log_file)) {
            touch($this->log_file);
        }

        file_put_contents($this->log_file, $entry, FILE_APPEND);
    }

    /**
     * Retrieve last N lines of logs.
     *
     * @param int $lines Number of lines.
     * @return array
     */
    public function get_logs($lines = 50) {
        if (!file_exists($this->log_file)) {
            return array('Log file is empty or does not exist.');
        }

        $content = file($this->log_file);
        if (!$content) {
            return array();
        }

        return array_slice(array_reverse($content), 0, $lines);
    }
    
    /**
     * Clear logs.
     */
    public function clear_logs() {
        if (file_exists($this->log_file)) {
            file_put_contents($this->log_file, '');
            $this->log("Logs cleared by user.", "SYSTEM");
        }
    }
}
