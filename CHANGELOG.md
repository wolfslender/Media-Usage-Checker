# Changelog

## [2.8.1] - 2025-05-18

### Added
- Documentación completa para desarrolladores en `docs/DEVELOPER.md`
- Ejemplo de integración personalizada en `examples/custom-integration.php`
- Sistema mejorado de manejo de errores y logs
- Soporte para filtros y acciones personalizadas
- Documentación de la API REST

### Fixed
- Corregidos errores de sintaxis en el archivo principal
- Mejorado el manejo de errores en el escaneo de archivos
- Optimizado el rendimiento del escaneo
- Corregidos problemas de seguridad en las verificaciones de capacidad del usuario

### Changed
- Actualizada la documentación del plugin
- Mejorada la estructura del código para mayor mantenibilidad
- Optimizado el sistema de caché

## [2.8.0] - 2025-05-18

### Added
- Detección mejorada de archivos en uso:
  - Soporte completo para temas personalizados
  - Verificación en widgets y menús
  - Detección en CSS personalizado
  - Análisis de logos e íconos del sitio
  - Comprobación en áreas de widgets
- Diseño responsive y mobile-first:
  - Interfaz adaptativa
  - Soporte para dispositivos móviles
  - Animaciones suaves
  - Mejor experiencia de usuario
- Soporte multilenguaje:
  - Inglés por defecto
  - Sistema de traducciones mejorado
  - Textos consistentes
- Mejoras en rendimiento:
  - Optimización de consultas SQL
  - Sistema de caché mejorado
  - Procesamiento por lotes optimizado
  - Mejor gestión de recursos

### Changed
- Rediseño completo de la interfaz de usuario
- Sistema de detección de archivos optimizado
- Mejora en la gestión de memoria
- Actualización del sistema de procesamiento por lotes
- Optimización de consultas a la base de datos
- Mejora en el sistema de caché

### Fixed
- Problemas de timeout del servidor
- Optimización del uso de memoria
- Consistencia de la interfaz de usuario
- Problemas de visualización en móviles
- Contraste de colores en la sección de configuración
- Precisión en la detección de archivos

### Security
- Validación mejorada de tipos de archivo
- Verificación de permisos de usuario
- Mejor sanitización de entradas
- Proceso seguro de eliminación de archivos

### Performance
- Reducción de carga del servidor
- Optimización de consultas
- Sistema de caché mejorado
- Mejor gestión de recursos
- Procesamiento por lotes mejorado

### Technical
- Refactorización del código
- Actualización a estándares de WordPress
- Mejora en la documentación
- Sistema de registro de errores mejorado

## [2.6.0] - 2025-05-17

### Added
- Enhanced cache system for faster media checks
- Improved batch processing with increased batch size (100 items)
- Preview button for used media files
- Real-time progress monitoring system
- Advanced error logging and reporting
- Object caching for media check results

### Changed
- Optimized SQL queries for better performance
- Reduced inter-batch sleep time to 100ms
- Enhanced memory management during scans
- Improved background processing system
- Updated user interface with better progress indicators
- Enhanced file preview functionality

### Fixed
- Memory handling for large media libraries
- Background process status monitoring
- Cache invalidation on media updates
- Query performance in large databases
- Progress indication accuracy
- File path validation

## [2.5.9] - 2024-11-08

### Added
- Enhanced customizer support with extensive checking for:
  - Site logos (all variants)
  - Favicons and site icons
  - Header and footer images
  - Background images
  - Theme customizer options
- Support for compressed files:
  - ZIP archives
  - RAR files
  - 7Z archives
  - TAR files
  - GZIP files
- Support for executable files
- New JavaScript optimizations:
  - Enhanced bulk selection handling
  - Improved delete confirmations
  - Responsive table handling
  - Better event management
  - Smooth animations
- Comprehensive CSS improvements:
  - CSS variables for easy theming
  - Enhanced responsive design
  - Custom checkboxes
  - Improved notifications
  - Tooltips system
  - Better table layouts
  - Mobile-first approach

