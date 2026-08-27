<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\PasswordHash;

/**
 * `Pramnos\Auth\PasswordHash` — the one place a secret becomes a hash.
 *
 * The knob this class exposes lowers the cost of bcrypt, so the tests that matter most are
 * the ones proving it **cannot be lowered by accident**: a typo in an environment variable
 * must not silently weaken every password in a deployment. Every rejection path is
 * asserted, and each one falls back to PHP's default rather than to something cheap.
 *
 * The suite itself runs with `PRAMNOS_BCRYPT_COST=4` (set in `tests/bootstrap.php`), so
 * each test here restores the environment it found — the alternative is exactly the class
 * of cross-test leak that cost this project 138 failures in two separate incidents.
 */
class PasswordHashTest extends TestCase
{
    /** @var string|false The cost the test run was started with */
    private string|false $originalCost;

    /**
     * Remembers the run's own cost setting.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->originalCost = getenv(PasswordHash::COST_ENV);
    }

    /**
     * Puts the run's cost setting back, whatever the test did to it.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if ($this->originalCost === false) {
            putenv(PasswordHash::COST_ENV);
        } else {
            putenv(PasswordHash::COST_ENV . '=' . $this->originalCost);
        }
    }

    /**
     * With no cost configured, the options are empty.
     *
     * Empty rather than an explicit default cost: with nothing configured this must behave
     * *exactly* as the bare `password_hash($plain, PASSWORD_DEFAULT)` calls it replaced,
     * including whatever PHP decides the default is in a future version. An explicit
     * `['cost' => 12]` would freeze today's answer into the framework.
     */
    public function testNoConfiguredCostMeansNoOptions(): void
    {
        // Arrange
        putenv(PasswordHash::COST_ENV);

        // Act & Assert
        $this->assertSame([], PasswordHash::options());
    }

    /**
     * A valid cost is applied.
     *
     * Asserted through `password_get_info()` on a real hash rather than only on the
     * options array, because what matters is that the cost reached bcrypt.
     */
    public function testAValidCostIsApplied(): void
    {
        // Arrange
        putenv(PasswordHash::COST_ENV . '=5');

        // Act
        $options = PasswordHash::options();
        $info    = password_get_info(PasswordHash::make('secret'));

        // Assert
        $this->assertSame(['cost' => 5], $options);
        $this->assertSame(5, $info['options']['cost'] ?? null, 'The cost must reach bcrypt.');
    }

    /**
     * The extremes of bcrypt's range are accepted.
     *
     * 4 and 31 are valid, so an off-by-one in the bounds check would reject a legitimate
     * setting — and cost 4 in particular is the one the test suite depends on.
     *
     * @param string $configured The value as it appears in the environment
     * @param int    $expected   The cost that should reach bcrypt
     * @return void
     */
    #[DataProvider('boundaryCosts')]
    public function testTheEndsOfTheValidRangeAreAccepted(string $configured, int $expected): void
    {
        // Arrange
        putenv(PasswordHash::COST_ENV . '=' . $configured);

        // Act & Assert
        $this->assertSame(['cost' => $expected], PasswordHash::options());
    }

    /**
     * The first and last costs bcrypt accepts.
     *
     * 31 is not exercised through an actual hash: at cost 31 that would take longer than
     * the age of this project.
     *
     * @return array<string, array{string, int}>
     */
    public static function boundaryCosts(): array
    {
        return [
            'lowest valid'  => ['4', 4],
            'highest valid' => ['31', 31],
        ];
    }

    /**
     * Anything invalid is ignored, and falls back to the default cost.
     *
     * This is the security-relevant assertion in the class. A typo in a deployment's
     * environment must not be able to weaken hashing, and it must not raise either: a
     * fatal here would stop people logging in, which is a worse outcome than a hash at the
     * default cost — and the default cost is never the unsafe answer.
     *
     * @param string $configured The invalid value
     * @return void
     */
    #[DataProvider('invalidCosts')]
    public function testAnythingInvalidFallsBackToTheDefault(string $configured): void
    {
        // Arrange
        putenv(PasswordHash::COST_ENV . '=' . $configured);

        // Act & Assert
        $this->assertSame(
            [],
            PasswordHash::options(),
            'An unusable cost must leave PHP to choose, not produce a weak hash.'
        );
    }

