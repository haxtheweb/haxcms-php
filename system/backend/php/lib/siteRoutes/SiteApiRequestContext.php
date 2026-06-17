<?php
include_once dirname(__FILE__) . '/SiteRouteUtils.php';
class SiteApiRequestContext
{
    public $site;
    public $method;
    public $requestPath;
    public $relativePath;
    public $apiBasePath;
    public $absoluteApiBasePath;
    public $routeSuffix;
    public $params;
    public $auth;
    public function __construct($site)
    {
        $this->site = $site;
        $this->method = SiteRouteUtils::getRequestMethod();
        $this->requestPath = SiteRouteUtils::getRequestPath();
        $this->relativePath = SiteRouteUtils::getRelativePathFromScript($this->requestPath);
        $this->apiBasePath = SiteRouteUtils::getApiBasePathFromRequestPath($this->requestPath);
        $this->absoluteApiBasePath = $this->getAbsoluteApiBasePath($this->apiBasePath);
        $this->routeSuffix = SiteRouteUtils::getRouteSuffixFromRelativePath($this->relativePath);
        $this->params = array();
        $this->auth = array(
            'authenticated' => false,
            'userName' => '',
        );
    }
    public static function fromSite($site)
    {
        return new self($site);
    }
    public function isSiteApiRequest()
    {
        return SiteRouteUtils::isSiteApiPath($this->relativePath);
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
    public function getBody()
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return array();
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        return array();
    }
    public function getRawBody()
    {
        $raw = file_get_contents('php://input');
        return $raw === false ? '' : $raw;
    }
    public function getHeader($name)
    {
        $normalizedName = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$normalizedName])) {
            return $_SERVER[$normalizedName];
        }
        $headers = function_exists('getallheaders') ? getallheaders() : array();
        if (is_array($headers)) {
            foreach ($headers as $key => $value) {
                if (strcasecmp($key, $name) === 0) {
                    return $value;
                }
            }
        }
        return null;
    }
    private function getAbsoluteApiBasePath($apiBasePath = '/x/api')
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
