<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Logs\Logger;
use Pramnos\Mcp\Tools\StatusTool;

/**
 * "Is anything broken, and what is waiting" — the first question of every session.
 *
 * The value is entirely in the **verdict**: five sections of JSON is what a person skims past,
 * and a one-line answer is what gets read. So most of what is asserted here is that the verdict
 * says the true thing, and in particular that it never says everything is fine while something
 * is not.
 */
#[CoversClass(StatusTool::class)]
class StatusToolTest extends TestCase
{
    /**
     * A healthy installation gets one line and no list to read.
     */
    public function testAHealthyInstallationSaysSoInOneLine(): void
    {
        // Act
        $verdict = $this->verdict([
            'database'   => ['connected' => true],
            'migrations' => ['applied' => 12, 'pending' => 0],
            'queue'      => ['enabled' => true, 'by_status' => ['done' => 4]],
            'health'     => ['status' => 'ok', 'checks' => ['database' => ['status' => 'ok']]],
            'errors'     => ['last' => null],
        ]);

        // Assert
        $this->assertSame(
            'Everything is up, nothing is pending, and nothing has errored.',
            $verdict
        );
    }

    /**
     * An unreachable database stops the report rather than being one finding among five.
     *
     * Every other section is unanswerable without it, and the usual cause — a container that is
     * not running — is what somebody needs told first, not fourth.
     */
    public function testAnUnreachableDatabaseIsTheWholeAnswer(): void
    {
        // Act
        $verdict = $this->verdict([
            'database'   => ['connected' => false],
            'migrations' => ['pending' => 9],
            'errors'     => ['at' => '2026-08-29 10:00:00'],
        ]);

        // Assert
        $this->assertStringContainsString('not reachable', $verdict);
        $this->assertStringContainsString('container', $verdict);
        $this->assertStringNotContainsString('pending migration', $verdict,
            'a pending-migration count read off an unreachable database is not information');
    }

    /**
     * Every kind of problem reaches the verdict, together.
     *
     * Reporting only the first one is how the second is discovered an hour later.
     */
    public function testEveryProblemReachesTheVerdict(): void
    {
        // Act
        $verdict = $this->verdict([
            'database'   => ['connected' => true],
            'migrations' => ['pending' => 3],
            'queue'      => ['enabled' => true, 'by_status' => ['failed' => 7]],
            'health'     => ['status' => 'degraded', 'checks' => ['cache' => ['status' => 'down']]],
            'errors'     => ['at' => '2026-08-29 10:00:00'],
        ]);

        // Assert
        $this->assertStringContainsString('3 pending migration', $verdict);
        $this->assertStringContainsString('cache is down', $verdict);
        $this->assertStringContainsString('7 failed queue job', $verdict);
        $this->assertStringContainsString('last error 2026-08-29 10:00:00', $verdict);
    }

    /**
     * A passing health check is not a finding, whatever word it uses for "fine".
     *
     * Different checks answer `ok`, `healthy` and `pass`, and a verdict that listed all three as
     * problems would be a verdict nobody reads twice.
     */
    public function testEveryWordForFineIsFine(): void
    {
        foreach (['ok', 'healthy', 'pass', 'OK'] as $state) {
            // Act
            $verdict = $this->verdict([
                'database' => ['connected' => true],
                'health'   => ['checks' => ['cache' => ['status' => $state]]],
                'errors'   => ['last' => null],
            ]);

            // Assert
            $this->assertStringNotContainsString('cache is', $verdict, $state);
        }
    }

    /**
     * Pending migrations are named, not only counted.
     *
     * "3 pending" is a number; the names say whether they are this afternoon's work or
     * somebody else's from a branch that should not be here.
     */
    public function testPendingMigrationsAreNamed(): void
    {
        // Act
        $status = $this->tool([
            'migrations' => ['applied' => 1, 'pending' => 2, 'names' => ['a', 'b']],
        ])->execute([]);

        // Assert
        $this->assertSame(['a', 'b'], $status['migrations']['names']);
    }

