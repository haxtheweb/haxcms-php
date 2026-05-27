<?php
class HAXCMSAPIKeysService
{
  const SUPPORTED_PROVIDERS = array(
    'youtube',
    'vimeo',
    'giphy',
    'unsplash',
    'flickr',
    'anthropic',
  );

  public static function getSupportedProviders()
  {
    return self::SUPPORTED_PROVIDERS;
  }

  private static function normalizeAPIKeyValue($value)
  {
    if (is_string($value)) {
      return trim($value);
    }
    if (is_null($value)) {
      return '';
    }
    return trim(strval($value));
  }

  public static function normalizeAPIKeys($input = array())
  {
    $source = array();
    if (is_object($input)) {
      $source = (array) $input;
    }
    else if (is_array($input)) {
      $source = $input;
    }
    $normalized = array();
    $providers = self::getSupportedProviders();
    foreach ($providers as $provider) {
      if (array_key_exists($provider, $source)) {
        $normalized[$provider] = self::normalizeAPIKeyValue($source[$provider]);
      }
      else {
        $normalized[$provider] = '';
      }
    }
    return $normalized;
  }

  public static function hasSupportedAPIKeyPayload($input = array())
  {
    $source = array();
    if (is_object($input)) {
      $source = (array) $input;
    }
    else if (is_array($input)) {
      $source = $input;
    }
    $providers = self::getSupportedProviders();
    foreach ($providers as $provider) {
      if (array_key_exists($provider, $source)) {
        return true;
      }
    }
    return false;
  }

  public static function getAPIKeysFilePath($haxcms)
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
    return rtrim($configDirectory, '/') . '/apiKeys.json';
  }

  public static function readAPIKeys($haxcms)
  {
    $filePath = self::getAPIKeysFilePath($haxcms);
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
    return self::normalizeAPIKeys($existing);
  }

  public static function writeAPIKeys($haxcms, $keys = array())
  {
    $filePath = self::getAPIKeysFilePath($haxcms);
    $directory = dirname($filePath);
    if (!is_dir($directory) && !@mkdir($directory, 0755, true)) {
      throw new Exception('Unable to create API key directory');
    }
    $normalized = self::normalizeAPIKeys($keys);
    $json = json_encode($normalized, JSON_PRETTY_PRINT);
    if (!is_string($json)) {
      throw new Exception('Unable to encode API key settings');
    }
    $written = @file_put_contents($filePath, $json . PHP_EOL);
    if ($written === false) {
      throw new Exception('Unable to write API key settings');
    }
    return $normalized;
  }
}
