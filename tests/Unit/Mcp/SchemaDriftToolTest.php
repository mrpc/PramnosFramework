<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Mcp\Tools\SchemaDriftTool;

/**
 * The schema on disk against the schema in the database.
 *
 * Every assertion here is about a **name**, because that is where this goes wrong: the same
 * table is spelled four ways across a project — `#PREFIX#usersettings` in a migration,
 * `pf_usersettings` in MySQL, `authserver.permissions` on PostgreSQL and
 * `authserver_permissions` on MySQL — and a tool that compares strings reports each spelling as
 * a separate problem. A page of findings that are all the same table twice is the same as no
 * tool.
 */
#[CoversClass(SchemaDriftTool::class)]
class SchemaDriftToolTest extends TestCase
{
    /**
     * A table created by the schema builder is found.
     */
    public function testTheSchemaBuilderIsRead(): void
    {
        // Act
        $tables = $this->probe()->probeTablesIn(<<<'PHP'
            <?php
            $schema->createTable('posts', function ($table) { $table->increments('id'); });
            PHP);

        // Assert
        $this->assertSame(['posts'], $tables);
    }

    /**
     * And so is a table created in raw SQL.
     *
     * Several migrations have to write raw SQL — a hypertable, a schema-qualified table, an
     * index the builder does not express — and a tool that read only `createTable()` would
     * report every one of them as a table nothing creates. Which is the loudest possible false
     * alarm about the most carefully written migrations in the project.
     */
    public function testRawSqlIsReadToo(): void
    {
        // Act
        $tables = $this->probe()->probeTablesIn(<<<'PHP'
            <?php
            $db->query("CREATE TABLE IF NOT EXISTS authserver.jwt_replay_prevention (id bigint)");
            PHP);

        // Assert
        $this->assertSame(['authserver.jwt_replay_prevention'], $tables);
    }

    /**
     * An interpolated name is not guessed at — the `hasTable()` guard names it instead.
     *
     * `CREATE TABLE IF NOT EXISTS {$t}` has no literal name. The optional `IF NOT EXISTS` group
     * backtracks and matches `IF` as the table, and the tool reports a pending migration for a
     * table called "IF".
     */
    public function testAnInterpolatedNameIsNotGuessedAt(): void
    {
        // Act
        $tables = $this->probe()->probeTablesIn(<<<'PHP'
            <?php
            if ($schema->hasTable('authserver.device_authorizations')) { return; }
            $db->query("CREATE TABLE IF NOT EXISTS {$t} (id bigint)");
            PHP);

        // Assert
        $this->assertSame(['authserver.device_authorizations'], $tables);
        $this->assertNotContains('IF', $tables);
    }

    /**
     * A name that is not a name is refused.
     */
    public function testSomethingThatIsNotATableNameIsRefused(): void
    {
        // Act
        $tables = $this->probe()->probeTablesIn(<<<'PHP'
            <?php
            // CREATE TABLE migrations. This comment is not a migration.
            PHP);

        // Assert
        $this->assertSame([], $tables);
    }

    /**
     * A view is an object a migration creates, and it is read like one.
     *
     * The live side reads `information_schema.tables`, which lists views. Matching only
     * `CREATE TABLE` on the declared side reported every view in the project as a live object
     * nothing creates — twenty-two of them, on a report whose whole value is that its lists are
     * short.
     */
    public function testAViewIsReadLikeATable(): void
    {
        // Act
        $tables = $this->probe()->probeTablesIn(<<<'PHP'
            <?php
            $db->query("CREATE OR REPLACE VIEW authserver.slow_api_calls AS SELECT 1");
            $db->query("CREATE MATERIALIZED VIEW pramnos.changelog_daily AS SELECT 1");
            PHP);

        // Assert
        $this->assertSame(
            ['authserver.slow_api_calls', 'pramnos.changelog_daily'],
            $tables
        );
    }

