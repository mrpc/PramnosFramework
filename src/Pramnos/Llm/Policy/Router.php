<?php

declare(strict_types=1);

namespace Pramnos\Llm\Policy;

use Pramnos\Llm\Connection;

/**
 * Who a call is for, and where it goes.
 *
 * {@see context()} fills in what the caller did not say — the organisation of the request, for
 * an application that has one. {@see route()} may hand back another connection before anything
 * is sent: an organisation's own choice of provider, its own key (`$connection->with([...])`),
 * a cheaper project for material that may go to it.
 */
interface Router
{
    /**
     * The call's context, completed.
     *
     * @param array<string, mixed> $context What the caller passed to `Client::about()`
     * @return array<string, mixed>
     */
    public function context(array $context): array;

    /**
     * The connection this call goes out on.
     *
     * @param array<string, mixed> $context
     */
    public function route(array $context, Connection $connection): Connection;
}
