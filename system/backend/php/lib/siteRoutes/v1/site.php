<?php
include_once dirname(__FILE__) . '/../SiteRouteUtils.php';
return function ($context) {
    $site = isset($context->site) ? $context->site : null;
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/x/api';
    if (!isset($site) || !isset($site->manifest)) {
        SiteRouteUtils::sendFormattedResponse(
            array('message' => 'Unable to resolve site context for /x/api/v1/site'),
            array('statusCode' => 404, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }
    $items = SiteRouteUtils::normalizeManifestItems($site);
    $tagSet = array();
    $regionSet = array();
    $publishedItems = 0;
    foreach ($items as $item) {
        if (!isset($item->metadata) || !is_object($item->metadata) || !isset($item->metadata->published) || $item->metadata->published !== false) {
            $publishedItems++;
        }
        if (isset($item->metadata) && is_object($item->metadata) && isset($item->metadata->tags)) {
            foreach (SiteRouteUtils::normalizeTagList($item->metadata->tags) as $tag) {
                $tagSet[$tag] = true;
            }
        }
        if (isset($item->metadata) && is_object($item->metadata) && isset($item->metadata->region) && $item->metadata->region != '') {
            $regionSet[(string) $item->metadata->region] = true;
        }
    }
    $siteDirectory = SiteRouteUtils::getSiteDirectory($site);
    $siteFiles = SiteRouteUtils::collectSiteFiles($site, $siteDirectory . '/files');
    $siteBasePath = SiteRouteUtils::getSiteBasePath($site);
    $links = array(
        'self' => $apiBasePath . '/v1/site',
        'items' => $apiBasePath . '/v1/items',
        'entities' => $apiBasePath . '/v1/entities',
        'schemas' => $apiBasePath . '/v1/schemas',
        'openapi' => $apiBasePath . '/openapi',
        'openapiJson' => $apiBasePath . '/openapi.json',
        'openapiYaml' => $apiBasePath . '/openapi.yaml',
        'manifest' => $siteBasePath . 'manifest.json',
        'serviceWorker' => $siteBasePath . 'service-worker.js',
        'serviceWorkerManifest' => $siteBasePath . 'push-manifest.json',
        'rss' => $siteBasePath . 'rss.xml',
        'atom' => $siteBasePath . 'atom.xml',
        'siteJson' => $siteBasePath . 'site.json',
        'sitemap' => $siteBasePath . 'sitemap.xml',
        'sitemapIndex' => $siteBasePath . 'sitemap-index.xml',
        'exports' => array(
            'zip' => $apiBasePath . '/v1/site/export/zip',
            'markdown' => $apiBasePath . '/v1/site/export/markdown',
            'pdf' => $apiBasePath . '/v1/site/export/pdf',
            'docx' => $apiBasePath . '/v1/site/export/docx',
            'epub' => $apiBasePath . '/v1/site/export/epub',
            'skeleton' => $apiBasePath . '/v1/site/export/skeleton',
        ),
    );
    $data = array(
        'id' => isset($site->manifest->id) ? $site->manifest->id : null,
        'name' => isset($site->manifest->metadata->site->name) ? (string) $site->manifest->metadata->site->name : (isset($site->name) ? (string) $site->name : ''),
        'title' => isset($site->manifest->title) ? $site->manifest->title : '',
        'description' => isset($site->manifest->description) ? $site->manifest->description : '',
        'language' => SiteRouteUtils::getSiteLanguage($site),
        'basePath' => $siteBasePath,
        'theme' => SiteRouteUtils::getSiteTheme($site),
        'updated' => (
            isset($site->manifest->metadata) &&
            isset($site->manifest->metadata->site) &&
            isset($site->manifest->metadata->site->updated)
        )
            ? SiteRouteUtils::toIsoDateFromUnixTime($site->manifest->metadata->site->updated)
            : null,
        'counts' => array(
            'items' => count($items),
            'publishedItems' => $publishedItems,
            'tags' => count($tagSet),
            'regions' => count($regionSet),
            'files' => count($siteFiles),
        ),
        'links' => $links,
        'jsonld' => array(
            '@context' => 'https://schema.org',
            '@type' => 'Dataset',
            '@id' => $links['self'] . '#site-summary',
            'name' => (isset($site->manifest->title) ? (string) $site->manifest->title : 'Site') . ' API summary',
            'description' => isset($site->manifest->description) ? (string) $site->manifest->description : '',
            'url' => $links['self'],
            'inLanguage' => SiteRouteUtils::getSiteLanguage($site),
        ),
    );
    SiteRouteUtils::sendFormattedResponse(
        $data,
        array(
            'allowedFormats' => array('json'),
            'defaultFormat' => 'json',
        ),
        $context->routeSuffix,
        $apiBasePath
    );
};
