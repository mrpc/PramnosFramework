<?php
namespace Pramnos\Document\DocumentTypes;
/**
 * Raw document
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class Raw extends \Pramnos\Document\Document
{

    function render()
    {
        $content = $this->bodyContent();

        /**
         * Post-process the rendered output to inject CSP nonces into inline
         * <script>/<style> tags, mirroring the Html document type. Raw output
         * is used for self-contained documents such as the log-viewer iframe
         * (Logs/raw); without this, their inline scripts are blocked by a
         * `script-src 'self' 'nonce-…'` policy.
         */
        // `currentInstance()`: rendering happens inside a request that has an application,
        // and the `if` below was already written for a null this call could not return.
        $app = \Pramnos\Application\Application::currentInstance();
        if ($app && property_exists($app, 'cspNonce') && $app->cspNonce !== '') {
            // One implementation, on Document. This was an identical copy of the
            // pattern in the other document type — two copies of a security-relevant
            // regex that had to agree with each other.
            $content = $this->injectCspNonces(
                $content,
                htmlspecialchars($app->cspNonce, ENT_QUOTES)
            );
        }

        return $content;
    }

}
