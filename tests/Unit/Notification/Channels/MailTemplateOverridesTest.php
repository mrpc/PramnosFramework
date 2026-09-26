<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Notification\Channels;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Messaging\MailTemplate;
use Pramnos\Notification\Channels\MailChannel;
use Pramnos\Notification\NotificationInterface;

/** A channel whose template lookup is scripted, so the rules can be tested without a database. */
class ScriptedTemplateChannel extends MailChannel
{
    public ?array $template = null;

    /** @var list<string> Every category this channel was asked about. */
    public array $askedFor = [];

    protected function templateFor(string $category): ?array
    {
        $this->askedFor[] = $category;

        return $this->template;
    }
}

/** A notification that declares a template key and some variables. */
class OverridableNotification implements NotificationInterface
{
    /** @param array<string, string|int> $vars */
    public function __construct(
        private array $mail,
        private string $category = 'auth.twofactor_code',
        private array $vars = []
    ) {
    }

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    /** @return array<string, mixed> */
    public function toMail(mixed $notifiable): array
    {
        return $this->mail;
    }

    /** @return array{category: string, vars: array<string, string|int>} */
    public function storedMailTemplate(): array
    {
        return ['category' => $this->category, 'vars' => $this->vars];
    }
}

/**
 * An operator's template replaces what the class composed — field by field.
 *
 * The `mailtemplates` table, its model and a full administration screen — list, edit,
 * delete and **test send** — shipped long ago, and **nothing read a template when
 * sending**. An operator could write one, save it, send themselves a test of it, and every
 * real message still went out with the text compiled into the notification class. A screen
 * that implies a capability the system does not have is worse than no screen: the text
 * looks changed and is not.
 *
 * The rule these tests hold is the one that makes the feature safe to switch on for
 * everybody: **empty means keep the default**. A row whose body is blank is an operator who
 * filled in the subject and nothing else, not an instruction to send an empty email.
 */
#[CoversClass(MailChannel::class)]
#[CoversClass(MailTemplate::class)]
class MailTemplateOverridesTest extends TestCase
{
    /**
     * A template row as `MailTemplate::lookup()` answers.
     *
     * An array rather than a model: `Model::__construct()` requires a `Controller`, and a
     * notification channel has none — mail is sent from a queue worker, a console command,
     * a second-factor step. The first version of the lookup was an instance method and was
     * therefore broken everywhere it mattered.
     *
     * @return array{subject: string, body: string, emailtemplate: string}
     */
    private function template(string $subject, string $body, string $wrapper = ''): array
    {
        return ['subject' => $subject, 'body' => $body, 'emailtemplate' => $wrapper];
    }

    /** Send through a channel whose lookup answers `$template`, and report what was sent. */
    private function send(
        OverridableNotification $notification,
        ?array $template
    ): SpyEmail {
        $spy     = new SpyEmail();
        $channel = new ScriptedTemplateChannel($spy);
        $channel->template = $template;

        $channel->send(new MailNotifiable('reader@example.com'), $notification);

        return $spy;
    }

    /**
     * A complete template replaces both fields.
     */
    public function testACompleteTemplateReplacesSubjectAndBody(): void
    {
        // Arrange
        $notification = new OverridableNotification(
            ['subject' => 'Built-in subject', 'body' => '<p>Built-in body</p>']
        );

        // Act
        $spy = $this->send($notification, $this->template('Operator subject', 'Operator body'));

        // Assert
        $this->assertSame('Operator subject', $spy->subject);
        $this->assertSame('Operator body', $spy->body);
    }

    /**
     * An empty body keeps the built-in body, and the template's subject still wins.
     *
     * **The rule the whole feature rests on.** An operator who filled in the subject and
     * left the body alone has not asked for an empty email, and a channel that read the row
     * wholesale would send one — silently, to everybody, for as long as nobody looked.
     */
    public function testAnEmptyBodyKeepsTheBuiltInOne(): void
    {
        // Arrange
        $notification = new OverridableNotification(
            ['subject' => 'Built-in subject', 'body' => '<p>Built-in body</p>']
        );

        // Act
        $spy = $this->send($notification, $this->template('Operator subject', '   '));

        // Assert
        $this->assertSame('Operator subject', $spy->subject);
        $this->assertSame('<p>Built-in body</p>', $spy->body, 'an empty template body sent an empty email');
    }

    /**
     * And the other half: an empty subject keeps the built-in subject.
     */
    public function testAnEmptySubjectKeepsTheBuiltInOne(): void
    {
        // Arrange
        $notification = new OverridableNotification(
            ['subject' => 'Built-in subject', 'body' => '<p>Built-in body</p>']
        );

        // Act
        $spy = $this->send($notification, $this->template('', 'Operator body'));

        // Assert
        $this->assertSame('Built-in subject', $spy->subject);
        $this->assertSame('Operator body', $spy->body);
    }

    /**
     * No template at all changes nothing.
     *
     * Every installation that has written no templates is in this case, so it is the one
     * that must be exactly today's behaviour.
     */
    public function testNoTemplateLeavesTheMessageAlone(): void
    {
        // Arrange
        $notification = new OverridableNotification(
            ['subject' => 'Built-in subject', 'body' => '<p>Built-in body</p>']
        );

        // Act
        $spy = $this->send($notification, null);

        // Assert
        $this->assertSame('Built-in subject', $spy->subject);
        $this->assertSame('<p>Built-in body</p>', $spy->body);
    }

