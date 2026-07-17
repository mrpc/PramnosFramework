<?php

declare(strict_types=1);

namespace Pramnos\Auth\Passkey;

use Pramnos\Framework\Factory;

/**
 * Passkey (WebAuthn/FIDO2) orchestration service.
 *
 * Concrete {@see PasskeyServiceInterface}. It owns everything that is NOT the
 * raw cryptography:
 *
 *   - **Challenge store** — a begin* call stashes the issued options in the
 *     session keyed by their challenge (TTL {@see self::CHALLENGE_TTL} seconds);
 *     the matching finish* call loads and immediately deletes that entry. This
 *     makes every challenge single-use: an expired or already-consumed challenge
 *     fails, which is the front-line replay defence for the ceremony itself.
 *     Verification always runs against the SERVER-stored options, never against
 *     options supplied by the client.
 *   - **Persistence** — new credentials are inserted into
 *     `authserver.passkey_credentials`; the signature counter is written back on
 *     every successful assertion.
 *   - **Sign-count replay / clone detection** — the adapter (via the WebAuthn
 *     counter check) rejects a non-increasing counter; persisting the advanced
 *     counter here is what makes that protection hold ACROSS requests.
 *
 * The cryptography is delegated to a {@see WebAuthnAdapterInterface} (default
 * {@see WebAuthnLibAdapter}); this service never references the WebAuthn library.
 *
 * Extension seam: an app layer (e.g. an application auth layer) can subclass this service
 * — or override the protected challenge-store / persistence / host methods — to
 * plug in licensing or a different storage backend without forking the framework.
 */
class PasskeyService implements PasskeyServiceInterface
{
    /** Challenge lifetime in seconds (design: 5 minutes). */
    protected const CHALLENGE_TTL = 300;

    protected const TABLE = 'authserver.passkey_credentials';

    protected WebAuthnAdapterInterface $adapter;

    /** @var \Pramnos\Database\Database */
    protected $database;

    protected Config $config;

    /**
     * @param WebAuthnAdapterInterface|null $adapter  Crypto adapter (default: WebAuthnLibAdapter).
     * @param \Pramnos\Database\Database|null $database Database (default: framework db).
     * @param Config|null                   $config   RP config (default: from settings).
     */
    public function __construct(
        ?WebAuthnAdapterInterface $adapter = null,
        $database = null,
        ?Config $config = null
    ) {
        $this->config   = $config ?? Config::fromSettings();
        $this->adapter  = $adapter ?? new WebAuthnLibAdapter($this->config);
        $this->database = $database ?: Factory::getDatabase();
    }

    // ── Registration ─────────────────────────────────────────────────────────

    public function beginRegistration(int $userId, ?string $label = null): RegistrationOptions
    {
        [$userName, $displayName] = $this->userIdentity($userId);

        $options = $this->adapter->createRegistrationOptions(
            $userId,
            $userName,
            $displayName,
            $this->activeCredentialIds($userId)
        );

        // Persist the issued options (with the intended label) for the finish step.
        $this->storeChallenge('reg', $options->challenge, $options->toArray() + ['label' => $label]);

        return $options;
    }

    public function finishRegistration(
        int $userId,
        RegistrationOptions $options,
        string $clientResponse
    ): PasskeyCredential {
        $stored = $this->consumeChallenge('reg', $options->challenge);
        if ($stored === null || (int) ($stored['userId'] ?? -1) !== $userId) {
            throw new PasskeyException('Unknown or expired registration challenge');
        }

        // Verify against the server-stored options, not whatever the client sent.
        $serverOptions = RegistrationOptions::fromArray($stored);

        $credential = $this->adapter->verifyRegistration($serverOptions, $clientResponse, $this->host());
        if ($credential->userId !== $userId) {
            throw new PasskeyException('Credential user mismatch');
        }

        $label = isset($stored['label']) && $stored['label'] !== null ? (string) $stored['label'] : null;

        return $this->persistCredential($credential, $label);
    }

    // ── Authentication ─────────────────────────────────────────────────────────

    public function beginAuthentication(?int $userId = null): AuthenticationOptions
    {
        // Identified ceremony pins the user's credentials; usernameless leaves
        // allowCredentials empty and resolves the user from the response.
        $allow = $userId !== null ? $this->activeCredentialIds($userId) : [];

        $options = $this->adapter->createAuthenticationOptions($userId, $allow);

        $this->storeChallenge('auth', $options->challenge, $options->toArray());

        return $options;
    }

