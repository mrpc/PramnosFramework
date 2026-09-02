<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\Unsubscribe;

/**
 * The plain-text answer an unsubscribe endpoint gives a machine.
 *
 * Five statements, never executed. This is the reply to a `List-Unsubscribe-Post` — a mail client
 * pressing the unsubscribe button on the user's behalf — so the audience is software, and the
 * requirements are the ones software has: a status code it can branch on, a content type it will
 * not try to render, and a body it can log.
 *
 * The `headers_sent()` guard is not defensiveness: this can be reached after output has begun
 * (a warning, a partially rendered page), and `header()` at that point is a PHP warning printed
 * into the response — which is worse than the missing header, because it corrupts the body the
 * client is about to read.
 */
#[CoversClass(Unsubscribe::class)]
class UnsubscribeRespondTest extends TestCase
{
    /** Exposes the seam and captures what it writes. */
    private function respond(int $status, string $message): string
    {
        $controller = new class extends Unsubscribe {
            public function __construct() {}

            public function exposeRespond(int $status, string $message): void
            {
                $this->respond($status, $message);
            }
        };

        ob_start();

        try {
            $controller->exposeRespond($status, $message);

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    /**
     * The message is written, with a trailing newline.
     *
     * A line rather than a bare string, because the reader is a log or a terminal and a response
     * body without a newline runs into whatever is printed next.
     */
    public function testTheMessageIsWrittenAsALine(): void
    {
        // Act
        $body = $this->respond(200, 'Unsubscribed');

        // Assert
        $this->assertSame("Unsubscribed\n", $body);
    }

    /**
     * The status code is set.
     *
     * What the mail client branches on. A `200` for a refusal would have it record the
     * unsubscribe as done.
     */
    public function testTheStatusCodeIsSet(): void
    {
        // Act
        $this->respond(422, 'Missing token');

        // Assert
        $this->assertSame(422, http_response_code());
    }

    /**
     * An empty message is still a line.
     *
     * A response with a status and no explanation is legitimate — `204`-shaped behaviour with a
     * body the client ignores — and it must not produce a bare, unterminated response.
     */
    public function testAnEmptyMessageIsStillALine(): void
    {
        // Act
        $body = $this->respond(200, '');

        // Assert
        $this->assertSame("\n", $body);
    }

    /**
     * The body is exactly the message — no markup, no wrapper.
     *
     * The distinction from `page()`, which is the answer for a person. A mail client handed HTML
     * here would either render it in a status area or log the tags.
     */
    public function testTheBodyCarriesNoMarkup(): void
    {
        // Act
        $body = $this->respond(200, 'You are unsubscribed from newsletter');

        // Assert
        $this->assertStringNotContainsString('<', $body);
        $this->assertStringNotContainsString('>', $body);
    }
}
