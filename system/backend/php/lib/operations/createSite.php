<?php
include_once dirname(__FILE__) . '/../SsrfGuard.php';
include_once dirname(__FILE__) . '/../stageRemoteFile.php';
include_once dirname(__FILE__) . '/../EntityRegistry.php';
include_once dirname(__FILE__) . '/../FileStorage.php';
include_once dirname(__FILE__) . '/../FileEntity.php';
include_once dirname(__FILE__) . '/../FileContentScanner.php';
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
        // fall back to the system-wide default language (set at install time
        // and editable via the Configuration admin panel) when present, else
        // the documented en-US default.
        $systemDefaultLang = 'en-US';
        if (
          isset($GLOBALS['HAXCMS']->config->localization) &&
          is_object($GLOBALS['HAXCMS']->config->localization) &&
          isset($GLOBALS['HAXCMS']->config->localization->defaultLanguage) &&
          is_string($GLOBALS['HAXCMS']->config->localization->defaultLanguage) &&
          $GLOBALS['HAXCMS']->config->localization->defaultLanguage !== ''
        ) {
          $systemDefaultLang = $GLOBALS['HAXCMS']->config->localization->defaultLanguage;
        }
        $schema->metadata->site->settings->lang = $systemDefaultLang;
      }
      if (!isset($schema->metadata->site->settings->publishPagesOn)) {
        $schema->metadata->site->settings->publishPagesOn = true;
      }
      if (!isset($schema->metadata->site->settings->canonical)) {
        $schema->metadata->site->settings->canonical = true;
      }
      if (!isset($schema->metadata->site->settings->pathauto)) {
        $schema->metadata->site->settings->pathauto = true;
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
      // walk through files if any came across and save each of them. build.files
      // is best-effort: a single bad/undownloadable entry must not fail the
      // whole site, so skipped entries are collected into $buildFileWarnings
      // (returned as data.warnings) and the site is still created. The
      // SSRF/extension/path guards inside importBuildFile still refuse to fetch
      // or write anything unsafe; they just no longer abort the request.
      $buildFileWarnings = array();
      if (is_array($filesToDownload)) {
        // one client for any remote files, configured as the importers do
        $client = new \GuzzleHttp\Client(['timeout' => 30, 'connect_timeout' => 10]);
        foreach ($filesToDownload as $locationName => $downloadLocation) {
          $this->importBuildFile($site, $locationName, $downloadLocation, $client, $buildFileWarnings);
        }
        if (count($filesToDownload) > 0) {
          $this->linkImportedPageFiles($site);
        }
      }
      // download user-customized theme and custom files (imported from another instance)
      if (isset($this->params['build']['siteFiles']) && is_array($this->params['build']['siteFiles'])) {
        foreach ($this->params['build']['siteFiles'] as $relativePath => $downloadUrl) {
          $normalizedPath = $this->normalizeSiteFilePath($relativePath);
          if ($normalizedPath === false) {
            continue;
          }
          if (!is_string($downloadUrl) || $downloadUrl === '') {
            continue;
          }
          // SSRF guard: reject private/loopback/link-local/metadata targets
          // before fetching, and disable redirects (matches the Node.js
          // safeFetch baseline; closes the redirect-to-metadata window).
          try {
            $content = SsrfGuard::safeFileGetContents($downloadUrl);
          } catch (SsrfGuardException $e) {
            $content = false;
          }
          if ($content !== false && $content !== '') {
            // CWE-434: verify the fetched content's MIME matches the target
            // extension before writing into the web-served site tree.
            if ($this->siteFileContentMimeAcceptable($content, $normalizedPath)) {
              $targetPath = $site->directory . '/' . $site->manifest->metadata->site->name . '/' . $normalizedPath;
              $targetDir = dirname($targetPath);
              if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
              }
              file_put_contents($targetPath, $content);
            }
          }
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
      // build the success response. Best-effort file-ingest warnings are
      // response-only metadata: surface them on the response envelope, NOT on
      // the $schema JSONOutlineSchemaItem. PHP 8.3 flags ad-hoc properties on a
      // typed class as a Deprecated notice ("Creation of dynamic property"),
      // and with display_errors on that notice prints to stdout before the
      // response headers are sent -> "headers already sent" -> no valid JSON
      // response (the site is already on disk by this point, so the user sees
      // "created but no response"). Keeping warnings off the schema item also
      // avoids polluting site.json, since JSONOutlineSchema::save() serializes
      // every declared/dynamic property of its items. The front-end creation
      // modal reads only status + data.slug/data.link/data.id; warnings are
      // optional and not consumed by it.
      $response = array(
        "status" => 200,
        "data" => $schema
      );
      if (count($buildFileWarnings) > 0) {
        $response['warnings'] = $buildFileWarnings;
      }
      return $response;
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
  /**
   * #3060: bring one build.files entry into the site. Importers hand over
   * remote files as http(s) URLs, so a URL is fetched through SsrfGuard into
   * the bulk-import staging root. From there every entry takes the same path:
   * the staged-path check, then a bulk-import HAXCMSFile::save that validates
   * the content and records the file entity in files.json. Ingestion is
   * best-effort: any entry that is unsafe or cannot be fetched/saved is skipped
   * rather than failing the site.
   *
   * Returns false for an invalid entry (unsafe name, disallowed extension, or
   * a source that is neither an http(s) URL nor a valid staged path) and true
   * otherwise (including a URL that could not be fetched or a file
   * HAXCMSFile::save rejected internally, where no entity is created). This
   * boolean contract is exercised directly by the unit suite, so it must not
   * change shape.
   *
   * When an optional `&$warnings` array is passed, every skip is recorded as
   * array('file'=>..., 'reason'=>...) so createSite can surface data.warnings
   * on its 200 response instead of aborting with 400. The collector is
   * intentionally optional so the boolean-only call path used by tests behaves
   * exactly as before. Mirrors importBuildFile in haxcms-nodejs createSite.js.
   */
  private function importBuildFile($site, $locationName, $downloadLocation, $client, &$warnings = null) {
    $normalizedImportName = $this->normalizeBulkImportName($locationName);
    if ($normalizedImportName === false) {
      if (is_array($warnings)) {
        $warnings[] = array('file' => $locationName, 'reason' => 'Invalid file name in build.files');
      }
      return false;
    }
    if (preg_match($this->safeBulkImportFilePattern, $normalizedImportName) !== 1) {
      if (is_array($warnings)) {
        $warnings[] = array('file' => $locationName, 'reason' => 'Disallowed file extension in build.files');
      }
      return false;
    }
    $downloaded = false;
    if (is_string($downloadLocation) && preg_match('/^https?:\/\//i', $downloadLocation) === 1) {
      $downloaded = haxcms_import_stage_remote_file($client, $downloadLocation, $normalizedImportName);
      if ($downloaded === false) {
        if (is_array($warnings)) {
          $warnings[] = array('file' => $locationName, 'reason' => 'Remote file could not be downloaded');
        }
        return true;
      }
      $downloadLocation = $downloaded;
    }
    $valid = HAXCMSFile::isValidBulkImportTmpPath($downloadLocation);
    if ($valid) {
      $file = new HAXCMSFile();
      // check for a file upload; we block a few formats by design. save() can
      // still reject the content (MIME/extension mismatch, over the size limit,
      // symlink TOCTOU, etc.) after the staged-path check passed; surface that
      // reason too so the caller knows why no entity was created.
      $saveResult = $file->save(Array(
        "name" => $normalizedImportName,
        "tmp_name" => $downloadLocation,
        "bulk-import" => TRUE
      ), $site);
      if (is_array($warnings) && is_array($saveResult) && isset($saveResult['status']) && $saveResult['status'] !== 200) {
        $reason = (is_string($saveResult['data']) && $saveResult['data'] !== '')
          ? $saveResult['data']
          : (is_array($saveResult['data']) && isset($saveResult['data']['message']) && is_string($saveResult['data']['message']) ? $saveResult['data']['message'] : 'File rejected during build.files import');
        $warnings[] = array('file' => $locationName, 'reason' => $reason);
      }
    }
    else if (is_array($warnings)) {
      $warnings[] = array('file' => $locationName, 'reason' => 'Invalid bulk import source path in build.files');
    }
    // save copies the file into the site, so a download is always removed
    if ($downloaded !== false) {
      @unlink($downloaded);
    }
    return $valid;
  }
  /**
   * #3043: point each page at the file entities its content references, once
   * the imported files exist. createSite writes the pages before it ingests
   * build.files, so the page metadata files cannot be set as each page is
   * written. Identity comes from files.json through the Entity API, as it
   * does for the docx import and for page saves; the FileStorage is created
   * after the ingest so it reads the records the ingest just wrote. Returns
   * the pages linked. Mirrors linkImportedPageFiles in haxcms-nodejs
   * createSite.js.
   */
  private function linkImportedPageFiles($site) {
    $fileStorage = FileStorage::registerOn(new EntityRegistry($site));
    $linked = 0;
    foreach ($site->manifest->items as $page) {
      $content = $site->getPageContent($page);
      $uuids = array();
      foreach (FileContentScanner::extractFileReferences($content) as $reference) {
        $uuid = $fileStorage->getDataStore()->resolveUuidByPath($reference);
        $entity = ($uuid !== '') ? $fileStorage->load($uuid) : null;
        if ($entity instanceof FileEntity && !in_array($entity->getUuid(), $uuids, true)) {
          $uuids[] = $entity->getUuid();
        }
      }
      if (count($uuids) > 0) {
        if (!isset($page->metadata) || !is_object($page->metadata)) {
          $page->metadata = new stdClass();
        }
        $page->metadata->files = $uuids;
        $linked++;
      }
    }
    if ($linked > 0) {
      $site->manifest->save(false);
    }
    return $linked;
  }
}
