<?php
/**
 * Plugin Name: Media Usage Checker
 * Plugin URI: https://wordpress.org/plugins/media-usage-checker/
 * Description: Identifies which media library files are in use in WordPress content and allows you to delete unused ones.
 * Version: 2.8.0
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Author: Alexis Olivero
 * Author URI: https://github.com/oliverodev
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: media-usage-checker
 * Domain Path: /languages
 *
 * @package Media_Usage_Checker
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('MUC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MUC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MUC_VERSION', '2.8.0');

// Security constants
define('MUC_NONCE_LIFETIME', DAY_IN_SECONDS);
define('MUC_MAX_FILE_SIZE', 104857600); // 100MB
define('MUC_MAX_EXECUTION_TIME', 300); // 5 minutos

// Performance constants
define('MUC_CACHE_GROUP', 'muc_cache');
define('MUC_CACHE_EXPIRE', HOUR_IN_SECONDS);

// Internationalization constants
define('MUC_TEXT_DOMAIN', 'media-usage-checker');

// Load required classes
if (file_exists(MUC_PLUGIN_DIR . 'includes/class-muc-logger.php')) {
    require_once MUC_PLUGIN_DIR . 'includes/class-muc-logger.php';
}
if (file_exists(MUC_PLUGIN_DIR . 'includes/class-muc-validator.php')) {
    require_once MUC_PLUGIN_DIR . 'includes/class-muc-validator.php';
}

// Configuration limits and constants
final class MUC_Config {
    const BATCH_SIZE = 100;
    const MINI_BATCH = 20;
    const TIME_LIMIT = 1800;
    const SLEEP_TIME = 100000;
    const MEMORY_LIMIT = '1024M';
    const LOG_FILE_SIZE_LIMIT = 1024 * 1024; // 1MB
    
    public static function init() {
        @ini_set('memory_limit', self::MEMORY_LIMIT);
        @set_time_limit(0);
        
        // Activar el caché de objetos si está disponible
        if (!wp_using_ext_object_cache()) {
            wp_cache_add_non_persistent_groups(['muc_media_check']);
        }
        
        if (!defined('MUC_SALT')) {
            define('MUC_SALT', defined('NONCE_SALT') ? NONCE_SALT : 'muc_default_salt_' . ABSPATH);
        }
        
        // Inicializar logger
        MUC_Logger::get_instance()->log('Plugin inicializado', 'info');
    }
}

MUC_Config::init();

// Validate AJAX requests
add_action('wp_ajax_muc_check_media', function() {
    if (!check_ajax_referer('muc_check_media', 'nonce', false)) {
        wp_send_json_error('Invalid nonce');
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
        return;
    }

    try {
        $validator = MUC_Validator::get_instance();
        $batch_size = $validator->validate_batch_size($_POST['batch_size']);
        $offset = $validator->validate_batch_size($_POST['offset']);

        if (!$batch_size || !$offset) {
            wp_send_json_error('Invalid parameters');
        }

        $result = muc_check_media_usage($batch_size, $offset);
        wp_send_json_success($result);
    } catch (Exception $e) {
        MUC_Logger::get_instance()->log('Error en AJAX: ' . $e->getMessage(), 'error');
        wp_send_json_error('Error interno');
    }
});

// Validate deletion requests
add_action('admin_post_muc_delete_media', function() {
    check_admin_referer('muc_delete_media', 'muc_delete_media_nonce');

    if (!current_user_can('manage_options')) {
        wp_redirect(admin_url('admin.php?page=media-usage-checker&error=permissions'));
        exit;
    }

    try {
        $validator = MUC_Validator::get_instance();
        $media_ids = isset($_POST['media_ids']) ? $_POST['media_ids'] : [];
        $valid_ids = array_filter($media_ids, function($id) use ($validator) {
            return $validator->validate_media_id($id);
        });

        if (empty($valid_ids)) {
            wp_redirect(admin_url('admin.php?page=media-usage-checker&error=invalid_ids'));
            exit;
        }

        muc_cleanup_unused_media($valid_ids);
        wp_redirect(admin_url('admin.php?page=media-usage-checker&success=deleted'));
        exit;
    } catch (Exception $e) {
        MUC_Logger::get_instance()->log('Error al eliminar medios: ' . $e->getMessage(), 'error');
        wp_redirect(admin_url('admin.php?page=media-usage-checker&error=error'));
        exit;
    }
});

// Añadir el menú al panel de administración
add_action('admin_menu', 'muc_add_admin_menu');

function muc_add_admin_menu() {
    add_menu_page(
        'Media Usage Checker',
        'Media Usage Checker',
        'manage_options',
        'media-usage-checker',
        'muc_admin_page',
        'dashicons-media-spreadsheet',
        25
    );
}

// Function to handle pagination
function muc_get_paged_results($items, $page, $per_page) {
    $total_items = count($items);
    $offset = ($page - 1) * $per_page;
    $paged_items = array_slice($items, $offset, $per_page);
    $total_pages = ceil($total_items / $per_page);

    return [
        'items' => $paged_items,
        'total_pages' => $total_pages
    ];
}

// Function to display pagination
function muc_display_pagination($current_page, $total_pages, $page_param) {
    if ($total_pages <= 1) {
        return;
    }
    
    echo '<div class="pagination" style="margin-top: 20px;">';
    for ($i = 1; $i <= $total_pages; $i++) {
        $url = add_query_arg($page_param, $i);
        $class = ($i == $current_page) ? ' class="current-page"' : '';
        echo "<a href='" . esc_url($url) . "'$class>$i</a> ";
    }
    echo '</div>';
}

// Improved function to sanitize inputs
function muc_sanitize_data($data, $type = 'text') {
    switch($type) {
        case 'int':
            return filter_var($data, FILTER_SANITIZE_NUMBER_INT);
        case 'url':
            return esc_url_raw($data);
        case 'filename':
            return sanitize_file_name($data);
        case 'key':
            return sanitize_key($data);
        default:
            return sanitize_text_field($data);
    }
}

// Add nonces with expiration time
function muc_create_nonce($action) {
    return wp_create_nonce($action . '_' . MUC_SALT);
}

// More strictly verify user capabilities
function muc_verify_user_capabilities() {
    if (!current_user_can('manage_options') || !current_user_can('upload_files')) {
        wp_die(esc_html__('No tienes permisos suficientes para acceder a esta página.', 'media-usage-checker'));
    }
}

// Optimized function to check media library file usage
function muc_check_media_usage($batch_size = MUC_Config::BATCH_SIZE, $offset = 0) {
    muc_verify_user_capabilities();
    
    try {
        $media_processor = new MUC_MediaProcessor($batch_size, $offset);
        return $media_processor->process();
    } catch (Exception $e) {
        muc_log_error('Error general en verificación de medios', $e);
        return ['used' => [], 'unused' => []];
    }
}

class MUC_MediaProcessor {
    private $batch_size;
    private $offset;
    private $start_time;
    
    public function __construct($batch_size, $offset) {
        $this->batch_size = abs(intval($batch_size));
        $this->offset = abs(intval($offset));
        $this->start_time = time();
    }
    
    public function process() {
        $media_items = $this->get_media_items();
        return $this->process_media_items($media_items);
    }
    
    private function get_media_items() {
        return get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => $this->batch_size,
            'offset' => $this->offset,
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
            'fields' => 'ids'
        ]);
    }
    
    private function process_media_items($media_items) {
        $unused_media = [];
        $used_media = [];
        
        foreach (array_chunk($media_items, MUC_Config::MINI_BATCH) as $chunk) {
            if ($this->should_stop_processing()) break;
            
            foreach ($chunk as $media_id) {
                $result = $this->process_single_media($media_id);
                if ($result) {
                    $result['is_used'] ? $used_media[] = $result['media'] : $unused_media[] = $result['media'];
                }
            }
            
            usleep(MUC_Config::SLEEP_TIME);
        }
        
        return ['used' => $used_media, 'unused' => $unused_media];
    }
    
    private function process_single_media($media_id) {
        try {
            $media = get_post($media_id);
            if (!$media) {
                return null;
            }
            
            return [
                'is_used' => muc_esta_medio_en_uso($media_id),
                'media' => $media
            ];
        } catch (Exception $e) {
            error_log('Media Usage Checker - Error procesando medio ' . $media_id . ': ' . $e->getMessage());
            return null;
        }
    }
    
    private function should_stop_processing() {
        return time() - $this->start_time >= MUC_Config::TIME_LIMIT;
    }
}

// Schedule background check
function muc_schedule_background_check() {
    if (!wp_next_scheduled('muc_background_check')) {
        wp_schedule_event(time(), 'fifteen_minutes', 'muc_background_check');
    }
}
add_action('wp', 'muc_schedule_background_check');

// Add custom intervals for WP-Cron
add_filter('cron_schedules', 'muc_add_cron_intervals');
function muc_add_cron_intervals($schedules) {
    $schedules['fifteen_minutes'] = [
        'interval' => 900, // 15 minutos
        'display' => printf(
            /* translators: %s: Time interval */
            esc_html__('Every %s', 'media-usage-checker'),
            esc_html('15 minutes')
        )
    ];
    $schedules['thirty_minutes'] = [
        'interval' => 1800, // 30 minutos
        'display' => printf(
            /* translators: %s: Time interval */
            esc_html__('Every %s', 'media-usage-checker'),
            esc_html('30 minutes')
        )
    ];
    return $schedules;
}

