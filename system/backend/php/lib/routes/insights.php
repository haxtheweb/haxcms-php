<?php
include_once dirname(__DIR__) . '/ReportHelpers.php';
trait OperationsRouteInsights {
  /**
   * @OA\Post(
   *    path="/insights",
   *    tags={"cms","authenticated","reports"},
   *    @OA\Response(
   *        response="200",
   *        description="Load site insights report data"
   *   )
   * )
   */
  public function insights() {
    $siteName = HAXCMSReportHelpers::getSiteName($this->params);
    if (
      $siteName == '' ||
      !HAXCMSReportHelpers::validateSiteToken($this->params, $siteName)
    ) {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid request token',
        )
      );
    }
    $site = $GLOBALS['HAXCMS']->loadSite($siteName);
    if (!$site || !isset($site->manifest)) {
      return array(
        'status' => 200,
        'data' => new stdClass(),
      );
    }
    return array(
      'status' => 200,
      'data' => HAXCMSReportHelpers::buildSummaryData($site, $this->params),
    );
  }
}
