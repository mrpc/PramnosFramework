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
    use \Pramnos\Llm\Chat\Web\SharesConversations;
    use \Pramnos\Llm\Chat\Web\ActsOnLists;
    use \Pramnos\Llm\Chat\Web\RatesAnswers;

    /** The administration ability that opens this screen — its menu item's id. */
    protected string $adminAbility = 'admin.ai';

    /** The usertype floor; 0 is the administration area's — {@see \Pramnos\Auth\AdminAccess::defaultUsertype()}, the `super` role unless set. */
    protected int $requiredUserType = 0;

    /** The parts of the screen a form can save. */
    public const SECTIONS = ['general', 'key', 'provider', 'features', 'prices', 'openai'];

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'all', 'settings', 'save', 'check', 'call', 'analyst', 'ask', 'asked', 'delete', 'forget', 'consent', 'api', 'export',
            'scheduled', 'schedule', 'scheduledupdate', 'scheduleddelete', 'scheduledrun', 'scheduledsubscribe', 'report',
            'share', 'unshare', 'shared', 'act', 'approve', 'reject', 'rate', 'reviews', 'reviewed', 'download', 'printable', 'pin']);
        // POST with the session's token: each of these changes what the installation spends, or spends.
        // `api` checks its own token, from the X-CSRF-Token header, since it also answers GET and DELETE.
        $this->addWriteAction(['save', 'check', 'ask', 'delete', 'forget', 'consent',
            'schedule', 'scheduledupdate', 'scheduleddelete', 'scheduledrun', 'scheduledsubscribe', 'share', 'unshare', 'act', 'approve', 'reject', 'rate', 'reviewed', 'pin']);
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
        $view->urls        = [
            'page'    => adminUrl('Ai'),
            'ask'     => adminUrl('Ai/ask'),
            'asked'   => adminUrl('Ai/asked/'),
            'consent' => adminUrl('Ai/consent'),
            'delete'  => adminUrl('Ai/delete/'),
            'forget'  => adminUrl('Ai/forget/'),
            // The analyst's scheduled tasks, where the application lets it schedule.
            'scheduled' => $this->analystSchedules() ? adminUrl('Ai/scheduled') : '',
            // Everybody's conversations, read only, for whoever reads them all.
            'all'       => $this->readsAll($this->reader()) ? adminUrl('Ai/all') : '',
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
            // The bad answers to look at, for whoever reads everybody's.
            'reviews'   => $this->readsAll($this->reader()) ? adminUrl('Ai/reviews') : '',
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
     * Every administrator's Ask conversations, read only: the list — by owner, by a word in the
     * title — and one opened as its owner sees it, under a banner saying whose it is. Nothing is
     * written but the audit log's line for a view of somebody else's. A 404 to whoever does not
     * read them all.
     */
    public function all(): mixed
    {
        $me = $this->reader();
        if (!$this->readsAll($me)) {
            ($this->application ?? \Pramnos\Application\Application::currentInstance())?->notFound();

            return null;
        }
        $request = new \Pramnos\Http\Request();
        $filters = ['person' => (int) $request->get('person', 0, 'get', 'int'), 'q' => (string) $request->get('q', '', 'get')];
        $openId  = (int) $request->get('c', 0, 'get', 'int');
        $open    = $openId > 0 ? \Pramnos\Llm\Analyst\Review::open($openId) : null;
        if ($openId > 0 && $open === null) {
            ($this->application ?? \Pramnos\Application\Application::currentInstance())?->notFound();

            return null;
        }
        if ($open !== null && $open['owner'] === (int) $me->userid) {
            // One's own opens in Ask, where it is not read only.
            $this->redirect(adminUrl('Ai') . '?c=' . $openId);

            return null;
        }
        if ($open !== null) {
            \Pramnos\Auth\AuditLog::record('ai.conversation_viewed', (int) $me->userid, $open['owner'], ['title' => $open['conversation']['title']],
                'chat_conversation', $openId);
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = t('Other people\'s Ask conversations');

        $view          = $this->getView('ai');
        $view->list    = \Pramnos\Llm\Analyst\Review::list($filters + ['except' => (int) $me->userid]);
        $view->owners  = \Pramnos\Llm\Analyst\Review::owners((int) $me->userid);
        $view->filters = $filters;
        $view->open    = $open;
        $view->me      = (int) $me->userid;
        $view->urls    = ['all' => adminUrl('Ai/all'), 'call' => adminUrl('Ai/call/'), 'ask' => adminUrl('Ai')];

        return $view->display('review');
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

    /** Who is signed in, or null. A seam for tests. */
    protected function reader(): ?object
    {
        $user = \Pramnos\User\User::getCurrentUser();

        return is_object($user) ? $user : null;
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
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . \Pramnos\Llm\Analyst\Exports::filename(basename($file, '.csv'), (int) filemtime($file)) . '"');
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
        $own = \Pramnos\Llm\Chat\TaskCosts::byTask() === [] ? [] : [new \Pramnos\Llm\Chat\TaskCosts()];
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
        $lines  = array_filter(preg_split('/\R/', $topups) ?: [], static fn (string $l): bool => trim($l) !== '');

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
        foreach (Llm::adminSections() as $section) {
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
}
