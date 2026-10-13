<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat\Api;

use Pramnos\Llm\Chat\Routines;
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
 * | `scope()`, `conversations()`, `asks()`, `routineService()` | where everything comes from |
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
        $sent  = is_array($input['attachments'] ?? null) ? array_values($input['attachments']) : [$input['attachment'] ?? null];
        $files = [];

        foreach ($sent as $file) {
            if (is_array($file) && (string) ($file['data'] ?? '') !== '') {
                $files[] = ['name' => (string) ($file['name'] ?? 'document'), 'bytes' => (string) base64_decode((string) $file['data'], true)];
            }
        }

        return $this->takeQuestion($input, $files);
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

        return ['status' => 200, 'data' => [
            'routines'  => $scope === null ? [] : $this->routineService()->mine($scope),
            'templates' => Routines::templates(),
            'cadences'  => Routines::CADENCES,
        ]];
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

    /** Pause or resume one; `active` in the body. */
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

        $input = $this->input();

        if (array_key_exists('active', $input) && !$this->routineService()->setActive($scope, (int) $routine_id, filter_var($input['active'], FILTER_VALIDATE_BOOLEAN))) {
            return self::error(409, (string) ($this->conversations()->assistant()->routineRefusal($scope, true) ?? 'It could not be resumed.'));
        }

        return ['status' => 200, 'data' => $this->routineService()->find($scope, (int) $routine_id)];
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

    /** Whether the provider in use asks for agreement, and whether this person gave it. */
    public function consent(): array
    {
        if (($stop = $this->refusal('consent')) !== null) {
            return $stop;
        }

        $scope    = $this->scope();
        $provider = $this->provider($scope);
        $config   = Providers::config($provider);

        return ['status' => 200, 'data' => [
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

        $store    = new StoredConsent();
        $provider = $this->provider($scope);
        $give ? $store->give($scope->organizationId, $scope->userId, $provider) : $store->withdraw($scope->organizationId, $scope->userId, $provider);

        return $this->consent();
    }

    /** The provider a conversation here goes to. */
    private function provider(?Scope $scope): string
    {
        $assistant = $this->conversations()->assistant();

        return $assistant->connection($scope ?? new Scope(0))->provider();
    }

    /** @return array{status: int, error: string} */
    private static function error(int $status, string $error): array
    {
        return ['status' => $status, 'error' => $error];
    }
}
