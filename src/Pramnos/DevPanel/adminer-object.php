<?php

/**
 * The hook Adminer looks for, in the global namespace where it looks.
 *
 * `bootstrap.inc.php` asks `function_exists('adminer_object')` — an unqualified string, which
 * PHP resolves in the global namespace only — and uses the return value as its `Adminer`
 * object. So this cannot be a namespaced function, a closure or a method, which is why it is
 * one line in a file of its own rather than part of {@see \Pramnos\DevPanel\AdminerBridge}.
 *
 * Included by `Pramnos\Application\Controllers\Adminer` immediately before Adminer itself, and
 * only when auto-login is on: with the function absent Adminer builds its own object and shows
 * its login form, which is the behaviour an installation gets by turning auto-login off.
 */

declare(strict_types=1);

if (!function_exists('adminer_object')) {
    function adminer_object()
    {
        return \Pramnos\DevPanel\AdminerBridge::plugin();
    }
}
