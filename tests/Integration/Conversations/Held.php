<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Conversations;

/**
 * What a test holds of a controller: who asks, the view it filled, where it sent the request.
 */
trait Held
{
    /** Who asks, set by the test. */
    public ?object $asking = null;

    /** The view the action filled. */
    public ?object $view = null;

    /** Where the request was sent. */
    public ?string $sentTo = null;

    /** The test's account, not a session. */
    protected function me(): ?object
    {
        return $this->asking;
    }

    /** A view that keeps what it is given and renders nothing. */
    public function &getView($name = '', $type = '', $args = array())
    {
        $this->view = new #[\AllowDynamicProperties] class {
            /** Which view was asked for. */
            public string $name = '';

            /** Nothing to render in a test. */
            public function display(string $template = ''): string
            {
                return 'rendered';
            }
        };
        $this->view->name = (string) $name;

        return $this->view;
    }

    /** Kept, not sent. */
    public function redirect($url = null, $quit = true, $code = '302'): void
    {
        $this->sentTo = (string) $url;
    }
}
