<?php

/*
Plugin Name: Media Usage Checker
Plugin URI: https://oliverodev.pages.dev/
Description: Identifies which media library files are in use in WordPress content and allows you to delete unused ones.
Version: 2.7.0
Author: Alexis Olivero
Author URI: https://www.oliverodev.pages.dev/
*/

if (!defined('ABSPATH')) {
    exit;
}

// Configuración de límites y constantes
final class MUC_Config {
    const BATCH_SIZE = 100; // Aumentado de 50 a 100
    const MINI_BATCH = 20;  // Aumentado de 10 a 20
    const TIME_LIMIT = 1800;
    const SLEEP_TIME = 100000; // Reducido de 200000 a 100000
    const MEMORY_LIMIT = '1024M';
    
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
    }
}

MUC_Config::init();

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

// Función para manejar la paginación
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

// Función para mostrar la paginación
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

// Función mejorada para sanitizar entradas
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

// Agregar nonces con tiempo de expiración
function muc_create_nonce($action) {
    return wp_create_nonce($action . '_' . MUC_SALT);
}

// Verificar capacidades de usuario de forma más estricta
function muc_verify_user_capabilities() {
    if (!current_user_can('manage_options') || !current_user_can('upload_files')) {
        wp_die(__('No tienes permisos suficientes para acceder a esta página.', 'media-usage-checker'));
    }
}

// Función optimizada para verificar el uso de archivos en la biblioteca de medios
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

// Programar la verificación en segundo plano
function muc_schedule_background_check() {
    if (!wp_next_scheduled('muc_background_check')) {
        wp_schedule_event(time(), 'fifteen_minutes', 'muc_background_check');
    }
}
add_action('wp', 'muc_schedule_background_check');

// Añadir intervalos personalizados para WP-Cron
add_filter('cron_schedules', 'muc_add_cron_intervals');
function muc_add_cron_intervals($schedules) {
    $schedules['fifteen_minutes'] = [
        'interval' => 900, // 15 minutos
        'display' => __('Cada 15 minutos')
    ];
    $schedules['thirty_minutes'] = [
        'interval' => 1800, // 30 minutos
        'display' => __('Cada 30 minutos')
    ];
    return $schedules;
}

// Función para la verificación en segundo plano
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

