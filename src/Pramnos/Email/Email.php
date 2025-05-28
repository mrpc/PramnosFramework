<?php
namespace Pramnos\Email;
/**
 * @package     PramnosFramework
 * @subpackage  Email
 */
class Email extends \Pramnos\Framework\Base
{
    /**
     * Whether to request a read receipt for this email
     * @var bool
     */
    public $sendReceipt = false;
    
    /**
     * Return path for this email (where bounces should go)
     * @var string|null
     */
    public $returnPath = NULL;
    
    /**
     * Organization header value
     * @var string|null
     */
    public $organization = NULL;
    
    /**
     * Abuse report address
     * @var string|null
     */
    public $abuse = NULL;
    
    /**
     * Unsubscribe link or email address
     * @var string|null
     */
    public $unsubscribe = NULL;
    
    /**
     * Custom email headers
     * @var array
     */
    public $headers = array();
    
    /**
     * Email priority (1-5, 1=highest, 5=lowest)
     * @var int
     */
    public $priority = 3;
    
    /**
     * Email subject line
     * @var string
     */
    public $subject = '';
    
    /**
     * Email body content (HTML)
     * @var string
     */
    public $body = '';
    
    /**
     * Recipient email address(es) - can be string or array
     * @var string|array
     */
    public $to = '';
    
    /**
     * Sender email address - can be string or array
     * @var string|array
     */
    public $from = '';
    
    /**
     * Path to a file to attach to the email
     * @var string
     */
    public $attach = "";
    
    /**
     * Whether to use batch sending mode
     * @var bool
     */
    public $batch = false;
    
    /**
     * Reply-to address for the email
     * @var string
     */
    public $replyto = '';
    
    /**
     * Carbon copy recipients
     * @var string|array
     */
    public $cc = '';
    
    /**
     * Blind carbon copy recipients
     * @var string|array
     */
    public $bcc = '';
    
    /**
     * Tracking ID for email opens
     * @var string
     */
    public $trackingId = '';

    /**
     * Enable debug logging
     * @var bool
     */
    public $debug = false;

    /**
     * Store the last error message
     * @var string
     */
    private $lastError = '';
    
    /**
     * Store the last exception
     * @var \Exception|null
     */
    private $lastException = null;

    function __construct()
    {
        parent::__construct();
    }

    public static function &getInstance()
    {
        static $instance;
        if (!is_object($instance)) {
            $instance = new Email;
        }
        return $instance;
    }

    public function addHeader($header, $content)
    {
        $this->headers[$header] = $content;
        return $this;
    }

    /**
     * Set the email subject
     * @param string $subject
     * @return $this
     */
    public function setSubject($subject)
    {
        $this->subject = $subject;
        return $this;
    }

    /**
     * Set the email body
     * @param string $body
     * @return $this
     */
    public function setBody($body)
    {
        $this->body = $body;
        return $this;
    }

    /**
     * Set the recipient(s)
     * @param mixed $to Email address or array of addresses
     * @return $this
     */
    public function setTo($to)
    {
        $this->to = $to;
        return $this;
    }

    /**
     * Set the sender
     * @param mixed $from Email address or array with address => name
     * @return $this
     */
    public function setFrom($from)
    {
        $this->from = $from;
        return $this;
    }

    /**
     * Set CC recipient(s)
     * @param mixed $cc Email address or array of addresses
     * @return $this
     */
    public function setCc($cc)
    {
        $this->cc = $cc;
        return $this;
    }

    /**
     * Set BCC recipient(s)
     * @param mixed $bcc Email address or array of addresses
     * @return $this
     */
    public function setBcc($bcc)
    {
        $this->bcc = $bcc;
        return $this;
    }

    /**
     * Enable or disable debug logging
     * 
     * @param bool $enable Whether to enable debug logging
     * @return $this
     */
    public function setDebug($enable = true)
    {
        $this->debug = (bool)$enable;
        return $this;
    }

    /**
     * Send the email
     * @return boolean
     */
    public function send()
    {
        try {
            // Reset last error before attempting to send
            $this->lastError = '';
            $this->lastException = null;
            
            return $this->sendWithSymfonyMailer();
        }
        catch (\Exception $exception) {
            $this->lastError = $exception->getMessage();
            $this->lastException = $exception;
            \Pramnos\Logs\Logger::log("Email error: " . $exception->getMessage() . "\n" . $exception->getTraceAsString());
            return false;
        }
    }
    
