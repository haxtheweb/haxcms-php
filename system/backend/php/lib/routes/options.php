<?php
trait OperationsRouteOptions {
  /**
   * 
   * @OA\Post(
   *    path="/options",
   *    tags={"api"},
   *    @OA\Response(
   *        response="200",
   *        description="API bandaid till we get all the APIs documented. This is an array of callbacks"
   *    )
   * )
   */
  public function options() {
    return array_keys(self::getRoutesMap());
  }
}
