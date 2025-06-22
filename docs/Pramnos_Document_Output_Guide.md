# Pramnos Framework - Document & Output System Guide

The Pramnos Framework includes a sophisticated document and output system that handles multiple output formats, theming, asset management, and content rendering. This guide covers the complete document system from basic usage to advanced customization.

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Document Types and Formats](#document-types-and-formats)
3. [Basic Document Usage](#basic-document-usage)
4. [Asset Management](#asset-management)
5. [Theme Integration](#theme-integration)
6. [Multi-Format Output](#multi-format-output)
7. [Meta Tags and SEO](#meta-tags-and-seo)
8. [Content Parsing and Processing](#content-parsing-and-processing)
9. [Advanced Features](#advanced-features)
10. [Best Practices](#best-practices)

## Architecture Overview

The document system consists of several key components:

```
src/Pramnos/Document/
├── Document.php              # Base document class
└── DocumentTypes/           # Format-specific implementations
    ├── Html.php             # HTML document type
    ├── Amp.php              # AMP (Accelerated Mobile Pages)
    ├── Pdf.php              # PDF document generation
    ├── Png.php              # PNG image output
    ├── Rss.php              # RSS feed generation
    └── Rss/
        └── Item.php         # RSS item class
```

### Key Features

- **Multiple Output Formats**: HTML, AMP, PDF, RSS, PNG, JSON, XML
- **Asset Management**: JavaScript and CSS dependency management
- **Theme Integration**: Seamless theming and template system
- **SEO Optimization**: Meta tags, Open Graph, and structured data
- **Content Processing**: Parsing, filtering, and transformation
- **Responsive Design**: Mobile-first and AMP support

## Document Types and Formats

### Available Document Types

```php
// Get document instance for different formats
$htmlDoc = \Pramnos\Framework\Factory::getDocument('html');
$pdfDoc = \Pramnos\Framework\Factory::getDocument('pdf');
$rssDoc = \Pramnos\Framework\Factory::getDocument('rss');
$ampDoc = \Pramnos\Framework\Factory::getDocument('amp');

// JSON/XML via content type setting
$doc = \Pramnos\Framework\Factory::getDocument();
$doc->setType('json');
```

### HTML Document Type

```php
$doc = \Pramnos\Framework\Factory::getDocument('html');

// Set basic properties
$doc->title = 'Page Title';
$doc->description = 'Page description for SEO';
$doc->setGenerator('Pramnos Framework');

// Add content
$doc->addContent('<h1>Hello World</h1>');

// Add HTML-specific attributes
$doc->extraHtmlTag = 'data-theme="dark"';
$doc->extraBodyTag = 'onload="initPage()"';

// Render the document
echo $doc->render();
```

### PDF Document Type

```php
$doc = \Pramnos\Framework\Factory::getDocument('pdf');

// Set PDF properties
$doc->title = 'Report Title';
$doc->setAuthor('Your Name');
$doc->setSubject('Monthly Report');

// Add content (HTML is automatically converted)
$doc->addContent('
    <h1>Monthly Report</h1>
    <table border="1">
        <tr><th>Metric</th><th>Value</th></tr>
        <tr><td>Sales</td><td>$10,000</td></tr>
    </table>
');

// Output PDF (will trigger download)
$doc->render();
```

### RSS Feed Type

```php
$doc = \Pramnos\Framework\Factory::getDocument('rss');

// Set feed properties
$doc->title = 'My Blog RSS Feed';
$doc->description = 'Latest blog posts';
$doc->link = 'https://example.com';

// Create RSS items
$item = new \Pramnos\Document\DocumentTypes\Rss\Item();
$item->title = 'Blog Post Title';
$item->description = 'Post excerpt or summary';
$item->link = 'https://example.com/blog/post-slug';
$item->pubDate = date('r'); // RFC 2822 date format

// Add item to feed
$doc->addItem($item);

// Output RSS XML
echo $doc->render();
```

### AMP Document Type

```php
$doc = \Pramnos\Framework\Factory::getDocument('amp');

// Set AMP properties
$doc->title = 'AMP Page Title';
$doc->canonical = 'https://example.com/original-page'; // Original page URL

// AMP-specific meta tags
$doc->og_image = 'https://example.com/image.jpg';

// Add AMP-compatible content
$doc->addContent('
    <h1>AMP Page</h1>
    <amp-img src="/image.jpg" width="600" height="400" layout="responsive"></amp-img>
');

echo $doc->render();
```

## Basic Document Usage

### Setting Document Properties

```php
$doc = \Pramnos\Framework\Factory::getDocument();

// Basic properties
$doc->title = 'Page Title';
$doc->description = 'Page description for search engines';
$doc->setGenerator('Pramnos Framework');
$doc->setLanguage('en');
$doc->setCharset('UTF-8');

// URL and canonical
$doc->url = 'https://example.com/current-page';

// Content modification date
$doc->mdate = date('c'); // ISO 8601 format
```

### Adding Content

```php
// Add content to document
$doc->addContent('<h1>Main Content</h1>');
$doc->addContent('<p>Additional content paragraph.</p>');

// Set content directly
$doc->setContent('<div>Complete page content</div>');

// Get current content
$currentContent = $doc->getContent();
```

### Document Rendering

```php
// Basic rendering
$output = $doc->render();

// Render and output
echo $doc->render();

// Check document type
if ($doc->getType() === 'html') {
    // HTML-specific processing
}
```

## Asset Management

### JavaScript Management

```php
$doc = \Pramnos\Framework\Factory::getDocument();

// Register scripts with dependencies
$doc->registerScript(
    'my-script',                                    // Handle
    '/assets/js/my-script.js',                     // Source URL
    ['jquery', 'jquery-ui'],                       // Dependencies
    '1.0.0',                                       // Version
    true                                           // Load in footer
);

// Enqueue scripts for loading
$doc->enqueueScript('my-script');
$doc->enqueueScript('jquery'); // Dependencies loaded automatically

// Add inline scripts
$doc->addScriptDeclaration('
    window.addEventListener("load", function() {
        console.log("Page loaded");
    });
');
```

### CSS Management

```php
// Register stylesheets
$doc->registerStyle(
    'my-styles',                                   // Handle
    '/assets/css/my-styles.css',                  // Source URL
    ['bootstrap'],                                 // Dependencies
    '1.0.0',                                      // Version
    'all'                                         // Media type
);

// Enqueue stylesheets
$doc->enqueueStyle('my-styles');

// Add inline styles
$doc->addStyleDeclaration('
    .custom-class {
        background-color: #f0f0f0;
        padding: 20px;
    }
');
```

### Default Framework Assets

```php
// Framework provides pre-registered assets
$doc->enqueueScript('jquery');          // jQuery 2.2.4
$doc->enqueueScript('jquery-ui');       // jQuery UI
$doc->enqueueScript('datatables');      // DataTables
$doc->enqueueScript('bootstrap-datepicker');
$doc->enqueueScript('jquery-inputmask');

// CSS frameworks
$doc->enqueueStyle('jquery-ui');
$doc->enqueueStyle('bootstrap');
$doc->enqueueStyle('datatables');
```

### Asset Dependencies

```php
// Complex dependency example
$doc->registerScript('app-core', '/js/core.js', ['jquery'], '1.0');
$doc->registerScript('app-utils', '/js/utils.js', ['app-core'], '1.0');
$doc->registerScript('app-main', '/js/main.js', ['app-utils', 'datatables'], '1.0');

// Only need to enqueue the main script - dependencies load automatically
$doc->enqueueScript('app-main');
```

## Theme Integration

### Using Themes

```php
// Get document instance
$doc = \Pramnos\Framework\Factory::getDocument();

// Theme is automatically loaded based on application settings
// Access theme object
$theme = $doc->themeObject;

if ($theme) {
    // Check theme capabilities
    if ($theme->allowsViewOverrides()) {
        // Theme supports view overrides
    }
    
    // Get theme settings
    $customSetting = $theme->getSetting('custom_option');
    
    // Set content type for theme
    $theme->setContentType('single'); // page, single, archive, etc.
}
```

### Theme Content Integration

```php
// In controllers, content is automatically integrated with theme
class PageController extends \Pramnos\Application\Controller
{
    public function display()
    {
        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Page Title';
        
        $view = $this->getView('Page');
        return $view->display('content');
        
        // Theme automatically wraps view content with header/footer
    }
}
```

### Disabling Theme

```php
// Disable theme for specific output (like AJAX responses)
$doc = \Pramnos\Framework\Factory::getDocument();
$doc->usetheme = false;

// Or set document type that doesn't use themes
$doc->setType('json');
```

## Multi-Format Output

### Format Detection and Switching

```php
// Automatic format detection from URL/request
// Example URLs:
// /page.html     -> HTML output
// /page.pdf      -> PDF output  
// /page.rss      -> RSS output
// /page?format=json -> JSON output

// Manual format switching
$format = \Pramnos\Http\Request::staticGet('format', 'html', 'get');

switch ($format) {
    case 'pdf':
        $doc = \Pramnos\Framework\Factory::getDocument('pdf');
        break;
    case 'rss':
        $doc = \Pramnos\Framework\Factory::getDocument('rss');
        break;
    case 'json':
        $doc = \Pramnos\Framework\Factory::getDocument('html');
        $doc->setType('json');
        break;
    default:
        $doc = \Pramnos\Framework\Factory::getDocument('html');
}
```

### JSON Output

```php
// For API responses
$doc = \Pramnos\Framework\Factory::getDocument();
$doc->setType('json');
$doc->usetheme = false;

// Add JSON data
$data = [
    'status' => 'success',
    'data' => $results,
    'message' => 'Operation completed'
];

$doc->setContent(json_encode($data, JSON_PRETTY_PRINT));

// Set appropriate headers
if (!headers_sent()) {
    header('Content-Type: application/json');
}

echo $doc->render();
```

### XML Output

```php
$doc = \Pramnos\Framework\Factory::getDocument();
$doc->setType('xml');
$doc->usetheme = false;

$xml = '<?xml version="1.0" encoding="UTF-8"?>
<response>
    <status>success</status>
    <data>
        <item id="1">Sample data</item>
    </data>
</response>';

$doc->setContent($xml);

if (!headers_sent()) {
    header('Content-Type: text/xml');
}

echo $doc->render();
```

## Meta Tags and SEO

### Basic SEO Meta Tags

```php
$doc = \Pramnos\Framework\Factory::getDocument();

// Standard meta tags
$doc->title = 'Page Title - Site Name';
$doc->description = 'Compelling page description under 160 characters';

// Additional meta tags
$doc->addMetaName('keywords', 'keyword1, keyword2, keyword3');
$doc->addMetaName('author', 'Author Name');
$doc->addMetaName('robots', 'index, follow');
$doc->addMetaName('viewport', 'width=device-width, initial-scale=1.0');
```

### Open Graph Meta Tags

```php
// Open Graph for social media sharing
$doc->og_title = 'Social Media Title';
$doc->og_description = 'Description for social media';
$doc->og_image = 'https://example.com/image.jpg';
$doc->og_url = 'https://example.com/current-page';
$doc->og_type = 'article'; // article, website, video, etc.
$doc->og_site_name = 'Site Name';

// Additional Open Graph properties
$doc->addMeta('og:article:author', 'Author Name');
$doc->addMeta('og:article:published_time', '2024-01-01T00:00:00Z');
```

### Twitter Card Meta Tags

```php
// Twitter-specific meta tags
$doc->addMetaName('twitter:card', 'summary_large_image');
$doc->addMetaName('twitter:site', '@yourusername');
$doc->addMetaName('twitter:creator', '@authorusername');
$doc->addMetaName('twitter:title', 'Twitter Title');
$doc->addMetaName('twitter:description', 'Twitter description');
$doc->addMetaName('twitter:image', 'https://example.com/twitter-image.jpg');
```

### Schema.org Structured Data

```php
// JSON-LD structured data
$structuredData = [
    '@context' => 'https://schema.org',
    '@type' => 'Article',
    'headline' => 'Article Title',
    'author' => [
        '@type' => 'Person',
        'name' => 'Author Name'
    ],
    'datePublished' => '2024-01-01',
    'dateModified' => '2024-01-15',
    'image' => 'https://example.com/article-image.jpg'
];

$doc->addScriptDeclaration(
    'var structuredData = ' . json_encode($structuredData) . ';',
    'application/ld+json'
);
```

## Content Parsing and Processing

### Content Filters and Parsing

```php
$doc = \Pramnos\Framework\Factory::getDocument();

// Parse content through addon filters
$content = '<p>Raw content with [shortcode]</p>';
$parsedContent = $doc->parse($content, 'html', 'html');

// Content is automatically filtered through registered addons
```

### Custom Content Processing

```php
// In an addon class
class ContentProcessorAddon extends \Pramnos\Addon\Addon
{
    public function onParse($text, $texttype, $doctype)
    {
        // Process shortcodes
        $text = preg_replace_callback('/\[gallery\s+([^\]]*)\]/', function($matches) {
            return $this->renderGallery($matches[1]);
        }, $text);
        
        // Process custom markup
        $text = str_replace('[contact-form]', $this->renderContactForm(), $text);
        
        return $text;
    }
    
    private function renderGallery($attributes)
    {
        // Gallery rendering logic
        return '<div class="gallery">Gallery content</div>';
    }
}
```

### Body Classes and Styling

```php
// Add CSS classes to body tag
$doc->addBodyClass('page-home');
$doc->addBodyClass('user-logged-in');
$doc->addBodyClass('theme-dark');

// Classes are automatically added to <body> tag in HTML output
```

## Advanced Features

### Custom Document Types

```php
// Create custom document type
namespace MyApp\Document;

class CustomDocument extends \Pramnos\Document\Document
{
    public function render()
    {
        // Set custom headers
        if (!headers_sent()) {
            header('Content-Type: application/custom+xml');
            header('X-Custom-Header: MyValue');
        }
        
        // Custom rendering logic
        $content = '<?xml version="1.0"?>';
        $content .= '<custom>';
        $content .= $this->getContent();
        $content .= '</custom>';
        
        return $content;
    }
}

// Register and use custom document type
$doc = new MyApp\Document\CustomDocument();
```

### Document Hooks and Events

```php
// Use addon system for document processing
class DocumentAddon extends \Pramnos\Addon\Addon
{
    public function onBeforeDocumentRender($doc)
    {
        // Modify document before rendering
        if ($doc->getType() === 'html') {
            $doc->addMetaName('generator', 'My CMS');
        }
    }
    
    public function onAfterDocumentRender($content, $doc)
    {
        // Post-process rendered content
        if ($doc->getType() === 'html') {
            $content = str_replace('</body>', $this->getAnalyticsCode() . '</body>', $content);
        }
        return $content;
    }
}
```

### Performance Optimization

```php
// Asset minification and compression
$doc = \Pramnos\Framework\Factory::getDocument();

// Enable asset compression (if supported)
$doc->setCompression(true);

// Combine and minify assets
$doc->combineAssets(true);

// Set cache headers for assets
$doc->setCacheHeaders(3600); // 1 hour cache
```

### AMP Optimization

```php
// AMP-specific optimizations
$doc = \Pramnos\Framework\Factory::getDocument('amp');

// AMP requires specific image handling
$content = '<amp-img src="/image.jpg" width="600" height="400" layout="responsive" alt="Description"></amp-img>';

// AMP analytics
$doc->addScriptDeclaration('
<amp-analytics type="googleanalytics">
<script type="application/json">
{
  "vars": {
    "account": "UA-XXXXX-Y"
  },
  "triggers": {
    "trackPageview": {
      "on": "visible",
      "request": "pageview"
    }
  }
}
</script>
</amp-analytics>
', 'amp-analytics');

$doc->addContent($content);
```

## Best Practices

### Document Structure

```php
// Follow consistent document structure pattern
class MyController extends \Pramnos\Application\Controller
{
    public function display()
    {
        // 1. Get document instance
        $doc = \Pramnos\Framework\Factory::getDocument();
        
        // 2. Set document properties
        $doc->title = 'Page Title';
        $doc->description = 'Page description';
        
        // 3. Add meta tags and assets
        $doc->addMetaName('keywords', 'relevant, keywords');
        $doc->enqueueScript('page-specific-js');
        
        // 4. Handle view and return content
        $view = $this->getView('MyView');
        return $view->display('template');
    }
}
```

### Asset Management Best Practices

```php
// Group related assets
$doc->registerScript('app-vendor', '/js/vendor.min.js', [], '1.0', true);
$doc->registerScript('app-core', '/js/core.min.js', ['app-vendor'], '1.0', true);
$doc->registerScript('app-features', '/js/features.min.js', ['app-core'], '1.0', true);

// Use versioning for cache busting
$version = \Pramnos\Application\Settings::getSetting('app_version', '1.0.0');
$doc->registerScript('app-main', '/js/main.js', ['app-features'], $version, true);

// Conditional loading
if ($this->requiresDataTables()) {
    $doc->enqueueScript('datatables');
    $doc->enqueueStyle('datatables');
}
```

### SEO Optimization

```php
// Create SEO helper methods
private function setSEOMeta($title, $description, $keywords = '')
{
    $doc = \Pramnos\Framework\Factory::getDocument();
    
    // Title with site name
    $siteName = \Pramnos\Application\Settings::getSetting('sitename');
    $doc->title = $title . ' - ' . $siteName;
    
    // Description
    $doc->description = $description;
    
    // Keywords
    if ($keywords) {
        $doc->addMetaName('keywords', $keywords);
    }
    
    // Open Graph
    $doc->og_title = $title;
    $doc->og_description = $description;
    $doc->og_site_name = $siteName;
    $doc->og_url = $this->getCurrentUrl();
    
    // Canonical URL
    $doc->addMeta('canonical', $this->getCurrentUrl());
}
```

### Multi-Format Support

```php
// Design controllers for multiple formats
public function display()
{
    $data = $this->getData();
    $format = $this->getRequestFormat();
    
    switch ($format) {
        case 'json':
            return $this->renderJson($data);
        case 'xml':
            return $this->renderXml($data);
        case 'pdf':
            return $this->renderPdf($data);
        case 'rss':
            return $this->renderRss($data);
        default:
            return $this->renderHtml($data);
    }
}

private function renderJson($data)
{
    $doc = \Pramnos\Framework\Factory::getDocument();
    $doc->setType('json');
    $doc->usetheme = false;
    $doc->setContent(json_encode($data));
    return $doc->render();
}
```

### Error Handling

```php
// Handle document errors gracefully
try {
    $doc = \Pramnos\Framework\Factory::getDocument();
    $doc->title = 'Page Title';
    $content = $doc->render();
} catch (\Exception $e) {
    // Log error
    \Pramnos\Logs\Logger::error('Document rendering failed', [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    
    // Fallback to simple output
    $content = '<html><body><h1>Service Unavailable</h1></body></html>';
}

echo $content;
```

---

## Related Documentation

- **[Framework Guide](Pramnos_Framework_Guide.md)** - MVC architecture and controller patterns
- **[Theme System Guide](Pramnos_Theme_Guide.md)** - Advanced theming and customization
- **[Cache System Guide](Pramnos_Cache_Guide.md)** - Caching rendered documents and assets
- **[Logging System Guide](Pramnos_Logging_Guide.md)** - Debugging document rendering issues

---

The Pramnos Document & Output System provides a comprehensive foundation for managing multi-format content delivery with robust asset management, SEO optimization, and theme integration capabilities. This system enables you to build applications that serve content efficiently across multiple platforms and formats while maintaining clean separation between content, presentation, and business logic.
