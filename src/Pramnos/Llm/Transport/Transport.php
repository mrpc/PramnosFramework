<?php

declare(strict_types=1);

namespace Pramnos\Llm\Transport;

use Pramnos\Llm\Connection;

/**
 * How a provider is spoken to.
 *
 * The client builds an OpenAI-shaped chat payload — `messages`, `tools`, `tool_choice` — and
 * reads an OpenAI-shaped answer: `choices[0].message`, `usage`. A provider that speaks that
 * natively needs {@see OpenAiCompatible}; one that does not translates both ways here, so the
 * tool loop never knows the difference.
 *
 * Neither method throws: a network failure is status `0` with the reason in
 * `['error' => ['message' => …]]`, so every failure reaches the log as a sentence.
 */
interface Transport
{
    /**
     * Send a chat completion.
     *
     * @param array<string, mixed> $payload OpenAI-shaped
     * @return array{0: int, 1: array<string, mixed>|null} Status, and the OpenAI-shaped answer
     */
    public function chat(Connection $connection, array $payload): array;

    /**
     * One GET against the provider: its model list, its balance.
     *
     * @return array{0: int, 1: array<string, mixed>|null}
     */
    public function get(Connection $connection, string $path): array;
}
