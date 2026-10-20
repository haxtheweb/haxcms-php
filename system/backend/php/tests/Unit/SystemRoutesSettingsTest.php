<?php
/**
 * Characterization tests for lib/systemRoutes/v1/settings.php.
 *
 * The returned route closure is exercised for every settings route family,
 * against the REAL Operations class + REAL settings services, over a per-test
 * temp configDirectory:
 *   - v1/status (real SystemStatusService report envelope; route temporarily
 *     flips REQUEST_METHOD to POST and restores it)
 *   - v1/system/version (getHAXCMSVersion passthrough)
 *   - v1/entities (real EntityRegistry over lib/entities.yaml; scope filter
 *     via body param and via $GLOBALS['HAXCMS']->safeGet)
 *   - v1/schemas (static catalog; filter.kind via params and via the raw
 *     $_SERVER['QUERY_STRING'] dot-preserved parse)
 *   - v1/configuration/{api-keys,media,localization} GET/POST/PATCH
 *   - v1/configuration/schema-files/operations (multipart $_POST + $_FILES
 *     upload happy path plus failure guards)
 *   - v1/blocks, v1/skeletons (+ includeDisabled), v1/themes GET/POST/PATCH
 *   - v1/skeletons/:skeletonName GET / PATCH (rename inference) / PUT /
 *     DELETE (delete inference) / HEAD 405
 *   - unknown route 404 (with a null apiBasePath exercising the '/system/api'
 *     default branch) and the __failed envelope
 *
 * Token selection is pinned through the fake's validateRequestToken call
 * log: header-fed routes see the X-HAXCMS-User-Token value, bearer-only
 * routes (blocks/themes/skeletons lists) see the server-resolved token.
 *
 * DOCUMENTED NOT-IN-PROCESS-COVERED BLOCKS in settings.php:
 *   - 493-495 (wrap a non-array / status-less $response): every Operations
 *     method reachable here returns an array that already carries 'status'
 *     (or '__failed', which exits earlier), so this defensive wrap cannot
 *     fire in-process.
 *   - saveEnabledSkeletons / saveEnabledThemes 'Missing/Invalid payload'
 *     400s: their resolvers fall back to $this->params (always an array
 *     after the route merge), so the null-payload guards are unreachable
 *     through this route.
 *   - haxcmsSystemSettingsInvokeAsPost's restore-when-absent branch is
 *     covered by running a POST with REQUEST_METHOD unset.
 * Note: v1/status performs one real GitHub release lookup with a 4s timeout
 * (statically cached for 300s), so the first status test may block briefly.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Configurable HAXCMS collaborator for the settings route. Extends the shared
 * Operations fake with the seams settings.php touches: token minting, theme
 * registry, skeleton dir discovery hooks, and machine-name normalization
 * (mirrored from HAXCMS::generateMachineName).
 */
class SettingsRouteFakeHaxcms extends OperationsTestHaxcms
{
    public $systemRequestBase = 'system/api/';
    public $coreConfigPath = '';
    public $publishedDirectory = '_published';
    public $archivedDirectory = '_archived';
    public $safeGet = null;
    public $requestTokenUserName = 'testuser';
    public $tokenCalls = array();
    public $requestTokenCalls = array();

    public function getRequestTokenUserName()
    {
        return $this->requestTokenUserName;
    }

    public function getRequestToken($name)
    {
        $this->requestTokenCalls[] = (string) $name;
        return 'server-token-for-' . $name;
    }

    public function generateMachineName($name)
    {
        // Mirrors HAXCMS::generateMachineName so skeleton/theme machine-name
        // assertions reflect the real production normalization contract.
        $name = str_replace(chr(0), '', (string) $name);
        $name = urldecode($name);
        $name = preg_replace('/\.{2,}/', '', $name);
        $name = preg_replace('/[\\\\\/]/', '', $name);
        $name = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $name);
        $name = preg_replace('/[-_]{2,}/', '-', $name);
        $name = trim($name, '-_');
        $name = strtolower($name);
        if (empty($name)) {
            $name = 'default';
        }
        return $name;
    }

    public function getThemes()
    {
        return array('clean-two' => array('name' => 'clean-two', 'element' => 'clean-two'));
    }

    public function dispatchEvent($eventName, $context = null)
    {
        // No-op hook sink: getSkeletonDirectories dispatches
        // 'haxcms-skeleton-dirs' unconditionally.
        return null;
    }

    public function validateRequestToken($token, $value)
    {
        $this->tokenCalls[] = array((string) $token, (string) $value);
        return parent::validateRequestToken($token, $value);
    }
}

class SystemRoutesSettingsTest extends TestCase
{
    private $savedHaxcms;
    private $savedGet;
    private $savedPost;
    private $savedFiles;
    private $savedServer;
    private $configDir;
    private $settingsPath;

