<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Translator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Translator\Language;

/**
 * One language object, chosen deliberately, with a way in for a missing string.
 *
 * Filed as FW-019 from a consuming application running **two** language objects at once,
 * only one of which had the translations loaded:
 *
 * ```
 * pramnos_factory::getLanguage()  ->  pramnos_language            #88
 * Language::getInstance()         ->  Pramnos\Translator\Language #486
 * same object: false
 * _('minutes ago')  ->  'λεπτά πριν'  /  'minutes ago'
 * ```
 *
 * Everything inside the framework that translates — seven call sites — translated from
 * the empty one. And it failed **silently**: both sides return the key unchanged when a
 * translation is missing, so "untranslated key" and "wrong instance" look identical. No
 * error; a page simply comes back in English.
 *
 * `getInstance()` hardcoded `new Language($lang)`, so a subclass could never be the
 * instance. The filing asked for `new static()`, and that does work — PHP 8.1+ shares a
 * method's static locals with its inherited copies. **But which class you get depends on
 * who asks first**, and `Factory::getLanguage()` is called from seven places inside the
 * framework, so an application's own class wins only by luck. That trades a certain bug
 * for a non-deterministic one. The class is declared instead.
 */
#[CoversClass(Language::class)]
class LanguageInstanceAndHookTest extends TestCase
{
    protected function setUp(): void
    {
        Language::resetInstance();
        $this->forgetApplication();
    }

    protected function tearDown(): void
    {
        Language::resetInstance();
        $this->forgetApplication();
    }

    private function forgetApplication(): void
    {
        $rc = new \ReflectionClass(Application::class);
        $rc->getProperty('appInstances')->setValue(null, []);
        $rc->getProperty('lastUsedApplication')->setValue(null, null);
    }

    /** An application whose app.php holds the given keys, with nothing booted. */
    private function application(array $info): void
    {
        $rc  = new \ReflectionClass(Application::class);
        $app = $rc->newInstanceWithoutConstructor();
        $app->applicationInfo = $info;

        $rc->getProperty('appInstances')->setValue(null, ['default' => $app]);
        $rc->getProperty('lastUsedApplication')->setValue(null, 'default');
    }

    // ── Which class is the instance ──────────────────────────────────────────

    /**
     * `language_class` in app.php decides, regardless of who asks first.
     *
     * The whole point of declaring it. `new static()` would have made this depend on call
     * order, and the seven framework call sites mean the order is not the application's
     * to control.
     */
    public function testTheDeclaredClassIsUsed(): void
    {
        // Arrange
        $this->application(['language_class' => DeclaredLanguage::class]);

        // Act & Assert
        $this->assertInstanceOf(DeclaredLanguage::class, Language::getInstance());
    }

    /**
     * `\<namespace>\Language` is tried when nothing is declared.
     *
     * The same convention `Application::resolveApplicationClass()` uses for the
     * application class, so a scaffolded project gets its subclass without configuring
     * anything — and there is one idea to learn rather than two.
     */
    public function testTheNamespaceConventionIsTried(): void
    {
        // Arrange — the fixture class below lives in this namespace.
        $this->application(['namespace' => 'Pramnos\\Tests\\Unit\\Pramnos\\Translator\\Fixture']);

        // Act & Assert
        $this->assertInstanceOf(FixtureNamespaceLanguage::class, Language::getInstance());
    }

    /**
     * A declaration that cannot be honoured falls back rather than failing.
     *
     * A typo in app.php, or a class that is not a Language at all. Refusing to start
     * would take the site down over a translation setting; the base class translates
     * correctly, and the wrong answer here is a missing override rather than a broken
     * page.
     *
     * The subclass check matters on its own: without it, `'language_class' => 'stdClass'`
     * would be constructed with a `$lang` argument it has no constructor for.
     */
    public function testAnUnusableDeclarationFallsBack(): void
    {
        foreach (['\\No\\Such\\Class', 'stdClass', ''] as $declared) {
            // Arrange
            Language::resetInstance();
            $this->application(['language_class' => $declared]);

            // Act & Assert
            $this->assertSame(
                Language::class,
                get_class(Language::getInstance()),
                'a declaration of "' . $declared . '" must fall back to the base class'
            );
        }
    }

    /**
     * With no application at all, the base class is used and nothing is constructed.
     *
     * `resolveClass()` reads `currentInstance()`, not `getInstance()`. Building an
     * application to discover which language class to build would be a factory call
     * inside a factory call — the pattern this framework has an explicit rule against,
     * after a CSRF check once booted a second application underneath a test suite.
     */
    public function testWithNoApplicationTheBaseClassIsUsedAndNoneIsBuilt(): void
    {
        // Act
        $language = Language::getInstance();

        // Assert
        $this->assertSame(Language::class, get_class($language));
        $this->assertNull(Application::currentInstance(), 'resolving a language must construct nothing');
    }

