<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat\Api;

use Pramnos\Llm\Chat\Routines;
use Pramnos\Llm\Connection;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Llm;
use Pramnos\Llm\Policy\StoredConsent;
use Pramnos\Llm\Providers;

/**
 * The JSON API over conversations, queued questions, memory, schedules and consent.
 *
 * Mounted with one line — {@see Routes::register()} — and extended by subclassing: every
 * decision an application makes for itself is a protected method here.
 *
 * | hook | |
 * |---|---|
 * | `refusal($action)` | called first by every action; a response to stop it — authentication, a plan |
 * | `beforeAsk($scope, $input)` | after the consent check: a limit, a charge |
 * | `storePicture($path, $filename)` | keep an attached picture and answer its address; none by default |
 * | `extra($input)` | what travels with the question to the assistant |
 * | `limits($scope)` | the limits a screen shows, decorated |
 * | `routinesAnswer($scope, $answer)` | the scheduled-questions list, decorated — a plan's limit |
 * | `scope()`, `conversations()`, `asks()`, `routineService()` | where everything comes from |
 *
 * Shared reports: `GET routines/{id}/runs`, `GET runs/{id}`, `POST runs/{id}/continue` (a
 * conversation of the reader's own, seeded with the run), `GET reports`, and
 * `POST`/`DELETE routines/{id}/subscribe` — each checked by {@see \Pramnos\Llm\Chat\TaskPolicy::canSee()}.
 *
 * Answers are `['status' => …, 'data' => …]` or `['status' => …, 'error' => …]`.
 */
class ChatController extends \Pramnos\Application\ApiCrudController
{
    use \Pramnos\Llm\Chat\Concerns\TakesQuestions;

    /**
     * Guarded as `chat`: talking to the model is what this does.
     */
    protected string $resource = 'chat';

    /** This person's conversations, and what a conversation here may do. */
    public function list(): array
    {
        if (($stop = $this->refusal('list')) !== null) {
            return $stop;
        }

        $scope = $this->scope();

        return ['status' => 200, 'data' => [
            'conversations' => $this->conversations()->list(),
            'limits'        => $this->limits($scope),
        ]];
    }

    /** One conversation, every message in it. */
    public function read($conversation_id): array
    {
        if (($stop = $this->refusal('read')) !== null) {
            return $stop;
        }

        $found = $this->conversations()->find((int) $conversation_id);

        return $found === null ? self::error(404, 'There is no such conversation.') : ['status' => 200, 'data' => $found];
    }

    /** Forget a conversation. */
    public function forget($conversation_id): array
    {
        if (($stop = $this->refusal('delete')) !== null) {
            return $stop;
        }

        return $this->conversations()->delete((int) $conversation_id)
            ? ['status' => 200, 'data' => ['deleted' => true]]
            : self::error(404, 'There is no such conversation.');
    }

    /**
     * Ask, starting or continuing a conversation.
     *
     * Queued by default: an answer with tools takes longer than a web server waits, and holds a
     * worker every other screen needs. 202 with `ask_id`, which `asked()` follows. An application
     * with no queue worker sets `'llm' => ['chat' => ['answer_in_request' => true]]` and gets the
     * answer itself, 200.
     */
    public function ask(): array
    {
        if (($stop = $this->refusal('ask')) !== null) {
            return $stop;
        }

        $input = $this->input();

        return $this->takeQuestion($input, \Pramnos\Llm\Chat\Attachments::fromJson($input));
    }

    /** A question while the worker answers it: waiting, answered with its conversation, or failed and why. */
    public function asked($ask_id): array
    {
        if (($stop = $this->refusal('read')) !== null) {
            return $stop;
        }

        $scope = $this->scope();
        $found = $scope === null ? null : $this->asks()->find($scope, (int) $ask_id);

        return $found === null ? self::error(404, 'There is no such question.') : ['status' => 200, 'data' => $found];
    }