    /**
     * The last error is found in the log, with the request that produced it.
     *
     * The request id is what makes it actionable: it is the argument to `request-debug`.
     */
    public function testTheLastErrorIsFoundWithItsRequest(): void
    {
        // Arrange
        $file = Logger::logDirectory() . DIRECTORY_SEPARATOR . 'statustool-test.log';

        if (!is_dir(Logger::logDirectory())) {
            mkdir(Logger::logDirectory(), 0777, true);
        }

        // Far enough ahead that a real log in the same directory cannot outrank it.
        file_put_contents($file, implode("\n", [
            json_encode(['timestamp' => '29/08/2099 09:00:00', 'level' => 'info', 'message' => 'fine']),
            json_encode(['timestamp' => '01/09/2099 10:00:00', 'level' => 'error', 'message' => 'older', 'request' => 'aaaaaaaaaaaaaaaa']),
            json_encode(['timestamp' => '29/09/2099 11:00:00', 'level' => 'critical', 'message' => 'newest', 'request' => 'bbbbbbbbbbbbbbbb']),
        ]) . "\n");

        try {
            // Act
            $errors = (new StatusToolProbe([]))->probeErrors();

            // Assert
            $this->assertSame('29/09/2099 11:00:00', $errors['at']);
            $this->assertSame('newest', $errors['message']);
            $this->assertSame('bbbbbbbbbbbbbbbb', $errors['request']);
        } finally {
            @unlink($file);
        }
    }

    /**
     * The most recent error is the most recent one, across a month boundary.
     *
     * The log writes `d/m/Y H:i:s`. Compared as strings, `01/09/2026` sorts before
     * `29/08/2026` — so "the last error" becomes the oldest one for the first days of every
     * month, and is right again by the tenth. A bug that is invisible on the afternoon it is
     * written and reappears twelve times a year.
     */
    public function testTheMostRecentErrorSurvivesAMonthBoundary(): void
    {
        // Arrange
        $file = Logger::logDirectory() . DIRECTORY_SEPARATOR . 'statustool-rollover.log';

        if (!is_dir(Logger::logDirectory())) {
            mkdir(Logger::logDirectory(), 0777, true);
        }

        file_put_contents($file, implode("\n", [
            json_encode(['timestamp' => '29/08/2099 23:59:00', 'level' => 'error', 'message' => 'august']),
            json_encode(['timestamp' => '01/09/2099 00:01:00', 'level' => 'error', 'message' => 'september']),
        ]) . "\n");

        try {
            // Act
            $errors = (new StatusToolProbe([]))->probeErrors();

            // Assert
            $this->assertSame('september', $errors['message'],
                'string comparison would have picked august');
        } finally {
            @unlink($file);
        }
    }

    /**
     * Nothing is started, migrated, cleared or retried.
     *
     * A tool meant to be called reflexively at the start of a session must not be able to
     * change anything, and the schema is where that promise is visible to a caller.
     */
    public function testItTakesNoArgumentsAtAll(): void
    {
        // Act
        $schema = (new StatusTool($this->application()))->inputSchema();

        // Assert
        $this->assertSame([], $schema['properties']);
    }

    /**
     * With no database at all, every section says so instead of throwing.
     *
     * An application configured without one is a real thing — a console-only tool, a project
     * mid-setup — and a status tool that fataled on it would fail exactly when somebody is
     * trying to find out what is wrong.
     */
    public function testWithNoDatabaseNothingThrows(): void
    {
        // Arrange
        $tool = new class extends StatusTool {
            public function __construct()
            {
                $app = new class extends \Pramnos\Application\Application {
                    public function __construct() {}
                };
                $app->database = null;

                parent::__construct($app);
            }
        };

        // Act
        $status = $tool->execute([]);

        // Assert
        $this->assertFalse($status['database']['connected']);
        $this->assertStringContainsString('no database', $status['database']['note']);
        $this->assertTrue($status['migrations']['unknown']);
        $this->assertTrue($status['queue']['unknown']);
        $this->assertStringContainsString('not reachable', $status['verdict']);
    }