// Function for background check
function muc_background_check() {
    try {
        $batch_size = MUC_Config::BATCH_SIZE;
        $offset = get_option('muc_current_offset', 0);
        $total_processed = get_option('muc_total_processed', 0);
        $start_time = time();

        $results = muc_check_media_usage($batch_size, $offset);
        $processed = count($results['used']) + count($results['unused']);
        
        if ($processed > 0) {
            update_option('muc_results_' . $offset, $results);
            update_option('muc_current_offset', $offset + $batch_size);
            update_option('muc_total_processed', $total_processed + $processed);
        }

        if ($processed < $batch_size || (time() - $start_time) >= MUC_Config::TIME_LIMIT) {
            delete_option('muc_current_offset');
            update_option('muc_last_check', time());
        } else {
            // Programar la siguiente verificación inmediatamente
            if (!wp_next_scheduled('muc_background_check')) {
                wp_schedule_single_event(time() + 1, 'muc_background_check');
            }
        }

    } catch (Exception $e) {
        error_log('Media Usage Checker - Error en verificación en segundo plano: ' . esc_html($e->getMessage()));
    }
}
add_action('muc_background_check', 'muc_background_check');

// Add necessary styles and scripts
add_action('admin_enqueue_scripts', 'muc_enqueue_admin_assets');
function muc_enqueue_admin_assets($hook) {
    if ('toplevel_page_media-usage-checker' !== $hook) {
        return;
    }
    
    wp_enqueue_style('muc-admin-style', plugins_url('assets/css/admin.css', __FILE__));
    wp_enqueue_script('muc-admin-script', plugins_url('assets/js/admin.js', __FILE__), array('jquery'), '1.0', true);
    
    wp_localize_script('muc-admin-script', 'mucAdmin', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('muc_ajax_nonce')
    ));
}

