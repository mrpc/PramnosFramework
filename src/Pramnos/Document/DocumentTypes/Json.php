<?php
namespace Pramnos\Document\DocumentTypes;


/**
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class Json extends \Pramnos\Document\Document
{

    function render()
    {
        $lang = \Pramnos\Framework\Factory::getLanguage();
        if (!headers_sent()) {
            // No status line here. `header('HTTP/1.1 200 OK')` used to be the first
            // call, and it did two harmful things: it stamped 200 over a status the
            // controller had already set — so a JSON *error* response was served as
            // 200 and the client could not tell failure from success — and it pinned
            // the status, because PHP ignores every later http_response_code() once
            // a status line has been sent by hand. 200 is the default anyway, so the
            // line was a no-op in the only case where it was correct.
            header(
                'Content-type: application/json; charset='
                . $lang->_('CHARSET')
            );
        }
        return $this->bodyContent();
    }

}
