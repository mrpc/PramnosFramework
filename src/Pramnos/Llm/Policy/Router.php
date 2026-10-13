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
 *
 * ### An explicit null organisation is an answer
 *
 * A caller that passes `organization_id => null` means *no organisation* — the administrators'
 * analyst reads the whole installation and does exactly this. A router that fills in the
 * request's tenant must do it only when the key is **missing**:
 *
 * ```php
 * if (!array_key_exists('organization_id', $context)) {   // not `??=`: null was said on purpose
 *     $context['organization_id'] = $this->tenant();
 * }
 * ```
 *
 * Filling it with `??` turns the analyst's call into the administrator's own organisation's, with
 * that organisation's provider, key and plan.
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
