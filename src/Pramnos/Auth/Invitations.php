<?php

declare(strict_types=1);

namespace Pramnos\Auth;

use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\User\Token;

/**
 * Invitations to register: one address, one link, used once.
 *
 * An invitation opens registration for a single address while it is otherwise closed. Because
 * the link is mailed to that address and registration accepts that address only, following it
 * is proof the person holds the mailbox — an invited account starts verified.
 *
 * ```php
 * $invite = (new Invitations())->invite('maria@example.com', [
 *     'invitedBy'      => $admin->userid,
 *     'organizationId' => 7,        // joins on acceptance
 *     'roleId'         => 3,        // and is given this role
 *     'note'           => 'Finance team',
 * ]);
 * $invite['link'];   // shown once; only its hash is stored
 * ```
 *
 * What it guarantees:
 *
 * - **The token is stored as {@see Token::lookup()}**, never in the clear — a copy of the table
 *   is not a way to register as anyone.
 * - **Used once**, atomically: the row is claimed by an update conditioned on it being unused, so
 *   two registrations racing on one link cannot both succeed.
 * - **One live invitation per address** — a new one withdraws the old — and **none for an address
 *   that already has an account**.
 * - **Short-lived**: `auth_invitation_ttl_hours`, 48 unless set.
 * - Accepting joins the organisation, gives the role, and fires `invitation.accepted` with the
 *   row, so an application attaches what is its own (the row's `metadata`) in a listener.
 */
class Invitations
{
    public const TTL_SETTING = 'auth_invitation_ttl_hours';

    public const DEFAULT_TTL_HOURS = 48;

    public const EVENT_ACCEPTED = 'invitation.accepted';

    private const TABLE = 'authserver.invitations';

    public function __construct(private ?Database $database = null)
    {
    }

    /** How long a new link lasts, in seconds. */
    public static function ttlSeconds(): int
    {
        $hours = (int) Settings::getSetting(self::TTL_SETTING, self::DEFAULT_TTL_HOURS);

        return ($hours > 0 ? $hours : self::DEFAULT_TTL_HOURS) * 3600;
    }

    /** The address an invitation is for, as it is stored and compared. */
    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Invite an address. Returns the new row's id, the token and the link — the only time the
     * token exists outside the mail.
     *
     * @param array{invitedBy?: int|null, organizationId?: int|null, roleId?: int|null,
     *              note?: string|null, metadata?: array<string, mixed>|null, send?: bool} $options
     * @return array{invitation_id: int, token: string, link: string, expires_at: int, sent: bool}
     * @throws InvitationException
     */
    public function invite(string $email, array $options = []): array
    {
        $email = self::normalizeEmail($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvitationException('invalid_email', 'That is not an email address.');
        }
        if ($this->accountExists($email)) {
            throw new InvitationException('account_exists', 'An account with that address already exists.');
        }

        $invitedBy      = isset($options['invitedBy']) ? (int) $options['invitedBy'] : null;
        $organizationId = isset($options['organizationId']) && (int) $options['organizationId'] > 0
            ? (int) $options['organizationId'] : null;
        $roleId         = isset($options['roleId']) && (int) $options['roleId'] > 0
            ? (int) $options['roleId'] : null;

        if ($roleId !== null) {
            $this->assertRoleFits($roleId, $organizationId);
        }

        $now   = time();
        $token = bin2hex(random_bytes(32));
        $metadata = $options['metadata'] ?? null;

        $this->withdrawLive($email, $now);
        $this->db()->queryBuilder()->table(self::TABLE)->insert([
            'email'           => $email,
            'token_lookup'    => Token::lookup($token),
            'organization_id' => $organizationId,
            'role_id'         => $roleId,
            'note'            => isset($options['note']) && trim((string) $options['note']) !== ''
                ? mb_substr(trim((string) $options['note']), 0, 255) : null,
            'metadata'        => $metadata === null ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'invited_by'      => $invitedBy,
            'created_at'      => $now,
            'expires_at'      => $now + self::ttlSeconds(),
        ]);

        $row = $this->byLookup(Token::lookup($token));
        $id  = (int) ($row['invitation_id'] ?? 0);
        $link = $this->linkFor($token);

        $sent = ($options['send'] ?? true) ? $this->send($email, $link, $row ?? []) : false;

        return [
            'invitation_id' => $id,
            'token'         => $token,
            'link'          => $link,
            'expires_at'    => (int) ($row['expires_at'] ?? 0),
            'sent'          => $sent,
        ];
    }

