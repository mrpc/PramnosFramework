<?php

declare(strict_types=1);

namespace Pramnos\Audience\Rules;

use Pramnos\Email\TemplateValues;

/**
 * What a rule's step can do: the framework's actions, and the ones an application declares.
 *
 * ```php
 * Actions::define('sms', t('Send an SMS'), [
 *     'text' => ['label' => t('Message'), 'type' => 'textarea'],
 * ], function (int $person, array $params, Context $context): array {
 *     $sent = Sms::send($person, $params['text'], origin: $context->origin());
 *
 *     return ['status' => $sent ? 'sent' : 'error', 'detail' => ['provider' => $sent?->id]];
 * }, channel: 'sms');
 * ```
 *
 * An action answers with a status — `sent` (a message went), `done`, `skipped`, `error` — and
 * what is worth keeping in the run log. One with a channel is a message: the engine checks its
 * consent list, its frequency caps and its sending hours first, and in shadow mode does not call
 * it at all. Text parameters may use the message placeholders (`{firstname}`…), filled for the
 * person.
 *
 * The framework's: `email` (a mail template), `bell` and `push` (a notification), `site_message`
 * (a banner, popup or card on the site), `group_add` and `group_remove`, `attribute` (set a column
 * of a declared attribute), `notify_admin`, `webhook`.
 */
final class Actions
{
    /** @var array<string, array{label: string, params: array<string, array<string, mixed>>, run: callable, channel: ?string}> */
    private static array $defined = [];

    /** @var (callable(): array<string, string>)|null The application's templates for the email action, by key */
    private static $templateOptions = null;

    /** @var (callable(string, object): ?array{subject: string, body: string, emailtemplate?: string})|null Its template, filled for a person */
    private static $templateFill = null;

    /**
     * Where the email action's templates come from, when not the framework's `mailtemplates`
     * (category, language, type 0): the choices the editor offers, and a template filled for one
     * person — in their language, their placeholders replaced.
     *
     * ```php
     * Actions::templates(
     *     fn () => Templates::titles(),                                   // ['welcome' => 'Welcome', …]
     *     fn (string $key, object $user) => Templates::render($key, $user), // ['subject' => …, 'body' => …] or null
     * );
     * ```
     *
     * @param callable(): array<string, string>                                              $options
     * @param callable(string, object): ?array{subject: string, body: string, emailtemplate?: string} $fill
     */
    public static function templates(callable $options, callable $fill): void
    {
        self::$templateOptions = $options;
        self::$templateFill    = $fill;
    }

    /**
     * The templates the email action offers, by key.
     *
     * @return array<string, string>
     */
    public static function templateOptions(): array
    {
        if (self::$templateOptions !== null) {
            return (self::$templateOptions)();
        }
        try {
            $rows = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#mailtemplates')
                ->select(['category', 'title', 'language'])->where('type', \Pramnos\Messaging\MailTemplate::TYPE_EMAIL)
                ->orderBy('title')->getAllOrFail();
        } catch (\Throwable) {
            return [];
        }
        $options = [];
        foreach ($rows as $row) {
            $key = (string) $row['category'];
            if ($key !== '' && !isset($options[$key])) {
                $options[$key] = trim((string) $row['title']) !== '' ? (string) $row['title'] : $key;
            }
        }

        return $options;
    }

    /**
     * The unsubscribe lists the email action offers: the lists of the declared mail types.
     *
     * @return array<string, string>
     */
    public static function listOptions(): array
    {
        $lists = [];
        foreach (\Pramnos\Email\MailTypes::all() as $type) {
            if ($type->list !== '' && $type->list !== null) {
                $lists[(string) $type->list] = (string) $type->label;
            }
        }

        return $lists;
    }

