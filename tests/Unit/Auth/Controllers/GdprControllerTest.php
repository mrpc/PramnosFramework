<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\Gdpr;

/**
 * Unit tests for Pramnos\Auth\Controllers\Gdpr.
 *
 * Gdpr is a controller that depends on $_SESSION, HTTP headers and the database.
 * We bypass the constructor (which calls parent::__construct() and instantiates
 * WebhookService) via ReflectionClass::newInstanceWithoutConstructor() and test
 * the private pure helpers in isolation.
 *
 * Tests cover:
 *   - resolveActor(): pure session/header reading with no DB required.
 *   - readJsonBody(): JSON decoding from a string (empty input path).
 */
#[CoversClass(Gdpr::class)]
class GdprControllerTest extends TestCase
{
    private Gdpr $gdpr;

    /**
     * Create the Gdpr object without running the constructor so that
     * WebhookService / Application boot / DB connections are never triggered.
     */
    protected function setUp(): void
    {
        // Arrange – bypass constructor
        $rc         = new \ReflectionClass(Gdpr::class);
        $this->gdpr = $rc->newInstanceWithoutConstructor();

        // Clean superglobal state
        $_SESSION = [];
        $_GET     = [];
        $_POST    = [];
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_GET     = [];
        $_POST    = [];
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    // ── resolveActor() ────────────────────────────────────────────────────────

    /**
     * When neither a Bearer token header nor session data are present,
     * resolveActor() must return [null, false].
     *
     * This covers the session-auth else-branch of resolveActor() (lines ~362-365)
     * where both user_id and is_admin fall back to null/false.
     */
    public function testResolveActorReturnsNullFalseWhenNoAuth(): void
    {
        // Arrange — no Authorization header, empty session

        // Act
        $result = $this->callPrivate('resolveActor');

        // Assert
        $this->assertSame([null, false], $result,
            'No session and no Bearer token must yield [null, false]');
    }

    /**
     * A session identity is read through the framework's own current-user
     * lookup, not from loose session keys.
     *
     * The three keys this used to read — `$_SESSION['user_id']`, a nested
     * `$_SESSION['user']` **array**, and `$_SESSION['is_admin']` — describe a
     * session shape the framework does not produce. `$_SESSION['user']` holds a
     * User *object*, and nothing anywhere sets `is_admin`. So every
     * session-authenticated request resolved to null and was refused, and the
     * admin branch was unreachable. These tests pinned that behaviour in place;
     * they now pin the working contract instead.
     */
    public function testResolveActorIgnoresSessionKeysTheFrameworkNeverSets(): void
    {
        // Arrange — exactly the shape the old implementation believed in
        $_SESSION['user_id']  = '42';
        $_SESSION['is_admin'] = 1;

        // Act
        [$userId, $isAdmin] = $this->callPrivate('resolveActor');

        // Assert — no signed-in user means no actor, whatever these keys say
        $this->assertNull($userId, 'a loose session key is not an identity');
        $this->assertFalse($isAdmin, 'and it certainly is not an admin');
    }

    /**
     * A nested `user` array is not an identity either.
     *
     * The framework stores a User object under that key. An array shaped like
     * one comes from somewhere else, and treating it as a signed-in user would
     * let anything that can write to the session name its own user id.
     */
    public function testResolveActorRejectsANestedUserArray(): void
    {
        // Arrange
        $_SESSION['user'] = ['userid' => 7, 'username' => 'alice'];

        // Act
        [$userId, $isAdmin] = $this->callPrivate('resolveActor');

        // Assert
        $this->assertNull($userId);
        $this->assertFalse($isAdmin);
    }

    /**
     * Admin is decided by `usertype >= 90`, the framework's admin tier.
     *
     * That is what the Users, Applications and Permissions admin controllers
     * all require. The previous implementation read a boolean `is_admin`
     * column that exists in no migration and no schema.
     */
    public function testAdminIsDecidedByUserType(): void
    {
        // Arrange
        $admin = new \stdClass();
        $admin->userid   = 5;
        $admin->usertype = 90;

        $plain = new \stdClass();
        $plain->userid   = 5;
        $plain->usertype = 10;

        $method = new \ReflectionMethod(Gdpr::class, 'isAdmin');

        // Act + Assert
        $this->assertTrue($method->invoke($this->gdpr, $admin));
        $this->assertFalse($method->invoke($this->gdpr, $plain), 'below the admin tier');
    }

    /**
     * When an Authorization header is present but the Bearer token format is
     * incorrect (e.g. "Basic …"), resolveActor() falls through to session auth
     * (no DB involved) and returns [null, false] when the session is also empty.
     *
     * This covers the `if ($authHeader && preg_match(...))` false branch when
     * the header scheme is not "Bearer" (line ~340).
     */
    public function testResolveActorFallsBackToSessionWhenHeaderIsNotBearer(): void
    {
        // Arrange — Basic auth header, no session
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';

        // Act
        $result = $this->callPrivate('resolveActor');

        // Assert — falls back to empty session → [null, false]
        $this->assertSame([null, false], $result,
            'A non-Bearer Authorization header must not match the Bearer preg_match');
    }

    // ── readJsonBody() ────────────────────────────────────────────────────────

    /**
     * readJsonBody() must return an empty array when php://input is empty.
     *
     * This covers the `if ($raw === false || $raw === '') return []` early-exit
     * in readJsonBody() (lines ~414-416).
     *
     * We cannot inject actual php://input content in a unit test, but we can
     * verify that invoking it in a CLI test context (where php://input is empty)
     * always returns [].
     */
    public function testReadJsonBodyReturnsEmptyArrayWhenInputIsEmpty(): void
    {
        // Act — in CLI context php://input is empty
        $result = $this->callPrivate('readJsonBody');

        // Assert
        $this->assertSame([], $result,
            'readJsonBody() must return [] when php://input is empty');
    }

    // ── VALID_REQUEST_TYPES constant ──────────────────────────────────────────

    /**
     * The VALID_REQUEST_TYPES constant must include 'export', 'delete', and
     * 'portability' — these are the three GDPR-mandated operations.
     *
     * Verifying the constant prevents accidental typos in future edits.
     * This covers the constant declaration line ~31.
     */
    public function testValidRequestTypesContainsExpectedValues(): void
    {
        // Act — read the constant via reflection (it is private)
        $rc    = new \ReflectionClass(Gdpr::class);
        $types = $rc->getConstant('VALID_REQUEST_TYPES');

        // Assert
        $this->assertContains('export',      $types);
        $this->assertContains('delete',      $types);
        $this->assertContains('portability', $types);
    }

    /**
     * The VALID_REVOKE_REASONS constant must contain the four expected strings.
     *
     * This covers the constant declaration on lines ~32-35.
     */
    public function testValidRevokeReasonsContainsExpectedValues(): void
    {
        // Act
        $rc      = new \ReflectionClass(Gdpr::class);
        $reasons = $rc->getConstant('VALID_REVOKE_REASONS');

        // Assert
        $this->assertContains('user_revoked',       $reasons);
        $this->assertContains('admin_revoked',       $reasons);
        $this->assertContains('gdpr_deletion',       $reasons);
        $this->assertContains('security_violation',  $reasons);
    }

    // ── Private reflection helper ─────────────────────────────────────────────

    /**
     * Call a private method on $this->gdpr via reflection.
     */
    private function callPrivate(string $method, mixed ...$args): mixed
    {
        $rm = new \ReflectionMethod(Gdpr::class, $method);
        return $rm->invoke($this->gdpr, ...$args);
    }
}
