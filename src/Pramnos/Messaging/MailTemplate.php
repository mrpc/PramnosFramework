<?php

declare(strict_types=1);

namespace Pramnos\Messaging;

/**
 * ORM model for the mailtemplates table — reusable notification templates.
 *
 * Templates are keyed by (category, language, type) and support email,
 * SMS, and push notification channels. Multiple language variants of the
 * same template are stored as separate rows.
 *
 * Type constants:
 *   MailTemplate::TYPE_EMAIL = 0
 *   MailTemplate::TYPE_SMS   = 1
 *   MailTemplate::TYPE_PUSH  = 2
 *
 *
 * @property int         $templateid
 * @property string      $title           Human-readable name for admin panel
 * @property string      $defaulttext     Template body with {placeholder} variables
 * @property string      $defaultsubject  Default subject / push notification title
 * @property string      $category        Category key for lookup (e.g. "auth", "billing")
 * @property string      $language        BCP 47 language tag (e.g. "el", "en")
 * @property int         $type            0=Email, 1=SMS, 2=Push notification
 * @property string      $sound           Push notification sound reference
 * @property int         $sendmethod      0=Default SMTP, 1=Amazon SES API
 * @property int|null    $defaultaccount  FK override for sender account; NULL = default
 * @property string      $emailtemplate   HTML wrapper template name (e.g. "default")
 */
class MailTemplate extends \Pramnos\Application\Model
{
    /** Email delivery channel */
    public const TYPE_EMAIL = 0;
    /** SMS delivery channel */
    public const TYPE_SMS   = 1;
    /** Push notification channel */
    public const TYPE_PUSH  = 2;

    /** Send via default SMTP */
    public const SENDMETHOD_SMTP = 0;
    /** Send via Amazon SES API */
    public const SENDMETHOD_SES  = 1;

    /** @var int */
    public $templateid;
    /** @var string */
    public $title;
    /** @var string  Template body with {placeholder} variables */
    public $defaulttext;
    /** @var string */
    public $defaultsubject;
    /** @var string  Category key for lookup (e.g. "auth", "billing") */
    public $category;
    /** @var string  BCP 47 language tag */
    public $language;
    /** @var int  0=Email, 1=SMS, 2=Push */
    public $type;
    /** @var string  Push notification sound reference */
    public $sound;
    /** @var int  0=SMTP, 1=SES */
    public $sendmethod;
    /** @var int|null */
    public $defaultaccount;
    /** @var string  HTML wrapper template name */
    public $emailtemplate;

    /** @var string */
    protected $_primaryKey = 'templateid';

    /** @var string */
    protected $_dbtable = 'mailtemplates';

    // ── CRUD ─────────────────────────────────────────────────────────────────

    /**
     * Load a template by primary key.
     *
     * @param  int|string $templateid
     * @param  string|null $key
     * @param  bool $debug
     * @return static
     */
    public function load($templateid, $key = null, $debug = false)
    {
        return parent::_load($templateid, null, $key, $debug);
    }

    /**
     * Persist the template (insert or update).
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
     * Delete a template by primary key.
     *
     * @param  int|string $templateid
     * @return static
     */
    public function delete($templateid)
    {
        return parent::_delete($templateid, null, null);
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
     * Return a list of templates matching the given filter.
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

    /**
     * Find a template by category, language, and type.
     *
     * Returns the first match ordered by templateid. Returns null when no
     * template is found for the requested combination.
     *
     * @param  string $category
     * @param  string $language
     * @param  int    $type      One of the TYPE_* constants
     * @return static|null
     */
    public function findByKey(string $category, string $language, int $type): ?static
    {
        $db     = \Pramnos\Database\Database::getInstance();
        $filter = $db->prepareQuery(
            "category = %s AND language = %s AND type = " . (int) $type,
            $category,
            $language
        );
        $list = $this->getList($filter, 'templateid ASC');

        // _getList returns an array keyed by primary key value — use reset() for first element
        $first = reset($list);
        return $first !== false ? $first : null;
    }
}