    /**
     * The live invitation a token belongs to — not accepted, not withdrawn, not expired — or null.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || !ctype_xdigit($token)) {
            return null;
        }

        $row = $this->byLookup(Token::lookup($token));

        return $row !== null && self::stateOf($row) === 'waiting' ? $row : null;
    }

    /**
     * Use the invitation for the account just created. Returns the row, or null if it was no
     * longer live — including when another registration claimed it first.
     *
     * @return array<string, mixed>|null
     */
    public function accept(string $token, int $userId): ?array
    {
        if ($userId <= 1 || $this->find($token) === null) {
            return null;
        }

        $now = time();
        $result = $this->db()->queryBuilder()->table(self::TABLE)
            ->where('token_lookup', Token::lookup($token))
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', $now)
            ->update(['accepted_at' => $now, 'accepted_userid' => $userId]);

        if (!is_object($result) || (int) $result->getAffectedRows() !== 1) {
            return null;
        }

        $row = $this->byLookup(Token::lookup($token));
        if ($row === null) {
            return null;
        }

        $this->join($row, $userId);

        // Listeners receive the row and the new account's id: `function (array $invitation, int $userId)`.
        \Pramnos\Event\Event::fire(self::EVENT_ACCEPTED, $row, $userId);

        return $row;
    }

