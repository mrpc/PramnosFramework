<?php

declare(strict_types=1);

namespace Pramnos\Auth\OAuth2\Client;

/**
 * Something went wrong talking to a provider.
 *
 * It carries the provider's own `error` code separately from the message, because one of
 * them is terminal and the rest are not. `invalid_grant` means the grant is gone — the user
 * changed their password, removed the application, or the refresh token expired — and no
 * amount of retrying will bring it back; every other error is worth trying again. That
 * distinction is what {@see isTerminal()} exists for, and it is why a caller should read
 * the code rather than match on the message.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license     MIT
 */
class OAuthClientException extends \RuntimeException
{
    /**
     * OAuth2 error codes that mean the grant is gone for good.
     *
     * `invalid_grant` is the one RFC 6749 §5.2 defines for it. `invalid_client` is here
     * too, reluctantly: it usually means the application's own credentials are wrong,
     * which is a deployment problem rather than a dead connection — but several providers
     * also return it for a revoked grant, and retrying a revoked grant every fifteen
     * minutes for ever is worse than stopping and saying so.
     *
     * @var list<string>
     */
    private const TERMINAL = ['invalid_grant', 'unauthorized_client', 'invalid_client'];

    public function __construct(
        string $message,
        public readonly string $error = '',
        public readonly string $provider = '',
        public readonly int $status = 0,
    ) {
        parent::__construct($message);
    }

    /**
     * Is this a grant that will never work again?
     *
     * A caller that is refreshing in the background uses this to decide between marking a
     * connection dead and leaving it alone for the next run. Getting it wrong in one
     * direction retries a revoked grant for ever; in the other it kills a live connection
     * over a network blip, and the user has to authorise again for nothing.
     */
    public function isTerminal(): bool
    {
        return in_array($this->error, self::TERMINAL, true);
    }
}
