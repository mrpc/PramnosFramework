<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst\Tools;

/**
 * The accounts: how many, of which type, how many signed up and signed in over a window.
 * Counts only; one person is the `user` tool's.
 */
final class UsersTool extends AdminTool
{
    public function name(): string
    {
        return 'users';
    }

    public function description(): string
    {
        return 'Accounts in this installation: totals, by user type, active and validated, sign-ups '
            . 'and sign-ins per period, and accounts never signed in. Counts only — no names.';
    }

    protected function answer(array $input, Window $window): array
    {
        if (!$this->has('#PREFIX#users')) {
            return self::absent('user accounts');
        }

        // userid 1 is the Guest row every installation has; it is nobody.
        $people = fn () => $this->table('#PREFIX#users')->where('userid', '>', 1);

        return [
            'window'           => $window->describe(),
            'total'            => $people()->count(),
            'active'           => $people()->where('active', 1)->count(),
            'validated'        => $people()->where('validated', 1)->count(),
            'by_usertype'      => $this->countBy($people(), 'usertype'),
            'signed_up'        => $window->apply($people(), 'regdate', true)->count(),
            'signed_in'        => $window->apply($people(), 'lastlogin', true)->count(),
            'never_signed_in'  => $people()->where(fn ($q) => $q->whereNull('lastlogin')->orWhere('lastlogin', 0))->count(),
            'sign_ups'         => $this->series($people(), 'regdate', $window, true),
            'last_sign_ins'    => $this->series($people(), 'lastlogin', $window, true),
        ];
    }
}
