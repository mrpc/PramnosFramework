<?php

declare(strict_types=1);

namespace Pramnos\Llm\Speech;

use Pramnos\Llm\Llm;
use Pramnos\Logs\Logger;

/**
 * Speech recognised by a server — dictation where the browser has none of its own (Firefox, an
 * installed app on an iPhone), or where its own is not good enough.
 *
 * ```php
 * 'llm' => ['listen' => [
 *     'provider'  => 'google',                                  // or 'whisper'
 *     'providers' => ['google' => ['language' => 'el-GR']],
 * ]],
 * ```
 *
 * `google` is Google Cloud Speech-to-Text, signed in with the same credentials as Text-to-Speech
 * (`speech.providers.google`'s `token` or `api_key`) unless given here. `whisper` is an
 * OpenAI-compatible transcription server — OpenAI's own, with the OpenAI key the language models
 * use, or the application's `base_url`.
 *
 * Every call writes one line to the `speech` log as `listen` — bytes, milliseconds, the seconds
 * recorded where the caller says, and their cost at the provider's `per_minute`.
 */
final class Listening
{
    /** The longest recording taken, in bytes: a minute of Opus is far less. */
    public const MAX_BYTES = 10_000_000;

    /** @var array<string, array<string, mixed>> */
    public const BUILT_IN = [
        'google'  => ['label' => 'Google Cloud', 'transport' => GoogleListen::class, 'base_url' => 'https://speech.googleapis.com',
            'language' => 'el-GR', 'options' => [], 'timeout' => 30, 'per_minute' => 0.016],
        'whisper' => ['label' => 'Whisper', 'transport' => WhisperListen::class, 'base_url' => 'https://api.openai.com',
            'model' => 'whisper-1', 'language' => 'el', 'timeout' => 60, 'per_minute' => 0.006],
    ];

    /**
     * The provider in use, merged over its built-in entry, with its credentials; null where it
     * has none.
     *
     * @return array<string, mixed>|null
     */
    public static function config(?string $provider = null): ?array
    {
        $section  = Llm::config('listen', []);
        $section  = is_array($section) ? $section : [];
        $provider = $provider ?? (string) ($section['provider'] ?? 'google');
        if (!isset(self::BUILT_IN[$provider]) && !isset($section['providers'][$provider])) {
            return null;
        }
        $entry = array_replace(self::BUILT_IN[$provider] ?? [], (array) ($section['providers'][$provider] ?? []));
        $keyed = static fn (mixed $given): bool => is_callable($given) || trim((string) $given) !== '';
        if ($provider === 'google' && !$keyed($entry['token'] ?? null) && !$keyed($entry['api_key'] ?? null)) {
            // The same service account as the voice.
            $speech = Speech::config('google');
            $entry['token']   = $speech['token'] ?? null;
            $entry['api_key'] = $speech['api_key'] ?? null;
        }
        if ($provider === 'whisper' && !$keyed($entry['api_key'] ?? null) && str_contains((string) $entry['base_url'], 'openai.com')) {
            try {
                $entry['api_key'] = \Pramnos\Security\Encrypter::maybeDecrypt(trim((string) \Pramnos\Application\Settings::getSetting('llm_openai_api_key', '')));
            } catch (\Throwable) {
                $entry['api_key'] = '';
            }
        }
        $cloud = str_contains((string) $entry['base_url'], 'googleapis.com') || str_contains((string) $entry['base_url'], 'openai.com');
        if ($cloud && !$keyed($entry['token'] ?? null) && !$keyed($entry['api_key'] ?? null)) {
            return null;
        }

        return is_a((string) ($entry['transport'] ?? ''), ListenTransport::class, true) ? ['provider' => $provider] + $entry : null;
    }

    /** Seconds a queued call waits for a worker before it is run inline instead. */
    public const QUEUE_WAIT_SECONDS = 5;

    /** Seconds between looks at a queued run a worker may still be claiming. */
    public const QUEUE_LOOK_EVERY = 0.2;

    /** Calls waiting or running at once, across everybody, before a new one runs inline instead of queueing. */
    public const QUEUE_MAX_PENDING = 5;

    /** Test seam: how the queue is reached. Null is the real {@see \Pramnos\Queue\QueueManager}. */
    public static ?\Closure $queueFactory = null;

    /**
     * The words in a recording — queued to a worker pointed at the self-hosted provider when
     * `'llm' => ['listen' => ['queue' => true]]]` is on and this call's provider is one (FW-508's
     * own reasoning: a self-hosted server absorbs its own CPU cost, but nothing then protects
     * *this* process's request budget while it waits on one). `$userId` is who the call is for —
     * without one, or without a queue worker, it runs inline as it always did; a hosted provider
     * (Google, OpenAI) is never queued, it has nothing of its own to protect.
     *
     * @param float|null $seconds How long it is, as the recorder says — for the log's cost
     * @return array{ok: bool, status: int, text?: string, says?: string}
     *         501 with no server, 422 for a recording that is empty or too long, 502 when the server fails
     */
    public static function hear(string $audio, string $type = 'audio/webm', ?float $seconds = null, ?string $provider = null, int $userId = 0): array
    {
        if ($audio === '' || strlen($audio) > self::MAX_BYTES) {
            return ['ok' => false, 'status' => 422, 'says' => 'The recording must be 1 byte to ' . (self::MAX_BYTES / 1_000_000) . ' MB.'];
        }
        $config = self::config($provider);
        if ($config === null) {
            return ['ok' => false, 'status' => 501, 'says' => 'Speech recognition is not set up.'];
        }
        if ($userId > 0 && self::queued($config)) {
            return self::hearQueued($audio, $type, $seconds, $config, $userId);
        }

        return self::hearNow($audio, $type, $seconds, $config);
    }

