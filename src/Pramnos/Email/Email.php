<?php
namespace Pramnos\Email;
/**
 * @package     PramnosFramework
 * @subpackage  Email
 */
class Email extends \Pramnos\Framework\Base
{

    public $sendReceipt = false;
    public $returnPath = NULL;
    public $organization = NULL;
    public $abuse = NULL;
    public $unsubscribe = NULL;
    public $headers = array();
    public $priority = 3;
    public $subject = '';
    public $body = '';
    public $to = '';
    public $from = '';
    public $attach = "";
    public $batch = false;
    /**
     * Reply to address
     * @var string
     */
    public $replyto = '';

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
     * Send the email
     * @return boolean
     */
    public function send()
    {
        try {
            return $this->sendWithSymfonyMailer();
        }
        catch (\Exception $exception) {
            \Pramnos\Logs\Logger::log($exception->getMessage());
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
        
        // Create DSN
        $scheme = $useTls ? 'smtps' : 'smtp';
        $dsn = new \Symfony\Component\Mailer\Transport\Dsn(
            $scheme,
            $host,
            $user,
            $pass,
            $port
        );
        
        // Create Transport
        $factory = new \Symfony\Component\Mailer\Transport\TransportFactory([
            new \Symfony\Component\Mailer\Bridge\Smtp\Transport\SmtpTransportFactory()
        ]);
        $transport = $factory->create($dsn);
        
        // Create Mailer
        $mailer = new \Symfony\Component\Mailer\Mailer($transport);
        
        // Create Email
        $email = new \Symfony\Component\Mime\Email();
        $email->subject($this->subject)
              ->html($this->body)
              ->text(strip_tags($this->body ?? ''));
        
        // Set priority
        $email->priority($this->getPriorityForSymfony());
        
        // Set To
        if (is_array($this->to)) {
            foreach ($this->to as $address => $name) {
                if (is_numeric($address)) {
                    $email->addTo($name);
                } else {
                    $email->addTo($address, $name);
                }
            }
        } else {
            $email->to($this->to);
        }
        
        // Set From
        if ($this->from != '') {
            if (is_array($this->from)) {
                foreach ($this->from as $address => $name) {
                    $email->from($address, $name);
                    break; // Only use the first from address
                }
            } else {
                $email->from($this->from);
            }
        } else {
            if (!\Pramnos\Application\Settings::getSetting('admin_mail')) {
                $email->from('nobody@pramnoshosting.com', 'Nobody');
            } else {
                $fromEmail = \Pramnos\Application\Settings::getSetting('admin_mail');
                $sitename = \Pramnos\Application\Settings::getSetting('sitename');
                if (!$sitename) {
                    $email->from($fromEmail);
                } else {
                    $email->from($fromEmail, $sitename);
                }
            }
        }
        
        // Set Reply-To
        if ($this->replyto != '') {
            $email->replyTo($this->replyto);
        } elseif (\Pramnos\Application\Settings::getSetting('admin_replymail') != '') {
            $email->replyTo(\Pramnos\Application\Settings::getSetting('admin_replymail'));
        }
        
        // Set Return-Path
        if (trim($this->returnPath) != '') {
            $email->returnPath(trim($this->returnPath));
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
            } else if (trim($this->returnPath) != '') {
                $email->getHeaders()->add(new \Symfony\Component\Mime\Header\UnstructuredHeader('Disposition-Notification-To', trim($this->returnPath)));
            } else if (\Pramnos\Application\Settings::getSetting('admin_mail')) {
                $email->getHeaders()->add(new \Symfony\Component\Mime\Header\UnstructuredHeader(
                    'Disposition-Notification-To',
                    \Pramnos\Application\Settings::getSetting('admin_mail')
                ));
            }
        }
        
        // Handle unsubscribe, organization and abuse headers
        if (trim($this->unsubscribe) != '') {
            $email->getHeaders()->add(new \Symfony\Component\Mime\Header\UnstructuredHeader('List-Unsubscribe', trim($this->unsubscribe)));
        }
        
        if (trim($this->organization) != '') {
            $email->getHeaders()->add(new \Symfony\Component\Mime\Header\UnstructuredHeader('Organization', trim($this->organization)));
        }
        
        if (trim($this->abuse) != '') {
            $email->getHeaders()->add(new \Symfony\Component\Mime\Header\UnstructuredHeader('X-Report-Abuse', trim($this->abuse)));
        }
        
        // Add attachment
        if (trim($this->attach) != "") {
            $email->attachFromPath($this->attach);
        }
        
        // Add headers
        foreach ($this->headers as $name => $value) {
            $email->getHeaders()->add(new \Symfony\Component\Mime\Header\UnstructuredHeader($name, $value));
        }
        
        // Send email
        $mailer->send($email);
        return true;
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
        return $email->send();
    }

}
