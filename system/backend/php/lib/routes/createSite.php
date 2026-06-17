<?php
trait OperationsRouteCreateSite {
  private function isSystemV1Request()
  {
    $requestPath = '';
    if (isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) && $_SERVER['REQUEST_URI'] !== '') {
      $parsedPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
      if (is_string($parsedPath) && $parsedPath !== '') {
        $requestPath = $parsedPath;
      }
    }
    if ($requestPath === '' && isset($_SERVER['SCRIPT_NAME']) && is_string($_SERVER['SCRIPT_NAME'])) {
      $requestPath = $_SERVER['SCRIPT_NAME'];
    }
    return (strpos($requestPath, '/system/api/v1/') !== false);
  }
  private function hasValidCreateSiteRequestToken()
  {
    return $this->isSystemV1Request();
  }
  private function hasValidCreateSiteUserToken()
  {
    return $this->isSystemV1Request();
  }
  /**
   * @OA\Post(
   *    path="/createSite",
   *    tags={"cms","authenticated","site"},
   *    @OA\Parameter(
   *         name="user_token",
   *         description="User validation token",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *     @OA\RequestBody(
   *        @OA\MediaType(
   *             mediaType="application/json",
   *             @OA\Schema(
   *                 @OA\Property(
   *                     property="site",
   *                     type="object"
   *                 ),
   *                 @OA\Property(
   *                     property="build",
   *                     type="object"
   *                 ),
   *                 @OA\Property(
   *                     property="token",
   *                     type="string"
   *                 ),
   *                 required={"site","token"},
   *                 example={
   *                    "site": {
   *                      "name": "mynewsite",
   *                      "description": "The description",
   *                      "theme": "learn-two-theme"
   *                    },
   *                    "build": {
   *                      "type": "course",
   *                      "structure": "docx import"
   *                    },
   *                    "token": "request-token"
   *                 }
   *             )
   *         )
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Create a new site"
   *   )
   * )
   */
  public function createSite() {
    if ($this->hasValidCreateSiteRequestToken() && $this->hasValidCreateSiteUserToken()) {
      $domain = null;
      // woohoo we can edit this thing!
      if (isset($this->params['site']['domain']) && $this->params['site']['domain'] != null && $this->params['site']['domain'] != '') {
        $domain = $this->params['site']['domain'];
      }
      // null in the event we get hits that don't have this
      $build = null;
      $filesToDownload = Array();
      $trustedSkeleton = null;
      $trustedSkeletonFilePath = null;
      // support for build info. the details used to actually create this site originally
      if (isset($this->params['build'])) {
        $build = new stdClass();
        // version of the platform used when originally created
        $build->version = $GLOBALS['HAXCMS']->getHAXCMSVersion();
        // course, website, portfolio, etc
        $build->structure = $this->params['build']['structure'];
        // TYPE of structure we are creating
        $build->type = $this->params['build']['type'];
        if ($build->type == 'docx import' || $build->structure == "import" || $build->structure == "from-skeleton") {
          // JSONOutlineSchemaItem Array
          $build->items = $this->params['build']['items'];
        }
        if (isset($this->params['build']['files'])) {
          $filesToDownload = $this->params['build']['files'];
        }
        $isFromSkeleton =
          isset($build->structure) &&
          $build->structure === 'from-skeleton';
        if ($isFromSkeleton) {
          $skeletonMachineName = (
            isset($this->params['build']['skeletonMachineName']) &&
            is_string($this->params['build']['skeletonMachineName'])
          )
            ? $this->params['build']['skeletonMachineName']
            : '';
          $resolvedSkeleton = null;
          if ($skeletonMachineName !== '') {
            $resolvedSkeleton = $this->resolveSkeletonBuildByMachineName($skeletonMachineName);
            if (!is_array($resolvedSkeleton) || !isset($resolvedSkeleton['skeleton']) || !is_array($resolvedSkeleton['skeleton'])) {
              $resolvedSkeleton = $this->resolveSkeletonBuildByThemeMachineName($skeletonMachineName);
            }
          }
          if (
            (!is_array($resolvedSkeleton) || !isset($resolvedSkeleton['skeleton']) || !is_array($resolvedSkeleton['skeleton'])) &&
            isset($build->items) &&
            is_array($build->items) &&
            count($build->items) > 0
          ) {
            $resolvedSkeleton = $this->resolveSkeletonByBuildItems($build->items);
          }
          if (
            $skeletonMachineName !== '' &&
            (!is_array($resolvedSkeleton) || !isset($resolvedSkeleton['skeleton']) || !is_array($resolvedSkeleton['skeleton']))
          ) {
            return array(
              '__failed' => array(
                'status' => 400,
                'message' => 'Unable to resolve skeletonMachineName for from-skeleton build',
                'skeletonMachineName' => $skeletonMachineName,
              )
            );
          }
          if (is_array($resolvedSkeleton) && isset($resolvedSkeleton['skeleton']) && is_array($resolvedSkeleton['skeleton'])) {
            $trustedSkeleton = $resolvedSkeleton['skeleton'];
            $trustedSkeletonFilePath = isset($resolvedSkeleton['filePath']) ? $resolvedSkeleton['filePath'] : null;
            $trustedBuild = (isset($trustedSkeleton['build']) && is_array($trustedSkeleton['build']))
              ? $trustedSkeleton['build']
              : array();
            if (isset($trustedBuild['structure']) && is_string($trustedBuild['structure']) && $trustedBuild['structure'] !== '') {
              $build->structure = $trustedBuild['structure'];
            }
            if (isset($trustedBuild['type']) && is_string($trustedBuild['type']) && $trustedBuild['type'] !== '') {
              $build->type = $trustedBuild['type'];
            }
            $build->items = (isset($trustedBuild['items']) && is_array($trustedBuild['items']))
              ? $trustedBuild['items']
              : array();
            if (isset($trustedBuild['files']) && (is_array($trustedBuild['files']) || is_object($trustedBuild['files']))) {
              $filesToDownload = is_object($trustedBuild['files'])
                ? (array)$trustedBuild['files']
                : $trustedBuild['files'];
            }
            error_log(
              '[createSite] resolved skeleton build from machine name: ' .
              json_encode(array(
                'skeletonMachineName' => $skeletonMachineName,
                'resolvedFile' => $trustedSkeletonFilePath,
                'itemCount' => is_array($build->items) ? count($build->items) : 0,
                'fileCount' => is_array($filesToDownload) ? count($filesToDownload) : 0,
              ))
            );
          }
        }
      }
      $buildDebug = array(
        'structure' => (is_object($build) && isset($build->structure)) ? $build->structure : null,
        'type' => (is_object($build) && isset($build->type)) ? $build->type : null,
        'skeletonMachineName' => isset($this->params['build']['skeletonMachineName']) ? $this->params['build']['skeletonMachineName'] : null,
        'hasItems' => (is_object($build) && isset($build->items) && is_array($build->items) && count($build->items) > 0),
        'itemCount' => (is_object($build) && isset($build->items) && is_array($build->items)) ? count($build->items) : 0,
        'hasFiles' => (is_array($filesToDownload) && count($filesToDownload) > 0),
        'fileCount' => is_array($filesToDownload) ? count($filesToDownload) : 0
      );
      error_log('[createSite] incoming build debug: ' . json_encode($buildDebug));
      if (is_object($build) && isset($build->structure) && $build->structure === 'from-skeleton') {
        error_log('[createSite] from-skeleton raw payload: ' . json_encode(isset($this->params['build']) ? $this->params['build'] : array()));
      }
      $useTrustedSkeleton =
        is_object($build) &&
        isset($build->structure) &&
        $build->structure === 'from-skeleton' &&
        is_array($trustedSkeleton);
      // sanitize name
      $name = $GLOBALS['HAXCMS']->generateMachineName($this->params['site']['name']);
      $site = $GLOBALS['HAXCMS']->loadSite(
          strtolower($name),
          true,
          $domain,
          $build
      );
      $supportedSiteLicenses = array(
        'by',
        'by-sa',
        'by-nd',
        'by-nc',
        'by-nc-sa',
        'by-nc-nd'
      );
      if (method_exists($site, 'getLicenseData')) {
        $licenseOptions = $site->getLicenseData('select');
        if (is_array($licenseOptions) && count($licenseOptions) > 0) {
          $normalizedSupportedLicenses = array();
          foreach (array_keys($licenseOptions) as $licenseKey) {
            $normalizedKey = strtolower(trim(str_replace('_', '-', strval($licenseKey))));
            if ($normalizedKey !== '') {
              $normalizedSupportedLicenses[] = $normalizedKey;
            }
          }
          if (count($normalizedSupportedLicenses) > 0) {
            $supportedSiteLicenses = array_values(array_unique($normalizedSupportedLicenses));
          }
        }
      }
      $normalizeSiteLicenseValue = function ($rawValue) use ($supportedSiteLicenses) {
        if (!is_string($rawValue)) {
          return null;
        }
        $value = strtolower(trim(str_replace('_', '-', $rawValue)));
        if ($value === '') {
          return null;
        }
        if (in_array($value, $supportedSiteLicenses, true)) {
          return $value;
        }
        foreach ($supportedSiteLicenses as $code) {
          if (
            strpos($value, '/licenses/' . $code) !== false ||
            strpos($value, 'cc ' . $code) !== false ||
            strpos($value, 'cc-' . $code) !== false ||
            strpos($value, 'cc:' . $code) !== false
          ) {
            return $code;
          }
        }
        return null;
      };
      $requestedLicense = null;
      if (isset($this->params['site']['license']) && is_string($this->params['site']['license'])) {
        $requestedLicense = $this->params['site']['license'];
      }
      $normalizedSiteLicense = $normalizeSiteLicenseValue($requestedLicense);
      if (
        is_null($normalizedSiteLicense) &&
        $useTrustedSkeleton &&
        isset($trustedSkeleton['site']) &&
        is_array($trustedSkeleton['site']) &&
        isset($trustedSkeleton['site']['license']) &&
        is_string($trustedSkeleton['site']['license'])
      ) {
        $normalizedSiteLicense = $normalizeSiteLicenseValue($trustedSkeleton['site']['license']);
      }
      if (!is_null($normalizedSiteLicense)) {
        $site->manifest->license = $normalizedSiteLicense;
      }
      // this could have changed after creation because of on file system
      $name = $site->manifest->metadata->site->name;
      // now get a new item to reference this into the top level sites listing
      $schema = $GLOBALS['HAXCMS']->outlineSchema->newItem();
      $schema->id = $site->manifest->id;
      $schema->title = $name;
      $schema->location =
          $GLOBALS['HAXCMS']->basePath .
          $GLOBALS['HAXCMS']->sitesDirectory .
          '/' .
          $site->manifest->metadata->site->name .
          '/index.html';
      $schema->slug = $schema->location;
      $schema->metadata->site = new stdClass();
      $schema->metadata->theme = new stdClass();
      if ($useTrustedSkeleton) {
        $trustedPlatform = $this->getTrustedSkeletonPlatform($trustedSkeleton);
        if (is_array($trustedPlatform)) {
          $schema->metadata->platform = $this->toObject($trustedPlatform);
        }
      }
      if (!isset($schema->metadata->platform) || !is_object($schema->metadata->platform)) {
        // platform settings scaffold (prevents front-end null handling)
        $schema->metadata->platform = new stdClass();
        $schema->metadata->platform->audience = 'expert';
        $schema->metadata->platform->features = new stdClass();
        $schema->metadata->platform->allowedBlocks = array();
      }
      // store build data in case we need it down the road (non-skeleton only)
      if (!$useTrustedSkeleton && is_object($build)) {
        $schema->metadata->build = $build;
        // we don't need to store replication of all items imported on site creation
        if (isset($schema->metadata->build->items)) {
          unset($schema->metadata->build->items);
        }
      }
      $schema->metadata->site->name = $site->manifest->metadata->site->name;
      if (!is_null($normalizedSiteLicense)) {
        $schema->metadata->site->license = $normalizedSiteLicense;
      }
      if (
        $useTrustedSkeleton &&
        isset($trustedSkeleton['site']) &&
        is_array($trustedSkeleton['site']) &&
        isset($trustedSkeleton['site']['theme']) &&
        is_string($trustedSkeleton['site']['theme']) &&
        $trustedSkeleton['site']['theme'] !== ''
      ) {
        $theme = $trustedSkeleton['site']['theme'];
      }
      else if (isset($this->params['site']['theme']) && is_string($this->params['site']['theme'])) {
        $theme = $this->params['site']['theme'];
      }
      else {
        $theme = HAXCMS_DEFAULT_THEME;
      }
      if (is_string($theme)) {
        $theme = strtolower(trim($theme));
      }
      if ($useTrustedSkeleton) {
        $trustedTheme = $this->getTrustedSkeletonTheme($trustedSkeleton);
        if (is_array($trustedTheme)) {
          $schema->metadata->theme = $this->toObject($trustedTheme);
        }
      }
      // look for a match so we can set the correct data
      if (!is_object($schema->metadata->theme) || count((array)$schema->metadata->theme) === 0) {
        $themes = $GLOBALS['HAXCMS']->getThemes();
        if (is_object($themes)) {
          $themes = (array)$themes;
        }
        if (is_array($themes) && isset($themes[$theme])) {
          $schema->metadata->theme = is_object($themes[$theme])
            ? json_decode(json_encode($themes[$theme]))
            : $this->toObject($themes[$theme]);
        }
        else {
          return array(
            '__failed' => array(
              'status' => 400,
              'message' => 'Invalid theme supplied for site creation',
              'theme' => $theme,
            )
          );
        }
      }
      if (!is_object($schema->metadata->theme)) {
        $schema->metadata->theme = new stdClass();
      }
      if (!isset($schema->metadata->theme->variables) || !is_object($schema->metadata->theme->variables)) {
        $schema->metadata->theme->variables = new stdClass();
      }
      // description for an overview if desired
      if (isset($this->params['site']['description']) && $this->params['site']['description'] != '' && $this->params['site']['description'] != null) {
          $schema->description = strip_tags($this->params['site']['description']);
      }
      else if (
        $useTrustedSkeleton &&
        isset($trustedSkeleton['site']) &&
        is_array($trustedSkeleton['site']) &&
        isset($trustedSkeleton['site']['description']) &&
        is_string($trustedSkeleton['site']['description'])
      ) {
          $schema->description = strip_tags($trustedSkeleton['site']['description']);
      }
      // background image / banner (request does not control this)
      if (
        $useTrustedSkeleton &&
        isset($trustedSkeleton['site']) &&
        is_array($trustedSkeleton['site']) &&
        isset($trustedSkeleton['site']['logo']) &&
        is_string($trustedSkeleton['site']['logo']) &&
        $trustedSkeleton['site']['logo'] !== ''
      ) {
        $schema->metadata->site->logo = $trustedSkeleton['site']['logo'];
      }
      else {
        $schema->metadata->site->logo = 'assets/banner.jpg';
      }
      // icon to express the concept / visually identify site
      $icon = 'icons:record-voice-over';
      if (
        isset($schema->metadata->theme->variables->icon) &&
        is_string($schema->metadata->theme->variables->icon) &&
        $schema->metadata->theme->variables->icon !== ''
      ) {
          $icon = $schema->metadata->theme->variables->icon;
      }
      $schema->metadata->theme->variables->icon = $icon;
      // slightly style the site based on css vars and hexcode
      if (
        isset($schema->metadata->theme->variables->hexCode) &&
        is_string($schema->metadata->theme->variables->hexCode) &&
        $schema->metadata->theme->variables->hexCode !== ''
      ) {
          $hex = $schema->metadata->theme->variables->hexCode;
      } else {
          $hex = HAXCMS_FALLBACK_HEX;
      }
      $schema->metadata->theme->variables->hexCode = $hex;
      if (
        isset($schema->metadata->theme->variables->cssVariable) &&
        is_string($schema->metadata->theme->variables->cssVariable) &&
        $schema->metadata->theme->variables->cssVariable !== ''
      ) {
          $cssvar = $schema->metadata->theme->variables->cssVariable;
      } else {
          $cssvar = '--simple-colors-default-theme-light-blue-7';
      }
      $schema->metadata->theme->variables->cssVariable = $cssvar;
      $trustedSettings = $useTrustedSkeleton
        ? $this->getTrustedSkeletonSettings($trustedSkeleton)
        : null;
      if (is_array($trustedSettings)) {
        $schema->metadata->site->settings = $this->toObject($trustedSettings);
      }
      else {
        $schema->metadata->site->settings = new stdClass();
      }
      if (!isset($schema->metadata->site->settings->lang) || $schema->metadata->site->settings->lang === '') {
        $schema->metadata->site->settings->lang = 'en-US';
      }
      if (!isset($schema->metadata->site->settings->publishPagesOn)) {
        $schema->metadata->site->settings->publishPagesOn = true;
      }
      if (!isset($schema->metadata->site->settings->canonical)) {
        $schema->metadata->site->settings->canonical = true;
      }
      $schema->metadata->site->created = time();
      $schema->metadata->site->updated = time();
      // check for publishing settings being set globally in HAXCMS
      // this would allow them to fork off to different locations down stream
      $schema->metadata->site->git = new stdClass();
      if (isset($GLOBALS['HAXCMS']->config->site->git->vendor)) {
          $schema->metadata->site->git =
              $GLOBALS['HAXCMS']->config->site->git;
          unset($schema->metadata->site->git->keySet);
          unset($schema->metadata->site->git->email);
          unset($schema->metadata->site->git->user);
      }
      // mirror the metadata information into the site's info
      // this means that this info is available to the full site listing
      // as well as this individual site. saves on performance / calls
      // later on if we only need to hit 1 file each time to get all the
      // data we need.
      foreach ($schema->metadata as $key => $value) {
          $site->manifest->metadata->{$key} = $value;
      }
      $site->manifest->metadata->node = new stdClass();
      $site->manifest->metadata->node->fields = new stdClass();
      $site->manifest->description = $schema->description;
      // save the outline into the new site
      $site->manifest->save(false);
      // walk through files if any came across and save each of them
      if (is_array($filesToDownload)) {
        foreach ($filesToDownload as $locationName => $downloadLocation) {
          $normalizedImportName = $this->normalizeBulkImportName($locationName);
          if (
            $normalizedImportName === false ||
            preg_match($this->safeBulkImportFilePattern, $normalizedImportName) !== 1 ||
            !HAXCMSFile::isValidBulkImportTmpPath($downloadLocation)
          ) {
            return array(
              '__failed' => array(
                'status' => 400,
                'message' => 'Invalid file import payload in build.files',
                'file' => $locationName,
              )
            );
          }
          $file = new HAXCMSFile();
          // check for a file upload; we block a few formats by design
          $fileResult = $file->save(Array(
            "name" => $normalizedImportName,
            "tmp_name" => $downloadLocation,
            "bulk-import" => TRUE
          ), $site);
        }
      }
      // main site schema doesn't care about publishing settings
      unset($schema->metadata->site->git);
      $git = new Git();
      $repo = $git->open(
          $site->directory . '/' . $site->manifest->metadata->site->name
      );
      $repo->add('.');
      $site->gitCommit(
          'A new journey begins: ' .
              $site->manifest->title .
              ' (' .
              $site->manifest->id .
              ')'
      );
      // make a branch but dont use it
      if (isset($site->manifest->metadata->site->git->staticBranch)) {
          $repo->create_branch(
              $site->manifest->metadata->site->git->staticBranch
          );
      }
      if (isset($site->manifest->metadata->site->git->branch)) {
          $repo->create_branch(
              $site->manifest->metadata->site->git->branch
          );
      }
      return array(
        "status" => 200,
        "data" => $schema
      );
    }
    else {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid request token',
        )
      );
    }
  }
}