### Changed
- Restructured asset loading system
- Improved file type detection
- Enhanced media verification process
- Optimized customizer checks
- Updated responsive behavior

### Fixed
- Customizer image detection issues
- Theme modification checks
- Widget image detection
- Menu item image handling
- Asset loading efficiency
- Mobile display issues

### Technical
- Added version-based cache busting
- Implemented strict type checking
- Enhanced security headers
- Improved error handling
- Better memory management

## [2.3.7] - 2024-11-07

### Added
- New function `muc_get_file_type_text()` that determines the file type based on its MIME type.
- Support for different common file types, including:
  - Images
  - Videos
  - Audio files
  - PDFs
  - Word documents
  - Excel spreadsheets
  - PowerPoint presentations
- Displays a generic "View file" text for unrecognized file types.

### Changed
- Preserved existing functionality, but now displays more specific text depending on the file type.

## [2.3.7] - 2024-11-07

### Added
- New **"Preview"** column in the table to display a thumbnail of the image.
- **"View Image"** button that opens the image in a new tab for easier access.

### Changed
- The button uses WordPress styling classes to maintain a consistent design.

### Fixed
- All existing functionalities have been preserved, ensuring application stability and consistency.

## [2.3.5] - 2024-11-1

### Added
- Implemented unlimited file processing by removing time and size limits.
- Enhanced background processing management to handle large media libraries.
- Integrated continuous batch scheduling for efficient handling of extensive media collections.

### Changed
- Updated memory and execution time configurations to support higher allocations and remove runtime restrictions.
- Optimized media verification functions to improve performance on large data sets.
- Improved the `muc_background_check` function to ensure continuity in file processing.

### Fixed
- Resolved issues causing interruptions during large-scale verifications to ensure continuous processing.
- Fixed timeout errors on servers with high file volumes, enhancing plugin stability.
- Improved exception handling to prevent individual errors from stopping full processing.

## [2.3.2] - 2024-11-01

### Added
- Implemented physical file deletion from uploads
- Added additional pre/post deletion verification
- Integrated file path validation

### Changed
- Enhanced `muc_handle_media_deletion()` function
- Updated `muc_force_delete_attachment_file()` function
- Optimized metadata cleanup process

### Fixed
- Fixed persistence of physical files in uploads
- Improved cache cleanup process
- Resolved permission issues in deletion

## [2.3.0] - 2024-11-01

### Added
- Added "Upload Date" column in both tables
- Enabled sorting by upload date
- Enhanced date display

### Changed
- Updated `muc_admin_page()` function
- Optimized temporal information display
- Improved date formatting

## [2.2.0] - 2024-10-31

### Added
- Implemented batch processing
- Added background processing with WP Cron
- New option for manual checks
- Optimized result storage

### Changed
- Optimized admin page
- Updated pagination
- Enhanced error handling

### Fixed
- Resolved server timeouts
- Optimized memory usage
- Improved overall performance

## [2.1.0] - 2024-10-30

### Added
- Batch processing system
- Background checks
- Manual verification option
- Result storage

### Changed
- General optimization
- Improved pagination
- Better error handling

### Fixed
- Fixed timeouts
- Memory optimization
- Performance improvements

## [2.0.0] - 2024-10-29

### Changed
- Removed custom trash bin
- Implemented direct deletion
- Updated user interface

### Added
- Deletion confirmation
- Batch deletion
- Usability enhancements

### Removed
- Trash page
- Trash management functions
- Restoration system

## [1.2.1] - 2024-10-26

### Changed
- Corrected post-restore redirection
- Improved permission management

### Fixed
- Restore permission error
- Enhanced ID validation
- Optimized redirections

## [1.2.0] - 2024-10-24

### Added
- Pagination in trash
- Permanent deletion
- Interface improvements

### Changed
- Optimized deletion logic
- Improved redirection system

### Fixed
- Permission errors
- Redirection issues
- Pagination handling

## [1.1.0] - 2024-10-22

### Added
- Initial trash functionality
- Basic management system
- Initial user interface