    /**
     * A migration whose table name is a constant is recorded as unreadable, not as nothing.
     *
     * `createTable(DeferredWriteQueue::TABLE, …)`, or a name read from a setting. Statically
     * there is nothing to read — and the consequence, if it went unsaid, is that the table it
     * creates appears under "no migration creates this", which is false and is the worst thing
     * this tool can say.
     */
    public function testATableNamedByAConstantIsReportedAsUnreadable(): void
    {
        // Arrange
        $directory = sys_get_temp_dir() . '/drift-const-' . bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents(
            $directory . '/2026_08_29_000001_create_deferredwrites_table.php',
            "<?php\n\$schema->createTable(DeferredWriteQueue::TABLE, function (\$t) {});\n"
        );

        try {
            // Act
            $tool = new class ($this->emptyApplication(), $directory) extends SchemaDriftTool {
                public function __construct(Application $app, private string $directory)
                {
                    parent::__construct($app);
                }

                /** @return list<string> */
                protected function migrationFiles(): array
                {
                    return (array) glob($this->directory . '/*.php');
                }

                /** @return array<string, list<string>> */
                public function probeDeclared(): array { return $this->declaredTables(); }

                /** @return list<string> */
                public function probeUnreadable(): array
                {
                    return (new \ReflectionProperty(SchemaDriftTool::class, 'unreadable'))
                        ->getValue($this);
                }
            };

            // Assert
            $this->assertSame([], $tool->probeDeclared(), 'nothing can be read from it');
            $this->assertSame(['create_deferredwrites_table'], $tool->probeUnreadable());
        } finally {
            exec('rm -rf ' . escapeshellarg($directory));
        }
    }

    /**
     * A migration that declared itself conditional is not drift.
     *
     * `pramnos.framework_policies` exists on MySQL and plain PostgreSQL and must *not* exist on
     * TimescaleDB, which manages its own policies. The history saying applied with no table is
     * the migration behaving exactly as designed — and reported as "applied without leaving its
     * table", which is the loudest finding this tool has, one false alarm at the top of a report
     * is enough to stop somebody reading it.
     */
    public function testAConditionalMigrationIsNotDrift(): void
    {
        // Arrange
        $directory = sys_get_temp_dir() . '/drift-cond-' . bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents(
            $directory . '/2026_08_29_000001_create_framework_policies_table.php',
            "<?php\npublic bool \$conditional = true;\n"
            . "\$schema->createTable('pramnos.framework_policies', function (\$t) {});\n"
        );

        try {
            // Act
            $answer = (new class ($this->emptyApplicationWithDb(), $directory) extends SchemaDriftTool {
                public function __construct(Application $app, private string $directory)
                {
                    parent::__construct($app);
                }

                /** @return list<string> */
                protected function migrationFiles(): array
                {
                    return (array) glob($this->directory . '/*.php');
                }

                /** @return list<string> */
                protected function liveTables(): array { return []; }

                /** @return array<string, true> */
                protected function appliedSlugs(): array
                {
                    return ['create_framework_policies_table' => true];
                }
            })->execute([]);

            // Assert
            $this->assertArrayNotHasKey('applied_but_missing', $answer);
            $this->assertSame('pramnos.framework_policies', $answer['conditional'][0]['table']);
            $this->assertStringContainsString('every migration has left its table behind',
                $answer['verdict']);
        } finally {
            exec('rm -rf ' . escapeshellarg($directory));
        }
    }

    /**
     * A prefixed live table and its unprefixed declaration are one table.
     */
    public function testThePrefixIsNotADifference(): void
    {
        // Act
        $answer = $this->probe(
            declared: ['#PREFIX#usersettings' => ['create_usersettings_table']],
            live: ['pf_usersettings'],
            applied: ['create_usersettings_table' => true],
            prefix: 'pf_'
        )->execute([]);

        // Assert
        $this->assertArrayNotHasKey('unmanaged', $answer);
        $this->assertArrayNotHasKey('applied_but_missing', $answer);
        $this->assertStringContainsString('Every live table', $answer['verdict']);
    }

    /**
     * A schema-qualified name and its flattened form are one table.
     *
     * The same migration writes `authserver.user_twofactor`, and MySQL stores
     * `authserver_user_twofactor`.
     */
    public function testASchemaAndItsFlattenedFormAreOneTable(): void
    {
        // Act
        $answer = $this->probe(
            declared: ['authserver.user_twofactor' => ['create_user_twofactor_table']],
            live: ['authserver_user_twofactor'],
            applied: ['create_user_twofactor_table' => true]
        )->execute([]);

        // Assert
        $this->assertArrayNotHasKey('unmanaged', $answer);
    }

    /**
     * A schema-qualified table and a bare legacy one of the same name are **not** one table.
     *
     * This is the bug the tool was written for: `authserver.permissions` created by a migration
     * and a legacy unprefixed `permissions` that nothing creates, queried by code that predates
     * the schema. Treating them as one spelling hides exactly the problem.
     */
    public function testASchemaTableAndALegacyOneAreNotTheSameTable(): void
    {
        // Act
        $answer = $this->probe(
            declared: ['authserver.permissions' => ['create_permissions_table']],
            live: ['authserver_permissions', 'permissions'],
            applied: ['create_permissions_table' => true]
        )->execute([]);

        // Assert
        $this->assertSame(['permissions'], $answer['unmanaged']);
    }

