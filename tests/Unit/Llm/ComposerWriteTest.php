<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Controllers\Composer;
use Pramnos\Llm\Client;

/**
 * "Write with AI" in the message composer, against a provider that is not called — the
 * framework's HTTP fake answers for it.
 *
 * What holds: the model is told to keep every placeholder the form offers and to write in the
 * given language; its JSON answer comes back as a subject and a cleaned body; the call is logged
 * with its purpose and the account; without a model, nothing to write, or an answer that is not a
 * message, the composer is told why instead.
 */
#[CoversClass(Composer::class)]
final class ComposerWriteTest extends LlmTestCase
{
    /** @var \Pramnos\Llm\CallLog|null The log the controller's client wrote to */
    private ?\Pramnos\Llm\CallLog $log = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_deepseek_api_key', 'sk-deepseek');
        $this->setting('llm_provider', 'deepseek');
        $this->log = self::memoryLog();
    }

    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }

    /** A composer that lets the test in and writes to the test's log. */
    private function composer(): Composer
    {
        $log = $this->log;

        return new class (null, $log) extends Composer {
            public function __construct(?\Pramnos\Application\Application $app, private \Pramnos\Llm\CallLog $calls)
            {
                parent::__construct($app);
            }

            /** Signed in, above the floor. */
            protected function requireMinUserType(int $minType): bool
            {
                return false;
            }

            /** The account writing. */
            protected function me(): int
            {
                return 7;
            }

            /** The test's log. */
            protected function client(): Client
            {
                return new Client(null, $this->calls);
            }
        };
    }

    /** The action's JSON. */
    private function write(array $post): array
    {
        $_POST = $post;

        return (array) json_decode((string) $this->composer()->write()->getBody(), true);
    }

    /**
     * An instruction becomes a subject and a body; the prompt keeps the form's placeholders and
     * names the language; the body comes back cleaned; the call is logged as the account's.
     */
    public function testAnInstructionBecomesAMessageWithItsPlaceholdersKept(): void
    {
        // Arrange
        $this->answer([[200, self::says("Here:\n```json\n{\"subject\": \"Welcome, {firstname}\", \"body\": \"<p>Hi {firstname}</p><script>x()</script>\"}\n```")]]);

        // Act
        $written = $this->write(['instruction' => 'Welcome them', 'subject' => '', 'body' => '', 'language' => 'el', 'fields' => '["{firstname}","{email}"]']);
        $system  = $this->sent[0]['body']['messages'][0]['content'];
        $asked   = json_decode($this->sent[0]['body']['messages'][1]['content'], true);

        // Assert
        $this->assertSame(['subject' => 'Welcome, {firstname}', 'body' => '<p>Hi {firstname}</p>'], $written);
        $this->assertStringContainsString('{firstname}, {email}', $system, 'the placeholders were not named to the model');
        $this->assertStringContainsString('never fill one in', $system);
        $this->assertStringContainsString('"el"', $system, 'the language was not asked for');
        $this->assertSame('Welcome them', $asked['instruction']);
        $this->assertSame('compose', $this->log->entries[0]['purpose']);
        $this->assertSame(7, $this->log->entries[0]['userid']);
    }

    /**
     * Nothing to write from, an answer that is not a message, and a model that fails are each
     * said, not filled in.
     */
    public function testWhatCannotBeWrittenIsSaid(): void
    {
        // Arrange
        $this->answer([[200, self::says('I cannot help with that.')], [500, ['error' => ['message' => 'down']]]]);

        // Act
        $nothing  = $this->write(['instruction' => '', 'subject' => '', 'body' => '<p></p>']);
        $notJson  = $this->write(['instruction' => 'Write', 'fields' => '[]']);
        $failed   = $this->write(['instruction' => 'Write', 'fields' => '[]']);
        $this->setting('llm_deepseek_api_key', '');
        $unconfigured = $this->write(['instruction' => 'Write']);

        // Assert
        $this->assertArrayHasKey('error', $nothing);
        $this->assertArrayHasKey('error', $notJson);
        $this->assertArrayHasKey('error', $failed);
        $this->assertSame('No language model is configured.', $unconfigured['error'] ?? null);
    }

    /** With no placeholders on the form, the prompt still says what one looks like. */
    public function testThePromptWithoutPlaceholdersOrLanguage(): void
    {
        // Act
        $prompt = Composer::prompt([], '');

        // Assert
        $this->assertStringContainsString('such as {firstname}', $prompt);
        $this->assertStringContainsString('language of the existing text', $prompt);
        $this->assertNull(Composer::parse('{"subject": "only"}'));
    }
}
