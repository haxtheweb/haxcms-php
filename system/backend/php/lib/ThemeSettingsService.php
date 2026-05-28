<?php
class HAXCMSThemeSettingsService
{
  public static function normalizeBoolean($value, $defaultValue = true)
  {
    if (is_bool($value)) {
      return $value;
    }
    if (is_int($value) || is_float($value)) {
      return ((float) $value) !== 0.0;
    }
    if (is_string($value)) {
      $normalized = strtolower(trim($value));
      if (
        $normalized === 'false' ||
        $normalized === '0' ||
        $normalized === 'off' ||
        $normalized === 'no' ||
        $normalized === 'disabled'
      ) {
        return false;
      }
      if (
        $normalized === 'true' ||
        $normalized === '1' ||
        $normalized === 'on' ||
        $normalized === 'yes' ||
        $normalized === 'enabled'
      ) {
        return true;
      }
    }
    return $defaultValue ? true : false;
  }

  public static function normalizeMachineName($haxcms, $value = '')
  {
    if (!is_object($haxcms) || !method_exists($haxcms, 'generateMachineName')) {
      return '';
    }
    return $haxcms->generateMachineName((string) $value);
  }

  public static function normalizeMachineNameList($haxcms, $input = array())
  {
    $source = array();
    if (is_array($input)) {
      $source = $input;
    }
    else if (is_object($input)) {
      $source = (array) $input;
    }
    $list = array();
    foreach ($source as $value) {
      $machineName = self::normalizeMachineName($haxcms, $value);
      if ($machineName === '') {
        continue;
      }
      if (!in_array($machineName, $list, true)) {
        $list[] = $machineName;
      }
    }
    return $list;
  }

  public static function normalizeEnabledThemeMap($haxcms, $input = array())
  {
    $source = $input;
    if (
      is_object($source) &&
      isset($source->enabledThemes)
    ) {
      $source = $source->enabledThemes;
    }
    else if (
      is_array($source) &&
      array_key_exists('enabledThemes', $source)
    ) {
      $source = $source['enabledThemes'];
    }
    $normalized = array();
    if (is_array($source) && array_keys($source) === range(0, count($source) - 1)) {
      $list = self::normalizeMachineNameList($haxcms, $source);
      foreach ($list as $machineName) {
        $normalized[$machineName] = true;
      }
      return $normalized;
    }
    if (is_object($source)) {
      $source = (array) $source;
    }
    if (!is_array($source)) {
      return $normalized;
    }
    foreach ($source as $key => $value) {
      $machineName = self::normalizeMachineName($haxcms, $key);
      if ($machineName === '') {
        continue;
      }
      $normalized[$machineName] = self::normalizeBoolean($value, true);
    }
    return $normalized;
  }

  public static function isThemeEnabled($haxcms, $machineName = '', $enabledThemes = array())
  {
    $normalizedMachineName = self::normalizeMachineName($haxcms, $machineName);
    if ($normalizedMachineName === '') {
      return true;
    }
    $map = self::normalizeEnabledThemeMap($haxcms, $enabledThemes);
    if (!array_key_exists($normalizedMachineName, $map)) {
      return true;
    }
    return $map[$normalizedMachineName] !== false;
  }

  public static function applyDetectedThemeDefaults($haxcms, $enabledThemes = array(), $detectedNames = array())
  {
    $map = self::normalizeEnabledThemeMap($haxcms, $enabledThemes);
    $names = self::normalizeMachineNameList($haxcms, $detectedNames);
    $changed = false;
    foreach ($names as $machineName) {
      if (!array_key_exists($machineName, $map)) {
        $map[$machineName] = true;
        $changed = true;
      }
    }
    return array(
      'enabledThemes' => $map,
      'changed' => $changed,
    );
  }

  public static function getEnabledThemesFilePath($haxcms)
  {
    $defaultConfigDirectory = rtrim(getcwd(), '/') . '/_config';
    $configDirectory = $defaultConfigDirectory;
    if (
      is_object($haxcms) &&
      isset($haxcms->configDirectory) &&
      is_string($haxcms->configDirectory) &&
      trim($haxcms->configDirectory) !== ''
    ) {
      $configDirectory = $haxcms->configDirectory;
    }
    return rtrim($configDirectory, '/') . '/settings/enabledThemes.json';
  }

