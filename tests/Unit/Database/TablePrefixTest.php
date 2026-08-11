<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Model;
use Pramnos\Database\SchemaBuilder;

/**
 * A schema builder over a connection whose prefix and driver the test chooses.
 */
class PrefixProbeSchema extends SchemaBuilder
{
    /**
     * @param string $prefix Configured prefix, as Database exposes it
     * @param bool   $mysql  Whether to answer as MySQL
     */
    public function __construct(string $prefix, private bool $mysql = false)
    {
        $this->db = new class ($prefix) {
            public function __construct(public string $prefix)
            {
            }
        };
        $this->capabilities = new class ($mysql) {
            public function __construct(private bool $mysql)
            {
            }

            public function isMySQL(): bool
            {
                return $this->mysql;
            }
        };
    }

    /** Expose the resolution under test. */
    public function resolve(string $table): string
    {
        return $this->resolveTable($table);
    }
}

/**
 * Covers what a table is called, across the two layers that used to disagree.
 *
 * Application code reads `#PREFIX#users`, which becomes `pramnos_users` on an
 * installation configured with a prefix. Migrations wrote `createTable('users')`,
 * which produced `users`. Same framework, same table, two names — invisible on
 * the default empty prefix, and total on any installation that sets one. Two of
 * the projects built on this framework do.
 *
 * The rule now: a plain name gets the prefix, once. Two things make "once" the
 * hard part — callers that resolve the name themselves and hand the result to
 * the builder, and models that declare a name with no token at all.
 */
class TablePrefixTest extends TestCase
{
    // ── The default: nothing changes ─────────────────────────────────────────

    /**
     * With no prefix configured, every name is left exactly as it was.
     *
     * This is what every existing installation and every test fixture runs, so
     * it is the assertion that says the change ships safely.
     */
    public function testAnEmptyPrefixChangesNothing(): void
    {
        // Arrange
        $schema = new PrefixProbeSchema('');

        // Act + Assert
        $this->assertSame('users', $schema->resolve('users'));
        $this->assertSame('users', $schema->resolve('#PREFIX#users'));
        $this->assertSame('authserver.roles', $schema->resolve('authserver.roles'));
    }

    // ── With a prefix ────────────────────────────────────────────────────────

    /**
     * A plain table name gets the prefix — the defect itself.
     */
    public function testAPlainNameIsPrefixed(): void
    {
        // Arrange
        $schema = new PrefixProbeSchema('pramnos_');

        // Act + Assert
        $this->assertSame('pramnos_users', $schema->resolve('users'));
    }

    /**
     * The token resolves to the same name, which is the point.
     *
     * `#PREFIX#users` in application code and `createTable('users')` in a
     * migration must mean one table.
     */
    public function testTheTokenAndThePlainNameAgree(): void
    {
        // Arrange
        $schema = new PrefixProbeSchema('pramnos_');

        // Act + Assert
        $this->assertSame(
            $schema->resolve('#PREFIX#users'),
            $schema->resolve('users'),
            'the two ways of naming a table must resolve to one name'
        );
    }

    /**
     * A name that already carries the prefix is not prefixed again.
     *
     * Not a nicety: six call sites in `Model` alone resolve the name themselves
     * and pass the *result* to the query builder — `getFullTableName()`
     * substitutes the token and hands `pramnos_users` to `from()`. Without this
     * the builder would look for `pramnos_pramnos_users`.
     */
    public function testAnAlreadyPrefixedNameIsNotPrefixedTwice(): void
    {
        // Arrange
        $schema = new PrefixProbeSchema('pramnos_');

        // Act + Assert
        $this->assertSame('pramnos_users', $schema->resolve('pramnos_users'));
    }

    /**
     * ...including when an alias is appended, as the listing helpers do.
     */
    public function testAnAliasedNameIsHandled(): void
    {
        // Arrange
        $schema = new PrefixProbeSchema('pramnos_');

        // Act + Assert — `from($this->getFullTableName() . ' a')`
        $this->assertSame('pramnos_users a', $schema->resolve('pramnos_users a'));
    }

    /**
     * On PostgreSQL a schema-qualified name is left alone.
     *
     * The schema is the namespace there. Prefixing inside it would rename tables
     * the framework addresses as `authserver.x` everywhere else.
     */
    public function testAQualifiedNameIsUntouchedOnPostgres(): void
    {
        // Arrange
        $schema = new PrefixProbeSchema('pramnos_');

        // Act + Assert
        $this->assertSame('authserver.roles', $schema->resolve('authserver.roles'));
    }

    /**
     * On MySQL it is flattened, and then prefixed — the prefix is the only
     * namespace MySQL has here.
     */
    public function testAQualifiedNameIsFlattenedAndPrefixedOnMysql(): void
    {
        // Arrange
        $schema = new PrefixProbeSchema('pramnos_', true);

        // Act + Assert
        $this->assertSame('pramnos_authserver_roles', $schema->resolve('authserver.roles'));
    }

    /**
     * And flattening does not double-prefix either.
     */
    public function testAQualifiedNameIsNotPrefixedTwiceOnMysql(): void
    {
        // Arrange
        $schema = new PrefixProbeSchema('pramnos_', true);

        // Act + Assert
        $this->assertSame(
            'pramnos_authserver_roles',
            $schema->resolve('pramnos_authserver.roles')
        );
    }

    // ── Model names ──────────────────────────────────────────────────────────

    /**
     * A model that declares a bare name gets the prefix.
     *
     * Six framework models do — `mails`, `messages`, `queueitems` and others.
     * They used that name in their own SQL and, once the builder started
     * prefixing, a different one through the builder. A model working through
     * one path and not the other is worse than one that fails outright.
     */
    public function testAModelWithABareTableNameIsNormalised(): void
    {
        // Act + Assert
        $this->assertSame('pramnos_mails', Model::resolveTableName('mails', 'pramnos_'));
    }

    /**
     * A model that uses the token resolves to the same name.
     */
    public function testAModelTokenResolvesToTheSameName(): void
    {
        // Act + Assert
        $this->assertSame(
            Model::resolveTableName('mails', 'pramnos_'),
            Model::resolveTableName('#PREFIX#mails', 'pramnos_')
        );
    }

    /**
     * With no prefix, both forms stay as they are.
     */
    public function testModelNamesAreUntouchedWithoutAPrefix(): void
    {
        // Act + Assert
        $this->assertSame('mails', Model::resolveTableName('mails', ''));
        $this->assertSame('mails', Model::resolveTableName('#PREFIX#mails', ''));
    }

    /**
     * A model name is never prefixed twice.
     */
    public function testAModelNameIsNotPrefixedTwice(): void
    {
        // Act + Assert
        $this->assertSame(
            'pramnos_mails',
            Model::resolveTableName('pramnos_mails', 'pramnos_')
        );
    }

    /**
     * A schema-qualified model name is left alone, as in the schema builder.
     */
    public function testAQualifiedModelNameIsUntouched(): void
    {
        // Act + Assert
        $this->assertSame(
            'authserver.roles',
            Model::resolveTableName('authserver.roles', 'pramnos_')
        );
    }

    /**
     * A model with no table declared at all stays that way.
     *
     * `null` means "work it out later" — `initTable()`, or the legacy path —
     * and turning it into the bare prefix would name a table that cannot exist.
     */
    public function testAnUndeclaredModelNameStaysNull(): void
    {
        // Act + Assert
        $this->assertNull(Model::resolveTableName(null, 'pramnos_'));
        $this->assertSame('', Model::resolveTableName('', 'pramnos_'));
    }
}