// Function that displays the main plugin page content
function muc_admin_page() {
    muc_verify_user_capabilities();
    
    $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'dashboard';
    ?>
    <div class="wrap muc-wrap">
        <div class="muc-header">
            <h1>Media Usage Checker</h1>
            <p class="muc-version">Version 2.8.0</p>
        </div>

        <nav class="muc-nav-tab-wrapper">
            <a href="?page=media-usage-checker&tab=dashboard" class="nav-tab <?php echo $current_tab === 'dashboard' ? 'nav-tab-active' : ''; ?>">
                <span class="dashicons dashicons-dashboard"></span> Dashboard
            </a>
            <a href="?page=media-usage-checker&tab=media-files" class="nav-tab <?php echo $current_tab === 'media-files' ? 'nav-tab-active' : ''; ?>">
                <span class="dashicons dashicons-admin-media"></span> Media Files
            </a>
            <a href="?page=media-usage-checker&tab=settings" class="nav-tab <?php echo $current_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                <span class="dashicons dashicons-admin-settings"></span> Settings
            </a>
        </nav>

        <div class="muc-content">
            <?php
            switch ($current_tab) {
                case 'dashboard':
                    muc_display_dashboard();
                    break;
                case 'media-files':
                    muc_display_media_files();
                    break;
                case 'settings':
                    muc_display_settings();
                    break;
            }
            ?>
        </div>
    </div>
    <?php
}

