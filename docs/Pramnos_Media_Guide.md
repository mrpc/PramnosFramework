---
use_cases:
  - Handling a file or image upload
  - Generating thumbnails or processing images
  - Organising stored media, or querying its schema
  - Setting memory_limit for image processing on a constrained host
  - Asking for a thumbnail size and getting different dimensions back
---

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

if ($media->error !== false) {
    // The URL was refused or the fetch failed. $media->error says which.
}
```

#### `addRemoteImage()` is the one that leaves the machine

These two sit one line apart and are not the same operation. `addImage()` reads a file this
application already has. `addRemoteImage()` **makes a request from the server**, to an address that —
for every caller shape this method reads as being for, an importer, a «fetch the logo from their
website» button, a picture-by-URL field — came from outside.

That matters because the server can reach places whoever supplied the address cannot: a cloud
provider's metadata endpoint on `169.254.169.254`, an unauthenticated panel on loopback, the database
on a private address, the rest of the subnet. So three guards run here, and each one is a fetch this
method used to perform:

- **the address is checked and then dialled** through
  [`Pramnos\Security\OutboundUrl`](Pramnos_Security_Guide.md#fetching-a-url-somebody-else-chose): the host is resolved, every
  address inside this network is refused, only `http` and `https` are accepted, credentials in the URL
  are refused, and the connection is made to the address that was approved rather than resolving the
  name a second time. Redirects are not followed — a redirect is a second address, chosen by the
  server being fetched.
- **the body is capped** at `$media->remoteMaxBytes` (10 MB by default), mid-stream.
- **the type is read from the bytes**, not from the URL. The extension on disk now describes the
  content; it used to default to `jpg` for anything the URL did not spell out, and everything under
  `www/uploads/` is served back by the web server according to that extension.

SVG is deliberately not on the accepted list for a *remote* fetch: it is markup, it can carry script,
and served from your own origin that script is same-origin. An application that wants remote SVGs owns
that decision and can fetch and sanitise one itself, then hand the file to `addImage()`.

On refusal the object's `error` is set and nothing is written. It does not throw, because a bad URL in
an import of two thousand should not abort the import.

The request itself is one overridable method, `fetchRemote()`. It exists so the decisions *after* the
bytes arrive can be tested — the type read from the content, the extension that follows, the refusal of
an error status — because reaching them otherwise means a real outbound request. Overriding it in
application code means opting out of the host check, the byte cap and the redirect checking, so do not.

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

#### `0` means «skip this rendition»

For all six of `max`, `maxHeight`, `medium`, `mediumHeight`, `thumb` and `thumbHeight`. An axis set to
`0` is not consulted; both axes of a rendition at `0` means that rendition is not derived at all.

Worth stating because four of the six used to mean the reverse. The guard was
`$startWidth > $this->medium`, so `medium = 0` was true for every picture that has ever existed — and
the setting that reads as «no medium rendition» produced one for every upload, at `ResizeTools`'
120-pixel default, a width the caller never named. Only `max` read the way it looked.

#### «Store this, derive nothing»

```php
$media = new MediaObject();
$media->deriveNothing = true;      // I have my own resizer; be the library
$media->addImage($file, 'logos');
```

For an application that wants what this class is good at — the storage layout, the md5 dedupe, the
mime, `mediause` — and wants its own resizer to own the pixels. The `thumbnails` column still holds
`original`, `medium` and `thumb`, all three pointing at the untouched file: callers ask for those by
name, and the entries describe what is actually there.

#### `max` is storage; `maxRequest` is retrieval

```php
$media->max        = 0;      // do not rewrite what I store
$media->maxRequest = 2048;   // but a get() may ask for up to 2048px
```

Two different questions that used to share one number, and sharing it meant the answer to one broke
the other. `get()` passed `max` down to `ResizeTools` as an upper bound on the *request*, so with
`max = 0` every requested width was «over the ceiling» and `ResizeTools` substituted its 120-pixel
default: a 40×40 source asked for at 512 came back 512 with the defaults and **120 with `max = 0`**,
at any size, silently. The application that set `max = 0` to protect its originals lost sized
renditions and was not told.

`maxRequest` at `0` (the default) falls back to `max`, and then to `ResizeTools`' own ceiling, so
nothing changes for a store that never sets either.

#### Renditions are never larger than the source

```php
$media->get(512, 512);       // a 40x40 source answers 40x40
$media->allowUpscale = true; // …unless you actually want invented pixels
```

Asking for a size above the picture's own used to write that size, by stretching — 512×512 of blur on
disk, recorded as a rendition, served as though it were real, and *larger than the original it came
from*. Requests are clamped to the source's dimensions now, scaled by the limiting factor so the
requested aspect survives: a 40×40 source asked for 512×256 gives 40×20, not 40×40.

A `Thumbnail`'s `x`/`y` are also read back from the file that was written rather than left as what was
asked for. They diverge exactly when something went wrong, which is when a recorded size is worth
having.

#### A vector answers with itself, and an unreadable file with the original

```php
$svg = new MediaObject();
$svg->addImage('/path/to/logo.svg', 'logos');
$svg->isVector();            // true
$svg->get(128, 64);          // the SVG itself, at its own dimensions
```

An SVG is already every size, so the original *is* the rendition. What happened before is worth
knowing, because every check downstream accepted it: GD cannot decode SVG on an ordinary build, so
`ResizeTools` drew the source path onto a 500×100 white JPEG and *that* was stored as the rendition —
at a URL ending `.jpg`, recorded in `thumbnails` as the size that had been requested. A logo became a
picture of a file path, and nothing raised, because a JPEG of an error message is a valid JPEG.

The same now holds for any file GD cannot read — a truncated upload, a file whose extension lies.
`get()` returns the original, sets `$media->error`, and records **nothing**: a row pointing at a file
that was never created is worse than no row, because the next `get()` at that size finds it, fails the
`file_exists()` check, deletes it and saves. One write per request, for ever.

`mediatype` stays `1` for an SVG. It is an image, and every application's own `mediatype == 1` branch
means «this is a picture» — giving vectors a new number would quietly route them into whatever those
branches do with a type they do not know.

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

### Transparency, and the one place it used to be lost

A PNG keeps its alpha through resizing **and** through cropping. Both paths prepare their
canvases with `imagealphablending(false)` + `imagesavealpha(true)`, which is what stops GD
compositing a transparent pixel onto the opaque black a truecolor canvas starts as.

Two things to know when a thumbnail comes back with black where it should be see-through:

- **`exporttype` decides.** The alpha handling is applied when the output is PNG. A JPEG has
  no alpha to keep, so transparency flattens onto black there — which is GD's behaviour, not
  a bug, and the fix is to export PNG (or WebP) for images that need it.
- **The cropping path has an intermediate canvas.** The source is scaled onto it before it
  reaches the thumbnail, and until this release only the thumbnail was prepared — so a
  cropped PNG arrived with its transparent regions already black, while the same image
  resized without cropping was fine. That asymmetry is what it looked like from outside: a
  crop bug rather than an alpha one.

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

### What was asked for, and what came out

`get($width, $height)` is a request for a **box**, not a promise of those exact
dimensions. With `allowUpscale` off — the default — a source smaller than the box
comes back at the source's own size: `get(177, 222)` against a 150×150 image
answers 120×150, because inventing pixels and recording them as a rendition is
worse than a smaller image.

So a stored rendition carries both numbers:

| Property | Meaning |
|---|---|
| `x`, `y` | what the rendition actually is — the size of the file on disk |
| `requestedX`, `requestedY` | the box that produced it |

```php
$rendition = $media->get(177, 222);   // 150×150 source

