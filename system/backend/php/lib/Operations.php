<?php
include_once "JSONOutlineSchemaItem.php";
include_once "SanitizeContent.php";
/**
 * @OA\Info(
 *     title="HAXcms API",
 *     version="",
 *     description="API for interfacing with HAXcms end points",
 *     termsOfService="https://haxtheweb.org",
 *     @OA\Contact(
 *       email="hax@psu.edu"
 *     ),
 *     @OA\License(
 *       name="Apache 2.0",
 *       url="http://www.apache.org/licenses/LICENSE-2.0.html"
 *     )
 * ),
 * @OA\ExternalDocumentation(
 *     description="HAXcms and all things HAX documentations",
 *     url="https://haxtheweb.org/"
 * ),
 * @OA\Tag(
 *     name="hax",
 *     description="Operations required for HAX editor to work",
 *     @OA\ExternalDocumentation(
 *         description="Find out more about hax editor integrations",
 *         url="https://haxtheweb.org/integrations/create-new-ones"
 *     )
 * ),
 * @OA\Tag(
 *     name="cms",
 *     description="Operations for the CMS side"
 * ),
 * @OA\Tag(
 *     name="site",
 *     description="Operations for sites"
 * ),
 * @OA\Tag(
 *     name="node",
 *     description="Operations for individual nodes in a site"
 * ),
 * @OA\Tag(
 *     name="file",
 *     description="Operations for files related to CMS or HAX"
 * ),
 * @OA\Tag(
 *     name="form",
 *     description="Operations related to form submission or generation"
 * ),
 * @OA\Tag(
 *     name="meta",
 *     description="Operations related to metadata management or processes"
 * ),
 * @OA\Tag(
 *     name="git",
 *     description="Operations related to git / version control of the site"
 * ),
 * @OA\Tag(
 *     name="user",
 *     description="Operations for the user account / object"
 * ),
 * @OA\Tag(
 *     name="api",
 *     description="endpoint to generate the API or surrounding API callbacks"
 * ),
 * @OA\Tag(
 *     name="settings",
 *     description="Internal settings related to configuration of this HAXcms deployment"
 * ),
 * @OA\Tag(
 *     name="authenticated",
 *     description="Operations requiring authentication"
 * )
 */
class Operations {
  public $params;
  public $rawParams;
  private $safeBulkImportFilePattern = '/\.(jpg|jpeg|png|gif|webm|webp|mp4|mp3|mov|csv|ppt|pptx|xlsx|doc|xls|docx|pdf|rtf|txt|vtt|html|md)$/i';
  private $imageScalePresets = array(
    'xs' => array('width' => 200, 'height' => 150),
    'sm' => array('width' => 320, 'height' => 240),
    'md' => array('width' => 400, 'height' => 300),
    'lg' => array('width' => 800, 'height' => 600),
    'xl' => array('width' => 1200, 'height' => 900),
  );
  private $allowedFileRenameExtensions = array(
    'jpg',
    'jpeg',
    'png',
    'gif',
    'webm',
    'webp',
    'mp4',
    'mp3',
    'mov',
    'csv',
    'ppt',
    'pptx',
    'xlsx',
    'doc',
    'xls',
    'docx',
    'pdf',
    'rtf',
    'txt',
    'vtt',
    'html',
    'md',
  );
  
