<?php
/**
 * Logger Class.
 *
 * Handles debug logging to a secure file with configurable log levels.
 *
 * @package Synditracker
 * @since   1.0.0
 */

namespace Synditracker\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Logger Class.
 *
 * @since 1.0.0
 */
class Logger {

    /**
     * Log levels in order of severity.
     *
     * @since 1.0.6
     * @var array
     */
    const LOG_LEVELS = array(
        'DEBUG'   => 0,
        'INFO'    => 1,
        'WARNING' => 2,
        'ERROR'   => 3,
        'SYSTEM'  => 4,
    );

    /**
     * Maximum log file size in bytes (5MB).
     *
     * @since 1.0.6
     * @var int
     */
    const MAX_LOG_SIZE = 5242880;

    /**
     * Log file path.
     *
     * @since 1.0.0
     * @var string
     */
    private $log_file;

    /**
     * Log directory path.
     *
     * @since 1.0.6
     * @var string
     */
    private $log_dir;

    /**
     * Minimum log level to record.
     *
     * @since 1.0.6
     * @var string
     */
    private $min_level;

    /**
     * Singleton instance.
     *
     * @since 1.0.0
     * @var Logger|null
     */
    private static $instance = null;

    /**
     * Constructor.
     *
     * @since 1.0.0
     * @since 1.0.6 Added configurable log level and log rotation.
     */
    public function __construct() {
        $upload_dir    = wp_upload_dir();
        $this->log_dir = $upload_dir['basedir'] . '/synditracker-logs';

        // Get minimum log level from options or constant.
        if ( defined( 'SYNDITRACKER_LOG_LEVEL' ) ) {
            $this->min_level = SYNDITRACKER_LOG_LEVEL;
        } else {
            $this->min_level = get_option( 'synditracker_log_level', 'INFO' );
        }

        $this->ensure_log_directory();
        $this->log_file = $this->log_dir . '/synditracker-debug.log';
    }

    /**
     * Ensure the log directory exists and is secured.
     *
     * @since  1.0.6
     * @return void
     */
    private function ensure_log_directory() {
        if ( ! file_exists( $this->log_dir ) ) {
            wp_mkdir_p( $this->log_dir );

            // Secure the directory.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            file_put_contents( $this->log_dir . '/index.php', '<?php // Silence is golden' );
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            file_put_contents( $this->log_dir . '/.htaccess', 'deny from all' );
        }
    }

    /**
     * Get the singleton instance.
     *
     * @since  1.0.0
     * @return Logger
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Log a message.
     *
     * @since  1.0.0
     * @since  1.0.6 Added log level filtering and rotation.
     * @param  string $message The message to log.
     * @param  string $level   Log level (DEBUG, INFO, WARNING, ERROR, SYSTEM).
     * @return bool True if logged, false if skipped due to level.
     */
    public function log( $message, $level = 'INFO' ) {
        // Check if this level should be logged.
        if ( ! $this->should_log( $level ) ) {
            return false;
        }

        // Check for log rotation.
        $this->maybe_rotate_log();

        $timestamp = current_time( 'mysql' );
        $entry     = sprintf( "[%s] [%s] %s\n", $timestamp, $level, $message );

        // Ensure file exists.
        if ( ! file_exists( $this->log_file ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch
            touch( $this->log_file );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents( $this->log_file, $entry, FILE_APPEND | LOCK_EX );

        return true;
    }

    /**
     * Check if a log level should be logged based on minimum level.
     *
     * @since  1.0.6
     * @param  string $level The log level to check.
     * @return bool True if should be logged, false otherwise.
     */
    private function should_log( $level ) {
        $level_priority     = isset( self::LOG_LEVELS[ $level ] ) ? self::LOG_LEVELS[ $level ] : 1;
        $min_level_priority = isset( self::LOG_LEVELS[ $this->min_level ] ) ? self::LOG_LEVELS[ $this->min_level ] : 1;

        return $level_priority >= $min_level_priority;
    }

    /**
     * Rotate log file if it exceeds maximum size.
     *
     * @since  1.0.6
     * @return void
     */
    private function maybe_rotate_log() {
        if ( ! file_exists( $this->log_file ) ) {
            return;
        }

        $file_size = filesize( $this->log_file );
        if ( $file_size < self::MAX_LOG_SIZE ) {
            return;
        }

        // Rotate: rename current log to .old and start fresh.
        $archive_file = $this->log_dir . '/synditracker-debug.old.log';

        // Remove old archive if exists.
        if ( file_exists( $archive_file ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            unlink( $archive_file );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
        rename( $this->log_file, $archive_file );
    }

    /**
     * Retrieve last N lines of logs.
     *
     * @since  1.0.0
     * @param  int $lines Number of lines to retrieve.
     * @return array Array of log lines.
     */
    public function get_logs( $lines = 50 ) {
        if ( ! file_exists( $this->log_file ) ) {
            return array( esc_html__( 'Log file is empty or does not exist.', 'synditracker' ) );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file
        $content = file( $this->log_file );
        if ( ! $content ) {
            return array();
        }

        return array_slice( array_reverse( $content ), 0, $lines );
    }

    /**
     * Clear all logs.
     *
     * @since  1.0.0
     * @return void
     */
    public function clear_logs() {
        if ( file_exists( $this->log_file ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            file_put_contents( $this->log_file, '' );
            $this->log( 'Logs cleared by user.', 'SYSTEM' );
        }
    }

    /**
     * Get the log file path.
     *
     * @since  1.0.6
     * @return string The log file path.
     */
    public function get_log_file_path() {
        return $this->log_file;
    }

    /**
     * Get the current minimum log level.
     *
     * @since  1.0.6
     * @return string The minimum log level.
     */
    public function get_min_level() {
        return $this->min_level;
    }

    /**
     * Set the minimum log level.
     *
     * @since  1.0.6
     * @param  string $level The log level to set.
     * @return void
     */
    public function set_min_level( $level ) {
        if ( isset( self::LOG_LEVELS[ $level ] ) ) {
            $this->min_level = $level;
            update_option( 'synditracker_log_level', $level );
        }
    }
}