// Función que muestra el contenido de la página principal del plugin
function muc_admin_page() {
    muc_verify_user_capabilities();
    
    // Forzar verificación si se solicita
    if (isset($_POST['muc_force_check']) && check_admin_referer('muc_force_check', 'muc_force_check_nonce')) {
        delete_option('muc_current_offset');
        delete_option('muc_total_processed');
        delete_option('muc_last_check');
        wp_schedule_single_event(time(), 'muc_background_check');
    }
    
    $used_page = isset($_GET['used_page']) ? max(1, intval($_GET['used_page'])) : 1;
    $unused_page = isset($_GET['unused_page']) ? max(1, intval($_GET['unused_page'])) : 1;
    $per_page = 20;

    // Verificar si hay una verificación en progreso
    $is_checking = get_option('muc_current_offset') !== false;
    
    // Si está en progreso, programar la siguiente verificación
    if ($is_checking && !wp_next_scheduled('muc_background_check')) {
        wp_schedule_single_event(time() + 1, 'muc_background_check');
    }
    
    $last_check = get_option('muc_last_check');
    $total_processed = get_option('muc_total_processed', 0);

    $used_media = [];
    $unused_media = [];

    for ($i = 0; $i < $total_processed; $i += MUC_Config::BATCH_SIZE) {
        $results = get_option('muc_results_' . $i, []);
        $used_media = array_merge($used_media, $results['used'] ?? []);
        $unused_media = array_merge($unused_media, $results['unused'] ?? []);
    }

    // Filtrar archivos que realmente existen
    $unused_media = array_filter($unused_media, function($media) {
        $file_path = get_attached_file($media->ID);
        return $file_path && file_exists($file_path) && filesize($file_path) > 0;
    });

    $used_media_paged = muc_get_paged_results($used_media, $used_page, $per_page);
    $unused_media_paged = muc_get_paged_results($unused_media, $unused_page, $per_page);

    ?>
    <div class="wrap">
        <h1>Media Usage Checker By Alexis Olivero - OliveroDev</h1>
        <p>This tool allows you to identify and delete unused files in the media library.</p>

        <?php if ($last_check): ?>
            <p>Last check: <?php echo date('Y-m-d H:i:s', $last_check); ?></p>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('muc_force_check', 'muc_force_check_nonce'); ?>
            <?php submit_button('Force Check', 'secondary', 'muc_force_check'); ?>
        </form>

        <h2>Files in Use</h2>
        <?php if (!empty($used_media_paged['items'])) : ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>File</th>
                        <th>ID</th>
                        <th>Size</th>
                        <th>Upload Date</th>
                        <th>Preview</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($used_media_paged['items'] as $media) : ?>
                        <?php
                        $file_path = get_attached_file($media->ID);
                        $file_size = file_exists($file_path) ? filesize($file_path) / 1024 / 1024 : 0;
                        $file_size = round($file_size, 2);
                        $upload_date = get_the_date('Y-m-d H:i:s', $media->ID);
                        $media_url = wp_get_attachment_url($media->ID);
                        ?>
                        <tr>
                            <td><?php echo esc_html($media->post_title); ?></td>
                            <td><?php echo esc_html($media->ID); ?></td>
                            <td><?php echo esc_html($file_size); ?> MB</td>
                            <td><?php echo esc_html($upload_date); ?></td>
                            <td>
                                <a href="<?php echo esc_url($media_url); ?>" target="_blank" class="button button-secondary">
                                    <?php echo esc_html(muc_get_file_type_text($media->ID)); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php muc_display_pagination($used_page, $used_media_paged['total_pages'], 'used_page'); ?>
        <?php else : ?>
            <p>No files were found in use.</p>
        <?php endif; ?>

        <h2>Files Not in Use</h2>
        <?php if (!empty($unused_media_paged['items'])) : ?>
            <form method="post">
                <?php wp_nonce_field('muc_bulk_delete', 'muc_bulk_nonce'); ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select-all"></th>
                            <th>File</th>
                            <th>ID</th>
                            <th>Size</th>
                            <th>Upload Date</th>
                            <th>Preview</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($unused_media_paged['items'] as $media) : ?>
                            <?php
                            $file_path = get_attached_file($media->ID);
                            $file_size = file_exists($file_path) ? filesize($file_path) / 1024 / 1024 : 0;
                            $file_size = round($file_size, 2);
                            $upload_date = get_the_date('Y-m-d H:i:s', $media->ID);
                            $media_url = wp_get_attachment_url($media->ID);
                            ?>
                            <tr>
                                <td><input type="checkbox" name="selected_media[]" value="<?php echo esc_attr($media->ID); ?>"></td>
                                <td><?php echo esc_html($media->post_title); ?></td>
                                <td><?php echo esc_html($media->ID); ?></td>
                                <td><?php echo esc_html($file_size); ?> MB</td>
                                <td><?php echo esc_html($upload_date); ?></td>
                                <td>
                                    <a href="<?php echo esc_url($media_url); ?>" target="_blank" class="button button-secondary">
                                        <?php echo esc_html(muc_get_file_type_text($media->ID)); ?>
                                    </a>
                                </td>
                                <td>
                                    <form method="post" style="display:inline;">
                                        <?php wp_nonce_field('muc_delete_media', 'muc_nonce'); ?>
                                        <input type="hidden" name="media_id" value="<?php echo esc_attr($media->ID); ?>">
                                        <input type="submit" name="delete_media" value="Delete" class="button button-secondary">
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <input type="submit" name="bulk_delete" value="Delete Selected" class="button button-primary">
            </form>
            <?php muc_display_pagination($unused_page, $unused_media_paged['total_pages'], 'unused_page'); ?>
        <?php else : ?>
            <p>No unused files found.</p>
        <?php endif; ?>
    </div>
    <?php
}

