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
        // Security (SEC-HEADERS): mirrors SystemApiRequestContext; protocol and
        // host come from the trusted-proxy / allowedHosts aware resolvers,
        // never from a raw X-Forwarded-Proto / X-Forwarded-Host. A forged
        // forwarded host would otherwise land in every absolute URL this
        // API emits (links, Content-Location, OpenAPI servers).
        $protocol = 'http';
        if (
            isset($GLOBALS['HAXCMS']) &&
            is_object($GLOBALS['HAXCMS']) &&
            isset($GLOBALS['HAXCMS']->protocol) &&
            $GLOBALS['HAXCMS']->protocol != ''
        ) {
            // bootstrap value: config.php may force it (load balancers), and
            // it is re-finalised through resolveTrustedProtocol() when a
            // trusted-proxy / allowedHosts config exists
            $protocol = (string) $GLOBALS['HAXCMS']->protocol;
        }
        else if (
            isset($GLOBALS['HAXCMS']) &&
            is_object($GLOBALS['HAXCMS']) &&
            method_exists($GLOBALS['HAXCMS'], 'resolveTrustedProtocol')
        ) {
            $protocol = (string) $GLOBALS['HAXCMS']->resolveTrustedProtocol();
        }
        else if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] && strtolower((string) $_SERVER['HTTPS']) != 'off') {
            $protocol = 'https';
        }
        else if (isset($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] != '') {
            $protocol = (string) $_SERVER['REQUEST_SCHEME'];
        }
        $host = '';
        if (
            isset($GLOBALS['HAXCMS']) &&
            is_object($GLOBALS['HAXCMS']) &&
            method_exists($GLOBALS['HAXCMS'], 'resolveTrustedHost')
        ) {
            $host = (string) $GLOBALS['HAXCMS']->resolveTrustedHost();
        }
        else if (
            isset($GLOBALS['HAXCMS']) &&
            is_object($GLOBALS['HAXCMS']) &&
            isset($GLOBALS['HAXCMS']->domain) &&
            $GLOBALS['HAXCMS']->domain != ''
        ) {
            $host = (string) $GLOBALS['HAXCMS']->domain;
        }
        else if (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] != '') {
            $host = (string) $_SERVER['HTTP_HOST'];
        }
        if ($host == '') {
            return $apiBasePath;
        }
        return $protocol . '://' . $host . $apiBasePath;
    }
}
