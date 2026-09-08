<?php
/**
 * Localization / system configuration settings service.
 *
 * Stores the system-wide default language (and future localization defaults)
 * as a `localization` block directly on _config/config.json so that it is
 * loaded at boot by HAXCMS::boot() (alongside mcp / security blocks) and is
 * the single source of truth shared by the installer wizard, createSite, and
 * the admin "Configuration" panel.
 *
 * This mirrors HAXCMSMediaSettingsService in shape (normalize* / read* /
 * write* / hasSupported* / isValid*) so the matching get/save operation traits
 * and NodeJS parity file follow the same proven pattern, while intentionally
 * persisting to config.json rather than a separate _config/settings file,
 * because the value must be readable before _config/settings is scanned and
 * must be writable by install.php during initial bootstrap.
 */
class HAXCMSLocalizationSettingsService
{
  const DEFAULT_LANGUAGE = 'en-US';

  // Curated set of common BCP-47 language tags. The per-site language field
  // in SEO settings remains a free-text field (ISO 639-1), so this list is a
  // convenience default set for the installer + Configuration panel only; it
  // does not constrain what a site may be set to directly.
  public static $SUPPORTED_LANGUAGES = array(
    'en-US' => 'English (United States)',
    'en-GB' => 'English (United Kingdom)',
    'es-ES' => 'Spanish (Spain)',
    'es-MX' => 'Spanish (Mexico)',
    'fr-FR' => 'French (France)',
    'de-DE' => 'German (Germany)',
    'it-IT' => 'Italian (Italy)',
    'pt-BR' => 'Portuguese (Brazil)',
    'pt-PT' => 'Portuguese (Portugal)',
    'nl-NL' => 'Dutch (Netherlands)',
    'pl-PL' => 'Polish (Poland)',
    'ru-RU' => 'Russian (Russia)',
    'uk-UA' => 'Ukrainian (Ukraine)',
    'ja-JP' => 'Japanese (Japan)',
    'ko-KR' => 'Korean (Korea)',
    'zh-CN' => 'Chinese (Simplified)',
    'zh-TW' => 'Chinese (Traditional)',
    'ar-SA' => 'Arabic (Saudi Arabia)',
    'hi-IN' => 'Hindi (India)',
    'tr-TR' => 'Turkish (Turkey)',
    'sv-SE' => 'Swedish (Sweden)',
    'da-DK' => 'Danish (Denmark)',
    'fi-FI' => 'Finnish (Finland)',
    'nb-NO' => 'Norwegian (Norway)',
    'cs-CZ' => 'Czech (Czech Republic)',
    'el-GR' => 'Greek (Greece)',
    'he-IL' => 'Hebrew (Israel)',
    'th-TH' => 'Thai (Thailand)',
    'vi-VN' => 'Vietnamese (Vietnam)',
    'id-ID' => 'Indonesian (Indonesia)',
    'ro-RO' => 'Romanian (Romania)',
    'hu-HU' => 'Hungarian (Hungary)',
    'ca-ES' => 'Catalan (Spain)',
  );

  /**
   * Resolve the config.json path the localization block lives on.
   */
  public static function getLocalizationSettingsFilePath($haxcms)
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
    return rtrim($configDirectory, '/') . '/config.json';
  }

  /**
   * Validate / normalize a single language code value. Returns the code if it
   * is a non-empty string matching ^[a-zA-Z]{2,3}(-[a-zA-Z]{2,4})?$ (BCP-47
   * shaped), else null. We intentionally do NOT restrict to the curated
   * SUPPORTED_LANGUAGES list so that sites / hosts may use any valid tag; the
   * curated list is only a UI convenience.
   */
  public static function normalizeDefaultLanguageValue($value)
  {
    if (is_null($value) || $value === '') {
      return null;
    }
    $value = (string) $value;
    // basic BCP-47-ish shape: 2-3 letter primary, optional 2-4 letter region
    if (preg_match('/^[a-zA-Z]{2,3}(-[a-zA-Z]{2,4})?$/', $value) !== 1) {
      return null;
    }
    // normalize the primary tag lower-case, region upper-case (en-US form)
    $parts = explode('-', $value);
    $parts[0] = strtolower($parts[0]);
    if (isset($parts[1])) {
      $parts[1] = strtoupper($parts[1]);
    }
    return implode('-', $parts);
  }

  public static function normalizeLocalizationSettings($input = array())
  {
    $source = array();
    if (is_object($input)) {
      $source = (array) $input;
    }
    else if (is_array($input)) {
      $source = $input;
    }
    return array(
      'defaultLanguage' => self::normalizeDefaultLanguageValue(
        array_key_exists('defaultLanguage', $source) ? $source['defaultLanguage'] : null
      ),
    );
  }

  public static function hasSupportedLocalizationSettingsPayload($input = array())
  {
    $source = array();
    if (is_object($input)) {
      $source = (array) $input;
    }
    else if (is_array($input)) {
      $source = $input;
    }
    return array_key_exists('defaultLanguage', $source);
  }

  public static function isValidDefaultLanguagePayloadValue($value)
  {
    if (is_null($value) || $value === '') {
      return true;
    }
    return !is_null(self::normalizeDefaultLanguageValue($value));
  }

  /**
   * Read the localization block out of _config/config.json (normalized).
   * Returns array('defaultLanguage' => <code|null>).
   */
  public static function readLocalizationSettings($haxcms)
  {
    $filePath = self::getLocalizationSettingsFilePath($haxcms);
    $existing = array();
    if (file_exists($filePath) && is_file($filePath)) {
      $raw = @file_get_contents($filePath);
      if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && isset($decoded['localization']) && is_array($decoded['localization'])) {
          $existing = $decoded['localization'];
        }
      }
    }
    return self::normalizeLocalizationSettings($existing);
  }

  /**
   * Write the localization block back into _config/config.json, preserving
   * all other top-level config keys. Returns the normalized localization
   * block that was persisted.
   */
  public static function writeLocalizationSettings($haxcms, $settings = array())
  {
    $filePath = self::getLocalizationSettingsFilePath($haxcms);
    if (!file_exists($filePath) || !is_file($filePath)) {
      throw new Exception('Unable to locate config.json for localization settings');
    }
    $source = array();
    if (is_object($settings)) {
      $source = (array) $settings;
    }
    else if (is_array($settings)) {
      $source = $settings;
    }
    // load the full config so we preserve sibling keys
    $raw = @file_get_contents($filePath);
    $config = json_decode($raw, true);
    if (!is_array($config)) {
      throw new Exception('Unable to parse config.json for localization settings');
    }
    if (!isset($config['localization']) || !is_array($config['localization'])) {
      $config['localization'] = array();
    }
    if (array_key_exists('defaultLanguage', $source)) {
      $config['localization']['defaultLanguage'] = self::normalizeDefaultLanguageValue(
        $source['defaultLanguage']
      );
    }
    $json = json_encode($config, JSON_PRETTY_PRINT);
    if (!is_string($json)) {
      throw new Exception('Unable to encode localization settings');
    }
    $written = @file_put_contents($filePath, $json . PHP_EOL, LOCK_EX);
    if ($written === false) {
      throw new Exception('Unable to write localization settings');
    }
    return self::normalizeLocalizationSettings($config['localization']);
  }
}