  /**
   * Check if a platform capability is allowed
   * @param object $site The site object
   * @param string $capability The capability to check (e.g., 'delete', 'addPage', 'outlineDesigner', 'manifest')
   * @return bool Whether the capability is allowed
   */
  private function platformAllows($site, $capability) {
    if (
      !isset($site->manifest->metadata->platform) ||
      (!is_object($site->manifest->metadata->platform) && !is_array($site->manifest->metadata->platform))
    ) {
      return true;
    }
    $platform = $site->manifest->metadata->platform;
    $keyAliases = array(
      'uploadMedia' => array('uploadMedia', 'upload'),
      'onlineMedia' => array('onlineMedia', 'onlineSearch'),
      'deletePage' => array('deletePage', 'delete'),
      'delete' => array('deletePage', 'delete'),
      'siteManifest' => array('siteManifest', 'manifest'),
      'manifest' => array('siteManifest', 'manifest'),
    );
    $keys = isset($keyAliases[$capability]) ? $keyAliases[$capability] : array($capability);
    $sources = array();
    if (isset($platform->features) && (is_object($platform->features) || is_array($platform->features))) {
      $sources[] = (array) $platform->features;
    }
    $sources[] = (array) $platform;
    foreach ($sources as $source) {
      foreach ($keys as $key) {
        if (array_key_exists($key, $source) && is_bool($source[$key])) {
          return $source[$key] !== false;
        }
      }
    }
    return true;
  }
  /**
   * Detect scoped Details payloads that omit legacy form token fields.
   */
  private function isScopedDetailsManifestPayload($params) {
    if (!is_array($params)) {
      return false;
    }
    if (!isset($params['manifest']) || !is_array($params['manifest'])) {
      return false;
    }
    $manifestSite = array();
    if (isset($params['manifest']['site']) && is_array($params['manifest']['site'])) {
      $manifestSite = $params['manifest']['site'];
    }
    $manifestSeo = array();
    if (isset($params['manifest']['seo']) && is_array($params['manifest']['seo'])) {
      $manifestSeo = $params['manifest']['seo'];
    }
    $hasDetailsFields =
      array_key_exists('title', $params) ||
      array_key_exists('homePageId', $params) ||
      array_key_exists('sw', $params) ||
      array_key_exists('forceUpgrade', $params) ||
      array_key_exists('manifest-title', $manifestSite) ||
      array_key_exists('manifest-metadata-site-homePageId', $manifestSite) ||
      array_key_exists('manifest-metadata-site-settings-sw', $manifestSeo) ||
      array_key_exists('manifest-metadata-site-settings-forceUpgrade', $manifestSeo);
    if (!$hasDetailsFields) {
      return false;
    }
    return !isset($params['haxcms_form_id']) && !isset($params['haxcms_form_token']);
  }
  /**
   * Ensure metadata containers required for scoped manifest writes exist.
   */
  private function ensureSiteMetadataContainers($site) {
    if (!isset($site->manifest->metadata) || !is_object($site->manifest->metadata)) {
      $site->manifest->metadata = new stdClass();
    }
    if (!isset($site->manifest->metadata->site) || !is_object($site->manifest->metadata->site)) {
      $site->manifest->metadata->site = new stdClass();
    }
    if (!isset($site->manifest->metadata->site->settings) || !is_object($site->manifest->metadata->site->settings)) {
      $site->manifest->metadata->site->settings = new stdClass();
    }
  }
  /**
   * Apply scoped Details payload values to the site manifest.
   */
  private function applyScopedDetailsManifestPayload($site, $params) {
    $this->ensureSiteMetadataContainers($site);
    $manifestSite = array();
    if (isset($params['manifest']) && is_array($params['manifest']) && isset($params['manifest']['site']) && is_array($params['manifest']['site'])) {
      $manifestSite = $params['manifest']['site'];
    }
    $manifestSeo = array();
    if (isset($params['manifest']) && is_array($params['manifest']) && isset($params['manifest']['seo']) && is_array($params['manifest']['seo'])) {
      $manifestSeo = $params['manifest']['seo'];
    }

    $titleValue = null;
    $hasTitleValue = false;
    if (array_key_exists('manifest-title', $manifestSite)) {
      $titleValue = $manifestSite['manifest-title'];
      $hasTitleValue = true;
    }
    else if (array_key_exists('title', $params)) {
      $titleValue = $params['title'];
      $hasTitleValue = true;
    }
    if ($hasTitleValue) {
      $site->manifest->title = strip_tags(strval($titleValue));
    }

    $homePageIdValue = null;
    $hasHomePageIdValue = false;
    if (array_key_exists('manifest-metadata-site-homePageId', $manifestSite)) {
      $homePageIdValue = $manifestSite['manifest-metadata-site-homePageId'];
      $hasHomePageIdValue = true;
    }
    else if (array_key_exists('homePageId', $params)) {
      $homePageIdValue = $params['homePageId'];
      $hasHomePageIdValue = true;
    }
    if ($hasHomePageIdValue) {
      $homePageId = filter_var(strval($homePageIdValue), FILTER_SANITIZE_FULL_SPECIAL_CHARS);
      $validPage = false;
      if ($homePageId !== '' && isset($site->manifest->items) && $site->manifest->items) {
        foreach ($site->manifest->items as $item) {
          if (isset($item->id) && $item->id === $homePageId) {
            $validPage = true;
            break;
          }
        }
      }
      if ($validPage) {
        $site->manifest->metadata->site->homePageId = $homePageId;
      }
      else {
        if (isset($site->manifest->metadata->site->homePageId)) {
          unset($site->manifest->metadata->site->homePageId);
        }
        if (isset($site->manifest->metadata->site->settings->homePageId)) {
          unset($site->manifest->metadata->site->settings->homePageId);
        }
      }
    }

    $swValue = null;
    $hasSwValue = false;
    if (array_key_exists('manifest-metadata-site-settings-sw', $manifestSeo)) {
      $swValue = $manifestSeo['manifest-metadata-site-settings-sw'];
      $hasSwValue = true;
    }
    else if (array_key_exists('sw', $params)) {
      $swValue = $params['sw'];
      $hasSwValue = true;
    }
    if ($hasSwValue) {
      $site->manifest->metadata->site->settings->sw = filter_var(
        $swValue,
        FILTER_VALIDATE_BOOLEAN
      );
    }

    $forceUpgradeValue = null;
    $hasForceUpgradeValue = false;
    if (array_key_exists('manifest-metadata-site-settings-forceUpgrade', $manifestSeo)) {
      $forceUpgradeValue = $manifestSeo['manifest-metadata-site-settings-forceUpgrade'];
      $hasForceUpgradeValue = true;
    }
    else if (array_key_exists('forceUpgrade', $params)) {
      $forceUpgradeValue = $params['forceUpgrade'];
      $hasForceUpgradeValue = true;
    }
    if ($hasForceUpgradeValue) {
      $site->manifest->metadata->site->settings->forceUpgrade = filter_var(
        $forceUpgradeValue,
        FILTER_VALIDATE_BOOLEAN
      );
    }

    $site->manifest->metadata->site->version = $GLOBALS['HAXCMS']->getHAXCMSVersion();
  }
  /**
   * Validate that an associative array/object only contains approved keys.
   */
  private function hasOnlyAllowedKeys($value, $allowedKeys) {
    if (is_object($value)) {
      $value = (array) $value;
    }
    if (!is_array($value)) {
      return false;
    }
    foreach ($value as $key => $unused) {
      if (!in_array($key, $allowedKeys, true)) {
        return false;
      }
    }
    return true;
  }
  /**
   * Normalize css variable submitted by appearance settings.
   */
  private function normalizeAppearanceCssVariable($value) {
    if (!is_string($value)) {
      return false;
    }
    $value = filter_var($value, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    if (!is_string($value)) {
      return false;
    }
    $value = str_replace('--simple-colors-default-theme-', '', $value);
    $value = preg_replace('/-7$/', '', $value);
    $value = strtolower(trim($value));
    if ($value === '' || preg_match('/^[a-z0-9-]+$/', $value) !== 1) {
      return false;
    }
    return $value;
  }
  /**
   * Validate and sanitize region ids for appearance settings.
   */
  private function sanitizeAppearanceRegionIds($value) {
    if (!is_array($value)) {
      return false;
    }
    $cleanIds = array();
    foreach ($value as $id) {
      if (!is_string($id)) {
        return false;
      }
      $cleanId = filter_var($id, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
      if (!is_string($cleanId)) {
        return false;
      }
      $cleanId = trim($cleanId);
      if ($cleanId === '') {
        return false;
      }
      if (!in_array($cleanId, $cleanIds, true)) {
        $cleanIds[] = $cleanId;
      }
    }
    return $cleanIds;
  }
  /**
   * Validate and normalize a bulk import file name.
   */
  private function normalizeBulkImportName($locationName) {
    if (!is_string($locationName)) {
      return false;
    }
    $normalized = trim(str_replace('\\', '/', preg_replace('/^files\//', '', $locationName)));
    if (
      $normalized === '' ||
      strpos($normalized, "\0") !== false ||
      strpos($normalized, '..') !== false ||
      substr($normalized, 0, 1) === '/'
    ) {
      return false;
    }
    $parts = explode('/', $normalized);
    foreach ($parts as $part) {
      if ($part === '' || $part === '.' || $part === '..') {
        return false;
      }
    }
    return $normalized;
  }
  /**
   * Clone mixed data through JSON encoding into an associative array.
   */
  private function cloneTemplateArray($value, $fallback = array()) {
    $encoded = json_encode($value);
    if ($encoded === false) {
      return $fallback;
    }
    $decoded = json_decode($encoded, true);
    if (is_array($decoded)) {
      return $decoded;
    }
    return $fallback;
  }
  /**
   * Normalize a template machine name.
   */
  private function normalizeTemplateMachineName($value) {
    if (!is_string($value)) {
      return 'site-template';
    }
    $machineName = $GLOBALS['HAXCMS']->generateMachineName($value);
    if (!is_string($machineName) || trim($machineName) === '') {
      return 'site-template';
    }
    $machineName = strtolower(trim(preg_replace('/\\.json$/i', '', $machineName)));
    if ($machineName === '') {
      return 'site-template';
    }
    return $machineName;
  }
  /**
   * Normalize a value into a string array for tags/category fields.
   */
  private function normalizeTemplateArrayValue($value) {
    if (is_array($value)) {
      return $this->cloneTemplateArray($value, array());
    }
    if (is_string($value) && trim($value) !== '') {
      return array(trim($value));
    }
    return array();
  }
  /**
   * Normalize a page location for safe on-disk reads.
   */
  private function normalizeTemplateLocation($location) {
    if (!is_string($location)) {
      return '';
    }
    $normalized = str_replace('\\', '/', $location);
    $normalized = ltrim(trim($normalized), '/');
    if (
      $normalized === '' ||
      strpos($normalized, "\0") !== false ||
      strpos($normalized, '..') !== false
    ) {
      return '';
    }
    return $normalized;
  }
  /**
   * Read page content from disk for template generation.
   */
  private function readTemplateItemContent($siteDirectory, $item) {
    $location = '';
    if (is_array($item) && isset($item['location'])) {
      $location = $item['location'];
    }
    $safeLocation = $this->normalizeTemplateLocation($location);
    if ($safeLocation === '') {
      return '';
    }
    $targetPath = $siteDirectory . '/' . $safeLocation;
    if (!file_exists($targetPath) || !is_file($targetPath)) {
      return '';
    }
    $content = @file_get_contents($targetPath);
    if ($content === false) {
      return '';
    }
    return $content;
  }
  /**
   * Normalize item metadata defaults for template export.
   */
  private function normalizeTemplateItemMetadata($metadata) {
    $result = is_array($metadata)
      ? $this->cloneTemplateArray($metadata, array())
      : array();
    if (!isset($result['tags']) || !is_array($result['tags'])) {
      $result['tags'] = array();
    }
    if (!array_key_exists('published', $result)) {
      $result['published'] = true;
    }
    if (!array_key_exists('hideInMenu', $result)) {
      $result['hideInMenu'] = false;
    }
    return $result;
  }
  /**
   * Resolve theme information for template export.
   */
  private function resolveTemplateThemeData($manifestMetadata = array()) {
    $themeMetadata =
      isset($manifestMetadata['theme']) && is_array($manifestMetadata['theme'])
        ? $manifestMetadata['theme']
        : array();
    $defaultTheme = defined('HAXCMS_DEFAULT_THEME')
      ? HAXCMS_DEFAULT_THEME
      : 'clean-two';
    $themeElement =
      isset($themeMetadata['element']) &&
      is_string($themeMetadata['element']) &&
      trim($themeMetadata['element']) !== ''
        ? trim($themeMetadata['element'])
        : $defaultTheme;
    $themeVariables =
      isset($themeMetadata['variables']) && is_array($themeMetadata['variables'])
        ? $this->cloneTemplateArray($themeMetadata['variables'], array())
        : array();
    $themeSettings = array();
    $themes = $this->cloneTemplateArray($GLOBALS['HAXCMS']->getThemes(), array());
    if (isset($themes[$themeElement]) && is_array($themes[$themeElement])) {
      $themeSettings = $this->cloneTemplateArray($themes[$themeElement], array());
    }
    foreach ($themeMetadata as $key => $value) {
      if ($key !== 'element' && $key !== 'variables') {
        $themeSettings[$key] = $value;
      }
    }
    $useCaseImage =
      isset($themeSettings['thumbnail']) &&
      is_string($themeSettings['thumbnail']) &&
      trim($themeSettings['thumbnail']) !== ''
        ? $themeSettings['thumbnail']
        : '@haxtheweb/haxcms-elements/lib/theme-screenshots/theme-' . $themeElement . '-thumb.jpg';
    return array(
      'themeElement' => $themeElement,
      'themeVariables' => $themeVariables,
      'themeSettings' => $themeSettings,
      'useCaseImage' => $useCaseImage,
    );
  }
  private function sanitizeFileRenameBaseName($value) {
    if (!is_string($value)) {
      return '';
    }
    $decodedValue = trim(urldecode($value));
    $decodedValue = strtolower($decodedValue);
    $decodedValue = preg_replace('/[^a-z0-9-]/', '-', $decodedValue);
    $decodedValue = preg_replace('/-+/', '-', $decodedValue);
    return trim($decodedValue, '-');
  }
  private function getAllowedRenameExtension($value) {
    $extension = strtolower(trim(ltrim(strval($value), '.')));
    if ($extension === '') {
      return '';
    }
    if (!preg_match('/^[a-z0-9]+$/', $extension)) {
      return '';
    }
    if (!in_array($extension, $this->allowedFileRenameExtensions, true)) {
      return '';
    }
    return $extension;
  }
  private function buildRenamedFilePath($pathResult, $requestedName) {
    if (!is_string($requestedName)) {
      return array(
        'valid' => false,
        'status' => 400,
        'message' => 'New file name is required',
      );
    }
    $sourceFileName = basename($pathResult['normalizedPath']);
    $sourceExtension = $this->getAllowedRenameExtension(
      pathinfo($sourceFileName, PATHINFO_EXTENSION)
    );
    if ($sourceExtension === '') {
      return array(
        'valid' => false,
        'status' => 400,
        'message' => 'Current file extension is not allowed for rename',
      );
    }
    $normalizedInput = trim(urldecode($requestedName));
    if ($normalizedInput === '') {
      return array(
        'valid' => false,
        'status' => 400,
        'message' => 'New file name is required',
      );
    }
    $segments = explode('.', $normalizedInput);
    if (count($segments) > 2) {
      return array(
        'valid' => false,
        'status' => 400,
        'message' => 'File name can only include one extension',
      );
    }
    $baseInput = $normalizedInput;
    $extensionInput = '';
    if (count($segments) === 2) {
      $baseInput = $segments[0];
      $extensionInput = $segments[1];
    }
    $safeBaseName = $this->sanitizeFileRenameBaseName($baseInput);
    if ($safeBaseName === '') {
      return array(
        'valid' => false,
        'status' => 400,
        'message' => 'New file name must include at least one alphanumeric character',
      );
    }
    if ($extensionInput !== '') {
      $allowedInputExtension = $this->getAllowedRenameExtension($extensionInput);
      if ($allowedInputExtension === '') {
        return array(
          'valid' => false,
          'status' => 400,
          'message' => 'Requested extension is not allowed',
        );
      }
      if ($allowedInputExtension !== $sourceExtension) {
        return array(
          'valid' => false,
          'status' => 400,
          'message' => 'Extension cannot be changed during rename and must remain .' . $sourceExtension,
        );
      }
    }
    $sourceDirectory = dirname($pathResult['normalizedPath']);
    $outputFileName = $safeBaseName . '.' . $sourceExtension;
    $relativeOutputPath = ($sourceDirectory === '.' || $sourceDirectory === '')
      ? $outputFileName
      : $sourceDirectory . '/' . $outputFileName;
    $relativeOutputPath = ltrim($this->normalizeFilePathValue($relativeOutputPath), '/');
    $outputPath = dirname($pathResult['resolvedPath']) . '/' . $outputFileName;
    $normalizedOutputPath = rtrim($this->normalizeFilePathValue($outputPath), '/');
    if (
      $normalizedOutputPath !== $pathResult['filesRoot'] &&
      strpos($normalizedOutputPath, $pathResult['filesRoot'] . '/') !== 0
    ) {
      return array(
        'valid' => false,
        'status' => 403,
        'message' => 'Renamed file path is outside of allowed files directory',
      );
    }
    if ($relativeOutputPath === $pathResult['normalizedPath']) {
      return array(
        'valid' => false,
        'status' => 400,
        'message' => 'New file name must be different from current name',
      );
    }
    if (file_exists($outputPath)) {
      return array(
        'valid' => false,
        'status' => 400,
        'message' => 'A file with this name already exists',
      );
    }
    return array(
      'valid' => true,
      'outputPath' => $outputPath,
      'relativePath' => $relativeOutputPath,
    );
  }
  /**
   * Build source URL for a site template payload.
   */
  private function getTemplateSourceUrl($siteName) {
    $basePath = $GLOBALS['HAXCMS']->basePath;
    if (!is_string($basePath) || $basePath === '') {
      $basePath = '/';
    }
    if (substr($basePath, -1) !== '/') {
      $basePath .= '/';
    }
    $sitesDirectory = trim((string) $GLOBALS['HAXCMS']->sitesDirectory, '/');
    return $basePath . $sitesDirectory . '/' . $siteName . '/';
  }
  /**
   * Generate a complete template skeleton payload from a site.
   */
  private function buildSiteTemplateSkeleton($site) {
    if (!$site || !isset($site->manifest)) {
      throw new Exception('Invalid site requested');
    }
    $manifest = $site->manifest;
    $manifestMetadata = isset($manifest->metadata)
      ? $this->cloneTemplateArray($manifest->metadata, array())
      : array();
    $siteMetadata =
      isset($manifestMetadata['site']) && is_array($manifestMetadata['site'])
        ? $manifestMetadata['site']
        : array();
    $siteNameSource =
      isset($siteMetadata['name']) &&
      is_string($siteMetadata['name']) &&
      trim($siteMetadata['name']) !== ''
        ? $siteMetadata['name']
        : (isset($site->name) ? (string) $site->name : 'site-template');
    $siteName = $this->normalizeTemplateMachineName($siteNameSource);
    $siteTitle =
      isset($manifest->title) && is_string($manifest->title) && trim($manifest->title) !== ''
        ? $manifest->title
        : $siteName;
    $siteDescription =
      isset($manifest->description) &&
      is_string($manifest->description) &&
      trim($manifest->description) !== ''
        ? 'Template based on ' . $manifest->description
        : 'Template based on ' . $siteTitle;
    $siteSettings =
      isset($siteMetadata['settings']) && is_array($siteMetadata['settings'])
        ? $this->cloneTemplateArray($siteMetadata['settings'], array())
        : array();
    if (!isset($siteSettings['lang']) || $siteSettings['lang'] === '') {
      $siteSettings['lang'] = 'en-US';
    }
    if (!array_key_exists('publishPagesOn', $siteSettings)) {
      $siteSettings['publishPagesOn'] = true;
    }
    if (!array_key_exists('canonical', $siteSettings)) {
      $siteSettings['canonical'] = true;
    }
    $platformSettings =
      isset($manifestMetadata['platform']) && is_array($manifestMetadata['platform'])
        ? $this->cloneTemplateArray($manifestMetadata['platform'], array())
        : array();
    $category = $this->normalizeTemplateArrayValue(
      isset($siteMetadata['category']) ? $siteMetadata['category'] : array()
    );
    $tags = $this->normalizeTemplateArrayValue(
      isset($siteMetadata['tags']) ? $siteMetadata['tags'] : array()
    );
    $themeData = $this->resolveTemplateThemeData($manifestMetadata);
    $siteDirectory = $site->directory . '/' . $siteName;
    $sourceUrl = $this->getTemplateSourceUrl($siteName);
    $rawItems = isset($manifest->items) && is_array($manifest->items)
      ? $manifest->items
      : array();
    usort($rawItems, function ($a, $b) {
      $aOrder = (is_object($a) && isset($a->order) && is_numeric($a->order))
        ? (int) $a->order
        : PHP_INT_MAX;
      $bOrder = (is_object($b) && isset($b->order) && is_numeric($b->order))
        ? (int) $b->order
        : PHP_INT_MAX;
      if ($aOrder === $bOrder) {
        return 0;
      }
      return ($aOrder < $bOrder) ? -1 : 1;
    });
    $structure = array();
    foreach ($rawItems as $index => $item) {
      $itemData = $this->cloneTemplateArray($item, array());
      $id =
        isset($itemData['id']) &&
        is_string($itemData['id']) &&
        trim($itemData['id']) !== ''
          ? $itemData['id']
          : $GLOBALS['HAXCMS']->generateUUID();
      $title =
        isset($itemData['title']) &&
        is_string($itemData['title']) &&
        trim($itemData['title']) !== ''
          ? $itemData['title']
          : 'Page ' . ($index + 1);
      $slug =
        isset($itemData['slug']) &&
        is_string($itemData['slug']) &&
        trim($itemData['slug']) !== ''
          ? $itemData['slug']
          : 'page-' . ($index + 1);
      $parent =
        isset($itemData['parent']) && $itemData['parent'] !== ''
          ? $itemData['parent']
          : null;
      $order =
        isset($itemData['order']) && is_numeric($itemData['order'])
          ? (int) $itemData['order']
          : (int) $index;
      $indent =
        isset($itemData['indent']) && is_numeric($itemData['indent'])
          ? (int) $itemData['indent']
          : 0;
      $metadata = $this->normalizeTemplateItemMetadata(
        isset($itemData['metadata']) ? $itemData['metadata'] : array()
      );
      $content = $this->readTemplateItemContent($siteDirectory, $itemData);
      $structure[] = array(
        'id' => $id,
        'title' => $title,
        'slug' => $slug,
        'order' => $order,
        'parent' => $parent,
        'indent' => $indent,
        'content' => $content,
        'metadata' => $metadata,
      );
    }
    $skeleton = array(
      'meta' => array(
        'name' => $siteName,
        'machineName' => $siteName,
        'priority' => 0,
        'description' => $siteDescription,
        'version' => '1.0.0',
        'created' => gmdate('c'),
        'type' => 'skeleton',
        'sourceUrl' => $sourceUrl,
        'useCaseTitle' => $siteTitle,
        'useCaseDescription' => $siteDescription,
        'useCaseImage' => $themeData['useCaseImage'],
        'category' => $category,
        'tags' => $tags,
        'attributes' => array(),
      ),
      'site' => array(
        'name' => $siteName,
        'description' => $siteDescription,
        'theme' => $themeData['themeElement'],
        'settings' => $siteSettings,
        'platform' => $platformSettings,
      ),
      'build' => array(
        'type' => 'skeleton',
        'structure' => 'from-skeleton',
        'items' => $structure,
        'files' => array(),
      ),
      'theme' => $themeData['themeSettings'],
      '_skeleton' => array(
        'originalMetadata' => array(
          'site' => array(
            'category' => $category,
            'tags' => $tags,
            'settings' => $siteSettings,
          ),
          'licensing' => isset($manifestMetadata['licensing']) && is_array($manifestMetadata['licensing'])
            ? $manifestMetadata['licensing']
            : array(),
          'node' => isset($manifestMetadata['node']) && is_array($manifestMetadata['node'])
            ? $manifestMetadata['node']
            : array(),
          'platform' => $platformSettings,
        ),
        'originalSettings' => $siteSettings,
        'fullThemeConfig' => array(
          'element' => $themeData['themeElement'],
          'variables' => $themeData['themeVariables'],
          'settings' => $themeData['themeSettings'],
        ),
      ),
    );
    if (isset($manifest->license) && is_string($manifest->license) && trim($manifest->license) !== '') {
      $skeleton['site']['license'] = $manifest->license;
    }
    return $skeleton;
  }
  /**
   * Normalize and validate an outline page location.
   */
  private function normalizeOutlineLocation($location) {
    if (!is_string($location)) {
      return false;
    }
    $normalized = str_replace('\\', '/', $location);
    $normalized = trim($normalized);
    if (
      $normalized === '' ||
      strpos($normalized, "\0") !== false ||
      substr($normalized, 0, 1) === '/'
    ) {
      return false;
    }
    $parts = explode('/', $normalized);
    foreach ($parts as $part) {
      if ($part === '' || $part === '.' || $part === '..') {
        return false;
      }
    }
    if (!isset($parts[0]) || ($parts[0] !== 'pages' && $parts[0] !== 'content')) {
      return false;
    }
    return implode('/', $parts);
  }
  /**
   * Ensure a write target resolves to an existing file within the site root.
   */
  private function getValidatedOutlineWriteTarget($siteDirectory, $location) {
    $normalizedLocation = $this->normalizeOutlineLocation($location);
    if ($normalizedLocation === false) {
      return false;
    }
    $siteRoot = realpath($siteDirectory);
    if ($siteRoot === false || !is_dir($siteRoot)) {
      return false;
    }
    $targetPath = $siteRoot . '/' . $normalizedLocation;
    if (!file_exists($targetPath) || !is_file($targetPath)) {
      return false;
    }
    $targetRealPath = realpath($targetPath);
    if ($targetRealPath === false) {
      return false;
    }
    if (
      $targetRealPath !== $siteRoot &&
      strpos($targetRealPath, $siteRoot . DIRECTORY_SEPARATOR) !== 0
    ) {
      return false;
    }
    return $targetRealPath;
  }
  /**
   * Validate that saved content appears to be HTML.
   */
  private function isLikelyHtmlContent($content) {
    if (!is_string($content)) {
      return false;
    }
    $trimmed = trim($content);
    if ($trimmed === '') {
      return false;
    }
    return preg_match('/<([a-zA-Z][a-zA-Z0-9-]*)([[:space:]][^>]*)?>/', $trimmed) === 1;
  }
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
    return get_class_methods($this);
  }
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
    // scan this document in order to build the Swagger docs
    // @todo make this scan multiple sources to surface user defined microservices
    $openapi = \OpenApi\scan(dirname(__FILE__) . '/Operations.php');
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
  /**
   * @OA\Post(
   *    path="/saveManifest",
   *    tags={"cms","authenticated"},
   *    @OA\Parameter(
   *         name="site_token",
   *         description="Site-specific validation token",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Save the manifest of the site"
   *   )
   * )
   */
  public function saveManifest() {
    if (isset($this->params['site_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['site_token'], $GLOBALS['HAXCMS']->getActiveUserName() . ':' . $this->params['site']['name'])) {
      // load the site from name
      $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);

      // preserve existing platform settings regardless of what the client sends
      // (platform settings are saved via savePlatformSettings)
      $existingPlatform = null;
      if (isset($site->manifest->metadata) && isset($site->manifest->metadata->platform)) {
        $existingPlatform = $site->manifest->metadata->platform;
      }
      
      // Check platform configuration
      if (!$this->platformAllows($site, 'siteManifest')) {
        return array(
          '__failed' => array(
            'status' => 403,
            'message' => 'Manifest editing is disabled for this site',
          )
        );
      }
      // standard form submit
      // @todo 
      // make the form point to a form submission endpoint with appropriate name
      // add a hidden field to the output that always has the haxcms_form_id as well
      // as a dynamically generated Request token relative to the name of the
      // form
      // pull the form schema for the form itself internally
      // ensure ONLY the things that appear in that schema get set
      // if something DID NOT COME ACROSS, don't unset it, only set what shows up
      // if something DID COME ACROSS WE DIDN'T SET, kill the transaction (xss)

      // - snag the form
      // @todo see if we can dynamically save the valus in the same format we loaded
      // the original form in. This would involve removing the vast majority of
      // what's below
      /*if ($GLOBALS['HAXCMS']->validateRequestToken(null, 'form')) {
        $context = array(
          'site' => array(),
          'node' => array(),
        );
        if (isset($this->params['site'])) {
          $context['site'] = $this->params['site'];
        }
        if (isset($this->params['node'])) {
          $context['node'] = $this->params['node'];
        }
        $form = $GLOBALS['HAXCMS']->loadForm($this->params['haxcms_form_id'], $context);
      }*/
      $isScopedDetailsPayload = $this->isScopedDetailsManifestPayload($this->params);
      $formToken = isset($this->params['haxcms_form_token']) ? $this->params['haxcms_form_token'] : null;
      $formId = isset($this->params['haxcms_form_id']) ? $this->params['haxcms_form_id'] : null;
      if ($isScopedDetailsPayload || $GLOBALS['HAXCMS']->validateRequestToken($formToken, $formId)) {
        if ($isScopedDetailsPayload) {
          $this->applyScopedDetailsManifestPayload($site, $this->params);
        }
        else {
        $site->manifest->title = strip_tags(
            $this->params['manifest']['site']['manifest-title']
        );
        $site->manifest->description = strip_tags(
            $this->params['manifest']['site']['manifest-description']
        );
        // store version data here so we know where we were when last globally saved
        $site->manifest->metadata->site->version = $GLOBALS['HAXCMS']->getHAXCMSVersion();
        $site->manifest->metadata->site->domain = filter_var(
            $this->params['manifest']['site']['manifest-metadata-site-domain'],
            FILTER_SANITIZE_URL
        );
        $site->manifest->metadata->site->domain = SanitizeContent::sanitizeURLValue(
          $site->manifest->metadata->site->domain,
          ''
        );
        $site->manifest->metadata->site->logo = filter_var(
            $this->params['manifest']['site']['manifest-metadata-site-logo'],
            FILTER_SANITIZE_URL
        );
        $site->manifest->metadata->site->logo = SanitizeContent::sanitizeURLValue(
          $site->manifest->metadata->site->logo,
          ''
        );
        $site->manifest->metadata->site->tags = filter_var(
          $this->params['manifest']['site']['manifest-metadata-site-tags'],
          FILTER_SANITIZE_FULL_SPECIAL_CHARS
        );
        if (!isset($site->manifest->metadata->site->static)) {
          $site->manifest->metadata->site->static = new stdClass();
        }
        if (!isset($site->manifest->metadata->site->settings)) {
          $site->manifest->metadata->site->settings = new stdClass();
        }
        if (isset($this->params['manifest']['site']['manifest-domain'])) {
            $domain = filter_var(
                $this->params['manifest']['site']['manifest-domain'],
                FILTER_SANITIZE_URL
            );
            $domain = SanitizeContent::sanitizeURLValue($domain, '');
            // support updating the domain CNAME value
            if ($site->manifest->metadata->site->domain != $domain) {
                $site->manifest->metadata->site->domain = $domain;
                @file_put_contents(
                    $site->directory .
                        '/' .
                        $site->manifest->site->name .
                        '/CNAME',
                    $domain
                );
            }
        }
        // look for a match so we can set the correct data
        foreach ($GLOBALS['HAXCMS']->getThemes() as $key => $theme) {
          if (
              filter_var($this->params['manifest']['theme']['manifest-metadata-theme-element'], FILTER_SANITIZE_FULL_SPECIAL_CHARS) ==
              $key
          ) {
              $site->manifest->metadata->theme = $theme;
          }
        }
        if (!isset($site->manifest->metadata->theme->variables)) {
          $site->manifest->metadata->theme->variables = new stdClass();
        }
        if (isset($this->params['manifest']['theme']['manifest-metadata-theme-variables-image'])) {
          $site->manifest->metadata->theme->variables->image = filter_var(
            $this->params['manifest']['theme']['manifest-metadata-theme-variables-image'],FILTER_SANITIZE_URL
          );
          $site->manifest->metadata->theme->variables->image = SanitizeContent::sanitizeURLValue(
            $site->manifest->metadata->theme->variables->image,
            ''
          );
        }
        if (isset($this->params['manifest']['theme']['manifest-metadata-theme-variables-imageAlt'])) {
          $site->manifest->metadata->theme->variables->imageAlt = filter_var(
            $this->params['manifest']['theme']['manifest-metadata-theme-variables-imageAlt'], FILTER_SANITIZE_FULL_SPECIAL_CHARS
          );
        }
        if (isset($this->params['manifest']['theme']['manifest-metadata-theme-variables-imageLink'])) {
          $site->manifest->metadata->theme->variables->imageLink = filter_var(
            $this->params['manifest']['theme']['manifest-metadata-theme-variables-imageLink'], FILTER_SANITIZE_URL
          );
          $site->manifest->metadata->theme->variables->imageLink = SanitizeContent::sanitizeURLValue(
            $site->manifest->metadata->theme->variables->imageLink,
            ''
          );
        }
        // REGIONS SUPPORT
        if (!isset($site->manifest->metadata->theme->regions)) {
          $site->manifest->metadata->theme->regions = new stdClass();
        }
        // look for a match so we can set the correct data
        $validRegions = array(
          "header",
          "sidebarFirst",
          "sidebarSecond",
          "contentTop",
          "contentBottom",
          "footerPrimary",
          "footerSecondary"
        );
        foreach ($validRegions as $i => $value) {
          if (isset($this->params['manifest']['theme']['manifest-metadata-theme-regions-' . $value])) {
            foreach ($this->params['manifest']['theme']['manifest-metadata-theme-regions-' . $value] as $j => $id) {
              $this->params['manifest']['theme']['manifest-metadata-theme-regions-' . $value][$j] = filter_var($id, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            }
            $site->manifest->metadata->theme->regions->{$value} = $this->params['manifest']['theme']['manifest-metadata-theme-regions-' . $value];
          }
        }
        if (isset($this->params['manifest']['theme']['manifest-metadata-theme-variables-hexCode'])) {
          $site->manifest->metadata->theme->variables->hexCode = filter_var(
            $this->params['manifest']['theme']['manifest-metadata-theme-variables-hexCode'],FILTER_SANITIZE_FULL_SPECIAL_CHARS
          );
        }
        $site->manifest->metadata->theme->variables->cssVariable = "--simple-colors-default-theme-" . filter_var(
          $this->params['manifest']['theme']['manifest-metadata-theme-variables-cssVariable'], FILTER_SANITIZE_FULL_SPECIAL_CHARS
        ). "-7";
        if (isset($this->params['manifest']['theme']['manifest-metadata-theme-variables-palette'])) {
          $palette = filter_var(
            $this->params['manifest']['theme']['manifest-metadata-theme-variables-palette'],
            FILTER_SANITIZE_FULL_SPECIAL_CHARS
          );
          if (is_string($palette)) {
            $palette = strtolower(trim($palette));
            if ($palette === '') {
              if (isset($site->manifest->metadata->theme->variables->palette)) {
                unset($site->manifest->metadata->theme->variables->palette);
              }
            }
            else if (preg_match('/^[a-z0-9-]+$/', $palette)) {
              $site->manifest->metadata->theme->variables->palette = $palette;
            }
          }
        }
        $site->manifest->metadata->theme->variables->icon = filter_var(
          $this->params['manifest']['theme']['manifest-metadata-theme-variables-icon'],FILTER_SANITIZE_FULL_SPECIAL_CHARS
        );
        if (isset($this->params['manifest']['author']['manifest-license'])) {
            $site->manifest->license = filter_var(
                $this->params['manifest']['author']['manifest-license'],
                FILTER_SANITIZE_FULL_SPECIAL_CHARS
            );
            if (!isset($site->manifest->metadata->author)) {
              $site->manifest->metadata->author = new stdClass();
            }
            $site->manifest->metadata->author->image = filter_var(
                $this->params['manifest']['author']['manifest-metadata-author-image'],
                FILTER_SANITIZE_URL
            );
            $site->manifest->metadata->author->image = SanitizeContent::sanitizeURLValue(
              $site->manifest->metadata->author->image,
              ''
            );
            $site->manifest->metadata->author->name = filter_var(
                $this->params['manifest']['author']['manifest-metadata-author-name'],
                FILTER_SANITIZE_FULL_SPECIAL_CHARS
            );
            $site->manifest->metadata->author->email = filter_var(
                $this->params['manifest']['author']['manifest-metadata-author-email'],
                FILTER_SANITIZE_EMAIL
            );
            $site->manifest->metadata->author->socialLink = filter_var(
                $this->params['manifest']['author']['manifest-metadata-author-socialLink'],
                FILTER_SANITIZE_URL
            );
            $site->manifest->metadata->author->socialLink = SanitizeContent::sanitizeURLValue(
              $site->manifest->metadata->author->socialLink,
              ''
            );
        }
        if (isset($this->params['manifest']['seo']['manifest-metadata-site-settings-private'])) {
          $site->manifest->metadata->site->settings->private = filter_var(
          $this->params['manifest']['seo']['manifest-metadata-site-settings-private'],
          FILTER_VALIDATE_BOOLEAN
          );
        }
        if (isset($this->params['manifest']['seo']['manifest-metadata-site-settings-canonical'])) {
          $site->manifest->metadata->site->settings->canonical = filter_var(
          $this->params['manifest']['seo']['manifest-metadata-site-settings-canonical'],
          FILTER_VALIDATE_BOOLEAN
          );
        }
        if (isset($this->params['manifest']['seo']['manifest-metadata-site-settings-lang'])) {
          $site->manifest->metadata->site->settings->lang = filter_var(
          $this->params['manifest']['seo']['manifest-metadata-site-settings-lang'],
          FILTER_SANITIZE_FULL_SPECIAL_CHARS
          );
        }
        if (isset($this->params['manifest']['seo']['manifest-metadata-site-settings-pathauto'])) {
          $site->manifest->metadata->site->settings->pathauto = filter_var(
          $this->params['manifest']['seo']['manifest-metadata-site-settings-pathauto'],
          FILTER_VALIDATE_BOOLEAN
          );
        }
        if (isset($this->params['manifest']['seo']['manifest-metadata-site-settings-publishPagesOn'])) {
          $site->manifest->metadata->site->settings->publishPagesOn = filter_var(
          $this->params['manifest']['seo']['manifest-metadata-site-settings-publishPagesOn'],
          FILTER_VALIDATE_BOOLEAN
          );
        }
        if (isset($this->params['manifest']['seo']['manifest-metadata-site-settings-sw'])) {
          $site->manifest->metadata->site->settings->sw = filter_var(
          $this->params['manifest']['seo']['manifest-metadata-site-settings-sw'],
          FILTER_VALIDATE_BOOLEAN
          );
        }
        if (isset($this->params['manifest']['seo']['manifest-metadata-site-settings-forceUpgrade'])) {
          $site->manifest->metadata->site->settings->forceUpgrade = filter_var(
          $this->params['manifest']['seo']['manifest-metadata-site-settings-forceUpgrade'],
          FILTER_VALIDATE_BOOLEAN
          );
        }
        if (isset($this->params['manifest']['seo']['manifest-metadata-site-settings-gaID'])) {
          $site->manifest->metadata->site->settings->gaID = filter_var(
          $this->params['manifest']['seo']['manifest-metadata-site-settings-gaID'],
          FILTER_SANITIZE_FULL_SPECIAL_CHARS
          );
        }
        // Handle homepage setting - validate it exists in the site outline
        if (isset($this->params['manifest']['site']['manifest-metadata-site-homePageId'])) {
          $homePageId = filter_var(
            $this->params['manifest']['site']['manifest-metadata-site-homePageId'],
            FILTER_SANITIZE_FULL_SPECIAL_CHARS
          );
          // Validate that the page exists in the site manifest
          $validPage = false;
          if ($homePageId && $homePageId !== '' && $site->manifest->items) {
            foreach ($site->manifest->items as $item) {
              if ($item->id === $homePageId) {
                $validPage = true;
                break;
              }
            }
          }
          // Only set if valid, otherwise leave as null/unset
          if ($validPage) {
            $site->manifest->metadata->site->homePageId = $homePageId;
          } else {
            // Remove the setting if it was previously set but is now invalid
            if (isset($site->manifest->metadata->site->homePageId)) {
              unset($site->manifest->metadata->site->homePageId);
            }
            // Also remove from settings path in case it was previously saved there
            if (isset($site->manifest->metadata->site->settings->homePageId)) {
              unset($site->manifest->metadata->site->settings->homePageId);
            }
          }
        }
        }
        // ensure platform exists; do not overwrite existing platform settings
        if (!isset($site->manifest->metadata->platform)) {
          $site->manifest->metadata->platform = new stdClass();
          $site->manifest->metadata->platform->audience = 'expert';
          $site->manifest->metadata->platform->features = new stdClass();
          $site->manifest->metadata->platform->allowedBlocks = array();
        }
        if (!is_null($existingPlatform)) {
          $site->manifest->metadata->platform = $existingPlatform;
        }

        $site->manifest->metadata->site->updated = time();
        // don't reorganize the structure
        $site->manifest->save(false);
        $site->gitCommit('Manifest updated');
        // rebuild the files that twig processes
        $site->rebuildManagedFiles();
        $site->updateAlternateFormats();
        $site->gitCommit('Managed files updated');
        return $site->manifest;
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
    else {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid site token',
        )
      );
    }
  }

  /**
   * @OA\Post(
   *    path="/saveSeoSettings",
   *    tags={"cms","authenticated"},
   *    @OA\Parameter(
   *         name="site_token",
   *         description="Site-specific validation token",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Save SEO and author settings into site.json"
   *   )
   * )
   */
  public function saveSeoSettings() {
    if (!isset($this->params['site']) || !isset($this->params['site']['name'])) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'missing site name',
        )
      );
    }
    if (isset($this->params['site_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['site_token'], $GLOBALS['HAXCMS']->getActiveUserName() . ':' . $this->params['site']['name'])) {
      $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
      if (!$this->platformAllows($site, 'seoManifest')) {
        return array(
          '__failed' => array(
            'status' => 403,
            'message' => 'SEO settings are disabled for this site',
          )
        );
      }
      if (!isset($site->manifest->metadata)) {
        $site->manifest->metadata = new stdClass();
      }
      if (!isset($site->manifest->metadata->site)) {
        $site->manifest->metadata->site = new stdClass();
      }
      if (!isset($site->manifest->metadata->site->settings)) {
        $site->manifest->metadata->site->settings = new stdClass();
      }
      if (!isset($site->manifest->metadata->author)) {
        $site->manifest->metadata->author = new stdClass();
      }

      $author = array();
      if (isset($this->params['author']) && is_array($this->params['author'])) {
        $author = $this->params['author'];
      }
      $seo = array();
      if (isset($this->params['seo']) && is_array($this->params['seo'])) {
        $seo = $this->params['seo'];
      }
      $manifestAuthor = array();
      if (
        isset($this->params['manifest']) &&
        isset($this->params['manifest']['author']) &&
        is_array($this->params['manifest']['author'])
      ) {
        $manifestAuthor = $this->params['manifest']['author'];
      }
      $manifestSeo = array();
      if (
        isset($this->params['manifest']) &&
        isset($this->params['manifest']['seo']) &&
        is_array($this->params['manifest']['seo'])
      ) {
        $manifestSeo = $this->params['manifest']['seo'];
      }

      $licenseValue = null;
      if (array_key_exists('license', $author)) {
        $licenseValue = $author['license'];
      }
      else if (array_key_exists('manifest.license', $manifestAuthor)) {
        $licenseValue = $manifestAuthor['manifest.license'];
      }
      if (!is_null($licenseValue)) {
        $site->manifest->license = filter_var(
          strval($licenseValue),
          FILTER_SANITIZE_FULL_SPECIAL_CHARS
        );
      }

      $authorImageValue = null;
      if (array_key_exists('image', $author)) {
        $authorImageValue = $author['image'];
      }
      else if (array_key_exists('manifest.metadata.author.image', $manifestAuthor)) {
        $authorImageValue = $manifestAuthor['manifest.metadata.author.image'];
      }
      if (!is_null($authorImageValue)) {
        $site->manifest->metadata->author->image = filter_var(
          strval($authorImageValue),
          FILTER_SANITIZE_URL
        );
        $site->manifest->metadata->author->image = SanitizeContent::sanitizeURLValue(
          $site->manifest->metadata->author->image,
          ''
        );
      }

      $authorNameValue = null;
      if (array_key_exists('name', $author)) {
        $authorNameValue = $author['name'];
      }
      else if (array_key_exists('manifest.metadata.author.name', $manifestAuthor)) {
        $authorNameValue = $manifestAuthor['manifest.metadata.author.name'];
      }
      if (!is_null($authorNameValue)) {
        $site->manifest->metadata->author->name = filter_var(
          strval($authorNameValue),
          FILTER_SANITIZE_FULL_SPECIAL_CHARS
        );
      }

      $authorEmailValue = null;
      if (array_key_exists('email', $author)) {
        $authorEmailValue = $author['email'];
      }
      else if (array_key_exists('manifest.metadata.author.email', $manifestAuthor)) {
        $authorEmailValue = $manifestAuthor['manifest.metadata.author.email'];
      }
      if (!is_null($authorEmailValue)) {
        $site->manifest->metadata->author->email = filter_var(
          strval($authorEmailValue),
          FILTER_SANITIZE_EMAIL
        );
      }

      $authorSocialLinkValue = null;
      if (array_key_exists('socialLink', $author)) {
        $authorSocialLinkValue = $author['socialLink'];
      }
      else if (array_key_exists('manifest.metadata.author.socialLink', $manifestAuthor)) {
        $authorSocialLinkValue = $manifestAuthor['manifest.metadata.author.socialLink'];
      }
      if (!is_null($authorSocialLinkValue)) {
        $site->manifest->metadata->author->socialLink = filter_var(
          strval($authorSocialLinkValue),
          FILTER_SANITIZE_URL
        );
        $site->manifest->metadata->author->socialLink = SanitizeContent::sanitizeURLValue(
          $site->manifest->metadata->author->socialLink,
          ''
        );
      }

      $descriptionValue = null;
      if (array_key_exists('description', $seo)) {
        $descriptionValue = $seo['description'];
      }
      else if (array_key_exists('manifest.description', $manifestSeo)) {
        $descriptionValue = $manifestSeo['manifest.description'];
      }
      if (!is_null($descriptionValue)) {
        $site->manifest->description = filter_var(
          strval($descriptionValue),
          FILTER_SANITIZE_FULL_SPECIAL_CHARS
        );
      }

      $logoValue = null;
      if (array_key_exists('logo', $seo)) {
        $logoValue = $seo['logo'];
      }
      else if (array_key_exists('manifest.metadata.site.logo', $manifestSeo)) {
        $logoValue = $manifestSeo['manifest.metadata.site.logo'];
      }
      if (!is_null($logoValue)) {
        $site->manifest->metadata->site->logo = filter_var(
          strval($logoValue),
          FILTER_SANITIZE_URL
        );
        $site->manifest->metadata->site->logo = SanitizeContent::sanitizeURLValue(
          $site->manifest->metadata->site->logo,
          ''
        );
      }

      $domainValue = null;
      if (array_key_exists('domain', $seo)) {
        $domainValue = $seo['domain'];
      }
      else if (array_key_exists('manifest.metadata.site.domain', $manifestSeo)) {
        $domainValue = $manifestSeo['manifest.metadata.site.domain'];
      }
      if (!is_null($domainValue)) {
        $site->manifest->metadata->site->domain = filter_var(
          strval($domainValue),
          FILTER_SANITIZE_URL
        );
        $site->manifest->metadata->site->domain = SanitizeContent::sanitizeURLValue(
          $site->manifest->metadata->site->domain,
          ''
        );
      }

      $langValue = null;
      if (array_key_exists('lang', $seo)) {
        $langValue = $seo['lang'];
      }
      else if (array_key_exists('manifest.metadata.site.settings.lang', $manifestSeo)) {
        $langValue = $manifestSeo['manifest.metadata.site.settings.lang'];
      }
      if (!is_null($langValue)) {
        $site->manifest->metadata->site->settings->lang = filter_var(
          strval($langValue),
          FILTER_SANITIZE_FULL_SPECIAL_CHARS
        );
      }

      $gaIDValue = null;
      if (array_key_exists('gaID', $seo)) {
        $gaIDValue = $seo['gaID'];
      }
      else if (array_key_exists('manifest.metadata.site.settings.gaID', $manifestSeo)) {
        $gaIDValue = $manifestSeo['manifest.metadata.site.settings.gaID'];
      }
      if (!is_null($gaIDValue)) {
        $site->manifest->metadata->site->settings->gaID = filter_var(
          strval($gaIDValue),
          FILTER_SANITIZE_FULL_SPECIAL_CHARS
        );
      }

      $privateInput = null;
      $privateHasValue = false;
      if (array_key_exists('private', $seo)) {
        $privateInput = $seo['private'];
        $privateHasValue = true;
      }
      else if (array_key_exists('manifest.metadata.site.settings.private', $manifestSeo)) {
        $privateInput = $manifestSeo['manifest.metadata.site.settings.private'];
        $privateHasValue = true;
      }
      if ($privateHasValue && !is_null($privateInput) && $privateInput !== '') {
        $privateValue = filter_var(
          $privateInput,
          FILTER_VALIDATE_BOOLEAN,
          FILTER_NULL_ON_FAILURE
        );
        if (!is_null($privateValue)) {
          $site->manifest->metadata->site->settings->private = $privateValue;
        }
      }

      $canonicalInput = null;
      $canonicalHasValue = false;
      if (array_key_exists('canonical', $seo)) {
        $canonicalInput = $seo['canonical'];
        $canonicalHasValue = true;
      }
      else if (array_key_exists('manifest.metadata.site.settings.canonical', $manifestSeo)) {
        $canonicalInput = $manifestSeo['manifest.metadata.site.settings.canonical'];
        $canonicalHasValue = true;
      }
      if ($canonicalHasValue && !is_null($canonicalInput) && $canonicalInput !== '') {
        $canonicalValue = filter_var(
          $canonicalInput,
          FILTER_VALIDATE_BOOLEAN,
          FILTER_NULL_ON_FAILURE
        );
        if (!is_null($canonicalValue)) {
          $site->manifest->metadata->site->settings->canonical = $canonicalValue;
        }
      }

      $pathautoInput = null;
      $pathautoHasValue = false;
      if (array_key_exists('pathauto', $seo)) {
        $pathautoInput = $seo['pathauto'];
        $pathautoHasValue = true;
      }
      else if (array_key_exists('manifest.metadata.site.settings.pathauto', $manifestSeo)) {
        $pathautoInput = $manifestSeo['manifest.metadata.site.settings.pathauto'];
        $pathautoHasValue = true;
      }
      if ($pathautoHasValue && !is_null($pathautoInput) && $pathautoInput !== '') {
        $pathautoValue = filter_var(
          $pathautoInput,
          FILTER_VALIDATE_BOOLEAN,
          FILTER_NULL_ON_FAILURE
        );
        if (!is_null($pathautoValue)) {
          $site->manifest->metadata->site->settings->pathauto = $pathautoValue;
        }
      }

      $publishPagesOnInput = null;
      $publishPagesOnHasValue = false;
      if (array_key_exists('publishPagesOn', $seo)) {
        $publishPagesOnInput = $seo['publishPagesOn'];
        $publishPagesOnHasValue = true;
      }
      else if (array_key_exists('manifest.metadata.site.settings.publishPagesOn', $manifestSeo)) {
        $publishPagesOnInput = $manifestSeo['manifest.metadata.site.settings.publishPagesOn'];
        $publishPagesOnHasValue = true;
      }
      if ($publishPagesOnHasValue && !is_null($publishPagesOnInput) && $publishPagesOnInput !== '') {
        $publishPagesOnValue = filter_var(
          $publishPagesOnInput,
          FILTER_VALIDATE_BOOLEAN,
          FILTER_NULL_ON_FAILURE
        );
        if (!is_null($publishPagesOnValue)) {
          $site->manifest->metadata->site->settings->publishPagesOn = $publishPagesOnValue;
        }
      }

      $site->manifest->metadata->site->updated = time();
      $site->manifest->save(false);
      $site->gitCommit('SEO settings updated');
      return $site->manifest;
    }
    else {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid site token',
        )
      );
    }
  }