    /**
     * Values that must not be honoured.
     *
     * `3` and `32` sit either side of bcrypt's range; `1` is the value a careless "make it
     * fast" edit would reach for; the empty string is what an unset variable in a `.env`
     * file looks like.
     *
     * @return array<string, array{string}>
     */
    public static function invalidCosts(): array
    {
        return [
            'below the range'  => ['3'],
            'far below'        => ['1'],
            'above the range'  => ['32'],
            'not a number'     => ['fast'],
            'empty'            => [''],
            'nearly a number'  => ['4x'],
        ];
    }

    /**
     * A hash made here verifies with the plain `password_verify()` an application uses.
     *
     * The whole trade depends on this: the cost changes, the algorithm does not, so a hash
     * written by a test at cost 4 and one written in production at cost 12 are the same
     * kind of object. If this failed, lowering the cost in the suite would be testing
     * something other than what ships.
     */
    public function testAHashItMakesIsAnOrdinaryVerifiableHash(): void
    {
        // Arrange
        putenv(PasswordHash::COST_ENV . '=4');

        // Act
        $hash = PasswordHash::make('correct horse');

        // Assert
        $this->assertTrue(password_verify('correct horse', $hash));
        $this->assertFalse(password_verify('wrong horse', $hash));
        $this->assertSame('bcrypt', password_get_info($hash)['algoName']);
    }

    /**
     * The same secret hashed twice gives different hashes.
     *
     * Proves the salt is still per-hash. A "cheaper hashing" change that quietly dropped
     * to something deterministic would pass every other test in this class.
     */
    public function testItSaltsEachHash(): void
    {
        // Arrange
        putenv(PasswordHash::COST_ENV . '=4');

        // Act
        $first  = PasswordHash::make('same secret');
        $second = PasswordHash::make('same secret');

        // Assert
        $this->assertNotSame($first, $second);
        $this->assertTrue(password_verify('same secret', $first));
        $this->assertTrue(password_verify('same secret', $second));
    }

    // ── Which scheme a stored hash is in ─────────────────────────────────────────

    /**
     * A hash this class writes for an account reports the preferred scheme.
     *
     * `verify()` returns the *name* of the scheme that matched rather than a boolean,
     * which is what makes rehash-on-login possible: the caller cannot know whether to
     * rewrite a row without knowing what it is holding.
     */
    public function testItReportsThePreferredSchemeForItsOwnHashes(): void
    {
        // Arrange
        putenv(PasswordHash::COST_ENV . '=4');

        // Act
        $hash = PasswordHash::make('correct horse', 42);

        // Assert
        $this->assertSame(PasswordHash::PREFERRED, PasswordHash::verify('correct horse', $hash, 42));
        $this->assertNull(PasswordHash::verify('wrong horse', $hash, 42));
        $this->assertFalse(PasswordHash::needsUpgrade(PasswordHash::SCHEME_HMAC, $hash));
    }

    /**
     * The whole password reaches the KDF, however long it is.
     *
     * This is the defect the HMAC pre-hash exists for. The scheme it replaced appended a
     * 32-character pepper to the plaintext, and bcrypt stops at 72 bytes — so everything
     * past the 40th character a user typed was discarded, and two long passwords sharing
     * a 40-character prefix verified against each other. Nothing reported it: both
     * passwords worked, which reads as success.
     */
    public function testALongPasswordIsNotTruncated(): void
    {
        // Arrange
        putenv(PasswordHash::COST_ENV . '=4');
        $prefix = str_repeat('a', 60);

        // Act
        $hash = PasswordHash::make($prefix . 'first ending', 42);

        // Assert
        $this->assertSame(
            PasswordHash::PREFERRED,
            PasswordHash::verify($prefix . 'first ending', $hash, 42)
        );
        $this->assertNull(
            PasswordHash::verify($prefix . 'second ending', $hash, 42),
            'a password sharing the first 60 characters must not verify'
        );
    }

    /**
     * A hash bound to one account does not verify for another.
     *
     * The pepper is derived from the user id, so the same password held by two accounts
     * is two different hashes — one leaked hash cannot be tested against every row.
     */
    public function testAHashIsBoundToItsAccount(): void
    {
        // Arrange
        putenv(PasswordHash::COST_ENV . '=4');

        // Act
        $hash = PasswordHash::make('same secret', 42);

        // Assert
        $this->assertSame(PasswordHash::PREFERRED, PasswordHash::verify('same secret', $hash, 42));
        $this->assertNull(PasswordHash::verify('same secret', $hash, 43));
    }

