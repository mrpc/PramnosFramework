<?php

declare(strict_types=1);

namespace Pramnos\Auth;

/**
 * OAuth2 Application Model
 *
 * Represents a registered OAuth2 client application. Wraps the `applications`
 * database table and provides the interface expected by the OAuth2 repositories
 * (getClientIdentifier, validateCredentials, etc.).
 *
 */
class Application extends \Pramnos\Application\Model
{
    /** @var int Auto-increment primary key */
    public int $appid = 0;

    /** @var string Human-readable application name */
    public string $name = '';

    /** @var string|null OAuth2 client_id (public identifier) */
    public ?string $apikey = null;

    /** @var string|null OAuth2 client_secret */
    public ?string $apisecret = null;

    /** @var int Application lifecycle status: 0 = disabled, 1 = active */
    public int $status = 1;

    /** @var int Unix timestamp of registration */
    public int $added = 0;

    /** @var string|null Optional description */
    public ?string $description = null;

    /** @var string|null Organization name */
    public ?string $organization = null;

    /** @var string|null Organization URL */
    public ?string $organizationurl = null;

    /** @var string|null Application homepage URL */
    public ?string $url = null;

    /** @var int Application type: 0=web, 1=mobile, 2=service */
    public int $apptype = 0;

    /** @var int Access type: 0=REST API key, 1=OAuth2 */
    public int $accesstype = 0;

    /** @var string API version string (e.g. "v1") */
    public string $apiversion = 'v1';

    /** @var string|null Space-separated allowed OAuth2 scopes */
    public ?string $scope = null;

    /** @var int Whether publicly listed: 0=private, 1=public */
    public int $public = 0;

    /** @var int Trusted first-party client: 1 skips the OAuth2 consent screen, 0 = untrusted (default) */
    public int $trusted = 0;

    /** @var string|null Comma-separated or JSON-array of allowed redirect URIs */
    public ?string $callback = null;

    /** @var int|null FK to users.userid */
    public ?int $owner = null;

    /** @var string|null PEM public key for JWT client authentication (RFC 7523) */
    public ?string $public_key = null;

    /** @var string|null URL to JWKS endpoint for dynamic public-key rotation */
    public ?string $jwks_uri = null;

    /** @var int|null FK to users.userid — dedicated system account for client-credentials tokens */
    public ?int $systemuser = null;

    protected $_primaryKey = 'appid';
    protected $_dbtable    = '#PREFIX#applications';

    /**
     * @param \Pramnos\Application\Controller $controller
     * @param string $name  Model name hint (optional)
     * @param int    $appid Load by PK on construction (0 = new record)
     */
    public function __construct(
        \Pramnos\Application\Controller $controller,
        string $name = '',
        int $appid = 0
    ) {
        parent::__construct($controller, $name);
        if ($appid === 0) {
            $this->_isnew = 1;
        } else {
            $this->load($appid);
        }
    }

    public function load(int $appid, ?string $key = null, bool $debug = false): static
    {
        return parent::_load($appid, null, $key, $debug);
    }

    public function save(bool $autoGetValues = false, bool $debug = false): static
    {
        return parent::_save(null, null, $autoGetValues, $debug);
    }

    public function delete(int $appid): static
    {
        return parent::_delete($appid, null, null);
    }

    /**
     * Load by OAuth2 client_id (apikey).
     *
     * @param string $apikey The client_id value.
     * @return static|false  Hydrated model or false when not found / inactive.
     */
    public function loadByApiKey(string $apikey): static|false
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        $result   = $database->queryBuilder()
            ->table('#PREFIX#applications')
            ->where('apikey', $apikey)
            ->where('status', 1)
            ->first();

        if (!$result || $result->numRows == 0) {
            return false;
        }

        foreach (array_keys($result->fields) as $key) {
            if (property_exists($this, $key)) {
                $this->$key = $result->fields[$key];
            }
        }
        $this->_isnew = false;