function muc_display_dashboard() {
    $total_media = wp_count_posts('attachment')->inherit;
    $used_count = get_option('muc_used_count', 0);
    $unused_count = get_option('muc_unused_count', 0);
    $last_check = get_option('muc_last_check');
    ?>
    <div class="muc-dashboard">
        <div class="muc-stats-grid">
            <div class="muc-stat-card">
                <span class="dashicons dashicons-admin-media"></span>
                <h3>Total Media Files</h3>
                <p class="muc-stat-number"><?php echo number_format($total_media); ?></p>
            </div>
            <div class="muc-stat-card">
                <span class="dashicons dashicons-yes-alt"></span>
                <h3>Files in Use</h3>
                <p class="muc-stat-number"><?php echo number_format($used_count); ?></p>
            </div>
            <div class="muc-stat-card">
                <span class="dashicons dashicons-warning"></span>
                <h3>Unused Files</h3>
                <p class="muc-stat-number"><?php echo number_format($unused_count); ?></p>
            </div>
        </div>

        <div class="muc-actions">
            <form method="post" class="muc-scan-form">
                <?php wp_nonce_field('muc_force_check', 'muc_force_check_nonce'); ?>
                <button type="submit" name="muc_force_check" class="button button-primary button-hero">
                    <span class="dashicons dashicons-search"></span> Scan Media Files
                </button>
            </form>
        </div>

        <?php if ($last_check): ?>
        <div class="muc-last-scan">
            <p>Last scan completed: <?php echo date('F j, Y g:i a', $last_check); ?></p>
        </div>
        <?php endif; ?>
    </div>
    <?php
}

