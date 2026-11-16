<?php

declare(strict_types=1);

namespace Pramnos\Llm\Speech;

use Pramnos\Llm\Llm;
use Pramnos\Logs\Logger;

/**
 * Text read aloud by a speech server the application runs — the voice for an assistant's
 * answers where the browser has none in the reader's language.
 *
 * Providers work as the language models' do: {@see BUILT_IN} says how to reach each kind of
 * server, and the application names its own server, voice and default in app.php:
 *
 * ```php
 * 'llm' => ['speech' => [
 *     'provider'  => 'piper',
 *     'providers' => ['piper' => ['base_url' => 'http://piper:5000', 'voice' => 'el_GR-rapunzelina-low']],
 * ]],
 * ```
 *
 * `google` is Google Cloud Text-to-Speech, signed in with a `token` callable (a service account's
 * access token) or an `api_key` — given here, or kept encrypted in the setting
 * {@see settingKey()}; without either it is not set up.
 *
 * `pronounce` lists the application's own words as they are said — a name, a brand — for every
 * provider and every caller ({@see pronounce()}); the chat pages hand the list to the browser's
 * voice too.
 *
 * `voices` lists the voices a person may choose from, each with a label, the provider's own name
 * for it, and optionally its provider and a recorded sample ({@see voices()}); `sample` is a text
 * the chat pages read in each, for a sample the application has not recorded:
 *
 * ```php
 * 'voices' => ['maria' => ['label' => 'Μαρία', 'voice' => 'el-GR-Wavenet-A', 'provider' => 'google']],
 * 'sample' => 'Γεια σου! Έτσι ακούγομαι.',
 * ```
 *
 * `fallback` names a second provider, asked when the first fails or is not set up — Piper beside
 * Google, say: a voice of one's own for when the cloud's is not there.
 *
 * Every call writes one line to the `speech` log — characters, milliseconds, bytes — so what the
 * server is asked for can be measured before it is scaled.
 */
final class Speech
{
    /** The longest text read in one call. */
    public const MAX_TEXT = 5000;

    /** @var array<string, array<string, mixed>> */
    public const BUILT_IN = [
        'piper'  => ['label' => 'Piper', 'transport' => Piper::class, 'base_url' => '', 'voice' => '', 'options' => [], 'timeout' => 30],
        'google' => ['label' => 'Google Cloud', 'transport' => GoogleCloud::class, 'base_url' => 'https://texttospeech.googleapis.com',
            'voice' => '', 'options' => [], 'timeout' => 30, 'needs_key' => true],
    ];

    /** Where a provider's API key is kept, encrypted. */
    public static function settingKey(string $provider): string
    {
        return 'llm_speech_' . $provider . '_api_key';
    }

    /**
     * The provider in use, merged over its built-in entry; null where none has a server.
     *
     * @return array<string, mixed>|null
     */
    public static function config(?string $provider = null): ?array
    {
        $section  = Llm::config('speech', []);
        $section  = is_array($section) ? $section : [];
        $provider = $provider ?? (string) ($section['provider'] ?? 'piper');
        $entry    = array_replace(self::BUILT_IN[$provider] ?? [], (array) ($section['providers'][$provider] ?? []));
        if (!empty($entry['needs_key'])) {
            if (!is_callable($entry['api_key'] ?? null) && trim((string) ($entry['api_key'] ?? '')) === '') {
                try {
                    $entry['api_key'] = \Pramnos\Security\Encrypter::maybeDecrypt(trim((string) \Pramnos\Application\Settings::getSetting(self::settingKey($provider), '')));
                } catch (\Throwable) {
                    $entry['api_key'] = '';
                }
            }
            // A callable is a credential: what it answers is asked for when the text is read.
            $keyed = static fn (mixed $given): bool => is_callable($given) || trim((string) $given) !== '';
            if (!$keyed($entry['token'] ?? null) && !$keyed($entry['api_key'])) {
                return null;
            }
        }

        return trim((string) ($entry['base_url'] ?? '')) === '' || !is_a((string) ($entry['transport'] ?? ''), Transport::class, true)
            ? null : ['provider' => $provider] + $entry;
    }

