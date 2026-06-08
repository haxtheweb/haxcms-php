<?php
include_once dirname(__FILE__) . '/../SiteRouteUtils.php';
return function ($context) {
    $site = isset($context->site) ? $context->site : null;
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/x/api';
    if (!isset($site) || !isset($site->manifest)) {
        SiteRouteUtils::sendFormattedResponse(
            array('message' => 'Unable to resolve site context for /x/api/v1/reports'),
            array('statusCode' => 404, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }
    $REPORT_DEFINITIONS = array(
        'overview' => array(
            'id' => 'overview',
            'title' => 'Overview report',
            'description' => 'Aggregate site statistics for dashboard overview cards.',
        ),
        'insights' => array(
            'id' => 'insights',
            'title' => 'Insights report',
            'description' => 'Content insight metrics including readability and structure counts.',
        ),
        'content' => array(
            'id' => 'content',
            'title' => 'Content report',
            'description' => 'Detailed page-by-page content metrics for admin review.',
        ),
        'links' => array(
            'id' => 'links',
            'title' => 'Links report',
            'description' => 'External link usage and grouping details.',
        ),
        'media' => array(
            'id' => 'media',
            'title' => 'Media report',
            'description' => 'Media usage and accessibility signal summary.',
        ),
    );
    $stripAndNormalizeText = function ($html = '') {
        $stripped = trim((string) strip_tags((string) $html));
        if ($stripped == '') {
            return '';
        }
        return trim((string) preg_replace('/\s+/', ' ', $stripped));
    };
    $extractLinks = function ($html = '') {
        $output = array();
        $count = preg_match_all('/<a\b[^>]*href\s*=\s*(["\'])(.*?)\1/i', (string) $html, $matches);
        if (!is_numeric($count) || !isset($matches[2]) || !is_array($matches[2])) {
            return $output;
        }
        foreach ($matches[2] as $href) {
            $hrefValue = trim((string) $href);
            if ($hrefValue != '') {
                $output[] = $hrefValue;
            }
        }
        return $output;
    };
    $calculateItemMetrics = function ($item, $html = '') use ($stripAndNormalizeText, $extractLinks, $apiBasePath) {
        $text = $stripAndNormalizeText($html);
        $wordCount = $text == '' ? 0 : count(preg_split('/\s+/', $text));
        $sentenceCount = 0;
        $sentenceMatchCount = preg_match_all('/[.!?]+/', $text, $sentenceMatches);
        if (is_numeric($sentenceMatchCount)) {
            $sentenceCount = intval($sentenceMatchCount);
        }
        $headingCount = 0;
        $headingMatchCount = preg_match_all('/<h[1-6]\b/i', (string) $html, $headingMatches);
        if (is_numeric($headingMatchCount)) {
            $headingCount = intval($headingMatchCount);
        }
        $imageCount = 0;
        $imageMatchCount = preg_match_all('/<img\b/i', (string) $html, $imageMatches);
        if (is_numeric($imageMatchCount)) {
            $imageCount = intval($imageMatchCount);
        }
        $videoCount = 0;
        $videoMatchCount = preg_match_all('/<video\b/i', (string) $html, $videoMatches);
        if (is_numeric($videoMatchCount)) {
            $videoCount = intval($videoMatchCount);
        }
        $audioCount = 0;
        $audioMatchCount = preg_match_all('/<audio\b/i', (string) $html, $audioMatches);
        if (is_numeric($audioMatchCount)) {
            $audioCount = intval($audioMatchCount);
        }
        $iframeCount = 0;
        $iframeMatchCount = preg_match_all('/<iframe\b/i', (string) $html, $iframeMatches);
        if (is_numeric($iframeMatchCount)) {
            $iframeCount = intval($iframeMatchCount);
        }
        $links = $extractLinks($html);
        $lookup = SiteRouteUtils::getItemLookupValue($item);
        return array(
            'id' => isset($item->id) ? $item->id : null,
            'title' => isset($item->title) ? $item->title : '',
            'slug' => isset($item->slug) ? $item->slug : '',
            'location' => isset($item->location) ? $item->location : '',
            'wordCount' => $wordCount,
            'sentenceCount' => $sentenceCount,
            'headingCount' => $headingCount,
            'charCount' => strlen($text),
            'linkCount' => count($links),
            'links' => $links,
            'media' => array(
                'images' => $imageCount,
                'video' => $videoCount,
                'audio' => $audioCount,
                'iframe' => $iframeCount,
            ),
            'apiLinks' => array(
                'item' => $apiBasePath . '/v1/items/' . rawurlencode($lookup),
                'content' => $apiBasePath . '/v1/content/' . rawurlencode($lookup),
            ),
        );
    };
    $routeSuffix = isset($context->routeSuffix) ? (string) $context->routeSuffix : '';
    $reportName = isset($context->params['report']) ? trim((string) $context->params['report']) : '';
    if ($reportName == '') {
        $reports = array();
        foreach ($REPORT_DEFINITIONS as $id => $definition) {
            $reports[] = array(
                'id' => $id,
                'title' => $definition['title'],
                'description' => $definition['description'],
                'links' => array(
                    'self' => $apiBasePath . '/v1/reports/' . rawurlencode($id),
                ),
            );
        }
        SiteRouteUtils::sendFormattedResponse(
            array(
                'count' => count($reports),
                'reports' => $reports,
                'links' => array(
                    'self' => $apiBasePath . '/v1/reports',
                ),
            ),
            array('allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $routeSuffix,
            $apiBasePath
        );
        return;
    }
    if (!array_key_exists($reportName, $REPORT_DEFINITIONS)) {
        SiteRouteUtils::sendFormattedResponse(
            array('message' => 'Unknown report "' . $reportName . '"'),
            array('statusCode' => 404, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $routeSuffix,
            $apiBasePath
        );
        return;
    }
    $orderedItems = SiteRouteUtils::getOrderedItems($site);
    $filteredItems = SiteRouteUtils::applyItemFilters($orderedItems, $site);
    $tagMap = array();
    $regionMap = array();
    $publishedItemCount = 0;
    $itemMetrics = array();
    $totalWords = 0;
    $totalSentences = 0;
    $totalHeadings = 0;
    $totalLinks = 0;
    foreach ($filteredItems as $item) {
        $published = !(
            isset($item->metadata) &&
            is_object($item->metadata) &&
            isset($item->metadata->published) &&
            $item->metadata->published === false
        );
        if ($published) {
            $publishedItemCount++;
        }
        if (isset($item->metadata) && is_object($item->metadata) && isset($item->metadata->tags)) {
            $itemTags = SiteRouteUtils::normalizeTagList($item->metadata->tags);
            foreach ($itemTags as $tag) {
                if (!array_key_exists($tag, $tagMap)) {
                    $tagMap[$tag] = 0;
                }
                $tagMap[$tag] += 1;
            }
        }
        $region = (
            isset($item->metadata) &&
            is_object($item->metadata) &&
            isset($item->metadata->region) &&
            trim((string) $item->metadata->region) != ''
        ) ? trim((string) $item->metadata->region) : 'default';
        if (!array_key_exists($region, $regionMap)) {
            $regionMap[$region] = 0;
        }
        $regionMap[$region] += 1;
        $html = SiteRouteUtils::getItemContent($site, $item);
        $metrics = $calculateItemMetrics($item, $html);
        $itemMetrics[] = $metrics;
        $totalWords += $metrics['wordCount'];
        $totalSentences += $metrics['sentenceCount'];
        $totalHeadings += $metrics['headingCount'];
        $totalLinks += $metrics['linkCount'];
    }
    $siteDirectory = SiteRouteUtils::getSiteDirectory($site);
    $files = SiteRouteUtils::collectSiteFiles($site, $siteDirectory . '/files');
    $buildReadabilityGrade = function ($avgSentenceLength) {
        if ($avgSentenceLength <= 12) {
            return 'elementary';
        }
        if ($avgSentenceLength <= 18) {
            return 'middle/high school';
        }
        return 'college level reading';
    };
    $data = array();
    if ($reportName == 'overview') {
        $customElementUsage = SiteRouteUtils::collectCustomElementUsage($site, $filteredItems);
        $data = array(
            'itemCount' => count($filteredItems),
            'publishedItemCount' => $publishedItemCount,
            'tagCount' => count($tagMap),
            'regionCount' => count($regionMap),
            'fileCount' => count($files),
            'customElementCount' => count($customElementUsage),
            'wordCount' => $totalWords,
            'estimatedReadingTimeMinutes' => $totalWords > 0 ? intval(ceil($totalWords / 200)) : 0,
        );
    }
    else if ($reportName == 'insights') {
        $averageWordsPerItem = count($filteredItems) > 0 ? $totalWords / count($filteredItems) : 0;
        $averageSentenceLength = $totalSentences > 0 ? ($totalWords / $totalSentences) : 0;
        $data = array(
            'averageWordsPerItem' => round($averageWordsPerItem, 2),
            'averageSentenceLength' => round($averageSentenceLength, 2),
            'headingDensityPerItem' => count($filteredItems) > 0 ? round($totalHeadings / count($filteredItems), 2) : 0,
            'linkDensityPerItem' => count($filteredItems) > 0 ? round($totalLinks / count($filteredItems), 2) : 0,
            'readability' => array(
                'gradeLevel' => $buildReadabilityGrade($averageSentenceLength),
                'wordCount' => $totalWords,
                'sentenceCount' => $totalSentences,
            ),
            'topTags' => SiteRouteUtils::sortRecords(
                array_map(function ($tagName) use ($tagMap) {
                    return array('tag' => $tagName, 'count' => $tagMap[$tagName]);
                }, array_keys($tagMap)),
                '',
                '-count'
            ),
        );
    }
    else if ($reportName == 'content') {
        $pages = SiteRouteUtils::sortRecords($itemMetrics, SiteRouteUtils::getQueryValue('sort', ''), '-wordCount');
        $paged = SiteRouteUtils::paginateRecords($pages, 25, 500);
        $data = array(
            'count' => count($paged['records']),
            'total' => $paged['page']['total'],
            'page' => $paged['page'],
            'pages' => $paged['records'],
        );
    }
    else if ($reportName == 'links') {
        $externalMap = array();
        $perItem = array();
        foreach ($itemMetrics as $metrics) {
            $externalLinks = array();
            foreach ($metrics['links'] as $href) {
                if (preg_match('/^https?:\/\//i', $href) !== 1) {
                    continue;
                }
                $externalLinks[] = $href;
                if (!array_key_exists($href, $externalMap)) {
                    $externalMap[$href] = 0;
                }
                $externalMap[$href] += 1;
            }
            $perItem[] = array(
                'id' => $metrics['id'],
                'title' => $metrics['title'],
                'slug' => $metrics['slug'],
                'externalLinkCount' => count($externalLinks),
                'externalLinks' => $externalLinks,
                'apiLinks' => $metrics['apiLinks'],
            );
        }
        $externalRecords = array();
        foreach ($externalMap as $href => $count) {
            $externalRecords[] = array('href' => $href, 'count' => $count);
        }
        $externalRecords = SiteRouteUtils::sortRecords($externalRecords, SiteRouteUtils::getQueryValue('sort', ''), '-count');
        $data = array(
            'externalLinkCount' => array_sum($externalMap),
            'uniqueExternalLinkCount' => count($externalRecords),
            'topExternalLinks' => $externalRecords,
            'pages' => $perItem,
        );
    }
    else if ($reportName == 'media') {
        $totals = array('images' => 0, 'video' => 0, 'audio' => 0, 'iframe' => 0);
        $pages = array();
        foreach ($itemMetrics as $metrics) {
            $totals['images'] += $metrics['media']['images'];
            $totals['video'] += $metrics['media']['video'];
            $totals['audio'] += $metrics['media']['audio'];
            $totals['iframe'] += $metrics['media']['iframe'];
            $pages[] = array(
                'id' => $metrics['id'],
                'title' => $metrics['title'],
                'slug' => $metrics['slug'],
                'media' => $metrics['media'],
                'apiLinks' => $metrics['apiLinks'],
            );
        }
        $data = array(
            'totals' => $totals,
            'pages' => $pages,
        );
    }
    $definition = $REPORT_DEFINITIONS[$reportName];
    $payload = array(
        'id' => $definition['id'],
        'title' => $definition['title'],
        'description' => $definition['description'],
        'generatedAt' => gmdate('c'),
        'data' => $data,
        'links' => array(
            'self' => $apiBasePath . '/v1/reports/' . rawurlencode($definition['id']),
            'collection' => $apiBasePath . '/v1/reports',
        ),
    );
    $fields = SiteRouteUtils::getCsvQuery('fields');
    if (count($fields) > 0) {
        $projected = array();
        foreach ($fields as $field) {
            if (array_key_exists($field, $payload)) {
                $projected[$field] = $payload[$field];
            }
        }
        if (count($projected) > 0) {
            $payload = $projected;
        }
    }
    SiteRouteUtils::sendFormattedResponse(
        $payload,
        array(
            'allowedFormats' => array('json', 'md', 'yaml', 'xml'),
            'defaultFormat' => 'json',
        ),
        $routeSuffix,
        $apiBasePath
    );
};
