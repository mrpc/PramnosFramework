<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\NavItem;
use Pramnos\Application\NavRegistry;

/**
 * The navigation the framework registers for itself — 10 uncovered statements.
 *
 * Navigation here is not written into a template: it is a registry, filtered per visitor by
 * sign-in state, usertype, permission and enabled feature. Which makes the *registration* the
 * interesting half, because a link registered with the wrong gate is a link that appears to
 * somebody it should not, and nothing about the page it points at will stop them — the screen's own
 * check is a separate decision that may or may not exist.
 *
 * Two of these registrations encode a judgement rather than a fact, and those are the ones asserted
 * hardest:
 *
 * - **mass messages need usertype 90, where every other admin screen needs 80.** That screen mails
 *   everybody. The privilege to edit a record and the privilege to send a message to every account
 *   on the installation are not the same privilege, and the difference is one integer in one
 *   constructor call.
 * - **admin links go through the administration area**, not `sURL . $path`. Not only when the
 *   request is already inside the area: the public site header shows this same section, so its
 *   links have to lead *into* the area from outside. A bare link takes the visitor to the same
 *   screen in the public theme, with no sidebar and outside the area's usertype floor, and nothing
 *   on the page says anything happened.
 *
 * A feature-gated link is asserted absent as well as present, because "registered with a feature
 * tag" and "registered only when the feature is on" look identical from the inside and differ
 * entirely for an installation that has the feature switched off.
 */
