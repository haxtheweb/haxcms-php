<?php
trait OperationsRouteConnectionSettings {
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
    $settings = $GLOBALS['HAXCMS']->appJWTConnectionSettings($GLOBALS['HAXCMS']->basePath);
    // Security (SEC-SESSION): this response is never cached. An answer that
    // carries a token (siteToken / userToken, or appSettings.jwt from a HAXiam
    // or server-injected bootstrap), or that answers a credentialed request, is
    // no-store. An anonymous answer with no token is `private, no-cache` so the
    // page keeps its back/forward cache entry (haxtheweb/issues#3244, NodeJS
    // parity).
    HAXCMS::sendUncachedResponseHeaders(
      isset($settings->siteToken) || isset($settings->userToken) || isset($settings->jwt)
    );
    // D55: match Node's connectionSettings.js output format exactly — prepend
    // the MicroFrontendRegistryConfig initialization line.
    // Security (SEC-SESSION): the HAXCMS_DISABLE_JWT_CHECKS env-var append that
    // injected a super-user JWT here was removed. This route is in the JWT-skip
    // allowlist (Request.php) so it runs unauthenticated; the env var would hand
    // a super-user JWT to any caller. Dev sessions should log in via the normal
    // login route instead.
    $returnData = json_encode($settings, JSON_UNESCAPED_SLASHES);
    return array(
      '__noencode' => array(
        'status' => 200,
        'contentType' => 'application/javascript',
        'message' => "window.MicroFrontendRegistryConfig = window.MicroFrontendRegistryConfig || {};\nwindow.appSettings =" . $returnData . ';',
      )
    );
  }
}
