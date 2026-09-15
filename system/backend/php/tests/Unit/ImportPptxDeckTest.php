<?php
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for haxcmsSystemBuildPptxDeckManifest() — the PHP port of the
 * Node PPTXInHTMLOut.toDeckManifest() + getExtractedFiles(). Mirrors the
 * Node test/unit/pptx-in-html-out.test.cjs cases: speaker-notes extraction,
 * empty-notes fallback, numeric slide ordering past 9, and the pruned
 * per-slide contract (no image field).
 */
class ImportPptxDeckTest extends TestCase
{
    private $tmpFiles = array();
    private static $manifestLoaded = false;

    protected function setUp(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive extension not available');
        }
        // pptxDeckHelper.php defines the top-level manifest builder functions;
        // require_once ensures the functions load exactly once.
        if (!self::$manifestLoaded) {
            $path = dirname(__DIR__, 2) . '/lib/pptxDeckHelper.php';
            $this->assertFileExists($path);
            require_once $path;
            self::$manifestLoaded = true;
        }
        $this->tmpFiles = array();
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            if (is_string($f) && file_exists($f)) {
                @unlink($f);
            }
        }
        $this->tmpFiles = array();
    }

    // ------------------------------------------------------------------
    // Fixture builders
    // ------------------------------------------------------------------

    private function makeZip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'deck_') . '.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $this->tmpFiles[] = $path;
        return $path;
    }

    private function slideXml(string $titleText, string $bodyText): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            . ' xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
            . '<p:cSld><p:spTree>'
            . '<p:sp>'
            . '<p:nvSpPr><p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr>'
            . '<p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:r><a:t>' . htmlspecialchars($titleText, ENT_XML1) . '</a:t></a:r></a:p></p:txBody>'
            . '</p:sp>'
            . '<p:sp>'
            . '<p:nvSpPr><p:nvPr></p:nvPr></p:nvSpPr>'
            . '<p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:r><a:t>' . htmlspecialchars($bodyText, ENT_XML1) . '</a:t></a:r></a:p></p:txBody>'
            . '</p:sp>'
            . '</p:spTree></p:cSld>'
            . '</p:sld>';
    }

    private function slideRelsXml(?string $notesTarget): string
    {
        $notesRelationship = $notesTarget !== null
            ? '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/notesSlide" Target="' . $notesTarget . '"/>'
            : '';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>'
            . $notesRelationship
            . '</Relationships>';
    }

    private function notesSlideXml(string $notesText): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<p:notes xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            . ' xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
            . '<p:cSld><p:spTree>'
            . '<p:sp>'
            . '<p:nvSpPr><p:nvPr><p:ph type="sldImg"/></p:nvPr></p:nvSpPr>'
            . '</p:sp>'
            . '<p:sp>'
            . '<p:nvSpPr><p:nvPr><p:ph type="body"/></p:nvPr></p:nvSpPr>'
            . '<p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:r><a:t>' . htmlspecialchars($notesText, ENT_XML1) . '</a:t></a:r></a:p></p:txBody>'
            . '</p:sp>'
            . '</p:spTree></p:cSld>'
            . '</p:notes>';
    }

    /**
     * Build a minimal PPTX with $slideCount slides. Slide 1 gets real title/body
     * text and (if $withNotes) a linked notes slide; the rest are bare for the
     * ordering test.
     */
    private function buildPptx(int $slideCount = 1, bool $withNotes = false): string
    {
        $entries = array();
        for ($i = 1; $i <= $slideCount; $i++) {
            $isFirst = $i === 1;
            $titleText = $isFirst ? 'Sample Title' : 'Slide ' . $i;
            $bodyText = $isFirst ? 'Sample body text' : '';
            $entries["ppt/slides/slide{$i}.xml"] = $this->slideXml($titleText, $bodyText);
            $notesTarget = ($isFirst && $withNotes) ? '../notesSlides/notesSlide1.xml' : null;
            $entries["ppt/slides/_rels/slide{$i}.xml.rels"] = $this->slideRelsXml($notesTarget);
        }
        if ($withNotes) {
            $entries['ppt/notesSlides/notesSlide1.xml'] = $this->notesSlideXml('These are the speaker notes.');
        }
        return $this->makeZip($entries);
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    public function testDeckManifestExtractsSpeakerNotes(): void
    {
        $tmpPath = $this->buildPptx(1, true);
        $result = haxcmsSystemBuildPptxDeckManifest($tmpPath);
        $this->assertCount(1, $result['slides']);
        $this->assertSame('These are the speaker notes.', $result['slides'][0]['notes']);
        $this->assertSame('Sample Title', $result['slides'][0]['title']);
        $this->assertStringContainsString('Sample body text', $result['slides'][0]['html']);
    }

    public function testDeckManifestEmptyNotesWhenNoNotesSlide(): void
    {
        $tmpPath = $this->buildPptx(1, false);
        $result = haxcmsSystemBuildPptxDeckManifest($tmpPath);
        $this->assertCount(1, $result['slides']);
        $this->assertSame('', $result['slides'][0]['notes']);
    }

    public function testDeckManifestOrdersSlidesNumericallyPast9(): void
    {
        // 11 slides proves numeric ordering: a lexicographic sort would place
        // slide10/slide11 immediately after slide1, before slide2.
        $tmpPath = $this->buildPptx(11, false);
        $result = haxcmsSystemBuildPptxDeckManifest($tmpPath);
        $numbers = array();
        foreach ($result['slides'] as $slide) {
            $numbers[] = $slide['number'];
        }
        $this->assertSame(range(1, 11), $numbers);
    }

    public function testDeckManifestHasPrunedPerSlideShape(): void
    {
        // The per-slide contract is {number, title, html, notes} — the image
        // field was pruned (slide-deck never reads it). Lock in its absence.
        $tmpPath = $this->buildPptx(1, true);
        $result = haxcmsSystemBuildPptxDeckManifest($tmpPath);
        $this->assertArrayNotHasKey('image', $result['slides'][0]);
        // verify the expected keys are present
        $this->assertArrayHasKey('number', $result['slides'][0]);
        $this->assertArrayHasKey('title', $result['slides'][0]);
        $this->assertArrayHasKey('html', $result['slides'][0]);
        $this->assertArrayHasKey('notes', $result['slides'][0]);
    }

    public function testDeckManifestHtmlContainsSlideWrapperAndHeading(): void
    {
        $tmpPath = $this->buildPptx(1, false);
        $result = haxcmsSystemBuildPptxDeckManifest($tmpPath);
        $html = $result['slides'][0]['html'];
        $this->assertStringContainsString('<div class="slide"', $html);
        $this->assertStringContainsString('<h1>Sample Title</h1>', $html);
        $this->assertStringContainsString('<p>Sample body text</p>', $html);
    }
}