    /**
     * `setInstance()` hands over an object the framework could not have built.
     *
     * The other half of the answer, and a different question from `language_class`: that
     * one is *which class*, this one is *here is the object I already have* — for a
     * bootstrap that constructs it with arguments the framework cannot supply.
     */
    public function testSetInstanceIsHonoured(): void
    {
        // Arrange
        $mine = new DeclaredLanguage();

        // Act
        Language::setInstance($mine);

        // Assert
        $this->assertSame($mine, Language::getInstance());
    }

    /**
     * `resetInstance()` lets the next caller build a fresh one.
     *
     * Needed by any test that changes the configured class or the active language;
     * without it the first test in a run decides for every test after it.
     */
    public function testResetInstanceForgetsIt(): void
    {
        // Arrange
        Language::setInstance(new DeclaredLanguage());

        // Act
        Language::resetInstance();

        // Assert
        $this->assertSame(Language::class, get_class(Language::getInstance()));
    }

    /**
     * Two calls return the same object.
     *
     * The property this whole filing is about: the reporting application had two, and
     * only one of them had strings loaded.
     */
    public function testItIsTheSameObjectEveryTime(): void
    {
        // Act
        $first  = Language::getInstance();
        $second = Language::getInstance();

        // Assert
        $this->assertSame($first, $second);
    }

    // ── The missing-string hook ──────────────────────────────────────────────

    /**
     * A key with no translation reaches `onMissingString()`.
     *
     * The extension point the filing asked for, and what it is for: recording the key to
     * feed a translation tool, and serving a regional dialect as a secondary string. The
     * default returns the key, which is exactly what `_()` did on its own.
     */
    public function testAMissingKeyReachesTheHook(): void
    {
        // Arrange
        $language = new HookedLanguage();

        // Act
        $result = $language->_('nothing_translates_this');

        // Assert
        $this->assertSame(['nothing_translates_this'], $language->seen);
        $this->assertSame('nothing_translates_this', $result, 'declining leaves the key alone');
    }

    /**
     * A translation the hook supplies is formatted with the caller's arguments.
     *
     * The improvement over the legacy filter this replaces: that one returned the
     * filtered string raw, so a supplied translation containing `%s` lost the arguments.
     * Harmless there only because none of its languages used a placeholder — a landmine
     * rather than a working design, and the filing said so itself.
     */
    public function testASuppliedTranslationIsFormatted(): void
    {
        // Arrange
        $language = new HookedLanguage();

        // Act
        $result = $language->_('greeting', 'Γιάννη');

        // Assert
        $this->assertSame('Καλώς ήρθες, Γιάννη', $result);
    }

    /**
     * An empty translation from the hook is respected.
     *
     * Identity against the key is the test, not emptiness: a hook is entitled to suppress
     * a string deliberately, and `''` is a legitimate answer that `empty()` would have
     * thrown away.
     */
    public function testAnEmptyTranslationFromTheHookIsRespected(): void
    {
        // Arrange
        $language = new HookedLanguage();

        // Act
        $result = $language->_('suppress_me');

        // Assert
        $this->assertSame('', $result);
    }

    /**
     * A stored translation still wins over the hook.
     *
     * The hook is the *miss* path. Consulting it for a key that has a translation would
     * make every lookup overridable, which is a different feature and not this one.
     */
    public function testAStoredTranslationDoesNotReachTheHook(): void
    {
        // Arrange
        $language = new HookedLanguage();
        // `addlang()` rather than touching $_strings, which is private — and this is
        // the method the framework's own loader uses, so the test exercises the real
        // route a translation arrives by.
        $language->addlang(['known' => 'γνωστό']);

        // Act
        $result = $language->_('known');

        // Assert
        $this->assertSame('γνωστό', $result);
        $this->assertSame([], $language->seen);
    }
}

/** A subclass an application might declare. */
class DeclaredLanguage extends Language
{
}

/** The class `\<namespace>\Language` resolves to in the convention test. */
class FixtureNamespaceLanguage extends Language
{
}

/** A subclass that records misses and answers some of them. */
class HookedLanguage extends Language
{
    /** @var list<string> */
    public array $seen = [];

    protected function onMissingString(string $string): string
    {
        $this->seen[] = $string;

        return match ($string) {
            'greeting'    => 'Καλώς ήρθες, %s',
            'suppress_me' => '',
            default       => $string,
        };
    }
}

class_alias(
    FixtureNamespaceLanguage::class,
    'Pramnos\\Tests\\Unit\\Pramnos\\Translator\\Fixture\\Language'
);
