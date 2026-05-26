<?php
trait OperationsRouteApi {
  /**
   * Generate the swagger API documentation for this site
   * 
   * @OA\Post(
   *    path="/",
   *    tags={"api"},
   *    @OA\Response(
   *        response="200",
   *        description="API documentation in YAML"
   *    )
   * )
   * @todo generate JSON:API
   */   
  public function api() {
    $this->openapi();
  }
}
