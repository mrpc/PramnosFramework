<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

/**
 * `POST /ClientVitals` — where `pf-vitals.js` sends what a sampled page view measured.
 *
 * The same door as {@see ClientErrorsController}: a POST from one of this site's own pages, a
 * small body, a few a minute per address — and a 204 whatever happens, since a page that is being
 * left has nobody to tell. Reached at its own address in every application, with no controller of
 * the application's own: the framework looks here for a name the application does not have.
 * Signed in or not is decided here, from the session, not by what the page claimed.
 */
class ClientVitals extends ClientErrorsController
{
    /** Keep the measurements, and answer nothing. */
    public function display(): mixed
    {
        \Pramnos\Framework\Factory::getDocument('json');

        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            return $this->answer(405);
        }
        if (!$this->sameOrigin()) {
            return $this->answer(403);
        }
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_BODY) {
            return $this->answer(413);
        }
        if ($this->overLimit()) {
            return $this->answer(429);
        }

        try {
            $user = \Pramnos\User\User::current();
            $this->vitals()->record($this->report(), is_object($user) && (int) ($user->userid ?? 0) > 1);
        } catch (\Throwable $e) {
            // No table yet, or no database: a measurement is not worth an error.
            \Pramnos\Logs\Logger::log('Browser vitals could not be recorded: ' . $e->getMessage(), 'clientvitals');
        }

        return $this->answer(204);
    }

    /** Where they are kept. */
    protected function vitals(): \Pramnos\Logs\ClientVitals
    {
        return new \Pramnos\Logs\ClientVitals();
    }
}