    public function finishAuthentication(
        AuthenticationOptions $options,
        string $clientResponse
    ): VerificationResult {
        $stored = $this->consumeChallenge('auth', $options->challenge);
        if ($stored === null) {
            throw new PasskeyException('Unknown or expired authentication challenge');
        }
        $serverOptions = AuthenticationOptions::fromArray($stored);

        $credentialId = $this->adapter->extractCredentialId($clientResponse);
        if ($credentialId === null) {
            throw new PasskeyException('Missing credential id in response');
        }

        $storedCredential = $this->findActiveByCredentialId($credentialId);
        if ($storedCredential === null) {
            throw new PasskeyException('Unknown credential');
        }

        // An identified ceremony must not be satisfied by another user's credential.
        if ($serverOptions->userId !== null && $storedCredential->userId !== $serverOptions->userId) {
            throw new PasskeyException('Credential does not belong to the requested user');
        }

        $result = $this->adapter->verifyAuthentication(
            $serverOptions,
            $clientResponse,
            $storedCredential,
            $this->host()
        );

        // Persist the advanced counter — this is what makes clone/replay
        // detection effective on the NEXT authentication.
        $this->updateSignCount((int) $storedCredential->id, $result->signCount);

        return $result;
    }

    // ── Credential management (dashboard) ─────────────────────────────────────

    /**
     * List a user's active passkeys.
     *
     * @return PasskeyCredential[]
     */
    public function listCredentials(int $userId): array
    {
        $rows = $this->database->queryBuilder()
            ->table(self::TABLE)
            ->where('userid', $userId)
            ->where('is_active', true)
            ->get();

        $list = [];
        while ($rows && $rows->fetch()) {
            $list[] = $this->mapRow($rows->fields);
        }
        return $list;
    }

    /**
     * Rename a passkey the user owns. Returns false when it does not exist / is
     * not theirs.
     */
    public function renameCredential(int $userId, int $credentialId, string $name): bool
    {
        if (!$this->ownsCredential($userId, $credentialId)) {
            return false;
        }
        $this->database->queryBuilder()
            ->table(self::TABLE)
            ->where('credentialid', $credentialId)
            ->update(['name' => $name]);
        return true;
    }

    /**
     * Revoke (soft-delete) a passkey the user owns. Returns false when it does
     * not exist / is not theirs.
     */
    public function revokeCredential(int $userId, int $credentialId): bool
    {
        if (!$this->ownsCredential($userId, $credentialId)) {
            return false;
        }
        $this->database->queryBuilder()
            ->table(self::TABLE)
            ->where('credentialid', $credentialId)
            ->update(['is_active' => false]);
        return true;
    }

    /** Whether the user has at least one active passkey (for login UX). */
    public function hasCredentials(int $userId): bool
    {
        return $this->activeCredentialIds($userId) !== [];
    }

    // ── Challenge store (protected seams) ─────────────────────────────────────

    /**
     * Store an issued ceremony's state under its challenge (single-use, TTL).
     *
     * Backed by the session: a WebAuthn ceremony always spans two requests from
     * the same browser (begin → finish), so the session — which persists per-user
     * via the session cookie — is the natural, always-available store. This makes
     * passkeys work out of the box without the deployment configuring a shared
     * cache backend (a plain per-request cache would lose the challenge between
     * the two requests, failing every ceremony with "unknown challenge").
     */
    protected function storeChallenge(string $type, string $challenge, array $data): void
    {
        $this->sessionStart();
        $_SESSION[$this->challengeKey($type, $challenge)] = [
            'data'    => $data,
            'expires' => time() + self::CHALLENGE_TTL,
        ];
    }

    /**
     * Load and immediately delete a challenge entry (single-use).
     *
     * @return array<string,mixed>|null null when absent / expired / malformed.
     */
    protected function consumeChallenge(string $type, string $challenge): ?array
    {
        $this->sessionStart();
        $key = $this->challengeKey($type, $challenge);
        if (!isset($_SESSION[$key]) || !is_array($_SESSION[$key])) {
            return null;
        }
        $entry = $_SESSION[$key];
        unset($_SESSION[$key]); // single-use: consumed whether valid or not
        if ((int) ($entry['expires'] ?? 0) < time()) {
            return null;
        }
        $data = $entry['data'] ?? null;
        return is_array($data) ? $data : null;
    }

