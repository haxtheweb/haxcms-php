<?php
trait OperationsRouteGetSkeleton {
  private function normalizeSkeletonLookupName($value)
  {
    if (!is_string($value)) {
      return '';
    }
    $safeValue = basename($value);
    $safeValue = preg_replace('/\\.json$/i', '', $safeValue);
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
  /**
   * Get a specific skeleton file by name.
   * Returns the skeleton JSON data.
   * Requires a valid user_token.
   *
   * @OA\Get(
   *    path="/getSkeleton",
   *    tags={"cms"},
   *    @OA\Parameter(
   *         name="name",
   *         description="Skeleton file name (without .json extension)",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Returns skeleton JSON data"
   *   )
   * )
   */
  public function getSkeleton() {
    // Validate user_token
    if (!isset($this->params['user_token']) || !$GLOBALS['HAXCMS']->validateRequestToken($this->params['user_token'], $GLOBALS['HAXCMS']->getActiveUserName())) {
      // Debug info to help track down token mismatches in local/dev setups.
      $activeUser = $GLOBALS['HAXCMS']->getActiveUserName();
      $expectedToken = $GLOBALS['HAXCMS']->getRequestToken($activeUser);
      $providedToken = isset($this->params['user_token']) ? $this->params['user_token'] : null;
      $debug = array(
        'activeUserName' => $activeUser,
        'hasUserToken' => isset($this->params['user_token']),
        'providedToken' => $providedToken,
        'expectedToken' => $expectedToken,
        // helpful to see what params made it this far
        'paramKeys' => array_keys($this->params),
      );
      // Log to PHP error log for backend inspection.
      error_log('HAXCMS getSkeleton token failure: ' . json_encode($debug));

      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid request token',
          'debug' => $debug,
        )
      );
    }

    if (!isset($this->params['name'])) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'skeleton name is required',
        )
      );
    }

    $normalizedTarget = $this->normalizeSkeletonLookupName($this->params['name']);
    if ($normalizedTarget === '') {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'invalid skeleton name',
        )
      );
    }

    // directories to search for skeleton files
    $dirs = $this->getSkeletonDirectories();

    // Search for the skeleton file
    foreach ($dirs as $dir) {
      if (!($handle = opendir($dir))) {
        continue;
      }
      while (false !== ($file = readdir($handle))) {
        if ($file === '.' || $file === '..') { continue; }
        $filePath = $dir . '/' . $file;
        if (!is_file($filePath) || strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) !== 'json') {
          continue;
        }
        $json = @file_get_contents($filePath);
        $skeleton = json_decode($json);
        if (!is_object($skeleton)) {
          continue;
        }
        $normalizedFileName = $this->normalizeSkeletonLookupName(
          pathinfo($file, PATHINFO_FILENAME)
        );
        $normalizedMetaMachineName = '';
        $normalizedMetaName = '';
        if (isset($skeleton->meta) && is_object($skeleton->meta)) {
          if (isset($skeleton->meta->machineName)) {
            $normalizedMetaMachineName = $this->normalizeSkeletonLookupName(
              $skeleton->meta->machineName
            );
          }
          if (isset($skeleton->meta->name)) {
            $normalizedMetaName = $this->normalizeSkeletonLookupName(
              $skeleton->meta->name
            );
          }
        }
        if (
          $normalizedTarget === $normalizedFileName ||
          ($normalizedMetaMachineName !== '' &&
            $normalizedTarget === $normalizedMetaMachineName) ||
          ($normalizedMetaName !== '' &&
            $normalizedTarget === $normalizedMetaName)
        ) {
          closedir($handle);
          return array(
            'status' => 200,
            'data' => $skeleton
          );
        }
      }
      closedir($handle);
    }

    return array(
      '__failed' => array(
        'status' => 404,
        'message' => 'skeleton not found',
      )
    );
  }
}
