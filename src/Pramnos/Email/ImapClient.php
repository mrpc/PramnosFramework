<?php

declare(strict_types=1);

namespace Pramnos\Email;

/**
 * Just enough IMAP (RFC 3501) to read a mailbox: its unread messages, whole and without marking
 * them, marked read or deleted once dealt with, and what has been read for a while cleared away.
 *
 * ```php
 * $imap = new ImapClient();
 * $imap->connect('imap.example.com', 993);          // 'ssl' (993), 'tls' (STARTTLS, 143) or 'none'
 * $imap->login('bounces@example.com', $password);   // or authenticate($user, $accessToken): XOAUTH2
 * $imap->select('INBOX');
 * foreach ($imap->unseen() as $uid) {
 *     $raw = $imap->fetch($uid);
 *     $imap->markSeen($uid);
 * }
 * $imap->logout();
 * ```
 *
 * PHP no longer ships its `imap` extension, and a library for a handful of commands would be more
 * than the commands. Over a socket, TLS from the start or STARTTLS, so the mail server can be this
 * machine or another. {@see BounceMailbox} reads the bounce mailbox with it.
 */
class ImapClient
{
    public const TIMEOUT = 20;

    /** @var resource|null */
    private $stream = null;

    private int $tag = 0;

    /**
     * @param resource|null $stream An open connection, for tests; else one is opened
     */
    public function __construct($stream = null)
    {
        $this->stream = $stream;
    }

    /**
     * Connect and read the greeting.
     *
     * @param string $security 'ssl' (implicit TLS), 'tls' (STARTTLS) or 'none'
     */
    public function connect(string $host, int $port, string $security = 'ssl'): void
    {
        // @codeCoverageIgnoreStart The network itself: a test hands the client a connected socket instead.
        if ($this->stream === null) {
            $context = stream_context_create(['ssl' => ['peer_name' => $host, 'SNI_enabled' => true]]);
            $stream  = @stream_socket_client(($security === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $error, self::TIMEOUT, STREAM_CLIENT_CONNECT, $context);

            if ($stream === false) {
                throw new \RuntimeException('Could not reach the mail server at ' . $host . ':' . $port . ' (' . $error . ').');
            }

            stream_set_timeout($stream, self::TIMEOUT);
            $this->stream = $stream;
        }
        // @codeCoverageIgnoreEnd

        $greeting = $this->line();

        if (!str_starts_with($greeting, '* OK') && !str_starts_with($greeting, '* PREAUTH')) {
            throw new \RuntimeException('The mail server did not greet as IMAP: ' . trim($greeting));
        }

        if ($security === 'tls') {
            $this->command('STARTTLS');

            // A real TLS handshake is not something a socket pair in a test can do.
            // @codeCoverageIgnoreStart
            if (!@stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('The mail server refused to start TLS.');
            }
            // @codeCoverageIgnoreEnd
        }
    }

    /** Sign in with a password. */
    public function login(string $user, string $password): void
    {
        $this->command('LOGIN ' . self::quote($user) . ' ' . self::quote($password));
    }

    /**
     * Sign in with an OAuth2 access token (SASL XOAUTH2), as Gmail and Microsoft 365 want for a
     * mailbox whose password signs in nowhere else.
     */
    public function authenticate(string $user, string $accessToken): void
    {
        $this->command('AUTHENTICATE XOAUTH2 ' . base64_encode('user=' . $user . "\x01auth=Bearer " . $accessToken . "\x01\x01"));
    }

    /** Open a folder. */
    public function select(string $folder = 'INBOX'): void
    {
        $this->command('SELECT ' . self::quote($folder));
    }

    /** @return list<int> The UIDs of the unread messages, oldest first. */
    public function unseen(): array
    {
        return $this->search('UNSEEN');
    }

    /**
     * Messages already read that arrived before a day: what a mailbox keeps
     * only for a while.
     *
     * @return list<int>
     */
    public function seenBefore(int $day): array
    {
        // IMAP's dates are English and day-month-year, whatever the locale.
        return $this->search('SEEN BEFORE ' . gmdate('j-M-Y', $day));
    }

    /** Marked for deletion; gone at {@see expunge()}. */
    public function delete(int $uid): void
    {
        $this->command('UID STORE ' . $uid . ' +FLAGS.SILENT (\\Deleted \\Seen)');
    }

    /** What was marked for deletion, removed from the mailbox. */
    public function expunge(): void
    {
        $this->command('EXPUNGE');
    }

    /** The UIDs a search finds, oldest first. @return list<int> */
    private function search(string $criteria): array
    {
        $uids = [];

        foreach ($this->command('UID SEARCH ' . $criteria)['lines'] as $line) {
            if (preg_match('/^\* SEARCH\b(.*)$/i', $line, $m)) {
                array_push($uids, ...array_map('intval', preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY) ?: []));
            }
        }

        sort($uids);

        return $uids;
    }