    /**
     * Declare an action.
     *
     * @param array<string, array{label: string, type: string, options?: array<int|string, string>}> $params
     * @param callable(int, array<string, mixed>, Context): array{status: string, detail?: array<string, mixed>} $run
     */
    public static function define(string $key, string $label, array $params, callable $run, ?string $channel = null): void
    {
        self::$defined[$key] = ['label' => $label, 'params' => $params, 'run' => $run, 'channel' => $channel];
    }

    /**
     * An action, or null.
     *
     * @return array{label: string, params: array<string, array<string, mixed>>, run: callable, channel: ?string}|null
     */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * Every action: the framework's, then the application's (which may replace one).
     *
     * @return array<string, array{label: string, params: array<string, array<string, mixed>>, run: callable, channel: ?string}>
     */
    public static function all(): array
    {
        return array_replace(self::framework(), self::$defined);
    }

    /** Forget the application's actions: tests. */
    public static function reset(): void
    {
        self::$defined         = [];
        self::$templateOptions = null;
        self::$templateFill    = null;
    }

    /**
     * A text with the person's placeholders filled.
     */
    public static function fill(string $text, int $person): string
    {
        return TemplateValues::fill($text, TemplateValues::forUser(self::user($person)));
    }

    /** The person's account, or null where it does not load. */
    public static function user(int $person): ?\Pramnos\User\User
    {
        $user = new \Pramnos\User\User($person);

        return (int) ($user->userid ?? 0) === $person ? $user : null;
    }

    /**
     * The framework's actions.
     *
     * @return array<string, array{label: string, params: array<string, array<string, mixed>>, run: callable, channel: ?string}>
     */
    private static function framework(): array
    {
        $text = static fn (string $label, string $type = 'text'): array => ['label' => $label, 'type' => $type];

        return [
            'email' => ['label' => t('Send an email'), 'channel' => 'email', 'params' => [
                'template' => ['label' => t('Mail template'), 'type' => 'select', 'options' => self::templateOptions(...)],
                'list'     => ['label' => t('Unsubscribe list'), 'type' => 'select', 'options' => self::listOptions(...)],
            ], 'run' => self::email(...)],
            'bell' => ['label' => t('Notify on the site'), 'channel' => 'site', 'params' => [
                'subject' => $text(t('Title')), 'body' => $text(t('Message'), 'textarea'), 'link' => $text(t('Link')),
            ], 'run' => static fn (int $person, array $params, Context $context): array => self::notify($person, $params, 'database', $context)],
            'push' => ['label' => t('Send a push notification'), 'channel' => 'push', 'params' => [
                'subject' => $text(t('Title')), 'body' => $text(t('Message'), 'textarea'), 'link' => $text(t('Link')),
            ], 'run' => static fn (int $person, array $params, Context $context): array => self::notify($person, $params, 'push', $context)],
            'site_message' => ['label' => t('Show a message on the site'), 'channel' => 'site', 'params' => [
                'kind' => ['label' => t('Kind'), 'type' => 'select', 'options' => ['banner' => t('Banner'), 'popup' => t('Popup'), 'card' => t('Card')]],
                'title' => $text(t('Title')), 'body' => $text(t('Message'), 'textarea'), 'link' => $text(t('Link')),
                'link_text' => $text(t('Button')), 'days' => $text(t('Shown for days'), 'number'),
            ], 'run' => static fn (int $person, array $params, Context $context): array => ($id = \Pramnos\Audience\SiteMessages::post(
                $context->isTest() ? $context->to() : $person, (string) ($params['kind'] ?? 'banner'),
                Actions::fill((string) ($params['title'] ?? ''), $person), Actions::fill((string) ($params['body'] ?? ''), $person),
                (string) ($params['link'] ?? ''), (string) ($params['link_text'] ?? ''), (int) ($params['days'] ?? 0), $context->origin()
            )) === null ? ['status' => 'skipped', 'detail' => ['reason' => 'not installed']] : ['status' => 'sent', 'detail' => ['message' => $id]]],
            'group_add' => ['label' => t('Put in a group'), 'channel' => null, 'params' => ['group' => $text(t('Group'), 'group')],
                'run' => static fn (int $person, array $params, Context $context): array => [
                    'status' => 'done', 'detail' => ['added' => \Pramnos\Audience\Groups::add((int) ($params['group'] ?? 0), $person, null, 'rule')]]],
            'group_remove' => ['label' => t('Take out of a group'), 'channel' => null, 'params' => ['group' => $text(t('Group'), 'group')],
                'run' => static fn (int $person, array $params, Context $context): array => [
                    'status' => 'done', 'detail' => ['removed' => \Pramnos\Audience\Groups::remove((int) ($params['group'] ?? 0), $person, null, 'rule')]]],
            'attribute' => ['label' => t('Set an attribute'), 'channel' => null, 'params' => [
                'attribute' => $text(t('Attribute'), 'attribute'), 'value' => $text(t('Value')),
            ], 'run' => self::attribute(...)],
            'notify_admin' => ['label' => t('Notify an administrator'), 'channel' => null, 'params' => [
                'to' => $text(t('Account id'), 'number'), 'subject' => $text(t('Title')), 'body' => $text(t('Message'), 'textarea'),
            ], 'run' => self::notifyAdmin(...)],
            'webhook' => ['label' => t('Call a webhook'), 'channel' => null, 'params' => ['url' => $text(t('Address'))],
                'run' => self::webhook(...)],
        ];
    }