    /** What the model remembers about the person asking. */
    public function memory(): array
    {
        if (($stop = $this->refusal('list')) !== null) {
            return $stop;
        }

        return ['status' => 200, 'data' => ['memories' => $this->conversations()->memory()?->all() ?? []]];
    }

    /** Forget one memory. */
    public function forgetMemory($memory_id): array
    {
        if (($stop = $this->refusal('delete')) !== null) {
            return $stop;
        }

        return $this->conversations()->memory()?->forget((int) $memory_id)
            ? ['status' => 200, 'data' => ['deleted' => true]]
            : self::error(404, 'There is no such memory.');
    }

    /** This person's scheduled questions, and the templates on offer. */
    public function routines(): array
    {
        if (($stop = $this->refusal('routines')) !== null) {
            return $stop;
        }

        $scope = $this->scope();

        $policy = $this->routineService()->policy();

        return ['status' => 200, 'data' => $this->routinesAnswer($scope, [
            'routines'  => $scope === null ? [] : $this->routineService()->mine($scope),
            'templates' => Routines::templates(),
            'cadences'  => Routines::CADENCES,
            'kinds'     => Routines::KINDS,
            // Whose a task may be, as the policy lets this person: a screen offers only these.
            'owners'    => $scope === null ? [] : array_values(array_filter(Routines::OWNERS, static fn (string $owner): bool => $policy->canManage($scope, $owner))),
        ])];
    }

    /** Schedule a question. */
    public function createRoutine(): array
    {
        if (($stop = $this->refusal('routines')) !== null) {
            return $stop;
        }

        $scope = $this->scope();

        if ($scope === null) {
            return self::error(403, 'This needs somebody signed in.');
        }

        $input = $this->input();
        $made  = $this->routineService()->create($scope, $input + ['timezone' => $this->conversations()->assistant()->timezone($scope)]);

        return is_string($made) ? self::error(422, $made) : ['status' => 201, 'data' => $made];
    }

    /**
     * Change one: `active` to pause or resume it, and any of `title`, `prompt`, `cadence`, `at`,
     * `day`, `nth`, `run_at`, `in_minutes` — what is left out stays.
     */
    public function updateRoutine($routine_id): array
    {
        if (($stop = $this->refusal('routines')) !== null) {
            return $stop;
        }

        $scope = $this->scope();
        $found = $scope === null ? null : $this->routineService()->find($scope, (int) $routine_id);

        if ($found === null) {
            return self::error(404, 'There is no such scheduled task.');
        }

        $input   = $this->input();
        $changes = array_intersect_key($input, array_flip(['title', 'prompt', 'cadence', 'at', 'day', 'nth', 'run_at', 'in_minutes']));

        if (array_key_exists('active', $input) && $changes === []) {
            // Only pausing or resuming: as before, a refusal to resume is a conflict.
            if (!$this->routineService()->setActive($scope, (int) $routine_id, filter_var($input['active'], FILTER_VALIDATE_BOOLEAN))) {
                return self::error(409, (string) ($this->conversations()->assistant()->routineRefusal($scope, true) ?? 'It could not be resumed.'));
            }

            return ['status' => 200, 'data' => $this->routineService()->find($scope, (int) $routine_id)];
        }

        if (array_key_exists('active', $input)) {
            $changes['active'] = filter_var($input['active'], FILTER_VALIDATE_BOOLEAN);
        }
        $changed = $changes === [] ? $found : $this->routineService()->update($scope, (int) $routine_id, $changes + ['timezone' => $found['timezone']]);

        return is_string($changed) ? self::error(422, $changed) : ['status' => 200, 'data' => $changed];
    }

    /** Delete one. */
    public function deleteRoutine($routine_id): array
    {
        if (($stop = $this->refusal('routines')) !== null) {
            return $stop;
        }

        $scope = $this->scope();

        return $scope !== null && $this->routineService()->delete($scope, (int) $routine_id)
            ? ['status' => 200, 'data' => ['deleted' => true]]
            : self::error(404, 'There is no such scheduled task.');
    }

