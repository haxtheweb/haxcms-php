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
    // Phase 3 (M1): this response may embed a per-user access JWT via
    // appSettings.jwt (HAXiam / server-injected bootstrap). Prevent caching.
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    // D55: match Node's connectionSettings.js output format exactly — prepend
    // the MicroFrontendRegistryConfig initialization line and append the dev-jwt
    // line when HAXCMS_DISABLE_JWT_CHECKS is set (parity with Node).
    $returnData = json_encode(
      $GLOBALS['HAXCMS']->appJWTConnectionSettings($GLOBALS['HAXCMS']->basePath),
      JSON_UNESCAPED_SLASHES
    );
    $after = '';
    if (getenv('HAXCMS_DISABLE_JWT_CHECKS')) {
      $superUserName = isset($GLOBALS['HAXCMS']->superUser->name)
        ? $GLOBALS['HAXCMS']->superUser->name
        : null;
      $after = 'window.appSettings.jwt = "' . $GLOBALS['HAXCMS']->getJWT($superUserName) . '"';
    }
    return array(
      '__noencode' => array(
        'status' => 200,
        'contentType' => 'application/javascript',
        'message' => "window.MicroFrontendRegistryConfig = window.MicroFrontendRegistryConfig || {};\nwindow.appSettings =" . $returnData . ';' . $after,
      )
    );
  }
}
