<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Assist;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Assist\AvatarSets;
use Pramnos\Assist\Characters;

/**
 * The framework's characters, and what naming one as the analyst's set gives: her picture with
 * its stylesheet in the page, her states, poses, transitions and moods — her art complete for
 * every pose she declares.
 */
#[CoversClass(Characters::class)]
#[CoversClass(AvatarSets::class)]
final class CharactersTest extends TestCase
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
     * Tesla is a character: who she is, and a set whose markup and stylesheet are files that
     * exist. A name the framework does not ship is null.
     */
    public function testTeslaIsACharacter(): void
    {
        // Arrange
        $tesla = Characters::get('tesla');

        // Act
        $set = $tesla['avatar_set'] ?? [];

        // Assert
        $this->assertSame(['tesla'], Characters::names());
        $this->assertSame('Tesla', $tesla['name']);
        $this->assertSame('female', $tesla['gender']);
        $this->assertStringContainsString('Tesla', $tesla['prompt']);
        $this->assertGreaterThan(24, $tesla['size'], 'a whole body wants more room than a face');
        $this->assertFileExists($set['markup']);
        $this->assertFileExists($set['css']);
        $this->assertNull(Characters::get('garfield'));
    }

    /**
     * `'avatar_set' => 'tesla'` draws her: her svg in the contract's element, her own states and
     * poses and transitions on it, and her stylesheet written into the page once — not a `<link>`
     * to a file no web root has. Each picture gets its own gradient ids.
     */
    public function testNamingTheCharacterDrawsHer(): void
    {
        // Arrange
        $this->analyst(['avatar_set' => 'tesla']);

        // Act
        $first  = AvatarSets::render('idle', 64);
        $second = AvatarSets::render('sleeping', 64);

        // Assert
        $this->assertStringStartsWith('<style>', $first, 'her stylesheet in the page');
        $this->assertStringContainsString('.pf-tesla [data-pose-art]', $first);
        $this->assertStringNotContainsString('<style>', $second, 'once a page');
        $this->assertStringNotContainsString('<link', $first);
        $this->assertStringContainsString('<svg class="pf-tesla"', $first);
        $this->assertStringContainsString('data-state="sleeping"', $second, 'a state of her own is taken');
        $this->assertStringContainsString('data-poses="sitting,classic,slender,loaf,lounging,sleeping,rubbing,stretch,spooked,sploot,stalking,belly-up"', $first);
        $this->assertStringContainsString('is-perking-up', $first, 'her transitions');
        $this->assertStringContainsString(' data-visemes', $first, 'a mouth for each sound, for pf-voice to move');
        $this->assertSame(['walk', 'trot', 'sprint', 'zoomies'], AvatarSets::gaits());
        // FW-453: what she does when touched — for the page, not the model
        $this->assertSame(['plays' => 'annoyed', 'buzz' => [60]], AvatarSets::reactions()['tickle']);
        $this->assertTrue(AvatarSets::reactions()['pet']['repeat']);
        $this->assertContains('annoyed', AvatarSets::states());
        $this->assertContains('purr', AvatarSets::states());
        $this->assertStringContainsString('&quot;annoyed&quot;:1600', $first, 'annoyed passes by itself');
        $this->assertStringContainsString('&quot;waking&quot;:1400', $first, 'waking ends by itself');
        // Each picture its own ids: a gradient referenced across two pictures breaks when one is hidden
        preg_match('/id="pf-tesla-ear-l-(\w+)"/', $first, $a);
        preg_match('/id="pf-tesla-ear-l-(\w+)"/', $second, $b);
        $this->assertNotSame($a[1], $b[1]);
        $this->assertStringNotContainsString('{uid}', $first);
        $this->assertContains('stalking', AvatarSets::states());
        $this->assertSame(Characters::get('tesla')['avatar_set']['poses'], AvatarSets::poses());
    }

    /**
     * A set the application registers under the same name is its own and wins over the
     * character.
     */
    public function testARegisteredSetWinsOverTheCharacter(): void
    {
        // Arrange
        $this->analyst(['avatar_set' => 'tesla']);
        AvatarSets::register('tesla', '<i class="mine"></i>');

        // Act
        $html = AvatarSets::render();

        // Assert
        $this->assertStringContainsString('<i class="mine"></i>', $html);
        $this->assertStringNotContainsString('pf-tesla', $html);
    }

    /**
     * Her moods, unless the application says its own: `avatar_moods` wins over a character's,
     * and the moods of a set given in app.php win over both. The mood words follow.
     */
    public function testHerMoodsUnlessTheApplicationSaysItsOwn(): void
    {
        // Arrange
        $this->analyst(['avatar_set' => 'tesla']);
        $hers = AvatarSets::moods();

        // Act
        $this->analyst(['avatar_set' => 'tesla', 'avatar_moods' => ['glad' => 'happy']]);
        $overridden = AvatarSets::moods();
        $this->analyst(['avatar_set' => ['markup' => '<i></i>', 'moods' => ['shy' => 'wink']], 'avatar_moods' => ['glad' => 'happy']]);
        $given = AvatarSets::moods();

        // Assert
        $this->assertSame('rubbing', $hers['affectionate']);
        $this->assertSame('loaf', $hers['calm'], 'a mood may play a pose');
        $this->assertSame(['glad' => 'happy'], $overridden);
        $this->assertSame(['shy' => 'wink'], $given);
        \Pramnos\Application\Application::getInstance()->applicationInfo['llm'] = ['analyst' => ['avatar_set' => 'tesla'], 'chat' => ['mood' => true]];
        $this->assertContains('affectionate', \Pramnos\Llm\Chat\Mood::words(), 'her moods are the words the model may say');
    }

    /**
     * Her art is complete: every pose she declares is drawn in the svg and shown by the
     * stylesheet when asked for, every state that has a pose of its own leads to a pose that is
     * drawn, and every mood plays a state or a pose she has. Nothing here is visible in a diff of
     * one of the three files.
     */
    public function testHerArtCoversEveryPoseStateAndMood(): void
    {
        // Arrange
        $set = Characters::get('tesla')['avatar_set'];
        $svg = (string) file_get_contents($set['markup']);
        $css = (string) file_get_contents($set['css']);
        $states = array_map(static fn ($k, $v): string => is_int($k) ? (string) $v : (string) $k, array_keys($set['states']), $set['states']);

        // Act
        $doc = new \DOMDocument();
        $parsed = $doc->loadXML($svg);
        preg_match_all('/data-pose-art="([a-z-]+)"/', $svg, $drawn);
        preg_match_all('/\[data-state="([a-z-]+)"\] \.pf-tesla \[data-pose-art="([a-z-]+)"\]/', $css, $implied, PREG_SET_ORDER);

        // Assert
        $this->assertTrue($parsed, 'the svg is well-formed');
        foreach ($set['poses'] as $pose) {
            $this->assertContains($pose, $drawn[1], "pose $pose is drawn");
            $this->assertStringContainsString('[data-pose="' . $pose . '"]', $css, "pose $pose is shown when asked for");
        }
        $this->assertNotEmpty($implied);
        foreach ($implied as [, $state, $pose]) {
            $this->assertContains($state, $states, "state $state is hers");
            $this->assertContains($pose, $set['poses'], "state $state leads to a pose she has");
        }
        foreach ($set['moods'] as $word => $plays) {
            $this->assertTrue(in_array($plays, [...AvatarSets::STATES, ...$states], true) || in_array($plays, $set['poses'], true), "mood $word plays something she has");
        }
        foreach (['open', 'happy', 'shut', 'drowsy', 'wink'] as $eyes) {
            $this->assertStringContainsString('data-eyes="' . $eyes . '"', $svg);
        }
    }
}
