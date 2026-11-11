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
     * @return array{ok: bool, status: int, audio?: string, type?: string, says?: string}
     *         `status` is the one to answer with: 501 with no server, 422 for a text that cannot
     *         be read, 502 when the server fails — the first provider's, when the fallback fails too
     */
    public static function speak(string $text, ?string $provider = null): array
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > self::MAX_TEXT) {
            return ['ok' => false, 'status' => 422, 'says' => 'The text must be 1 to ' . self::MAX_TEXT . ' characters.'];
        }
        $text   = self::pronounce($text);
        $spoken = self::speakWith($text, $provider);
        if ($spoken['ok'] || $provider !== null) {
            return $spoken;
        }
        $section  = Llm::config('speech', []);
        $fallback = is_array($section) ? (string) ($section['fallback'] ?? '') : '';
        if ($fallback === '' || $fallback === (string) ($section['provider'] ?? 'piper')) {
            return $spoken;
        }
        $second = self::speakWith($text, $fallback);

        return $second['ok'] ? $second : $spoken;
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

    /**
     * Read a text aloud with one provider, and log the call.
     *
     * @return array{ok: bool, status: int, audio?: string, type?: string, says?: string}
     */
    private static function speakWith(string $text, ?string $provider): array
    {
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
            : ['ok' => false, 'status' => 502, 'says' => 'The speech server did not answer'
                . ($status > 0 ? ' (' . $status . ')' . (str_starts_with($type, 'audio/') || trim($audio) === '' ? '.' : ': ' . mb_substr(trim($audio), 0, 200)) : ': ' . mb_substr($audio, 0, 200))];
    }
}