function muc_display_media_files() {
    // Obtener la página actual
    $current_page = isset($_GET['media_page']) ? max(1, intval($_GET['media_page'])) : 1;
    $per_page = 20;

    // Obtener todos los archivos multimedia
    $media_query = new WP_Query([
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'posts_per_page' => $per_page,
        'paged' => $current_page,
        'orderby' => 'date',
        'order' => 'DESC'
    ]);

    ?>
    <div class="muc-media-files">
        <h2>Archivos Multimedia</h2>
        
        <?php if ($media_query->have_posts()) : ?>
            <form method="post" class="muc-media-form">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th class="check-column">
                                <input type="checkbox" id="select-all">
                            </th>
                            <th>Vista Previa</th>
                            <th>Título</th>
                            <th>Tipo</th>
                            <th>Tamaño</th>
                            <th>Fecha</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($media_query->have_posts()) : $media_query->the_post(); 
                            $media_id = get_the_ID();
                            $file_path = get_attached_file($media_id);
                            $file_size = file_exists($file_path) ? size_format(filesize($file_path), 2) : 'N/A';
                            $mime_type = get_post_mime_type($media_id);
                            $is_image = wp_attachment_is_image($media_id);
                            $is_used = muc_esta_medio_en_uso($media_id);
                            ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="selected_media[]" value="<?php echo esc_attr($media_id); ?>">
                                </td>
                                <td>
                                    <?php if ($is_image) : ?>
                                        <?php echo wp_get_attachment_image($media_id, [50, 50]); ?>
                                    <?php else : ?>
                                        <span class="dashicons dashicons-media-default"></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php the_title(); ?></strong>
                                    <div class="row-actions">
                                        <span class="view">
                                            <a href="<?php echo esc_url(wp_get_attachment_url($media_id)); ?>" target="_blank">Ver</a>
                                        </span>
                                    </div>
                                </td>
                                <td><?php echo esc_html($mime_type); ?></td>
                                <td><?php echo esc_html($file_size); ?></td>
                                <td><?php echo get_the_date(); ?></td>
                                <td>
                                    <span class="muc-status <?php echo $is_used ? 'used' : 'unused'; ?>">
                                        <?php echo $is_used ? esc_html__('File in use', 'media-usage-checker') : esc_html__('File not in use', 'media-usage-checker'); ?>
                                    </span>
                                </td>
                                <td>
                                    <button type="submit" name="delete_media" value="<?php echo esc_attr($media_id); ?>" 
                                            class="button button-secondary" 
                                            <?php echo $is_used ? 'disabled' : ''; ?>>
                                        <?php esc_html_e('Delete', 'media-usage-checker'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>

                <div class="tablenav bottom">
                    <div class="alignleft actions bulkactions">
                        <button type="submit" name="bulk_delete" class="button button-primary">
                            Eliminar Seleccionados
                        </button>
                    </div>
                    <?php
                    echo paginate_links([
                        'base' => add_query_arg('media_page', '%#%'),
                        'format' => '',
                        'prev_text' => esc_html__('&laquo;', 'media-usage-checker'),
                        'next_text' => esc_html__('&raquo;', 'media-usage-checker'),
                        'total' => $media_query->max_num_pages,
                        'current' => $current_page
                    ]);
                    ?>
                </div>
            </form>
        <?php else : ?>
            <p>No se encontraron archivos multimedia.</p>
        <?php endif; 
        wp_reset_postdata();
        ?>
    </div>
    <?php
}

function muc_display_settings() {
    // Guardar cambios en la configuración
    if (isset($_POST['muc_save_settings']) && check_admin_referer('muc_settings_nonce')) {
        $batch_size = absint($_POST['muc_batch_size']);
        $scan_frequency = sanitize_text_field($_POST['muc_scan_frequency']);
        $file_types = isset($_POST['muc_file_types']) ? array_map('sanitize_text_field', $_POST['muc_file_types']) : [];
        
        update_option('muc_batch_size', $batch_size);
        update_option('muc_scan_frequency', $scan_frequency);
        update_option('muc_file_types', $file_types);
        
        echo '<div class="notice notice-success"><p>Configuración guardada exitosamente.</p></div>';
    }

    // Obtener valores actuales
    $batch_size = get_option('muc_batch_size', 100);
    $scan_frequency = get_option('muc_scan_frequency', 'daily');
    $file_types = get_option('muc_file_types', ['image', 'document', 'video', 'audio']);
    ?>
    <div class="muc-settings">
        <h2><?php esc_html_e('Media Usage Checker Settings', 'media-usage-checker'); ?></h2>
        
        <form method="post" class="muc-settings-form">
            <?php wp_nonce_field('muc_settings_nonce'); ?>
            
            <div class="muc-setting-section">
                <h3><?php esc_html_e('Performance', 'media-usage-checker'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="muc_batch_size"><?php esc_html_e('Batch Size', 'media-usage-checker'); ?></label>
                        </th>
                        <td>
                            <input type="number" 
                                   id="muc_batch_size" 
                                   name="muc_batch_size" 
                                   value="<?php echo esc_attr($batch_size); ?>" 
                                   min="10" 
                                   max="500">
                            <p class="description"><?php esc_html_e('Number of files to process per batch. A larger number may increase speed but also resource usage.', 'media-usage-checker'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="muc-setting-section">
                <h3><?php esc_html_e('Scan Schedule', 'media-usage-checker'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Scan Frequency', 'media-usage-checker'); ?></th>
                        <td>
                            <select name="muc_scan_frequency">
                                <option value="hourly" <?php selected($scan_frequency, 'hourly'); ?>><?php esc_html_e('Hourly', 'media-usage-checker'); ?></option>
                                <option value="twicedaily" <?php selected($scan_frequency, 'twicedaily'); ?>><?php esc_html_e('Twice Daily', 'media-usage-checker'); ?></option>
                                <option value="daily" <?php selected($scan_frequency, 'daily'); ?>><?php esc_html_e('Daily', 'media-usage-checker'); ?></option>
                                <option value="weekly" <?php selected($scan_frequency, 'weekly'); ?>><?php esc_html_e('Weekly', 'media-usage-checker'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('How often the automatic file scan will be performed.', 'media-usage-checker'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="muc-setting-section">
                <h3><?php esc_html_e('File Types', 'media-usage-checker'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Included File Types', 'media-usage-checker'); ?></th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" 
                                           name="muc_file_types[]" 
                                           value="image" 
                                           <?php checked(in_array('image', $file_types)); ?>>
                                    <?php esc_html_e('Images (jpg, png, gif, etc.)', 'media-usage-checker'); ?>
                                </label>
                                <br>
                                <label>
                                    <input type="checkbox" 
                                           name="muc_file_types[]" 
                                           value="document" 
                                           <?php checked(in_array('document', $file_types)); ?>>
                                    <?php esc_html_e('Documents (pdf, doc, docx, etc.)', 'media-usage-checker'); ?>
                                </label>
                                <br>
                                <label>
                                    <input type="checkbox" 
                                           name="muc_file_types[]" 
                                           value="video" 
                                           <?php checked(in_array('video', $file_types)); ?>>
                                    <?php esc_html_e('Videos (mp4, mov, avi, etc.)', 'media-usage-checker'); ?>
                                </label>
                                <br>
                                <label>
                                    <input type="checkbox" 
                                           name="muc_file_types[]" 
                                           value="audio" 
                                           <?php checked(in_array('audio', $file_types)); ?>>
                                    <?php esc_html_e('Audio (mp3, wav, ogg, etc.)', 'media-usage-checker'); ?>
                                </label>
                                <p class="description"><?php esc_html_e('Select which types of files to include in the scan.', 'media-usage-checker'); ?></p>
                            </fieldset>
                        </td>
                    </tr>
                </table>
            </div>

            <p class="submit">
                <button type="submit" name="muc_save_settings" class="button button-primary">
                    <?php esc_html_e('Save Settings', 'media-usage-checker'); ?>
                </button>
            </p>
        </form>
    </div>
    <?php
}

function muc_esta_medio_en_uso($media_id) {
    global $wpdb;
    
    // Obtener la URL del archivo multimedia y sus variantes
    $media_url = wp_get_attachment_url($media_id);
    if (!$media_url) return false;
    
    $media_path = get_attached_file($media_id);
    $filename = basename($media_path);
    $upload_dir = wp_upload_dir();
    $relative_path = str_replace($upload_dir['basedir'], '', $media_path);
    $base_url = str_replace($upload_dir['baseurl'], '', $media_url);
    
    // 1. Verificar en contenido de posts y páginas
    $posts_with_media = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*)
        FROM {$wpdb->posts}
        WHERE (
            post_content LIKE %s 
            OR post_content LIKE %s 
            OR post_content LIKE %s
            OR post_content LIKE %s
        )
        AND post_type NOT IN ('attachment', 'revision', 'auto-draft')
        AND post_status IN ('publish', 'draft', 'private', 'pending')
    ", 
        '%' . $wpdb->esc_like($media_url) . '%',
        '%' . $wpdb->esc_like($base_url) . '%',
        '%' . $wpdb->esc_like($relative_path) . '%',
        '%' . $wpdb->esc_like($filename) . '%'
    ));
    
    if ($posts_with_media > 0) return true;
    
    // 2. Verificar en metadatos de posts
    $meta_keys_to_check = array(
        '_thumbnail_id',          // Imagen destacada
        '_product_image_gallery', // WooCommerce
        '_elementor_data',        // Elementor
        '_wpb_shortcodes_custom_css', // WPBakery
        '_divi_',                // Divi Builder
        '_fusion_builder_',      // Fusion Builder
        '_vc_post_settings',     // Visual Composer
        'panels_data',           // Page Builder by SiteOrigin
        '_cornerstone_data',     // Cornerstone
        '_fl_builder_data',      // Beaver Builder
        'block_data'             // Gutenberg blocks
    );
    
    $meta_keys_sql = implode("' OR meta_key LIKE '", array_map(array($wpdb, 'esc_like'), $meta_keys_to_check));
    
    $meta_with_media = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*)
        FROM {$wpdb->postmeta}
        WHERE (meta_key LIKE '" . $meta_keys_sql . "')
        AND (
            meta_value LIKE %s 
            OR meta_value LIKE %s
            OR meta_value LIKE %s
            OR meta_value LIKE %s
            OR meta_value = %s
        )
    ",
        '%' . $wpdb->esc_like($media_url) . '%',
        '%' . $wpdb->esc_like($base_url) . '%',
        '%' . $wpdb->esc_like($relative_path) . '%',
        '%' . $wpdb->esc_like($filename) . '%',
        $media_id
    ));
    
    if ($meta_with_media > 0) return true;
    
    // 3. Verificar en opciones y widgets
    $options_with_media = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*)
        FROM {$wpdb->options}
        WHERE (
            option_name LIKE '%widget%'
            OR option_name LIKE '%theme_mods%'
            OR option_name LIKE '%sidebars_widgets%'
            OR option_name LIKE '%custom_css%'
            OR option_name LIKE '%background%'
        )
        AND (
            option_value LIKE %s
            OR option_value LIKE %s
            OR option_value LIKE %s
            OR option_value LIKE %s
        )
    ",
        '%' . $wpdb->esc_like($media_url) . '%',
        '%' . $wpdb->esc_like($base_url) . '%',
        '%' . $wpdb->esc_like($relative_path) . '%',
        '%' . $wpdb->esc_like($filename) . '%'
    ));
    
    if ($options_with_media > 0) return true;
    
    // 4. Verificar en términos y taxonomías
    $term_meta_with_media = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*)
        FROM {$wpdb->termmeta}
        WHERE meta_value LIKE %s
        OR meta_value LIKE %s
        OR meta_value LIKE %s
        OR meta_value LIKE %s
    ",
        '%' . $wpdb->esc_like($media_url) . '%',
        '%' . $wpdb->esc_like($base_url) . '%',
        '%' . $wpdb->esc_like($relative_path) . '%',
        '%' . $wpdb->esc_like($filename) . '%'
    ));
    
    if ($term_meta_with_media > 0) return true;
    
    // 5. Verificar si es logo del sitio o imagen de fondo
    $custom_logo_id = get_theme_mod('custom_logo');
    $header_image = get_theme_mod('header_image');
    $background_image = get_theme_mod('background_image');
    
    if ($custom_logo_id == $media_id) return true;
    if ($header_image && strpos($header_image, $filename) !== false) return true;
    if ($background_image && strpos($background_image, $filename) !== false) return true;
    
    // Si no se encontró ningún uso, retornar false
    return false;
}

