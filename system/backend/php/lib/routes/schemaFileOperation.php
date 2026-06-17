<?php
trait OperationsRouteSchemaFileOperation {
  private function schemaOperationConfigs()
  {
    return array(
      'skeleton' => array(
        'directory' => 'skeletons',
        'extension' => 'json',
      ),
    );
  }

  private function schemaOperationFail($status = 400, $message = 'invalid request')
  {
    return array(
      '__failed' => array(
        'status' => $status,
        'message' => $message,
      )
    );
  }

  private function schemaOperationParam($key, $default = '')
  {
    if (
      isset($this->params) &&
      is_array($this->params) &&
      array_key_exists($key, $this->params) &&
      $this->params[$key] !== null
    ) {
      return $this->params[$key];
    }
    return $default;
  }

  private function normalizeSchemaOperationValue($value)
  {
    if (!is_string($value)) {
      return '';
    }
    return strtolower(trim($value));
  }

  private function normalizeSchemaOperationMachineName($value)
  {
    if (!is_string($value)) {
      return '';
    }
    $safeValue = basename($value);
    $safeValue = preg_replace('/\\.[^\\/\\.]+$/', '', $safeValue);
    $safeValue = trim($safeValue);
    if ($safeValue === '') {
      return '';
    }
    $normalized = $GLOBALS['HAXCMS']->generateMachineName($safeValue);
    if (
      !is_string($normalized) ||
      $normalized === '' ||
      ($normalized === 'default' && strtolower($safeValue) !== 'default')
    ) {
      return '';
    }
    return $normalized;
  }

  private function schemaOperationDirectory($schema, $config)
  {
    return $GLOBALS['HAXCMS']->configDirectory . '/user/' . $config['directory'];
  }

  private function schemaOperationRelativeLocation($schema, $config, $fileName)
  {
    $configDirectoryName = basename($GLOBALS['HAXCMS']->configDirectory);
    return $configDirectoryName . '/user/' . $config['directory'] . '/' . $fileName;
  }