    protected function setUp(): void
    {
        $this->settingsPath = dirname(__DIR__, 2) . '/lib/systemRoutes/v1/settings.php';
        $this->savedHaxcms = isset($GLOBALS['HAXCMS']) ? $GLOBALS['HAXCMS'] : null;
        $this->savedGet = $_GET;
        $this->savedPost = $_POST;
        $this->savedFiles = $_FILES;
        $this->savedServer = $_SERVER;
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';
        unset($_SERVER['HTTP_X_HAXCMS_USER_TOKEN']);
        unset($_SERVER['QUERY_STRING']);
        $_GET = array();
        $_POST = array();
        $_FILES = array();
        http_response_code(200);
        $this->configDir = sys_get_temp_dir() . '/haxcms_settings_route_' . uniqid();
        @mkdir($this->configDir, 0777, true);
        $GLOBALS['HAXCMS'] = new SettingsRouteFakeHaxcms();
        $GLOBALS['HAXCMS']->configDirectory = $this->configDir;
        // Core config skeleton dir intentionally absent; tests that need it
        // create it under this root.
        $GLOBALS['HAXCMS']->coreConfigPath = $this->configDir . '/core-config/';
    }

    protected function tearDown(): void
    {
        if ($this->savedHaxcms !== null) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
        $_GET = $this->savedGet;
        $_POST = $this->savedPost;
        $_FILES = $this->savedFiles;
        $_SERVER = $this->savedServer;
        $this->rrmdir($this->configDir);
        http_response_code(200);
    }

    private function haxcms()
    {
        return $GLOBALS['HAXCMS'];
    }

