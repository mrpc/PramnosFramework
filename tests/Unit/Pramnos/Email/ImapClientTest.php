<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Email\ImapClient;
use PHPUnit\Framework\TestCase;

/**
 * Just enough IMAP. What matters: the commands go out tagged and quoted; the
 * unread UIDs are read, the whole message comes as a literal of exactly its
 * length; a refusal says which command, never the password; a server that
 * closes or does not greet as IMAP says so.
 */
#[CoversClass(ImapClient::class)]
final class ImapClientTest extends TestCase
{
    /** A client on one end of a socket pair, the server's answers written on the other. @return array{ImapClient, resource} */
    private function server(string $answers): array
    {
        [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($server, $answers);

        return [new ImapClient($client), $server];
    }

    /** Read what the client sent, from the server's end. */
    private static function sent($server): string
    {
        stream_set_blocking($server, false);

        return (string) stream_get_contents($server);
    }

    /** A session: greeting, login, select, the unread, one message, marked read, logout. */
    public function testItReadsTheUnreadAndMarksThemRead(): void
    {
        // Arrange
        $message = "From: pr@label.example\r\nSubject: Blue Remixes\r\n\r\nThe release.\r\n";
        [$imap, $server] = $this->server(
            "* OK IMAP ready\r\n"
            . "A1 OK LOGIN done\r\n"
            . "* 3 EXISTS\r\nA2 OK [READ-WRITE] SELECT done\r\n"
            . "* SEARCH 12 7\r\nA3 OK SEARCH done\r\n"
            . "* 1 FETCH (UID 7 BODY[] {" . strlen($message) . "}\r\n" . $message . ")\r\nA4 OK FETCH done\r\n"
            . "A5 OK STORE done\r\n"
            . "* BYE\r\nA6 OK LOGOUT done\r\n"
        );

        // Act
        $imap->connect('mail.example', 993);
        $imap->login('press@example.com', 'pa"ss\\word');
        $imap->select();
        $uids = $imap->unseen();
        $raw  = $imap->fetch(7);
        $imap->markSeen(7);
        $sent = self::sent($server);
        $imap->logout();
        $imap->logout();

        // Assert
        $this->assertSame([7, 12], $uids, 'oldest first');
        $this->assertSame($message, $raw);
        $this->assertStringContainsString('A1 LOGIN "press@example.com" "pa\\"ss\\\\word"' . "\r\n", $sent);
        $this->assertStringContainsString("A2 SELECT \"INBOX\"\r\nA3 UID SEARCH UNSEEN\r\nA4 UID FETCH 7 (BODY.PEEK[])\r\nA5 UID STORE 7 +FLAGS.SILENT (\\Seen)\r\n", $sent);
    }

    /**
     * The mailbox kept tidy: the read ones before a day are
     * found with IMAP's own date form, English and day-month-year whatever the
     * locale; a deleted one is marked deleted and read; expunge removes them.
     */
    public function testItFindsTheOldAndDeletesThem(): void
    {
        // Arrange
        [$imap, $server] = $this->server(
            "* OK IMAP ready\r\n"
            . "* SEARCH 3 9\r\nA1 OK SEARCH done\r\n"
            . "* SEARCH\r\nA2 OK SEARCH done\r\n"
            . "A3 OK STORE done\r\n"
            . "* 1 EXPUNGE\r\nA4 OK EXPUNGE done\r\n"
        );
        $imap->connect('mail.example', 993);

        // Act
        $old  = $imap->seenBefore((int) strtotime('2026-09-21 13:00 UTC'));
        $none = $imap->seenBefore((int) strtotime('2026-01-05 00:00 UTC'));
        $imap->delete(3);
        $imap->expunge();
        $sent = self::sent($server);

        // Assert
        $this->assertSame([3, 9], $old);
        $this->assertSame([], $none);
        $this->assertStringContainsString("A1 UID SEARCH SEEN BEFORE 21-Sep-2026\r\nA2 UID SEARCH SEEN BEFORE 5-Jan-2026\r\nA3 UID STORE 3 +FLAGS.SILENT (\\Deleted \\Seen)\r\nA4 EXPUNGE\r\n", $sent);
    }

    /** A refused login says LOGIN and the server's words — never the password it was sent. */
    public function testARefusalSaysWhichCommandAndNotThePassword(): void
    {
        // Arrange
        [$imap, $server] = $this->server("* OK ready\r\nA1 NO [AUTHENTICATIONFAILED] Invalid credentials\r\n");
        $imap->connect('mail.example', 993);

        // Act
        $said = null;
        try {
            $imap->login('press', 'secret-password');
        } catch (\RuntimeException $refused) {
            $said = $refused->getMessage();
        }

        // Assert
        $this->assertSame('The mail server refused LOGIN: NO [AUTHENTICATIONFAILED] Invalid credentials', $said, 'a refused login throws');
        $this->assertStringNotContainsString('secret-password', (string) $said);
    }

    /** A server that does not greet as IMAP, one that closes, one that stops mid-message, and none at all: each says so. */
    public function testWhatIsNotAnImapServerSaysSo(): void
    {
        // Arrange
        [$smtp]   = $this->server("220 mail.example ESMTP\r\n");
        [$closes, $closing] = $this->server("* OK ready\r\n");
        [$cut, $cutting]    = $this->server("* OK ready\r\n* 1 FETCH (BODY[] {100}\r\nonly a little");
        $closes->connect('mail.example', 993);
        // The server stops talking but still hears: the client's write goes, its read finds the end.
        stream_socket_shutdown($closing, STREAM_SHUT_WR);
        $cut->connect('mail.example', 993);
        stream_socket_shutdown($cutting, STREAM_SHUT_WR);
        $said = [];

        // Act
        foreach ([fn () => $smtp->connect('mail.example', 993), fn () => $closes->unseen(), fn () => $cut->fetch(1), fn () => (new ImapClient())->connect('127.0.0.1', 1, 'none')] as $call) {
            try {
                $call();
            } catch (\RuntimeException $failed) {
                $said[] = $failed->getMessage();
            }
        }

        // Assert
        $this->assertSame('The mail server did not greet as IMAP: 220 mail.example ESMTP', $said[0]);
        $this->assertSame('The mail server closed the connection.', $said[1]);
        $this->assertSame('The mail server closed the connection mid-message.', $said[2]);
        $this->assertStringStartsWith('Could not reach the mail server at 127.0.0.1:1', $said[3]);
        // Saying goodbye to a server that stopped talking is quiet.
        $cut->logout();
        // And one that has hung up altogether: the write itself fails.
        fclose($closing);
        $this->expectExceptionMessage('The mail server closed the connection.');
        $closes->select();
    }

    /** STARTTLS is asked for on a plain connection; a server that refuses it says so. */
    public function testStartTlsIsAskedFor(): void
    {
        // Arrange
        [$imap, $server] = $this->server("* OK ready\r\nA1 BAD STARTTLS not supported\r\n");

        // Act
        try {
            $imap->connect('mail.example', 143, 'tls');
        } catch (\RuntimeException $refused) {
            $said = $refused->getMessage();
        }

        // Assert
        $this->assertSame("A1 STARTTLS\r\n", self::sent($server));
        $this->assertSame('The mail server refused STARTTLS: BAD STARTTLS not supported', $said ?? '');
    }

    /**
     * Signed in with an OAuth2 access token, the SASL XOAUTH2 string goes in the command; a
     * refused token is answered with the server's continuation, an empty line, and then said.
     */
    public function testXoauth2SignsInAndARefusalIsSaid(): void
    {
        // Arrange
        $sasl = base64_encode("user=bounces@example.com\x01auth=Bearer tok-123\x01\x01");
        [$imap, $server] = $this->server("* OK ready\r\nA1 OK signed in\r\n");
        [$refused, $refusing] = $this->server("* OK ready\r\n+ eyJzdGF0dXMiOiI0MDAifQ==\r\nA1 NO [AUTHENTICATIONFAILED] Invalid credentials\r\n");
        $imap->connect('imap.example.com', 993);
        $refused->connect('imap.example.com', 993);

        // Act
        $imap->authenticate('bounces@example.com', 'tok-123');
        $said = '';
        try {
            $refused->authenticate('bounces@example.com', 'stale');
        } catch (\RuntimeException $e) {
            $said = $e->getMessage();
        }

        // Assert
        $this->assertSame('A1 AUTHENTICATE XOAUTH2 ' . $sasl . "\r\n", self::sent($server));
        $this->assertStringEndsWith("\r\n\r\n", self::sent($refusing), 'the continuation answered with an empty line');
        $this->assertSame('The mail server refused AUTHENTICATE: NO [AUTHENTICATIONFAILED] Invalid credentials', $said);
    }
}
