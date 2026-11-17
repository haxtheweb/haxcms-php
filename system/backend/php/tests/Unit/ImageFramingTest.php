<?php
use PHPUnit\Framework\TestCase;

include_once dirname(__DIR__, 2) . '/lib/ImageFraming.php';

/**
 * Image framing cuts (haxtheweb/issues#2976). The names are a contract with
 * @haxtheweb/d-d-d/lib/ImageFraming.js and haxcms-nodejs
 * src/lib/imageFraming.js: all three test the same golden values, and these
 * are the same cases as haxcms-nodejs test/unit/image-framing.test.cjs.
 */
class ImageFramingTest extends TestCase
{
    private const RECT = array('x' => 0.12, 'y' => 0, 'w' => 0.763, 'h' => 1);
    private $site;

    protected function tearDown(): void
    {
        if ($this->site && is_dir($this->site)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->site, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->site);
        }
    }

    private function makeSite(): string
    {
        $this->site = sys_get_temp_dir() . '/framing-' . bin2hex(random_bytes(6));
        mkdir($this->site . '/files', 0755, true);
        return $this->site;
    }

    private function rectJson(): string
    {
        return json_encode(array('sm' => self::RECT));
    }

    public function testGoldenNamesMatchTheFrontEndAndNode(): void
    {
        $this->assertSame('0.120,0.000,0.763,1.000', ImageFraming::framingKey(self::RECT));
        $this->assertSame('924e65bf', ImageFraming::fnv1a(ImageFraming::framingKey(self::RECT)));
        $this->assertSame(
            'files/haxcms-managed/framing/field-day-sm-924e65bf-600w.jpg',
            ImageFraming::framingCutUrl('files/field-day.jpg', 'sm', self::RECT, 600)
        );
        $this->assertSame(
            'files/haxcms-managed/framing/field-day-full-1200w.jpg',
            ImageFraming::framingFullUrl('files/field-day.jpg', 1200)
        );
        $this->assertSame(
            'files/haxcms-managed/framing/photos--Field-full-600w.png',
            ImageFraming::framingFullUrl('files/photos/Field.PNG', 600)
        );
        $this->assertSame(array(600, 1200), ImageFraming::framingCutWidths('sm'));
        $this->assertSame(array(1920, 3840), ImageFraming::framingCutWidths('xl'));
    }

    public function testOnlyPlainStillImagesUnderFilesAreCut(): void
    {
        foreach (array(
            'https://example.com/files/a.jpg',
            '/files/a.jpg',
            'files/../site.json',
            'files/haxcms-managed/framing/a-full-600w.jpg',
            'files/movie.gif',
            'files/a b.jpg',
            'files/.hidden.jpg',
            'files/noext',
        ) as $source) {
            $this->assertNull(ImageFraming::framingSource($source), $source);
        }
        $this->assertSame(array('stem' => 'a', 'ext' => 'webp'), ImageFraming::framingSource('files/a.webp?v=2'));
    }

    public function testBadFramingValuesAreDropped(): void
    {
        $this->assertSame(array(), ImageFraming::parseFraming('not json'));
        $this->assertSame(
            array(),
            ImageFraming::parseFraming('{"sm":{"x":0.5,"y":0,"w":0.8,"h":1},"zz":{"x":0,"y":0,"w":1,"h":1}}')
        );
        $this->assertEquals(array('md' => self::RECT), ImageFraming::parseFraming(array('md' => self::RECT)));
    }

    public function testThePlanListsFullVariantsForEveryHeroAndCutsForCustomSizes(): void
    {
        $html = '<ddd-hero source="files/field-day.jpg"></ddd-hero>'
            . "<ddd-hero source=\"files/field-day.jpg\" framing='" . $this->rectJson() . "'></ddd-hero>"
            . '<ddd-hero source="files/clip.mp4" media-type="video"></ddd-hero>'
            . '<img source="files/other.jpg">';
        $locations = array_map(function ($entry) {
            return $entry['location'];
        }, ImageFraming::framingPlan($html));
        $this->assertSame(array(
            'files/haxcms-managed/framing/field-day-full-600w.jpg',
            'files/haxcms-managed/framing/field-day-full-1200w.jpg',
            'files/haxcms-managed/framing/field-day-full-1920w.jpg',
            'files/haxcms-managed/framing/field-day-full-2880w.jpg',
            'files/haxcms-managed/framing/field-day-sm-924e65bf-600w.jpg',
            'files/haxcms-managed/framing/field-day-sm-924e65bf-1200w.jpg',
        ), $locations);
    }

    public function testCutsAreWrittenInsideTheSiteFramedAndNeverUpscaled(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is not available');
        }
        $site = $this->makeSite();
        $img = imagecreatetruecolor(1600, 900);
        imagefill($img, 0, 0, imagecolorallocate($img, 0x33, 0x66, 0x99));
        imagejpeg($img, $site . '/files/field-day.jpg');
        $html = "<ddd-hero source=\"files/field-day.jpg\" framing='" . $this->rectJson() . "'></ddd-hero>";
        $written = ImageFraming::cutFramings($site, $html);
        $this->assertCount(6, $written);
        $dir = $site . '/files/haxcms-managed/framing/';
        $this->assertSame(600, getimagesize($dir . 'field-day-full-600w.jpg')[0]);
        // the photo is 1600 wide, so the 1920 variant keeps it at 1600
        $this->assertSame(1600, getimagesize($dir . 'field-day-full-1920w.jpg')[0]);
        // the cut is 0.763 of the width, full height: 1221 x 900, sized to 600
        $cut = getimagesize($dir . 'field-day-sm-924e65bf-600w.jpg');
        $this->assertSame(600, $cut[0]);
        $this->assertEqualsWithDelta((int) round(900 * 600 / 1221), $cut[1], 1);
        $this->assertSame(1200, getimagesize($dir . 'field-day-sm-924e65bf-1200w.jpg')[0]);
        // a second save keeps the files there
        $this->assertSame(array(), ImageFraming::cutFramings($site, $html));
    }

    public function testAMissingPhotoOrAPathOutOfTheSiteWritesNothing(): void
    {
        $site = $this->makeSite();
        $this->assertSame(array(), ImageFraming::cutFramings($site, '<ddd-hero source="files/gone.jpg"></ddd-hero>'));
        $this->assertSame(array(), ImageFraming::cutFramings($site, '<ddd-hero source="files/../../etc/x.jpg"></ddd-hero>'));
        $this->assertFalse(ImageFraming::containedPath($site, '../outside.jpg'));
    }
}
