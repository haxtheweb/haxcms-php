<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Characterization tests for the OperationsRouteSchemaFileOperation trait
 * (lib/operations/schemaFileOperation.php).
 *
 * Tests the schemaFileOperation() entrypoint which handles upload,
 * rename, and delete operations on skeleton JSON files in the user
 * config directory.
 *
 * Gate tests cover: method not allowed, invalid token, invalid schema,
 * invalid action, method-action mismatch. Happy paths verify actual
 * filesystem mutations (upload/rename/delete) against a temp config
 * directory with real skeleton JSON files.
 */
class OperationsSchemaFileOperationTest extends TestCase
{
    private $haxcms;
    private $ops;
    private $savedHaxcms;
    private $savedServerSoftware;
    private $savedRequestMethod;
    private $savedFiles;
    private $tmpRoot;
    private $skeletonsDir;

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        if (isset($_SERVER['SERVER_SOFTWARE'])) {
            $this->savedServerSoftware = $_SERVER['SERVER_SOFTWARE'];
        }
        if (isset($_SERVER['REQUEST_METHOD'])) {
            $this->savedRequestMethod = $_SERVER['REQUEST_METHOD'];
        }
        if (isset($_FILES)) {
            $this->savedFiles = $_FILES;
        }
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->tmpRoot = sys_get_temp_dir() . '/haxcms_schema_' . uniqid();
        $this->skeletonsDir = $this->tmpRoot . '/user/skeletons';
        mkdir($this->skeletonsDir, 0777, true);

        $this->haxcms = new SchemaOpsTestHaxcms();
        $this->haxcms->configDirectory = $this->tmpRoot;
        $GLOBALS['HAXCMS'] = $this->haxcms;

