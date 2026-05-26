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
    setcookie('haxcms_refresh_token', '', 1, '/', '', true, true);
    return array(
      "status" => 200,
      "data" => 'loggedout',
    );
  }
}
