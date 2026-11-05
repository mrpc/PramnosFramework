<?php

declare(strict_types=1);

namespace Pramnos\Dashboard;

/**
 * The framework's own organisations, as a {@see OrganizationSource}: `organizations` by name, and
 * an active, unexpired membership in the table the permission system reads
 * ({@see \Pramnos\Auth\Role::membershipTable()}).
 */
final class FrameworkOrganizations implements OrganizationSource
{
    /** Called an organisation. */
    public function label(): string
    {
        return 'Organisation';
    }

    /** @return array<int|string, string> The active ones, up to 500, by name; none without the table. */
    public function choices(): array
    {
        try {
            $rows = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#organizations')
                ->select(['organization_id', 'name'])->where('is_active', true)->orderBy('name')->limit(500)->getAllOrFail();
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['organization_id']] = (string) $row['name'];
        }

        return $out;
    }

    /** An active, unexpired member; nobody without the membership table. */
    public function isMember(object $user, string $id): bool
    {
        $userId = (int) ($user->userid ?? 0);
        if ($userId < 2 || !ctype_digit($id)) {
            return false;
        }
        try {
            $db    = \Pramnos\Framework\Factory::getDatabase();
            $table = \Pramnos\Auth\Role::membershipTable();
            if (!$db->schema()->hasTable($table)) {
                return false;
            }
            $row = $db->queryBuilder()->table($table)->where('userid', $userId)
                ->where(\Pramnos\Auth\Role::organizationColumn(), (int) $id)->where('is_active', true)->first();
            if (!$row || $row->numRows === 0) {
                return false;
            }
            $expires = $row->fields['expires_at'] ?? null;

            return $expires === null || $expires === '' || strtotime((string) $expires) > time();
        } catch (\Throwable) {
            return false;
        }
    }
}