  /**
   * @OA\Post(
   *    path="/saveAppearanceSettings",
   *    tags={"cms","authenticated"},
   *    @OA\Parameter(
   *         name="site_token",
   *         description="Site-specific validation token",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Save appearance settings into site.json metadata.theme"
   *   )
   * )
   */
  public function saveAppearanceSettings() {
    if (!isset($this->params['site']) || !isset($this->params['site']['name'])) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'missing site name',
        )
      );
    }
    if (isset($this->params['site_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['site_token'], $GLOBALS['HAXCMS']->getActiveUserName() . ':' . $this->params['site']['name'])) {
      $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
      if (!$site || !isset($site->manifest)) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'invalid site',
          )
        );
      }
      if (!$this->platformAllows($site, 'themeManifest')) {
        return array(
          '__failed' => array(
            'status' => 403,
            'message' => 'Theme settings are disabled for this site',
          )
        );
      }

      $siteParams = isset($this->params['site']) ? $this->params['site'] : null;
      if (is_object($siteParams)) {
        $siteParams = (array) $siteParams;
      }
      if (!$this->hasOnlyAllowedKeys($siteParams, array('name'))) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'invalid site payload',
          )
        );
      }

      $manifestParams = isset($this->params['manifest']) ? $this->params['manifest'] : null;
      if (is_object($manifestParams)) {
        $manifestParams = (array) $manifestParams;
      }
      if (!$this->hasOnlyAllowedKeys($manifestParams, array('theme'))) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'invalid manifest payload',
          )
        );
      }

      $themeParams = isset($manifestParams['theme']) ? $manifestParams['theme'] : null;
      if (is_object($themeParams)) {
        $themeParams = (array) $themeParams;
      }
      $regionFieldMap = array(
        'manifest-metadata-theme-regions-header' => 'header',
        'manifest-metadata-theme-regions-sidebarFirst' => 'sidebarFirst',
        'manifest-metadata-theme-regions-sidebarSecond' => 'sidebarSecond',
        'manifest-metadata-theme-regions-contentTop' => 'contentTop',
        'manifest-metadata-theme-regions-contentBottom' => 'contentBottom',
        'manifest-metadata-theme-regions-footerPrimary' => 'footerPrimary',
        'manifest-metadata-theme-regions-footerSecondary' => 'footerSecondary',
      );
      $allowedThemeKeys = array_merge(
        array(
          'manifest-metadata-theme-element',
          'manifest-metadata-theme-variables-image',
          'manifest-metadata-theme-variables-imageAlt',
          'manifest-metadata-theme-variables-imageLink',
          'manifest-metadata-theme-variables-cssVariable',
          'manifest-metadata-theme-variables-palette',
          'manifest-metadata-theme-variables-icon',
        ),
        array_keys($regionFieldMap)
      );
      if (!$this->hasOnlyAllowedKeys($themeParams, $allowedThemeKeys)) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'invalid appearance payload',
          )
        );
      }

      if (!isset($site->manifest->metadata) || !is_object($site->manifest->metadata)) {
        $site->manifest->metadata = new stdClass();
      }
      if (!isset($site->manifest->metadata->site) || !is_object($site->manifest->metadata->site)) {
        $site->manifest->metadata->site = new stdClass();
      }
      if (!isset($site->manifest->metadata->theme) || !is_object($site->manifest->metadata->theme)) {
        $site->manifest->metadata->theme = new stdClass();
      }

      if (array_key_exists('manifest-metadata-theme-element', $themeParams)) {
        if (!is_string($themeParams['manifest-metadata-theme-element'])) {
          return array(
            '__failed' => array(
              'status' => 400,
              'message' => 'invalid theme element',
            )
          );
        }
        $themeElement = trim(filter_var($themeParams['manifest-metadata-theme-element'], FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        if ($themeElement === '') {
          return array(
            '__failed' => array(
              'status' => 400,
              'message' => 'invalid theme element',
            )
          );
        }
        $themes = $GLOBALS['HAXCMS']->getThemes();
        $themeValue = null;
        if (is_object($themes) && isset($themes->{$themeElement})) {
          $themeValue = $themes->{$themeElement};
        }
        else if (is_array($themes) && isset($themes[$themeElement])) {
          $themeValue = $themes[$themeElement];
        }
        if (is_null($themeValue)) {
          return array(
            '__failed' => array(
              'status' => 400,
              'message' => 'invalid theme element',
            )
          );
        }
        $site->manifest->metadata->theme = $themeValue;
      }

      if (!isset($site->manifest->metadata->theme->variables) || !is_object($site->manifest->metadata->theme->variables)) {
        $site->manifest->metadata->theme->variables = new stdClass();
      }
      if (!isset($site->manifest->metadata->theme->regions) || !is_object($site->manifest->metadata->theme->regions)) {
        $site->manifest->metadata->theme->regions = new stdClass();
      }

      if (array_key_exists('manifest-metadata-theme-variables-image', $themeParams)) {
        $imageValue = $themeParams['manifest-metadata-theme-variables-image'];
        if (!is_null($imageValue) && !is_string($imageValue)) {
          return array(
            '__failed' => array(
              'status' => 400,
              'message' => 'invalid image value',
            )
          );
        }
        $site->manifest->metadata->theme->variables->image = SanitizeContent::sanitizeURLValue(
          filter_var($imageValue, FILTER_SANITIZE_URL),
          ''
        );
      }
      if (array_key_exists('manifest-metadata-theme-variables-imageAlt', $themeParams)) {
        $imageAltValue = $themeParams['manifest-metadata-theme-variables-imageAlt'];
        if (!is_null($imageAltValue) && !is_string($imageAltValue)) {
          return array(
            '__failed' => array(
              'status' => 400,
              'message' => 'invalid imageAlt value',
            )
          );
        }
        $site->manifest->metadata->theme->variables->imageAlt = filter_var(
          $imageAltValue,
          FILTER_SANITIZE_FULL_SPECIAL_CHARS
        );
      }
      if (array_key_exists('manifest-metadata-theme-variables-imageLink', $themeParams)) {
        $imageLinkValue = $themeParams['manifest-metadata-theme-variables-imageLink'];
        if (!is_null($imageLinkValue) && !is_string($imageLinkValue)) {
          return array(
            '__failed' => array(
              'status' => 400,
              'message' => 'invalid imageLink value',
            )
          );
        }
        $site->manifest->metadata->theme->variables->imageLink = SanitizeContent::sanitizeURLValue(
          filter_var($imageLinkValue, FILTER_SANITIZE_URL),
          ''
        );
      }
      if (array_key_exists('manifest-metadata-theme-variables-cssVariable', $themeParams)) {
        $cssVariable = $this->normalizeAppearanceCssVariable(
          $themeParams['manifest-metadata-theme-variables-cssVariable']
        );
        if ($cssVariable === false) {
          return array(
            '__failed' => array(
              'status' => 400,
              'message' => 'invalid cssVariable value',
            )
          );
        }
        $site->manifest->metadata->theme->variables->cssVariable =
          '--simple-colors-default-theme-' . $cssVariable . '-7';
      }
      if (array_key_exists('manifest-metadata-theme-variables-palette', $themeParams)) {
        $paletteValue = $themeParams['manifest-metadata-theme-variables-palette'];
        if (!is_null($paletteValue) && !is_string($paletteValue)) {
          return array(
            '__failed' => array(
              'status' => 400,
              'message' => 'invalid palette value',
            )
          );
        }
        if (is_null($paletteValue)) {
          if (isset($site->manifest->metadata->theme->variables->palette)) {
            unset($site->manifest->metadata->theme->variables->palette);
          }
        }
        else {
          $palette = filter_var($paletteValue, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
          if (!is_string($palette)) {
            return array(
              '__failed' => array(
                'status' => 400,
                'message' => 'invalid palette value',
              )
            );
          }
          $palette = strtolower(trim($palette));
          if ($palette === '') {
            if (isset($site->manifest->metadata->theme->variables->palette)) {
              unset($site->manifest->metadata->theme->variables->palette);
            }
          }
          else if (preg_match('/^[a-z0-9-]+$/', $palette) === 1) {
            $site->manifest->metadata->theme->variables->palette = $palette;
          }
          else {
            return array(
              '__failed' => array(
                'status' => 400,
                'message' => 'invalid palette value',
              )
            );
          }
        }
      }
      if (array_key_exists('manifest-metadata-theme-variables-icon', $themeParams)) {
        $iconValue = $themeParams['manifest-metadata-theme-variables-icon'];
        if (!is_null($iconValue) && !is_string($iconValue)) {
          return array(
            '__failed' => array(
              'status' => 400,
              'message' => 'invalid icon value',
            )
          );
        }
        $site->manifest->metadata->theme->variables->icon = filter_var(
          $iconValue,
          FILTER_SANITIZE_FULL_SPECIAL_CHARS
        );
      }

      foreach ($regionFieldMap as $field => $regionName) {
        if (array_key_exists($field, $themeParams)) {
          $cleanRegionIds = $this->sanitizeAppearanceRegionIds($themeParams[$field]);
          if ($cleanRegionIds === false) {
            return array(
              '__failed' => array(
                'status' => 400,
                'message' => 'invalid region value',
              )
            );
          }
          $site->manifest->metadata->theme->regions->{$regionName} = $cleanRegionIds;
        }
      }

      $site->manifest->metadata->site->updated = time();
      $site->manifest->save(false);
      $site->gitCommit('Appearance settings updated');
      $site->rebuildManagedFiles();
      $site->updateAlternateFormats();
      $site->gitCommit('Managed files updated');

      return array(
        'status' => 200,
        'data' => array(
          'saved' => true,
          'appearance' => array(
            'theme' => true,
          )
        )
      );
    }
    else {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid site token',
        )
      );
    }
  }

  /**
   * @OA\Post(
   *    path="/savePlatformSettings",
   *    tags={"cms","authenticated"},
   *    @OA\Parameter(
   *         name="site_token",
   *         description="Site-specific validation token",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description=\"Save platform feature settings into site.json metadata.platform.features\"
   *   )
   * )
   */
  public function savePlatformSettings() {
    if (isset($this->params['site_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['site_token'], $GLOBALS['HAXCMS']->getActiveUserName() . ':' . $this->params['site']['name'])) {
      // load the site from name
      $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
      if (!isset($this->rawParams['platform'])) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'missing platform settings',
          )
        );
      }

      $platform = $this->rawParams['platform'];
      // platform can arrive as an array depending on request parsing
      if (is_array($platform)) {
        $platform = json_decode(json_encode($platform));
      }

      // Validate payload shape

      $validFeatureKeys = array(
        'addPage',
        'saveAndEdit',
        'deletePage',
        'outlineDesigner',
        'styleGuide',
        'insights',
        'siteManifest',
        'themeManifest',
        'authorManifest',
        'seoManifest',
        'pageBreak',
        'addBlock',
        'popularGizmos',
        'recentGizmos',
        'contentMap',
        'viewSource',
        'uploadMedia',
        'onlineMedia',
        'community',
        'pageTemplates',
        'blockTemplates'
      );
      $legacyFeatureKeyMap = array(
        'manifest' => array('siteManifest', 'themeManifest', 'authorManifest', 'seoManifest'),
        'onlineSearch' => array('onlineMedia'),
        'delete' => array('deletePage')
      );
      $featureSources = array();
      if (isset($platform->features) && (is_object($platform->features) || is_array($platform->features))) {
        $featureSources[] = $platform->features;
      }
      if (isset($platform->cmsFeatures) && (is_object($platform->cmsFeatures) || is_array($platform->cmsFeatures))) {
        $featureSources[] = $platform->cmsFeatures;
      }
      if (isset($platform->editorFeatures) && (is_object($platform->editorFeatures) || is_array($platform->editorFeatures))) {
        $featureSources[] = $platform->editorFeatures;
      }
      if (count($featureSources) === 0) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'invalid features',
          )
        );
      }
      $normalizedFeatures = array();
      foreach ($featureSources as $featureSource) {
        if (is_object($featureSource)) {
          $featureSource = (array) $featureSource;
        }
        foreach ($featureSource as $key => $value) {
          // Accept boolean-like values (true/false, "true"/"false", 1/0)
          // and normalize to strict booleans for storage.
          $coerced = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
          if (is_null($coerced)) {
            return array(
              '__failed' => array(
                'status' => 400,
                'message' => 'invalid feature value for ' . $key,
              )
            );
          }
          if (in_array($key, $validFeatureKeys)) {
            $normalizedFeatures[$key] = $coerced;
          }
          else if (isset($legacyFeatureKeyMap[$key])) {
            foreach ($legacyFeatureKeyMap[$key] as $mappedKey) {
              $normalizedFeatures[$mappedKey] = $coerced;
            }
          }
          else {
            return array(
              '__failed' => array(
                'status' => 400,
                'message' => 'invalid feature key',
              )
            );
          }
        }
      }

      // Write features only. Audience and allowed blocks are managed by their
      // dedicated endpoints (saveEditorSettings / saveAllowedBlocks).
      if (!isset($site->manifest->metadata) || !is_object($site->manifest->metadata)) {
        $site->manifest->metadata = new stdClass();
      }
      if (!isset($site->manifest->metadata->site) || !is_object($site->manifest->metadata->site)) {
        $site->manifest->metadata->site = new stdClass();
      }
      if (!isset($site->manifest->metadata->platform) || !is_object($site->manifest->metadata->platform)) {
        $site->manifest->metadata->platform = new stdClass();
      }
      $site->manifest->metadata->platform->features = new stdClass();
      foreach ($validFeatureKeys as $i => $k) {
        if (isset($normalizedFeatures[$k])) {
          $site->manifest->metadata->platform->features->{$k} = $normalizedFeatures[$k];
        }
      }
      $site->manifest->metadata->site->updated = time();

      // don't reorganize the structure
      $site->manifest->save(false);
      $site->gitCommit('Platform settings updated');

      return $site->manifest;
    }
    else {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid site token',
        )
      );
    }
  }

  /**
   * @OA\Post(
   *    path="/saveEditorSettings",
   *    tags={"cms","authenticated"},
   *    @OA\Parameter(
   *         name="site_token",
   *         description="Site-specific validation token",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Save editor settings into site.json metadata.platform.audience"
   *   )
   * )
   */
  public function saveEditorSettings() {
    if (!isset($this->params['site']) || !isset($this->params['site']['name'])) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'missing site name',
        )
      );
    }
    if (isset($this->params['site_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['site_token'], $GLOBALS['HAXCMS']->getActiveUserName() . ':' . $this->params['site']['name'])) {
      $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
      if (!$this->platformAllows($site, 'siteManifest')) {
        return array(
          '__failed' => array(
            'status' => 403,
            'message' => 'Editor settings are disabled for this site',
          )
        );
      }
      if (!isset($this->rawParams['platform'])) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'missing platform settings',
          )
        );
      }

      $platform = $this->rawParams['platform'];
      if (is_array($platform)) {
        $platform = json_decode(json_encode($platform));
      }

      if (!isset($platform->audience) || !is_string($platform->audience)) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'invalid audience',
          )
        );
      }
      $audience = strtolower(trim($platform->audience));
      $allowedAudiences = array('novice', 'expert');
      if (!in_array($audience, $allowedAudiences)) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'invalid audience',
          )
        );
      }

      if (!isset($site->manifest->metadata) || !is_object($site->manifest->metadata)) {
        $site->manifest->metadata = new stdClass();
      }
      if (!isset($site->manifest->metadata->site) || !is_object($site->manifest->metadata->site)) {
        $site->manifest->metadata->site = new stdClass();
      }
      if (!isset($site->manifest->metadata->platform) || !is_object($site->manifest->metadata->platform)) {
        $site->manifest->metadata->platform = new stdClass();
      }

      $site->manifest->metadata->platform->audience = $audience;
      $site->manifest->metadata->site->updated = time();

      $site->manifest->save(false);
      $site->gitCommit('Editor settings updated');

      return $site->manifest;
    }
    else {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid site token',
        )
      );
    }
  }

  /**
   * @OA\Post(
   *    path="/saveAllowedBlocks",
   *    tags={"cms","authenticated"},
   *    @OA\Parameter(
   *         name="site_token",
   *         description="Site-specific validation token",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Save allowed blocks into site.json metadata.platform.allowedBlocks"
   *   )
   * )
   */
  public function saveAllowedBlocks() {
    if (isset($this->params['site_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['site_token'], $GLOBALS['HAXCMS']->getActiveUserName() . ':' . $this->params['site']['name'])) {
      // load the site from name
      $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
      if (!$this->platformAllows($site, 'siteManifest')) {
        return array(
          '__failed' => array(
            'status' => 403,
            'message' => 'Allowed blocks settings are disabled for this site',
          )
        );
      }
      if (!isset($this->rawParams['platform'])) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'missing platform settings',
          )
        );
      }

      $platform = $this->rawParams['platform'];
      // platform can arrive as an array depending on request parsing
      if (is_array($platform)) {
        $platform = json_decode(json_encode($platform));
      }

      if (!property_exists($platform, 'allowedBlocks')) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'invalid allowedBlocks',
          )
        );
      }
      if (!is_null($platform->allowedBlocks) && !is_array($platform->allowedBlocks)) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'invalid allowedBlocks',
          )
        );
      }
      $cleanAllowedBlocks = null;
      if (is_array($platform->allowedBlocks)) {
        $wcMap = $GLOBALS['HAXCMS']->getWCRegistryJson($site);
        $cleanAllowedBlocks = array();
        foreach ($platform->allowedBlocks as $index => $tag) {
          if (!is_string($tag)) {
            return array(
              '__failed' => array(
                'status' => 400,
                'message' => 'invalid allowedBlocks entry at index ' . $index,
              )
            );
          }

          $cleanTag = trim($tag);
          if ($cleanTag === '') {
            return array(
              '__failed' => array(
                'status' => 400,
                'message' => 'invalid tag name in allowedBlocks at index ' . $index . ' (empty value)',
              )
            );
          }

          // Allow basic HTML primitives (no dash) OR web components found in wc-registry
          $isHtmlTag = false;
          if (preg_match('/^[a-z][a-z0-9]*$/', $cleanTag) && strpos($cleanTag, '-') === false) {
            $isHtmlTag = true;
          }

          $isRegisteredWc = false;
          if (!$isHtmlTag && $wcMap && isset($wcMap->{$cleanTag})) {
            $isRegisteredWc = true;
          }

          if (!$isHtmlTag && !$isRegisteredWc) {
            return array(
              '__failed' => array(
                'status' => 400,
                'message' => 'invalid tag name in allowedBlocks at index ' . $index . ': ' . $cleanTag,
              )
            );
          }

          $cleanAllowedBlocks[] = $cleanTag;
        }
        $cleanAllowedBlocks = array_values(array_unique($cleanAllowedBlocks));
        sort($cleanAllowedBlocks);
      }

      if (!isset($site->manifest->metadata)) {
        $site->manifest->metadata = new stdClass();
      }
      if (!isset($site->manifest->metadata->site)) {
        $site->manifest->metadata->site = new stdClass();
      }
      if (!isset($site->manifest->metadata->platform) || !is_object($site->manifest->metadata->platform)) {
        $site->manifest->metadata->platform = new stdClass();
        $site->manifest->metadata->platform->audience = 'expert';
        $site->manifest->metadata->platform->features = new stdClass();
        $site->manifest->metadata->platform->allowedBlocks = array();
      }

      $site->manifest->metadata->platform->allowedBlocks = $cleanAllowedBlocks;
      $site->manifest->metadata->site->updated = time();

      // don't reorganize the structure
      $site->manifest->save(false);
      $site->gitCommit('Allowed blocks updated');

      return $site->manifest;
    }
    else {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid site token',
        )
      );
    }
  }

  /**
   * @OA\Post(
   *    path="/saveOutline",
   *    tags={"cms","authenticated","site"},
   *    @OA\Parameter(
   *         name="site_token",
   *         description="Site-specific validation token",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Save an entire site outline"
   *   )
   * )
   */
  public function saveOutline() {
    if (isset($this->params['site_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['site_token'], $GLOBALS['HAXCMS']->getActiveUserName() . ':' . $this->params['site']['name'])) {
      $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
      
      // Check platform configuration
      if (!$this->platformAllows($site, 'outlineDesigner')) {
        return array(
          '__failed' => array(
            'status' => 403,
            'message' => 'Outline operations are disabled for this site',
          )
        );
      }
      $siteDirectory = $site->directory . '/' . $site->manifest->metadata->site->name;
      $original = $site->manifest->items;
      $originalLocationMap = array();
      foreach ($original as $originalItem) {
        $originalLocationMap[$originalItem->id] = $this->normalizeOutlineLocation($originalItem->location);
      }
      $safeLocationMap = array();
      $items = $this->rawParams['items'];
      $itemMap = array();
      $pageAlternateContentMap = array();
      $normalizeOutlineSlug = function ($slug, $page = null, $pathAuto = false) use ($site) {
        $normalizedSlug = $GLOBALS['HAXCMS']->generateSlugName($slug);
        if ($normalizedSlug == 'x') {
          $normalizedSlug = 'x-x';
        }
        if (substr($normalizedSlug, 0, 2) == 'x/') {
          $normalizedSlug = str_replace('x/', 'x-x/', $normalizedSlug);
        }
        if ($normalizedSlug == '') {
          $normalizedSlug = 'blank';
        }
        return $site->getUniqueSlugName($normalizedSlug, $page, $pathAuto);
      };
      // items from the POST
      foreach ($items as $key => $item) {
        // get a fake item of the existing
        if (!($page = $site->loadNode($item->id))) {
          $page = $GLOBALS['HAXCMS']->outlineSchema->newItem();
          // we don't trust the front end UUID if it wasn't existing already
          $itemMap[$item->id] = $page->id;
        }
        // set a title if we have one
        if ($item->title != '' && $item->title) {
          $page->title = $item->title;
        }
        $cleanTitle = $GLOBALS['HAXCMS']->cleanTitle($page->title);
        if ($item->parent == null) {
          $page->parent = null;
          $page->indent = 0;
        } else {
          // check the item map as backend dictates unique ID
          if (isset($itemMap[$item->parent])) {
            $page->parent = $itemMap[$item->parent];
          } else {
            // set to the parent id
            $page->parent = $item->parent;
          }
          // move it one indentation below the parent; this can be changed later if desired
          $page->indent = $item->indent;
        }
        if (isset($item->order)) {
          $page->order = (int)$item->order;
        } else {
          $page->order = (int)$key;
        }
        // location is backend-controlled to prevent arbitrary writes
        if (isset($originalLocationMap[$page->id]) && $originalLocationMap[$page->id]) {
          $page->location = $originalLocationMap[$page->id];
        } else {
          // generate a logical page slug
          $page->location = 'pages/' . $page->id . '/index.html';
        }
        // keep slug if we get one already, but sanitize / normalize it
        if (isset($item->slug) && $item->slug != '') {
            $page->slug = $normalizeOutlineSlug($item->slug, $page, false);
        } else {
            // generate a logical page slug
            $page->slug = $normalizeOutlineSlug($cleanTitle, $page, true);
        }
        // verify this exists, front end could have set what they wanted
        // or it could have just been renamed
        // if it doesn't exist currently make sure the name is unique
        if (!$site->loadNode($page->id)) {
          $site->recurseCopy(
              HAXCMS_ROOT . '/system/boilerplate/page/default',
              $siteDirectory . '/' . str_replace('/index.html', '', $page->location)
          );
          $pageAlternateContentMap[$page->id] = '';
        }
        // this would imply existing item, lets see if it moved or needs moved
        else {
            $moved = false;
            foreach ($original as $key => $tmpItem) {
                // see if this is something moving as opposed to brand new
                if (
                    $tmpItem->id == $page->id &&
                    $tmpItem->slug != ''
                ) {
                    // core support for automatically managing paths to make them nice
                    if (isset($site->manifest->metadata->site->settings->pathauto) && $site->manifest->metadata->site->settings->pathauto) {
                        $moved = true;
                        $page->slug = $normalizeOutlineSlug(
                          $GLOBALS['HAXCMS']->cleanTitle($page->title),
                          $page,
                          true
                        );
                    }
                    else if ($tmpItem->slug != $page->slug) {
                        $moved = true;
                        $page->slug = $normalizeOutlineSlug($page->slug, $page, false);
                    }
                }
            }
            // it wasn't moved and it doesn't exist... let's fix that
            // this is beyond an edge case
            if (
                !$moved &&
                !file_exists($siteDirectory . '/' . $page->location)
            ) {
                $pAuto = false;
                if (isset($site->manifest->metadata->site->settings->pathauto) && $site->manifest->metadata->site->settings->pathauto) {
                  $pAuto = true;
                }
                $tmpTitle = $normalizeOutlineSlug($cleanTitle, $page, $pAuto);
                $page->location = 'pages/' . $page->id . '/index.html';
                $page->slug = $tmpTitle;
                $site->recurseCopy(
                    HAXCMS_ROOT . '/system/boilerplate/page/default',
                    $siteDirectory . '/' . str_replace('/index.html', '', $page->location)
                );
                $pageAlternateContentMap[$page->id] = '';
            }
        }
        if (!isset($page->slug) || !is_string($page->slug) || $page->slug == '') {
            $page->slug = $normalizeOutlineSlug($cleanTitle, $page, true);
        }
        $safeLocationMap[$page->id] = $page->location;
        // check for any metadata keys that did come over
        foreach ($item->metadata as $key => $value) {
            $page->metadata->{$key} = $value;
        }
        // safety check for new things
        if (!isset($page->metadata->created)) {
            $page->metadata->created = time();
            $page->metadata->images = array();
            $page->metadata->videos = array();
        }
        // always update at this time
        $page->metadata->updated = time();
        if ($site->loadNode($page->id)) {
            $site->updateNode($page);
        } else {
            $site->manifest->addItem($page);
        }
      }
      // process any duplicate / contents requests we had now that structure is sane
      // including potentially duplication of material from something
      // we are about to act on and now that we have the map
      $items = $this->rawParams['items'];
      foreach ($items as $key => $item) {
        // load the item, or the item as built out of the itemMap
        // since we reset the UUID on creation
        if (!($page = $site->loadNode($item->id))) {
          if (isset($itemMap[$item->id])) {
            $page = $site->loadNode($itemMap[$item->id]);
          }
        }
        if (!$page) {
          return array(
            '__failed' => array(
              'status' => 400,
              'message' => 'invalid page reference',
            )
          );
        }
        $expectedLocation = null;
        if (isset($safeLocationMap[$page->id]) && $safeLocationMap[$page->id]) {
          $expectedLocation = $safeLocationMap[$page->id];
        } else {
          $expectedLocation = $this->normalizeOutlineLocation($page->location);
        }
        if (!$expectedLocation) {
          return array(
            '__failed' => array(
              'status' => 400,
              'message' => 'invalid page location',
            )
          );
        }
        // location is backend-controlled based on page id, ignore client input
        if (!$this->getValidatedOutlineWriteTarget($siteDirectory, $expectedLocation)) {
          return array(
            '__failed' => array(
              'status' => 400,
              'message' => 'invalid write target',
            )
          );
        }
        $page->location = $expectedLocation;
        $alternateContent = '';
        $shouldWriteAlternate = false;
        if (isset($pageAlternateContentMap[$page->id])) {
          $shouldWriteAlternate = true;
        }
        if (isset($item->duplicate)) {
          // load the node we are duplicating with support for the same map needed for page loading
          if (!$nodeToDuplicate = $site->loadNode($item->duplicate)) {
            if (isset($itemMap[$item->duplicate])) {
              $nodeToDuplicate = $site->loadNode($itemMap[$item->duplicate]);
            }
          }
          if (!$nodeToDuplicate) {
            return array(
              '__failed' => array(
                'status' => 400,
                'message' => 'invalid duplicate source',
              )
            );
          }
          $content = $site->getPageContent($nodeToDuplicate);
          if (!$this->isLikelyHtmlContent($content)) {
            return array(
              '__failed' => array(
                'status' => 400,
                'message' => 'invalid duplicate content',
              )
            );
          }
          // write it to the file system
          $alternateContent = SanitizeContent::sanitizeHTMLForStorage($content);
          $bytes = $page->writeLocation(
            $alternateContent,
            HAXCMS_ROOT .
            '/' .
            $GLOBALS['HAXCMS']->sitesDirectory .
            '/' .
            $site->manifest->metadata->site->name .
            '/'
          );
          if ($bytes === false) {
            return array(
              '__failed' => array(
                'status' => 500,
                'message' => 'failed to write',
              )
            );
          }
          $shouldWriteAlternate = true;
        }
        // contents that were shipped across, and not null, take priority over a dup request
        if (isset($item->contents) && $item->contents && $item->contents != '') {
          if (!$this->isLikelyHtmlContent($item->contents)) {
            return array(
              '__failed' => array(
                'status' => 400,
                'message' => 'invalid page contents',
              )
            );
          }
          // write it to the file system
          $alternateContent = SanitizeContent::sanitizeHTMLForStorage($item->contents);
          $bytes = $page->writeLocation(
            $alternateContent,
            HAXCMS_ROOT .
            '/' .
            $GLOBALS['HAXCMS']->sitesDirectory .
            '/' .
            $site->manifest->metadata->site->name .
            '/'
          );
          if ($bytes === false) {
            return array(
              '__failed' => array(
                'status' => 500,
                'message' => 'failed to write',
              )
            );
          }
          $shouldWriteAlternate = true;
        }
        if ($shouldWriteAlternate) {
          $site->writePageAlternateFormats($page, $alternateContent);
        }
      }
      $items = $this->rawParams['items'];
      // now, we can finally delete as content operations have finished
      foreach ($items as $key => $item) {
        // verify if we were told to delete this item via flag not in the real spec
        if (isset($item->delete) && $item->delete == TRUE) {
          // load the item, or the item as built out of the itemMap
          // since we reset the UUID on creation
          if (!($page = $site->loadNode($item->id))) {
            if (isset($itemMap[$item->id])) {
              $page = $site->loadNode($itemMap[$item->id]);
            }
          }
          if (!$page) {
            continue;
          }
          $site->deleteNode($page);
          $site->gitCommit(
            'Page deleted: ' . $page->title . ' (' . $page->id . ')'
          );
        }
      }
      $site->manifest->save();
      // now, we need to look for orphans if we deleted anything
      $orphanCheck = $site->manifest->items;
      foreach ($orphanCheck as $key => $item) {
        // just to be safe..
        if ($page = $site->loadNode($item->id)) {
          // ensure that parent is valid to rescue orphan items
          if ($page->parent != null && !($parentPage = $site->loadNode($page->parent))) {
            $page->parent = null;
            // force to bottom of things while still being in old order if lots of things got axed
            $page->order = (int)$page->order + count($site->manifest->items) - 1;
            $site->updateNode($page);
          }
        }
      }
      $site->manifest->metadata->site->updated = time();
      $site->manifest->save();
      // update alt formats like rss as we did massive changes
      $site->updateAlternateFormats();
      $site->gitCommit('Outline updated in bulk');
      return $site->manifest->items;
    } else {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid site token',
        )
      );
    }
  }
  /**
   * @OA\Post(
   *     path="/createNode",
   *     tags={"cms","authenticated","node"},
   *     @OA\Parameter(
   *         name="site_token",
   *         description="Site-specific validation token",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *     ),
   *     @OA\RequestBody(
   *        @OA\MediaType(
   *             mediaType="application/json",
   *             @OA\Schema(
   *                 @OA\Property(
   *                     property="site",
   *                     type="object"
   *                 ),
   *                 @OA\Property(
   *                     property="items",
   *                     type="object"
   *                 ),
   *                 @OA\Property(
   *                     property="node",
   *                     type="object"
   *                 ),
   *                 @OA\Property(
   *                     property="indent",
   *                     type="number"
   *                 ),
   *                 @OA\Property(
   *                     property="order",
   *                     type="number"
   *                 ),
   *                 @OA\Property(
   *                     property="parent",
   *                     type="string"
   *                 ),
   *                 @OA\Property(
   *                     property="description",
   *                     type="string"
   *                 ),
   *                 @OA\Property(
   *                     property="metadata",
   *                     type="object"
   *                 ),
   *                 required={"site","node"},
   *                 example={
   *                    "site": {
   *                      "name": "mysite"
   *                    },
   *                    "node": {
   *                      "id": null,
   *                      "title": "Cool post",
   *                      "location": null,
   *                      "duplicate": "item-123-ddd-333"
   *                    },
   *                    "indent": null,
   *                    "order": null,
   *                    "parent": null,
   *                    "description": "An example description for the post",
   *                    "metadata": {"tags": "metadata,can,be,whatever,you,want","other":"stuff"}
   *                 }
   *             )
   *         )
   *     ),
   *    @OA\Response(
   *        response="200",
   *        description="object with full properties returned"
   *   )
   * )
   */
  public function createNode() {
    $nodeParams = $this->params;
    if (isset($this->params['site_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['site_token'], $GLOBALS['HAXCMS']->getActiveUserName() . ':' . $nodeParams['site']['name'])) {
      $site = $GLOBALS['HAXCMS']->loadSite(strtolower($nodeParams['site']['name']));
      
      // Check platform configuration
      if (!$this->platformAllows($site, 'addPage')) {
        return array(
          '__failed' => array(
            'status' => 403,
            'message' => 'Adding pages is disabled for this site',
          )
        );
      }
      // implies we've been TOLD to create nodes
      // this is typically from a docx import
      if (isset($nodeParams['items'])) {
        // create pages
        for ($i=0; $i < count($nodeParams['items']); $i++) {
          // outline-designer allows delete + confirmation but we don't have anything
          // so instead, just don't process the thing in question if asked to delete it
          if (isset($nodeParams['items'][$i]['delete']) && $nodeParams['items'][$i]['delete'] == TRUE) {
            // do nothing
          }
          else {
            $item = $site->addPage(
              $nodeParams['items'][$i]['parent'], 
              $nodeParams['items'][$i]['title'], 
              'html', 
              $nodeParams['items'][$i]['slug'],
              $nodeParams['items'][$i]['id'],
              $nodeParams['items'][$i]['indent'],
              ((isset($nodeParams['items'][$i]['content']) && $nodeParams['items'][$i]['content'] != '') ? $nodeParams['items'][$i]['content'] : (isset($nodeParams['items'][$i]['contents']) ? $nodeParams['items'][$i]['contents'] : '')),
              (isset($nodeParams['items'][$i]['order']) ? $nodeParams['items'][$i]['order'] : null),
              (isset($nodeParams['items'][$i]['metadata']) ? $nodeParams['items'][$i]['metadata'] : null)
            );  
          }
        }
        $site->gitCommit(count($nodeParams['items']) . ' pages added'); 
      }
      else {
        // generate a new item based on the site
        $item = $site->itemFromParams($nodeParams);
        $item->metadata->images = array();
        $item->metadata->videos = array();
        // generate the boilerplate to fill this page
        $site->recurseCopy(
            HAXCMS_ROOT . '/system/boilerplate/page/default',
            $site->directory .
                '/' .
                $site->manifest->metadata->site->name .
                '/' .
                str_replace('/index.html', '', $item->location)
        );
        // add the item back into the outline schema
        $site->manifest->addItem($item);
        $site->manifest->save();
        $alternateContent = '';
        // support for duplicating the content of another item
        if (isset($nodeParams['node']['duplicate'])) {
          // verify we can load this id
          if ($nodeToDuplicate = $site->loadNode($nodeParams['node']['duplicate'])) {
            $content = $site->getPageContent($nodeToDuplicate);
            // verify we actually have the id of an item that we just created
            if ($page = $site->loadNode($item->id)) {
              // write it to the file system
              // this all seems round about but it's more secure
              $alternateContent = SanitizeContent::sanitizeHTMLForStorage($content);
              $bytes = $page->writeLocation(
                $alternateContent,
                HAXCMS_ROOT .
                '/' .
                $GLOBALS['HAXCMS']->sitesDirectory .
                '/' .
                $site->manifest->metadata->site->name .
                '/'
              );
            }
          }
        }
        // implies front end was told to generate a page with set content
        // this is possible when importing and processing a file to generate
        // html which becomes the boilerplated content in effect
        else if (isset($nodeParams['node']['contents'])) {
          if ($page = $site->loadNode($item->id)) {
            // write it to the file system
            $alternateContent = SanitizeContent::sanitizeHTMLForStorage($nodeParams['node']['contents']);
            $bytes = $page->writeLocation(
              $alternateContent,
              HAXCMS_ROOT .
              '/' .
              $GLOBALS['HAXCMS']->sitesDirectory .
              '/' .
              $site->manifest->metadata->site->name .
              '/'
            );
          }
        }
        if ($page = $site->loadNode($item->id)) {
          $site->writePageAlternateFormats($page, $alternateContent);
        }
        $site->gitCommit('Page added:' . $item->title . ' (' . $item->id . ')'); 
        // update the alternate formats as a new page exists
        $site->updateAlternateFormats();
      }
      return array(
        'status' => 200,
        'data' => $item
      );
    }
    else {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid site token',
        )
      );
    }
  }
  /**
   * @OA\Post(
   *    path="/saveNode",
   *    tags={"cms","authenticated","node"},
   *    @OA\Parameter(
   *         name="site_token",
   *         description="Site-specific validation token",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Save a node"
   *   )
   * )
   */
  public function saveNode() {
    if (isset($this->params['site_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['site_token'], $GLOBALS['HAXCMS']->getActiveUserName() . ':' . $this->params['site']['name'])) {
      $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
      
      // Special handling for style guide endpoint through saveNode
      if (isset($this->params['node']['id']) && $this->params['node']['id'] === 'x/theme/style-guide') {
        return $this->handleStyleGuideSave($site);
      }
      
      $schema = array();
      if (isset($this->params['node']['body'])) {
        $body = $this->params['node']['body'];
        // we ship the schema with the body
        if (isset($this->params['node']['schema'])) {
          $schema = $this->params['node']['schema'];
        }
      }
      $details = array();
      // if we have details object then merge configure and advanced
      if (isset($this->params['node']['details'])) {
        foreach ($this->params['node']['details']['node']['configure'] as $key => $value) {
          $details[$key] = $value;
        }
        foreach ($this->params['node']['details']['node']['advanced'] as $key => $value) {
          $details[$key] = $value;
        }
      }
      // update the page's content, using manifest to find it
      // this ensures that writing is always to what the file system
      // determines to be the correct page
      // @todo review this step by step
      if ($page = $site->loadNode($this->params['node']['id'])) {
        // convert web location for loading into file location for writing
        if (isset($body)) {
          $bytes = 0;
          // see if we have multiple pages / this page has been told to split into multiple
          $pageData = $GLOBALS['HAXCMS']->pageBreakParser($body);
          foreach($pageData as $data) {
            // trap to ensure if front-end didnt send a UUID for id then we make it
            if (!isset($data["attributes"]["title"])) {
              $data["attributes"]["title"] = 'New page';
            }
            // to avoid critical error in parsing, we defer to the POST's ID always
            // this also blocks multiple page breaks if it doesn't exist as we don't allow
            // the front end to dictate what gets created here
            if (!isset($data["attributes"]["item-id"])) {
              $data["attributes"]["item-id"] = $this->params['node']['id'];
            }
            if (!isset($data["attributes"]["path"]) || $data["attributes"]["path"] == '#') {
              $data["attributes"]["path"] = $data["attributes"]["title"];
            }
            // verify this pages does not exist; this is only possible if we parse multiple page-break
            // a capability that is not supported currently beyond experiments
            if (!$page = $site->loadNode($data["attributes"]["item-id"])) {
              if (!$this->platformAllows($site, 'addPage')) {
                return array(
                  '__failed' => array(
                    'status' => 403,
                    'message' => 'Adding pages is disabled for this site',
                  )
                );
              }
              // generate a new item based on the site
              $nodeParams = array(
                "node" => array(
                  "title" => $data["attributes"]["title"],
                  "id" => $data["attributes"]["item-id"],
                  "location" => $data["attributes"]["path"],
                )
              );
              $item = $site->itemFromParams($nodeParams);
              // generate the boilerplate to fill this page
              $site->recurseCopy(
                  HAXCMS_ROOT . '/system/boilerplate/page/default',
                  $site->directory .
                      '/' .
                      $site->manifest->metadata->site->name .
                      '/' .
                      str_replace('/index.html', '', $item->location)
              );
              // add the item back into the outline schema
              $site->manifest->addItem($item);
              $site->manifest->save();
              $site->gitCommit('Page added:' . $item->title . ' (' . $item->id . ')');
              // possible the item-id had to be made by back end
              $data["attributes"]["item-id"] = $item->id;
            }
            // now this should exist if it didn't a minute ago
            $page = $site->loadNode($data["attributes"]["item-id"]);
            $sanitizedContent = SanitizeContent::sanitizeHTMLForStorage($data['content']);
            // @todo make sure that we stripped off page-break
            // and now save WITHOUT the top level page-break
            // to avoid duplication issues
            $bytes = $page->writeLocation(
              $sanitizedContent,
              HAXCMS_ROOT .
              '/' .
              $GLOBALS['HAXCMS']->sitesDirectory .
              '/' .
              $site->manifest->metadata->site->name .
              '/'
            );
            if ($bytes === false) {
              return array(
                '__failed' => array(
                  'status' => 500,
                  'message' => 'failed to write',
                )
              );
            } else {
                // sanity check
                if (!isset($page->metadata)) {
                  $page->metadata = new stdClass();
                }
                // update attributes in the page
                if (isset($data["attributes"]["title"])) {
                  // decode entities and strip tags so manifest stores clean text
                  $page->title = html_entity_decode(strip_tags($data["attributes"]["title"]));
                }
                if (isset($data["attributes"]["slug"])) {
                  // account for x being the only front end reserved route
                  if ($data["attributes"]["slug"] == "x") {
                    $data["attributes"]["slug"] = "x-x";
                  }
                  // same but trying to force a sub-route; paths cannot conflict with front end
                  if (substr( $data["attributes"]["slug"], 0, 2 ) == "x/") {
                    $data["attributes"]["slug"] = str_replace('x/', 'x-x/', $data["attributes"]["slug"]);
                  }
                  // machine name should more aggressively scrub the slug than clean title
                  // @todo need to verify this doesn't already exist
                  $page->slug = $GLOBALS['HAXCMS']->generateSlugName($data["attributes"]["slug"]);
                }
                if (isset($data["attributes"]["parent"])) {
                  $page->parent = $data["attributes"]["parent"];
                }
                else {
                  $page->parent = null;
                }
                // allow setting theme via page break
                if (isset($data["attributes"]["developer-theme"]) && $data["attributes"]["developer-theme"] != '') {
                  $themes = $GLOBALS['HAXCMS']->getThemes();
                  $value = filter_var($data["attributes"]["developer-theme"], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
                  // support for removing the custom theme or applying none
                  if ($value == '_none_' || $value == '' || !$value || !isset($themes->{$value})) {
                    unset($page->metadata->theme);
                  }
                  // ensure it exists
                  else if (isset($themes->{$value})) {
                    $page->metadata->theme = $themes->{$value};
                    $page->metadata->theme->key = $value;
                  }
                }
                else if (isset($page->metadata->theme)) {
                  unset($page->metadata->theme);
                }
                if (isset($data["attributes"]["depth"])) {
                  $page->indent = (int)$data["attributes"]["depth"];
                }
                if (isset($data["attributes"]["order"])) {
                  $page->order = (int)$data["attributes"]["order"];
                }
                // boolean so these are either there or not
                // historically we are published if this value is not set
                // and that will remain true however as we save / update pages
                // this will ensure that we set things to published
                if (isset($data["attributes"]["published"])) {
                  $page->metadata->published = true;
                }
                else {
                  $page->metadata->published = false;
                }
                // support for defining and updating page type
                if (isset($data["attributes"]["page-type"]) && $data["attributes"]["page-type"] != '') {
                  $page->metadata->pageType = $data["attributes"]["page-type"];
                }
                // they sent across nothing but we had something previously
                else if (isset($page->metadata->pageType)) {
                  unset($page->metadata->pageType);
                }
                // support for defining and updating hideInMenu
                if (isset($data["attributes"]["hide-in-menu"])) {
                  $page->metadata->hideInMenu = true;
                }
                else {
                  $page->metadata->hideInMenu = false;
                }
                // support for defining and updating related-items
                if (isset($data["attributes"]["related-items"]) && $data["attributes"]["related-items"] != '') {
                  $page->metadata->relatedItems = SanitizeContent::sanitizeMetadataValue(
                    $data["attributes"]["related-items"]
                  );
                }
                // they sent across nothing but we had something previously
                else if (isset($page->metadata->relatedItems)) {
                  unset($page->metadata->relatedItems);
                }
                // support for defining and updating image
                if (isset($data["attributes"]["image"]) && $data["attributes"]["image"] != '') {
                  $page->metadata->image = SanitizeContent::sanitizeURLValue(
                    $data["attributes"]["image"],
                    ''
                  );
                }
                // they sent across nothing but we had something previously
                else if (isset($page->metadata->image)) {
                  unset($page->metadata->image);
                }
                // support for defining and updating page type
                if (isset($data["attributes"]["tags"]) && $data["attributes"]["tags"] != '') {
                  $page->metadata->tags = SanitizeContent::sanitizeMetadataValue(
                    $data["attributes"]["tags"]
                  );
                }
                // they sent across nothing but we had something previously
                else if (isset($page->metadata->tags)) {
                  unset($page->metadata->tags);
                }
                // support for defining and updating page accentColor
                if (isset($data["attributes"]["accent-color"]) && $data["attributes"]["accent-color"] != '') {
                  $page->metadata->accentColor = SanitizeContent::sanitizeMetadataValue(
                    $data["attributes"]["accent-color"]
                  );
                }
                // they sent across nothing but we had something previously
                else if (isset($page->metadata->accentColor)) {
                  unset($page->metadata->accentColor);
                }
                // support for defining and updating page type
                if (isset($data["attributes"]["icon"]) && $data["attributes"]["icon"] != '') {
                  $page->metadata->icon = SanitizeContent::sanitizeMetadataValue(
                    $data["attributes"]["icon"]
                  );
                }
                // they sent across nothing but we had something previously
                else if (isset($page->metadata->icon)) {
                  unset($page->metadata->icon);
                }
                // support for defining an image to represent the page
                if (isset($data["attributes"]["image"]) && $data["attributes"]["image"] != '') {
                  $page->metadata->image = SanitizeContent::sanitizeURLValue(
                    $data["attributes"]["image"],
                    ''
                  );
                }
                // they sent across nothing but we had something previously
                else if (isset($page->metadata->image)) {
                  unset($page->metadata->image);
                }
                // support for defining and updating author
                if (isset($data["attributes"]["author"]) && $data["attributes"]["author"] != '') {
                  $page->metadata->author = SanitizeContent::sanitizeMetadataValue(
                    $data["attributes"]["author"]
                  );
                }
                // they sent across nothing but we had something previously
                else if (isset($page->metadata->author)) {
                  unset($page->metadata->author);
                }
                if (!isset($data["attributes"]["locked"])) {
                  $page->metadata->locked = false;
                }
                else {
                  $page->metadata->locked = true;
                }
                // update the updated timestamp
                $page->metadata->updated = time();
                $clean = strip_tags($body);
                // auto generate a text only description from first 200 chars
                // unless we were sent one to use
                if (isset($data["attributes"]["description"]) && $data["attributes"]["description"] != '') {
                  $page->description = html_entity_decode(strip_tags($data["attributes"]["description"]));
                }
                else {
                  $decodedClean = html_entity_decode($clean);
                  $page->description = str_replace(
                    "\n",
                    '',
                    substr($decodedClean, 0, 200)
                );
                }
                $readtime = round(str_word_count($clean) / 200);
                // account for uber small body
                if ($readtime == 0) {
                  $readtime = 1;
                }
                $page->metadata->readtime = $readtime;
                // reset bc we rebuild this each page save
                $page->metadata->videos = array();
                $page->metadata->images = array();
                // pull schema apart and seee if we have any images
                // that other things could use for metadata / theming purposes
                foreach ($schema as $element) {
                  switch($element['tag']) {
                    case 'img':
                      if (isset($element['properties']['src'])) {
                        array_push($page->metadata->images, $element['properties']['src']);
                      }
                    break;
                    case 'a11y-gif-player':
                      if (isset($element['properties']['src'])) {
                        array_push($page->metadata->images, $element['properties']['src']);
                      }
                    break;
                    case 'media-image':
                      if (isset($element['properties']['source'])) {
                        array_push($page->metadata->images, $element['properties']['source']);
                      }
                    break;
                    case 'video-player':
                      if (isset($element['properties']['source'])) {
                        array_push($page->metadata->videos, $element['properties']['source']);
                      }
                    break;
                  }
                }
                $site->updateNode($page);
                $site->writePageAlternateFormats($page, $sanitizedContent);
                $site->gitCommit(
                  'Page updated: ' . $page->title . ' (' . $page->id . ')'
                );
            }
          }
          return array(
            'status' => 200,
            'data' => $page
          );
        }
      }
      else {
        return array(
          '__failed' => array(
            'status' => 500,
            'message' => 'failed to write',
          )
        );
      }
    }
    else {
      return array(
        '__failed' => array(
          'status' => 500,
          'message' => 'failed to write',
        )
      );
    }
  }
  /**
   * @OA\Post(
   *    path="/deleteNode",
   *    tags={"cms","authenticated","node"},
   *    @OA\Parameter(
   *         name="site_token",
   *         description="Site-specific validation token",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Delete a node"
   *   )
   * )
   */
  public function deleteNode() {
    if (isset($this->params['site_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['site_token'], $GLOBALS['HAXCMS']->getActiveUserName() . ':' . $this->params['site']['name'])) {
      $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);

      // Check platform configuration
      if (!$this->platformAllows($site, 'deletePage')) {
        return array(
          '__failed' => array(
            'status' => 403,
            'message' => 'Delete is disabled for this site',
          )
        );
      }
      // update the page's content, using manifest to find it
      // this ensures that writing is always to what the file system
      // determines to be the correct page
      if ($page = $site->loadNode($this->params['node']['id'])) {
          if ($site->deleteNode($page) === false) {
            return array(
              '__failed' => array(
                'status' => 500,
                'message' => 'failed to delete',
              )
            );
          } else {
            // now, we need to look for orphans if we deleted anything
            $orphanCheck = $site->manifest->items;
            foreach ($orphanCheck as $key => $item) {
              // just to be safe..
              if ($page = $site->loadNode($item->id)) {
                // ensure that parent is valid to rescue orphan items
                if ($page->parent != null && !($parentPage = $site->loadNode($page->parent))) {
                  $page->parent = null;
                  // force to bottom of things while still being in old order if lots of things got axed
                  $page->order = (int)$page->order + count($site->manifest->items) - 1;
                  $site->updateNode($page);
                }
              }
            }
            $site->gitCommit(
              'Page deleted: ' . $page->title . ' (' . $page->id . ')'
            );
            return array(
              'status' => 200,
              'data' => $page
            );
          }
          exit();
      } else {
        return array(
          '__failed' => array(
            'status' => 500,
            'message' => 'failed to delete',
          )
        );
      }
    }
    else {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid site token',
        )
      );
    }
  }

  /**
   * @OA\Post(
   *    path="/saveNodeDetails",
   *    tags={"cms","authenticated","node"},
   *    @OA\Parameter(
   *         name="site_token",
   *         description="Site-specific validation token",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Perform a singular node operation: moveUp, moveDown, indent, outdent, setParent, setTitle, setDescription, setTags, setIcon, setMedia, setImage, setRelatedItems, setLocked, setPublished, setHideInMenu, setSlug"
   *   )
   * )
   */
  public function saveNodeDetails() {
    if (isset($this->params['site_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['site_token'], $GLOBALS['HAXCMS']->getActiveUserName() . ':' . $this->params['site']['name'])) {
      $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);

      // Check platform configuration
      if (!$this->platformAllows($site, 'outlineDesigner')) {
        return array(
          '__failed' => array(
            'status' => 403,
            'message' => 'Outline operations are disabled for this site',
          )
        );
      }
      if (!isset($this->params['node']['id'])) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'Missing node id',
          )
        );
      }
      $operation = isset($this->params['node']['details']['operation']) ? $this->params['node']['details']['operation'] : null;
      $pageDetailOperations = array(
        'setTitle',
        'setDescription',
        'setTags',
        'setIcon',
        'setMedia',
        'setImage',
        'setRelatedItems',
        'setLocked',
        'setPublished',
        'setHideInMenu',
      );
      if (in_array($operation, $pageDetailOperations, true) && !$this->platformAllows($site, 'pageBreak')) {
        return array(
          '__failed' => array(
            'status' => 403,
            'message' => 'Page details editing is disabled for this site',
          )
        );
      }
      $page = $site->loadNode($this->params['node']['id']);
      if (!$page) {
        return array(
          '__failed' => array(
            'status' => 404,
            'message' => 'Node not found',
          )
        );
      }
      // Store original count for safety check
      $originalItemCount = count($site->manifest->items);
      $items = $site->manifest->items;
      $sameParent = function($a, $b) {
        $pa = isset($a->parent) ? $a->parent : null;
        $pb = isset($b->parent) ? $b->parent : null;
        return ($pa === $pb);
      };
      $siblings = array();
      foreach ($items as $it) {
        if ($sameParent($it, $page)) { $siblings[] = $it; }
      }
      // helper to find sibling by order within same parent
      $findSiblingByOrder = function($order) use ($siblings) {
        foreach ($siblings as $s) {
          if (isset($s->order) && (int)$s->order === (int)$order) { return $s; }
        }
        return null;
      };
      // helper to get last child order for a given parent id
      $lastChildOrder = function($parentId) use ($items) {
        $max = -1;
        foreach ($items as $it) {
          $p = isset($it->parent) ? $it->parent : null;
          if ($p === $parentId && isset($it->order)) {
            $o = (int)$it->order;
            if ($o > $max) { $max = $o; }
          }
        }
        return $max;
      };

      switch ($operation) {
        case 'moveUp':
          if (isset($page->order) && (int)$page->order > 0) {
            $other = $findSiblingByOrder((int)$page->order - 1);
            if ($other && $other->id !== $page->id) {
              $other->order = (int)$other->order + 1;
              $page->order = (int)$page->order - 1;
            }
          }
          break;
        case 'moveDown':
          if (isset($page->order)) {
            $other = $findSiblingByOrder((int)$page->order + 1);
            if ($other && $other->id !== $page->id) {
              $other->order = (int)$other->order - 1;
              $page->order = (int)$page->order + 1;
            }
          }
          break;
        case 'indent':
          if (isset($page->order)) {
            $prev = $findSiblingByOrder((int)$page->order - 1);
            if ($prev) {
              $page->parent = $prev->id;
              $page->indent = isset($prev->indent) ? ((int)$prev->indent + 1) : 1;
              $page->order = $lastChildOrder($prev->id) + 1;
            }
          }
          break;
        case 'outdent':
          if (isset($page->parent) && $page->parent !== null) {
            $parentNode = $site->loadNode($page->parent);
            $newParent = $parentNode ? $parentNode->parent : null;
            $insertAfterOrder = $parentNode && isset($parentNode->order) ? ((int)$parentNode->order + 1) : 0;
            // shift siblings in new parent group to make space
            foreach ($items as $it) {
              $p = isset($it->parent) ? $it->parent : null;
              if ($p === $newParent && isset($it->order) && (int)$it->order >= $insertAfterOrder) {
                $it->order = (int)$it->order + 1;
              }
            }
            $page->parent = $newParent;
            $page->indent = isset($page->indent) ? max(((int)$page->indent) - 1, 0) : 0;
            $page->order = $insertAfterOrder;
          }
          break;
        case 'setParent':
          // Move page under a specific parent
          // Use array_key_exists to properly handle null values
          $newParent = array_key_exists('parent', $this->params['node']['details']) ? $this->params['node']['details']['parent'] : null;
          $newOrder = array_key_exists('order', $this->params['node']['details']) ? (int)$this->params['node']['details']['order'] : 0;
          // account for this being set to empty string which means null
          if (!$newParent || $newParent === '') {
            $newParent = null;
          }
          // Update the page's parent and order
          $page->parent = $newParent;
          $page->order = $newOrder;
          // Calculate indent based on new parent depth
          if ($newParent === null) {
            $page->indent = 0;
          } else {
            $parentNode = $site->loadNode($newParent);
            $page->indent = $parentNode && isset($parentNode->indent) ? ((int)$parentNode->indent + 1) : 1;
          }
          break;
        // Singular field modification operations
        case 'setTitle':
          if (array_key_exists('title', $this->params['node']['details']) && $this->params['node']['details']['title'] !== '') {
            $page->title = strip_tags($this->params['node']['details']['title']);
          }
          break;
        case 'setDescription':
          if (array_key_exists('description', $this->params['node']['details'])) {
            if ($this->params['node']['details']['description'] !== '') {
              $page->description = strip_tags($this->params['node']['details']['description']);
            } else {
              $page->description = '';
            }
          }
          break;
        case 'setTags':
          if (!isset($page->metadata)) {
            $page->metadata = new stdClass();
          }
          if (array_key_exists('tags', $this->params['node']['details'])) {
            if ($this->params['node']['details']['tags'] !== '' && $this->params['node']['details']['tags'] !== null) {
              $page->metadata->tags = SanitizeContent::sanitizeMetadataValue(
                $this->params['node']['details']['tags']
              );
            } else {
              unset($page->metadata->tags);
            }
          }
          break;
        case 'setIcon':
          if (!isset($page->metadata)) {
            $page->metadata = new stdClass();
          }
          if (array_key_exists('icon', $this->params['node']['details'])) {
            if ($this->params['node']['details']['icon'] !== '' && $this->params['node']['details']['icon'] !== null) {
              $page->metadata->icon = SanitizeContent::sanitizeMetadataValue(
                $this->params['node']['details']['icon']
              );
            } else {
              unset($page->metadata->icon);
            }
          }
          break;
        case 'setMedia':
        case 'setImage':
          if (!isset($page->metadata)) {
            $page->metadata = new stdClass();
          }
          if (array_key_exists('image', $this->params['node']['details'])) {
            if ($this->params['node']['details']['image'] !== '' && $this->params['node']['details']['image'] !== null) {
              $page->metadata->image = SanitizeContent::sanitizeURLValue(
                $this->params['node']['details']['image'],
                ''
              );
            } else {
              unset($page->metadata->image);
            }
          }
          break;
        case 'setRelatedItems':
          if (!isset($page->metadata)) {
            $page->metadata = new stdClass();
          }
          if (array_key_exists('relatedItems', $this->params['node']['details'])) {
            if ($this->params['node']['details']['relatedItems'] !== '' && $this->params['node']['details']['relatedItems'] !== null) {
              $page->metadata->relatedItems = SanitizeContent::sanitizeMetadataValue(
                $this->params['node']['details']['relatedItems']
              );
            } else {
              unset($page->metadata->relatedItems);
            }
          }
          break;
        case 'setLocked':
          if (!isset($page->metadata)) {
            $page->metadata = new stdClass();
          }
          if (array_key_exists('locked', $this->params['node']['details'])) {
            $page->metadata->locked = filter_var($this->params['node']['details']['locked'], FILTER_VALIDATE_BOOLEAN);
          }
          break;
        case 'setPublished':
          if (!isset($page->metadata)) {
            $page->metadata = new stdClass();
          }
          if (array_key_exists('published', $this->params['node']['details'])) {
            $page->metadata->published = filter_var($this->params['node']['details']['published'], FILTER_VALIDATE_BOOLEAN);
          }
          break;
        case 'setHideInMenu':
          if (!isset($page->metadata)) {
            $page->metadata = new stdClass();
          }
          if (array_key_exists('hideInMenu', $this->params['node']['details'])) {
            $page->metadata->hideInMenu = filter_var($this->params['node']['details']['hideInMenu'], FILTER_VALIDATE_BOOLEAN);
          }
          break;
        case 'setSlug':
          // Limited case - allow modifying slug but validate it's unique
          if (array_key_exists('slug', $this->params['node']['details']) && $this->params['node']['details']['slug'] !== '') {
            $newSlug = $this->params['node']['details']['slug'];
            // account for x being the only front end reserved route
            if ($newSlug == "x") {
              $newSlug = "x-x";
            }
            // same but trying to force a sub-route; paths cannot conflict with frontend
            if (substr($newSlug, 0, 2) == "x/") {
              $newSlug = str_replace('x/', 'x-x/', $newSlug);
            }
            $page->slug = $GLOBALS['HAXCMS']->generateSlugName($newSlug);
          }
          break;
        default:
          break;
      }

      // Since loadNode returns a reference, $page modifications already update the manifest
      // Only reassign items if we made a copy that needs to go back
      $site->manifest->items = $items;
      
      // Safety check: ensure item count hasn't changed
      if (count($site->manifest->items) !== $originalItemCount) {
        return array(
          '__failed' => array(
            'status' => 500,
            'message' => 'Item count mismatch: expected ' . $originalItemCount . ' but got ' . count($site->manifest->items) . '. Operation aborted to prevent data loss.',
          )
        );
      }
      
      $site->manifest->metadata->site->updated = time();
      $site->manifest->save(false);
      $site->updateAlternateFormats();
      $site->gitCommit('Node operation: ' . $operation . ' on ' . $page->title . ' (' . $page->id . ')');

      $updated = $site->loadNode($page->id);
      return array(
        'status' => 200,
        'data' => $updated,
      );
    }
    else {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid site token',
        )
      );
    }
  }

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

  /**
   * @OA\Get(
   *    path="/connectionSettings",
   *    tags={"cms"},
   *    @OA\Response(
   *        response="200",
   *        description="Generate the connection settings dynamically for implying we have a PHP backend"
   *   )
   * )
   */
  public function connectionSettings() {
    // In HAXiam mode, require an authenticated user and enforce
    // /<username>/system/api/* path alignment with authenticated principal.
    if (isset($GLOBALS['HAXCMS']->config->iam) && $GLOBALS['HAXCMS']->config->iam) {
      $tenantUser = $GLOBALS['HAXCMS']->getIAMTenantUserName();
      $pathUser = $GLOBALS['HAXCMS']->getRequestPathUserName();
      // If both are present they must agree.
      if (!is_null($tenantUser) && $tenantUser != '' && !is_null($pathUser) && $pathUser != '' && $tenantUser !== $pathUser) {
        return array(
          '__failed' => array(
            'status' => 403,
            'message' => 'Access denied',
          )
        );
      }
      // Expected IAM user identity for this request.
      $expectedUser = null;
      if (!is_null($tenantUser) && $tenantUser != '') {
        $expectedUser = $tenantUser;
      }
      else if (!is_null($pathUser) && $pathUser != '') {
        $expectedUser = $pathUser;
      }
      if (!is_null($expectedUser) && $expectedUser != '') {
        $authenticatedUser = $GLOBALS['HAXCMS']->getAuthenticatedUserName();
        if (is_null($authenticatedUser) || $authenticatedUser == '' || $authenticatedUser !== $expectedUser) {
          return array(
            '__failed' => array(
              'status' => 403,
              'message' => 'Access denied',
            )
          );
        }
      }
    }
    // need to return this as if it was a javascript file, weird looking for sure
    return array(
      '__noencode' => array(
        'status' => 200,
        'contentType' => 'application/javascript',
        'message' => 'window.appSettings = ' . json_encode($GLOBALS['HAXCMS']->appJWTConnectionSettings($GLOBALS['HAXCMS']->basePath)) . ';',
      )
    );
  }
  /**
   * 
   * HAX EDITOR CALLBACKS
   * 
   */

  /**
   * @OA\GET(
   *    path="/generateAppStore",
   *    tags={"hax","api"},
   *    @OA\Parameter(
   *         name="appstore_token",
   *         description="security token for appstore",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Generate the AppStore spec for HAX editor directions"
   *   )
   * )
   */
  public function generateAppStore() {
    // test if this is a valid user login with this specialty token that HAX looks for
    if (
      isset($this->params['appstore_token']) &&
      $GLOBALS['HAXCMS']->validateRequestToken($this->params['appstore_token'], 'appstore') &&
      isset($this->params['site_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['site_token'], $GLOBALS['HAXCMS']->getActiveUserName() . ':' . $this->params['site']['name'])) {
      $haxService = new HAXAppStoreService();
      $apikeys = array();
      $baseApps = $haxService->baseSupportedApps();
      foreach ($baseApps as $key => $app) {
        if (
          isset($GLOBALS['HAXCMS']->config->appStore->apiKeys->{$key}) &&
          $GLOBALS['HAXCMS']->config->appStore->apiKeys->{$key} != ''
        ) {
          $apikeys[$key] = $GLOBALS['HAXCMS']->config->appStore->apiKeys->{$key};
        }
      }
      $appStore = $haxService->loadBaseAppStore($apikeys);
      // pull in the core one we supply, though only upload works currently
      $tmp = json_decode($GLOBALS['HAXCMS']->siteConnectionJSON($this->params['site_token']));
      array_push($appStore, $tmp);
      if (isset($GLOBALS['HAXCMS']->config->appStore->stax)) {
          $staxList = $GLOBALS['HAXCMS']->config->appStore->stax;
      } else {
          $staxList = $haxService->loadBaseStax();
      }
      if (isset($GLOBALS['HAXCMS']->config->appStore->autoloader)) {
          $autoloaderList = $GLOBALS['HAXCMS']->config->appStore->autoloader;
      } else {
          $autoloaderList = json_decode('
        [
          "lesson-overview",
          "lesson-highlight",
          "video-player",
          "meme-maker",
          "lrn-aside",
          "grid-plate",
          "magazine-cover",
          "image-compare-slider",
          "license-element",
          "self-check",
          "multiple-choice",
          "oer-schema",
          "hero-banner",
          "task-list",
          "lrn-table",
          "media-image",
          "lrndesign-blockquote",
          "a11y-gif-player",
          "wikipedia-query",
          "lrn-vocab",
          "full-width-image",
          "person-testimonial",
          "citation-element",
          "stop-note",
          "learning-component",
          "mark-the-words",
          "twitter-embed",
          "spotify-embed",
          "place-holder",
          "lrn-math",
          "q-r",
          "lrndesign-gallery",
          "lrndesign-timeline"
        ]
        ');
      }
      return array(
          'status' => 200,
          'apps' => $appStore,
          'stax' => $staxList,
          'autoloader' => $autoloaderList
      );
    }
  }
  /**
   * @OA\Post(
   *    path="/getUserData",
   *    tags={"cms","authenticated","user","settings"},
   *    @OA\Parameter(
   *         name="user_token",
   *         description="User validation token",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Load data about the logged in user"
   *   )
   * )
   */
  public function getUserData() {
    if (isset($this->params['user_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['user_token'], $GLOBALS['HAXCMS']->getActiveUserName())) {
      return array(
        'status' => 200,
        'data' => $GLOBALS['HAXCMS']->userData
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
  /**
   * @OA\Post(
   *    path="/formLoad",
   *    tags={"cms","authenticated","form"},
   *    @OA\Parameter(
   *         name="haxcms_form_id",
   *         description="Form identifier to load",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Load a form based on ID"
   *   )
   * )
   */
  public function formLoad() {
    if ($GLOBALS['HAXCMS']->validateRequestToken(null, 'form')) {
      $context = array(
        'site' => array(),
        'node' => array(),
      );
      if (isset($this->params['site'])) {
        $context['site'] = $this->params['site'];
      }
      if (isset($this->params['node'])) {
        $context['node'] = $this->params['node'];
      }
      // @todo add support for hooking in multiple
      $form = $GLOBALS['HAXCMS']->loadForm($this->params['haxcms_form_id'], $context);
      if (isset($form->fields['__failed'])) {
        return array(
          $form->fields
        );
      }
      return array(
        'status' => 200,
        'data' => $form
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
  /**
   * @OA\Post(
   *    path="/formProcess",
   *    tags={"cms","authenticated","form"},
   *    @OA\Parameter(
   *         name="haxcms_form_id",
   *         description="Form identifier to process",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Parameter(
   *         name="haxcms_form_token",
   *         description="Form request token",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Process a form based on ID and input data"
   *   )
   * )
   */
  public function formProcess() {
    if ($GLOBALS['HAXCMS']->validateRequestToken($this->params['haxcms_form_token'], $this->params['haxcms_form_id'])) {
      $context = array(
        'site' => array(),
        'node' => array(),
      );
      if (isset($this->params['site'])) {
        $context['site'] = $this->params['site'];
      }
      if (isset($this->params['node'])) {
        $context['node'] = $this->params['node'];
      }
      $form = $GLOBALS['HAXCMS']->processForm($this->params['haxcms_form_id'], $this->params, $context);
      if (isset($form->fields['__failed'])) {
        return array(
          $form->fields
        );
      }
      return array(
        'status' => 200,
        'data' => $form
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
  /**
   * @OA\Get(
   *    path="/listFiles",
   *    tags={"hax","authenticated","file"},
   *    @OA\Parameter(
   *         name="site_token",
   *         description="Site-specific validation token",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Load existing files for presentation in HAX find area"
   *   )
   * )
   */
  public function listFiles() {
    if (isset($this->params['site_token']) && !isset($this->params['site']) && !isset($this->params['siteName'])) {
      $tmp = explode('?siteName=', $this->params['site_token']);
      if (count($tmp) == 2) {
        $this->params['site_token'] = $tmp[0];
        $this->params['siteName'] = $tmp[1];
      }
    }
    $siteName = '';
    if (isset($this->params['site']) && isset($this->params['site']['name'])) {
      $siteName = (string) $this->params['site']['name'];
    }
    else if (isset($this->params['siteName'])) {
      $siteName = (string) $this->params['siteName'];
    }
    if (
      !isset($this->params['site_token']) ||
      $siteName == '' ||
      !$GLOBALS['HAXCMS']->validateRequestToken($this->params['site_token'], $GLOBALS['HAXCMS']->getActiveUserName() . ':' . $siteName)
    ) {
      return array();
    }
    $site = $GLOBALS['HAXCMS']->loadSite($siteName);
    if (!$site) {
      return array();
    }
    $search = (isset($this->params['filename'])) ? (string) $this->params['filename'] : '';
    $fileDir = $this->getSiteFilesDirectory($site);
    return $this->collectSiteFiles($site, $fileDir, $search);
  }
  private function normalizeFilePathValue($value) {
    return str_replace('\\', '/', (string) $value);
  }
  private function getSiteFilesDirectory($site) {
    return $site->directory . '/' . $site->manifest->metadata->site->name . '/files';
  }
  private function isSiteInMultisiteContext($site) {
    if (
      isset($GLOBALS['HAXCMS']->operatingContext) &&
      $GLOBALS['HAXCMS']->operatingContext == 'multisite'
    ) {
      return true;
    }
    $siteRoot = realpath($site->directory . '/' . $site->manifest->metadata->site->name);
    if ($siteRoot !== false) {
      $siteRoot = rtrim($this->normalizeFilePathValue($siteRoot), '/');
      $multisiteBootstrap = $siteRoot . '/../../system/backend/php/bootstrapHAX.php';
      if (file_exists($siteRoot . '/config.php') && !file_exists($multisiteBootstrap)) {
        return false;
      }
    }
    if (method_exists($GLOBALS['HAXCMS'], 'getDeploymentProfile')) {
      return $GLOBALS['HAXCMS']->getDeploymentProfile() != 'single-site';
    }
    return false;
  }
  private function buildSiteFileUrl($site, $relativeFilePath) {
    $normalizedRelativePath = ltrim($this->normalizeFilePathValue($relativeFilePath), '/');
    $fullUrl = '/' . $normalizedRelativePath;
    if ($this->isSiteInMultisiteContext($site)) {
      $fullUrl = $GLOBALS['HAXCMS']->basePath .
        $GLOBALS['HAXCMS']->sitesDirectory .
        '/' .
        $site->manifest->metadata->site->name .
        '/' .
        $normalizedRelativePath;
    }
    return $fullUrl;
  }
  private function buildSiteFileRecord($site, $absolutePath, $siteRelativePath = '') {
    $safeRelativePath = ltrim($this->normalizeFilePathValue($siteRelativePath), '/');
    $mimetype = @mime_content_type($absolutePath);
    if (!$mimetype) {
      $mimetype = '';
    }
    $size = @filesize($absolutePath);
    if ($size === false) {
      $size = 0;
    }
    $dateCreated = 0;
    $createdTimestamp = @filemtime($absolutePath);
    if ($createdTimestamp === false || $createdTimestamp <= 0) {
      $createdTimestamp = @filectime($absolutePath);
    }
    if ($createdTimestamp !== false && $createdTimestamp > 0) {
      $dateCreated = ((int) $createdTimestamp) * 1000;
    }
    $fullUrl = $this->buildSiteFileUrl($site, $safeRelativePath);
    if ($dateCreated > 0) {
      $fullUrl .= (strpos($fullUrl, '?') === false ? '?t=' : '&t=') . $dateCreated;
    }
    return array(
      'path' => $safeRelativePath,
      'url' => $safeRelativePath,
      'fullUrl' => $fullUrl,
      'mimetype' => $mimetype,
      'name' => basename($safeRelativePath),
      'size' => $size,
      'dateCreated' => $dateCreated,
    );
  }
  private function isManagedDerivativePath($relativePath = '') {
    $normalizedRelativePath = ltrim(
      $this->normalizeFilePathValue($relativePath),
      '/'
    );
    return (
      $normalizedRelativePath === 'haxcms-managed' ||
      strpos($normalizedRelativePath, 'haxcms-managed/') === 0
    );
  }
  private function collectSiteFiles($site, $fileDir, $search = '') {
    $files = array();
    if (!is_dir($fileDir)) {
      return $files;
    }
    $searchValue = strtolower(trim((string) $search));
    $ignoredFiles = array('.', '..', '.gitkeep', '.DS_Store', '._.DS_Store', '.htaccess', '._htaccess');
    $rootPath = rtrim($this->normalizeFilePathValue($fileDir), '/');
    try {
      $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fileDir, FilesystemIterator::SKIP_DOTS)
      );
      foreach ($iterator as $entry) {
        if (!$entry->isFile() || $entry->isLink()) {
          continue;
        }
        $entryName = $entry->getFilename();
        if (in_array($entryName, $ignoredFiles, true)) {
          continue;
        }
        $absolutePath = $entry->getPathname();
        $normalizedAbsolutePath = $this->normalizeFilePathValue($absolutePath);
        if (
          $normalizedAbsolutePath !== $rootPath &&
          strpos($normalizedAbsolutePath, $rootPath . '/') !== 0
        ) {
          continue;
        }
        $relativePath = substr($normalizedAbsolutePath, strlen($rootPath) + 1);
        if ($relativePath === false || $relativePath === '') {
          continue;
        }
        $relativePath = ltrim($relativePath, '/');
        if ($this->isManagedDerivativePath($relativePath)) {
          continue;
        }
        if (
          $searchValue !== '' &&
          strpos(strtolower($relativePath), $searchValue) === false &&
          strpos(strtolower($entryName), $searchValue) === false
        ) {
          continue;
        }
        $siteRelativePath = 'files/' . $relativePath;
        $files[] = $this->buildSiteFileRecord($site, $absolutePath, $siteRelativePath);
      }
    }
    catch (Exception $e) {}
    usort($files, function($a, $b) {
      return strcmp($a['path'], $b['path']);
    });
    return $files;
  }
  private function resolveSiteFileOperationPath($site, $requestedPath) {
    if (!is_string($requestedPath)) {
      return array(
        'valid' => false,
        'status' => 400,
        'message' => 'Invalid file path',
      );
    }
    $normalizedPath = trim(urldecode($requestedPath));
    $normalizedPath = $this->normalizeFilePathValue($normalizedPath);
    $normalizedPath = ltrim($normalizedPath, '/');
    while (strpos($normalizedPath, './') === 0) {
      $normalizedPath = substr($normalizedPath, 2);
    }
    if (
      $normalizedPath === '' ||
      strpos($normalizedPath, "\0") !== false ||
      strpos($normalizedPath, '..') !== false
    ) {
      return array(
        'valid' => false,
        'status' => 400,
        'message' => 'Invalid file path',
      );
    }
    if (strpos($normalizedPath, 'files/') !== 0) {
      return array(
        'valid' => false,
        'status' => 400,
        'message' => 'File path must start with files/',
      );
    }
    $siteRoot = realpath($site->directory . '/' . $site->manifest->metadata->site->name);
    if ($siteRoot === false) {
      return array(
        'valid' => false,
        'status' => 500,
        'message' => 'Unable to resolve site path',
      );
    }
    $siteRoot = rtrim($this->normalizeFilePathValue($siteRoot), '/');
    $filesRoot = $siteRoot . '/files';
    if (!is_dir($filesRoot)) {
      return array(
        'valid' => false,
        'status' => 404,
        'message' => 'Files directory was not found',
      );
    }
    $candidatePath = $siteRoot . '/' . $normalizedPath;
    $resolvedPath = realpath($candidatePath);
    if ($resolvedPath === false || !is_file($resolvedPath)) {
      return array(
        'valid' => false,
        'status' => 404,
        'message' => 'Requested file was not found',
      );
    }
    $resolvedPath = rtrim($this->normalizeFilePathValue($resolvedPath), '/');
    if (
      $resolvedPath !== $filesRoot &&
      strpos($resolvedPath, $filesRoot . '/') !== 0
    ) {
      return array(
        'valid' => false,
        'status' => 403,
        'message' => 'File path is outside of allowed files directory',
      );
    }
    if (is_link($resolvedPath)) {
      return array(
        'valid' => false,
        'status' => 404,
        'message' => 'Requested file path is not a valid file',
      );
    }
    return array(
      'valid' => true,
      'normalizedPath' => $normalizedPath,
      'resolvedPath' => $resolvedPath,
      'siteRoot' => $siteRoot,
      'filesRoot' => $filesRoot,
    );
  }
  private function getScalePresetByKey($sizeKey) {
    $key = strtolower(trim((string) $sizeKey));
    if ($key == '' || !isset($this->imageScalePresets[$key])) {
      $key = 'md';
    }
    return array(
      'key' => $key,
      'preset' => $this->imageScalePresets[$key],
      'presets' => $this->imageScalePresets,
    );
  }
  private function getSafeImageOpsBaseName($relativePath) {
    $baseName = pathinfo($relativePath, PATHINFO_FILENAME);
    $safeBaseName = preg_replace('/[^a-zA-Z0-9._-]/', '-', $baseName);
    $safeBaseName = preg_replace('/-+/', '-', $safeBaseName);
    $safeBaseName = trim($safeBaseName, '.-_');
    if ($safeBaseName === '') {
      $safeBaseName = 'image';
    }
    return $safeBaseName;
  }
  private function ensureImageOpsOutputDirectory($filesRoot) {
    $outputDirectory = rtrim($filesRoot, '/') . '/imgops';
    if (file_exists($outputDirectory) && is_link($outputDirectory)) {
      return array(
        'valid' => false,
        'status' => 400,
        'message' => 'Output path is not writable',
      );
    }
    if (!is_dir($outputDirectory) && !@mkdir($outputDirectory, 0755, true)) {
      return array(
        'valid' => false,
        'status' => 500,
        'message' => 'Unable to prepare image operations directory',
      );
    }
    $resolvedOutputDirectory = realpath($outputDirectory);
    if ($resolvedOutputDirectory === false) {
      return array(
        'valid' => false,
        'status' => 500,
        'message' => 'Unable to prepare image operations directory',
      );
    }
    $resolvedOutputDirectory = rtrim($this->normalizeFilePathValue($resolvedOutputDirectory), '/');
    $normalizedFilesRoot = rtrim($this->normalizeFilePathValue($filesRoot), '/');
    if (
      $resolvedOutputDirectory !== $normalizedFilesRoot . '/imgops' &&
      strpos($resolvedOutputDirectory, $normalizedFilesRoot . '/') !== 0
    ) {
      return array(
        'valid' => false,
        'status' => 403,
        'message' => 'Invalid output file path',
      );
    }
    return array(
      'valid' => true,
      'outputDirectory' => $resolvedOutputDirectory,
    );
  }
  private function buildImageOpsOutputPath($filesRoot, $sourceRelativePath, $width, $height) {
    $directoryResult = $this->ensureImageOpsOutputDirectory($filesRoot);
    if (!$directoryResult['valid']) {
      return $directoryResult;
    }
    $outputFileName = $this->getSafeImageOpsBaseName($sourceRelativePath) .
      '-' . ((int) $width) .
      'x' . ((int) $height) .
      '.jpg';
    $outputPath = $directoryResult['outputDirectory'] . '/' . $outputFileName;
    if (file_exists($outputPath) && is_link($outputPath)) {
      return array(
        'valid' => false,
        'status' => 400,
        'message' => 'Output path is not writable',
      );
    }
    return array(
      'valid' => true,
      'outputPath' => $outputPath,
      'relativePath' => 'files/imgops/' . $outputFileName,
    );
  }
  private function isImageProcessingAvailable() {
    return
      function_exists('imagecreatefromstring') &&
      function_exists('imagecreatetruecolor') &&
      function_exists('imagejpeg') &&
      function_exists('imagecopy') &&
      function_exists('imagecopyresampled') &&
      function_exists('imagecolorallocate') &&
      function_exists('imagefill');
  }
  private function createImageResourceFromPath($sourcePath) {
    $contents = @file_get_contents($sourcePath);
    if ($contents === false) {
      return false;
    }
    return @imagecreatefromstring($contents);
  }
  private function convertImageToJpgFile($sourcePath, $outputPath, $transformMode = 'none') {
    if (!$this->isImageProcessingAvailable()) {
      return array(
        'success' => false,
        'status' => 500,
        'message' => 'Image conversion support is unavailable on this server',
      );
    }
    $mode = strtolower(trim((string) $transformMode));
    if ($mode == '') {
      $mode = 'none';
    }
    if (!in_array($mode, array('none', 'sepia', 'black-and-white'), true)) {
      return array(
        'success' => false,
        'status' => 400,
        'message' => 'Unsupported transform operation',
      );
    }
    if (
      $mode != 'none' &&
      (
        !function_exists('imagefilter') ||
        !defined('IMG_FILTER_GRAYSCALE') ||
        !defined('IMG_FILTER_COLORIZE')
      )
    ) {
      return array(
        'success' => false,
        'status' => 500,
        'message' => 'Image transform support is unavailable on this server',
      );
    }
    $sourceImage = $this->createImageResourceFromPath($sourcePath);
    if (!$sourceImage) {
      return array(
        'success' => false,
        'status' => 400,
        'message' => 'Only raster images can be converted to JPG',
      );
    }
    $width = imagesx($sourceImage);
    $height = imagesy($sourceImage);
    if ($width <= 0 || $height <= 0) {
      imagedestroy($sourceImage);
      return array(
        'success' => false,
        'status' => 500,
        'message' => 'Unable to determine source image size',
      );
    }
    $targetImage = imagecreatetruecolor($width, $height);
    if (!$targetImage) {
      imagedestroy($sourceImage);
      return array(
        'success' => false,
        'status' => 500,
        'message' => 'Unable to prepare image conversion',
      );
    }
    $background = imagecolorallocate($targetImage, 255, 255, 255);
    imagefill($targetImage, 0, 0, $background);
    imagecopy($targetImage, $sourceImage, 0, 0, 0, 0, $width, $height);
    if ($mode == 'black-and-white') {
      @imagefilter($targetImage, IMG_FILTER_GRAYSCALE);
    }
    else if ($mode == 'sepia') {
      @imagefilter($targetImage, IMG_FILTER_GRAYSCALE);
      @imagefilter($targetImage, IMG_FILTER_COLORIZE, 90, 55, 30);
    }
    $jpgQuality = ($mode == 'none') ? 90 : 82;
    $saved = @imagejpeg($targetImage, $outputPath, $jpgQuality);
    imagedestroy($sourceImage);
    imagedestroy($targetImage);
    if (!$saved) {
      return array(
        'success' => false,
        'status' => 500,
        'message' => 'Unable to save converted image',
      );
    }
    return array(
      'success' => true,
      'width' => $width,
      'height' => $height,
    );
  }
  private function scaleImageToPresetFile($sourcePath, $outputPath, $targetWidth, $targetHeight) {
    if (!$this->isImageProcessingAvailable()) {
      return array(
        'success' => false,
        'status' => 500,
        'message' => 'Image scaling support is unavailable on this server',
      );
    }
    $sourceImage = $this->createImageResourceFromPath($sourcePath);
    if (!$sourceImage) {
      return array(
        'success' => false,
        'status' => 400,
        'message' => 'Only raster images can be scaled',
      );
    }
    $sourceWidth = imagesx($sourceImage);
    $sourceHeight = imagesy($sourceImage);
    if ($sourceWidth <= 0 || $sourceHeight <= 0) {
      imagedestroy($sourceImage);
      return array(
        'success' => false,
        'status' => 500,
        'message' => 'Unable to determine source image size',
      );
    }
    $targetWidth = max((int) $targetWidth, 1);
    $targetHeight = max((int) $targetHeight, 1);
    $ratio = min(
      $targetWidth / $sourceWidth,
      $targetHeight / $sourceHeight,
      1
    );
    $outputWidth = max((int) floor($sourceWidth * $ratio), 1);
    $outputHeight = max((int) floor($sourceHeight * $ratio), 1);
    $targetImage = imagecreatetruecolor($outputWidth, $outputHeight);
    if (!$targetImage) {
      imagedestroy($sourceImage);
      return array(
        'success' => false,
        'status' => 500,
        'message' => 'Unable to prepare image scaling',
      );
    }
    $background = imagecolorallocate($targetImage, 255, 255, 255);
    imagefill($targetImage, 0, 0, $background);
    imagecopyresampled(
      $targetImage,
      $sourceImage,
      0,
      0,
      0,
      0,
      $outputWidth,
      $outputHeight,
      $sourceWidth,
      $sourceHeight
    );
    $saved = @imagejpeg($targetImage, $outputPath, 82);
    imagedestroy($sourceImage);
    imagedestroy($targetImage);
    if (!$saved) {
      return array(
        'success' => false,
        'status' => 500,
        'message' => 'Unable to save scaled image',
      );
    }
    return array(
      'success' => true,
      'width' => $outputWidth,
      'height' => $outputHeight,
    );
  }
  private function getImageExtensionForRotation($sourcePath) {
    $extension = strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION));
    if ($extension == 'jpeg') {
      $extension = 'jpg';
    }
    return $extension;
  }
  private function saveImageResourceByExtension($imageResource, $outputPath, $extension) {
    if ($extension == 'jpg') {
      if (!function_exists('imagejpeg')) {
        return false;
      }
      return @imagejpeg($imageResource, $outputPath, 90);
    }
    if ($extension == 'png') {
      if (!function_exists('imagepng')) {
        return false;
      }
      return @imagepng($imageResource, $outputPath, 6);
    }
    if ($extension == 'gif') {
      if (!function_exists('imagegif')) {
        return false;
      }
      return @imagegif($imageResource, $outputPath);
    }
    if ($extension == 'webp') {
      if (!function_exists('imagewebp')) {
        return false;
      }
      return @imagewebp($imageResource, $outputPath, 90);
    }
    return false;
  }
  private function rotateImageInPlaceFile($sourcePath, $degrees = 90) {
    if (
      !$this->isImageProcessingAvailable() ||
      !function_exists('imagerotate')
    ) {
      return array(
        'success' => false,
        'status' => 500,
        'message' => 'Image rotation support is unavailable on this server',
      );
    }
    $sourceImage = $this->createImageResourceFromPath($sourcePath);
    if (!$sourceImage) {
      return array(
        'success' => false,
        'status' => 400,
        'message' => 'Only raster images can be rotated',
      );
    }
    $extension = $this->getImageExtensionForRotation($sourcePath);
    if (!in_array($extension, array('jpg', 'png', 'gif', 'webp'), true)) {
      imagedestroy($sourceImage);
      return array(
        'success' => false,
        'status' => 400,
        'message' => 'Image format does not support in-place rotation',
      );
    }
    $rotationDegrees = (int) $degrees;
    if ($rotationDegrees <= 0) {
      $rotationDegrees = 90;
    }
    $rotationDegrees = 360 - ($rotationDegrees % 360);
    if ($rotationDegrees == 360) {
      $rotationDegrees = 0;
    }
    $backgroundColor = imagecolorallocatealpha($sourceImage, 0, 0, 0, 127);
    $rotatedImage = @imagerotate($sourceImage, $rotationDegrees, $backgroundColor);
    imagedestroy($sourceImage);
    if (!$rotatedImage) {
      return array(
        'success' => false,
        'status' => 500,
        'message' => 'Unable to rotate image',
      );
    }
    if (in_array($extension, array('png', 'gif', 'webp'), true)) {
      @imagealphablending($rotatedImage, false);
      @imagesavealpha($rotatedImage, true);
    }
    $temporaryPath = $sourcePath . '.rotate-' . uniqid('', true);
    $saved = $this->saveImageResourceByExtension(
      $rotatedImage,
      $temporaryPath,
      $extension
    );
    imagedestroy($rotatedImage);
    if (!$saved) {
      if (file_exists($temporaryPath)) {
        @unlink($temporaryPath);
      }
      return array(
        'success' => false,
        'status' => 500,
        'message' => 'Unable to save rotated image',
      );
    }
    if (!@rename($temporaryPath, $sourcePath)) {
      if (@copy($temporaryPath, $sourcePath)) {
        @unlink($temporaryPath);
      }
      else {
        @unlink($temporaryPath);
        return array(
          'success' => false,
          'status' => 500,
          'message' => 'Unable to replace original image after rotation',
        );
      }
    }
    return array(
      'success' => true,
    );
  }
  /**
   * @OA\\Post(
   *    path=\"/fileOperation\",
   *    tags={\"hax\",\"authenticated\",\"file\"},
   *    @OA\\Response(
   *        response=\"200\",
   *        description=\"Perform file operations for a site file\"
   *   )
   * )
   */
  public function fileOperation() {
    if (isset($this->params['site_token']) && !isset($this->params['site']) && !isset($this->params['siteName'])) {
      $tmp = explode('?siteName=', $this->params['site_token']);
      if (count($tmp) == 2) {
        $this->params['site_token'] = $tmp[0];
        $this->params['siteName'] = $tmp[1];
      }
    }
    $siteName = '';
    if (isset($this->params['site']) && isset($this->params['site']['name'])) {
      $siteName = (string) $this->params['site']['name'];
    }
    else if (isset($this->params['siteName'])) {
      $siteName = (string) $this->params['siteName'];
    }
    if (!isset($this->params['site_token'])) {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'Missing site token',
        )
      );
    }
    if ($siteName == '') {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'Missing site name',
        )
      );
    }
    if (!$GLOBALS['HAXCMS']->validateRequestToken($this->params['site_token'], $GLOBALS['HAXCMS']->getActiveUserName() . ':' . $siteName)) {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'Invalid site token',
        )
      );
    }
    $site = $GLOBALS['HAXCMS']->loadSite($siteName);
    if (!$site) {
      return array(
        '__failed' => array(
          'status' => 404,
          'message' => 'Site not found',
        )
      );
    }
    if (!$this->platformAllows($site, 'uploadMedia')) {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'File operations are disabled for this site',
        )
      );
    }
    $operation = isset($this->params['operation']) ? trim((string) $this->params['operation']) : '';
    if (!in_array($operation, array('delete', 'rename', 'convert-jpg', 'scale', 'sepia', 'black-and-white', 'rotate-90'), true)) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'Unsupported file operation',
        )
      );
    }
    $requestedPath = '';
    if (isset($this->params['path'])) {
      $requestedPath = $this->params['path'];
    }
    else if (isset($this->params['filePath'])) {
      $requestedPath = $this->params['filePath'];
    }
    else if (isset($this->params['file'])) {
      $requestedPath = $this->params['file'];
    }
    if (is_array($requestedPath) || is_object($requestedPath)) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'Only a single file path is allowed per request',
        )
      );
    }
    $pathResult = $this->resolveSiteFileOperationPath($site, $requestedPath);
    if (!$pathResult['valid']) {
      return array(
        '__failed' => array(
          'status' => $pathResult['status'],
          'message' => $pathResult['message'],
        )
      );
    }
    if ($operation == 'delete') {
      if (!@unlink($pathResult['resolvedPath'])) {
        return array(
          '__failed' => array(
            'status' => 500,
            'message' => 'Unable to delete file',
          )
        );
      }
      $site->gitCommit('File deleted: ' . $pathResult['normalizedPath']);
      return array(
        'status' => 200,
        'data' => array(
          'operation' => $operation,
          'path' => $pathResult['normalizedPath'],
          'deleted' => true,
        )
      );
    }
    if ($operation == 'rename') {
      $renameValue = '';
      if (isset($this->params['newName'])) {
        $renameValue = $this->params['newName'];
      }
      else if (isset($this->params['name'])) {
        $renameValue = $this->params['name'];
      }
      else if (isset($this->params['value'])) {
        $renameValue = $this->params['value'];
      }
      $renameResult = $this->buildRenamedFilePath($pathResult, $renameValue);
      if (!$renameResult['valid']) {
        return array(
          '__failed' => array(
            'status' => $renameResult['status'],
            'message' => $renameResult['message'],
          )
        );
      }
      if (!@rename($pathResult['resolvedPath'], $renameResult['outputPath'])) {
        return array(
          '__failed' => array(
            'status' => 500,
            'message' => 'Unable to rename file',
          )
        );
      }
      $fileRecord = $this->buildSiteFileRecord(
        $site,
        $renameResult['outputPath'],
        $renameResult['relativePath']
      );
      $site->gitCommit(
        'File renamed: ' .
        $pathResult['normalizedPath'] .
        ' -> ' .
        $renameResult['relativePath']
      );
      return array(
        'status' => 200,
        'data' => array(
          'operation' => $operation,
          'source' => $pathResult['normalizedPath'],
          'path' => $renameResult['relativePath'],
          'file' => $fileRecord,
        )
      );
    }
    if ($operation == 'rotate-90') {
      $rotateResult = $this->rotateImageInPlaceFile(
        $pathResult['resolvedPath'],
        90
      );
      if (!$rotateResult['success']) {
        return array(
          '__failed' => array(
            'status' => $rotateResult['status'],
            'message' => $rotateResult['message'],
          )
        );
      }
      $fileRecord = $this->buildSiteFileRecord(
        $site,
        $pathResult['resolvedPath'],
        $pathResult['normalizedPath']
      );
      $site->gitCommit('File rotated (90deg): ' . $pathResult['normalizedPath']);
      return array(
        'status' => 200,
        'data' => array(
          'operation' => $operation,
          'path' => $pathResult['normalizedPath'],
          'file' => $fileRecord,
        )
      );
    }
    if ($operation == 'convert-jpg') {
      $sourceDimensions = @getimagesize($pathResult['resolvedPath']);
      $targetWidth = (is_array($sourceDimensions) && isset($sourceDimensions[0]) && $sourceDimensions[0] > 0)
        ? (int) $sourceDimensions[0]
        : (int) $this->imageScalePresets['md']['width'];
      $targetHeight = (is_array($sourceDimensions) && isset($sourceDimensions[1]) && $sourceDimensions[1] > 0)
        ? (int) $sourceDimensions[1]
        : (int) $this->imageScalePresets['md']['height'];
      $outputResult = $this->buildImageOpsOutputPath(
        $pathResult['filesRoot'],
        $pathResult['normalizedPath'],
        $targetWidth,
        $targetHeight
      );
      if (!$outputResult['valid']) {
        return array(
          '__failed' => array(
            'status' => $outputResult['status'],
            'message' => $outputResult['message'],
          )
        );
      }
      $conversionResult = $this->convertImageToJpgFile(
        $pathResult['resolvedPath'],
        $outputResult['outputPath']
      );
      if (!$conversionResult['success']) {
        return array(
          '__failed' => array(
            'status' => $conversionResult['status'],
            'message' => $conversionResult['message'],
          )
        );
      }
      $fileRecord = $this->buildSiteFileRecord(
        $site,
        $outputResult['outputPath'],
        $outputResult['relativePath']
      );
      $site->gitCommit(
        'File converted to JPG: ' .
        $pathResult['normalizedPath'] .
        ' -> ' .
        $outputResult['relativePath']
      );
      return array(
        'status' => 200,
        'data' => array(
          'operation' => $operation,
          'source' => $pathResult['normalizedPath'],
          'file' => $fileRecord,
        )
      );
    }
    if ($operation == 'sepia' || $operation == 'black-and-white') {
      $sourceDimensions = @getimagesize($pathResult['resolvedPath']);
      $targetWidth = (is_array($sourceDimensions) && isset($sourceDimensions[0]) && $sourceDimensions[0] > 0)
        ? (int) $sourceDimensions[0]
        : (int) $this->imageScalePresets['md']['width'];
      $targetHeight = (is_array($sourceDimensions) && isset($sourceDimensions[1]) && $sourceDimensions[1] > 0)
        ? (int) $sourceDimensions[1]
        : (int) $this->imageScalePresets['md']['height'];
      $outputResult = $this->buildImageOpsOutputPath(
        $pathResult['filesRoot'],
        $pathResult['normalizedPath'] . '-' . $operation,
        $targetWidth,
        $targetHeight
      );
      if (!$outputResult['valid']) {
        return array(
          '__failed' => array(
            'status' => $outputResult['status'],
            'message' => $outputResult['message'],
          )
        );
      }
      $conversionResult = $this->convertImageToJpgFile(
        $pathResult['resolvedPath'],
        $outputResult['outputPath'],
        $operation
      );
      if (!$conversionResult['success']) {
        return array(
          '__failed' => array(
            'status' => $conversionResult['status'],
            'message' => $conversionResult['message'],
          )
        );
      }
      $fileRecord = $this->buildSiteFileRecord(
        $site,
        $outputResult['outputPath'],
        $outputResult['relativePath']
      );
      $site->gitCommit(
        'File transformed (' .
        $operation .
        '): ' .
        $pathResult['normalizedPath'] .
        ' -> ' .
        $outputResult['relativePath']
      );
      return array(
        'status' => 200,
        'data' => array(
          'operation' => $operation,
          'source' => $pathResult['normalizedPath'],
          'file' => $fileRecord,
        )
      );
    }
    $presetResult = $this->getScalePresetByKey(
      isset($this->params['size']) ? $this->params['size'] : ''
    );
    $outputResult = $this->buildImageOpsOutputPath(
      $pathResult['filesRoot'],
      $pathResult['normalizedPath'],
      $presetResult['preset']['width'],
      $presetResult['preset']['height']
    );
    if (!$outputResult['valid']) {
      return array(
        '__failed' => array(
          'status' => $outputResult['status'],
          'message' => $outputResult['message'],
        )
      );
    }
    $scaleResult = $this->scaleImageToPresetFile(
      $pathResult['resolvedPath'],
      $outputResult['outputPath'],
      $presetResult['preset']['width'],
      $presetResult['preset']['height']
    );
    if (!$scaleResult['success']) {
      return array(
        '__failed' => array(
          'status' => $scaleResult['status'],
          'message' => $scaleResult['message'],
        )
      );
    }
    $fileRecord = $this->buildSiteFileRecord(
      $site,
      $outputResult['outputPath'],
      $outputResult['relativePath']
    );
    $site->gitCommit(
      'File scaled (' .
      $presetResult['key'] .
      '): ' .
      $pathResult['normalizedPath'] .
      ' -> ' .
      $outputResult['relativePath']
    );
    return array(
      'status' => 200,
      'data' => array(
        'operation' => $operation,
        'source' => $pathResult['normalizedPath'],
        'size' => $presetResult['key'],
        'dimensions' => array(
          'width' => $presetResult['preset']['width'],
          'height' => $presetResult['preset']['height'],
        ),
        'presets' => $presetResult['presets'],
        'file' => $fileRecord,
      )
    );
  }
  /**
   * @OA\Get(
   *    path="/siteSearch",
   *    tags={"hax","authenticated","site"},
   *    @OA\Parameter(
   *         name="site_token",
   *         description="Site-specific validation token",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Parameter(
   *         name="siteName",
   *         description="Name of the site to search",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Parameter(
   *         name="search",
   *         description="Search query string",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Search site content and metadata fields"
   *   )
   * )
   */
  public function siteSearch() {
    $siteName = $this->getSiteSearchSiteName();
    if ($siteName == '') {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'siteName is required',
        )
      );
    }
    if (!(isset($this->params['site_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['site_token'], $GLOBALS['HAXCMS']->getActiveUserName() . ':' . $siteName))) {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid request token',
        )
      );
    }
    $searchTerm = isset($this->params['search']) ? trim((string) $this->params['search']) : '';
    if ($searchTerm == '') {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'Search query is required',
        )
      );
    }
    if (strlen($searchTerm) > 256) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'Search query is too long (max 256 characters)',
        )
      );
    }
    $selectorMode = $this->parseSiteSearchBoolean(isset($this->params['searchSelector']) ? $this->params['searchSelector'] : false);
    if (!$selectorMode && isset($this->params['searchMode']) && strtolower((string) $this->params['searchMode']) == 'selector') {
      $selectorMode = true;
    }
    $caseSensitive = $this->parseSiteSearchBoolean(isset($this->params['searchCaseSensitive']) ? $this->params['searchCaseSensitive'] : false);
    $searchLimit = $this->parseSiteSearchLimit(isset($this->params['searchLimit']) ? $this->params['searchLimit'] : null, 25);
    $searchFields = $selectorMode
      ? array('content')
      : $this->normalizeSiteSearchFields(isset($this->params['searchField']) ? $this->params['searchField'] : null);
    $mode = $selectorMode ? 'selector' : 'text';
    $selectorData = null;
    if ($selectorMode) {
      $selectorData = $this->parseSimpleSiteSearchSelector($searchTerm);
      if (!$selectorData['valid']) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => $selectorData['reason'],
          )
        );
      }
    }
    $response = array(
      'status' => 200,
      'data' => array(
        'query' => $searchTerm,
        'fields' => $searchFields,
        'mode' => $mode,
        'caseSensitive' => $caseSensitive,
        'limit' => $searchLimit,
        'total' => 0,
        'matches' => array(),
      )
    );
    $site = $GLOBALS['HAXCMS']->loadSite($siteName);
    if (!isset($site) || !isset($site->manifest) || !isset($site->manifest->items)) {
      return $response;
    }
    $items = $site->manifest->orderTree($site->manifest->items);
    $contentCache = array();
    $matches = array();
    foreach ($items as $item) {
      if ($searchLimit > 0 && count($matches) >= $searchLimit) {
        break;
      }
      $fieldMatches = array();
      foreach ($searchFields as $field) {
        $content = '';
        if ($field == 'content') {
          if (isset($item->id) && isset($contentCache[$item->id])) {
            $content = $contentCache[$item->id];
          }
          else if (isset($item->id)) {
            $page = $site->loadNode($item->id);
            if ($page) {
              $content = $site->getPageContent($page);
            }
            if (!is_string($content)) {
              $content = '';
            }
            $contentCache[$item->id] = $content;
          }
        }
        $fieldValue = $this->getSiteSearchFieldValue($field, $item, $content);
        if ($selectorMode) {
          $selectorMatch = $this->siteSearchSelectorMatch($fieldValue, $selectorData);
          if (!is_null($selectorMatch)) {
            $fieldMatches[] = array(
              'field' => 'content',
              'type' => 'selector',
              'selector' => $selectorData['selector'],
              'count' => $selectorMatch['count'],
              'snippets' => $selectorMatch['snippets'],
            );
          }
        }
        else {
          $textMatch = $this->siteSearchTextMatch($fieldValue, $searchTerm, $caseSensitive);
          if (!is_null($textMatch)) {
            $fieldMatches[] = array(
              'field' => $field,
              'type' => 'text',
              'index' => $textMatch['index'],
              'length' => $textMatch['length'],
              'snippet' => $textMatch['snippet'],
            );
          }
        }
      }
      if (count($fieldMatches) > 0) {
        $matches[] = array(
          'id' => isset($item->id) ? $item->id : null,
          'title' => isset($item->title) ? $item->title : '',
          'slug' => isset($item->slug) ? $item->slug : '',
          'location' => isset($item->location) ? $item->location : '',
          'parent' => isset($item->parent) ? $item->parent : null,
          'description' => isset($item->description) ? $item->description : '',
          'tags' => $this->siteSearchTagsValue($item),
          'matches' => $fieldMatches,
        );
      }
    }
    $response['data']['matches'] = $matches;
    $response['data']['total'] = count($matches);
    return $response;
  }
  private function getSiteSearchSiteName() {
    if (isset($this->params['site']) && isset($this->params['site']['name'])) {
      return (string) $this->params['site']['name'];
    }
    if (isset($this->params['siteName'])) {
      return (string) $this->params['siteName'];
    }
    return '';
  }
  private function parseSiteSearchBoolean($value) {
    if (is_bool($value)) {
      return $value;
    }
    if (is_numeric($value)) {
      return intval($value) === 1;
    }
    if (is_string($value)) {
      $normalized = strtolower(trim($value));
      return in_array($normalized, array('1', 'true', 'yes', 'on'));
    }
    return false;
  }
  private function parseSiteSearchLimit($value, $fallback = 25) {
    if (is_null($value) || $value === '') {
      return $fallback;
    }
    if (!is_numeric($value)) {
      return $fallback;
    }
    $limit = intval($value);
    if ($limit < 0) {
      return 0;
    }
    if ($limit > 200) {
      return 200;
    }
    return $limit;
  }
  private function normalizeSiteSearchFields($searchFieldValue = null) {
    $defaultFields = array('title', 'slug', 'description', 'tags', 'content');
    $allowedFields = array('id', 'title', 'slug', 'description', 'tags', 'content', 'location', 'parent');
    if (is_null($searchFieldValue) || $searchFieldValue === '') {
      return $defaultFields;
    }
    $values = is_array($searchFieldValue) ? $searchFieldValue : explode(',', (string) $searchFieldValue);
    $normalized = array();
    foreach ($values as $value) {
      $field = strtolower(trim((string) $value));
      if ($field == '') {
        continue;
      }
      if ($field == 'all') {
        return $defaultFields;
      }
      if (in_array($field, $allowedFields) && !in_array($field, $normalized)) {
        $normalized[] = $field;
      }
    }
    if (count($normalized) === 0) {
      return $defaultFields;
    }
    return $normalized;
  }
  private function parseSimpleSiteSearchSelector($selectorValue) {
    $selector = trim((string) $selectorValue);
    if ($selector == '') {
      return array('valid' => false, 'reason' => 'Selector query is required');
    }
    if (
      strpos($selector, ',') !== false ||
      strpos($selector, ' ') !== false ||
      strpos($selector, '>') !== false ||
      strpos($selector, '+') !== false ||
      strpos($selector, '~') !== false ||
      strpos($selector, ':') !== false
    ) {
      return array('valid' => false, 'reason' => 'Only simple selectors are supported (tag, tag[attr], tag[attr="value"], [attr])');
    }
    $pattern = '/^([a-zA-Z][a-zA-Z0-9-]*)?(?:\[\s*([a-zA-Z_:][a-zA-Z0-9:._-]*)\s*(?:=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\]\s"\']+)))?\s*\])?$/';
    if (!preg_match($pattern, $selector, $matches)) {
      return array('valid' => false, 'reason' => 'Invalid selector syntax');
    }
    $tag = isset($matches[1]) && $matches[1] != '' ? strtolower($matches[1]) : null;
    $attr = isset($matches[2]) && $matches[2] != '' ? strtolower($matches[2]) : null;
    $attrValue = null;
    if (array_key_exists(3, $matches)) {
      $attrValue = $matches[3];
    }
    else if (array_key_exists(4, $matches)) {
      $attrValue = $matches[4];
    }
    else if (array_key_exists(5, $matches)) {
      $attrValue = $matches[5];
    }
    if (is_null($tag) && is_null($attr)) {
      return array('valid' => false, 'reason' => 'Selector must include at least a tag or attribute');
    }
    $selectorOut = '';
    if (!is_null($tag)) {
      $selectorOut .= $tag;
    }
    if (!is_null($attr)) {
      $selectorOut .= '[' . $attr;
      if (!is_null($attrValue)) {
        $selectorOut .= '="' . $attrValue . '"]';
      }
      else {
        $selectorOut .= ']';
      }
    }
    $xpath = !is_null($tag) ? '//' . $tag : '//*';
    if (!is_null($attr)) {
      if (!is_null($attrValue)) {
        $xpath .= '[@' . $attr . '=' . $this->siteSearchXPathLiteral($attrValue) . ']';
      }
      else {
        $xpath .= '[@' . $attr . ']';
      }
    }
    return array(
      'valid' => true,
      'selector' => $selectorOut,
      'xpath' => $xpath,
    );
  }
  private function siteSearchXPathLiteral($value) {
    if (strpos($value, "'") === false) {
      return "'" . $value . "'";
    }
    if (strpos($value, '"') === false) {
      return '"' . $value . '"';
    }
    $parts = explode("'", $value);
    $expression = 'concat(';
    for ($i = 0; $i < count($parts); $i++) {
      if ($i > 0) {
        $expression .= ", \"'\", ";
      }
      $expression .= "'" . $parts[$i] . "'";
    }
    $expression .= ')';
    return $expression;
  }
  private function siteSearchTagsValue($item) {
    if (!isset($item->metadata) || !isset($item->metadata->tags) || is_null($item->metadata->tags)) {
      return '';
    }
    if (is_array($item->metadata->tags)) {
      return implode(', ', $item->metadata->tags);
    }
    if (is_string($item->metadata->tags)) {
      return $item->metadata->tags;
    }
    return json_encode($item->metadata->tags);
  }
  private function getSiteSearchFieldValue($field, $item, $content = '') {
    switch ($field) {
      case 'id':
      case 'title':
      case 'slug':
      case 'description':
      case 'location':
      case 'parent':
        return isset($item->{$field}) ? (string) $item->{$field} : '';
      case 'tags':
        return $this->siteSearchTagsValue($item);
      case 'content':
        return is_string($content) ? $content : '';
    }
    return '';
  }
  private function siteSearchTextMatch($value, $searchTerm, $caseSensitive = false) {
    if (!is_string($value) || $value === '') {
      return null;
    }
    $needle = (string) $searchTerm;
    $haystack = $value;
    if (!$caseSensitive) {
      $needle = strtolower($needle);
      $haystack = strtolower($haystack);
    }
    $index = strpos($haystack, $needle);
    if ($index === false) {
      return null;
    }
    $length = strlen((string) $searchTerm);
    $start = max($index - 60, 0);
    $end = min($index + $length + 60, strlen($value));
    $snippet = preg_replace('/\s+/', ' ', trim(substr($value, $start, $end - $start)));
    return array(
      'index' => $index,
      'length' => $length,
      'snippet' => $snippet,
    );
  }
  private function siteSearchSelectorMatch($content, $selectorData) {
    if (!is_string($content) || trim($content) == '') {
      return null;
    }
    if (!isset($selectorData['xpath']) || $selectorData['xpath'] == '') {
      return null;
    }
    $previousState = libxml_use_internal_errors(true);
    $document = new DOMDocument();
    $loaded = $document->loadHTML(
      '<!doctype html><html><body><div id="hax-search-wrapper">' . $content . '</div></body></html>',
      LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
    );
    if (!$loaded) {
      libxml_clear_errors();
      libxml_use_internal_errors($previousState);
      return null;
    }
    $xpath = new DOMXPath($document);
    $nodes = $xpath->query($selectorData['xpath']);
    libxml_clear_errors();
    libxml_use_internal_errors($previousState);
    if (!$nodes || $nodes->length === 0) {
      return null;
    }
    $snippets = array();
    for ($i = 0; $i < $nodes->length && $i < 3; $i++) {
      $snippet = $document->saveHTML($nodes->item($i));
      $snippet = preg_replace('/\s+/', ' ', trim((string) $snippet));
      if (strlen($snippet) > 180) {
        $snippet = substr($snippet, 0, 177) . '...';
      }
      $snippets[] = $snippet;
    }
    return array(
      'count' => $nodes->length,
      'snippets' => $snippets,
    );
  }
  /**
   * Return default skeleton directories in precedence order.
   */
  private function getDefaultSkeletonDirectories() {
    return array(
      'user' => $GLOBALS['HAXCMS']->configDirectory . '/user/skeletons',
      'config' => $GLOBALS['HAXCMS']->configDirectory . '/skeletons',
      'core' => $GLOBALS['HAXCMS']->coreConfigPath . 'skeletons',
    );
  }
  /**
   * Discover skeleton directories, allowing integrations (like HAXiam) to alter search locations.
   * Precedence defaults to user -> config -> core and can be extended via hook.
   */
  private function getSkeletonDirectories() {
    $defaultDirs = $this->getDefaultSkeletonDirectories();
    $dirs = array();
    foreach ($defaultDirs as $dir) {
      if (is_dir($dir)) {
        $dirs[] = rtrim($dir, '/');
      }
    }
    $context = new stdClass();
    $context->directories = $dirs;
    $context->defaultDirectories = $defaultDirs;
    $GLOBALS['HAXCMS']->dispatchEvent('haxcms-skeleton-dirs', $context);
    if (!is_object($context) || !isset($context->directories) || !is_array($context->directories)) {
      return $dirs;
    }
    $finalDirs = array();
    foreach ($context->directories as $dir) {
      if (!is_string($dir)) {
        continue;
      }
      $normalizedDir = rtrim(trim($dir), '/');
      if ($normalizedDir === '' || !is_dir($normalizedDir)) {
        continue;
      }
      if (!in_array($normalizedDir, $finalDirs, true)) {
        $finalDirs[] = $normalizedDir;
      }
    }
    return $finalDirs;
  }
  /**
   * Normalize skeleton machine name for matching (filename or meta name).
   */
  private function normalizeSkeletonMachineName($value) {
    if (!is_string($value)) {
      return '';
    }
    return strtolower(trim(preg_replace('/\.json$/i', '', $value)));
  }
  /**
   * Resolve skeleton build payload by machine name from user/config/core skeleton folders.
   */
  private function resolveSkeletonBuildByMachineName($machineName) {
    $normalizedTarget = $this->normalizeSkeletonMachineName($machineName);
    if ($normalizedTarget === '') {
      return null;
    }
    $dirs = $this->getSkeletonDirectories();
    foreach ($dirs as $dir) {
      if (!($handle = opendir($dir))) { continue; }
      while (false !== ($file = readdir($handle))) {
        if ($file === '.' || $file === '..') { continue; }
        $filePath = $dir . '/' . $file;
        if (!is_file($filePath) || strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) !== 'json') {
          continue;
        }
        $json = @file_get_contents($filePath);
        $skeleton = json_decode($json, true);
        if (!is_array($skeleton)) {
          continue;
        }
        $normalizedFileName = $this->normalizeSkeletonMachineName(pathinfo($file, PATHINFO_FILENAME));
        $meta = isset($skeleton['meta']) && is_array($skeleton['meta']) ? $skeleton['meta'] : array();
        $normalizedMetaMachineName = $this->normalizeSkeletonMachineName(isset($meta['machineName']) ? $meta['machineName'] : '');
        $normalizedMetaName = $this->normalizeSkeletonMachineName(isset($meta['name']) ? $meta['name'] : '');
        if (
          $normalizedTarget === $normalizedFileName ||
          $normalizedTarget === $normalizedMetaMachineName ||
          $normalizedTarget === $normalizedMetaName
        ) {
          closedir($handle);
          return array(
            'filePath' => $filePath,
            'skeleton' => $skeleton,
            'build' => isset($skeleton['build']) && is_array($skeleton['build']) ? $skeleton['build'] : null,
          );
        }
      }
      closedir($handle);
    }
    return null;
  }
  /**
   * Resolve a trusted skeleton payload by matching machineName against theme keys/elements.
   * This allows from-skeleton requests to pass either skeleton machine name or theme machine name.
   */
  private function resolveSkeletonBuildByThemeMachineName($machineName) {
    $normalizedTarget = $this->normalizeSkeletonMachineName($machineName);
    if ($normalizedTarget === '') {
      return null;
    }
    $themes = $GLOBALS['HAXCMS']->getThemes();
    if (is_object($themes)) {
      $themes = (array)$themes;
    }
    if (!is_array($themes)) {
      return null;
    }
    $matchedThemeKey = null;
    foreach ($themes as $themeKey => $themeObj) {
      $normalizedKey = $this->normalizeSkeletonMachineName($themeKey);
      $themeElement = '';
      if (is_array($themeObj) && isset($themeObj['element']) && is_string($themeObj['element'])) {
        $themeElement = $themeObj['element'];
      }
      else if (is_object($themeObj) && isset($themeObj->element) && is_string($themeObj->element)) {
        $themeElement = $themeObj->element;
      }
      $normalizedElement = $this->normalizeSkeletonMachineName($themeElement);
      if (
        $normalizedTarget === $normalizedKey ||
        ($normalizedElement !== '' && $normalizedTarget === $normalizedElement)
      ) {
        $matchedThemeKey = $themeKey;
        break;
      }
    }
    if (is_null($matchedThemeKey)) {
      return null;
    }
    $fallbackSkeleton = $this->resolveSkeletonBuildByMachineName('default-starter');
    $trustedSkeleton = null;
    $trustedSkeletonFilePath = null;
    if (
      is_array($fallbackSkeleton) &&
      isset($fallbackSkeleton['skeleton']) &&
      is_array($fallbackSkeleton['skeleton'])
    ) {
      $trustedSkeleton = $fallbackSkeleton['skeleton'];
      $trustedSkeletonFilePath = isset($fallbackSkeleton['filePath'])
        ? $fallbackSkeleton['filePath']
        : null;
    }
    if (!is_array($trustedSkeleton)) {
      $trustedSkeleton = array(
        'meta' => array(),
        'site' => array(),
        'build' => array(
          'type' => 'skeleton',
          'structure' => 'from-skeleton',
          'items' => array(),
          'files' => array(),
        ),
      );
      $trustedSkeletonFilePath = 'generated:theme-fallback';
    }
    if (!isset($trustedSkeleton['meta']) || !is_array($trustedSkeleton['meta'])) {
      $trustedSkeleton['meta'] = array();
    }
    $trustedSkeleton['meta']['machineName'] = $matchedThemeKey;
    $trustedSkeleton['meta']['name'] = $matchedThemeKey;
    if (!isset($trustedSkeleton['site']) || !is_array($trustedSkeleton['site'])) {
      $trustedSkeleton['site'] = array();
    }
    $trustedSkeleton['site']['theme'] = $matchedThemeKey;
    if (
      isset($trustedSkeleton['_skeleton']) &&
      is_array($trustedSkeleton['_skeleton']) &&
      isset($trustedSkeleton['_skeleton']['fullThemeConfig'])
    ) {
      unset($trustedSkeleton['_skeleton']['fullThemeConfig']);
    }
    return array(
      'filePath' => $trustedSkeletonFilePath,
      'skeleton' => $trustedSkeleton,
      'build' => isset($trustedSkeleton['build']) && is_array($trustedSkeleton['build'])
        ? $trustedSkeleton['build']
        : null,
    );
  }
  /**
   * Build a compact signature from build items for fallback skeleton matching.
   */
  private function buildItemsSignature($items) {
    if (!is_array($items) || count($items) === 0) {
      return null;
    }
    $ids = array();
    foreach ($items as $item) {
      if (is_array($item) && isset($item['id']) && is_string($item['id'])) {
        $ids[] = $item['id'];
      }
      if (count($ids) >= 5) {
        break;
      }
    }
    if (count($ids) === 0) {
      return null;
    }
    return count($items) . ':' . implode('|', $ids);
  }
  /**
   * Resolve skeleton by comparing build item signatures when machineName is unavailable.
   */
  private function resolveSkeletonByBuildItems($items) {
    $targetSignature = $this->buildItemsSignature($items);
    if (is_null($targetSignature)) {
      return null;
    }
    $dirs = $this->getSkeletonDirectories();
    foreach ($dirs as $dir) {
      if (!($handle = opendir($dir))) { continue; }
      while (false !== ($file = readdir($handle))) {
        if ($file === '.' || $file === '..') { continue; }
        $filePath = $dir . '/' . $file;
        if (!is_file($filePath) || strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) !== 'json') {
          continue;
        }
        $json = @file_get_contents($filePath);
        $skeleton = json_decode($json, true);
        if (!is_array($skeleton) || !isset($skeleton['build']) || !is_array($skeleton['build'])) {
          continue;
        }
        $skeletonBuildItems = isset($skeleton['build']['items']) && is_array($skeleton['build']['items'])
          ? $skeleton['build']['items']
          : array();
        $skeletonSignature = $this->buildItemsSignature($skeletonBuildItems);
        if (!is_null($skeletonSignature) && $skeletonSignature === $targetSignature) {
          closedir($handle);
          return array(
            'filePath' => $filePath,
            'skeleton' => $skeleton,
            'build' => $skeleton['build'],
          );
        }
      }
      closedir($handle);
    }
    return null;
  }
  /**
   * Convert arrays into stdClass objects recursively for metadata assignment.
   */
  private function toObject($value) {
    if (is_array($value)) {
      return json_decode(json_encode($value));
    }
    return $value;
  }
  /**
   * Get trusted settings from skeleton payload with fallbacks.
   */
  private function getTrustedSkeletonSettings($skeleton) {
    if (!is_array($skeleton)) {
      return null;
    }
    if (isset($skeleton['site']) && is_array($skeleton['site']) && isset($skeleton['site']['settings']) && is_array($skeleton['site']['settings'])) {
      return $skeleton['site']['settings'];
    }
    if (
      isset($skeleton['_skeleton']) &&
      is_array($skeleton['_skeleton']) &&
      isset($skeleton['_skeleton']['originalSettings']) &&
      is_array($skeleton['_skeleton']['originalSettings'])
    ) {
      return $skeleton['_skeleton']['originalSettings'];
    }
    if (
      isset($skeleton['_skeleton']) &&
      is_array($skeleton['_skeleton']) &&
      isset($skeleton['_skeleton']['originalMetadata']) &&
      is_array($skeleton['_skeleton']['originalMetadata']) &&
      isset($skeleton['_skeleton']['originalMetadata']['site']) &&
      is_array($skeleton['_skeleton']['originalMetadata']['site']) &&
      isset($skeleton['_skeleton']['originalMetadata']['site']['settings']) &&
      is_array($skeleton['_skeleton']['originalMetadata']['site']['settings'])
    ) {
      return $skeleton['_skeleton']['originalMetadata']['site']['settings'];
    }
    return null;
  }
  /**
   * Get trusted platform settings from skeleton payload with fallbacks.
   */
  private function getTrustedSkeletonPlatform($skeleton) {
    if (!is_array($skeleton)) {
      return null;
    }
    if (isset($skeleton['site']) && is_array($skeleton['site']) && isset($skeleton['site']['platform']) && is_array($skeleton['site']['platform'])) {
      return $skeleton['site']['platform'];
    }
    if (
      isset($skeleton['_skeleton']) &&
      is_array($skeleton['_skeleton']) &&
      isset($skeleton['_skeleton']['originalMetadata']) &&
      is_array($skeleton['_skeleton']['originalMetadata']) &&
      isset($skeleton['_skeleton']['originalMetadata']['platform']) &&
      is_array($skeleton['_skeleton']['originalMetadata']['platform'])
    ) {
      return $skeleton['_skeleton']['originalMetadata']['platform'];
    }
    return null;
  }
  /**
   * Build theme metadata from trusted skeleton payload.
   */
  private function getTrustedSkeletonTheme($skeleton) {
    if (!is_array($skeleton)) {
      return null;
    }
    $theme = null;
    if (
      isset($skeleton['_skeleton']) &&
      is_array($skeleton['_skeleton']) &&
      isset($skeleton['_skeleton']['fullThemeConfig']) &&
      is_array($skeleton['_skeleton']['fullThemeConfig'])
    ) {
      $fullThemeConfig = $skeleton['_skeleton']['fullThemeConfig'];
      $themeBase = array();
      if (isset($fullThemeConfig['settings']) && is_array($fullThemeConfig['settings'])) {
        $themeBase = $fullThemeConfig['settings'];
      }
      if (isset($fullThemeConfig['element']) && is_string($fullThemeConfig['element']) && $fullThemeConfig['element'] !== '') {
        $themeBase['element'] = $fullThemeConfig['element'];
      }
      if (isset($fullThemeConfig['variables']) && is_array($fullThemeConfig['variables'])) {
        $themeBase['variables'] = $fullThemeConfig['variables'];
      }
      if (count($themeBase) > 0) {
        $theme = $themeBase;
      }
    }
    $skeletonThemeElement = '';
    if (
      isset($skeleton['site']) &&
      is_array($skeleton['site']) &&
      isset($skeleton['site']['theme']) &&
      is_string($skeleton['site']['theme']) &&
      $skeleton['site']['theme'] !== ''
    ) {
      $skeletonThemeElement = $skeleton['site']['theme'];
    }
    if ((!is_array($theme) || count($theme) === 0) && $skeletonThemeElement !== '') {
      $themes = $GLOBALS['HAXCMS']->getThemes();
      if (is_object($themes)) {
        $themes = json_decode(json_encode($themes), true);
      }
      if (!is_array($themes)) {
        $themes = array();
      }
      if (isset($themes[$skeletonThemeElement])) {
        $theme = json_decode(json_encode($themes[$skeletonThemeElement]), true);
      }
    }
    if (
      (!is_array($theme) || count($theme) === 0) &&
      isset($skeleton['theme']) &&
      (is_array($skeleton['theme']) || is_object($skeleton['theme']))
    ) {
      $theme = is_object($skeleton['theme'])
        ? json_decode(json_encode($skeleton['theme']), true)
        : $skeleton['theme'];
    }
    if (!is_array($theme) || count($theme) === 0) {
      return null;
    }
    if (!isset($theme['element']) && $skeletonThemeElement !== '') {
      $theme['element'] = $skeletonThemeElement;
    }
    if (
      !isset($theme['element']) &&
      isset($theme['path']) &&
      is_string($theme['path']) &&
      $theme['path'] !== ''
    ) {
      $pathParts = explode('/', $theme['path']);
      $inferred = array_pop($pathParts);
      $inferred = preg_replace('/\\.js$/', '', $inferred);
      if ($inferred !== '') {
        $theme['element'] = $inferred;
      }
    }
    if (!isset($theme['variables']) || !is_array($theme['variables'])) {
      $theme['variables'] = array();
    }
    return $theme;
  }
  /**
   * @OA\Post(
   *    path="/login",
   *    tags={"cms","user"},
   *    description="Attempt a user login",
   *    @OA\Parameter(
   *     description="User name",
   *     example="admin",
   *     name="username",
   *     in="query",
   *     required=true,
   *     @OA\Schema(type="string")
   *   ),
   *   @OA\Parameter(
   *     description="Password",
   *     example="admin",
   *     name="password",
   *     in="query",
   *     required=true,
   *     @OA\Schema(type="string")
   *   ),
   *    @OA\Response(
   *        response="200",
   *        description="JWT token as response"
   *   ),
   *    @OA\Response(
   *        response="403",
   *        description="Invalid token / Login is required"
   *   )
   * )
   */
  public function login() {
    // if we don't have a user and the don't answer, bail
    if (isset($this->params['username']) && isset($this->params['password'])) {
      // _ paranoia
      $u = $this->params['username'];
      // driving me insane
      $p = $this->params['password'];
      // _ paranoia ripping up my brain
      // test if this is a valid user login
      if (!$GLOBALS['HAXCMS']->testLogin($u, $p, true)) {
        return array(
          '__failed' => array(
            'status' => 403,
            'message' => 'Access denied',
          )
        );
      } else {
          // set a refresh_token COOKIE that will ship w/ all calls automatically
          setcookie('haxcms_refresh_token', $GLOBALS['HAXCMS']->getRefreshToken($u), $_expires = 0, $_path = '/', $_domain = '', $_secure = true, $_httponly = true);
          return array(
            "status" => 200,
            "jwt" => $GLOBALS['HAXCMS']->getJWT($u),
          );
      }
    }
    //old way
    // if we don't have a user and the don't answer, bail
    else if (isset($this->params['u']) && isset($this->params['p'])) {
      // _ paranoia
      $u = $this->params['u'];
      // driving me insane
      $p = $this->params['p'];
      // _ paranoia ripping up my brain
      // test if this is a valid user login
      if (!$GLOBALS['HAXCMS']->testLogin($u, $p, true)) {
        return array(
          '__failed' => array(
            'status' => 403,
            'message' => 'Access denied',
          )
        );
      } else {
          // set a refresh_token COOKIE that will ship w/ all calls automatically
          setcookie('haxcms_refresh_token', $GLOBALS['HAXCMS']->getRefreshToken($u), $_expires = 0, $_path = '/', $_domain = '', $_secure = true, $_httponly = true);
          return $GLOBALS['HAXCMS']->getJWT($u);
      }
    }
    // login end point requested yet a jwt already exists
    // this is something of a revalidate case
    else if (isset($this->params['jwt'])) {
      return array(
        "status" => 200,
        "jwt" => $GLOBALS['HAXCMS']->validateJWT(),
      );
    }
    else {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'Login is required',
        )
      );
    } 
  }
  /**
   * @OA\Get(
   *    path="/logout",
   *    tags={"cms","user"},
   *    @OA\Response(
   *        response="200",
   *        description="User logout, front end will kill token"
   *   )
   * )
   */
  public function logout() {
    setcookie('haxcms_refresh_token', '', 1, '/', '', true, true);
    return array(
      "status" => 200,
      "data" => 'loggedout',
    );
  }
  /**
   * @OA\Post(
   *    path="/refreshAccessToken",
   *    tags={"cms","user"},
   *    @OA\Response(
   *        response="200",
   *        description="User access token for refreshing JWT when it goes stale"
   *   )
   * )
   */
  public function refreshAccessToken() {
    // check that we have a valid refresh token
    $validRefresh = $GLOBALS['HAXCMS']->validateRefreshToken(FALSE);
    // if we have a valid refresh token then issue a new access token
    if ($validRefresh) {
      return array(
        "status" => 200,
        "jwt" => $GLOBALS['HAXCMS']->getJWT($validRefresh->user),
      );
    }
    else {
      // this failed so unset the cookie
      setcookie('haxcms_refresh_token', '', 1, '/', '', true, true);
      return array(
        '__failed' => array(
          'status' => 401,
          'message' => 'haxcms_refresh_token:invalid',
        )
      );
    }
  }
  /**
   * @OA\Post(
   *    path="/saveFile",
   *    tags={"hax","authenticated","file"},
   *    @OA\Parameter(
   *         name="site_token",
   *         description="Site-specific validation token",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Parameter(
   *         name="file-upload",
   *         description="File to upload",
   *         in="header",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\RequestBody(
   *        @OA\MediaType(
   *             mediaType="application/json",
   *             @OA\Schema(
   *                 @OA\Property(
   *                     property="site",
   *                     type="object"
   *                 ),
   *                 @OA\Property(
   *                     property="node",
   *                     type="object"
   *                 ),
   *                 required={"site"},
   *                 example={
   *                    "site": {
   *                      "name": "mynewsite"
   *                    },
   *                    "node": {
   *                      "id": ""
   *                    }
   *                 }
   *             )
   *         )
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="User is uploading a file to present in a site"
   *   )
   * )
   */
  public function saveFile() {
    // resolve front-end parsing issue with saveFiles based on how that was structured
    // this is a bit of a hack but site token will have the ?siteName in it as opposed to stand alone params
    if (isset($this->params['site_token']) && !isset($this->params['site'])) {
      $tmp = explode('?siteName=', $this->params['site_token']);
      if (count($tmp) == 2) {
        $this->params['site_token'] = $tmp[0];
        $this->params['site']['name'] = $tmp[1];
      }
    }
    $siteName = '';
    if (isset($this->params['site']) && isset($this->params['site']['name'])) {
      $siteName = (string) $this->params['site']['name'];
    }
    else if (isset($this->params['siteName'])) {
      $siteName = (string) $this->params['siteName'];
      $this->params['site']['name'] = $siteName;
    }
    else if (isset($this->params['site[name]'])) {
      $siteName = (string) $this->params['site[name]'];
      $this->params['site']['name'] = $siteName;
    }
    $nodeId = '';
    if (isset($this->params['node']) && isset($this->params['node']['id'])) {
      $nodeId = (string) $this->params['node']['id'];
    }
    else if (isset($this->params['nodeId'])) {
      $nodeId = (string) $this->params['nodeId'];
      $this->params['node']['id'] = $nodeId;
    }
    else if (isset($this->params['node[id]'])) {
      $nodeId = (string) $this->params['node[id]'];
      $this->params['node']['id'] = $nodeId;
    }
    if (
      isset($this->params['site_token']) &&
      $siteName != '' &&
      $nodeId != '' &&
      $GLOBALS['HAXCMS']->validateRequestToken(
        $this->params['site_token'],
        $GLOBALS['HAXCMS']->getActiveUserName() . ':' . $siteName
      ) &&
      isset($_FILES['file-upload'])
    ) {
      $site = $GLOBALS['HAXCMS']->loadSite($siteName);
      if (!$this->platformAllows($site, 'uploadMedia')) {
        return array(
          '__failed' => array(
            'status' => 403,
            'message' => 'Uploading media is disabled for this site',
          )
        );
      }
      // update the page's content, using manifest to find it
      // this ensures that writing is always to what the file system
      // determines to be the correct page
      $page = $site->loadNode($nodeId);
      $upload = $_FILES['file-upload'];
      $file = new HAXCMSFile();
      $fileResult = $file->save($upload, $site, $page);
      if ($fileResult['status'] == 500) {
        return array(
          '__failed' => array(
            'status' => 500,
            'message' => $fileResult['data'],
          )
        );
      }
      $site->gitCommit('File added: ' . $upload['name']);
      return $fileResult;
    }
    else {
      return array(
        '__failed' => array(
          'status' => 500,
          'message' => 'Invalid file request',
        )
      );
    }
  }

  /**
   * Handle style guide save operation through saveNode endpoint
   * @param object $site The HAXcms site object
   * @return array Response array with status and data
   */
  private function handleStyleGuideSave($site) {
    $siteDirectory = $site->directory . '/' . $site->manifest->metadata->site->name;
    $styleGuideFile = $siteDirectory . '/theme/style-guide.html';
    
    // Extract content from node body (saveNode endpoint)
    $content = null;
    if (isset($this->params['node']['body'])) {
      $content = $this->params['node']['body'];
    }
    
    // validate that we have content to save
    if (!$content) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'Content parameter is required',
        )
      );
    }
    
    // validate content is a string and has some actual content
    if (!is_string($content)) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'Content must be a string',
        )
      );
    }
    
    // basic validation - ensure we have some HTML-like content
    $cleanContent = trim($content);
    if (empty($cleanContent)) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'Content cannot be empty',
        )
      );
    }
    
    // validate that content appears to be HTML by checking for basic HTML patterns
    // this follows similar pattern to how saveNode validates content structure
    if (!preg_match('/<[^>]+>/', $cleanContent)) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'Content must be valid HTML',
        )
      );
    }
    
    // check if the theme directory exists, if not create it
    $themeDirectory = $siteDirectory . '/theme';
    if (!file_exists($themeDirectory)) {
      if (!mkdir($themeDirectory, 0755, true)) {
        return array(
          '__failed' => array(
            'status' => 500,
            'message' => 'Failed to create theme directory',
          )
        );
      }
    }
    
    // ensure the site's style guide setting allows writing to the default location
    // only allow writing to the default location (theme/style-guide.html)
    // if user has changed the styleGuide setting to an external URL, block writes
    if (isset($site->manifest->metadata->theme->styleGuide) && 
        $site->manifest->metadata->theme->styleGuide !== null && 
        $site->manifest->metadata->theme->styleGuide !== '') {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'Style guide is configured to use external source. Cannot edit through HAXcms.',
        )
      );
    }
    
    // write the content to the style guide file
    $bytes = file_put_contents($styleGuideFile, $cleanContent);
    
    if ($bytes === false) {
      return array(
        '__failed' => array(
          'status' => 500,
          'message' => 'Failed to write style guide file',
        )
      );
    }
    
    // commit to git
    $site->gitCommit('Style guide updated');
    
    return array(
      'status' => 200,
      'message' => 'Style guide saved successfully',
      'data' => [
        'bytes' => $bytes,
        'file' => 'theme/style-guide.html'
      ]
    );
  }
  /**
   * Discover available site skeletons from core and user config directories.
   * Returns metadata list compatible with app-hax v2 dashboard.
   * Requires a valid user_token and JWT.
   *
   * @OA\Get(
   *    path="/skeletonsList",
   *    tags={"cms"},
   *    @OA\Response(
   *        response="200",
   *        description="List available site skeletons"
   *   )
   * )
   */
  public function skeletonsList() {
    // Validate user_token like listSites
    if (!isset($this->params['user_token']) || !$GLOBALS['HAXCMS']->validateRequestToken($this->params['user_token'], $GLOBALS['HAXCMS']->getActiveUserName())) {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid request token',
        )
      );
    }

    $items = array();
    $seen = array();
    // directories to scan for JSON skeleton definitions
    $dirs = $this->getSkeletonDirectories();

    foreach ($dirs as $dir) {
      if ($handle = opendir($dir)) {
        while (false !== ($file = readdir($handle))) {
          if ($file === '.' || $file === '..') { continue; }
          $path = $dir . '/' . $file;
          if (is_file($path) && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'json') {
            $json = @file_get_contents($path);
            $skeleton = json_decode($json);
            if (!is_object($skeleton)) { continue; }
            // Accept flexible export structures; derive meta fields
            $meta = isset($skeleton->meta) ? $skeleton->meta : new stdClass();
            $title = isset($meta->useCaseTitle) && $meta->useCaseTitle ? $meta->useCaseTitle : (isset($meta->name) ? $meta->name : basename($file, '.json'));
            $description = isset($meta->useCaseDescription) && $meta->useCaseDescription ? $meta->useCaseDescription : (isset($meta->description) ? $meta->description : '');
            $image = isset($meta->useCaseImage) ? $meta->useCaseImage : '';
            // priority: negative floats to the top, positive sinks
            $priority = 0;
            if (isset($meta->priority) && is_numeric($meta->priority)) {
              $priority = 0 + $meta->priority;
            }
            // categories/tags from meta or build type if present
            $category = array();
            if (isset($meta->category) && is_array($meta->category)) { $category = $meta->category; }
            else if (isset($meta->tags) && is_array($meta->tags)) { $category = $meta->tags; }
            // attributes/icons optional in meta
            $attributes = array();
            if (isset($meta->attributes) && is_array($meta->attributes)) { $attributes = $meta->attributes; }
            // demo/source url optional
            $demo = isset($meta->sourceUrl) ? $meta->sourceUrl : '#';
            // Build API URL to fetch skeleton content with user_token
            $skeletonName = basename($file, '.json');
            // de-dupe by machineName using precedence order above
            if (in_array($skeletonName, $seen, TRUE)) {
              continue;
            }
            // "default-starter" is a shared internal fallback skeleton that
            // many generic themes reference as their skeleton definition.
            // It should not appear in the public list of selectable skeletons.
            if ($skeletonName === 'default-starter') {
              continue;
            }
            // Ensure base API path ends with a trailing slash so route
            // concatenation does not produce `/system/apigetSkeleton`.
            $baseAPIPath = $GLOBALS['HAXCMS']->basePath . $GLOBALS['HAXCMS']->systemRequestBase . '/';
            $userToken = isset($this->params['user_token']) ? $this->params['user_token'] : '';
            $skeletonUrl = $baseAPIPath . 'getSkeleton?name=' . urlencode($skeletonName) . '&user_token=' . urlencode($userToken);
            $items[] = array(
              'title' => $title,
              'description' => $description,
              'image' => $image,
              'priority' => $priority,
              'category' => $category,
              'attributes' => $attributes,
              // repeat machine name explicitly so UIs don't have to infer it from skeleton-url
              'machineName' => $skeletonName,
              'machine-name' => $skeletonName,
              'demo-url' => $demo,
              'skeleton-url' => $skeletonUrl
            );
            $seen[] = $skeletonName;
          }
        }
        closedir($handle);
      }
    }

    return array(
      'status' => 200,
      'data' => $items
    );
  }

  /**
   * Get a specific skeleton file by name.
   * Returns the skeleton JSON data.
   * Requires a valid user_token.
   *
   * @OA\Get(
   *    path="/getSkeleton",
   *    tags={"cms"},
   *    @OA\Parameter(
   *         name="name",
   *         description="Skeleton file name (without .json extension)",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Returns skeleton JSON data"
   *   )
   * )
   */
  public function getSkeleton() {
    // Validate user_token
    if (!isset($this->params['user_token']) || !$GLOBALS['HAXCMS']->validateRequestToken($this->params['user_token'], $GLOBALS['HAXCMS']->getActiveUserName())) {
      // Debug info to help track down token mismatches in local/dev setups.
      $activeUser = $GLOBALS['HAXCMS']->getActiveUserName();
      $expectedToken = $GLOBALS['HAXCMS']->getRequestToken($activeUser);
      $providedToken = isset($this->params['user_token']) ? $this->params['user_token'] : null;
      $debug = array(
        'activeUserName' => $activeUser,
        'hasUserToken' => isset($this->params['user_token']),
        'providedToken' => $providedToken,
        'expectedToken' => $expectedToken,
        // helpful to see what params made it this far
        'paramKeys' => array_keys($this->params),
      );
      // Log to PHP error log for backend inspection.
      error_log('HAXCMS getSkeleton token failure: ' . json_encode($debug));

      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid request token',
          'debug' => $debug,
        )
      );
    }

    if (!isset($this->params['name'])) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'skeleton name is required',
        )
      );
    }

    // Sanitize the skeleton name to prevent directory traversal
    $safeName = basename($this->params['name']);
    $fileName = (substr($safeName, -5) === '.json') ? $safeName : $safeName . '.json';

    // directories to search for skeleton files
    $dirs = $this->getSkeletonDirectories();

    // Search for the skeleton file
    foreach ($dirs as $dir) {
      $filePath = $dir . '/' . $fileName;
      
      if (file_exists($filePath)) {
        $json = @file_get_contents($filePath);
        $skeleton = json_decode($json);
        
        if ($skeleton === null) {
          return array(
            '__failed' => array(
              'status' => 500,
              'message' => 'Failed to parse skeleton file',
            )
          );
        }
        
        return array(
          'status' => 200,
          'data' => $skeleton
        );
      }
    }

    return array(
      '__failed' => array(
        'status' => 404,
        'message' => 'skeleton not found',
      )
    );
  }

  /**
   * 
   * SITE LISTING CALLBACKS
   * 
   */

  /**
   * @OA\Get(
   *    path="/listSites",
   *    tags={"cms"},
   *    @OA\Response(
   *        response="200",
   *        description="Load a list of all sites the user has created"
   *   )
   * )
   */
  public function listSites() {
    if (isset($this->params['user_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['user_token'], $GLOBALS['HAXCMS']->getActiveUserName())) {
      // top level fake JOS
      $return = array(
        "id" => "123-123-123-123",
        "title" => "My sites",
        "author" => "me",
        "description" => "All of my micro sites I know and love.",
        "license" => "by-sa",
        "metadata" => array(),
        "items" => array()
      );
      // loop through files directory so we can cache those things too
      if ($handle = opendir(HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory)) {
        while (false !== ($item = readdir($handle))) {
          if ($item != "." && $item != ".." && is_dir(HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $item) && file_exists(HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $item . '/site.json')) {
            $json = file_get_contents(HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $item . '/site.json');
            $site = json_decode($json);
            if (isset($site->title)) {
              $site->indent = 0;
              $site->order = 0;
              $site->parent = null;
              $site->location = $GLOBALS['HAXCMS']->basePath . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $item . '/';
              $site->slug = $GLOBALS['HAXCMS']->basePath . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $item . '/';
              $site->metadata->pageCount = count($site->items);
              // we don't need all items stored here
              unset($site->items);
              // unset other things we don't need to send across in this meta site.json response
              if (isset($site->metadata->dynamicElementLoader)) {
                unset($site->metadata->dynamicElementLoader);
              }
              
              if (isset($site->metadata->node)) {
                unset($site->metadata->node);
              }
              if (isset($site->metadata->build->items)) {
                unset($site->metadata->build->items);
              }
              $return['items'][] = $site;
            }
          }
        }
        closedir($handle);
      }
      return array(
        "status" => 200,
        "data" => $return
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
    if ($GLOBALS['HAXCMS']->validateRequestToken() && isset($this->params['user_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['user_token'], $GLOBALS['HAXCMS']->getActiveUserName())) {
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
  /**
   * @OA\Post(
   *    path="/cloneSite",
   *    tags={"cms","authenticated","site"},
   *    @OA\Parameter(
   *         name="user_token",
   *         description="User validation token",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\RequestBody(
   *        @OA\MediaType(
   *             mediaType="application/json",
   *             @OA\Schema(
   *                 @OA\Property(
   *                     property="site",
   *                     type="object"
   *                 ),
   *                 required={"site"},
   *                 example={
   *                    "site": {
   *                      "name": "mynewsite"
   *                    },
   *                 }
   *             )
   *         )
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Clone a site by copying and renaming the folder on file system"
   *   )
   * )
   */
  public function cloneSite() {
    if (isset($this->params['user_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['user_token'], $GLOBALS['HAXCMS']->getActiveUserName())) {
      $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
      $siteDirectoryPath = $site->directory . '/' . $site->manifest->metadata->site->name;
      $originalPathForReplacement = "/sites/" . $site->manifest->metadata->site->name . "/files/";
      $cloneName = $GLOBALS['HAXCMS']->getUniqueName($site->name);
      // ensure the path to the new folder is valid
      $GLOBALS['fileSystem']->mirror(
          HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $site->manifest->metadata->site->name,
          HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $cloneName
      );
      // we need to then load and rewrite the site name var or it will conflict given the name change
      $site = $GLOBALS['HAXCMS']->loadSite($cloneName);
      $site->manifest->metadata->site->name = $cloneName;
      $site->manifest->id = $GLOBALS['HAXCMS']->generateUUID();
      // loop through all items and rewrite the path to files as we cloned it
      foreach ($site->manifest->items as $delta => $item) {
        if (isset($item->metadata->files)) {
          foreach ($item->metadata->files as $delta2 => $file) {
            $site->manifest->items[$delta]->metadata->files[$delta2]->path = str_replace(
              $originalPathForReplacement,
              '/sites/' . $cloneName . '/files/',
              $site->manifest->items[$delta]->metadata->files[$delta2]->path
            );
            $site->manifest->items[$delta]->metadata->files[$delta2]->fullUrl = str_replace(
              $originalPathForReplacement,
              '/sites/' . $cloneName . '/files/',
              $site->manifest->items[$delta]->metadata->files[$delta2]->fullUrl
            );
          }
        }
      }
      $site->save();
      return array(
        'status' => 200,
        'data' => array(
          'detail' =>
            $GLOBALS['HAXCMS']->basePath .
            $GLOBALS['HAXCMS']->sitesDirectory .
            '/' .
            $cloneName,
          'name' => $cloneName
        ),
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
  /**
   * @OA\Post(
   *    path="/downloadSite",
   *    tags={"cms","authenticated","site","meta"},
   *    @OA\Parameter(
   *         name="user_token",
   *         description="User validation token",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\RequestBody(
   *        @OA\MediaType(
   *             mediaType="application/json",
   *             @OA\Schema(
   *                 @OA\Property(
   *                     property="site",
   *                     type="object"
   *                 ),
   *                 required={"site"},
   *                 example={
   *                    "site": {
   *                      "name": "mynewsite"
   *                    },
   *                 }
   *             )
   *         )
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Download the site folder as a zip file"
   *   )
   * )
   */
  public function downloadSite() {
    if (isset($this->params['user_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['user_token'], $GLOBALS['HAXCMS']->getActiveUserName())) {
      // load site
      $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
      // helpful boilerplate https://stackoverflow.com/questions/29873248/how-to-zip-a-whole-directory-and-download-using-php
      $dir = HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $site->manifest->metadata->site->name;
      // form a basic name
      $zip_file =
        HAXCMS_ROOT .
        '/' .
        $GLOBALS['HAXCMS']->publishedDirectory .
        '/' .
        $site->manifest->metadata->site->name .
        '.zip';
      // Get real path for our folder
      $rootPath = realpath($dir);
      // Initialize archive object
      $zip = new ZipArchive();
      $zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
      // Create recursive directory iterator
      $directory = new RecursiveDirectoryIterator($rootPath);
      $filtered = new DirFilter($directory, array('node_modules'));
      $files = new RecursiveIteratorIterator($filtered);
      foreach ($files as $name => $file) {
        // Skip directories (they would be added automatically)
        if (!$file->isDir()) {
          // Get real and relative path for current file
          $filePath = $file->getRealPath();
          $relativePath = substr($filePath, strlen($rootPath) + 1);
          // Add current file to archive
          if ($filePath != '' && $relativePath != '') {
            $zip->addFile($filePath, $relativePath);
          }
        }
      }
      // Zip archive will be created only after closing object
      $zip->close();
      return array(
        'status' => 200,
        'data' => array(
          'link' =>
            $GLOBALS['HAXCMS']->basePath .
            $GLOBALS['HAXCMS']->publishedDirectory .
            '/' .
            basename($zip_file),
          'name' => basename($zip_file)
        )
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
  /**
   * @OA\\Post(
   *    path=\"/saveSiteAsTemplate\",
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
   *        description=\"Generate a skeleton from an existing site and save it to user templates\"
   *   )
   * )
   */
  public function saveSiteAsTemplate() {
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
      $machineName =
        isset($skeleton['meta']) &&
        is_array($skeleton['meta']) &&
        isset($skeleton['meta']['machineName']) &&
        is_string($skeleton['meta']['machineName']) &&
        trim($skeleton['meta']['machineName']) !== ''
          ? $this->normalizeTemplateMachineName($skeleton['meta']['machineName'])
          : $this->normalizeTemplateMachineName($site->manifest->metadata->site->name);
      if ($machineName === '') {
        $machineName = 'site-template';
      }
      if (!isset($skeleton['meta']) || !is_array($skeleton['meta'])) {
        $skeleton['meta'] = array();
      }
      $skeleton['meta']['name'] = $machineName;
      $skeleton['meta']['machineName'] = $machineName;
      $skeletonsDirectory = $GLOBALS['HAXCMS']->configDirectory . '/user/skeletons';
      if (!file_exists($skeletonsDirectory) && !mkdir($skeletonsDirectory, 0755, true)) {
        return array(
          '__failed' => array(
            'status' => 500,
            'message' => 'Unable to create skeletons directory',
          )
        );
      }
      if (!is_dir($skeletonsDirectory) || !is_writable($skeletonsDirectory)) {
        return array(
          '__failed' => array(
            'status' => 500,
            'message' => 'Skeletons directory is not writable',
          )
        );
      }
      $targetPath = $skeletonsDirectory . '/' . $machineName . '.json';
      $payload = json_encode($skeleton, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
      if ($payload === false) {
        return array(
          '__failed' => array(
            'status' => 500,
            'message' => 'Unable to encode site skeleton',
          )
        );
      }
      $writeResult = @file_put_contents($targetPath, $payload . PHP_EOL);
      if ($writeResult === false) {
        return array(
          '__failed' => array(
            'status' => 500,
            'message' => 'Unable to save site skeleton',
          )
        );
      }
      $baseAPIPath = $GLOBALS['HAXCMS']->basePath . $GLOBALS['HAXCMS']->systemRequestBase . '/';
      $userToken = $this->params['user_token'];
      $skeletonUrl = $baseAPIPath . 'getSkeleton?name=' . urlencode($machineName) . '&user_token=' . urlencode($userToken);
      return array(
        'status' => 200,
        'data' => array(
          'saved' => true,
          'name' => $machineName,
          'filename' => $machineName . '.json',
          'path' => $targetPath,
          'link' => $skeletonUrl,
        ),
      );
    }
    catch (Exception $e) {
      return array(
        '__failed' => array(
          'status' => 500,
          'message' => 'Unable to save site skeleton',
          'detail' => $e->getMessage(),
        )
      );
    }
  }
  /**
   * @OA\Post(
   *    path="/archiveSite",
   *    tags={"cms","authenticated","site"},
   *    @OA\Parameter(
   *         name="user_token",
   *         description="User validation token",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\RequestBody(
   *        @OA\MediaType(
   *             mediaType="application/json",
   *             @OA\Schema(
   *                 @OA\Property(
   *                     property="site",
   *                     type="object"
   *                 ),
   *                 required={"site"},
   *                 example={
   *                    "site": {
   *                      "name": "mynewsite"
   *                    },
   *                 }
   *             )
   *         )
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Archive a site by moving it on the file system"
   *   )
   * )
   */
  public function archiveSite() {
    if (isset($this->params['user_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['user_token'], $GLOBALS['HAXCMS']->getActiveUserName())) {
      $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
      if ($site->manifest->metadata->site->name) {
        rename(
          HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $site->manifest->metadata->site->name,
          HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->archivedDirectory . '/' . $site->manifest->metadata->site->name);
        return array(
          'status' => 200,
          'data' => array(
            'name' => $site->name,
            'detail' => 'Site archived',
          )
        );
      }
      else {
        return array(
          '__failed' => array(
            'status' => 500,
            'message' => 'Site does not exist',
          )
        );
      }
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
   * HAXIAM Add User Access - Grant access to a site by creating symlinks
   * 
   * @OA\Post(
   *    path="/haxiamAddUserAccess",
   *    tags={"cms","authenticated","haxiam"},
   *    @OA\Parameter(
   *         name="user_token",
   *         description="User validation token",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\RequestBody(
   *        @OA\MediaType(
   *             mediaType="application/json",
   *             @OA\Schema(
   *                 @OA\Property(
   *                     property="userName",
   *                     type="string",
   *                     description="Username to grant access to"
   *                 ),
   *                 @OA\Property(
   *                     property="siteName",
   *                     type="string",
   *                     description="Name of the site to grant access to"
   *                 ),
   *                 required={"userName", "siteName"},
   *                 example={
   *                    "userName": "xyz456",
   *                    "siteName": "stuff"
   *                 }
   *             )
   *         )
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="User access granted successfully",
   *        @OA\MediaType(
   *             mediaType="application/json",
   *             @OA\Schema(
   *                 @OA\Property(
   *                     property="status",
   *                     type="string",
   *                     example="success"
   *                 ),
   *                 @OA\Property(
   *                     property="message",
   *                     type="string",
   *                     example="User access granted successfully"
   *                 ),
   *                 @OA\Property(
   *                     property="userName",
   *                     type="string",
   *                     example="xyz456"
   *                 ),
   *                 @OA\Property(
   *                     property="timestamp",
   *                     type="string",
   *                     format="date-time"
   *                 )
   *             )
   *         )
   *    ),
   *    @OA\Response(
   *        response="403",
   *        description="User not found or unauthorized"
   *    ),
   *    @OA\Response(
   *        response="400",
   *        description="Invalid input or HAXIAM not enabled"
   *    ),
   *    @OA\Response(
   *        response="500",
   *        description="Failed to create user access"
   *    )
   * )
   */
  public function haxiamAddUserAccess() {
    // Only allow this operation in HAXIAM mode
    if (!isset($GLOBALS['HAXCMS']->config->iam) || !$GLOBALS['HAXCMS']->config->iam) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'HAXIAM mode is not enabled',
        )
      );
    }

    // Validate user token for security (same as other user operations like archiveSite)
    if (!isset($this->params['user_token']) || !$GLOBALS['HAXCMS']->validateRequestToken($this->params['user_token'], $GLOBALS['HAXCMS']->getActiveUserName())) {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid request token',
        )
      );
    }

    // Validate required parameters
    if (!isset($this->params['userName']) || empty(trim($this->params['userName']))) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'userName is required',
        )
      );
    }

    if (!isset($this->params['siteName']) || empty(trim($this->params['siteName']))) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'siteName is required',
        )
      );
    }

    // Clean and validate inputs using HAXCMS machine name sanitization
    $rawUserName = trim($this->params['userName']);
    $rawSiteName = trim($this->params['siteName']);
    
    // Validate and sanitize userName using the enhanced generateMachineName method
    $targetUserName = $GLOBALS['HAXCMS']->generateMachineName($rawUserName);
    if ($targetUserName !== $rawUserName || empty($targetUserName)) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'userName must be a valid machine name (alphanumeric, hyphens, underscores only)',
        )
      );
    }
    
    // Validate and sanitize siteName using the enhanced generateMachineName method
    $siteName = $GLOBALS['HAXCMS']->generateMachineName($rawSiteName);
    if ($siteName !== $rawSiteName || empty($siteName)) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'siteName must be a valid machine name (alphanumeric, hyphens, underscores only)',
        )
      );
    }

    $currentUser = $GLOBALS['HAXCMS']->getActiveUserName();
    
    // Prevent self-access grants
    if ($targetUserName === $currentUser) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'Cannot grant access to yourself',
        )
      );
    }

    // Validate that the target user exists in HAXIAM and has a sites directory
    if (!$this->_validateHAXIAMUser($targetUserName)) {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'User not found or has not set up HAXIAM yet',
        )
      );
    }

    // Validate that the current user owns the specified site
    if (!$this->_validateUserOwnsSite($currentUser, $siteName)) {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'You do not own this site or site does not exist',
        )
      );
    }

    // Create the symlink for the target user
    try {
      $result = $this->_createUserSiteSymlink($currentUser, $targetUserName, $siteName);
      if ($result['success']) {
        // Log the access grant
        error_log("HAXIAM: User '{$currentUser}' granted access to site '{$siteName}' to user '{$targetUserName}'");
        
        return array(
          'status' => 'success',
          'message' => 'User access granted successfully',
          'userName' => $targetUserName,
          'timestamp' => date('c')
        );
      } else {
        return array(
          '__failed' => array(
            'status' => 500,
            'message' => $result['error'],
          )
        );
      }
    } catch (Exception $e) {
      error_log("HAXIAM addUserAccess error: " . $e->getMessage());
      return array(
        '__failed' => array(
          'status' => 500,
          'message' => 'Failed to create user access',
        )
      );
    }
  }

  /**
   * Helper method to validate if a user exists in HAXIAM and has set up their environment
   */
  private function _validateHAXIAMUser($userName) {
    // In HAXIAM, users have directories under /var/www/sites/{userName}
    $userPath = '/var/www/sites/' . $userName;
    $sitesPath = $userPath . '/sites';
    
    // User must exist AND have a sites directory (indicating they've logged in and set up HAXIAM)
    return is_dir($userPath) && is_dir($sitesPath);
  }

  /**
   * Helper method to validate that the current user owns the specified site
   */
  private function _validateUserOwnsSite($userName, $siteName) {
    // Check that the site exists in the user's sites directory
    $sitePath = '/var/www/sites/' . $userName . '/sites/' . $siteName;
    return is_dir($sitePath) && !is_link($sitePath); // Must be actual directory, not symlink
  }

  /**
   * Helper method to create a symlink in the target user's sites directory
   */
  private function _createUserSiteSymlink($sourceUser, $targetUser, $siteName) {
    // Source: the actual site directory owned by the source user
    $sourceSitePath = '/var/www/sites/' . $sourceUser . '/sites/' . $siteName;
    
    // Target: where the symlink should be created in target user's directory
    $targetUserSitesDir = '/var/www/sites/' . $targetUser . '/sites';
    $targetSitePath = $targetUserSitesDir . '/' . $siteName;
    
    // Double-check source site exists and is actually a directory (not a symlink)
    if (!is_dir($sourceSitePath) || is_link($sourceSitePath)) {
      return array('success' => false, 'error' => 'Source site does not exist or is not owned by you');
    }
    
    // Check if target already has access (symlink or directory already exists)
    if (file_exists($targetSitePath)) {
      return array('success' => false, 'error' => 'User already has access to this site');
    }
    
    // Ensure target user's sites directory exists - DO NOT create it, fail if it doesn't exist
    if (!is_dir($targetUserSitesDir)) {
      return array('success' => false, 'error' => 'Target user has not set up HAXIAM yet - they must log in first');
    }
    
    // Create the symlink using relative path: ../../sourceuser/sites/sitename
    $relativePath = '../../' . $sourceUser . '/sites/' . $siteName;
    
    if (@symlink($relativePath, $targetSitePath)) {
      return array('success' => true);
    } else {
      return array('success' => false, 'error' => 'Failed to create symlink - check permissions');
    }
  }
}