// Función para verificar si un medio está en uso (como imagen destacada o en contenido)
function muc_esta_medio_en_uso($media_id) {
    global $wpdb;
    
    // Cache del resultado
    $cache_key = 'muc_media_used_' . $media_id;
    $cached_result = wp_cache_get($cache_key);
    if ($cached_result !== false) {
        return $cached_result;
    }
    
    // Obtener URL y nombre del archivo una sola vez
    $media_url = wp_get_attachment_url($media_id);
    if (!$media_url) {
        wp_cache_set($cache_key, false, '', 3600);
        return false;
    }
    $media_filename = basename($media_url);
    
    // Consulta optimizada que combina todas las verificaciones
    $is_used = $wpdb->get_var($wpdb->prepare("
        SELECT EXISTS (
            SELECT 1 FROM (
                -- Verificar en contenido de posts y páginas
                SELECT post_content FROM {$wpdb->posts}
                WHERE post_type NOT IN ('attachment', 'revision', 'auto-draft', 'trash')
                AND post_status NOT IN ('trash', 'auto-draft')
                AND (
                    post_content LIKE %s
                    OR post_content LIKE %s
                    OR post_content LIKE %s
                    OR post_content LIKE %s
                )
                UNION ALL
                -- Verificar en metadatos (incluyendo constructores de páginas)
                SELECT meta_value FROM {$wpdb->postmeta}
                WHERE meta_key IN (
                    '_thumbnail_id',
                    '_product_image_gallery',
                    '_elementor_data',
                    '_wpb_shortcodes_custom_css',
                    '_divi_builder_settings',
                    '_fusion_builder_content',
                    '_cornerstone_data',
                    '_themify_builder_settings_json',
                    '_oxygen_builder_data',
                    '_fl_builder_data',
                    '_wp_page_template',
                    '_wp_attached_file'
                )
                AND (
                    meta_value = %d 
                    OR meta_value LIKE %s 
                    OR meta_value LIKE %s
                    OR meta_value LIKE %s
                    OR meta_value LIKE %s
                )
                UNION ALL
                -- Verificar en opciones del tema y widgets
                SELECT option_value FROM {$wpdb->options}
                WHERE (
                    option_name LIKE %s
                    OR option_name LIKE %s
                    OR option_name = 'site_icon'
                    OR option_name = 'site_logo'
                    OR option_name LIKE 'widget_%'
                    OR option_name LIKE 'theme_mods_%'
                )
                AND option_value LIKE %s
            ) AS combined_check
            LIMIT 1
        )
    ",
        '%' . $wpdb->esc_like($media_url) . '%',
        '%' . $wpdb->esc_like($media_filename) . '%',
        '%wp-image-' . $media_id . '%',
        '%wp-att-' . $media_id . '%',
        $media_id,
        '%:"' . $media_id . '"%',
        '%s:' . strlen($media_id) . ':"' . $media_id . '"%',
        '%' . $wpdb->esc_like('{"url":"' . $media_url) . '%',
        '%' . $wpdb->esc_like('"background-image":"' . $media_url) . '%',
        'theme_mods_%',
        'widget_%',
        '%' . $wpdb->esc_like($media_url) . '%'
    ));

    // Verificaciones adicionales para constructores de páginas y plugins
    if (!$is_used) {
        // Verificar en CSS personalizado y bloques reutilizables
        $custom_css = wp_get_custom_css();
        if (strpos($custom_css, $media_url) !== false || strpos($custom_css, $media_filename) !== false) {
            $is_used = true;
        }
        
        // Verificar en bloques reutilizables
        $reusable_blocks = get_posts([
            'post_type' => 'wp_block',
            'posts_per_page' => -1,
            'post_status' => 'publish'
        ]);
        
        foreach ($reusable_blocks as $block) {
            if (
                strpos($block->post_content, $media_url) !== false ||
                strpos($block->post_content, 'wp-image-' . $media_id) !== false
            ) {
                $is_used = true;
                break;
            }
        }
    }
    
    // Guardar en caché por una hora
    wp_cache_set($cache_key, (bool)$is_used, '', 3600);
    
    return (bool)$is_used;
}

