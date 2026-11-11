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
 * `POST routines/{id}/report` sends one of the person's own tasks as a metrics report from now on.
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

    /** This person's artifacts — the tables, lists and texts kept from their conversations — without their content. */
    public function artifacts(): array
    {
        if (($stop = $this->refusal('list')) !== null) {
            return $stop;
        }
        $scope = $this->scope();

        return ['status' => 200, 'data' => ['artifacts' => $scope === null ? [] : (new \Pramnos\Llm\Chat\Artifacts($scope))->list()]];
    }

    /** One artifact, with its content. */
    public function readArtifact($artifact_id): array
    {
        if (($stop = $this->refusal('read')) !== null) {
            return $stop;
        }
        $scope = $this->scope();
        $found = $scope === null ? null : (new \Pramnos\Llm\Chat\Artifacts($scope))->find((int) $artifact_id);

        return $found === null ? self::error(404, 'There is no such artifact.') : ['status' => 200, 'data' => $found];
    }

    /** Remove one of this person's artifacts. */
    public function deleteArtifact($artifact_id): array
    {
        if (($stop = $this->refusal('delete')) !== null) {
            return $stop;
        }
        $scope = $this->scope();

        return $scope !== null && (new \Pramnos\Llm\Chat\Artifacts($scope))->delete((int) $artifact_id)
            ? ['status' => 200, 'data' => ['deleted' => true]]
            : self::error(404, 'There is no such artifact.');
    }

    /** Share one of this person's conversations, as it stands, with `names` (usernames or emails). */
    public function shareConversation($conversation_id): array
    {
        return $this->shareOne(\Pramnos\Llm\Chat\Shares::CONVERSATION, (int) $conversation_id);
    }

    /** Share one of this person's artifacts with `names`. */
    public function shareArtifact($artifact_id): array
    {
        return $this->shareOne(\Pramnos\Llm\Chat\Shares::ARTIFACT, (int) $artifact_id);
    }

    /** Who one of this person's conversations is shared with. */
    public function conversationShares($conversation_id): array
    {
        return $this->sharesOfOne(\Pramnos\Llm\Chat\Shares::CONVERSATION, (int) $conversation_id);
    }

    /** Who one of this person's artifacts is shared with. */
    public function artifactShares($artifact_id): array
    {
        return $this->sharesOfOne(\Pramnos\Llm\Chat\Shares::ARTIFACT, (int) $artifact_id);
    }

    /** Stop one of this person's shares. */
    public function revokeShare($share_id): array
    {
        if (($stop = $this->refusal('delete')) !== null) {
            return $stop;
        }
        $scope = $this->scope();

        return $scope !== null && (new \Pramnos\Llm\Chat\Shares())->revoke($scope, (int) $share_id)
            ? ['status' => 200, 'data' => ['deleted' => true]]
            : self::error(404, 'There is no such share.');
    }

    /** What others shared with this person. */
    public function sharedWithMe(): array
    {
        if (($stop = $this->refusal('list')) !== null) {
            return $stop;
        }
        $scope = $this->scope();

        return ['status' => 200, 'data' => ['shared' => $scope === null ? [] : (new \Pramnos\Llm\Chat\Shares())->withMe($scope->userId)]];
    }

    /** Who matches `?q=`, to share with or to read a report: `SharePolicy::candidates()`. */
    public function people(): array
    {
        if (($stop = $this->refusal('list')) !== null) {
            return $stop;
        }
        $scope = $this->scope();

        return ['status' => 200, 'data' => ['people' => $scope === null ? [] : \Pramnos\Llm\Chat\SharePolicy::current()->candidates($scope, (string) ($_GET['q'] ?? ''))]];
    }

    /** Open something shared with this person: the conversation of their own that holds it. */
    public function openShared($share_id): array
    {
        if (($stop = $this->refusal('read')) !== null) {
            return $stop;
        }
        $made = (new \Pramnos\Llm\Chat\Shares())->open((int) $share_id, $this->conversations());

        return $made === null ? self::error(404, 'There is nothing shared with you here.') : ['status' => 200, 'data' => ['conversation_id' => $made]];
    }

    /** The bulk actions a list artifact offers this person, for its kind of record. */
    public function artifactActions($artifact_id): array
    {
        if (($stop = $this->refusal('read')) !== null) {
            return $stop;
        }
        $scope = $this->scope();
        $found = $scope === null ? null : (new \Pramnos\Llm\Chat\Artifacts($scope))->find((int) $artifact_id);
        if ($found === null || $found['kind'] !== 'list') {
            return self::error(404, 'There is no such list.');
        }

        return ['status' => 200, 'data' => ['actions' => \Pramnos\Bulk\BulkActions::available((string) $found['entity'], $scope->userId)]];
    }

    /** Run a bulk action on a list artifact's records, with `params`: done now, or queued for a long list. */
    public function runArtifactAction($artifact_id, $action): array
    {
        if (($stop = $this->refusal('share')) !== null) {
            return $stop;
        }
        $scope = $this->scope();
        $found = $scope === null ? null : (new \Pramnos\Llm\Chat\Artifacts($scope))->find((int) $artifact_id);
        if ($found === null || $found['kind'] !== 'list') {
            return self::error(404, 'There is no such list.');
        }
        $params = $this->input()['params'] ?? [];
        $done   = \Pramnos\Bulk\BulkActions::run((string) $found['entity'], (string) $action, (array) ($found['content']['ids'] ?? []), is_array($params) ? $params : [], $scope->userId);

        return is_string($done) ? self::error(422, $done) : ['status' => 200, 'data' => $done];
    }

    /** Approve a change the model proposed: it runs now, as this person. */
    public function approveProposal($artifact_id): array
    {
        if (($stop = $this->refusal('share')) !== null) {
            return $stop;
        }
        $scope = $this->scope();
        $done  = $scope === null ? 'There is no such proposal.' : (new \Pramnos\Llm\Chat\Proposals($scope))->approve((int) $artifact_id);

        return is_string($done) ? self::error(str_starts_with($done, 'There is no such') ? 404 : 422, $done) : ['status' => 200, 'data' => $done];
    }

    /** Reject a change the model proposed: nothing changes. */
    public function rejectProposal($artifact_id): array
    {
        if (($stop = $this->refusal('share')) !== null) {
            return $stop;
        }
        $scope = $this->scope();
        $why   = $scope === null ? 'There is no such proposal.' : (new \Pramnos\Llm\Chat\Proposals($scope))->reject((int) $artifact_id);

        return $why === null ? ['status' => 200, 'data' => ['rejected' => true]] : self::error(str_starts_with($why, 'There is no such') ? 404 : 422, $why);
    }

    /** Rate an answer in one of this person's conversations: `rating` 1, -1, or 0 to take it back; `note`. */
    public function rate($message_id): array
    {
        if (($stop = $this->refusal('share')) !== null) {
            return $stop;
        }
        $scope = $this->scope();
        $input = $this->input();
        $why   = $scope === null ? 'There is no such answer.'
            : (new \Pramnos\Llm\Chat\Ratings())->rate($scope, (int) $message_id, (int) ($input['rating'] ?? 0), (string) ($input['note'] ?? ''));

        return $why === null ? ['status' => 200, 'data' => ['rated' => true]] : self::error(str_starts_with($why, 'There is no such') ? 404 : 422, $why);
    }

    /** An artifact's rows as CSV — `filename` and `csv` — for a screen to save. */
    public function artifactCsv($artifact_id): array
    {
        if (($stop = $this->refusal('read')) !== null) {
            return $stop;
        }
        $scope = $this->scope();
        $found = $scope === null ? null : (new \Pramnos\Llm\Chat\Artifacts($scope))->find((int) $artifact_id);
        $csv   = $found === null ? null : \Pramnos\Llm\Chat\Artifacts::csv($found);

        return $csv === null ? self::error(404, 'There is nothing to download here.')
            : ['status' => 200, 'data' => ['filename' => \Pramnos\Llm\Chat\Artifacts::filename($found, 'csv'), 'csv' => $csv]];
    }

    /** Pin an artifact to this person's dashboard, or take it off with `pinned: false`. */
    public function pinArtifact($artifact_id): array
    {
        if (($stop = $this->refusal('share')) !== null) {
            return $stop;
        }
        $scope = $this->scope();
        $on    = filter_var($this->input()['pinned'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $why   = $scope === null ? 'There is no such artifact.' : (new \Pramnos\Llm\Chat\Artifacts($scope))->pin((int) $artifact_id, $on);

        return $why === null ? ['status' => 200, 'data' => ['pinned' => $on]] : self::error(str_starts_with($why, 'There is no such') ? 404 : 422, $why);
    }

    /** @return array<string, mixed> */
    private function shareOne(string $kind, int $targetId): array
    {
        if (($stop = $this->refusal('share')) !== null) {
            return $stop;
        }
        $scope = $this->scope();
        $names = $this->input()['names'] ?? [];
        $names = is_array($names) ? array_map('strval', $names) : (preg_split('/[\s,;]+/', (string) $names, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $done  = $scope === null ? 'This needs somebody signed in.' : (new \Pramnos\Llm\Chat\Shares())->share($scope, $kind, $targetId, $names);

        if (is_string($done)) {
            return self::error(str_starts_with($done, 'There is no such') ? 404 : 422, $done);
        }

        return ['status' => 200, 'data' => $done];
    }

    /** @return array<string, mixed> */
    private function sharesOfOne(string $kind, int $targetId): array
    {
        if (($stop = $this->refusal('read')) !== null) {
            return $stop;
        }
        $scope = $this->scope();

        return ['status' => 200, 'data' => ['shares' => $scope === null ? [] : (new \Pramnos\Llm\Chat\Shares())->sharesOf($scope, $kind, $targetId)]];
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

    /**
     * Read a text aloud — an answer, where the browser has no voice in its language — with the
     * application's speech server ({@see \Pramnos\Llm\Speech\Speech}).
     *
     * `{"text": "…"}` → `audio` (base64), its `type` (as the server says: `audio/wav`, `audio/mpeg`…)
     * and `bytes`; with `"raw": true`, the audio itself, a third smaller. 501 where no speech server
     * is set up, 422 for an empty or overlong text, 502 when the server fails — JSON either way.
     * It reads in the person's own voice for this persona ({@see voices()}), else the persona's;
     * `"voice": key` reads in one of the list, for a sample.
     */
    public function speak(): array|\Pramnos\Http\Response
    {
        if (($stop = $this->refusal('ask')) !== null) {
            return $stop;
        }
        $input  = $this->input();
        $spoken = \Pramnos\Llm\Speech\Speech::speak((string) ($input['text'] ?? ''), null, $this->voiceFor((string) ($input['voice'] ?? '')));
        if (!$spoken['ok']) {
            return self::error($spoken['status'], $spoken['says']);
        }

        return filter_var($input['raw'] ?? false, FILTER_VALIDATE_BOOLEAN)
            ? \Pramnos\Http\Response::make($spoken['audio'])->withHeader('Content-Type', $spoken['type'])
            : ['status' => 200, 'data' => ['audio' => base64_encode($spoken['audio']), 'type' => $spoken['type'], 'bytes' => strlen($spoken['audio'])]];
    }

    /**
     * The voice a text is read in: the one asked for, where it is one of the list (a sample); the
     * person's own for this persona; the persona's; else the provider's default (null).
     */
    private function voiceFor(string $asked): ?string
    {
        $voices = \Pramnos\Llm\Speech\Speech::voices();
        if (isset($voices[$asked])) {
            return $asked;
        }
        $scope = $this->scope();
        if ($scope === null) {
            return null;
        }
        if ($scope->userId > 0 && ($own = \Pramnos\Llm\Speech\Speech::chosen(new \Pramnos\User\User($scope->userId), $scope->persona)) !== null) {
            return $own;
        }
        $persona = \Pramnos\Llm\Chat\Personas::of($scope);

        return $persona === null ? null : (\Pramnos\Llm\Chat\Personas::get($persona)['voice'] ?? null);
    }

    /**
     * The voices the person may choose from (`'speech' => ['voices' => …]`): `GET {chat}/voices` →
     * `{voices: [{key, label, sample}], chosen, sample_text}` — `sample` a recording's address where
     * the application has one, else null, and `sample_text` what to read with `POST {chat}/speak`
     * `{"text": …, "voice": key}` instead; `chosen` the person's own for this persona, or null.
     * `PUT {chat}/voice` with `{"voice": key}` keeps one, `""` goes back to the persona's; 422 for
     * one not on the list.
     */
    public function voices(): array
    {
        $put = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'PUT';
        if (($stop = $this->refusal($put ? 'ask' : 'read')) !== null) {
            return $stop;
        }
        $scope = $this->scope();
        if ($scope === null || $scope->userId < 1) {
            return self::error(401, 'This needs somebody signed in.');
        }
        $user = new \Pramnos\User\User($scope->userId);
        if ($put && !\Pramnos\Llm\Speech\Speech::choose($user, (string) ($this->input()['voice'] ?? ''), $scope->persona)) {
            return self::error(422, 'That is not one of the voices.');
        }
        $list = [];
        foreach (\Pramnos\Llm\Speech\Speech::voices() as $key => $voice) {
            $list[] = ['key' => $key, 'label' => $voice['label'], 'sample' => $voice['sample']];
        }

        return ['status' => 200, 'data' => ['voices' => $list, 'chosen' => \Pramnos\Llm\Speech\Speech::chosen($user, $scope->persona),
            'sample_text' => \Pramnos\Llm\Speech\Speech::sample()]];
    }

    /**
     * The words in a recording, by the application's speech-recognition server
     * (`Speech\Listening`) — dictation where the browser has none: `POST {chat}/listen` with
     * `{"audio": "<base64>", "type": "audio/webm", "seconds": 4.2}`, or a multipart file `audio`.
     * → `{text}`. 501 where no server is set up, 422 for an empty or overlong recording, 502 when
     * the server fails.
     */
    public function listen(): array
    {
        if (($stop = $this->refusal('ask')) !== null) {
            return $stop;
        }
        $input = $this->input();
        $file  = $_FILES['audio'] ?? null;
        if (is_array($file) && is_string($file['tmp_name'] ?? null) && is_uploaded_file($file['tmp_name'])) {
            $audio = (string) file_get_contents($file['tmp_name']);
            $type  = (string) ($file['type'] ?? 'audio/webm');
        } else {
            $audio = (string) base64_decode((string) ($input['audio'] ?? ''), true);
            $type  = (string) ($input['type'] ?? 'audio/webm');
        }
        $seconds = is_numeric($input['seconds'] ?? null) ? (float) $input['seconds'] : null;
        $heard   = \Pramnos\Llm\Speech\Listening::hear($audio, $type, $seconds);

        return $heard['ok'] ? ['status' => 200, 'data' => ['text' => $heard['text']]] : self::error($heard['status'], $heard['says']);
    }

    /**
     * The assistant's mood with the person asking (`Chat\Mood`), for the avatar to show — idle
     * included: `GET {chat}/mood`, or `?conversation_id=` for one conversation's. `mood` is null when
     * nothing is felt or moods are off; `plays` is what the avatar set says the word plays.
     */
    public function mood(): array
    {
        if (($stop = $this->refusal('read')) !== null) {
            return $stop;
        }
        $scope = $this->scope();
        $id    = (int) ($_GET['conversation_id'] ?? 0);

        $on    = \Pramnos\Llm\Chat\Mood::on($scope);

        return ['status' => 200, 'data' => ['on' => $on,
            'mood' => $scope === null || !$on ? null : (new \Pramnos\Llm\Chat\Mood($scope))->now($id > 0 ? $id : null)]];
    }

    /**
     * The person's style (`Chat\Acts`): a pose of the avatar set's that the picture keeps between
     * answers. `GET {chat}/style` → `{pose, poses, on}`; `PUT {chat}/style` with `{"pose": "…"}`
     * keeps one, `""` or null none. 422 for a pose the set does not have. An answer's `wear` covers
     * it for that answer.
     */
    public function style(): array
    {
        if (($stop = $this->refusal($_SERVER['REQUEST_METHOD'] === 'PUT' ? 'ask' : 'read')) !== null) {
            return $stop;
        }
        $scope = $this->scope();
        if ($scope === null || $scope->userId < 1) {
            return self::error(401, 'This needs somebody signed in.');
        }
        $user = new \Pramnos\User\User($scope->userId);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'PUT'
            && !\Pramnos\Llm\Chat\Acts::choose($user, (string) ($this->input()['pose'] ?? ''), $scope->persona)) {
            return self::error(422, 'That is not one of the picture\'s poses.');
        }

        return ['status' => 200, 'data' => ['on' => \Pramnos\Llm\Chat\Acts::on($scope) || \Pramnos\Assist\AvatarSets::poses() !== [],
            'pose' => \Pramnos\Llm\Chat\Acts::style($user, $scope->persona), 'poses' => \Pramnos\Assist\AvatarSets::poses()]];
    }

    /**
     * Who the person is to the assistant: `GET {chat}/me` → `{name, usertype, persona,
     * first_meeting, reset_at, first_meetings}` — `first_meeting` the start of their first
     * conversation with this persona since their experience was last reset, null before any;
     * `first_meetings` each persona's, by key (ISO 8601); `reset_at` when an administrator last
     * reset their experience ({@see \Pramnos\Llm\Chat\People::reset()}), or null.
     */
    public function me(): array
    {
        if (($stop = $this->refusal('read')) !== null) {
            return $stop;
        }
        $scope = $this->scope();
        if ($scope === null || $scope->userId < 1) {
            return self::error(401, 'This needs somebody signed in.');
        }
        $user     = new \Pramnos\User\User($scope->userId);
        $meetings = (new \Pramnos\Llm\Chat\People())->firstMeetings($scope->userId);
        $first    = $meetings[\Pramnos\Llm\Chat\People::personaOf($scope->stored())] ?? null;
        $name     = trim((string) ($user->firstname ?? ''));

        return ['status' => 200, 'data' => ['name' => $name !== '' ? $name : (string) ($user->username ?? ''), 'usertype' => (int) ($user->usertype ?? 0),
            'persona' => \Pramnos\Llm\Chat\Personas::of($scope), 'first_meeting' => $first,
            // After an administrator reset the person's experience: the page starts over too, with no flag of its own.
            'reset_at' => \Pramnos\Llm\Chat\People::resetAt($user), 'first_meetings' => $meetings]];
    }

    /**
     * The assistant's diary (`Chat\Diary`), the current persona's: `GET {chat}/diary` →
     * `{pages: [{artifact_id, day, unread}], unread}`, newest first; `GET {chat}/diary/{id}` →
     * `{artifact_id, day, text}`, which marks it and the pages before it read. 404 for a page not
     * the persona's; 501 where the application keeps no diary.
     */
    public function diary($id = null): array
    {
        if (($stop = $this->refusal('read')) !== null) {
            return $stop;
        }
        $scope = $this->scope();
        if ($scope === null || $scope->userId < 1) {
            return self::error(401, 'This needs somebody signed in.');
        }
        if (!\Pramnos\Llm\Chat\Diary::on($scope)) {
            return self::error(501, 'There is no diary here.');
        }
        $diary = new \Pramnos\Llm\Chat\Diary($scope);
        $user  = new \Pramnos\User\User($scope->userId);
        if ($id === null) {
            $pages = $diary->pages($user);

            return ['status' => 200, 'data' => ['pages' => $pages, 'unread' => count(array_filter($pages, static fn (array $p): bool => $p['unread']))]];
        }
        $page = $diary->read($user, (int) $id);

        return $page === null ? self::error(404, 'There is no such page.') : ['status' => 200, 'data' => $page];
    }

    /**
     * The person's companion settings, this persona's (`Chat\Settings`): `GET {chat}/settings` →
     * `{persona, voice, style, thought_at, quiet: {from, to}, notify: {thought, diary, reminders},
     * app: {…}}`; `PUT {chat}/settings` with any of them changes those — all or none, 422 naming
     * the field that is wrong — and answers them all. `{chat}/voice`, `/style` and `/persona` write
     * to the same place.
     */
    public function settings(): array
    {
        $put = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'PUT';
        if (($stop = $this->refusal($put ? 'ask' : 'read')) !== null) {
            return $stop;
        }
        $scope = $this->scope();
        if ($scope === null || $scope->userId < 1) {
            return self::error(401, 'This needs somebody signed in.');
        }
        $user = new \Pramnos\User\User($scope->userId);
        if ($put && is_string($done = \Pramnos\Llm\Chat\Settings::change($user, $scope->persona, $this->input()))) {
            return self::error(422, $done);
        }

        return ['status' => 200, 'data' => \Pramnos\Llm\Chat\Settings::of($put ? new \Pramnos\User\User($scope->userId) : $user, $scope->persona)];
    }

    /**
     * The day's cards (`Chat\Today`): `GET {chat}/today` → `{day, cards: [{kind, title, text,
     * action}]}` — `kind` one of thought, event, reminder, question, diary; `action` what opening
     * it does, `{type, id}`. On the person's clock.
     */
    public function today(): array
    {
        if (($stop = $this->refusal('read')) !== null) {
            return $stop;
        }
        $scope = $this->scope();
        if ($scope === null || $scope->userId < 1) {
            return self::error(401, 'This needs somebody signed in.');
        }
        $chat     = $this->conversations();
        $timezone = $chat->assistant()->timezone($scope);
        $day      = (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('Y-m-d');
        $cards    = \Pramnos\Llm\Chat\Today::cards($scope, new \Pramnos\User\User($scope->userId), $day, $timezone,
            static fn (\Pramnos\Llm\Chat\Scope $s, string $d): ?string => $chat->thought($s, $d), $this->routineService());

        return ['status' => 200, 'data' => ['day' => $day, 'cards' => $cards]];
    }

    /**
     * The personas the person can talk to (`Chat\Personas`): `GET {chat}/personas` →
     * `{personas: [{key, name, gender, voice, size, default, chosen, avatar}], chosen}` — `avatar` its picture's HTML; empty where the
     * application has none.
     */
    public function personas(): array
    {
        if (($stop = $this->refusal('read')) !== null) {
            return $stop;
        }
        $scope  = $this->scope();
        $chosen = $scope === null ? null : \Pramnos\Llm\Chat\Personas::of($scope);
        $list   = array_values(array_map(static fn (array $p): array => ['key' => $p['key'], 'name' => $p['name'], 'gender' => $p['gender'],
            'voice' => $p['voice'], 'size' => $p['size'], 'default' => $p['key'] === \Pramnos\Llm\Chat\Personas::default(), 'chosen' => $p['key'] === $chosen,
            // Its picture, idle, with its stylesheet: a persona without a set of its own has the analyst's.
            // What its picture does when touched (never told to the model).
            'reactions' => \Pramnos\Assist\AvatarSets::using(is_string($p['avatar_set']) || is_array($p['avatar_set']) ? $p['avatar_set'] : null,
                static fn (): array => \Pramnos\Assist\AvatarSets::reactions()),
            'avatar' => self::absolute(\Pramnos\Assist\AvatarSets::using(is_string($p['avatar_set']) || is_array($p['avatar_set']) ? $p['avatar_set'] : null,
                static fn (): string => \Pramnos\Assist\AvatarSets::render('idle', (int) ($p['size'] ?? 64)), $p['name']))],
            \Pramnos\Llm\Chat\Personas::all()));

        return ['status' => 200, 'data' => ['personas' => $list, 'chosen' => $chosen]];
    }

    /**
     * HTML with its addresses on this server made whole, for a client on another origin (an app
     * at its own domain) that would read `/themes/…` against its own.
     */
    private static function absolute(string $html): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '') {
            return $html;
        }
        $origin = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $host;

        return (string) preg_replace('#(\s(?:href|src)=")/(?!/)#', '$1' . $origin . '/', $html);
    }

    /**
     * Choose the persona the person talks to: `PUT {chat}/persona` with `{"persona": "tesla"}`.
     * Its conversations, memories and mood are its own from then on; 422 for one not offered.
     */
    public function persona(): array
    {
        if (($stop = $this->refusal('ask')) !== null) {
            return $stop;
        }
        $scope = $this->scope();
        if ($scope === null || $scope->userId < 1) {
            return self::error(401, 'This needs somebody signed in.');
        }
        if (!\Pramnos\Llm\Chat\Personas::choose(new \Pramnos\User\User($scope->userId), (string) ($this->input()['persona'] ?? ''))) {
            return self::error(422, 'There is no such persona here.');
        }

        return ['status' => 200, 'data' => ['chosen' => (string) $this->input()['persona']]];
    }

    /**
     * The VAPID public key, for `PushManager.subscribe()` in an app that signs in with a token —
     * which the site's own `Push` controller, made for its pages and their session, does not take.
     * 503 where the installation has no key pair.
     */
    public function pushKey(): array
    {
        if (($stop = $this->refusal('read')) !== null) {
            return $stop;
        }
        $vapid = \Pramnos\Push\Vapid::load();

        return $vapid === null ? self::error(503, 'This installation has no VAPID key pair. Run `push:vapid-generate`.')
            : ['status' => 200, 'data' => ['publicKey' => $vapid['publicKey']]];
    }

    /**
     * Keep this device's subscription — `PushSubscription.toJSON()` as the body — for the person
     * asking, marked with the app it came from: the conversations' space (`chat` for an
     * assistant's own app). Its notifications (`pushApp()`) go to those subscriptions alone, and
     * the site's do not. Sent again, the same subscription is refreshed, not added twice.
     */
    public function pushSubscribe(): array
    {
        if (($stop = $this->refusal('ask')) !== null) {
            return $stop;
        }
        $scope = $this->scope();
        if ($scope === null) {
            return self::error(401, 'This needs somebody signed in.');
        }
        $subscription = $this->input();
        if (!\Pramnos\Push\Subscriptions::store($scope->userId, $subscription, (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))) {
            return self::error(422, 'That subscription is not usable — it needs an https endpoint and both keys.');
        }
        \Pramnos\Push\Subscriptions::tagApp($scope->userId, (string) $subscription['endpoint'], $scope->space);

        return ['status' => 200, 'data' => ['subscribed' => true, 'app' => $scope->space]];
    }

    /** Forget this device's subscription, by its `endpoint`; nothing to forget is not an error. */
    public function pushUnsubscribe(): array
    {
        if (($stop = $this->refusal('ask')) !== null) {
            return $stop;
        }
        $scope    = $this->scope();
        $endpoint = trim((string) ($this->input()['endpoint'] ?? ''));
        if ($scope === null || $endpoint === '') {
            return self::error($scope === null ? 401 : 422, $scope === null ? 'This needs somebody signed in.' : 'Send the endpoint to forget.');
        }
        \Pramnos\Push\Subscriptions::forget($endpoint, $scope->userId);

        return ['status' => 200, 'data' => ['unsubscribed' => true]];
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

    /**
     * A queued question followed as a stream of events (`Asks::follow()`): `GET {chat}/asks/{id}/stream`
     * — `doing`, `partial` (whole sentences, tags out), then `answered` or `failed`. An `EventSource`
     * that reconnects is told the same from where the ask stands. 404 for an ask not the person's.
     */
    public function askStream($ask_id): array|\Pramnos\Http\StreamedResponse
    {
        if (($stop = $this->refusal('read')) !== null) {
            return $stop;
        }
        $scope = $this->scope();
        $asks  = $this->asks();
        if ($scope === null || $asks->find($scope, (int) $ask_id) === null) {
            return self::error(404, 'There is no such question.');
        }

        return \Pramnos\Http\StreamedResponse::sse(static function (\Pramnos\Http\Sse\SseWriter $sse) use ($asks, $scope, $ask_id): void {
            $sse->retry(1000);
            $asks->follow($scope, (int) $ask_id, static function (string $event, mixed $data) use ($sse): void {
                $sse->event($event, $data);
            });
        });
    }

    /** Stop a question while the worker answers it: `POST /chat/asks/{ask_id}/cancel`. */
    public function cancelAsk($ask_id): array
    {
        if (($stop = $this->refusal('ask')) !== null) {
            return $stop;
        }

        $scope = $this->scope();

        return $scope !== null && $this->asks()->cancel($scope, (int) $ask_id)
            ? ['status' => 200, 'data' => ['cancelled' => true]]
            : self::error(404, 'There is no such question being answered.');
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

    /**
     * The person's skills — their own, and those offered to a role — and, for whoever manages
     * them, every role's skills. Empty, with `enabled` false, where stored skills are off.
     *
     * @return array<string, mixed>
     */
    public function skillList(): array
    {
        if (($stop = $this->refusal('list')) !== null) {
            return $stop;
        }
        $user   = $this->requestUser();
        $store  = new \Pramnos\Llm\Chat\StoredSkills();
        $on     = \Pramnos\Llm\Chat\StoredSkills::enabled();
        $manage = \Pramnos\Llm\Chat\StoredSkills::canManageRoles($user);
        $mine   = $on ? $store->mine((int) $user->userid) : [];

        return ['status' => 200, 'data' => ['enabled' => $on, 'manage' => $manage,
            'mine' => array_values(array_filter($mine, static fn (array $s): bool => $s['level'] === \Pramnos\Llm\Chat\StoredSkills::USER)),
            'offered' => array_values(array_filter($mine, static fn (array $s): bool => $s['level'] === \Pramnos\Llm\Chat\StoredSkills::ROLE)),
            'roles' => $on && $manage ? $store->roles() : []]];
    }

    /**
     * Keep a skill: `name`, `description`, `tools`, `body`, and `for` (`user`, or `role:<usertype>`
     * for one that manages them) — a new one, or with `skill_id` one being changed.
     *
     * @return array<string, mixed>
     */
    public function skillSave($skill_id = null): array
    {
        if (($stop = $this->refusal('share')) !== null) {
            return $stop;
        }
        $body  = (array) (new \Pramnos\Http\Request())->body();
        $input = array_intersect_key($body, array_flip(['name', 'description', 'tools', 'body']));
        $for   = (string) ($body['for'] ?? '');
        if (str_starts_with($for, 'role:')) {
            $input += ['level' => \Pramnos\Llm\Chat\StoredSkills::ROLE, 'role' => substr($for, 5)];
        } elseif ($for === 'user') {
            $input['level'] = \Pramnos\Llm\Chat\StoredSkills::USER;
        }
        $id    = (int) ($skill_id ?? 0);
        $saved = (new \Pramnos\Llm\Chat\StoredSkills())->save($this->requestUser(), $input, $id > 0 ? $id : null);

        return is_string($saved) ? self::error(422, t($saved)) : ['status' => $id > 0 ? 200 : 201, 'data' => ['skill' => (new \Pramnos\Llm\Chat\StoredSkills())->find($saved)]];
    }

    /**
     * Turn one of one's skills on or off: `on`. A draft turned on is kept.
     *
     * @return array<string, mixed>
     */
    public function skillTurn($skill_id): array
    {
        $on = !empty(((array) (new \Pramnos\Http\Request())->body())['on']);

        return $this->skillDoes('share', fn (object $user): bool => (new \Pramnos\Llm\Chat\StoredSkills())->turn($user, (int) $skill_id, $on), ['on' => $on]);
    }

    /**
     * Delete one of one's skills.
     *
     * @return array<string, mixed>
     */
    public function skillDelete($skill_id): array
    {
        return $this->skillDoes('delete', fn (object $user): bool => (new \Pramnos\Llm\Chat\StoredSkills())->delete($user, (int) $skill_id), ['deleted' => true]);
    }

    /**
     * Offer one of one's skills to a role — `role` — for an administrator to approve.
     *
     * @return array<string, mixed>
     */
    public function skillShare($skill_id): array
    {
        if (($stop = $this->refusal('share')) !== null) {
            return $stop;
        }
        $made = (new \Pramnos\Llm\Chat\StoredSkills())->share($this->requestUser(), (int) $skill_id, (string) (((array) (new \Pramnos\Http\Request())->body())['role'] ?? ''));

        return is_string($made) ? self::error(422, t($made)) : ['status' => 201, 'data' => ['offered' => $made]];
    }

    /**
     * Approve a skill offered to a role: for whoever manages the roles' skills.
     *
     * @return array<string, mixed>
     */
    public function skillApprove($skill_id): array
    {
        return $this->skillDoes('share', fn (object $user): bool => (new \Pramnos\Llm\Chat\StoredSkills())->approve($user, (int) $skill_id), ['approved' => true]);
    }

    /**
     * Turn down a skill offered to a role: for whoever manages the roles' skills.
     *
     * @return array<string, mixed>
     */
    public function skillReject($skill_id): array
    {
        return $this->skillDoes('share', fn (object $user): bool => (new \Pramnos\Llm\Chat\StoredSkills())->reject($user, (int) $skill_id), ['rejected' => true]);
    }

    /**
     * A change to one skill, as the person: 404 for a skill that is not theirs to change.
     *
     * @param array<string, mixed> $done
     * @return array<string, mixed>
     */
    private function skillDoes(string $action, callable $do, array $done): array
    {
        if (($stop = $this->refusal($action)) !== null) {
            return $stop;
        }

        return $do($this->requestUser()) ? ['status' => 200, 'data' => $done] : self::error(404, 'There is no such skill of yours.');
    }

    /** The MCP servers this person connected themselves, without their secrets. */
    public function connections(): array
    {
        if (($stop = $this->refusal('list')) !== null) {
            return $stop;
        }
        $scope = $this->scope();
        if ($scope === null || !\Pramnos\Llm\Mcp\Connections::on()) {
            return self::error(404, 'Connecting servers is not offered here.');
        }

        return ['status' => 200, 'data' => ['connections' => (new \Pramnos\Llm\Mcp\Connections($scope))->all()]];
    }

    /**
     * Connect a server: `name`, `url`, and a `token` where it takes one. A server that wants the
     * person to sign in answers `authorize`, the address to send them to; they come back to the
     * page's `chat/connected`.
     */
    public function connect(): array
    {
        if (($stop = $this->refusal('routines')) !== null) {
            return $stop;
        }
        $scope = $this->scope();
        if ($scope === null || !\Pramnos\Llm\Mcp\Connections::on()) {
            return self::error(404, 'Connecting servers is not offered here.');
        }
        $input = $this->input();
        $made  = (new \Pramnos\Llm\Mcp\Connections($scope))->add((string) ($input['name'] ?? ''), (string) ($input['url'] ?? ''), (string) ($input['token'] ?? ''));

        return isset($made['error']) ? self::error(422, $made['error']) : ['status' => isset($made['authorize']) ? 200 : 201, 'data' => $made];
    }

    /** Remove a connection. */
    public function disconnect($name): array
    {
        if (($stop = $this->refusal('delete')) !== null) {
            return $stop;
        }
        $scope = $this->scope();
        if ($scope === null || !\Pramnos\Llm\Mcp\Connections::on()) {
            return self::error(404, 'Connecting servers is not offered here.');
        }

        return (new \Pramnos\Llm\Mcp\Connections($scope))->remove((string) $name)
            ? ['status' => 200, 'data' => ['deleted' => true]]
            : self::error(404, 'There is no such connection.');
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

        $ran = $this->routineService()->runNow($found, time());

        // Queued: accepted, as a question is — the task says `running` until it has run.
        return $ran['ok'] ? ['status' => $ran['queued'] ? 202 : 200, 'data' => $ran] : ['status' => 409, 'error' => $ran['says'], 'data' => $ran];
    }

    /** One of this person's tasks sent as a report from now on ({@see \Pramnos\Llm\Chat\Routines::asReport()}): the report's id. */
    public function routineAsReport($routine_id): array
    {
        if (($stop = $this->refusal('routines')) !== null) {
            return $stop;
        }
        $scope = $this->scope();
        $made  = $scope === null ? 'There is no such scheduled task.' : $this->routineService()->asReport($scope, (int) $routine_id);
        if (is_string($made)) {
            return self::error($made === 'There is no such scheduled task.' ? 404 : 422, $made);
        }

        return ['status' => 201, 'data' => ['report_id' => $made, 'url' => adminUrl('MetricReports') . '/show?report=' . $made]];
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
        return $this->guard(in_array($action, ['ask', 'routines', 'consent', 'share'], true) ? 'create' : ($action === 'delete' ? 'delete' : 'list'));
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
