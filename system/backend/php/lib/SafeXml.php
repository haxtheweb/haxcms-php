<?php
/**
 * Loads XML that came out of an uploaded document (docx, pptx parts and
 * their relationship files) into a DOMDocument.
 */
class SafeXml
{
    /**
     * Security (SEC-DOS, SEC-UPLOAD): OOXML parts never carry a DTD, so a part
     * that declares a DOCTYPE or an ENTITY is refused before libxml sees it
     * (no entity expansion, no external subset), and nothing is fetched over
     * the network while parsing (LIBXML_NONET). Same rule as PptxToHtml and
     * OdtToHtml. Returns false when refused or unparseable; the document is
     * then left empty, which every caller already treats as "nothing here".
     */
    public static function loadInto(DOMDocument $doc, $xml)
    {
        if (!is_string($xml) || $xml === '' || self::declaresDoctypeOrEntity($xml)) {
            return false;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $doc->loadXML($xml, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return $loaded === true;
    }

    public static function declaresDoctypeOrEntity($xml)
    {
        return is_string($xml) && preg_match('/<!DOCTYPE|<!ENTITY/i', $xml) === 1;
    }
}
