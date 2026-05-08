<?php

declare(strict_types=1);

namespace Pramnos\Messaging;

/**
 * ORM model for the massmessages table — broadcast message headers.
 *
 * A massmessage is a single authored message dispatched to many recipients.
 * This header stores content and metadata; individual recipient delivery
 * records live in MassMessageRecipient and per-user copies in Message (via
 * the massid FK).
 *
 * Type constants:
 *   MassMessage::TYPE_EMAIL   = 0
 *   MassMessage::TYPE_MESSAGE = 1
 *   MassMessage::TYPE_PUSH    = 2
 *
 * Status constants:
 *   MassMessage::STATUS_PENDING   = 0
 *   MassMessage::STATUS_SENT      = 1
 *   MassMessage::STATUS_SCHEDULED = 2
 *
 * @package     PramnosFramework
 * @subpackage  Messaging
 *
 * @property int         $messageid
 * @property string      $subject
 * @property string      $message          Body sent to all recipients
 * @property int         $type             0=Email, 1=Internal, 2=Push
 * @property int|null    $sender           FK to users.userid
 * @property int|null    $status           0=pending, 1=sent, 2=scheduled
 * @property int|null    $created          Unix timestamp of creation
 * @property int|null    $scheduled        Unix timestamp for scheduled delivery; 0=immediate
 * @property int         $totalrecipients  Count of recipients dispatched to
 * @property string|null $request          JSON API request payload for audit
 */
class MassMessage extends \Pramnos\Application\Model
{
    /** Email broadcast */
    public const TYPE_EMAIL   = 0;
    /** Internal message broadcast */
    public const TYPE_MESSAGE = 1;
    /** Push notification broadcast */
    public const TYPE_PUSH    = 2;

    /** Not yet dispatched */
    public const STATUS_PENDING   = 0;
    /** Dispatched to all recipients */
    public const STATUS_SENT      = 1;
    /** Scheduled for future delivery */
    public const STATUS_SCHEDULED = 2;

    /** @var int */
    public $messageid;
    /** @var string */
    public $subject;
    /** @var string */
    public $message;
    /** @var int  0=Email, 1=Internal, 2=Push */
    public $type;
    /** @var int|null  FK to users.userid */
    public $sender;
    /** @var int|null */
    public $status;
    /** @var int|null  Unix timestamp */
    public $created;
    /** @var int|null  Unix timestamp; 0 = send immediately */
    public $scheduled;
    /** @var int */
    public $totalrecipients;
    /** @var string|null  JSON originating API request */
    public $request;

    /** @var string */
    protected $_primaryKey = 'messageid';

    /** @var string */
    protected $_dbtable = 'massmessages';

    // ── CRUD ─────────────────────────────────────────────────────────────────

    /**
     * Load a mass message by primary key.
     *
     * @param  int|string $messageid
     * @param  string|null $key
     * @param  bool $debug
     * @return static
     */
    public function load($messageid, $key = null, $debug = false)
    {
        return parent::_load($messageid, null, $key, $debug);
    }

    /**
     * Persist the mass message (insert or update).
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
     * Delete a mass message by primary key.
     *
     * @param  int|string $messageid
     * @return static
     */
    public function delete($messageid)
    {
        return parent::_delete($messageid, null, null);
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
     * Return a list of mass messages matching the given filter.
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
