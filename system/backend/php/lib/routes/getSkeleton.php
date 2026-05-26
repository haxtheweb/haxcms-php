<?php
trait OperationsRouteGetSkeleton {
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

    // Sanitize the skeleton name to prevent directory traversal
    $safeName = basename($this->params['name']);
    $fileName = (substr($safeName, -5) === '.json') ? $safeName : $safeName . '.json';

    // directories to search for skeleton files
    $dirs = $this->getSkeletonDirectories();

    // Search for the skeleton file
    foreach ($dirs as $dir) {
      $filePath = $dir . '/' . $fileName;
      
      if (file_exists($filePath)) {
        $json = @file_get_contents($filePath);
        $skeleton = json_decode($json);
        
        if ($skeleton === null) {
          return array(
            '__failed' => array(
              'status' => 500,
              'message' => 'Failed to parse skeleton file',
            )
          );
        }
        
        return array(
          'status' => 200,
          'data' => $skeleton
        );
      }
    }

    return array(
      '__failed' => array(
        'status' => 404,
        'message' => 'skeleton not found',
      )
    );
  }
}
