<?php

declare(strict_types=1);

namespace Pramnos\Llm\Speech;

use Pramnos\Http\Client as Http;

/**
 * An OpenAI-compatible transcription server (`POST {base_url}/v1/audio/transcriptions`): OpenAI's
 * Whisper, or one the application runs itself (faster-whisper behind the same API). The recording
 * goes as a file; `model` and `language` (two letters) beside it.
 */
final class WhisperListen implements ListenTransport
{
    /** A file name for each type, so the server knows what it is reading. */
    private const NAMES = ['audio/webm' => 'speech.webm', 'audio/ogg' => 'speech.ogg', 'audio/wav' => 'speech.wav', 'audio/mpeg' => 'speech.mp3', 'audio/mp4' => 'speech.m4a'];

    /** {@inheritDoc} */
    public function hear(array $config, string $audio, string $type): array
    {
        $base = strtolower(trim(explode(';', $type)[0]));
        try {
            $request = Http::post(rtrim((string) $config['base_url'], '/') . '/v1/audio/transcriptions');
            $key     = trim((string) (is_callable($config['api_key'] ?? null) ? $config['api_key']() : ($config['api_key'] ?? '')));
            if ($key !== '') {
                $request->header('Authorization', 'Bearer ' . $key);
            }
            $response = $request->multipart([
                ['name' => 'file', 'contents' => $audio, 'filename' => self::NAMES[$base] ?? 'speech.webm', 'type' => $base !== '' ? $base : 'audio/webm'],
                ['name' => 'model', 'contents' => (string) ($config['model'] ?? 'whisper-1')],
                ['name' => 'language', 'contents' => substr((string) ($config['language'] ?? 'el'), 0, 2)],
                ['name' => 'response_format', 'contents' => 'json'],
            ])->timeout(max(1, (int) ($config['timeout'] ?? 60)))->send();
        } catch (\Throwable $why) {
            return [0, $why->getMessage()];
        }
        $body = json_decode($response->body(), true);
        if ($response->status() !== 200) {
            return [$response->status(), (string) ($body['error']['message'] ?? mb_substr($response->body(), 0, 200))];
        }

        return [200, trim((string) ($body['text'] ?? ''))];
    }
}
