# Pramnos Framework - Internationalization (i18n) Guide

The Pramnos Framework includes a comprehensive internationalization system that enables applications to support multiple languages and locales. The system provides translation management, language switching, and localization features.

## Table of Contents

1. [Overview](#overview)
2. [Basic Usage](#basic-usage)
3. [Language Files](#language-files)
4. [Translation Functions](#translation-functions)
5. [Language Management](#language-management)
6. [String Discovery](#string-discovery)
7. [Localization Features](#localization-features)
8. [Best Practices](#best-practices)
9. [API Reference](#api-reference)

## Overview

The Internationalization system consists of:

- **Language Class** (`\Pramnos\Translator\Language`) - Core translation functionality
- **StringFinder** (`\Pramnos\Translator\StringFinder`) - Automatic string discovery for translation
- **Helper Functions** - Convenient translation functions (`l()`, `_()`)
- **Multi-language Support** - Support for dynamic language switching
- **Localization** - Date, time, and number formatting

### Key Features

- **Multiple Language Support**: Easy switching between languages
- **Translation Management**: Load and manage translation strings
- **String Discovery**: Automatic discovery of translatable strings
- **Template Integration**: Seamless integration with theme system
- **Fallback System**: Graceful fallback to default language
- **Greeklish Support**: Built-in Greek to Latin character conversion
- **Parameter Substitution**: Dynamic content in translations

## Basic Usage

### Setting Up Languages

```php
use Pramnos\Translator\Language;

// Get language instance
$lang = Language::getInstance();

// Load a language
$lang->load('english');  // Load English translations
$lang->load('greek');    // Load Greek translations

// Set active language
$lang->setLang('greek');
```

### Basic Translation

```php
// Using the language instance
$lang = Language::getInstance();
echo $lang->_('Hello, World!');

// Using the global helper function
l('Hello, World!');  // Outputs translated text

// With parameters
l('Welcome, %s!', $username);
```

### Language Switching

```php
// Switch language via URL parameter
if (isset($_GET['lang'])) {
    $_SESSION['language'] = $_GET['lang'];
    $lang = Language::getInstance();
    $lang->setLang($_GET['lang']);
}

// In application initialization
$application = new \Pramnos\Application\Application();
if (isset($_GET['lang'])) {
    $application->language = $_GET['lang'];
}
```

## Language Files

### File Structure

Language files are stored in the `language/` directory:

```
language/
├── english.php
├── greek.php
├── spanish.php
├── french.php
├── english.png      (flag icons)
├── greek.png
└── ...
```

### Language File Format

```php
<?php
// language/english.php

$lang = array(
    // Basic translations
    'Hello' => 'Hello',
    'Welcome' => 'Welcome',
    'Home' => 'Home',
    'Login' => 'Login',
    'Logout' => 'Logout',
    
    // Messages with parameters
    'Welcome, %s!' => 'Welcome, %s!',
    'You have %d new messages' => 'You have %d new messages',
    
    // Common terms
    'Save' => 'Save',
    'Cancel' => 'Cancel',
    'Delete' => 'Delete',
    'Edit' => 'Edit',
    'Add' => 'Add',
    
    // Time-related
    'ago' => 'ago',
    'minutes' => 'minutes',
    'hours' => 'hours',
    'days' => 'days',
    'months' => 'months',
    'years' => 'years',
    'Yesterday' => 'Yesterday',
    
    // System strings
    'LangShort' => 'en',
    'CHARSET' => 'UTF-8',
    
    // Navigation
    'Previous' => 'Previous',
    'Next' => 'Next',
    'Page' => 'Page',
    'All' => 'All'
);
```

### Greek Language Example

```php
<?php
// language/greek.php

$lang = array(
    'Hello' => 'Γεια σας',
    'Welcome' => 'Καλώς ήρθατε',
    'Home' => 'Αρχική',
    'Login' => 'Σύνδεση',
    'Logout' => 'Αποσύνδεση',
    
    'Welcome, %s!' => 'Καλώς ήρθατε, %s!',
    'You have %d new messages' => 'Έχετε %d νέα μηνύματα',
    
    'Save' => 'Αποθήκευση',
    'Cancel' => 'Ακύρωση',
    'Delete' => 'Διαγραφή',
    'Edit' => 'Επεξεργασία',
    'Add' => 'Προσθήκη',
    
    'ago' => 'πριν',
    'minutes' => 'λεπτά',
    'hours' => 'ώρες',
    'days' => 'ημέρες',
    'months' => 'μήνες',
    'years' => 'χρόνια',
    'Yesterday' => 'Χθες',
    
    'LangShort' => 'el',
    'CHARSET' => 'UTF-8'
);
```

## Translation Functions

### Using the `l()` Function

The `l()` function is a global helper for quick translations:

```php
// Simple translation
l('Hello, World!');

// With parameters
l('Welcome, %s!', $username);
l('You have %d items', $count);

// Multiple parameters
l('User %s has %d points in %s', $username, $points, $category);
```

### Using the Language Object

```php
$lang = Language::getInstance();

// Simple translation
echo $lang->_('Hello, World!');

// With parameters
echo $lang->_('Welcome, %s!', $username);

// Check current language
if ($lang->currentlang() === 'greek') {
    echo $lang->_('Greek content');
}
```

### Theme Integration

```php
// In theme files, both methods work
<h1><?php l('Welcome to our site'); ?></h1>

<p><?php echo $lang->_('Thank you for visiting!'); ?></p>

<!-- With parameters -->
<p><?php l('Last updated: %s', date('Y-m-d')); ?></p>
```

## Language Management

### Available Languages

```php
// Get list of available languages
$languages = Language::getLanguages();

foreach ($languages as $langCode) {
    $flag = Language::getFlag($langCode);
    echo "<option value='$langCode'>$langCode</option>";
}
```

### Language Flags

```php
$lang = Language::getInstance();

// Get flag for current language
$currentFlag = $lang->getFlag();

// Get flag for specific language
$greekFlag = $lang->getFlag('greek');
$englishFlag = $lang->getFlag('english');

// Use in HTML
echo "<img src='$currentFlag' alt='Language Flag'>";
```

### Loading Custom Language Paths

```php
$lang = Language::getInstance();

// Load from custom path
$lang->load('custom_lang', '/path/to/custom/languages/');

// Load additional strings
$customStrings = [
    'Custom String' => 'Translated Custom String',
    'Another String' => 'Another Translation'
];
$lang->addlang($customStrings);
```

## String Discovery

### Automatic String Discovery

The StringFinder class can automatically discover translatable strings in your code:

```php
use Pramnos\Translator\StringFinder;

$finder = new StringFinder();

// Search for translatable strings in a directory
$strings = $finder->findInPath('/path/to/your/code');

// Generate language file content
foreach ($strings as $string) {
    echo "'$string' => '$string',\n";
}
```

### Finding Patterns

The StringFinder looks for common translation patterns:

```php
// These patterns are automatically detected:
l('Translatable string');
$lang->_('Another string');
echo $lang->_('Third string');

// In templates:
<?php l('Template string'); ?>
```

## Localization Features

### Date and Time Formatting

```php
use Pramnos\General\Helpers;

$lang = Language::getInstance();

// Format relative time
$timeAgo = Helpers::formatTimePassed(time() - 3600); // 1 hour ago
echo $timeAgo; // Output depends on current language

// The system automatically uses translated terms:
// English: "1 hours ago"
// Greek: "1 ώρες πριν"
```

### Greeklish Conversion

```php
use Pramnos\General\Helpers;

// Convert Greek text to Latin characters
$greekText = 'Καλησπέρα';
$greeklish = Helpers::greeklish($greekText);
echo $greeklish; // Output: "Kalispera"

// URL-friendly version
$urlFriendly = Helpers::greeklish($greekText, true);
echo $urlFriendly; // Output: "kalispera"
```

### Character Set Support

```php
// Language files define character sets
$lang = Language::getInstance();
$charset = $lang->_('CHARSET'); // UTF-8, ISO-8859-7, etc.

// Use in HTML documents
echo '<meta charset="' . $charset . '">';
```

### Pluralization Support

```php
use Pramnos\General\StringHelper;

// Automatic pluralization (English)
$singular = 'item';
$plural = StringHelper::pluralize($singular); // 'items'

// Reverse operation
$original = StringHelper::singularize($plural); // 'item'

// Works with irregular forms
$person = StringHelper::pluralize('person'); // 'people'
$child = StringHelper::pluralize('child'); // 'children'
```

## Best Practices

### 1. Consistent String Keys

```php
// Use consistent, descriptive keys
l('form.save.button');          // Good
l('form.cancel.button');        // Good
l('error.validation.email');    // Good

l('Save');                      // Less descriptive
l('Btn Save');                  // Inconsistent
```

### 2. Parameter Placeholders

```php
// Use descriptive placeholders
l('user.welcome.message', $username);  // %s automatically
l('items.count.display', $count);      // %d for numbers

// For multiple parameters, be explicit
l('order.summary', $total, $items, $date);
```

### 3. Fallback Handling

```php
$lang = Language::getInstance();

// Always provide fallback
try {
    $lang->load($userLanguage);
} catch (Exception $e) {
    $lang->load('english'); // Fallback to English
}
```

### 4. Context-Specific Translations

```php
// Organize by context
$lang = array(
    // Navigation
    'nav.home' => 'Home',
    'nav.about' => 'About',
    'nav.contact' => 'Contact',
    
    // Forms
    'form.name' => 'Name',
    'form.email' => 'Email',
    'form.submit' => 'Submit',
    
    // Messages
    'msg.success' => 'Success!',
    'msg.error' => 'Error occurred',
    'msg.warning' => 'Warning'
);
```

### 5. Language Detection

```php
// Detect user's preferred language
$userLang = 'english'; // default

// From session
if (isset($_SESSION['language'])) {
    $userLang = $_SESSION['language'];
}
// From browser
elseif (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
    $acceptLangs = explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']);
    $browserLang = substr($acceptLangs[0], 0, 2);
    
    $availableLangs = Language::getLanguages();
    if (in_array($browserLang, $availableLangs)) {
        $userLang = $browserLang;
    }
}

$lang = Language::getInstance();
$lang->setLang($userLang);
```

## API Reference

### Language Class Methods

#### Core Methods
- `__construct($lang, $path)` - Create language instance
- `load($language, $path, $setDefault)` - Load language file
- `setLang($language)` - Set active language
- `currentlang()` - Get current language code
- `_($string, ...$args)` - Translate string with parameters

#### Management Methods
- `addlang($strings)` - Add translation strings
- `getlang()` - Get all translation strings
- `static getLanguages()` - Get available languages
- `getFlag($lang)` - Get language flag URL
- `static getInstance($lang)` - Get singleton instance

### StringFinder Class Methods

#### Discovery Methods
- `__construct()` - Create finder instance
- `findInPath($path)` - Find translatable strings in directory

### Helper Functions

#### Global Functions
- `l($string, ...$args)` - Quick translation function
- `env($constant, $default)` - Environment variable helper

#### Utility Functions
- `Helpers::greeklish($text, $urlFriendly)` - Greek to Latin conversion
- `Helpers::formatTimePassed($timestamp)` - Localized time formatting
- `StringHelper::pluralize($word)` - English pluralization
- `StringHelper::singularize($word)` - English singularization

### Language File Structure

#### Required Keys
- `'LangShort'` - ISO language code (e.g., 'en', 'el', 'es')
- `'CHARSET'` - Character encoding (e.g., 'UTF-8')

#### Common Translation Keys
- Navigation: `'Home'`, `'Login'`, `'Logout'`
- Actions: `'Save'`, `'Cancel'`, `'Delete'`, `'Edit'`, `'Add'`
- Time: `'ago'`, `'minutes'`, `'hours'`, `'days'`, `'months'`, `'years'`
- Pagination: `'Previous'`, `'Next'`, `'Page'`, `'All'`

## Related Documentation

- [Framework Guide](Pramnos_Framework_Guide.md) - Core framework concepts
- [Theme Guide](Pramnos_Theme_Guide.md) - Using translations in themes
- [Application Guide](Pramnos_Application_Guide.md) - Application-level language settings
- [Console Guide](Pramnos_Console_Guide.md) - CLI language management tools

## Troubleshooting

### Common Issues

1. **Translations Not Loading**
   - Verify language file exists in `/language/` directory
   - Check file permissions
   - Ensure proper PHP syntax in language file

2. **Missing Translations**
   - Check if string exists in language file
   - Verify correct language is loaded
   - Use StringFinder to discover missing strings

3. **Character Encoding Issues**
   - Ensure language files are saved in UTF-8
   - Check `'CHARSET'` setting in language files
   - Verify web server character encoding

4. **Parameter Substitution Not Working**
   - Use correct placeholder format (`%s`, `%d`)
   - Ensure parameter count matches placeholders
   - Check for typos in translation keys

For additional debugging, check the application logs and verify language file syntax using PHP's built-in syntax checker.
