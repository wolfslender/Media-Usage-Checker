<?php

/*
Plugin Name: Media Usage Checker
Plugin URI: https://www.olivero.com/
Description: Identifica qué archivos de la biblioteca de medios están en uso en el contenido de WordPress y permite eliminar los que no se usan.
Version: 2.3.6
Author: Alexis Olivero
Author URI: https://www.olivero.com/
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

// Función optimizada para verificar el uso de archivos en la biblioteca de medios
function muc_check_media_usage($batch_size = MUC_BATCH_SIZE, $offset = 0) {
    try {
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

        // Procesar en mini-lotes
        foreach (array_chunk($media_items, MUC_MINI_BATCH) as $chunk) {
            if (time() - $start_time >= MUC_TIME_LIMIT) {
                break;
            }

            foreach ($chunk as $media_id) {
                try {
                    $media = get_post($media_id);
                    if (!$media) continue;

                    $media_url = wp_get_attachment_url($media_id);
                    if (!$media_url) continue;

                    // Consulta optimizada
                    $is_used = $wpdb->get_var($wpdb->prepare(
                        "SELECT ID FROM $wpdb->posts 
                        WHERE post_type NOT IN ('attachment','revision') 
                        AND (post_content LIKE %s 
                        OR post_excerpt LIKE %s) 
                        LIMIT 1",
                        '%' . $wpdb->esc_like($media_url) . '%',
                        '%' . $wpdb->esc_like(basename($media_url)) . '%'
                    )) || muc_esta_medio_en_uso($media_id);

                    if ($is_used) {
                        $used_media[] = $media;
                    } else {
                        $unused_media[] = $media;
                    }

                    // Limpiar memoria
                    wp_cache_delete($media_id, 'posts');
                    clean_post_cache($media_id);

                } catch (Exception $e) {
                    error_log('Media Usage Checker - Error procesando media ID ' . $media_id . ': ' . $e->getMessage());
                    continue;
                }
            }
            
            // Pausa entre mini-lotes
            usleep(MUC_SLEEP_TIME);
        }

        return [
            'used' => $used_media,
            'unused' => $unused_media
        ];

    } catch (Exception $e) {
        error_log('Media Usage Checker - Error general: ' . $e->getMessage());
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
        error_log('Media Usage Checker - Error en verificación en segundo plano: ' . $e->getMessage());
    }
}
add_action('muc_background_check', 'muc_background_check');

// Función que muestra el contenido de la página principal del plugin
function muc_admin_page() {
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
                            ?>
                            <tr>
                                <td><input type="checkbox" name="selected_media[]" value="<?php echo esc_attr($media->ID); ?>"></td>
                                <td><?php echo esc_html($media->post_title); ?></td>
                                <td><?php echo esc_html($media->ID); ?></td>
                                <td><?php echo esc_html($file_size); ?> MB</td>
                                <td><?php echo esc_html($upload_date); ?></td>
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

    <script>
        document.getElementById('select-all').addEventListener('click', function(event) {
            let checkboxes = document.querySelectorAll('input[name="selected_media[]"]');
            for (let checkbox of checkboxes) {
                checkbox.checked = event.target.checked;
            }
        });
    </script>
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
    
    // Verificar si el medio está usado como imagen destacada
    $is_featured = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $wpdb->postmeta 
        WHERE meta_key = '_thumbnail_id' 
        AND meta_value = %d",
        $media_id
    )) > 0;
    
    if ($is_featured) {
        return true;
    }
    
    // Verificar si el medio está usado en cualquier contenido
    $is_used = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $wpdb->posts 
        WHERE post_type NOT IN ('attachment','revision') 
        AND (post_content LIKE %s 
        OR post_excerpt LIKE %s) 
        LIMIT 1",
        '%' . $wpdb->esc_like($media_url) . '%',
        '%' . $wpdb->esc_like($media_filename) . '%'
    )) > 0;
    
    return $is_used;
}

// Función para manejar la eliminación de medios
function muc_handle_media_deletion() {
    // Manejar la eliminación individual
    if (isset($_POST['delete_media']) && isset($_POST['media_id']) && 
        check_admin_referer('muc_delete_media', 'muc_nonce')) {
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
    if (isset($_POST['bulk_delete']) && check_admin_referer('muc_bulk_delete', 'muc_bulk_nonce')) {
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

// Agregar estilos CSS
add_action('admin_head', 'muc_admin_styles');
function muc_admin_styles() {
    ?>
    <style>
        .pagination {
            margin: 20px 0;
        }
        .pagination a {
            padding: 5px 10px;
            margin: 0 5px;
            text-decoration: none;
            border: 1px solid #ddd;
            background: #f7f7f7;
        }
        .pagination a.current-page {
            background: #0073aa;
            color: white;
            border-color: #0073aa;
        }
    </style>
    <?php
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