    /** The whole message, as it was sent — without marking it read. */
    public function fetch(int $uid): string
    {
        return (string) ($this->command('UID FETCH ' . $uid . ' (BODY.PEEK[])')['literals'][0] ?? '');
    }

    /** Mark a message read. */
    public function markSeen(int $uid): void
    {
        $this->command('UID STORE ' . $uid . ' +FLAGS.SILENT (\\Seen)');
    }

    /** Sign out and close; again is nothing. */
    public function logout(): void
    {
        if ($this->stream !== null) {
            try {
                $this->command('LOGOUT');
            } catch (\RuntimeException) {
                // Already gone: nothing left to say goodbye to.
            }

            fclose($this->stream);
            $this->stream = null;
        }
    }

    /**
     * Send one command and read up to its tagged answer, literals included.
     *
     * @return array{lines: list<string>, literals: list<string>}
     */
    private function command(string $command): array
    {
        $tag = 'A' . (++$this->tag);
        // A server that has hung up answers a write with a broken pipe: said as the closed connection it is.
        if (@fwrite($this->stream, $tag . ' ' . $command . "\r\n") === false) {
            throw new \RuntimeException('The mail server closed the connection.');
        }
        $lines    = [];
        $literals = [];

        while (true) {
            $line = $this->line();

            // A literal: {n} at the end of a line, then exactly n bytes.
            if (preg_match('/\{(\d+)\}\r?\n$/', $line, $m)) {
                $literals[] = $this->bytes((int) $m[1]);
            }

            // A continuation after AUTHENTICATE carries the server's error detail: answered with an
            // empty line, the tagged refusal follows.
            if (str_starts_with($line, '+')) {
                @fwrite($this->stream, "\r\n");

                continue;
            }

            if (str_starts_with($line, $tag . ' ')) {
                if (!preg_match('/^' . $tag . ' OK\b/i', $line)) {
                    // Never the password: LOGIN's refusal is said without the command.
                    throw new \RuntimeException('The mail server refused ' . strtok($command, ' ') . ': ' . trim(substr($line, strlen($tag) + 1)));
                }

                return ['lines' => $lines, 'literals' => $literals];
            }

            $lines[] = rtrim($line, "\r\n");
        }
    }

    /** One line from the server. */
    private function line(): string
    {
        $line = fgets($this->stream);

        if ($line === false) {
            throw new \RuntimeException('The mail server closed the connection.');
        }

        return $line;
    }

    /** Exactly `$count` bytes from the server. */
    private function bytes(int $count): string
    {
        $read = '';

        while (strlen($read) < $count) {
            $chunk = fread($this->stream, $count - strlen($read));

            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException('The mail server closed the connection mid-message.');
            }

            $read .= $chunk;
        }

        return $read;
    }

    /** An IMAP quoted string. */
    private static function quote(string $value): string
    {
        return '"' . addcslashes($value, '"\\') . '"';
    }
}
