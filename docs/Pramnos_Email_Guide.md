---
use_cases:
  - Sending an email from application code
  - Configuring SMTP or another transport
  - Replying in the same thread as the message being answered
  - Adding an application's own columns, statuses or resend rule to the email history screen
---

# Pramnos Framework - Email System Guide

The Pramnos Framework includes a comprehensive email system built on top of Symfony Mailer that provides a clean, flexible API for sending emails with advanced features like tracking, templates, and multiple transport options.

The email pages:

- **Email** (this page)
- [Writing a message: templates, wrapper, editor, the parts](Pramnos_Email_Writing_Guide.md)
- [Mail to many: kinds of mail, unsubscribing, mailing lists](Pramnos_Email_Mass_Mail_Guide.md)
- [Deliverability: tracking, Gmail actions, DNS, bounces](Pramnos_Email_Deliverability_Guide.md)
- [The outbox, and what is kept of a sent message](Pramnos_Email_Outbox_Guide.md)

## Overview

The Email system (`\Pramnos\Email\Email`) is a powerful wrapper around Symfony Mailer that provides:

- **Simple API**: Fluent interface for building and sending emails
- **SMTP transport**: the installation's `smtp_*` settings, or per message another SMTP server (`via()`) or any Symfony Mailer transport (`withTransport()`)
- **Email Tracking**: opt-in open and click tracking for list mail, recorded in the database
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
```

`smtp_pass` is encrypted at rest, and the `/admin/Settings` screen **never prints it
back**: the field renders empty, submitting it empty means "keep what is stored", and the
placeholder is what tells you whether there is one. To clear it, write an empty value
through `Settings::setSetting()`.

With no `smtp_host` every send fails, with `getLastError()` reading *SMTP not configured:
smtp_host is empty.* `Email::isConfigured()` asks the same question before a send.

```php
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

The debug lines go to the framework log: the SMTP host, user and port, the scheme chosen for
the port, and whether a password is set. Nothing about the password itself is written, neither
characters nor length, because that log is the one people paste into tickets.

## Reading an email

`Pramnos\Email\MailMessage::parse($raw)` reads a saved or received email — `.eml`, or a message
fetched from a mailbox — with the standard library: its `subject`, `from`, `reply_to`,
`message_id`, `references`, `date`, `to` (every address it was sent to), `text` and `attachments`
(name, type and bytes each). Multipart parts are walked at any depth, base64 and quoted-printable
decoded, charsets and encoded-word headers turned to UTF-8; the plain-text part wins, and HTML is
read as its words when it is all there is. `original` is the email it forwards, when it forwards
one — from an attached message or the block a mail program writes above it — so the first sender
is known. `cleanSubject()` drops `Fwd:` and its kin; `looksLikeOne()` says whether bytes are an
email at all. It is not a full RFC 5322 reader: no S/MIME, no TNEF.

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
   - Check `'email' => ['tracking' => true]` in `app/app.php`
   - Check the message is list mail (`offerUnsubscribe()` or a `type()` with a list) and called `enableTracking()` — transactional mail is never tracked
   - Check the `emailtracking` and `emailtrackingclicks` tables exist (`migrate`)
   - Ensure `/emailpixel` and `/emailclick` are reachable from outside

For additional support, refer to the framework's logging system to capture detailed error information.
