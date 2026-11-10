<?php

declare(strict_types=1);

namespace Pramnos\Llm\Speech;

use Pramnos\Http\Client as Http;

/**
 * Piper's own HTTP server (`python3 -m piper.http_server`): `POST /` with `{"text", "voice"}` and
 * the entry's `options` (`length_scale`, `speaker_id`, …), answered with a WAV file.
 */
final class Piper implements Transport
{
    /** {@inheritDoc} */
    public function speak(array $config, string $text): array
    {
        $body = ['text' => $text] + (array) ($config['options'] ?? []);
        if ((string) ($config['voice'] ?? '') !== '') {
            $body['voice'] = (string) $config['voice'];
        }
        try {
            $response = Http::post(rtrim((string) $config['base_url'], '/') . '/')
                ->json($body)
                ->timeout(max(1, (int) ($config['timeout'] ?? 30)))
                ->send();
        } catch (\Throwable $why) {
            return [0, $why->getMessage(), ''];
        }

        return [$response->status(), $response->body(), $response->header('content-type') ?: 'audio/wav'];
    }
}
