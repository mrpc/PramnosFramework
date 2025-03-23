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
        //Create the Transport

        if (\Pramnos\Application\Settings::getSetting('smtp_tls') == 'yes'
            && \Pramnos\Application\Settings::getSetting('smtp_port') != '') {

            $transport = (
                new \Swift_SmtpTransport(
                    \Pramnos\Application\Settings::getSetting("smtp_host"),
                    \Pramnos\Application\Settings::getSetting('smtp_port'),
                    'tls'
                )
            )->setUsername(
                \Pramnos\Application\Settings::getSetting("smtp_user")
            )->setPassword(
                \Pramnos\Application\Settings::getSetting("smtp_pass")
            );

        } else {
            $transport = \Swift_SmtpTransport::newInstance(
                \Pramnos\Application\Settings::getSetting("smtp_host")
            )
                ->setUsername(\Pramnos\Application\Settings::getSetting(
                    "smtp_user"
                ))
                ->setPassword(\Pramnos\Application\Settings::getSetting(
                    "smtp_pass"
                ));
        }




        //Create the Mailer using your created Transport
        $mailer = \Swift_Mailer::newInstance($transport);
        //Create a message
        $message = \Swift_Message::newInstance($this->subject)
            ->setTo($this->to)
            ->setBody($this->body, 'text/html')
            ->addPart(strip_tags($this->body ?? ''), 'text/plain');

        $message->setPriority($this->priority);


        if ($this->from != '') {
            $message->setFrom($this->from);
            if ($this->sendReceipt == true) {
                if (is_array($this->from)) {
                    $m = array_keys($this->from);
                    $message->setReadReceiptTo($m[0]);
                }
                else {
                    $message->setReadReceiptTo($this->from);
                }
            }
        } else {
            if (!\Pramnos\Application\Settings::getSetting('admin_mail')) {
                $message->setFrom(
                    array('nobody@pramnoshosting.com' => 'Nobody')
                );
            } else {
                if (!\Pramnos\Application\Settings::getSetting('sitename')) {
                    $message->setFrom(
                        \Pramnos\Application\Settings::getSetting('admin_mail')
                    );
                } else {
                    $message->setFrom(
                        array(
                            \Pramnos\Application\Settings::getSetting(
                                'admin_mail'
                            ) => \Pramnos\Application\Settings::getSetting(
                                'sitename'
                            )
                        )
                    );
                }
                if ($this->sendReceipt == true) {
                    if (trim($this->returnPath) != '') {
                        $message->setReadReceiptTo(trim($this->returnPath));
                    } else {
                        $message->setReadReceiptTo(
                            \Pramnos\Application\Settings::getSetting(
                                'admin_mail'
                            )
                        );
                    }
                }
            }
        }

        if (trim($this->attach) != "") {
            $message->attach(\Swift_Attachment::fromPath($this->attach));
        }

        if (trim($this->unsubscribe) != '') {

        }

        if (trim($this->returnPath) != '') {
            $message->setReturnPath(trim($this->returnPath));
        }

        if (trim($this->organization) != '') {

        }

        if (trim($this->abuse) != '') {

        }

        if ($this->replyto != '') {
            $message->setReplyTo($this->replyto);
        } elseif (\Pramnos\Application\Settings::getSetting(
            'admin_replymail'
        ) != '') {
            $message->setReplyTo(
                \Pramnos\Application\Settings::getSetting('admin_replymail')
            );
        }

        //Send the message

        try {
            if ($this->batch == false) {
                $result = $mailer->send($message);
            } else {
                $result = $mailer->batchSend($message);
            }
        }
        catch (\Exception $exception) {
            \Pramnos\Logs\Logger::log($exception->getMessage());
            return false;
        }

        return $result;
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
