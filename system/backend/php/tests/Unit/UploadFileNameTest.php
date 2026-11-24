<?php
require_once __DIR__ . '/FaultInjectionTestSupport.php';

/**
 * The name an uploaded file is saved under, through the real POST
 * /x/api/v1/files handler. The rule is the same in haxcms-nodejs
 * (HAXCMSFile.save) and is checked across both backends by the golden
 * parity cases 39b and 40 in haxtheweb/create:
 *
 *   - Unicode letters are kept as sent
 *   - runs of white space and path/shell characters become "-", so a file
 *     URL never needs escaping
 *   - the name is cleaned up before the "already there" check, so a second
 *     upload never overwrites a file, and the suffix is _1, _2, ...
 *
 * No faults are injected here: the fault-injection base class is only used
 * for its real site fixture and route runner.
 */
class UploadFileNameTest extends FaultInjectionTestCase
{
    private function upload(string $name, string $bytes): array
    {
        $staging = $this->haxcms->configDirectory . '/tmp/imports';
        if (!is_dir($staging)) {
            mkdir($staging, 0777, true);
        }
        $tmp = $staging . '/' . uniqid('upload', true) . '.png';
        file_put_contents($tmp, $bytes);
        $_FILES['file-upload'] = array(
            'name' => $name,
            'type' => 'image/png',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmp),
            // the bulk-import staging path stands in for is_uploaded_file(),
            // which is always false under the CLI
            'bulk-import' => true,
        );
        $res = $this->request('filesMutation.php', $this->context(array(), 'POST', 'files'));
        unset($_FILES['file-upload']);
        return $res;
    }

    private static function png(int $r, int $g, int $b): string
    {
        $img = imagecreatetruecolor(2, 2);
        imagefill($img, 0, 0, imagecolorallocate($img, $r, $g, $b));
        ob_start();
        imagepng($img);
        return (string) ob_get_clean();
    }

    private function filesOnDisk(): array
    {
        $out = array_values(array_filter(scandir($this->siteDir() . '/files'), function ($f) {
            return $f[0] !== '.' && $f !== 'files.json';
        }));
        sort($out);
        return $out;
    }

    public function testUnicodeLettersAreKeptAndSpacesBecomeDashes(): void
    {
        $res = $this->upload('café ñandú.png', self::png(255, 0, 170));
        $this->assertSame(200, $res['status'], json_encode($res['body']));
        $this->assertSame(array('café-ñandú.png'), $this->filesOnDisk());
    }

    public function testPathAndShellCharactersBecomeDashes(): void
    {
        $res = $this->upload('a:b|c"d.png', self::png(0, 0, 0));
        $this->assertSame(200, $res['status'], json_encode($res['body']));
        $this->assertSame(array('a-b-c-d.png'), $this->filesOnDisk());
    }

    public function testASecondUploadOfTheSameNameGetsAnUnderscoreSuffix(): void
    {
        $pink = self::png(255, 0, 170);
        $teal = self::png(0, 128, 128);
        $this->assertSame(200, $this->upload('square.png', $pink)['status']);
        $this->assertSame(200, $this->upload('square.png', $teal)['status']);
        $this->assertSame(array('square.png', 'square_1.png'), $this->filesOnDisk());
        $this->assertSame($pink, file_get_contents($this->siteDir() . '/files/square.png'));
        $this->assertSame($teal, file_get_contents($this->siteDir() . '/files/square_1.png'));
    }

    // "my photo.png" and "my-photo.png" clean up to the same name. The
    // "already there" check used to look for the name as sent, so the second
    // upload replaced the first one's bytes.
    public function testTwoNamesThatCleanUpTheSameNeverOverwriteEachOther(): void
    {
        $first = self::png(255, 0, 0);
        $second = self::png(0, 0, 255);
        $this->assertSame(200, $this->upload('my-photo.png', $first)['status']);
        $this->assertSame(200, $this->upload('my photo.png', $second)['status']);
        $this->assertSame(array('my-photo.png', 'my-photo_1.png'), $this->filesOnDisk());
        $this->assertSame($first, file_get_contents($this->siteDir() . '/files/my-photo.png'));
        $this->assertSame($second, file_get_contents($this->siteDir() . '/files/my-photo_1.png'));
    }
}
