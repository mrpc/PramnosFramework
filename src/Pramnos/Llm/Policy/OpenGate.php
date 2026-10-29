<?php

declare(strict_types=1);

namespace Pramnos\Llm\Policy;

/** No gate: every call the model asks for runs. */
final class OpenGate implements ToolGate
{
    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $tool
     * @param array<string, mixed> $context
     */
    public function before(string $name, array $arguments, array $tool, array $context): ?string
    {
        return null;
    }
}
