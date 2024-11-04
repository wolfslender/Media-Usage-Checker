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
