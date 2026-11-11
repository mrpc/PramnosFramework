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

    /**
     * The words in a recording.
     *
     * @param float|null $seconds How long it is, as the recorder says — for the log's cost
     * @return array{ok: bool, status: int, text?: string, says?: string}
     *         501 with no server, 422 for a recording that is empty or too long, 502 when the server fails
     */
    public static function hear(string $audio, string $type = 'audio/webm', ?float $seconds = null, ?string $provider = null): array
    {
        if ($audio === '' || strlen($audio) > self::MAX_BYTES) {
            return ['ok' => false, 'status' => 422, 'says' => 'The recording must be 1 byte to ' . (self::MAX_BYTES / 1_000_000) . ' MB.'];
        }
        $config = self::config($provider);
        if ($config === null) {
            return ['ok' => false, 'status' => 501, 'says' => 'Speech recognition is not set up.'];
        }
        $started = hrtime(true);
        [$status, $text] = (new $config['transport']())->hear($config, $audio, $type);
        $ok = $status === 200;
        Logger::info('listen', ['provider' => $config['provider'], 'bytes' => strlen($audio), 'ms' => (int) ((hrtime(true) - $started) / 1e6),
            'seconds' => $seconds, 'cost' => $seconds === null ? null : round($seconds / 60 * (float) ($config['per_minute'] ?? 0), 6),
            'chars' => $ok ? mb_strlen($text) : 0, 'status' => $status], 'speech');

        return $ok ? ['ok' => true, 'status' => 200, 'text' => $text]
            : ['ok' => false, 'status' => 502, 'says' => 'The speech server did not answer' . ($status > 0 ? ' (' . $status . '): ' : ': ') . mb_substr($text, 0, 200)];
    }
}