    /**
     * Send email using Symfony Mailer
     * @return boolean
     */
    protected function sendWithSymfonyMailer()
    {
        $host = \Pramnos\Application\Settings::getSetting("smtp_host");
        $user = \Pramnos\Application\Settings::getSetting("smtp_user");
        $pass = \Pramnos\Application\Settings::getSetting("smtp_pass");
        $port = \Pramnos\Application\Settings::getSetting('smtp_port');
        $useTls = \Pramnos\Application\Settings::getSetting('smtp_tls') == 'yes';


         // Advanced debugging for credentials
        $this->debugLog("Credentials check:");
        $this->debugLog("- SMTP Host: {$host}");
        $this->debugLog("- SMTP User: {$user}");
        $this->debugLog("- SMTP Port: {$port}");
        $this->debugLog("- Password length: " . strlen($pass) . " chars");
        $this->debugLog("- First 4 chars of password: " . substr($pass, 0, 4));
        
        
        
        // Log SMTP settings (without password)
        $this->debugLog("Sending mail via SMTP: {$host}:{$port}, User: {$user}, TLS: " . ($useTls ? 'yes' : 'no'));
        
        try {
            // Amazon SES and many other SMTP servers require explicit TLS settings
            // Determine the correct scheme based on port and TLS settings
            if ($port == 465) {
                // Port 465 always uses implicit SSL
                $scheme = 'smtps';
                $this->debugLog("Using smtps (implicit SSL) for port 465");
            } else if ($port == 587 && $useTls) {
                // Port 587 typically uses STARTTLS (explicit TLS)
                $scheme = 'smtp';
                $this->debugLog("Using STARTTLS for port 587");
            } else if ($useTls) {
                // Other ports with TLS enabled
                $scheme = 'smtps';
                $this->debugLog("Using smtps (implicit SSL) based on TLS setting");
            } else {
                // Plain SMTP without encryption
                $scheme = 'smtp';
                $this->debugLog("Using plain SMTP without encryption");
            }
            
            // Create DSN with proper configuration
            $dsn = new \Symfony\Component\Mailer\Transport\Dsn(
                $scheme,
                $host,
                $user,
                $pass,
                $port
            );
            
            // For AWS SES and similar services on port 587, we need to set explicit STARTTLS mode
            if ($port == 587 && $useTls) {
                $factory = new \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory();
                $transport = $factory->create($dsn);
                
                // Force STARTTLS if available
                if (method_exists($transport, 'setStartTLS')) {
                    $transport->setStartTLS(true);
                    $this->debugLog("Explicitly enabled STARTTLS on transport");
                }
                
                // Configure authentication mechanisms explicitly for AWS SES
                if (method_exists($transport, 'setAuthMode')) {
                    $transport->setAuthMode('login');
                    $this->debugLog("Explicitly set auth mode to 'login'");
                }
            } else {
                // For other configurations, use the standard transport factory
                $dsnString = sprintf('%s://%s:%s@%s:%d', $scheme, urlencode($user), urlencode($pass), $host, $port);
                $transport = \Symfony\Component\Mailer\Transport::fromDsn($dsnString);
            }
            
            // Create Mailer
            $mailer = new \Symfony\Component\Mailer\Mailer($transport);
            
            // Create Email
            $email = new \Symfony\Component\Mime\Email();
            $email->subject($this->subject)
                  ->html($this->body)
                  ->text(strip_tags($this->body ?? ''));
            
            // Set priority
            $email->priority($this->getPriorityForSymfony());
            
            // Handle recipients (to, cc, bcc) - support multidimensional arrays like SwiftMailer
            $this->addRecipients($email, $this->to, 'to');
            $this->addRecipients($email, $this->cc, 'cc');
            $this->addRecipients($email, $this->bcc, 'bcc');
            
            // Log recipient
            $this->debugLog("Email recipient: " . (is_array($this->to) ? print_r($this->to, true) : $this->to));
            
            // Set From address
            $this->setFromAddress($email);
            
            // Set Reply-To
            if ($this->replyto != '') {
                $email->replyTo(new \Symfony\Component\Mime\Address($this->replyto));
            } elseif (\Pramnos\Application\Settings::getSetting('admin_replymail') != '') {
                $email->replyTo(new \Symfony\Component\Mime\Address(\Pramnos\Application\Settings::getSetting('admin_replymail')));
            }
            
            // Set Return-Path
            if ($this->returnPath !== null && trim((string)$this->returnPath) != '') {
                $email->returnPath(trim((string)$this->returnPath));
            }
            
            // Handle read receipt
            if ($this->sendReceipt == true) {
                if ($this->from != '') {
                    if (is_array($this->from)) {
                        $m = array_keys($this->from);
                        $email->getHeaders()->add(new \Symfony\Component\Mime\Header\UnstructuredHeader('Disposition-Notification-To', $m[0]));
                    } else {
                        $email->getHeaders()->add(new \Symfony\Component\Mime\Header\UnstructuredHeader('Disposition-Notification-To', $this->from));
                    }
                } else if ($this->returnPath !== null && trim((string)$this->returnPath) != '') {
                    $email->getHeaders()->add(new \Symfony\Component\Mime\Header\UnstructuredHeader('Disposition-Notification-To', trim((string)$this->returnPath)));
                } else if (\Pramnos\Application\Settings::getSetting('admin_mail')) {
                    $email->getHeaders()->add(new \Symfony\Component\Mime\Header\UnstructuredHeader(
                        'Disposition-Notification-To',
                        \Pramnos\Application\Settings::getSetting('admin_mail')
                    ));
                }
            }
            
            // Handle unsubscribe header
            if ($this->unsubscribe !== null && trim((string)$this->unsubscribe) != '') {
                $email->getHeaders()->add(new \Symfony\Component\Mime\Header\UnstructuredHeader('List-Unsubscribe', trim((string)$this->unsubscribe)));
            }
            
            // Handle organization header
            if ($this->organization !== null && trim((string)$this->organization) != '') {
                $email->getHeaders()->add(new \Symfony\Component\Mime\Header\UnstructuredHeader('Organization', trim((string)$this->organization)));
            }
            
            // Handle abuse header
            if ($this->abuse !== null && trim((string)$this->abuse) != '') {
                $email->getHeaders()->add(new \Symfony\Component\Mime\Header\UnstructuredHeader('X-Report-Abuse', trim((string)$this->abuse)));
            }
            
            // Add attachment
            if (trim($this->attach) != "") {
                if (file_exists($this->attach)) {
                    $email->attachFromPath($this->attach);
                } else {
                    \Pramnos\Logs\Logger::log("Email attachment not found: " . $this->attach);
                }
            }
            
            // Add headers
            foreach ($this->headers as $name => $value) {
                $email->getHeaders()->add(new \Symfony\Component\Mime\Header\UnstructuredHeader($name, $value));
            }
            
            // Send email
            $mailer->send($email);
            $this->debugLog("Email sent successfully");
            return true;
        } catch (\Exception $e) {
            \Pramnos\Logs\Logger::log("SMTP transport error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            throw $e; // Re-throw to be caught by the outer catch
        }
    }
    
    /**
     * Set the from address on the email object
     * 
     * @param \Symfony\Component\Mime\Email $email The email object
     * @return void
     */
    protected function setFromAddress($email)
    {
        $this->debugLog("Setting from address: " . (is_array($this->from) ? print_r($this->from, true) : $this->from));
        
        if (empty($this->from)) {
            $fromEmail = \Pramnos\Application\Settings::getSetting('admin_mail') ?: 'nobody@pramnoshosting.com';
            $sitename = \Pramnos\Application\Settings::getSetting('sitename') ?: 'Nobody';
            $email->from(new \Symfony\Component\Mime\Address($fromEmail, $sitename));
            $this->debugLog("Using default from address: {$sitename} <{$fromEmail}>");
            return;
        }
        
        // Handle array format (email => name pairs)
        if (is_array($this->from)) {
            foreach ($this->from as $address => $name) {
                try {
                    // If the key is a valid email address
                    if (is_string($address) && filter_var($address, FILTER_VALIDATE_EMAIL)) {
                        $email->from(new \Symfony\Component\Mime\Address($address, $name));
                        $this->debugLog("Added from address with name: {$name} <{$address}>");
                    } 
                    // If the key is numeric and value is an email address
                    else if (is_numeric($address) && is_string($name) && filter_var($name, FILTER_VALIDATE_EMAIL)) {
                        $email->from(new \Symfony\Component\Mime\Address($name));
                        $this->debugLog("Added from address without name: {$name}");
                    } else {
                        $this->debugLog("Invalid from address format: key={$address}, value=" . (is_string($name) ? $name : gettype($name)));
                    }
                    break; // Only use the first from address
                } catch (\Exception $e) {
                    $this->debugLog("Error setting from address {$address}: " . $e->getMessage());
                }
            }
        } 
        // Handle string format (simple email address)
        else if (is_string($this->from) && !empty($this->from)) {
            try {
                $email->from(new \Symfony\Component\Mime\Address($this->from));
                $this->debugLog("Added string from address: {$this->from}");
            } catch (\Exception $e) {
                $this->debugLog("Error setting from address {$this->from}: " . $e->getMessage());
                
                // Fallback to default from
                $fromEmail = \Pramnos\Application\Settings::getSetting('admin_mail') ?: 'nobody@pramnoshosting.com';
                $sitename = \Pramnos\Application\Settings::getSetting('sitename') ?: 'Nobody';
                $email->from(new \Symfony\Component\Mime\Address($fromEmail, $sitename));
                $this->debugLog("Falling back to default from address: {$sitename} <{$fromEmail}>");
            }
        }
    }
    
    /**
     * Handle adding recipients to email with support for multidimensional arrays
     * like those used by SwiftMailer
     * 
     * @param \Symfony\Component\Mime\Email $email
     * @param mixed $recipients String, array, or multidimensional array of recipients
     * @param string $type The type of recipient: 'to', 'cc', or 'bcc'
     * @return void
     */
    protected function addRecipients($email, $recipients, $type = 'to')
    {
        if (empty($recipients)) {
            return;
        }
        
        // Log recipients format for debugging
        $this->debugLog("Raw recipients data: " . print_r($recipients, true));
        
        // Handle string recipient
        if (!is_array($recipients)) {
            switch ($type) {
                case 'cc': $email->addCc($recipients); break;
                case 'bcc': $email->addBcc($recipients); break;
                default: $email->addTo($recipients);
            }
            return;
        }
        
        // Create an array of Address objects for all recipients
        $addresses = [];
        
        // Loop through recipients and collect all address objects
        foreach ($recipients as $key => $value) {
            try {
                // In your specific case, value is name and key is email
                if (is_string($key) && filter_var($key, FILTER_VALIDATE_EMAIL)) {
                    $addresses[] = new \Symfony\Component\Mime\Address($key, $value);
                    $this->debugLog("Added address object: {$value} <{$key}>");
                }
                // For numeric keys, value is the email
                else if (is_numeric($key) && is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $addresses[] = new \Symfony\Component\Mime\Address($value);
                    $this->debugLog("Added address object with no name: {$value}");
                }
                // If value is array, recursively process it
                else if (is_array($value)) {
                    // For nested arrays, we need to call addRecipients recursively
                    $this->addRecipients($email, $value, $type);
                }
                else {
                    $this->debugLog("Invalid recipient format: key={$key}, value=" . (is_string($value) ? $value : gettype($value)));
                }
            }
            catch (\Exception $e) {
                $this->debugLog("Error creating address for {$key}: " . $e->getMessage());
            }
        }
        
        // Add all collected addresses at once
        if (!empty($addresses)) {
            $this->debugLog("Adding " . count($addresses) . " recipients of type {$type}");
            
            // Critical part: We need to add multiple recipients correctly
            switch ($type) {
                case 'cc':
                    // Add each address individually to ensure all are included
                    foreach ($addresses as $address) {
                        $email->addCc($address);
                        $this->debugLog("Adding CC recipient: " . $address->getAddress());
                    }
                    break;
                case 'bcc':
                    // Add each address individually to ensure all are included
                    foreach ($addresses as $address) {
                        $email->addBcc($address);
                        $this->debugLog("Adding BCC recipient: " . $address->getAddress());
                    }
                    break;
                default:
                    // Add each address individually to ensure all are included
                    foreach ($addresses as $address) {
                        $email->addTo($address);
                        $this->debugLog("Adding TO recipient: " . $address->getAddress());
                    }
            }
        }
    }
    
    /**
     * Convert priority values to Symfony Mailer priority
     * @return int
     */
    protected function getPriorityForSymfony()
    {
        // 1 = highest, 5 = lowest
        // Symfony: \Symfony\Component\Mime\Email::PRIORITY_HIGHEST (1) to PRIORITY_LOWEST (5)
        switch ($this->priority) {
            case 1:
                return \Symfony\Component\Mime\Email::PRIORITY_HIGHEST;
            case 2:
                return \Symfony\Component\Mime\Email::PRIORITY_HIGH;
            case 3:
                return \Symfony\Component\Mime\Email::PRIORITY_NORMAL;
            case 4:
                return \Symfony\Component\Mime\Email::PRIORITY_LOW;
            case 5:
                return \Symfony\Component\Mime\Email::PRIORITY_LOWEST;
            default:
                return \Symfony\Component\Mime\Email::PRIORITY_NORMAL;
        }
    }

    /**
     * Enable email tracking to detect when emails are opened
     * 
     * This adds a transparent tracking pixel to the email that reports
     * back to your server when the email is opened.
     * 
     * Usage:
     * ```php
     * $email = new \Pramnos\Email\Email();
     * $email->setSubject('Welcome!')
     *       ->setBody('<p>Hello and welcome!</p>')
     *       ->setTo('user@example.com')
     *       ->enableTracking()  // Enable tracking
     *       ->send();
     * ```
     *
     * You can also specify a custom tracking ID:
     * ```php
     * $email->enableTracking('user_123_welcome_email');
     * ```
     *
     * Privacy Considerations:
     * - Email tracking should be disclosed to recipients in your privacy policy
     * - Some email clients block tracking pixels
     * - In some jurisdictions, explicit consent may be required for tracking
     *
     * @param string|null $trackingId Optional custom tracking ID (defaults to auto-generated)
     * @return $this
     */
    public function enableTracking($trackingId = null)
    {
        // Generate tracking ID if not provided
        if ($trackingId === null) {
            $trackingId = uniqid('email_', true);
        }
        
        // Store tracking ID
        $this->trackingId = $trackingId;
        
        // Get tracking URL - use configured route
        $domain = \Pramnos\Application\Settings::getSetting('site_url') ?: 'https://example.com';
        $trackingPath = \Pramnos\Application\Settings::getSetting('email_tracking_path') ?: '/email-track';
        $trackingUrl = rtrim($domain, '/') . $trackingPath . '?id=' . urlencode($trackingId);
        
        // Add tracking pixel to email body
        $trackingPixel = '<img src="' . $trackingUrl . '" alt="" width="1" height="1" style="display:none;" />';
        $this->body = $this->body . $trackingPixel;
        
        // Store tracking info in database
        $this->logTrackingEmail($trackingId);
        
        return $this;
    }

    /**
     * Log email tracking information
     * @param string $trackingId
     * @return void
     */
    private function logTrackingEmail($trackingId)
    {
        try {
            // Get database instance
            $db = \Pramnos\Database\Database::getInstance();
            
            // Format recipients for storage
            $recipient = $this->to;
            if (is_array($this->to)) {
                $recipientAddresses = [];
                foreach ($this->to as $address => $name) {
                    if (is_numeric($address)) {
                        $recipientAddresses[] = $name;
                    } else {
                        $recipientAddresses[] = "$name <$address>";
                    }
                }
                $recipient = implode(', ', $recipientAddresses);
            }
            
            // Store tracking information
            $db->insert('email_tracking', [
                'tracking_id' => $trackingId,
                'recipient' => $recipient,
                'subject' => $this->subject,
                'sent_at' => date('Y-m-d H:i:s'),
                'opened' => 0,
                'opened_at' => null
            ]);
            
        } catch (\Exception $e) {
            \Pramnos\Logs\Logger::log("Error logging email tracking: " . $e->getMessage());
        }
    }

    /**
     * Handle email tracking requests
     * 
     * Usage:
     * 1. Create a route in your application that points to this method:
     *    - Route: /email-track
     *    - Parameters: id (tracking ID)
     * 
     * 2. Example implementation in your router or controller:
     *    ```
     *    // In router config
     *    $router->get('/email-track', function() {
     *        $trackingId = $_GET['id'] ?? '';
     *        \Pramnos\Email\Email::handleTrackingRequest($trackingId);
     *    });
     *    
     *    // Or in a framework controller:
     *    public function trackEmail(Request $request)
     *    {
     *        $trackingId = $request->query->get('id');
     *        \Pramnos\Email\Email::handleTrackingRequest($trackingId);
     *    }
     *    ```
     * 
     * 3. Make sure your emails include tracking by calling $email->enableTracking()
     *    before sending.
     * 
     * 4. You'll need a database table to store tracking data. Example structure:
     *    ```
     *    CREATE TABLE email_tracking (
     *      id INT PRIMARY KEY AUTO_INCREMENT,
     *      tracking_id VARCHAR(64) NOT NULL UNIQUE,
     *      recipient TEXT,
     *      subject VARCHAR(255),
     *      sent_at DATETIME,
     *      opened TINYINT(1) DEFAULT 0,
     *      opened_at DATETIME NULL,
     *      ip_address VARCHAR(45) NULL,
     *      user_agent TEXT NULL,
     *      INDEX (tracking_id)
     *    );
     *    ```
     * 
     * @param string $trackingId The tracking ID from the request
     * @return void
     */
    public static function handleTrackingRequest($trackingId)
    {
        if (!empty($trackingId)) {
            try {
                // Log the email open in your database
                $db = \Pramnos\Database\Database::getInstance();
                
                // Update tracking record to mark as opened
                $db->update('email_tracking', 
                    [
                        'opened' => 1, 
                        'opened_at' => date('Y-m-d H:i:s'),
                        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
                    ], 
                    ['tracking_id' => $trackingId]
                );
                
                // Optional: Log the event
                \Pramnos\Logs\Logger::log("Email opened: $trackingId from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            } catch (\Exception $e) {
                // Silent fail - don't show errors to email clients
                \Pramnos\Logs\Logger::log("Error tracking email open: " . $e->getMessage(), \Pramnos\Logs\Logger::LEVEL_ERROR);
            }
        }

        // Return a transparent 1x1 pixel GIF
        if (!headers_sent()) {
            header('Content-Type: image/gif');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
        }
        
        // Output transparent 1x1 GIF
        echo base64_decode('R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==');
        exit;
    }

    /**
     * Get the last error message if email sending failed
     * 
     * @return string The last error message or empty string if no error occurred
     */
    public function getLastError()
    {
        return $this->lastError;
    }
    
    /**
     * Get the last exception if email sending failed
     * 
     * @return \Exception|null The last exception or null if no exception occurred
     */
    public function getLastException()
    {
        return $this->lastException;
    }
    
    /**
     * Checks if there was an error during the last send operation
     * 
     * @return bool True if there was an error, false otherwise
     */
    public function hasError()
    {
        return !empty($this->lastError);
    }

    /**
     * Send an email
     * @param string $subject
     * @param string $body
     * @param mixed $to
     * @param string $from
     * @param string $attach
     * @param bool $batch
     * @param string $replyto
     * @return array
     */
    public static function sendMail($subject, $body, $to, $from = '',
        $attach = "", $batch = false, $replyto = '')
    {
        $email = new Email();
        $email->subject = $subject;
        $email->body = $body;
        $email->to = $to;
        $email->from = $from;
        $email->attach = $attach;
        $email->batch = $batch;
        $email->replyto = $replyto;
        $result = $email->send();
        
        // Return an array with the result and any error
        return [
            'success' => $result,
            'error' => $email->getLastError()
        ];
    }

    /**
     * Log message when debug is enabled
     * 
     * @param string $message The message to log
     * @return void
     */
    protected function debugLog($message)
    {
        if ($this->debug) {
            \Pramnos\Logs\Logger::log($message);
        }
    }

}
