<?php
/**
 * Media Usage Checker Validator
 * 
 * @package Media_Usage_Checker
 * @since 2.8.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class MUC_Validator {
    private static $instance = null;

    private function __construct() {}

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function validate_file_path($path) {
        if (empty($path)) {
            return false;
        }

        $path = sanitize_file_name($path);
        if (!file_exists($path)) {
            return false;
        }

        return $path;
    }

    public function validate_media_id($id) {
        if (!is_numeric($id)) {
            return false;
        }

        $id = intval($id);
        if ($id <= 0) {
            return false;
        }

        return get_post($id) ? $id : false;
    }

    public function validate_batch_size($size) {
        if (!is_numeric($size)) {
            return false;
        }

        $size = intval($size);
        if ($size <= 0 || $size > 1000) {
            return false;
        }

        return $size;
    }

    public function validate_action($action) {
        $allowed_actions = [
            'check_media',
            'delete_media',
            'force_check',
            'clear_logs'
        ];

        return in_array($action, $allowed_actions);
    }

    public function validate_nonce($nonce, $action) {
        return wp_verify_nonce($nonce, 'muc_' . $action);
    }
}

// Initialize the validator
add_action('plugins_loaded', function() {
    MUC_Validator::get_instance();
});
