<?php
/**
 * Media Usage Checker Logger
 * 
 * @package Media_Usage_Checker
 * @since 2.8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MUC_Logger {
    private static $instance = null;
    private $log_file;
    private $max_log_size = 1024 * 1024; // 1MB

    private function __construct() {
        $upload_dir = wp_upload_dir();
        $this->log_file = $upload_dir['basedir'] . '/muc-logs/muc-' . date('Y-m-d') . '.log';
        
        // Create logs directory if it doesn't exist
        wp_mkdir_p(dirname($this->log_file));
    }

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function log($message, $type = 'info') {
        if (!is_string($message)) {
            $message = print_r($message, true);
        }

        $log_message = sprintf(
            '[%s] [%s] %s' . PHP_EOL,
            current_time('Y-m-d H:i:s'),
            strtoupper($type),
            $message
        );

        // Check file size
        if (file_exists($this->log_file) && filesize($this->log_file) > $this->max_log_size) {
            $this->rotate_logs();
        }

        // Write to file
        error_log($log_message, 3, $this->log_file);
    }

    private function rotate_logs() {
        $backup_name = str_replace('.log', '-' . date('His') . '.log', $this->log_file);
        if (file_exists($this->log_file)) {
            rename($this->log_file, $backup_name);
        }
    }

    public function get_logs($lines = 100) {
        if (!file_exists($this->log_file)) {
            return [];
        }

        $logs = file($this->log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$logs) {
            return [];
        }

        return array_slice($logs, -$lines);
    }

    public function clear_logs() {
        if (file_exists($this->log_file)) {
            unlink($this->log_file);
            return true;
        }
        return false;
    }
}