  public static function readEnabledThemeMap($haxcms)
  {
    $filePath = self::getEnabledThemesFilePath($haxcms);
    if (!file_exists($filePath) || !is_file($filePath)) {
      return array();
    }
    $raw = @file_get_contents($filePath);
    if (!is_string($raw) || trim($raw) === '') {
      return array();
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) && !is_object($decoded)) {
      return array();
    }
    return self::normalizeEnabledThemeMap($haxcms, $decoded);
  }

  public static function writeEnabledThemeMap($haxcms, $enabledThemes = array())
  {
    $filePath = self::getEnabledThemesFilePath($haxcms);
    $directory = dirname($filePath);
    if (!is_dir($directory) && !@mkdir($directory, 0755, true)) {
      throw new Exception('Unable to create enabledThemes settings directory');
    }
    $normalized = self::normalizeEnabledThemeMap($haxcms, $enabledThemes);
    ksort($normalized);
    $payload = array(
      'enabledThemes' => $normalized,
    );
    $json = json_encode($payload, JSON_PRETTY_PRINT);
    if (!is_string($json)) {
      throw new Exception('Unable to encode enabledThemes settings');
    }
    $written = @file_put_contents($filePath, $json . PHP_EOL);
    if ($written === false) {
      throw new Exception('Unable to write enabledThemes settings');
    }
    return $normalized;
  }

  private static function normalizeThemeCategory($value)
  {
    if (is_array($value)) {
      $category = array();
      foreach ($value as $item) {
        if (!is_string($item)) {
          continue;
        }
        $normalized = trim($item);
        if ($normalized === '') {
          continue;
        }
        $category[] = $normalized;
      }
      return $category;
    }
    if (is_string($value) && trim($value) !== '') {
      return array(trim($value));
    }
    return array();
  }

  private static function normalizeThemePriority($value)
  {
    if (is_numeric($value)) {
      return 0 + $value;
    }
    return 0;
  }

  private static function readThemeValue($theme = array(), $key = '')
  {
    if (is_array($theme) && array_key_exists($key, $theme)) {
      return $theme[$key];
    }
    if (is_object($theme) && isset($theme->{$key})) {
      return $theme->{$key};
    }
    return null;
  }

  private static function readThemeMachineName($theme = array())
  {
    $candidates = array(
      self::readThemeValue($theme, 'machineName'),
      self::readThemeValue($theme, 'machine-name'),
      self::readThemeValue($theme, 'element'),
      self::readThemeValue($theme, 'name'),
    );
    foreach ($candidates as $candidate) {
      if (is_string($candidate) && trim($candidate) !== '') {
        return trim($candidate);
      }
    }
    return '';
  }

  public static function isThemeHidden($theme = array())
  {
    return self::normalizeBoolean(self::readThemeValue($theme, 'hidden'), false);
  }

  public static function isThemeTerrible($theme = array(), $machineName = '')
  {
    $name = trim((string) $machineName);
    if ($name === '') {
      $name = self::readThemeMachineName($theme);
    }
    $lowerName = strtolower($name);
    return (
      self::normalizeBoolean(self::readThemeValue($theme, 'terrible'), false) ||
      strpos($lowerName, 'terrible') === 0
    );
  }

  public static function getThemeScreenshot($theme = array())
  {
    $candidates = array(
      self::readThemeValue($theme, 'screenshot'),
      self::readThemeValue($theme, 'thumbnail'),
      self::readThemeValue($theme, 'preview'),
    );
    foreach ($candidates as $candidate) {
      if (is_string($candidate) && trim($candidate) !== '') {
        return trim($candidate);
      }
    }
    return '';
  }

  public static function normalizeThemeRecord($haxcms, $machineName = '', $theme = array(), $scope = 'registry')
  {
    $source = array();
    if (is_array($theme)) {
      $source = $theme;
    }
    else if (is_object($theme)) {
      $source = json_decode(json_encode($theme), true);
      if (!is_array($source)) {
        $source = array();
      }
    }
    $normalized = $source;
    $normalized['machineName'] = $machineName;
    $normalized['machine-name'] = $machineName;
    $normalized['scope'] = $scope;
    $normalized['element'] = (
      isset($normalized['element']) &&
      is_string($normalized['element']) &&
      trim($normalized['element']) !== ''
    ) ? trim($normalized['element']) : $machineName;
    $normalized['path'] = (
      isset($normalized['path']) &&
      is_string($normalized['path'])
    ) ? $normalized['path'] : '';
    $normalized['name'] = (
      isset($normalized['name']) &&
      is_string($normalized['name']) &&
      trim($normalized['name']) !== ''
    ) ? trim($normalized['name']) : $machineName;
    $normalized['description'] = (
      isset($normalized['description']) &&
      is_string($normalized['description'])
    ) ? $normalized['description'] : '';
    $normalized['thumbnail'] = (
      isset($normalized['thumbnail']) &&
      is_string($normalized['thumbnail'])
    ) ? $normalized['thumbnail'] : '';
    $normalized['screenshot'] = self::getThemeScreenshot($normalized);
    $normalized['category'] = self::normalizeThemeCategory(
      isset($normalized['category']) ? $normalized['category'] : array()
    );
    $normalized['hidden'] = self::isThemeHidden($source);
    $normalized['terrible'] = self::isThemeTerrible($source, $machineName);
    $normalized['priority'] = self::normalizeThemePriority(
      isset($source['priority']) ? $source['priority'] : 0
    );
    return $normalized;
  }

  private static function addPathCandidate(&$candidates, $candidate)
  {
    if (!is_string($candidate) || trim($candidate) === '') {
      return;
    }
    $normalized = str_replace('\\', '/', $candidate);
    if (!in_array($normalized, $candidates, true)) {
      $candidates[] = $normalized;
    }
  }

  private static function getThemePathCandidates($haxcms, $themePath = '')
  {
    $relativePath = ltrim(str_replace('\\', '/', trim((string) $themePath)), '/');
    if ($relativePath === '') {
      return array();
    }
    $candidates = array();
    $root = defined('HAXCMS_ROOT') ? HAXCMS_ROOT : getcwd();
    self::addPathCandidate($candidates, rtrim($root, '/') . '/' . $relativePath);
    self::addPathCandidate($candidates, rtrim($root, '/') . '/build/es6/node_modules/' . $relativePath);
    self::addPathCandidate($candidates, rtrim($root, '/') . '/public/build/es6/node_modules/' . $relativePath);
    self::addPathCandidate($candidates, rtrim($root, '/') . '/src/public/build/es6/node_modules/' . $relativePath);
    self::addPathCandidate($candidates, rtrim($root, '/') . '/node_modules/' . $relativePath);
    return $candidates;
  }

  private static function getDefaultThemeDirectories($haxcms)
  {
    $configDirectory = rtrim(getcwd(), '/') . '/_config';
    if (
      is_object($haxcms) &&
      isset($haxcms->configDirectory) &&
      is_string($haxcms->configDirectory) &&
      trim($haxcms->configDirectory) !== ''
    ) {
      $configDirectory = rtrim($haxcms->configDirectory, '/');
    }
    return array(
      'user' => $configDirectory . '/user/themes',
      'config' => $configDirectory . '/themes',
    );
  }

  private static function getThemeDirectories($haxcms)
  {
    $defaultDirs = self::getDefaultThemeDirectories($haxcms);
    $dirs = array();
    foreach ($defaultDirs as $dir) {
      if (is_dir($dir)) {
        $dirs[] = rtrim($dir, '/');
      }
    }
    if (is_object($haxcms) && method_exists($haxcms, 'dispatchEvent')) {
      $context = new stdClass();
      $context->directories = $dirs;
      $context->defaultDirectories = $defaultDirs;
      $haxcms->dispatchEvent('haxcms-theme-dirs', $context);
      if (
        is_object($context) &&
        isset($context->directories) &&
        is_array($context->directories)
      ) {
        $finalDirs = array();
        foreach ($context->directories as $dir) {
          if (!is_string($dir)) {
            continue;
          }
          $normalized = rtrim(trim($dir), '/');
          if ($normalized === '' || !is_dir($normalized)) {
            continue;
          }
          if (!in_array($normalized, $finalDirs, true)) {
            $finalDirs[] = $normalized;
          }
        }
        $dirs = $finalDirs;
      }
    }
    return $dirs;
  }

  private static function discoverConfigDirectoryThemes($haxcms)
  {
    $themes = array();
    $dirs = self::getThemeDirectories($haxcms);
    foreach ($dirs as $dir) {
      $scope = (strpos($dir, '/user/themes') !== false) ? 'user-config' : 'config';
      if (!($handle = opendir($dir))) {
        continue;
      }
      while (false !== ($file = readdir($handle))) {
        if ($file === '.' || $file === '..') {
          continue;
        }
        $path = $dir . '/' . $file;
        if (!is_file($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'json') {
          continue;
        }
        $raw = @file_get_contents($path);
        $theme = json_decode($raw, true);
        if (!is_array($theme) && !is_object($theme)) {
          continue;
        }
        $themeArray = is_object($theme) ? (array) $theme : $theme;
        $rawName = pathinfo($file, PATHINFO_FILENAME);
        $machineNameSource = $rawName;
        if (isset($themeArray['machineName']) && is_string($themeArray['machineName']) && trim($themeArray['machineName']) !== '') {
          $machineNameSource = $themeArray['machineName'];
        }
        else if (isset($themeArray['element']) && is_string($themeArray['element']) && trim($themeArray['element']) !== '') {
          $machineNameSource = $themeArray['element'];
        }
        else if (isset($themeArray['name']) && is_string($themeArray['name']) && trim($themeArray['name']) !== '') {
          $machineNameSource = $themeArray['name'];
        }
        $machineName = self::normalizeMachineName($haxcms, $machineNameSource);
        if ($machineName === '' || isset($themes[$machineName])) {
          continue;
        }
        $themes[$machineName] = self::normalizeThemeRecord(
          $haxcms,
          $machineName,
          $themeArray,
          $scope
        );
      }
      closedir($handle);
    }
    return $themes;
  }

  private static function isThemeDetectedOnFileSystem($haxcms, $machineName = '', $theme = array(), $configThemes = array())
  {
    $themePath = '';
    if (is_array($theme) && isset($theme['path']) && is_string($theme['path']) && trim($theme['path']) !== '') {
      $themePath = trim($theme['path']);
    }
    else if (is_object($theme) && isset($theme->path) && is_string($theme->path) && trim($theme->path) !== '') {
      $themePath = trim($theme->path);
    }
    if ($themePath === '') {
      return true;
    }
    $candidates = self::getThemePathCandidates($haxcms, $themePath);
    foreach ($candidates as $candidate) {
      if (file_exists($candidate)) {
        return true;
      }
    }
    return array_key_exists($machineName, $configThemes);
  }

  public static function discoverThemes($haxcms)
  {
    $discovered = array();
    $seen = array();
    $configThemes = self::discoverConfigDirectoryThemes($haxcms);
    $sourceThemes = array();
    if (is_object($haxcms) && method_exists($haxcms, 'getThemes')) {
      $themes = $haxcms->getThemes();
      if (is_object($themes)) {
        $sourceThemes = (array) $themes;
      }
      else if (is_array($themes)) {
        $sourceThemes = $themes;
      }
    }

    foreach ($sourceThemes as $key => $theme) {
      if (!is_array($theme) && !is_object($theme)) {
        continue;
      }
      $themeArray = is_object($theme) ? (array) $theme : $theme;
      $machineNameSource = $key;
      if (!is_string($machineNameSource) || trim($machineNameSource) === '') {
        if (isset($themeArray['machineName'])) {
          $machineNameSource = $themeArray['machineName'];
        }
        else if (isset($themeArray['element'])) {
          $machineNameSource = $themeArray['element'];
        }
        else if (isset($themeArray['name'])) {
          $machineNameSource = $themeArray['name'];
        }
      }
      $machineName = self::normalizeMachineName($haxcms, $machineNameSource);
      if ($machineName === '' || in_array($machineName, $seen, true)) {
        continue;
      }
      if (!self::isThemeDetectedOnFileSystem($haxcms, $machineName, $themeArray, $configThemes)) {
        continue;
      }
      if (isset($configThemes[$machineName])) {
        $themeArray = array_merge($themeArray, $configThemes[$machineName]);
      }
      $discovered[] = self::normalizeThemeRecord($haxcms, $machineName, $themeArray, 'registry');
      $seen[] = $machineName;
    }

    if (count($discovered) === 0) {
      foreach ($sourceThemes as $key => $theme) {
        if (!is_array($theme) && !is_object($theme)) {
          continue;
        }
        $themeArray = is_object($theme) ? (array) $theme : $theme;
        $machineNameSource = $key;
        if (!is_string($machineNameSource) || trim($machineNameSource) === '') {
          if (isset($themeArray['machineName'])) {
            $machineNameSource = $themeArray['machineName'];
          }
          else if (isset($themeArray['element'])) {
            $machineNameSource = $themeArray['element'];
          }
          else if (isset($themeArray['name'])) {
            $machineNameSource = $themeArray['name'];
          }
        }
        $machineName = self::normalizeMachineName($haxcms, $machineNameSource);
        if ($machineName === '' || in_array($machineName, $seen, true)) {
          continue;
        }
        if (isset($configThemes[$machineName])) {
          $themeArray = array_merge($themeArray, $configThemes[$machineName]);
        }
        $discovered[] = self::normalizeThemeRecord($haxcms, $machineName, $themeArray, 'registry');
        $seen[] = $machineName;
      }
    }

    foreach ($configThemes as $machineName => $themeData) {
      if (in_array($machineName, $seen, true)) {
        continue;
      }
      $discovered[] = self::normalizeThemeRecord($haxcms, $machineName, $themeData, 'config');
      $seen[] = $machineName;
    }

    return $discovered;
  }

  public static function themesToMap($themes = array())
  {
    $source = is_array($themes) ? array_values($themes) : array();
    usort($source, function ($a, $b) {
      $aName = (is_array($a) && isset($a['machineName'])) ? (string) $a['machineName'] : '';
      $bName = (is_array($b) && isset($b['machineName'])) ? (string) $b['machineName'] : '';
      if ($aName === $bName) {
        return 0;
      }
      return ($aName < $bName) ? -1 : 1;
    });
    $map = array();
    foreach ($source as $theme) {
      if (!is_array($theme) || !isset($theme['machineName'])) {
        continue;
      }
      $machineName = $theme['machineName'];
      if (!is_string($machineName) || $machineName === '') {
        continue;
      }
      $map[$machineName] = $theme;
    }
    return $map;
  }
}