// Nueva función auxiliar para buscar en arrays recursivamente
function muc_search_in_array($array, $search_values) {
    foreach ($array as $value) {
        if (is_array($value)) {
            if (muc_search_in_array($value, $search_values)) {
                return true;
            }
        } elseif (is_string($value) || is_numeric($value)) {
            foreach ($search_values as $search) {
                if (
                    (is_numeric($search) && $value == $search) ||
                    (is_string($search) && strpos($value, $search) !== false)
                ) {
                    return true;
                }
            }
        }
    }
    return false;
}

// Función para manejar la eliminación de medios
function muc_handle_media_deletion() {
    muc_verify_user_capabilities();
    
    // Manejar la eliminación individual
    if (isset($_POST['delete_media']) && isset($_POST['media_id'])) {
        // Verificar nonce específico para eliminación individual
        if (!isset($_POST['muc_nonce']) || !wp_verify_nonce($_POST['muc_nonce'], 'muc_delete_media')) {
            wp_die(__('Error de seguridad: Nonce inválido', 'media-usage-checker'));
        }

        $media_id = intval($_POST['media_id']);
        
        if ($media_id > 0) {
            // Verificar si el medio está en uso
            if (muc_esta_medio_en_uso($media_id)) {
                // Redirigir con mensaje de error
                wp_safe_redirect(add_query_arg(
                    array(
                        'page' => 'media-usage-checker',
                        'muc_message' => 'delete_failed_in_use'
                    ),
                    admin_url('admin.php')
                ));
                exit;
            }

            $file = get_attached_file($media_id);
            $deleted = wp_delete_attachment($media_id, true);
            
            // Verificación adicional para el archivo físico
            if ($deleted && file_exists($file)) {
                wp_delete_file($file);
            }
            
            if ($deleted) {
                wp_safe_redirect(add_query_arg(
                    array(
                        'page' => 'media-usage-checker',
                        'muc_message' => 'delete_success',
                        'muc_count' => 1
                    ),
                    admin_url('admin.php')
                ));
                exit;
            }
        }
    }

    // Manejar la eliminación por lotes
    if (isset($_POST['bulk_delete'])) {
        // Verificar nonce específico para eliminación en lote
        if (!isset($_POST['muc_bulk_nonce']) || !wp_verify_nonce($_POST['muc_bulk_nonce'], 'muc_bulk_delete')) {
            wp_die(__('Error de seguridad: Nonce inválido', 'media-usage-checker'));
        }

        if (!empty($_POST['selected_media']) && is_array($_POST['selected_media'])) {
            $success_count = 0;
            $failed_count = 0;
            
            foreach ($_POST['selected_media'] as $media_id) {
                $media_id = intval($media_id);
                if ($media_id > 0) {
                    // Verificar si el medio está en uso
                    if (muc_esta_medio_en_uso($media_id)) {
                        $failed_count++;
                        continue;
                    }

                    $file = get_attached_file($media_id);
                    if (wp_delete_attachment($media_id, true)) {
                        // Verificación adicional para el archivo físico
                        if (file_exists($file)) {
                            wp_delete_file($file);
                        }
                        $success_count++;
                    }
                }
            }

            wp_safe_redirect(add_query_arg(
                array(
                    'page' => 'media-usage-checker',
                    'muc_message' => 'bulk_delete_success',
                    'muc_count' => $success_count,
                    'muc_failed_count' => $failed_count
                ),
                admin_url('admin.php')
            ));
            exit;
        }
    }
}
add_action('admin_init', 'muc_handle_media_deletion');

