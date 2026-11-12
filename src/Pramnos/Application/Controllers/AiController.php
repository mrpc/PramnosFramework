<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Application\Settings;
use Pramnos\Llm\Billing\OpenAiCosts;
use Pramnos\Llm\CallLog;
use Pramnos\Llm\Connection;
use Pramnos\Llm\Llm;
use Pramnos\Llm\Pricing;
use Pramnos\Llm\Providers;

/**
 * `/admin/Ai` — Ask, the administrators' analyst; `/admin/Ai/settings` — every language-model
 * setting of the installation, in one place.
 *
 * Providers and their keys, the provider each of the application's features uses, the unit
 * prices calls are costed at, what was spent and what failed — and the application's own
 * sections beside them (`'llm' => ['admin_sections' => [...]]`, see `Pramnos\Llm\Admin\Section`).
 * One screen, so there is never a second copy of a setting somewhere else that disagrees.
 *
 * ### Ask
 *
 * `/admin/Ai` asks the installation's own figures — accounts, email, the queue, logs, model
 * spend — through `Pramnos\Llm\Analyst`, behind the same usertype as the settings. An
 * administrator's analyst conversations are their own and never in their Ask AI. The same, as
 * JSON in the conversation API's shape, is under `/admin/Ai/api/…`. `/admin/Ai/all` reads every
 * administrator's, read only, for whoever has `admin.ai.all` (root, in usertype mode) — each view
 * of somebody else's recorded in the audit log as `ai.conversation_viewed`.
 *
 * ### Keys are never printed back
 *
 * A key field renders empty with a placeholder saying whether one is saved; submitting it empty
 * keeps what is stored, and clearing it is its own checkbox. A key on a screen is a key in a
 * screenshot.
 */
class AiController extends Controller
{
    // The analyst's scheduled tasks and reports, at `Ai/scheduled`, where the application lets
    // the analyst schedule (its routineRefusal()).
    use \Pramnos\Llm\Chat\Web\SchedulesTasks;
    use \Pramnos\Llm\Chat\Web\KeepsSkills;
    use \Pramnos\Llm\Chat\Web\KeepsCatalogSkills;
    use \Pramnos\Llm\Chat\Web\SharesConversations {
        shared as protected sharedInAsk;
    }
    use \Pramnos\Llm\Chat\Web\ActsOnLists;
    use \Pramnos\Llm\Chat\Web\RatesAnswers;
    use \Pramnos\Llm\Chat\Web\ReadsAloud;
    use \Pramnos\Llm\Chat\Web\ChoosesModel;

    /** The administration ability that opens this screen — its menu item's id. */
    protected string $adminAbility = 'admin.ai';

    /** The usertype floor; 0 is the administration area's — {@see \Pramnos\Auth\AdminAccess::defaultUsertype()}, the `super` role unless set. */
    protected int $requiredUserType = 0;

