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
 * `/admin/Ai` — every language-model setting of the installation, in one place.
 *
 * Providers and their keys, the provider each of the application's features uses, the unit
 * prices calls are costed at, what was spent and what failed — and the application's own
 * sections beside them (`'llm' => ['admin_sections' => [...]]`, see `Pramnos\Llm\Admin\Section`).
 * One screen, so there is never a second copy of a setting somewhere else that disagrees.
 *
 * ### The analyst
 *
 * `/admin/Ai/analyst` asks the installation's own figures — accounts, email, the queue, logs,
 * model spend — through `Pramnos\Llm\Analyst`, behind the same usertype as the rest of this
 * screen. An administrator's analyst conversations are their own and never in their Ask AI.
 *
 * ### Keys are never printed back
 *
 * A key field renders empty with a placeholder saying whether one is saved; submitting it empty
 * keeps what is stored, and clearing it is its own checkbox. A key on a screen is a key in a
 * screenshot.
 */
class AiController extends Controller
{
    /** The administration ability that opens this screen — its menu item's id. */
    protected string $adminAbility = 'admin.ai';

    /** Minimum usertype: these settings spend the installation's money. */
    protected int $requiredUserType = 90;

    /** The parts of the screen a form can save. */
    public const SECTIONS = ['general', 'key', 'provider', 'features', 'prices', 'openai'];

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'save', 'check', 'call', 'analyst', 'analystAsk', 'analystAsked', 'analystDelete', 'analystForget', 'analystConsent']);
        // POST with the session's token: each of these changes what the installation spends, or spends.
        $this->addWriteAction(['save', 'check', 'analystAsk', 'analystDelete', 'analystForget', 'analystConsent']);
        parent::__construct($application);
    }

    /**
     * The screen.
     */
    public function display(): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'AI';

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
        if ($this->requireMinUserType($this->requiredUserType)) {
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
        $this->redirect(adminUrl('Ai'));
    }

    /**
     * Ask a provider whether its key works.
     */
    public function check(): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $provider = (string) ($_POST['provider'] ?? '');

        if (!Providers::isKnown($provider)) {
            $this->addError('There is no provider called ' . $provider . '.');
        } else {
            $result = $this->connection($provider)->check();
            $result['ok'] ? $this->addMessage($result['says']) : $this->addError($result['says']);
        }

        $this->redirect(adminUrl('Ai'));
    }

    /**
     * The analyst: questions about the installation, answered from its own figures.
     */
    public function analyst(): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $request = new \Pramnos\Http\Request();
        $page    = $this->analystDesk()->page((int) $request->get('c', 0, 'get', 'int'), (int) $request->get('ask', 0, 'get', 'int'));

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Analyst';

        $view          = $this->getView('ai');
        $view->heading = 'Analyst';
        $view->urls    = [
            'page'    => adminUrl('Ai/analyst'),
            'ask'     => adminUrl('Ai/analystAsk'),
            'asked'   => adminUrl('Ai/analystAsked/'),
            'consent' => adminUrl('Ai/analystConsent'),
            'delete'  => adminUrl('Ai/analystDelete/'),
            'forget'  => adminUrl('Ai/analystForget/'),
        ];
        foreach ($page as $name => $value) {
            $view->{$name} = $value;
        }

        return $view->display('analyst');
    }

    /**
     * Ask the analyst. A redirect to the page following the question — or JSON, for
     * `pf-chat.js`, which sends `Accept: application/json`.
     */
    public function analystAsk(): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
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
    public function analystAsked(mixed $id = null): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $found = $this->analystDesk()->asked((int) ($id ?: \Pramnos\Http\Request::staticGetOption()));

        return $this->analystJson($found === null ? ['status' => 404, 'error' => 'There is no such question.'] : ['status' => 200, 'data' => $found]);
    }

    /** Forget one analyst conversation. */
    public function analystDelete(mixed $id = null): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $this->analystDesk()->forget((int) ($id ?: \Pramnos\Http\Request::staticGetOption()))
            ? $this->addMessage('The conversation is forgotten.')
            : $this->addError('There is no such conversation.');
        $this->redirect($this->analystUrl());

        return null;
    }

    /** Forget one thing the analyst remembers. */
    public function analystForget(mixed $id = null): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $this->analystDesk()->forgetMemory((int) ($id ?: \Pramnos\Http\Request::staticGetOption()))
            ? $this->addMessage('Forgotten.')
            : $this->addError('There is no such memory.');
        $this->redirect($this->analystUrl((int) ($_POST['conversation_id'] ?? 0)));

        return null;
    }

    /** Agree to the analyst's provider, or withdraw — `give` in the form. */
    public function analystConsent(): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $this->analystDesk()->consent(!empty($_POST['give']));
        $this->redirect($this->analystUrl((int) ($_POST['conversation_id'] ?? 0)));

        return null;
    }

    /** The administrator's analyst. Override to charge, limit or describe it differently. */
    protected function analystDesk(): \Pramnos\Llm\Analyst\Desk
    {
        $user = \Pramnos\User\User::getCurrentUser();

        return new \Pramnos\Llm\Analyst\Desk(is_object($user) ? (int) ($user->userid ?? 0) : 0);
    }

    private function analystUrl(int $conversationId = 0, int $askId = 0): string
    {
        $query = array_filter(['c' => $conversationId, 'ask' => $askId]);

        return adminUrl('Ai/analyst') . ($query === [] ? '' : '?' . http_build_query($query));
    }

    /** JSON for `pf-chat.js`, with the answer's status as the HTTP status. */
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
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $id  = (int) \Pramnos\Http\Request::staticGetOption();
        $row = $this->log()->find($id);

        if ($row === null) {
            $this->addError('That call is not in the log.');
            $this->redirect(adminUrl('Ai'));

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

        foreach (Llm::adminSections() as $section) {
            try {
                $out[] = ['key' => $section->key(), 'title' => $section->title(), 'html' => $section->render()];
            } catch (\Throwable $why) {
                // One broken section must not take the screen with it.
                $out[] = ['key' => $section->key(), 'title' => $section->title(),
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
            if ($section->key() === $key) {
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
