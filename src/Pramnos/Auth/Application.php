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
 * @package PramnosFramework
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

    /** @var string|null Comma-separated or JSON-array of allowed redirect URIs */
    public ?string $callback = null;

    /** @var int|null FK to users.userid */
    public ?int $owner = null;

    /** @var string|null PEM public key for JWT client authentication (RFC 7523) */
    public ?string $public_key = null;

    /** @var string|null URL to JWKS endpoint for dynamic public-key rotation */
    public ?string $jwks_uri = null;

    protected string $_primaryKey = 'appid';
    protected string $_dbtable    = '#PREFIX#applications';

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
        $sql = $database->prepareQuery(
            'SELECT * FROM `#PREFIX#applications` WHERE `apikey` = %s AND `status` = 1',
            $apikey
        );
        $result = $database->query($sql);

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
     * @param string      $clientId     OAuth2 client_id
     * @param string|null $clientSecret OAuth2 client_secret (null for public clients)
     * @return bool
     */
    public function validateCredentials(string $clientId, ?string $clientSecret): bool
    {
        $database = \Pramnos\Framework\Factory::getDatabase();

        if ($clientSecret === null) {
            $sql = $database->prepareQuery(
                'SELECT appid FROM `#PREFIX#applications` WHERE `apikey` = %s AND `status` = 1',
                $clientId
            );
        } else {
            $sql = $database->prepareQuery(
                'SELECT appid FROM `#PREFIX#applications` WHERE `apikey` = %s AND `apisecret` = %s AND `status` = 1',
                $clientId,
                $clientSecret
            );
        }

        $result = $database->query($sql);

        return $result && $result->numRows > 0;
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
