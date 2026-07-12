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
            header('HTTP/1.1 200 OK');
            header(
                'Content-type: application/json; charset='
                . $lang->_('CHARSET')
            );
        }
        return self::_getContent();
    }

}
