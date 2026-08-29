<?php

declare(strict_types=1);

namespace Pramnos\Messaging\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Framework\Factory;
use Pramnos\Messaging\MassMessage;
use Pramnos\Messaging\MassMessageAudience;
use Pramnos\Messaging\MassMessageDispatcher;

/**
 * The administration screens for a message sent to many accounts.
 *
 * `massmessages` and `massmessagerecipients` have been in the schema since the messaging
 * feature shipped, with a model each. Nothing composed one, nothing sent one and nothing
 * displayed one — so an application that wanted to tell its users something wrote its own
 * loop, in a controller, inside a request.
 *
 * Four things this screen does that such a loop does not:
 *
 * - **It says how many people first.** The compose form counts the audience before anybody
 *   presses send. A count is the one number that changes an operator's mind, and it is the
 *   number nobody has when the send is a loop in a request.
 * - **Sending is queueing.** `send()` resolves the audience, writes a recipient row each and
 *   returns. `messages:dispatch` delivers them on a timer. A send of four thousand emails
 *   inside a POST is a request that times out halfway, with no way to say how far it got and
 *   a page that offers to try again.
 * - **It shows progress per message.** Delivered, failed and still pending, from the
 *   recipient rows — so "did it go out" has an answer that is not "the log says it started".
 * - **It refuses to send twice.** A message that already has recipients cannot be re-queued.
 *   Everything else here is recoverable; that one reaches every person on the list.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license     MIT
 */
class MassMessagesController extends Controller
{
    /** Minimum usertype. This screen mails everybody, so it is not for a junior operator. */
    protected int $requiredUserType = 90;

