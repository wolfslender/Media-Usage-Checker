<?php
/*
Plugin Name: Media Usage Checker
Plugin URI: https://www.olivero.com/
Description: Identifica qué archivos de la biblioteca de medios están en uso en el contenido de WordPress y permite eliminar los que no se usan.
Version: 1.2.0
Author: Alexis Olivero
Author URI: https://www.olivero.com/
*/

if (!defined('ABSPATH')) {
    exit;
}

// Agregar opción para la papelera al activar el plugin
register_activation_hook(__FILE__, 'muc_plugin_activate');

function muc_plugin_activate() {
    add_option('muc_trash_items', array());
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

    // Agregar submenú para la papelera
    add_submenu_page(
        'media-usage-checker',
        'Papelera de Medios',
        'Papelera',
        'manage_options',
        'media-usage-trash',
        'muc_trash_page'
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

// Función para mover un archivo a la papelera
function muc_move_to_trash($media_id) {
    if (!$media_id || !is_int($media_id)) {
        error_log("Invalid media ID provided to muc_move_to_trash: " . print_r($media_id, true));
        return;
    }

    $trash_items = get_option('muc_trash_items', array());

    $media_info = array(
        'id' => $media_id,
        'title' => get_the_title($media_id),
        'url' => wp_get_attachment_url($media_id),
        'date_trashed' => current_time('mysql'),
        'file_path' => get_attached_file($media_id)
    );

    // Si alguno de los datos es nulo o falla, muestra un error en el log
    if (!$media_info['title'] || !$media_info['url']) {
        error_log("Failed to retrieve media info for ID: " . $media_id);
        return;
    }

    $trash_items[] = $media_info;
    update_option('muc_trash_items', $trash_items);

    // Ocultar el archivo pero no eliminarlo
    $result = wp_update_post(array(
        'ID' => $media_id,
        'post_status' => 'trash'
    ));

    if (is_wp_error($result)) {
        error_log("Failed to trash media ID: " . $media_id . ". Error: " . $result->get_error_message());
    }
}

// Función para restaurar un archivo de la papelera
function muc_restore_from_trash($media_id) {
    $trash_items = get_option('muc_trash_items', array());

    // Eliminar de la lista de papelera
    $trash_items = array_filter($trash_items, function($item) use ($media_id) {
        return $item['id'] != $media_id;
    });

    update_option('muc_trash_items', $trash_items);

    // Restaurar el estado del archivo
    wp_update_post(array(
        'ID' => $media_id,
        'post_status' => 'inherit'
    ));
}

// Registra la página en el menú de administración
add_action('admin_menu', function() {
    add_menu_page(
        'Papelera de Medios', // Título de la página
        'Papelera', // Texto del menú
        'manage_options', // Capacidad
        'muc_trash', // Slug
        'muc_trash_page' // Función que muestra el contenido
    );
});

// Función para mostrar el contenido de la página de papelera
function muc_trash_page() {
    // Verifica si el usuario tiene permisos para acceder
    if (!current_user_can('manage_options')) {
        wp_die(__('Lo siento, no tienes permisos para acceder a esta página.'));
    }

    // Manejo de eliminación permanente de archivos
    if (isset($_POST['delete_all_permanent'])) {
        if (isset($_POST['muc_delete_nonce']) && wp_verify_nonce($_POST['muc_delete_nonce'], 'muc_delete_media')) {
            // Obtener elementos de la papelera
            $trash_items = get_option('muc_trash_items', array());

            // Configurar paginación
            $items_per_page = 10; 
            $current_page = isset($_GET['paged']) ? (int)$_GET['paged'] : 1;
            $offset = ($current_page - 1) * $items_per_page;

            // Eliminar los primeros 10 elementos de la página actual
            for ($i = $offset; $i < $offset + $items_per_page && $i < count($trash_items); $i++) {
                unset($trash_items[$i]);
            }
            // Reindexar el array
            $trash_items = array_values($trash_items);
            update_option('muc_trash_items', $trash_items);

            echo '<div class="notice notice-success"><p>Los archivos seleccionados han sido eliminados permanentemente.</p></div>';
            wp_redirect(admin_url('admin.php?page=muc_trash&paged=' . $current_page));
            exit;
        } else {
            echo '<div class="notice notice-error"><p>Error de seguridad. No se pudo eliminar los archivos.</p></div>';
        }
    }

    // Título y mensaje de la página
    echo '<h1 style="color: #333; font-size: 24px;">Papelera de Medios</h1>';
    echo '<p style="color: #666;">Esta es la página de archivos movidos a la papelera.</p>';
    
    // Obtener los elementos de la papelera
    $trash_items = get_option('muc_trash_items', array());

    // Configuración de paginación
    $items_per_page = 10; 
    $total_items = count($trash_items);
    $total_pages = ceil($total_items / $items_per_page);
    $current_page = isset($_GET['paged']) ? (int)$_GET['paged'] : 1;
    $current_page = max(1, min($current_page, $total_pages));

    // Obtener los elementos para la página actual
    $offset = ($current_page - 1) * $items_per_page;
    $paged_items = array_slice($trash_items, $offset, $items_per_page);

    if (!empty($paged_items)) {
        echo '<ul style="list-style-type: none; padding: 0;">';
        foreach ($paged_items as $item) {
            echo '<li style="margin: 10px 0; padding: 10px; border: 1px solid #ddd; background: #f9f9f9; border-radius: 4px;">';
            echo '<strong>' . esc_html($item['title']) . '</strong> - ';
            echo '<a href="' . esc_url($item['url']) . '" style="color: #0073aa; text-decoration: none;">Ver Archivo</a>';
            echo ' - <form method="post" style="display:inline;">';
            echo '<input type="hidden" name="media_id" value="' . esc_attr($item['id']) . '">';
            echo wp_nonce_field('muc_restore_media', 'muc_restore_nonce', true, false);
            echo '<input type="submit" name="restore_media" value="Restaurar" class="button button-secondary" style="margin-left: 10px;">';
            echo '</form>';
            echo '</li>';
        }
        echo '</ul>';
        
        // Botón para eliminar los primeros 10 archivos permanentemente
        echo '<form method="post" style="margin-top: 20px;">';
        echo wp_nonce_field('muc_delete_media', 'muc_delete_nonce', true, false); // Añadir nonce para seguridad
        echo '<input type="submit" name="delete_all_permanent" value="Eliminar Archivos Permanentemente" class="button button-danger" style="padding: 10px 15px;">';
        echo '</form>';
    } else {
        echo '<p>No hay archivos en la papelera.</p>';
    }

    // Paginación
    if ($total_pages > 1) {
        echo '<div class="pagination" style="margin-top: 20px;">';
        for ($i = 1; $i <= $total_pages; $i++) {
            if ($i == $current_page) {
                echo '<span style="margin-right: 5px; font-weight: bold;">' . $i . '</span>'; // Página actual
            } else {
                echo '<a href="?page=muc_trash&paged=' . $i . '" style="margin-right: 5px; color: #0073aa; text-decoration: none;">' . $i . '</a>';
            }
        }
        echo '</div>';
    }
}



// Función que muestra el contenido de la página principal del plugin
function muc_admin_page() {
    $used_page = isset($_GET['used_page']) ? max(1, intval($_GET['used_page'])) : 1;
    $unused_page = isset($_GET['unused_page']) ? max(1, intval($_GET['unused_page'])) : 1;
    $per_page = 20;

    $media_usage = muc_check_media_usage();
    $used_media = muc_get_paged_results($media_usage['used'], $used_page, $per_page);
    $unused_media = muc_get_paged_results($media_usage['unused'], $unused_page, $per_page);

    ?>
    <div class="wrap">
        <h1>Media Usage Checker By Alexis Olivero - OliveroDev</h1>
        <p>Esta herramienta te permite identificar y mover a la papelera archivos en la biblioteca de medios que no están en uso y fue creada para el departamento de Educacion de Puerto Rico.</p>

        <h2>Archivos en Uso</h2>
        <?php if (!empty($used_media['items'])) : ?>
            <!-- Tabla de archivos en uso -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Archivo</th>
                        <th>ID</th>
                        <th>Tamaño</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($used_media['items'] as $media) : ?>
                        <?php
                        $file_path = get_attached_file($media->ID);
                        $file_size = file_exists($file_path) ? filesize($file_path) / 1024 / 1024 : 0;
                        $file_size = round($file_size, 2);
                        ?>
                        <tr>
                            <td><?php echo esc_html($media->post_title); ?></td>
                            <td><?php echo esc_html($media->ID); ?></td>
                            <td><?php echo esc_html($file_size); ?> MB</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php muc_display_pagination($used_page, $used_media['total_pages'], 'used_page'); ?>
        <?php else : ?>
            <p>No se encontraron archivos en uso.</p>
        <?php endif; ?>

        <h2>Archivos No en Uso</h2>
        <?php if (!empty($unused_media['items'])) : ?>
            <!-- Formulario para eliminación por lotes -->
            <form method="post">
                <?php wp_nonce_field('muc_bulk_delete', 'muc_bulk_nonce'); ?>

                <!-- Tabla de archivos no utilizados con checkbox -->
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select-all"></th>
                            <th>Archivo</th>
                            <th>ID</th>
                            <th>Tamaño</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($unused_media['items'] as $media) : ?>
                            <?php
                            $file_path = get_attached_file($media->ID);
                            $file_size = file_exists($file_path) ? filesize($file_path) / 1024 / 1024 : 0;
                            $file_size = round($file_size, 2);
                            ?>
                            <tr>
                                <td><input type="checkbox" name="selected_media[]" value="<?php echo esc_attr($media->ID); ?>"></td>
                                <td><?php echo esc_html($media->post_title); ?></td>
                                <td><?php echo esc_html($media->ID); ?></td>
                                <td><?php echo esc_html($file_size); ?> MB</td>
                                <td>
                                    <form method="post" style="display:inline;">
                                        <?php wp_nonce_field('muc_delete_media', 'muc_nonce'); ?>
                                        <input type="hidden" name="media_id" value="<?php echo esc_attr($media->ID); ?>">
                                        <input type="submit" name="delete_media" value="Mover a papelera" class="button button-secondary">
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Botón para eliminación por lotes -->
                <input type="submit" name="bulk_delete" value="Mover seleccionados a papelera" class="button button-primary">
            </form>

            <!-- Paginar archivos no utilizados -->
            <?php muc_display_pagination($unused_page, $unused_media['total_pages'], 'unused_page'); ?>
        <?php else : ?>
            <p>No se encontraron archivos sin uso.</p>
        <?php endif; ?>
    </div>

    <!-- Script JavaScript para seleccionar todos los checkboxes -->
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

// Función para verificar el uso de archivos en la biblioteca de medios
function muc_check_media_usage() {
    global $wpdb;

    $media_items = get_posts([
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'posts_per_page' => -1
    ]);

    $unused_media = [];
    $used_media = [];

    foreach ($media_items as $media) {
        $media_id = $media->ID;
        $media_url = wp_get_attachment_url($media_id);

        $is_used = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ID FROM $wpdb->posts WHERE post_content LIKE %s LIMIT 1",
                '%' . $wpdb->esc_like($media_url) . '%'
            )
        );

        if ($is_used) {
            $used_media[] = $media;
        } else {
            $unused_media[] = $media;
        }
    }

    return [
        'used' => $used_media,
        'unused' => $unused_media
    ];
}

// Agregar función para los mensajes de notificación 
function muc_admin_notices() {
    if (isset($_GET['muc_message'])) {
        $message = sanitize_text_field($_GET['muc_message']);
        $type = isset($_GET['muc_type']) ? sanitize_text_field($_GET['muc_type']) : 'success';
        $count = isset($_GET['muc_count']) ? intval($_GET['muc_count']) : 0;
        
        $class = 'notice notice-' . $type . ' is-dismissible';
        
        switch ($message) {
            case 'bulk_delete_success':
                $notice = sprintf(
                    _n(
                        'Se ha movido %d archivo a la papelera exitosamente.',
                        'Se han movido %d archivos a la papelera exitosamente.',
                        $count,
                        'media-usage-checker'
                    ),
                    $count
                );
                break;
            case 'bulk_delete_error':
                $notice = __('Ha ocurrido un error al intentar mover los archivos a la papelera.', 'media-usage-checker');
                break;
            default:
                return;
        }
        
        printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($notice));
    }
}
add_action('admin_notices', 'muc_admin_notices');

