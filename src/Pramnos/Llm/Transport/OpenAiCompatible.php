<?php

declare(strict_types=1);

namespace Pramnos\Llm\Transport;

use Pramnos\Http\Client as Http;
use Pramnos\Llm\Connection;

/**
 * `POST {base_url}/chat/completions` with a bearer key: OpenAI, DeepSeek, Gemini's
 * compatibility endpoint, Mistral, and most others.
 */
final class OpenAiCompatible implements Transport
{
    /**
     * @param array<string, mixed> $payload
     * @return array{0: int, 1: array<string, mixed>|null}
     */
    public function chat(Connection $connection, array $payload): array
    {
        try {
            $response = Http::post($connection->baseUrl() . '/chat/completions')
                ->bearerToken($connection->apiKey())
                ->json($payload)
                ->timeout($connection->timeout())
                ->send();

            return [$response->status(), self::decode($response->body())];
        } catch (\Throwable $why) {
            return [0, ['error' => ['message' => $why->getMessage()]]];
        }
    }

    /** @return array{0: int, 1: array<string, mixed>|null} */
    public function get(Connection $connection, string $path): array
    {
        try {
            $response = Http::get($connection->baseUrl() . $path)
                ->bearerToken($connection->apiKey())
                ->header('Accept', 'application/json')
                ->timeout($connection->timeout())
                ->send();

            return [$response->status(), self::decode($response->body())];
        } catch (\Throwable $why) {
            return [0, ['error' => ['message' => $why->getMessage()]]];
        }
    }

    /** @return array<string, mixed>|null */
    private static function decode(string $body): ?array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
