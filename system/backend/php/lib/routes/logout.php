<?php
trait OperationsRouteLogout {
  /**
   * @OA\Get(
   *    path="/logout",
   *    tags={"cms","user"},
   *    @OA\Response(
   *        response="200",
   *        description="User logout, front end will kill token"
   *   )
   * )
   */
  public function logout() {
    // Security best practice (M3): clear via the centralized helper so the
    // Secure/SameSite flags match how the cookie was set (required for the
    // browser to actually delete it).
    $GLOBALS['HAXCMS']->setRefreshTokenCookie('', 1);
    return array(
      "status" => 200,
      "data" => 'loggedout',
    );
  }
}
