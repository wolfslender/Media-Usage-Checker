<?php
/*
Plugin Name: Media Usage Checker
Description: Identifica qué archivos de la biblioteca de medios están en uso en el contenido de WordPress.
Version: 1.0
Author: Alexis
Author URI: https://www.oliverodev.com/
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
        <p>Esta herramienta te permite identificar archivos en la biblioteca de medios que están o no en uso en tu contenido de WordPress.</p>

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
                    <li><?php echo esc_html($media->post_title); ?> (ID: <?php echo esc_html($media->ID); ?>)</li>
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
?>
