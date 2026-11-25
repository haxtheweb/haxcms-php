<?php
include_once dirname(__FILE__) . '/../../siteRoutes/SiteRouteUtils.php';
include_once dirname(__FILE__) . '/../../SsrfGuard.php';

$__vendorAutoload = dirname(__FILE__) . '/../../../vendor/autoload.php';
if (file_exists($__vendorAutoload)) {
    require_once $__vendorAutoload;
}

if (!function_exists('haxcmsReadWebsiteMetadata')) {
    /**
     * array(name => value) of a page's title, meta tags (by name or
     * property) and JSON-LD blocks (as "ld+json"), plus the url asked for.
     * Same shape as haxcms-nodejs readWebsiteMetadata.
     */
    function haxcmsReadWebsiteMetadata($html, $url)
    {
        $metadata = array();
        if (is_string($html) && $html !== '' && class_exists('DOMDocument')) {
            $dom = new DOMDocument('1.0', 'UTF-8');
            $previous = libxml_use_internal_errors(true);
            $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?><div id="wrapper">' . $html . '</div>', LIBXML_HTML_NODEFDTD | LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if ($loaded) {
                $xpath = new DOMXPath($dom);
                foreach ($xpath->query('//title|//meta|//script[@type="application/ld+json"]') as $tag) {
                    if (!($tag instanceof DOMElement)) {
                        continue;
                    }
                    $key = null;
                    $value = null;
                    $name = strtolower($tag->nodeName);
                    if ($name === 'script') {
                        $key = 'ld+json';
                        $decoded = json_decode($tag->textContent);
                        $value = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
                    } elseif ($name === 'title') {
                        $key = 'title';
                        $value = $tag->textContent;
                    } else {
                        $key = $tag->getAttribute('name') !== '' ? $tag->getAttribute('name') : ($tag->getAttribute('property') !== '' ? $tag->getAttribute('property') : null);
                        $value = $tag->getAttribute('content') !== '' ? $tag->getAttribute('content') : null;
                    }
                    if ($key !== null && $value !== null && $value !== '' && $key !== '__proto__' && $key !== 'constructor') {
                        $metadata[$key] = $value;
                    }
                }
            }
        }
        $metadata['url'] = $url;
        return $metadata;
    }
}

/**
 * POST /system/api/v1/actions/website-metadata
 * Skim the title and metadata off a web page, for link previews and
 * inserting a link by URL in the editor. Mirrors haxcms-nodejs
 * systemRoutes/v1/routes/websiteMetadata.js.
 *
 * Security (SEC-SSRF): the page is only ever read through
 * SsrfGuard::safeGuzzleRequest, so a private, loopback or metadata address
 * is refused (redirects included), and no more than 2 MB is read.
 */
return function ($context) {
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/system/api';
    $body = is_array($context->body) ? $context->body : array();
    $send = function ($payload, $status) use ($context, $apiBasePath) {
        SiteRouteUtils::sendFormattedResponse(
            $payload,
            array('statusCode' => $status, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
    };
    $q = isset($body['q']) && is_string($body['q']) ? trim($body['q']) : '';
    if ($q === '') {
        $send(array('status' => 400, 'data' => array('error' => 'missing `q` param')), 400);
        return;
    }
    if (strlen($q) > 2048 || !preg_match('/^https?:\/\//i', $q)) {
        $send(array('status' => 400, 'data' => array('error' => 'invalid q param')), 400);
        return;
    }
    $html = '';
    try {
        $client = new \GuzzleHttp\Client(array('timeout' => 10));
        $response = SsrfGuard::safeGuzzleRequest($client, 'GET', $q, array('max_bytes' => 2097152, 'http_errors' => false));
        $status = $response->getStatusCode();
        $html = ($status >= 200 && $status < 300) ? (string) $response->getBody() : '';
    } catch (\Throwable $e) {
        // unreachable or refused: answer with what we know, the url
        $html = '';
    }
    $send(array('status' => 200, 'data' => haxcmsReadWebsiteMetadata($html, $q)), 200);
};
