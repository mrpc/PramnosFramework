<?php

namespace Pramnos\Auth;

use Pramnos\Database\Database;

/**
 * Creating, changing and retiring OAuth2 / API applications — the rules, without a screen.
 *
 * The administration area's `ApplicationsController` is one caller. An application with a
 * screen of its own — a Svelte panel, a developer portal — is the other: it writes its own JSON
 * endpoints and calls this, so the screen is the application's and the security decisions stay
 * the framework's. Those decisions are the ones worth not re-implementing:
 *
 * - **Callbacks** are normalised into one space-separated list, a script-only scheme is refused
 *   by name rather than silently dropped on read, and a list longer than the column is refused
 *   before the driver truncates it.
 * - **Secrets** come from the CSPRNG and are stored hashed. The plain client secret is returned
 *   once, by {@see create()} or {@see rotateSecret()}, and never again.
 * - **Retiring** an application revokes every active token it holds before switching it off.
 *
 * Webhook endpoints are {@see WebhookService}'s; whether a client needs a registered callback
 * at all is {@see Application::needsARegisteredCallback()}.
 */
class ApplicationService
{
    /** The columns an application's public description is made of — never its secrets. */
    private const PUBLIC_COLUMNS = [
        'appid', 'name', 'description', 'apikey', 'callback', 'scope', 'status', 'apptype',
        'accesstype', 'apiversion', 'appversion', 'public', 'is_confidential', 'organization',
        'organizationurl', 'url', 'supportemail', 'termsurl', 'privacyurl', 'jwks_uri', 'added',
    ];

    public function __construct(private ?Database $database = null)
    {
    }

    /**
     * Check and normalise an application's fields, stopping at the first problem.
     *
     * Every field has the meaning and default the administration form gives it; a missing one
     * is its default. The error is a sentence for the person who typed the value, plain text —
     * a caller rendering it as HTML escapes it.
     *
     * @param array<string, mixed> $input           The form or request body
     * @param int|null             $callbackCeiling The `callback` column's width; null reads it
     * @return array{fields: array<string, mixed>, error: ?string}
     */
    public function validate(array $input, ?int $callbackCeiling = null): array
    {
        $text = static fn (string $key, string $default = ''): string => trim((string) ($input[$key] ?? $default));
        $orNull = static fn (string $value): ?string => $value !== '' ? $value : null;

        $name = $text('name');
        if ($name === '') {
            return ['fields' => [], 'error' => 'A name is required.'];
        }

        /*
         * One application has several legitimate callbacks — localhost, staging, production —
         * typed one per line, comma-separated or spaced. What is stored is one space-separated
         * list, so what the endpoint reads back is the same shape whoever typed it.
         */
        $rawCallback = $text('callback');

        // `parseRedirectUris()` drops a script-only scheme on read — safe and silent. This is
        // the one place there is somebody to tell.
        foreach (preg_split('/[\s,]+/', $rawCallback) ?: [] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && Application::isRefusedScheme($candidate)) {
                return ['fields' => [], 'error' => 'That callback scheme is not allowed: ' . $candidate
                    . '. A callback may be http(s) or an app scheme like myapp://oauth.'];
            }
        }
        $callback = implode(' ', Application::parseRedirectUris($rawCallback));

        // An installation whose `applications` predates the migration system still has
        // `varchar(255)`; refuse the overflow with the fix rather than the driver's message.
        $ceiling = $callbackCeiling ?? $this->callbackCeiling();
        if ($ceiling > 0 && strlen($callback) > $ceiling) {
            return ['fields' => [], 'error' => 'Those callbacks are ' . strlen($callback)
                . ' characters and this database still limits the field to ' . $ceiling
                . '. Run the framework migrations to widen it, then save again.'];
        }

