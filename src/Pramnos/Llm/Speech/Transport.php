<?php

declare(strict_types=1);

namespace Pramnos\Llm\Speech;

/**
 * How text becomes speech on one kind of server — {@see Piper}'s HTTP server, or an
 * application's own. Named in a speech provider's entry as `transport`.
 *
 * Never throws: a failure is status 0 with the reason.
 */
interface Transport
{
    /**
     * @param array<string, mixed> $config The provider's entry: `base_url`, `voice`, `options`, `timeout`
     * @return array{0: int, 1: string, 2: string} The status, the audio (or why there is none), its type
     */
    public function speak(array $config, string $text): array;
}
