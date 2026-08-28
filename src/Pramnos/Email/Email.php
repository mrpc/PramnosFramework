<?php
namespace Pramnos\Email;
/**
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
     * Unsubscribe URL (or a bare `mailto:`), for the `List-Unsubscribe` header
     * @var string|null
     */
    public $unsubscribe = NULL;

    /**
     * A `mailto:` alternative offered alongside the URL
     *
     * Both go in the header when both are set. A provider picks whichever it supports, and
     * the mailto is what keeps the header useful in a client older than RFC 8058.
     * @var string|null
     */
    public $unsubscribeMailto = NULL;

    /**
     * Does the URL accept a POST with no confirmation? (RFC 8058)
     *
     * When true, `List-Unsubscribe-Post: List-Unsubscribe=One-Click` goes out beside the
     * link, which is what makes Gmail and Yahoo draw their own unsubscribe control instead of
     * offering "report spam" as the easier option. It is a promise about the endpoint: a POST
     * to that URL must unsubscribe immediately, with no login and no "are you sure" page.
     * @var bool
     */
    public $unsubscribeOneClick = false;

    /**
     * The list this message belongs to, or empty for transactional mail
     *
     * Not a header — it is what the visible footer link and the suppression check are built
     * from. Transactional mail (a password reset, a second-factor code) has no list and gets
     * no link: nobody unsubscribes from being able to sign in.
     * @var string
     */
    public $unsubscribeList = '';
    
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
     * The wrapper this message is sent in, overriding the `emailtheme` setting.
     *
     * Null means "whatever the installation is configured to use", which on an
     * installation that has configured nothing is no wrapper at all.
     *
     * @var ?string
     * @see \Pramnos\Email\EmailTheme
     */
    public $template = null;

    /**
     * The wrapped body, from the last {@see send()}.
     *
     * Kept because the mailer and the audit log both need it and neither should wrap it
     * again: rendering twice would nest the shell inside itself, and the recorded copy
     * would not be the message that was delivered.
     *
     * @var ?string
     */
    protected $renderedBody = null;
    
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
     * Optional source module label recorded with each send (e.g. 'auth').
     * Purely for traceability in the mails audit log.
     * @var string
     */
    public $module = '';

    /**
     * Whether send() records the outbound email in the `mails` table.
     * On by default so the table is a complete delivery log; set false to
     * suppress recording for a specific send.
     * @var bool
     */
    public $recordToMails = true;

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
     * Send this message in a named wrapper, whatever the installation's default is.
     *
     * `null` puts the choice back to the `emailtheme` setting. An empty string means "no
     * wrapper for this one", which is the only way to send a bare body from an installation
     * that wraps everything — a machine-readable mail, or one whose body is a whole
     * document already.
     *
     * @param  ?string $template Wrapper name, `''` for none, `null` for the default.
     * @return $this
     * @see \Pramnos\Email\EmailTheme
     */
    public function setTemplate(?string $template)
    {
        $this->template = $template;
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
     * Offer an unsubscribe: the link, the mailto, the headers and the footer line.
     *
     * ```php
     * $email->offerUnsubscribe('marketing');                  // for a known address
     * $email->offerUnsubscribe('marketing', 'a@example.com');  // before setTo()
     * ```
     *
     * One call rather than four properties, because the four have to agree: a
     * `List-Unsubscribe-Post` promising one-click over a URL that shows a confirmation page is
     * worse than no header, and a header with no visible link in the body fails the other half
     * of what a mailbox provider looks at.
     *
     * @param  string $list  The list name — see {@see Unsubscribe}
     * @param  string $email The recipient; defaults to whatever `setTo()` was given
     * @return $this
     */
    public function offerUnsubscribe(string $list, string $email = '')
    {
        $address = $email !== '' ? $email : (string) $this->to;

        if (trim($address) === '') {
            return $this;
        }

        $token = Unsubscribe::token($address, $list);

        $this->unsubscribeList     = $list;
        $this->unsubscribe         = Unsubscribe::url($token);
        $this->unsubscribeMailto   = Unsubscribe::mailto($token);
        $this->unsubscribeOneClick = true;

        return $this;
    }

    /**
     * The headers that decide how a mailbox provider treats this message.
     *
     * Four of them, none of which changes what the reader sees and all of which change what
     * happens to the message. A caller that set one itself wins — these are defaults, not
     * policy.
     *
     * @param \Symfony\Component\Mime\Email $email The message being built
     */
    protected function addDeliverabilityHeaders(\Symfony\Component\Mime\Email $email): void
    {
        $headers  = $email->getHeaders();
        $explicit = array_change_key_case($this->headers, CASE_LOWER);

        $set = static function (string $name, string $value) use ($headers, $explicit): void {
            if ($value === '' || isset($explicit[strtolower($name)])) {
                return;
            }

            $headers->add(new \Symfony\Component\Mime\Header\UnstructuredHeader($name, $value));
        };

        /*
         * `Auto-Submitted: auto-generated` (RFC 3834).
         *
         * Every message this class sends is generated by a program, and saying so is what stops
         * an out-of-office responder replying to it — and then to the reply, which is how a mail
         * loop starts. It costs nothing and it is the header most often missing from
         * transactional mail.
         */
        $set('Auto-Submitted', 'auto-generated');

        /*
         * A unique reference, so Gmail does not collapse two messages into one thread.
         *
         * Gmail groups by subject, and this server's most useful mail has a repeating subject —
         * «a new sign-in to your account». Two sign-ins from two devices arrived looking like
         * one message with the older one hidden behind «show trimmed content», which is exactly
         * the message somebody needs to see twice. `X-Entity-Ref-ID` is the documented way to
         * say "this is its own thing"; the value only has to be unique.
         */
        $set('X-Entity-Ref-ID', bin2hex(random_bytes(16)));

        $list = trim((string) $this->unsubscribeList);

        if ($list === '') {
            return;   // the rest are about bulk mail, and this is not bulk mail
        }

        /*
         * `Precedence: bulk` — not in any standard, honoured nearly everywhere.
         *
         * It is the older half of the same idea as `Auto-Submitted`: a vacation responder that
         * ignores one usually respects the other. On list mail only, because marking a password
         * reset as bulk is an invitation to deprioritise it.
         */
        $set('Precedence', 'bulk');

        $host = $this->senderHost();

        if ($host === '') {
            return;
        }

        /*
         * `List-ID` (RFC 2919) — a stable identifier for the list, so a client can group,
         * filter and unsubscribe by list rather than by guessing from the subject.
         */
        $set('List-ID', '<' . $this->headerToken($list) . '.' . $host . '>');

        /*
         * `Feedback-ID` — Google Postmaster's grouping key.
         *
         * Without it every complaint about every message lands in one bucket and the dashboard
         * can tell you that something is wrong but not what. With it, the spam rate is broken
         * down by the first field, so «the newsletter is being marked as spam and the receipts
         * are not» becomes a fact rather than a theory.
         *
         * Four colon-separated fields, the last identifying the sender, each limited to the
         * characters Google accepts.
         */
        $set(
            'Feedback-ID',
            $this->headerToken($list) . ':pramnos:bulk:' . $this->headerToken($host)
        );
    }

    /**
     * The host this mail claims to come from.
     *
     * From the sender address, falling back to the site URL — a `List-ID` built on the wrong
     * domain is worse than none, because it is a stable identifier for something that does not
     * exist.
     */
    protected function senderHost(): string
    {
        $from = trim((string) $this->from);

        if (str_contains($from, '@')) {
            $host = trim(substr($from, strrpos($from, '@') + 1), " \t<>");

            if ($host !== '') {
                return strtolower($host);
            }
        }

        $url = (string) \Pramnos\Application\Settings::getSetting('siteurl');
        $host = (string) parse_url($url, PHP_URL_HOST);

        return strtolower($host);
    }

    /**
     * A string reduced to what these headers allow.
     *
     * `Feedback-ID` accepts only letters, digits, `_`, `.` and `-`, and any field longer than 64
     * characters invalidates the whole header — which fails the way every mail header fails:
     * silently, and nowhere near the sender.
     */
    protected function headerToken(string $value): string
    {
        $token = (string) preg_replace('~[^A-Za-z0-9_.-]+~', '-', $value);

        return substr(trim($token, '-'), 0, 64);
    }

    /**
     * The `List-Unsubscribe` value: each entry in angle brackets, comma separated.
     *
     * @return string Empty when there is nothing to offer
     */
    protected function unsubscribeHeaderValue(): string
    {
        $entries = [];

        foreach ([$this->unsubscribe, $this->unsubscribeMailto] as $candidate) {
            $candidate = trim((string) $candidate);

            if ($candidate === '') {
                continue;
            }

            // Tolerate a caller that already wrapped it — this property predates the helper
            // and existing applications set it by hand.
            $entries[] = str_starts_with($candidate, '<') ? $candidate : '<' . $candidate . '>';
        }

        return implode(', ', array_unique($entries));
    }

    /**
     * Send the email
     * @return boolean
     */
    public function send()
    {
        // The wrapper, once, before anybody reads the body: the mailer sends this and the
        // audit log records it, so they have to be the same string.
        $this->renderedBody = EmailTheme::wrap(
            (string) $this->body,
            $this->template,
            [
                'subject' => (string) $this->subject,
                // So a wrapper can render the visible line. Empty on transactional mail, and
                // a wrapper that prints an unsubscribe link on a password reset is a wrapper
                // teaching its readers that the link means nothing.
                'unsubscribeUrl'  => trim((string) $this->unsubscribe),
                'unsubscribeList' => (string) $this->unsubscribeList,
            ]
        );

        try {
            // Reset last error before attempting to send
            $this->lastError = '';
            $this->lastException = null;

            $sent = $this->sendWithSymfonyMailer();
        }
        catch (\Exception $exception) {
            $this->lastError = $exception->getMessage();
            $this->lastException = $exception;
            \Pramnos\Logs\Logger::log("Email error: " . $exception->getMessage() . "\n" . $exception->getTraceAsString());
            $sent = false;
        }

        // Record every outbound email (success or failure) in the mails audit log.
        $this->recordMail((bool) $sent);

        return $sent;
    }

    /**
     * Record this send in the `mails` table — an audit log of every outbound
     * email. Runs on both success and failure so the log is complete.
     *
     * Best-effort: a DB / model failure here must never break (nor change the
     * result of) the actual send, so it is caught and logged. Suppress per send
     * with {@see self::$recordToMails} = false. Overridable for custom logging.
     */
    protected function recordMail(bool $success): void
    {
        if (!$this->recordToMails) {
            return;
        }
        try {
            $tomail  = $this->emailToString($this->to);
            $date    = time();
            \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table('#PREFIX#mails')
                ->insert([
                    // 1 = sent, 0 = failed (matches Pramnos\Messaging\Mail::STATUS_*).
                    'status'     => $success ? 1 : 0,
                    'frommail'   => $this->emailToString($this->from),
                    'fromname'   => '',
                    'tomail'     => $tomail,
                    'toname'     => '',
                    'subject'    => (string) $this->subject,
                    'content'    => (string) ($this->renderedBody ?? $this->body),
                    'date'       => $date,
                    'module'     => (string) $this->module,
                    'moduleinfo' => '',
                    'extrainfo'  => $success ? '' : (string) $this->lastError,
                    'path'       => '',
                    'hash'       => md5($tomail . '|' . (string) $this->subject . '|' . $date),
                ]);
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Could not record mail in mails table: ' . $e->getMessage());
        }
    }

    /**
     * Flatten a to/from value — a plain address string, a list of addresses, or
     * an [email => name] map — into a comma-separated address string for the log.
     */
    protected function emailToString($value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $key => $val) {
                // [email => name] → use the key; [0 => email] → use the value.
                $parts[] = is_string($key) ? $key : (string) $val;
            }
            return implode(', ', $parts);
        }
        return '';
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
            $body = $this->renderedBody ?? (string) $this->body;
            /*
             * The text part is *converted*, not stripped.
             *
             * `strip_tags($body)` threw away every `href` — so «click here to confirm» arrived
             * with nothing to click — ran adjacent table cells together into one line, and kept
             * the contents of the `<style>` block, showing the reader the CSS. A multipart
             * message whose alternative part does not match the HTML is also a documented spam
             * signal, so the part that was supposed to help deliverability was hurting it.
             */
            $email->subject($this->subject)
                  ->html($body)
                  ->text(PlainText::fromHtml($body));
            
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
            
            /*
             * The two headers a mailbox provider looks for.
             *
             * `List-Unsubscribe` used to be emitted with whatever string the caller set, which
             * is not a header value: RFC 2369 wants each entry inside angle brackets, and a
             * bare URL is ignored — silently, because nothing in mail reports a malformed
             * header back to the sender. So the header was there, looked right in a dump, and
             * did nothing.
             *
             * `List-Unsubscribe-Post` is the other half, and the half Gmail and Yahoo actually
             * require of anyone sending in volume. Without it they draw no unsubscribe control,
             * and the reader's easiest way out of a list is the spam button — which is counted
             * against every future message, including the transactional mail this header never
             * appears on.
             */
            $unsubscribeHeader = $this->unsubscribeHeaderValue();

            if ($unsubscribeHeader !== '') {
                $email->getHeaders()->add(new \Symfony\Component\Mime\Header\UnstructuredHeader('List-Unsubscribe', $unsubscribeHeader));

                if ($this->unsubscribeOneClick == true) {
                    $email->getHeaders()->add(new \Symfony\Component\Mime\Header\UnstructuredHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click'));
                }
            }
            
            $this->addDeliverabilityHeaders($email);

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
            $db->insertDataToTable('email_tracking', [
                ['fieldName' => 'tracking_id', 'value' => $trackingId, 'type' => 'string'],
                ['fieldName' => 'recipient', 'value' => $recipient, 'type' => 'string'],
                ['fieldName' => 'subject', 'value' => $this->subject, 'type' => 'string'],
                ['fieldName' => 'sent_at', 'value' => date('Y-m-d H:i:s'), 'type' => 'string'],
                ['fieldName' => 'opened', 'value' => 0, 'type' => 'integer'],
                ['fieldName' => 'opened_at', 'value' => null, 'type' => 'string']
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
                $db->updateTableData('email_tracking', 
                    [
                        ['fieldName' => 'opened', 'value' => 1, 'type' => 'integer'],
                        ['fieldName' => 'opened_at', 'value' => date('Y-m-d H:i:s'), 'type' => 'string'],
                        ['fieldName' => 'ip_address', 'value' => \Pramnos\Http\Request::clientIp() ?: null, 'type' => 'string'],
                        ['fieldName' => 'user_agent', 'value' => $_SERVER['HTTP_USER_AGENT'] ?? null, 'type' => 'string']
                    ], 
                    "`tracking_id` = '" . $db->prepareInput($trackingId) . "'"
                );
                
                // Optional: Log the event
                \Pramnos\Logs\Logger::log("Email opened: $trackingId from " . \Pramnos\Http\Request::clientIp('unknown'));
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
        if (defined('PRAMNOS_TESTING')) {
            return;
        }
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