        $apiversion = $text('apiversion', 'v1');
        $fields     = [
            'name'            => $name,
            'description'     => $orNull($text('description')),
            'callback'        => $orNull($callback),
            'scope'           => $orNull($text('scope')),
            'status'          => max(0, min(1, (int) ($input['status'] ?? 1))),
            'apptype'         => max(0, min(5, (int) ($input['apptype'] ?? 0))),
            'accesstype'      => max(0, min(2, (int) ($input['accesstype'] ?? 0))),
            'apiversion'      => $apiversion !== '' ? $apiversion : 'v1',
            'appversion'      => $text('appversion'),
            'public'          => isset($input['public']) ? 1 : 0,
            // About client authentication, not about being listed in a directory: unset is a
            // public client, one that cannot hold a secret.
            'is_confidential' => isset($input['is_confidential']) ? 1 : 0,
            'organization'    => $orNull($text('organization')),
            'organizationurl' => $orNull($text('organizationurl')),
            'url'             => $orNull($text('url')),
            'supportemail'    => $orNull($text('supportemail')),
            'termsurl'        => $orNull($text('termsurl')),
            'privacyurl'      => $orNull($text('privacyurl')),
            'public_key'      => $orNull($text('public_key')),
            'jwks_uri'        => $orNull($text('jwks_uri')),
        ];

        // Only when sent — the bundled forms send `trusted` as a hidden 0 behind the checkbox —
        // so an older copy of the form does not quietly revoke a trusted client, and only where
        // the column exists.
        $schema = $this->db()->schema();
        if (array_key_exists('trusted', $input) && $schema->hasColumn('applications', 'trusted')) {
            $fields['trusted'] = (int) $input['trusted'] === 1 ? 1 : 0;
        }

        // The lifetimes, likewise only when sent: blank is the server default, stored as NULL.
        foreach (['access_token_ttl', 'refresh_token_ttl'] as $ttl) {
            if (array_key_exists($ttl, $input) && $schema->hasColumn('applications', $ttl)) {
                $fields[$ttl] = (int) $input[$ttl] > 0 ? (int) $input[$ttl] : null;
            }
        }

