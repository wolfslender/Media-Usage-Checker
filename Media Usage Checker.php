<?php
/*
Plugin Name: Media Usage Checker
Plugin URI: https://www.olivero.com/
Description: Identifica qué archivos de la biblioteca de medios están en uso en el contenido de WordPress y permite eliminar los que no se usan.
Version: 1.1
Author: Alexis Olivero
author_uri: https://www.olivero.com/
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

// Función que muestra el contenido de la página del plugin
function muc_admin_page() {
    ?>
    <div class="wrap">
        <h1>Media Usage Checker</h1>
        <p>Esta herramienta te permite identificar y eliminar archivos en la biblioteca de medios que no están en uso en tu contenido de WordPress.</p>

        <?php
        // Obtener los archivos en uso y no en uso
        $media_usage = muc_check_media_usage();
        $used_media = $media_usage['used'];
        $unused_media = $media_usage['unused'];
        ?>

        <h2>Archivos en Uso</h2>
        <?php if (!empty($used_media)) : ?>
            <ul>
                <?php foreach ($used_media as $media) : ?>
                    <li><?php echo esc_html($media->post_title); ?> (ID: <?php echo esc_html($media->ID); ?>)</li>
                <?php endforeach; ?>
            </ul>
        <?php else : ?>
            <p>No se encontraron archivos en uso.</p>
        <?php endif; ?>

        <h2>Archivos No en Uso</h2>
        <?php if (!empty($unused_media)) : ?>
            <ul>
                <?php foreach ($unused_media as $media) : ?>
                    <?php
                    // Obtener el tamaño del archivo en MB
                    $file_path = get_attached_file($media->ID);
                    $file_size = file_exists($file_path) ? filesize($file_path) / 1024 / 1024 : 0;
                    $file_size = round($file_size, 2); // Redondear a 2 decimales
                    ?>
                    <li>
                        <?php echo esc_html($media->post_title); ?> (ID: <?php echo esc_html($media->ID); ?>) -
                        Tamaño: <?php echo esc_html($file_size); ?> MB

                        <!-- Botón para eliminar -->
                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field('muc_delete_media', 'muc_nonce'); ?>
                            <input type="hidden" name="media_id" value="<?php echo esc_attr($media->ID); ?>">
                            <input type="submit" name="delete_media" value="Eliminar" class="button button-danger" onclick="return confirm('¿Estás seguro de que deseas eliminar este archivo?');">
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else : ?>
            <p>No se encontraron archivos sin uso.</p>
        <?php endif; ?>
    </div>
    <?php
}

// Función para verificar el uso de archivos en la biblioteca de medios
function muc_check_media_usage() {
    global $wpdb;

    // Obtener todos los archivos de la biblioteca de medios
    $media_items = get_posts([
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'posts_per_page' => -1
    ]);

    $unused_media = [];
    $used_media = [];

    // Verificar el uso de cada archivo en el contenido de WordPress
    foreach ($media_items as $media) {
        $media_id = $media->ID;
        $media_url = wp_get_attachment_url($media_id);

        // Verificar si el archivo está en uso en cualquier contenido
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

    // Retornar ambos resultados
    return [
        'used' => $used_media,
        'unused' => $unused_media
    ];
}

// Acción para manejar la eliminación de archivos no utilizados
add_action('admin_init', 'muc_handle_media_deletion');

function muc_handle_media_deletion() {
    if (isset($_POST['delete_media']) && isset($_POST['media_id']) && check_admin_referer('muc_delete_media', 'muc_nonce')) {
        $media_id = intval($_POST['media_id']);

        // Eliminar el archivo de la biblioteca de medios
        wp_delete_attachment($media_id, true);

        // Redirigir para evitar el reenvío de formularios
        wp_safe_redirect(admin_url('admin.php?page=media-usage-checker'));
        exit;
    }
}
?>
