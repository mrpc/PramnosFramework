---
use_cases:
  - Translating strings or adding a language
  - Working out why a page renders in English whatever the settings say
  - Choosing which language a request is served in, per site or per area
  - Choosing between the translation helper functions
  - Discovering untranslated strings in the codebase
  - Translating a SPA or Svelte front end from the same catalogue
  - Listing the languages an installation ships
  - Making an application's own Language subclass the one the framework uses
  - Catching every untranslated key, to log it or to serve a dialect
---

# Pramnos Framework - Internationalization (i18n) Guide

The Pramnos Framework includes a comprehensive internationalization system that enables applications to support multiple languages and locales. The system provides translation management, language switching, and localization features.

## Table of Contents

1. [Overview](#overview)
2. [Which language a request is served in](#which-language-a-request-is-served-in)
3. [Basic Usage](#basic-usage)
4. [Language Files](#language-files)
5. [Translation Functions](#translation-functions)
6. [Language Management](#language-management)
7. [String Discovery](#string-discovery)
8. [Localization Features](#localization-features)
9. [Best Practices](#best-practices)
10. [API Reference](#api-reference)

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


## Which language a request is served in

Nothing has to be called for this to work. `Application::init()` resolves the language
once per request and loads the catalogue, and every candidate it consults is one you can
set:

| Rank | Where it comes from | Set it by |
|------|--------------------|-----------|
| 1 | `?lang=` on the URL | a language picker linking to `?lang=el` |
| 2 | the administration area's own `language` | `'admin' => ['language' => 'en']` in `app.php` |
| 3 | the session | automatic — `?lang=` is remembered |
| 4 | the `language` cookie | a login, from the account's `users.language` |
| 5 | the `language` setting, then `default_language` | `app/config/settings.php` |
| 6 | the first installed language, then `english` | nothing — the fallback |

Three of those are worth a sentence each.

**The administration area may be in a different language from the site.** A panel whose
operators work in English, in front of a site whose visitors read Greek, is the normal
case rather than an exotic one:

```php
// app/app.php
'admin' => [
    'prefix'       => 'admin',
    'theme'        => 'admin',
    'min_usertype' => 80,
    'language'     => 'en',   // the site itself is 'el'
],
```

It outranks the session deliberately. An area language a stale cookie can override is a
suggestion, not a configuration — the panel would follow whatever the front decided.

**An account's own language is honoured from the moment it signs in.** `users.language`
is a column the framework does not write; it is yours to set on a profile screen. A login
carries it into the session, so the page *after* the login form is already in it:

```php
$user->language = 'el';
$user->save();
```

**A language that is not installed is refused.** Every candidate is checked against
`Language::getLanguages()`, which is the filenames in `app/language/`. That is a security
property as much as a correctness one — `load()` interpolates the name into an `include`
path, and `?lang=` used to reach it unfiltered.

### Changing it mid-request

For a language picker that applies its choice immediately, or a controller serving one
screen in another language:

```php
$application = \Pramnos\Application\Application::currentInstance();

if (!$application->setLanguage($chosen)) {
    // Not installed. Refused rather than silently loading nothing, because
    // load() falling through to English looks exactly like success.
}
```

It validates, remembers the choice in the session, and reloads the catalogue.

### When a page comes back in English and nothing you change helps

This is worth knowing as a shape, because it does not look like a bug in the language
system — it looks like a site that was written in English.

A missing key renders as **itself**, and the framework's keys *are* the English wording:
`t('Account Dashboard')` returns `Account Dashboard` with no catalogue loaded at all. So
"no language was ever selected" and "this string is not translated" produce identical
pages. There is no error, no empty string and nothing to grep for.

Two things produce it:

- **The catalogue is not named what is being asked for.** `english` is the framework's
  fallback name. A project whose files are `en.php` and `el.php` has no `english.php`, so
  a request for `english` loads *nothing*. `en` is tried after `english` for exactly this
  reason, and the resolver's own last resort is the first installed language rather than a
  name that may not exist.
- **The string is not in the catalogue.** Check with the key, not with the page:
  `var_dump(isset(\Pramnos\Translator\Language::getInstance()->getlang()['Account Dashboard']))`.

`$lang->currentlang()` tells you which language was resolved, which separates the two in
one line.

### Text that is not for whoever made the request

An email is the case. The language of a request belongs to the person who made it; the
language of a notification belongs to the person who *receives* it — and those are different
people whenever an operator resets somebody's password from an English administration
screen, or a queue worker with no language at all sends a code.

```php
$mail = \Pramnos\Translator\Language::using($user->language, fn () => [
    'subject' => t('Password reset'),
    'body'    => t('We received a request to reset your password.'),
]);
```

`Notifier::sendNow()` already does this for every notification: if the notifiable has a
`language`, the channels render inside it. So a notification needs no special handling, and
only mail composed outside the notification system — like the password-reset link — asks for
it directly. See the [Notifications guide](Pramnos_Notifications_Guide.md).

Two things it does that a hand-rolled switch does not:

- **It restores the catalogue, not just the name.** `load()` *merges* — `addlang()` is an
  `array_merge` — so switching by calling `load()` twice leaves the second language's
  translations in place, and the next message in the first language comes out in the second.
  Nothing raises; it is noticed by a recipient.
- **It ignores a language that is not installed.** The name usually comes from
  `users.language`, which is data, and `load()` builds a path out of it.


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

### `l()` echoes, `t()` returns

Two helpers, and the difference is the whole reason both exist:

```php
l('Hello, World!');            // echoes  — for a template
$title = t('Hello, World!');   // returns — for a value
```

`l()` is right inside a view and useless anywhere a translation is a *value*: a document
title, a flash message, an array of labels, an exception. Those call sites had only
`\Pramnos\Framework\Factory::getLanguage()->_(…)`, which is long enough that most of them
kept an English literal instead — the framework's own account screens among them, whose
page titles were hardcoded until `t()` existed.

Both take the same arguments and format by the same rules as `_()`.

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

### Formatting: what happens with and without arguments

`_()` (and `l()`, which forwards to it) formats the translation **only when you
pass arguments**. That rule decides three behaviours worth knowing:

```php
// Language file: '%s is on air' => '%s εκπέμπει τώρα'

$lang->_('%s is on air');           // '%s εκπέμπει τώρα'  — verbatim
$lang->_('%s is on air', 'Aroma');  // 'Aroma εκπέμπει τώρα'
```

**No arguments returns the translation as it is**, placeholders included. That is
what lets a call site look a key up and format it itself, and it is why a key
with a `%s` in it is safe to translate before every call site is ready.

**Arguments are spread across the placeholders in order** (`vsprintf`
semantics), so a translation may use positional specifiers to reorder them —
which is often necessary, since word order differs between languages:

```php
// 'Welcome %1$s, you have %2$d messages'
//   => '%2$d μηνύματα σας περιμένουν, %1$s'
l('Welcome %1$s, you have %2$d messages', $username, $count);
```

**A mismatch is not fatal.** If a translation asks for more placeholders than the
call site passes, or contains a specifier that is not valid, the *unformatted*
translation is returned and the mismatch is written to the error log. Language
files are content — a translator adding a stray `%s` must not be able to take a
page down. Check the log if a page shows a raw `%s` where a value belongs.

> **Fixed in this release.** `_()` used to call `sprintf()` on every translation
> it found, whether or not arguments were given, and passed those arguments as a
> single array. On PHP 8 both are fatal: looking up a translation containing
> `%s` with no arguments raised `ArgumentCountError`, and one *with* arguments
> printed `Array` for the first placeholder. The examples above were in this
> guide before they worked. If your application worked around it by reading
> `getlang()` and formatting the string itself, that workaround can now go.

### Translating a front end from the same catalogue

A SPA cannot call `_()`. Without an endpoint a front end either ships no
translation at all or grows a **second** catalogue — and a second catalogue
means a string that moves between a component and a controller loses its
translation, silently, in whichever direction it moved.

So `scaffold:spa` writes a controller answering `GET {apiPrefix}/language`,
which serves this installation's own map, and `lib/i18n.svelte.js` is a client
for it:

```js
import { t, tHtml, loadLanguage, availableLanguages } from './lib/i18n.svelte.js';

await loadLanguage();            // the signed-in account's language
await loadLanguage('greek');     // or a named one, for the sign-in screen

t('Save');                       // 'Αποθήκευση'
t('%s is on air', 'Aroma');      // same key, same %s rule as _()
```

Same key (the English source), same fallback (the key itself), same `%s`
substitution. A string translated for a server-rendered page is translated for a
screen.

Three properties worth knowing:

- **`tHtml()` keeps the translation's markup live and escapes what is
  substituted into it.** Some translations carry `<strong>`; a value arriving at
  run time from an API or another user is script. A translator writing a tag is
  trusted; a runtime value is not.
- **The endpoint is unauthenticated.** The sign-in screen needs its labels, and
  a catalogue is the same text the server-rendered pages emit to anybody.
- **A language nobody ships is refused, not substituted.** `load()` falls back
  to English on its own, so an endpoint that reported the request as applied
  would leave a client believing it is in Greek and never asking again.

It is written only when `app/language/` exists — a project with no catalogue
does not need an endpoint over an empty array, and `t()` returning its own key is
already correct with no endpoint at all.

### One language object, and how to make it yours

Everything that translates goes through `Language::getInstance()`, and there is exactly
one instance. That matters more than it sounds: an application that ends up with **two** —
its own with the strings loaded, the framework's without — gets a page that renders half in
English, and nothing reports it. Both objects return the key unchanged for a missing
translation, so *"untranslated key"* and *"wrong instance"* look identical.

#### Declaring your own subclass

```php
// app/app.php
'language_class' => '\MyApp\Language',
```

With nothing declared, `\<namespace>\Language` is tried — the same convention the
application class uses — and the framework's own class is the fallback. A declaration that
names a missing class, or a class that is not a `Language`, is ignored rather than fatal: a
translation setting should not be able to take a site down.

**Declared rather than inferred, on purpose.** The obvious alternative is `new static()` in
`getInstance()`, and it does work — PHP 8.1 and later share a method's static locals with
its inherited copies. But *which* class you get then depends on who asks first:

| asks first | you get |
|---|---|
| your subclass | your subclass, overrides running |
| the framework | the base class, overrides never running |

`Factory::getLanguage()` is called from seven places inside the framework, so the order is
not yours to control. Declaring the class removes the race.

#### Handing over an object you built yourself

```php
\Pramnos\Translator\Language::setInstance(new \MyApp\Language('el', $myPath));
```

For a bootstrap that constructs it with arguments the framework cannot supply. A different
question from `language_class`: that one is *which class*, this is *here is the object*.

`resetInstance()` exists for tests — without it the first test in a run decides the
language for every test after it.

### Catching a missing translation

Override `onMissingString()` to get one last chance at a key that has no translation:

```php
namespace MyApp;

class Language extends \Pramnos\Translator\Language
{
    protected function onMissingString(string $string): string
    {
        // Record it, so a translation tool has something to work from
        MissingStrings::note($string);

        // …or answer it, for a regional variant kept as a secondary catalogue
        return Dialect::lookup($string) ?? $string;
    }
}
```

The default returns the key, which is what `_()` does on its own. Two things worth
knowing:

- **Whatever you return is formatted with the caller's arguments**, exactly as a stored
  translation would be. So a supplied `'Καλώς ήρθες, %s'` works with `_('greeting',
  $name)`. The legacy filter this replaces returned its result raw, which silently dropped
  the arguments — harmless there only because none of its languages used a placeholder.
- **Returning the key unchanged means "I have nothing"**, and returning `''` means "show
  nothing". Identity against the key is the test, not emptiness, so an empty translation is
  a decision you can make.

The hook is only consulted on a **miss**. A key with a translation never reaches it.

### Where language files are looked for

`load()` searches, in order: the path given to the constructor, `LANGPATH`,
`ROOT/app/language`, then `ROOT/language`. The requested language across all of them
first, then `english` across all of them.

That second pass is the part worth stating, because it used to be broken: the English
fallback existed only under `ROOT/language`, so a project laid out the way `init` lays one
out — with `app/language/` — did **not** fall back to English when a language file was
missing. It returned `false` and the page rendered untranslated.

`getFlag()` is narrower on purpose, and answers a different question: a flag has to be
*servable*, not merely present. It looks under the web root (`www/assets/flags/`) and at
the historical `ROOT/language/`, and returns `false` for a flag sitting anywhere a browser
cannot reach — which is the truth about it, and better than a URL that 404s.

### Listing the languages an installation ships

```php
$languages = \Pramnos\Translator\Language::getLanguages();   // ['english', 'greek']
```

It looks where `load()` looks: `LANGPATH`, then `app/language/`, then
`ROOT/language/`, merged and de-duplicated.

> **Fixed 2026-08-24.** It scanned `ROOT/language` and nothing else, while
> `load()` reads `app/language` first — the layout `init` actually generates. So
> on a normal project it threw *"Languages directory does not exist"* while
> `_()` was translating happily, and anything offering a language picker had
> nothing to put in it.

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
- `_($string, ...$args)` - Translate a string; formats it with `$args` when any are given, returns it verbatim when none are

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
- [Framework Guide](Pramnos_Framework_Guide.md) - Application-level language settings
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