$rendition->x;           // 120  — read this to lay out a page
$rendition->y;           // 150
$rendition->requestedX;  // 177  — this is what the cache is keyed on
$rendition->requestedY;  // 222
```

**Why both, rather than just the result.** The lookup has to find the entry again
from the same request, and the request is the only thing the caller has. Keyed on
the result, `get(177, 222)` never matched the 120×150 entry it had just written,
so every call rebuilt the image, appended another entry and saved the row — and
because the resizer prefixes a random number when the filename it wants already
exists, that was an unbounded number of files on disk and entries in the column,
for one image at one size.

`requestedX`/`requestedY` are `0` on entries written before they existed, and on
entries belonging to an application's own thumbnail class, which the reader hands
back unconverted. Those are matched on `x`/`y` as they always were, so nothing
stored has to be migrated — the `thumbnails` column is JSON of the declared
fields, and a field absent from a row reads back as the class default.

If you need the rendition to fill the box, ask for it:

```php
$media->allowUpscale = true;   // then x/y and requestedX/Y agree again
```

which is the right call when the caller is a template with a fixed frame, and the
wrong one when you are producing derivatives to store.

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

**Created by the shipped migration** — `Pramnos\Framework\Migrations\Core\CreateMediaTables`,
which builds both tables in one `up()` because `mediause.mediaid` has a cascading foreign key onto
`media.mediaid` and the parent has to exist first. Run `migrate` and they are there.

That migration is the definition. It did not exist until 1 September 2026: `MediaObject` arrived with
the framework's original import in 2020, and the migrations were reconstructed six years later from
an application that does not use it — so these two tables were never in the source that
reconstruction read. In the meantime three different shapes were written down (this guide's, the
framework test's, and the one actually running), and they disagreed on the type of half the columns.
Read the migration rather than any of them.

### `media`

One row per stored file. Twenty-three columns; the ones worth knowing:

| Column | Notes |
|---|---|
| `mediaid` | Signed auto-increment. **Not** `UNSIGNED` — `mediause.mediaid` is signed, and MySQL refuses a foreign key between columns that differ in signedness. |
| `md5` | Content hash. Indexed, because every upload looks it up before storing: `where md5 = %s and medialink = 0`. **Empty when the file could not be read** — see below. |
| `mimetype` | What the file actually is, read with `finfo` at upload. `''` when it could not be read. |
| `medialink` | When this row is a duplicate, the `mediaid` holding the real file. **`0` means "not a duplicate"** — a sentinel, which is why there is no foreign key here. |
| `userid` | The uploader, or **`0` for no signed-in user** — the same reason there is no key on it. |
| `order` | Display order, for emoticon sets. A reserved word in both backends; see below. |
| `thumbnails` | **JSON** of each thumbnail's fields. Rows written before 1 September 2026 hold PHP-serialised objects and are still read; see below. |
| `otherusers`, `othermodules` | `tinyint` flags: may other users / other modules see this file. |

### `mediause`

One row per *usage*, so one file can appear in many places — `users.photo` holds a `usageid`.

| Column | Notes |
|---|---|
| `usageid` | Signed auto-increment. |
| `mediaid` | Cascading foreign key onto `media.mediaid`, **both directions**. Deleting a file removes its usages; a usage naming no file is refused. |
| `module`, `specific` | Which record uses it. Indexed as a pair, because three separate queries filter on `module`, on `specific`, or on both. |
| `order` | Display order within the record that uses it — a gallery is ordered. |

### `mimetype`, and why it is not `mediatype`

`uploadFile()` reads the real type with `finfo` to decide whether the content matches the extension
— the check that refuses a PHP script named `holiday.jpg`. That value used to be used and thrown
away. Two things went with it: the security decision became unauditable, and anything serving the
file later had to re-guess the type from the extension, which is precisely the claim the check exists
to distrust.

`mediatype` is not a substitute. It is a display family — 1 image, 2 emoticon, 3 PDF, 0 other — and
cannot tell a png from a jpeg. It is what the class branches on ten times over; `mimetype` is what
the file is.

Both entry points fill it: `uploadFile()` from the check it already performs, and `addImage()` from
a detection of its own. `addImage()` gains no *validation* — it takes a file the application already
has, not one a visitor sent.

### An unreadable file gets an empty hash, not the hash of nothing

`file_get_contents()` on a missing file returns `false`, and `md5(false)` is `md5('')` —
`d41d8cd98f00b204e9800998ecf8427e`, **the same value for every missing file**. Since a re-upload is
found with `where md5 = %s and medialink = 0`, every file whose bytes had gone was a duplicate of
every other one, and the next upload could be linked to any of them.

A production library of 4,551 files held 14 rows carrying exactly that hash. `createMd5()` now leaves
the hash empty instead, which matches nothing — the honest answer for a file nobody can read.

### `thumbnails` holds JSON, and why it stopped holding objects

PHP's `serialize()` writes the **class name into the data**. That single fact is behind four separate
problems, and all four are gone now that the column holds JSON of the fields:

- **who can read a row depended on which classes the reading process had.** This framework on its
  own, a second application on the same database, a CLI script that skips the first application's
  autoloader — each got `__PHP_Incomplete_Class` and a fatal on the first property read.
- **renaming a property silently dropped every stored value**, because property names are in the
  payload too. No error, just an empty field from then on.
- **`unserialize()` instantiates classes and runs their magic methods.** Any write access to that
  column was a step towards code execution. (The framework already knew: `Helpers::checkUnserialize()`
  exists and passes `allowed_classes => false`.)
- **nothing outside PHP could read it.** No SQL, no `JSON_EXTRACT`, no reporting tool, and no index
  on «thumbnails wider than 500px».

`Thumbnail` is eight scalar properties with no methods and no nesting, so there is nothing about it
JSON cannot carry.

**No migration is needed.** The reader accepts both formats — a leading `[` distinguishes JSON from
`serialize()`'s `a:` — and the writer only produces JSON, so a row converts itself the next time its
media object is saved. Objects from a legacy row come back **as they are** rather than being recast
into `Thumbnail`: an application with its own thumbnail class has been getting its own type from
those rows for years, and changing that on read would alter what `getThumb()` hands its callers for
rows nobody has touched.

An unencodable payload — a filename in some encoding `json_encode()` refuses — falls back to
`serialize()` for that row and logs it. Losing the thumbnails would be worse, and the reader accepts
both anyway.

### A thumbnail the reading process cannot load is dropped, not fatal

`thumbnails` holds serialised objects, and the class name travels with them — so **who can read a row
depends on which classes that process has**. An application with its own thumbnail class reads its own
rows fine; the class is declared, the objects come back whole.

It breaks for a different reader: this framework on its own, a second application sharing the
database, a CLI process that never boots the first application's class aliases. There `unserialize()`
yields `__PHP_Incomplete_Class`, and `getThumb()` reads `$thumb->reason` on every entry — reading
*any* property of an incomplete class is a fatal error rather than a missing thumbnail.

Entries that cannot be read are now dropped on load and `getThumb()` falls back to an empty
`Thumbnail`, which it already did for a file with no thumbnails. `unserialize()` is deliberately
**not** restricted with `allowed_classes`: that would be better hardening and would also discard an
application's own thumbnail class that loads perfectly well today.

### `order` and `specific` are reserved words

Both are real column names here and both are reserved in MySQL and PostgreSQL. `MediaObject`'s own
queries are hand-written SQL with the quoting already in place, but anything going through the query
builder needs the grammar to quote them — which it now does, for these and the rest of the words that
turn up as column names. Before that, `where('order', 5)` compiled to `WHERE order = ?`, a syntax
error on both backends.

If you are naming a column, this still argues for avoiding a reserved word. These two are kept
because they are what is running.

### An installation that predates this migration

`hasTable()` guards both `createTable()` calls, so `migrate` leaves existing tables alone — it will
not touch a `media` that has been there for years. Such a table may differ from what the migration
now creates: character set, the index on `md5`, whether `(module, specific)` is indexed, and the width
of `filesize` and `date`. Reconciling that is the application's decision, not the framework's, and
nothing in the framework depends on it.

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

#### `memory_limit` while filling a thumbnail

Resampling with a `$fillcolor` other than black calls `imagefill()`, which on a large
image can want more memory than a constrained host allows. `ResizeTools` therefore raises
`memory_limit` to **at least 256 MB** for that one call and puts the old value back.

**It only ever raises it.** A host configured with 512 MB, or with no limit, is left
alone. That is worth knowing because it used to set 256 MB unconditionally, which on such
a host is a *reduction* — the opposite of the intent — and PHP refuses it outright once
the process is already using more:

```
Failed to set memory limit to 268435456 bytes (Current memory usage is 279969792 bytes)
```

So on a generous host the fill silently ran with **less** memory than the request already
had. Nothing to configure; the behaviour is simply correct now.

If 256 MB is not enough for the images an application handles, raise the host's own
`memory_limit` — anything at or above the floor is respected as-is.

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
- [Framework Guide](Pramnos_Framework_Guide.md) - Application structure
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
