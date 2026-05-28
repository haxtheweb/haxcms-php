<?php
class HAXCMSSkeletonSettingsService
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

  public static function normalizeEnabledSkeletonMap($haxcms, $input = array())
  {
    $source = $input;
    if (
      is_object($source) &&
      isset($source->enabledSkeletons)
    ) {
      $source = $source->enabledSkeletons;
    }
    else if (
      is_array($source) &&
      array_key_exists('enabledSkeletons', $source)
    ) {
      $source = $source['enabledSkeletons'];
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

  public static function isSkeletonEnabled($haxcms, $machineName = '', $enabledSkeletons = array())
  {
    $normalizedMachineName = self::normalizeMachineName($haxcms, $machineName);
    if ($normalizedMachineName === '') {
      return true;
    }
    $map = self::normalizeEnabledSkeletonMap($haxcms, $enabledSkeletons);
    if (!array_key_exists($normalizedMachineName, $map)) {
      return true;
    }
    return $map[$normalizedMachineName] !== false;
  }

  public static function applyDetectedSkeletonDefaults($haxcms, $enabledSkeletons = array(), $detectedNames = array())
  {
    $map = self::normalizeEnabledSkeletonMap($haxcms, $enabledSkeletons);
    $names = self::normalizeMachineNameList($haxcms, $detectedNames);
    $changed = false;
    foreach ($names as $machineName) {
      if (!array_key_exists($machineName, $map)) {
        $map[$machineName] = true;
        $changed = true;
      }
    }
    return array(
      'enabledSkeletons' => $map,
      'changed' => $changed,
    );
  }

  public static function getEnabledSkeletonsFilePath($haxcms)
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
    return rtrim($configDirectory, '/') . '/settings/enabledSkeletons.json';
  }

  public static function readEnabledSkeletonMap($haxcms)
  {
    $filePath = self::getEnabledSkeletonsFilePath($haxcms);
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
    return self::normalizeEnabledSkeletonMap($haxcms, $decoded);
  }

  public static function writeEnabledSkeletonMap($haxcms, $enabledSkeletons = array())
  {
    $filePath = self::getEnabledSkeletonsFilePath($haxcms);
    $directory = dirname($filePath);
    if (!is_dir($directory) && !@mkdir($directory, 0755, true)) {
      throw new Exception('Unable to create enabledSkeletons settings directory');
    }
    $normalized = self::normalizeEnabledSkeletonMap($haxcms, $enabledSkeletons);
    ksort($normalized);
    $payload = array(
      'enabledSkeletons' => $normalized,
    );
    $json = json_encode($payload, JSON_PRETTY_PRINT);
    if (!is_string($json)) {
      throw new Exception('Unable to encode enabledSkeletons settings');
    }
    $written = @file_put_contents($filePath, $json . PHP_EOL);
    if ($written === false) {
      throw new Exception('Unable to write enabledSkeletons settings');
    }
    return $normalized;
  }
}