// Funcion handle_media versión actualizada
function muc_handle_media_deletion() {
    // Manejar el movimiento a la papelera individual
    if (isset($_POST['delete_media']) && isset($_POST['media_id']) && 
        check_admin_referer('muc_delete_media', 'muc_nonce')) {
        $media_id = intval($_POST['media_id']);
        
        // Validar si el ID de medios es válido
        if ($media_id <= 0) {
            error_log("Invalid media ID provided: " . $media_id);
            wp_die("ID de medios no válido.");
        }

        muc_move_to_trash($media_id);
        wp_safe_redirect(add_query_arg(
            array(
                'page' => 'media-usage-checker',
                'muc_message' => 'bulk_delete_success',
                'muc_count' => 1
            ),
            admin_url('admin.php')
        ));
        exit;
    }

    // Manejar la restauración
    if (isset($_POST['restore_media']) && isset($_POST['media_id']) && 
        check_admin_referer('muc_restore_media', 'muc_restore_nonce')) {
        $media_id = intval($_POST['media_id']);
        muc_restore_from_trash($media_id);
        wp_safe_redirect(admin_url('admin.php?page=media-usage-trash'));
        exit;
    }

    // Manejar la eliminación permanente
    if (isset($_POST['delete_permanent']) && isset($_POST['media_id']) && 
        check_admin_referer('muc_delete_permanent', 'muc_delete_permanent_nonce')) {
        $media_id = intval($_POST['media_id']);
        
        // Eliminar de la papelera
        $trash_items = get_option('muc_trash_items', array());
        $trash_items = array_filter($trash_items, function($item) use ($media_id) {
            return $item['id'] != $media_id;
        });
        update_option('muc_trash_items', $trash_items);
        
        // Eliminar permanentemente
        wp_delete_attachment($media_id, true);
        
        wp_safe_redirect(admin_url('admin.php?page=media-usage-trash'));
        exit;
    }

    // Manejar la eliminación por lotes
    if (isset($_POST['bulk_delete']) && check_admin_referer('muc_bulk_delete', 'muc_bulk_nonce')) {
        if (!empty($_POST['selected_media']) && is_array($_POST['selected_media'])) {
            $success_count = 0;
            $has_errors = false;
            
            foreach ($_POST['selected_media'] as $media_id) {
                $media_id = intval($media_id);
                if ($media_id > 0) {
                    try {
                        muc_move_to_trash($media_id);
                        $success_count++;
                    } catch (Exception $e) {
                        error_log("Error deleting media ID: " . $media_id . " - " . $e->getMessage());
                        $has_errors = true;
                    }
                }
            }

            // Preparar la redirección con el mensaje apropiado
            $redirect_args = array(
                'page' => 'media-usage-checker',
                'muc_message' => ($success_count > 0) ? 'bulk_delete_success' : 'bulk_delete_error',
                'muc_type' => ($has_errors) ? 'warning' : 'success',
                'muc_count' => $success_count
            );

            wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }
    }
}
add_action('admin_init', 'muc_handle_media_deletion');



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
        .button-danger {
            background: #dc3232 !important;
            border-color: #dc3232 !important;
            color: white !important;
        }
        .button-danger:hover {
            background: #aa0000 !important;
            border-color: #aa0000 !important;
        }

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
        .button-danger {
            background: #dc3232 !important;
            border-color: #dc3232 !important;
            color: white !important;
        }
        .button-danger:hover {
            background: #aa0000 !important;
            border-color: #aa0000 !important;
        }
        /* Nuevos estilos para la papelera */
        .trash-item {
            margin: 10px 0;
            padding: 10px;
            border: 1px solid #ddd;
            background: #f9f9f9;
            border-radius: 4px;
        }
        .trash-item strong {
            color: #333;
        }
    </style>
    <?php
}