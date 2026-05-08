<?php

declare(strict_types=1);

namespace Pramnos\Messaging;

/**
 * ORM model for the messages table — internal private messages and notifications.
 *
 * A single table handles all inbox/outbox/archive/notification states via the
 * `type` column. Each delivery creates two rows: one for the sender (type=2)
 * and one for each recipient (type=1 initially). Notifications use types 8/9
 * and have no fromuserid.
 *
 * Type constants:
 *   Message::TYPE_READ             = 0   (sender copy, read)
 *   Message::TYPE_NEW              = 1   (recipient inbox, unread)
 *   Message::TYPE_SENT             = 2   (sender outbox)
 *   Message::TYPE_INBOX_ARCHIVE    = 3
 *   Message::TYPE_OUTBOX_ARCHIVE   = 4
 *   Message::TYPE_UNREAD           = 5
 *   Message::TYPE_MARKED_READ      = 6
 *   Message::TYPE_DELETED          = 7
 *   Message::TYPE_NOTIFICATION_NEW = 8
 *   Message::TYPE_NOTIFICATION_READ= 9
 *
 * @package     PramnosFramework
 * @subpackage  Messaging
 *
 * @property int         $messageid
 * @property int|null    $massid          FK to massmessages.messageid; NULL for direct messages
 * @property int         $type            State — see TYPE_* constants
 * @property string      $subject
 * @property string      $text            Message body
 * @property string      $url             Optional action URL
 * @property string      $urlcaption      Display text for action URL
 * @property string      $attachmenttext  Serialised attachment metadata
 * @property string      $image           Path to image shown alongside the message
 * @property string      $securitycode    Short random code for unsubscribe/one-time links
 * @property int|null    $fromuserid      FK to users.userid; NULL for system notifications
 * @property int|null    $touserid        FK to users.userid
 * @property int         $date            Unix timestamp
 * @property string      $ip              Sender IPv4 at send time
 * @property int         $bbcode          1 = body contains BBCode
 * @property int         $html            1 = body is trusted HTML
 * @property int         $smilies         1 = expand emoticons
 * @property int         $signature       1 = append sender signature
 * @property int         $attachment      1 = has file attachments
 */
class Message extends \Pramnos\Application\Model
{
    /** Sender copy — message has been read */
    public const TYPE_READ              = 0;
    /** Recipient inbox — unread */
    public const TYPE_NEW               = 1;
    /** Sender outbox */
    public const TYPE_SENT              = 2;
    /** Inbox archived */
    public const TYPE_INBOX_ARCHIVE     = 3;
    /** Outbox archived */
    public const TYPE_OUTBOX_ARCHIVE    = 4;
    /** Recipient inbox — still unread (alias kept for legacy code) */
    public const TYPE_UNREAD            = 5;
    /** Recipient inbox — manually marked read */
    public const TYPE_MARKED_READ       = 6;
    /** Soft-deleted */
    public const TYPE_DELETED           = 7;
    /** Notification — not yet seen */
    public const TYPE_NOTIFICATION_NEW  = 8;
    /** Notification — seen/dismissed */
    public const TYPE_NOTIFICATION_READ = 9;

    /** @var int */
    public $messageid;
    /** @var int|null  FK to massmessages.messageid */
    public $massid;
    /** @var int */
    public $type;
    /** @var string */
    public $subject;
    /** @var string */
    public $text;
    /** @var string */
    public $url;
    /** @var string */
    public $urlcaption;
    /** @var string */
    public $attachmenttext;
    /** @var string */
    public $image;
    /** @var string */
    public $securitycode;
    /** @var int|null */
    public $fromuserid;
    /** @var int|null */
    public $touserid;
    /** @var int  Unix timestamp */
    public $date;
    /** @var string  IPv4 address */
    public $ip;
    /** @var int */
    public $bbcode;
    /** @var int */
    public $html;
    /** @var int */
    public $smilies;
    /** @var int */
    public $signature;
    /** @var int */
    public $attachment;

    /** @var string */
    protected $_primaryKey = 'messageid';

    /** @var string */
    protected $_dbtable = 'messages';

    // ── CRUD ─────────────────────────────────────────────────────────────────

    /**
     * Load a message by primary key.
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
     * Persist the message (insert or update).
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
     * Delete a message by primary key.
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
     * Return a list of messages matching the given filter.
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

    // ── Inbox / outbox helpers ────────────────────────────────────────────────

    /**
     * Return the unread message count for a given user.
     *
     * Counts rows where touserid matches and type is TYPE_NEW (1) or
     * TYPE_UNREAD (5) — the two states representing an unread inbox item.
     *
     * @param  int $userId
     * @return int
     */
    public function countUnread(int $userId): int
    {
        $db  = \Pramnos\Database\Database::getInstance();
        $uid = (int) $userId;
        $types = implode(',', [self::TYPE_NEW, self::TYPE_UNREAD]);

        $result = $db->query(
            "SELECT COUNT(*) AS cnt FROM messages WHERE touserid = {$uid} AND type IN ({$types})"
        );

        if (!$result || !$result->fetch()) {
            return 0;
        }

        return (int) ($result->fields['cnt'] ?? 0);
    }

    /**
     * Return the unread notification count for a given user.
     *
     * @param  int $userId
     * @return int
     */
    public function countUnreadNotifications(int $userId): int
    {
        $db  = \Pramnos\Database\Database::getInstance();
        $uid = (int) $userId;

        $result = $db->query(
            "SELECT COUNT(*) AS cnt FROM messages WHERE touserid = {$uid} AND type = " . self::TYPE_NOTIFICATION_NEW
        );

        if (!$result || !$result->fetch()) {
            return 0;
        }

        return (int) ($result->fields['cnt'] ?? 0);
    }
}
