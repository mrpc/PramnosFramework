# Pramnos Framework - Media System Guide

The Pramnos Framework includes a comprehensive media management system that handles file uploads, image processing, thumbnail generation, and media organization. The system provides automatic resizing, cropping, rotation, and supports various media types including images, documents, and PDFs.

## Table of Contents

1. [Overview](#overview)
2. [Basic Usage](#basic-usage)
3. [Media Types](#media-types)
4. [File Upload](#file-upload)
5. [Image Processing](#image-processing)
6. [Thumbnails](#thumbnails)
7. [Media Organization](#media-organization)
8. [Advanced Features](#advanced-features)
9. [Database Schema](#database-schema)
10. [API Reference](#api-reference)

## Overview

The Media system consists of several key components:

- **MediaObject** (`\Pramnos\Media\MediaObject`) - Main media management class
- **ResizeTools** (`\Pramnos\Media\ResizeTools`) - Image resizing and processing
- **Thumbnail** (`\Pramnos\Media\Thumbnail`) - Thumbnail representation
- **File Management** - Organized storage with automatic directory creation

### Key Features

- **Multi-format Support**: Images (JPG, PNG, GIF, BMP, ICO), PDFs, Office documents
- **Automatic Processing**: Thumbnail generation, image resizing, orientation fixing
- **Organized Storage**: Hierarchical directory structure by date and module
- **Usage Tracking**: Track media usage across different modules
- **Deduplication**: Automatic detection and linking of duplicate files
- **Image Manipulation**: Rotate, crop, resize with quality preservation
- **Responsive Images**: Multiple thumbnail sizes for different use cases

## Basic Usage

### Creating a MediaObject

```php
use Pramnos\Media\MediaObject;

// Create new media object
$media = new MediaObject();

// Set basic properties
$media->mediatype = 1;  // 1 = image
$media->name = 'Sample Image';
$media->description = 'A sample image for demonstration';
$media->module = 'gallery';
```

### Uploading Files

```php
// Upload from $_FILES
$media = new MediaObject();
$result = $media->uploadFile($_FILES['file'], 'gallery');

if ($media->error === false) {
    echo "File uploaded successfully! Media ID: " . $media->mediaid;
} else {
    echo "Upload failed: " . $media->error;
}
```

### Adding Existing Images

```php
// Add an existing image file
$media = new MediaObject();
$media->addImage('/path/to/existing/image.jpg', 'gallery', true); // true = delete original

// Add remote image
$media = new MediaObject();
$media->addRemoteImage('https://example.com/image.jpg', 'gallery');
```

## Media Types

The system supports different media types with specific handling:

```php
// Media type constants
$media->mediatype = 0; // Generic file
$media->mediatype = 1; // Image
$media->mediatype = 2; // Emoticon/small image
$media->mediatype = 3; // PDF document
$media->mediatype = 4; // Flash media (deprecated)
$media->mediatype = 5; // Video
```

### Type-Specific Processing

```php
// Images get automatic thumbnails and resizing
$imageMedia = new MediaObject();
$imageMedia->mediatype = 1;
$imageMedia->max = 1024;        // Max width/height
$imageMedia->medium = 600;      // Medium size
$imageMedia->thumb = 120;       // Thumbnail size

// PDFs get placeholder thumbnails
$pdfMedia = new MediaObject();
$pdfMedia->mediatype = 3;
```

## File Upload

### Basic File Upload

```php
// Handle form upload
if (isset($_FILES['upload'])) {
    $media = new MediaObject();
    
    // Set upload constraints
    $media->max = 2048;          // Max size: 2048px
    $media->medium = 800;        // Medium size: 800px
    $media->thumb = 150;         // Thumbnail: 150px
    $media->deleteOriginal = false; // Keep original
    
    $media->uploadFile($_FILES['upload'], 'user_uploads');
    
    if ($media->error === false) {
        $media->save();
        echo "Upload successful: " . $media->url;
    }
}
```

### Multiple File Upload

```php
// Fix multiple file upload array structure
\Pramnos\General\Helpers::fixFilesArray($_FILES);

foreach ($_FILES['uploads'] as $file) {
    $media = new MediaObject();
    $media->uploadFile($file, 'gallery');
    
    if ($media->error === false) {
        $media->save();
    }
}
```

### Upload Validation

```php
$media = new MediaObject();

// Set allowed file types
$media->mediatype = 1; // Images only

// Upload will automatically validate:
// - File type (by extension and MIME type)
// - File size (by server limits)
// - Image dimensions
// - Security (filename sanitization)

$result = $media->uploadFile($_FILES['file'], 'gallery');
```

## Image Processing

### Automatic Processing

```php
$media = new MediaObject();

// Set processing options
$media->max = 1920;              // Maximum dimension
$media->maxHeight = 1080;        // Maximum height
$media->medium = 800;            // Medium size
$media->mediumHeight = 600;      // Medium height
$media->thumb = 200;             // Thumbnail size
$media->thumbHeight = 150;       // Thumbnail height
$media->fixOrientation = true;   // Fix EXIF orientation

$media->uploadFile($_FILES['image'], 'gallery');
```

### Manual Image Processing

```php
// Load existing media
$media = new MediaObject();
$media->load(123);

// Process image with new settings
$media->processImage($media->filename, dirname($media->filename));
$media->save();
```

### Image Rotation

```php
$media = new MediaObject();
$media->load(123);

// Rotate image
$media->rotateLeft();   // 90 degrees left
$media->rotateRight();  // 90 degrees right
$media->rotate(45);     // Custom angle
```

## Thumbnails

### Automatic Thumbnail Generation

```php
// Thumbnails are created automatically during upload
$media = new MediaObject();
$media->uploadFile($_FILES['image'], 'gallery');

// Access thumbnails
foreach ($media->thumbnails as $thumbnail) {
    echo "Size: " . $thumbnail->x . "x" . $thumbnail->y . "\n";
    echo "URL: " . $thumbnail->url . "\n";
    echo "Reason: " . $thumbnail->reason . "\n"; // 'original', 'medium', 'thumb'
}
```

### Getting Specific Thumbnails

```php
$media = new MediaObject();
$media->load(123);

// Get predefined sizes
$thumb = $media->getThumb();     // Standard thumbnail
$medium = $media->getMedium();   // Medium size
$original = $media->getOriginal(); // Original size

// Get custom size (creates if doesn't exist)
$custom = $media->get(300, 200, true); // 300x200, cropped
```

### Custom Thumbnail Creation

```php
$media = new MediaObject();
$media->load(123);

// Create custom size thumbnail
$thumbnail = $media->get(
    400,        // Width
    300,        // Height
    true,       // Crop to exact size
    false,      // Don't force recreation
    false,      // No debug
    true        // Use resampling for quality
);

echo "Custom thumbnail URL: " . $thumbnail->url;
```

## Media Organization

### Module-Based Organization

```php
// Files are organized by module
$media = new MediaObject();
$media->uploadFile($_FILES['file'], 'gallery');    // Goes to /uploads/gallery/
$media->uploadFile($_FILES['file'], 'products');   // Goes to /uploads/products/
$media->uploadFile($_FILES['file'], 'blog');       // Goes to /uploads/blog/
```

### Date-Based Structure

The system automatically creates a hierarchical directory structure:

```
www/uploads/
├── gallery/
│   ├── 2024/
│   │   ├── 01/
│   │   │   ├── 15/
│   │   │   │   ├── image1.jpg
│   │   │   │   └── thumb_image1.jpg
│   │   │   └── 16/
│   │   └── 02/
│   └── 2023/
└── products/
    └── 2024/
```

### Usage Tracking

```php
// Track where media is used
$media = new MediaObject();
$media->load(123);

// Add usage
$media->addUsage(
    'blog',           // Module
    'post-456',       // Specific item ID
    'Featured Image', // Title
    'Main blog post image', // Description
    'featured,blog',  // Tags
    1                 // Order
);

// Get all usages
$usages = $media->getUsages('blog');
foreach ($usages as $usage) {
    echo "Used in: " . $usage->usageModule . " - " . $usage->usageSpecific;
}
```

## Advanced Features

### Deduplication

```php
// The system automatically detects duplicates by MD5 hash
$media = new MediaObject();
$media->uploadFile($_FILES['file'], 'gallery');

// If file already exists, $media->medialink will point to original
if ($media->medialink > 0) {
    echo "This file already exists as Media ID: " . $media->medialink;
}
```

### Media Linking

```php
// Get all media linked to the same original
$media = new MediaObject();
$media->load(123);

$linkedMedia = $media->getLinkedMedia();
foreach ($linkedMedia as $linked) {
    echo "Linked media ID: " . $linked->mediaid;
}
```

### Batch Operations

```php
// Update multiple media usages
MediaObject::multipleUsageUpdate(
    [123, 456, 789],  // Media IDs
    'gallery',        // Module
    'album-1'         // Specific ID
);

// Clear all usages for a module
$media = new MediaObject();
$media->clearUsage('old_module', 'item-123');
```

### Media Lists

```php
// Get media by type
$media = new MediaObject();
$imageList = $media->getList(1, 'gallery'); // Type 1 (images) from gallery module

// Get media by user
$userMedia = $media->getList(0, '', 123); // All types, any module, user ID 123
```

## Database Schema

### Main Media Table

```sql
CREATE TABLE media (
    mediaid INT AUTO_INCREMENT PRIMARY KEY,
    mediatype INT DEFAULT 0,
    userid INT DEFAULT 0,
    module VARCHAR(100),
    order_field INT DEFAULT 0,
    name VARCHAR(255),
    filename TEXT,
    url TEXT,
    shortcut VARCHAR(50),
    tags TEXT,
    date INT,
    views INT DEFAULT 0,
    thumbnails TEXT,
    filesize INT DEFAULT 0,
    description TEXT,
    x INT DEFAULT 0,
    y INT DEFAULT 0,
    usages INT DEFAULT 0,
    md5 VARCHAR(32),
    medialink INT DEFAULT 0,
    otherusers TINYINT DEFAULT 0,
    othermodules TINYINT DEFAULT 0
);
```

### Media Usage Table

```sql
CREATE TABLE mediause (
    usageid INT AUTO_INCREMENT PRIMARY KEY,
    mediaid INT,
    module VARCHAR(100),
    specific VARCHAR(255),
    date INT,
    title VARCHAR(255),
    description TEXT,
    tags TEXT,
    order_field INT DEFAULT 0,
    FOREIGN KEY (mediaid) REFERENCES media(mediaid)
);
```

## API Reference

### MediaObject Class Methods

#### Upload Methods
- `uploadFile($file, $module, $type)` - Upload file from $_FILES
- `uploadImage($file, $module)` - Upload image file
- `addImage($filepath, $module, $deleteOriginal)` - Add existing image
- `addRemoteImage($url, $module)` - Download and add remote image

#### Processing Methods
- `processImage($file, $path)` - Process uploaded image
- `rotate($degrees)` - Rotate image by degrees
- `rotateLeft()` - Rotate 90 degrees left
- `rotateRight()` - Rotate 90 degrees right

#### Thumbnail Methods
- `get($width, $height, $crop, $force, $debug, $resample)` - Get/create thumbnail
- `getThumb()` - Get standard thumbnail
- `getMedium()` - Get medium size image
- `getOriginal()` - Get original size image

#### Database Methods
- `load($mediaid)` - Load media by ID
- `save($force)` - Save media to database
- `delete()` - Delete media and files

#### Usage Methods
- `addUsage($module, $specific, $title, $description, $tags, $order)` - Add usage
- `getUsages($module, $specific, $removeDuplicates)` - Get usage list
- `clearUsage($module, $specific, $safe)` - Remove usages
- `removeUsage($usageid, $safe)` - Remove specific usage

#### Utility Methods
- `createMd5()` - Generate MD5 hash of file
- `getList($type, $module, $userid)` - Get media list

### ResizeTools Class Methods

#### Main Methods
- `resize($src, $width, $height)` - Resize image to dimensions
- `display($src, $width, $height)` - Output resized image directly

#### Configuration Properties
- `$maxsize` - Maximum allowed dimension (default: 1024)
- `$defaultwidth` - Default width when not specified (default: 120)
- `$crop` - Allow cropping when both dimensions set (default: true)
- `$resample` - Use resampling for quality (default: true)
- `$fillcolor` - Background fill color for resampling (default: "FFFFFF")
- `$debug` - Enable debug output (default: false)

### Thumbnail Class Properties

- `$filename` - Full file path
- `$url` - Web-accessible URL
- `$x` - Width in pixels
- `$y` - Height in pixels
- `$filesize` - File size in bytes
- `$views` - View counter
- `$reason` - Creation reason ('original', 'medium', 'thumb', 'custom')

## Best Practices

### 1. File Upload Security

```php
// Always validate uploads
$media = new MediaObject();
$media->mediatype = 1; // Restrict to images only

// Set reasonable size limits
$media->max = 2048;
$media->medium = 800;

// Check for errors after upload
if ($media->uploadFile($_FILES['file'], 'gallery') && $media->error === false) {
    $media->save();
}
```

### 2. Performance Optimization

```php
// Use appropriate thumbnail sizes
$media = new MediaObject();
$media->thumb = 150;      // For listing pages
$media->medium = 600;     // For detail views
$media->max = 1920;       // For full-size display

// Lazy load thumbnails
$thumbnail = $media->get(200, 200, false, false); // Don't force recreation
```

### 3. Storage Management

```php
// Regular cleanup of unused media
$media = new MediaObject();
$unusedMedia = $media->getList(0, '', ''); // Get all media

foreach ($unusedMedia as $item) {
    if ($item->usages == 0) {
        // Consider for deletion after grace period
        if ($item->date < (time() - (30 * 24 * 3600))) { // 30 days old
            $item->delete();
        }
    }
}
```

### 4. Error Handling

```php
try {
    $media = new MediaObject();
    $media->uploadFile($_FILES['file'], 'gallery');
    
    if ($media->error !== false) {
        throw new Exception("Upload failed: " . $media->error);
    }
    
    $media->save();
} catch (Exception $e) {
    \Pramnos\Logs\Logger::log("Media upload error: " . $e->getMessage());
    // Handle error appropriately
}
```

## Related Documentation

- [Framework Guide](Pramnos_Framework_Guide.md) - Core framework concepts
- [Database Guide](Pramnos_Database_API_Guide.md) - Database operations
- [Theme Guide](Pramnos_Theme_Guide.md) - Media display in themes
- [Application Guide](Pramnos_Application_Guide.md) - Application structure
- [Logging Guide](Pramnos_Logging_Guide.md) - Error logging and debugging

## Troubleshooting

### Common Issues

1. **Upload Failures**
   - Check file permissions on upload directory
   - Verify PHP upload limits (upload_max_filesize, post_max_size)
   - Ensure sufficient disk space

2. **Thumbnail Generation Issues**
   - Verify GD extension is installed
   - Check memory limits for large images
   - Ensure write permissions on thumbnail directories

3. **File Not Found Errors**
   - Verify file paths are correct
   - Check that files weren't manually deleted
   - Use the path fixing functionality for migrated sites

4. **Performance Issues**
   - Optimize image sizes before upload
   - Use appropriate thumbnail sizes
   - Consider CDN for large media libraries

For additional debugging, enable debug mode on ResizeTools and check the application logs for detailed error information.
