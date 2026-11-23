<?php
use PHPUnit\Framework\TestCase;

/**
 * SEC-DOS / SEC-UPLOAD: XML parts of uploaded documents are parsed without
 * DTDs, entities or network access.
 */
class SafeXmlTest extends TestCase
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/systemRoutes/v1/imports/importUtils.php';
        require_once dirname(__DIR__, 2) . '/lib/pptxDeckHelper.php';
    }

    private function documentXml($bodyText, $prolog = '')
    {
        return '<?xml version="1.0"?>' . $prolog .
            '<w:document xmlns:w="' . self::W . '"><w:body><w:p><w:r><w:t>' . $bodyText . '</w:t></w:r></w:p></w:body></w:document>';
    }

    public function testPlainDocumentPartsStillParse(): void
    {
        $doc = new DOMDocument();
        $this->assertTrue(SafeXml::loadInto($doc, $this->documentXml('Hello')));
        $this->assertStringContainsString('Hello', haxcmsImportConvertDocxXmlToHtml($this->documentXml('Hello')));
    }

    public function testDocumentPartWithDoctypeAndEntityIsRefused(): void
    {
        $xml = $this->documentXml('&lol9;', '<!DOCTYPE w:document [<!ENTITY lol "lol"><!ENTITY lol9 "&lol;&lol;&lol;">]>');
        $doc = new DOMDocument();
        $this->assertFalse(SafeXml::loadInto($doc, $xml));
        $this->assertNull($doc->documentElement);
        $this->assertStringNotContainsString('lol', haxcmsImportConvertDocxXmlToHtml($xml));
    }

    public function testExternalEntityDeclarationIsRefused(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><r>&x;</r>';
        $this->assertFalse(SafeXml::loadInto(new DOMDocument(), $xml));
        $this->assertFalse(SafeXml::loadInto(new DOMDocument(), str_replace('DOCTYPE', 'doctype', $xml)), 'case-insensitive');
        $this->assertFalse(SafeXml::loadInto(new DOMDocument(), ''));
        $this->assertFalse(SafeXml::loadInto(new DOMDocument(), null));
    }

    public function testNoUploadedXmlIsParsedOutsideTheHardenedLoaders(): void
    {
        // PptxToHtml and OdtToHtml do their own DOCTYPE/ENTITY rejection with
        // LIBXML_NONET; ExportConverters parses the site's own sanitized HTML
        $allowed = array('SafeXml.php', 'PptxToHtml.php', 'OdtToHtml.php', 'ExportConverters.php');
        $libDir = dirname(__DIR__, 2) . '/lib';
        $found = array();
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($libDir, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (substr($file->getFilename(), -4) !== '.php' || in_array($file->getFilename(), $allowed, true)) {
                continue;
            }
            if (preg_match('/->loadXML\s*\(|simplexml_load_(string|file)\s*\(/', file_get_contents($file->getPathname()))) {
                $found[] = str_replace($libDir . '/', '', $file->getPathname());
            }
        }
        $this->assertSame(array(), $found, 'parse document XML with SafeXml::loadInto');
    }
}
