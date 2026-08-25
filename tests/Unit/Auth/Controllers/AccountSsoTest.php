<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\TestCase;

/**
 * `Account::sso()` — the single sign-on status page.
 *
 * WHAT: what the page hands its view for a signed-in and for a signed-out
 *       visitor.
 * WHY:  the view shipped in all three bundled themes with no controller able to
 *       render it, so this action is new and its contract with that view is the
 *       thing to hold. The page is public, and the two branches it has are the
 *       only two it may have: a signed-out visitor must not be handed a user
 *       object or a list of applications, and the query for those applications
 *       must not run at all for them.
 */
class AccountSsoTest extends TestCase
{
    private SsoAccount $c;

    protected function setUp(): void
    {
        $this->c = new SsoAccount(null);
    }

    /**
     * A signed-out visitor gets the negative answer and nothing else.
     *
     * `activeApps` empty is a contract with the view, which iterates it without
     * checking. `appQueries` empty is the one that matters: an unauthenticated
     * request must not reach the tokens table.
     */
    public function testASignedOutVisitorIsToldSoAndNothingIsQueried(): void
    {
        // Arrange — no user
        $this->c->userId = null;

        // Act
        $out = $this->c->sso();

        // Assert
        $this->assertSame('VIEW:sso', $out);
        $this->assertFalse($this->c->view->props['isLoggedIn']);
        $this->assertNull($this->c->view->props['user']);
        $this->assertSame([], $this->c->view->props['activeApps']);
        $this->assertSame([], $this->c->appQueries, 'a guest must not query authorized applications');
    }

    /**
     * A signed-in visitor gets their identity and their authorized applications.
     *
     * The application list is looked up for their id and no other — asserted on
     * the recorder rather than on the result, so a query built with the wrong id
     * fails here instead of showing somebody else's applications.
     */
    public function testASignedInVisitorGetsTheirOwnApplications(): void
    {
        // Arrange
        $this->c->userId = 7;
        $this->c->apps   = [['name' => 'Acme CRM', 'website_url' => 'https://crm.example.com']];

        // Act
        $out = $this->c->sso();

        // Assert
        $this->assertSame('VIEW:sso', $out);
        $this->assertTrue($this->c->view->props['isLoggedIn']);
        $this->assertSame([7], $this->c->appQueries);
        $this->assertSame('Acme CRM', $this->c->view->props['activeApps'][0]['name']);
    }

    /**
     * The page is a public action, and titled.
     *
     * Public because the useful half of it is the answer given to somebody who is
     * not signed in — that is what another application sends them here to find
     * out.
     */
    public function testThePageIsPublicAndTitled(): void
    {
        // Act
        $this->c->sso();

        // Assert
        $this->assertContains('sso', $this->c->actions);
        $this->assertNotContains('sso', $this->c->actions_auth);
        $this->assertSame('Single sign-on', $this->c->doc->title);
    }
}

/**
 * Registration harness, extended with the authorized-applications boundary.
 *
 * Reuses `RegisteringAccount` because the view, document, redirect and
 * current-user seams are the same ones this action needs; only the application
 * lookup is new.
 */
class SsoAccount extends RegisteringAccount
{
    /** @var list<array<string, mixed>> What the lookup should return */
    public array $apps = [];

    /** @var list<int> User ids the application lookup was asked about */
    public array $appQueries = [];

    protected function getAuthorizedApplications(int $userId): array
    {
        $this->appQueries[] = $userId;
        return $this->apps;
    }
}
