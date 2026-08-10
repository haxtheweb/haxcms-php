<?php
include_once dirname(__FILE__) . '/../SiteRouteUtils.php';
include_once dirname(__FILE__) . '/../../ReportHelpers.php';
include_once dirname(__FILE__) . '/../../DaleChallWordList.php';
return function ($context) {
    $site = isset($context->site) ? $context->site : null;
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/x/api';
    $routeSuffix = isset($context->routeSuffix) ? (string) $context->routeSuffix : '';
    $sendTopLevelError = function ($statusCode, $message) use ($routeSuffix, $apiBasePath) {
        SiteRouteUtils::sendFormattedResponse(
            array(
                'message' => (string) $message,
            ),
            array(
                'statusCode' => intval($statusCode),
                'allowedFormats' => array('json'),
                'defaultFormat' => 'json',
            ),
            $routeSuffix,
            $apiBasePath
        );
    };
    if (!isset($site) || !isset($site->manifest)) {
        $sendTopLevelError(404, 'Unable to resolve site context for /x/api/v1/reports/:report');
        return;
    }
    $REPORT_DEFINITIONS = array(
        'overview' => array(
            'id' => 'overview',
            'label' => 'Stats',
            'icon' => 'hax:graph',
            'title' => 'Overview report',
            'description' => 'Aggregate site statistics for dashboard overview cards.',
            'includes' => null,
        ),
        'content' => array(
            'id' => 'content',
            'label' => 'Content',
            'icon' => 'icons:view-module',
            'title' => 'Content report',
            'description' => 'Detailed page-by-page content metrics for admin review.',
            'includes' => array('contentData'),
        ),
        'links' => array(
            'id' => 'links',
            'label' => 'Links',
            'icon' => 'icons:link',
            'title' => 'Links report',
            'description' => 'External link usage and grouping details.',
            'includes' => array('linkData'),
        ),
        'media' => array(
            'id' => 'media',
            'label' => 'Media',
            'icon' => 'icons:perm-media',
            'title' => 'Media report',
            'description' => 'Media usage and accessibility signal summary.',
            'includes' => array('mediaData'),
        ),
    );
    $reportName = isset($context->params['report']) ? trim((string) $context->params['report']) : '';
    if ($reportName == '') {
        $reports = array();
        foreach ($REPORT_DEFINITIONS as $id => $definition) {
            $reports[] = array(
                'id' => $id,
                'label' => $definition['label'],
                'icon' => $definition['icon'],
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
        $sendTopLevelError(404, 'Unknown report "' . $reportName . '"');
        return;
    }
    $ancestor = SiteRouteUtils::getQueryValue('filter.ancestor', '');
    if (!is_string($ancestor) || trim($ancestor) == '') {
        $ancestor = SiteRouteUtils::getQueryValue('filter.parent', '');
    }
    $ancestor = is_string($ancestor) && trim($ancestor) != '' ? trim($ancestor) : null;
    $definition = $REPORT_DEFINITIONS[$reportName];
    $includes = (
        isset($definition['includes']) &&
        is_array($definition['includes']) &&
        count($definition['includes']) > 0
    ) ? $definition['includes'] : null;
    $reportParams = array();
    if (!is_null($ancestor)) {
        $reportParams['activeId'] = $ancestor;
    }
    if ($includes === null) {
        $rawData = HAXCMSReportHelpers::buildSummaryData($site, $reportParams);
        $summaryKeys = array(
            'pages',
            'audio',
            'pageType',
            'selfChecks',
            'objectives',
            'authorNotes',
            'images',
            'h5p',
            'headings',
            'dataTables',
            'specialTags',
            'links',
            'placeholders',
            'siteremotecontent',
            'readTime',
            'video',
            'videoLength',
        );
        $data = array();
        foreach ($summaryKeys as $key) {
            $value = array_key_exists($key, $rawData) ? $rawData[$key] : 0;
            $data[$key] = is_numeric($value) ? intval($value) : 0;
        }
    }
    else if (in_array('contentData', $includes, true)) {
        $data = HAXCMSReportHelpers::buildContentData($site, $reportParams);
    }
    else if (in_array('linkData', $includes, true)) {
        $data = HAXCMSReportHelpers::buildLinkData($site, $reportParams);
    }
    else if (in_array('mediaData', $includes, true)) {
        $data = HAXCMSReportHelpers::buildMediaData($site, $reportParams);
    }
    else {
        $data = array();
    }
    if ($reportName == 'overview') {
        $items = SiteRouteUtils::getOrderedItems($site);
        if (!is_null($ancestor) && isset($site->manifest) && method_exists($site->manifest, 'findBranch')) {
            $branchItems = $site->manifest->findBranch($ancestor);
            if (is_array($branchItems)) {
                $items = $branchItems;
            }
        }
        $textParts = array();
        foreach ($items as $item) {
            if (
                isset($item->metadata) &&
                is_object($item->metadata) &&
                isset($item->metadata->published) &&
                $item->metadata->published === false
            ) {
                continue;
            }
            $textParts[] = trim(strip_tags(SiteRouteUtils::getItemContent($site, $item)));
        }
        $readabilityText = trim(preg_replace('/\\s+/', ' ', implode(' ', $textParts)));
        $tokens = $readabilityText == '' ? array() : preg_split('/\\s+/', $readabilityText);
        $lexiconCount = 0;
        $difficultWords = 0;
        $syllableCount = 0;
        if (is_array($tokens)) {
            foreach ($tokens as $token) {
                $word = strtolower(trim(preg_replace('/^[^a-z\']+|[^a-z\']+$/i', '', (string) $token)));
                if ($word == '') {
                    continue;
                }
                $lexiconCount++;
                if (!DaleChallWordList::isFamiliarWord($word)) {
                    $difficultWords++;
                }
                $wordSyllables = preg_match_all('/[aeiouy]+/i', $word, $matches);
                $syllableCount += is_numeric($wordSyllables) && intval($wordSyllables) > 0 ? intval($wordSyllables) : 1;
            }
        }
        $sentenceCount = preg_match_all('/[.!?]+/', $readabilityText, $sentenceMatches);
        if (!is_numeric($sentenceCount)) {
            $sentenceCount = 0;
        }
        $daleChallScore = 0.0;
        if ($lexiconCount > 0 && $sentenceCount > 0) {
            $difficultPercent = ($difficultWords / $lexiconCount) * 100;
            $daleChallScore = (0.1579 * $difficultPercent) + (0.0496 * ($lexiconCount / $sentenceCount));
            if ($difficultWords == 0) {
                $daleChallScore += 3.6365;
            }
        }
        $gradeLevel = 'college level reading';
        if ($daleChallScore <= 4.9) {
            $gradeLevel = '4th grade or lower';
        }
        else if ($daleChallScore <= 5.9) {
            $gradeLevel = '5th / 6th grade';
        }
        else if ($daleChallScore <= 6.9) {
            $gradeLevel = '7th / 8th grade';
        }
        else if ($daleChallScore <= 7.9) {
            $gradeLevel = '9th / 10th grade';
        }
        else if ($daleChallScore <= 8.9) {
            $gradeLevel = '11th / 12th grade';
        }
        $data['readability'] = array(
            'gradeLevel' => $gradeLevel,
            'difficultWords' => intval($difficultWords),
            'syllableCount' => intval($syllableCount),
            'lexiconCount' => intval($lexiconCount),
            'sentenceCount' => intval($sentenceCount),
        );
    }
    $siteBasePath = SiteRouteUtils::getSiteBasePath($site);
    if (isset($data['contentData']) && is_array($data['contentData'])) {
        foreach ($data['contentData'] as $contentIdx => $contentRow) {
            if (!is_array($contentRow)) {
                continue;
            }
            $data['contentData'][$contentIdx]['link'] = $siteBasePath . (isset($contentRow['slug']) ? $contentRow['slug'] : '');
        }
    }
    if (isset($data['linkData']) && is_array($data['linkData'])) {
        foreach ($data['linkData'] as $linkHref => $linkEntries) {
            if (!is_array($linkEntries)) {
                continue;
            }
            foreach ($linkEntries as $entryIdx => $linkEntry) {
                if (!is_array($linkEntry)) {
                    continue;
                }
                $linkPage = (isset($linkEntry['itemId']) && $linkEntry['itemId']) ? $site->manifest->getItemById($linkEntry['itemId']) : false;
                $data['linkData'][$linkHref][$entryIdx]['link'] = $linkPage ? $siteBasePath . $linkPage->slug : '';
                $data['linkData'][$linkHref][$entryIdx]['pageTitle'] = $linkPage ? $linkPage->title : '';
            }
        }
    }
    if (isset($data['mediaData']) && is_array($data['mediaData'])) {
        foreach ($data['mediaData'] as $mediaIdx => $mediaItem) {
            if (!is_array($mediaItem)) {
                continue;
            }
            $mediaPage = (isset($mediaItem['itemId']) && $mediaItem['itemId']) ? $site->manifest->getItemById($mediaItem['itemId']) : false;
            $data['mediaData'][$mediaIdx]['pageLink'] = $mediaPage ? $siteBasePath . $mediaPage->slug : '';
            $data['mediaData'][$mediaIdx]['pageSlug'] = $mediaPage ? $mediaPage->slug : '';
            $data['mediaData'][$mediaIdx]['pageTitle'] = $mediaPage ? $mediaPage->title : '';
        }
    }
    $payload = array(
        'id' => $definition['id'],
        'label' => $definition['label'],
        'icon' => $definition['icon'],
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
            $key = trim((string) $field);
            if ($key != '' && array_key_exists($key, $payload)) {
                $projected[$key] = $payload[$key];
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