# Pramnos Framework - Theme System Guide

The Pramnos Framework includes a powerful theming system that provides flexible template management, widget support, menu systems, and complete design customization. This guide covers everything from basic theme usage to advanced theme development.

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Basic Theme Usage](#basic-theme-usage)
3. [Theme Structure](#theme-structure)
4. [Template System](#template-system)
5. [Widget System](#widget-system)
6. [Menu System](#menu-system)
7. [Theme Settings](#theme-settings)
8. [Content Types](#content-types)
9. [Asset Management](#asset-management)
10. [Theme Development](#theme-development)
11. [Advanced Features](#advanced-features)
12. [Best Practices](#best-practices)

## Architecture Overview

The theme system is built around the `\Pramnos\Theme\Theme` class and integrates seamlessly with the document and view systems:

```
themes/
├── default/                 # Default theme
│   ├── theme.html.php      # Main template file
│   ├── style.css           # Theme stylesheet
│   ├── screenshot.png      # Theme preview image
│   ├── header.php          # Header template
│   ├── footer.php          # Footer template
│   └── views/              # View overrides
│       └── ControllerName/
│           └── template.html.php
└── custom-theme/           # Custom theme
    ├── theme.html.php
    ├── style.css
    └── functions.php       # Theme customization
```

### Key Components

- **Theme Templates**: HTML/PHP files that define layout structure
- **View Overrides**: Theme-specific view templates
- **Widget Areas**: Customizable content regions
- **Menu Areas**: Navigation management
- **Settings System**: Configurable theme options
- **Asset Integration**: CSS/JS management

## Basic Theme Usage

### Getting the Current Theme

```php
// Get active theme instance
$theme = \Pramnos\Theme\Theme::getTheme();

// Get theme with specific path
$theme = \Pramnos\Theme\Theme::getTheme('my-theme', '/custom/themes/path');

// Load theme for display
$theme = \Pramnos\Theme\Theme::getTheme('my-theme', '', true);
```

### Setting Active Theme

```php
// Set theme in application settings
\Pramnos\Application\Settings::setSetting('theme', 'my-theme');

// Or programmatically in controllers
$doc = \Pramnos\Framework\Factory::getDocument();
$doc->themeObject = \Pramnos\Theme\Theme::getTheme('my-theme');
```

### Basic Theme Information

```php
$theme = \Pramnos\Theme\Theme::getTheme();

echo $theme->title;        // Theme display name
echo $theme->author;       // Theme author
echo $theme->copyright;    // Copyright information
echo $theme->url;          // Author URL
echo $theme->info;         // Theme description
echo $theme->thumbnail;    // Screenshot URL
```

## Theme Structure

### Main Template File (theme.html.php)

The main template file defines the overall page structure:

```php
<!DOCTYPE html>
<html lang="<?php echo $lang->_('LangShort'); ?>">
<head>
    <meta charset="<?php echo $lang->_('CHARSET'); ?>">
    <title><?php echo $doc->title; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo $doc->header; // Framework-generated head content ?>
</head>
<body>
    <header>
        <h1><?php echo \Pramnos\Application\Settings::getSetting('sitename'); ?></h1>
        <?php 
        // Display navigation menu
        wp_nav_menu(['theme_location' => 'primary']); 
        ?>
    </header>
    
    <main>
        <?php echo '[MODULE]'; // Framework content insertion point ?>
    </main>
    
    <aside>
        <?php dynamic_sidebar('sidebar-1'); // Widget area ?>
    </aside>
    
    <footer>
        <p>&copy; <?php echo date('Y'); ?> <?php echo $sitename; ?></p>
    </footer>
</body>
</html>
```

### Content Type Templates

Themes can have specific templates for different content types:

```php
// In theme constructor or init method
$this->elements = [
    'index' => 'theme.html.php',      // Default template
    'page' => 'page.html.php',        // Page template
    'single' => 'single.html.php',    // Single post template
    'archive' => 'archive.html.php',  // Archive template
    'search' => 'search.html.php',    // Search results
    '404' => '404.html.php',          // Error page
    'login' => 'login.html.php',      // Login page
    'header' => 'header.php',         // Header include
    'footer' => 'footer.php',         // Footer include
    'sidebar' => 'sidebar.php',       // Sidebar include
    'style' => 'style.css',           // Main stylesheet
    'dynamicStyle' => 'style.php'     // Dynamic CSS
];
```

### Theme Functions File

Create a `functions.php` file for theme customization:

```php
<?php
// Theme functions and customization

// Theme setup
add_action('after_setup_theme', 'my_theme_setup');

function my_theme_setup() {
    // Add theme support for various features
    add_theme_support('post-thumbnails');
    add_theme_support('custom-logo');
    
    // Register navigation menus
    register_nav_menus([
        'primary' => 'Primary Menu',
        'footer' => 'Footer Menu'
    ]);
    
    // Register widget areas
    register_sidebar([
        'name' => 'Main Sidebar',
        'id' => 'sidebar-1',
        'before_widget' => '<div class="widget">',
        'after_widget' => '</div>',
        'before_title' => '<h3 class="widget-title">',
        'after_title' => '</h3>'
    ]);
}

// Enqueue theme assets
add_action('wp_enqueue_scripts', 'my_theme_scripts');

function my_theme_scripts() {
    $doc = \Pramnos\Framework\Factory::getDocument();
    
    // Theme stylesheet
    $doc->enqueueStyle('theme-style', get_template_directory_uri() . '/style.css');
    
    // Theme JavaScript
    $doc->enqueueScript('theme-js', get_template_directory_uri() . '/js/theme.js', ['jquery']);
}
```

## Template System

### View Overrides

Themes can override framework views by placing template files in the `views/` directory:

```
themes/my-theme/
└── views/
    ├── User/
    │   ├── profile.html.php    # Override User/profile view
    │   └── dashboard.html.php  # Override User/dashboard view
    └── Blog/
        ├── post.html.php       # Override Blog/post view
        └── archive.html.php    # Override Blog/archive view
```

### Template Hierarchy

The framework follows this template hierarchy:

1. Theme-specific view override (`themes/mytheme/views/Controller/template.html.php`)
2. Application view (`src/Views/Controller/template.html.php`)
3. Framework default view (if applicable)

### Template Variables

Templates have access to these variables:

```php
// In any template file
echo $this->variableName;    // View variables
echo $doc->title;            // Document properties
echo $lang->_('STRING');     // Language translations
echo sURL;                   // Base URL constant
echo $_url;                  // Controller URL
```

### Including Template Parts

```php
// In theme templates
<?php get_header(); ?>      // Include header.php
<?php get_footer(); ?>      // Include footer.php
<?php get_sidebar(); ?>     // Include sidebar.php

// Include custom template parts
<?php include 'template-parts/hero.php'; ?>
<?php include 'template-parts/content-' . $post_type . '.php'; ?>
```

## Widget System

### Registering Widget Areas

```php
// In theme functions.php or theme class
class MyTheme extends \Pramnos\Theme\Theme
{
    public function init()
    {
        // Register widget areas (sidebars)
        $this->addSidebar('sidebar-1', 'Main Sidebar', [
            'description' => 'Main sidebar widget area',
            'before_widget' => '<div class="widget %2$s">',
            'after_widget' => '</div>',
            'before_title' => '<h3 class="widget-title">',
            'after_title' => '</h3>'
        ]);
        
        $this->addSidebar('footer-1', 'Footer Widget 1', [
            'description' => 'First footer widget area'
        ]);
        
        return $this;
    }
}
```

### Displaying Widget Areas

```php
// In theme templates
<?php if (is_active_sidebar('sidebar-1')) : ?>
    <div class="sidebar">
        <?php dynamic_sidebar('sidebar-1'); ?>
    </div>
<?php endif; ?>

// Multiple footer widgets
<div class="footer-widgets">
    <?php for ($i = 1; $i <= 3; $i++) : ?>
        <?php if (is_active_sidebar("footer-{$i}")) : ?>
            <div class="footer-widget-<?php echo $i; ?>">
                <?php dynamic_sidebar("footer-{$i}"); ?>
            </div>
        <?php endif; ?>
    <?php endfor; ?>
</div>
```

### Custom Widget Development

```php
// Create custom widget
class CustomWidget extends \Pramnos\Theme\Widget
{
    public function __construct()
    {
        parent::__construct(
            'custom_widget',           // Widget ID
            'Custom Widget',           // Widget Name
            ['description' => 'A custom widget for my theme']
        );
    }
    
    public function widget($args, $instance)
    {
        echo $args['before_widget'];
        
        if (!empty($instance['title'])) {
            echo $args['before_title'] . $instance['title'] . $args['after_title'];
        }
        
        echo '<p>' . $instance['content'] . '</p>';
        
        echo $args['after_widget'];
    }
    
    public function form($instance)
    {
        $title = !empty($instance['title']) ? $instance['title'] : '';
        $content = !empty($instance['content']) ? $instance['content'] : '';
        
        return '
        <p>
            <label for="widget_title">Title:</label>
            <input type="text" name="title" value="' . $title . '" />
        </p>
        <p>
            <label for="widget_content">Content:</label>
            <textarea name="content">' . $content . '</textarea>
        </p>';
    }
}

// Register widget
add_action('widgets_init', function() {
    register_widget('CustomWidget');
});
```

## Menu System

### Registering Menu Areas

```php
// In theme class or functions.php
class MyTheme extends \Pramnos\Theme\Theme
{
    public function init()
    {
        // Register menu locations
        $this->addMenuArea('primary', 'Primary Navigation', [
            'description' => 'Main site navigation'
        ]);
        
        $this->addMenuArea('footer', 'Footer Navigation', [
            'description' => 'Footer links'
        ]);
        
        $this->addMenuArea('social', 'Social Links', [
            'description' => 'Social media links'
        ]);
        
        return $this;
    }
}

// Alternative registration
register_nav_menus([
    'primary' => 'Primary Menu',
    'footer' => 'Footer Menu',
    'social' => 'Social Links'
]);
```

### Displaying Menus

```php
// Basic menu display
<?php wp_nav_menu(['theme_location' => 'primary']); ?>

// Advanced menu with custom options
<?php wp_nav_menu([
    'theme_location' => 'primary',
    'container' => 'nav',
    'container_class' => 'main-navigation',
    'container_id' => 'site-navigation',
    'menu_class' => 'nav-menu',
    'menu_id' => 'primary-menu',
    'before' => '<li class="menu-item">',
    'after' => '</li>',
    'link_before' => '<span>',
    'link_after' => '</span>',
    'echo' => true
]); ?>

// Conditional menu display
<?php if (has_nav_menu('primary')) : ?>
    <nav class="main-navigation">
        <?php wp_nav_menu(['theme_location' => 'primary']); ?>
    </nav>
<?php endif; ?>
```

### Custom Menu Walker

```php
// Create custom menu walker for advanced menu styling
class CustomMenuWalker extends \Pramnos\Theme\MenuWalker
{
    public function start_lvl(&$output, $depth = 0, $args = null)
    {
        $output .= '<ul class="sub-menu level-' . $depth . '">';
    }
    
    public function start_el(&$output, $item, $depth = 0, $args = null, $id = 0)
    {
        $classes = ['menu-item'];
        
        if ($item->hasChildren) {
            $classes[] = 'has-dropdown';
        }
        
        if ($item->isActive) {
            $classes[] = 'active';
        }
        
        $output .= '<li class="' . implode(' ', $classes) . '">';
        $output .= '<a href="' . $item->url . '">' . $item->title . '</a>';
    }
}

// Use custom walker
wp_nav_menu([
    'theme_location' => 'primary',
    'walker' => new CustomMenuWalker()
]);
```

## Theme Settings

### Adding Theme Settings

```php
class MyTheme extends \Pramnos\Theme\Theme
{
    public function init()
    {
        // Add theme customization options
        $this->addField(
            'logo_url',                    // Setting name
            'Logo URL',                    // Display label
            'image',                       // Field type
            '',                           // Options (for selectbox)
            'Upload your site logo'        // Description
        );
        
        $this->addField(
            'color_scheme',
            'Color Scheme',
            'selectbox',
            'default,Default|dark,Dark|light,Light',
            'Choose the color scheme'
        );
        
        $this->addField(
            'enable_sidebar',
            'Enable Sidebar',
            'checkbox',
            '',
            'Show sidebar on pages'
        );
        
        $this->addField(
            'footer_text',
            'Footer Text',
            'textarea',
            '',
            'Custom footer text'
        );
        
        return $this;
    }
}
```

### Using Theme Settings

```php
// In theme templates
$theme = \Pramnos\Theme\Theme::getTheme();

// Get setting values
$logoUrl = $theme->getSetting('logo_url');
$colorScheme = $theme->getSetting('color_scheme');
$enableSidebar = $theme->getSetting('enable_sidebar');
$footerText = $theme->getSetting('footer_text');

// Use in templates
<?php if ($logoUrl) : ?>
    <img src="<?php echo $logoUrl; ?>" alt="Site Logo" class="site-logo">
<?php endif; ?>

<body class="color-scheme-<?php echo $colorScheme; ?>">

<?php if ($enableSidebar) : ?>
    <aside class="sidebar">
        <?php dynamic_sidebar('sidebar-1'); ?>
    </aside>
<?php endif; ?>

<footer>
    <?php echo $footerText ?: 'Default footer text'; ?>
</footer>
```

### Field Types

Available field types for theme settings:

```php
// Text input
$this->addField('site_tagline', 'Site Tagline', 'textfield');

// Number input
$this->addField('posts_per_page', 'Posts Per Page', 'number');

// Email input
$this->addField('contact_email', 'Contact Email', 'email');

// URL input
$this->addField('social_facebook', 'Facebook URL', 'url');

// Textarea
$this->addField('about_text', 'About Text', 'textarea');

// Checkbox
$this->addField('show_breadcrumbs', 'Show Breadcrumbs', 'checkbox');

// Select dropdown
$this->addField('layout', 'Layout', 'selectbox', 'full,Full Width|boxed,Boxed|fluid,Fluid');

// Image upload
$this->addField('header_image', 'Header Image', 'image');
```

## Content Types

### Setting Content Types

```php
// In controllers
class BlogController extends \Pramnos\Application\Controller
{
    public function display()
    {
        // Set content type for theme template selection
        $this->setContentType('archive');
        
        // Theme will use archive.html.php if available
        $view = $this->getView('Blog');
        return $view->display('archive');
    }
    
    public function post()
    {
        $this->setContentType('single');
        
        $view = $this->getView('Blog');
        return $view->display('post');
    }
}
```

### Template Selection Logic

```php
// Theme automatically selects templates based on content type
// Priority order:
// 1. {content-type}.{format}.php (e.g., single.html.php)
// 2. theme.{format}.php (e.g., theme.html.php) 
// 3. Default framework template

// Custom content type handling
class MyTheme extends \Pramnos\Theme\Theme
{
    public function loadtheme()
    {
        $contentType = $this->getContentType();
        
        // Custom logic for specific content types
        switch ($contentType) {
            case 'product':
                $this->elements['product'] = 'templates/product.html.php';
                break;
            case 'event':
                $this->elements['event'] = 'templates/event.html.php';
                break;
        }
        
        parent::loadtheme();
    }
}
```

## Asset Management

### Theme Stylesheets

```php
// In theme class or functions.php
public function displayInit()
{
    $doc = \Pramnos\Framework\Factory::getDocument();
    
    // Main theme stylesheet
    $doc->enqueueStyle('theme-style', $this->getThemeUrl() . '/style.css');
    
    // Additional stylesheets
    $doc->enqueueStyle('theme-layout', $this->getThemeUrl() . '/css/layout.css', ['theme-style']);
    
    // Conditional stylesheets
    if ($this->getSetting('enable_dark_mode')) {
        $doc->enqueueStyle('theme-dark', $this->getThemeUrl() . '/css/dark.css');
    }
    
    // Responsive stylesheets
    $doc->enqueueStyle('theme-responsive', $this->getThemeUrl() . '/css/responsive.css', [], '', 'screen and (max-width: 768px)');
    
    return $this;
}
```

### Theme JavaScript

```php
public function displayInit()
{
    $doc = \Pramnos\Framework\Factory::getDocument();
    
    // Theme JavaScript
    $doc->enqueueScript('theme-js', $this->getThemeUrl() . '/js/theme.js', ['jquery'], '', true);
    
    // Conditional scripts
    if ($this->getSetting('enable_animations')) {
        $doc->enqueueScript('theme-animations', $this->getThemeUrl() . '/js/animations.js', ['theme-js'], '', true);
    }
    
    // Localized scripts
    $doc->addScriptDeclaration('
        var themeSettings = {
            ajaxUrl: "' . sURL . 'ajax/",
            nonce: "' . wp_create_nonce('theme_ajax') . '",
            colorScheme: "' . $this->getSetting('color_scheme') . '"
        };
    ');
    
    return $this;
}
```

### Dynamic CSS

Create a `style.php` file for dynamic CSS generation:

```php
<?php
// style.php - Dynamic CSS based on theme settings
header('Content-Type: text/css');

$theme = \Pramnos\Theme\Theme::getTheme();
$primaryColor = $theme->getSetting('primary_color') ?: '#007cba';
$secondaryColor = $theme->getSetting('secondary_color') ?: '#005a87';
$fontFamily = $theme->getSetting('font_family') ?: 'Arial, sans-serif';
?>

:root {
    --primary-color: <?php echo $primaryColor; ?>;
    --secondary-color: <?php echo $secondaryColor; ?>;
    --font-family: <?php echo $fontFamily; ?>;
}

body {
    font-family: var(--font-family);
    color: var(--primary-color);
}

.button {
    background-color: var(--primary-color);
    border-color: var(--secondary-color);
}

.button:hover {
    background-color: var(--secondary-color);
}

<?php if ($theme->getSetting('enable_shadows')) : ?>
.card {
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
<?php endif; ?>

<?php if ($theme->getSetting('layout_width')) : ?>
.container {
    max-width: <?php echo $theme->getSetting('layout_width'); ?>px;
}
<?php endif; ?>
```

## Theme Development

### Creating a New Theme

1. **Create theme directory structure:**

```bash
mkdir themes/my-new-theme
cd themes/my-new-theme
```

2. **Create basic files:**

```php
// theme.html.php - Main template
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $doc->title; ?></title>
    <?php echo $doc->header; ?>
</head>
<body <?php body_class(); ?>>
    <div class="site-wrapper">
        <header class="site-header">
            <?php get_header(); ?>
        </header>
        
        <main class="site-main">
            <?php echo '[MODULE]'; ?>
        </main>
        
        <footer class="site-footer">
            <?php get_footer(); ?>
        </footer>
    </div>
</body>
</html>
```

```css
/* style.css - Main stylesheet */
/*
Theme Name: My New Theme
Description: A custom theme for Pramnos Framework
Author: Your Name
Version: 1.0
*/

/* Reset and base styles */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: Arial, sans-serif;
    line-height: 1.6;
    color: #333;
}

/* Layout */
.site-wrapper {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

.site-main {
    flex: 1;
    padding: 20px;
}

/* Header */
.site-header {
    background: #f8f9fa;
    padding: 20px;
    border-bottom: 1px solid #e9ecef;
}

/* Footer */
.site-footer {
    background: #343a40;
    color: white;
    padding: 20px;
    text-align: center;
}
```

3. **Create theme class:**

```php
// functions.php or in theme directory
class MyNewTheme extends \Pramnos\Theme\Theme
{
    public function init()
    {
        // Theme setup
        $this->title = 'My New Theme';
        $this->author = 'Your Name';
        $this->info = 'A custom theme for Pramnos Framework';
        
        // Register menus
        $this->addMenuArea('primary', 'Primary Menu');
        $this->addMenuArea('footer', 'Footer Menu');
        
        // Register sidebars
        $this->addSidebar('sidebar-main', 'Main Sidebar');
        $this->addSidebar('footer-1', 'Footer Widget 1');
        
        // Add theme settings
        $this->addField('logo', 'Site Logo', 'image');
        $this->addField('primary_color', 'Primary Color', 'color');
        $this->addField('show_sidebar', 'Show Sidebar', 'checkbox');
        
        return $this;
    }
    
    public function displayInit()
    {
        parent::displayInit();
        
        $doc = \Pramnos\Framework\Factory::getDocument();
        
        // Enqueue theme assets
        $doc->enqueueScript('theme-main', $this->getThemeUrl() . '/js/main.js', ['jquery']);
        
        return $this;
    }
}
```

### Theme Testing

```php
// Create test data and scenarios
class ThemeTest
{
    public function testThemeLoading()
    {
        $theme = \Pramnos\Theme\Theme::getTheme('my-new-theme');
        assert($theme instanceof \Pramnos\Theme\Theme);
        assert($theme->theme === 'my-new-theme');
    }
    
    public function testThemeSettings()
    {
        $theme = \Pramnos\Theme\Theme::getTheme('my-new-theme');
        
        // Test setting values
        $theme->setSetting('primary_color', '#ff0000');
        assert($theme->getSetting('primary_color') === '#ff0000');
    }
    
    public function testWidgetAreas()
    {
        $theme = \Pramnos\Theme\Theme::getTheme('my-new-theme');
        $widgetAreas = $theme->getWidgetAreas();
        
        assert(isset($widgetAreas['sidebar-main']));
        assert(isset($widgetAreas['footer-1']));
    }
}
```

## Advanced Features

### Custom Post Types Integration

```php
// Support for custom post types
class BlogTheme extends \Pramnos\Theme\Theme
{
    public function init()
    {
        // Define templates for custom post types
        $this->elements['product'] = 'single-product.html.php';
        $this->elements['event'] = 'single-event.html.php';
        $this->elements['portfolio'] = 'archive-portfolio.html.php';
        
        return $this;
    }
    
    public function loadtheme()
    {
        // Custom template selection logic
        $contentType = $this->getContentType();
        $postType = $this->getCurrentPostType();
        
        if ($postType && isset($this->elements[$postType])) {
            $this->_contentType = $postType;
        }
        
        parent::loadtheme();
    }
}
```

### Theme Child Support

```php
// Support for child themes
class ChildTheme extends \Pramnos\Theme\Theme
{
    protected $parentTheme = 'parent-theme-name';
    
    public function loadtheme()
    {
        // Load parent theme first
        if ($this->parentTheme) {
            $parentPath = $this->path . DS . $this->parentTheme;
            if (file_exists($parentPath . DS . 'theme.html.php')) {
                // Inherit parent elements
                $parent = new Theme($this->parentTheme, $this->path);
                $this->elements = array_merge($parent->elements, $this->elements);
            }
        }
        
        parent::loadtheme();
    }
}
```

### AJAX Theme Integration

```php
// AJAX support in themes
public function displayInit()
{
    $doc = \Pramnos\Framework\Factory::getDocument();
    
    // Add AJAX support
    $doc->addScriptDeclaration('
        var ajaxSettings = {
            url: "' . sURL . 'ajax/theme",
            nonce: "' . wp_create_nonce('theme_ajax') . '"
        };
    ');
    
    return $this;
}

// AJAX handler
public function handleAjax($action, $data)
{
    switch ($action) {
        case 'load_more_posts':
            return $this->loadMorePosts($data);
        case 'update_theme_setting':
            return $this->updateThemeSetting($data);
        default:
            return ['error' => 'Invalid action'];
    }
}
```

## Best Practices

### Theme Performance

```php
// Optimize theme performance
public function displayInit()
{
    $doc = \Pramnos\Framework\Factory::getDocument();
    
    // Minify and combine assets
    if (ENVIRONMENT === 'production') {
        $doc->enqueueStyle('theme-combined', $this->getThemeUrl() . '/css/combined.min.css');
        $doc->enqueueScript('theme-combined', $this->getThemeUrl() . '/js/combined.min.js');
    } else {
        // Development assets
        $doc->enqueueStyle('theme-style', $this->getThemeUrl() . '/css/style.css');
        $doc->enqueueScript('theme-script', $this->getThemeUrl() . '/js/script.js');
    }
    
    return $this;
}
```

### Responsive Design

```css
/* Mobile-first responsive design */
.container {
    width: 100%;
    padding: 0 15px;
}

@media (min-width: 576px) {
    .container {
        max-width: 540px;
        margin: 0 auto;
    }
}

@media (min-width: 768px) {
    .container {
        max-width: 720px;
    }
}

@media (min-width: 992px) {
    .container {
        max-width: 960px;
    }
}

@media (min-width: 1200px) {
    .container {
        max-width: 1140px;
    }
}
```

### Accessibility

```php
// Accessibility best practices in themes
// In template files:

// Proper heading hierarchy
<h1><?php echo $doc->title; ?></h1>
<h2>Section Title</h2>
<h3>Subsection Title</h3>

// Alt text for images
<img src="<?php echo $imageUrl; ?>" alt="<?php echo $imageAlt; ?>">

// Skip links
<a class="skip-link screen-reader-text" href="#main">Skip to main content</a>

// ARIA labels
<nav aria-label="Primary Navigation">
    <?php wp_nav_menu(['theme_location' => 'primary']); ?>
</nav>

// Form labels
<label for="search">Search:</label>
<input type="search" id="search" name="search">
```

### Security

```php
// Security best practices
// Sanitize output
echo htmlspecialchars($userInput);
echo esc_attr($attribute);
echo esc_url($url);

// Validate input
$color = $this->getSetting('primary_color');
if (!preg_match('/^#[a-f0-9]{6}$/i', $color)) {
    $color = '#007cba'; // Default color
}

// Nonce verification for AJAX
if (!wp_verify_nonce($_POST['nonce'], 'theme_ajax')) {
    die('Security check failed');
}

// Capability checks
if (!current_user_can('manage_options')) {
    die('Insufficient permissions');
}
```

---

## Related Documentation

- **[Framework Guide](Pramnos_Framework_Guide.md)** - MVC architecture and view system
- **[Document & Output Guide](Pramnos_Document_Output_Guide.md)** - Document system integration
- **[Authentication Guide](Pramnos_Authentication_Guide.md)** - User-based theme features
- **[Cache System Guide](Pramnos_Cache_Guide.md)** - Caching theme assets and output

---

The Pramnos Theme System provides a comprehensive foundation for building flexible, maintainable, and feature-rich themes. With support for widgets, menus, settings, and complete customization, you can create themes that meet any design requirement while maintaining clean separation between presentation and logic.
