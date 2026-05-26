<?php
include_once "JSONOutlineSchemaItem.php";
include_once "SanitizeContent.php";
include_once dirname(__FILE__) . '/routes/RoutesMap.php';
foreach (OperationsRoutesMap::getRoutesMap() as $operationsRouteFile) {
  include_once $operationsRouteFile;
}
include_once dirname(__FILE__) . '/routes/Routes.php';
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
  use OperationsRoutes;

  private static $routesMap = null;
  public $params;
  public $rawParams;

  public static function getRoutesMap() {
    if (is_null(self::$routesMap)) {
      self::$routesMap = OperationsRoutesMap::getRoutesMap();
    }
    return self::$routesMap;
  }

  public static function getRouteFile($routeName) {
    $routesMap = self::getRoutesMap();
    if (isset($routesMap[$routeName])) {
      return $routesMap[$routeName];
    }
    return null;
  }
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
   * HAX EDITOR CALLBACKS
   * 
   */

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
   * 
   * SITE LISTING CALLBACKS
   * 
   */



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
