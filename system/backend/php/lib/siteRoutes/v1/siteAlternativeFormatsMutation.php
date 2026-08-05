<?php
include_once dirname(__FILE__) . '/../SiteRouteUtils.php';
// D44: POST v1/site/updateAlternativeFormats — mirrors Node site.js
// updateSiteAlternativeFormats (site.js:566-635). The route security layer
// already enforces authenticated-site (POST → authenticated-site), so the
// bearer + site-token gate is satisfied before this handler runs. We
// re-validate the site token here for defense-in-depth parity with the
// Node handler which also re-checks it inside the function body.
return function ($context) {
    $site = isset($context->site) ? $context->site : null;
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/x/api';
    if (!isset($site) || !isset($site->manifest)) {
        SiteRouteUtils::sendFormattedResponse(
            array(
                'message' => 'Unable to resolve site context for /x/api/v1/site/updateAlternativeFormats',
            ),
            array(
                'statusCode' => 404,
                'allowedFormats' => array('json'),
                'defaultFormat' => 'json',
            ),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }
    $siteName = isset($site->manifest->metadata->site->name)
        ? (string) $site->manifest->metadata->site->name
        : '';
    if ($siteName == '') {
        SiteRouteUtils::sendFormattedResponse(
            array(
                'message' => 'Unable to resolve site name for /x/api/v1/site/updateAlternativeFormats',
            ),
            array(
                'statusCode' => 400,
                'allowedFormats' => array('json'),
                'defaultFormat' => 'json',
            ),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }
    $siteToken = $context->getHeader('X-HAXCMS-Site-Token');
    if (!is_string($siteToken) || $siteToken == '' || !SiteRouteUtils::validateSiteToken($siteName, $siteToken)) {
        SiteRouteUtils::sendFormattedResponse(
            array(
                'message' => 'X-HAXCMS-Site-Token header is required for this endpoint',
            ),
            array(
                'statusCode' => 403,
                'allowedFormats' => array('json'),
                'defaultFormat' => 'json',
            ),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }
    $body = $context->getBody();
    if (!is_array($body)) {
        $body = array();
    }
    $format = null;
    if (isset($body['format'])) {
        $requestedFormat = trim((string) $body['format']);
        if ($requestedFormat !== '') {
            $format = $requestedFormat;
        }
    }
    if ($format !== null) {
        $allowedFormats = array('rss', 'sitemap', 'search', 'llms');
        if (!in_array($format, $allowedFormats, true)) {
            SiteRouteUtils::sendFormattedResponse(
                array(
                    'message' => 'Invalid format requested for alternative formats update',
                ),
                array(
                    'statusCode' => 400,
                    'allowedFormats' => array('json'),
                    'defaultFormat' => 'json',
                ),
                $context->routeSuffix,
                $apiBasePath
            );
            return;
        }
    }
    try {
        $site->updateAlternateFormats($format);
    } catch (Exception $e) {
        SiteRouteUtils::sendFormattedResponse(
            array(
                'message' => 'Unable to update alternative formats for this site',
            ),
            array(
                'statusCode' => 500,
                'allowedFormats' => array('json'),
                'defaultFormat' => 'json',
            ),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }
    SiteRouteUtils::sendFormattedResponse(
        array(
            'status' => 200,
            'data' => array(
                'updated' => true,
                'site' => array(
                    'name' => $siteName,
                ),
                'format' => $format,
            ),
        ),
        array(
            'statusCode' => 200,
            'allowedFormats' => array('json'),
            'defaultFormat' => 'json',
            'envelope' => false,
        ),
        $context->routeSuffix,
        $apiBasePath
    );
};