        return ['error' => null, 'fields' => $fields];
    }

    /**
     * Register an application and issue its credentials.
     *
     * The client secret is returned here and nowhere else: the column holds its hash. The
     * realtime key is separate and encrypted rather than hashed, because it has to be read back
     * to sign a channel.
     *
     * @param array<string, mixed> $input
     * @return array{appid: int, apikey: string, secret: string}
     * @throws \InvalidArgumentException With the validation error, when the input is refused
     */
    public function create(array $input, ?int $callbackCeiling = null): array
    {
        $fields = $this->validOrThrow($input, $callbackCeiling);

        $secret          = bin2hex(random_bytes(32));
        $broadcastSecret = bin2hex(random_bytes(32));
        $fields['apikey']           = bin2hex(random_bytes(16));
        $fields['apisecret']        = PasswordHash::make($secret);
        $fields['added']            = time();
        $fields['broadcast_secret'] = \Pramnos\Security\Encrypter::isAvailable()
            ? \Pramnos\Security\Encrypter::encrypt($broadcastSecret)
            : $broadcastSecret;

        $this->db()->queryBuilder()->table('#PREFIX#applications')->insert($fields);

        // Found by its key, which is random and unique, rather than by an insert id the two
        // drivers report differently.
        $row = $this->db()->queryBuilder()->table('#PREFIX#applications')
            ->select(['appid'])->where('apikey', $fields['apikey'])->first();

        return [
            'appid'  => (int) ($row->fields['appid'] ?? 0),
            'apikey' => $fields['apikey'],
            'secret' => $secret,
        ];
    }

    /**
     * Change an application's fields. Its credentials are never touched here.
     *
     * @param array<string, mixed> $input
     * @return bool False when there is no such application
     * @throws \InvalidArgumentException With the validation error, when the input is refused
     */
    public function update(int $appId, array $input, ?int $callbackCeiling = null): bool
    {
        $fields = $this->validOrThrow($input, $callbackCeiling);
        if (!$this->exists($appId)) {
            return false;
        }

        $this->db()->queryBuilder()->table('#PREFIX#applications')
            ->where('appid', $appId)->update($fields);

        return true;
    }

    /**
     * Issue a new client secret, returning it once.
     *
     * Existing tokens stay valid until they expire: they do not depend on the secret.
     *
     * @return string|null The new plain secret, or null when there is no such application
     */
    public function rotateSecret(int $appId): ?string
    {
        if (!$this->exists($appId)) {
            return null;
        }

        $secret = bin2hex(random_bytes(32));
        $this->db()->queryBuilder()->table('#PREFIX#applications')
            ->where('appid', $appId)->update(['apisecret' => PasswordHash::make($secret)]);

        return $secret;
    }

    /**
     * Switch an application off and revoke every active token it holds.
     *
     * The tokens are kept, as status 3 (revoked), for the audit trail; the application row is
     * kept with status 0.
     *
     * @return bool False when there is no such application
     */
    public function deactivate(int $appId): bool
    {
        if (!$this->exists($appId)) {
            return false;
        }

        $this->db()->queryBuilder()->table('#PREFIX#usertokens')
            ->where('applicationid', $appId)->where('status', 1)
            ->update(['status' => 3, 'removedate' => time()]);
        $this->db()->queryBuilder()->table('#PREFIX#applications')
            ->where('appid', $appId)->update(['status' => 0]);

        return true;
    }

    /**
     * The active tokens issued to an application, most recently used first — never the tokens
     * themselves.
     *
     * @return list<array<string, mixed>>
     */
    public function tokens(int $appId): array
    {
        $result = $this->db()->queryBuilder()
            ->table('#PREFIX#usertokens ut')
            ->join('#PREFIX#users u', 'ut.userid', '=', 'u.userid')
            ->select(['ut.tokenid', 'u.username', 'ut.scope', 'ut.expires', 'ut.lastused', 'ut.status', 'ut.ipaddress', 'ut.tokentype'])
            ->where('ut.applicationid', $appId)
            ->where('ut.status', 1)
            ->orderBy('ut.lastused', 'desc')
            ->get();

        $rows = [];
        while ($result && $result->fetch()) {
            $rows[] = (array) $result->fields;
        }

        return $rows;
    }

    /**
     * An application's public description — no secret, hashed or otherwise.
     *
     * @return array<string, mixed>|null Null when there is no such application
     */
    public function find(int $appId): ?array
    {
        $row = $this->db()->queryBuilder()->table('#PREFIX#applications')
            ->select(self::PUBLIC_COLUMNS)->where('appid', $appId)->first();

        return $row && $row->numRows > 0 ? (array) $row->fields : null;
    }

    /** Whether an application with this id exists. */
    public function exists(int $appId): bool
    {
        if ($appId <= 0) {
            return false;
        }
        $row = $this->db()->queryBuilder()->table('#PREFIX#applications')
            ->select(['appid'])->where('appid', $appId)->first();

        return $row && $row->numRows > 0;
    }

    /**
     * How many characters the `callback` column can hold, or 0 when it is unbounded.
     *
     * Read from the catalogue, because the answer differs by installation rather than by driver:
     * a database the framework created has `text`; one whose table predates the migration system
     * still has `varchar(255)`. Best effort: a catalogue that cannot answer gives no ceiling and
     * the driver's own refusal.
     *
     * Catalogue introspection, so raw SQL: the query builder does not reach
     * `information_schema.columns`.
     */
    public function callbackCeiling(): int
    {
        try {
            $db = $this->db();

            $result = $db->query(
                $db->prepareQuery(
                    "SELECT COALESCE(character_maximum_length, 0) AS len
                       FROM information_schema.columns
                      WHERE table_name = %s AND column_name = 'callback'
                      ORDER BY table_schema
                      LIMIT 1",
                    $db->prefix . 'applications'
                )
            );

            if (!$result || !$result->numRows) {
                // PostgreSQL puts the table in a schema rather than behind a prefix.
                $result = $db->query(
                    "SELECT COALESCE(character_maximum_length, 0) AS len
                       FROM information_schema.columns
                      WHERE table_name = 'applications' AND column_name = 'callback'
                      ORDER BY table_schema
                      LIMIT 1"
                );
            }

            return $result && $result->numRows ? (int) ($result->fields['len'] ?? 0) : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * The validated fields, or the validation error as an exception.
     *
     * @return array<string, mixed>
     */
    private function validOrThrow(array $input, ?int $callbackCeiling): array
    {
        $checked = $this->validate($input, $callbackCeiling);
        if ($checked['error'] !== null) {
            throw new \InvalidArgumentException($checked['error']);
        }

        return $checked['fields'];
    }

    /** The connection given, or the current one. */
    private function db(): Database
    {
        return $this->database ?? \Pramnos\Framework\Factory::getDatabase();
    }
}