// Función para los mensajes de notificación
function muc_admin_notices() {
    if (isset($_GET['muc_message'])) {
        $message = sanitize_text_field($_GET['muc_message']);
        $count = isset($_GET['muc_count']) ? intval($_GET['muc_count']) : 0;
        $failed_count = isset($_GET['muc_failed_count']) ? intval($_GET['muc_failed_count']) : 0;
        
        $class = 'notice notice-success is-dismissible';
        $notice = '';

        switch ($message) {
            case 'delete_success':
                $notice = __('El archivo se ha eliminado exitosamente.', 'media-usage-checker');
                break;
            case 'bulk_delete_success':
                $notice = sprintf(
                    _n(
                        'Se ha eliminado %d archivo exitosamente.',
                        'Se han eliminado %d archivos exitosamente.',
                        $count,
                        'media-usage-checker'
                    ),
                    $count
                );
                if ($failed_count > 0) {
                    $notice .= ' ' . sprintf(
                        _n(
                            'No se pudo eliminar %d archivo porque está en uso.',
                            'No se pudieron eliminar %d archivos porque están en uso.',
                            $failed_count,
                            'media-usage-checker'
                        ),
                        $failed_count
                    );
                }
                break;
            case 'delete_failed_in_use':
                $notice = __('No se pudo eliminar el archivo porque está en uso.', 'media-usage-checker');
                $class = 'notice notice-error is-dismissible';
                break;
            case 'check_complete':
                $notice = __('La verificación de medios se ha completado.', 'media-usage-checker');
                break;
            default:
                return;
        }
        
        if ($notice) {
            printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($notice));
        }
    }
}
add_action('admin_notices', 'muc_admin_notices');

// Eliminar la función muc_admin_styles anterior y reemplazarla por:
function muc_enqueue_admin_assets() {
    if (isset($_GET['page']) && $_GET['page'] === 'media-usage-checker') {
        $plugin_dir_url = plugin_dir_url(__FILE__);
        $version = '2.5.9';
        
        // Registrar y encolar CSS
        wp_enqueue_style(
            'muc-admin-styles',
            $plugin_dir_url . 'assets/css/muc-admin.css',
            [],
            $version
        );

        // Registrar y encolar JavaScript
        wp_enqueue_script(
            'muc-admin-scripts',
            $plugin_dir_url . 'assets/js/muc-admin.js',
            ['jquery'],
            $version,
            true
        );

        // Agregar variables localizadas para JavaScript
        wp_localize_script('muc-admin-scripts', 'mucSettings', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('muc-ajax-nonce'),
            'messages' => [
                'confirmDelete' => __('¿Está seguro de que desea eliminar este archivo?', 'media-usage-checker'),
                'confirmBulkDelete' => __('¿Está seguro de que desea eliminar los archivos seleccionados?', 'media-usage-checker'),
                'noSelection' => __('Por favor, seleccione al menos un archivo para eliminar.', 'media-usage-checker'),
                'checking' => __('Verificando...', 'media-usage-checker')
            ]
        ]);
    }
}
add_action('admin_enqueue_scripts', 'muc_enqueue_admin_assets');

// Agregar esta nueva función después de muc_admin_styles()
function muc_get_file_type_text($media_id) {
    $mime_type = get_post_mime_type($media_id);
    $type_parts = explode('/', $mime_type);
    $main_type = $type_parts[0];
    $sub_type = $type_parts[1] ?? '';

    switch ($main_type) {
        case 'image':
            return 'View image';
        case 'video':
            return 'View video';
        case 'audio':
            return 'Ver audio';
        case 'application':
            switch ($sub_type) {
                case 'pdf':
                    return 'View PDF';
                case 'msword':
                case 'vnd.openxmlformats-officedocument.wordprocessingml.document':
                    return 'View document';
                case 'vnd.ms-excel':
                case 'vnd.openxmlformats-officedocument.spreadsheetml.sheet':
                    return 'View Excel';
                case 'vnd.ms-powerpoint':
                case 'vnd.openxmlformats-officedocument.presentationml.presentation':
                    return 'View PowerPoint';
                case 'zip':
                case 'x-zip':
                case 'x-zip-compressed':
                    return 'View ZIP';
                case 'rar':
                case 'x-rar':
                case 'x-rar-compressed':
                    return 'View RAR';
                case 'x-7z-compressed':
                    return 'View 7Z';
                case 'x-tar':
                    return 'View TAR';
                case 'gzip':
                case 'x-gzip':
                    return 'View GZIP';
                case 'x-msdownload':
                case 'exe':
                case 'x-exe':
                    return 'View EXE';
                default:
                    return 'View archivo';
            }
        default:
            return 'View archivo';
    }
}