    /**
     * A mail template, filled for the person, in their language, handed to the outbox; recorded in
     * the mail log with where it came from, tracked when its list allows.
     *
     * @param array<string, mixed> $params
     * @return array{status: string, detail: array<string, mixed>}
     */
    private static function email(int $person, array $params, Context $context): array
    {
        $data = self::user($person);
        $user = $context->isTest() ? self::user($context->to()) : $data;
        if ($data === null || $user === null || trim((string) $user->email) === '') {
            return ['status' => 'skipped', 'detail' => ['reason' => 'no address']];
        }
        if (self::$templateFill !== null) {
            $template = (self::$templateFill)((string) ($params['template'] ?? ''), $data);
            $filled   = $template;
        } else {
            $template = \Pramnos\Messaging\MailTemplate::lookup((string) ($params['template'] ?? ''), (string) ($data->language ?? ''));
            $filled   = $template === null ? null : \Pramnos\Messaging\MailTemplate::fill($template, TemplateValues::forUser($data));
        }
        if ($template === null || $filled === null) {
            return ['status' => 'error', 'detail' => ['reason' => 'no template ' . ($params['template'] ?? '')]];
        }
        if ($context->isTest()) {
            $filled['subject'] = '[' . t('Test') . '] ' . $filled['subject'];
        }
        $email  = new \Pramnos\Email\Email();
        $email->setSubject($filled['subject'])->setBody($filled['body'])->setTo((string) $user->email);
        $email->forUser($context->to());
        $email->module     = 'rule';
        $email->moduleinfo = $context->origin();
        if (($template['emailtemplate'] ?? '') !== '') {
            $email->setTemplate($template['emailtemplate']);
        }
        $list = trim((string) ($params['list'] ?? ''));
        // The footer link is the last word: an address that left this list, or everything, is not sent to.
        if ($list !== '' && \Pramnos\Email\Unsubscribe::isOptedOut((string) $user->email, $list)) {
            return ['status' => 'skipped', 'detail' => ['reason' => 'unsubscribed from ' . $list]];
        }
        if ($list !== '') {
            $email->offerUnsubscribe($list, (string) $user->email);
            $email->enableTracking();
        }
        // The outbox, like every other send: mail:flush delivers it, with its retries.
        if (!$email->queue()) {
            return ['status' => 'error', 'detail' => ['reason' => 'not accepted: ' . ($email->getLastError() ?: 'opted out')]];
        }

        return ['status' => 'sent', 'detail' => ['mail' => self::mailId($context->origin()), 'template' => $params['template']]];
    }

