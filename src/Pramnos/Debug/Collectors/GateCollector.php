<?php

declare(strict_types=1);

namespace Pramnos\Debug\Collectors;

use Pramnos\Auth\Gate;

/**
 * Which authorization rule decided, for every check this request made.
 *
 * The {@see AuthCollector} answers *who* the request is and *what convinced the server* —
 * identity, credential, expiry. It does not answer the next question, and until this panel
 * existed **nothing could**: was the action allowed, and which rule said so.
 *
 * That is not an oversight in the toolbar, it is a property of the feature. A gate's rule is a
 * closure in a bootstrap file, so it appears in no stack trace. A `Gate::before` hook that
 * returns `true` skips everything after it and leaves no mark. The SQL panel is no help either,
 * because a decision may touch no database at all. And a 403 tells you that something refused,
 * not which of six steps did:
 *
 * | Step | Means |
 * | --- | --- |
 * | `before` | A global hook decided immediately — "an administrator may do anything" |
 * | `ability` | A named `Gate::define()` rule answered |
 * | `policy` | A policy method answered; `detail` names it |
 * | `store` | The permission store answered, via `fallbackToPermissions()` |
 * | `default` | **Nobody claimed this ability**, so it was refused |
 * | `after` | A rule answered and an `after` hook overrode it |
 *
 * `default` is the row that earns the panel. `fallbackToPermissions()` is off by default, so an
 * ability nobody defined is silently refused — which makes a typo in an ability name
 * *indistinguishable* from a deliberate deny, since both produce `false`. Seeing
 * `default — no rule claimed this` turns an hour of reading bootstrap files into two seconds.
 *
 * ### What this deliberately does not carry
 *
 * **The arguments.** A policy check receives whole models, and this payload is attached to the
 * response and sits in a browser's network log — so the subject is reduced to its class name and
 * the user to an id. Nothing that came out of a database travels. That is the same rule
 * {@see AuthCollector} applies to the credential it exists to explain.
 *
 * It also shows only what *this request* did, not what the user is permitted in general. The
 * latter is a question for the permission store and a different tool; a request-scoped panel that
 * quietly became a permissions browser would be answering neither question well.
 *
 * @see Gate::enableDecisionLog() which the debug provider calls; off otherwise, so an
 *      application that never opens the toolbar pays one boolean check per decision
 */
class GateCollector implements CollectorInterface
{
    /**
     * The panel's key in the debug payload.
     *
     * @return string
     */
    public function name(): string
    {
        return 'gate';
    }

    /**
     * Every distinct decision, with a count for the ones that repeated.
     *
     * The summary counts exist so the tab can be read without opening it: `refused` is the
     * number worth a badge, and `undefined` is the number that usually means a typo.
     *
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $decisions = Gate::decisionLog();

        $allowed   = 0;
        $refused   = 0;
        $undefined = 0;

        foreach ($decisions as $decision) {
            if ($decision['allowed']) {
                $allowed += $decision['times'];
            } else {
                $refused += $decision['times'];
            }

            if ($decision['step'] === 'default') {
                $undefined += $decision['times'];
            }
        }

        return [
            'checks'    => $allowed + $refused,
            'allowed'   => $allowed,
            'refused'   => $refused,
            'undefined' => $undefined,
            'decisions' => $decisions,
        ];
    }
}
