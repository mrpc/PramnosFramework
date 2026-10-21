<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Audience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\ConsentLists as Screen;
use Pramnos\Audience\ConsentLists;

/**
 * `/admin/ConsentLists` on MySQL, prefixed MySQL and PostgreSQL: the lists with how many are in
 * each, the form over the declared sources, the writes in the audit log, and every theme's view.
 */
#[CoversClass(Screen::class)]
class ConsentListsScreenTest extends TestCase
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
     * The lists show their count — and say so when a list reads a source the code no longer
     * declares, rather than failing the page — in every theme.
     */
    #[DataProvider('databases')]
    public function testTheListsShowHowManyAreInEach(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->db->queryBuilder()->table('pramnos.emailoptouts')->insert(['email' => 'bob@example.com', 'list' => 'all', 'source' => 'page', 'created_at' => time()]);
        ConsentLists::source('gone', 'A source about to go', []);
        ConsentLists::create('Service mail', 'email', 'everyone', 'What we must tell you', true, 'service');
        ConsentLists::create('Orphan', 'sms', 'gone');
        ConsentLists::reset();
        $this->signIn();
        $screen = $this->screen(Screen::class);

        // Act
        $this->request('GET');
        $screen->display();
        $vars = (array) $screen->view;
        $html = [];
        foreach (['tailwind', 'bootstrap', 'plain-css'] as $theme) {
            $html[$theme] = $this->render($theme, 'consentlists/consentlists.html.php', $vars);
        }

        // Assert
        $byName = array_column($vars['lists'], null, 'name');
        $this->assertSame(2, $byName['Service mail']['count'], 'bob left everything');
        $this->assertNull($byName['Orphan']['count']);
        $this->assertStringContainsString('no longer declares', $byName['Orphan']['problem']);
        foreach ($html as $theme => $page) {
            $this->assertStringContainsString('Service mail', $page, $theme);
            $this->assertStringContainsString('cannot count', $page, $theme);
            $this->assertStringContainsString('and the opt-outs of service', $page, $theme);
            $this->assertStringContainsString('What we must tell you', $page, $theme);
        }
    }

    /**
     * The form offers the declared sources and the channels; saving creates and changes, each in
     * the audit log; a list that does not check goes back to the form.
     */
    #[DataProvider('databases')]
    public function testTheFormSavesAndAudits(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        ConsentLists::source('smsoffers', 'Offers by SMS', ['conditions' => [['attribute' => 'language', 'op' => 'not_empty']]], column: 'smsoffers');
        $this->signIn();
        $screen = $this->screen(Screen::class);

        // Act
        $this->request('GET');
        $screen->edit();
        $new = (array) $screen->view;
        $this->request('POST', null, [], ['name' => 'SMS offers', 'channel' => 'sms', 'source' => 'smsoffers', 'defaulton' => '1']);
        $screen->save();
        $id = ConsentLists::all()[0]['id'];
        $this->request('POST', null, [], ['listid' => (string) $id, 'name' => 'SMS offers', 'channel' => 'fax', 'source' => 'smsoffers']);
        $screen->save();
        $this->request('POST', null, [], ['listid' => (string) $id, 'name' => 'Offers by SMS', 'channel' => 'sms', 'source' => 'smsoffers', 'description' => 'Discounts']);
        $screen->save();
        $this->request('GET', $id);
        $screen->edit();
        $edit = (array) $screen->view;
        $forms = [];
        foreach (['tailwind', 'bootstrap', 'plain-css'] as $theme) {
            $forms[$theme] = $this->render($theme, 'consentlists/edit.html.php', $edit);
        }
        $blank = $this->render('tailwind', 'consentlists/edit.html.php', $new);

        // Assert
        $this->assertSame([
            ['message', 'Saved.'],
            ['error', 'A consent list is for one of: email, sms, push, phone, site.'],
            ['message', 'Saved.'],
        ], $screen->flash);
        $list = ConsentLists::get($id);
        $this->assertSame(['Offers by SMS', 'Discounts', false], [$list['name'], $list['description'], $list['defaultOn']]);
        $this->assertSame(['everyone', 'smsoffers'], array_keys($new['sources']));
        $this->assertSame('SMS', $new['channels']['sms']);
        foreach ($forms as $theme => $page) {
            $this->assertStringContainsString('name="listid"', $page, $theme);
            $this->assertMatchesRegularExpression('/value="smsoffers"\s*selected/', $page, $theme);
            $this->assertStringContainsString('getTokenField', file_get_contents(ROOT . '/scaffolding/themes/' . $theme . '/views/consentlists/edit.html.php'));
        }
        $this->assertStringNotContainsString('name="listid"', $blank);
        $events = array_column($this->db->queryBuilder()->table('authserver.audit_log')->where('object_type', 'consentlist')->orderBy('auditid')->getAll(), 'event_type');
        $this->assertSame(['consentlist.created', 'consentlist.updated'], $events);
    }

    /** A list in use is not deleted; one in no use is, audited; an unknown one goes back to the lists. */
    #[DataProvider('databases')]
    public function testDeletingIsRefusedWhileInUse(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $id = ConsentLists::create('Newsletter', 'email', 'everyone');
        ConsentLists::usage('sends', fn (int $l): array => ['send October news']);
        $this->signIn();
        $screen = $this->screen(Screen::class);

        // Act
        $this->request('POST', $id);
        $screen->delete();
        ConsentLists::reset();
        $screen->delete();
        $screen->delete();
        $this->request('GET', 999);
        $gone = $screen->edit();
        $this->request('POST', null, [], ['listid' => '999', 'name' => 'x']);
        $screen->save();

        // Assert
        $this->assertSame([
            ['error', 'This consent list is in use: send October news'],
            ['message', 'Consent list deleted.'],
            ['error', 'That consent list no longer exists.'],
            ['error', 'That consent list no longer exists.'],
            ['error', 'That consent list no longer exists.'],
        ], $screen->flash);
        $this->assertNull($gone);
        $this->assertSame(['consentlist.deleted'], array_column($this->db->queryBuilder()->table('authserver.audit_log')->where('object_type', 'consentlist')->getAll(), 'event_type'));
    }

    /** Below the administration floor nothing is read or written. */
    #[DataProvider('databases')]
    public function testEveryActionIsRefusedBelowTheFloor(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $id = ConsentLists::create('Newsletter', 'email', 'everyone');
        $this->signIn(1);
        $screen = $this->screen(Screen::class);
        $this->request('POST', $id, [], ['name' => 'x', 'channel' => 'sms', 'source' => 'everyone']);

        // Act
        $answers = [$screen->display(), $screen->edit()];
        $screen->save();
        $screen->delete();

        // Assert
        $this->assertSame([null, null], $answers);
        $this->assertCount(4, $screen->redirects);
        $this->assertSame('Newsletter', ConsentLists::get($id)['name']);
    }
}
