<?php

declare(strict_types=1);

namespace Pramnos\Llm\Speech;

use Pramnos\Http\Client as Http;

/**
 * Google Cloud Text-to-Speech (`POST {base_url}/v1/text:synthesize`).
 *
 * Signed in with a bearer `token` — a callable the application gives, usually its service
 * account's access token with the `cloud-platform` scope — or else with an `api_key`
 * (`x-goog-api-key`). The `voice` is Google's full name (`el-GR-Wavenet-A`); its language is the
 * name's first two parts unless `options.languageCode` says otherwise. The other options go into
 * `audioConfig` as they are (`speakingRate`, `pitch`, `audioEncoding`, MP3 by default), and
 * `options.project` is sent as the billed project.
 */
final class GoogleCloud implements Transport
{
    /** The type of each encoding Google answers in. */
    private const TYPES = ['MP3' => 'audio/mpeg', 'OGG_OPUS' => 'audio/ogg', 'LINEAR16' => 'audio/wav', 'MULAW' => 'audio/basic', 'ALAW' => 'audio/basic'];

    /** {@inheritDoc} */
    public function speak(array $config, string $text): array
    {
        $options  = (array) ($config['options'] ?? []);
        $voice    = (string) ($config['voice'] ?? '');
        $language = (string) ($options['languageCode'] ?? implode('-', array_slice(explode('-', $voice), 0, 2)));
        $project  = (string) ($options['project'] ?? '');
        unset($options['languageCode'], $options['project']);
        $audio    = $options + ['audioEncoding' => 'MP3'];

        try {
            $request = Http::post(rtrim((string) $config['base_url'], '/') . '/v1/text:synthesize');
            $token   = self::value($config['token'] ?? null);
            $token !== '' ? $request->header('Authorization', 'Bearer ' . $token)
                : $request->header('x-goog-api-key', self::value($config['api_key'] ?? null));
            if ($project !== '') {
                $request->header('x-goog-user-project', $project);
            }
            $response = $request->json([
                'input'       => ['text' => $text],
                'voice'       => array_filter(['languageCode' => $language, 'name' => $voice], static fn (string $v): bool => $v !== ''),
                'audioConfig' => $audio,
            ])->timeout(max(1, (int) ($config['timeout'] ?? 30)))->send();
        } catch (\Throwable $why) {
            return [0, $why->getMessage(), ''];
        }
        $body = json_decode($response->body(), true);
        if ($response->status() !== 200) {
            return [$response->status(), (string) ($body['error']['message'] ?? mb_substr($response->body(), 0, 200)), 'text/plain'];
        }
        $decoded = base64_decode((string) ($body['audioContent'] ?? ''), true);

        return [200, $decoded === false ? '' : $decoded, self::TYPES[strtoupper((string) $audio['audioEncoding'])] ?? 'audio/mpeg'];
    }

    /** A credential as given: a string, or a callable that answers one. */
    private static function value(mixed $given): string
    {
        return trim((string) (is_callable($given) ? $given() : $given));
    }
}
