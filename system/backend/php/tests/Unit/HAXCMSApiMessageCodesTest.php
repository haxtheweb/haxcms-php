<?php
use PHPUnit\Framework\TestCase;

/**
 * Translatable API messages (haxtheweb/issues#3157): responses keep their
 * English message and gain a `code` (+ `params`) the editor and CLI translate.
 * The code list (lib/haxcms-api-codes.json) is shared with haxcms-nodejs and
 * @haxtheweb/haxcms-elements, so these cases mirror the Node tests.
 */
class HAXCMSApiMessageCodesTest extends TestCase
{
    private function catalog()
    {
        return json_decode(file_get_contents(dirname(__DIR__, 2) . '/lib/haxcms-api-codes.json'), true);
    }

    public function testCatalogCodesAreUniqueAndPatternsCompile()
    {
        $seen = array();
        foreach ($this->catalog()['codes'] as $entry) {
            $this->assertMatchesRegularExpression('/^[A-Z][A-Z0-9_]*$/', $entry['code']);
            $this->assertArrayNotHasKey($entry['code'], $seen, 'duplicate ' . $entry['code']);
            $seen[$entry['code']] = true;
            $this->assertNotSame('', $entry['en']);
            foreach ($entry['match'] as $source) {
                $this->assertNotFalse(@preg_match('~^(?:' . $source . ')[.!]?$~iu', ''), $entry['code'] . ': ' . $source);
            }
        }
    }

    public function testCatalogMatchesTheBundledFrontEndCopyWhenPresent()
    {
        $bundled = dirname(__DIR__, 4) . '/build/es6/node_modules/@haxtheweb/haxcms-elements/lib/haxcms-api-codes.json';
        if (!file_exists($bundled)) {
            $this->assertTrue(true);
            return;
        }
        $other = json_decode(file_get_contents($bundled), true);
        $mine = $this->catalog();
        if (!is_array($other) || $other['version'] !== $mine['version']) {
            $this->assertTrue(true);
            return;
        }
        $this->assertSame(
            array_column($other['codes'], 'code'),
            array_column($mine['codes'], 'code'),
            'lib/haxcms-api-codes.json is out of sync with @haxtheweb/haxcms-elements'
        );
    }

    public static function examples()
    {
        return array(
            array('invalid request token', 'USER_TOKEN_INVALID', array()),
            array('invalid site token', 'SITE_TOKEN_INVALID', array()),
            array('Item not found for idOrSlug "my-page"', 'ITEM_NOT_FOUND', array('idOrSlug' => 'my-page')),
            array('Invalid file type. Expected .docx, got: notes.txt', 'UPLOAD_WRONG_TYPE', array('expected' => '.docx', 'filename' => 'notes.txt')),
            array('Unable to resolve site context for /x/api/v1/files', 'SITE_CONTEXT_UNRESOLVED', array('route' => '/x/api/v1/files')),
            array('Outline operations are disabled for this site', 'FEATURE_DISABLED_OUTLINE', array()),
            array('Site does not exist', 'SITE_NOT_FOUND', array()),
            array('contact-form does not exist', 'FORM_NOT_FOUND', array('form' => 'contact-form')),
            array('missing `repoUrl` param', 'PARAM_REQUIRED', array('param' => 'repoUrl')),
            array('Unable to fetch site.json from https://x.test/site.json: timeout', 'IMPORT_FETCH_FAILED', array('url' => 'https://x.test/site.json', 'detail' => 'timeout')),
            array('Unable to determine source image size', 'IMAGE_OP_FAILED', array()),
            array('Too many login attempts. Please retry later.', 'AUTH_RATE_LIMITED', array()),
            array('Admin access required', 'ACCESS_DENIED', array()),
            array('User access granted successfully', 'ACCESS_GRANTED', array()),
        );
    }

    /**
     * @dataProvider examples
     */
    public function testKnownMessagesGetTheExpectedCode($message, $code, $params)
    {
        $found = HAXCMSApiMessageCodes::match($message);
        $this->assertNotNull($found, 'no code for ' . $message);
        $this->assertSame($code, $found['code']);
        $this->assertSame($params, $found['params']);
    }

    public function testUnknownOrOversizedTextGetsNoCode()
    {
        $this->assertNull(HAXCMSApiMessageCodes::match('Everything worked out great today'));
        $this->assertNull(HAXCMSApiMessageCodes::match(''));
        $this->assertNull(HAXCMSApiMessageCodes::match(null));
        $this->assertNull(HAXCMSApiMessageCodes::match('Access denied' . str_repeat(' ', 2000)));
    }

    public function testEnvelopesGetCodeAndParamsNextToTheMessage()
    {
        $body = HAXCMSApiMessageCodes::annotate(array('status' => 404, 'data' => array('message' => 'Item not found for idOrSlug "intro"')), 404);
        $this->assertSame('Item not found for idOrSlug "intro"', $body['data']['message']);
        $this->assertSame('ITEM_NOT_FOUND', $body['data']['code']);
        $this->assertSame(array('idOrSlug' => 'intro'), $body['data']['params']);

        $failed = HAXCMSApiMessageCodes::annotate(array('__failed' => array('status' => 403, 'message' => 'invalid site token')), 200);
        $this->assertSame('SITE_TOKEN_INVALID', $failed['__failed']['code']);
        $this->assertArrayNotHasKey('params', $failed['__failed']);

        $top = HAXCMSApiMessageCodes::annotate(array('status' => 404, 'message' => 'Unknown site API route'), 404);
        $this->assertSame('ROUTE_NOT_FOUND', $top['code']);

        $error = HAXCMSApiMessageCodes::annotate(array('status' => 400, 'data' => array('error' => 'Error converting PDF to HTML: bad file')), 400);
        $this->assertSame('CONVERSION_FAILED', $error['data']['code']);
        $this->assertSame(array('detail' => 'bad file'), $error['data']['params']);
    }

