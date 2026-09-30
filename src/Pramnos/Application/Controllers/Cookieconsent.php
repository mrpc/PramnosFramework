<?php

namespace Pramnos\Application\Controllers;

/**
 * `/cookieconsent` — the endpoints behind `assets/js/pf-consent.js`.
 *
 * - `GET /cookieconsent` returns the banner's configuration as JSON. A server-
 *   rendered page does not need it (the configuration rides on the script tag);
 *   a SPA shell does, because it renders without booting the application and so
 *   cannot read the settings itself.
 * - `POST /cookieconsent/record` appends a signed-in visitor's choice to the
 *   consent trail. The script calls it only when the page carries
 *   `<meta name="csrf">`, which is to say only when somebody is signed in.
 *
 * An application class of the same name replaces this one.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class Cookieconsent extends \Pramnos\Application\Controller
{
    public $actions = ['display', 'record'];

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        parent::__construct($application);
        $this->addWriteAction(['record']);
    }

    /**
     * The configuration, translated for the current language.
     */
    public function display(): mixed
    {
        // Every visitor gets the same answer, so a browser and a shared cache may keep it
        // for a few minutes. Settings changes reach a SPA within that window.
        if (!headers_sent()) {
            header('Cache-Control: public, max-age=300');
        }

        return $this->json(\Pramnos\Security\CookieConsent::enabled()
            ? \Pramnos\Security\CookieConsent::config(defined('sURL') ? sURL : '')
            : ['enabled' => false]);
    }

    /**
     * Append the visitor's choice to `authserver.user_consents`.
     *
     * Body: `{"granted": ["analytics", ...]}`. The cookie is already written by
     * the time this is called — it is what decides — so a failure here is
     * logged and reported, never a reason to ask the visitor again.
     */
    public function record(): mixed
    {
        $userId = $this->currentUserId();

        if ($userId <= 1) {
            return $this->json(['error' => 'Sign in first.'], 401);
        }

        $body    = json_decode($this->rawBody(), true);
        $granted = is_array($body) && is_array($body['granted'] ?? null)
            ? array_values(array_filter($body['granted'], 'is_string'))
            : [];

        try {
            $rows = \Pramnos\Security\CookieConsent::record(
                \Pramnos\Framework\Factory::getDatabase(),
                $userId,
                $granted,
                (string) \Pramnos\Http\Request::clientIp()
            );
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log(
                'Could not record a cookie consent choice: ' . $exception->getMessage(),
                'security'
            );

            return $this->json(['error' => 'The choice is saved in this browser but could not be recorded.'], 500);
        }

        return $this->json(['recorded' => $rows]);
    }

    /** The signed-in user's id; 0 or 1 (the guest) for nobody. */
    protected function currentUserId(): int
    {
        return (int) (\Pramnos\User\User::getCurrentUser()->userid ?? 0);
    }

    /** The request body as it arrived. Separate only because `php://input` cannot be arranged. */
    protected function rawBody(): string
    {
        return (string) file_get_contents('php://input');
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function json(array $data, int $status = 200): mixed
    {
        \Pramnos\Framework\Factory::getDocument('json');

        return \Pramnos\Http\Response::json($data, $status);
    }
}
