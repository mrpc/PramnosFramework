<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Audience;

use Pramnos\Application\Application;
use Pramnos\User\User;

/**
 * What a test of an administration screen needs: somebody signed in, a request, a controller whose
 * redirects and views are recorded rather than acted on, and each theme's view rendered with the
 * variables the controller gave it.
 */
trait AdminScreen
{
    /** Sign out and clear the request. */
    private function clearRequest(): void
    {
        $_SESSION = [];
        $_POST    = [];
        $_GET     = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        \Pramnos\Http\Request::resetInstance();
        if (Application::getInstance()) {
            Application::getInstance()->currentUser = null;
        }
    }

    /** An administrator — or, with a low type, somebody below the floor — signed in as account 2. */
    private function signIn(int $usertype = 98, int $id = 2): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = ['logged' => true, 'login' => true, 'userid' => $id, 'uid' => $id, 'usertype' => $usertype];
        $user = new User(0);
        $user->userid   = $id;
        $user->usertype = $usertype;
        $user->username = 'admin';
        Application::getInstance()->currentUser = $user;
    }

    /**
     * A request: the method, the option the router passes (`/Segments/view/5` → 5), the query and
     * the form.
     *
     * @param array<string, string> $get
     * @param array<string, string> $post
     */
    private function request(string $method, ?int $option = null, array $get = [], array $post = []): void
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_GET  = $get + ($option !== null ? ['_option' => (string) $option] : []);
        $_POST = $post;
        \Pramnos\Http\Request::resetInstance();
    }

    /**
     * The controller as a subclass that records redirects, the view it filled and the flash
     * messages, instead of leaving the request.
     *
     * @param class-string $class
     */
    private function screen(string $class): object
    {
        $code = 'return new class (\Pramnos\Application\Application::getInstance()) extends \\' . ltrim($class, '\\') . ' {
            public array $redirects = [];
            public ?object $view = null;
            public array $flash = [];
            public function redirect($url = null, $quit = true, $code = "302") { $this->redirects[] = (string) $url; }
            public function addMessage($message) { $this->flash[] = ["message", (string) $message]; return $this; }
            public function addError($error) { $this->flash[] = ["error", (string) $error]; return $this; }
            public function &getView($name = "", $type = "", $args = []) {
                $this->view = new class extends \stdClass {
                    public string $template = "";
                    public function display($template = "") { $this->template = (string) $template; return "view:" . $template; }
                };
                $view = $this->view;
                return $view;
            }
        };';

        return eval($code);
    }

    /**
     * A theme's view rendered with the variables a controller set, as the view engine would.
     *
     * @param array<string, mixed> $vars
     */
    private function render(string $theme, string $file, array $vars): string
    {
        $view = new class extends \stdClass {
            /** The partials a view includes: nothing here. */
            public function insert(string $name): void
            {
            }
        };
        foreach ($vars as $key => $value) {
            $view->{$key} = $value;
        }
        $path = ROOT . '/scaffolding/themes/' . $theme . '/views/' . $file;

        return (function (string $path): string {
            ob_start();
            try {
                include $path;
            } finally {
                $html = (string) ob_get_clean();
            }

            return $html;
        })->call($view, $path);
    }
}
