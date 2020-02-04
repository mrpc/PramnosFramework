<?php
namespace Pramnos\Document\DocumentTypes;


/**
 * @package     PramnosFramework
 * @subpackage  Document
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
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
