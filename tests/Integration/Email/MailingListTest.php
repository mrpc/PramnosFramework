<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Settings;
use Pramnos\Email\MailAction;
use Pramnos\Email\MailingList;
use Pramnos\Email\MailType;
use Pramnos\Email\MailTypes;
use Pramnos\Email\Unsubscribe;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * Opt-in mailing lists against a real database: subscribing, confirming, leaving.
 *
 * glideday needs a newsletter — a landing-page form for people with no account, a choice on
 * each account, a checkbox when a WordPress site is paired — and the owner put the list in the
 * framework. What these tests pin is the part GDPR and ePrivacy care about: nobody is on a list
 * without confirming, leaving by any route ends it, the answer to a form does not reveal who is
 * subscribed, and an erased account leaves nothing behind.
 *
 * Both backends: {@see MailingListPostgreSQLTest} re-runs it.
 */
#[CoversClass(MailingList::class)]
#[CoversClass(\Pramnos\Application\Controllers\MailingListsController::class)]
#[CoversClass(MailTypes::class)]
#[CoversClass(MailType::class)]
class MailingListTest extends BaseTestCase
{
    protected $db;

    private string $address = '';

    /** Confirmation mails "sent", as [list, email]. */
    private array $sent = [];

    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        \Pramnos\Application\Application::getInstance();

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        Settings::setDatabase($this->db, false);
        $this->runMigrations([
            \Pramnos\Framework\Migrations\Core\CreateSettingsTable::class,
            \Pramnos\Framework\Migrations\Messaging\CreateEmailoptoutsTable::class,
            \Pramnos\Framework\Migrations\Messaging\CreateMailingListSubscribersTable::class,
        ], $this->db);

        MailTypes::reset();
        MailTypes::register(new MailType('newsletter', 'Newsletter', 'News, monthly.', list: 'newsletter', optIn: true));
        MailTypes::register(new MailType('digest', 'Digest', 'A weekly summary.', list: 'digest'));

        $this->address = 'list_' . bin2hex(random_bytes(5)) . '@example.com';
        $this->sent    = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $this->db->queryBuilder()->table(MailingList::TABLE)->where('email', $this->address)->delete();
        $this->db->queryBuilder()->table('pramnos.emailoptouts')->whereRaw('LOWER(email) = ?', [$this->address])->delete();
        MailTypes::reset();

        // Back on the default database: the PostgreSQL sibling points the singleton elsewhere.
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;

