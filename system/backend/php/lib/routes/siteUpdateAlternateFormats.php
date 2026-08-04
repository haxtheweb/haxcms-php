<?php
trait OperationsRouteSiteUpdateAlternateFormats {
  /**
   * @OA\Post(
   *    path="/siteUpdateAlternateFormats",
   *    tags={"cms","authenticated","meta"},
   *    @OA\Parameter(
   *         name="site_token",
   *         description="Site-specific validation token",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Update the alternative formats surrounding a site"
   *   )
   * )
   */
  public function siteUpdateAlternateFormats() {
    if (!isset($this->params['site']) || !isset($this->params['site']['name'])) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'missing site name',
        )
      );
    }
    if (!(isset($this->params['site_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['site_token'], $GLOBALS['HAXCMS']->getActiveUserName() . ':' . $this->params['site']['name']))) {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid site token',
        )
      );
    }
    $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
    if (!$site || !isset($site->manifest)) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'invalid site',
        )
      );
    }
    $format = NULL;
    if (isset($this->params['format'])) {
      $requestedFormat = trim((string) $this->params['format']);
      if ($requestedFormat !== '') {
        $allowedFormats = array('rss', 'sitemap', 'search', 'llms', 'service-worker');
        if (!in_array($requestedFormat, $allowedFormats, true)) {
          return array(
            '__failed' => array(
              'status' => 400,
              'message' => 'invalid format',
            )
          );
        }
        $format = $requestedFormat;
      }
    }
    $site->updateAlternateFormats($format);
    return array(
      'status' => 200,
      'data' => array(
        'updated' => true,
        'site' => array(
          'name' => $this->params['site']['name'],
        ),
        'format' => $format,
      )
    );
  }
}