    /** Run one now, whatever its schedule. */
    public function runRoutine($routine_id): array
    {
        if (($stop = $this->refusal('routines')) !== null) {
            return $stop;
        }

        $scope = $this->scope();
        $found = $scope === null ? null : $this->routineService()->find($scope, (int) $routine_id);

        if ($found === null) {
            return self::error(404, 'There is no such scheduled task.');
        }

        $ran = $this->routineService()->run($found, time());

        return $ran['ok'] ? ['status' => 200, 'data' => $ran] : ['status' => 409, 'error' => $ran['says'], 'data' => $ran];
    }

    /** A task's runs, the newest first — one of this person's, or a shared report they may read. */
    public function routineRuns($routine_id): array
    {
        if (($stop = $this->refusal('routines')) !== null) {
            return $stop;
        }

        $task = $this->readable((int) $routine_id);

        return $task === null
            ? self::error(404, 'There is no such scheduled task.')
            : ['status' => 200, 'data' => ['task' => $task, 'runs' => $this->routineService()->runs((int) $routine_id, min(100, max(1, (int) ($_GET['limit'] ?? 20))))]];
    }

    /** One run, with its task — for a reader of the report it belongs to. */
    public function readRun($run_id): array
    {
        if (($stop = $this->refusal('routines')) !== null) {
            return $stop;
        }

        $run = $this->routineService()->runById((int) $run_id);

        return $run === null || $this->readable($run['task']['routine_id']) === null
            ? self::error(404, 'There is no such run.')
            : ['status' => 200, 'data' => $run];
    }

    /** Open a run as a conversation of this person's own, to ask about it: `conversation_id`. */
    public function continueRun($run_id): array
    {
        if (($stop = $this->refusal('ask')) !== null) {
            return $stop;
        }

        $scope = $this->scope();
        $id    = $scope === null ? null : $this->routineService()->continueRun($scope, (int) $run_id);

        return $id === null ? self::error(404, 'There is no such run.') : ['status' => 200, 'data' => ['conversation_id' => $id]];
    }

    /** The shared reports this person may receive here, and whether they do. */
    public function reports(): array
    {
        if (($stop = $this->refusal('routines')) !== null) {
            return $stop;
        }

        $scope = $this->scope();

        return ['status' => 200, 'data' => ['reports' => $scope === null ? [] : $this->routineService()->reports($scope)]];
    }

    /** Receive a shared report — `email` too, if the application sends it. */
    public function subscribe($routine_id): array
    {
        if (($stop = $this->refusal('routines')) !== null) {
            return $stop;
        }

        $scope = $this->scope();
        $why   = $scope === null ? 'This needs somebody signed in.'
            : $this->routineService()->subscribe($scope->userId, (int) $routine_id, filter_var($this->input()['email'] ?? false, FILTER_VALIDATE_BOOLEAN));

        return $why === null ? ['status' => 200, 'data' => ['subscribed' => true]] : self::error(404, $why);
    }

    /** Stop receiving a shared report. */
    public function unsubscribe($routine_id): array
    {
        if (($stop = $this->refusal('routines')) !== null) {
            return $stop;
        }

        $scope = $this->scope();

        return $scope !== null && $this->routineService()->unsubscribe($scope->userId, (int) $routine_id)
            ? ['status' => 200, 'data' => ['subscribed' => false]]
            : self::error(404, 'You do not receive it.');
    }

    /**
     * A task this person may read: theirs here, or a shared one the policy lets them see.
     *
     * @return array<string, mixed>|null
     */
    private function readable(int $routineId): ?array
    {
        $scope = $this->scope();
        if ($scope === null) {
            return null;
        }
        $service = $this->routineService();
        $task    = $service->find($scope, $routineId) ?? $service->byId($routineId);

        return $task !== null && $service->policy()->canSee($scope->userId, $task) ? $task : null;
    }

