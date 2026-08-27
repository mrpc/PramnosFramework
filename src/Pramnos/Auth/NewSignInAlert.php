<?php

namespace Pramnos\Auth;

/**
 * Notifies a user, if they asked to be, when an account sign-in comes from a
 * browser/platform combination that account has not used before.
 *
 * ## Opt-in, and stored without a migration
 *
 * The preference lives in `userdetails` — the framework's per-user key/value store,
 * the same table password-reset tokens use. That is deliberate: it needs no schema
 * change, so the feature works on every installation the moment it is upgraded,
 * including the ones whose `migration_cutoff` skips baseline migrations. It also
 * inherits the cascade on user deletion that table already has, which a GDPR-relevant
 * preference needs anyway.
 *
 * Absent means off. A security notification nobody asked for, describing a sign-in
 * they just performed, arriving from an address they may not recognise, is how a
 * feature like this teaches people to ignore it.
 *
 * ## What counts as "new"
 *
 * A {@see SignInFingerprint} — browser family and platform family, nothing else.
 * **Not the IP address**, which is dynamic on most consumer connections and would
 * fire on a router reboot; and not the browser version, which changes every four
 * weeks and would fire monthly. See that class for why the coarseness is the feature.
 *
 * ## Where the history comes from, and why it matters on day one
 *
 * From `authserver.user_activity_log`, which has recorded `user_agent` against every
 * `login` since the auth feature shipped.
 *
 * That choice is the difference between a feature and an incident. The obvious
 * alternative — start recording device fingerprints now and compare against those —
 * has no history on the day it is switched on, so **every user's next sign-in looks
 * new** and everyone who opted in is notified at once. Reading an audit trail that
 * already holds months of user agents means the first sign-in after upgrading is
 * recognised as familiar, which is what it is.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class NewSignInAlert
{
    /**
     * The connection to use, or the framework's when none is given.
     *
     * Every method here takes one. That is not ceremony: a class that resolves its
     * own connection cannot be pointed at a test database, and cannot be pointed at
     * a second one by an application that has two. This framework has already
     * documented what the alternative costs — see `Service::database()`, where a
     * defaulted connection in a converted class would have silently repointed 59 call
     * sites.
     *
     * @param  \Pramnos\Database\Database|null $database Explicit connection
     * @return \Pramnos\Database\Database
     */
    private static function db(?\Pramnos\Database\Database $database = null): \Pramnos\Database\Database
    {
        return $database ?? \Pramnos\Framework\Factory::getDatabase();
    }

    /**
     * The `userdetails.fieldname` holding the opt-in.
     *
     * @var string
     */
    public const PREFERENCE = 'notify_new_signin';

    /**
     * How many previous sign-ins to consider.
     *
     * Bounded so a long-lived account does not read its entire history at every
     * login. A hundred sign-ins is far past the point where a person has stopped
     * acquiring new browsers, and the query is served by `idx_user_activity_userid`.
     *
     * @var int
     */
    private const HISTORY_LIMIT = 100;

    /**
     * Whether this user asked to be told.
     *
     * @param  int                             $userId   The user
     * @param  \Pramnos\Database\Database|null $database Explicit connection
     * @return bool
     */
    public static function isEnabledFor(int $userId, ?\Pramnos\Database\Database $database = null): bool
    {
        if ($userId <= 0) {
            return false;
        }

        try {
            $value = self::db($database)->queryBuilder()
                ->table('#PREFIX#userdetails')
                ->where('userid', $userId)
                ->where('fieldname', self::PREFERENCE)
                ->value('value');
        } catch (\Throwable) {
            // A preference that cannot be read is not a reason to fail a login, and
            // the safe answer for "should we send mail" is no.
            return false;
        }

        return (string) $value === '1';
    }

    /**
     * Turn the notification on or off for a user.
     *
     * @param  int                             $userId   The user
     * @param  bool                            $enabled  Whether to notify
     * @param  \Pramnos\Database\Database|null $database Explicit connection
     * @return void
     */
    public static function setEnabledFor(int $userId, bool $enabled, ?\Pramnos\Database\Database $database = null): void
    {
        if ($userId <= 0) {
            return;
        }

        self::db($database)->queryBuilder()
            ->table('#PREFIX#userdetails')
            ->upsert(
                array(
                    'userid'    => $userId,
                    'fieldname' => self::PREFERENCE,
                    'value'     => $enabled ? '1' : '0',
                ),
                array('userid', 'fieldname'),
                array('value')
            );
    }

    /**
     * The fingerprints this account has signed in with before.
     *
     * @param  int    $userId  The user
     * @param  string $exclude A fingerprint to leave out — the current sign-in, whose
     *                         own log row has usually been written by the time this
     *                         runs, and which would otherwise always match itself
     * @param  \Pramnos\Database\Database|null $database Explicit connection
     * @return array<string, true> Fingerprint => true
     */
    public static function knownFingerprints(int $userId, string $exclude = '', ?\Pramnos\Database\Database $database = null): array
    {
        $known = array();

        try {
            $rows = self::db($database)->queryBuilder()
                ->table('authserver.user_activity_log')
                ->select('user_agent')
                ->where('userid', $userId)
                ->where('action', 'login')
                ->orderBy('created_at', 'desc')
                ->limit(self::HISTORY_LIMIT)
                ->getAll();
        } catch (\Throwable $ex) {
            \Pramnos\Logs\Logger::log(
                'NewSignInAlert could not read sign-in history: ' . $ex->getMessage(),
                'auth'
            );

            // No history is not the same as no matches. Returning an empty set here
            // would mean "everything is new", and the caller would notify — turning a
            // database hiccup into mail to the user. The caller treats a failure as
            // "say nothing", which is why this is distinguishable from a real empty.
            return array();
        }

        foreach ((array) $rows as $row) {
            $fingerprint = SignInFingerprint::fromUserAgent($row['user_agent'] ?? null);
            if ($fingerprint !== $exclude) {
                $known[$fingerprint] = true;
            }
        }

        return $known;
    }

    /**
     * Check the sign-in that just happened, and notify if it warrants it.
     *
     * Called from the login lifecycle **after** the activity log has recorded this
     * sign-in, which is why {@see isNew()} excludes the fingerprint it is testing —
     * otherwise every sign-in matches its own row and this silently never fires.
     *
     * Best-effort throughout. A notification is not worth failing a login for, and
     * the failure modes here are all external: an unreachable mail server, a log
     * table that does not exist, a user record that cannot be loaded.
     *
     * @param  int                             $userId   Who just signed in
     * @param  \Pramnos\Database\Database|null $database Explicit connection
     * @return bool Whether a notification was sent — for callers that want to log it
     */
    public static function checkAndNotify(int $userId, ?\Pramnos\Database\Database $database = null): bool
    {
        try {
            if (!self::isEnabledFor($userId, $database)) {
                return false;
            }

            $fingerprint = SignInFingerprint::current();
            if (!self::isNew($userId, $fingerprint, $database)) {
                return false;
            }

            $user = new \Pramnos\User\User($userId);
            if (empty($user->email)) {
                // Nothing to send to. Not an error: an account can exist without a
                // deliverable address, and the alternative — throwing — would fail a
                // login over a notification.
                return false;
            }

            (new \Pramnos\Notification\Notifier())->sendNow(
                $user,
                new Notifications\NewSignInNotification($fingerprint)
            );

            return true;
        } catch (\Throwable $ex) {
            \Pramnos\Logs\Logger::log(
                'New sign-in notification failed: ' . $ex->getMessage(),
                'auth'
            );

            return false;
        }
    }

    /**
     * Whether this sign-in is from a combination the account has not used.
     *
     * **An account with no history at all answers `false`.** A first-ever sign-in is
     * new by definition and notifying about it tells the person something they are
     * currently doing, from an account they just created. It is also what every
     * account looks like on the day this ships if the history source is empty — see
     * the class docblock.
     *
     * @param  int    $userId      The user
     * @param  string $fingerprint The current sign-in's fingerprint
     * @param  \Pramnos\Database\Database|null $database Explicit connection
     * @return bool
     */
    public static function isNew(int $userId, string $fingerprint, ?\Pramnos\Database\Database $database = null): bool
    {
        $known = self::knownFingerprints($userId, $fingerprint, $database);

        if ($known === array()) {
            return false;
        }

        return !isset($known[$fingerprint]);
    }
}
