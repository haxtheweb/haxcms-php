<?php
use PHPUnit\Framework\TestCase;

include_once dirname(__DIR__, 2) . '/lib/SimpleImgVariants.php';

/**
 * simple-img variants. The names are a contract with
 * @haxtheweb/simple-img/lib/SimpleImgVariants.js and haxcms-nodejs
 * src/lib/simpleImgVariants.js: all three test the same golden values, and
 * these are the same cases as haxcms-nodejs
 * test/unit/simple-img-variants.test.cjs.
 */
class SimpleImgVariantsTest extends TestCase
{
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
        $this->site = sys_get_temp_dir() . '/simple-img-' . bin2hex(random_bytes(6));
        mkdir($this->site . '/files', 0755, true);
        mkdir($this->site . '/pages/a', 0755, true);
        return $this->site;
    }

    private function writePhoto(string $name, int $w, int $h, string $format = 'jpg'): void
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 0x33, 0x66, 0xcc));
        $file = $this->site . '/files/' . $name;
        if ($format === 'png') {
            imagepng($img, $file);
        } elseif ($format === 'gif') {
            imagegif($img, $file);
        } else {
            imagejpeg($img, $file);
        }
    }

    private function dims(string $location): array
    {
        $info = getimagesize($this->site . '/' . $location);
        return array($info[0], $info[1], $info['mime']);
    }

    public function testGoldenNamesMatchTheFrontEndAndNode(): void
    {
        $this->assertSame(
            'files/haxcms-managed/simple-img/field-day-a9eb28d6.jpg',
            SimpleImgVariants::variantUrl(array('src' => 'files/field-day.jpg'))
        );
        $this->assertSame(
            'files/haxcms-managed/simple-img/field-day-a9eb28d6.jpg',
            SimpleImgVariants::variantUrl(array(
                'src' => 'files/field-day.jpg', 'width' => '300', 'height' => '200', 'quality' => '80',
                'rotate' => '0', 'fit' => 'cover', 'format' => 'jpg', 'wmspot' => 'se',
            ))
        );
        $this->assertSame(
            'files/haxcms-managed/simple-img/photos--Field-00167df4.webp',
            SimpleImgVariants::variantUrl(array(
                'src' => 'files/photos/Field.PNG?v=2', 'width' => 640, 'height' => 480, 'quality' => 60,
                'rotate' => '-90', 'fit' => 'Inside', 'format' => 'webp', 'watermark' => 'files/logo.png', 'wmspot' => 'nw',
            ))
        );
        $this->assertSame(
            'files/haxcms-managed/simple-img/clip-4aff2e69.jpg',
            SimpleImgVariants::variantUrl(array('src' => 'files/clip.gif', 'quality' => 50, 'format' => 'jpeg'))
        );
    }

    public function testOnlySitePhotosQuarterTurnsSaneSizesAndSiteWatermarksGetAVariant(): void
    {
        $cases = array(
            array('src' => 'https://example.com/files/a.jpg'),
            array('src' => '/files/a.jpg'),
            array('src' => 'files/../site.json'),
            array('src' => 'files/haxcms-managed/simple-img/a-00000000.jpg'),
            array('src' => 'files/a.svg'),
            array('src' => 'files/a b.jpg'),
            array('src' => 'files/a.jpg', 'rotate' => 45),
            array('src' => 'files/a.jpg', 'width' => 5000),
            array('src' => 'files/a.jpg', 'watermark' => 'https://example.com/logo.png'),
            array('src' => 'files/a.jpg', 'watermark' => 'files/logo.gif'),
        );
        foreach ($cases as $attrs) {
            $this->assertNull(SimpleImgVariants::variantUrl($attrs), json_encode($attrs));
        }
        $this->assertSame(
            array('path' => 'files/a.gif', 'stem' => 'a', 'ext' => 'gif'),
            SimpleImgVariants::source('files/a.gif?x=1')
        );
    }

    public function testThePlanListsOneVariantPerFileForSimpleImgOnlyCappedPerSave(): void
    {
        $html = '<simple-img src="files/a.jpg"></simple-img>'
            . '<simple-img src="files/a.jpg" width="300" height="200"></simple-img>'
            . '<simple-img src="files/a.jpg" width="100" height="100"></simple-img>'
            . '<img src="files/b.jpg">'
            . '<simple-img src="https://example.com/c.jpg"></simple-img>';
        $this->assertCount(2, SimpleImgVariants::plan($html));
        $many = '';
        for ($i = 0; $i < SimpleImgVariants::MAX_VARIANTS + 5; $i++) {
            $many .= '<simple-img src="files/a.jpg" width="' . ($i + 1) . '"></simple-img>';
        }
        $this->assertCount(SimpleImgVariants::MAX_VARIANTS, SimpleImgVariants::plan($many));
        $this->assertCount(SimpleImgVariants::MAX_VARIANTS + 5, SimpleImgVariants::plan($many, false));
    }

    public function testVariantsAreWrittenInsideTheSiteAtTheAskedSizeFitAndFormat(): void
    {
        $site = $this->makeSite();
        $this->writePhoto('a.jpg', 800, 400);
        $this->writePhoto('logo.png', 20, 10, 'png');
        $html = '<simple-img src="files/a.jpg"></simple-img>'
            . '<simple-img src="files/a.jpg" width="100" height="100" fit="inside" format="png"></simple-img>'
            . '<simple-img src="files/a.jpg" width="50" height="80" rotate="90" format="webp"></simple-img>'
            . '<simple-img src="files/a.jpg" width="120" height="60" watermark="files/logo.png" wmspot="nw"></simple-img>';
        $written = SimpleImgVariants::cut($site, $html);
        $this->assertCount(4, $written);
        $this->assertSame(array(300, 200, 'image/jpeg'), $this->dims(SimpleImgVariants::variantUrl(array('src' => 'files/a.jpg'))));
        $this->assertSame(array(100, 50, 'image/png'), $this->dims(SimpleImgVariants::variantUrl(array(
            'src' => 'files/a.jpg', 'width' => 100, 'height' => 100, 'fit' => 'inside', 'format' => 'png',
        ))));
        $this->assertSame(array(50, 80, 'image/webp'), $this->dims(SimpleImgVariants::variantUrl(array(
            'src' => 'files/a.jpg', 'width' => 50, 'height' => 80, 'rotate' => 90, 'format' => 'webp',
        ))));
        $this->assertSame(array(120, 60, 'image/jpeg'), $this->dims(SimpleImgVariants::variantUrl(array(
            'src' => 'files/a.jpg', 'width' => 120, 'height' => 60, 'watermark' => 'files/logo.png', 'wmspot' => 'nw',
        ))));
        // a second save finds the files made and makes nothing
        $this->assertSame(array(), SimpleImgVariants::cut($site, $html));
    }

    public function testAGifGivesItsFirstFrame(): void
    {
        $site = $this->makeSite();
        $this->writePhoto('clip.gif', 40, 30, 'gif');
        $written = SimpleImgVariants::cut($site, '<simple-img src="files/clip.gif" quality="50"></simple-img>');
        $this->assertSame(array(SimpleImgVariants::variantUrl(array('src' => 'files/clip.gif', 'quality' => 50))), $written);
        $this->assertSame('image/jpeg', $this->dims($written[0])[2]);
    }

    public function testAMissingPhotoAFakeImageOrAWatermarkBiggerThanTheVariantWritesNothing(): void
    {
        $site = $this->makeSite();
        file_put_contents($site . '/files/fake.jpg', '<svg></svg>');
        $this->writePhoto('a.jpg', 200, 200);
        $this->writePhoto('big.png', 400, 400, 'png');
        $written = SimpleImgVariants::cut(
            $site,
            '<simple-img src="files/missing.jpg"></simple-img>'
            . '<simple-img src="files/fake.jpg"></simple-img>'
            . '<simple-img src="files/a.jpg" width="100" height="100" watermark="files/big.png"></simple-img>'
        );
        $this->assertSame(array(), $written);
        $dir = $site . '/' . SimpleImgVariants::DIR;
        $left = is_dir($dir) ? array_values(array_diff(scandir($dir), array('.', '..'))) : array();
        $this->assertSame(array(), $left, 'no half-written file is left behind');
    }

    public function testASaveOutOfTimeStartsNoNewVariants(): void
    {
        $site = $this->makeSite();
        $this->writePhoto('a.jpg', 100, 100);
        $this->assertSame(array(), SimpleImgVariants::cut($site, '<simple-img src="files/a.jpg"></simple-img>', array('maxSeconds' => 0)));
    }

    public function testAPhotosVariantsNoPageUsesAnyMoreAreRemovedWhenItGetsANewOne(): void
    {
        $site = $this->makeSite();
        $this->writePhoto('a.jpg', 400, 400);
        $this->writePhoto('b.jpg', 400, 400);
        $page = $site . '/pages/a/index.html';
        $first = '<simple-img src="files/a.jpg" width="100" height="100"></simple-img>'
            . '<simple-img src="files/b.jpg" width="100" height="100"></simple-img>';
        file_put_contents($page, $first);
        $old = SimpleImgVariants::cut($site, $first);
        $this->assertCount(2, $old);
        $second = '<simple-img src="files/a.jpg" width="120" height="120"></simple-img>'
            . '<simple-img src="files/b.jpg" width="100" height="100"></simple-img>';
        file_put_contents($page, $second);
        $made = SimpleImgVariants::cut($site, $second);
        $this->assertCount(1, $made);
        $left = array_values(array_diff(scandir($site . '/' . SimpleImgVariants::DIR), array('.', '..')));
        $expected = array(basename($made[0]), basename($old[1]));
        sort($left);
        sort($expected);
        $this->assertSame($expected, $left);
        file_put_contents($site . '/' . SimpleImgVariants::DIR . 'notes.txt', 'keep');
        $this->assertSame(array(), SimpleImgVariants::clean($site, array('a'), array()));
    }
}
