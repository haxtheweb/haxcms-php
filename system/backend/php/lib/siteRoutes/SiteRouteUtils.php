<?php
class SiteRouteUtils
{
    public static function getRequestPath()
    {
        if (isset($_SERVER['REQUEST_URI'])) {
            $requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if (is_string($requestPath) && $requestPath != '') {
                return $requestPath;
            }
        }
        return '/';
    }
    public static function getRelativePathFromScript($requestPath = '')
    {
        $path = is_string($requestPath) && $requestPath != ''
            ? $requestPath
            : self::getRequestPath();
        $scriptDirectory = '';
        if (isset($_SERVER['SCRIPT_NAME']) && is_string($_SERVER['SCRIPT_NAME'])) {
            $scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
        }
        if ($scriptDirectory == '.' || $scriptDirectory == '/') {
            $scriptDirectory = '';
        }
        $scriptDirectory = rtrim($scriptDirectory, '/');
        if ($scriptDirectory != '' && strpos($path, $scriptDirectory) === 0) {
            $path = substr($path, strlen($scriptDirectory));
        }
        if (!is_string($path) || $path == '') {
            return '/';
        }
        if (substr($path, 0, 1) != '/') {
            $path = '/' . $path;
        }
        return $path;
    }
    public static function isSiteApiPath($relativePath = '')
    {
        $path = is_string($relativePath) && $relativePath != ''
            ? $relativePath
            : self::getRelativePathFromScript();
        return (bool) preg_match('/^\/x\/api(?:\/|$)/', $path);
    }
    public static function getApiBasePathFromRequestPath($requestPath = '')
    {
        $path = is_string($requestPath) && $requestPath != ''
            ? $requestPath
            : self::getRequestPath();
        $matched = array();
        if (preg_match('/^(.*\/x\/api)(?:\/.*)?$/', $path, $matched) === 1 && isset($matched[1])) {
            return $matched[1];
        }
        return '/x/api';
    }
    public static function getRouteSuffixFromRelativePath($relativePath = '')
    {
        $path = is_string($relativePath) && $relativePath != ''
            ? $relativePath
            : self::getRelativePathFromScript();
        if (!self::isSiteApiPath($path)) {
            return null;
        }
        $suffix = preg_replace('/^\/x\/api\/?/', '', $path);
        if (!is_string($suffix)) {
            return '';
        }
        return trim($suffix, '/');
    }
    public static function getRequestMethod()
    {
        if (isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])) {
            return strtoupper($_SERVER['REQUEST_METHOD']);
        }
        return 'GET';
    }
    public static function getBearerTokenFromRequest()
    {
        $auth = null;
        if (isset($_SERVER['HTTP_AUTHORIZATION']) && is_string($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth = $_SERVER['HTTP_AUTHORIZATION'];
        }
        if ($auth === null && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) && is_string($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        if ($auth === null) {
            $envAuth = getenv('HTTP_AUTHORIZATION');
            if (is_string($envAuth) && $envAuth !== '') {
                $auth = $envAuth;
            }
        }
        if ($auth === null && function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $key => $value) {
                    if (strcasecmp($key, 'Authorization') === 0) {
                        $auth = $value;
                        break;
                    }
                }
            }
        }
        if ($auth === null || $auth === '') {
            return null;
        }
        $parts = explode(' ', trim($auth));
        if (count($parts) === 2 && strcasecmp($parts[0], 'Bearer') === 0) {
            $token = trim($parts[1]);
            return $token !== '' ? $token : null;
        }
        return null;
    }
    public static function validateSiteToken($siteName, $token)
    {
        if (
            isset($GLOBALS['HAXCMS']) &&
            is_object($GLOBALS['HAXCMS']) &&
            method_exists($GLOBALS['HAXCMS'], 'validateRequestToken')
        ) {
            $userName = $GLOBALS['HAXCMS']->getRequestTokenUserName();
            $tokenValue = $userName . ':' . (string) $siteName;
            return $GLOBALS['HAXCMS']->validateRequestToken($token, $tokenValue);
        }
        return false;
    }
    public static function getQueryValue($key, $fallbackValue = '')
    {
        if (!isset($_GET) || !is_array($_GET) || !array_key_exists($key, $_GET)) {
            return $fallbackValue;
        }
        return $_GET[$key];
    }
    public static function getCsvQuery($key)
    {
        $value = self::getQueryValue($key, '');
        if (is_array($value)) {
            $values = array();
            foreach ($value as $item) {
                $part = trim((string) $item);
                if ($part != '') {
                    $values[] = $part;
                }
            }
            return $values;
        }
        if (!is_string($value) || trim($value) == '') {
            return array();
        }
        $parts = explode(',', $value);
        $output = array();
        foreach ($parts as $part) {
            $cleanPart = trim((string) $part);
            if ($cleanPart != '') {
                $output[] = $cleanPart;
            }
        }
        return $output;
    }
    public static function getNumberQuery($key, $fallbackValue, $min = null, $max = null)
    {
        $value = self::getQueryValue($key, $fallbackValue);
        if (!is_numeric($value)) {
            return $fallbackValue;
        }
        $output = intval($value);
        if (!is_null($min) && $output < $min) {
            $output = $min;
        }
        if (!is_null($max) && $output > $max) {
            $output = $max;
        }
        return $output;
    }
    public static function getBooleanQuery($key, $fallbackValue = null)
    {
        if (!isset($_GET) || !is_array($_GET) || !array_key_exists($key, $_GET)) {
            return $fallbackValue;
        }
        $value = $_GET[$key];
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return intval($value) !== 0;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (
                $normalized == '1' ||
                $normalized == 'true' ||
                $normalized == 'yes' ||
                $normalized == 'on'
            ) {
                return true;
            }
            if (
                $normalized == '0' ||
                $normalized == 'false' ||
                $normalized == 'no' ||
                $normalized == 'off'
            ) {
                return false;
            }
        }
        return $fallbackValue;
    }
    public static function normalizeTagList($tags)
    {
        if (is_array($tags)) {
            $output = array();
            foreach ($tags as $tag) {
                $cleanTag = trim((string) $tag);
                if ($cleanTag != '') {
                    $output[] = $cleanTag;
                }
            }
            return $output;
        }
        if (is_string($tags)) {
            $parts = explode(',', $tags);
            $output = array();
            foreach ($parts as $part) {
                $cleanTag = trim($part);
                if ($cleanTag != '') {
                    $output[] = $cleanTag;
                }
            }
            return $output;
        }
        return array();
    }
    public static function toIsoDateFromUnixTime($value)
    {
        if (!is_numeric($value)) {
            return null;
        }
        $unixTime = intval($value);
        if ($unixTime <= 0) {
            return null;
        }
        return gmdate('c', $unixTime);
    }
    public static function getVersion()
    {
        if (
            isset($GLOBALS['HAXCMS']) &&
            is_object($GLOBALS['HAXCMS']) &&
            method_exists($GLOBALS['HAXCMS'], 'getHAXCMSVersion')
        ) {
            return $GLOBALS['HAXCMS']->getHAXCMSVersion();
        }
        return '0.0.0';
    }
    public static function normalizePathForResponse($value = '')
    {
        return str_replace('\\', '/', (string) $value);
    }
    public static function normalizeBasePath($basePath = '/')
    {
        $output = trim((string) $basePath);
        if ($output == '') {
            $output = '/';
        }
        if (substr($output, 0, 1) != '/') {
            $output = '/' . $output;
        }
        if (substr($output, -1) != '/') {
            $output .= '/';
        }
        return $output;
    }
    public static function getSiteBasePath($site)
    {
        if (
            !isset($site) ||
            !isset($site->manifest) ||
            !isset($site->manifest->metadata) ||
            !isset($site->manifest->metadata->site) ||
            !isset($site->manifest->metadata->site->name)
        ) {
            return '/';
        }
        $siteName = (string) $site->manifest->metadata->site->name;
        $basePath = '/';
        $sitesDirectory = '_sites';
        if (
            isset($GLOBALS['HAXCMS']) &&
            is_object($GLOBALS['HAXCMS']) &&
            isset($GLOBALS['HAXCMS']->basePath)
        ) {
            $basePath = self::normalizeBasePath($GLOBALS['HAXCMS']->basePath);
            if (isset($GLOBALS['HAXCMS']->sitesDirectory) && $GLOBALS['HAXCMS']->sitesDirectory != '') {
                $sitesDirectory = $GLOBALS['HAXCMS']->sitesDirectory;
            }
        }
        if (
            isset($site->basePath) &&
            is_string($site->basePath) &&
            strpos($site->basePath, '/' . $sitesDirectory . '/') !== false
        ) {
            return $basePath . $sitesDirectory . '/' . $siteName . '/';
        }
        return $basePath . $siteName . '/';
    }
    public static function getSiteLanguage($site)
    {
        if (
            isset($site) &&
            isset($site->manifest) &&
            isset($site->manifest->metadata) &&
            isset($site->manifest->metadata->site) &&
            isset($site->manifest->metadata->site->settings) &&
            isset($site->manifest->metadata->site->settings->lang) &&
            $site->manifest->metadata->site->settings->lang != ''
        ) {
            return (string) $site->manifest->metadata->site->settings->lang;
        }
        if (isset($site) && isset($site->language) && $site->language != '') {
            return (string) $site->language;
        }
        return 'en';
    }
    public static function getSiteTheme($site)
    {
        if (
            isset($site) &&
            isset($site->manifest) &&
            isset($site->manifest->metadata) &&
            isset($site->manifest->metadata->theme) &&
            isset($site->manifest->metadata->theme->element) &&
            $site->manifest->metadata->theme->element != ''
        ) {
            return (string) $site->manifest->metadata->theme->element;
        }
        return null;
    }
    public static function getSiteDirectory($site)
    {
        if (isset($site) && isset($site->siteDirectory) && is_string($site->siteDirectory) && $site->siteDirectory != '') {
            return rtrim($site->siteDirectory, '/');
        }
        if (
            isset($site) &&
            isset($site->directory) &&
            isset($site->manifest) &&
            isset($site->manifest->metadata) &&
            isset($site->manifest->metadata->site) &&
            isset($site->manifest->metadata->site->name)
        ) {
            return rtrim((string) $site->directory, '/') . '/' . (string) $site->manifest->metadata->site->name;
        }
        return '';
    }
    public static function normalizeManifestItems($site)
    {
        if (!isset($site) || !isset($site->manifest) || !isset($site->manifest->items)) {
            return array();
        }
        if (is_array($site->manifest->items)) {
            $items = array();
            foreach ($site->manifest->items as $item) {
                if ($item) {
                    $items[] = $item;
                }
            }
            return $items;
        }
        if (is_object($site->manifest->items)) {
            $items = array();
            foreach ($site->manifest->items as $item) {
                if ($item) {
                    $items[] = $item;
                }
            }
            return $items;
        }
        return array();
    }
    public static function getOrderedItems($site)
    {
        $items = self::normalizeManifestItems($site);
        if (
            isset($site) &&
            isset($site->manifest) &&
            method_exists($site->manifest, 'orderTree')
        ) {
            try {
                $ordered = $site->manifest->orderTree($items);
                if (is_array($ordered)) {
                    return $ordered;
                }
            }
            catch (Exception $e) {
            }
        }
        return $items;
    }
    public static function findItemByIdOrSlug($site, $idOrSlug = '')
    {
        $value = urldecode(trim((string) $idOrSlug));
        if ($value == '' || !isset($site) || !isset($site->manifest)) {
            return null;
        }
        if (method_exists($site->manifest, 'getItemById')) {
            $byId = $site->manifest->getItemById($value);
            if ($byId) {
                return $byId;
            }
        }
        if (method_exists($site->manifest, 'getItemByProperty')) {
            $bySlug = $site->manifest->getItemByProperty('slug', $value);
            if ($bySlug) {
                return $bySlug;
            }
        }
        foreach (self::normalizeManifestItems($site) as $item) {
            if (
                (isset($item->id) && (string) $item->id === $value) ||
                (isset($item->slug) && (string) $item->slug === $value)
            ) {
                return $item;
            }
        }
        return null;
    }
    public static function getItemContent($site, $item)
    {
        if (!isset($site) || !isset($item) || !method_exists($site, 'getPageContent')) {
            return '';
        }
        if (method_exists($site, 'loadNode') && isset($item->id)) {
            $page = $site->loadNode($item->id);
            if ($page) {
                $content = $site->getPageContent($page);
                if (is_string($content)) {
                    return $content;
                }
            }
        }
        $content = $site->getPageContent($item);
        if (is_string($content)) {
            return $content;
        }
        return '';
    }
    public static function normalizeSortTokens($sortValue = '', $defaultSort = '')
    {
        $source = trim((string) $sortValue);
        if ($source == '' && $defaultSort != '') {
            $source = trim((string) $defaultSort);
        }
        if ($source == '') {
            return array();
        }
        $parts = explode(',', $source);
        $tokens = array();
        foreach ($parts as $part) {
            $clean = trim((string) $part);
            if ($clean == '') {
                continue;
            }
            $desc = false;
            if (substr($clean, 0, 1) == '-') {
                $desc = true;
                $clean = substr($clean, 1);
            }
            if ($clean != '') {
                $tokens[] = array(
                    'key' => $clean,
                    'desc' => $desc,
                );
            }
        }
        return $tokens;
    }
    public static function getValueByPath($record, $pathExpression = '')
    {
        $parts = explode('.', (string) $pathExpression);
        $parts = array_values(array_filter($parts, function ($part) {
            return $part !== '';
        }));
        if (count($parts) == 0) {
            return null;
        }
        $active = $record;
        foreach ($parts as $part) {
            if (is_array($active) && array_key_exists($part, $active)) {
                $active = $active[$part];
                continue;
            }
            if (is_object($active) && isset($active->{$part})) {
                $active = $active->{$part};
                continue;
            }
            return null;
        }
        return $active;
    }
    public static function comparePrimitiveValues($a, $b, $desc = false)
    {
        $aUndefined = is_null($a);
        $bUndefined = is_null($b);
        if ($aUndefined && $bUndefined) {
            return 0;
        }
        if ($aUndefined) {
            return $desc ? 1 : -1;
        }
        if ($bUndefined) {
            return $desc ? -1 : 1;
        }
        if (is_numeric($a) && is_numeric($b)) {
            $aNum = floatval($a);
            $bNum = floatval($b);
            if ($aNum == $bNum) {
                return 0;
            }
            if ($desc) {
                return $aNum < $bNum ? 1 : -1;
            }
            return $aNum < $bNum ? -1 : 1;
        }
        $aValue = strtolower((string) $a);
        $bValue = strtolower((string) $b);
        if ($aValue == $bValue) {
            return 0;
        }
        if ($desc) {
            return $aValue < $bValue ? 1 : -1;
        }
        return $aValue < $bValue ? -1 : 1;
    }
    public static function sortRecords($records = array(), $sortValue = '', $defaultSort = '')
    {
        $tokens = self::normalizeSortTokens($sortValue, $defaultSort);
        if (count($tokens) == 0) {
            return $records;
        }
        usort($records, function ($a, $b) use ($tokens) {
            foreach ($tokens as $token) {
                $aValue = SiteRouteUtils::getValueByPath($a, $token['key']);
                $bValue = SiteRouteUtils::getValueByPath($b, $token['key']);
                if (is_null($aValue) && strpos($token['key'], '.') === false) {
                    $aValue = SiteRouteUtils::getValueByPath($a, 'metadata.' . $token['key']);
                }
                if (is_null($bValue) && strpos($token['key'], '.') === false) {
                    $bValue = SiteRouteUtils::getValueByPath($b, 'metadata.' . $token['key']);
                }
                $comparison = SiteRouteUtils::comparePrimitiveValues($aValue, $bValue, $token['desc']);
                if ($comparison !== 0) {
                    return $comparison;
                }
            }
            return 0;
        });
        return $records;
    }
    public static function paginateRecords($records = array(), $defaultLimit = 25, $maxLimit = 200)
    {
        $limit = self::getNumberQuery('page.limit', $defaultLimit, 1, $maxLimit);
        $offset = self::getNumberQuery('page.offset', 0, 0);
        return array(
            'page' => array(
                'limit' => $limit,
                'offset' => $offset,
                'total' => count($records),
            ),
            'records' => array_slice($records, $offset, $limit),
        );
    }
    public static function projectRecord($record, $fields = array())
    {
        if (!is_array($fields) || count($fields) == 0) {
            return $record;
        }
        $output = array();
        foreach ($fields as $field) {
            $value = self::getValueByPath($record, $field);
            if (!is_null($value)) {
                self::setValueByPath($output, $field, $value);
            }
        }
        return $output;
    }
    public static function projectCollection($records = array(), $fields = array())
    {
        if (!is_array($fields) || count($fields) == 0) {
            return $records;
        }
        $output = array();
        foreach ($records as $record) {
            $output[] = self::projectRecord($record, $fields);
        }
        return $output;
    }
    public static function setValueByPath(&$target, $pathExpression, $value)
    {
        $parts = explode('.', (string) $pathExpression);
        $parts = array_values(array_filter($parts, function ($part) {
            return $part !== '';
        }));
        if (count($parts) == 0) {
            return;
        }
        $active = &$target;
        for ($i = 0; $i < count($parts) - 1; $i++) {
            $part = $parts[$i];
            if (!isset($active[$part]) || !is_array($active[$part])) {
                $active[$part] = array();
            }
            $active = &$active[$part];
        }
        $active[$parts[count($parts) - 1]] = $value;
    }
    public static function detectResponseFormat($allowedFormats = array('json'), $defaultFormat = 'json', $routeSuffix = '')
    {
        $normalizedAllowed = self::normalizeAllowedFormats($allowedFormats);
        $suffix = strtolower((string) $routeSuffix);
        if (preg_match('/\.yaml$/', $suffix)) {
            return 'yaml';
        }
        if (preg_match('/\.json$/', $suffix)) {
            return 'json';
        }
        $queryFormat = self::normalizeFormatValue(self::getQueryValue('format', ''));
        if ($queryFormat != '' && in_array($queryFormat, $normalizedAllowed, true)) {
            return $queryFormat;
        }
        $normalizedDefault = self::normalizeFormatValue($defaultFormat);
        if ($normalizedDefault != '' && in_array($normalizedDefault, $normalizedAllowed, true)) {
            return $normalizedDefault;
        }
        return $normalizedAllowed[0];
    }
    public static function normalizeAllowedFormats($allowedFormats = array('json'))
    {
        $normalized = array();
        foreach ($allowedFormats as $format) {
            $cleanFormat = self::normalizeFormatValue($format);
            if ($cleanFormat != '' && !in_array($cleanFormat, $normalized, true)) {
                $normalized[] = $cleanFormat;
            }
        }
        if (count($normalized) == 0) {
            $normalized[] = 'json';
        }
        return $normalized;
    }
    public static function normalizeFormatValue($value = '')
    {
        $normalized = strtolower(trim((string) $value));
        $aliases = array(
            'json' => 'json',
            'application/json' => 'json',
            'application/vnd.oai.openapi+json' => 'json',
            'application/vnd.oai.openapi+json;version=3.0' => 'json',
            'md' => 'md',
            'markdown' => 'md',
            'text/markdown' => 'md',
            'yaml' => 'yaml',
            'yml' => 'yaml',
            'application/yaml' => 'yaml',
            'application/x-yaml' => 'yaml',
            'text/yaml' => 'yaml',
            'xml' => 'xml',
            'application/xml' => 'xml',
            'text/xml' => 'xml',
            'html' => 'html',
            'text/html' => 'html',
        );
        if (array_key_exists($normalized, $aliases)) {
            return $aliases[$normalized];
        }
        return '';
    }
    public static function getFormatMimeType($format = 'json')
    {
        $map = array(
            'json' => 'application/json; charset=utf-8',
            'md' => 'text/markdown; charset=utf-8',
            'yaml' => 'application/yaml; charset=utf-8',
            'xml' => 'application/xml; charset=utf-8',
            'html' => 'text/html; charset=utf-8',
        );
        if (array_key_exists($format, $map)) {
            return $map[$format];
        }
        return 'text/plain; charset=utf-8';
    }
    public static function sendFormattedResponse(
        $data,
        $options = array(),
        $routeSuffix = '',
        $apiBasePath = '/x/api'
    ) {
        $statusCode = isset($options['statusCode']) ? intval($options['statusCode']) : 200;
        $allowedFormats = isset($options['allowedFormats']) && is_array($options['allowedFormats'])
            ? $options['allowedFormats']
            : array('json');
        $defaultFormat = isset($options['defaultFormat']) ? $options['defaultFormat'] : 'json';
        $envelope = !(isset($options['envelope']) && $options['envelope'] === false);
        $rawByFormat = isset($options['rawByFormat']) && is_array($options['rawByFormat'])
            ? $options['rawByFormat']
            : array();
        $format = self::detectResponseFormat($allowedFormats, $defaultFormat, $routeSuffix);
        // If the data is already an enveloped v1 response, pass it through directly
        if ($envelope && is_array($data) && isset($data['status']) && isset($data['data']) && !isset($data['__failed']) && !isset($data['__noencode'])) {
            $payload = $data;
        }
        else {
            $payload = $envelope ? array('status' => $statusCode, 'data' => $data) : $data;
        }
        $resourcePath = $routeSuffix == '' ? $apiBasePath : $apiBasePath . '/' . $routeSuffix;
        self::setRepresentationHeaders($resourcePath, $allowedFormats, $format);
        http_response_code($statusCode);
        header('Content-Type: ' . self::getFormatMimeType($format));
        if (array_key_exists($format, $rawByFormat)) {
            $rawValue = $rawByFormat[$format];
            if (is_string($rawValue)) {
                print $rawValue;
            }
            else {
                print self::serializePayload($rawValue, $format);
            }
            return;
        }
        print self::serializePayload($payload, $format);
    }
    public static function setRepresentationHeaders($resourcePath, $allowedFormats, $selectedFormat)
    {
        $normalizedAllowed = self::normalizeAllowedFormats($allowedFormats);
        header('Vary: Accept');
        header('Content-Location: ' . self::getRepresentationPath($resourcePath, $selectedFormat));
        $alternates = array();
        foreach ($normalizedAllowed as $format) {
            $alternates[] =
                '<' . self::getRepresentationPath($resourcePath, $format) . '>; rel="alternate"; type="' .
                str_replace('; charset=utf-8', '', self::getFormatMimeType($format)) . '"';
        }
        if (count($alternates) > 0) {
            header('Link: ' . implode(', ', $alternates));
        }
    }
    public static function getRepresentationPath($resourcePath, $format)
    {
        $cleanPath = preg_replace('/\.(json|md|markdown|yaml|yml|xml|html)$/i', '', (string) $resourcePath);
        $ext = $format == 'yaml' ? 'yaml' : $format;
        return $cleanPath . '.' . $ext;
    }
    public static function serializePayload($payload, $format = 'json')
    {
        if ($format == 'json') {
            return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if ($format == 'yaml') {
            return self::toYaml($payload);
        }
        if ($format == 'xml') {
            return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" . self::toXmlNode('response', $payload);
        }
        if ($format == 'md') {
            if (is_string($payload)) {
                return $payload;
            }
            return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if ($format == 'html') {
            if (is_string($payload)) {
                return $payload;
            }
            return '<pre>' . self::escapeHtmlValue(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre>';
        }
        return (string) $payload;
    }
    public static function escapeHtmlValue($value = '')
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    public static function escapeXmlValue($value = '')
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }
    public static function getSafeXmlTag($tag = '')
    {
        $normalized = preg_replace('/[^a-zA-Z0-9_-]/', '-', (string) $tag);
        if ($normalized == '' || preg_match('/^[0-9]/', $normalized)) {
            $normalized = 'item-' . $normalized;
        }
        return $normalized;
    }
    public static function toXmlNode($name, $value)
    {
        $tag = self::getSafeXmlTag($name);
        if (is_null($value)) {
            return '<' . $tag . '></' . $tag . '>';
        }
        if (is_array($value)) {
            $isAssociative = array_keys($value) !== range(0, count($value) - 1);
            $children = '';
            foreach ($value as $key => $item) {
                $childName = $isAssociative ? $key : 'item';
                $children .= self::toXmlNode($childName, $item);
            }
            return '<' . $tag . '>' . $children . '</' . $tag . '>';
        }
        if (is_object($value)) {
            $children = '';
            foreach ($value as $key => $item) {
                $children .= self::toXmlNode($key, $item);
            }
            return '<' . $tag . '>' . $children . '</' . $tag . '>';
        }
        return '<' . $tag . '>' . self::escapeXmlValue($value) . '</' . $tag . '>';
    }
    public static function toYaml($value)
    {
        if (class_exists('\\Symfony\\Component\\Yaml\\Yaml')) {
            return \Symfony\Component\Yaml\Yaml::dump($value, 8, 2, \Symfony\Component\Yaml\Yaml::DUMP_OBJECT_AS_MAP);
        }
        return self::simpleYamlSerialize($value, 0);
    }
    public static function parseYaml($yamlText = '')
    {
        if (class_exists('\\Symfony\\Component\\Yaml\\Yaml')) {
            return \Symfony\Component\Yaml\Yaml::parse($yamlText);
        }
        return null;
    }
    public static function getItemLookupValue($item)
    {
        if (isset($item) && isset($item->slug) && $item->slug != '') {
            return (string) $item->slug;
        }
        if (isset($item) && isset($item->id) && $item->id != '') {
            return (string) $item->id;
        }
        return '';
    }
    public static function itemToSummary($item, $apiBasePath = '/x/api')
    {
        $metadata = isset($item->metadata) && is_object($item->metadata)
            ? (array) $item->metadata
            : array();
        $lookupValue = self::getItemLookupValue($item);
        $parentLookupValue = isset($item->parent) && $item->parent != ''
            ? (string) $item->parent
            : '';
        return array(
            'id' => isset($item->id) ? $item->id : null,
            'title' => isset($item->title) ? $item->title : '',
            'slug' => isset($item->slug) ? $item->slug : '',
            'parent' => isset($item->parent) ? $item->parent : null,
            'indent' => isset($item->indent) ? intval($item->indent) : 0,
            'order' => isset($item->order) ? intval($item->order) : 0,
            'location' => isset($item->location) ? $item->location : '',
            'description' => isset($item->description) ? $item->description : '',
            'metadata' => $metadata,
            'region' => array_key_exists('region', $metadata) ? (string) $metadata['region'] : null,
            'tags' => array_key_exists('tags', $metadata) ? self::normalizeTagList($metadata['tags']) : array(),
            'published' => !array_key_exists('published', $metadata) || $metadata['published'] !== false,
            'links' => array(
                'self' => $apiBasePath . '/v1/items/' . rawurlencode($lookupValue),
                'content' => $apiBasePath . '/v1/content/' . rawurlencode($lookupValue),
                'parent' => $parentLookupValue != ''
                    ? $apiBasePath . '/v1/items/' . rawurlencode($parentLookupValue)
                    : null,
                'children' => isset($item->id)
                    ? $apiBasePath . '/v1/items?filter.parent=' . rawurlencode((string) $item->id)
                    : null,
            ),
            'related' => array(
                array(
                    'rel' => 'entity',
                    'type' => 'item',
                    'href' => $apiBasePath . '/v1/entities#item',
                ),
                array(
                    'rel' => 'schema',
                    'type' => 'jsonOutlineSchema',
                    'href' => $apiBasePath . '/v1/schemas?filter.kind=jsonOutlineSchema',
                ),
                array(
                    'rel' => 'schema',
                    'type' => 'jsonOutlineSchemaItem',
                    'href' => $apiBasePath . '/v1/schemas?filter.kind=jsonOutlineSchemaItem',
                ),
            ),
        );
    }
    public static function contentToRecord($item, $body = '')
    {
        return array(
            'id' => isset($item->id) ? $item->id : null,
            'slug' => isset($item->slug) ? $item->slug : '',
            'title' => isset($item->title) ? $item->title : '',
            'format' => 'html',
            'mode' => 'bundle',
            'body' => is_string($body) ? $body : '',
        );
    }
    public static function encodeSlugPath($slug = '')
    {
        $parts = explode('/', (string) $slug);
        $output = array();
        foreach ($parts as $part) {
            $cleanPart = trim((string) $part);
            if ($cleanPart != '') {
                $output[] = rawurlencode($cleanPart);
            }
        }
        return implode('/', $output);
    }
    public static function buildCanonicalPagePath($basePath = '/', $slug = '')
    {
        $normalizedBasePath = rtrim(self::normalizeBasePath($basePath), '/');
        $encodedSlugPath = self::encodeSlugPath($slug);
        if ($encodedSlugPath == '') {
            return $normalizedBasePath == '' ? '/' : $normalizedBasePath;
        }
        if ($normalizedBasePath == '' || $normalizedBasePath == '/') {
            return '/' . $encodedSlugPath;
        }
        return $normalizedBasePath . '/' . $encodedSlugPath;
    }
    public static function applyItemFilters($items = array(), $site = null)
    {
        $output = $items;
        $filterParent = (string) self::getQueryValue('filter.parent', '');
        $filterAncestor = (string) self::getQueryValue('filter.ancestor', '');
        $filterDepth = self::getNumberQuery('filter.depth', null, 0);
        $filterTags = array_map('strtolower', self::getCsvQuery('filter.tags'));
        $filterPublished = self::getBooleanQuery('filter.published', null);
        $filterPageType = trim((string) self::getQueryValue('filter.pageType', ''));
        $filterRegion = trim((string) self::getQueryValue('filter.region', ''));
        if ($filterAncestor != '' && isset($site) && isset($site->manifest) && method_exists($site->manifest, 'findBranch')) {
            try {
                $branch = $site->manifest->findBranch($filterAncestor);
                if (is_array($branch)) {
                    $branchIds = array();
                    foreach ($branch as $branchItem) {
                        if (isset($branchItem->id)) {
                            $branchIds[(string) $branchItem->id] = true;
                        }
                    }
                    $output = array_values(array_filter($output, function ($item) use ($branchIds) {
                        return isset($item->id) && array_key_exists((string) $item->id, $branchIds);
                    }));
                }
            }
            catch (Exception $e) {
            }
        }
        if ($filterParent != '') {
            $output = array_values(array_filter($output, function ($item) use ($filterParent) {
                $itemParent = isset($item->parent) ? (string) $item->parent : '';
                return $itemParent === (string) $filterParent;
            }));
        }
        if (
            !is_null($filterDepth) &&
            $filterAncestor != '' &&
            isset($site) &&
            isset($site->manifest)
        ) {
            $ancestorItem = self::findItemByIdOrSlug($site, $filterAncestor);
            if ($ancestorItem && isset($ancestorItem->indent)) {
                $baseIndent = intval($ancestorItem->indent);
                $output = array_values(array_filter($output, function ($item) use ($baseIndent, $filterDepth) {
                    $indent = isset($item->indent) ? intval($item->indent) : 0;
                    return $indent <= $baseIndent + intval($filterDepth);
                }));
            }
        }
        if (count($filterTags) > 0) {
            $output = array_values(array_filter($output, function ($item) use ($filterTags) {
                $itemTags = array();
                if (isset($item->metadata) && is_object($item->metadata) && isset($item->metadata->tags)) {
                    $itemTags = array_map('strtolower', SiteRouteUtils::normalizeTagList($item->metadata->tags));
                }
                foreach ($filterTags as $tag) {
                    if (in_array($tag, $itemTags, true)) {
                        return true;
                    }
                }
                return false;
            }));
        }
        if (!is_null($filterPublished)) {
            $output = array_values(array_filter($output, function ($item) use ($filterPublished) {
                $published = true;
                if (
                    isset($item->metadata) &&
                    is_object($item->metadata) &&
                    isset($item->metadata->published) &&
                    $item->metadata->published === false
                ) {
                    $published = false;
                }
                return $published === $filterPublished;
            }));
        }
        if ($filterPageType != '') {
            $output = array_values(array_filter($output, function ($item) use ($filterPageType) {
                if (!isset($item->metadata) || !is_object($item->metadata) || !isset($item->metadata->pageType)) {
                    return false;
                }
                return (string) $item->metadata->pageType === $filterPageType;
            }));
        }
        if ($filterRegion != '') {
            $output = array_values(array_filter($output, function ($item) use ($filterRegion) {
                if (!isset($item->metadata) || !is_object($item->metadata) || !isset($item->metadata->region)) {
                    return false;
                }
                return (string) $item->metadata->region === $filterRegion;
            }));
        }
        return $output;
    }
    public static function collectSiteFiles($site, $siteFilePath, $search = '')
    {
        $files = array();
        if (!is_dir($siteFilePath)) {
            return $files;
        }
        $searchValue = strtolower(trim((string) $search));
        $ignoredFiles = array('.', '..', '.gitkeep', '.DS_Store', '._.DS_Store', '.htaccess', '._htaccess');
        $directories = array($siteFilePath);
        while (count($directories) > 0) {
            $activeDirectory = array_pop($directories);
            $entries = @scandir($activeDirectory);
            if (!is_array($entries)) {
                $entries = array();
            }
            foreach ($entries as $entryName) {
                if (in_array($entryName, $ignoredFiles, true)) {
                    continue;
                }
                $absoluteEntryPath = $activeDirectory . '/' . $entryName;
                if (is_link($absoluteEntryPath)) {
                    continue;
                }
                if (is_dir($absoluteEntryPath)) {
                    $relativeDirectoryPath = ltrim(self::normalizePathForResponse(str_replace($siteFilePath, '', $absoluteEntryPath)), '/');
                    if (
                        $relativeDirectoryPath == 'haxcms-managed' ||
                        strpos($relativeDirectoryPath, 'haxcms-managed/') === 0
                    ) {
                        continue;
                    }
                    $directories[] = $absoluteEntryPath;
                    continue;
                }
                if (!is_file($absoluteEntryPath)) {
                    continue;
                }
                $relativePath = ltrim(self::normalizePathForResponse(str_replace($siteFilePath, '', $absoluteEntryPath)), '/');
                if (
                    $relativePath == '' ||
                    $relativePath == 'haxcms-managed' ||
                    strpos($relativePath, 'haxcms-managed/') === 0
                ) {
                    continue;
                }
                if (
                    $searchValue != '' &&
                    strpos(strtolower($relativePath), $searchValue) === false &&
                    strpos(strtolower($entryName), $searchValue) === false
                ) {
                    continue;
                }
                $files[] = array(
                    'relativePath' => $relativePath,
                    'absolutePath' => $absoluteEntryPath,
                    'stats' => @stat($absoluteEntryPath),
                );
            }
        }
        usort($files, function ($a, $b) {
            return strcmp($a['relativePath'], $b['relativePath']);
        });
        return $files;
    }
    public static function extractCustomElementTagsFromHtml($html = '')
    {
        $usage = array();
        $source = (string) $html;
        if ($source == '') {
            return $usage;
        }
        if (preg_match_all('/<([a-z][a-z0-9-]*-[a-z0-9-]*)\b/i', $source, $matches) === false) {
            return $usage;
        }
        if (!isset($matches[1]) || !is_array($matches[1])) {
            return $usage;
        }
        foreach ($matches[1] as $tag) {
            $cleanTag = strtolower((string) $tag);
            if ($cleanTag == '') {
                continue;
            }
            if (!array_key_exists($cleanTag, $usage)) {
                $usage[$cleanTag] = 0;
            }
            $usage[$cleanTag] += 1;
        }
        return $usage;
    }
    public static function collectCustomElementUsage($site, $items = array())
    {
        $usage = array();
        foreach ($items as $item) {
            $html = self::getItemContent($site, $item);
            $matches = self::extractCustomElementTagsFromHtml($html);
            foreach ($matches as $key => $count) {
                if (!array_key_exists($key, $usage)) {
                    $usage[$key] = 0;
                }
                $usage[$key] += intval($count);
            }
        }
        return $usage;
    }
    private static function simpleYamlSerialize($value, $level = 0)
    {
        $indent = str_repeat('  ', $level);
        if (is_null($value)) {
            return "null\n";
        }
        if (is_bool($value)) {
            return ($value ? 'true' : 'false') . "\n";
        }
        if (is_numeric($value)) {
            return (string) $value . "\n";
        }
        if (is_string($value)) {
            $escaped = str_replace('"', '\"', $value);
            return '"' . $escaped . "\"\n";
        }
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (is_array($value)) {
            $isAssociative = array_keys($value) !== range(0, count($value) - 1);
            $output = '';
            if ($isAssociative) {
                foreach ($value as $key => $item) {
                    if (is_array($item) || is_object($item)) {
                        $output .= $indent . $key . ":\n" . self::simpleYamlSerialize($item, $level + 1);
                    }
                    else {
                        $output .= $indent . $key . ': ' . rtrim(self::simpleYamlSerialize($item, 0)) . "\n";
                    }
                }
            }
            else {
                foreach ($value as $item) {
                    if (is_array($item) || is_object($item)) {
                        $output .= $indent . "-\n" . self::simpleYamlSerialize($item, $level + 1);
                    }
                    else {
                        $output .= $indent . '- ' . rtrim(self::simpleYamlSerialize($item, 0)) . "\n";
                    }
                }
            }
            return $output;
        }
        return "\n";
    }
}
