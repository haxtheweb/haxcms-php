<?php
trait OperationsRouteOpenapi {
  /**
   * Generate the swagger API documentation for this site
   * 
   * @OA\Post(
   *    path="/openapi/json",
   *    tags={"api"},
   *    @OA\Response(
   *        response="200",
   *        description="API documentation in JSON"
   *    )
   * )
   */
  public function openapi() {
    // scan Operations metadata plus all route files in order to build Swagger docs
    $openapiScanPaths = array(
      dirname(__FILE__) . '/../Operations.php',
      dirname(__FILE__),
    );
    $openapi = \OpenApi\scan($openapiScanPaths);
    // dynamically add the version
    $openapi->info->version = $GLOBALS['HAXCMS']->getHAXCMSVersion();
    $openapi->servers = Array();
    $openapi->servers[0] = new stdClass();
    // generate url dynamically w/ path to the API route
    $openapi->servers[0]->url = $GLOBALS['HAXCMS']->protocol . '://' . $GLOBALS['HAXCMS']->domain . $GLOBALS['HAXCMS']->basePath . $GLOBALS['HAXCMS']->systemRequestBase;
    $openapi->servers[0]->description = "Site list / dashboard for administrator user";
    // output, yaml we have to exit early or we'll get encapsulation
    if (isset($this->params['args']) && $this->params['args'][1] == 'json') {
      return json_decode($openapi->toJson());
    }
    else if (isset($this->params['args']) && $this->params['args'][1] == 'haxSchema') {
      $haxSchema = array('configure' => array());
      $target = null; 
      // support a specific endpoint that a form is desired for
      if (isset($this->params['args'][2]) && !is_null($this->params['args'][2])) {
        $target = $this->params['args'][2];
        $haxSchema = array();
      }
      foreach ($openapi->paths as $obj) {
        if (!is_null($target) && str_replace('/','', $obj->path) != $target) {
          continue;
        }
        $haxSchema[$obj->path] = array();
        $params = array();
        if (isset($obj->post) && isset($obj->post->parameters)) {
          $params = $obj->post->parameters;
        }
        else if (isset($obj->get) && isset($obj->get->parameters)) {
          $params = $obj->get->parameters;
        }
        if (is_array($params)) {
          foreach ($params as $param) {
            $haxSchema[$obj->path][] = json_decode('{
              "property": "' . $param->name . '",
              "title": "' . ucfirst($param->name) . '",
              "description": "' . $param->description . '",
              "inputMethod": "' . $GLOBALS['HAXCMS']->getInputMethod($param->schema->type) . '",
              "required": ' . (isset($param->required) ? (bool) $param->required : 'false') . '
            }');
          }
        }
      }
      return $haxSchema;
    }
    else {
      echo $openapi->toYaml();
      exit;
    }
  }
}