    /** The mail log row a send with this origin wrote. */
    public static function mailId(string $origin): ?int
    {
        try {
            $rows = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#mails')->select(['id'])
                ->where('module', 'rule')->where('moduleinfo', $origin)->orderBy('id', 'desc')->limit(1)->getAllOrFail();
        } catch (\Throwable) {
            return null;
        }

        return $rows === [] ? null : (int) $rows[0]['id'];
    }

    /**
     * A notification on one channel.
     *
     * @param array<string, mixed> $params
     * @return array{status: string, detail: array<string, mixed>}
     */
    private static function notify(int $person, array $params, string $channel, ?Context $context = null): array
    {
        $user = self::user($context !== null && $context->isTest() ? $context->to() : $person);
        if ($user === null) {
            return ['status' => 'skipped', 'detail' => ['reason' => 'no account']];
        }
        $message = (new \Pramnos\Notification\Message(
            self::fill((string) ($params['subject'] ?? ''), $person),
            self::fill((string) ($params['body'] ?? ''), $person)
        ))->to($channel);
        if (trim((string) ($params['link'] ?? '')) !== '') {
            $message->link((string) $params['link']);
        }
        $user->notify($message);

        return ['status' => 'sent', 'detail' => ['channel' => $channel]];
    }

    /**
     * Set a column of a declared attribute — of the users table or a one-to-one table.
     *
     * @param array<string, mixed> $params
     * @return array{status: string, detail: array<string, mixed>}
     */
    private static function attribute(int $person, array $params, Context $context): array
    {
        $attribute = \Pramnos\Audience\Attributes::get((string) ($params['attribute'] ?? ''));
        if ($attribute === null || $attribute->isExpression() || $attribute->type() === \Pramnos\Audience\Attribute::MANY) {
            return ['status' => 'error', 'detail' => ['reason' => 'not a column attribute']];
        }
        // The column is the part after the qualifier; the row is the person's, in its own table.
        $column = explode('.', $attribute->sql('mysql'), 2)[1];
        \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table($attribute->table() ?? '#PREFIX#users')
            ->where($attribute->table() === null ? 'userid' : $attribute->foreignKey(), $person)
            ->update([$column => $params['value'] ?? null]);

        return ['status' => 'done', 'detail' => ['attribute' => $attribute->key(), 'value' => $params['value'] ?? null]];
    }

    /**
     * Tell an administrator, on the site and by email.
     *
     * @param array<string, mixed> $params
     * @return array{status: string, detail: array<string, mixed>}
     */
    private static function notifyAdmin(int $person, array $params, Context $context): array
    {
        $admin = self::user((int) ($params['to'] ?? 0));
        if ($admin === null) {
            return ['status' => 'error', 'detail' => ['reason' => 'no administrator ' . ($params['to'] ?? '')]];
        }
        $admin->notify((new \Pramnos\Notification\Message(
            self::fill((string) ($params['subject'] ?? ''), $person),
            self::fill((string) ($params['body'] ?? ''), $person)
        ))->to('database', 'mail')->link(\Pramnos\Html\AudienceBuilder::personUrl($person)));

        return ['status' => 'done', 'detail' => ['to' => (int) $admin->userid]];
    }

    /**
     * POST the person and where it came from as JSON.
     *
     * @param array<string, mixed> $params
     * @return array{status: string, detail: array<string, mixed>}
     */
    private static function webhook(int $person, array $params, Context $context): array
    {
        $url = trim((string) ($params['url'] ?? ''));
        if (preg_match('~^https?://~i', $url) !== 1) {
            return ['status' => 'error', 'detail' => ['reason' => 'not an http address']];
        }
        $response = \Pramnos\Http\Client::post($url)->timeout(10)
            ->json(['person' => $person, 'origin' => $context->toArray()])->send();

        return ['status' => $response->successful() ? 'done' : 'error', 'detail' => ['status' => $response->status()]];
    }
}
