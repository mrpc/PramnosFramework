<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Assist;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Assist\AvatarSets;

/**
 * The analyst's living picture: one element carrying `data-state`, around the set the
 * application names — or the framework's own when it names none, or its plain picture.
 */
#[CoversClass(AvatarSets::class)]
final class AvatarSetsTest extends TestCase
{
    /** @var array<string, mixed>|null The application's `llm` section, put back after each test */
    private mixed $kept = null;

    protected function setUp(): void
    {
        $this->kept = \Pramnos\Application\Application::getInstance()->applicationInfo['llm'] ?? null;
        AvatarSets::reset();
    }

    protected function tearDown(): void
    {
        \Pramnos\Application\Application::getInstance()->applicationInfo['llm'] = $this->kept;
        AvatarSets::reset();
    }

    /** Set the application's analyst section for the test. */
    private function analyst(array $config): void
    {
        \Pramnos\Application\Application::getInstance()->applicationInfo['llm'] = ['analyst' => $config];
    }

    /**
     * With nothing named, the framework's set: the contract's element — class, state, its class,
     * the durations of the states that end by themselves, the size — around a drop with eyes; an
     * unknown state is idle, and offline is marked as the server's.
     */
    public function testTheFrameworksSetWhenNoneIsNamed(): void
    {
        // Arrange
        $this->analyst([]);

        // Act
        $idle    = AvatarSets::render();
        $odd     = AvatarSets::render('dancing', 40, 'mine');
        $offline = AvatarSets::render('offline');

        // Assert
        $this->assertStringStartsWith('<span class="pf-assistant-avatar pf-assistant--idle" data-state="idle" data-durations="{&quot;success&quot;:1800,&quot;greeting&quot;:2400,&quot;notify&quot;:4000}" role="img" aria-label="Ask">', $idle, 'the stylesheet\'s size: no style');
        $this->assertStringContainsString('<svg class="pf-assistant-pf"', $idle, 'the framework\'s drop');
        $this->assertStringContainsString('class="pf-assistant-avatar pf-assistant--idle mine" data-state="idle"', $odd, 'not a state: idle');
        $this->assertStringContainsString('--pf-assistant-size:40px', $odd);
        $this->assertStringContainsString(' data-offline ', $offline, 'offline is the server\'s word');
        $this->assertSame(['idle', 'listening', 'thinking', 'talking', 'success', 'error', 'offline', 'greeting', 'notify'], AvatarSets::STATES);
    }

    /**
     * A set in app.php — markup from a file, its stylesheet, its own durations — is drawn, its
     * stylesheet once a page; a registered set by its name, its markup from what returns it; a
     * plain picture with no set is that picture, in the same element.
     */
    public function testTheApplicationsSet(): void
    {
        // Arrange
        $file = tempnam(sys_get_temp_dir(), 'naya');
        file_put_contents($file, '<svg class="naya"></svg>');
        AvatarSets::register('kit', static fn (): string => '<i class="kit"></i>', null, ['greeting' => 900]);

        try {
            // Act
            $this->analyst(['name' => 'Naya', 'avatar_set' => ['markup' => $file, 'css' => '/naya.css', 'states' => ['success' => 500]]]);
            $first  = AvatarSets::render('thinking');
            $second = AvatarSets::render();
            $this->analyst(['avatar_set' => 'kit']);
            $named  = AvatarSets::render();
            $this->analyst(['avatar' => '/img/ai.svg']);
            $plain  = AvatarSets::render('success', 20);
            $this->analyst(['avatar_set' => 'nobody']);
            $unknown = AvatarSets::render();
        } finally {
            unlink($file);
        }

        // Assert
        $this->assertStringStartsWith('<link rel="stylesheet" href="/naya.css"><span class="pf-assistant-avatar pf-assistant--thinking" data-state="thinking"', $first);
        $this->assertStringContainsString('<svg class="naya"></svg></span>', $first, 'its markup, from the file');
        $this->assertStringContainsString('aria-label="Naya"', $first);
        $this->assertStringContainsString('&quot;success&quot;:500', $first, 'its own duration');
        $this->assertStringNotContainsString('<link', $second, 'the stylesheet once a page');
        $this->assertStringContainsString('<i class="kit"></i>', $named);
        $this->assertStringContainsString('&quot;greeting&quot;:900', $named);
        $this->assertStringContainsString('<img src="/img/ai.svg" alt="" width="20" height="20"></span>', $plain, 'a plain picture in the contract\'s element');
        $this->assertStringContainsString('<svg class="pf-assistant-pf"', $unknown, 'a name nobody registered: the framework\'s');
    }
}
