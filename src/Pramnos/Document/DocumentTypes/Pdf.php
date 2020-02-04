<?php
namespace Pramnos\Document\DocumentTypes;
/**
 * @package     PramnosFramework
 * @subpackage  Document
 * @copyright   2005 - 2015 Yannis - Pastis Glaros, Pramnos Hosting
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */
class Pdf extends \Pramnos\Document\Document
{

    /**
     * Render the PDF document
     */
    public function render()
    {
        ini_set('display_errors', 'Off');
        $htmlbuffer = self::_getContent();
        if ($this->printpaper != "") {
            $printpaper = $this->printpaper;
        } else {
            $printpaper = "A4";
        }
        $pdf = new TCPDF(
            PDF_PAGE_ORIENTATION, PDF_UNIT, $printpaper, true, 'UTF-8', false
        );
        // set document information
        $pdf->SetCreator("PramnosFramework");
        $pdf->SetAuthor('Pramnos Hosting LTD');
        if ($this->title == '') {
            $pdf->SetTitle('Report');
            $pdf->SetSubject('Report');
            $pdf->SetKeywords('Report');
        } else {
            $pdf->SetTitle($this->title);
            $pdf->SetSubject($this->title);
            $pdf->SetKeywords($this->title);
        }

        $lang = \Pramnos\Framework\Factory::getLanguage();

        $l = array();
        $l['a_meta_charset'] = 'UTF-8';
        $l['a_meta_dir'] = 'ltr';
        if ($lang->_('LangShort') == "LangShort") {
            $l['a_meta_language'] = 'en';
        } else {
            $l['a_meta_language'] = $lang->_('LangShort');
        }

        // TRANSLATIONS --------------------------------------
        $l['w_page'] = $lang->_('Page');
        $pdf->setLanguageArray($l);
        // set default header data
        # $pdf->SetHeaderData(PDF_HEADER_LOGO, PDF_HEADER_LOGO_WIDTH,
        # "PramnosEMR PDF Export", PDF_HEADER_STRING);
        // set header and footer fonts
        $pdf->setHeaderFont(Array('dejavusans', '', PDF_FONT_SIZE_MAIN));
        $pdf->setFooterFont(Array('dejavusans', '', PDF_FONT_SIZE_DATA));
        // set default monospaced font
        $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
        //set margins
        $pdf->SetMargins(PDF_MARGIN_LEFT, 4, PDF_MARGIN_RIGHT);
        $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
        $pdf->SetHeaderMargin(0);
        $pdf->setPrintHeader(false);
        //set auto page breaks
        $pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);
        //set image scale factor
        $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
        //set some language-dependent strings
        $pdf->setLanguageArray($l);
        // ---------------------------------------------------------
        // set font
        $pdf->SetFont('dejavusans', '', 10);
        // add a page
        $pdf->AddPage();
        #$img_pattern  = "/<img[^>]+src=\"[^\"]+\"[^>]*>/i";
        #$htmlbuffer = preg_replace($img_pattern, '', $htmlbuffer);
        // We now remove the object blocks (video, flash)
        $object_pattern = "/<object[0-9 a-z_?*=\":\-\/\.#\,<>\\n\\r\\t]+<\/object>/smi";
        $htmlbuffer = preg_replace($object_pattern, '', $htmlbuffer);
        $object_pattern = "/<script[^>]*>(.*)<\/script>/smi";
        $htmlbuffer = preg_replace($object_pattern, '', $htmlbuffer);
        /* Eugef: also remove links from teaser */
        #$link_pattern = "/<a[^>]*>(.*)<\/a>/smi";
        #$htmlbuffer = preg_replace($link_pattern, '', $htmlbuffer);
        // output the HTML content
        $pdf->writeHTML($htmlbuffer, true, 0, true, 0);
        $pdf->Output('export.pdf', 'I');
    }

}