    private function rrmdir($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * Include the route file fresh and invoke the closure with a reflection
     * context; returns raw output, decoded JSON, and the response code.
     * $_SERVER['REQUEST_METHOD'] is set from $method first (schemaFileOperation
     * reads it directly for unwrapped PATCH/PUT/DELETE calls); $serverOverrides
     * with a null value then unsets that entry. Pass $apiBasePath = null to
     * exercise the route's '/system/api' default branch.
     */
    private function runHandler($routeSuffix, $method = 'GET', $body = array(), $params = array(), array $serverOverrides = array(), $apiBasePath = '/system/api')
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        foreach ($serverOverrides as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
        $context = (new ReflectionClass('SystemApiRequestContext'))->newInstanceWithoutConstructor();
        $context->apiBasePath = $apiBasePath;
        $context->routeSuffix = $routeSuffix;
        $context->method = $method;
        $context->body = $body;
        $context->params = $params;
        $handler = require $this->settingsPath;
        ob_start();
        $handler($context);
        $raw = ob_get_clean();
        return array(
            'raw' => $raw,
            'data' => json_decode($raw, true),
            'code' => http_response_code(),
        );
    }

    private function writeSkeletonFixture($machineName, array $extra = array())
    {
        $dir = $this->configDir . '/user/skeletons';
        @mkdir($dir, 0777, true);
        $payload = array_merge(array(
            'name' => $machineName,
            'build' => array('pages' => array()),
            'meta' => array('machineName' => $machineName),
        ), $extra);
        file_put_contents($dir . '/' . $machineName . '.json', json_encode($payload));
        return $dir . '/' . $machineName . '.json';
    }

    private function makeUploadFile($contents)
    {
        $tmp = tempnam(sys_get_temp_dir(), 'skel_upload_');
        file_put_contents($tmp, $contents);
        return $tmp;
    }

    // ------------------------------------------------------------------
    // v1/status + v1/system/version
    // ------------------------------------------------------------------

    public function testStatusGetReturnsReportEnvelopeAndRestoresMethod(): void
    {
        $result = $this->runHandler('v1/status', 'GET', array(), array(), array(
            'HTTP_X_HAXCMS_USER_TOKEN' => 'client-token',
        ));

        $this->assertSame(200, $result['code']);
        $this->assertSame(200, $result['data']['status']);
        $this->assertArrayHasKey('summary', $result['data']['data']);
        $this->assertArrayHasKey('rows', $result['data']['data']);
        $this->assertNotEmpty($result['data']['data']['rows']);
        // normalizeVersion only trims / strips a leading 'v'.
        $this->assertSame('0.0.0-test', $result['data']['data']['summary']['haxcmsVersionCurrent']);
        // v1/status is a canonical read route: the client header is validated.
        $this->assertSame(array(array('client-token', 'testuser')), $this->haxcms()->tokenCalls);
        // The route's temporary POST flip for systemStatus() is restored.
        $this->assertSame('GET', $_SERVER['REQUEST_METHOD']);
    }

    public function testSystemVersionReturnsVersionEnvelope(): void
    {
        $result = $this->runHandler('v1/system/version', 'GET', array(), array(), array(
            'HTTP_X_HAXCMS_USER_TOKEN' => 'client-token',
        ));

        $this->assertSame(200, $result['data']['status']);
        $this->assertSame('0.0.0-test', $result['data']['data']['version']);
        // The version route builds its envelope directly: no Operations call,
        // so no validateRequestToken happens even though the header token was
        // fed into params.
        $this->assertSame(array(), $this->haxcms()->tokenCalls);
    }

    // ------------------------------------------------------------------
    // v1/entities (real EntityRegistry over lib/entities.yaml)
    // ------------------------------------------------------------------

    public function testEntitiesGetReturnsAllRegistryDescriptors(): void
    {
        $result = $this->runHandler('v1/entities', 'GET', array(), array(), array(
            'HTTP_X_HAXCMS_USER_TOKEN' => 'client-token',
        ));

        $this->assertSame(200, $result['data']['status']);
        $this->assertSame(6, $result['data']['data']['count']);
        $entities = $result['data']['data']['entities'];
        $this->assertCount(6, $entities);
        // entities.yaml order: file, item, theme, skeleton, site, system.
        $this->assertSame('file', $entities[0]['type']);
        $this->assertSame('site', $entities[0]['scope']);
        $this->assertSame('system', $entities[5]['type']);
        $this->assertSame('/system/api/entities', $result['data']['data']['links']['self']);
    }

    public function testEntitiesGetFiltersByScopeFromBodyParam(): void
    {
        $result = $this->runHandler('v1/entities', 'GET', array('scope' => ' system '), array(), array(
            'HTTP_X_HAXCMS_USER_TOKEN' => 'client-token',
        ));

        $this->assertSame(200, $result['data']['status']);
        $this->assertSame(4, $result['data']['data']['count']);
        $this->assertSame('theme', $result['data']['data']['entities'][0]['type']);
    }

    public function testEntitiesGetReadsScopeFromSafeGet(): void
    {
        $this->haxcms()->safeGet = array('scope' => 'site');

        $result = $this->runHandler('v1/entities', 'GET', array(), array(), array(
            'HTTP_X_HAXCMS_USER_TOKEN' => 'client-token',
        ));

        $this->assertSame(200, $result['data']['status']);
        $this->assertSame(2, $result['data']['data']['count']);
        $this->assertSame('file', $result['data']['data']['entities'][0]['type']);
    }

    public function testEntitiesGetFallsBackToGetScopeWhenParamNotString(): void
    {
        // A non-string scope in the merged params skips the params branch;
        // the $_GET fallback then supplies the scope.
        $_GET['scope'] = 'site';

        $result = $this->runHandler('v1/entities', 'GET', array('scope' => array('junk')));

        $this->assertSame(200, $result['data']['status']);
        $this->assertSame(2, $result['data']['data']['count']);
        $this->assertSame('file', $result['data']['data']['entities'][0]['type']);
    }

    // ------------------------------------------------------------------
    // v1/schemas + filter.kind
    // ------------------------------------------------------------------

    public function testSchemasGetReturnsFullCatalog(): void
    {
        $result = $this->runHandler('v1/schemas', 'GET', array(), array(), array(
            'HTTP_X_HAXCMS_USER_TOKEN' => 'client-token',
        ));

        $this->assertSame(200, $result['data']['status']);
        $this->assertSame(6, $result['data']['data']['count']);
        $schemas = $result['data']['data']['schemas'];
        $this->assertSame('json-outline-schema', $schemas[0]['id']);
        $this->assertSame('jsonOutlineSchema', $schemas[0]['kind']);
        $this->assertSame('/system/api/schemas', $result['data']['data']['links']['self']);
    }

    public function testSchemasGetFiltersByUnderscoredKindParam(): void
    {
        $result = $this->runHandler('v1/schemas', 'GET', array('filter_kind' => 'jsonOutlineSchemaItem'), array(), array(
            'HTTP_X_HAXCMS_USER_TOKEN' => 'client-token',
        ));

        $this->assertSame(200, $result['data']['status']);
        $this->assertSame(1, $result['data']['data']['count']);
        $this->assertSame('json-outline-schema-item', $result['data']['data']['schemas'][0]['id']);
    }

    public function testSchemasGetFiltersByDottedKindFromRawQueryString(): void
    {
        // PHP mangles filter.kind into filter_kind in $_GET; the route parses
        // the raw query string to support the dotted form.
        $_SERVER['QUERY_STRING'] = 'filter.kind=appStoreSchema';

        $result = $this->runHandler('v1/schemas', 'GET', array(), array(), array(
            'HTTP_X_HAXCMS_USER_TOKEN' => 'client-token',
        ));

        $this->assertSame(200, $result['data']['status']);
        $this->assertSame(1, $result['data']['data']['count']);
        $this->assertSame('app-store-schema', $result['data']['data']['schemas'][0]['id']);
    }

    // ------------------------------------------------------------------
    // v1/configuration/api-keys
    // ------------------------------------------------------------------

    public function testApiKeysGetReturnsNormalizedProviderMapForSuperUser(): void
    {
        $this->haxcms()->activeUserName = 'admin';

        $result = $this->runHandler('v1/configuration/api-keys', 'GET', array(), array(), array(
            'HTTP_X_HAXCMS_USER_TOKEN' => 'client-token',
        ));

        $this->assertSame(200, $result['data']['status']);
        $data = $result['data']['data'];
        $this->assertSame(
            array('youtube', 'vimeo', 'giphy', 'unsplash', 'flickr', 'anthropic'),
            array_keys($data)
        );
        $this->assertSame('', $data['youtube']);
        // api-keys GET feeds the client header token.
        $this->assertSame(array(array('client-token', 'admin')), $this->haxcms()->tokenCalls);
    }

    public function testApiKeysGetRejectsNonSuperUser(): void
    {
        $result = $this->runHandler('v1/configuration/api-keys', 'GET', array(), array(), array(
            'HTTP_X_HAXCMS_USER_TOKEN' => 'client-token',
        ));

        $this->assertSame(403, $result['data']['status']);
        $this->assertSame('Admin access required', $result['data']['data']['message']);
    }

    public function testApiKeysPatchSavesKeysAndPersistsFile(): void
    {
        $this->haxcms()->activeUserName = 'admin';

        $result = $this->runHandler('v1/configuration/api-keys', 'PATCH', array(
            'apiKeys' => array('youtube' => '  yt-key  '),
        ), array(), array(
            'HTTP_X_HAXCMS_USER_TOKEN' => 'client-token',
        ));

        $this->assertSame(200, $result['data']['status']);
        $this->assertSame('yt-key', $result['data']['data']['youtube']);
        $this->assertSame('', $result['data']['data']['vimeo']);
        $filePath = $this->configDir . '/settings/apiKeys.json';
        $this->assertFileExists($filePath);
        $stored = json_decode(file_get_contents($filePath), true);
        $this->assertSame('yt-key', $stored['youtube']);
    }

    public function testApiKeysPatchFailsWithMissingPayload(): void
    {
        $this->haxcms()->activeUserName = 'admin';

        $result = $this->runHandler('v1/configuration/api-keys', 'PATCH', array(), array(), array(
            'HTTP_X_HAXCMS_USER_TOKEN' => 'client-token',
        ));

        $this->assertSame(400, $result['data']['status']);
        $this->assertSame('Missing API key payload', $result['data']['data']['message']);
    }

    public function testApiKeysPostWithAbsentRequestMethodRestoresAbsence(): void
    {
        // haxcmsSystemSettingsInvokeAsPost restores absence (unset) when the
        // wrapped call ran without a REQUEST_METHOD.
        $this->haxcms()->activeUserName = 'admin';

        $result = $this->runHandler('v1/configuration/api-keys', 'POST', array(), array(), array(
            'REQUEST_METHOD' => null,
            'HTTP_X_HAXCMS_USER_TOKEN' => 'client-token',
        ));

        $this->assertSame(200, $result['data']['status']);
        $this->assertFalse(isset($_SERVER['REQUEST_METHOD']));
    }

    // ------------------------------------------------------------------
    // v1/configuration/media
    // ------------------------------------------------------------------

    public function testMediaSettingsGetReturnsEffectiveDefaults(): void
    {
        $result = $this->runHandler('v1/configuration/media', 'GET', array(), array(), array(
            'HTTP_X_HAXCMS_USER_TOKEN' => 'client-token',
        ));

        $this->assertSame(200, $result['data']['status']);
        $this->assertSame(80, $result['data']['data']['jpegQuality']);
        $this->assertSame(1024, $result['data']['data']['maxUploadSizeMb']);
        $this->assertSame('jpg,jpeg,png,gif,webp,svg', $result['data']['data']['acceptedFormats']);
    }

    public function testMediaSettingsPatchSavesAndPersists(): void
    {
        $result = $this->runHandler('v1/configuration/media', 'PATCH', array(
            'mediaSettings' => array('jpegQuality' => '95', 'acceptedFormats' => '.PNG, jpg'),
        ), array(), array(
            'HTTP_X_HAXCMS_USER_TOKEN' => 'client-token',
        ));

        $this->assertSame(200, $result['data']['status']);
        $this->assertSame(95, $result['data']['data']['jpegQuality']);
        $this->assertSame('png,jpg', $result['data']['data']['acceptedFormats']);
        $this->assertFileExists($this->configDir . '/settings/media.json');
    }

    public function testMediaSettingsPatchRejectsInvalidJpegQuality(): void
    {
        $result = $this->runHandler('v1/configuration/media', 'PATCH', array(
            'mediaSettings' => array('jpegQuality' => 'not-a-number'),
        ), array(), array(
            'HTTP_X_HAXCMS_USER_TOKEN' => 'client-token',
        ));

        $this->assertSame(400, $result['data']['status']);
        $this->assertSame('Invalid jpegQuality value', $result['data']['data']['message']);
    }

    // ------------------------------------------------------------------
    // v1/configuration/localization
    // ------------------------------------------------------------------

    public function testLocalizationGetReturnsDefaultLanguageWhenUnconfigured(): void
    {
        $result = $this->runHandler('v1/configuration/localization', 'GET', array(), array(), array(
            'HTTP_X_HAXCMS_USER_TOKEN' => 'client-token',
        ));

        $this->assertSame(200, $result['data']['status']);
        $this->assertSame('en-US', $result['data']['data']['defaultLanguage']);
    }

    public function testLocalizationPatchWritesConfigJsonAndSyncsRuntimeConfig(): void
    {
        file_put_contents($this->configDir . '/config.json', "{}\n");

        $result = $this->runHandler('v1/configuration/localization', 'PATCH', array(
            'localizationSettings' => array('defaultLanguage' => 'pt-br'),
        ), array(), array(
            'HTTP_X_HAXCMS_USER_TOKEN' => 'client-token',
        ));

        $this->assertSame(200, $result['data']['status']);
        $this->assertSame('pt-BR', $result['data']['data']['defaultLanguage']);
        $stored = json_decode(file_get_contents($this->configDir . '/config.json'), true);
        $this->assertSame('pt-BR', $stored['localization']['defaultLanguage']);
        // The route-level contract: in-memory config stays in sync.
        $this->assertSame('pt-BR', $this->haxcms()->config->localization->defaultLanguage);
    }

    public function testLocalizationPatchFailsWithoutConfigJson(): void
    {
        $result = $this->runHandler('v1/configuration/localization', 'PATCH', array(
            'localizationSettings' => array('defaultLanguage' => 'pt-BR'),
        ), array(), array(
            'HTTP_X_HAXCMS_USER_TOKEN' => 'client-token',
        ));

        $this->assertSame(500, $result['data']['status']);
        $this->assertSame('Unable to save localization settings', $result['data']['data']['message']);
    }

    public function testLocalizationPatchRejectsInvalidLanguage(): void
    {
        file_put_contents($this->configDir . '/config.json', "{}\n");

        $result = $this->runHandler('v1/configuration/localization', 'PATCH', array(
            'localizationSettings' => array('defaultLanguage' => 'nope!!'),
        ), array(), array(
            'HTTP_X_HAXCMS_USER_TOKEN' => 'client-token',
        ));

        $this->assertSame(400, $result['data']['status']);
        $this->assertSame('Invalid defaultLanguage value', $result['data']['data']['message']);
    }

    // ------------------------------------------------------------------
    // v1/configuration/schema-files/operations (multipart upload)
    // ------------------------------------------------------------------

    public function testSchemaFileOperationUploadWritesSkeletonFile(): void
    {
        $_POST = array('schema' => 'skeleton', 'action' => 'upload', 'name' => 'My Skel');
        $tmp = $this->makeUploadFile(json_encode(array('name' => 'My Skel', 'build' => array('pages' => array()))));
        $_FILES['file'] = array('tmp_name' => $tmp, 'name' => 'my-skel.json', 'error' => UPLOAD_ERR_OK);

        $result = $this->runHandler('v1/configuration/schema-files/operations', 'GET', array(), array(), array(
            'HTTP_X_HAXCMS_USER_TOKEN' => 'client-token',
        ));
        @unlink($tmp);

        $this->assertSame(200, $result['data']['status']);
        $data = $result['data']['data'];
        $this->assertSame('upload', $data['action']);
        $this->assertSame('my-skel', $data['machineName']);
        $this->assertSame('my-skel.json', $data['fileName']);
        $this->assertSame(basename($this->configDir) . '/user/skeletons/my-skel.json', $data['location']);
        $destination = $this->configDir . '/user/skeletons/my-skel.json';
        $this->assertFileExists($destination);
        $stored = json_decode(file_get_contents($destination), true);
        $this->assertSame('my-skel', $stored['meta']['machineName']);
        // InvokeAsPost restores the original method after the call.
        $this->assertSame('GET', $_SERVER['REQUEST_METHOD']);
    }

    public function testSchemaFileOperationUploadFailsWithoutFile(): void
    {
        $_POST = array('schema' => 'skeleton', 'action' => 'upload');

        $result = $this->runHandler('v1/configuration/schema-files/operations', 'GET', array(), array(), array(
            'HTTP_X_HAXCMS_USER_TOKEN' => 'client-token',
        ));

        $this->assertSame(400, $result['data']['status']);
        $this->assertSame('missing file upload', $result['data']['data']['message']);
    }

    public function testSchemaFileOperationUploadRejectsWrongExtension(): void
    {
        $_POST = array('schema' => 'skeleton', 'action' => 'upload');
        $tmp = $this->makeUploadFile('{}');
        $_FILES['file'] = array('tmp_name' => $tmp, 'name' => 'payload.txt', 'error' => UPLOAD_ERR_OK);

        $result = $this->runHandler('v1/configuration/schema-files/operations', 'GET', array(), array(), array(
            'HTTP_X_HAXCMS_USER_TOKEN' => 'client-token',
        ));
        @unlink($tmp);

        $this->assertSame(400, $result['data']['status']);
        $this->assertSame('invalid file type for schema skeleton; expected .json', $result['data']['data']['message']);
    }

    public function testSchemaFileOperationUploadRejectsInvalidJson(): void
    {
        $_POST = array('schema' => 'skeleton', 'action' => 'upload');
        $tmp = $this->makeUploadFile('not-json{{');
        $_FILES['file'] = array('tmp_name' => $tmp, 'name' => 'broken.json', 'error' => UPLOAD_ERR_OK);

        $result = $this->runHandler('v1/configuration/schema-files/operations', 'GET', array(), array(), array(
            'HTTP_X_HAXCMS_USER_TOKEN' => 'client-token',
        ));
        @unlink($tmp);

        $this->assertSame(400, $result['data']['status']);
        $this->assertSame('invalid skeleton json', $result['data']['data']['message']);
    }

    // ------------------------------------------------------------------
    // v1/blocks
    // ------------------------------------------------------------------

    public function testBlocksGetReturnsFlatListWrappedWithoutNestedStatus(): void
    {
        // Bearer-only route: the server-resolved token is what gets validated,
        // proving the header was NOT fed for this route family.
        $this->haxcms()->requestTokenUserName = '';

        $result = $this->runHandler('v1/blocks', 'GET');

        $this->assertSame(200, $result['data']['status']);
        $data = $result['data']['data'];
        $this->assertArrayNotHasKey('status', $data);
        $this->assertSame(array('grid-plate'), $data['autoloader']);
        $this->assertSame(array(), $data['enabledBlocks']);
        $this->assertSame(array(), $data['apps']);
        // Empty requestTokenUserName fell back to getActiveUserName when the
        // server token was minted.
        $this->assertSame(array('testuser'), $this->haxcms()->requestTokenCalls);
        $this->assertSame(array(array('server-token-for-testuser', 'testuser')), $this->haxcms()->tokenCalls);
    }

    public function testBlocksGetFiltersToEnabledOnlyWhenRequested(): void
    {
        $result = $this->runHandler('v1/blocks', 'GET', array('enabled' => 'true'));

        $this->assertSame(200, $result['data']['status']);
        $this->assertSame(array(), $result['data']['data']['autoloader']);
    }

    public function testBlocksPatchSavesEnabledBlocksAndListReadsThemBack(): void
    {
        $saved = $this->runHandler('v1/blocks', 'PATCH', array(
            'enabledBlocks' => array('Grid-Plate', 'grid-plate', 'x'),
        ), array(), array(
            'HTTP_X_HAXCMS_USER_TOKEN' => 'client-token',
        ));

        $this->assertSame(200, $saved['data']['status']);
        $this->assertSame(array('grid-plate', 'x'), $saved['data']['data']['enabledBlocks']);
        $this->assertFileExists($this->configDir . '/settings/enabledBlocks.json');

        $listed = $this->runHandler('v1/blocks', 'GET');
        $this->assertSame(array('grid-plate', 'x'), $listed['data']['data']['enabledBlocks']);
        $this->assertSame(array('grid-plate'), $listed['data']['data']['autoloader']);
    }

    public function testBlocksPatchRejectsInvalidTag(): void
    {
        $result = $this->runHandler('v1/blocks', 'PATCH', array(
            'enabledBlocks' => array('1bad'),
        ), array(), array(
            'HTTP_X_HAXCMS_USER_TOKEN' => 'client-token',
        ));

        $this->assertSame(400, $result['data']['status']);
        $this->assertSame('Invalid enabledBlocks payload', $result['data']['data']['message']);
    }

    // ------------------------------------------------------------------
    // v1/skeletons
    // ------------------------------------------------------------------

    public function testSkeletonsGetReturnsEmptyListWithNoSkeletonDirs(): void
    {
        $result = $this->runHandler('v1/skeletons', 'GET');

        $this->assertSame(200, $result['data']['status']);
        $this->assertSame(array(), $result['data']['data']);
    }

    public function testSkeletonsGetAndPostSucceedWithoutUserTokenHeader(): void
    {
        // haxtheweb/issues#3168 item 2: skeleton list reads are bearer-only.
        // No X-HAXCMS-User-Token is sent; the handler validates the
        // server-resolved token instead (NodeJS parity).
        unset($_SERVER['HTTP_X_HAXCMS_USER_TOKEN']);
        $this->assertFalse(haxcmsSystemSettingsRequiresUserTokenHeader('v1/skeletons', 'GET'));
        $this->assertFalse(haxcmsSystemSettingsRequiresUserTokenHeader('v1/skeletons', 'POST'));
        $this->assertFalse(haxcmsSystemSettingsRequiresUserTokenHeader('v1/skeletons/my-skel', 'GET'));
        // Writes still require the header.
        $this->assertTrue(haxcmsSystemSettingsRequiresUserTokenHeader('v1/skeletons', 'PATCH'));
        foreach (array('GET', 'POST') as $method) {
            $result = $this->runHandler('v1/skeletons', $method);
            $this->assertSame(200, $result['data']['status'], $method);
            $this->assertSame(array(), $result['data']['data'], $method);
        }
    }

    public function testSkeletonsGetListsDiscoveredSkeletonsAndPersistsDefaults(): void
    {
        $this->writeSkeletonFixture('my-skel', array(
            'meta' => array('useCaseTitle' => 'My Skel', 'useCaseDescription' => 'A test skeleton'),
        ));

        $result = $this->runHandler('v1/skeletons', 'GET');

        $this->assertSame(200, $result['data']['status']);
        $items = $result['data']['data'];
        $this->assertCount(1, $items);
        $this->assertSame('my-skel', $items[0]['machineName']);
        $this->assertSame('my-skel', $items[0]['machine-name']);
        $this->assertSame('My Skel', $items[0]['title']);
        $this->assertTrue($items[0]['enabled']);
        $this->assertStringContainsString('/v1/skeletons/my-skel', $items[0]['skeleton-url']);
        // applyDetectedSkeletonDefaults persisted the new default.
        $this->assertFileExists($this->configDir . '/settings/enabledSkeletons.json');
    }

    public function testSkeletonsGetExcludesDisabledUnlessRequested(): void
    {
        $this->writeSkeletonFixture('my-skel');
        @mkdir($this->configDir . '/settings', 0777, true);
        file_put_contents(
            $this->configDir . '/settings/enabledSkeletons.json',
            json_encode(array('enabledSkeletons' => array('my-skel' => false)))
        );

        $filtered = $this->runHandler('v1/skeletons', 'GET');
        $this->assertSame(200, $filtered['data']['status']);
        $this->assertSame(array(), $filtered['data']['data']);

        $included = $this->runHandler('v1/skeletons', 'POST', array('includeDisabled' => 'true'));
        $this->assertSame(200, $included['data']['status']);
        $this->assertCount(1, $included['data']['data']);
        $this->assertFalse($included['data']['data'][0]['enabled']);
    }

    public function testSkeletonsPatchSavesEnabledSkeletonsMap(): void
    {
        $result = $this->runHandler('v1/skeletons', 'PATCH', array(
            'enabledSkeletons' => array('My Skel'),
        ), array(), array(
            'HTTP_X_HAXCMS_USER_TOKEN' => 'client-token',
        ));

        $this->assertSame(200, $result['data']['status']);
        $this->assertSame(array('my-skel'), $result['data']['data']['enabledSkeletons']);
        $this->assertSame(array('my-skel' => true), $result['data']['data']['settings']);
        $stored = json_decode(file_get_contents($this->configDir . '/settings/enabledSkeletons.json'), true);
        $this->assertSame(array('my-skel' => true), $stored['enabledSkeletons']);
    }

    // ------------------------------------------------------------------
    // v1/skeletons/:skeletonName
    // ------------------------------------------------------------------

    public function testSkeletonNameGetReturnsSkeletonDefinition(): void
    {
        $this->writeSkeletonFixture('my-skel', array(
            'name' => 'My Skel',
        ));

        $result = $this->runHandler('v1/skeletons/my-skel', 'GET', array(), array(
            'skeletonName' => 'my-skel',
        ));

        $this->assertSame(200, $result['data']['status']);
        $this->assertSame('My Skel', $result['data']['data']['name']);
    }

    public function testSkeletonNameGetReturns404WhenMissing(): void
    {
        $result = $this->runHandler('v1/skeletons/does-not-exist', 'GET', array(), array(
            'skeletonName' => 'does-not-exist',
        ));

        $this->assertSame(404, $result['data']['status']);
        $this->assertSame('skeleton not found', $result['data']['data']['message']);
    }

    public function testSkeletonNamePatchDefaultsToRenameAndMovesFile(): void
    {
        $original = $this->writeSkeletonFixture('my-skel');

        $result = $this->runHandler('v1/skeletons/my-skel', 'PATCH', array(
            'newName' => 'Renamed Skel',
        ), array('skeletonName' => 'my-skel'));

        $this->assertSame(200, $result['data']['status']);
        $data = $result['data']['data'];
        $this->assertSame('rename', $data['action']);
        $this->assertSame('renamed-skel', $data['machineName']);
        $this->assertSame('renamed-skel.json', $data['fileName']);
        $nextPath = $this->configDir . '/user/skeletons/renamed-skel.json';
        $this->assertFileExists($nextPath);
        $this->assertFileDoesNotExist($original);
        $stored = json_decode(file_get_contents($nextPath), true);
        $this->assertSame('renamed-skel', $stored['meta']['machineName']);
    }

    public function testSkeletonNamePutRejectsNonRenameAction(): void
    {
        $this->writeSkeletonFixture('my-skel');

        $result = $this->runHandler('v1/skeletons/my-skel', 'PUT', array(
            'action' => 'delete',
        ), array('skeletonName' => 'my-skel'));

        $this->assertSame(400, $result['data']['status']);
        $this->assertSame('only rename is allowed for PATCH/PUT on skeletons', $result['data']['data']['message']);
    }

    public function testSkeletonNameDeleteRemovesFile(): void
    {
        $original = $this->writeSkeletonFixture('my-skel');

        $result = $this->runHandler('v1/skeletons/my-skel', 'DELETE', array(), array(
            'skeletonName' => 'my-skel',
        ));

        $this->assertSame(200, $result['data']['status']);
        $this->assertSame('delete', $result['data']['data']['action']);
        $this->assertSame('my-skel', $result['data']['data']['machineName']);
        $this->assertFileDoesNotExist($original);
    }

    public function testSkeletonNameDeleteRejectsRenameAction(): void
    {
        $this->writeSkeletonFixture('my-skel');

        $result = $this->runHandler('v1/skeletons/my-skel', 'DELETE', array(
            'action' => 'rename',
        ), array('skeletonName' => 'my-skel'));

        $this->assertSame(400, $result['data']['status']);
        $this->assertSame('only delete is allowed for DELETE on skeletons', $result['data']['data']['message']);
    }

    public function testSkeletonNameHeadReturnsMethodNotAllowed(): void
    {
        $result = $this->runHandler('v1/skeletons/my-skel', 'HEAD', array(), array(
            'skeletonName' => 'my-skel',
        ));

        $this->assertSame(405, $result['data']['status']);
        $this->assertSame('Method not allowed', $result['data']['data']);
    }

    // ------------------------------------------------------------------
    // v1/themes
    // ------------------------------------------------------------------

    public function testThemesGetListsRegistryThemesAndPersistsDefaults(): void
    {
        $result = $this->runHandler('v1/themes', 'GET');

        $this->assertSame(200, $result['data']['status']);
        $items = $result['data']['data'];
        $this->assertCount(1, $items);
        $this->assertSame('clean-two', $items[0]['machineName']);
        $this->assertSame('clean-two', $items[0]['element']);
        $this->assertTrue($items[0]['enabled']);
        $this->assertFalse($items[0]['hidden']);
        $this->assertFileExists($this->configDir . '/settings/enabledThemes.json');
        // themes reads are bearer-only: the server-resolved token is validated.
        $this->assertSame(array(array('server-token-for-testuser', 'testuser')), $this->haxcms()->tokenCalls);
    }

    public function testThemesPostAlsoLists(): void
    {
        $result = $this->runHandler('v1/themes', 'POST');

        $this->assertSame(200, $result['data']['status']);
        $this->assertCount(1, $result['data']['data']);
        $this->assertSame('clean-two', $result['data']['data'][0]['machineName']);
    }

    public function testThemesPatchSavesEnabledThemes(): void
    {
        $result = $this->runHandler('v1/themes', 'PATCH', array(
            'enabledThemes' => array('clean-two'),
        ), array(), array(
            'HTTP_X_HAXCMS_USER_TOKEN' => 'client-token',
        ));

        $this->assertSame(200, $result['data']['status']);
        $this->assertSame(array('clean-two'), $result['data']['data']['enabledThemes']);
        $this->assertSame(array('clean-two' => true), $result['data']['data']['settings']);
    }

    // ------------------------------------------------------------------
    // Unknown route + envelope guards
    // ------------------------------------------------------------------

    public function testUnknownRouteReturns404WithDefaultApiBasePath(): void
    {
        // apiBasePath = null exercises the route's isset fallback to
        // '/system/api' before the 404 is emitted.
        $result = $this->runHandler('v1/bogus', 'GET', array(), array(), array(), null);

        $this->assertSame(404, $result['data']['status']);
        $this->assertSame('Unknown settings route', $result['data']['data']['message']);
    }
}