    /**
     * A table nothing creates is reported, and the note says why it matters here and not now.
     */
    public function testATableNothingCreatesIsReported(): void
    {
        // Act
        $answer = $this->probe(declared: [], live: ['handmade'])->execute([]);

        // Assert
        $this->assertSame(['handmade'], $answer['unmanaged']);
        $this->assertStringContainsString('fresh installation', $answer['unmanaged_note']);
    }

    /**
     * A migration that ran without leaving its table is the alarming finding, kept apart.
     *
     * From "not created yet", which is ordinary. Every future run considers the first one done.
     */
    public function testAppliedWithoutATableIsSeparateFromPending(): void
    {
        // Act
        $answer = $this->probe(
            declared: [
                'gone'    => ['create_gone_table'],
                'pending' => ['create_pending_table'],
            ],
            live: [],
            applied: ['create_gone_table' => true]
        )->execute([]);

        // Assert
        $this->assertSame('gone', $answer['applied_but_missing'][0]['table']);
        $this->assertSame('pending', $answer['not_created_yet'][0]['table']);
        $this->assertStringContainsString('applied without their table', $answer['verdict']);
    }

    /**
     * Pending migrations alone are not drift, and the verdict says so.
     */
    public function testPendingMigrationsAloneAreNotDrift(): void
    {
        // Act
        $answer = $this->probe(declared: ['later' => ['create_later_table']], live: [])->execute([]);

        // Assert
        $this->assertStringContainsString('Nothing has drifted', $answer['verdict']);
    }

    /**
     * Asked about one table, it answers the question somebody actually has.
     */
    public function testOneTableAnswersTheFourStates(): void
    {
        // Arrange
        $probe = $this->probe(
            declared: ['posts' => ['create_posts_table'], 'gone' => ['create_gone_table']],
            live: ['posts', 'handmade'],
            applied: ['create_posts_table' => true, 'create_gone_table' => true]
        );

        // Assert
        $this->assertStringContainsString('has run here', $probe->execute(['table' => 'posts'])['verdict']);
        $this->assertStringContainsString('made it by hand', $probe->execute(['table' => 'handmade'])['verdict']);
        $this->assertStringContainsString('future run will consider it done',
            $probe->execute(['table' => 'gone'])['verdict']);
        $this->assertStringContainsString('does not have', $probe->execute(['table' => 'nothing'])['verdict']);
    }

    /**
     * A table that exists but whose migration has not run here is its own answer.
     *
     * Something else created it — a hand-run SQL file, a restored dump — and it may not match
     * what the migration would have made.
     */
    public function testATableWhoseMigrationDidNotRunIsFlagged(): void
    {
        // Act
        $answer = $this->probe(
            declared: ['posts' => ['create_posts_table']],
            live: ['posts'],
            applied: []
        )->execute(['table' => 'posts']);

        // Assert
        $this->assertTrue($answer['exists']);
        $this->assertSame([], $answer['applied']);
        $this->assertStringContainsString('something else made it', $answer['verdict']);
    }

    /**
     * The slug is the one the history records, not the file's name.
     *
     * `MigrationRunner` stores `create_users_table`; the file is
     * `2026_08_29_000001_create_users_table.php`. Compared as filenames, nothing matches and
     * every applied migration reads as "has not run here" — a confidently wrong answer about
     * the one thing this tool exists to say.
     */
    public function testTheSlugMatchesWhatTheHistoryRecords(): void
    {
        // Act & Assert
        $probe = $this->probe();
        $this->assertSame(
            'create_users_table',
            $probe->probeSlugOf('/x/2026_08_29_000001_create_users_table.php')
        );
        $this->assertSame('handwritten', $probe->probeSlugOf('/x/Handwritten.php'));
    }

    /**
     * A database that will not answer is an empty side, not an exception.
     *
     * A drift tool that fatals on a broken connection fails at the only moment somebody would
     * be running it.
     */
    public function testADatabaseThatWillNotAnswerIsEmpty(): void
    {
        // Arrange
        $db = new class {
            public bool $connected = true;
            public string $type = 'mysql';
            public string $prefix = '';

            public function query(string $sql): mixed
            {
                throw new \RuntimeException('server has gone away');
            }
        };

        $app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
        $app->database = $db;

        $tool = new class ($app) extends SchemaDriftTool {
            /** @return list<string> */
            public function probeLive(): array { return $this->liveTables(); }

            /** @return array<string, true> */
            public function probeApplied(): array { return $this->appliedSlugs(); }
        };

        // Assert
        $this->assertSame([], $tool->probeLive());
        $this->assertSame([], $tool->probeApplied());
    }

