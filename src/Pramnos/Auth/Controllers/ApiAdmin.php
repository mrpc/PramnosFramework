<?php

declare(strict_types=1);

namespace Pramnos\Auth\Controllers;

use Pramnos\Application\ApiCrudController;
use Pramnos\Http\Request;
use Pramnos\Http\Response;

/**
 * Read-only administration endpoints for a single-page application.
 *
 * The MVC scaffold generates whole admin areas — users, settings, logs — as
 * server-rendered pages. A SPA project got none of them, so "the SPA should have
 * the app's functions" meant hand-writing every one.
 *
 * These expose the same data as JSON. Read-only on purpose: creating and
 * deactivating users has consequences (sessions, tokens, GDPR records) that the
 * existing server-rendered flows already handle correctly, and duplicating them
 * behind a thinner API is how the two drift apart. Listing, searching and
 * inspecting is what an admin screen needs most, and it is safe to serve twice.
 *
 * Every action goes through ApiCrudController::guard(), so it is authenticated,
 * permission-checked per action, and answers 401 and 403 distinctly.
 */
class ApiAdmin extends ApiCrudController
{
    /** Resource name used when asking the permission store. */
    protected string $resource = 'admin';

    /**
     * GET /admin/users — the user list, paged and searchable.
     *
     * Uses the User model's own list pipeline, so paging, sorting and searching
     * behave exactly as they do everywhere else in the framework rather than
     * being re-implemented for one screen.
     */
    public function users(): mixed
    {
        if (($denied = $this->guard('users')) !== null) {
            return $denied;
        }

        $user = new \Pramnos\User\User();

        return Response::json($user->_getApiList(
            ['userid', 'username', 'email', 'usertype', 'active', 'regdate'],
            (string) Request::staticGet('search', '', 'get'),
            (string) Request::staticGet('sort', '', 'get'),
            '',
            '',
            '',
            null,
            null,
            (int) Request::staticGet('page', 1, 'get', 'int'),
            (int) Request::staticGet('limit', 20, 'get', 'int')
        ));
    }

    /**
     * GET /admin/search — one term across every registered entity.
     *
     * The aggregate that no single model can implement for itself. Per-entity search
     * already exists — every model is an `ApiListSource` and `ApiListQuery` runs the
     * term — so this registers nothing of its own: it loads `app/search.php` and loops
     * the sources it declares.
     *
     * Guarded as its own action rather than reusing `users`: an omnibox reaches whatever
     * the application registered, which is a wider grant than "may list users" and must
     * be grantable separately.
     */
    public function search(): mixed
    {
        if (($denied = $this->guard('search')) !== null) {
            return $denied;
        }

        \Pramnos\Search\Registry::loadDefinitions();

        return Response::json(\Pramnos\Search\Registry::query(
            (string) Request::staticGet('q', '', 'get'),
            // Capped rather than taken from the request: an omnibox asking for 500 rows
            // from six tables is a denial-of-service endpoint with a friendly name.
            min(20, max(1, (int) Request::staticGet('limit', 5, 'get', 'int')))
        ));
    }

    /**
     * GET /admin/logs — one page of a log file.
     *
     * The file is chosen from the viewer's whitelist, never from the raw
     * parameter: a log endpoint that accepts a path is a file-disclosure
     * endpoint. An unknown name falls back to the default file rather than
     * reading whatever was asked for.
     */
    public function logs(): mixed
    {
        if (($denied = $this->guard('logs')) !== null) {
            return $denied;
        }

        $viewer = new \Pramnos\Logs\LogViewer();
        // `pramnosframework.log`, with the extension: log names carry it — `LogController`'s
        // whitelist is `['pramnosframework.log', 'php_error.log']` and `getLogFilePath()` maps a
        // name to the directory unchanged. The default was the name without it, so it resolved to
        // a file that does not exist and this endpoint threw on its own default parameter.
        $file   = (string) Request::staticGet('file', 'pramnosframework.log', 'get');

        try {
            // setFile() validates against the whitelist; the exception is the
            // answer to "may I read this?", not an error to propagate.
            $viewer->setFile($file, true);
        } catch (\Throwable) {
            return ['status' => 404, 'error' => 'unknown_log'];
        }

        $viewer->setParameters(
            true,
            (int) Request::staticGet('page', 1, 'get', 'int'),
            (int) Request::staticGet('limit', 50, 'get', 'int'),
            (string) Request::staticGet('search', '', 'get')
        );

        try {
            return Response::json($viewer->getLogContent());
        } catch (\Throwable) {
            /*
             * A log on the whitelist that has never been written to.
             *
             * `setFile()` above answers "may I read this?" and the 404 is right for a name that
             * is not on the list. This is the other question — *is there anything in it* — and
             * `processFile()` throws for a file that does not exist on disk, which is the normal
             * state of a log nothing has logged to yet. Uncaught, that made the panel answer
             * **500** on a fresh installation, for the endpoint somebody opens precisely because
             * they are trying to find out what went wrong.
             *
             * An empty page, in the shape the reader already handles, so "nothing has been
             * logged" renders as an empty list rather than as a broken screen.
             */
            return Response::json(['lines' => [], 'total' => 0, 'matched_total' => 0]);
        }
    }

    /**
     * GET /admin/summary — the numbers a dashboard opens with.
     *
     * Deliberately cheap: counts only, so the screen that loads first does not
     * become the slowest request in the application.
     */
    public function summary(): mixed
    {
        if (($denied = $this->guard('summary')) !== null) {
            return $denied;
        }

        return Response::json([
            'users'    => $this->countRows('#PREFIX#users'),
            'sessions' => $this->countRows('#PREFIX#sessions'),
            'php'      => PHP_VERSION,
            'time'     => gmdate('c'),
        ]);
    }

    /**
     * Count rows in a table, or null when it does not exist.
     *
     * A dashboard must not fail because an optional feature's table was never
     * created — a missing number is information, an exception is not.
     */
    protected function countRows(string $table): ?int
    {
        try {
            $database = \Pramnos\Database\Database::getInstance();

            return (int) $database->queryBuilder()->table($table)->count();
        } catch (\Throwable) {
            return null;
        }
    }
}
