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
    // In HAXiam mode, require an authenticated user and enforce
    // /<username>/system/api/* path alignment with authenticated principal.
    if (isset($GLOBALS['HAXCMS']->config->iam) && $GLOBALS['HAXCMS']->config->iam) {
      $tenantUser = $GLOBALS['HAXCMS']->getIAMTenantUserName();
      $pathUser = $GLOBALS['HAXCMS']->getRequestPathUserName();
      // If both are present they must agree.
      if (!is_null($tenantUser) && $tenantUser != '' && !is_null($pathUser) && $pathUser != '' && $tenantUser !== $pathUser) {
        return array(
          '__failed' => array(
            'status' => 403,
            'message' => 'Access denied',
          )
        );
      }
      // Expected IAM user identity for this request.
      $expectedUser = null;
      if (!is_null($tenantUser) && $tenantUser != '') {
        $expectedUser = $tenantUser;
      }
      else if (!is_null($pathUser) && $pathUser != '') {
        $expectedUser = $pathUser;
      }
      if (!is_null($expectedUser) && $expectedUser != '') {
        $authenticatedUser = $GLOBALS['HAXCMS']->getAuthenticatedUserName();
        if (is_null($authenticatedUser) || $authenticatedUser == '' || $authenticatedUser !== $expectedUser) {
          return array(
            '__failed' => array(
              'status' => 403,
              'message' => 'Access denied',
            )
          );
        }
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