    public function testExistingCodesAreKept()
    {
        $body = HAXCMSApiMessageCodes::annotate(array('status' => 400, 'data' => array('message' => 'Invalid request', 'code' => 'CUSTOM')), 400);
        $this->assertSame('CUSTOM', $body['data']['code']);
    }

    public function testSuccessfulResponsesOnlyGetInformationalCodes()
    {
        $ok = HAXCMSApiMessageCodes::annotate(array('status' => 200, 'data' => array('message' => 'Access denied')), 200);
        $this->assertArrayNotHasKey('code', $ok['data']);
        $saved = HAXCMSApiMessageCodes::annotate(array('status' => 200, 'data' => array('message' => 'Style guide saved successfully')), 200);
        $this->assertSame('STYLE_GUIDE_SAVED', $saved['data']['code']);
        $this->assertSame('loggedout', HAXCMSApiMessageCodes::annotate('loggedout', 200));
        $this->assertSame(array(array('message' => 'Access denied')), HAXCMSApiMessageCodes::annotate(array(array('message' => 'Access denied')), 403));
    }

    public function testTextFillsParamsIntoTheEnglishText()
    {
        $this->assertSame('The file is larger than the 25 MB upload limit.', HAXCMSApiMessageCodes::text('UPLOAD_TOO_LARGE', array('maxMb' => '25')));
        $this->assertNull(HAXCMSApiMessageCodes::text('NOT_A_CODE'));
    }

    /**
     * Every 'message' / 'error' literal under lib/ should have a code, so new
     * messages don't silently stay English-only. Add a pattern to
     * lib/haxcms-api-codes.json (and the copies in haxcms-nodejs and
     * @haxtheweb/haxcms-elements) when this fails.
     */
    public function testMessageLiteralsInLibAllHaveACode()
    {
        $root = dirname(__DIR__, 2) . '/lib';
        $missing = array();
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (substr($file->getFilename(), -4) !== '.php') {
                continue;
            }
            $tokens = token_get_all(file_get_contents($file->getPathname()));
            $count = count($tokens);
            for ($i = 0; $i < $count; $i++) {
                if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }
                $key = substr($tokens[$i][1], 1, -1);
                if ($key !== 'message' && $key !== 'error') {
                    continue;
                }
                $j = $i + 1;
                while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }
                if ($j >= $count || !is_array($tokens[$j]) || $tokens[$j][0] !== T_DOUBLE_ARROW) {
                    continue;
                }
                $j++;
                while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }
                $value = $this->literalValue($tokens, $j);
                if ($value === null || !preg_match('/[a-z]{3}/i', $value)) {
                    continue;
                }
                // connectionSettings sends JavaScript in 'message', not text people read
                if (strpos($value, 'window.') === 0) {
                    continue;
                }
                if (HAXCMSApiMessageCodes::match($value) === null) {
                    $missing[] = substr($file->getPathname(), strlen($root) + 1) . ':' . $tokens[$i][2] . ' ' . json_encode($value);
                }
            }
        }
        $this->assertSame(array(), $missing, "messages without a code:\n" . implode("\n", $missing));
    }

    // a string literal, "interpolated $vars", or 'a' . $b . 'c' concatenation; variables become "1"
    private function literalValue($tokens, $start)
    {
        $out = '';
        $sawText = false;
        $count = count($tokens);
        for ($j = $start; $j < $count; $j++) {
            $token = $tokens[$j];
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $raw = $token[1];
                $out .= $raw[0] === "'" ? str_replace(array("\\'", '\\\\'), array("'", '\\'), substr($raw, 1, -1)) : stripcslashes(substr($raw, 1, -1));
                $sawText = true;
            }
            else if ($token === '"') {
                // interpolated string: collect until the closing quote
                for ($j = $j + 1; $j < $count && $tokens[$j] !== '"'; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_ENCAPSED_AND_WHITESPACE) {
                        $out .= stripcslashes($tokens[$j][1]);
                    }
                    else if (is_array($tokens[$j]) && $tokens[$j][0] === T_VARIABLE) {
                        $out .= '1';
                    }
                }
                $sawText = true;
            }
            else if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }
            else if ($token === '.') {
                continue;
            }
            else if ($sawText && (is_array($token) && ($token[0] === T_VARIABLE || $token[0] === T_STRING))) {
                // a variable or call in a concatenation: skip to the next '.'
                $depth = 0;
                for (; $j < $count; $j++) {
                    if ($tokens[$j] === '(' || $tokens[$j] === '[') {
                        $depth++;
                    }
                    else if ($tokens[$j] === ')' || $tokens[$j] === ']') {
                        if ($depth === 0) {
                            break;
                        }
                        $depth--;
                    }
                    else if ($depth === 0 && ($tokens[$j] === '.' || $tokens[$j] === ',' || $tokens[$j] === ';')) {
                        break;
                    }
                }
                $out .= '1';
                if ($j < $count && $tokens[$j] === '.') {
                    continue;
                }
                break;
            }
            else {
                break;
            }
        }
        return $sawText ? $out : null;
    }
}
