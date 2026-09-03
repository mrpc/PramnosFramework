<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Notification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Notification\ChannelInterface;
use Pramnos\Notification\NotifiableInterface;
use Pramnos\Notification\NotifiableTrait;
use Pramnos\Notification\NotificationInterface;
use Pramnos\Notification\Notifier;

/**
 * Unit tests for Notifier — the central dispatch hub.
 *
 * These tests verify the routing logic without exercising real transport
 * channels. All channels are replaced with lightweight spy/stub objects.
 */
#[CoversClass(Notifier::class)]
#[CoversClass(NotifiableTrait::class)]
class NotifierTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────────
    // sendNow() — dispatches channels from via()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A notification renders in the recipient's language, not the sender's.
     *
     * This is the only text in an application that is not for whoever made the request. A
     * Greek account was told about its own new sign-in in English whenever the request that
     * triggered the mail came from somewhere else — an operator on an English administration
     * screen, a queue worker with no language at all — and there is nothing in the mail to
     * suggest a bug: it is simply in the wrong language.
     *
     * Asserted through the language the channel *sees*, because that is when `toMail()` runs.
     */
    public function testSendNowRendersInTheRecipientsLanguage(): void
    {
        // Arrange — a catalogue with one language installed, and an account that uses it
        $directory = sys_get_temp_dir() . '/pf-notifier-lang-' . bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents(
            $directory . '/english.php',
            '<?php $lang = ["Hello" => "Hello"]; return $lang;'
        );
        file_put_contents(
            $directory . '/greek.php',
            '<?php $lang = ["Hello" => "Γεια"]; return $lang;'
        );

        \Pramnos\Translator\Language::resetInstance();
        $language = new class ($directory) extends \Pramnos\Translator\Language {
            private string $directory;

            public function __construct(string $directory)
            {
                $this->directory = $directory;
                parent::__construct('english');
            }

            protected function languageDirectories(): array
            {
                return [$this->directory];
            }

            public static function getLanguages()
            {
                return ['english', 'greek'];
            }
        };
        $language->load('english');
        \Pramnos\Translator\Language::setInstance($language);

        LanguageSpyChannel::$seen = '';
        $notifier = new Notifier();
        $notifier->registerChannel('langspy', LanguageSpyChannel::class);

        $notifiable = new StubNotifiable();
        $notifiable->language = 'greek';

        try {
            // Act
            $notifier->sendNow($notifiable, new LanguageSpyNotification());

            // Assert
            $this->assertSame('Γεια', LanguageSpyChannel::$seen,
                'the channel renders while the recipient language is loaded');
            $this->assertSame('english', $language->currentlang(),
                'and the request language is back afterwards');
            $this->assertSame('Hello', $language->_('Hello'),
                'with its own catalogue, which a merge-based switch would have replaced');
        } finally {
            foreach ((array) glob($directory . '/*') as $file) {
                @unlink((string) $file);
            }
            @rmdir($directory);
            \Pramnos\Translator\Language::resetInstance();
        }
    }

    /**
     * A recipient with no language of its own changes nothing.
     *
     * `PlainAddress` is the case: an address and nothing else. Guessing would be worse than
     * the installation's own language.
     */
    public function testARecipientWithNoLanguageIsSentInTheCurrentOne(): void
    {
        // Arrange
        LanguageSpyChannel::$seen = '';
        $notifier = new Notifier();
        $notifier->registerChannel('langspy', LanguageSpyChannel::class);

        // Act — no `language` property set on the notifiable
        $notifier->sendNow(new StubNotifiable(), new LanguageSpyNotification());

        // Assert — the key itself, since no catalogue is loaded in this test
        $this->assertSame('Hello', LanguageSpyChannel::$seen);
    }

    /**
     * sendNow() must invoke the send() method on every channel returned by
     * $notification->via(). Verified via a spy channel that counts calls.
     */
    public function testSendNowCallsAllChannelsReturnedByVia(): void
    {
        // Arrange
        SpyChannel::$calls = [];
        $notifier = new Notifier();
        $notifier->registerChannel('spy', SpyChannel::class);
        $notifiable    = new StubNotifiable();
        $notification  = new TwoSpyChannelNotification();

        // Act
        $notifier->sendNow($notifiable, $notification);

        // Assert — spy channel was called twice (two 'spy' entries in via())
        $this->assertCount(2, SpyChannel::$calls,
            'sendNow() must call send() once per channel in via()');
    }

    /**
     * When via() returns an empty array no channel is invoked and no error
     * is thrown — the notification is silently dropped.
     */
    public function testSendNowWithNoChannelsDoesNothing(): void
    {
        // Arrange
        SpyChannel::$calls = [];
        $notifier     = new Notifier();
        $notifier->registerChannel('spy', SpyChannel::class);
        $notifiable   = new StubNotifiable();
        $notification = new NoChannelNotification();

        // Act
        $notifier->sendNow($notifiable, $notification);

        // Assert
        $this->assertEmpty(SpyChannel::$calls, 'No channels → no send() call expected');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // send() — bulk dispatch over multiple notifiables
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * send() must call sendNow() for every entity in the $notifiables array.
     *
     * Three entities → three send() calls on the spy channel.
     */
    public function testSendDispatchesNotificationToAllNotifiables(): void
    {
        // Arrange
        SpyChannel::$calls = [];
        $notifier      = new Notifier();
        $notifier->registerChannel('spy', SpyChannel::class);
        $notification  = new SingleSpyChannelNotification();
        $notifiables   = [new StubNotifiable(), new StubNotifiable(), new StubNotifiable()];

        // Act
        $notifier->send($notifiables, $notification);

        // Assert — one send() call per notifiable
        $this->assertCount(3, SpyChannel::$calls,
            'send() must invoke the channel once per notifiable entity');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Channel resolution
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A fully-qualified class name that implements ChannelInterface must be
     * accepted as a channel name, without prior registration.
     *
     * This allows app-specific channels to be used in via() without patching
     * the framework.
     */
    public function testFullyQualifiedChannelClassIsResolved(): void
    {
        // Arrange
        SpyChannel::$calls = [];
        $notifier      = new Notifier();
        $notifiable    = new StubNotifiable();
        $notification  = new FqcnChannelNotification();  // via() returns [SpyChannel::class]

        // Act — no exception expected
        $notifier->sendNow($notifiable, $notification);

        // Assert
        $this->assertCount(1, SpyChannel::$calls,
            'FQCN channel class must be resolved and called without registration');
    }

    /**
     * An unknown channel name that is neither a registered alias nor a FQCN
     * implementing ChannelInterface must throw an InvalidArgumentException.
     */
    public function testUnknownChannelThrowsInvalidArgumentException(): void
    {
        // Arrange
        $notifier     = new Notifier();
        $notifiable   = new StubNotifiable();
        $notification = new UnknownChannelNotification();

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown notification channel/');

        // Act
        $notifier->sendNow($notifiable, $notification);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // NotifiableTrait — notify() delegates to Notifier
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * NotifiableTrait::notify() must create a Notifier and call sendNow().
     *
     * Verified indirectly: the spy channel is registered via the default
     * constructor, so if notify() calls sendNow() the spy will be invoked.
     * We cannot intercept the Notifier created inside notify(), so we use a
     * global alias registered in the Notifier default map.
     *
     * Instead we test via a custom Notifier-aware notifiable that exposes
     * which Notifier it used — but since NotifiableTrait is not injectable,
     * we just assert the side effect on a real channel.
     */
    public function testNotifiableTraitNotifyCallsSendNow(): void
    {
        // Arrange — use TrackableNotifiable that injects a known spy Notifier
        SpyChannel::$calls = [];
        $notifiable = new TrackableNotifiable();

        // Act
        $notifiable->notify(new SingleSpyChannelNotification());

        // Assert — spy was called
        $this->assertCount(1, SpyChannel::$calls,
            'NotifiableTrait::notify() must call Notifier::sendNow()');
    }

    /**
     * NotifiableTrait::notify() default implementation (line 36) must call
     * Notifier::sendNow() directly. Uses StubNotifiable (no override) so the
     * trait's own body runs. Uses the 'log' built-in channel which writes to
     * a temp path — no side effects on test infrastructure.
     */
    public function testNotifiableTraitDefaultNotifyBodyRuns(): void
    {
        // Arrange — StubNotifiable uses the trait's unoverridden notify()
        $notifiable = new StubNotifiable();

        // Act — calls NotifiableTrait::notify() directly (line 36).
        // LogChannelNotification routes to the built-in 'log' channel so the
        // fresh Notifier constructed inside notify() can resolve it without
        // custom registration.
        $notifiable->notify(new LogChannelNotification());

        // Assert — no exception thrown means line 36 was reached and executed
        $this->addToAssertionCount(1);
    }

    /**
     * NotifiableTrait::routeNotificationFor('mail') returns $this->email.
     */
    public function testNotifiableTraitRoutesMailToEmailProperty(): void
    {
        // Arrange
        $notifiable        = new StubNotifiable();
        $notifiable->email = 'alice@example.com';

        // Act
        $address = $notifiable->routeNotificationFor('mail');

        // Assert
        $this->assertSame('alice@example.com', $address);
    }

    /**
     * NotifiableTrait::routeNotificationFor('database') returns $this->userid
     * when set, or $this->id as fallback.
     */
    public function testNotifiableTraitRoutesDatabaseToUserid(): void
    {
        // Arrange
        $notifiable         = new StubNotifiable();
        $notifiable->userid = 99;

        // Act
        $id = $notifiable->routeNotificationFor('database');

        // Assert
        $this->assertSame(99, $id);
    }

    /**
     * routeNotificationFor() returns null for unknown channels, allowing
     * the channel to decide how to handle a missing address.
     */
    public function testNotifiableTraitReturnsNullForUnknownChannel(): void
    {
        // Arrange
        $notifiable = new StubNotifiable();

        // Act + Assert
        $this->assertNull($notifiable->routeNotificationFor('sms'),
            'Unknown channel must return null — not throw');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // registerChannel()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * registerChannel() must allow overriding a built-in channel alias with a
     * custom implementation.
     */
    public function testRegisterChannelOverridesBuiltInAlias(): void
    {
        // Arrange — override the 'log' alias with SpyChannel
        SpyChannel::$calls = [];
        $notifier = new Notifier();
        $notifier->registerChannel('log', SpyChannel::class);
        $notification = new LogChannelNotification();
        $notifiable   = new StubNotifiable();

        // Act
        $notifier->sendNow($notifiable, $notification);

        // Assert — SpyChannel was used instead of the real LogChannel
        $this->assertCount(1, SpyChannel::$calls,
            'registerChannel() must replace the built-in channel alias');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // A channel that fails, and the channels after it
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A channel that throws must not decide whether the later channels are tried.
     *
     * `ChannelInterface` asks channels not to throw and the built-in ones keep to it, so the
     * ones that do throw are the custom channels the framework invites — talking to somebody
     * else's gateway, over a network. Left to propagate, one of those makes the **order** of
     * `via()` decide whether the mail goes out when the SMS gateway times out: load-bearing,
     * invisible, and nowhere written down.
     *
     * Asserted on the channel *after* the failure, because that is the one that used to be
     * skipped. Asserting that `sendNow()` did not throw would pass on an implementation that
     * swallowed the exception and abandoned the loop anyway.
     */
    public function testAChannelThatThrowsDoesNotStopTheOnesAfterIt(): void
    {
        // Arrange
        SpyChannel::$calls = [];
        $notifier = new Notifier();
        $notifier->registerChannel('boom', ThrowingChannel::class);
        $notifier->registerChannel('spy', SpyChannel::class);

        // Act
        $notifier->sendNow(new StubNotifiable(), new ThrowingThenSpyNotification());

        // Assert
        $this->assertCount(
            1,
            SpyChannel::$calls,
            'the channel listed after the failing one was never called'
        );
    }

    /**
     * `throwOnChannelFailure()` asks for the opposite, for a caller that must know.
     *
     * A queue worker deciding whether to retry the job, or an administration screen that told
     * an operator «sent» and has to be able to take it back. Best-effort is right for the
     * request path and wrong for both of those.
     */
    public function testThrowOnChannelFailureRaisesTheChannelsException(): void
    {
        // Arrange
        SpyChannel::$calls = [];
        $notifier = (new Notifier())->throwOnChannelFailure();
        $notifier->registerChannel('boom', ThrowingChannel::class);
        $notifier->registerChannel('spy', SpyChannel::class);

        // Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the gateway is down');

        // Act
        $notifier->sendNow(new StubNotifiable(), new ThrowingThenSpyNotification());
    }

    /**
     * And it is off unless asked for — the default serves the request path.
     *
     * Somebody changing their password should not be shown a failure because an audit
     * broadcast could not connect. Stated as its own test because a default is exactly the
     * kind of thing a later refactor flips without noticing.
     */
    public function testFailuresAreCaughtUnlessThrowingIsAskedFor(): void
    {
        // Arrange
        $notifier = new Notifier();
        $notifier->registerChannel('boom', ThrowingChannel::class);
        $notifier->registerChannel('spy', SpyChannel::class);

        // Act — no expectException: this must simply return
        $notifier->sendNow(new StubNotifiable(), new ThrowingThenSpyNotification());

        // Assert
        $this->assertTrue(true, 'reaching this line is the assertion');
    }

    /**
     * `throwOnChannelFailure(false)` puts it back, so the setter is a switch and not a latch.
     */
    public function testThrowingCanBeTurnedBackOff(): void
    {
        // Arrange
        $notifier = (new Notifier())->throwOnChannelFailure()->throwOnChannelFailure(false);
        $notifier->registerChannel('boom', ThrowingChannel::class);
        $notifier->registerChannel('spy', SpyChannel::class);
        SpyChannel::$calls = [];

        // Act
        $notifier->sendNow(new StubNotifiable(), new ThrowingThenSpyNotification());

        // Assert
        $this->assertCount(1, SpyChannel::$calls);
    }

    /**
     * An unknown channel name throws even though delivery failures do not.
     *
     * The distinction is the whole point of resolving outside the `try`: a typo in `via()`, or
     * a channel class that was renamed, is a **mistake in the code** and not a delivery that
     * failed. Catching it would turn the one error in this subsystem that a test would catch
     * into a line in a log file nobody reads.
     *
     * The spy assertion is what makes this test about resolution rather than about throwing:
     * the unknown name is first in `via()`, so a `spy` that ran would mean the loop had
     * continued past a bug.
     */
    public function testAnUnknownChannelThrowsEvenThoughFailuresAreCaught(): void
    {
        // Arrange
        SpyChannel::$calls = [];
        $notifier = new Notifier();
        $notifier->registerChannel('spy', SpyChannel::class);

        // Act
        try {
            $notifier->sendNow(new StubNotifiable(), new UnknownThenSpyNotification());
            $this->fail('an unknown channel name was swallowed');
        } catch (\InvalidArgumentException) {
            // Assert — expected, and nothing after it ran
            $this->assertSame([], SpyChannel::$calls);
        }
    }

    /**
     * The message names the form that actually works from where it is read.
     *
     * It used to say «Register it with `Notifier::registerChannel()`» and nothing else, which
     * is advice that cannot be followed: somebody reading this has almost always arrived via
     * `$user->notify()`, and `NotifiableTrait::notify()` builds its **own** `Notifier` — so an
     * alias registered anywhere else does not exist as far as that call is concerned. The one
     * remedy the message gave was the one that could not work.
     */
    public function testTheUnknownChannelMessageNamesTheFormThatWorksThroughNotify(): void
    {
        // Arrange
        $notifier = new Notifier();

        // Act
        try {
            $notifier->sendNow(new StubNotifiable(), new UnknownChannelNotification());
            $this->fail('no exception was raised');
        } catch (\InvalidArgumentException $exception) {
            // Assert
            $message = $exception->getMessage();

            $this->assertStringContainsString(
                'fully-qualified class name',
                $message,
                'the message does not offer the route that needs no registration'
            );
            $this->assertStringContainsString(
                'notify()',
                $message,
                'the message does not say why the alias route may not apply'
            );
        }
    }
}

// =============================================================================
// Stubs and spies
// =============================================================================

/** Spy channel that records all send() calls. */
class SpyChannel implements ChannelInterface
{
    public static array $calls = [];

    public function send(mixed $notifiable, NotificationInterface $notification): void
    {
        self::$calls[] = ['notifiable' => $notifiable, 'notification' => $notification];
    }
}

/** Notifiable stub using the trait. */
class StubNotifiable implements NotifiableInterface
{
    use NotifiableTrait;

    public string $email  = '';
    public int    $userid = 0;
    public int    $id     = 0;

    /** Set only where a test is about the recipient's own language. */
    public string $language = '';
}

/**
 * A notifiable that overrides notify() to inject a known Notifier so the spy
 * channel is in the alias map without modifying the global default map.
 */
class TrackableNotifiable implements NotifiableInterface
{
    use NotifiableTrait;

    public string $email  = '';
    public int    $userid = 0;

    public function notify(NotificationInterface $notification): void
    {
        $notifier = new Notifier();
        $notifier->registerChannel('spy', SpyChannel::class);
        $notifier->sendNow($this, $notification);
    }
}

/** Notification that lists two 'spy' channels. */
class TwoSpyChannelNotification implements NotificationInterface
{
    public function via(mixed $notifiable): array { return ['spy', 'spy']; }
}

/** Notification with no channels. */
class NoChannelNotification implements NotificationInterface
{
    public function via(mixed $notifiable): array { return []; }
}

/** Notification with a single 'spy' channel. */
class SingleSpyChannelNotification implements NotificationInterface
{
    public function via(mixed $notifiable): array { return ['spy']; }
}

/** Notification that passes SpyChannel FQCN as channel name. */
class FqcnChannelNotification implements NotificationInterface
{
    public function via(mixed $notifiable): array { return [SpyChannel::class]; }
}

/** Notification that requests an unknown channel. */
class UnknownChannelNotification implements NotificationInterface
{
    public function via(mixed $notifiable): array { return ['nonexistent_channel_xyz']; }
}

/** Notification for the log alias override test. */
class LogChannelNotification implements NotificationInterface
{
    public function via(mixed $notifiable): array { return ['log']; }
}

/**
 * Records what a translation resolves to at the moment the channel runs.
 */
class LanguageSpyChannel implements ChannelInterface
{
    public static string $seen = '';

    public function send(mixed $notifiable, NotificationInterface $notification): void
    {
        self::$seen = (string) \Pramnos\Framework\Factory::getLanguage()->_('Hello');
    }
}

/**
 * One channel, the language spy.
 */
class LanguageSpyNotification implements NotificationInterface
{
    public function via(mixed $notifiable): array
    {
        return ['langspy'];
    }
}

/** A channel that fails the way a real one does: a gateway that will not answer. */
class ThrowingChannel implements ChannelInterface
{
    public function send(mixed $notifiable, NotificationInterface $notification): void
    {
        throw new \RuntimeException('the gateway is down');
    }
}

/** The failing channel first, so the spy after it is the thing being asserted. */
class ThrowingThenSpyNotification implements NotificationInterface
{
    public function via(mixed $notifiable): array { return ['boom', 'spy']; }
}

/** An unknown name first: a bug, and the spy must not be reached past it. */
class UnknownThenSpyNotification implements NotificationInterface
{
    public function via(mixed $notifiable): array { return ['nonexistent_channel_xyz', 'spy']; }
}
