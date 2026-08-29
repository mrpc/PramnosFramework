<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Messaging;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Messaging\Controllers\MassMessagesController;
use Pramnos\Messaging\MassMessage;

/**
 * What the compose screen writes into `massmessages.request`, and reads back out.
 *
 * That column is the audit record of a decision — who this went to, and how it was sent — and
 * it is a text column shared by two kinds of thing. The reading has to keep them apart and has
 * to survive a row written before the second kind existed.
 */
#[CoversClass(MassMessagesController::class)]
class MassMessagesControllerTest extends TestCase
{
    /**
     * A row from before the send options existed reads as criteria and no options.
     *
     * Every mass message already in an installation's table is one of these.
     */
    public function testARowFromBeforeTheOptionsExistedStillReads(): void
    {
        // Arrange
        $message = $this->message('{"usertype_min":50,"validated_only":true}');

        // Act & Assert
        $probe = new MassMessagesProbe();
        $this->assertSame(
            ['usertype_min' => 50, 'validated_only' => true],
            $probe->probeStoredCriteria($message)
        );
        $this->assertSame([], $probe->probeOptions($message));
    }

    /**
     * The options are kept out of the criteria, and the criteria out of the options.
     *
     * `resolve()` ignores keys it does not know, so a leaked `options` key would not break the
     * audience — it would appear in the description of who the message was for, which is the
     * one sentence anybody ever reads about a send.
     */
    public function testTheTwoKindsOfThingAreKeptApart(): void
    {
        // Arrange
        $message = $this->message(
            '{"usertype_min":50,"options":{"tracking":true,"list":"digest"}}'
        );
        $probe = new MassMessagesProbe();

        // Act
        $criteria = $probe->probeStoredCriteria($message);
        $options  = $probe->probeOptions($message);

        // Assert
        $this->assertSame(['usertype_min' => 50], $criteria);
        $this->assertArrayNotHasKey('options', $criteria);
        $this->assertSame(['tracking' => true, 'list' => 'digest'], $options);
    }

    /**
     * Rubbish in the column is not a reason to fail rendering the screen.
     */
    public function testAnUnreadableRequestIsEmptyRatherThanFatal(): void
    {
        // Arrange
        $probe = new MassMessagesProbe();

        // Assert
        foreach (['', 'not json', '"a string"', '42'] as $stored) {
            $this->assertSame([], $probe->probeStoredCriteria($this->message($stored)), $stored);
            $this->assertSame([], $probe->probeOptions($this->message($stored)), $stored);
        }
    }

    /**
     * A date is read as ISO, and anything else is refused.
     *
     * `strtotime()` reads a slash-separated date as American month-first, so `03/04/2026` is
     * March on a screen that said April — and the audience it selects is a different set of
     * people, silently. The form posts ISO; this refuses everything else rather than guessing.
     */
    public function testADateIsReadAsIsoOrNotAtAll(): void
    {
        // Arrange
        $probe = new MassMessagesProbe();

        // Act & Assert
        $this->assertSame(strtotime('2026-04-03'), $probe->probeTimestamp('2026-04-03'));
        $this->assertSame(0, $probe->probeTimestamp('03/04/2026'),
            'a slash date is ambiguous, and guessing picks the wrong month half the time');
        $this->assertSame(0, $probe->probeTimestamp(''));
        $this->assertSame(0, $probe->probeTimestamp('yesterday'));
        $this->assertSame(0, $probe->probeTimestamp('2026-4-3'));
    }

    /**
     * The wrapper list is read from the directories, so the bundled default is always there.
     */
    public function testTheWrapperListComesFromTheDirectories(): void
    {
        // Assert
        $this->assertContains('default', (new MassMessagesProbe())->probeTemplates());
    }

    /**
     * The audience criteria come off the form as the operator set them.
     */
    public function testTheCriteriaComeOffTheForm(): void
    {
        // Arrange
        $criteria = $this->post([
            'usertype_min'      => '10',
            'usertype_max'      => '50',
            'language'          => ' el ',
            'twofactor'         => 'without',
            'last_login_before' => '2026-01-01',
            'exclude_optouts'   => 'massmessages',
            'validated_only'    => '1',
            'active_only'       => '1',
        ])->probeCriteria();

        // Assert
        $this->assertSame(10, $criteria['usertype_min']);
        $this->assertSame(50, $criteria['usertype_max']);
        $this->assertSame('el', $criteria['language'], 'trimmed');
        $this->assertSame('without', $criteria['twofactor']);
        $this->assertSame(strtotime('2026-01-01'), $criteria['last_login_before']);
        $this->assertSame('massmessages', $criteria['exclude_optouts']);
        $this->assertTrue($criteria['validated_only']);
    }

    /**
     * A form left alone stores nothing but the two booleans.
     *
     * An empty string or a zero is "not filtering by this", and storing it would put a key in
     * the audit record that reads as a decision nobody made — and `describe()` would then
     * announce it to whoever reads what the send was aimed at.
     */
    public function testAnUntouchedFormStoresNoFilters(): void
    {
        // Act
        $criteria = $this->post([])->probeCriteria();

        // Assert
        $this->assertSame(
            ['validated_only', 'active_only'],
            array_keys($criteria)
        );
    }

