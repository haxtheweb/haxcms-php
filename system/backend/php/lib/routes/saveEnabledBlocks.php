<?php
trait OperationsRouteSaveEnabledBlocks {
  /**
   * @OA\Post(
   *    path="/saveEnabledBlocks",
   *    tags={"cms","authenticated","settings"},
   *    @OA\Response(
   *        response="200",
   *        description="Persist enabled blocks settings"
   *   )
   * )
   */
  public function saveEnabledBlocks() {
    if (!isset($this->params['user_token']) || !$GLOBALS['HAXCMS']->validateRequestToken($this->params['user_token'], $GLOBALS['HAXCMS']->getActiveUserName())) {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid request token',
        )
      );
    }
    $payload = null;
    if (isset($this->params['enabledBlocks'])) {
      $payload = $this->params['enabledBlocks'];
    }
    else if (isset($this->rawParams['enabledBlocks'])) {
      $payload = $this->rawParams['enabledBlocks'];
    }
    else if (is_array($this->params)) {
      $payload = $this->params;
    }
    if (!is_array($payload)) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'Missing enabledBlocks payload',
        )
      );
    }
    $clean = array();
    foreach ($payload as $i => $tag) {
      if (!is_string($tag)) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'Invalid enabledBlocks payload',
          )
        );
      }
      $normalized = strtolower(trim($tag));
      if ($normalized === '' || !preg_match('/^[a-z][a-z0-9-]*$/', $normalized)) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'Invalid enabledBlocks payload',
          )
        );
      }
      $clean[] = $normalized;
    }
    $clean = array_values(array_unique($clean));
    sort($clean);
    $settingsDir = $GLOBALS['HAXCMS']->configDirectory . '/settings';
    if (!is_dir($settingsDir)) {
      @mkdir($settingsDir, 0777, true);
    }
    file_put_contents(
      $settingsDir . '/enabledBlocks.json',
      json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
    return array(
      'status' => 200,
      'data' => array(
        'enabledBlocks' => $clean,
      ),
    );
  }
}
