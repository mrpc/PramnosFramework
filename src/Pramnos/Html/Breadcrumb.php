<?php
namespace Pramnos\Html;
/**
 * HTLM Breadcrumb class
 * @package     PramnosFramework
 * @subpackage  Html
 * @copyright   2005 - 2017 Yannis - Pastis Glaros, Pramnos Hosting
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */
class Breadcrumb extends \Pramnos\Framework\Base
{
    /**
     * Array of breadcrumbs to add
     * @var array
     */
    public $items = array();
    /**
     *
     * @var type
     */
    public $extraStyle = '';


    /**
     * Add a breadcrumb item
     * @param string $label  Text of the breadcrumb
     * @param string $url    URL of breadcrumb
     * @param string $title  Meta Title
     */
    public function addItem($label, $url = '', $title = '')
    {
        $this->items[$label] = array(
            'label' => $label,
            'url' => $url,
            'title' => $title
        );
    }

    /**
     * Render and return the actual breadcrumb
     * @return string
     */
    public function render()
    {
        $text = '<nav aria-label="breadcrumb" role="navigation">'
            . '<ol class="breadcrumb">'
            . "\n";
        $script = '<script type="application/ld+json">{'
            . '"@context": "https://schema.org", '
            . '"@type": "BreadcrumbList", '
            . '"itemListElement": [';
        $header = count($this->items) + 1;
        $count = 0;
        $comma = '';
        foreach ($this->items as $item) {
            $count += 1;
            $label = isset($item['label']) ? (string)$item['label'] : '';
            $script .= $comma . '{ "@type": "ListItem", '
                . '"position": '
                . $count
                . ', "name": "'
                . addslashes(strip_tags($label))
                . '", '
                . '"item": "'
                . $item['url']
                . '" }';
            $comma = ', ';
            if ($item['title'] == '') {
                $item['title'] = $label;
            }
            $text .= '<li class="breadcrumb-item';
            if ($header == 2) {
                $text .= ' active';
            }
            $text .= '"';
            if ($header == 2) {
                $text .= ' aria-current="page"';
            }
            $text .= '><h'
                . $header
                . ' >';
            if ($item['url'] != '') {
                $text .= '<a title="'
                    . $item['title']
                    . '" href="'
                    . $item['url']
                    . '">';
            } else {
                $text .= '<span title="'
                    . $item['title']
                    . '">';
            }
            $text .= '<span>'
                . $label
                . '</span>';
            if ($item['url'] != '') {
                $text .= '</a>';
            } else {
                $text .= '</span>';
            }
            $text .= '<meta content="'
                . $count
                . '" />'
                . ''
                . "\n";
            $text .= '</h' . $header . "></li>\n";
            $header -= 1;

        }
        $script .= ']} </script>';
        $text .= '</ol></nav>';
        return $text . $script;
    }

}
