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
 * Every call writes one line to the `speech` log — characters, milliseconds, bytes — so what the
 * server is asked for can be measured before it is scaled.
 */
final class Speech
{
    /** The longest text read in one call. */
    public const MAX_TEXT = 5000;

    /** @var array<string, array<string, mixed>> */
    public const BUILT_IN = [
        'piper' => ['label' => 'Piper', 'transport' => Piper::class, 'base_url' => '', 'voice' => '', 'options' => [], 'timeout' => 30],
    ];

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

        return trim((string) ($entry['base_url'] ?? '')) === '' || !is_a((string) ($entry['transport'] ?? ''), Transport::class, true)
            ? null : ['provider' => $provider] + $entry;
    }

    /**
     * Read a text aloud.
     *
     * @return array{ok: bool, status: int, audio?: string, type?: string, says?: string}
     *         `status` is the one to answer with: 501 with no server, 422 for a text that cannot
     *         be read, 502 when the server fails
     */
    public static function speak(string $text, ?string $provider = null): array
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > self::MAX_TEXT) {
            return ['ok' => false, 'status' => 422, 'says' => 'The text must be 1 to ' . self::MAX_TEXT . ' characters.'];
        }
        $config = self::config($provider);
        if ($config === null) {
            return ['ok' => false, 'status' => 501, 'says' => 'Speech is not set up.'];
        }
        $started = hrtime(true);
        [$status, $audio, $type] = (new $config['transport']())->speak($config, $text);
        $ok = $status === 200 && $audio !== '';
        Logger::info('speak', ['provider' => $config['provider'], 'voice' => (string) ($config['voice'] ?? ''), 'chars' => mb_strlen($text),
            'ms' => (int) ((hrtime(true) - $started) / 1e6), 'bytes' => $ok ? strlen($audio) : 0, 'status' => $status], 'speech');

        return $ok ? ['ok' => true, 'status' => 200, 'audio' => $audio, 'type' => $type]
            : ['ok' => false, 'status' => 502, 'says' => 'The speech server did not answer' . ($status > 0 ? ' (' . $status . ').' : ': ' . mb_substr($audio, 0, 200))];
    }
}