    /** Ensure a session exists to back the challenge store (no-op in CLI/tests). */
    protected function sessionStart(): void
    {
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!isset($_SESSION)) {
            $_SESSION = [];
        }
    }

    /** Sanitised, collision-free cache key for a ceremony challenge. */
    protected function challengeKey(string $type, string $challenge): string
    {
        return $type . '_' . hash('sha256', $challenge);
    }

    /** Request host used for RP-id / origin verification. */
    protected function host(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        return $host !== '' ? (string) $host : $this->config->rpId;
    }

    // ── Persistence helpers ───────────────────────────────────────────────────

    /**
     * Account name + display name for a user, used in the WebAuthn user entity.
     *
     * @return array{0:string,1:string} [userName, displayName]
     */
    protected function userIdentity(int $userId): array
    {
        $row = $this->database->queryBuilder()
            ->table('users')
            ->where('userid', $userId)
            ->first();

        if (!$row || $row->numRows === 0) {
            return ['user' . $userId, 'User ' . $userId];
        }

        $username = (string) ($row->fields['username'] ?? ('user' . $userId));
        $first    = trim((string) ($row->fields['firstname'] ?? ''));
        $last     = trim((string) ($row->fields['lastname'] ?? ''));
        $display  = trim($first . ' ' . $last);
        if ($display === '') {
            $display = $username;
        }
        return [$username, $display];
    }

    /**
     * Base64url credential ids of a user's active passkeys.
     *
     * @return string[]
     */
    protected function activeCredentialIds(int $userId): array
    {
        $rows = $this->database->queryBuilder()
            ->table(self::TABLE)
            ->select('credential_id')
            ->where('userid', $userId)
            ->where('is_active', true)
            ->get();

        $ids = [];
        while ($rows && $rows->fetch()) {
            $ids[] = (string) $rows->fields['credential_id'];
        }
        return $ids;
    }

    /** Find an active stored credential by its base64url id, or null. */
    protected function findActiveByCredentialId(string $credentialId): ?PasskeyCredential
    {
        $row = $this->database->queryBuilder()
            ->table(self::TABLE)
            ->where('credential_id', $credentialId)
            ->where('is_active', true)
            ->first();

        if (!$row || $row->numRows === 0) {
            return null;
        }
        return $this->mapRow($row->fields);
    }

    /** Insert a freshly registered credential and return it with its id. */
    protected function persistCredential(PasskeyCredential $credential, ?string $label): PasskeyCredential
    {
        $existing = $this->database->queryBuilder()
            ->table(self::TABLE)
            ->where('credential_id', $credential->credentialId)
            ->first();
        if ($existing && $existing->numRows > 0) {
            throw new PasskeyException('Credential already registered');
        }

        $this->database->queryBuilder()
            ->table(self::TABLE)
            ->insert([
                'userid'          => $credential->userId,
                'credential_id'   => $credential->credentialId,
                'public_key'      => $credential->publicKey,
                'sign_count'      => $credential->signCount,
                'aaguid'          => $credential->aaguid,
                'transports'      => json_encode($credential->transports),
                'name'            => $label,
                'backup_eligible' => $credential->backupEligible,
                'backup_state'    => $credential->backupState,
                'is_active'       => true,
                'created_at'      => date('Y-m-d H:i:s'),
            ]);

        $row = $this->database->queryBuilder()
            ->table(self::TABLE)
            ->where('credential_id', $credential->credentialId)
            ->first();

        return $this->mapRow($row->fields);
    }

    /** Write back the advanced signature counter and last-used time. */
    protected function updateSignCount(int $id, int $signCount): void
    {
        $this->database->queryBuilder()
            ->table(self::TABLE)
            ->where('credentialid', $id)
            ->update([
                'sign_count'   => $signCount,
                'last_used_at' => date('Y-m-d H:i:s'),
            ]);
    }

    /** Whether $userId owns credential row $credentialId. */
    protected function ownsCredential(int $userId, int $credentialId): bool
    {
        $row = $this->database->queryBuilder()
            ->table(self::TABLE)
            ->where('credentialid', $credentialId)
            ->where('userid', $userId)
            ->first();
        return $row && $row->numRows > 0;
    }

    /**
     * Map a DB row to a PasskeyCredential VO.
     *
     * @param array<string,mixed> $f
     */
    protected function mapRow(array $f): PasskeyCredential
    {
        $transports = [];
        if (!empty($f['transports'])) {
            $decoded = json_decode((string) $f['transports'], true);
            if (is_array($decoded)) {
                $transports = $decoded;
            }
        }

        return new PasskeyCredential(
            (int) $f['credentialid'],
            (int) $f['userid'],
            (string) $f['credential_id'],
            (string) $f['public_key'],
            (int) $f['sign_count'],
            isset($f['aaguid']) && $f['aaguid'] !== null ? (string) $f['aaguid'] : null,
            $transports,
            isset($f['name']) && $f['name'] !== null ? (string) $f['name'] : null,
            $this->toBool($f['backup_eligible'] ?? false),
            $this->toBool($f['backup_state'] ?? false),
            $this->toBool($f['is_active'] ?? true),
            isset($f['created_at']) && $f['created_at'] !== null ? (string) $f['created_at'] : null,
            isset($f['last_used_at']) && $f['last_used_at'] !== null ? (string) $f['last_used_at'] : null
        );
    }

    /**
     * Normalise a DB boolean across drivers (MySQL 1/0, PostgreSQL t/f, native
     * bool, "true"/"false").
     */
    protected function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        $s = strtolower(trim((string) $value));
        return in_array($s, ['1', 't', 'true', 'yes', 'y'], true);
    }
}