function muc_cleanup_unused_media() {
    // Obtener archivos sin usar
    $args = array(
        'post_type' => 'attachment',
        'posts_per_page' => -1,
        'post_status' => 'inherit'
    );
    
    $attachments = get_posts($args);
    $unused_count = 0;
    
    foreach ($attachments as $attachment) {
        if (!muc_esta_medio_en_uso($attachment->ID)) {
            $unused_count++;
            // Actualizar el contador de archivos sin usar
            update_option('muc_unused_count', $unused_count);
        }
    }
    
    // Actualizar el contador de archivos usados
    $total_media = wp_count_posts('attachment')->inherit;
    update_option('muc_used_count', $total_media - $unused_count);
    
    // Actualizar la última verificación
    update_option('muc_last_check', time());
}

function muc_update_dashboard_stats() {
    global $wpdb;
    
    // Obtener total de archivos multimedia
    $total_media = $wpdb->get_var("
        SELECT COUNT(*)
        FROM {$wpdb->posts}
        WHERE post_type = 'attachment'
        AND post_status = 'inherit'
    ");
    
    $used_count = 0;
    $unused_count = 0;
    
    // Procesar en lotes para evitar timeout
    $batch_size = 100;
    $offset = 0;
    
    while (true) {
        $attachments = $wpdb->get_col($wpdb->prepare("
            SELECT ID
            FROM {$wpdb->posts}
            WHERE post_type = 'attachment'
            AND post_status = 'inherit'
            LIMIT %d OFFSET %d
        ", $batch_size, $offset));
        
        if (empty($attachments)) {
            break;
        }
        
        foreach ($attachments as $attachment_id) {
            if (muc_esta_medio_en_uso($attachment_id)) {
                $used_count++;
            } else {
                $unused_count++;
            }
        }
        
        $offset += $batch_size;
        
        // Actualizar las estadísticas en tiempo real
        update_option('muc_used_count', $used_count);
        update_option('muc_unused_count', $unused_count);
        update_option('muc_total_media', $total_media);
        
        // Incrementar el offset para la siguiente iteración
        $offset += $batch_size;
        
        // Pequeña pausa para evitar sobrecargar el servidor
        if (function_exists('usleep')) {
            usleep(100000); // 100ms
        }
    }
    
    // Actualizar la última verificación
    update_option('muc_last_check', time());
    
    return [
        'total' => $total_media,
        'used' => $used_count,
        'unused' => $unused_count
    ];
}

// Agregar la actualización de estadísticas al escaneo forzado
add_action('admin_init', function() {
    if (isset($_POST['muc_force_check']) && check_admin_referer('muc_force_check', 'muc_force_check_nonce')) {
        muc_update_dashboard_stats();
        wp_redirect(add_query_arg('updated', '1', wp_get_referer()));
        exit;
    }
});

/**
 * Checks if the plugin is active in a multisite network
 * 
 * @return bool True if active network-wide, false otherwise
 */
function muc_is_network_active() {
    if (!is_multisite()) {
        return false;
    }
    
    $plugins = get_site_option('active_sitewide_plugins');
    return isset($plugins[plugin_basename(__FILE__)]);
}

/**
 * Logs errors to WordPress log
 * 
 * @param string $message Error message
 * @param mixed $data Additional log data
 * @return void
 */
function muc_log_error($message, $data = []) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        if (!empty($data)) {
            if ($data instanceof Exception) {
                $message .= ' ' . $data->getMessage();
                $data = [
                    'file' => $data->getFile(),
                    'line' => $data->getLine(),
                    'trace' => $data->getTraceAsString()
                ];
            }
        }
        MUC_Logger::get_instance()->log($message, 'error', $data);
    }
}

/**
 * Clears site cache
 * 
 * @return void
 */
function muc_clear_cache() {
    // Clear WordPress cache
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }
    
    // Clear object cache if available
    if (function_exists('wp_cache_clear_cache')) {
        wp_cache_clear_cache();
    }
    
    // Clear transients
    global $wpdb;
    $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '\_transient\_%' OR option_name LIKE '\_site\_transient\_%'");
    
    // Allow other plugins to clear their cache
    do_action('muc_after_clear_cache');
}