#[CoversClass(Application::class)]
class DefaultNavItemsTest extends TestCase
{
    private object $application;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }

        NavRegistry::reset();

        $this->application = new class extends Application {
            public function __construct()
            {
            }
        };
    }

    protected function tearDown(): void
    {
        NavRegistry::reset();
    }

    /** The registered items, by id. @return array<string, NavItem> */
    private function registered(): array
    {
        return (array) (new \ReflectionProperty(NavRegistry::class, 'items'))->getValue();
    }

    private function item(string $id): ?NavItem
    {
        return $this->registered()[$id] ?? null;
    }

    // ── What is always there ──────────────────────────────────────────────────

    /**
     * Home, sign-in and the account link are registered with no feature at all.
     *
     * The floor every installation gets. A feature tag on any of them would make the site's own
     * front door disappear on a deployment that had not enabled something — and the sign-in link
     * vanishing is the failure nobody can report, because reporting it requires signing in.
     */
    public function testTheAlwaysVisibleLinksNeedNoFeature(): void
    {
        // Act
        $this->application->registerDefaultNavItems([]);

        // Assert
        foreach (['main.home', 'user.login', 'user.account'] as $id) {
            $item = $this->item($id);
            $this->assertNotNull($item, $id . ' is not registered without features');
            $this->assertNull($item->feature, $id . ' is gated on a feature it should not need');
        }
    }

    /**
     * The sign-in link is for guests and the account link is for signed-in visitors.
     *
     * The pair that has to be right in both directions: a `Login` link shown to somebody already
     * signed in reads as having been signed out, and `My Account` shown to a guest leads to a
     * redirect back to the form they were trying to avoid.
     */
    public function testTheSignInAndAccountLinksAreOppositelyGated(): void
    {
        // Act
        $this->application->registerDefaultNavItems([]);

        // Assert
        $this->assertTrue($this->item('user.login')->guestOnly, 'Login is offered to signed-in users');
        $this->assertFalse($this->item('user.login')->requireAuth);

        $this->assertTrue($this->item('user.account')->requireAuth, 'My Account is offered to guests');
        $this->assertFalse($this->item('user.account')->guestOnly);
    }

    // ── What a feature brings ─────────────────────────────────────────────────

    /**
     * A feature's links appear only when the feature is on.
     *
     * Both directions, because they look identical from the inside: an item registered
     * unconditionally *with* a feature tag is filtered out at display time and behaves almost the
     * same — until something reads the registry without passing the feature list, and then every
     * installation shows links to screens it does not have.
     */
    public function testAFeaturesLinksAppearOnlyWithTheFeature(): void
    {
        // Act — nothing enabled
        $this->application->registerDefaultNavItems([]);
        $without = array_keys($this->registered());

        NavRegistry::reset();

        // Act — messaging and queue enabled
        $this->application->registerDefaultNavItems(['messaging', 'queue']);
        $with = array_keys($this->registered());

        // Assert
        $this->assertNotContains('admin.mailtemplates', $without, 'a messaging screen without messaging');
        $this->assertNotContains('admin.massmessages', $without);
        $this->assertNotContains('admin.queue', $without, 'a queue screen without the queue feature');

        $this->assertContains('admin.mailtemplates', $with, 'messaging brought no template screen');
        $this->assertContains('admin.massmessages', $with);
        $this->assertContains('admin.queue', $with);
    }

    /**
     * Every feature-gated item carries the tag it was gated on.
     *
     * The registration decides whether the item exists; the tag decides whether it is *shown* on a
     * request whose feature list has changed since boot. Registering conditionally and forgetting
     * the tag leaves an item that survives a feature being switched off at runtime.
     */
    public function testEveryFeatureGatedItemAlsoCarriesItsTag(): void
    {
        // Act
        $this->application->registerDefaultNavItems(['messaging', 'queue']);

        // Assert
        $this->assertSame('messaging', $this->item('admin.mailtemplates')->feature);
        $this->assertSame('messaging', $this->item('admin.massmessages')->feature);
        $this->assertSame('queue', $this->item('admin.queue')->feature);
    }

    // ── The judgement calls ───────────────────────────────────────────────────

    /**
     * Mass messages need a higher usertype than the rest of the administration area.
     *
     * Because that screen mails everybody. The privilege to edit a record and the privilege to send
     * a message to every account on the installation are not the same privilege — and the whole
     * difference is one integer in one constructor call, which is exactly the kind of thing a later
     * tidy-up normalises away.
     */
    public function testMassMessagesNeedAHigherUsertypeThanTheRest(): void
    {
        // Act
        $this->application->registerDefaultNavItems(['messaging', 'queue']);

        // Assert
        $this->assertSame(
            90,
            $this->item('admin.massmessages')->minUserType,
            'the screen that mails every account is behind the same gate as the rest'
        );
        $this->assertSame(80, $this->item('admin.mailtemplates')->minUserType);
        $this->assertSame(80, $this->item('admin.queue')->minUserType);
    }

    /**
     * Every administration link requires a sign-in and a usertype.
     *
     * The registry is one of two gates and the screen's own check is the other. Neither replaces the
     * other — but an admin link with no gate here is shown to every visitor, and a link is an
     * invitation: somebody follows it, and whether they get in then rests entirely on a check that
     * may not be there.
     */
    public function testEveryAdminLinkRequiresASignInAndAUsertype(): void
    {
        // Act
        $this->application->registerDefaultNavItems(['messaging', 'queue', 'auth']);

        // Assert
        foreach ($this->registered() as $id => $item) {
            if (!str_starts_with($id, 'admin.')) {
                continue;
            }

            $this->assertTrue($item->requireAuth, $id . ' is offered to guests');
            $this->assertGreaterThanOrEqual(80, $item->minUserType, $id . ' has no usertype floor');
        }
    }

    /**
     * Templates and mass messages are grouped under System, not People.
     *
     * A template is a thing the system says, not a person — and the grouping is what somebody scans
     * when looking for a screen. Filed under People it sits among the account screens, where nobody
     * looking for the wording of a notification will think to look.
     */
    public function testMessageScreensAreGroupedUnderSystem(): void
    {
        // Act
        $this->application->registerDefaultNavItems(['messaging']);

        // Assert
        $this->assertSame('System', $this->item('admin.mailtemplates')->group);
        $this->assertSame('System', $this->item('admin.massmessages')->group);
    }

    /**
     * Admin links lead into the administration area.
     *
     * Not only from inside it: the public site header shows this same section, so a bare
     * `sURL . $path` link takes the visitor to the same screen in the public theme — no sidebar,
     * outside the area's usertype floor, and with nothing on the page to say anything happened.
     *
     * Asserted against `AdminArea::url()` rather than a literal prefix, because whether an area is
     * mounted at all is configuration: with none, that helper answers the bare path and the two
     * agree, which is the correct behaviour to pin either way.
     */
    public function testAdminLinksLeadIntoTheAdministrationArea(): void
    {
        // Act
        $this->application->registerDefaultNavItems(['queue']);

        // Assert
        $this->assertSame(
            \Pramnos\Http\AdminArea::url('Queue'),
            $this->item('admin.queue')->url,
            'the link leaves the administration area'
        );
    }

    /**
     * Registering twice replaces rather than duplicates.
     *
     * The registry is keyed by id so an application can override a framework default — which also
     * means a second boot in one process, as a test suite does constantly, must not leave two Home
     * links in the menu.
     */
    public function testRegisteringTwiceDoesNotDuplicate(): void
    {
        // Act
        $this->application->registerDefaultNavItems(['messaging']);
        $first = count($this->registered());
        $this->application->registerDefaultNavItems(['messaging']);

        // Assert
        $this->assertSame($first, count($this->registered()), 'the menu gained a second copy of itself');
    }
}
