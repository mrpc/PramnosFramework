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

    /**
     * The email template an operator wrote for this category, in the reader's language.
     *
     * ## Why this exists
     *
     * The `mailtemplates` table, this model and a full administration screen — list, edit,
     * delete and **test send** — have shipped for a long time, and **nothing ever read a
     * template when sending**. An operator could write one, save it, send themselves a test
     * of it, and every real message still went out with the text compiled into the class.
     * A screen that implies a capability the system does not have is worse than no screen:
     * the text looks changed and is not.
     *
     * ## Why it is static, and returns an array
     *
     * `Model::__construct()` requires a `Controller`. A notification channel has none —
     * mail is sent from a queue worker, a console command, a second-factor step — so a
     * lookup that needed an instance could not be called from the one place that needed it.
     * The first version of this was an instance method and was broken everywhere it
     * mattered; the integration test found it before an installation did.
     *
     * ## Language
     *
     * The reader's language first, then the site's default, then **any row for the
     * category**. The last step is deliberate: an operator who wrote one template, in one
     * language, meant it to be used — refusing it because the recipient's tag does not match
     * would look exactly like the override not working, and there would be no way to tell
     * the two apart.
     *
     * @param  string $category Template key, e.g. `auth.twofactor_code`
     * @param  string $language BCP 47 tag of the person who will read it
     * @return array{subject: string, body: string, emailtemplate: string}|null
     */
    public static function lookup(string $category, string $language = ''): ?array
    {
        if ($category === '') {
            return null;
        }

        $db = \Pramnos\Database\Database::getInstance();

        $languages = [];

        if ($language !== '') {
            $languages[] = $language;
        }

        $default = (string) \Pramnos\Application\Settings::getSetting('default_language', '');

        if ($default !== '' && !in_array($default, $languages, true)) {
            $languages[] = $default;
        }

        // The preferred languages in order, then one more pass with no language filter.
        // {@see the doc-block} for why the last pass exists.
        foreach (array_merge($languages, [null]) as $tag) {
            $query = $db->queryBuilder()->table('#PREFIX#mailtemplates')
                ->where('category', $category)
                ->where('type', self::TYPE_EMAIL);

            if ($tag !== null) {
                $query->where('language', $tag);
            }

            $row = $query->orderBy('templateid', 'ASC')->first();

            if ($row && (int) ($row->numRows ?? 0) > 0) {
                return [
                    'subject'       => trim((string) ($row->fields['defaultsubject'] ?? '')),
                    'body'          => trim((string) ($row->fields['defaulttext'] ?? '')),
                    'emailtemplate' => trim((string) ($row->fields['emailtemplate'] ?? '')),
                ];
            }
        }

        return null;
    }

    /**
     * A stored template's subject and body with `{placeholder}` substituted.
     *
     * **Empty is the whole fallback rule.** A row that exists with an empty body is not an
     * instruction to send an empty email — it is an operator who filled in the subject and
     * nothing else, and the built-in body should still be used. So each field is answered
     * independently and a caller that gets `''` keeps its own.
     *
     * Unknown placeholders are left alone rather than blanked: `{firstname}` in a template
     * whose caller supplies no such variable stays visible, which is a mistake somebody can
     * see in a test send. Silently deleting it would make the template look correct in the
     * editor and arrive wrong.
     *
     * @param  array{subject: string, body: string, emailtemplate?: string} $template
     * @param  array<string, string|int|float>                              $vars
     * @return array{subject: string, body: string}
     */
    public static function fill(array $template, array $vars = []): array
    {
        $search  = [];
        $replace = [];

        foreach ($vars as $name => $value) {
            $search[]  = '{' . $name . '}';
            $replace[] = (string) $value;
        }

        $substitute = static function (string $text) use ($search, $replace): string {
            return $text === '' || $search === [] ? $text : str_replace($search, $replace, $text);
        };

        return [
            'subject' => $substitute(trim((string) ($template['subject'] ?? ''))),
            'body'    => $substitute(trim((string) ($template['body'] ?? ''))),
        ];
    }
}
