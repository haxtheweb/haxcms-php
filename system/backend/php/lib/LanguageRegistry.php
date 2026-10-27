<?php
/**
 * Canonical language registry for the backend.
 *
 * system/coreConfig/languages.json is a byte-identical copy of the registry
 * authored in webcomponents at elements/i18n-manager/lib/languages.json,
 * mirrored here the same way themes.json is. The front end reads the same
 * file, so the installer, the Configuration panel, the site language field,
 * the CLI and the /system/api/v1/languages routes all agree on which
 * languages exist.
 *
 * Entries are keyed by the canonical code that gets stored (en, sw, pt-BR,
 * zh-CN). An entry without `parent` is a language; one with `parent` is a
 * regional variant that resolves to its parent's translations. `locale` is
 * the translation file suffix (<namespace>.<locale>.json), so zh-CN loads zh
 * files. Mirrors haxcms-nodejs src/lib/languageRegistry.js.
 */
class HAXCMSLanguageRegistry
{
  private static $cache = null;

  public static function registryPath()
  {
    return dirname(__FILE__) . '/../../../coreConfig/languages.json';
  }

  /**
   * Build { languages, lookup } from a parsed registry. lookup maps a
   * lower-case code or alias to its canonical code; aliases never override a
   * real code.
   */
  private static function prepare($data)
  {
    if (!is_array($data) || !isset($data['languages']) || !is_array($data['languages'])) {
      throw new Exception('language registry: missing languages');
    }
    $lookup = array();
    foreach ($data['languages'] as $code => $entry) {
      $lookup[strtolower((string) $code)] = (string) $code;
    }
    foreach ($data['languages'] as $code => $entry) {
      if (isset($entry['aliases']) && is_array($entry['aliases'])) {
        foreach ($entry['aliases'] as $alias) {
          $key = strtolower((string) $alias);
          if (!isset($lookup[$key])) {
            $lookup[$key] = (string) $code;
          }
        }
      }
    }
    return array('languages' => $data['languages'], 'lookup' => $lookup);
  }

  /**
   * Load (once) and return array('languages' => ..., 'lookup' => ...). Pass a
   * parsed registry to use it instead of the file (tests).
   */
  public static function getRegistry($data = null)
  {
    if (is_array($data)) {
      return self::prepare($data);
    }
    if (self::$cache === null) {
      $raw = @file_get_contents(self::registryPath());
      $decoded = $raw === false ? null : json_decode($raw, true);
      self::$cache = self::prepare($decoded);
    }
    return self::$cache;
  }

  public static function reset()
  {
    self::$cache = null;
  }

  /**
   * The registry code a value is exactly, or an alias of, ignoring case and
   * `_` vs `-` (zh_CN, ZH-cn and the alias zh all give zh-CN). Never drops a
   * region, so a well-formed code the registry does not list (fr-CA) gives
   * null.
   */
  public static function matchLanguageCode($value, $registry = null)
  {
    if (!is_string($value) || $value === '') {
      return null;
    }
    $reg = self::getRegistry($registry);
    $cleaned = strtolower(str_replace('_', '-', trim($value)));
    if ($cleaned === '' || !isset($reg['lookup'][$cleaned])) {
      return null;
    }
    $code = $reg['lookup'][$cleaned];
    return isset($reg['languages'][$code]) ? $code : null;
  }

  /**
   * Registry entry for a code or alias, with its canonical `code` first.
   */
  public static function getLanguage($value, $registry = null)
  {
    $code = self::matchLanguageCode($value, $registry);
    if ($code === null) {
      return null;
    }
    $reg = self::getRegistry($registry);
    return array('code' => $code) + $reg['languages'][$code];
  }

  /**
   * All entries, sorted by English name.
   * @param string $regions all (default) | featured | none: which regional
   * variants (en-GB, pt-BR...) to include
   */
  public static function listLanguages($regions = 'all', $registry = null)
  {
    $reg = self::getRegistry($registry);
    $out = array();
    foreach ($reg['languages'] as $code => $entry) {
      if (isset($entry['parent']) && $entry['parent']) {
        if ($regions === 'none') {
          continue;
        }
        if ($regions === 'featured' && (!isset($entry['featured']) || $entry['featured'] !== true)) {
          continue;
        }
      }
      $out[] = array('code' => (string) $code) + $entry;
    }
    usort($out, function ($a, $b) {
      $byName = strcasecmp($a['name'], $b['name']);
      return $byName !== 0 ? $byName : strcmp($a['code'], $b['code']);
    });
    return $out;
  }

  /**
   * code => English name for every language and regional variant.
   */
  public static function supportedLanguages()
  {
    $map = array();
    foreach (self::listLanguages('all') as $entry) {
      $map[$entry['code']] = $entry['name'];
    }
    return $map;
  }
}
