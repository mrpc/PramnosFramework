<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Llm;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Http\Client as Http;
use Pramnos\Http\ClientResponse;
use Pramnos\Llm\CallLog;
use Pramnos\Llm\Llm;

/**
 * Shared ground for the language-model tests: settings in memory only, no policies, no fakes,
 * and the application's `llm` section put back afterwards.
 */
abstract class LlmTestCase extends TestCase
{
    /** @var array<string, mixed>|null The `llm` section before the test */
    private ?array $llmConfig = null;

    /** The APP_KEY before the test, restored after it. */
    private string|false $savedKey = false;

    /** @var list<array{url: string, body: array<string, mixed>, raw: string}> What was sent to a provider */
    protected array $sent = [];

    protected function setUp(): void
    {
        $this->llmConfig = Application::getInstance()->applicationInfo['llm'] ?? null;
        // Keys are stored encrypted, so the tests need a key to encrypt them with.
        $this->savedKey = getenv('APP_KEY');
        putenv('APP_KEY=test-key-for-the-llm-module');
        $_ENV['APP_KEY'] = 'test-key-for-the-llm-module';
        // A provider's busy answer is asked again after a wait: no test waits for it.
        \Pramnos\Llm\Client::$sleep = static fn (int $seconds) => null;
        Settings::clearSettings();
        Llm::reset();
        Http::resetFakes();
        $this->sent = [];
        // Learning after each answer is on by default and costs a call; a test that counts the
        // calls an answer makes turns it on itself (MemoryLearningTest).
        $this->configure([]);
    }

    protected function tearDown(): void
    {
        \Pramnos\Llm\Client::$sleep = null;
        $app = Application::getInstance();
        if ($this->llmConfig === null) {
            unset($app->applicationInfo['llm']);
        } else {
            $app->applicationInfo['llm'] = $this->llmConfig;
        }
        Settings::clearSettings();
        Llm::reset();
        Http::resetFakes();
        if ($this->savedKey === false) {
            putenv('APP_KEY');
            unset($_ENV['APP_KEY']);
        } else {
            putenv('APP_KEY=' . $this->savedKey);
            $_ENV['APP_KEY'] = $this->savedKey;
        }
    }

    /**
     * Set the application's `llm` section.
     *
     * @param array<string, mixed> $config
     */
    protected function configure(array $config): void
    {
        if (!isset($config['chat']['memory']['learn'])) {
            $config['chat'] = (is_array($config['chat'] ?? null) ? $config['chat'] : []);
            $config['chat']['memory'] = (is_array($config['chat']['memory'] ?? null) ? $config['chat']['memory'] : []) + ['learn' => false];
        }
        // Summaries, like learning, are a call a test asks for: off unless it does.
        $config['chat'] = (is_array($config['chat'] ?? null) ? $config['chat'] : []) + ['summarize' => false];
        Application::getInstance()->applicationInfo['llm'] = $config;
        Llm::reset();
    }

    /** Store a setting in memory only. */
    protected function setting(string $name, mixed $value): void
    {
        Settings::setSetting($name, $value, false);
    }

    /**
     * Answer every request with the next response in turn, and record what was sent.
     *
     * Each response is `[status, body]`; the last one repeats.
     *
     * @param list<array{0: int, 1: array<string, mixed>|string}> $responses
     */
    protected function answer(array $responses): void
    {
        $queue = $responses;
        Http::fake(['*' => function (Http $request) use (&$queue): ClientResponse {
            $this->sent[] = [
                'url'  => $request->url(),
                'body' => (array) json_decode((string) $request->requestBody(), true),
                'raw'  => (string) $request->requestBody(),
            ];
            [$status, $body] = count($queue) > 1 ? array_shift($queue) : $queue[0];

            return ClientResponse::make(is_string($body) ? $body : (string) json_encode($body), $status);
        }]);
    }

    /**
     * A chat completion that says something.
     *
     * @param array<string, mixed> $usage
     * @return array<string, mixed>
     */
    protected static function says(string $reply, array $usage = ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15], string $finish = 'stop'): array
    {
        return ['choices' => [['message' => ['role' => 'assistant', 'content' => $reply], 'finish_reason' => $finish]], 'usage' => $usage];
    }

    /**
     * A chat completion that calls tools.
     *
     * @param list<array{0: string, 1: array<string, mixed>}> $calls Name and arguments each
     * @param array<string, mixed> $extra Other fields of the message
     * @return array<string, mixed>
     */
    protected static function calls(array $calls, array $extra = []): array
    {
        $toolCalls = [];
        foreach ($calls as $i => [$name, $arguments]) {
            $toolCalls[] = ['id' => 'call_' . $i, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($arguments)]];
        }

        // `$extra` first, so it can give the turn text of its own.
        return ['choices' => [['message' => $extra + ['role' => 'assistant', 'content' => '', 'tool_calls' => $toolCalls], 'finish_reason' => 'tool_calls']],
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 4, 'total_tokens' => 24]];
    }

    /**
     * Every system message of a request, joined: the system prompt and the context's other zones
     * (`ContextAssembler`), wherever in the messages each one is.
     *
     * @param array{body: array<string, mixed>} $request
     */
    protected static function systemOf(array $request): string
    {
        return implode("\n\n", array_map(
            static fn (array $m): string => (string) $m['content'],
            array_values(array_filter((array) $request['body']['messages'], static fn (array $m): bool => ($m['role'] ?? '') === 'system'))
        ));
    }

    /**
     * A request's messages without its system ones: what was said, and the tool calls and results.
     *
     * @param array{body: array<string, mixed>} $request
     * @return list<array<string, mixed>>
     */
    protected static function spoken(array $request): array
    {
        return array_values(array_filter((array) $request['body']['messages'], static fn (array $m): bool => ($m['role'] ?? '') !== 'system'));
    }

    /** A call log that keeps its entries in memory. */
    protected static function memoryLog(): CallLog
    {
        return new class extends CallLog {
            /** @var list<array<string, mixed>> */
            public array $entries = [];

            /** @param array<string, mixed> $entry */
            public function record(array $entry): void
            {
                $this->entries[] = $entry;
            }
        };
    }
}
