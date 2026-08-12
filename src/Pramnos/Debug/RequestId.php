<?php

declare(strict_types=1);

namespace Pramnos\Debug;

/**
 * A name for this request, so anything written during it can be found again.
 *
 * The toolbar's data travels with the response it describes, which is what makes
 * it work with nothing to correlate and nothing to clean up. It is also its
 * ceiling: a request that *died* has no response to carry anything, and an error
 * page cannot hold a `_debug` key. The count of what was raised gets through in
 * a header; the messages stay on the server.
 *
 * This is the thread back to them. Every log line written while the toolbar is
 * active carries the id, the response announces it, and
 * {@see \Pramnos\DevPanel\DevPanelController::logs()} will hand back the lines
 * for **one id** — never for a time window.
 *
 * That distinction is the reason this class exists rather than a timestamp
 * comparison. On a live server the toolbar is open for one browser, by grant,
 * while every other visitor is writing into the same seconds. "Everything logged
 * between the request and its response" would hand over their lines too, which
 * is a data leak wearing a debugging hat.
 *
 * **Inactive by default.** Nothing generates an id until {@see activate()} is
 * called, which only DebugBarServiceProvider does, and only when the toolbar
 * boots. A production installation's log format is therefore untouched.
 */
final class RequestId
{
    /**
     * This request's id, once something has asked for it.
     *
     * @var string|null
     */
    private static ?string $id = null;

    /**
     * Whether ids are being issued at all.
     *
     * @var bool
     */
    private static bool $active = false;

    /**
     * Start issuing an id for this request.
     *
     * Called by DebugBarServiceProvider when the toolbar boots. Idempotent: a
     * worker that boots the provider once per request gets one id per request
     * through {@see reset()}, not one for its lifetime.
     *
     * @return void
     */
    public static function activate(): void
    {
        self::$active = true;
    }

    /**
     * Are ids being issued?
     *
     * @return bool
     */
    public static function isActive(): bool
    {
        return self::$active;
    }

    /**
     * This request's id, or null when ids are not being issued.
     *
     * The null return is what keeps Logger's output identical in production:
     * there is no id to add, so no line changes shape.
     *
     * @return string|null
     */
    public static function activeId(): ?string
    {
        return self::$active ? self::current() : null;
    }

    /**
     * This request's id, generating one on first use.
     *
     * Sixteen hex characters: long enough not to collide across the requests one
     * page makes, short enough to read out of a log line and paste into a search.
     *
     * An incoming `X-Request-Id` is deliberately **not** honoured. It would be
     * the conventional thing to do — but the id decides which log lines are
     * handed back, and accepting a caller-supplied one means accepting a caller
     * who chooses to be indistinguishable from someone else's request. The
     * correlation this class serves is between a response and the server's own
     * logs, and for that the server's own id is the right one.
     *
     * @return string
     */
    public static function current(): string
    {
        if (self::$id === null) {
            self::$id = bin2hex(random_bytes(8));
        }

        return self::$id;
    }

    /**
     * Forget the current id and stop issuing.
     *
     * For tests, and for any process serving more than one request in a single
     * PHP lifetime — a second request must not inherit the first one's name.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$id     = null;
        self::$active = false;
    }
}
