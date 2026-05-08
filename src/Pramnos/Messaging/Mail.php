<?php

declare(strict_types=1);

namespace Pramnos\Messaging;

/**
 * ORM model for the mails table — email send history and outbox queue.
 *
 * Rows with status=2 (queued) are picked up by the queue processor for
 * delivery. Sent emails (status=1) are retained for audit and resend.
 * Failed deliveries are stored with status=0.
 *
 * Status constants:
 *   Mail::STATUS_FAILED  = 0
 *   Mail::STATUS_SENT    = 1
 *   Mail::STATUS_QUEUED  = 2
 *
 * @package     PramnosFramework
 * @subpackage  Messaging
 *
 * @property int    $id
 * @property int    $status         0=failed, 1=sent, 2=queued
 * @property string $frommail       Sender email address
 * @property string $fromname       Sender display name
 * @property string $tomail         Recipient email address
 * @property string $toname         Recipient display name
 * @property string $subject        Email subject line
 * @property string $content        Full email body (HTML or plain text)
 * @property int    $date           Unix timestamp of creation
 * @property string $module         Application module that triggered the email
 * @property string $moduleinfo     Module-specific context string
 * @property string $extrainfo      Additional metadata (JSON or serialised)
 * @property string $path           Template path used to render this email
 * @property string $hash           MD5 hash for deduplication
 */
class Mail extends \Pramnos\Application\Model
{
    /** Delivery failed */
    public const STATUS_FAILED = 0;
    /** Delivered successfully */
    public const STATUS_SENT   = 1;
    /** Queued for delivery */
    public const STATUS_QUEUED = 2;

    /** @var int */
    public $id;
    /** @var int  0=failed, 1=sent, 2=queued */
    public $status;
    /** @var string */
    public $frommail;
    /** @var string */
    public $fromname;
    /** @var string */
    public $tomail;
    /** @var string */
    public $toname;
    /** @var string */
    public $subject;
    /** @var string */
    public $content;
    /** @var int  Unix timestamp */
    public $date;
    /** @var string */
    public $module;
    /** @var string */
    public $moduleinfo;
    /** @var string */
    public $extrainfo;
    /** @var string */
    public $path;
    /** @var string  MD5 hash for deduplication */
    public $hash;

    /** @var string */
    protected $_primaryKey = 'id';

    /** @var string */
    protected $_dbtable = 'mails';

    /** @var bool  Always read live delivery status */
    protected $useCacheInLists = false;

    // ── CRUD ─────────────────────────────────────────────────────────────────

    /**
     * Load a mail record by primary key.
     *
     * @param  int|string $id
     * @param  string|null $key
     * @param  bool $debug
     * @return static
     */
    public function load($id, $key = null, $debug = false)
    {
        return parent::_load($id, null, $key, $debug);
    }

    /**
     * Persist the mail record (insert or update).
     *
     * @param  bool $autoGetValues
     * @param  bool $debug
     * @return static
     */
    public function save($autoGetValues = false, $debug = false)
    {
        return parent::_save(null, null, $autoGetValues, $debug);
    }

    /**
     * Delete a mail record by primary key.
     *
     * @param  int|string $id
     * @return static
     */
    public function delete($id)
    {
        return parent::_delete($id, null, null);
    }

    /**
     * Return all properties as an associative array.
     *
     * @return array<string, mixed>
     */
    public function getData()
    {
        return parent::getData();
    }

    /**
     * Return a list of mail records matching the given filter.
     *
     * @param  string|null $filter  SQL WHERE clause (without the WHERE keyword)
     * @param  string|null $order   SQL ORDER / LIMIT clause
     * @param  bool $debug
     * @return static[]
     */
    public function getList($filter = null, $order = null, $debug = false)
    {
        return parent::_getList($filter, $order, null, null, $debug);
    }
}
