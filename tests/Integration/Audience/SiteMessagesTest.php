<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Audience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\SiteMessages as Screen;
use Pramnos\Audience\Rules\Actions;
use Pramnos\Audience\Rules\Context;
use Pramnos\Audience\SiteMessages;
use Pramnos\Html\SiteMessages as Render;

/**
 * Messages on the site's pages, on MySQL, prefixed MySQL and PostgreSQL: posted for a person,
 * shown to them alone until closed or expired, marked seen when first shown; a banner and a popup
 * where the layout puts them, cards in their slot; closed by a form, back to the page.
 */
#[CoversClass(SiteMessages::class)]
#[CoversClass(\Pramnos\Audience\Installed::class)]
#[CoversClass(Render::class)]
#[CoversClass(Screen::class)]
#[CoversClass(Actions::class)]
class SiteMessagesTest extends TestCase
{
    use AudienceProbe {
        tearDown as private probeTearDown;
    }
    use AdminScreen;

    /** Clear the request, then the probe's own tear-down. */
    protected function tearDown(): void
    {
        $this->clearRequest();
        $this->probeTearDown();
    }

    /**
     * A person sees their own messages, oldest first, until they close them or they expire; the
     * first showing is recorded; a kind that is not one is refused.
     */
    #[DataProvider('databases')]
    public function testMessagesAreShownUntilClosedOrExpired(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $anna    = $this->ids['anna'];
        $banner  = SiteMessages::post($anna, 'banner', '<b>Renew</b> soon', '<p>Ok</p><script>x()</script>', '/premium', 'Renew', 7, 'rule:1:v1:s:run1');
        $card    = SiteMessages::post($anna, 'card', 'A tip');
        $old     = SiteMessages::post($anna, 'card', 'Old', days: 1);
        $this->db->queryBuilder()->table('site_messages')->where('id', $old)->update(['expiresat' => time() - 10]);
        SiteMessages::post($this->ids['bob'], 'banner', 'Not hers');

        // Act
        $first  = SiteMessages::pending($anna);
        $seen   = $this->db->queryBuilder()->table('site_messages')->where('id', $banner)->first()->fields['seenat'];
        $closed = SiteMessages::dismiss($banner, $anna);
        $again  = SiteMessages::dismiss($banner, $anna);
        $theirs = SiteMessages::dismiss($card, $this->ids['bob']);
        $after  = SiteMessages::pending($anna, 'card');

        // Assert
        $this->assertSame([$banner, $card], array_column($first, 'id'));
        $this->assertSame(['banner', 'Renew soon', '<p>Ok</p>', '/premium', 'Renew'], [$first[0]['kind'], $first[0]['title'], $first[0]['body'], $first[0]['link'], $first[0]['linkText']]);
        $this->assertNotNull($seen);
        $this->assertSame([true, false, false], [$closed, $again, $theirs]);
        $this->assertSame([$card], array_column($after, 'id'));
        $this->assertNull(SiteMessages::post(0, 'card', 'Nobody'));
        $this->expectExceptionMessage('A site message is a banner, popup, card.');
        SiteMessages::post($anna, 'toast', 'x');
    }

    /**
     * The layout renders banners and one popup for whoever is signed in — cards in their own slot —
     * each with a close form carrying the token and the page; a visitor gets nothing.
     */
    #[DataProvider('databases')]
    public function testTheLayoutRendersThemForTheSignedInPerson(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $anna = $this->ids['anna'];
        SiteMessages::post($anna, 'banner', 'Banner');
        SiteMessages::post($anna, 'popup', 'First popup', '<p>Hi</p>', '/go');
        SiteMessages::post($anna, 'popup', 'Second popup');
        SiteMessages::post($anna, 'card', 'Card');
        $_SERVER['REQUEST_URI'] = '/account?tab=2';

        // Act
        $visitor = Render::render();
        $this->signIn(1, $anna);
        $page  = Render::render();
        $cards = Render::render('card');

        // Assert
        $this->assertSame('', $visitor);
        $this->assertStringContainsString('pf-site-message-banner', $page);
        $this->assertStringContainsString('<dialog open class="pf-site-message pf-site-message-popup"', $page);
        $this->assertStringContainsString('First popup', $page);
        $this->assertStringNotContainsString('Second popup', $page, 'one popup at a time');
        $this->assertStringNotContainsString('Card', $page);
        $this->assertStringContainsString('More', $page, 'a link with no words of its own');
        $this->assertStringContainsString('SiteMessages/dismiss/', $page);
        $this->assertStringContainsString('name="back" value="/account?tab=2"', $page);
        $this->assertStringContainsString('pf-site-message-card', $cards);
        $this->assertStringNotContainsString('Banner', $cards);
    }

    /**
     * Closing goes back to the page it was on — a path on this site, never an address elsewhere —
     * and closes only the person's own message.
     */
    #[DataProvider('databases')]
    public function testClosingGoesBackToThePage(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $id = SiteMessages::post($this->ids['anna'], 'banner', 'Hi');
        $this->signIn(1, $this->ids['anna']);
        $screen = $this->screen(Screen::class);
        $this->request('POST', $id, [], ['back' => '/account']);

        // Act
        $screen->dismiss();
        $backs = [Screen::back('/x?y=1'), Screen::back('//evil.example/'), Screen::back('https://evil.example/'), Screen::back('/\\evil')];

        // Assert
        $this->assertSame([], SiteMessages::pending($this->ids['anna']));
        $this->assertStringEndsWith('/account', $screen->redirects[0]);
        $home = defined('sURL') ? (string) \sURL : '/';
        $this->assertSame([rtrim($home, '/') . '/x?y=1', $home, $home, $home], $backs);
    }

    /**
     * A rule's site_message action posts the message with the step's origin, filled for the person;
     * a test send puts it on the asking account's pages.
     */
    #[DataProvider('databases')]
    public function testARuleStepPostsAMessage(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $run  = Actions::get('site_message')['run'];
        $real = new Context(3, 'Nudge', 1, 'tell', 40, $this->ids['cara'], [], null, false, null);
        $test = new Context(3, 'Nudge', 1, 'tell', 0, $this->ids['cara'], [], null, false, null, $this->ids['bob']);
        $params = ['kind' => 'card', 'title' => 'Hi {firstname}', 'body' => 'x', 'days' => 3];

        // Act
        $sent   = $run($this->ids['cara'], $params, $real);
        $tested = $run($this->ids['cara'], $params, $test);

        // Assert
        $this->assertSame('sent', $sent['status']);
        $row = $this->db->queryBuilder()->table('site_messages')->where('id', $sent['detail']['message'])->first()->fields;
        $this->assertSame(['Hi Cara', 'rule:3:v1:tell:run40'], [$row['title'], $row['origin']]);
        $this->assertNotNull($row['expiresat']);
        $this->assertSame('Hi Cara', SiteMessages::pending($this->ids['bob'])[0]['title'] ?? null, 'the test went to bob, with cara\'s name');
        $this->assertSame('sent', $tested['status']);
    }
}
