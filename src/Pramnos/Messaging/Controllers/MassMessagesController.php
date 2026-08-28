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
        $this->addAuthAction(['display', 'view', 'edit', 'save', 'send', 'delete']);
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
        // Before anybody presses send, not after.
        $view->audienceSize = (new MassMessageAudience())->count($criteria);

        return $view->display('edit');
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

        $criteria = [
            'usertype_min'   => max(0, (int) $request->get('usertype_min', 0, 'post', 'int')),
            'validated_only' => (bool) $request->get('validated_only', 0, 'post', 'int'),
            'active_only'    => (bool) $request->get('active_only', 0, 'post', 'int'),
        ];

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
        // what it meant at queue time belongs in the recipient rows.
        $message->request = json_encode($criteria, JSON_UNESCAPED_SLASHES);

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

        return is_array($decoded) ? $decoded : [];
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
