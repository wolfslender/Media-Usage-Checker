<?php
/**
 * Custom Integration Example with Media Usage Checker
 * 
 * This file shows how to extend and customize the functionality of the
 * Media Usage Checker plugin to adapt it to specific needs.
 * 
 * @package MediaUsageChecker
 * @since 2.8.0
 */

// Prevent direct access
defined('ABSPATH') || exit;

/**
 * Example class to integrate with Media Usage Checker
 */
class MUC_Custom_Integration {

    /**
     * Initialize the integration
     */
    public static function init() {
        // Agregar tipos MIME personalizados
        add_filter('muc_allowed_mime_types', [__CLASS__, 'add_custom_mime_types']);
        
        // Modify the scan batch size
        add_filter('muc_scan_batch_size', [__CLASS__, 'modify_batch_size']);
        
        // Custom actions
        add_action('muc_after_scan', [__CLASS__, 'after_scan_action']);
        add_action('muc_before_delete', [__CLASS__, 'before_delete_action']);
        
        // Add custom columns
        add_filter('manage_media_columns', [__CLASS__, 'add_custom_columns']);
        add_action('manage_media_custom_column', [__CLASS__, 'render_custom_columns'], 10, 2);
    }
    
    /**
     * Add custom MIME types
     */
    public static function add_custom_mime_types($mime_types) {
        $custom_types = [
            // Add custom MIME types here
            'my_custom_type' => 'application/my-custom-type',
            'psd' => 'image/vnd.adobe.photoshop',
            'indd' => 'application/x-indesign',
        ];
        
        return array_merge($mime_types, $custom_types);
    }
    
    /**
     * Modify the scan batch size
     */
    public static function modify_batch_size($batch_size) {
        // Reduce batch size in resource-limited environments
        if (wp_is_mobile() || wp_is_mobile_redirect()) {
            return 20;
        }
        
        // Increase size on high-resource servers
        if (wp_convert_hr_to_bytes(ini_get('memory_limit')) > 268435456) { // 256MB+
            return 200;
        }
        
        return $batch_size;
    }
    
    /**
     * Action after scanning
     */
    public static function after_scan_action($stats) {
        // Log the scan in a custom log
        $log_message = sprintf(
            '[%s] Escaneo completado - Total: %d, Usados: %d, No usados: %d',
            current_time('mysql'),
            $stats['total'],
            $stats['used'],
            $stats['unused']
        );
        
        error_log($log_message);
        
        // Optional: Send email notification if there are many unused files
        if ($stats['unused'] > 50) {
            $to = get_option('admin_email');
            $subject = sprintf(
                '[%s] Advertencia: %d archivos no utilizados encontrados',
                get_bloginfo('name'),
                $stats['unused']
            );
            
            $message = sprintf(
                "%d unused files were found on your site %s.\n\n" .
                "Total files: %d\n" .
                "Files in use: %d\n" .
                "Unused files: %d\n\n" .
                "Please check the Media Usage Checker section in the admin panel.",
                $stats['unused'],
                get_bloginfo('url'),
                $stats['total'],
                $stats['used'],
                $stats['unused']
            );
            
            wp_mail($to, $subject, $message);
        }
    }
    
    /**
     * Action before deleting files
     */
    public static function before_delete_action($media_ids) {
        // Log the deletion action
        $log_message = sprintf(
            '[%s] Intentando eliminar %d archivos: %s',
            current_time('mysql'),
            count($media_ids),
            implode(', ', $media_ids)
        );
        
        error_log($log_message);
        
        // Optional: Backup files before deleting them
        foreach ($media_ids as $media_id) {
            $file = get_attached_file($media_id);
            if (file_exists($file)) {
                $backup_dir = WP_CONTENT_DIR . '/muc-backups/' . date('Y/m/');
                if (!file_exists($backup_dir)) {
                    wp_mkdir_p($backup_dir);
                }
                
                $backup_file = $backup_dir . basename($file) . '.backup';
                if (!file_exists($backup_file)) {
                    copy($file, $backup_file);
                }
            }
        }
    }
    
    /**
     * Add custom columns to the media list
     */
    public static function add_custom_columns($columns) {
        $new_columns = [];
        
        foreach ($columns as $key => $title) {
            $new_columns[$key] = $title;
            
            // Add after the author column
            if ('author' === $key) {
                $new_columns['muc_status'] = __('Estado de Uso', 'media-usage-checker');
                $new_columns['muc_last_scan'] = __('Último Escaneo', 'media-usage-checker');
            }
        }
        
        return $new_columns;
    }
    
    /**
     * Display the content of custom columns
     */
    public static function render_custom_columns($column_name, $post_id) {
        switch ($column_name) {
            case 'muc_status':
                $is_used = function_exists('muc_esta_medio_en_uso') ? 
                    muc_esta_medio_en_uso($post_id) : true;
                $status = $is_used ? 
                    '<span style="color:green;">' . __('En Uso', 'media-usage-checker') . '</span>' : 
                    '<span style="color:red;">' . __('No Usado', 'media-usage-checker') . '</span>';
                echo $status;
                break;
                
            case 'muc_last_scan':
                $last_scan = get_post_meta($post_id, '_muc_last_scan', true);
                echo $last_scan ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_scan)) : __('Nunca', 'media-usage-checker');
                break;
        }
    }
}

// Initialize the integration
add_action('init', ['MUC_Custom_Integration', 'init']);

/**
 * Convenience function to check if a file is in use
 * 
 * @param int $attachment_id Attachment ID
 * @return bool True if in use, false otherwise
 */
function muc_is_media_in_use($attachment_id) {
    if (!function_exists('muc_esta_medio_en_uso')) {
        return true; // Por defecto, asumir que está en uso si el plugin no está activo
    }
    
    return muc_esta_medio_en_uso($attachment_id);
}