        $this->ops = new Operations();
        $this->ops->params = array();
        $this->ops->rawParams = array();
    }

    protected function tearDown(): void
    {
        if (isset($this->savedHaxcms)) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
            $this->savedHaxcms = null;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
        if (isset($this->savedServerSoftware)) {
            $_SERVER['SERVER_SOFTWARE'] = $this->savedServerSoftware;
            $this->savedServerSoftware = null;
        } else {
            unset($_SERVER['SERVER_SOFTWARE']);
        }
        if (isset($this->savedRequestMethod)) {
            $_SERVER['REQUEST_METHOD'] = $this->savedRequestMethod;
            $this->savedRequestMethod = null;
        } else {
            unset($_SERVER['REQUEST_METHOD']);
        }
        if (isset($this->savedFiles)) {
            $_FILES = $this->savedFiles;
            $this->savedFiles = null;
        } else {
            $_FILES = array();
        }
        $this->rrmdir($this->tmpRoot);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * Create a skeleton JSON file in the skeletons directory.
     */
    private function createSkeletonFile(string $machineName, array $data = array()): string
    {
        $fileName = $machineName . '.json';
        $filePath = $this->skeletonsDir . '/' . $fileName;
        $payload = array_merge(
            array('meta' => array('machineName' => $machineName)),
            $data
        );
        file_put_contents($filePath, json_encode($payload, JSON_PRETTY_PRINT));
        return $filePath;
    }

    /**
     * Create a temp file with valid skeleton JSON for upload tests.
     */
    private function createUploadFile(string $content): array
    {
        $tmpPath = sys_get_temp_dir() . '/haxcms_upload_' . uniqid() . '.json';
        file_put_contents($tmpPath, $content);
        return array(
            'name' => 'test-skeleton.json',
            'type' => 'application/json',
            'tmp_name' => $tmpPath,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($content),
        );
    }

    // =========================================================================
    // Method gate
    // =========================================================================

    public function testSchemaFileOperationMethodNotAllowedReturns405(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->ops->params = array(
            'user_token' => 'good',
            'action' => 'delete',
        );
        $result = $this->ops->schemaFileOperation();
        $this->assertSame(405, $result['__failed']['status']);
        $this->assertSame('method not allowed', $result['__failed']['message']);
    }

    // =========================================================================
    // Token gate
    // =========================================================================

    public function testSchemaFileOperationMissingTokenReturns403(): void
    {
        $this->ops->params = array(
            'action' => 'delete',
            'name' => 'test',
        );
        $result = $this->ops->schemaFileOperation();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid request token', $result['__failed']['message']);
    }

    public function testSchemaFileOperationInvalidTokenReturns403(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array(
            'user_token' => 'bad',
            'action' => 'delete',
            'name' => 'test',
        );
        $result = $this->ops->schemaFileOperation();
        $this->assertSame(403, $result['__failed']['status']);
    }

    // =========================================================================
    // Schema and action validation
    // =========================================================================

    public function testSchemaFileOperationInvalidSchemaReturns400(): void
    {
        $this->ops->params = array(
            'user_token' => 'good',
            'schema' => 'bogus',
            'action' => 'delete',
            'name' => 'test',
        );
        $result = $this->ops->schemaFileOperation();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('invalid schema', $result['__failed']['message']);
    }

    public function testSchemaFileOperationInvalidActionReturns400(): void
    {
        $this->ops->params = array(
            'user_token' => 'good',
            'action' => 'frobnicate',
            'name' => 'test',
        );
        $result = $this->ops->schemaFileOperation();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('invalid action', $result['__failed']['message']);
    }

    // =========================================================================
    // Method-action consistency
    // =========================================================================

    public function testSchemaFileOperationPatchWithNonRenameReturns400(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        $this->ops->params = array(
            'user_token' => 'good',
            'action' => 'delete',
            'name' => 'test',
        );
        $result = $this->ops->schemaFileOperation();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertStringContainsString('only rename is allowed', $result['__failed']['message']);
    }

    public function testSchemaFileOperationDeleteWithNonDeleteReturns400(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $this->ops->params = array(
            'user_token' => 'good',
            'action' => 'upload',
        );
        $result = $this->ops->schemaFileOperation();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertStringContainsString('only delete is allowed', $result['__failed']['message']);
    }

    // =========================================================================
    // upload — happy path
    // =========================================================================

    public function testSchemaFileOperationUploadCreatesFileAndReturns200(): void
    {
        $skeletonJson = json_encode(array(
            'meta' => array('name' => 'Test Skeleton'),
            'site' => array('name' => 'test-skeleton'),
        ));
        $_FILES['file'] = $this->createUploadFile($skeletonJson);

        $this->ops->params = array(
            'user_token' => 'good',
            'action' => 'upload',
            'name' => 'my-skeleton',
        );
        $result = $this->ops->schemaFileOperation();
        $this->assertSame(200, $result['status']);
        $this->assertSame('my-skeleton', $result['data']['machineName']);
        $this->assertSame('my-skeleton.json', $result['data']['fileName']);

        // Verify file exists on disk
        $filePath = $this->skeletonsDir . '/my-skeleton.json';
        $this->assertTrue(file_exists($filePath));
        $saved = json_decode(file_get_contents($filePath), true);
        $this->assertSame('my-skeleton', $saved['meta']['machineName']);
    }

    // =========================================================================
    // upload — missing file
    // =========================================================================

    public function testSchemaFileOperationUploadMissingFileReturns400(): void
    {
        $_FILES = array();
        $this->ops->params = array(
            'user_token' => 'good',
            'action' => 'upload',
            'name' => 'my-skeleton',
        );
        $result = $this->ops->schemaFileOperation();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('missing file upload', $result['__failed']['message']);
    }

    // =========================================================================
    // upload — invalid extension
    // =========================================================================

    public function testSchemaFileOperationUploadInvalidExtensionReturns400(): void
    {
        $tmpPath = sys_get_temp_dir() . '/haxcms_upload_' . uniqid() . '.txt';
        file_put_contents($tmpPath, 'not json');
        $_FILES['file'] = array(
            'name' => 'test.txt',
            'type' => 'text/plain',
            'tmp_name' => $tmpPath,
            'error' => UPLOAD_ERR_OK,
            'size' => 8,
        );
        $this->ops->params = array(
            'user_token' => 'good',
            'action' => 'upload',
            'name' => 'my-skeleton',
        );
        $result = $this->ops->schemaFileOperation();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertStringContainsString('invalid file type', $result['__failed']['message']);
        @unlink($tmpPath);
    }

    // =========================================================================
    // upload — invalid JSON
    // =========================================================================

    public function testSchemaFileOperationUploadInvalidJsonReturns400(): void
    {
        $_FILES['file'] = $this->createUploadFile('not valid json {{{');
        $this->ops->params = array(
            'user_token' => 'good',
            'action' => 'upload',
            'name' => 'my-skeleton',
        );
        $result = $this->ops->schemaFileOperation();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('invalid skeleton json', $result['__failed']['message']);
    }

    // =========================================================================
    // upload — file already exists
    // =========================================================================

    public function testSchemaFileOperationUploadExistingFileReturns409(): void
    {
        $this->createSkeletonFile('existing-skeleton');
        $skeletonJson = json_encode(array('meta' => array('name' => 'Test')));
        $_FILES['file'] = $this->createUploadFile($skeletonJson);
        $this->ops->params = array(
            'user_token' => 'good',
            'action' => 'upload',
            'name' => 'existing-skeleton',
        );
        $result = $this->ops->schemaFileOperation();
        $this->assertSame(409, $result['__failed']['status']);
        $this->assertSame('file already exists', $result['__failed']['message']);
    }

    // =========================================================================
    // rename — happy path
    // =========================================================================

    public function testSchemaFileOperationRenameMovesFileAndUpdatesMeta(): void
    {
        $this->createSkeletonFile('old-name');
        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        $this->ops->params = array(
            'user_token' => 'good',
            'action' => 'rename',
            'name' => 'old-name',
            'newName' => 'new-name',
        );
        $result = $this->ops->schemaFileOperation();
        $this->assertSame(200, $result['status']);
        $this->assertSame('new-name', $result['data']['machineName']);
        $this->assertSame('new-name.json', $result['data']['fileName']);

        // Old file gone, new file exists with updated meta
        $this->assertFalse(file_exists($this->skeletonsDir . '/old-name.json'));
        $this->assertTrue(file_exists($this->skeletonsDir . '/new-name.json'));
        $saved = json_decode(file_get_contents($this->skeletonsDir . '/new-name.json'), true);
        $this->assertSame('new-name', $saved['meta']['machineName']);
    }

    // =========================================================================
    // rename — file not found
    // =========================================================================

    public function testSchemaFileOperationRenameFileNotFoundReturns404(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        $this->ops->params = array(
            'user_token' => 'good',
            'action' => 'rename',
            'name' => 'nonexistent',
            'newName' => 'something',
        );
        $result = $this->ops->schemaFileOperation();
        $this->assertSame(404, $result['__failed']['status']);
        $this->assertSame('file not found', $result['__failed']['message']);
    }

    // =========================================================================
    // rename — same name
    // =========================================================================

    public function testSchemaFileOperationRenameSameNameReturns400(): void
    {
        $this->createSkeletonFile('same-name');
        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        $this->ops->params = array(
            'user_token' => 'good',
            'action' => 'rename',
            'name' => 'same-name',
            'newName' => 'same-name',
        );
        $result = $this->ops->schemaFileOperation();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('new name must be different', $result['__failed']['message']);
    }

    // =========================================================================
    // rename — target file exists
    // =========================================================================

    public function testSchemaFileOperationRenameTargetExistsReturns409(): void
    {
        $this->createSkeletonFile('source-name');
        $this->createSkeletonFile('target-name');
        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        $this->ops->params = array(
            'user_token' => 'good',
            'action' => 'rename',
            'name' => 'source-name',
            'newName' => 'target-name',
        );
        $result = $this->ops->schemaFileOperation();
        $this->assertSame(409, $result['__failed']['status']);
    }

    // =========================================================================
    // delete — happy path
    // =========================================================================

    public function testSchemaFileOperationDeleteRemovesFileAndReturns200(): void
    {
        $filePath = $this->createSkeletonFile('doomed-skeleton');
        $this->assertTrue(file_exists($filePath));
        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $this->ops->params = array(
            'user_token' => 'good',
            'action' => 'delete',
            'name' => 'doomed-skeleton',
        );
        $result = $this->ops->schemaFileOperation();
        $this->assertSame(200, $result['status']);
        $this->assertSame('doomed-skeleton', $result['data']['machineName']);
        $this->assertFalse(file_exists($filePath), 'File deleted from disk');
    }

    // =========================================================================
    // delete — file not found
    // =========================================================================

    public function testSchemaFileOperationDeleteFileNotFoundReturns404(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $this->ops->params = array(
            'user_token' => 'good',
            'action' => 'delete',
            'name' => 'nonexistent',
        );
        $result = $this->ops->schemaFileOperation();
        $this->assertSame(404, $result['__failed']['status']);
        $this->assertSame('file not found', $result['__failed']['message']);
    }
}

/**
 * HAXCMS mock for schema file operation tests. Adds generateMachineName
 * which is required by the schema operation normalization.
 */
class SchemaOpsTestHaxcms extends OperationsTestHaxcms
{
    public function generateMachineName($name)
    {
        $name = str_replace(chr(0), '', $name);
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
}
