<?php
include_once dirname(__FILE__) . '/../siteRoutes/SiteRouteUtils.php';
class SystemApiRequestContext
{
    public $method;
    public $requestPath;
    public $relativePath;
    public $apiBasePath;
    public $absoluteApiBasePath;
    public $routeSuffix;
    public $params;
    public $body;
    public $rawBody;
    public function __construct()
    {
        $this->method = SiteRouteUtils::getRequestMethod();
        $this->requestPath = SiteRouteUtils::getRequestPath();
        $this->relativePath = SiteRouteUtils::getRelativePathFromScript($this->requestPath);
        $this->apiBasePath = self::getApiBasePathFromRequestPath($this->requestPath);
        $this->absoluteApiBasePath = $this->getAbsoluteApiBasePath($this->apiBasePath);
        $this->routeSuffix = self::getRouteSuffixFromRelativePath($this->relativePath);
        $this->params = array();
        $this->body = array();
        $this->rawBody = array();
        $this->parseBody();
    }
    public static function create()
    {
        return new self();
    }
    public function isSystemApiRequest()
    {
        return self::isSystemApiPath($this->relativePath);
    }
    public function setRouteParams($params = array())
    {
        if (is_array($params)) {
            $this->params = $params;
        }
    }
    public function getParam($name, $fallbackValue = '')
    {
        if (!is_array($this->params) || !array_key_exists($name, $this->params)) {
            return $fallbackValue;
        }
        return $this->params[$name];
    }
    private function parseBody()
    {
        $input = file_get_contents('php://input');
        if (is_string($input) && $input !== '') {
            $decoded = json_decode($input, true);
            if (is_array($decoded)) {
                $this->body = $decoded;
            }
            $this->rawBody = $input;
        }
    }
    public static function isSystemApiPath($relativePath = '')
    {
        $path = is_string($relativePath) && $relativePath != ''
            ? $relativePath
            : SiteRouteUtils::getRelativePathFromScript();
        return (bool) preg_match('/^\/system\/api(?:\/|$)/', $path);
    }
    public static function getApiBasePathFromRequestPath($requestPath = '')
    {
        $path = is_string($requestPath) && $requestPath != ''
            ? $requestPath
            : SiteRouteUtils::getRequestPath();
        $matched = array();
        if (preg_match('/^(.*\/system\/api)(?:\/.*)?$/', $path, $matched) === 1 && isset($matched[1])) {
            return $matched[1];
        }
        return '/system/api';
    }
    public static function getRouteSuffixFromRelativePath($relativePath = '')
    {
        $path = is_string($relativePath) && $relativePath != ''
            ? $relativePath
            : SiteRouteUtils::getRelativePathFromScript();
        if (!self::isSystemApiPath($path)) {
            return null;
        }
        $suffix = preg_replace('/^\/system\/api\/?/', '', $path);
        if (!is_string($suffix)) {
            return '';
        }
        return trim($suffix, '/');
    }
    private function getAbsoluteApiBasePath($apiBasePath = '/system/api')
    {
        $protocol = 'http';
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] != '') {
            $protoParts = explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO']);
            $protocol = trim($protoParts[0]);
        }
        else if (isset($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] != '') {
            $protocol = $_SERVER['REQUEST_SCHEME'];
        }
        else if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] && strtolower($_SERVER['HTTPS']) != 'off') {
            $protocol = 'https';
        }
        else if (
            isset($GLOBALS['HAXCMS']) &&
            is_object($GLOBALS['HAXCMS']) &&
            isset($GLOBALS['HAXCMS']->protocol) &&
            $GLOBALS['HAXCMS']->protocol != ''
        ) {
            $protocol = $GLOBALS['HAXCMS']->protocol;
        }
        $host = '';
        if (isset($_SERVER['HTTP_X_FORWARDED_HOST']) && $_SERVER['HTTP_X_FORWARDED_HOST'] != '') {
            $hostParts = explode(',', $_SERVER['HTTP_X_FORWARDED_HOST']);
            $host = trim($hostParts[0]);
        }
        else if (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] != '') {
            $host = $_SERVER['HTTP_HOST'];
        }
        else if (
            isset($GLOBALS['HAXCMS']) &&
            is_object($GLOBALS['HAXCMS']) &&
            isset($GLOBALS['HAXCMS']->domain) &&
            $GLOBALS['HAXCMS']->domain != ''
        ) {
            $host = $GLOBALS['HAXCMS']->domain;
        }
        if ($host == '') {
            return $apiBasePath;
        }
        return $protocol . '://' . $host . $apiBasePath;
    }
}
