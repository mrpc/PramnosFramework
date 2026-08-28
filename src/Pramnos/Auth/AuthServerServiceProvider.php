<?php

declare(strict_types=1);

namespace Pramnos\Auth;

use Pramnos\Application\ServiceProvider;

/**
 * Bootstraps the OAuth2 Authorization Server feature.
 *
 * Registered automatically by the FeatureRegistry when 'authserver' appears
 * in the application's app.php features list.
 *
 * Lifecycle:
 *   register() — runs before boot(); safe for early bindings only.
 *   boot()     — runs after all providers have registered; safe for anything
 *                that depends on other features (e.g. 'auth') being registered.
 *
 * RSA key generation is NOT automatic at bootstrap to avoid file-system side
 * effects during request handling. Call OAuth2ServerFactory::generateKeyPair()
 * from `pramnos init` or a one-time setup command instead.
 *
 */
class AuthServerServiceProvider extends ServiceProvider
{
    /**
     * Register early bindings.
     *
     * Binding the factory as a lazy closure lets controllers and controllers
     * resolve it via the application container without instantiating it on
     * every request.
     */
    public function register(): void
    {
        // Nothing to pre-register at framework level.
        // Applications may override boot() to bind the factory to a DI container.
    }

    /**
     * Bootstrap OAuth2 services after all providers have registered.
     *
     * RSA keys are still not generated here — that is a setup-time action, not a
     * per-request one, and controllers that need the keys guard for themselves.
     *
     * The signing-key **health check** is registered, which is a different thing:
     * registering it touches nothing, and the check only runs when something asks
     * for a health report. The built-in checks cover the database, disk and
     * memory and say nothing about the key pair, so `/health/check` reported `ok`
     * on a server that could not issue a single token.
     */
    public function boot(): void
    {
        \Pramnos\Health\HealthRegistry::register(new Health\SigningKeysCheck());

        $this->registerMailActions();
    }

    /**
     * The one-click mail actions this feature can actually honour.
     *
     * Registered, not used. `revoke-sessions` is the handler a "this wasn't me" button needs,
     * and the framework's own new-sign-in alert deliberately does not offer one: that message
     * carries no link at all, because a link in an unexpected security email is the shape of the
     * attack it warns about. Whether to reverse that for your own application is a judgement
     * about your users, so the capability is here and the decision is not made for you:
     *
     * ```php
     * $url = MailAction::url('revoke-sessions', ['user' => $userId], 3600);
     * $mail->addStructuredData(Actions::confirm('It wasn\'t me', $url));
     * ```
     *
     * A short TTL is the right default for that one. The link ends every session on the
     * account, and a message that sat in a mailbox for a month should not still be able to.
     */
    protected function registerMailActions(): void
    {
        \Pramnos\Email\MailAction::register(
            'revoke-sessions',
            static function (array $claim): bool {
                $userId = (int) ($claim['user'] ?? 0);

                // Below 2 is the guest and the system account; `revokeOtherSessions()` refuses
                // them too, and a token naming one is a token that was built wrong.
                if ($userId < 2) {
                    return false;
                }

                $user = new \Pramnos\User\User($userId);

                if ((int) $user->userid !== $userId) {
                    return false;
                }

                // `null`, not the current session: there is no current session. The caller is a
                // mailbox provider's server, and the point is to end the one the reader is
                // worried about.
                $user->revokeOtherSessions(null);

                /*
                 * True even when nothing was ended.
                 *
                 * The handler has to be idempotent — Gmail retries, and a reader may press the
                 * button twice — and "there were no sessions left to end" is the desired state,
                 * not a failure. Returning false would turn a second press into a 500 and, on a
                 * provider that retries 500s, into a loop.
                 */
                return true;
            },
            false,
            'Every session on your account has been signed out. Change your password next.'
        );
    }
}
