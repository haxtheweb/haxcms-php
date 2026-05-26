<?php
trait OperationsRouteDownloadSiteSkeleton {
  /**
   * @OA\\Post(
   *    path=\"/downloadSiteSkeleton\",
   *    tags={\"cms\",\"authenticated\",\"site\",\"meta\"},
   *    @OA\\Parameter(
   *         name=\"user_token\",
   *         description=\"User validation token\",
   *         in=\"query\",
   *         required=true,
   *         @OA\\Schema(type=\"string\")
   *    ),
   *    @OA\\RequestBody(
   *        @OA\\MediaType(
   *             mediaType=\"application/json\",
   *             @OA\\Schema(
   *                 @OA\\Property(
   *                     property=\"site\",
   *                     type=\"object\"
   *                 ),
   *                 required={\"site\"},
   *                 example={
   *                    \"site\": {
   *                      \"name\": \"mynewsite\"
   *                    },
   *                 }
   *             )
   *         )
   *    ),
   *    @OA\\Response(
   *        response=\"200\",
   *        description=\"Generate and return skeleton JSON for an existing site\"
   *   )
   * )
   */
  public function downloadSiteSkeleton() {
    if (!isset($this->params['user_token']) || !$GLOBALS['HAXCMS']->validateRequestToken($this->params['user_token'], $GLOBALS['HAXCMS']->getActiveUserName())) {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid request token',
        )
      );
    }
    if (
      !isset($this->params['site']) ||
      !is_array($this->params['site']) ||
      !isset($this->params['site']['name']) ||
      !is_string($this->params['site']['name']) ||
      trim($this->params['site']['name']) === ''
    ) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'invalid site name',
        )
      );
    }
    $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
    if (!$site || !isset($site->manifest)) {
      return array(
        '__failed' => array(
          'status' => 404,
          'message' => 'Site does not exist',
        )
      );
    }
    try {
      $skeleton = $this->buildSiteTemplateSkeleton($site);
      $filename =
        (isset($skeleton['meta']) &&
          is_array($skeleton['meta']) &&
          isset($skeleton['meta']['machineName']) &&
          is_string($skeleton['meta']['machineName']) &&
          trim($skeleton['meta']['machineName']) !== ''
            ? $skeleton['meta']['machineName']
            : $this->normalizeTemplateMachineName($site->manifest->metadata->site->name)) .
        '.json';
      return array(
        'status' => 200,
        'data' => array(
          'skeleton' => $skeleton,
          'filename' => $filename,
        ),
      );
    }
    catch (Exception $e) {
      return array(
        '__failed' => array(
          'status' => 500,
          'message' => 'Unable to generate site skeleton',
          'detail' => $e->getMessage(),
        )
      );
    }
  }
}
