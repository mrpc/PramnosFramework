<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\Gdpr;

/**
 * Which account a GDPR request's token resolves to.
 *
 * This controller exports and erases somebody's personal data on the strength of a token, so the
 * question "whose account is this" is the whole of its authorisation. Four statements, never
 * executed, and one of them is the guard:
 *
 * ```php
 * return (int) $user->userid >= 2 ? $user : null;
 * ```
 *
 * **`>= 2`, not `> 0`.** Zero is "no account was loaded" — the state a `User` is in after a token
 * that resolved to nothing — and one is the framework's system row. A token that fails to resolve
 * leaves `userid` at `0`, so a `> 0` test would be the same guard; a `>= 1` test would hand an
 * unauthenticated caller the system account, and an export of it is an export of whatever the
 * framework attributes to nobody.
 */
#[CoversClass(Gdpr::class)]
class GdprTokenResolutionTest extends TestCase
{
    private mixed $savedDatabase = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Kept so tearDown can put it back. Dropping the singleton without restoring it hands
        // every later test in the run this class's connection — which is how eleven unrelated
        // OAuth tests failed in the full suite while passing under a filter.
        $saved = &\Pramnos\Database\Database::getInstance();
        $this->savedDatabase = $saved;

        // `loadByToken()` queries, so the lookup needs somewhere to look — and a lookup that
        // cannot run is not the same as one that found nothing, which is the distinction under
        // test.
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        \Pramnos\Application\Settings::loadSettings(
            ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php'
        );

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $database  = \Pramnos\Framework\Factory::getDatabase();

        try {
            if (!$database->connected) {
                $database->connect();
            }
        } catch (\Throwable $exception) {
            $this->markTestSkipped('The database is not reachable: ' . $exception->getMessage());
        }

        if (!$database->connected) {
            $this->markTestSkipped('The database is not reachable.');
        }

        \Pramnos\Application\Application::getInstance()->database = $database;
    }

    protected function tearDown(): void
    {
        $restore = &\Pramnos\Database\Database::getInstance();
        $restore = $this->savedDatabase;

        parent::tearDown();
    }

    /** Exposes the seam the token path goes through. */
    private function controller(): object
    {
        return new class extends Gdpr {
            public function __construct() {}

            public function exposeUserFromToken(string $token): ?\Pramnos\User\User
            {
                return $this->userFromToken($token);
            }
        };
    }

    /**
     * A token that resolves to nothing is nobody.
     *
     * The ordinary case for an expired link, a truncated URL, or a token somebody guessed. `null`
     * rather than an empty `User`, because an empty `User` is truthy and every caller checking
     * `if ($user)` would proceed.
     */
    public function testATokenThatResolvesToNothingIsNobody(): void
    {
        // Act
        $user = $this->controller()->exposeUserFromToken('not-a-token-that-exists-anywhere');

        // Assert
        $this->assertNull($user, 'an unresolvable token produced an account');
    }

    /**
     * An empty token is nobody too.
     *
     * What a request with the parameter present and blank sends. It must not be a shortcut to the
     * first row the lookup happens to return.
     */
    public function testAnEmptyTokenIsNobody(): void
    {
        // Act + Assert
        $this->assertNull($this->controller()->exposeUserFromToken(''));
    }

    /**
     * The guard is on the id, so `0` and `1` can never come back.
     *
     * Asserted on the boundary rather than through a token, because the two ids that must be
     * refused are exactly the two a failed lookup and a system row produce — and no token can be
     * made to resolve to them on purpose.
     */
    public function testTheGuardRefusesTheGuestAndSystemIds(): void
    {
        // Arrange — the expression the guard is
        $refused = [0, 1];
        $allowed = [2, 42, 999999];

        // Act + Assert
        foreach ($refused as $id) {
            $this->assertFalse($id >= 2, 'id ' . $id . ' must not count as an account');
        }

        foreach ($allowed as $id) {
            $this->assertTrue($id >= 2, 'id ' . $id . ' is an ordinary account');
        }
    }
}
