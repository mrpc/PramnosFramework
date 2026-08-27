<?php

declare(strict_types=1);

namespace Pramnos\Auth;

/**
 * The second factors this installation has, strongest first.
 *
 * The login flow asks this rather than naming factors, so an application adds SMS or a
 * push to its own app from a service provider and the flow, the step-up screen and the
 * audit log pick it up without being edited:
 *
 * ```php
 * // In a service provider's boot()
 * \Pramnos\Auth\SecondFactorRegistry::register(new \MyApp\Auth\SmsSecondFactor());
 * ```
 *
 * ## Why a registry rather than configuration
 *
 * Because a factor is code, not a name. An SMS adaptor needs a gateway account, a phone
 * number per user and a bill; only the application has those. A configuration key could
 * name a class, which is the same thing with a worse error message when it is missing.
 *
 * ## What is *not* here
 *
 * The application's opt-in list. `auth.twofactor_methods` still decides which registered
 * factors may be used — registering an adaptor makes it possible, not active. The two are
 * separate because a shared codebase may register several and a given deployment offer
 * one, and because an operator switching a factor off must not require a code change.
 */
class SecondFactorRegistry
{
    /** @var array<string, SecondFactorInterface> */
    private static array $factors = array();

    /** Have the framework's own factors been put in place? */
    private static bool $defaultsRegistered = false;

    /**
     * Add a factor, or replace one registered under the same name.
     *
     * Replacing is deliberate: an application that wants the mailed code to go through its
     * own transactional-mail provider registers its own `email` and gets it, rather than
     * having two factors answering to one name and the flow picking whichever came first.
     */
    public static function register(SecondFactorInterface $factor): void
    {
        self::$factors[$factor->name()] = $factor;
    }

    /**
     * Every registered factor the application allows, strongest first.
     *
     * @return list<SecondFactorInterface>
     */
    public static function all(): array
    {
        self::registerDefaults();

        /**
         * The application's list decides, when there is an application.
         *
         * With none — a console command, a worker, a unit test — everything registered is
         * allowed. There is no configuration to honour in that state, and a factor got
         * there because code put it there; filtering it out would mean a registry that
         * silently answers "nothing" outside a web request, which is the shape of a bug
         * nobody finds until a CLI tool needs to verify a code.
         */
        $application = \Pramnos\Application\Application::currentInstance();
        $allowed = is_object($application) ? EmailSecondFactor::allowedMethods() : null;

        $factors = array();

        foreach (self::$factors as $name => $factor) {
            if ($allowed === null || in_array($name, $allowed, true)) {
                $factors[] = $factor;
            }
        }

        usort($factors, static fn (SecondFactorInterface $a, SecondFactorInterface $b): int
            => $b->strength() <=> $a->strength());

        return $factors;
    }

    /**
     * One factor by name, or null when it is unregistered or not allowed here.
     *
     * Null rather than an exception: the name arrives from a form field, so an unknown one
     * is a stale page or somebody probing, and neither deserves a stack trace.
     */
    public static function get(string $name): ?SecondFactorInterface
    {
        foreach (self::all() as $factor) {
            if ($factor->name() === $name) {
                return $factor;
            }
        }

        return null;
    }

    /**
     * The factors this account can actually complete, strongest first.
     *
     * @return list<SecondFactorInterface>
     */
    public static function enrolledFor(int $userId): array
    {
        $enrolled = array();

        foreach (self::all() as $factor) {
            try {
                if ($factor->isEnrolledFor($userId)) {
                    $enrolled[] = $factor;
                }
            } catch (\Throwable $exception) {
                // A factor that cannot answer is not offered. One adaptor with an
                // unreachable gateway must not make every account unable to sign in.
                \Pramnos\Logs\Logger::log(
                    'Second factor ' . $factor->name() . ' could not answer for '
                    . $userId . ': ' . $exception->getMessage(),
                    'auth'
                );
            }
        }

        return $enrolled;
    }

    /**
     * Forget everything, including the built-ins.
     *
     * Test seam. Without it the first test to register a double decides for the whole run.
     */
    public static function reset(): void
    {
        self::$factors = array();
        self::$defaultsRegistered = false;
    }

    /**
     * The framework's own factors, registered on first use.
     *
     * Lazily rather than from a service provider so that a console command, a test or an
     * application that boots no providers still gets the built-in behaviour. An
     * application that registers its own `totp` or `email` before this runs keeps it: the
     * defaults never overwrite what is already there.
     */
    private static function registerDefaults(): void
    {
        if (self::$defaultsRegistered) {
            return;
        }

        self::$defaultsRegistered = true;

        foreach ([new Factors\TotpSecondFactor(), new Factors\EmailCodeSecondFactor()] as $factor) {
            if (!isset(self::$factors[$factor->name()])) {
                self::$factors[$factor->name()] = $factor;
            }
        }
    }
}
