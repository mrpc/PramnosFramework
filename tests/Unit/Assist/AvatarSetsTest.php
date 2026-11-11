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
        $this->assertStringStartsWith('<span class="pf-assistant-avatar pf-assistant--idle" data-state="idle" data-durations="{&quot;success&quot;:1800,&quot;greeting&quot;:2400,&quot;notify&quot;:4000}" style="display:inline-block;vertical-align:middle;width:var(--pf-assistant-size,24px);height:var(--pf-assistant-size,24px)" role="img" aria-label="Ask">', $idle, 'its size inline, through the variable a theme sets');
        // Without the theme's stylesheet: sized, and drawn in the text's colour and white, not a page-wide black shape
        $this->assertStringContainsString('width="100%" height="100%"', $idle);
        $this->assertStringContainsString('class="pf-assistant-body" fill="currentColor"', $idle);
        $this->assertStringContainsString('<svg class="pf-assistant-pf"', $idle, 'the framework\'s drop');
        $this->assertStringContainsString('class="pf-assistant-avatar pf-assistant--idle mine" data-state="idle"', $odd, 'not a state: idle');
        $this->assertStringContainsString('--pf-assistant-size:40px;', $odd);
        $this->assertStringContainsString('width:var(--pf-assistant-size,40px)', $odd, 'and its fallback is the size asked for');
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
        $file = tempnam(sys_get_temp_dir(), 'aria');
        file_put_contents($file, '<svg class="aria"></svg>');
        AvatarSets::register('kit', static fn (): string => '<i class="kit"></i>', null, ['greeting' => 900]);

        try {
            // Act
            $this->analyst(['name' => 'Aria', 'avatar_set' => ['markup' => $file, 'css' => '/aria.css', 'states' => ['success' => 500]]]);
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
        $this->assertSame('<link rel="stylesheet" href="/aria.css">', $linkFirst, 'to put before a button; the picture then does not link it again');
        $this->assertStringStartsWith('<span class="pf-assistant-avatar pf-assistant--thinking" data-state="thinking"', $first);
        $this->assertStringContainsString('<svg class="aria"></svg></span>', $first, 'its markup, from the file');
        $this->assertStringContainsString('aria-label="Aria"', $first);
        $this->assertStringContainsString('&quot;success&quot;:500', $first, 'its own duration');
        $this->assertStringNotContainsString('<link', $second, 'the stylesheet once a page');
        $this->assertStringContainsString('<i class="kit"></i>', $named);
        $this->assertStringContainsString('&quot;greeting&quot;:900', $named);
        $this->assertStringContainsString('<img src="/img/ai.svg" alt="" width="20" height="20"></span>', $plain, 'a plain picture in the contract\'s element');
        $this->assertStringContainsString('<svg class="pf-assistant-pf"', $unknown, 'a name nobody registered: the framework\'s');
        $this->assertSame('', AvatarSets::stylesheet(), 'the framework\'s set has no stylesheet of its own');
    }

    /**
     * FW-424: a set with states of its own, poses and transitions. A name alone in `states` is a
     * state of its own, a name with milliseconds ends by itself; the picture can be drawn in one
     * and carries the set's own states, its poses and its transitions for the script. A set that
     * declares none of them draws as before, with none of the attributes.
     */
    public function testASetsOwnStatesPosesAndTransitions(): void
    {
        // Arrange
        $this->analyst(['avatar_set' => ['markup' => '<svg></svg>',
            'states'      => ['curious', 'sleeping', 'waking' => 900, 'success' => 1000, '<bad>'],
            'poses'       => ['sitting', 'loaf'],
            'transitions' => ['idle>listening' => ['is-perking-up', 360], 'sleeping>*' => 'is-waking-up', 'nonsense' => 'x']]]);

        // Act
        $curious = AvatarSets::render('curious');
        $states  = AvatarSets::states();

        // Assert
        $this->assertSame([...AvatarSets::STATES, 'curious', 'sleeping', 'waking'], $states, 'the nine first; a name that is no name dropped');
        $this->assertSame(['sitting', 'loaf'], AvatarSets::poses());
        $this->assertSame(['idle>listening' => ['class' => 'is-perking-up', 'ms' => 360], 'sleeping>*' => ['class' => 'is-waking-up', 'ms' => AvatarSets::TRANSITION_MS]], AvatarSets::transitions());
        $this->assertStringContainsString('data-state="curious"', $curious, 'a state of its own can be drawn');
        $this->assertStringContainsString('data-states="curious,sleeping,waking"', $curious);
        $this->assertStringContainsString('data-poses="sitting,loaf"', $curious);
        $this->assertStringContainsString('&quot;waking&quot;:900', $curious, 'its own state ends by itself');
        $this->assertStringContainsString('&quot;success&quot;:1000', $curious, 'and its duration for one of the nine');
        $this->assertStringContainsString('data-transitions="{&quot;idle&gt;listening&quot;:{&quot;class&quot;:&quot;is-perking-up&quot;,&quot;ms&quot;:360}', $curious);

        // A registered set says the same through register(); one with none of it has no attributes.
        AvatarSets::register('cat', '<svg></svg>', null, ['drowsy'], ['loaf'], ['*>sleeping' => 'is-dozing']);
        $this->analyst(['avatar_set' => 'cat']);
        $this->assertSame('drowsy', AvatarSets::states()[9]);
        $this->assertSame(['loaf'], AvatarSets::poses());
        $this->analyst(['avatar_set' => ['markup' => '<svg></svg>']]);
        $plain = AvatarSets::render('curious');
        $this->assertStringContainsString('data-state="idle"', $plain, 'not its state: idle');
        $this->assertStringNotContainsString('data-states=', $plain);
        $this->assertStringNotContainsString('data-poses=', $plain);
        $this->assertStringNotContainsString('data-transitions=', $plain);
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
        $this->analyst(['avatar_set' => ['markup' => '<svg class="aria"></svg>', 'css' => '/aria.css']]);

        // Act
        $data = AvatarSets::render('idle', 32, '', false);
        $page = AvatarSets::stylesheet();
        $next = AvatarSets::render();

        // Assert
        $this->assertStringStartsWith('<span class="pf-assistant-avatar', $data, 'data: no link');
        $this->assertSame('<link rel="stylesheet" href="/aria.css">', $page, 'the page still gets it');
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
        mkdir($root . '/aria', 0777, true);
        file_put_contents($root . '/aria/aria.css', '.aria{}');
        touch($root . '/aria/aria.css', 1700000000);
        $kept = $_SERVER['DOCUMENT_ROOT'] ?? null;
        $_SERVER['DOCUMENT_ROOT'] = $root;
        $links = [];

        try {
            // Act
            foreach (['/aria/aria.css', 'https://cdn.example.com/aria.css', '/aria/aria.css?v=7', '/aria/missing.css'] as $css) {
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
            '<link rel="stylesheet" href="/aria/aria.css?v=1700000000">',
            '<link rel="stylesheet" href="https://cdn.example.com/aria.css">',
            '<link rel="stylesheet" href="/aria/aria.css?v=7">',
            '<link rel="stylesheet" href="/aria/missing.css">',
        ], $links);
    }
}
