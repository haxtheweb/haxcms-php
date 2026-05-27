<?php
include_once dirname(__DIR__) . '/ReportHelpers.php';
trait OperationsRouteLinkChecker {
  /**
   * @OA\Post(
   *    path="/linkChecker",
   *    tags={"cms","authenticated","reports"},
   *    @OA\Response(
   *        response="200",
   *        description="Load link checker report data"
   *   )
   * )
   */
  public function linkChecker() {
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
      'data' => HAXCMSReportHelpers::buildLinkData($site, $this->params),
    );
  }
}