    /**
     * Read a text aloud — with `$provider`, or the one in use and, should it fail or not be set
     * up, the application's `fallback` (`'speech' => ['fallback' => 'piper']`), once.
     *
     * `$voice` is one of {@see voices()}, by its key — with its provider, where it names one — or
     * else the provider's own name for a voice. The fallback reads in its own voice.
     *
     * `$userId` is who the call is for — queued to a self-hosted provider's own worker when the
     * application has turned that on ({@see speakWith()}); without one it always runs inline.
     *
     * @return array{ok: bool, status: int, audio?: string, type?: string, says?: string}
     *         `status` is the one to answer with: 501 with no server, 422 for a text that cannot
     *         be read, 502 when the server fails — the first provider's, when the fallback fails too
     */
    public static function speak(string $text, ?string $provider = null, ?string $voice = null, int $userId = 0): array
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > self::MAX_TEXT) {
            return ['ok' => false, 'status' => 422, 'says' => 'The text must be 1 to ' . self::MAX_TEXT . ' characters.'];
        }
        $named  = $provider !== null;
        $listed = $voice !== null ? (self::voices()[$voice] ?? null) : null;
        if ($listed !== null) {
            $provider ??= $listed['provider'];
            $voice    = $listed['voice'];
        }
        $text   = self::pronounce($text);
        $spoken = self::speakWith($text, $provider, $voice, $userId);
        if ($spoken['ok'] || $named) {
            return $spoken;
        }
        $section  = Llm::config('speech', []);
        $fallback = is_array($section) ? (string) ($section['fallback'] ?? '') : '';
        if ($fallback === '' || $fallback === ($provider ?? (string) ($section['provider'] ?? 'piper'))) {
            return $spoken;
        }
        $second = self::speakWith($text, $fallback, null, $userId);

        return $second['ok'] ? $second : $spoken;
    }

    /**
     * The voices a person may choose from (`'speech' => ['voices' => [...]]`), by key; one without
     * a provider's name for it is left out.
     *
     * @return array<string, array{label: string, voice: string, provider: ?string, sample: ?string}>
     */
    public static function voices(): array
    {
        $section = Llm::config('speech', []);
        $out     = [];
        foreach ((is_array($section) && is_array($section['voices'] ?? null) ? $section['voices'] : []) as $key => $given) {
            if (is_array($given) && trim((string) ($given['voice'] ?? '')) !== '') {
                $out[(string) $key] = ['label' => (string) ($given['label'] ?? $key), 'voice' => trim((string) $given['voice']),
                    'provider' => isset($given['provider']) ? (string) $given['provider'] : null,
                    'sample' => isset($given['sample']) ? (string) $given['sample'] : null];
            }
        }

        return $out;
    }

    /** The detail the person's own voice is kept in, one for each persona. */
    public const VOICE_DETAIL = 'askvoice';

    /** The voice the person chose (a key of {@see voices()}) for a persona — a partition's own over the holder's — or null. */
    public static function chosen(object $user, ?string $persona = null, ?int $partition = null): ?string
    {
        $own = $partition === null ? '' : (string) ($user->{self::voiceDetail($persona, $partition)} ?? '');
        $key = $own !== '' ? $own : (string) ($user->{self::voiceDetail($persona)} ?? '');

        return isset(self::voices()[$key]) ? $key : null;
    }

    /** Keep the person's voice for a persona: one of {@see voices()}, or '' for the default. False for neither. */
    public static function choose(object $user, string $key, ?string $persona = null, ?int $partition = null): bool
    {
        if ($key !== '' && !isset(self::voices()[$key])) {
            return false;
        }
        $user->{self::voiceDetail($persona, $partition)} = $key;
        $user->save();

        return true;
    }

    /** The detail a persona's voice is kept in. */
    private static function voiceDetail(?string $persona, ?int $partition = null): string
    {
        return ($persona === null ? self::VOICE_DETAIL : self::VOICE_DETAIL . '-' . $persona) . ($partition === null ? '' : '@' . $partition);
    }

    /** The text a voice's sample is read from, where the application has not recorded one; '' for none. */
    public static function sample(): string
    {
        $section = Llm::config('speech', []);

        return is_array($section) ? trim((string) ($section['sample'] ?? '')) : '';
    }

    /**
     * The application's own words, as they are said: `'speech' => ['pronounce' => ['Ava' =>
     * 'Έιβα']]`, each a whole word in any case — "Avalon" is left alone. The longest first, so a
     * phrase is replaced before a word inside it.
     *
     * @return array<string, string>
     */
    public static function pronunciations(): array
    {
        $section = Llm::config('speech', []);
        $words   = is_array($section) && is_array($section['pronounce'] ?? null) ? $section['pronounce'] : [];
        $words   = array_filter(array_map('strval', $words), static fn (string $said, $word): bool => trim((string) $word) !== '', ARRAY_FILTER_USE_BOTH);
        uksort($words, static fn ($a, $b): int => mb_strlen((string) $b) <=> mb_strlen((string) $a));

        return $words;
    }

    /** A text with the application's own words as they are said ({@see pronunciations()}). */
    public static function pronounce(string $text): string
    {
        foreach (self::pronunciations() as $word => $said) {
            $text = (string) preg_replace('/(?<!\pL)' . preg_quote((string) $word, '/') . '(?!\pL)/iu', str_replace(['\\', '$'], ['\\\\', '\\$'], $said), $text);
        }

        return $text;
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
     * Read a text aloud with one provider, and log the call — queued to a worker pointed at the
     * self-hosted provider when `'llm' => ['speech' => ['queue' => true]]]` is on and this call's
     * provider is one ({@see Listening::hear()}'s own reasoning, FW-508). `$userId` is who the
     * call is for; without one it always runs inline.
     *
     * @return array{ok: bool, status: int, audio?: string, type?: string, says?: string}
     */
    private static function speakWith(string $text, ?string $provider, ?string $voice = null, int $userId = 0): array
    {
        $config = self::config($provider);
        if ($config === null) {
            return ['ok' => false, 'status' => 501, 'says' => 'Speech is not set up.'];
        }
        if ($voice !== null && $voice !== '') {
            $config['voice'] = $voice;
        }

        return $userId > 0 && self::queued($config)
            ? self::speakQueued($text, $config, $userId)
            : self::speakNow($text, $config);
    }

    /**
     * The actual call to the provider, and its log line — called inline, or by {@see SpeechQueueTask}
     * once a worker has claimed a queued one.
     *
     * @param array<string, mixed> $config
     * @return array{ok: bool, status: int, audio?: string, type?: string, says?: string}
     */
    public static function speakNow(string $text, array $config, ?string $voice = null): array
    {
        if ($voice !== null && $voice !== '') {
            $config['voice'] = $voice;
        }
        $started = hrtime(true);
        [$status, $audio, $type] = (new $config['transport']())->speak($config, $text);
        $ok = $status === 200 && $audio !== '';
        Logger::info('speak', ['provider' => $config['provider'], 'voice' => (string) ($config['voice'] ?? ''), 'chars' => mb_strlen($text),
            'ms' => (int) ((hrtime(true) - $started) / 1e6), 'bytes' => $ok ? strlen($audio) : 0, 'status' => $status], 'speech');
        self::logCall($config, $ok, $status);

        return $ok ? ['ok' => true, 'status' => 200, 'audio' => $audio, 'type' => $type]
            : ['ok' => false, 'status' => 502, 'says' => 'The speech server did not answer'
                . ($status > 0 ? ' (' . $status . ')' . (str_starts_with($type, 'audio/') || trim($audio) === '' ? '.' : ': ' . mb_substr(trim($audio), 0, 200)) : ': ' . mb_substr($audio, 0, 200))];
    }

    /** Whether this call's provider is self-hosted and the application turned queueing on. */
    private static function queued(array $config): bool
    {
        $section = Llm::config('speech', []);
        $on      = is_array($section) && !empty($section['queue']);
        $cloud   = str_contains((string) $config['base_url'], 'googleapis.com');

        return $on && !$cloud && SpeechQueue::available();
    }

    /**
     * Dispatched to the `speech` queue pool; run inline instead the moment anything about
     * queueing does not go cleanly.
     *
     * @param array<string, mixed> $config
     * @return array{ok: bool, status: int, audio?: string, type?: string, says?: string}
     */
    private static function speakQueued(string $text, array $config, int $userId): array
    {
        if (SpeechQueue::pending() >= self::QUEUE_MAX_PENDING) {
            return self::speakNow($text, $config);
        }

        $runId = SpeechQueue::create($userId, 'speak', $text, ['provider' => $config['provider'], 'voice' => (string) ($config['voice'] ?? '')]);

        try {
            self::queue()->addTask(SpeechQueue::TASK, ['run_id' => $runId, 'user_id' => $userId], 1, 1);
        } catch (\Throwable) {
            SpeechQueue::forget($runId);

            return self::speakNow($text, $config);
        }

        $deadline = microtime(true) + self::QUEUE_WAIT_SECONDS;
        while (true) {
            $row = SpeechQueue::find($runId, $userId);
            if ($row === null || (string) $row['status'] === 'error') {
                SpeechQueue::forget($runId);

                return $row === null ? self::speakNow($text, $config)
                    : ['ok' => false, 'status' => 502, 'says' => (string) $row['error']];
            }
            if ((string) $row['status'] === 'done') {
                SpeechQueue::forget($runId);

                return (array) json_decode((string) $row['result'], true);
            }
            if (microtime(true) >= $deadline) {
                SpeechQueue::forget($runId);

                return self::speakNow($text, $config);
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
    private static function logCall(array $config, bool $ok, int $status): void
    {
        (new \Pramnos\Llm\CallLog())->record([
            'purpose' => 'speak', 'provider' => (string) $config['provider'], 'model' => (string) ($config['voice'] ?? ''),
            'http_status' => $status, 'duration_ms' => null,
            'error' => $ok ? null : 'The speech server did not answer' . ($status > 0 ? ' (' . $status . ').' : '.'),
        ]);
    }
}