    /** Withdraw an invitation that has not been used. */
    public function revoke(int $invitationId): bool
    {
        $result = $this->db()->queryBuilder()->table(self::TABLE)
            ->where('invitation_id', $invitationId)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => time()]);

        return is_object($result) && (int) $result->getAffectedRows() === 1;
    }

    /**
     * Mail a fresh link for an invitation that is still waiting — a new token and a new expiry,
     * since the old token cannot be recovered from its hash. Null if it is no longer waiting.
     *
     * @return array{invitation_id: int, token: string, link: string, expires_at: int, sent: bool}|null
     */
    public function resend(int $invitationId): ?array
    {
        $row = $this->byId($invitationId);
        if ($row === null || !in_array(self::stateOf($row), ['waiting', 'expired'], true)) {
            return null;
        }

        $token   = bin2hex(random_bytes(32));
        $expires = time() + self::ttlSeconds();
        $this->db()->queryBuilder()->table(self::TABLE)
            ->where('invitation_id', $invitationId)
            ->update(['token_lookup' => Token::lookup($token), 'expires_at' => $expires]);

        $link = $this->linkFor($token);
        $row['expires_at'] = $expires;

        return [
            'invitation_id' => $invitationId,
            'token'         => $token,
            'link'          => $link,
            'expires_at'    => $expires,
            'sent'          => $this->send((string) $row['email'], $link, $row),
        ];
    }

    /**
     * Invitations, newest first, each with its `state`.
     *
     * @param int|null $organizationId Only those into this organisation
     * @return list<array<string, mixed>>
     */
    public function all(?int $organizationId = null, int $limit = 200): array
    {
        $query = $this->db()->queryBuilder()->table(self::TABLE)
            ->orderBy('created_at', 'desc')
            ->limit(max(1, $limit));
        if ($organizationId !== null) {
            $query->where('organization_id', $organizationId);
        }

        $rows = [];
        $result = $query->get();
        while ($result && $result->fetch()) {
            $row = $result->fields;
            unset($row['token_lookup']);
            $row['state'] = self::stateOf($row);
            $rows[] = $row;
        }

        return $rows;
    }

    /** waiting, accepted, revoked or expired. */
    public static function stateOf(array $row, ?int $now = null): string
    {
        if (!empty($row['accepted_at'])) {
            return 'accepted';
        }
        if (!empty($row['revoked_at'])) {
            return 'revoked';
        }

        return (int) ($row['expires_at'] ?? 0) > ($now ?? time()) ? 'waiting' : 'expired';
    }

    /** The registration link for a token. */
    public function linkFor(string $token): string
    {
        return \Pramnos\Http\SiteUrl::to('register?invite=' . rawurlencode($token));
    }

    // ── Internal ────────────────────────────────────────────────────────────────

    /** Add the new account to the invitation's organisation and give it the role. */
    protected function join(array $row, int $userId): void
    {
        $organizationId = (int) ($row['organization_id'] ?? 0);
        $invitedBy      = isset($row['invited_by']) ? (int) $row['invited_by'] : null;

        if ($organizationId > 0) {
            $this->db()->queryBuilder()->table(Role::membershipTable())->upsert(
                [
                    'userid'                    => $userId,
                    Role::organizationColumn()  => $organizationId,
                    'granted_by'                => $invitedBy,
                    'is_active'                 => 1,
                ],
                ['userid', Role::organizationColumn()],
                ['granted_by', 'is_active']
            );
        }

        $roleId = (int) ($row['role_id'] ?? 0);
        if ($roleId > 0) {
            $role = new Role(new \Pramnos\Application\Controller());
            $role->load($roleId);
            if ((int) $role->roleid === $roleId && !$role->assignTo($userId, $invitedBy)) {
                \Pramnos\Logs\Logger::logError(
                    'Invitation ' . ($row['invitation_id'] ?? '?') . ': the role was not given — ' . $role->getLastError()
                );
            }
        }

        Permissions::getInstance()->clearCache();
    }

    /**
     * A role given on acceptance has to be one the account can hold: an organisation's role goes
     * only with an invitation into that organisation.
     */
    protected function assertRoleFits(int $roleId, ?int $organizationId): void
    {
        $role = new Role(new \Pramnos\Application\Controller());
        $role->load($roleId);
        if ((int) $role->roleid !== $roleId) {
            throw new InvitationException('unknown_role', 'That role does not exist.');
        }

        $roleOrganization = (int) ($role->{Role::organizationColumn()} ?? 0);
        if ($roleOrganization > 0 && $roleOrganization !== (int) $organizationId) {
            throw new InvitationException(
                'role_outside_organization',
                'That role belongs to another organisation than the one the invitation is into.'
            );
        }
    }

    /** Withdraw every unused invitation for this address, so one link is live at a time. */
    protected function withdrawLive(string $email, int $now): void
    {
        $this->db()->queryBuilder()->table(self::TABLE)
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $now]);
    }

    protected function accountExists(string $email): bool
    {
        return $this->db()->queryBuilder()->table('#PREFIX#users')
            ->whereRaw('LOWER(email) = ?', [$email])
            ->exists();
    }

    /** Mail the link. False when it could not be queued; the link still works. */
    protected function send(string $email, string $link, array $row): bool
    {
        try {
            $inviter = '';
            if (!empty($row['invited_by'])) {
                $user = new \Pramnos\User\User();
                $user->load((int) $row['invited_by']);
                $inviter = (string) ($user->username ?? '');
            }

            (new \Pramnos\Notification\Notifier())->sendNow(
                (object) ['email' => $email, 'userid' => 0],
                new Notifications\InvitationNotification(
                    $link,
                    self::ttlSeconds(),
                    $inviter,
                    (string) ($row['note'] ?? '')
                )
            );

            return true;
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::logError('Invitation mail to ' . $email . ' failed: ' . $e->getMessage(), $e);

            return false;
        }
    }

    /** @return array<string, mixed>|null */
    protected function byLookup(string $lookup): ?array
    {
        $row = $this->db()->queryBuilder()->table(self::TABLE)->where('token_lookup', $lookup)->first();

        return $row && $row->numRows > 0 ? $row->fields : null;
    }

    /** @return array<string, mixed>|null */
    protected function byId(int $id): ?array
    {
        $row = $this->db()->queryBuilder()->table(self::TABLE)->where('invitation_id', $id)->first();

        return $row && $row->numRows > 0 ? $row->fields : null;
    }

    protected function db(): Database
    {
        return $this->database ?? \Pramnos\Framework\Factory::getDatabase();
    }
}