    /**
     * A notification that declares no template is never even looked up.
     *
     * Opting in is per notification, read through `method_exists()` like `queueable()` and
     * `unsubscribeList()` beside it. The name is `storedMailTemplate()` and not
     * `mailTemplate()` because that one was taken — it names the HTML wrapper, and reusing
     * it set the wrapper to the string `Array`. A notification that says nothing must not pay for a
     * database query on every send.
     */
    public function testANotificationWithoutADeclarationIsNotLookedUp(): void
    {
        // Arrange — the plain notification from the neighbouring suite declares nothing
        $spy     = new SpyEmail();
        $channel = new ScriptedTemplateChannel($spy);
        $channel->template = $this->template('Operator subject', 'Operator body');

        // Act
        $channel->send(
            new MailNotifiable('reader@example.com'),
            new SimpleMailNotification(['subject' => 'Built-in', 'body' => 'Body'])
        );

        // Assert
        $this->assertSame([], $channel->askedFor, 'a notification that declares nothing was looked up');
        $this->assertSame('Built-in', $spy->subject);
    }

    /**
     * A declaration with no category is not looked up.
     *
     * The shape an application reaches by computing the category and getting `''` back —
     * from a setting nobody filled in, or a branch that did not match. Nothing is looked
     * up and the class's own text is sent, which is the only safe reading of "I could not
     * say which template".
     */
    public function testAnEmptyCategoryIsNotLookedUp(): void
    {
        // Arrange
        $spy     = new SpyEmail();
        $channel = new ScriptedTemplateChannel($spy);
        $channel->template = $this->template('Operator subject', 'Operator body');

        // Act
        $channel->send(
            new MailNotifiable('reader@example.com'),
            new OverridableNotification(['subject' => 'Built-in', 'body' => 'Body'], '')
        );

        // Assert
        $this->assertSame([], $channel->askedFor);
        $this->assertSame('Built-in', $spy->subject);
    }

    /**
     * Placeholders are substituted from the variables the notification supplies.
     */
    public function testPlaceholdersAreSubstituted(): void
    {
        // Arrange
        $notification = new OverridableNotification(
            ['subject' => 'Built-in', 'body' => 'Built-in'],
            'auth.twofactor_code',
            ['code' => '481920', 'minutes' => 10]
        );

        // Act
        $spy = $this->send(
            $notification,
            $this->template('{code} is your code', 'It expires in {minutes} minutes.')
        );

        // Assert
        $this->assertSame('481920 is your code', $spy->subject);
        $this->assertSame('It expires in 10 minutes.', $spy->body);
    }

    /**
     * An unknown placeholder is left visible rather than blanked.
     *
     * Deleting it would make a template look correct in the editor and arrive wrong.
     * Leaving `{firstname}` in the text is a mistake somebody can see in a test send,
     * which is what the test-send button on that screen is for.
     */
    public function testAnUnknownPlaceholderSurvives(): void
    {
        // Arrange
        $notification = new OverridableNotification(
            ['subject' => 'Built-in', 'body' => 'Built-in'],
            'auth.twofactor_code',
            ['code' => '481920']
        );

        // Act
        $spy = $this->send($notification, $this->template('x', 'Hello {firstname}, your code is {code}.'));

        // Assert
        $this->assertSame('Hello {firstname}, your code is 481920.', $spy->body);
    }

    /**
     * The template's HTML wrapper is applied.
     *
     * Choosing the wrapper is most of why an operator opens that screen, and until now the
     * field was written to the database and read only by a test send.
     */
    public function testTheWrapperFromTheTemplateIsApplied(): void
    {
        // Arrange
        $notification = new OverridableNotification(['subject' => 'x', 'body' => 'y']);

        // Act
        $spy = $this->send($notification, $this->template('s', 'b', 'marketing'));

        // Assert
        $this->assertTrue($spy->templateSet);
        $this->assertSame('marketing', $spy->templateGiven);
    }

    /**
     * A lookup that raises leaves the composed message alone.
     *
     * An installation that never migrated the messaging tables still sends its mail: a
     * template is an override, and failing to find one is not a failure to send.
     */
    public function testALookupThatRaisesDoesNotStopTheMail(): void
    {
        // Arrange
        $spy     = new SpyEmail();
        $channel = new class ($spy) extends MailChannel {
            protected function templateFor(string $category): ?array
            {
                throw new \RuntimeException('no such table: mailtemplates');
            }
        };

        // Act + Assert — the throw escapes only if it is not handled at the seam
        try {
            $channel->send(
                new MailNotifiable('reader@example.com'),
                new OverridableNotification(['subject' => 'Built-in', 'body' => 'Body'])
            );
        } catch (\Throwable $exception) {
            $this->fail('a failed template lookup stopped the mail: ' . $exception->getMessage());
        }

        $this->assertSame(1, $spy->sendCount);
        $this->assertSame('Built-in', $spy->subject);
    }
}