/**
 * Initializes the REST API
 * 
 * @return void
 */
function muc_init_rest_api() {
    add_action('rest_api_init', function() {
        register_rest_route('media-usage-checker/v1', '/status', [
            'methods' => 'GET',
            'callback' => 'muc_rest_get_status',
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ]);
    });
}
add_action('init', 'muc_init_rest_api');

/**
 * Handles REST request to get plugin status
 * 
 * @param WP_REST_Request $request Request object
 * @return WP_REST_Response API response
 */
function muc_rest_get_status($request) {
    $response = [
        'status' => 'success',
        'data' => [
            'version' => '2.8.0',
            'total_media' => (int) wp_count_posts('attachment')->inherit,
            'used_count' => (int) get_option('muc_used_count', 0),
            'unused_count' => (int) get_option('muc_unused_count', 0),
            'last_check' => get_option('muc_last_check') ? date('c', get_option('muc_last_check')) : null,
            'memory_usage' => size_format(memory_get_usage(true)),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => (int) ini_get('max_execution_time')
        ]
    ];

    return new WP_REST_Response($response, 200);
}

/**
 * Gets ARIA attributes to improve accessibility
 * 
 * @return array Array with ARIA attributes
 */
function muc_get_aria_attributes() {
    return [
        'search' => [
            'label' => esc_html__('Search media files', 'media-usage-checker'),
            'placeholder' => esc_html__('Search media files...', 'media-usage-checker'),
            'button' => [
                'label' => esc_html__('Search', 'media-usage-checker')
            ]
        ],
        'filter' => [
            'label' => esc_html__('Filter media files', 'media-usage-checker'),
            'options' => [
                'all' => esc_html__('All files', 'media-usage-checker'),
                'used' => esc_html__('Used files', 'media-usage-checker'),
                'unused' => esc_html__('Unused files', 'media-usage-checker')
            ]
        ]
    ];
}

