<?php
include_once dirname(__FILE__) . '/../LanguageRegistry.php';

/**
 * /system/api/v1/languages, /languages/:code and /languages/:code/translations
 * (parity with haxcms-nodejs src/systemRoutes/v1/routes/languagesList.js,
 * getLanguage.js and languageTranslations.js). All read the canonical language
 * registry in system/coreConfig/languages.json.
 */
trait OperationsRouteLanguages
{
  private function languagesTokenFailure()
  {
    return array(
      '__failed' => array(
        'status' => 403,
        'message' => 'invalid request token',
      )
    );
  }

  private function languagesTokenValid()
  {
    return isset($this->params['user_token']) &&
      $GLOBALS['HAXCMS']->validateRequestToken(
        $this->params['user_token'],
        $GLOBALS['HAXCMS']->getActiveUserName()
      );
  }

  private function languagesFailed($status, $message)
  {
    return array(
      '__failed' => array(
        'status' => $status,
        'message' => $message,
      )
    );
  }

  /**
   * Folder holding the front end packages (@haxtheweb/*), each with a
   * locales/ folder of <namespace>.<locale>.json files. Overridable for
   * tests through $this->languagePackagesRoot.
   */
  private function languagePackagesRoot()
  {
    if (isset($this->languagePackagesRoot) && is_string($this->languagePackagesRoot)) {
      return $this->languagePackagesRoot;
    }
    return dirname(__FILE__) . '/../../../../../build/es6/node_modules/@haxtheweb';
  }

  /**
   * Is this a name that is safe to compare against file names? The namespace
   * filter is only ever compared, never joined onto a path.
   */
  private function languageSafeName($value)
  {
    return is_string($value) &&
      strlen($value) <= 200 &&
      preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/', $value) === 1;
  }

  /**
   * Collect the translated strings for one language across every element.
   * Namespaces ending in `.haxProperties` hold the editor (HAX schema)
   * strings; everything else is UI strings.
   */
  private function collectLanguageTranslations($locale, $namespace, $type, $root)
  {
    $result = array('count' => 0, 'namespaces' => array());
    if (!$this->languageSafeName($locale) || !is_dir($root)) {
      return $result;
    }
    $suffix = '.' . $locale . '.json';
    $packages = scandir($root);
    if ($packages === false) {
      return $result;
    }
    sort($packages);
    foreach ($packages as $pkg) {
      if ($pkg === '.' || $pkg === '..') {
        continue;
      }
      $localesDir = $root . '/' . $pkg . '/locales';
      if (!is_dir($localesDir)) {
        continue;
      }
      $files = scandir($localesDir);
      if ($files === false) {
        continue;
      }
      sort($files);
      foreach ($files as $file) {
        if (substr($file, -strlen($suffix)) !== $suffix) {
          continue;
        }
        $key = substr($file, 0, -strlen($suffix));
        if ($key === '' || $key === false) {
          continue;
        }
        $isSchema = substr($key, -strlen('.haxProperties')) === '.haxProperties';
        if ($type === 'ui' && $isSchema) {
          continue;
        }
        if ($type === 'schema' && !$isSchema) {
          continue;
        }
        $base = $isSchema ? substr($key, 0, -strlen('.haxProperties')) : $key;
        if ($namespace !== '' && $base !== $namespace) {
          continue;
        }
        $path = $localesDir . '/' . $file;
        if (!is_file($path)) {
          continue;
        }
        $raw = @file_get_contents($path);
        $strings = $raw === false ? null : json_decode($raw, true);
        // a list is not a string map
        if (!is_array($strings) || (count($strings) > 0 && array_keys($strings) === range(0, count($strings) - 1))) {
          continue;
        }
        $result['namespaces'][$key] = array(
          'namespace' => $base,
          'package' => '@haxtheweb/' . $pkg,
          'type' => $isSchema ? 'schema' : 'ui',
          'file' => $file,
          // an empty file must still encode as an object
          'strings' => count($strings) === 0 ? new stdClass() : $strings,
        );
        $result['count']++;
      }
    }
    if (count($result['namespaces']) === 0) {
      $result['namespaces'] = new stdClass();
    }
    return $result;
  }

  public function languagesList()
  {
    if (!$this->languagesTokenValid()) {
      return $this->languagesTokenFailure();
    }
    $regions = isset($this->params['regions']) ? $this->params['regions'] : 'all';
    if (!in_array($regions, array('all', 'featured', 'none'), true)) {
      $regions = 'all';
    }
    try {
      return array(
        'status' => 200,
        'data' => HAXCMSLanguageRegistry::listLanguages($regions),
      );
    }
    catch (Exception $e) {
      return $this->languagesFailed(500, 'Unable to load languages');
    }
  }

  public function getLanguage()
  {
    if (!$this->languagesTokenValid()) {
      return $this->languagesTokenFailure();
    }
    $code = isset($this->params['code']) ? $this->params['code'] : '';
    try {
      $language = HAXCMSLanguageRegistry::getLanguage($code);
      if ($language === null) {
        return $this->languagesFailed(404, 'Language not found');
      }
      return array('status' => 200, 'data' => $language);
    }
    catch (Exception $e) {
      return $this->languagesFailed(500, 'Unable to load language');
    }
  }

  public function languageTranslations()
  {
    if (!$this->languagesTokenValid()) {
      return $this->languagesTokenFailure();
    }
    $code = isset($this->params['code']) ? $this->params['code'] : '';
    try {
      $language = HAXCMSLanguageRegistry::getLanguage($code);
      if ($language === null) {
        return $this->languagesFailed(404, 'Language not found');
      }
      $namespace = isset($this->params['namespace']) && is_string($this->params['namespace'])
        ? $this->params['namespace']
        : '';
      if ($namespace !== '' && !$this->languageSafeName($namespace)) {
        return $this->languagesFailed(400, 'Invalid namespace');
      }
      $type = isset($this->params['type']) && is_string($this->params['type'])
        ? $this->params['type']
        : 'all';
      if (!in_array($type, array('all', 'ui', 'schema'), true)) {
        return $this->languagesFailed(400, 'Invalid type');
      }
      $collected = $this->collectLanguageTranslations(
        $language['locale'],
        $namespace,
        $type,
        $this->languagePackagesRoot()
      );
      return array(
        'status' => 200,
        'data' => array(
          'language' => $language,
          'locale' => $language['locale'],
          'count' => $collected['count'],
          'namespaces' => $collected['namespaces'],
        ),
      );
    }
    catch (Exception $e) {
      return $this->languagesFailed(500, 'Unable to load translations');
    }
  }
}
