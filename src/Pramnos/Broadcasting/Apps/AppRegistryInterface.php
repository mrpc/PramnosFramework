<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting\Apps;

/**
 * Where the realtime edge looks up the app behind a connection key.
 *
 * Two implementations ship: {@see ConfigAppRegistry} reads the single app in
 * `broadcasting.pusher` (the historical behaviour), and
 * {@see AuthServerAppRegistry} reads the `applications` table the AuthServer
 * already maintains, which makes apps first-class records with an owner, a
 * status and a rotatable secret instead of entries in a config file.
 *
 * **Both the signer and the verifier resolve through this.** The web request that
 * signs a channel token and the daemon that verifies it must agree on the app's
 * secret, and they are different processes reading the same configuration. An
 * interface with one lookup is what keeps them from drifting.
 */
interface AppRegistryInterface
{
    /**
     * The app presenting $key, or null when there is no active app with it.
     *
     * Implementations must treat an inactive or unknown key the same way — a
     * caller deciding whether to admit a connection has no use for the
     * difference, and telling them apart would leak which keys exist.
     */
    public function findByKey(string $key): ?BroadcastApp;

    /**
     * The app to use when no key was presented, or null when there is no sensible
     * default. Single-app deployments have one; a multi-tenant registry does not.
     */
    public function defaultApp(): ?BroadcastApp;
}