    /**
     * A database that refuses to connect is reported with what it said.
     *
     * "Not reachable" is not an answer somebody can act on; the driver's own message names the
     * host and the port, and the usual reading of it is "the container is not running".
     */
    public function testAConnectionFailureCarriesTheReason(): void
    {
        // Arrange
        $tool = new class extends StatusTool {
            public function __construct()
            {
                $db = new class {
                    public bool $connected = false;

                    public function connect(): void
                    {
                        throw new \RuntimeException('Connection refused on db:5432');
                    }
                };

                $app = new class extends \Pramnos\Application\Application {
                    public function __construct() {}
                };
                $app->database = $db;

                parent::__construct($app);
            }

            public function probeDatabase(): array { return $this->database(); }
        };

        // Act
        $database = $tool->probeDatabase();

        // Assert
        $this->assertFalse($database['connected']);
        $this->assertStringContainsString('Connection refused', $database['error']);
        $this->assertStringContainsString('container', $database['note']);
    }

    /**
     * With no log directory, there is no last error rather than a crash.
     *
     * A fresh installation that has never logged anything, which is also the installation most
     * likely to be asking what is wrong.
     */
    public function testWithNoLogDirectoryThereIsNoLastError(): void
    {
        // Arrange
        $tool = new class extends StatusTool {
            public function __construct()
            {
                $app = new class extends \Pramnos\Application\Application {
                    public function __construct() {}
                };
                $app->database = null;

                parent::__construct($app);
            }

            protected function tail(string $path, int $bytes = 262144): array { return []; }

            /** @return array<string, mixed> */
            public function probeErrors(): array { return $this->errors(); }
        };

        // Act
        $errors = $tool->probeErrors();

        // Assert — either no directory, or a directory with no error in it; both are "nothing"
        $this->assertArrayNotHasKey('at', $errors);
        $this->assertArrayHasKey('note', $errors);
    }

    /**
     * A file it cannot open contributes nothing rather than stopping the read.
     *
     * One unreadable log — a permissions mistake, a rotated file half-moved — must not cost the
     * answer that the *other* files hold.
     */
    public function testAnUnreadableFileIsSkipped(): void
    {
        // Arrange
        $tool = new class extends StatusTool {
            public function __construct()
            {
                $app = new class extends \Pramnos\Application\Application {
                    public function __construct() {}
                };
                $app->database = null;

                parent::__construct($app);
            }

            /** @return list<string> */
            public function probeTail(string $path): array { return $this->tail($path); }
        };

        // Assert
        $this->assertSame([], $tool->probeTail('/no/such/file.log'));
    }

    /** @param array<string, mixed> $status */
    private function verdict(array $status): string
    {
        return (new StatusToolProbe([]))->probeVerdict($status);
    }

    /** @param array<string, mixed> $sections */
    private function tool(array $sections): StatusToolProbe
    {
        return new StatusToolProbe($sections);
    }

    private function application(): Application
    {
        $app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
        $app->database = null;

        return $app;
    }
}

/** A status tool whose sections are supplied, so the verdict can be asserted on its own. */
class StatusToolProbe extends StatusTool
{
    /** @param array<string, mixed> $sections */
    public function __construct(private array $sections)
    {
        $app = new class extends Application {
            public function __construct() {}
        };
        $app->database = null;

        parent::__construct($app);
    }

    protected function database(): array
    {
        return $this->sections['database'] ?? ['connected' => true];
    }

    protected function migrations(): array
    {
        return $this->sections['migrations'] ?? ['applied' => 0, 'pending' => 0];
    }

    protected function queue(): array
    {
        return $this->sections['queue'] ?? ['enabled' => false];
    }

    protected function health(): array
    {
        return $this->sections['health'] ?? ['status' => 'ok', 'checks' => []];
    }

    /** @param array<string, mixed> $status */
    public function probeVerdict(array $status): string
    {
        return $this->verdict($status);
    }

    /** @return array<string, mixed> */
    public function probeErrors(): array
    {
        return parent::errors();
    }
}
