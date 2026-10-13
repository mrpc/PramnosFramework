<?php

declare(strict_types=1);

namespace Pramnos\Llm\Policy;

use Pramnos\Llm\Connection;

/**
 * Data a tool returns that may only go to some providers.
 *
 * A tool marks its result with a tag — always, or by what it returned (`Tools::tag()`). When a
 * tagged result comes back and the connection in use does not {@see accepts()} it, the answer
 * **moves** to {@see alternative()} for the rest of the conversation, before the result is sent
 * anywhere; with no alternative, the model is told {@see refusalResult()} in its place.
 *
 * An example: figures from Google's APIs, which an application's terms say never go to one
 * provider. The rule names the tag `google`, accepts the providers that may take it, and offers
 * the one an administrator chose for it — after asking {@see Consent} for it.
 */
interface DataRule
{
    /** The tag this rule governs. */
    public function tag(): string;

    /** May data with this tag go out on this connection? */
    public function accepts(Connection $connection): bool;

    /**
     * Where the answer moves to, or null when there is nowhere it may go.
     *
     * Asking consent for the alternative is this method's job: it knows which provider it is
     * about to offer.
     *
     * @param array<string, mixed> $context
     */
    public function alternative(array $context, Connection $current): ?Connection;

    /** Told to the model in place of a result that had nowhere to go. */
    public function refusalResult(): string;
}
