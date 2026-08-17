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
            $nonce = htmlspecialchars($app->cspNonce, ENT_QUOTES);

            // Inline <script> tags (no src= attribute)
            $content = preg_replace_callback(
                '/<script(?![^>]*\bsrc\s*=)([^>]*)>/i',
                static function (array $matches) use ($nonce): string {
                    return '<script nonce="' . $nonce . '"' . $matches[1] . '>';
                },
                $content
            );

            // Inline <style> blocks
            $content = preg_replace_callback(
                '/<style([^>]*)>/i',
                static function (array $matches) use ($nonce): string {
                    return '<style nonce="' . $nonce . '"' . $matches[1] . '>';
                },
                $content
            );
        }

        return $content;
    }

}
