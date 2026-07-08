<?php
include_once dirname(__FILE__) . '/../../Operations.php';
return function ($context) {
    $site = isset($context->site) ? $context->site : null;
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/x/api';
    if (!isset($site) || !isset($site->manifest)) {
        SiteRouteUtils::sendFormattedResponse(
            array(
                'status' => 404,
                'message' => 'Unable to resolve site context for /x/api/v1/site/normalize-slugs',
            ),
            array(
                'statusCode' => 404,
                'allowedFormats' => array('json'),
                'defaultFormat' => 'json',
                'envelope' => false,
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
                'status' => 400,
                'message' => 'Unable to resolve site name for /x/api/v1/site/normalize-slugs',
            ),
            array(
                'statusCode' => 400,
                'allowedFormats' => array('json'),
                'defaultFormat' => 'json',
                'envelope' => false,
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
                'status' => 403,
                'message' => 'X-HAXCMS-Site-Token header is required for this endpoint',
            ),
            array(
                'statusCode' => 403,
                'allowedFormats' => array('json'),
                'defaultFormat' => 'json',
                'envelope' => false,
            ),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }
    $operations = new Operations();
    if (!$operations->platformAllows($site, 'outlineDesigner')) {
        SiteRouteUtils::sendFormattedResponse(
            array(
                'status' => 403,
                'message' => 'Outline operations are disabled for this site',
            ),
            array(
                'statusCode' => 403,
                'allowedFormats' => array('json'),
                'defaultFormat' => 'json',
                'envelope' => false,
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
    $isPreview = false;
    if (isset($body['preview']) && ($body['preview'] === true || $body['preview'] === 'true' || $body['preview'] === 1)) {
        $isPreview = true;
    } else if (isset($_GET['preview']) && ($_GET['preview'] === 'true' || $_GET['preview'] === '1')) {
        $isPreview = true;
    }
    $pathautoEnabled = isset($site->manifest->metadata->site->settings->pathauto) && $site->manifest->metadata->site->settings->pathauto;
    $items = SiteRouteUtils::normalizeManifestItems($site);
    $originalItems = array();
    foreach ($items as $item) {
        $originalItems[$item->id] = clone $item;
    }
    // Temporarily update manifest items so getUniqueSlugName sees updated parent slugs
    $originalManifestItems = $site->manifest->items;
    $site->manifest->items = $items;
    // Process items in tree order so parents are updated before children
    $processedIds = array();
    $changes = array();
    $skipped = array();
    $remaining = array_values($items);
    $maxIterations = count($remaining) * 2;
    $iteration = 0;
    while (count($remaining) > 0 && $iteration < $maxIterations) {
        $iteration++;
        $nextBatch = array();
        foreach ($remaining as $item) {
            $parent = isset($item->parent) ? (string) $item->parent : '';
            $canProcess = ($parent == '' || in_array($parent, $processedIds));
            if (!$canProcess) {
                $nextBatch[] = $item;
                continue;
            }
            $processedIds[] = (string) $item->id;
            $oldSlug = isset($item->slug) ? (string) $item->slug : '';
            $overridePathauto = false;
            if (
                isset($item->metadata) &&
                is_object($item->metadata) &&
                isset($item->metadata->overridePathauto) &&
                $item->metadata->overridePathauto === true
            ) {
                $overridePathauto = true;
            }
            $shouldSkip = false;
            $reason = '';
            if ($pathautoEnabled && $overridePathauto) {
                $shouldSkip = true;
                $reason = 'overridePathauto';
            }
            if (!$shouldSkip) {
                $cleanTitle = $GLOBALS['HAXCMS']->cleanTitle($item->title);
                $newSlug = $site->getUniqueSlugName($cleanTitle, $item, true);
                if ($newSlug != $oldSlug) {
                    $item->slug = $newSlug;
                    $changes[] = array(
                        'id' => (string) $item->id,
                        'title' => isset($item->title) ? (string) $item->title : '',
                        'oldSlug' => $oldSlug,
                        'newSlug' => $newSlug,
                    );
                }
            } else {
                $skipped[] = array(
                    'id' => (string) $item->id,
                    'title' => isset($item->title) ? (string) $item->title : '',
                    'oldSlug' => $oldSlug,
                    'reason' => $reason,
                );
            }
        }
        $remaining = $nextBatch;
    }
    if ($isPreview) {
        $site->manifest->items = $originalManifestItems;
    } else {
        $site->manifest->metadata->site->updated = time();
        $site->manifest->save(false);
        $site->updateAlternateFormats();
        $site->gitCommit('Bulk slug normalization: ' . count($changes) . ' changed, ' . count($skipped) . ' skipped');
    }
    $data = array(
        'changed' => count($changes) > 0,
        'preview' => $isPreview,
        'changes' => $changes,
        'skipped' => $skipped,
    );
    SiteRouteUtils::sendFormattedResponse(
        array('status' => 200, 'data' => $data),
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