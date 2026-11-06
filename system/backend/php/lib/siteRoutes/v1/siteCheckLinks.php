<?php
include_once dirname(__FILE__) . '/../SiteRouteUtils.php';
include_once dirname(__FILE__) . '/../../LinkChecker.php';
/**
 * POST x/api/v1/site/check-links
 * Body: { links: [href, ...] } (at most LinkChecker::MAX_LINKS)
 *
 * Answers whether each outside link still resolves. Only links that appear in
 * this site's own pages are checked; see lib/LinkChecker.php for the limits.
 * Mirrors haxcms-nodejs src/siteRoutes/v1/linkChecks.js.
 */
return function ($context) {
    $site = isset($context->site) ? $context->site : null;
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/x/api';
    $fail = function ($statusCode, $message, $extra = array()) use ($context, $apiBasePath) {
        SiteRouteUtils::sendFormattedResponse(
            array_merge(array('message' => $message), $extra),
            array(
                'statusCode' => $statusCode,
                'allowedFormats' => array('json'),
                'defaultFormat' => 'json',
            ),
            $context->routeSuffix,
            $apiBasePath
        );
    };
    if (!isset($site) || !isset($site->manifest)) {
        $fail(404, 'Unable to resolve site context for /x/api/v1/site/check-links');
        return;
    }
    $body = $context->getBody();
    $links = (is_array($body) && isset($body['links']) && is_array($body['links'])) ? $body['links'] : null;
    if (!is_array($links) || count($links) === 0) {
        $fail(400, 'links is required');
        return;
    }
    if (count($links) > LinkChecker::MAX_LINKS) {
        $fail(400, 'invalid links value (at most ' . LinkChecker::MAX_LINKS . ' per request)');
        return;
    }
    // every page, published or not: authors fix links in drafts too
    $siteHtml = '';
    $items = SiteRouteUtils::getOrderedItems($site);
    foreach ($items as $item) {
        if (!isset($item->id) || !method_exists($site, 'loadNode') || !method_exists($site, 'getPageContent')) {
            continue;
        }
        $page = $site->loadNode($item->id);
        if (!$page) {
            continue;
        }
        $content = $site->getPageContent($page);
        if (is_string($content) && $content !== '') {
            $siteHtml .= '<div>' . $content . '</div>';
        }
    }
    $siteName = isset($site->manifest->metadata->site->name)
        ? (string) $site->manifest->metadata->site->name
        : (isset($site->name) ? (string) $site->name : '');
    $userName = (isset($context->auth) && is_array($context->auth) && isset($context->auth['userName']))
        ? (string) $context->auth['userName']
        : '';
    $rateSettings = $GLOBALS['HAXCMS']->getLinkCheckRateLimitSettings();
    $cache = (isset($GLOBALS['HAXCMS']->cache) && $GLOBALS['HAXCMS']->cache) ? $GLOBALS['HAXCMS']->cache : null;
    $outcome = LinkChecker::checkSiteLinks(array(
        'siteHtml' => $siteHtml,
        'links' => $links,
        'siteKey' => $siteName,
        'rateKey' => $userName . '::' . $siteName,
        'rateSettings' => $rateSettings,
        'cache' => $cache,
    ));
    $windowMinutes = max(1, intval(round(intval($rateSettings->windowMs) / 60000)));
    if ($outcome['rateLimited']) {
        $retryAfter = intval($outcome['retryAfterSeconds']);
        @header('Retry-After: ' . $retryAfter);
        $minutes = max(1, intval(ceil($retryAfter / 60)));
        $fail(
            429,
            'Link check limit reached: ' . intval($rateSettings->max) . ' checks per ' . $windowMinutes . ' minute' . ($windowMinutes !== 1 ? 's' : '') . ' for this site. You can retry in ' . $minutes . ' minute' . ($minutes !== 1 ? 's' : '') . '.',
            array('retryAfterSeconds' => $retryAfter)
        );
        return;
    }
    SiteRouteUtils::sendFormattedResponse(
        array(
            'count' => count($outcome['results']),
            'checked' => $outcome['checked'],
            'cached' => $outcome['cached'],
            'deferred' => $outcome['deferred'],
            'results' => $outcome['results'],
            'limits' => array(
                'maxLinks' => LinkChecker::MAX_LINKS,
                'windowSeconds' => intval(round(intval($rateSettings->windowMs) / 1000)),
                'maxChecksPerWindow' => intval($rateSettings->max),
                'remaining' => intval($outcome['remaining']),
                'cacheSeconds' => LinkChecker::RESULT_TTL_SECONDS,
            ),
            'links' => array(
                'self' => $apiBasePath . '/v1/site/check-links',
            ),
        ),
        array(
            'allowedFormats' => array('json'),
            'defaultFormat' => 'json',
        ),
        $context->routeSuffix,
        $apiBasePath
    );
};
