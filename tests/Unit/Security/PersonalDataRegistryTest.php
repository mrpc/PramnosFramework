<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Security\PersonalDataRegistry;

/**
 * What a diagnostic tool may hand back, and what it may not.
 *
 * This is a denial list, which means the default answer for an unknown table is
 * «readable». That choice is deliberate and is argued on the class; what it makes
 * load-bearing is everything below — the framework's own defaults have to be
 * present without anybody loading them, an application's declaration has to
 * survive a table prefix, and the column half has to catch a table nobody
 * classified at all.
 */
#[CoversClass(PersonalDataRegistry::class)]
class PersonalDataRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        PersonalDataRegistry::reset();
    }

    protected function tearDown(): void
    {
        PersonalDataRegistry::reset();
    }

    /**
     * The framework's own tables are denied without anybody calling a loader.
     *
     * The failure this prevents is silent and total: a boot path that forgets to
     * load the registry would otherwise answer «not personal» for `users`, and
     * nothing anywhere would say so.
     */
    public function testFrameworkDefaultsApplyWithoutBeingLoaded(): void
    {
        // Assert — no registerTable(), no loadFromConfig(), nothing
        $this->assertTrue(PersonalDataRegistry::isPersonalTable('users'));
        $this->assertTrue(PersonalDataRegistry::isPersonalTable('usertokens'));
        $this->assertTrue(PersonalDataRegistry::isPersonalColumn('password'));
    }

    /**
     * A table nobody has heard of is readable. That is the denial list's contract,
     * stated as a test so that changing it has to be a decision.
     */
    public function testAnUndeclaredTableIsReadable(): void
    {
        // Assert
        $this->assertFalse(PersonalDataRegistry::isPersonalTable('images'));
    }

    /**
     * A declaration matches through a table prefix.
     *
     * An installation with `prefix = pramnos_` stores `pramnos_users`. A
     * declaration naming `users` has to cover it, because the prefix is a
     * property of the installation and not of what the table holds — and nobody
     * writing app.php should have to repeat it.
     */
    public function testAPrefixedTableStillMatches(): void
    {
        // Assert
        $this->assertTrue(PersonalDataRegistry::isPersonalTable('pramnos_users'));
    }

    /**
     * `#PREFIX#users` is `users`.
     *
     * Framework SQL is written against the placeholder, not the resolved name, so
     * a scan reading a statement sees `#PREFIX#users` — and a check that did not
     * know that would hand back the rows of every framework table named the way
     * the framework names them.
     */
    public function testThePrefixPlaceholderIsNotPartOfTheName(): void
    {
        // Assert
        $this->assertTrue(PersonalDataRegistry::isPersonalTable('#PREFIX#users'));
    }

    /**
     * A schema-qualified name is compared on the bare name.
     *
     * `authserver.twofactor_attempts` and `twofactor_attempts` are the same table
     * on PostgreSQL and MySQL respectively, and a query may name either.
     */
    public function testASchemaQualifiedNameMatches(): void
    {
        // Assert
        $this->assertTrue(PersonalDataRegistry::isPersonalTable('authserver.user_consents'));
        $this->assertTrue(PersonalDataRegistry::isPersonalTable('"public"."users"'));
    }

    /**
     * The column half is what covers a table nobody classified.
     *
     * `billing_email` in an application's own `invoices` table is withheld
     * although no declaration mentions either — which is the whole point of
     * matching column names wherever they appear.
     */
    public function testColumnNamesMatchOnBothEnds(): void
    {
        // Assert
        $this->assertTrue(PersonalDataRegistry::isPersonalColumn('billing_email'));
        $this->assertTrue(PersonalDataRegistry::isPersonalColumn('email_verified'));
        $this->assertTrue(PersonalDataRegistry::isPersonalColumn('EMAIL'));
        // Not a tail or a head on a `_` boundary — `emailing` is not `email`
        $this->assertFalse(PersonalDataRegistry::isPersonalColumn('emailing'));
        $this->assertFalse(PersonalDataRegistry::isPersonalColumn('width'));
        $this->assertFalse(PersonalDataRegistry::isPersonalColumn(''));
    }

    /**
     * An application's app.php block adds to the framework's lists.
     *
     * Adding rather than replacing is the default because the framework's entries
     * are its own tables: an application that replaced them would be asserting it
     * knows better about `usertokens`, which it does not.
     */
    public function testConfigAddsToTheDefaults(): void
    {
        // Act
        PersonalDataRegistry::loadFromConfig(array(
            'tables'  => array('customers', 'support_tickets'),
            'columns' => array('tax_number'),
        ));

        // Assert
        $this->assertTrue(PersonalDataRegistry::isPersonalTable('customers'));
        $this->assertTrue(PersonalDataRegistry::isPersonalColumn('tax_number'));
        // and the framework's own survived
        $this->assertTrue(PersonalDataRegistry::isPersonalTable('users'));
    }

    /**
     * `replace` starts from nothing, including the framework's own entries.
     *
     * Rarely right, and tested so that it is at least honest: after a replace,
     * `users` is readable.
     */
    public function testReplaceDiscardsTheFrameworkDefaults(): void
    {
        // Act
        PersonalDataRegistry::loadFromConfig(array(
            'replace' => true,
            'tables'  => array('customers'),
        ));

        // Assert
        $this->assertTrue(PersonalDataRegistry::isPersonalTable('customers'));
        $this->assertFalse(PersonalDataRegistry::isPersonalTable('users'));
        $this->assertFalse(PersonalDataRegistry::isPersonalColumn('password'));
    }

    /**
     * Non-string entries are ignored rather than coerced.
     *
     * An app.php with a stray nested array must not register a table called
     * `Array`, and must not stop the entries either side of it from loading.
     */
    public function testMalformedConfigEntriesAreSkipped(): void
    {
        // Act
        PersonalDataRegistry::loadFromConfig(array(
            'tables'  => array('customers', array('nested'), 42, '  '),
            'columns' => array('tax_number', null),
        ));

        // Assert
        $this->assertTrue(PersonalDataRegistry::isPersonalTable('customers'));
        $this->assertTrue(PersonalDataRegistry::isPersonalColumn('tax_number'));
        $this->assertNotContains('', PersonalDataRegistry::tables());
    }

    /**
     * The lists can be read back, which is what a documentation or audit answer
     * needs — «what exactly is being withheld here».
     */
    public function testTheListsAreReadable(): void
    {
        // Act
        $tables  = PersonalDataRegistry::tables();
        $columns = PersonalDataRegistry::columns();

        // Assert
        $this->assertContains('users', $tables);
        $this->assertContains('password', $columns);
    }

    /**
     * Registering by hand works and is normalised the same way as a declaration.
     */
    public function testRegisteringByHand(): void
    {
        // Act
        PersonalDataRegistry::registerTable('  Public.Customers  ');
        PersonalDataRegistry::registerColumn('  Tax_Number  ');

        // Assert
        $this->assertTrue(PersonalDataRegistry::isPersonalTable('customers'));
        $this->assertTrue(PersonalDataRegistry::isPersonalColumn('tax_number'));
    }
}
