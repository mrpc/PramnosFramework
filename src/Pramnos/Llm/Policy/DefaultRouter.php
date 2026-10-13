<?php

declare(strict_types=1);

namespace Pramnos\Llm\Policy;

use Pramnos\Llm\Connection;

/**
 * The person signed in, no organisation, and the connection as it was built.
 */
class DefaultRouter implements Router
{
    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function context(array $context): array
    {
        if (!isset($context['userid'])) {
            $user = \Pramnos\User\User::getCurrentUser();
            $id   = is_object($user) ? (int) ($user->userid ?? 0) : 0;

            $context['userid'] = $id > 0 ? $id : null;
        }

        return $context;
    }

    /** @param array<string, mixed> $context */
    public function route(array $context, Connection $connection): Connection
    {
        return $connection;
    }
}
