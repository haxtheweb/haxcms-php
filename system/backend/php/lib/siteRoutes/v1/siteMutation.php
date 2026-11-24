<?php
include_once dirname(__FILE__) . '/../../Operations.php';
return function ($context) {
    $body = $context->getBody();
    if (!is_array($body)) {
        $body = array();
    }
    unset($body['jwt']);
    unset($body['user_token']);
    unset($body['site_token']);
    $siteName = '';
    if (
        isset($context->site) &&
        isset($context->site->manifest) &&
        isset($context->site->manifest->metadata) &&
        isset($context->site->manifest->metadata->site) &&
        isset($context->site->manifest->metadata->site->name)
    ) {
        $siteName = (string) $context->site->manifest->metadata->site->name;
    }
    if (!isset($body['site']) || !is_array($body['site'])) {
        $body['site'] = array();
    }
    // Security (SEC-ROUTE): force the site name to the resolved site so a client
    // cannot target a foreign site (IDOR defense-in-depth).
    $body['site']['name'] = $siteName;
    $siteToken = $context->getHeader('X-HAXCMS-Site-Token');
    if (!is_string($siteToken)) {
        $siteToken = '';
    }
    $body['site_token'] = $siteToken;
    $routeSuffix = trim((string) $context->routeSuffix, '/');
    $operations = new Operations();
    $result = null;
    if (substr($routeSuffix, -11) === '/appearance') {
        $operations->params = $body;
        $operations->rawParams = $body;
        $result = $operations->saveAppearanceSettings();
    } else if (substr($routeSuffix, -9) === '/platform') {
        $operations->params = $body;
        $operations->rawParams = $body;
        $result = $operations->savePlatformSettings();
    } else if (substr($routeSuffix, -7) === '/blocks') {
        $operations->params = $body;
        $operations->rawParams = $body;
        $result = $operations->saveAllowedBlocks();
    } else if (substr($routeSuffix, -7) === '/editor') {
        $operations->params = $body;
        $operations->rawParams = $body;
        $result = $operations->saveEditorSettings();
    } else if (substr($routeSuffix, -4) === '/seo') {
        $operations->params = $body;
        $operations->rawParams = $body;
        $result = $operations->saveSeoSettings();
    } else if (substr($routeSuffix, -8) === '/outline') {
        // getBody() decodes JSON objects as associative arrays, so an empty
        // item {} and an empty list [] both arrive as array(). Keep an empty
        // object an object, so it is answered "Each outline item requires an
        // id" like haxcms-nodejs; a list stays "must be an object". Only the
        // error message depends on this: neither one is ever saved.
        if (isset($body['items']) && is_array($body['items'])) {
            $rawPayload = json_decode($context->getRawBody());
            // items sent as a JSON object ({}) is not a list: refused with
            // "Outline payload requires an items array", as in haxcms-nodejs.
            // getBody() made it an array, which would read as an empty list.
            if (is_object($rawPayload) && isset($rawPayload->items) && is_object($rawPayload->items)) {
                $body['items'] = null;
            }
            if (is_object($rawPayload) && isset($rawPayload->items) && is_array($rawPayload->items)) {
                foreach ($rawPayload->items as $rawIndex => $rawItem) {
                    if (
                        $rawItem instanceof stdClass &&
                        count(get_object_vars($rawItem)) === 0 &&
                        array_key_exists($rawIndex, $body['items']) &&
                        $body['items'][$rawIndex] === array()
                    ) {
                        $body['items'][$rawIndex] = new stdClass();
                    }
                }
            }
        }
        $operations->params = $body;
        $operations->rawParams = $body;
        $result = $operations->saveOutline();
    } else {
        $operations->params = $body;
        $operations->rawParams = $body;
        $result = $operations->saveManifest();
    }
    if (is_array($result) && isset($result['__failed'])) {
        SiteRouteUtils::sendFormattedResponse(
            array(
                'message' => $result['__failed']['message'],
            ),
            array(
                'statusCode' => intval($result['__failed']['status']),
                'allowedFormats' => array('json'),
                'defaultFormat' => 'json',
            ),
            $context->routeSuffix,
            $context->apiBasePath
        );
        return;
    }
    if (is_array($result) && isset($result['status']) && isset($result['data'])) {
        SiteRouteUtils::sendFormattedResponse(
            $result,
            array(
                'statusCode' => 200,
                'allowedFormats' => array('json'),
                'defaultFormat' => 'json',
                'envelope' => false,
            ),
            $context->routeSuffix,
            $context->apiBasePath
        );
        return;
    }
    SiteRouteUtils::sendFormattedResponse(
        array('status' => 200, 'data' => $result),
        array(
            'statusCode' => 200,
            'allowedFormats' => array('json'),
            'defaultFormat' => 'json',
            'envelope' => false,
        ),
        $context->routeSuffix,
        $context->apiBasePath
    );
};