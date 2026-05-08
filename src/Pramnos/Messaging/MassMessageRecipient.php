<?php

declare(strict_types=1);

namespace Pramnos\Messaging;

/**
 * ORM model for the massmessagerecipients table — per-user delivery tracking.
 *
 * One row per (massmessage, recipient) pair. Tracks whether the broadcast
 * was successfully delivered to each individual user. The status mirrors
 * the Message::TYPE_* states for consistency.
 *
 * Status constants:
 *   MassMessageRecipient::STATUS_PENDING   = 0
 *   MassMessageRecipient::STATUS_DELIVERED = 1
 *   MassMessageRecipient::STATUS_FAILED    = 2
 *
 * @package     PramnosFramework
 * @subpackage  Messaging
 *
 * @property int $recipientid  Auto-increment PK
 * @property int $messageid    FK to massmessages.messageid
 * @property int $userid       FK to users.userid
 * @property int $status       0=pending, 1=delivered, 2=failed
 */
class MassMessageRecipient extends \Pramnos\Application\Model
{
    /** Delivery not yet attempted */
    public const STATUS_PENDING   = 0;
    /** Successfully delivered */
    public const STATUS_DELIVERED = 1;
    /** Delivery failed */
    public const STATUS_FAILED    = 2;

    /** @var int */
    public $recipientid;
    /** @var int */
    public $messageid;
    /** @var int */
    public $userid;
    /** @var int */
    public $status;

    /** @var string */
    protected $_primaryKey = 'recipientid';

    /** @var string */
    protected $_dbtable = 'massmessagerecipients';

    // ── CRUD ─────────────────────────────────────────────────────────────────

    /**
     * Load a recipient record by primary key.
     *
     * @param  int|string $recipientid
     * @param  string|null $key
     * @param  bool $debug
     * @return static
     */
    public function load($recipientid, $key = null, $debug = false)
    {
        return parent::_load($recipientid, null, $key, $debug);
    }

    /**
     * Persist the recipient record (insert or update).
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
     * Delete a recipient record by primary key.
     *
     * @param  int|string $recipientid
     * @return static
     */
    public function delete($recipientid)
    {
        return parent::_delete($recipientid, null, null);
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
     * Return a list of recipient records matching the given filter.
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
