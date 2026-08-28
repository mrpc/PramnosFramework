<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

/**
 * The tracking pixel.
 *
 * Bundled, so an application that switches tracking on does not also have to write a route. The
 * previous version of this feature asked for exactly that — in a doc-block — which is the reason
 * it never worked anywhere: the pixel pointed at a 404 and no open was ever recorded.
 *
 * Always answers with the image, whatever happened behind it. A broken image in the middle of a
 * message is a worse outcome than a lost measurement.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class Emailpixel extends \Pramnos\Application\Controller
{
    public $actions = ['display'];

    public function display(array $args = []): void
    {
        \Pramnos\Framework\Factory::getDocument('raw');

        $request = new \Pramnos\Http\Request();

        \Pramnos\Email\Email::handleTrackingRequest((string) $request->get('t', '', 'get'));
    }
}
