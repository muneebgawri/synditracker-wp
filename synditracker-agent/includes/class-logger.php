<?php
/**
 * Logger Class
 *
 * Handles debug logging to a secure file with configurable log levels.
 *
 * @package    Synditracker
 * @subpackage Synditracker/Agent
 * @since      1.0.0
 */

namespace Synditracker\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Logger class for handling debug logging.
 *
 * @since 1.0.0
 */
class Logger {

    /**
     * Log file path.
     *
     * @since 1.0.0
     * @var string
     */
    private $log_file;

    /**
     * Singleton instance.
     *
     * @since 1.0.0
     * @var Logger|null
     */
    private static $instance = null;

    /**
     * Available log levels with priority.
     *
     * @since 1.0.5
     * @var array
     */
    private $log_levels = array(
        'DEBUG'   => 0,
        'INFO'    => 1,
        'WARNING' => 2,
        'ERROR'   => 3,
        'SYSTEM'  => 4,
    );

    /**
     * Current minimum log level.
     *
     * @since 1.0.5
     * @var string
     */
    private $min_log_level;

    /**
     * Maximum log file size in bytes (5MB).
     *
     * @since 1.0.5
     * @var int
     */
    private $max_file_size = 5242880;

    /**
     * Constructor.
     *
     * @since 1.0.0
     */
    public function __construct() {
        $upload_dir = wp_upload_dir();
        $log_dir    = $upload_dir['basedir'] . '/synditracker-logs';

        if ( ! file_exists( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
            // Secure the directory.
            file_put_contents( $log_dir . '/index.php', '<?php // Silence is golden' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            file_put_contents( $log_dir . '/.htaccess', 'deny from all' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        }

        $this->log_file = $log_dir . '/synditracker-debug.log';

        // Set minimum log level from option or default to INFO.
        $this->min_log_level = defined( 'SYNDITRACKER_LOG_LEVEL' ) ? SYNDITRACKER_LOG_LEVEL : get_option( 'synditracker_log_level', 'INFO' );
    }

    /**
     * Get singleton instance.
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
     * Check if log level should be logged.
     *
     * @since  1.0.5
     * @param  string $level Log level to check.
     * @return bool
     */
    private function should_log( $level ) {
        $current_priority = isset( $this->log_levels[ $level ] ) ? $this->log_levels[ $level ] : 1;
        $min_priority     = isset( $this->log_levels[ $this->min_log_level ] ) ? $this->log_levels[ $this->min_log_level ] : 1;

        return $current_priority >= $min_priority;
    }

    /**
     * Rotate log file if it exceeds max size.
     *
     * @since  1.0.5
     * @return void
     */
    private function maybe_rotate_log() {
        if ( ! file_exists( $this->log_file ) ) {
            return;
        }

        $file_size = filesize( $this->log_file );
        if ( $file_size > $this->max_file_size ) {
            $backup_file = $this->log_file . '.' . gmdate( 'Y-m-d-His' ) . '.bak';
            rename( $this->log_file, $backup_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename

            // Keep only the 3 most recent backups.
            $log_dir  = dirname( $this->log_file );
            $backups  = glob( $log_dir . '/*.bak' );
            if ( count( $backups ) > 3 ) {
                usort( $backups, function( $a, $b ) {
                    return filemtime( $a ) - filemtime( $b );
                });
                $to_delete = array_slice( $backups, 0, count( $backups ) - 3 );
                foreach ( $to_delete as $old_backup ) {
                    unlink( $old_backup ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                }
            }

            $this->log( 'Log file rotated due to size limit.', 'SYSTEM' );
        }
    }

    /**
     * Log a message.
     *
     * @since  1.0.0
     * @param  string $message The message to log.
     * @param  string $level   Level (DEBUG, INFO, WARNING, ERROR, SYSTEM).
     * @return void
     */
    public function log( $message, $level = 'INFO' ) {
        // Skip if level is below minimum.
        if ( ! $this->should_log( $level ) ) {
            return;
        }

        $this->maybe_rotate_log();

        $timestamp = current_time( 'mysql' );
        $entry     = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;

        // Ensure file exists or create it.
        if ( ! file_exists( $this->log_file ) ) {
            touch( $this->log_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch
        }

        file_put_contents( $this->log_file, $entry, FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
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
            return array( esc_html__( 'Log file is empty or does not exist.', 'synditracker-agent' ) );
        }

        $content = file( $this->log_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file
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
            file_put_contents( $this->log_file, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            $this->log( 'Logs cleared by user.', 'SYSTEM' );
        }
    }
}
