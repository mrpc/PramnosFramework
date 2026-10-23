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
            $linkFirst = AvatarSets::stylesheet();
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
        $this->assertSame('<link rel="stylesheet" href="/naya.css">', $linkFirst, 'to put before a button; the picture then does not link it again');
        $this->assertStringStartsWith('<span class="pf-assistant-avatar pf-assistant--thinking" data-state="thinking"', $first);
        $this->assertStringContainsString('<svg class="naya"></svg></span>', $first, 'its markup, from the file');
        $this->assertStringContainsString('aria-label="Naya"', $first);
        $this->assertStringContainsString('&quot;success&quot;:500', $first, 'its own duration');
        $this->assertStringNotContainsString('<link', $second, 'the stylesheet once a page');
        $this->assertStringContainsString('<i class="kit"></i>', $named);
        $this->assertStringContainsString('&quot;greeting&quot;:900', $named);
        $this->assertStringContainsString('<img src="/img/ai.svg" alt="" width="20" height="20"></span>', $plain, 'a plain picture in the contract\'s element');
        $this->assertStringContainsString('<svg class="pf-assistant-pf"', $unknown, 'a name nobody registered: the framework\'s');
        $this->assertSame('', AvatarSets::stylesheet(), 'the framework\'s set has no stylesheet of its own');
    }

    /**
     * A picture drawn as data first — the dock's props, a `<template>` — carries no `<link>` and
     * does not count as the page having the stylesheet: `stylesheet()` afterwards still gives it.
     * Without this the stylesheet was spent on data, never reached the page, and a set's picture
     * was drawn unstyled.
     */
    public function testAPictureDrawnAsDataLeavesTheStylesheetToThePage(): void
    {
        // Arrange
        $this->analyst(['avatar_set' => ['markup' => '<svg class="naya"></svg>', 'css' => '/naya.css']]);

        // Act
        $data = AvatarSets::render('idle', 32, '', false);
        $page = AvatarSets::stylesheet();
        $next = AvatarSets::render();

        // Assert
        $this->assertStringStartsWith('<span class="pf-assistant-avatar', $data, 'data: no link');
        $this->assertSame('<link rel="stylesheet" href="/naya.css">', $page, 'the page still gets it');
        $this->assertStringNotContainsString('<link', $next, 'and once');
    }

    /**
     * A stylesheet on this server is linked with `?v=` its file's time, so a cache or a CDN does not
     * keep an old one; one on another host, or carrying its own query, is linked as given.
     */
    public function testAStylesheetOnThisServerCarriesItsVersion(): void
    {
        // Arrange
        $root = sys_get_temp_dir() . '/pfavatar-' . bin2hex(random_bytes(4));
        mkdir($root . '/naya', 0777, true);
        file_put_contents($root . '/naya/naya.css', '.naya{}');
        touch($root . '/naya/naya.css', 1700000000);
        $kept = $_SERVER['DOCUMENT_ROOT'] ?? null;
        $_SERVER['DOCUMENT_ROOT'] = $root;
        $links = [];

        try {
            // Act
            foreach (['/naya/naya.css', 'https://cdn.example.com/naya.css', '/naya/naya.css?v=7', '/naya/missing.css'] as $css) {
                AvatarSets::reset();
                $this->analyst(['avatar_set' => ['markup' => '<i></i>', 'css' => $css]]);
                $links[] = AvatarSets::stylesheet();
            }
        } finally {
            $_SERVER['DOCUMENT_ROOT'] = $kept;
            exec('rm -rf ' . escapeshellarg($root));
        }

        // Assert
        $this->assertSame([
            '<link rel="stylesheet" href="/naya/naya.css?v=1700000000">',
            '<link rel="stylesheet" href="https://cdn.example.com/naya.css">',
            '<link rel="stylesheet" href="/naya/naya.css?v=7">',
            '<link rel="stylesheet" href="/naya/missing.css">',
        ], $links);
    }
}
