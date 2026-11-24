<?php
use PHPUnit\Framework\TestCase;

/**
 * SEC-PATH / SEC-SESSION: which HAXiam tenant (users/<name>) a request maps
 * to, and that CLI detection cannot be driven from the request.
 */
class IamTenantTest extends TestCase
{
    public function testPlainNamesFromTheFirstPathSegmentAreAccepted(): void
    {
        $this->assertSame('bto108', IamTenant::fromRequestUri('/bto108/sites/ist256/syllabus'));
        $this->assertSame('jane.doe@psu.edu', IamTenant::fromRequestUri('/jane.doe@psu.edu/'));
        $this->assertSame('a_b-c', IamTenant::fromRequestUri('/a_b-c'));
    }

    public function testQueryStringAndFragmentNeverFormTheTenant(): void
    {
        $this->assertSame('bto108', IamTenant::fromRequestUri('/bto108?x=../../etc'));
        $this->assertNull(IamTenant::fromRequestUri('/?bto108'));
        $this->assertSame('bto108', IamTenant::fromRequestUri('/bto108#frag'));
    }

    public function testUnsafeOrEmptySegmentsGiveNoTenant(): void
    {
        foreach (array('/', '', '/..', '/../x', '/.hidden', '/-rf', '/a..b', '/%2e%2e', '/a b', "/a\0b", '//x') as $uri) {
            $this->assertNull(IamTenant::fromRequestUri($uri), var_export($uri, true));
        }
        $this->assertNull(IamTenant::fromRequestUri(null));
        $this->assertNull(IamTenant::fromRequestUri('/' . str_repeat('a', 129)));
        $this->assertSame(str_repeat('a', 128), IamTenant::fromRequestUri('/' . str_repeat('a', 128)));
    }

    public function testDocumentRootTenantIsValidated(): void
    {
        $this->assertSame('bto108', IamTenant::fromDocumentRoot('/var/www/haxiam/users/bto108/sites/flourish/x'));
        $this->assertNull(IamTenant::fromDocumentRoot('/var/www/haxiam/users/../sites/flourish/x'));
        $this->assertNull(IamTenant::fromDocumentRoot('/a/b'));
        $this->assertNull(IamTenant::fromDocumentRoot(''));
    }

    public function testCliIsDecidedFromTheSapiNotArgc(): void
    {
        $savedSoftware = isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : null;
        $savedArgc = isset($_SERVER['argc']) ? $_SERVER['argc'] : null;
        try {
            $_SERVER['SERVER_SOFTWARE'] = 'Apache';
            $_SERVER['argc'] = 3;
            $this->assertFalse(IamTenant::isCli(), 'argc must not make a web request look like the CLI');
            unset($_SERVER['SERVER_SOFTWARE']);
            $this->assertTrue(IamTenant::isCli());
        } finally {
            if ($savedSoftware === null) {
                unset($_SERVER['SERVER_SOFTWARE']);
            } else {
                $_SERVER['SERVER_SOFTWARE'] = $savedSoftware;
            }
            if ($savedArgc === null) {
                unset($_SERVER['argc']);
            } else {
                $_SERVER['argc'] = $savedArgc;
            }
        }
    }

    public function testBootstrapUsesTheTenantRules(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/bootstrapHAX.php');
        $this->assertStringContainsString('IamTenant::fromRequestUri(', $src);
        $this->assertStringContainsString('IamTenant::fromDocumentRoot(', $src);
        $this->assertStringContainsString('IamTenant::isCli()', $src);
        $this->assertStringNotContainsString("\$_SERVER['argc']", $src);
    }
}
