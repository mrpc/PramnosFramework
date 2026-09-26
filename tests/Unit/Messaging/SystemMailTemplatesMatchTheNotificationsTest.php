<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Messaging;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Messaging\SystemMailTemplates;

/**
 * What the editor advertises and what a notification supplies must be the same list.
 *
 * Two declarations of one fact, in different files, for different readers:
 *
 * - `SystemMailTemplates` is what an operator is shown — the categories in the editor and
 *   the `{placeholders}` each offers.
 * - A notification's `storedMailTemplate()` is what is actually substituted at send time.
 *
 * They drift silently and in both directions, and each direction has its own cost. A
 * placeholder advertised and not supplied renders as `{like_this}` in somebody's email —
 * the operator did exactly what the screen told them and the message went out broken. A
 * category declared by a notification and missing from the registry is an override that
 * works and that nobody can find, which is the state this whole feature was in before the
 * registry existed.
 *
 * So the lists are compared rather than trusted, by reflecting the real notification
 * classes rather than by repeating their contents here — a copy of the answer would drift
 * with everything else.
 */
#[CoversClass(SystemMailTemplates::class)]
class SystemMailTemplatesMatchTheNotificationsTest extends TestCase
{
    /**
     * Every notification class the framework ships that declares a stored template.
     *
     * Found by walking the directory rather than listed, so a fifth notification added
     * without a registry entry fails this instead of being silently unfindable.
     *
     * @return array<string, class-string>
     */
    private function declaringNotifications(): array
    {
        $found = [];
        $dir   = dirname(__DIR__, 3) . '/src/Pramnos/Auth/Notifications';

        foreach (\Pramnos\Framework\Testing\Tree::files($dir) as $path) {
            $class = 'Pramnos\\Auth\\Notifications\\' . basename($path, '.php');

            if (!class_exists($class) || !method_exists($class, 'storedMailTemplate')) {
                continue;
            }

            $found[basename($path, '.php')] = $class;
        }

        return $found;
    }

    /**
     * The category and variables a notification declares, without constructing one.
     *
     * `storedMailTemplate()` reads instance properties, so the class is instantiated
     * without its constructor and the method called on that — enough to read the literal
     * category and the variable *names*, which is all this compares.
     *
     * @return array{category: string, vars: list<string>}
     */
    private function declarationOf(string $class): array
    {
        $instance = (new \ReflectionClass($class))->newInstanceWithoutConstructor();

        // Typed properties are uninitialised on an object built this way, and
        // `storedMailTemplate()` reads them. Give each one a value of its type so the
        // method runs; the values are never compared, only the keys.
        foreach ((new \ReflectionClass($class))->getProperties() as $property) {
            $type = $property->getType();

            if (!$type instanceof \ReflectionNamedType || $property->isStatic()) {
                continue;
            }

            $property->setValue($instance, match ($type->getName()) {
                'int'   => 600,
                'bool'  => false,
                'array' => [],
                default => 'x',
            });
        }

        $declared = (array) $instance->storedMailTemplate();

        return [
            'category' => (string) ($declared['category'] ?? ''),
            'vars'     => array_keys((array) ($declared['vars'] ?? [])),
        ];
    }

    /**
     * Every notification's category is in the registry, and every registry entry is used.
     *
     * Both directions, because they fail differently: a missing registry entry is an
     * override nobody can find; a registry entry no notification answers to is a category
     * an operator can write text for that will never be sent.
     */
    public function testTheCategoriesOnBothSidesAreTheSameSet(): void
    {
        // Arrange
        $notifications = $this->declaringNotifications();
        $this->assertNotEmpty($notifications, 'the sweep found no notification to check');

        $declared = [];
        foreach ($notifications as $name => $class) {
            $declared[$this->declarationOf($class)['category']] = $name;
        }

        $registered = array_keys(SystemMailTemplates::all());

        // Assert
        sort($registered);
        $fromNotifications = array_keys($declared);
        sort($fromNotifications);

        $this->assertSame(
            $registered,
            $fromNotifications,
            'the editor and the notifications disagree about which categories exist'
        );
    }

    /**
     * Every advertised placeholder is one the notification actually supplies.
     *
     * The direction that reaches a person: an operator writes `{firstname}` because the
     * screen offered it, and the message goes out with `{firstname}` in the text.
     */
    public function testEveryAdvertisedPlaceholderIsSupplied(): void
    {
        // Arrange
        $missing = [];

        $notifications = $this->declaringNotifications();
        $this->assertNotEmpty($notifications, 'the sweep found nothing to check');

        foreach ($notifications as $name => $class) {
            $declaration = $this->declarationOf($class);
            $advertised  = SystemMailTemplates::placeholdersFor($declaration['category']);

            foreach ($advertised as $placeholder) {
                if (!in_array($placeholder, $declaration['vars'], true)) {
                    $missing[] = $declaration['category'] . ' advertises {' . $placeholder
                        . '} and ' . $name . ' does not supply it';
                }
            }
        }

        // Assert
        $this->assertSame([], $missing);
    }

    /**
     * And every variable a notification supplies is advertised.
     *
     * The quieter direction: a variable nobody is told about is one nobody uses, which
     * makes the template less useful than the built-in text it replaces.
     */
    public function testEverySuppliedVariableIsAdvertised(): void
    {
        // Arrange
        $unadvertised = [];

        $notifications = $this->declaringNotifications();
        $this->assertNotEmpty($notifications, 'the sweep found nothing to check');

        foreach ($notifications as $name => $class) {
            $declaration = $this->declarationOf($class);
            $advertised  = SystemMailTemplates::placeholdersFor($declaration['category']);

            foreach ($declaration['vars'] as $variable) {
                if (!in_array($variable, $advertised, true)) {
                    $unadvertised[] = $name . ' supplies {' . $variable
                        . '} and ' . $declaration['category'] . ' does not offer it';
                }
            }
        }

        // Assert
        $this->assertSame([], $unadvertised);
    }

    /**
     * An application's own category gets no placeholders and no note from the framework.
     *
     * The registry describes what the framework sends, and an application's categories are
     * its own business — answering anything for one would be an invention. The editor falls
     * back to the placeholders it finds in the text, which is what it always did.
     */
    public function testAnUnknownCategoryIsDescribedByNobody(): void
    {
        // Act + Assert
        $this->assertSame([], SystemMailTemplates::placeholdersFor('shop.order_shipped'));
        $this->assertSame('', SystemMailTemplates::describe('shop.order_shipped'));
    }

    /**
     * A known category's note is the one the registry declares.
     */
    public function testAKnownCategoryDescribesItself(): void
    {
        // Act
        $note = SystemMailTemplates::describe('auth.twofactor_code');

        // Assert
        $this->assertStringContainsString('second factor', $note);
    }

    /**
     * Each entry carries the sentence the editor shows.
     *
     * `auth.new_signin` does not tell an operator whether editing it is safe, or whether
     * somebody is waiting for the message. A registry entry with no description is a
     * category in a list and nothing more.
     */
    public function testEveryCategoryExplainsItself(): void
    {
        foreach (SystemMailTemplates::all() as $category => $entry) {
            // Assert
            $this->assertNotSame('', trim($entry['title']), $category . ' has no title');
            $this->assertGreaterThan(
                40,
                strlen(trim($entry['description'])),
                $category . ' has no description worth showing'
            );
        }
    }
}
