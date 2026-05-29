<?php
trait OperationsRouteConnectionSettings {
  /**
   * @OA\Get(
   *    path="/connectionSettings",
   *    tags={"cms"},
   *    @OA\Response(
   *        response="200",
   *        description="Generate the connection settings dynamically for implying we have a PHP backend"
   *   )
   * )
   * @OA\Post(
   *    path="/connectionSettings",
   *    tags={"cms"},
   *    @OA\Response(
   *        response="200",
   *        description="Generate the connection settings dynamically for implying we have a PHP backend"
   *   )
   * )
   */
  public function connectionSettings() {
    if (method_exists($GLOBALS['HAXCMS'], 'validateIAMRouteAuthorization')) {
      $authorization = $GLOBALS['HAXCMS']->validateIAMRouteAuthorization(TRUE);
      if (is_array($authorization) && isset($authorization['allowed']) && !$authorization['allowed']) {
        return array(
          '__failed' => array(
            'status' => isset($authorization['status']) ? (int) $authorization['status'] : 403,
            'message' => isset($authorization['message']) && $authorization['message'] != '' ? $authorization['message'] : 'Access denied',
          )
        );
      }
    }
    // need to return this as if it was a javascript file, weird looking for sure
    return array(
      '__noencode' => array(
        'status' => 200,
        'contentType' => 'application/javascript',
        'message' => 'window.appSettings = ' . json_encode($GLOBALS['HAXCMS']->appJWTConnectionSettings($GLOBALS['HAXCMS']->basePath)) . ';',
      )
    );
  }
}
