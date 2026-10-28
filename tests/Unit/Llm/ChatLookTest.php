<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Llm\Chat\Web\ChatLook;

/**
 * What the conversations page takes from the application to look like its own: an accent colour
 * that cannot become CSS of its own, and the `/` commands its composer offers.
 */
#[CoversClass(ChatLook::class)]
final class ChatLookTest extends TestCase
{
    /**
     * A colour in any of the forms CSS takes passes; one carrying `;` or `:` — rules of its own,
     * in the `style` attribute it lands in — is empty, as is one too long to be a colour.
     *
     * The value is the application's, but it is set in configuration, and a stray `;` there must
     * not restyle the page or load a URL.
     */
    public function testAnAccentIsAColourOrNothing(): void
    {
        // Arrange
        $colours = ['#7c3aed', ' rgb(124 58 237 / 80%) ', 'oklch(0.6 0.2 300)', 'rebeccapurple'];
        $not     = ['red; background: url(https://x.example/)', 'expression:alert(1)', str_repeat('a', 65), ''];

        // Act
        $kept    = array_map([ChatLook::class, 'accent'], $colours);
        $dropped = array_map([ChatLook::class, 'accent'], $not);

        // Assert
        $this->assertSame(['#7c3aed', 'rgb(124 58 237 / 80%)', 'oklch(0.6 0.2 300)', 'rebeccapurple'], $kept);
        $this->assertSame(['', '', '', ''], $dropped);
    }

    /**
     * The page's own commands come first — a new conversation, the scheduled tasks only where the
     * page has them, attaching, what it remembers — then the application's, each with text to put
     * in the box or a page to go to. One without a usable name, without either, or with a name
     * already taken is left out, and one with both keeps its text.
     *
     * The menu is drawn from this list as it stands; an entry that does nothing, or a second
     * `/new`, would be a command that lies about what it does.
     */
    public function testTheCommandsAreThePagesThenTheApplications(): void
    {
        // Arrange
        $own = [
            ['name' => 'week', 'says' => 'This week', 'text' => 'How did this week go?'],
            ['name' => 'help', 'says' => 'How it works', 'url' => '/help'],
            ['name' => 'both', 'text' => 'Text wins', 'url' => '/ignored'],
            ['name' => 'new', 'text' => 'A second /new'],
            ['name' => 'no spaces', 'text' => 'x'],
            ['name' => 'nothing', 'says' => 'Does nothing'],
            'not an array',
        ];

        // Act
        $withTasks    = ChatLook::commands(['page' => '/chat', 'scheduled' => '/chat/scheduled'], $own);
        $withoutTasks = ChatLook::commands(['page' => '/admin/Ai', 'scheduled' => '']);

        // Assert
        $this->assertSame(['new', 'scheduled', 'attach', 'memory', 'week', 'help', 'both'], array_column($withTasks, 'name'));
        $this->assertSame(['name' => 'new', 'says' => 'Start a new conversation', 'url' => '/chat'], $withTasks[0]);
        $this->assertSame(['name' => 'help', 'says' => 'How it works', 'url' => '/help'], $withTasks[5]);
        $this->assertSame(['name' => 'both', 'says' => '', 'text' => 'Text wins'], $withTasks[6]);
        $this->assertSame(['new', 'attach', 'memory'], array_column($withoutTasks, 'name'), 'no scheduled tasks on a page without them');
    }
}