        return $this;
    }

    /**
     * Validate client_id + client_secret combination.
     *
     * A registered secret must be presented. The previous version appended the
     * `apisecret` condition only when a secret arrived, so a request that simply
     * omitted `client_secret` matched on `apikey` + `status` alone and every
     * active application authenticated without one.
     *
     * That was reachable: `league/oauth2-server` 8.5 reads the secret as `null`
     * when the parameter is absent and `AbstractGrant::validateClient()` hands the
     * decision to this method unexamined, so a token request carrying nothing but
     * a `client_id` — a public identifier, shipped in every SPA and mobile app —
     * was granted a token.
     *
     * A client whose stored `apisecret` is empty is left as it was: nothing was
     * registered for it, so there is nothing to present. Whether such a client
     * should exist at all is the public/confidential question, which
     * {@see isConfidential()} still answers `true` to unconditionally.
     *
     * The comparison is `hash_equals()` rather than a SQL `=`, so the secret is
     * matched in constant time and never travels into a WHERE clause.
     *
     * @param string      $clientId     OAuth2 client_id
     * @param string|null $clientSecret OAuth2 client_secret (null when absent)
     * @return bool
     */
    public function validateCredentials(string $clientId, ?string $clientSecret): bool
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        $result   = $database->queryBuilder()
            ->table('#PREFIX#applications')
            ->where('apikey', $clientId)
            ->where('status', 1)
            ->first();

        if (!$result || $result->numRows == 0) {
            return false;
        }

        $stored = (string) ($result->fields['apisecret'] ?? '');

        // No secret registered: the client has none to give, so accept only a
        // request that presents none either. Accepting any string here would have
        // been looser than the version this replaces, where a non-empty secret
        // simply failed to match the empty column.
        if ($stored === '') {
            return $clientSecret === null || $clientSecret === '';
        }

        return $clientSecret !== null
            && hash_equals($stored, $clientSecret);
    }

    /**
     * Assign a system user to this application (used by JWT client_credentials grant).
     *
     * Updates the `systemuser` column in the applications table for the current
     * application record.  Called after a new sys_* user has been created so the
     * same user is reused on subsequent token requests (regression fix UW-461).
     *
     * @param int $userId FK to users.userid
     * @return bool True on success, false if the application has no PK yet.
     */
    public function assignSystemUser(int $userId): bool
    {
        if ($this->appid === 0) {
            return false;
        }

        $database = \Pramnos\Framework\Factory::getDatabase();
        $database->queryBuilder()
            ->table('#PREFIX#applications')
            ->where('appid', $this->appid)
            ->update(['systemuser' => $userId]);

        $this->systemuser = $userId;

        return true;
    }

    /**
     * The user a client-credentials token for this application belongs to.
     *
     * ## Why an application needs a user at all
     *
     * `usertokens.userid` is a foreign key to `users`, and a client-credentials
     * token has no end user — the token represents the application. Something has
     * to own the row. The answer is a dedicated account per application, created
     * once and reused: `usertokens` stays referentially intact, and `introspect`,
     * `revoke` and the audit trail keep working on the issued token because there
     * is a real subject behind it.
     *
     * This used to exist only inside the JWT-client-assertion branch of the token
     * endpoint, written inline. The consequence was that the **secret-based**
     * `client_credentials` grant — the ordinary one — wrote `userid = 0`, violated
     * the key, and answered `server_error`. It was not a subtle failure: that grant
     * did not work at all, and the one path that did work was the one that happened
     * to carry this thirty-line block with it.
     *
     * Idempotent: an application that already has one gets it back without a write.
     *
     * @return int The system account's userid, or 0 when one could not be made
     */
    public function systemUserId(): int
    {
        // `> 1`, not `> 0`: 0 and 1 are the framework's guest and system rows, and
        // a column left holding either is not this application's own account. A
        // token attributed to one would sit under an identity shared with every
        // other application that had the same gap.
        if ($this->systemuser !== null && (int) $this->systemuser > 1) {
            return (int) $this->systemuser;
        }

        if ($this->appid === 0) {
            return 0;
        }

        try {
            $userId = $this->createSystemUserRow();

            // 0 and 1 are the framework's guest and system rows. Attributing an
            // application's tokens to either would put them under a shared
            // identity.
            if ($userId <= 1) {
                return 0;
            }

            $this->assignSystemUser($userId);

            return $userId;
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log(
                'Could not create a system user for application ' . $this->appid
                . ': ' . $exception->getMessage()
            );

            return 0;
        }
    }

    /**
     * Create the machine account and return its id.
     *
     * A seam: making a user row is the one thing here that needs a database, and
     * the branching around it is what is worth testing.
     *
     * `usertype` 1 marks it as a machine account — below every administrative
     * threshold in the framework, so a token issued to an application can never be
     * mistaken for one issued to an operator.
     */
    protected function createSystemUserRow(): int
    {
        $user = new \Pramnos\User\User();

        $user->usertype  = 1;
        $user->username  = 'sys_' . bin2hex(random_bytes(8));
        $user->email     = $user->username . '@system.local';
        $user->active    = 1;
        $user->validated = 1;
        $user->regdate   = time();
        $user->save();

        return (int) $user->userid;
    }

    // --- OAuth2 client interface helpers ------------------------------------

    /** Return the OAuth2 client_id. */
    public function getClientIdentifier(): mixed
    {
        return $this->apikey;
    }

    /** Return the display name (used in consent screens). */
    public function getClientName(): string
    {
        return $this->name;
    }

    /**
     * Return the registered redirect URI(s).
     * The value stored in `callback` may be a comma-separated list or a
     * JSON array; we normalize to a plain string (first URI) for league
     * compatibility. Repositories that need the full list use this method.
     */
    public function getRedirectUri(): string
    {
        if (empty($this->callback)) {
            return '';
        }
        $decoded = json_decode($this->callback, true);
        if (is_array($decoded)) {
            return $decoded[0] ?? '';
        }
        $parts = array_map('trim', explode(',', $this->callback));
        return $parts[0];
    }

    /** Return all registered redirect URIs as an array. */
    public function getRedirectUris(): array
    {
        if (empty($this->callback)) {
            return [];
        }
        $decoded = json_decode($this->callback, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        return array_map('trim', explode(',', $this->callback));
    }

    /** Confidential clients require a secret; public clients do not. */
    public function isConfidential(): bool
    {
        return true;
    }

    /** Return allowed scopes as an array. */
    public function getScopes(): array
    {
        return $this->scope ? explode(' ', trim($this->scope)) : [];
    }

    /** Check whether a given scope is allowed for this client. */
    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->getScopes(), true);
    }
}
