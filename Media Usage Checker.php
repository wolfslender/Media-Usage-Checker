<?php
/*
Plugin Name: Media Usage Checker
Plugin URI: https://www.olivero.com/
Description: Identifica qué archivos de la biblioteca de medios están en uso en el contenido de WordPress y permite eliminar los que no se usan. Ahora incluye una papelera para recuperación.
Version: 1.3
Author: Alexis Olivero
Author URI: https://www.olivero.com/
*/

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente
}

// Añadir el menú al panel de administración
add_action('admin_menu', 'muc_add_admin_menu');

function muc_add_admin_menu() {
    add_menu_page(
        'Media Usage Checker', // Título de la página
        'Media Usage Checker', // Nombre del menú
        'manage_options', // Capacidad requerida
        'media-usage-checker', // Slug de la página
        'muc_admin_page', // Función que mostrará el contenido
        'dashicons-media-spreadsheet', // Icono del menú
        25 // Posición del menú
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

// Función que muestra el contenido de la página del plugin
function muc_admin_page() {
    $used_page = isset($_GET['used_page']) ? max(1, intval($_GET['used_page'])) : 1;
    $unused_page = isset($_GET['unused_page']) ? max(1, intval($_GET['unused_page'])) : 1;
    $trash_page = isset($_GET['trash_page']) ? max(1, intval($_GET['trash_page'])) : 1;
    $per_page = 20; // Elementos por página

    // Obtener los archivos en uso y no en uso
    $media_usage = muc_check_media_usage();
    $used_media = muc_get_paged_results($media_usage['used'], $used_page, $per_page);
    $unused_media = muc_get_paged_results($media_usage['unused'], $unused_page, $per_page);
    $trash_media = muc_get_paged_results(muc_get_trash_media(), $trash_page, $per_page);

    ?>
    <div class="wrap">
        <h1>Media Usage Checker</h1>
        <p>Esta herramienta te permite identificar, restaurar o eliminar archivos en la biblioteca de medios que no están en uso en tu contenido de WordPress.</p>

        <h2>Archivos en Uso</h2>
        <?php if (!empty($used_media['items'])) : ?>
            <ul>
                <?php foreach ($used_media['items'] as $media) : ?>
                    <li><?php echo esc_html($media->post_title); ?> (ID: <?php echo esc_html($media->ID); ?>)</li>
                <?php endforeach; ?>
            </ul>
            <?php muc_display_pagination($used_page, $used_media['total_pages'], 'used_page'); ?>
        <?php else : ?>
            <p>No se encontraron archivos en uso.</p>
        <?php endif; ?>

        <h2>Archivos No en Uso</h2>
        <?php if (!empty($unused_media['items'])) : ?>
            <ul>
                <?php foreach ($unused_media['items'] as $media) : ?>
                    <?php
                    $file_path = get_attached_file($media->ID);
                    $file_size = file_exists($file_path) ? filesize($file_path) / 1024 / 1024 : 0;
                    $file_size = round($file_size, 2);
                    ?>
                    <li>
                        <?php echo esc_html($media->post_title); ?> (ID: <?php echo esc_html($media->ID); ?>) -
                        Tamaño: <?php echo esc_html($file_size); ?> MB
                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field('muc_delete_media', 'muc_nonce'); ?>
                            <input type="hidden" name="media_id" value="<?php echo esc_attr($media->ID); ?>">
                            <input type="submit" name="move_to_trash" value="Mover a Papelera" class="button button-danger" onclick="return confirm('¿Mover a la papelera?');">
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php muc_display_pagination($unused_page, $unused_media['total_pages'], 'unused_page'); ?>
        <?php else : ?>
            <p>No se encontraron archivos sin uso.</p>
        <?php endif; ?>

        <h2>Archivos en la Papelera</h2>
        <?php if (!empty($trash_media['items'])) : ?>
            <ul>
                <?php foreach ($trash_media['items'] as $media) : ?>
                    <li>
                        <?php echo esc_html($media->post_title); ?> (ID: <?php echo esc_html($media->ID); ?>)
                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field('muc_restore_media', 'muc_nonce'); ?>
                            <input type="hidden" name="media_id" value="<?php echo esc_attr($media->ID); ?>">
                            <input type="submit" name="restore_media" value="Restaurar" class="button button-primary">
                        </form>
                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field('muc_delete_permanent', 'muc_nonce'); ?>
                            <input type="hidden" name="media_id" value="<?php echo esc_attr($media->ID); ?>">
                            <input type="submit" name="delete_permanent" value="Eliminar Permanentemente" class="button button-danger" onclick="return confirm('¿Eliminar permanentemente?');">
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php muc_display_pagination($trash_page, $trash_media['total_pages'], 'trash_page'); ?>
        <?php else : ?>
            <p>No hay archivos en la papelera.</p>
        <?php endif; ?>
    </div>
    <?php
}

// Función para obtener los archivos en la papelera
function muc_get_trash_media() {
    return get_posts([
        'post_type' => 'attachment',
        'post_status' => 'trash',
        'posts_per_page' => -1
    ]);
}

// Función para manejar las solicitudes de mover, restaurar o eliminar archivos
add_action('admin_init', 'muc_handle_media_actions');

function muc_handle_media_actions() {
    if (isset($_POST['move_to_trash']) && check_admin_referer('muc_delete_media', 'muc_nonce')) {
        $media_id = intval($_POST['media_id']);
        wp_trash_post($media_id);
    } elseif (isset($_POST['restore_media']) && check_admin_referer('muc_restore_media', 'muc_nonce')) {
        $media_id = intval($_POST['media_id']);
        wp_untrash_post($media_id);
    } elseif (isset($_POST['delete_permanent']) && check_admin_referer('muc_delete_permanent', 'muc_nonce')) {
        $media_id = intval($_POST['media_id']);
        wp_delete_attachment($media_id, true);
    }
    wp_safe_redirect(admin_url('admin.php?page=media-usage-checker'));
    exit;
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

// Función para verificar el uso de archivos en la biblioteca de medios
function muc_check_media_usage() {
    global $wpdb;
    $media_items = get_posts([
       

