<?php

declare(strict_types=1);

namespace Pramnos\Auth;

use Pramnos\Database\Database;

/**
 * Deletes everything the framework holds about one account, and the account itself.
 *
 * ```php
 * (new AccountErasure($database))->erase($userId);
 * ```
 *
 * What an Article 17 erasure performs — {@see \Pramnos\Auth\Controllers\Account::eraseUserData()}
 * calls it — and what a test suite calls to remove a user it created, so that the two can
 * never disagree about what belongs to a person.
 *
 * ## Applications contribute their own rows through `account.data_erase`
 *
 * ```php
 * \Pramnos\Event\Event::listen('account.data_erase', function (int $userId) {
 *     // Delete this application's rows for $userId, then:
 *     return true;      // or false to stop the erase before anything is deleted
 * });
 * ```
 *
 * **Fired before the framework's own deletes, and that order is not arbitrary.** An
 * application's rows almost always carry a foreign key to `users`; deleting the user
 * first makes the framework's own `DELETE` fail on them, and an erase that half happened
 * is worse than one that did not start. A listener returning `false` stops the whole
 * erase, and this raises.
 *
 * In a multi-tenant application the data belongs to the **organisation**, not to the
 * person, and deleting the last member of a tenant is not the same act as deleting a
 * colleague's login. The listener owns that decision; there is no sensible default. And
 * nothing cascades into a table with no foreign key, which is most hypertables: delete
 * them explicitly.
 *
 * ## What the framework deletes
 *
 * Every table below is deleted explicitly. None of them carries a cascading foreign key
 * to `users` on every installation, so "the database will take care of it" is true on
 * none of them. A table the installation does not have is skipped and logged: it holds
 * none of this user's rows.
 *
 * Not deleted: the change log. Its `userid` is the *author* of a change to some other
 * record, and erasing it would rewrite other records' history.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class AccountErasure
{
    /** The event an application listens on to erase its own rows first. */
    public const EVENT = 'account.data_erase';

    /**
     * @param Database $database The connection to erase through
     */
    public function __construct(private Database $database)
    {
    }

    /**
     * Erase one account.
     *
     * @param  int $userId The account being erased
     * @return void
     * @throws \RuntimeException When a listener refuses the erase; nothing is deleted then
     */
    public function erase(int $userId): void
    {
        foreach (\Pramnos\Event\Event::fire(self::EVENT, $userId) as $answer) {
            if ($answer === false) {
                throw new \RuntimeException(
                    'A listener on ' . self::EVENT . ' refused the erase for user '
                    . $userId . '. Nothing has been deleted.'
                );
            }
        }

        $schema  = $this->database->schema();
        $skipped = [];

        foreach ($this->tables() as $table => $column) {
            if (!$schema->hasTable($table)) {
                $skipped[] = $table;

                continue;
            }

            $this->database->queryBuilder()->table($table)->where($column, $userId)->delete();
        }

        $this->eraseNotifications($userId);

        if ($skipped !== []) {
            // Logged, because "this installation has no such table" and "the erase missed
            // something" look identical from the outside, and only one of them is fine.
            \Pramnos\Logs\Logger::log(
                'Data erase for user ' . $userId . ': skipped tables this installation '
                . 'does not have — ' . implode(', ', $skipped),
                'auth'
            );
        }

        // Invitations it sent hold other people's addresses; the one it came from, its own.
        // Kept in the service that owns the table, so the rule lives with the rows.
        (new Invitations($this->database))->forgetUser($userId);

        // Mailing-list rows by account and by address, read before the account row goes. And
        // the profile picture: its usage is released, and the image deleted when nothing else
        // uses it.
        $account = $this->database->queryBuilder()->table('#PREFIX#users')
            ->select(['email', 'photo'])->where('userid', $userId)->first();
        if ($account && $account->numRows > 0 && (int) ($account->fields['photo'] ?? 0) > 0) {
            \Pramnos\User\ProfilePhoto::release((int) $account->fields['photo']);
        }
        (new \Pramnos\Email\MailingList($this->database))->forgetUser(
            $userId,
            $account && $account->numRows > 0 ? (string) $account->fields['email'] : ''
        );

        $this->database->queryBuilder()->table('#PREFIX#users')->where('userid', $userId)->delete();
        // A role or a membership just went: answers cached about this user are stale.
        $this->database->cacheflush('permissions');
    }

    /**
     * The tables keyed by the user's id, and the column that holds it.
     *
     * @return array<string, string>
     */
    protected function tables(): array
    {
        return [
            'usertokens'                       => 'userid',
            'authserver.oauth2_user_consents'  => 'userid',
            'authserver.user_activity_log'     => 'userid',
            'authserver.user_privacy_settings' => 'userid',
            'authserver.user_twofactor'        => 'userid',
            'authserver.twofactor_setup'       => 'userid',
            // Credentials: a passkey or a trusted device outliving its account is a way in
            // to an id nobody owns.
            'authserver.passkey_credentials'   => 'userid',
            'authserver.trusted_devices'       => 'userid',
            // What the person held, and where.
            Role::assignmentTable()            => 'userid',
            Role::membershipTable()            => 'userid',
            'userstogroups'                    => 'userid',
            // Where their notifications went, and what was sent there.
            'pramnos.pushsubscriptions'        => 'userid',
            'pramnos.pushlog'                  => 'userid',
            \Pramnos\Push\TestPush::TABLE      => 'userid',
        ];
    }

    /**
     * Delete the notifications addressed to this user, and only to a user.
     *
     * `notifications` is keyed by the notifiable's class and id, and an organisation or any
     * other notifiable may have the same id. So the classes stored for this id are read
     * first, and only those that are users are deleted.
     */
    protected function eraseNotifications(int $userId): void
    {
        if (!$this->database->schema()->hasTable('notifications')) {
            return;
        }

        $result = $this->database->queryBuilder()->table('notifications')
            ->select('notifiable_type')->distinct()->where('notifiable_id', $userId)->get();

        $userTypes = [];
        while ($result && $result->fetch()) {
            $type = (string) $result->fields['notifiable_type'];
            if (is_a($type, \Pramnos\User\User::class, true)) {
                $userTypes[] = $type;
            }
        }

        if ($userTypes === []) {
            return;
        }

        $this->database->queryBuilder()->table('notifications')
            ->where('notifiable_id', $userId)->whereIn('notifiable_type', $userTypes)->delete();
    }
}
