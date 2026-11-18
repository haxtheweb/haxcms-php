<?php
/**
 * HAXCMSSameAs::normalize keeps exactly the sameAs URLs haxcms-nodejs keeps,
 * spelled the same way (haxtheweb/issues#3367). The vectors file is shared
 * with haxcms-nodejs test/fixtures/same-as-vectors.json.
 */

use PHPUnit\Framework\TestCase;

class HAXCMSSameAsTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/HAXCMSSameAs.php';
    }

    public function testSharedVectorsMatchHaxcmsNodejs(): void
    {
        $data = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/same-as-vectors.json'), true);
        $this->assertNotEmpty($data['vectors']);
        foreach ($data['vectors'] as $vector) {
            $this->assertSame(
                $vector['kept'] === null ? array() : array($vector['kept']),
                HAXCMSSameAs::normalize(array($vector['input'])),
                $vector['input']
            );
        }
    }

    public function testListOrderDedupeLimitAndInputTypes(): void
    {
        $this->assertSame(
            array('https://github.com/haxtheweb', 'https://www.linkedin.com/company/x', 'http://example.org/a'),
            HAXCMSSameAs::normalize("https://github.com/haxtheweb\n  https://www.linkedin.com/company/x  \r\nhttps://github.com/haxtheweb\n\nhttp://example.org/a")
        );
        $many = array();
        for ($i = 0; $i < HAXCMSSameAs::MAX_COUNT + 5; $i++) {
            $many[] = 'https://example.org/' . $i;
        }
        $this->assertCount(HAXCMSSameAs::MAX_COUNT, HAXCMSSameAs::normalize($many));
        $this->assertSame(array(), HAXCMSSameAs::normalize(null));
        $this->assertSame(array(), HAXCMSSameAs::normalize(5));
        $this->assertSame(array(), HAXCMSSameAs::normalize(array('a' => 'https://x.org')));
        $this->assertSame(array(), HAXCMSSameAs::normalize(array(5, null, array())));
        $this->assertSame(array(), HAXCMSSameAs::normalize(array('https://' . str_repeat('a', 2050) . '.com')));
    }
}
