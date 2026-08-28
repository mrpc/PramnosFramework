---
use_cases:
  - Sending an email from application code
  - Configuring SMTP or another transport
  - Tracking or debugging delivery
---

# Pramnos Framework - Email System Guide

The Pramnos Framework includes a comprehensive email system built on top of Symfony Mailer that provides a clean, flexible API for sending emails with advanced features like tracking, templates, and multiple transport options.

## Table of Contents

1. [Overview](#overview)
2. [Basic Usage](#basic-usage)
3. [Configuration](#configuration)
4. [Advanced Features](#advanced-features)
5. [Email Tracking](#email-tracking)
6. [SMTP Configuration](#smtp-configuration)
7. [Error Handling](#error-handling)
8. [Best Practices](#best-practices)
9. [API Reference](#api-reference)

## Overview

The Email system (`\Pramnos\Email\Email`) is a powerful wrapper around Symfony Mailer that provides:

- **Simple API**: Fluent interface for building and sending emails
- **Multiple Transport Options**: SMTP, local mail, and more
- **Email Tracking**: Built-in open tracking with database logging
- **Attachment Support**: File attachments with validation
- **Template Integration**: Works seamlessly with the theme system
- **Error Handling**: Comprehensive error reporting and debugging
- **Priority Support**: Set email priority levels
- **Advanced Headers**: Custom headers, read receipts, unsubscribe links

## Basic Usage

### Sending a Simple Email

```php
use Pramnos\Email\Email;

// Create new email instance
$email = new Email();

// Set email properties and send
$email->setSubject('Welcome to our service')
      ->setBody('<h1>Welcome!</h1><p>Thank you for joining us.</p>')
      ->setTo('user@example.com')
      ->setFrom('noreply@yoursite.com')
      ->send();
```

### Using the Static Method

```php
// Quick static method for simple emails
$result = Email::sendMail(
    'Subject Line',                    // Subject
    '<p>HTML email content</p>',       // Body (HTML)
    'recipient@example.com',           // To
    'sender@yoursite.com',             // From
    '',                                // Attachment path (optional)
    false,                             // Batch mode (optional)
    'reply@yoursite.com'               // Reply-to (optional)
);

if ($result['success']) {
    echo "Email sent successfully!";
} else {
    echo "Error: " . $result['error'];
}
```

### Multiple Recipients

```php
$email = new Email();
$email->setSubject('Newsletter')
      ->setBody('<h1>Monthly Newsletter</h1>')
      ->setTo([
          'user1@example.com' => 'John Doe',
          'user2@example.com' => 'Jane Smith',
          'user3@example.com'  // Email without name
      ])
      ->setCc('manager@example.com')
      ->setBcc('archive@example.com')
      ->send();
```

## Configuration

### SMTP Settings

Configure SMTP settings in your application settings:

```php
use Pramnos\Application\Settings;

// Basic SMTP configuration
Settings::setSetting('smtp_host', 'smtp.yourprovider.com');
Settings::setSetting('smtp_user', 'your-username');
Settings::setSetting('smtp_pass', 'your-password');
Settings::setSetting('smtp_port', 587);
Settings::setSetting('smtp_tls', 'yes');

// Default from address
Settings::setSetting('admin_mail', 'noreply@yoursite.com');
Settings::setSetting('sitename', 'Your Website Name');
Settings::setSetting('admin_replymail', 'support@yoursite.com');
```

### AWS SES Configuration

For Amazon SES (port 587 with STARTTLS):

```php
Settings::setSetting('smtp_host', 'email-smtp.us-west-2.amazonaws.com');
Settings::setSetting('smtp_user', 'your-ses-access-key');
Settings::setSetting('smtp_pass', 'your-ses-secret-key');
Settings::setSetting('smtp_port', 587);
Settings::setSetting('smtp_tls', 'yes');
```

## Advanced Features

### Email with Attachments

```php
$email = new Email();
$email->setSubject('Document Attached')
      ->setBody('<p>Please find the attached document.</p>')
      ->setTo('user@example.com')
      ->setFrom('sender@example.com');

// Set attachment path
$email->attach = '/path/to/document.pdf';

$email->send();
```

### Setting Email Priority

```php
$email = new Email();
$email->priority = 1; // 1 = highest, 5 = lowest (default: 3)
$email->setSubject('Urgent: Action Required')
      ->setBody('<p>This is an urgent message.</p>')
      ->setTo('user@example.com')
      ->send();
```

### Custom Headers

```php
$email = new Email();
$email->addHeader('X-Campaign-ID', 'newsletter-2024-01')
      ->addHeader('X-Mailer', 'Pramnos Framework');

// Built-in header support
$email->organization = 'Your Company Name';
$email->unsubscribe = 'mailto:unsubscribe@yoursite.com';
$email->abuse = 'abuse@yoursite.com';
$email->returnPath = 'bounces@yoursite.com';

$email->send();
```

### Read Receipts

```php
$email = new Email();
$email->sendReceipt = true;
$email->setSubject('Important Document')
      ->setBody('<p>Please confirm you have received this.</p>')
      ->setTo('user@example.com')
      ->send();
```

## Message templates, and the screen that edits them

The `messaging` feature ships `mailtemplates` — a table, a model, and a lookup by
`(category, language, type)`. One notification is several rows: the same category and
channel, one row per language.

```php
$template = (new \Pramnos\Messaging\MailTemplate($controller))
    ->findByKey('auth.passwordreset', 'el', \Pramnos\Messaging\MailTemplate::TYPE_EMAIL);
```

**`/admin/MailTemplates` is where an operator edits them.** Until it existed the table was
reachable only through a database client, which in practice meant the templates were not
edited at all: a project that wanted to change the wording of a password-reset email
changed the code that composes it and left the template unused.

Three things the screen does, each because leaving it out makes the screen decorative:

- **It lists the placeholders**, read from the template's own body and subject — a
  documented list goes stale the first time an application adds one, and an editor that
  shows none is a form where a typo produces a mail with a literal `{nmae}` in it. CSS
  braces are not mistaken for placeholders.
- **It groups the language variants**, so the list answers "is the reset email translated
  into Greek" instead of showing eighty flat rows.
- **It sends a test**, because the only way to know a template renders is to render it.
  Placeholders arrive as `[name]` — visible where each one lands, without invented data
  that would hide a missing one.

The body is stored as written: an email template *is* markup, and a screen that sanitised
it would make the feature useless. It is escaped where it is displayed — into a
`<textarea>` and a `<pre>` — which is the correct half to do it in.

## Which language a message is written in

`Notifier::sendNow()` renders every notification in the **recipient's** language — the
`language` property of the notifiable, which on a `User` is `users.language`. A notifiable
without one (a `PlainAddress`, an account that never chose) is sent in whatever language the
installation is currently using.

That is the only correct answer and it was not the old one: the language of a request belongs
to whoever made it, so an operator resetting a password from an English administration screen
sent an English mail to an account whose every screen is Greek, and a queue worker sent
whatever the default was.

Mail composed outside the notification system asks for the same thing itself — see
`Language::using()` in the Internationalization guide. The framework's own auth mail
(codes, sign-in alerts, security changes, the reset link) is all translatable: the keys are
the English sentences, so an application supplies its language file and nothing else.

## The wrapper a message is sent in

Bodies are fragments — a paragraph, a code, a link — and every application wants the same
shell around all of them: its logo, its colours, a footer with a company name in it. That
shell is a **wrapper**, named rather than derived:

```php
// Every message, from the settings
Settings::setSetting('emailtheme', 'default');

// Or one message
(new Email())->setBody('<p>Your code is 123456</p>')->setTemplate('branded')->send();
```

`mailtemplates.emailtemplate` is the per-template version of the same choice, and the
test-send on `/admin/MailTemplates` uses it — so what arrives in a test is what a recipient
would get.

**Off until it is named.** `emailtheme` is empty on an existing installation and an empty
name wraps nothing: bodies go out exactly as they did before. That matters more than it
sounds — an application whose bodies are already complete documents would otherwise get a
second `<html>` inside the first on an upgrade.

**`null`, `''` and a name are three different things.** `null` (the default) takes the
installation's setting; a name overrides it; and `''` sends *this* message bare, which is
the only way to send an unwrapped one from an installation that wraps everything — a body
that is already a whole document, or one meant to be parsed rather than read.

### Where a wrapper lives

`{name}.html.php`, in the first of these that has it:

| Path | For |
| --- | --- |
| `app/emails/` | the application's own |
| `emails/` at the project root | an older layout |
| the framework's bundled copy | so `default` resolves with nothing published |

Copy the bundled `default` into `app/emails/` and edit it there: the application's file of
the same name wins, so the copy is the customisation and the bundled one stays the
fallback.

Not per theme, deliberately. A theme is a stylesheet and an email cannot use one — HTML mail
is nested tables and inline attributes, because Outlook renders with Word's engine and
Gmail strips `<style>` from anything forwarded. An application that wants two looks names
two wrappers.

### What the file receives

```php
<?php /* app/emails/branded.html.php */ ?>
<!DOCTYPE html>
<html><body>
    <h1><?php echo htmlspecialchars($sitename); ?></h1>
    <?php echo $content; ?>
    <footer><?php echo htmlspecialchars($sitename . ' · ' . $year); ?></footer>
</body></html>
```

`$content` is the body, already HTML. `$subject`, `$sitename`, `$siteurl` and `$year` come
with it, so a wrapper needs no arguments; anything else passed to `EmailTheme::wrap()` is in
scope under its own key. `$content` is the one variable a wrapper cannot replace.

### It fails open, and the name is not a path

A wrapper that is missing, or that raises while rendering, logs to the `email` log and the
message is sent **unwrapped**. A mail whose shell is broken still has to be delivered: the
code in it is what somebody is waiting for, and a missing footer is not a reason to withhold
it. That is also what makes a typo in a settings field cost one unbranded email rather than
every email.

The name is checked against `[A-Za-z0-9_-]` before it reaches a path, because it arrives
from a column an administrator edits. Anything with a separator in it is refused rather than
sanitised — there is no correct number of `..` segments to strip, and a name with a slash in
it was never a wrapper name.

## A message to many accounts

`massmessages` and `massmessagerecipients` have been in the schema since the messaging
feature shipped, with a model each and nothing that composed, sent or displayed one. There
is now `/admin/MassMessages`, and behind it two classes:

```php
$audience = (new MassMessageAudience())->resolve(['usertype_min' => 0]);
(new MassMessageDispatcher())->queue($messageId, $audience);   // once, in the request
(new MassMessageDispatcher())->dispatch(100);                  // over and over, on a timer
```

### Queueing and delivering are separate because they fail differently

`queue()` writes one recipient row per account and returns. `dispatch()` — driven by
`messages:dispatch`, scheduled every five minutes — takes pending recipients in batches and
marks each one as it is attempted.

A send of four thousand emails inside a POST is a request that times out halfway, leaving an
operator with no idea how far it got and a page offering to send again. Here the answer to
"how far did it get" is a row count, and the answer to "is it safe to run again" is yes.

**Queueing is the step that must not repeat.** A message that already has recipients is
refused rather than queued a second time: everything else on this screen is recoverable, and
that one reaches every person on the list.

### What each channel means

| Type | Delivery | Failure |
| --- | --- | --- |
| `TYPE_EMAIL` | one email per recipient, in the recipient's language and this installation's wrapper | the mailer refused it |
| `TYPE_MESSAGE` | a row in `messages` — the account's own inbox | the write failed |
| `TYPE_PUSH` | nothing; the framework has no push transport | every recipient, so it is visible |

Push is refused rather than skipped. An operator who chose it is owed "there is no transport
for this", not a message that reports itself sent to nobody.

### The audience is resolved once

Criteria are stored on the message (`request`, as JSON); the list they meant is stored in
`massmessagerecipients`. Re-resolving at delivery time would silently include accounts
created after somebody approved the send and drop the ones deleted since — so the recipients
would stop matching what was approved.

`usertype_min`, `validated_only` and `active_only`, all defaulting to "every account that can
actually receive something". An application with its own idea of an audience hands
`queue()` its own list of ids instead.

The compose screen counts the audience **before** anybody presses send, because a count is
the one number that changes an operator's mind, and it is exactly the number nobody has when
the send is a loop somebody wrote in a controller.

## Email Tracking

The framework includes built-in email tracking functionality that can track when emails are opened.

### Enabling Tracking

```php
$email = new Email();
$email->setSubject('Welcome!')
      ->setBody('<h1>Welcome to our service!</h1>')
      ->setTo('user@example.com')
      ->enableTracking()  // Enable automatic tracking
      ->send();

// Or with custom tracking ID
$email->enableTracking('user_123_welcome_email');
```

### Setting Up Tracking Route

Create a route in your application to handle tracking requests:

```php
// In your router configuration
$router->get('/email-track', function() {
    $trackingId = $_GET['id'] ?? '';
    \Pramnos\Email\Email::handleTrackingRequest($trackingId);
});
```

### Tracking Configuration

```php
use Pramnos\Application\Settings;

// Configure tracking settings
Settings::setSetting('site_url', 'https://yoursite.com');
Settings::setSetting('email_tracking_path', '/email-track');
```

### Database Schema for Tracking

The tracking system requires an `email_tracking` table:

```sql
CREATE TABLE email_tracking (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tracking_id VARCHAR(255) UNIQUE,
    recipient TEXT,
    subject VARCHAR(255),
    sent_at DATETIME,
    opened TINYINT DEFAULT 0,
    opened_at DATETIME NULL
);
```

### Privacy Considerations

- **Disclosure**: Email tracking should be disclosed in your privacy policy
- **Consent**: Some jurisdictions require explicit consent for tracking
- **Blocking**: Some email clients block tracking pixels automatically

## SMTP Configuration

### Transport Types

The email system automatically selects the appropriate transport based on configuration:

```php
// Port 465 - Implicit SSL (smtps://)
Settings::setSetting('smtp_port', 465);
Settings::setSetting('smtp_tls', 'yes');

// Port 587 - STARTTLS (smtp://)
Settings::setSetting('smtp_port', 587);
Settings::setSetting('smtp_tls', 'yes');

// Port 25 - Plain SMTP (smtp://)
Settings::setSetting('smtp_port', 25);
Settings::setSetting('smtp_tls', 'no');
```

### Debug Mode

Enable debug mode to troubleshoot email issues:

```php
$email = new Email();
$email->setDebug(true)  // Enable debug logging
      ->setSubject('Test Email')
      ->setBody('<p>This is a test.</p>')
      ->setTo('test@example.com')
      ->send();
```

## Error Handling

### Checking for Errors

```php
$email = new Email();
$success = $email->setSubject('Test')
                 ->setBody('<p>Test content</p>')
                 ->setTo('user@example.com')
                 ->send();

if (!$success) {
    // Check for errors
    if ($email->hasError()) {
        $errorMessage = $email->getLastError();
        $exception = $email->getLastException();
        
        echo "Email failed: " . $errorMessage;
        
        // Log the full exception if needed
        if ($exception) {
            \Pramnos\Logs\Logger::log("Email error: " . $exception->getTraceAsString());
        }
    }
}
```

### Common Error Scenarios

1. **Authentication Failed**: Check SMTP credentials
2. **Connection Timeout**: Verify host and port settings
3. **TLS/SSL Issues**: Ensure proper encryption settings
4. **Attachment Not Found**: Verify file paths and permissions
5. **Invalid Recipients**: Check email address formats

## Best Practices

### 1. Configuration Management

```php
// Store sensitive settings securely
Settings::setSetting('smtp_pass', env('SMTP_PASSWORD'));
Settings::setSetting('smtp_user', env('SMTP_USERNAME'));
```

### 2. Template Usage

```php
// Use templates for consistent email design
$email = new Email();
$template = file_get_contents('templates/welcome-email.html');
$template = str_replace('{{username}}', $user->name, $template);

$email->setBody($template);
```

### 3. Batch Processing

```php
// For large email lists, use batch processing
$emails = ['user1@example.com', 'user2@example.com', /* ... */];

foreach (array_chunk($emails, 50) as $batch) {
    foreach ($batch as $recipient) {
        $email = new Email();
        $email->setTo($recipient)
              ->setSubject('Newsletter')
              ->setBody($content)
              ->send();
        
        // Add delay to avoid rate limiting
        usleep(100000); // 0.1 second delay
    }
}
```

### 4. Error Logging

```php
$email = new Email();
$success = $email->send();

if (!$success) {
    \Pramnos\Logs\Logger::log(
        "Email failed to: " . $email->to . 
        " Error: " . $email->getLastError()
    );
}
```

### 5. Testing

```php
// Use a test mode flag
if (defined('EMAIL_TEST_MODE') && EMAIL_TEST_MODE) {
    // Override recipient for testing
    $email->setTo('test@yoursite.com');
}
```

## API Reference

### Email Class Methods

#### Configuration Methods

- `setSubject(string $subject)` - Set email subject
- `setBody(string $body)` - Set HTML email body
- `setTo(mixed $to)` - Set recipient(s)
- `setFrom(mixed $from)` - Set sender
- `setCc(mixed $cc)` - Set CC recipients
- `setBcc(mixed $bcc)` - Set BCC recipients
- `addHeader(string $name, string $value)` - Add custom header

#### Sending Methods

- `send()` - Send the email (returns boolean)
- `static sendMail(...)` - Static method for quick sending

#### Tracking Methods

- `enableTracking(string $id = null)` - Enable email tracking
- `static handleTrackingRequest(string $trackingId)` - Handle tracking pixel requests

#### Error Handling Methods

- `hasError()` - Check if last send operation had errors
- `getLastError()` - Get last error message
- `getLastException()` - Get last exception object

#### Debug Methods

- `setDebug(bool $enable)` - Enable/disable debug logging

### Properties

#### Email Content
- `$subject` - Email subject line
- `$body` - Email body (HTML)
- `$to` - Recipient(s)
- `$from` - Sender
- `$cc` - CC recipients
- `$bcc` - BCC recipients
- `$replyto` - Reply-to address

#### Settings
- `$priority` - Email priority (1-5)
- `$attach` - Attachment file path
- `$batch` - Batch sending mode

#### Advanced Headers
- `$sendReceipt` - Request read receipt
- `$returnPath` - Return path for bounces
- `$organization` - Organization header
- `$abuse` - Abuse report address
- `$unsubscribe` - Unsubscribe link
- `$headers` - Custom headers array

#### Tracking
- `$trackingId` - Tracking identifier
- `$debug` - Debug mode flag

## Related Documentation

- [Framework Guide](Pramnos_Framework_Guide.md) - Core framework concepts
- [Framework Guide](Pramnos_Framework_Guide.md) - Application structure and settings
- [Database Guide](Pramnos_Database_API_Guide.md) - Database operations for tracking
- [Logging Guide](Pramnos_Logging_Guide.md) - Error logging and debugging
- [Theme Guide](Pramnos_Theme_Guide.md) - Email templates and theming

## Troubleshooting

### Common Issues

1. **SMTP Authentication Failed**
   - Verify username/password
   - Check if app-specific passwords are required
   - Ensure SMTP is enabled on your email provider

2. **Connection Refused**
   - Verify SMTP host and port
   - Check firewall settings
   - Ensure TLS/SSL settings match provider requirements

3. **Emails Going to Spam**
   - Set up SPF, DKIM, and DMARC records
   - Use proper from addresses
   - Avoid spam trigger words in subject/content

4. **Tracking Not Working**
   - Verify tracking route is properly configured
   - Check database table exists
   - Ensure tracking pixel URL is accessible

For additional support, refer to the framework's logging system to capture detailed error information.
