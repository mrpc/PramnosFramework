<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Email\MailType;
use Pramnos\Email\MailTypes;

/**
 * What kinds of mail this application sends.
 *
 * Every feature built around mail needed this list and none of them had it: the unsubscribe
 * list was a string typed at each call site, the mass-send screen asked for one in a free-text
 * box, and there was no way to show somebody the mail they can turn off because nothing knew
 * what it was.
 */
#[CoversClass(MailTypes::class)]
#[CoversClass(MailType::class)]
class MailTypesTest extends TestCase
{
    protected function tearDown(): void
    {
        MailTypes::reset();
        parent::tearDown();
    }

    /**
     * A type with a list can be turned off; one without cannot.
     *
     * The one field the whole feature turns on. It decides the unsubscribe link, the two
     * headers a mailbox provider reads, whether tracking is allowed and whether an opted-out
     * address is skipped — all of which used to be decided at each call site, separately.
     */
    public function testAListIsWhatMakesATypeOptional(): void
    {
        // Arrange
        $digest  = new MailType('digest', 'Weekly digest', 'Every Monday.', 'digest');
        $receipt = new MailType('receipt', 'Receipts', 'What you paid for.');

        // Assert
        $this->assertFalse($digest->transactional());
        $this->assertTrue($receipt->transactional());
        $this->assertSame('digest', $digest->toArray()['list']);
        $this->assertTrue($receipt->toArray()['transactional']);
    }

    /**
     * A type whose list is nothing but spaces is transactional.
     *
     * `new MailType(…, ' ')` is somebody meaning "none", and reading it as a list named `" "`
     * would produce an unsubscribe link that suppresses nothing — the worst of both, since the
     * message then carries a promise it does not keep.
     */
    public function testAWhitespaceListIsNoList(): void
    {
        // Assert
        $this->assertTrue((new MailType('x', 'X', '', '   '))->transactional());
    }

    /**
     * Only the types somebody can turn off are a preference.
     *
     * A preferences page listing a second-factor code with a checkbox beside it is offering a
     * choice that does not exist, and the reader finds that out by unticking it.
     */
    public function testOnlyOptionalTypesAreOfferedAsPreferences(): void
    {
        // Arrange
        MailTypes::reset();
        MailTypes::register(new MailType('digest', 'Weekly digest', '', 'digest'));
        MailTypes::register(new MailType('receipt', 'Receipts', ''));

        // Act
        $optional = MailTypes::optional();

        // Assert
        $this->assertArrayHasKey('digest', $optional);
        $this->assertArrayNotHasKey('receipt', $optional);
    }

    /**
     * The framework's own mail is registered without anybody asking.
     *
     * These have to be there for the *first* thing that looks — a preferences page, a send, an
     * administration screen — any of which can run where a ServiceProvider that would have
     * registered them was never booted. A list of mail types that is sometimes missing three
     * entries fails as a checkbox silently absent from somebody's preferences.
     */
    public function testTheFrameworksOwnTypesAreThereWithoutRegistration(): void
    {
        // Arrange
        MailTypes::reset();

        // Act
        $all = MailTypes::all();

        // Assert
        $this->assertArrayHasKey('newsignin', $all);
        $this->assertArrayHasKey('second-factor-code', $all);
        $this->assertSame('newsignin', MailTypes::listFor('newsignin'),
            'the sign-in alert is the one framework mail somebody can turn off');
        $this->assertSame('', MailTypes::listFor('second-factor-code'),
            'a code you need in order to sign in is not');
    }

    /**
     * An application overrides a framework type by registering the same name.
     *
     * Its label is what a person reads, and an application that words it differently — or puts
     * the alert on a list it already has — should not have to work around the default.
     */
    public function testAnApplicationCanOverrideAFrameworkType(): void
    {
        // Arrange
        MailTypes::reset();

        // Act
        MailTypes::register(new MailType('newsignin', 'Ειδοποιήσεις σύνδεσης', '', 'security'));

        // Assert
        $this->assertSame('Ειδοποιήσεις σύνδεσης', MailTypes::get('newsignin')?->label);
        $this->assertSame('security', MailTypes::listFor('newsignin'));
    }

    /**
     * An unknown name is treated as transactional, not as an error.
     *
     * The thing that would throw is a send. A typo in a type name must not stop a password
     * reset — it means a message goes out without an unsubscribe link it should not have
     * carried anyway.
     */
    public function testAnUnknownTypeIsTreatedAsTransactional(): void
    {
        // Assert
        $this->assertFalse(MailTypes::has('no-such-type'));
        $this->assertNull(MailTypes::get('no-such-type'));
        $this->assertSame('', MailTypes::listFor('no-such-type'));
        $this->assertTrue(MailTypes::allows('no-such-type', 'reader@example.com'));
    }

    /**
     * Transactional mail is allowed whatever the address has asked for.
     *
     * A password reset must arrive for somebody who unsubscribed from everything. That is not a
     * loophole — it is the reason transactional mail is a separate thing, and a sender who
     * suppressed it would lock people out of their own accounts.
     */
    public function testTransactionalMailIsAllowedForAnyAddress(): void
    {
        // Arrange
        MailTypes::reset();
        MailTypes::register(new MailType('receipt', 'Receipts', ''));

        // Assert — no database is touched, because no list means nothing to look up
        $this->assertTrue(MailTypes::allows('receipt', 'gone@example.com'));
    }

    /**
     * An empty address is allowed through rather than suppressed.
     *
     * There is nobody to have opted out. Refusing here would turn "this message has no
     * recipient yet" into "this recipient unsubscribed", and the caller would look for the
     * opt-out that does not exist.
     */
    public function testAnEmptyAddressIsNotTreatedAsUnsubscribed(): void
    {
        // Arrange
        MailTypes::reset();
        MailTypes::register(new MailType('digest', 'Weekly digest', '', 'digest'));

        // Assert
        $this->assertTrue(MailTypes::allows('digest', '   '));
    }
}
