<?php

declare(strict_types=1);

namespace Pramnos\Llm\Speech;

/** One kind of speech-recognition server: audio in, the words said out. */
interface ListenTransport
{
    /**
     * The words in a recording.
     *
     * @param array<string, mixed> $config The provider's entry ({@see Listening::config()})
     * @return array{0: int, 1: string} The status, and the text — or, on failure, why
     */
    public function hear(array $config, string $audio, string $type): array;
}