    /**
     * A plain bcrypt written by somebody else still verifies, and is named as legacy.
     *
     * Reported by an application that hashes its own passwords — `password_hash($plain,
     * PASSWORD_DEFAULT)`, no pepper — against a shared user table. Its accounts could not
     * pass the framework's own password step-up: the correct password was refused, with
     * nothing in the refusal to suggest that hashing was the reason. Two schemes coexist
     * in one table because either side may have created the row, so verification reads
     * both and says which one it found.
     */
    public function testAPlainHashFromAnotherWriterStillVerifies(): void
    {
        // Arrange — exactly what an application writing its own rows produces
        $foreign = password_hash('secret123', PASSWORD_DEFAULT, ['cost' => 4]);

        // Act
        $scheme = PasswordHash::verify('secret123', $foreign, 42);

        // Assert
        $this->assertSame(PasswordHash::SCHEME_PLAIN, $scheme);
        $this->assertNull(PasswordHash::verify('wrong', $foreign, 42));
        $this->assertTrue(
            PasswordHash::needsUpgrade($scheme, $foreign),
            'and it is worth rewriting on the next successful sign-in'
        );
    }

    /**
     * The pepper-suffix scheme this class replaced still verifies.
     *
     * Every account in every existing deployment is in it, so refusing it would lock out
     * every user at once. It verifies, and reports itself as due for an upgrade.
     */
    public function testThePreviousPepperSchemeStillVerifies(): void
    {
        // Arrange
        $legacy = password_hash('securepass' . PasswordHash::pepper(42), PASSWORD_DEFAULT, ['cost' => 4]);

        // Act
        $scheme = PasswordHash::verify('securepass', $legacy, 42);

        // Assert
        $this->assertSame(PasswordHash::SCHEME_PEPPER, $scheme);
        $this->assertTrue(PasswordHash::needsUpgrade($scheme, $legacy));
    }

    /**
     * A raw md5 verifies only when the caller asks for it.
     *
     * Off by default, because a deployment with no md5 rows must not accept a 32-character
     * hash as a password scheme at all. On when a caller knows it is migrating an old
     * table — and then only to rewrite the row immediately.
     */
    public function testMd5IsRefusedUnlessAskedFor(): void
    {
        // Arrange
        $ancient = md5('securepass');

        // Act & Assert
        $this->assertNull(PasswordHash::verify('securepass', $ancient, 42));
        $this->assertSame(
            PasswordHash::SCHEME_MD5,
            PasswordHash::verify('securepass', $ancient, 42, true)
        );
        $this->assertNull(PasswordHash::verify('wrong', $ancient, 42, true));
    }

    /**
     * An empty stored hash is never a match.
     *
     * `users.password` is empty for an account that has never had one — an invited user, a
     * row created by an administrator, a client-credentials account. `password_verify('',
     * '')` is false, so this would probably be safe anyway; "probably" is not the standard
     * for the one function that decides whether a password is correct.
     */
    public function testAnEmptyStoredHashNeverMatches(): void
    {
        // Act & Assert
        $this->assertNull(PasswordHash::verify('', '', 42));
        $this->assertNull(PasswordHash::verify('anything', '', 42));
    }

    /**
     * With no user id there is no pepper, and the hash is a plain one.
     *
     * That is the case at registration, before the row exists and the id is known. The
     * hash it writes has to be readable afterwards, when the id *is* known — so it is
     * written as, and recognised as, the plain scheme rather than as a peppered hash for
     * a user id of zero.
     */
    public function testWithoutAUserIdItWritesAPlainHash(): void
    {
        // Arrange
        putenv(PasswordHash::COST_ENV . '=4');

        // Act
        $hash = PasswordHash::make('no id yet');

        // Assert
        $this->assertSame(PasswordHash::SCHEME_PLAIN, PasswordHash::verify('no id yet', $hash, 42));
        $this->assertSame(PasswordHash::SCHEME_PLAIN, PasswordHash::verify('no id yet', $hash));
    }
}