    /** The parts of the screen a form can save. */
    public const SECTIONS = ['general', 'key', 'provider', 'features', 'prices', 'openai', 'modelchoices'];

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'all', 'settings', 'avatar', 'save', 'check', 'call', 'analyst', 'ask', 'asked', 'cancel', 'delete', 'forget', 'consent', 'api', 'export',
            'scheduled', 'schedule', 'scheduledupdate', 'scheduleddelete', 'scheduledrun', 'scheduledsubscribe', 'report',
            'skills', 'skillsave', 'skillturn', 'skilldelete', 'skillshare', 'skillapprove', 'skillreject', 'memories', 'memorycorrect', 'memorydelete',
            'skillcatalog', 'skillinstall', 'skillcatalogturn', 'skillcatalogrole', 'skillcatalogupdate', 'skillcatalogdelete',
            'share', 'unshare', 'shared', 'people', 'act', 'assign', 'rerun', 'view', 'approve', 'reject', 'rate', 'reviews', 'reviewed', 'download', 'printable', 'pin', 'speak', 'voicereply', 'modelchoice',
            'companionreset', 'conversationsdelete', 'holdingsdelete', 'artifact']);
        // POST with the session's token: each of these changes what the installation spends, or spends.
        // `api` checks its own token, from the X-CSRF-Token header, since it also answers GET and DELETE.
        $this->addWriteAction(['save', 'check', 'ask', 'cancel', 'delete', 'forget', 'consent',
            'schedule', 'scheduledupdate', 'scheduleddelete', 'scheduledrun', 'scheduledsubscribe', 'share', 'unshare', 'act', 'approve', 'reject', 'rate', 'reviewed', 'pin', 'speak', 'voicereply', 'modelchoice',
            'skillsave', 'skillturn', 'skilldelete', 'skillshare', 'skillapprove', 'skillreject', 'memorycorrect', 'memorydelete',
            'skillinstall', 'skillcatalogturn', 'skillcatalogrole', 'skillcatalogupdate', 'skillcatalogdelete', 'companionreset', 'conversationsdelete', 'holdingsdelete']);
        parent::__construct($application);
    }

    /**
     * Ask: the administrator's conversation with the analyst, about the installation's figures.
     * `?show=problems`, the settings' old address for failed calls, goes to the settings.
     */
    public function display(): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }

        $request = new \Pramnos\Http\Request();

        if ((string) $request->get('show', '', 'get') !== '') {
            $this->redirect(adminUrl('Ai/settings') . '?show=' . rawurlencode((string) $request->get('show', '', 'get')));

            return null;
        }

        // Nothing to ask without a key: the settings, where it is set.
        if (!\Pramnos\Llm\Analyst\Analyst::available()) {
            $this->addMessage('Set a provider\'s API key to use Ask.');
            $this->redirect(adminUrl('Ai/settings'));

            return null;
        }

        $page = $this->analystDesk()->page((int) $request->get('c', 0, 'get', 'int'), (int) $request->get('ask', 0, 'get', 'int'));

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = \Pramnos\Llm\Analyst\Analyst::name();

        $view              = $this->getView('ai');
        $view->heading     = \Pramnos\Llm\Analyst\Analyst::name();
        $view->headingAvatar = \Pramnos\Assist\AvatarSets::render(\Pramnos\Llm\Analyst\Analyst::available() ? 'idle' : 'offline', 32);
        $view->settingsUrl = $this->settingsLink();
        $analyst           = Llm::config('analyst', []);
        $view->waitingLines = is_array($analyst['waiting_lines'] ?? null) ? $analyst['waiting_lines'] : [];
        $view->suggestions  = is_array($analyst['suggestions'] ?? null) ? $analyst['suggestions'] : [];
        $view->assistantName = is_string($analyst['name'] ?? null) ? $analyst['name'] : t('Analyst');
        $view->greeting = \Pramnos\Llm\Chat\Web\ChatLook::greeting(is_array($analyst) ? $analyst : []);
        foreach (['placeholder', 'accent'] as $key) {
            $view->{$key} = is_string($analyst[$key] ?? null) ? $analyst[$key] : '';
        }
        $view->commands = is_array($analyst['commands'] ?? null) ? $analyst['commands'] : [];
        // `?q=`: a question begun elsewhere — "Ask about this" on a report's card — left to be sent.
        $view->prefill  = mb_substr((string) $request->get('q', '', 'get'), 0, 2000);
        $view->urls        = [
            'page'    => adminUrl('Ai'),
            'ask'     => adminUrl('Ai/ask'),
            'asked'   => adminUrl('Ai/asked/'),
            'consent' => adminUrl('Ai/consent'),
            'delete'  => adminUrl('Ai/delete/'),
            'forget'  => adminUrl('Ai/forget/'),
            // The analyst's scheduled tasks, where the application lets it schedule.
            'scheduled' => $this->analystSchedules() ? adminUrl('Ai/scheduled') : '',
            // Skills of a role and the analyst's own, where the application turned them on.
            'skills'    => \Pramnos\Llm\Chat\StoredSkills::enabled() ? adminUrl('Ai/skills') : '',
            // The application's skills from the catalogues, for whoever manages skills.
            'skillcatalog' => \Pramnos\Llm\Chat\CatalogSkills::canManage($this->reader()) ? adminUrl('Ai/skillcatalog') : '',
            // Everybody's Ask memory, for whoever reads everybody's conversations.
            'memories'  => $this->readsAll($this->reader()) && !$this->assistantsInMenu() ? adminUrl('Ai/memories') : '',
            // Everybody's conversations, read only, for whoever reads them all.
            'all'       => $this->readsAll($this->reader()) && !$this->assistantsInMenu() ? adminUrl('Ai/all') : '',
            'share'     => adminUrl('Ai/share/'),
            'unshare'   => adminUrl('Ai/unshare/'),
            'shared'    => adminUrl('Ai/shared/'),
            'act'       => adminUrl('Ai/act/'),
            'approve'   => adminUrl('Ai/approve/'),
            'reject'    => adminUrl('Ai/reject/'),
            'rate'      => adminUrl('Ai/rate/'),
            'download'  => adminUrl('Ai/download/'),
            'print'     => adminUrl('Ai/printable/'),
            'pin'       => adminUrl('Ai/pin/'),
            // Handing an artifact to another administrator, where shares and to-dos are kept.
            'assign'    => \Pramnos\Llm\Chat\Shares::available() ? adminUrl('Ai/assign/') : '',
            'rerun'     => adminUrl('Ai/rerun/'),
            'view'      => adminUrl('Ai/view/'),
            // Answers read aloud by the speech server, where there is one.
            'speak'     => self::readsAloudOnServer() ? adminUrl('Ai/speak') : '',
            'voicereply' => adminUrl('Ai/voicereply'),
            'modelchoice' => adminUrl('Ai/modelchoice'),
            // The bad answers to look at, for whoever reads everybody's.
            'reviews'   => $this->readsAll($this->reader()) && !$this->assistantsInMenu() ? adminUrl('Ai/reviews') : '',
        ];
        foreach ($page as $name => $value) {
            $view->{$name} = $value;
        }
        foreach ($this->sharing($this->taskScope(), (int) $request->get('c', 0, 'get', 'int')) as $name => $value) {
            $view->{$name} = $value;
        }
        $view->bulkActions = $this->listActions($this->taskScope(), is_array($page['conversation'] ?? null) ? $page['conversation'] : null);
        $view->pinned      = \Pramnos\Llm\Chat\Artifacts::pinnedIds(new \Pramnos\User\User($this->taskScope()->userId));

        return $view->display('ask');
    }

    /**
     * The assistants' control centre, read only: everybody's conversations, channel by channel —
     * the Ask's, each companion persona's, any other space — by owner, by a word in the title, and
     * one opened as its owner sees it, under a banner saying whose it is. Beside a companion's, what
     * it knows of its owner (`Chat\People::person()`), and the way to reset their experience where
     * {@see resetsCompanion()} allows. Nothing is written but the audit log's line for a view of
     * somebody else's. A 404 to whoever does not read them all.
     */
    public function all(): mixed
    {
        $me = $this->reader();
        if (!$this->readsAll($me)) {
            ($this->application ?? \Pramnos\Application\Application::currentInstance())?->notFound();

            return null;
        }
        $request = new \Pramnos\Http\Request();
        $channels = \Pramnos\Llm\Analyst\Review::channels();
        $openId  = (int) $request->get('c', 0, 'get', 'int');
        $who     = (int) $request->get('person', 0, 'get', 'int');
        $urls    = ['all' => adminUrl('Ai/all'), 'call' => adminUrl('Ai/call/'), 'ask' => $this->assistantsInMenu() ? '' : adminUrl('Ai'), 'reset' => adminUrl('Ai/companionreset'), 'delete' => adminUrl('Ai/conversationsdelete'), 'deleteHeld' => adminUrl('Ai/holdingsdelete'), 'artifact' => adminUrl('Ai/artifact/'),
            'reviews' => adminUrl('Ai/reviews'), 'memories' => adminUrl('Ai/memories'), 'settings' => adminUrl('Ai/settings'), 'scheduled' => adminUrl('Ai/scheduled')];
        // Without a conversation: the dashboard — every channel's figures and people — or one person's card.
        if ($openId === 0) {
            return $who > 0 ? $this->assistantPerson($me, $who, $channels, $urls) : $this->assistantsDashboard($request, $channels, $urls);
        }
        $channel  = (string) $request->get('channel', \Pramnos\Llm\Chat\Scope::ADMIN, 'get');
        $channel  = isset($channels[$channel]) ? $channel : \Pramnos\Llm\Chat\Scope::ADMIN;
        $filters  = ['channel' => $channel, 'person' => 0, 'q' => (string) $request->get('q', '', 'get')];
        $open    = $openId > 0 ? \Pramnos\Llm\Analyst\Review::open($openId) : null;
        if ($openId > 0 && $open === null) {
            ($this->application ?? \Pramnos\Application\Application::currentInstance())?->notFound();

            return null;
        }
        if ($open !== null && $open['owner'] === (int) $me->userid && $open['channel'] === \Pramnos\Llm\Chat\Scope::ADMIN) {
            // One's own opens in Ask, where it is not read only.
            $this->redirect(adminUrl('Ai') . '?c=' . $openId);

            return null;
        }
        if ($open !== null) {
            \Pramnos\Auth\AuditLog::record('ai.conversation_viewed', (int) $me->userid, $open['owner'], ['title' => $open['conversation']['title']],
                'chat_conversation', $openId);
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = t('The assistants');
        $ask        = $channel === \Pramnos\Llm\Chat\Scope::ADMIN;
        $companion  = $open !== null && \Pramnos\Llm\Chat\People::companion($open['channel']);

        $view           = $this->getView('ai');
        $view->list     = \Pramnos\Llm\Analyst\Review::list($filters + ($ask ? ['except' => (int) $me->userid] : []));
        $view->owners   = \Pramnos\Llm\Analyst\Review::owners($ask ? (int) $me->userid : 0, $channel);
        $view->channels = $channels;
        $view->filters  = $filters;
        $view->open     = $open;
        $view->person   = $companion ? (new \Pramnos\Llm\Chat\People())->person($open['owner']) : null;
        $view->resets   = $companion && $this->resetsCompanion($me);
        $view->me       = (int) $me->userid;
        // Back to the Ask only where it is reached from there, not from the application's own menu.
        $view->urls     = $urls;

        return $view->display('review');
    }

    /**
     * The control centre's front: a channel's figures — or every channel's — with a chart of its
     * days, and the people who talked to it, searched and sorted (`Analyst\Overview`).
     *
     * @param array<string, string> $channels
     * @param array<string, string> $urls
     */
    private function assistantsDashboard(\Pramnos\Http\Request $request, array $channels, array $urls): mixed
    {
        $channel  = (string) $request->get('channel', '', 'get');
        $channel  = isset($channels[$channel]) ? $channel : '';
        $sort     = (string) $request->get('sort', 'last', 'get');
        $search   = (string) $request->get('q', '', 'get');
        $overview = new \Pramnos\Llm\Analyst\Overview();
        \Pramnos\Framework\Factory::getDocument()->title = t('The assistants');
        $view           = $this->getView('ai');
        $view->channels = $channels;
        $view->filters  = ['channel' => $channel, 'q' => $search, 'sort' => in_array($sort, \Pramnos\Llm\Analyst\Overview::SORTS, true) ? $sort : 'last'];
        $view->stats    = $overview->stats($channel);
        $view->people   = $overview->people($channel, $search, $sort);
        $view->urls     = $urls;

        return $view->display('assistants');
    }

    /**
     * One person's card: what the companion knows of them, their conversations by channel to read,
     * their memories by topic, and the experience reset where the reader may. A 404 for nobody.
     *
     * @param array<string, string> $channels
     * @param array<string, string> $urls
     */
    private function assistantPerson(object $me, int $who, array $channels, array $urls): mixed
    {
        $person = (new \Pramnos\Llm\Chat\People())->person($who);
        if ($person === null) {
            ($this->application ?? \Pramnos\Application\Application::currentInstance())?->notFound();

            return null;
        }
        \Pramnos\Auth\AuditLog::record('ai.person_viewed', (int) $me->userid, $who, [], 'user', $who);
        $overview = new \Pramnos\Llm\Analyst\Overview();
        \Pramnos\Framework\Factory::getDocument()->title = \Pramnos\Conversations\People::names([$who])[$who] ?? '#' . $who;
        $view                = $this->getView('ai');
        $view->channels      = $channels;
        $view->card          = $person + ['name' => \Pramnos\Conversations\People::names([$who])[$who] ?? '#' . $who];
        $view->conversations = $overview->conversationsOf($who);
        $view->memories      = $overview->memoriesOf($who);
        $view->holdings      = $overview->holdingsOf($who) + $this->personSections($who);
        $view->resets        = $this->resetsCompanion($me);
        $view->deletes       = $this->deletesConversations($me);
        $view->deletable     = self::DELETABLE;
        $view->urls          = $urls;

        return $view->display('assistant');
    }

    /**
     * The application's own sections on a person's card, beside the framework's: title => rows
     * (each an array of column => value; a `conversation_id` column links to the conversation).
     * None by default; an application adds its own by overriding this.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    protected function personSections(int $userId): array
    {
        return [];
    }

    /** The sections of a person's card whose rows root may delete there, and the audit event each deletion writes. */
    public const DELETABLE = ['routines' => 'ai.routine_deleted', 'calendar' => 'ai.calendar_item_deleted', 'artifacts' => 'ai.artifact_deleted',
        'shares' => 'ai.share_deleted', 'dossier' => 'ai.dossier_entry_deleted', 'skills' => 'ai.skill_deleted', 'memories' => 'ai.memory_deleted'];

    /** Where each deletable section's rows are, by their id: table, id column. */
    private const HELD = ['routines' => ['pramnos.ai_routines', 'routine_id'], 'calendar' => ['assist.chat_calendar', 'item_id'],
        'artifacts' => ['assist.chat_artifacts', 'artifact_id'], 'shares' => ['assist.chat_shares', 'share_id'], 'dossier' => ['assist.chat_dossier', 'dossier_id'],
        'skills' => [\Pramnos\Llm\Chat\StoredSkills::TABLE, 'skill_id'], 'memories' => ['assist.chat_memory', 'memory_id']];

    /**
     * Who may delete for good, from a person's card, their conversations and anything else listed
     * there that can be deleted ({@see DELETABLE}): root, by default. An application gives it to
     * whom it decides by overriding this.
     */
    protected function deletesConversations(object $user): bool
    {
        return $this->readsAll($user) && \Pramnos\User\Roles::atLeast($user, \Pramnos\User\Roles::ROOT);
    }

    /**
     * Delete rows of one section of a person's card for good — `section` (one of {@see DELETABLE})
     * and `ids[]` in the form, with the session's token — each through what owns it, so nothing is
     * left behind: a routine with its runs and readers, an artifact with its members and shares.
     * Each is audited. A 404 to whoever may not ({@see deletesConversations()}).
     */
    public function holdingsdelete(): mixed
    {
        $me      = $this->reader();
        $section = (string) ($_POST['section'] ?? '');
        if ($me === null || !$this->deletesConversations($me) || !isset(self::HELD[$section])) {
            ($this->application ?? \Pramnos\Application\Application::currentInstance())?->notFound();

            return null;
        }
        [$table, $key] = self::HELD[$section];
        $ids  = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])), static fn (int $id): bool => $id > 0)));
        $rows = $ids === [] ? [] : \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table($table)->whereIn($key, $ids)->getAllOrFail();
        $done = 0;
        foreach ($rows as $row) {
            $id    = (int) $row[$key];
            $owner = (int) ($row['userid'] ?? 0);
            $scope = new \Pramnos\Llm\Chat\Scope($owner, isset($row['organization_id']) ? (int) $row['organization_id'] : null, (string) ($row['space'] ?? \Pramnos\Llm\Chat\Scope::CHAT));
            $gone  = match ($section) {
                'routines'  => (new \Pramnos\Llm\Chat\Routines((new \Pramnos\Llm\Chat\Conversations(new \Pramnos\Llm\Chat\BaseAssistant()))->as($scope)))->delete($scope, $id),
                'calendar'  => (new \Pramnos\Llm\Chat\Calendar($scope))->delete($id),
                'artifacts' => (new \Pramnos\Llm\Chat\Artifacts($scope))->delete($id),
                'shares'    => (new \Pramnos\Llm\Chat\Shares())->revoke($scope, $id),
                'dossier'   => (new \Pramnos\Llm\Chat\Profile($scope))->forget($id),
                'skills'    => (new \Pramnos\Llm\Chat\StoredSkills())->delete(new \Pramnos\User\User($owner), $id),
                'memories'  => (new \Pramnos\Llm\Chat\MemoryAdmin())->delete($me, $id), // audits it itself
            };
            if ($gone) {
                if ($section !== 'memories') {
                    \Pramnos\Auth\AuditLog::record(self::DELETABLE[$section], (int) $me->userid, $owner > 0 ? $owner : null,
                        ['title' => (string) ($row['title'] ?? $row['subject'] ?? $row['name'] ?? '')], $section, $id);
                }
                $done++;
            }
        }
        $done === 0 ? $this->addError(t('There was nothing to delete.')) : $this->addMessage(sprintf(t('%d deleted.'), $done));
        $back = (string) ($_POST['back'] ?? '');
        $this->redirect(adminUrl('Ai/all') . (preg_match('/^\?[A-Za-z0-9=&_:%-]*$/', $back) === 1 ? $back : ''));

        return null;
    }

    /**
     * `Ai/artifact/{id}` — anybody's artifact, read only inside the administration, with the way
     * back to its owner's card, for whoever reads everybody's conversations; each reading audited
     * (`ai.artifact_viewed`). `?print=1` gives it on a page of its own, to print. A 404 otherwise.
     */
    public function artifact(mixed $id = null): mixed
    {
        $me  = $this->reader();
        $id  = (int) ($id ?: \Pramnos\Http\Request::staticGetOption());
        $row = $me === null || !$this->readsAll($me) || $id < 1 ? null
            : (\Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('assist.chat_artifacts')->where('artifact_id', $id)->limit(1)->getAllOrFail()[0] ?? null);
        $artifacts = $row === null ? null : new \Pramnos\Llm\Chat\Artifacts(new \Pramnos\Llm\Chat\Scope((int) $row['userid'], $row['organization_id'] === null ? null : (int) $row['organization_id'], (string) $row['space']));
        $found     = $artifacts?->find($id);
        if ($found === null) {
            ($this->application ?? \Pramnos\Application\Application::currentInstance())?->notFound();

            return null;
        }
        $embed = static fn (int $one): ?array => $artifacts->find($one);
        $owner = (int) $row['userid'];
        if (!empty($_GET['print'])) {
            return $this->sendFile('', 'text/html; charset=utf-8', \Pramnos\Llm\Chat\Web\ArtifactTools::printable($found, $embed));
        }
        \Pramnos\Auth\AuditLog::record('ai.artifact_viewed', (int) $me->userid, $owner, [], 'artifact', $id);
        $person = \Pramnos\Conversations\People::names([$owner])[$owner] ?? '#' . $owner;
        \Pramnos\Framework\Factory::getDocument()->title = (string) $found['title'];
        $view           = $this->getView('ai');
        $view->artifact = $found;
        $view->embed    = $embed;
        $view->person   = $person;
        $view->urls     = ['all' => adminUrl('Ai/all'), 'card' => adminUrl('Ai/all') . '?person=' . $owner, 'print' => adminUrl('Ai/artifact/' . $id) . '?print=1'];

        return $view->display('artifact');
    }

    /**
     * Delete conversations for good, from a person's card: `ids[]` in the form, with the session's
     * token; their messages, questions, what they made and their shares go with them. Each is
     * audited (`ai.conversation_deleted`). A 404 to whoever may not ({@see deletesConversations()}).
     */
    public function conversationsdelete(): mixed
    {
        $me = $this->reader();
        if ($me === null || !$this->deletesConversations($me)) {
            ($this->application ?? \Pramnos\Application\Application::currentInstance())?->notFound();

            return null;
        }
        $ids  = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])), static fn (int $id): bool => $id > 0)));
        $rows = $ids === [] ? [] : \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('assist.chat_conversations')
            ->select(['conversation_id', 'userid', 'organization_id', 'space', 'title'])->whereIn('conversation_id', $ids)->getAllOrFail();
        $done = 0;
        foreach ($rows as $row) {
            $owner = (int) $row['userid'];
            $scope = new \Pramnos\Llm\Chat\Scope($owner, $row['organization_id'] === null ? null : (int) $row['organization_id'], (string) $row['space']);
            if ((new \Pramnos\Llm\Chat\Conversations(new \Pramnos\Llm\Chat\BaseAssistant()))->as($scope)->delete((int) $row['conversation_id'])) {
                \Pramnos\Auth\AuditLog::record('ai.conversation_deleted', (int) $me->userid, $owner, ['conversation_id' => (int) $row['conversation_id'], 'space' => (string) $row['space'], 'title' => (string) $row['title']], 'user', $owner);
                $done++;
            }
        }
        $done === 0 ? $this->addError(t('There was nothing to delete.')) : $this->addMessage(sprintf(t('%d conversations deleted.'), $done));
        $back = (string) ($_POST['back'] ?? '');
        $this->redirect(adminUrl('Ai/all') . (preg_match('/^\?[A-Za-z0-9=&_:%-]*$/', $back) === 1 ? $back : ''));

        return null;
    }

    /**
     * Who may reset a person's experience of the companion, from the control centre: root, by default.
     * An application gives it to whom it decides by overriding this.
     */
    protected function resetsCompanion(object $user): bool
    {
        return $this->readsAll($user) && \Pramnos\User\Roles::atLeast($user, \Pramnos\User\Roles::ROOT);
    }

    /**
     * Reset a person's experience of the companion (`Chat\People::reset()`) — their choices back
     * to the defaults and each persona meeting them again, their history kept: `userid` in the form, with
     * the session's token; `back`, the control centre's query to go back to. A 404 to whoever may
     * not ({@see resetsCompanion()}).
     */
    public function companionreset(): mixed
    {
        $me = $this->reader();
        if ($me === null || !$this->resetsCompanion($me)) {
            ($this->application ?? \Pramnos\Application\Application::currentInstance())?->notFound();

            return null;
        }
        $person = (int) ($_POST['userid'] ?? 0);
        if ($person < 1) {
            $this->addError(t('There is nobody to reset.'));
        } else {
            (new \Pramnos\Llm\Chat\People())->reset($person, (int) $me->userid);
            $this->addMessage(t('The experience was reset. Their conversations and memories are kept.'));
        }
        $back = (string) ($_POST['back'] ?? '');
        $this->redirect(adminUrl('Ai/all') . (preg_match('/^\?[A-Za-z0-9=&_:%-]*$/', $back) === 1 ? $back : ''));

        return null;
    }

    /**
     * `Ai/reviews` — the bad answers nobody has looked at yet, for whoever reads everybody's Ask
     * conversations (it shows their questions). A 404 to anybody else.
     */
    public function reviews(): mixed
    {
        $me = $this->reader();
        if (!$this->readsAll($me)) {
            ($this->application ?? \Pramnos\Application\Application::currentInstance())?->notFound();

            return null;
        }
        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = t('Answers to review');
        $ratings       = new \Pramnos\Llm\Chat\Ratings();
        $view          = $this->getView('ai');
        $view->queue   = $ratings->queue();
        $view->summary = $ratings->summary();
        $view->urls    = ['reviewed' => adminUrl('Ai/reviewed/'), 'open' => adminUrl('Ai/all') . '?c=', 'back' => adminUrl('Ai')];

        return $view->display('reviews');
    }

    /** `Ai/reviewed/{message}` — take a bad answer out of the queue, as looked at. */
    public function reviewed(mixed $id = null): mixed
    {
        $me = $this->reader();
        if (!$this->readsAll($me)) {
            ($this->application ?? \Pramnos\Application\Application::currentInstance())?->notFound();

            return null;
        }
        (new \Pramnos\Llm\Chat\Ratings())->reviewed((int) ($id ?: \Pramnos\Http\Request::staticGetOption()), (int) $me->userid)
            ? $this->addMessage('Marked reviewed.') : $this->addError('That answer is not waiting to be reviewed.');
        $this->redirect(adminUrl('Ai/reviews'));

        return null;
    }

    /**
     * The screen's floor: `analyst.floor` where the application sets it, else the administration's.
     * Reports are offered, and sent, to nobody below it.
     */
    protected function adminFloor(): int
    {
        $analyst = Llm::config('analyst', []);
        $floor   = is_array($analyst) ? (int) ($analyst['floor'] ?? 0) : 0;

        return $floor > 0 ? $floor : parent::adminFloor();
    }

    /**
     * `Ai/shared/{share}` — where a share's notification leads: opened as a conversation of one's
     * own in Ask, or, for somebody who cannot open Ask, read here as it was shared.
     */
    public function shared(mixed $id = null): mixed
    {
        if (!$this->readsOnly()) {
            return $this->sharedInAsk($id);
        }
        $found = (new \Pramnos\Llm\Chat\Shares())->snapshot((int) ($id ?: \Pramnos\Http\Request::staticGetOption()), (int) $this->reader()->userid);
        if ($found === null) {
            ($this->application ?? \Pramnos\Application\Application::currentInstance())?->notFound();

            return null;
        }

        return $this->readOnlyPage($found['title'], $found['messages'], sprintf(t('Shared with you by %s'), $found['from']));
    }

    /** Shared reports are offered only to roles that may open this screen — unless the application lets them go lower. */
    protected function audienceFloor(): int
    {
        return \Pramnos\Llm\Chat\TaskPolicy::reportsBelowFloor() ? 0 : $this->adminFloor();
    }

    /**
     * Whether whoever is signed in is below this screen's floor: they read a report or a share sent
     * to them read only, since they cannot open Ask.
     */
    protected function readsOnly(): bool
    {
        $user = $this->reader();

        return $user !== null && (int) ($user->userid ?? 0) > 1 && (int) ($user->usertype ?? 0) < $this->adminFloor();
    }

    /**
     * A page that only shows: a report's run, or what was shared, for somebody who cannot open Ask.
     *
     * @param list<array<string, mixed>> $messages
     */
    protected function readOnlyPage(string $title, array $messages, string $note): mixed
    {
        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = $title;
        $view           = $this->getView('ai');
        $view->title    = $title;
        $view->messages = $messages;
        $view->note     = $note;

        return $view->display('readonly');
    }

    /** Who is signed in, or null. A seam for tests. */
    protected function reader(): ?object
    {
        $user = \Pramnos\User\User::getCurrentUser();

        return is_object($user) ? $user : null;
    }

    /**
     * `Ai/memories` — everybody's Ask memory ({@see \Pramnos\Llm\Chat\MemoryAdmin}), for whoever
     * reads everybody's conversations: who has memories, and with `?person=&org=&space=` one
     * person's (0, an organisation's shared ones) to correct or delete. Each reading is in the
     * audit log.
     */
    public function memories(): mixed
    {
        $me = $this->reader();
        if (!$this->readsAll($me)) {
            ($this->application ?? \Pramnos\Application\Application::currentInstance())?->notFound();

            return null;
        }
        $admin  = new \Pramnos\Llm\Chat\MemoryAdmin();
        $person = isset($_GET['person']) ? (int) $_GET['person'] : null;
        $org    = isset($_GET['org']) && $_GET['org'] !== '' ? (int) $_GET['org'] : null;
        // The channel it shows: the one it is opened from — the Ask's, from Ask — said on the page.
        $space  = (string) ($_GET['space'] ?? \Pramnos\Llm\Chat\Scope::ADMIN);

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = t('Everybody\'s memory');
        $view           = $this->getView('ai');
        $view->people   = array_values(array_filter($admin->people(), static fn (array $who): bool => $who['space'] === $space));
        $view->channels = \Pramnos\Llm\Analyst\Review::channels() + [$space => \Pramnos\Llm\Chat\Memory::channelName($space)];
        $view->topics   = \Pramnos\Llm\Chat\Memory::TOPICS;
        $view->person   = $person;
        $view->org      = $org;
        $view->space    = $space;
        $view->memories = $person === null ? [] : $admin->of($me, $person, $org, $space);
        $view->urls     = ['page' => adminUrl('Ai/memories'), 'correct' => adminUrl('Ai/memorycorrect'), 'delete' => adminUrl('Ai/memorydelete'), 'chat' => adminUrl('Ai')];

        return $view->display('memories');
    }

    /** Correct one memory's words: `memory_id` and `content` in the form. */
    public function memorycorrect(): mixed
    {
        return $this->memoryDone(fn (object $me, int $id): bool => (new \Pramnos\Llm\Chat\MemoryAdmin())->correctOn($me, $id, (string) ($_POST['content'] ?? ''),
            isset($_POST['topic']) ? (string) $_POST['topic'] : null), 'Saved.');
    }

    /** Delete one memory: `memory_id` in the form. */
    public function memorydelete(): mixed
    {
        return $this->memoryDone(fn (object $me, int $id): bool => (new \Pramnos\Llm\Chat\MemoryAdmin())->delete($me, $id), 'Deleted.');
    }

    /**
     * Run a memory action for whoever reads everybody's conversations, say how it went, and go
     * back to the person's memories (`back` in the form).
     *
     * @param callable(object, int): bool $do
     */
    private function memoryDone(callable $do, string $done): mixed
    {
        $me = $this->reader();
        if ($me === null || !$this->readsAll($me)) {
            ($this->application ?? \Pramnos\Application\Application::currentInstance())?->notFound();

            return null;
        }
        $do($me, (int) ($_POST['memory_id'] ?? 0)) ? $this->addMessage(t($done)) : $this->addError(t('There is no such memory.'));
        $back = (string) ($_POST['back'] ?? '');
        $this->redirect(adminUrl('Ai/memories') . (preg_match('/^\?[A-Za-z0-9=&_:%-]*$/', $back) === 1 ? $back : ''));

        return null;
    }

    /**
     * Whether the assistants' management — the control centre, everybody's memory, the answers to
     * review — is in the application's own menu, and so not linked from the Ask's gear, which keeps
     * what is the Ask's: `'llm' => ['analyst' => ['assistants_in_menu' => true]]`. Overridable.
     */
    protected function assistantsInMenu(): bool
    {
        $analyst = \Pramnos\Llm\Llm::config('analyst', []);

        return is_array($analyst) && !empty($analyst['assistants_in_menu']);
    }

    /** Whether an account reads every administrator's Ask conversations — `admin.ai.all`, root's in usertype mode. */
    protected function readsAll(?object $user): bool
    {
        return \Pramnos\Llm\Analyst\Review::readsAll($user);
    }

    /**
     * The settings: providers and keys, features, prices, what was spent and what failed.
     */
    public function settings(): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'AI settings';

        $request  = new \Pramnos\Http\Request();
        $problems = (string) $request->get('show', '', 'get') === 'problems';
        $log      = $this->log();

        $view            = $this->getView('ai');
        $view->providers = $this->providers();
        $view->selected  = Providers::resolve();
        $view->timeout   = (int) Settings::getSetting(Providers::TIMEOUT_SETTING, 0);
        $view->logging   = $log->isEnabled();
        $view->features  = $this->features();
        $view->prices    = $this->pricesJson();
        $view->day       = $log->summary(24);
        $view->month     = $log->summary(24 * 30);
        $view->byModel   = $log->byModel(24 * 30);
        $view->calls     = $log->recent(25, $problems);
        $view->problems  = $problems;
        $view->openai    = $this->openAiCosts();
        $view->appSections = $this->sections();
        $view->canEncrypt = \Pramnos\Security\Encrypter::isAvailable();

        return $view->display();
    }

    /**
     * The assistant's picture in every state: the set in use, large, a button per state (the one
     * playing marked; those that end by themselves going back to idle), a pose of any name, the
     * active/away preview, and — where the set declares them — its signals and the state each
     * plays. Opened as the settings are.
     */
    public function avatar(): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = t('The assistant\'s picture');

        $view = $this->getView('ai');
        // With personas, one of them at a time (?persona=): its own set, name, signals and moves.
        $personas = \Pramnos\Llm\Chat\Personas::all();
        $key      = (string) ($_GET['persona'] ?? '');
        $key      = isset($personas[$key]) ? $key : (string) \Pramnos\Llm\Chat\Personas::default();
        $fill     = static function () use ($view): void {
            $view->picture = \Pramnos\Assist\AvatarSets::render('idle', 160);
            $view->states  = \Pramnos\Assist\AvatarSets::states();
            $view->poses   = \Pramnos\Assist\AvatarSets::poses();
            $view->moods   = \Pramnos\Assist\AvatarSets::moods();
            $view->acts    = array_map(static fn (array $a): string => $a['plays'], \Pramnos\Llm\Chat\Acts::acts());
            $view->wear    = array_map(static fn (array $w): string => $w['pose'], \Pramnos\Llm\Chat\Acts::wear());
            $view->gaits   = \Pramnos\Assist\AvatarSets::gaits();
        };
        if ($key !== '') {
            $set = $personas[$key]['avatar_set'];
            \Pramnos\Assist\AvatarSets::using(is_string($set) || is_array($set) ? $set : null, $fill, $personas[$key]['name']);
        } else {
            $fill();
        }
        $view->personas = array_values(array_map(static fn (array $p): array => ['key' => $p['key'], 'name' => $p['name'],
            'url' => adminUrl('Ai/avatar') . '?persona=' . rawurlencode($p['key']), 'current' => $p['key'] === $key], $personas));
        // The companion's games, tried on the preview (pf-companion-play.js).
        $view->play = assetUrl('assets/js/pf-companion-play.js');
        $view->urls = ['settings' => adminUrl('Ai/settings'), 'chat' => adminUrl('Ai')];

        return $view->display('avatar');
    }

    /**
     * Save one part of the screen.
     */
    public function save(): void
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return;
        }

        $input   = $_POST;
        $section = (string) ($input['section'] ?? '');
        $error   = match ($section) {
            'general'  => $this->saveGeneral($input),
            'key'      => $this->saveKey($input),
            'provider' => $this->saveProvider($input),
            'features' => $this->saveFeatures($input),
            'prices'   => $this->savePrices($input),
            'openai'   => $this->saveOpenAi($input),
            default    => $this->saveSection($section, $input),
        };

        Connection::forget();
        OpenAiCosts::forget();

        $error === null ? $this->addMessage('Saved.') : $this->addError($error);
        $this->redirect(adminUrl('Ai/settings'));
    }

    /**
     * Ask a provider whether its key works.
     */
    public function check(): void
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return;
        }

        $provider = (string) ($_POST['provider'] ?? '');

        if (!Providers::isKnown($provider)) {
            $this->addError('There is no provider called ' . $provider . '.');
        } else {
            $result = $this->connection($provider)->check();
            $result['ok'] ? $this->addMessage($result['says']) : $this->addError($result['says']);
        }

        $this->redirect(adminUrl('Ai/settings'));
    }

    /** The analyst's first address: the Ask page now. */
    public function analyst(): mixed
    {
        $query = array_filter(['c' => (int) ($_GET['c'] ?? 0), 'ask' => (int) ($_GET['ask'] ?? 0)]);
        $this->redirect(adminUrl('Ai') . ($query === [] ? '' : '?' . http_build_query($query)), true, '301');

        return null;
    }

    /**
     * Ask the analyst. A redirect to the page following the question — or JSON, for
     * `pf-chat.js`, which sends `Accept: application/json`.
     */
    public function ask(): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }

        $input  = $_POST;
        $result = $this->analystDesk()->ask($input, \Pramnos\Llm\Chat\Attachments::uploaded());

        if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
            return $this->analystJson($result);
        }

        $conversationId = (int) ($result['data']['conversation_id'] ?? ($input['conversation_id'] ?? 0));

        if ($result['status'] >= 400) {
            $this->addError((string) $result['error']);
        }

        $this->redirect($this->analystUrl($conversationId, (int) ($result['data']['ask_id'] ?? 0)));

        return null;
    }

    /** A question the analyst is answering, for `pf-chat.js`. */
    public function asked(mixed $id = null): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }

        $found = $this->analystDesk()->asked((int) ($id ?: \Pramnos\Http\Request::staticGetOption()));

        return $this->analystJson($found === null ? ['status' => 404, 'error' => 'There is no such question.'] : ['status' => 200, 'data' => $found]);
    }

    /** Forget one analyst conversation. */
    /** Stop a question the analyst is answering, for `pf-chat.js`. */
    public function cancel(mixed $id = null): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }

        $stopped = $this->analystDesk()->cancel((int) ($id ?: \Pramnos\Http\Request::staticGetOption()));

        return $this->analystJson($stopped ? ['status' => 200, 'data' => ['cancelled' => true]] : ['status' => 404, 'error' => 'There is no such question being answered.']);
    }

    /** Forget one of the analyst's conversations. */
    public function delete(mixed $id = null): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }

        $this->analystDesk()->forget((int) ($id ?: \Pramnos\Http\Request::staticGetOption()))
            ? $this->addMessage('The conversation is forgotten.')
            : $this->addError('There is no such conversation.');
        $this->redirect($this->analystUrl());

        return null;
    }

    /** Forget one thing the analyst remembers. */
    public function forget(mixed $id = null): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }

        $this->analystDesk()->forgetMemory((int) ($id ?: \Pramnos\Http\Request::staticGetOption()))
            ? $this->addMessage('Forgotten.')
            : $this->addError('There is no such memory.');
        $this->redirect($this->analystUrl((int) ($_POST['conversation_id'] ?? 0)));

        return null;
    }

    /** Agree to the analyst's provider, or withdraw — `give` in the form. */
    public function consent(): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }

        $this->analystDesk()->consent(!empty($_POST['give']));
        $this->redirect($this->analystUrl((int) ($_POST['conversation_id'] ?? 0)));

        return null;
    }

    /**
     * The analyst as JSON, in the conversation API's shape — for a screen of the application's
     * own, such as the SPA's `components/llm/Chat.svelte` with
     * `chatClient(api, {chat: '/admin/Ai/api/chat', asks: '/admin/Ai/api/asks', memory: '/admin/Ai/api/memory', consent: '/admin/Ai/api/consent'})`.
     *
     * Anything but GET needs the session's token in the `X-CSRF-Token` header.
     */
    public function api(): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        if ($method !== 'GET' && !$this->apiTokenGiven()) {
            return $this->analystJson(['status' => 403, 'error' => 'The session\'s token is missing; send it in X-CSRF-Token.']);
        }

        return $this->analystJson($this->analystDesk()->api($method, $this->apiPath(), $this->apiInput()));
    }

    /**
     * A CSV the analyst wrote — `export/<name>` — to the administrator it was written for, and
     * to nobody else: a link passed on opens nothing.
     */
    public function export(mixed $name = null): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }

        $file = \Pramnos\Llm\Analyst\Exports::path((string) ($name ?: \Pramnos\Http\Request::staticGetOption()), $this->adminId());

        if ($file === null) {
            $this->addError('That file is not there any more — exports are kept ' . \Pramnos\Llm\Analyst\Exports::DAYS . ' days.');
            $this->redirect(adminUrl('Ai'));

            return null;
        }

        \Pramnos\Framework\Factory::getDocument('raw');
        $filename = \Pramnos\Llm\Analyst\Exports::filename(basename($file, '.csv'), (int) filemtime($file));
        // `?as=xlsx`: the same rows as a spreadsheet.
        if (($_GET['as'] ?? '') === 'xlsx') {
            if (!headers_sent()) {
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . preg_replace('/\.csv$/', '', $filename) . '.xlsx"');
                header('Cache-Control: private, no-store');
            }

            return \Pramnos\Export\Xlsx::fromCsv((string) file_get_contents($file));
        }
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: private, no-store');
        }

        // ponytail: read whole into memory — up to 100,000 rows; stream with readfile() if exports grow.
        return (string) file_get_contents($file);
    }

    /**
     * The request's fields: a form's, or a JSON body's — the conversation API's clients send
     * JSON, which only the API's own front controller decodes into `$_POST`.
     *
     * @return array<string, mixed>
     */
    protected function apiInput(): array
    {
        if ($_POST !== []) {
            return $_POST;
        }

        $decoded = json_decode(\Pramnos\Http\Request::rawBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * `Ai/report/{run}` — where an analyst report's notification leads: the run, opened as one of
     * this administrator's analyst conversations, the same one each time.
     */
    public function report(mixed $id = null): mixed
    {
        // Somebody below the floor the report reached: read it here, without Ask.
        if ($this->readsOnly()) {
            $tasks = new \Pramnos\Llm\Chat\Routines($this->taskConversations());
            $run   = $tasks->runById((int) ($id ?: \Pramnos\Http\Request::staticGetOption()));
            if ($run === null || $run['status'] !== 'ok' || !$tasks->policy()->canSee((int) $this->reader()->userid, $run['task'])) {
                ($this->application ?? \Pramnos\Application\Application::currentInstance())?->notFound();

                return null;
            }

            return $this->readOnlyPage((string) $run['task']['title'], [['role' => 'assistant', 'content' => (string) $run['content'], 'artifacts' => []]],
                (string) ($run['finished_at'] ?? ''));
        }
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }
        $made = $this->taskService()->continueRun($this->taskScope(), (int) ($id ?: \Pramnos\Http\Request::staticGetOption()));
        if ($made === null) {
            $this->addError('There is no such report you may read.');
        }
        $this->redirect($this->analystUrl((int) $made));

        return null;
    }

    /**
     * The "Settings" link under Ask's heading; `''` hides it — an application whose settings are
     * reached from its own menu returns that. Without a key, Ask still sends to the settings.
     */
    protected function settingsLink(): string
    {
        return adminUrl('Ai/settings');
    }

    /** The scheduled page's actions are behind the same floor as Ask. */
    protected function taskDenied(): bool
    {
        return $this->requireMinUserType($this->adminFloor());
    }

    /** Whether the application lets the analyst schedule — its assistant does not refuse. */
    protected function analystSchedules(): bool
    {
        return \Pramnos\Llm\Analyst\Analyst::assistant()->routineRefusal($this->taskScope(), false) === null;
    }

    /** The administrator signed in, as the analyst's scope. */
    protected function taskScope(): \Pramnos\Llm\Chat\Scope
    {
        return \Pramnos\Llm\Analyst\Analyst::scope($this->adminId());
    }

    /** The analyst's conversations, as this administrator. */
    protected function taskConversations(): \Pramnos\Llm\Chat\Conversations
    {
        return \Pramnos\Llm\Analyst\Analyst::conversations($this->adminId());
    }

    /** A role's skill is for the types that reach the analyst: from its floor up. */
    protected function skillRolesFloor(): int
    {
        return $this->adminFloor();
    }

    /** `Ai/skills`, or `Ai/<action>`. */
    protected function skillsUrl(string $action = ''): string
    {
        return adminUrl('Ai/' . ($action === '' ? 'skills' : $action));
    }

    /** `Ai/scheduled`, or `Ai/<action>`. */
    protected function scheduledUrl(string $action = ''): string
    {
        return adminUrl('Ai/' . ($action === '' ? 'scheduled' : $action));
    }

    /** `Ai/report/<run>`. */
    protected function taskReportUrl(int $runId): string
    {
        return adminUrl('Ai/report/' . ($runId > 0 ? $runId : ''));
    }

    /** The analyst's page. */
    protected function taskChatUrl(): string
    {
        return $this->analystUrl();
    }

    /** The area's sign-in, by way of the analyst's page. */
    protected function taskSignInUrl(): string
    {
        return $this->analystUrl();
    }

    /** The administrator's analyst. Override to charge, limit or describe it differently. */
    protected function analystDesk(): \Pramnos\Llm\Analyst\Desk
    {
        return new \Pramnos\Llm\Analyst\Desk($this->adminId());
    }

    /** The administrator signed in, or 0. */
    protected function adminId(): int
    {
        $user = \Pramnos\User\User::getCurrentUser();

        return is_object($user) ? (int) ($user->userid ?? 0) : 0;
    }

    /**
     * What follows `api/` in the address: `['chat', '5']`.
     *
     * @return list<string>
     */
    protected function apiPath(): array
    {
        $path  = (string) parse_url(\Pramnos\Http\Request::currentUri(), PHP_URL_PATH);
        $parts = array_values(array_filter(explode('/', $path), static fn (string $p): bool => $p !== ''));
        $at    = array_search('api', $parts, true);

        return $at === false ? [] : array_slice($parts, (int) $at + 1);
    }

    /** Whether a write to the API carries the session's token. */
    protected function apiTokenGiven(): bool
    {
        $session = \Pramnos\Http\Session::getInstance();

        foreach ([$_SERVER['HTTP_X_CSRF_TOKEN'] ?? '', $_POST['_csrf_token'] ?? ''] as $candidate) {
            if (is_string($candidate) && $candidate !== '' && $session->verifyCsrfToken($candidate)) {
                return true;
            }
        }

        return false;
    }

    protected function shareReturnUrl(int $conversationId): string
    {
        return $this->analystUrl($conversationId);
    }

    private function analystUrl(int $conversationId = 0, int $askId = 0): string
    {
        $query = array_filter(['c' => $conversationId, 'ask' => $askId]);

        return adminUrl('Ai') . ($query === [] ? '' : '?' . http_build_query($query));
    }

    /** JSON, with the answer's status as the HTTP status. */
    protected function analystJson(array $answer): mixed
    {
        \Pramnos\Framework\Factory::getDocument('json');
        http_response_code((int) ($answer['status'] ?? 200));

        return json_encode($answer, JSON_UNESCAPED_UNICODE);
    }

    /**
     * One call in full: the prompt, the messages, the reply, the usage and the error.
     */
    public function call(): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }

        $id  = (int) \Pramnos\Http\Request::staticGetOption();
        $row = $this->log()->find($id);

        if ($row === null) {
            $this->addError('That call is not in the log.');
            $this->redirect(adminUrl('Ai/settings'));

            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'AI call #' . $id;

        $view       = $this->getView('ai');
        $view->call = $row;

        return $view->display('call');
    }

    /** The call log. A seam for tests. */
    protected function log(): CallLog
    {
        return new CallLog();
    }

    /** A provider's connection. A seam for tests. */
    protected function connection(string $provider): Connection
    {
        return new Connection($provider);
    }

    /** @return array<string, mixed>|null OpenAI's billed costs, when an Admin key is saved */
    protected function openAiCosts(): ?array
    {
        return OpenAiCosts::configured() ? (new OpenAiCosts())->summary() : null;
    }

    /**
     * Every provider, as the screen shows it. The key itself is never part of this.
     *
     * @return list<array<string, mixed>>
     */
    protected function providers(): array
    {
        $out = [];

        foreach (Providers::all() as $id => $config) {
            $connection = $this->connection((string) $id);
            $configured = $connection->isConfigured();

            $out[] = [
                'id'          => $id,
                'label'       => (string) $config['label'],
                'configured'  => $configured,
                'api_key_url' => (string) ($config['api_key_url'] ?? ''),
                'base_url'    => (string) Settings::getSetting((string) Providers::settingKey($id, 'base_url'), ''),
                'default_url' => (string) ($config['base_url'] ?? ''),
                'model'       => $connection->model(),
                'models'      => $connection->models(),
                'temperature' => (string) Settings::getSetting((string) Providers::settingKey($id, 'temperature'), ''),
                'max_tokens'  => (string) Settings::getSetting((string) Providers::settingKey($id, 'max_tokens'), ''),
                'reasoning'   => $connection->reasoning(),
                'consent'     => !empty($config['requires_consent']),
                'place'       => (string) ($config['place'] ?? ''),
                'balance'     => $configured && $connection->supportsBalance() ? $connection->balance() : null,
            ];
        }

        return $out;
    }

    /**
     * The application's features and what each has set.
     *
     * @return list<array{name: string, label: string, values: array<string, string>}>
     */
    protected function features(): array
    {
        $out = [];

        foreach (Connection::features() as $name => $label) {
            $values = [];

            foreach (Connection::FEATURE_FIELDS as $field) {
                $values[$field] = (string) Settings::getSetting(Connection::featureSetting($name, $field), '');
            }

            $out[] = ['name' => $name, 'label' => $label, 'values' => $values];
        }

        return $out;
    }

    /** The prices as they are edited: the setting, or the seed it starts from. */
    protected function pricesJson(): string
    {
        $stored = trim((string) Settings::getSetting(Pricing::SETTING, ''));

        return $stored !== '' ? $stored : Pricing::seedJson();
    }

    /**
     * The application's sections, rendered.
     *
     * @return list<array{key: string, title: string, html: string}>
     */
    protected function sections(): array
    {
        $out = [];

        // The framework's own card first, once a scheduled task has ever run; an application's
        // sections follow it.
        $own = [...(\Pramnos\Llm\Chat\TaskCosts::byTask() === [] ? [] : [new \Pramnos\Llm\Chat\TaskCosts()]), new \Pramnos\Llm\Chat\ModelChoices()];
        foreach ([...$own, ...Llm::adminSections()] as $section) {
            try {
                // A card that only shows has no form and no Save button.
                $out[] = ['key' => $section->key(), 'title' => $section->title(), 'html' => $section->render(), 'saves' => $section instanceof \Pramnos\Llm\Admin\Section];
            } catch (\Throwable $why) {
                // One broken section must not take the screen with it.
                $out[] = ['key' => $section->key(), 'title' => $section->title(), 'saves' => false,
                    'html' => '<p>' . htmlspecialchars('This section failed: ' . $why->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>'];
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $input */
    private function saveGeneral(array $input): ?string
    {
        $provider = (string) ($input['provider'] ?? '');

        if ($provider !== '' && !Providers::isKnown($provider)) {
            return 'There is no provider called ' . $provider . '.';
        }

        $this->store(Providers::SELECTED_SETTING, $provider);
        $this->store(Providers::TIMEOUT_SETTING, (string) max(0, min(300, (int) ($input['timeout'] ?? 0))));
        $this->store(Providers::LOG_SETTING, empty($input['log']) ? '0' : '1');

        return null;
    }

    /** @param array<string, mixed> $input */
    private function saveKey(array $input): ?string
    {
        $provider = (string) ($input['provider'] ?? '');

        if (!Providers::isKnown($provider)) {
            return 'There is no provider called ' . $provider . '.';
        }

        $key = trim((string) ($input['api_key'] ?? ''));

        // Empty keeps what is stored: the screen never prints a key back.
        if (!empty($input['clear_key'])) {
            $this->store((string) Providers::settingKey($provider, 'api_key'), '');
        } elseif ($key !== '') {
            $this->store((string) Providers::settingKey($provider, 'api_key'), $this->encrypted($key));
        }

        return null;
    }

    /** @param array<string, mixed> $input */
    private function saveProvider(array $input): ?string
    {
        $provider = (string) ($input['provider'] ?? '');

        if (!Providers::isKnown($provider)) {
            return 'There is no provider called ' . $provider . '.';
        }

        $temperature = trim((string) ($input['temperature'] ?? ''));

        if ($temperature !== '' && (!is_numeric($temperature) || (float) $temperature < 0 || (float) $temperature > 2)) {
            return 'The temperature is a number from 0 to 2.';
        }

        $this->store((string) Providers::settingKey($provider, 'base_url'), trim((string) ($input['base_url'] ?? '')));
        $this->store((string) Providers::settingKey($provider, 'model'), trim((string) ($input['model'] ?? '')));
        $this->store((string) Providers::settingKey($provider, 'temperature'), $temperature);
        $this->store((string) Providers::settingKey($provider, 'max_tokens'), (int) ($input['max_tokens'] ?? 0) > 0 ? (string) (int) $input['max_tokens'] : '');
        $this->store((string) Providers::settingKey($provider, 'reasoning'), empty($input['reasoning']) ? '0' : '1');

        return null;
    }

    /** @param array<string, mixed> $input */
    private function saveFeatures(array $input): ?string
    {
        $posted = is_array($input['features'] ?? null) ? $input['features'] : [];

        foreach (array_keys(Connection::features()) as $name) {
            $values   = is_array($posted[$name] ?? null) ? $posted[$name] : [];
            $provider = trim((string) ($values['provider'] ?? ''));

            if ($provider !== '' && !Providers::isKnown($provider)) {
                return 'There is no provider called ' . $provider . '.';
            }

            foreach (Connection::FEATURE_FIELDS as $field) {
                $value = trim((string) ($values[$field] ?? ''));
                $this->store(Connection::featureSetting($name, $field), match ($field) {
                    'max_tokens'  => (int) $value > 0 ? (string) (int) $value : '',
                    'reasoning'   => in_array($value, ['0', '1'], true) ? $value : '',
                    'temperature' => is_numeric($value) ? (string) (float) $value : '',
                    default       => $value,
                });
            }
        }

        return null;
    }

    /** @param array<string, mixed> $input */
    private function savePrices(array $input): ?string
    {
        if (!empty($input['reset'])) {
            $this->store(Pricing::SETTING, '');

            return null;
        }

        $checked = Pricing::validate((string) ($input['prices'] ?? ''));

        if (!$checked['ok']) {
            return $checked['says'];
        }

        $this->store(Pricing::SETTING, $checked['json']);

        return null;
    }

    /** @param array<string, mixed> $input */
    private function saveOpenAi(array $input): ?string
    {
        $key = trim((string) ($input['admin_key'] ?? ''));

        if (!empty($input['clear_key'])) {
            $this->store(OpenAiCosts::ADMIN_KEY, '');
        } elseif ($key !== '') {
            $this->store(OpenAiCosts::ADMIN_KEY, $this->encrypted($key));
        }

        $topups = trim((string) ($input['topups'] ?? ''));
        $lines  = array_filter(preg_split('/\r\n|\r|\n/', $topups) ?: [], static fn (string $l): bool => trim($l) !== '');

        if (count(OpenAiCosts::topups($topups)) !== count($lines)) {
            return 'Each top-up is a date and an amount on its own line: 2026-10-01 50';
        }

        $this->store(OpenAiCosts::TOPUPS, $topups);
        $this->store(OpenAiCosts::PROJECT, trim((string) ($input['project'] ?? '')));

        return null;
    }

    /** @param array<string, mixed> $input */
    private function saveSection(string $key, array $input): ?string
    {
        foreach ([new \Pramnos\Llm\Chat\ModelChoices(), ...Llm::adminSections()] as $section) {
            if ($section->key() === $key && $section instanceof \Pramnos\Llm\Admin\Section) {
                return $section->save($input);
            }
        }

        return 'Nothing on this screen is called ' . $key . '.';
    }

    /** A key, encrypted when the installation can. */
    private function encrypted(string $key): string
    {
        return \Pramnos\Security\Encrypter::isAvailable() ? \Pramnos\Security\Encrypter::encrypt($key) : $key;
    }

    /** Write one setting. A seam for tests. */
    protected function store(string $name, string $value): void
    {
        Settings::setSetting($name, $value);
    }

    /** The analyst's conversations, by its name, in the scheduled page's breadcrumbs. */
    protected function taskChatName(): string
    {
        return \Pramnos\Llm\Analyst\Analyst::name();
    }
}