// Función para forzar una verificación manual
function muc_force_check() {
    if (isset($_POST['muc_force_check']) && check_admin_referer('muc_force_check', 'muc_force_check_nonce')) {
        muc_background_check();
        wp_redirect(add_query_arg('muc_message', 'check_complete', wp_get_referer()));
        exit;
    }
}
add_action('admin_init', 'muc_force_check');

// Función mejorada para forzar la eliminación del archivo físico
function muc_force_delete_attachment_file($delete, $post) {
    if ($delete) {
        // Obtener la ruta completa del archivo original
        $file = get_attached_file($post->ID);
        
        // Obtener el directorio de uploads
        $upload_dir = wp_upload_dir();
        
        // Obtener metadata del archivo
        $metadata = wp_get_attachment_metadata($post->ID);
        
        // Eliminar el archivo original
        if ($file && file_exists($file)) {
            wp_delete_file($file);
        }
        
        // Eliminar todas las miniaturas y variaciones
        if (!empty($metadata['sizes'])) {
            $base_dir = dirname($file) . '/';
            
            foreach ($metadata['sizes'] as $size => $sizeinfo) {
                $size_file = $base_dir . $sizeinfo['file'];
                if (file_exists($size_file)) {
                    wp_delete_file($size_file);
                }
            }
        }
        
        // Limpiar la caché y metadata
        clean_attachment_cache($post->ID);
        wp_cache_delete($post->ID, 'posts');
        delete_post_meta($post->ID, '_wp_attachment_metadata');
        delete_post_meta($post->ID, '_wp_attached_file');
    }
    
    return $delete;
}

// Asegurarse de que la función se ejecute antes de la eliminación
remove_filter('pre_delete_attachment', 'muc_force_delete_attachment_file');
add_filter('pre_delete_attachment', 'muc_force_delete_attachment_file', 1, 2);

// Modificar la función de encabezados de seguridad
function muc_add_security_headers() {
    // Solo agregar encabezados si los headers no han sido enviados
    if (!headers_sent()) {
        // Verificar si estamos en una página del plugin
        if (isset($_GET['page']) && $_GET['page'] === 'media-usage-checker') {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('X-XSS-Protection: 1; mode=block');
            header('Referrer-Policy: strict-origin-when-cross-origin');
        }
    }
}

// Cambiar el hook para los encabezados de seguridad
remove_action('admin_init', 'muc_add_security_headers');
add_action('admin_head', 'muc_add_security_headers', 1);

// Mejorar la seguridad de las operaciones con archivos
function muc_secure_file_operation($file_path) {
    // Validar que el archivo está dentro del directorio de uploads
    $upload_dir = wp_upload_dir();
    if (strpos($file_path, $upload_dir['basedir']) !== 0) {
        return false;
    }
    
    // Verificar extensión del archivo
    $allowed_extensions = array_keys(get_allowed_mime_types());
    $file_extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    if (!in_array($file_extension, $allowed_extensions)) {
        return false;
    }
    
    return true;
}

class MUC_Logger {
    private static function format_message($message, Exception $e = null) {
        $formatted = 'Media Usage Checker - ' . $message;
        if ($e) {
            $formatted .= ': ' . esc_html($e->getMessage());
        }
        return $formatted;
    }
    
    public static function error($message, Exception $e = null) {
        error_log(self::format_message($message, $e));
    }
}

function muc_log_error($message, $exception = null) {
    $error_message = 'Media Usage Checker - ' . $message;
    if ($exception instanceof Exception) {
        $error_message .= ': ' . $exception->getMessage();
    }
    error_log($error_message);
}

function muc_check_progress() {
    check_ajax_referer('muc-ajax-nonce', 'nonce');
    
    wp_send_json([
        'is_checking' => get_option('muc_current_offset') !== false
    ]);
}
add_action('wp_ajax_muc_check_progress', 'muc_check_progress');