        parent::tearDown();
    }

    /** The service with its mailer replaced by a list of what it would have sent. */
    private function lists(): MailingList
    {
        $sent = &$this->sent;

        return new class ($this->db, $sent) extends MailingList {
            public function __construct(\Pramnos\Database\Database $db, private array &$sent)
            {
                parent::__construct($db);
            }

            protected function sendConfirmation(string $list, string $email, string $language): void
            {
                $this->sent[] = [$list, $email, $language];
            }
        };
    }

    /** @return array<string, mixed> */
    private function row(string $list = 'newsletter'): array
    {
        $row = $this->db->queryBuilder()->table(MailingList::TABLE)
            ->where('list', $list)->where('email', $this->address)->first();

        return $row && $row->numRows > 0 ? $row->fields : [];
    }

    /** The token the confirmation link carries, as the mail would carry it. */
    private function tokenFor(string $list): string
    {
        parse_str((string) parse_url(MailingList::confirmationUrl($list, $this->address), PHP_URL_QUERY), $query);

        return (string) $query['t'];
    }

    /**
     * Double opt-in: asking is not being subscribed.
     *
     * A pending row with the consent trail, one confirmation mail, and no newsletter until the
     * link is followed. The address is stored lowercased, whatever was typed.
     */
    public function testSubscribingAsksForConfirmationAndSubscribesNobodyYet(): void
    {
        // Act
        $result = $this->lists()->subscribe('newsletter', strtoupper($this->address), [
            'source' => 'landing', 'consent' => 'Send me the newsletter.', 'language' => 'el', 'ip' => '203.0.113.9',
        ]);

        // Assert
        $row = $this->row();
        $this->assertSame('confirmation_sent', $result);
        $this->assertSame(MailingList::PENDING, $row['status']);
        $this->assertSame('Send me the newsletter.', $row['consent_text']);
        $this->assertSame('landing', $row['source']);
        $this->assertSame('el', $row['language']);
        $this->assertSame('203.0.113.9', $row['ip']);
        $this->assertSame([['newsletter', $this->address, 'el']], $this->sent);
        $this->assertFalse($this->lists()->isSubscribed('newsletter', $this->address));
        $this->assertFalse(MailTypes::allows('newsletter', $this->address), 'a newsletter must not go to a pending address');
    }

    /**
     * Following the link confirms, and only once, and only a pending row.
     */
    public function testTheLinkConfirmsAPendingAddress(): void
    {
        // Arrange
        $lists = $this->lists();
        $lists->subscribe('newsletter', $this->address);

        // Act
        $confirmed = $lists->confirm($this->tokenFor('newsletter'));
        $again     = $lists->confirm($this->tokenFor('newsletter'));

        // Assert
        $this->assertSame(['list' => 'newsletter', 'email' => $this->address], $confirmed);
        $this->assertNull($again, 'a confirmed row is not confirmed twice');
        $this->assertTrue($lists->isSubscribed('newsletter', $this->address));
        $this->assertTrue(MailTypes::allows('newsletter', $this->address));
        $this->assertGreaterThan(0, (int) $this->row()['confirmed_at']);
    }

    /**
     * A token for something else, a forged one, or one for a row that left, confirms nothing.
     */
    public function testATokenThatIsNotAConfirmationConfirmsNothing(): void
    {
        // Arrange
        $lists = $this->lists();
        $lists->subscribe('newsletter', $this->address);
        $other = MailAction::token('revoke-sessions', ['l' => 'newsletter', 'e' => $this->address]);

        // Act + Assert
        $this->assertNull($lists->confirm($other), 'the action is part of what is signed');
        $this->assertNull($lists->confirm('not-a-token'));

        Unsubscribe::optOut($this->address, 'newsletter');
        $this->assertNull($lists->confirm($this->tokenFor('newsletter')), 'an old link does not put back an address that left');
        $this->assertSame(MailingList::UNSUBSCRIBED, $this->row()['status']);
    }

    /**
     * Asking again while pending sends at most one more mail an hour.
     *
     * The form answers the same either way; this is what keeps it from being a way to fill
     * somebody's inbox.
     */
    public function testAskingAgainWhilePendingDoesNotResendAtOnce(): void
    {
        // Arrange
        $lists = $this->lists();
        $lists->subscribe('newsletter', $this->address);

        // Act
        $second = $lists->subscribe('newsletter', $this->address);
        $this->db->queryBuilder()->table(MailingList::TABLE)->where('email', $this->address)
            ->update(['confirmation_sent_at' => time() - MailingList::RESEND_AFTER - 1]);
        $later = $lists->subscribe('newsletter', $this->address);

        // Assert
        $this->assertSame('already_pending', $second);
        $this->assertSame('confirmation_sent', $later);
        $this->assertCount(2, $this->sent);
    }

    /**
     * A proved address is subscribed at once, and a confirmed one is left as it is.
     *
     * An account's own verified address, or one arriving on a signed link, needs no second
     * mail to prove what is already proved.
     */
    public function testAProvedAddressIsSubscribedWithoutAMail(): void
    {
        // Act
        $first  = $this->lists()->subscribe('newsletter', $this->address, ['confirmed' => true, 'source' => 'account']);
        $second = $this->lists()->subscribe('newsletter', $this->address);

        // Assert
        $this->assertSame('confirmed', $first);
        $this->assertSame('already_confirmed', $second);
        $this->assertSame([], $this->sent);
        $this->assertTrue($this->lists()->isSubscribed('newsletter', $this->address));
    }

    /**
     * Leaving by any route ends the subscription — including leaving everything.
     *
     * And subscribing again afterwards clears the opt-out of **this** list only: somebody who
     * left everything and then asked for the newsletter did not ask for everything else back.
     */
    public function testLeavingEndsItAndComingBackRestoresOnlyThisList(): void
    {
        // Arrange
        $lists = $this->lists();
        $lists->subscribe('newsletter', $this->address, ['confirmed' => true]);

        // Act — leave everything, then ask for the newsletter again
        Unsubscribe::optOut($this->address, Unsubscribe::LIST_ALL);
        $afterLeaving = $lists->isSubscribed('newsletter', $this->address);
        $lists->subscribe('newsletter', $this->address, ['confirmed' => true]);

        // Assert
        $this->assertFalse($afterLeaving);
        $this->assertTrue($lists->isSubscribed('newsletter', $this->address));
        $this->assertTrue(Unsubscribe::isOptedOut($this->address, 'digest'), 'everything else stays off');
    }

    /**
     * `unsubscribe()` goes through `Unsubscribe`, so the opt-out record is written as for any
     * other way of leaving.
     */
    public function testUnsubscribeRecordsTheOptOut(): void
    {
        // Arrange
        $lists = $this->lists();
        $lists->subscribe('newsletter', $this->address, ['confirmed' => true]);

        // Act
        $lists->unsubscribe('newsletter', $this->address);

        // Assert
        $this->assertFalse($lists->isSubscribed('newsletter', $this->address));
        $this->assertTrue(Unsubscribe::isOptedOut($this->address, 'newsletter'));
    }

    /**
     * A list no opt-in type names, or a malformed address, is refused before anything is written.
     *
     * Otherwise a public form could create lists, or rows nobody can ever mail.
     */
    public function testAnUnknownListOrABadAddressIsRefused(): void
    {
        // Act + Assert
        foreach ([['digest', $this->address], ['nosuchlist', $this->address], ['newsletter', 'not an address']] as [$list, $email]) {
            try {
                $this->lists()->subscribe($list, $email);
                $this->fail("{$list} / {$email} should have been refused");
            } catch (\InvalidArgumentException) {
                // expected
            }
        }
        $this->assertSame([], $this->row());
    }

    /**
     * The export and the erase find a subscription by account and by address.
     */
    public function testAnAccountsRowsAreExportedAndErased(): void
    {
        // Arrange — a row by address only, as a subscription made before the account existed
        $lists = $this->lists();
        $lists->subscribe('newsletter', $this->address, ['confirmed' => true, 'userid' => null]);

        // Act
        $exported = $lists->rowsFor(987654, $this->address);
        $lists->forgetUser(987654, $this->address);

        // Assert
        $this->assertCount(1, $exported);
        $this->assertSame('newsletter', $exported[0]['list']);
        $this->assertSame([], $this->row());
    }

    /**
     * The confirmed subscribers of a list, for sending to it.
     */
    public function testConfirmedSubscribersListsOnlyTheConfirmed(): void
    {
        // Arrange
        $lists = $this->lists();
        $lists->subscribe('newsletter', $this->address, ['confirmed' => true, 'language' => 'el']);

        // Act
        $mine = array_values(array_filter(
            $lists->confirmedSubscribers('newsletter'),
            fn (array $r): bool => $r['email'] === $this->address
        ));

        // Assert
        $this->assertSame([['email' => $this->address, 'userid' => null, 'language' => 'el']], $mine);
    }

    // ── The administration screen ──────────────────────────────────────────

    /**
     * The counts per list and state move with the rows, and every opt-in list is listed.
     *
     * The screen's first view: how many are waiting, confirmed and gone on each list. Read as a
     * difference, because other tests' rows may share the table.
     */
    public function testCountsFollowTheRowsAndEveryOptInListIsListed(): void
    {
        // Arrange
        $lists  = $this->lists();
        $before = $lists->counts()['newsletter'] ?? ['pending' => 0, 'confirmed' => 0, 'unsubscribed' => 0];

        // Act
        $lists->subscribe('newsletter', $this->address);

        // Assert
        $after = $lists->counts()['newsletter'];
        $this->assertSame($before['pending'] + 1, $after['pending']);
        $this->assertSame($before['confirmed'], $after['confirmed']);
        $this->assertContains('newsletter', $lists->lists());
        $this->assertNotContains('digest', $lists->lists(), 'a list that is not opt-in has no subscribers to show');
    }

    /**
     * A list's subscribers are found by part of an address and by state, one page at a time.
     */
    public function testSubscribersAreSearchedFilteredAndPaged(): void
    {
        // Arrange
        $lists = $this->lists();
        $lists->subscribe('newsletter', $this->address, ['confirmed' => true]);
        $part  = substr($this->address, 0, 12);

        // Act
        $found     = $lists->subscribers('newsletter', '', strtoupper($part));
        $confirmed = $lists->subscribers('newsletter', MailingList::CONFIRMED, $part);
        $pending   = $lists->subscribers('newsletter', MailingList::PENDING, $part);
        $second    = $lists->subscribers('newsletter', '', $part, 2, 1);

        // Assert
        $this->assertSame(1, $found['total']);
        $this->assertSame($this->address, $found['rows'][0]['email']);
        $this->assertSame(1, $confirmed['total']);
        $this->assertSame(0, $pending['total'], 'the state narrows the search');
        $this->assertSame([], $second['rows'], 'one match, so a second page of one is empty');
    }

    /**
     * An administrator can send a pending address its confirmation again, without the hourly limit.
     *
     * The limit guards a public form against being used to mail somebody repeatedly; one press
     * of the button is not that. A confirmed row is not sent anything.
     */
    public function testAPendingAddressCanBeSentItsConfirmationAgain(): void
    {
        // Arrange — pending, and its confirmation just went
        $lists = $this->lists();
        $lists->subscribe('newsletter', $this->address, ['language' => 'el']);
        $id    = (int) $this->row()['subscriberid'];

        // Act
        $resent = $lists->resendConfirmation($id);

        // Assert
        $this->assertTrue($resent);
        $this->assertCount(2, $this->sent, 'the first mail and the one asked for');
        $this->assertSame(['newsletter', $this->address, 'el'], $this->sent[1]);

        // Assert — a confirmed row, and a row that does not exist, are refused
        $lists->subscribe('newsletter', $this->address, ['confirmed' => true]);
        $this->assertFalse($lists->resendConfirmation($id));
        $this->assertFalse($lists->resendConfirmation(0));
    }

    /**
     * Taking an address off by its row is leaving: the row ends and the opt-out is recorded.
     */
    public function testUnsubscribingByRowIsLeaving(): void
    {
        // Arrange
        $lists = $this->lists();
        $lists->subscribe('newsletter', $this->address, ['confirmed' => true]);
        $id    = (int) $this->row()['subscriberid'];

        // Act
        $done = $lists->unsubscribeSubscriber($id);

        // Assert
        $this->assertTrue($done);
        $this->assertSame(MailingList::UNSUBSCRIBED, $this->row()['status']);
        $this->assertTrue(Unsubscribe::isOptedOut($this->address, 'newsletter'));
        $this->assertFalse($lists->unsubscribeSubscriber($id), 'a row that has left cannot leave again');
    }

    /**
     * The export carries the confirmed only, with when they confirmed.
     */
    public function testTheExportCarriesTheConfirmedOnly(): void
    {
        // Arrange
        $lists = $this->lists();
        $lists->subscribe('newsletter', $this->address, ['confirmed' => true, 'language' => 'el']);

        // Act
        $mine = array_values(array_filter(
            $lists->confirmedExport('newsletter'),
            fn (array $r): bool => $r['email'] === $this->address
        ));

        // Assert
        $this->assertCount(1, $mine);
        $this->assertSame('el', $mine[0]['language']);
        $this->assertGreaterThan(0, (int) $mine[0]['confirmed_at']);
    }

    /**
     * The screen: the list, a row action, and the CSV — through the controller.
     *
     * The controller's own guards are exercised by Controller's tests; here the actions are
     * reached with the floor and the redirect replaced, so what is under test is what each one
     * does to the rows and what it answers.
     */
    public function testTheScreenShowsResendsUnsubscribesAndExports(): void
    {
        // Arrange
        $lists = $this->lists();
        $lists->subscribe('newsletter', $this->address, ['language' => 'el']);
        $id     = (int) $this->row()['subscriberid'];
        $screen = new class ($lists) extends \Pramnos\Application\Controllers\MailingListsController {
            public array $redirectedTo = [];
            public mixed $shown = null;

            public function __construct(private MailingList $lists)
            {
            }

            protected function mailingList(): MailingList
            {
                return $this->lists;
            }

            protected function requireMinUserType(int $minType): bool
            {
                return false;
            }

            public function redirect($url = null, $quit = true, $code = '302')
            {
                $this->redirectedTo[] = $url;
            }

            public function &getView($name = '', $type = '', $args = [])
            {
                $owner = $this;
                $view  = new class ($owner) extends \stdClass {
                    public function __construct(private $owner)
                    {
                    }

                    public function display($name = '')
                    {
                        $this->owner->shown = get_object_vars($this);

                        return 'shown';
                    }
                };

                return $view;
            }
        };
        $_GET  = ['list' => 'newsletter', 'q' => $this->address];
        $_POST = ['list' => 'newsletter', 'q' => $this->address];

        // Act — the list
        $screen->display();

        // Assert
        $this->assertSame('newsletter', $screen->shown['list']);
        $this->assertSame(1, $screen->shown['result']['total']);

        // Act — resend, then unsubscribe, by the row's id
        $_GET['_option'] = (string) $id;   // what the router sets for …/resend/{id}
        $screen->resend();
        $screen->unsubscribe();

        // Assert
        $this->assertCount(2, $this->sent, 'the confirmation went again');
        $this->assertSame(MailingList::UNSUBSCRIBED, $this->row()['status']);
        $this->assertStringContainsString('list=newsletter', (string) $screen->redirectedTo[0], 'back to the list the row was on');

        // Act — both again, on a row that has left: each refuses, with a reason
        $_SESSION['_errors'] = [];
        $screen->resend();
        $screen->unsubscribe();

        // Assert
        $this->assertCount(2, $_SESSION['_errors'] ?? [], 'neither can be done to a row that has left');
        $this->assertCount(2, $this->sent, 'and nothing more was sent');

        // Act — the export of a list, and of one that does not exist
        $lists->subscribe('newsletter', $this->address, ['confirmed' => true, 'language' => 'el']);
        $csv     = $screen->export();
        $_GET    = ['list' => 'nosuchlist'];
        $missing = $screen->export();

        // Assert
        $this->assertStringContainsString($this->address . ',el,', $csv->getBody());
        $this->assertStringStartsWith('text/csv', (string) $csv->getHeaderLine('Content-Type'));
        $this->assertSame(404, $missing->getStatusCode());
    }

    /**
     * An opt-in type needs a list, and a list is found by its type.
     */
    public function testAnOptInTypeNeedsAList(): void
    {
        // Assert
        $this->assertSame('newsletter', MailTypes::byList('newsletter')?->name);
        $this->assertNull(MailTypes::byList('nosuchlist'));
        $this->assertTrue(MailTypes::get('newsletter')->toArray()['opt_in']);

        $this->expectException(\InvalidArgumentException::class);
        new MailType('broken', 'Broken', '', '', true);
    }

    // ── The account screen and the preferences page ─────────────────────────────

    /**
     * The account screen lists the opt-in lists, off until joined, and says when one is pending.
     *
     * Off by default, as consent to marketing has to be; "pending" is shown as such, because
     * "check your inbox" is not "off".
     */
    public function testTheAccountScreenListsTheOptInListsWithTheirState(): void
    {
        // Arrange — the newsletter pending; the digest is not opt-in, so it is not listed
        $this->lists()->subscribe('newsletter', $this->address);
        $account = $this->account();
        $user    = (object) ['userid' => 555001, 'email' => $this->address];

        // Act
        $choices = (new \ReflectionMethod($account, 'mailingListChoices'))->invoke($account, $user);

        // Assert
        $this->assertSame([[
            'list' => 'newsletter', 'label' => 'Newsletter', 'description' => 'News, monthly.', 'status' => 'pending',
        ]], $choices);
    }

    /**
     * Ticking the box with a verified address subscribes it at once; unticking leaves.
     *
     * A verified address needs no mail to prove what is already proved. Unticking is the same as
     * the unsubscribe link, so the opt-out is recorded too.
     */
    public function testTheAccountScreenJoinsAndLeaves(): void
    {
        // Arrange
        $account = $this->account();
        $save    = new \ReflectionMethod($account, 'saveMailingLists');
        $user    = (object) ['userid' => 555001, 'email' => $this->address, 'validated' => 1, 'language' => 'el'];

        // Act — tick, then untick
        $_POST = ['lists' => ['newsletter' => '1']];
        $save->invoke($account, $user);
        $joined = $this->row();
        $_POST = [];
        $save->invoke($account, $user);

        // Assert
        $this->assertSame(MailingList::CONFIRMED, $joined['status']);
        $this->assertSame('account', $joined['source']);
        $this->assertSame('News, monthly.', $joined['consent_text']);
        $this->assertSame(MailingList::UNSUBSCRIBED, $this->row()['status']);
        $this->assertTrue(Unsubscribe::isOptedOut($this->address, 'newsletter'));
    }

    /**
     * The preferences page shows an opt-in list as off until confirmed, and turning it back on
     * from there subscribes at once — the link is signed for the address.
     */
    public function testThePreferencesPageTreatsAnOptInListAsOffUntilJoined(): void
    {
        // Arrange
        $page = new class extends \Pramnos\Application\Controllers\Unsubscribe {
            public function __construct()
            {
            }

            public function prefs(string $email): string
            {
                return $this->preferences($email);
            }

            public function turnOn(string $email, string $list): bool
            {
                return $this->optIn($email, $list);
            }
        };

        // Act
        $before = $page->prefs($this->address);
        $on     = $page->turnOn($this->address, 'newsletter');

        // Assert
        $this->assertMatchesRegularExpression('/Newsletter<\/strong> — <span class="off">not receiving/', $before);
        $this->assertTrue($on);
        $this->assertTrue($this->lists()->isSubscribed('newsletter', $this->address));
        $this->assertSame('preferences', $this->row()['source']);
        $this->assertStringContainsString('Newsletter</strong> — receiving', $page->prefs($this->address));
    }

    private function account(): \Pramnos\Auth\Controllers\Account
    {
        return (new \ReflectionClass(\Pramnos\Auth\Controllers\Account::class))->newInstanceWithoutConstructor();
    }
}
