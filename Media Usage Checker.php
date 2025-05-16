<?php

/*
Plugin Name: Media Usage Checker
Plugin URI: https://www.oliverodev.com/
Description: Identifica qué archivos de la biblioteca de medios están en uso en el contenido de WordPress y permite eliminar los que no se usan.
Version: 2.5.9
Author: Alexis Olivero
Author URI: https://www.oliverodev.pages.dev/
*/

if (!defined('ABSPATH')) {
    exit;
}

// Configuración de límites
@ini_set('memory_limit', '1024M'); // Aumentar límite de memoria a 1GB
@set_time_limit(0); // Eliminar límite de tiempo de ejecución

// Constantes globales
define('MUC_BATCH_SIZE', 50);
define('MUC_MINI_BATCH', 10);
define('MUC_TIME_LIMIT', 1800); // 30 minutos
define('MUC_SLEEP_TIME', 200000); // 0.2 segundos

// Mejorar la seguridad de las constantes
if (!defined('MUC_SALT')) {
    if (defined('NONCE_SALT')) {
        define('MUC_SALT', NONCE_SALT);
    } else {
        define('MUC_SALT', 'muc_default_salt_' . ABSPATH);
    }
}

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
function muc_check_media_usage($batch_size = MUC_BATCH_SIZE, $offset = 0) {
    muc_verify_user_capabilities();
    
    try {
        // Validar parámetros
        $batch_size = abs(intval($batch_size));
        $offset = abs(intval($offset));
        
        global $wpdb;
        
        // Aumentar límites
        @set_time_limit(0);
        wp_raise_memory_limit('admin');
        
        $start_time = time();
        
        $media_items = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => $batch_size,
            'offset' => $offset,
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
            'fields' => 'ids'
        ]);

        $unused_media = [];
        $used_media = [];

        foreach (array_chunk($media_items, MUC_MINI_BATCH) as $chunk) {
            if (time() - $start_time >= MUC_TIME_LIMIT) {
                break;
            }

            foreach ($chunk as $media_id) {
                $mime_type = get_post_mime_type($media_id);
                if (!in_array($mime_type, get_allowed_mime_types())) {
                    continue;
                }

                try {
                    $media = get_post($media_id);
                    if (!$media) continue;

                    $media_url = wp_get_attachment_url($media_id);
                    if (!$media_url) continue;

                    // Verificar si el archivo existe físicamente
                    $file_path = get_attached_file($media_id);
                    if (!$file_path || !file_exists($file_path)) {
                        continue;
                    }

                    $is_used = muc_esta_medio_en_uso($media_id);

                    if ($is_used) {
                        $used_media[] = $media;
                    } else {
                        // Solo añadir a unused si realmente existe el archivo
                        if (filesize($file_path) > 0) {
                            $unused_media[] = $media;
                        }
                    }

                    // Limpiar memoria
                    wp_cache_delete($media_id, 'posts');
                    clean_post_cache($media_id);

                } catch (Exception $e) {
                    error_log('Media Usage Checker - Error procesando media ID ' . $media_id . ': ' . esc_html($e->getMessage()));
                    continue;
                }
            }
            
            usleep(MUC_SLEEP_TIME);
        }

        return [
            'used' => $used_media,
            'unused' => $unused_media
        ];

    } catch (Exception $e) {
        error_log('Media Usage Checker - Error general: ' . esc_html($e->getMessage()));
        return [
            'used' => [],
            'unused' => []
        ];
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
        $batch_size = MUC_BATCH_SIZE;
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

        if ($processed < $batch_size || (time() - $start_time) >= MUC_TIME_LIMIT) {
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
    
    $used_page = isset($_GET['used_page']) ? max(1, intval($_GET['used_page'])) : 1;
    $unused_page = isset($_GET['unused_page']) ? max(1, intval($_GET['unused_page'])) : 1;
    $per_page = 20;

    $last_check = get_option('muc_last_check');
    $total_processed = get_option('muc_total_processed', 0);

    $used_media = [];
    $unused_media = [];

    for ($i = 0; $i < $total_processed; $i += MUC_BATCH_SIZE) {
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
        <p>Esta herramienta te permite identificar y eliminar archivos en la biblioteca de medios que no están en uso.</p>

        <?php if ($last_check): ?>
            <p>Última verificación: <?php echo date('Y-m-d H:i:s', $last_check); ?></p>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('muc_force_check', 'muc_force_check_nonce'); ?>
            <?php submit_button('Forzar verificación', 'secondary', 'muc_force_check'); ?>
        </form>

        <h2>Archivos en Uso</h2>
        <?php if (!empty($used_media_paged['items'])) : ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Archivo</th>
                        <th>ID</th>
                        <th>Tamaño</th>
                        <th>Fecha de Subida</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($used_media_paged['items'] as $media) : ?>
                        <?php
                        $file_path = get_attached_file($media->ID);
                        $file_size = file_exists($file_path) ? filesize($file_path) / 1024 / 1024 : 0;
                        $file_size = round($file_size, 2);
                        $upload_date = get_the_date('Y-m-d H:i:s', $media->ID);
                        ?>
                        <tr>
                            <td><?php echo esc_html($media->post_title); ?></td>
                            <td><?php echo esc_html($media->ID); ?></td>
                            <td><?php echo esc_html($file_size); ?> MB</td>
                            <td><?php echo esc_html($upload_date); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php muc_display_pagination($used_page, $used_media_paged['total_pages'], 'used_page'); ?>
        <?php else : ?>
            <p>No se encontraron archivos en uso.</p>
        <?php endif; ?>

        <h2>Archivos No en Uso</h2>
        <?php if (!empty($unused_media_paged['items'])) : ?>
            <form method="post">
                <?php wp_nonce_field('muc_bulk_delete', 'muc_bulk_nonce'); ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select-all"></th>
                            <th>Archivo</th>
                            <th>ID</th>
                            <th>Tamaño</th>
                            <th>Fecha de Subida</th>
                            <th>Vista Previa</th>
                            <th>Acciones</th>
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
                                        <input type="submit" name="delete_media" value="Eliminar" class="button button-secondary">
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <input type="submit" name="bulk_delete" value="Eliminar seleccionados" class="button button-primary">
            </form>
            <?php muc_display_pagination($unused_page, $unused_media_paged['total_pages'], 'unused_page'); ?>
        <?php else : ?>
            <p>No se encontraron archivos sin uso.</p>
        <?php endif; ?>
    </div>
    <?php
}

// Función para verificar si un medio está en uso (como imagen destacada o en contenido)
function muc_esta_medio_en_uso($media_id) {
    global $wpdb;
    
    // Obtener URL y nombre del archivo
    $media_url = wp_get_attachment_url($media_id);
    if (!$media_url) {
        return false;
    }
    $media_filename = basename($media_url);
    
    // 1. Verificación exhaustiva del personalizador
    $customizer_settings = [
        'custom_logo',
        'site_logo',
        'site_icon',
        'background_image',
        'header_image',
        'header_video',
        'external_header_video',
        'custom_css',
        'logo',
        'mobile_logo',
        'footer_logo',
        'retina_logo',
        'favicon',
        'apple_touch_icon',
        'mobile_icon',
        'site_icon_png',
        'header_banner_image',
        'footer_background_image',
        'login_logo',
        'transparent_logo',
        'sticky_header_logo'
    ];

    // Verificar cada configuración del personalizador
    foreach ($customizer_settings as $setting) {
        if (get_theme_mod($setting) == $media_id) {
            return true;
        }
    }

    // Verificar en todas las opciones del tema
    $theme_mods = get_theme_mods();
    if ($theme_mods) {
        foreach ($theme_mods as $key => $value) {
            if (
                (is_numeric($value) && $value == $media_id) || 
                (is_string($value) && (
                    strpos($value, $media_url) !== false || 
                    strpos($value, $media_filename) !== false
                ))
            ) {
                return true;
            }
        }
    }

    // 2. Verificar uso en contenido de posts y páginas con una búsqueda más exhaustiva
    $content_search = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $wpdb->posts 
        WHERE post_type NOT IN ('attachment', 'revision', 'auto-draft') 
        AND (
            post_content LIKE %s 
            OR post_content LIKE %s
            OR post_content LIKE %s
            OR post_content LIKE %s
            OR post_content LIKE %s
        )",
        '%' . $wpdb->esc_like($media_url) . '%',
        '%' . $wpdb->esc_like($media_filename) . '%',
        '%wp-image-' . $media_id . '%',
        '%attachment_' . $media_id . '%',
        '%' . $wpdb->esc_like(wp_get_attachment_image_url($media_id, 'full')) . '%'
    ));
    
    if ($content_search > 0) return true;

    // 3. Verificar uso como imagen destacada en cualquier post type
    $featured_image = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $wpdb->postmeta 
        WHERE meta_key IN ('_thumbnail_id', '_product_image_gallery', '_wp_page_template') 
        AND (meta_value = %d OR meta_value LIKE %s)",
        $media_id,
        '%":' . $media_id . ',%'
    ));
    
    if ($featured_image > 0) return true;

    // 4. Verificar en opciones del tema y personalizador de manera más exhaustiva
    $theme_mods_search = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $wpdb->options 
        WHERE (
            option_name LIKE %s 
            OR option_name LIKE %s
            OR option_name LIKE %s
            OR option_name LIKE %s
        ) AND (
            option_value LIKE %s 
            OR option_value LIKE %s
            OR option_value LIKE %s
            OR option_value LIKE %s
        )",
        'theme_mods_%',
        '%_options',
        'widget_%',
        'sidebars_%',
        '%' . $wpdb->esc_like($media_url) . '%',
        '%' . $wpdb->esc_like($media_filename) . '%',
        '%:"' . $media_id . '"%',
        '%s:' . strlen($media_id) . ':"' . $media_id . '"%'
    ));
    
    if ($theme_mods_search > 0) return true;

    // 5. Verificar en metadatos personalizados
    $custom_fields = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $wpdb->postmeta 
        WHERE meta_value LIKE %s 
        OR meta_value LIKE %s
        OR meta_value LIKE %s
        OR meta_value LIKE %s",
        '%' . $wpdb->esc_like($media_url) . '%',
        '%' . $wpdb->esc_like($media_filename) . '%',
        '%:"' . $media_id . '"%',
        '%s:' . strlen($media_id) . ':"' . $media_id . '"%'
    ));
    
    if ($custom_fields > 0) return true;

    // 6. Verificar en constructores de página populares
    $page_builders = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $wpdb->postmeta 
        WHERE meta_key IN (
            '_elementor_data',
            '_wpb_shortcodes_custom_css',
            '_fusion_builder_content',
            '_divi_content',
            'panels_data'
        ) 
        AND (
            meta_value LIKE %s 
            OR meta_value LIKE %s
            OR meta_value LIKE %s
        )",
        '%' . $wpdb->esc_like($media_url) . '%',
        '%"id":' . $media_id . '%',
        '%"url":"' . $wpdb->esc_like($media_url) . '"%'
    ));
    
    if ($page_builders > 0) return true;

    // 7. Verificar en menús de navegación
    $nav_menu_items = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $wpdb->postmeta pm
        JOIN $wpdb->posts p ON p.ID = pm.post_id
        WHERE p.post_type = 'nav_menu_item'
        AND (
            pm.meta_value LIKE %s 
            OR pm.meta_value = %d
            OR p.post_content LIKE %s
        )",
        '%' . $wpdb->esc_like($media_url) . '%',
        $media_id,
        '%' . $wpdb->esc_like($media_filename) . '%'
    ));
    
    if ($nav_menu_items > 0) return true;

    // 8. Verificar en widgets y sidebars serializados
    $widgets = get_option('widget_media_image');
    if ($widgets && is_array($widgets)) {
        foreach ($widgets as $widget) {
            if (isset($widget['attachment_id']) && $widget['attachment_id'] == $media_id) {
                return true;
            }
        }
    }

    // 9. Verificar en opciones serializadas del personalizador
    $serialized_options = $wpdb->get_results(
        "SELECT option_value 
        FROM $wpdb->options 
        WHERE option_name LIKE '%theme_mods%' 
        OR option_name LIKE '%customizer%'
        OR option_name LIKE '%widget%'"
    );

    foreach ($serialized_options as $option) {
        if (is_serialized($option->option_value)) {
            $unserialized = @unserialize($option->option_value);
            if ($unserialized !== false) {
                if (muc_search_in_array($unserialized, [$media_id, $media_url, $media_filename])) {
                    return true;
                }
            }
        }
    }

    // 10. Verificar en opciones del personalizador
    $theme_mods = get_theme_mods();
    if ($theme_mods) {
        foreach ($theme_mods as $mod) {
            if (is_string($mod) && (
                strpos($mod, $media_url) !== false ||
                strpos($mod, $media_filename) !== false
            )) {
                return true;
            }
        }
    }

    return false;
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
            return 'Ver imagen';
        case 'video':
            return 'Ver video';
        case 'audio':
            return 'Ver audio';
        case 'application':
            switch ($sub_type) {
                case 'pdf':
                    return 'Ver PDF';
                case 'msword':
                case 'vnd.openxmlformats-officedocument.wordprocessingml.document':
                    return 'Ver documento';
                case 'vnd.ms-excel':
                case 'vnd.openxmlformats-officedocument.spreadsheetml.sheet':
                    return 'Ver Excel';
                case 'vnd.ms-powerpoint':
                case 'vnd.openxmlformats-officedocument.presentationml.presentation':
                    return 'Ver PowerPoint';
                case 'zip':
                case 'x-zip':
                case 'x-zip-compressed':
                    return 'Ver ZIP';
                case 'rar':
                case 'x-rar':
                case 'x-rar-compressed':
                    return 'Ver RAR';
                case 'x-7z-compressed':
                    return 'Ver 7Z';
                case 'x-tar':
                    return 'Ver TAR';
                case 'gzip':
                case 'x-gzip':
                    return 'Ver GZIP';
                case 'x-msdownload':
                case 'exe':
                case 'x-exe':
                    return 'Ver EXE';
                default:
                    return 'Ver archivo';
            }
        default:
            return 'Ver archivo';
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