    /**
     * The actual call to the provider, and its log line — called inline, or by {@see SpeechQueueTask}
     * once a worker has claimed a queued one.
     *
     * @param array<string, mixed> $config
     * @return array{ok: bool, status: int, text?: string, says?: string}
     */
    public static function hearNow(string $audio, string $type, ?float $seconds, array $config): array
    {
        $started = hrtime(true);
        [$status, $text] = (new $config['transport']())->hear($config, $audio, $type);
        $ok = $status === 200;
        Logger::info('listen', ['provider' => $config['provider'], 'bytes' => strlen($audio), 'ms' => (int) ((hrtime(true) - $started) / 1e6),
            'seconds' => $seconds, 'cost' => $seconds === null ? null : round($seconds / 60 * (float) ($config['per_minute'] ?? 0), 6),
            'chars' => $ok ? mb_strlen($text) : 0, 'status' => $status], 'speech');
        self::logCall($config, $ok, $status, $seconds);

        return $ok ? ['ok' => true, 'status' => 200, 'text' => $text]
            : ['ok' => false, 'status' => 502, 'says' => 'The speech server did not answer' . ($status > 0 ? ' (' . $status . '): ' : ': ') . mb_substr($text, 0, 200)];
    }

    /** Whether this call's provider is self-hosted and the application turned queueing on. */
    private static function queued(array $config): bool
    {
        $section = Llm::config('listen', []);
        $on      = is_array($section) && !empty($section['queue']);
        $cloud   = str_contains((string) $config['base_url'], 'googleapis.com') || str_contains((string) $config['base_url'], 'openai.com');

        return $on && !$cloud && SpeechQueue::available();
    }

    /**
     * Dispatched to the `speech` queue pool; run inline instead the moment anything about
     * queueing does not go cleanly — queueing is only ever a performance choice here, never a new
     * way for a call that would otherwise have worked to fail.
     *
     * @param array<string, mixed> $config
     * @return array{ok: bool, status: int, text?: string, says?: string}
     */
    private static function hearQueued(string $audio, string $type, ?float $seconds, array $config, int $userId): array
    {
        if (SpeechQueue::pending() >= self::QUEUE_MAX_PENDING) {
            return self::hearNow($audio, $type, $seconds, $config);
        }

        $runId = SpeechQueue::create($userId, 'listen', base64_encode($audio), ['type' => $type, 'seconds' => $seconds, 'provider' => $config['provider']]);

        try {
            self::queue()->addTask(SpeechQueue::TASK, ['run_id' => $runId, 'user_id' => $userId], 1, 1);
        } catch (\Throwable) {
            SpeechQueue::forget($runId);

            return self::hearNow($audio, $type, $seconds, $config);
        }

        $deadline = microtime(true) + self::QUEUE_WAIT_SECONDS;
        while (true) {
            $row = SpeechQueue::find($runId, $userId);
            if ($row === null || (string) $row['status'] === 'error') {
                SpeechQueue::forget($runId);

                return $row === null ? self::hearNow($audio, $type, $seconds, $config)
                    : ['ok' => false, 'status' => 502, 'says' => (string) $row['error']];
            }
            if ((string) $row['status'] === 'done') {
                SpeechQueue::forget($runId);

                return (array) json_decode((string) $row['result'], true);
            }
            if (microtime(true) >= $deadline) {
                SpeechQueue::forget($runId);

                return self::hearNow($audio, $type, $seconds, $config);
            }
            usleep((int) (self::QUEUE_LOOK_EVERY * 1_000_000));
        }
    }

    /** The queue manager the dispatch above reaches — overridden by {@see $queueFactory} in tests. */
    private static function queue(): \Pramnos\Queue\QueueManager
    {
        return self::$queueFactory !== null ? (self::$queueFactory)() : new \Pramnos\Queue\QueueManager(
            \Pramnos\Queue\QueueManager::controllerOrPlain(\Pramnos\Application\Application::currentInstance(), 'queue')
        );
    }

    /** Beside the `speech` log: the same call in `llm_calls`, so it shows in `/admin/Ai/settings` too (FW-521). */
    private static function logCall(array $config, bool $ok, int $status, ?float $seconds): void
    {
        (new \Pramnos\Llm\CallLog())->record([
            'purpose' => 'listen', 'provider' => (string) $config['provider'], 'model' => (string) ($config['model'] ?? ''),
            'http_status' => $status, 'duration_ms' => null,
            'error' => $ok ? null : 'The speech server did not answer' . ($status > 0 ? ' (' . $status . ').' : '.'),
        ]);
    }
}
