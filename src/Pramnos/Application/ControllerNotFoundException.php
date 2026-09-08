<?php

namespace Pramnos\Application;

/**
 * Thrown by {@see Application::getController()} when no class answers to that name.
 *
 * ## Why a type, and not a message
 *
 * `getController()` does more than look up a class name — it **instantiates** one. So a
 * `catch (\Exception)` around it also covers a constructor that throws: a missing
 * dependency, a failed database connection, a bad configuration read at construction time.
 * `Api::exec()` turned all of those into `{"status":404,"error":"EndpointNotFound"}`.
 *
 * That is worse than the fatal it replaced, and worse in the way that matters: a 500 gets
 * looked at, and a 404 on a path the client mistyped gets ignored. An endpoint that had
 * broken *itself* reported as an endpoint that does not exist, indefinitely, and that path
 * did not log.
 *
 * A consumer that wanted to separate the two had only the message text to go on, and one
 * did exactly that — `strpos($e->getMessage(), 'Cannot find controller:') !== 0` — which is
 * the right shape and the wrong mechanism. Matching a message string is brittle, and it was
 * brittle *because* there was no type.
 *
 * ```php
 * catch (\Pramnos\Application\ControllerNotFoundException $exception) {   // 404
 * ```
 *
 * and everything else propagates to the handler below it, which already distinguishes a
 * `ValidationException`, a 403 by code and a SQL error, and logs the last one.
 *
 * ## And the message no longer carries the request
 *
 * It used to append the request URI and the **username of whoever was logged in**, to help
 * whoever read the log. It did help — but where an exception message goes next is the
 * caller's choice, and every unhandled path puts it somewhere it should not be: an
 * application that lets it escape renders a PHP error page carrying the username, a debug
 * toolbar or an exception reporter forwards it by design, `display_errors` on a staging
 * host turns it into a page. That is how this was found: a mistyped API version answered
 * 500 with a trace, and the trace named the signed-in user.
 *
 * A mistyped path is also the shape of a path being probed, so the audience for that
 * message is not always a colleague. The context is not dropped — it is logged, where the
 * request cannot read it back — and {@see getContext()} keeps it available to a handler
 * that has somewhere safe to put it.
 */
class ControllerNotFoundException extends \Exception
{
    /** The controller name that could not be resolved. */
    protected string $controller;

    /**
     * The request context: `url` and `user`, when there was either.
     *
     * @var array<string,string>
     */
    protected array $context;

    /**
     * @param string               $controller The name nothing answered to
     * @param array<string,string> $context    `url` and `user`, for a handler with a log
     */
    public function __construct(string $controller, array $context = [])
    {
        $this->controller = $controller;
        $this->context    = $context;

        parent::__construct('Cannot find controller: ' . $controller);
    }

    public function getController(): string
    {
        return $this->controller;
    }

    /**
     * @return array<string,string>
     */
    public function getContext(): array
    {
        return $this->context;
    }
}
