<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\Mailinglist;
use Pramnos\Cache\Cache;
use Pramnos\Email\MailAction;
use Pramnos\Email\MailingList as Lists;
use Pramnos\Email\MailType;
use Pramnos\Email\MailTypes;
use Pramnos\Http\Middleware\RateLimitMiddleware;
use Pramnos\Http\TooManyRequestsException;

/**
 * `/mailinglist/subscribe` and `/mailinglist/confirm` — what the public half refuses and says.
 *
 * The store is covered against real databases in `MailingListTest`; this is the endpoint: that a
 * stale form, an unknown list and a bad address are refused before anything is written, that the
 * answer to a valid request does not depend on the address, that the consent stored is the one the
 * application declared, and that the confirmation link needs a button press, because mail scanners
 * follow links.
 */
#[CoversClass(Mailinglist::class)]
class MailinglistControllerTest extends TestCase
{
    protected function setUp(): void
    {
        MailTypes::reset();
        MailTypes::register(new MailType('newsletter', 'Newsletter', 'News, monthly.', list: 'newsletter', optIn: true));
        MailTypes::register(new MailType('digest', 'Digest', 'Weekly.', list: 'digest'));
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        unset($_SERVER['HTTP_ACCEPT'], $_GET['t'], $_REQUEST['t']);
    }

