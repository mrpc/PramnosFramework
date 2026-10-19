<?php

declare(strict_types=1);

namespace Pramnos\Email;

use Pramnos\Application\Settings;

/**
 * The mailbox delivery reports come back to, read over IMAP — with a password, or with OAuth2
 * (XOAUTH2) as Gmail and Microsoft 365 want — and without the `imap` extension, which PHP no
 * longer ships.
 *
 * ```php
 * // app/app.php
 * 'email' => ['bounces' => ['mailbox' => [
 *     'host'       => 'imap.gmail.com',
 *     'port'       => 993,
 *     'encryption' => 'ssl',            // or 'none', for a server on the same host
 *     'username'   => 'bounces@example.com',
 *     'password'   => '…',              // or, for OAuth2:
 *     'oauth'      => ['client_id' => '…', 'client_secret' => '…', 'refresh_token' => '…'],
 *     'folder'     => 'INBOX',
 * ]]],
 * ```
 *
 * Without that block the settings an application already keeps are read: `imap_host` (the
 * `{host:993/imap/ssl}INBOX` form included), `imap_user`, `imap_pass` and
 * `imap_oauth_client_id` / `_client_secret` / `_refresh_token`. `mail:bounces --imap` uses it: the
 * unseen messages are read without marking them, each delivery report is recorded and marked seen,
 * and anything else is left unread for a person.
 *
 * It speaks IMAP through {@see ImapClient}.
 */
class BounceMailbox
{
    /** Where a Google refresh token is exchanged, unless `oauth.token_url` says otherwise. */
    public const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /**
     * @param array<string, mixed> $config As under `email.bounces.mailbox`
     * @param ImapClient|null      $client The connection; one is opened unless given
     */
    public function __construct(private array $config, private ?ImapClient $client = null)
    {
    }

    /**
     * The mailbox the application is configured with; null when it has none.
     */
    public static function configured(): ?self
    {
        $app    = \Pramnos\Application\Application::currentInstance();
        $config = is_object($app) ? ($app->applicationInfo['email']['bounces']['mailbox'] ?? null) : null;
        if (is_array($config) && trim((string) ($config['host'] ?? '')) !== '') {
            return new self($config);
        }
        $host = trim((string) Settings::getSetting('imap_host'));
        if ($host === '') {
            return null;
        }
        // `{imap.example.com:993/imap/ssl}INBOX`, as the imap extension wrote it.
        $config = ['host' => $host, 'port' => 993, 'encryption' => 'ssl', 'folder' => 'INBOX'];
        if (preg_match('/^\{([^}:\/]+)(?::(\d+))?([^}]*)\}(.*)$/', $host, $m) === 1) {
            // Without /ssl the imap extension spoke STARTTLS unless told /notls.
            $encryption = str_contains($m[3], '/ssl') ? 'ssl' : (str_contains($m[3], '/notls') ? 'none' : 'tls');
            $config = [
                'host'       => $m[1],
                'port'       => (int) ($m[2] !== '' ? $m[2] : ($encryption === 'ssl' ? 993 : 143)),
                'encryption' => $encryption,
                'folder'     => $m[4] !== '' ? $m[4] : 'INBOX',
            ];
        }
        $config['username'] = (string) Settings::getSetting('imap_user');
        $config['password'] = (string) Settings::getSetting('imap_pass');
        $refresh            = (string) Settings::getSetting('imap_oauth_refresh_token');
        if ($refresh !== '') {
            $config['oauth'] = [
                'client_id'     => (string) Settings::getSetting('imap_oauth_client_id'),
                'client_secret' => (string) Settings::getSetting('imap_oauth_client_secret'),
                'refresh_token' => $refresh,
            ];
        }

        return new self($config);
    }

    /** Where it reads, for a person: `user@host/folder`. */
    public function describe(): string
    {
        return ($this->config['username'] ?? '') . '@' . ($this->config['host'] ?? '') . '/' . ($this->config['folder'] ?? 'INBOX');
    }

    /**
     * Connect, sign in and open the folder.
     *
     * @throws \RuntimeException when the server cannot be reached or refuses
     */
    public function open(): void
    {
        $this->client ??= new ImapClient();
        $this->client->connect(
            (string) ($this->config['host'] ?? ''),
            (int) ($this->config['port'] ?? 993),
            (string) ($this->config['encryption'] ?? 'ssl')
        );
        $user  = (string) ($this->config['username'] ?? '');
        $oauth = $this->config['oauth'] ?? null;
        if (is_array($oauth) && ($oauth['refresh_token'] ?? '') !== '') {
            $this->client->authenticate($user, $this->accessToken($oauth));
        } else {
            $this->client->login($user, (string) ($this->config['password'] ?? ''));
        }
        $this->client->select((string) ($this->config['folder'] ?? 'INBOX'));
    }

    /**
     * The unseen messages, oldest first, as UIDs — with a limit, the newest that many.
     *
     * The newest, because what is left unseen grows: only reports are marked seen, and a reply a
     * person still has to read stays unseen. The oldest `$limit` would be the same old replies
     * on every run once there were more of them than the limit, and a new report behind them
     * would never be read.
     *
     * @return list<int>
     */
    public function unseen(int $limit = 0): array
    {
        $uids = $this->client?->unseen() ?? [];

        return $limit > 0 ? array_slice($uids, -$limit) : $uids;
    }

    /** A message, whole, without marking it seen. */
    public function fetch(int $uid): string
    {
        return (string) $this->client?->fetch($uid);
    }

    /** Mark a message seen. */
    public function markSeen(int $uid): void
    {
        $this->client?->markSeen($uid);
    }

    /** Sign out and close. */
    public function close(): void
    {
        $this->client?->logout();
    }

    /**
     * An access token for a refresh token.
     *
     * @param array<string, mixed> $oauth
     */
    protected function accessToken(array $oauth): string
    {
        $response = \Pramnos\Http\Client::post((string) ($oauth['token_url'] ?? self::GOOGLE_TOKEN_URL))->form([
            'client_id'     => (string) ($oauth['client_id'] ?? ''),
            'client_secret' => (string) ($oauth['client_secret'] ?? ''),
            'refresh_token' => (string) $oauth['refresh_token'],
            'grant_type'    => 'refresh_token',
        ])->send();
        $token = (string) ($response->json('access_token') ?? '');
        if (!$response->successful() || $token === '') {
            throw new \RuntimeException('The bounce mailbox\'s refresh token was refused (' . $response->status() . '): ' . substr($response->body(), 0, 200));
        }

        return $token;
    }
}