    /**
     * Whether the provider a feature uses asks for agreement, and whether this person gave it —
     * `feature` in the query, `chat` by default: each feature can go to its own provider.
     */
    public function consent(): array
    {
        if (($stop = $this->refusal('consent')) !== null) {
            return $stop;
        }

        $scope    = $this->scope();
        $feature  = $this->feature();
        $provider = $this->provider($scope, $feature);

        if ($provider === null) {
            return self::error(422, 'There is no such feature.');
        }

        $config = Providers::config($provider);

        return ['status' => 200, 'data' => [
            'feature'  => $feature,
            'provider' => $provider,
            'label'    => (string) $config['label'],
            'place'    => (string) ($config['place'] ?? ''),
            'required' => StoredConsent::required($provider),
            'given'    => $scope !== null && Llm::consent()->refusal($scope->context(), $provider) === null,
        ]];
    }

    /** Agree, for the provider in use. */
    public function giveConsent(): array
    {
        return $this->changeConsent(true);
    }

    /** Withdraw agreement. */
    public function withdrawConsent(): array
    {
        return $this->changeConsent(false);
    }

    // ── Hooks ────────────────────────────────────────────────────────────────

    /**
     * Whether this action may go ahead; a response to stop it. Signed in, and allowed by the
     * permission store on the `chat` resource, by default.
     */
    protected function refusal(string $action): ?array
    {
        return $this->guard(in_array($action, ['ask', 'routines', 'consent'], true) ? 'create' : ($action === 'delete' ? 'delete' : 'list'));
    }

    /** @return array<string, mixed> The limits a screen shows */
    protected function limits(?Scope $scope): array
    {
        return $this->conversations()->limits();
    }

    protected function routineService(): Routines
    {
        return new Routines($this->conversations());
    }

    /**
     * The scheduled-questions answer, decorated — the plan's limit on active ones, say.
     *
     * @param array{routines: list<array<string, mixed>>, templates: array<string, mixed>, cadences: list<string>, kinds: list<string>, owners: list<string>} $answer
     * @return array<string, mixed>
     */
    protected function routinesAnswer(?Scope $scope, array $answer): array
    {
        return $answer;
    }

    /** @return array<string, mixed> The request's fields */
    protected function input(): array
    {
        return (new \Pramnos\Http\Request())->body();
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function changeConsent(bool $give): array
    {
        if (($stop = $this->refusal('consent')) !== null) {
            return $stop;
        }

        $scope = $this->scope();

        if ($scope === null) {
            return self::error(403, 'This needs somebody signed in.');
        }

        $provider = $this->provider($scope, $this->feature());

        if ($provider === null) {
            return self::error(422, 'There is no such feature.');
        }

        $store = new StoredConsent();
        $give ? $store->give($scope->organizationId, $scope->userId, $provider) : $store->withdraw($scope->organizationId, $scope->userId, $provider);

        return $this->consent();
    }

    /** The feature agreement is asked about: `feature` in the query or body, `chat` by default. */
    private function feature(): string
    {
        $feature = trim((string) ($_GET['feature'] ?? $this->input()['feature'] ?? ''));

        return $feature === '' ? 'chat' : $feature;
    }

    /**
     * The provider a feature's calls go to, as the router sends them for this person — null for
     * a feature the application does not have.
     */
    private function provider(?Scope $scope, string $feature): ?string
    {
        if ($feature === 'chat') {
            return $this->conversations()->connection()->provider();
        }

        if (!isset(Connection::features()[$feature])) {
            return null;
        }

        $router = Llm::router();
        $scope ??= new Scope(0);

        return $router->route($router->context(['purpose' => $feature] + $scope->context()), new Connection(null, $feature))->provider();
    }

    /** @return array{status: int, error: string} */
    private static function error(int $status, string $error): array
    {
        return ['status' => $status, 'error' => $error];
    }
}
