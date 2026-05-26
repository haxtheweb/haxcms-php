<?php
trait OperationsRouteSkeletonsList {
  /**
   * Discover available site skeletons from core and user config directories.
   * Returns metadata list compatible with app-hax v2 dashboard.
   * Requires a valid user_token and JWT.
   *
   * @OA\Get(
   *    path="/skeletonsList",
   *    tags={"cms"},
   *    @OA\Response(
   *        response="200",
   *        description="List available site skeletons"
   *   )
   * )
   */
  public function skeletonsList() {
    // Validate user_token like listSites
    if (!isset($this->params['user_token']) || !$GLOBALS['HAXCMS']->validateRequestToken($this->params['user_token'], $GLOBALS['HAXCMS']->getActiveUserName())) {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid request token',
        )
      );
    }

    $items = array();
    $seen = array();
    // directories to scan for JSON skeleton definitions
    $dirs = $this->getSkeletonDirectories();

    foreach ($dirs as $dir) {
      if ($handle = opendir($dir)) {
        while (false !== ($file = readdir($handle))) {
          if ($file === '.' || $file === '..') { continue; }
          $path = $dir . '/' . $file;
          if (is_file($path) && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'json') {
            $json = @file_get_contents($path);
            $skeleton = json_decode($json);
            if (!is_object($skeleton)) { continue; }
            // Accept flexible export structures; derive meta fields
            $meta = isset($skeleton->meta) ? $skeleton->meta : new stdClass();
            $title = isset($meta->useCaseTitle) && $meta->useCaseTitle ? $meta->useCaseTitle : (isset($meta->name) ? $meta->name : basename($file, '.json'));
            $description = isset($meta->useCaseDescription) && $meta->useCaseDescription ? $meta->useCaseDescription : (isset($meta->description) ? $meta->description : '');
            $image = isset($meta->useCaseImage) ? $meta->useCaseImage : '';
            // priority: negative floats to the top, positive sinks
            $priority = 0;
            if (isset($meta->priority) && is_numeric($meta->priority)) {
              $priority = 0 + $meta->priority;
            }
            // categories/tags from meta or build type if present
            $category = array();
            if (isset($meta->category) && is_array($meta->category)) { $category = $meta->category; }
            else if (isset($meta->tags) && is_array($meta->tags)) { $category = $meta->tags; }
            // attributes/icons optional in meta
            $attributes = array();
            if (isset($meta->attributes) && is_array($meta->attributes)) { $attributes = $meta->attributes; }
            // demo/source url optional
            $demo = isset($meta->sourceUrl) ? $meta->sourceUrl : '#';
            // Build API URL to fetch skeleton content with user_token
            $skeletonName = basename($file, '.json');
            // de-dupe by machineName using precedence order above
            if (in_array($skeletonName, $seen, TRUE)) {
              continue;
            }
            // "default-starter" is a shared internal fallback skeleton that
            // many generic themes reference as their skeleton definition.
            // It should not appear in the public list of selectable skeletons.
            if ($skeletonName === 'default-starter') {
              continue;
            }
            // Ensure base API path ends with a trailing slash so route
            // concatenation does not produce `/system/apigetSkeleton`.
            $baseAPIPath = $GLOBALS['HAXCMS']->basePath . $GLOBALS['HAXCMS']->systemRequestBase . '/';
            $userToken = isset($this->params['user_token']) ? $this->params['user_token'] : '';
            $skeletonUrl = $baseAPIPath . 'getSkeleton?name=' . urlencode($skeletonName) . '&user_token=' . urlencode($userToken);
            $items[] = array(
              'title' => $title,
              'description' => $description,
              'image' => $image,
              'priority' => $priority,
              'category' => $category,
              'attributes' => $attributes,
              // repeat machine name explicitly so UIs don't have to infer it from skeleton-url
              'machineName' => $skeletonName,
              'machine-name' => $skeletonName,
              'demo-url' => $demo,
              'skeleton-url' => $skeletonUrl
            );
            $seen[] = $skeletonName;
          }
        }
        closedir($handle);
      }
    }

    return array(
      'status' => 200,
      'data' => $items
    );
  }
}