    protected function tearDown(): void
    {
        MailTypes::reset();
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_SERVER['HTTP_ACCEPT'], $_GET['t'], $_REQUEST['t']);
    }

    /**
     * The controller with a recording store, a form token it is told is valid or not, and a
     * limiter over an in-memory cache.
     */
    private function endpoint(bool $validToken = true, int $limit = 100, ?string $state = 'confirmation_sent', ?array $confirms = null): object
    {
        $store = new class ($state, $confirms) extends Lists {
            public array $calls = [];

            public function __construct(private ?string $state, private ?array $confirms)
            {
            }

            public function subscribe(string $list, string $email, array $options = []): string
            {
                $this->calls[] = [$list, $email, $options];
                if ($this->state === null) {
                    throw new \RuntimeException('database down');
                }

                return $this->state;
            }

            public function confirm(string $token): ?array
            {
                $this->calls[] = ['confirm', $token];

                return $this->confirms;
            }
        };

        return new class ($store, $validToken, $limit) extends Mailinglist {
            public function __construct(public Lists $store, private bool $validToken, private int $limit)
            {
            }

            protected function lists(): Lists
            {
                return $this->store;
            }

            protected function validFormToken(): bool
            {
                return $this->validToken;
            }

            protected function limiter(): RateLimitMiddleware
            {
                return new RateLimitMiddleware($this->limit, 3600, 'test-mailinglist:', new Cache(null, null, 'array'));
            }
        };
    }

    private function call(object $endpoint, string $action): string
    {
        ob_start();
        try {
            $endpoint->$action();
        } finally {
            $out = (string) ob_get_clean();
        }

        return $out;
    }

    /**
     * A valid request is subscribed with the application's consent sentence, not the visitor's.
     *
     * A consent trail whose sentence the browser supplied proves nothing, so the description of
     * the registered type is what is stored. A `source` that is not a short identifier is
     * replaced, because the value is the visitor's.
     */
    public function testAValidRequestSubscribesWithTheDeclaredConsent(): void
    {
        // Arrange
        $_POST = ['email' => 'reader@example.com', 'list' => 'newsletter', 'source' => '<script>', 'consent' => 'I agree to everything'];
        $endpoint = $this->endpoint();

        // Act
        $out = $this->call($endpoint, 'subscribe');

        // Assert
        [$list, $email, $options] = $endpoint->store->calls[0];
        $this->assertSame('newsletter', $list);
        $this->assertSame('reader@example.com', $email);
        $this->assertSame('News, monthly.', $options['consent']);
        $this->assertSame('form', $options['source']);
        $this->assertStringContainsString('Check your inbox', $out);
    }

    /**
     * The answer is the same whether the address was new, pending, subscribed — or failed.
     *
     * Otherwise the form is a way to find out who is on the list.
     */
    public function testTheAnswerDoesNotDependOnTheAddress(): void
    {
        // Arrange
        $_POST = ['email' => 'reader@example.com', 'list' => 'newsletter', 'source' => 'landing'];

        // Act
        $answers = [];
        foreach (['confirmation_sent', 'already_pending', 'already_confirmed', null] as $state) {
            $answers[] = $this->call($this->endpoint(state: $state), 'subscribe');
        }

        // Assert
        $this->assertCount(1, array_unique($answers), 'every outcome must read the same');
    }

    /**
     * A script gets JSON; the source it names is kept when it is a short identifier.
     */
    public function testAScriptGetsJson(): void
    {
        // Arrange
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_POST = ['email' => 'reader@example.com', 'list' => 'newsletter', 'source' => 'wordpress'];
        $endpoint = $this->endpoint();

        // Act
        $answer = json_decode($this->call($endpoint, 'subscribe'), true);

        // Assert
        $this->assertTrue($answer['ok']);
        $this->assertSame('wordpress', $endpoint->store->calls[0][2]['source']);
    }

    /**
     * A stale form, a GET, a list nobody declared opt-in, and a malformed address are refused
     * before the store is asked anything.
     *
     * @param array<string, string> $post
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testARefusedRequestWritesNothing(string $method, bool $token, array $post, string $says): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = $method;
        $_POST = $post;
        $endpoint = $this->endpoint(validToken: $token);

        // Act
        $out = $this->call($endpoint, 'subscribe');

        // Assert
        $this->assertSame([], $endpoint->store->calls);
        $this->assertStringContainsString($says, $out);
    }

    /** @return array<string, array{0:string,1:bool,2:array<string,string>,3:string}> */
    public static function refusals(): array
    {
        $ok = ['email' => 'reader@example.com', 'list' => 'newsletter'];

        return [
            'a GET'              => ['GET', true, $ok, 'form post'],
            'a stale form'       => ['POST', false, $ok, 'expired'],
            'an unknown list'    => ['POST', true, ['list' => 'nosuch'] + $ok, 'no such list'],
            'an opt-out list'    => ['POST', true, ['list' => 'digest'] + $ok, 'no such list'],
            'a malformed address' => ['POST', true, ['email' => 'not-an-address'] + $ok, 'not an email address'],
        ];
    }

    /** The form is rate-limited per address. */
    public function testTheFormIsRateLimited(): void
    {
        // Arrange
        $_POST = ['email' => 'reader@example.com', 'list' => 'newsletter'];

        // Assert
        $this->expectException(TooManyRequestsException::class);

        // Act
        $this->call($this->endpoint(limit: 0), 'subscribe');
    }

    /**
     * The link opens a page with a button and confirms nothing on its own.
     *
     * Mail scanners follow every link in a message; confirming on the GET would subscribe
     * everybody whose provider checks links.
     */
    public function testTheLinkShowsAButtonAndConfirmsNothing(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $token = MailAction::token(Lists::CONFIRM_ACTION, ['l' => 'newsletter', 'e' => 'reader@example.com']);
        $_GET['t'] = $_REQUEST['t'] = $token;
        $endpoint = $this->endpoint();

        // Act
        $out = $this->call($endpoint, 'confirm');

        // Assert
        $this->assertStringContainsString('<form method="post">', $out);
        $this->assertStringContainsString(htmlspecialchars($token, ENT_QUOTES), $out);
        $this->assertStringContainsString('Newsletter', $out);
        $this->assertSame([], $endpoint->store->calls);
    }

    /** Pressing the button confirms, and says what for. */
    public function testPressingTheButtonConfirms(): void
    {
        // Arrange
        $token = MailAction::token(Lists::CONFIRM_ACTION, ['l' => 'newsletter', 'e' => 'reader@example.com']);
        $_POST['t'] = $_REQUEST['t'] = $token;
        $endpoint = $this->endpoint(confirms: ['list' => 'newsletter', 'email' => 'reader@example.com']);

        // Act
        $out = $this->call($endpoint, 'confirm');

        // Assert
        $this->assertSame([['confirm', $token]], $endpoint->store->calls);
        $this->assertStringContainsString('You are subscribed', $out);
        $this->assertStringContainsString('reader@example.com', $out);
    }

    /** A valid link for a row that is no longer pending says so. */
    public function testALinkForARowNoLongerPendingSaysSo(): void
    {
        // Arrange
        $_POST['t'] = $_REQUEST['t'] = MailAction::token(Lists::CONFIRM_ACTION, ['l' => 'newsletter', 'e' => 'reader@example.com']);

        // Act
        $out = $this->call($this->endpoint(confirms: null), 'confirm');

        // Assert
        $this->assertStringContainsString('Nothing to confirm', $out);
    }

    /** A token that is not a confirmation, or none at all, is a dead link. */
    public function testATokenThatIsNotAConfirmationIsADeadLink(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET['t'] = $_REQUEST['t'] = MailAction::token('revoke-sessions', ['user' => 5]);

        // Act
        $out = $this->call($this->endpoint(), 'confirm');

        // Assert
        $this->assertStringContainsString('no longer works', $out);
    }

    /** `/mailinglist` itself is not a page. */
    public function testTheBareAddressIsNotFound(): void
    {
        // Act
        $out = $this->call($this->endpoint(), 'display');

        // Assert
        $this->assertStringContainsString('Not found', $out);
    }

    /**
     * The confirmation page says what happened in `data-` attributes a script can read.
     *
     * The confirmed double opt-in is what a newsletter's analytics counts, and the page is the
     * only place a tag manager sees it.
     */
    public function testTheConfirmationPageCarriesItsState(): void
    {
        // Arrange
        $token = MailAction::token(Lists::CONFIRM_ACTION, ['l' => 'newsletter', 'e' => 'reader@example.com']);
        $_POST['t'] = $_REQUEST['t'] = $token;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $confirmed = $this->endpoint(confirms: ['list' => 'newsletter', 'email' => 'reader@example.com']);
        $nothing   = $this->endpoint(confirms: null);

        // Act
        $yes = $this->call($confirmed, 'confirm');
        $no  = $this->call($nothing, 'confirm');

        // Assert
        $this->assertStringContainsString('data-state="confirmed" data-list="newsletter"', $yes);
        $this->assertStringContainsString('data-state="nothing-to-confirm"', $no);
    }

    /**
     * A script posting the form is told the state too, the same whatever the address was.
     */
    public function testAScriptIsToldThePendingState(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_ACCEPT']    = 'application/json';
        $_POST = ['email' => 'reader@example.com', 'list' => 'newsletter', '_csrf_token' => 'x'];
        $endpoint = $this->endpoint();

        // Act
        $json = json_decode($this->call($endpoint, 'subscribe'), true);

        // Assert
        $this->assertSame('pending', $json['state']);
        $this->assertSame('newsletter', $json['list']);
    }

    /**
     * With a theme that has the standalone layout, the page is the theme's, not a bare one.
     *
     * The layout carries the application's head — scripts, Tag Manager, the cookie banner —
     * and its footer with a way back to the site.
     */
    public function testAThemeWithAStandaloneLayoutGetsThePage(): void
    {
        // Arrange — a theme directory with login.php, on the html document
        $dir = sys_get_temp_dir() . '/theme_' . bin2hex(random_bytes(4));
        mkdir($dir);
        touch($dir . '/login.php');
        $theme = new class ($dir) {
            public string $contentType = '';

            public function __construct(public string $fullpath)
            {
            }

            public function setContentType(string $type): void
            {
                $this->contentType = $type;
            }
        };
        $doc = \Pramnos\Framework\Factory::getDocument('html');
        $previousTheme = $doc->themeObject ?? null;
        $doc->themeObject = $theme;
        $token = MailAction::token(Lists::CONFIRM_ACTION, ['l' => 'newsletter', 'e' => 'reader@example.com']);
        $_POST['t'] = $_REQUEST['t'] = $token;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $endpoint = $this->endpoint(confirms: ['list' => 'newsletter', 'email' => 'reader@example.com']);

        // Act
        try {
            $out     = $this->call($endpoint, 'confirm');
            $content = (string) ($doc->content ?? '');
            if ($content === '' && method_exists($doc, 'getContent')) {
                $content = (string) $doc->getContent();
            }
        } finally {
            $doc->themeObject = $previousTheme;
            $doc->setContent('');
            \Pramnos\Framework\Factory::getDocument('raw');
            @unlink($dir . '/login.php');
            @rmdir($dir);
        }

        // Assert — nothing echoed bare; the theme's login layout, carrying the page and its state
        $this->assertSame('', $out);
        $this->assertSame('login', $theme->contentType);
        $this->assertStringContainsString('You are subscribed', $content);
        $this->assertStringContainsString('data-state="confirmed"', $content);
    }
}