    /**
     * Unticking "validated only" means unvalidated accounts are included, and survives.
     *
     * `array_filter` drops `false`, and dropped, the key reverts to its default of *true* on
     * the next read — so the operator's decision to include unvalidated addresses would be
     * silently reversed between saving and sending.
     */
    public function testUntickingValidatedOnlySurvivesTheSave(): void
    {
        // Act
        $criteria = $this->post(['validated_only' => '0', 'active_only' => '0'])->probeCriteria();

        // Assert
        $this->assertArrayHasKey('validated_only', $criteria);
        $this->assertFalse($criteria['validated_only']);
        $this->assertFalse($criteria['active_only']);
    }

    /**
     * A negative usertype is not a filter.
     */
    public function testANegativeUsertypeIsNotAFilter(): void
    {
        // Act
        $criteria = $this->post(['usertype_min' => '-5'])->probeCriteria();

        // Assert
        $this->assertArrayNotHasKey('usertype_min', $criteria);
    }

    /**
     * The send options come off the form, and an untouched form has none.
     */
    public function testTheSendOptionsComeOffTheForm(): void
    {
        // Assert — nothing chosen
        $this->assertSame([], $this->post([])->probeOptionsFrom());
        $this->assertSame([], $this->post(['template' => '__default__'])->probeOptionsFrom());

        // …and everything chosen
        $options = $this->post([
            'link'        => 'https://example.com/notice',
            'list'        => 'announcements',
            'preheader'   => 'Ten minutes on Sunday',
            'tracking'    => '1',
            'template'    => 'receipt',
            'action_type' => 'view',
            'action_name' => 'Read it',
            'action_url'  => 'https://example.com/notice',
        ])->probeOptionsFrom();

        $this->assertSame('https://example.com/notice', $options['link']);
        $this->assertSame('announcements', $options['list']);
        $this->assertSame('Ten minutes on Sunday', $options['preheader']);
        $this->assertTrue($options['tracking']);
        $this->assertSame('receipt', $options['template']);
        $this->assertSame('view', $options['action_type']);
    }

    /**
     * "No wrapper" is stored; "the default" is not stored at all.
     *
     * Both look empty and they are different answers. Conflated, a campaign that deliberately
     * sends a bare body silently acquires the installation's wrapper.
     */
    public function testNoWrapperIsStoredAndTheDefaultIsNot(): void
    {
        // Assert
        $chosen = $this->post(['template' => ''])->probeOptionsFrom();
        $this->assertArrayHasKey('template', $chosen);
        $this->assertSame('', $chosen['template']);

        $this->assertArrayNotHasKey('template', $this->post(['template' => '__default__'])->probeOptionsFrom());
    }

    /**
     * A campaign that chose no options writes no `options` key.
     *
     * An empty object in the audit record reads as a decision somebody made — "send this with
     * no wrapper, no list and no tracking" — which is a different statement from having left
     * the form alone. It is also what makes an old row and a new one with no options identical,
     * which is the property {@see testARowFromBeforeTheOptionsExistedStillReads} depends on.
     */
    public function testACampaignWithNoOptionsWritesNoOptionsKey(): void
    {
        // Arrange
        $probe = new MassMessagesProbe();

        // Act
        $bare = $probe->probeRequestJson(['usertype_min' => 50], []);
        $full = $probe->probeRequestJson(['usertype_min' => 50], ['tracking' => true]);

        // Assert
        $this->assertSame('{"usertype_min":50}', $bare);
        $this->assertSame('{"usertype_min":50,"options":{"tracking":true}}', $full);
        $this->assertSame([], $probe->probeOptions($this->message($bare)));
        $this->assertSame(['tracking' => true], $probe->probeOptions($this->message($full)));
    }

    /**
     * A URL in the record is stored readable.
     *
     * `JSON_UNESCAPED_SLASHES`, because the only reader of this column is a person trying to
     * work out what a send was aimed at, and `https:\/\/example.com` is not that.
     */
    public function testUrlsInTheRecordStayReadable(): void
    {
        // Act
        $json = (new MassMessagesProbe())->probeRequestJson([], ['link' => 'https://example.com/a']);

        // Assert
        $this->assertStringContainsString('https://example.com/a', $json);
    }

    /**
     * Arrange a POST body and return a probe reading it.
     *
     * @param array<string, string> $fields
     */
    private function post(array $fields): MassMessagesProbe
    {
        $_POST    = $fields;
        $_REQUEST = $fields;
        \Pramnos\Http\Request::resetInstance();

        return new MassMessagesProbe();
    }

    private function message(string $request): MassMessage
    {
        $message = new MassMessage(new MassMessagesProbe());
        $message->request = $request;

        return $message;
    }
}

/** Exposes the four readers the compose screen depends on. */
class MassMessagesProbe extends MassMessagesController
{
    public function __construct()
    {
        // Deliberately not parent::__construct(): it registers actions against an application
        // this test does not have.
    }

    /** @return array<string, mixed> */
    public function probeStoredCriteria(MassMessage $message): array
    {
        return $this->criteriaOf($message);
    }

    /** @return array<string, mixed> */
    public function probeOptions(MassMessage $message): array
    {
        return $this->optionsOf($message);
    }

    public function probeTimestamp(string $value): int
    {
        return $this->timestampOf($value);
    }

    /** @return list<string> */
    public function probeTemplates(): array
    {
        return $this->mailTemplates();
    }

    /** @return array<string, mixed> */
    public function probeCriteria(): array
    {
        return $this->criteriaFrom(new \Pramnos\Http\Request());
    }

    /** @return array<string, mixed> */
    public function probeOptionsFrom(): array
    {
        return $this->optionsFrom(new \Pramnos\Http\Request());
    }

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, mixed> $options
     */
    public function probeRequestJson(array $criteria, array $options): string
    {
        return $this->requestJson($criteria, $options);
    }
}
