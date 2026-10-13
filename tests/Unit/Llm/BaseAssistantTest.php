<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Llm\Chat\BaseAssistant;
use Pramnos\Llm\Chat\Chat;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Chat\Turn;
use Pramnos\Llm\Llm;

/**
 * The assistant an application gets before it writes its own: every hook answers something
 * sensible, so an application overrides only what it has.
 */
#[CoversClass(BaseAssistant::class)]
#[CoversClass(Chat::class)]
class BaseAssistantTest extends LlmTestCase
{
    /**
     * Its defaults: the `chat` feature's connection when there is one, a short prompt, nothing of
     * its own to add, and the turn's question and messages passed through unchanged.
     */
    public function testTheDefaults(): void
    {
        // Arrange
        $assistant = new BaseAssistant();
        $chat      = new Conversations($assistant);
        $turn      = new Turn(new Scope(1), null, false, [], [], [], $chat);
        $message   = ['message_id' => 1];

        // Act / Assert
        $this->assertNull($assistant->connection(new Scope(1))->feature());
        $this->configure(['features' => ['chat' => 'Ask AI']]);
        $this->assertSame('chat', $assistant->connection(new Scope(1))->feature());
        $this->assertStringContainsString('Never claim to have done anything', $assistant->systemPrompt($turn));
        $this->assertSame('', $assistant->context($turn));
        $this->assertSame([], $assistant->tools($turn));
        $this->assertSame([], $assistant->due($turn));
        $this->assertNull($assistant->verify($turn));
        $this->assertContains('remember', $assistant->writes());
        $this->assertSame('q', $assistant->question($turn, 'q'));
        $this->assertSame($message, $assistant->answered($turn, $message));
        $this->assertSame([$message], $assistant->present(new Scope(1), [$message]));
    }

    /**
     * A held tag with a rule says the rule's own refusal.
     */
    public function testAHeldTagSaysTheRulesRefusal(): void
    {
        // Arrange
        Llm::use('rules', [new GoogleToOpenAi()]);

        // Assert
        $this->assertSame('No.', (new BaseAssistant())->heldRefusal('google'));
    }

    /**
     * The request's conversations, with the configured assistant, as the person signed in.
     */
    public function testTheRequestsConversations(): void
    {
        // Arrange
        Llm::use('router', new class extends \Pramnos\Llm\Policy\DefaultRouter {
            public function context(array $context): array
            {
                return ['userid' => 7];
            }
        });

        // Act
        $chat = Chat::conversations();

        // Assert
        $this->assertSame(7, $chat->scope()->userId);
        $this->assertInstanceOf(BaseAssistant::class, $chat->assistant());
    }
}