    /**
     * The migration files are found where the loader says they are.
     *
     * Recursively, because a project keeps them in `framework/<feature>/` and `app/`
     * subdirectories — a flat scan finds the handful at the top and reports every table below
     * as created by nothing.
     */
    public function testTheMigrationFilesAreFoundRecursively(): void
    {
        // Act
        $files = (new class ($this->emptyApplication()) extends SchemaDriftTool {
            /** @return list<string> */
            public function probeFiles(): array { return $this->migrationFiles(); }
        })->probeFiles();

        // Assert
        $this->assertGreaterThan(20, count($files), 'this repository ships dozens');

        foreach ($files as $file) {
            $this->assertStringEndsWith('.php', $file);
        }

        $nested = array_filter($files, static fn (string $f): bool => str_contains($f, '/framework/'));
        $this->assertNotSame([], $nested, 'the loader nests them one directory deep');
    }

    /**
     * A file that cannot be read contributes nothing rather than stopping the scan.
     */
    public function testAnUnreadableMigrationIsSkipped(): void
    {
        // Act
        $declared = (new class ($this->emptyApplication()) extends SchemaDriftTool {
            /** @return list<string> */
            protected function migrationFiles(): array { return ['/no/such/migration.php']; }

            /** @return array<string, list<string>> */
            public function probeDeclared(): array { return $this->declaredTables(); }
        })->probeDeclared();

        // Assert
        $this->assertSame([], $declared);
    }

    /**
     * Two migrations that both name a table are both recorded against it.
     *
     * One creates it and a later one adds an index; asked which migration is responsible, both
     * are the honest answer.
     */
    public function testTwoMigrationsNamingOneTableAreBothRecorded(): void
    {
        // Arrange
        $directory = sys_get_temp_dir() . '/drift-' . bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents(
            $directory . '/2026_08_29_000001_create_posts_table.php',
            "<?php\n\$schema->createTable('posts', function (\$t) {});\n"
        );
        file_put_contents(
            $directory . '/2026_08_29_000002_index_posts_table.php',
            "<?php\nif (\$schema->hasTable('posts')) { }\n"
        );

        try {
            // Act
            $declared = (new class ($this->emptyApplication(), $directory) extends SchemaDriftTool {
                public function __construct(Application $app, private string $directory)
                {
                    parent::__construct($app);
                }

                /** @return list<string> */
                protected function migrationFiles(): array
                {
                    return (array) glob($this->directory . '/*.php');
                }

                /** @return array<string, list<string>> */
                public function probeDeclared(): array { return $this->declaredTables(); }
            })->probeDeclared();

            // Assert
            $this->assertSame(
                ['create_posts_table', 'index_posts_table'],
                $declared['posts']
            );
        } finally {
            exec('rm -rf ' . escapeshellarg($directory));
        }
    }

    /**
     * Without a database there is nothing to compare against, and it says so.
     */
    public function testWithoutADatabaseThereIsNothingToCompare(): void
    {
        // Arrange
        $app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
        $app->database = null;

        // Assert
        $this->assertArrayHasKey('error', (new SchemaDriftTool($app))->execute([]));
    }

    private function emptyApplicationWithDb(): Application
    {
        $db = new class {
            public bool $connected = true;
            public string $type = 'mysql';
            public string $prefix = '';
        };

        $app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
        $app->database = $db;

        return $app;
    }

    private function emptyApplication(): Application
    {
        $app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
        $app->database = null;

        return $app;
    }

    /**
     * @param array<string, list<string>> $declared
     * @param list<string>                $live
     * @param array<string, true>         $applied
     */
    private function probe(
        array $declared = [],
        array $live = [],
        array $applied = [],
        string $prefix = ''
    ): object {
        $db = new class ($prefix) {
            public bool $connected = true;
            public string $type = 'mysql';

            public function __construct(public string $prefix) {}
        };

        $app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
        $app->database = $db;

        return new class ($app, $declared, $live, $applied) extends SchemaDriftTool {
            public function __construct(
                Application $app,
                private array $declared,
                private array $live,
                private array $applied
            ) {
                parent::__construct($app);
            }

            protected function declaredTables(): array { return $this->declared; }

            protected function liveTables(): array { return $this->live; }

            protected function appliedSlugs(): array { return $this->applied; }

            /** @return list<string> */
            public function probeTablesIn(string $source): array { return $this->tablesIn($source); }

            public function probeSlugOf(string $path): string { return $this->slugOf($path); }
        };
    }
}
