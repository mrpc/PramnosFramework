<?php

declare(strict_types=1);

namespace Pramnos\Llm\Speech;

use Pramnos\Http\Client as Http;

/**
 * Google Cloud Speech-to-Text (`POST {base_url}/v1/speech:recognize`), signed in as the
 * Text-to-Speech is: a bearer `token` callable (a service account) or an `api_key`. A recording
 * of up to a minute; WebM and Ogg Opus as a browser's MediaRecorder makes them, WAV and FLAC read
 * from their own header.
 */
final class GoogleListen implements ListenTransport
{
    /** Google's encoding for each type a browser records in; a type not here is left for Google to read. */
    private const ENCODINGS = ['audio/webm' => 'WEBM_OPUS', 'audio/ogg' => 'OGG_OPUS'];

    /** {@inheritDoc} */
    public function hear(array $config, string $audio, string $type): array
    {
        $options  = (array) ($config['options'] ?? []);
        $project  = (string) ($options['project'] ?? '');
        unset($options['project']);
        $base     = strtolower(trim(explode(';', $type)[0]));
        $encoding = self::ENCODINGS[$base] ?? null;
        $recognition = $options + array_filter([
            'languageCode'        => (string) ($config['language'] ?? 'el-GR'),
            'encoding'            => $encoding,
            'sampleRateHertz'     => $encoding === null ? null : 48000,
            'enableAutomaticPunctuation' => true,
        ], static fn ($v): bool => $v !== null);

        try {
            $request = Http::post(rtrim((string) $config['base_url'], '/') . '/v1/speech:recognize');
            $token   = self::value($config['token'] ?? null);
            $token !== '' ? $request->header('Authorization', 'Bearer ' . $token)
                : $request->header('x-goog-api-key', self::value($config['api_key'] ?? null));
            if ($project !== '') {
                $request->header('x-goog-user-project', $project);
            }
            $response = $request->json(['config' => $recognition, 'audio' => ['content' => base64_encode($audio)]])
                ->timeout(max(1, (int) ($config['timeout'] ?? 30)))->send();
        } catch (\Throwable $why) {
            return [0, $why->getMessage()];
        }
        $body = json_decode($response->body(), true);
        if ($response->status() !== 200) {
            return [$response->status(), (string) ($body['error']['message'] ?? mb_substr($response->body(), 0, 200))];
        }
        $said = array_map(static fn (array $r): string => trim((string) ($r['alternatives'][0]['transcript'] ?? '')), (array) ($body['results'] ?? []));

        return [200, trim(implode(' ', array_filter($said)))];
    }

    /** A credential as given: a string, or a callable that answers one. */
    private static function value(mixed $given): string
    {
        return trim((string) (is_callable($given) ? $given() : $given));
    }
}