    /**
     * The channels a mass message can go out on, as the model numbers them.
     *
     * @var array<int, string>
     */
    public const TYPES = [
        MassMessage::TYPE_EMAIL   => 'Email',
        MassMessage::TYPE_MESSAGE => 'Internal message',
        MassMessage::TYPE_PUSH    => 'Push (no transport)',
    ];

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'view', 'edit', 'preview', 'save', 'send', 'delete']);
        parent::__construct($application);
    }

    /**
     * Every mass message, newest first, with how far each one got.
     */
    public function display(): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $doc        = Factory::getDocument();
        $doc->title = 'Mass messages';

        $dispatcher = new MassMessageDispatcher();
        $messages   = [];

        try {
            $model = new MassMessage($this);
            foreach ((array) $model->getList(null, 'messageid desc') as $row) {
                $row = (array) $row;
                // The progress is per message and read per row on purpose: this list is
                // short (one row per send, not per recipient) and the alternative is a
                // join that has to be explained every time somebody reads it.
                $row['progress'] = $dispatcher->progress((int) ($row['messageid'] ?? 0));
                $messages[] = $row;
            }
        } catch (\Throwable $ex) {
            $this->addError('Could not read the messages: ' . $ex->getMessage());
        }

        $view           = $this->getView('massmessages');
        $view->messages = $messages;
        $view->types    = self::TYPES;

        return $view->display();
    }

    /**
     * One message: what it says, who it went to, and how that went.
     */
    public function view(mixed $id = null): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $message = $this->loadOrRedirect();
        if ($message === null) {
            return null;
        }

        $doc        = Factory::getDocument();
        $doc->title = (string) ($message->subject ?: 'Mass message');

        $view           = $this->getView('massmessages');
        $view->message  = $message->getData();
        $view->types    = self::TYPES;
        $view->progress = (new MassMessageDispatcher())->progress((int) $message->messageid);
        $view->audience = MassMessageAudience::describe($this->criteriaOf($message));

        return $view->display('view');
    }

    /**
     * The compose form — for a new message, or one not sent yet.
     */
    public function edit(mixed $id = null): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $id      = (int) \Pramnos\Http\Request::staticGetOption();
        $message = new MassMessage($this);

        if ($id > 0) {
            $message->load($id);

            if ((int) $message->messageid !== $id) {
                $this->addError('That message no longer exists.');
                $this->redirect(adminUrl('MassMessages'));

                return null;
            }

            // A sent message is a record of something that happened. Editing it would
            // rewrite what a few thousand people were told they received.
            if ((int) $message->status === MassMessage::STATUS_SENT) {
                $this->addError('That message has been sent; it cannot be edited.');
                $this->redirect(adminUrl('MassMessages/view/') . $id);

                return null;
            }
        }

        $doc        = Factory::getDocument();
        $doc->title = $id > 0 ? 'Edit mass message' : 'New mass message';

        $criteria = $this->criteriaOf($message);

        $view              = $this->getView('massmessages');
        $view->message     = $message->getData();
        $view->types       = self::TYPES;
        $view->criteria    = $criteria;
        $view->options     = $this->optionsOf($message);
        $view->languages   = $this->audienceLanguages();
        $view->templates   = $this->mailTemplates();
        $view->tracking    = \Pramnos\Email\Tracking::enabled();
        $view->groups        = $this->userGroups();
        $view->organizations = $this->organizations();
        // Before anybody presses send, not after.
        $view->preview      = $this->audienceFor($criteria);
        $view->audienceSize = (int) $view->preview['total'];

        return $view->display('edit');
    }

    /**
     * Who these criteria mean, before anything is saved or sent.
     *
     * The screen asked an operator to choose a band of accounts and then pressed send. What the
     * choice *meant* was visible only afterwards, in the recipient rows of a message that had
     * already gone out — and a send to the wrong band is not something anybody can take back.
     *
     * The same form, posted to a different action: the criteria are read exactly as `save()`
     * reads them, resolved, and the compose screen is rendered again with the answer on it.
     * Nothing is written, so an operator can try a filter, look, and change it — which is the
     * loop that was missing.
     *
     * Deliberately not JSON and not a fetch: this has to work identically in three themes and
     * with no JavaScript, and the thing being previewed is a form the browser can post.
     */
    public function preview(mixed $id = null): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $request  = new \Pramnos\Http\Request();
        $criteria = $this->criteriaFrom($request);
        $message  = new MassMessage($this);
        $id       = (int) $request->get('messageid', 0, 'post', 'int');

        if ($id > 0) {
            $message->load($id);
        }

        /*
         * The unsaved body and subject, carried through.
         *
         * Previewing is something somebody does *while writing*. Rendering the stored message
         * would throw away what they had typed, which turns one look at the audience into
         * retyping the message — so nobody would look.
         */
        $message->subject = trim(strip_tags((string) $request->get('subject', '', 'post')));
        $message->message = (string) $request->get('message', '', 'post');
        $message->type    = (int) $request->get('type', MassMessage::TYPE_EMAIL, 'post', 'int');
        $message->scheduled = $this->timestampOf((string) $request->get('scheduled', '', 'post'));

        $doc        = Factory::getDocument();
        $doc->title = $id > 0 ? 'Edit mass message' : 'New mass message';

        $view                = $this->getView('massmessages');
        $view->message       = $message->getData();
        $view->types         = self::TYPES;
        $view->criteria      = $criteria;
        $view->options       = $this->optionsFrom($request);
        $view->languages     = $this->audienceLanguages();
        $view->templates     = $this->mailTemplates();
        $view->tracking      = \Pramnos\Email\Tracking::enabled();
        $view->groups        = $this->userGroups();
        $view->organizations = $this->organizations();
        $view->preview       = $this->audienceFor($criteria);
        $view->audienceSize  = (int) $view->preview['total'];
        $view->previewed     = true;

        return $view->display('edit');
    }

    /**
     * Who these criteria mean, as a seam.
     *
     * `MassMessageAudience` reads `users`, `userstogroups` and the organizations table; a test
     * that asserts what reaches the compose screen should not need three tables to do it.
     *
     * @param  array<string, mixed> $criteria
     * @return array{total: int, sample: list<array<string, mixed>>, truncated: int}
     */
    protected function audienceFor(array $criteria): array
    {
        return (new MassMessageAudience())->preview($criteria);
    }

    /**
     * The account groups this installation has, for the picker.
     *
     * @return array<int, string> groupid => name
     */
    protected function userGroups(): array
    {
        $groups = [];

        try {
            $result = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table('#PREFIX#usergroups')
                ->select(['groupid', 'name'])
                ->orderBy('name', 'asc')
                ->get();

            while (($row = $result->fetch()) !== null) {
                $groups[(int) ($row['groupid'] ?? 0)] = (string) ($row['name'] ?? '');
            }
        } catch (\Throwable) {
            // No table, or no groups. An empty picker is the honest answer; a screen that
            // refused to render because one optional filter has nothing to offer is not.
            return [];
        }

        return $groups;
    }

    /**
     * The organizations this installation has, for the picker.
     *
     * Belongs to the authserver feature, so an installation without it has no such table and
     * this answers with nothing rather than raising.
     *
     * @return array<int, string> organization_id => name
     */
    protected function organizations(): array
    {
        $organizations = [];

        try {
            $result = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table('organizations')
                ->select(['organization_id', 'name'])
                ->where('is_active', 1)
                ->orderBy('name', 'asc')
                ->get();

            while (($row = $result->fetch()) !== null) {
                $organizations[(int) ($row['organization_id'] ?? 0)] = (string) ($row['name'] ?? '');
            }
        } catch (\Throwable) {
            return [];
        }

        return $organizations;
    }

    /**
     * Create or update a message, without sending anything.
     */
    public function save(): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $request = new \Pramnos\Http\Request();
        $id      = (int) $request->get('messageid', 0, 'post', 'int');

        $message = new MassMessage($this);
        if ($id > 0) {
            $message->load($id);

            if ((int) $message->status === MassMessage::STATUS_SENT) {
                $this->addError('That message has been sent; it cannot be edited.');
                $this->redirect(adminUrl('MassMessages/view/') . $id);

                return;
            }
        }

        $subject = trim(strip_tags((string) $request->get('subject', '', 'post')));

        if ($subject === '') {
            $this->addError('A message needs a subject.');
            $this->redirect(adminUrl('MassMessages/edit/') . ($id ?: ''));

            return;
        }

        $criteria = $this->criteriaFrom($request);
        $options  = $this->optionsFrom($request);

        $scheduled = trim((string) $request->get('scheduled', '', 'post'));
        $scheduled = $scheduled === '' ? 0 : (int) strtotime($scheduled);

        $message->subject   = $subject;
        // Markup on purpose: this is the body of a message.
        $message->message   = (string) $request->get('message', '', 'post');
        $message->type      = (int) $request->get('type', MassMessage::TYPE_EMAIL, 'post', 'int');
        $message->sender    = (int) (\Pramnos\User\User::getCurrentUser()->userid ?? 0) ?: null;
        $message->scheduled = $scheduled > 0 ? $scheduled : 0;
        $message->status    = $scheduled > 0
            ? MassMessage::STATUS_SCHEDULED
            : MassMessage::STATUS_PENDING;

        if ((int) $message->messageid < 1) {
            $message->created         = time();
            $message->totalrecipients = 0;
        }

        // The criteria, not the list: what somebody decided belongs in the audit trail, and
        // what it meant at queue time belongs in the recipient rows. The send options sit
        // beside them under their own key — same decision, same record — which is also what
        // keeps a row written before they existed readable: no key, no options.
        $message->request = $this->requestJson($criteria, $options);

        try {
            $message->save();
            $this->addMessage('Message saved. Nothing has been sent yet.');
        } catch (\Throwable $ex) {
            $this->addError('Could not save: ' . $ex->getMessage());
            $this->redirect(adminUrl('MassMessages/edit/') . ($id ?: ''));

            return;
        }

        $this->redirect(adminUrl('MassMessages/view/') . (int) $message->messageid);
    }

    /**
     * Queue a message for delivery.
     *
     * Queueing, not sending: the recipient rows are written here and `messages:dispatch`
     * delivers them. What this action costs is one INSERT per recipient, which is bounded
     * and fast; what it does not do is hold a request open while a mail server answers
     * four thousand times.
     *
     * POST only, with the anti-CSRF token, because a GET that mails everybody is one
     * prefetch away from happening by itself.
     */
    public function send(mixed $id = null): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $id = (int) \Pramnos\Http\Request::staticGetOption();

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST'
            || !\Pramnos\Http\Session::getInstance()->checkToken('post')
        ) {
            $this->addError('That request could not be verified. Try again from the message.');
            $this->redirect(adminUrl('MassMessages/view/') . $id);

            return;
        }

        $message = new MassMessage($this);
        $message->load($id);

        if ((int) $message->messageid !== $id || $id < 1) {
            $this->addError('That message no longer exists.');
            $this->redirect(adminUrl('MassMessages'));

            return;
        }

        $dispatcher = new MassMessageDispatcher();
        $criteria   = $this->criteriaOf($message);
        $audience   = (new MassMessageAudience())->resolve($criteria);

        if ($audience === []) {
            $this->addError('Those criteria match nobody, so nothing was queued.');
            $this->redirect(adminUrl('MassMessages/view/') . $id);

            return;
        }

        $queued = $dispatcher->queue($id, $audience);

        if ($queued === 0) {
            // The one refusal worth its own message: everything else here is recoverable,
            // and this one would reach every person on the list a second time.
            $this->addError('That message already has recipients; it was not queued again.');
            $this->redirect(adminUrl('MassMessages/view/') . $id);

            return;
        }

        $this->addMessage(
            $queued . ' recipient(s) queued. Delivery runs on the schedule — '
            . 'this page shows the progress.'
        );
        $this->redirect(adminUrl('MassMessages/view/') . $id);
    }

    /**
     * Delete a message that was never sent.
     */
    public function delete(mixed $id = null): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $id      = (int) \Pramnos\Http\Request::staticGetOption();
        $message = new MassMessage($this);
        $message->load($id);

        if ((int) $message->messageid !== $id || $id < 1) {
            $this->addError('That message no longer exists.');
            $this->redirect(adminUrl('MassMessages'));

            return;
        }

        if ((int) $message->status === MassMessage::STATUS_SENT) {
            $this->addError('A sent message is a record of what people received; it stays.');
            $this->redirect(adminUrl('MassMessages/view/') . $id);

            return;
        }

        try {
            $message->delete($id);
            $this->addMessage('Message deleted.');
        } catch (\Throwable $ex) {
            $this->addError('Could not delete: ' . $ex->getMessage());
        }

        $this->redirect(adminUrl('MassMessages'));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * The audience criteria out of the compose form.
     *
     * A method rather than eight lines inside `save()`, because these are the decisions worth
     * asserting on their own: each one silently selects a different set of people.
     *
     * @return array<string, mixed>
     */
    protected function criteriaFrom(\Pramnos\Http\Request $request): array
    {
        $criteria = array_filter([
            'usertype_min'   => max(0, (int) $request->get('usertype_min', 0, 'post', 'int')),
            'usertype_max'   => max(0, (int) $request->get('usertype_max', 0, 'post', 'int')),
            'language'       => trim((string) $request->get('language', '', 'post')),
            'twofactor'      => trim((string) $request->get('twofactor', '', 'post')),
            'last_login_after'  => $this->timestampOf((string) $request->get('last_login_after', '', 'post')),
            'last_login_before' => $this->timestampOf((string) $request->get('last_login_before', '', 'post')),
            // The audience that will actually be mailed, not the one that would have been.
            // Left in, the count promises an operator several hundred people who unsubscribed
            // and will be skipped at delivery — and the count is the number that decides
            // whether the send happens at all.
            'exclude_optouts' => trim((string) $request->get('exclude_optouts', '', 'post')),
        ], static fn ($value): bool => $value !== '' && $value !== 0);

        /*
         * The two booleans are written after the filter, not through it.
         *
         * Their `false` is meaningful — "include unvalidated accounts" — and `array_filter`
         * drops it, which would silently restore the default of excluding them.
         */
        $criteria['validated_only'] = (bool) $request->get('validated_only', 0, 'post', 'int');
        $criteria['active_only']    = (bool) $request->get('active_only', 0, 'post', 'int');

        /*
         * Groups, organizations and named accounts, all after the filter for the same reason.
         *
         * They arrive as arrays from a multi-select or as a pasted string from a spreadsheet,
         * and `MassMessageAudience::ids()` reads both. `array_filter` above drops an empty
         * array as readily as an empty string, which is right — but it also cannot tell an
         * array of ids from a scalar, so these are normalised here and written only when they
         * name something.
         */
        foreach (['groups', 'organizations', 'only_ids', 'exclude_ids'] as $key) {
            $ids = MassMessageAudience::ids($request->get($key, [], 'post'));

            if ($ids !== []) {
                $criteria[$key] = $ids;
            }
        }

        return $criteria;
    }

    /**
     * The send options out of the compose form.
     *
     * @return array<string, mixed>
     */
    protected function optionsFrom(\Pramnos\Http\Request $request): array
    {
        $options = array_filter([
            'link'        => trim((string) $request->get('link', '', 'post')),
            'list'        => trim((string) $request->get('list', '', 'post')),
            'preheader'   => trim((string) $request->get('preheader', '', 'post')),
            'tracking'    => (bool) $request->get('tracking', 0, 'post', 'int'),
            'action_type' => trim((string) $request->get('action_type', '', 'post')),
            'action_name' => trim((string) $request->get('action_name', '', 'post')),
            'action_url'  => trim((string) $request->get('action_url', '', 'post')),
        ], static fn ($value): bool => $value !== '' && $value !== false);

        $template = $request->get('template', '__default__', 'post');

        if ((string) $template !== '__default__') {
            // `''` is a real answer — no wrapper for this campaign — so it is stored as one
            // rather than filtered away with the empty strings above.
            $options['template'] = (string) $template;
        }

        return $options;
    }

    /**
     * The audit record of one composed message: what it was aimed at, and how it was sent.
     *
     * The options sit under their own key inside the criteria rather than beside them, so a row
     * written before they existed — which is every mass message already in an installation's
     * table — has no `options` key and reads as none. A campaign that chose nothing writes no
     * key at all, because an empty object in the record reads as a decision somebody made.
     *
     * @param array<string, mixed> $criteria
     * @param array<string, mixed> $options
     */
    protected function requestJson(array $criteria, array $options): string
    {
        return (string) json_encode(
            $options === [] ? $criteria : $criteria + ['options' => $options],
            JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * The audience criteria stored with a message, or the defaults.
     *
     * `request` is a JSON column the model documents as "the originating API request", and
     * this is that: the request that composed the message. Reading it defensively because
     * it is a text column — an older row, or one written by something else, is not a reason
     * to fail rendering the screen.
     *
     * @return array<string, mixed>
     */
    protected function criteriaOf(MassMessage $message): array
    {
        $decoded = json_decode((string) ($message->request ?? ''), true);

        if (!is_array($decoded)) {
            return [];
        }

        unset($decoded['options']);

        return $decoded;
    }

    /**
     * The send options stored with a message, or none.
     *
     * @return array<string, mixed>
     */
    protected function optionsOf(MassMessage $message): array
    {
        $decoded = json_decode((string) ($message->request ?? ''), true);

        return is_array($decoded) ? (array) ($decoded['options'] ?? []) : [];
    }

    /**
     * A date from the form as a timestamp, or 0.
     *
     * `strtotime()` reads a slash-separated date as American month-first, so `03/04/2026` is
     * March on a screen that said April. The form fields are `<input type="date">`, which
     * posts ISO — this only has to refuse anything that is not.
     */
    protected function timestampOf(string $value): int
    {
        $value = trim($value);

        if ($value === '' || !preg_match('~^\d{4}-\d{2}-\d{2}$~', $value)) {
            return 0;
        }

        $stamp = strtotime($value);

        return $stamp === false ? 0 : $stamp;
    }

    /**
     * The languages accounts actually have, for the audience filter.
     *
     * Read from `users` rather than from the installation's catalogue: a language nobody has
     * set is an audience of nobody, and offering it invites an operator to compose a message
     * and then wonder why the count is zero.
     *
     * @return list<string>
     */
    protected function audienceLanguages(): array
    {
        $languages = [];

        try {
            $result = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table('#PREFIX#users')
                ->select(['language'])
                ->groupBy('language')
                ->get();

            while (($row = $result->fetch()) !== null) {
                $language = trim((string) ($row['language'] ?? ''));

                if ($language !== '') {
                    $languages[] = $language;
                }
            }
        } catch (\Throwable) {
            return [];
        }

        sort($languages);

        return $languages;
    }

    /**
     * The mail wrappers this installation can render, by name.
     *
     * @return list<string>
     */
    protected function mailTemplates(): array
    {
        $names = [];

        foreach (\Pramnos\Email\EmailTheme::directories() as $directory) {
            foreach ((array) @glob($directory . DIRECTORY_SEPARATOR . '*.html.php') as $file) {
                $names[basename((string) $file, '.html.php')] = true;
            }
        }

        ksort($names);

        return array_keys($names);
    }

    /**
     * Load the message named in the URL, or send the operator back to the list.
     */
    protected function loadOrRedirect(): ?MassMessage
    {
        $id      = (int) \Pramnos\Http\Request::staticGetOption();
        $message = new MassMessage($this);

        if ($id > 0) {
            $message->load($id);
        }

        if ((int) $message->messageid !== $id || $id < 1) {
            $this->addError('That message no longer exists.');
            $this->redirect(adminUrl('MassMessages'));

            return null;
        }

        return $message;
    }
}