/**
 * Displays error or success messages
 * 
 * @param string $message Message to display
 * @param string $type Message type (error, warning, success, info)
 * @param bool $dismissible Whether the message can be dismissed
 * @return void
 */
function muc_display_notice($message, $type = 'info', $dismissible = true) {
    $class = 'notice notice-' . esc_attr($type);
    if ($dismissible) {
        $class .= ' is-dismissible';
    }
    ?>
    <div class="<?php echo esc_attr($class); ?>">
        <p><?php echo wp_kses_post($message); ?></p>
    </div>
    <?php
}

/**
 * Handles plugin updates
 * 
 * @return void
 */
function muc_check_for_updates() {
    $current_version = get_option('muc_version', '1.0.0');
    
    if (version_compare($current_version, MUC_VERSION, '<')) {
        // Update database schema if needed
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Example: Update options or database tables
        if (version_compare($current_version, '2.0.0', '<')) {
            // Migrations for version 2.0.0
        }
        
        // Update the version in the database
        update_option('muc_version', MUC_VERSION);
        
        // Clear cache after update
        muc_clear_cache();
    }
}
add_action('plugins_loaded', 'muc_check_for_updates');

/**
 * Documentación para desarrolladores
 * 
 * Filtros disponibles:
 * - 'muc_allowed_mime_types' - Modificar tipos MIME permitidos
 * - 'muc_scan_batch_size' - Cambiar el tamaño del lote de escaneo
 * - 'muc_scan_frequency' - Cambiar la frecuencia de escaneo programado
 * 
 * Acciones disponibles:
 * - 'muc_before_scan' - Antes de iniciar un escaneo
 * - 'muc_after_scan' - Después de completar un escaneo
 * - 'muc_before_delete' - Antes de eliminar archivos
 * - 'muc_after_delete' - Después de eliminar archivos
 */