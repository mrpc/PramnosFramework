<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Mcp\Tools\FindSymbolTool;

/**
 * `find-symbol` — the question grep cannot answer.
 *
 * Grep finds **strings**. This finds **calls**, and names the function each one sits inside,
 * which is the field that turns a list of line numbers into an explanation.
 *
 * It was written after an afternoon spent tracing which code ran a particular query: eight
 * greps, then a patch to `QueryBuilder::exists()` that dumped a backtrace, in a framework
 * whose entire source was on disk. Grep could not find it — the calling line contained
 * neither word being searched for, because the name was in a constant three lines up.
 *
 * These tests run against fixture files written for the purpose rather than against the
 * framework's own source, so they assert behaviour instead of a snapshot of this repository.
 */
#[CoversClass(FindSymbolTool::class)]
class FindSymbolToolTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/find-symbol-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/src', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->root . '/src/*.php') as $file) {
            @unlink((string) $file);
        }

        @rmdir($this->root . '/src');
        @rmdir($this->root);

        parent::tearDown();
    }

    private function write(string $name, string $code): void
    {
        file_put_contents($this->root . '/src/' . $name, "<?php\n" . $code);
    }

    /** @return array<string, mixed> */
    private function find(array $input): array
    {
        /** @var array<string, mixed> $answer */
        $answer = (new FindSymbolTool($this->root))->execute($input);

        return $answer;
    }

    // ── what it is for ───────────────────────────────────────────────────────

    /**
     * Every call is reported with the function it sits inside.
     *
     * The whole point. `Permissions::tableExists` explains a line number;
     * `src/Pramnos/Auth/Permissions.php:413` does not, and that difference is the reason
     * eight greps failed to find a caller that was sitting in plain sight.
     */
    public function testEachCallerNamesTheFunctionItSitsIn(): void
    {
        // Arrange
        $this->write('Probe.php', <<<'CODE'
        namespace App;

        class Probe
        {
            public function outer(): void
            {
                $this->target();
            }

            public function target(): void
            {
            }
        }

        function loose(): void
        {
            (new Probe())->target();
        }
        CODE);

        // Act
        $answer = $this->find(['name' => 'target']);

        // Assert
        $this->assertSame('App\Probe::target', $answer['definitions'][0]['name']);
        $this->assertSame('method', $answer['definitions'][0]['kind']);

        $in = array_column($answer['callers'], 'in');
        $this->assertContains('App\Probe::outer', $in);
        $this->assertContains('App\loose', $in, 'a function outside a class is named too');
    }

    /**
     * A mention in a comment, a doc-block or a string is not a call.
     *
     * This is most of what grep returns when the name is an ordinary English word — `logs`,
     * `table`, `run` — and it is why this is token-based rather than a regex. Verified against
     * the real thing while building it: grep reported 14 hits for `parseTimestamp` where four
     * were calls, four were tests and one was a sentence in a comment.
     */
    public function testCommentsAndStringsAreNotCalls(): void
    {
        // Arrange
        $this->write('Quiet.php', <<<'CODE'
        namespace App;

        class Quiet
        {
            /**
             * See target() for the reason.
             */
            public function speak(): string
            {
                // target() is deliberately not called here
                return 'target()';
            }
        }
        CODE);

        // Act
        $answer = $this->find(['name' => 'target']);

        // Assert
        $this->assertSame([], $answer['callers']);
        $this->assertSame([], $answer['definitions']);
        $this->assertSame(1, $answer['files']['containing'],
            'the string is in the file — it is simply not a call');
    }

    /**
     * An interpolated string does not unwind the scope stack.
     *
     * The bug that shipped in the first draft and was caught by using the tool on the
     * framework: `"{$user->name}"` opens with a `T_CURLY_OPEN` **token** and closes with a
     * plain `}` character. A class containing one appeared to close a brace it never opened,
     * so every call after that point was reported as a bare function —
     * `hasContinuousAggregatePolicy` instead of `SchemaBuilder::hasContinuousAggregatePolicy`.
     *
     * A wrong `in` is worse than a missing one: it sends the reader to the wrong class.
     */
    public function testAnInterpolatedStringDoesNotBreakTheEnclosingScope(): void
    {
        // Arrange
        $this->write('Interpolated.php', <<<'CODE'
        namespace App;

        class Interpolated
        {
            public function first(string $who): string
            {
                return "hello {$who} and ${who}";
            }

            public function second(): void
            {
                $this->target();
            }
        }
        CODE);

        // Act
        $answer = $this->find(['name' => 'target']);

        // Assert
        $this->assertSame(
            'App\Interpolated::second',
            $answer['callers'][0]['in'],
            'the class was still open when second() called target()'
        );
    }

    /**
     * A closure does not steal the name of the method containing it.
     *
     * Closures and anonymous classes open braces without a name, and getting that wrong
     * shows up as calls attributed to the wrong scope — the same failure as above, from the
     * other direction.
     */
    public function testAClosureKeepsTheEnclosingClassCorrect(): void
    {
        // Arrange
        $this->write('Closures.php', <<<'CODE'
        namespace App;

        class Closures
        {
            public function wrapper(): callable
            {
                return function () {
                    return 42;
                };
            }

            public function after(): void
            {
                $this->target();
            }
        }
        CODE);

        // Act
        $answer = $this->find(['name' => 'target']);

        // Assert
        $this->assertSame('App\Closures::after', $answer['callers'][0]['in']);
    }

    // ── the shapes a call comes in ───────────────────────────────────────────

    /**
     * Method, static, function and instantiation are each recognised and labelled.
     */
    public function testEveryCallShapeIsFound(): void
    {
        // Arrange
        $this->write('Shapes.php', <<<'CODE'
        namespace App;

        class Shapes
        {
            public function all(): void
            {
                $this->target();
                self::target();
                target();
                new target();
            }
        }
        CODE);

        // Act
        $types = array_column($this->find(['name' => 'target'])['callers'], 'type');

        // Assert
        $this->assertContains('method', $types);
        $this->assertContains('static', $types);
        $this->assertContains('call', $types);
        $this->assertContains('new', $types);
    }

    /**
     * A class reached statically is a use of that class, even with no `(` after its name.
     *
     * `LogAnalytics::summary()` puts the parentheses on the method. Without this, "who uses
     * this class" came back empty for a class only ever reached statically — which in this
     * framework is most of them.
     */
    public function testAStaticReferenceCountsAsAUseOfTheClass(): void
    {
        // Arrange
        $this->write('Consumer.php', <<<'CODE'
        namespace App;

        class Consumer
        {
            public function read(): mixed
            {
                return \Other\Registry::all();
            }
        }
        CODE);

        // Act
        $answer = $this->find(['name' => 'Registry']);

        // Assert
        $this->assertSame('class-ref', $answer['callers'][0]['type']);
        $this->assertSame('App\Consumer::read', $answer['callers'][0]['in']);
    }

    /**
     * A fully-qualified name is found by its last segment.
     *
     * Nobody searching for `LogAnalytics` wants to have to type the namespace, and the
     * framework's own call sites mix both spellings in the same file.
     */
    public function testAQualifiedNameMatchesOnItsLastSegment(): void
    {
        // Arrange
        $this->write('Qualified.php', <<<'CODE'
        namespace App;

        class Qualified
        {
            public function go(): void
            {
                \Deep\Down\Thing::method();
            }
        }
        CODE);

        // Act & Assert
        $this->assertCount(1, $this->find(['name' => 'Thing'])['callers']);
    }

    /**
     * A declaration is not counted as a call to itself.
     */
    public function testADeclarationIsNotItsOwnCaller(): void
    {
        // Arrange
        $this->write('Only.php', <<<'CODE'
        namespace App;

        class Only
        {
            public function target(): void
            {
            }
        }
        CODE);

        // Act
        $answer = $this->find(['name' => 'target']);

        // Assert
        $this->assertCount(1, $answer['definitions']);
        $this->assertSame([], $answer['callers']);
    }

    /**
     * Classes, interfaces, traits and enums are all found as definitions.
     */
    public function testTheDeclarationKindsAreAllRecognised(): void
    {
        // Arrange
        $this->write('Kinds.php', <<<'CODE'
        namespace App;

        interface Wanted
        {
        }
        CODE);
        $this->write('Kinds2.php', <<<'CODE'
        namespace App;

        enum Colour
        {
            case Red;
        }
        CODE);

        // Act & Assert
        $this->assertSame('class', $this->find(['name' => 'Wanted'])['definitions'][0]['kind']);
        $this->assertSame('App\Colour', $this->find(['name' => 'Colour'])['definitions'][0]['name']);
    }

    /**
     * `Foo::class` is not a declaration of `Foo`.
     *
     * `T_CLASS` fires on both, and treating the constant as a declaration would report a
     * class as defined in every file that mentions it.
     */
    public function testTheClassConstantIsNotADeclaration(): void
    {
        // Arrange
        $this->write('Constant.php', <<<'CODE'
        namespace App;

        class Holder
        {
            public function which(): string
            {
                return Holder::class;
            }
        }
        CODE);

        // Act
        $answer = $this->find(['name' => 'class']);

        // Assert
        $this->assertSame([], $answer['definitions']);
    }

    // ── narrowing and honesty ────────────────────────────────────────────────

    /**
     * `Class::method` narrows the definitions and says why it cannot narrow the callers.
     *
     * Establishing that a given `$thing->hasTable()` is a `SchemaBuilder` would need type
     * inference. Presenting name-matched callers as if they were precise would be the tool
     * lying about its own accuracy, on the question it exists to answer.
     */
    public function testNamingAClassNarrowsDefinitionsAndAdmitsAboutCallers(): void
    {
        // Arrange
        $this->write('Two.php', <<<'CODE'
        namespace App;

        class Alpha
        {
            public function shared(): void
            {
            }
        }

        class Beta
        {
            public function shared(): void
            {
            }
        }
        CODE);

        // Act
        $answer = $this->find(['name' => 'Alpha::shared']);

        // Assert
        $this->assertCount(1, $answer['definitions']);
        $this->assertSame('App\Alpha::shared', $answer['definitions'][0]['name']);
        $this->assertStringContainsString('type inference', (string) $answer['note']);
    }

    /**
     * Nothing found says so, and says what this cannot see.
     *
     * A dynamic call — `$method()`, `call_user_func` — is invisible here, and an empty answer
     * that did not admit it would read as "nothing calls this", which is how a method gets
     * deleted.
     */
    public function testAnEmptyAnswerSaysWhatItCannotSee(): void
    {
        // Act
        $answer = $this->find(['name' => 'nothingIsCalledThis']);

        // Assert
        $this->assertSame([], $answer['callers']);
        $this->assertStringContainsString('call_user_func', (string) $answer['note']);
    }

    /**
     * How much was looked at is reported, not just what was found.
     *
     * "No callers" is only trustworthy alongside the number of files that were read.
     */
    public function testItSaysHowMuchItLookedAt(): void
    {
        // Arrange
        $this->write('One.php', 'namespace App; class One { public function target(): void {} }');
        $this->write('Two.php', 'namespace App; class Two {}');

        // Act
        $answer = $this->find(['name' => 'target']);

        // Assert
        $this->assertSame(2, $answer['files']['searched']);
        $this->assertSame(1, $answer['files']['containing']);
        $this->assertTrue($answer['complete']);
    }

    /**
     * Bad input is refused rather than scanned for.
     */
    public function testItRefusesSomethingThatIsNotAnIdentifier(): void
    {
        // Assert
        $this->assertArrayHasKey('error', $this->find(['name' => '']));
        $this->assertArrayHasKey('error', $this->find(['name' => 'has-a-dash']));
        $this->assertArrayHasKey('error', $this->find(['name' => '$variable']));
    }

    /**
     * `kind` leaves out the half that was not asked for.
     *
     * A caller that wants only the definition of a widely-used method should not be handed
     * sixty call sites — an MCP response is a message.
     */
    public function testKindLimitsWhatComesBack(): void
    {
        // Arrange
        $this->write('Both.php', <<<'CODE'
        namespace App;

        class Both
        {
            public function target(): void
            {
            }

            public function caller(): void
            {
                $this->target();
            }
        }
        CODE);

        // Act
        $definitions = $this->find(['name' => 'target', 'kind' => 'definitions']);
        $callers     = $this->find(['name' => 'target', 'kind' => 'callers']);

        // Assert
        $this->assertArrayHasKey('definitions', $definitions);
        $this->assertArrayNotHasKey('callers', $definitions);
        $this->assertArrayHasKey('callers', $callers);
        $this->assertArrayNotHasKey('definitions', $callers);

        // The counts are reported either way, so a narrowed answer still says what exists
        $this->assertSame(1, $definitions['counts']['callers']);
    }

    /**
     * The schema names itself well enough to be picked over grep.
     *
     * The description is the entire interface a caller sees before choosing, and the whole
     * problem this tool solves is that grep is the habit.
     */
    public function testTheDescriptionSaysWhyNotToUseGrep(): void
    {
        // Arrange
        $tool = new FindSymbolTool($this->root);

        // Assert
        $this->assertSame('find-symbol', $tool->name());
        $this->assertStringContainsString('grep', $tool->description());
        $this->assertSame(['name'], $tool->inputSchema()['required']);
    }
}