  private function schemaOperationResolveExistingFile($schema, $config, $name)
  {
    $machineName = $this->normalizeSchemaOperationMachineName($name);
    if ($machineName === '') {
      return null;
    }
    $schemaDirectory = $this->schemaOperationDirectory($schema, $config);
    if (!is_dir($schemaDirectory)) {
      return null;
    }
    if (!($handle = opendir($schemaDirectory))) {
      return null;
    }
    while (false !== ($fileName = readdir($handle))) {
      if ($fileName === '.' || $fileName === '..') {
        continue;
      }
      $filePath = $schemaDirectory . '/' . $fileName;
      if (!is_file($filePath)) {
        continue;
      }
      if (strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) !== $config['extension']) {
        continue;
      }
      $fileMachineName = $this->normalizeSchemaOperationMachineName(
        pathinfo($fileName, PATHINFO_FILENAME)
      );
      if ($fileMachineName === $machineName) {
        closedir($handle);
        return array(
          'machineName' => $machineName,
          'fileName' => $fileName,
          'filePath' => $filePath,
          'schemaDirectory' => $schemaDirectory,
        );
      }
    }
    closedir($handle);
    return null;
  }

  private function schemaOperationUpdateSkeletonMetaMachineName($filePath, $machineName)
  {
    $raw = @file_get_contents($filePath);
    if (!is_string($raw) || $raw === '') {
      return;
    }
    $parsed = json_decode($raw, true);
    if (!is_array($parsed)) {
      return;
    }
    if (!isset($parsed['meta']) || !is_array($parsed['meta'])) {
      $parsed['meta'] = array();
    }
    $parsed['meta']['machineName'] = $machineName;
    @file_put_contents(
      $filePath,
      json_encode($parsed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
    );
  }

  /**
   * @OA\Post(
   *    path="/schemaFileOperation",
   *    tags={"cms","authenticated","settings"},
   *    @OA\Response(
   *        response="200",
   *        description="Perform skeleton file upload, rename, or delete operations under the user config directory"
   *   )
   * )
   */
  public function schemaFileOperation()
  {
    if (
      !isset($_SERVER['REQUEST_METHOD']) ||
      strtoupper((string) $_SERVER['REQUEST_METHOD']) !== 'POST'
    ) {
      return $this->schemaOperationFail(405, 'method not allowed');
    }
    if (
      !isset($this->params['user_token']) ||
      !$GLOBALS['HAXCMS']->validateRequestToken(
        $this->params['user_token'],
        $GLOBALS['HAXCMS']->getActiveUserName()
      )
    ) {
      return $this->schemaOperationFail(403, 'invalid request token');
    }
    $schema = $this->normalizeSchemaOperationValue(
      $this->schemaOperationParam('schema', '')
    );
    if ($schema === '') {
      $schema = 'skeleton';
    }
    if ($schema === 'skeletons') {
      $schema = 'skeleton';
    }
    $schemaConfigs = $this->schemaOperationConfigs();
    if (!array_key_exists($schema, $schemaConfigs)) {
      return $this->schemaOperationFail(400, 'invalid schema');
    }
    $config = $schemaConfigs[$schema];
    $action = $this->normalizeSchemaOperationValue(
      $this->schemaOperationParam('action', '')
    );
    if ($action !== 'upload' && $action !== 'rename' && $action !== 'delete') {
      return $this->schemaOperationFail(400, 'invalid action');
    }
    $schemaDirectory = $this->schemaOperationDirectory($schema, $config);
    if ($action === 'upload') {
      if (!isset($_FILES['file'])) {
        return $this->schemaOperationFail(400, 'missing file upload');
      }
      $upload = $_FILES['file'];
      if (
        !isset($upload['tmp_name']) ||
        !isset($upload['name']) ||
        !isset($upload['error']) ||
        (int) $upload['error'] !== UPLOAD_ERR_OK
      ) {
        return $this->schemaOperationFail(400, 'invalid file upload');
      }
      $extension = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
      if ($extension !== $config['extension']) {
        return $this->schemaOperationFail(
          400,
          'invalid file type for schema ' . $schema . '; expected .' . $config['extension']
        );
      }
      $machineName = $this->normalizeSchemaOperationMachineName(
        $this->schemaOperationParam('name', '')
      );
      if ($machineName === '') {
        $machineName = $this->normalizeSchemaOperationMachineName($upload['name']);
      }
      if ($machineName === '') {
        return $this->schemaOperationFail(400, 'invalid upload name');
      }
      if (!is_dir($schemaDirectory)) {
        @mkdir($schemaDirectory, 0755, true);
      }
      if (!is_dir($schemaDirectory)) {
        return $this->schemaOperationFail(500, 'failed to prepare schema directory');
      }
      $fileName = $machineName . '.' . $config['extension'];
      $destinationPath = $schemaDirectory . '/' . $fileName;
      if (file_exists($destinationPath)) {
        return $this->schemaOperationFail(409, 'file already exists');
      }
      $raw = @file_get_contents($upload['tmp_name']);
      $parsed = json_decode($raw, true);
      if (!is_array($parsed)) {
        return $this->schemaOperationFail(400, 'invalid skeleton json');
      }
      if (!isset($parsed['meta']) || !is_array($parsed['meta'])) {
        $parsed['meta'] = array();
      }
      $parsed['meta']['machineName'] = $machineName;
      $saved = @file_put_contents(
        $destinationPath,
        json_encode($parsed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
      );
      if ($saved === false) {
        return $this->schemaOperationFail(500, 'failed to save uploaded file');
      }
      return array(
        'status' => 200,
        'data' => array(
          'action' => $action,
          'schema' => $schema,
          'machineName' => $machineName,
          'fileName' => $fileName,
          'location' => $this->schemaOperationRelativeLocation($schema, $config, $fileName),
          'path' => $destinationPath,
        ),
      );
    }
    if ($action === 'rename') {
      $existing = $this->schemaOperationResolveExistingFile(
        $schema,
        $config,
        $this->schemaOperationParam('name', $this->schemaOperationParam('oldName', ''))
      );
      if (is_null($existing)) {
        return $this->schemaOperationFail(404, 'file not found');
      }
      $nextMachineName = $this->normalizeSchemaOperationMachineName(
        $this->schemaOperationParam('newName', '')
      );
      if ($nextMachineName === '') {
        return $this->schemaOperationFail(400, 'invalid new name');
      }
      if ($nextMachineName === $existing['machineName']) {
        return $this->schemaOperationFail(400, 'new name must be different');
      }
      $nextFileName = $nextMachineName . '.' . $config['extension'];
      $nextPath = $existing['schemaDirectory'] . '/' . $nextFileName;
      if (file_exists($nextPath)) {
        return $this->schemaOperationFail(409, 'file already exists');
      }
      if (!@rename($existing['filePath'], $nextPath)) {
        return $this->schemaOperationFail(500, 'failed to rename file');
      }
      $this->schemaOperationUpdateSkeletonMetaMachineName($nextPath, $nextMachineName);
      return array(
        'status' => 200,
        'data' => array(
          'action' => $action,
          'schema' => $schema,
          'machineName' => $nextMachineName,
          'fileName' => $nextFileName,
          'location' => $this->schemaOperationRelativeLocation($schema, $config, $nextFileName),
          'path' => $nextPath,
        ),
      );
    }
    $existing = $this->schemaOperationResolveExistingFile(
      $schema,
      $config,
      $this->schemaOperationParam('name', $this->schemaOperationParam('oldName', ''))
    );
    if (is_null($existing)) {
      return $this->schemaOperationFail(404, 'file not found');
    }
    if (!@unlink($existing['filePath'])) {
      return $this->schemaOperationFail(500, 'failed to delete file');
    }
    return array(
      'status' => 200,
      'data' => array(
        'action' => $action,
        'schema' => $schema,
        'machineName' => $existing['machineName'],
        'fileName' => $existing['fileName'],
        'location' => $this->schemaOperationRelativeLocation($schema, $config, $existing['fileName']),
        'path' => $existing['filePath'],
      ),
    );
  }
}
