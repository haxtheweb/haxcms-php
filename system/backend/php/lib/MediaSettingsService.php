<?php
class HAXCMSMediaSettingsService
{
  const MIN_JPEG_QUALITY = 1;
  const MAX_JPEG_QUALITY = 100;
  const MIN_UPLOAD_SIZE_MB = 1;
  const MAX_UPLOAD_SIZE_MB = 10240;

  private static function normalizeJpegQualityValue($value)
  {
    if (is_null($value) || $value === '') {
      return null;
    }
    $quality = filter_var($value, FILTER_VALIDATE_INT);
    if ($quality === false) {
      return null;
    }
    if ($quality < self::MIN_JPEG_QUALITY) {
      $quality = self::MIN_JPEG_QUALITY;
    }
    if ($quality > self::MAX_JPEG_QUALITY) {
      $quality = self::MAX_JPEG_QUALITY;
    }
    return $quality;
  }

  private static function normalizeMaxUploadSizeMbValue($value)
  {
    if (is_null($value) || $value === '') {
      return null;
    }
    $size = filter_var($value, FILTER_VALIDATE_INT);
    if ($size === false) {
      return null;
    }
    if ($size < self::MIN_UPLOAD_SIZE_MB) {
      $size = self::MIN_UPLOAD_SIZE_MB;
    }
    if ($size > self::MAX_UPLOAD_SIZE_MB) {
      $size = self::MAX_UPLOAD_SIZE_MB;
    }
    return $size;
  }

  private static function normalizeAcceptedFormatsValue($value)
  {
    if (is_null($value)) {
      return null;
    }
    $candidates = array();
    if (is_array($value)) {
      $candidates = $value;
    }
    else if (is_string($value)) {
      $candidates = explode(',', $value);
    }
    else {
      return null;
    }
    $seen = array();
    $normalized = array();
    foreach ($candidates as $candidate) {
      $format = strtolower(trim((string) $candidate));
      $format = ltrim($format, '.');
      if (
        $format === '' ||
        preg_match('/^[a-z0-9]+$/', $format) !== 1 ||
        array_key_exists($format, $seen)
      ) {
        continue;
      }
      $seen[$format] = true;
      $normalized[] = $format;
    }
    if (count($normalized) === 0) {
      return null;
    }
    return implode(',', $normalized);
  }

  public static function getMediaSettingsFilePath($haxcms)
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
    return rtrim($configDirectory, '/') . '/settings/media.json';
  }

  public static function normalizeMediaSettings($input = array())
  {
    $source = array();
    if (is_object($input)) {
      $source = (array) $input;
    }
    else if (is_array($input)) {
      $source = $input;
    }
    return array(
      'jpegQuality' => self::normalizeJpegQualityValue(
        array_key_exists('jpegQuality', $source) ? $source['jpegQuality'] : null
      ),
      'maxUploadSizeMb' => self::normalizeMaxUploadSizeMbValue(
        array_key_exists('maxUploadSizeMb', $source) ? $source['maxUploadSizeMb'] : null
      ),
      'acceptedFormats' => self::normalizeAcceptedFormatsValue(
        array_key_exists('acceptedFormats', $source) ? $source['acceptedFormats'] : null
      ),
    );
  }

  public static function hasSupportedMediaSettingsPayload($input = array())
  {
    $source = array();
    if (is_object($input)) {
      $source = (array) $input;
    }
    else if (is_array($input)) {
      $source = $input;
    }
    return (
      array_key_exists('jpegQuality', $source) ||
      array_key_exists('maxUploadSizeMb', $source) ||
      array_key_exists('acceptedFormats', $source)
    );
  }

  public static function isValidJpegQualityPayloadValue($value)
  {
    if (is_null($value) || $value === '') {
      return true;
    }
    return !is_null(self::normalizeJpegQualityValue($value));
  }

  public static function isValidMaxUploadSizeMbPayloadValue($value)
  {
    if (is_null($value) || $value === '') {
      return true;
    }
    return !is_null(self::normalizeMaxUploadSizeMbValue($value));
  }

  public static function isValidAcceptedFormatsPayloadValue($value)
  {
    if (is_null($value) || $value === '') {
      return true;
    }
    return !is_null(self::normalizeAcceptedFormatsValue($value));
  }

  public static function readMediaSettings($haxcms)
  {
    $filePath = self::getMediaSettingsFilePath($haxcms);
    $existing = array();
    if (file_exists($filePath) && is_file($filePath)) {
      $raw = @file_get_contents($filePath);
      if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
          $existing = $decoded;
        }
      }
    }
    return self::normalizeMediaSettings($existing);
  }

  public static function writeMediaSettings($haxcms, $settings = array())
  {
    $filePath = self::getMediaSettingsFilePath($haxcms);
    $directory = dirname($filePath);
    if (!is_dir($directory) && !@mkdir($directory, 0755, true)) {
      throw new Exception('Unable to create media settings directory');
    }
    $source = array();
    if (is_object($settings)) {
      $source = (array) $settings;
    }
    else if (is_array($settings)) {
      $source = $settings;
    }
    $normalized = self::readMediaSettings($haxcms);
    if (array_key_exists('jpegQuality', $source)) {
      $normalized['jpegQuality'] = self::normalizeJpegQualityValue(
        $source['jpegQuality']
      );
    }
    if (array_key_exists('maxUploadSizeMb', $source)) {
      $normalized['maxUploadSizeMb'] = self::normalizeMaxUploadSizeMbValue(
        $source['maxUploadSizeMb']
      );
    }
    if (array_key_exists('acceptedFormats', $source)) {
      $normalized['acceptedFormats'] = self::normalizeAcceptedFormatsValue(
        $source['acceptedFormats']
      );
    }
    $json = json_encode($normalized, JSON_PRETTY_PRINT);
    if (!is_string($json)) {
      throw new Exception('Unable to encode media settings');
    }
    $written = @file_put_contents($filePath, $json . PHP_EOL);
    if ($written === false) {
      throw new Exception('Unable to write media settings');
    }
    return $normalized;
  }
}
