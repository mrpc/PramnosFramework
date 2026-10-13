<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst\Tools;

use Pramnos\Framework\Factory;
use Pramnos\Llm\Analyst\Redactor;
use Pramnos\Mcp\McpToolInterface;

/**
 * One account, when the administrator names it: its type, state and dates — and its email
 * address only when asked for, for that one account. Never a list.
 *
 * Its result is tagged `personal`, so a data rule can keep it from a provider that may not
 * have personal data.
 */
final class UserTool implements McpToolInterface
{
    public function name(): string
    {
        return 'user';
    }

    public function description(): string
    {
        return 'One account, by id or username, when the administrator asks about a particular '
            . 'person: user type, active, validated, when they signed up and last signed in. The '
            . 'email address only with include_email, and only when the administrator asked for it.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'userid'        => ['type' => 'integer'],
            'username'      => ['type' => 'string'],
            'include_email' => ['type' => 'boolean', 'description' => 'Only when the administrator asked for this person\'s email.'],
        ]];
    }

    public function execute(array $input): mixed
    {
        $db = Factory::getDatabase();

        if (!$db->schema()->hasTable('#PREFIX#users')) {
            return 'This installation keeps no user accounts.';
        }

        $query = $db->queryBuilder()->table('#PREFIX#users')->select(['userid', 'username', 'email', 'usertype', 'active', 'validated', 'regdate', 'lastlogin'])->where('userid', '>', 1);

        if ((int) ($input['userid'] ?? 0) > 0) {
            $query->where('userid', (int) $input['userid']);
        } elseif (trim((string) ($input['username'] ?? '')) !== '') {
            $query->where('username', trim((string) $input['username']));
        } else {
            return 'Error: name one account, by userid or username.';
        }

        $result = $query->first();
        $row    = $result && (int) $result->numRows > 0 ? (array) $result->fields : null;

        if ($row === null) {
            return 'There is no such account.';
        }

        $email = !empty($input['include_email']) ? (string) $row['email'] : '';
        $found = [
            'userid'      => (int) $row['userid'],
            'username'    => (string) $row['username'],
            'usertype'    => (int) $row['usertype'],
            'active'      => (bool) $row['active'],
            'validated'   => (bool) $row['validated'],
            'signed_up'   => (int) $row['regdate'] > 0 ? gmdate('Y-m-d', (int) $row['regdate']) : null,
            'last_signin' => (int) $row['lastlogin'] > 0 ? gmdate('Y-m-d H:i', (int) $row['lastlogin']) : null,
        ] + ($email !== '' ? ['email' => $email] : []);

        // A username can itself be an address or a phone number: masked like everything else,
        // except the one email asked for.
        return Redactor::clean((string) json_encode($found, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), [$email]);
    }
}
