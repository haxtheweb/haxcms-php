<?php
trait OperationsRouteSiteUpdateAlternateFormats {
  /**
   * @OA\Post(
   *    path="/siteUpdateAlternateFormats",
   *    tags={"cms","authenticated","meta"},
   *    @OA\Response(
   *        response="200",
   *        description="Update the alternative formats surrounding a site"
   *   )
   * )
   */
  public function siteUpdateAlternateFormats() {
    $format = NULL;
    $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
    if (isset($this->params['format'])) {
      $format = $this->params['format'];
    }
    $site->updateAlternateFormats($format);
  }
}
