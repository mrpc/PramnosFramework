<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;

/**
 * Deleting an OAuth client must take its tokens with it.
 *
 * `fk_usertokens_applicationid` was `ON DELETE SET NULL`. Delete a client and its tokens were not
 * removed — their `applicationid` became `NULL`, which is indistinguishable from a token that was
 * never issued through OAuth at all. So each one silently changed category and carried on
 * authenticating.
 *
 * Measured on a working installation before the change: **507 of 522 tokens had a null
 * `applicationid`, thirteen of them still active and unexpired** — thirteen live credentials
 * belonging to clients that had been deleted.
 *
 * `SET NULL` is a reasonable default for a column that annotates a row. `applicationid` does not
 * annotate: it answers *who may use this token, on whose behalf*. Removing the answer does not
 * retire the question, it makes it unanswerable while the token stays valid — and deleting a
 * client is the one action an operator takes precisely to stop it having access.
 *
 * Asserted against the migration rather than against a live constraint, because the rule is what
 * ships: an installation gets whatever this file declares.
 */
class TokenOutlivesItsClientTest extends TestCase
{
    private function migration(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 3)
            . '/database/migrations/framework/core/2026_08_31_000001_cascade_usertokens_application.php'
        );
    }

    /**
     * The constraint is recreated with `cascade`, not `set null`.
     */
    public function testTheClientsTokensGoWithIt(): void
    {
        // Act
        $migration = $this->migration();

        // Assert
        $this->assertStringContainsString("->onDelete('cascade')", $migration);
        $this->assertStringContainsString("->name('fk_usertokens_applicationid')", $migration);
    }

    /**
     * And it drops the old one first, because a delete rule cannot be altered.
     *
     * Neither PostgreSQL nor MySQL has an `ALTER CONSTRAINT` for `ON DELETE`. A migration that
     * only adds would silently do nothing where the constraint already exists — which is every
     * installation this matters on.
     */
    public function testItReplacesTheConstraintRatherThanAddingOne(): void
    {
        // Act
        $migration = $this->migration();

        // Assert
        $this->assertStringContainsString(
            "dropForeign('fk_usertokens_applicationid')", $migration
        );
        $this->assertLessThan(
            strpos($migration, "->onDelete('cascade')"),
            strpos($migration, "dropForeign('fk_usertokens_applicationid')"),
            'the drop has to come before the recreate'
        );
    }

    /**
     * `down()` restores the previous rule.
     *
     * A migration that cannot be reversed is one nobody dares run on a credentials table.
     */
    public function testItIsReversible(): void
    {
        // Arrange
        $migration = $this->migration();
        $down      = substr($migration, (int) strpos($migration, 'function down'));

        // Assert
        $this->assertStringContainsString("->onDelete('set null')", $down);
    }

    /**
     * Rows already detached are left alone, and that is deliberate.
     *
     * A token whose `applicationid` is already `NULL` cannot be traced to a client — the old rule
     * destroyed the reference rather than recording it. Nothing separates "issued by a client that
     * is gone" from "never an OAuth token", so a sweep would have to guess, and guessing here
     * revokes working credentials.
     */
    public function testItDoesNotSweepRowsThatAreAlreadyDetached(): void
    {
        // Act
        $migration = $this->migration();

        // Assert
        $this->assertStringNotContainsString('DELETE FROM', strtoupper($migration));
        $this->assertStringContainsString('applicationid` is already `NULL`', $migration,
            'the reason for leaving them has to be written down, or somebody adds the sweep'
        );
    }
}
