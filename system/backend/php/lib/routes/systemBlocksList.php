<?php
trait OperationsRouteSystemBlocksList {
  /**
   * @OA\Get(
   *    path="/systemBlocksList",
   *    tags={"cms","authenticated","settings"},
   *    @OA\Response(
   *        response="200",
   *        description="Load system block inventory and enabled blocks"
   *   )
   * )
   */
  public function systemBlocksList() {
    if (!isset($this->params['user_token']) || !$GLOBALS['HAXCMS']->validateRequestToken($this->params['user_token'], $GLOBALS['HAXCMS']->getActiveUserName())) {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid request token',
        )
      );
    }
    $autoloader = array();
    if (
      isset($GLOBALS['HAXCMS']->config->appStore) &&
      isset($GLOBALS['HAXCMS']->config->appStore->autoloader) &&
      is_array($GLOBALS['HAXCMS']->config->appStore->autoloader)
    ) {
      $autoloader = array_values($GLOBALS['HAXCMS']->config->appStore->autoloader);
    }
    if (count($autoloader) === 0) {
      $autoloader = array('grid-plate');
    }
    $enabledBlocks = array();
    $enabledFile = $GLOBALS['HAXCMS']->configDirectory . '/settings/enabledBlocks.json';
    if (file_exists($enabledFile)) {
      $decoded = json_decode(file_get_contents($enabledFile));
      if (is_array($decoded)) {
        foreach ($decoded as $tag) {
          if (is_string($tag)) {
            $normalized = strtolower(trim($tag));
            if ($normalized !== '' && preg_match('/^[a-z][a-z0-9-]*$/', $normalized)) {
              $enabledBlocks[] = $normalized;
            }
          }
        }
      }
    }
    $enabledBlocks = array_values(array_unique($enabledBlocks));
    sort($enabledBlocks);
    return array(
      'status' => 200,
      'apps' => array(),
      'stax' => array(),
      'autoloader' => $autoloader,
      'enabledBlocks' => $enabledBlocks,
    );
  }
}
