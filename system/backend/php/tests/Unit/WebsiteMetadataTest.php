<?php
use PHPUnit\Framework\TestCase;

/**
 * POST /system/api/v1/actions/website-metadata: the on-premises replacement
 * for the open-apis metadata service (link previews, insert link by URL).
 * Same cases as haxcms-nodejs test/unit/website-metadata.test.cjs.
 */
class WebsiteMetadataTest extends TestCase
{
    private function route()
    {
        return require dirname(__DIR__, 2) . '/lib/systemRoutes/v1/websiteMetadata.php';
    }

    private function invoke(array $body): array
    {
        $context = new stdClass();
        $context->body = $body;
        $context->routeSuffix = 'v1/actions/website-metadata';
        $context->apiBasePath = '/system/api';
        http_response_code(200);
        ob_start();
        call_user_func($this->route(), $context);
        $raw = ob_get_clean();
        return array(http_response_code(), json_decode($raw, true));
    }

    public function testReadsTitleMetaByNameOrPropertyAndJsonLdKeepingTheAskedUrl(): void
    {
        $this->route();
        $html = '<html><head><title>Field day</title>'
            . '<meta name="description" content="A day outside">'
            . '<meta property="og:title" content="Field day!">'
            . '<meta property="og:image" content="https://example.com/a.jpg">'
            . '<meta name="empty">'
            . '<meta name="__proto__" content="x">'
            . '<script type="application/ld+json">{"@type":"Event"}</script>'
            . '<script type="application/ld+json">not json</script>'
            . '</head><body></body></html>';
        $data = json_decode(json_encode(haxcmsReadWebsiteMetadata($html, 'https://example.com/day')), true);
        $this->assertSame('Field day', $data['title']);
        $this->assertSame('A day outside', $data['description']);
        $this->assertSame('Field day!', $data['og:title']);
        $this->assertSame('https://example.com/a.jpg', $data['og:image']);
        $this->assertSame('https://example.com/day', $data['url']);
        $this->assertArrayNotHasKey('empty', $data);
        $this->assertArrayNotHasKey('__proto__', $data);
        $this->assertSame(array('@type' => 'Event'), $data['ld+json']);
    }

    public function testAMissingNonHttpOrOverlongQIsA400(): void
    {
        foreach (array(null, '', 'ftp://example.com', 'javascript:alert(1)', 'https://' . str_repeat('a', 2100)) as $q) {
            list($status) = $this->invoke($q === null ? array() : array('q' => $q));
            $this->assertSame(400, $status, substr((string) $q, 0, 30));
        }
    }

    public function testAPrivateOrLoopbackAddressIsNeverFetchedOnlyTheUrlComesBack(): void
    {
        foreach (array('http://127.0.0.1/', 'http://169.254.169.254/latest/meta-data/', 'http://[::1]/') as $q) {
            list($status, $payload) = $this->invoke(array('q' => $q));
            $this->assertSame(200, $status);
            $this->assertSame(array('status' => 200, 'data' => array('url' => $q)), $payload);
        }
    }
}
