# Developer Guide - Media Usage Checker

## Introduction

Welcome to the Media Usage Checker developer documentation. This document provides information on how to extend and customize the plugin according to your needs.

## Table of Contents

1. [Available Filters](#available-filters)
2. [Available Actions](#available-actions)
3. [REST API](#rest-api)
4. [Usage Examples](#usage-examples)
5. [Best Practices](#best-practices)

## Available Filters

### `muc_allowed_mime_types`
Filters the allowed MIME types during scanning.

**Parameters:**
- `$allowed_types` (array) - Default allowed MIME types

**Example:**
```php
add_filter('muc_allowed_mime_types', function($types) {
    // Add a new MIME type
    $types['my_custom_type'] = 'application/my-custom-type';
    return $types;
});
```

### `muc_scan_batch_size`
Modifies the scan batch size.

**Parameters:**
- `$batch_size` (int) - Batch size (default: 100)

### `muc_scan_frequency`
Changes the scheduled scan frequency.

**Parameters:**
- `$frequency` (string) - Frequency (hourly, twicedaily, daily)

## Available Actions

### `muc_before_scan`
Runs before starting a scan.

### `muc_after_scan`
Runs after completing a scan.

**Parameters:**
- `$stats` (array) - Scan statistics

### `muc_before_delete`
Runs before deleting files.

**Parameters:**
- `$media_ids` (array) - IDs of media to be deleted

### `muc_after_delete`
Runs after deleting files.

**Parameters:**
- `$deleted_ids` (array) - IDs of deleted media

## REST API

The plugin provides a REST endpoint to get the current status:

### GET /wp-json/media-usage-checker/v1/status

**Example Response:**
```json
{
  "status": "success",
  "data": {
    "version": "2.8.0",
    "total_files": 150,
    "last_scan": "2025-05-18 12:00:00",
    "next_scheduled": 1652880000,
    "memory_usage": "64.5 MB",
    "max_execution_time": 300,
    "memory_limit": "512M"
  }
}
```

## Usage Examples

### 1. Add a new custom file type

```php
// In your plugin or theme's functions.php
add_filter('muc_allowed_mime_types', function($types) {
    $types['my_custom_type'] = 'application/my-custom-type';
    return $types;
});
```

### 2. Schedule an action after scanning

```php
add_action('muc_after_scan', function($stats) {
    // Enviar notificación por correo electrónico
    $to = get_option('admin_email');
    $subject = 'Escaneo de medios completado';
    $message = sprintf(
        'Se completó el escaneo de medios.\n\nTotal: %d\nUsados: %d\nNo usados: %d',
        $stats['total'],
        $stats['used'],
        $stats['unused']
    );
    
    wp_mail($to, $subject, $message);
});
```

## Buenas Prácticas

1. **Seguridad:**
   - Siempre valida y sanea los datos de entrada
   - Usa nonces para las acciones del usuario
   - Verifica las capacidades del usuario

2. **Rendimiento:**
   - Usa transients para almacenar resultados costosos
   - Limita las consultas a la base de datos
   - Usa paginación para grandes conjuntos de datos

3. **Compatibilidad:**
   - Prueba con diferentes versiones de WordPress
   - Verifica la compatibilidad con otros plugins populares
   - Sigue los estándares de codificación de WordPress

## Soporte

Para reportar errores o solicitar características, por favor crea un issue en el [repositorio de GitHub](https://github.com/tu-usuario/media-usage-checker/issues).

## Licencia

GPLv2 o posterior
