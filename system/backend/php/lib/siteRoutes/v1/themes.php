<?php
include_once dirname(__FILE__) . '/../SiteRouteUtils.php';
include_once dirname(__FILE__) . '/../../ThemeSettingsService.php';
return function ($context) {
    $site = isset($context->site) ? $context->site : null;
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/x/api';
    if (!isset($site) || !isset($site->manifest)) {
        SiteRouteUtils::sendFormattedResponse(
            array('message' => 'Unable to resolve site context for /x/api/v1/themes'),
            array('statusCode' => 404, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }
    $getActiveThemeName = function ($site) {
        if (
            isset($site->manifest->metadata) &&
            isset($site->manifest->metadata->theme)
        ) {
            if (
                isset($site->manifest->metadata->theme->element) &&
                trim((string) $site->manifest->metadata->theme->element) != ''
            ) {
                return trim((string) $site->manifest->metadata->theme->element);
            }
            if (
                isset($site->manifest->metadata->theme->machineName) &&
                trim((string) $site->manifest->metadata->theme->machineName) != ''
            ) {
                return trim((string) $site->manifest->metadata->theme->machineName);
            }
            if (
                isset($site->manifest->metadata->theme->name) &&
                trim((string) $site->manifest->metadata->theme->name) != ''
            ) {
                return trim((string) $site->manifest->metadata->theme->name);
            }
        }
        return '';
    };
    $readValue = function ($value, $key, $fallbackValue = '') {
        if (is_array($value) && array_key_exists($key, $value)) {
            return $value[$key];
        }
        if (is_object($value) && isset($value->{$key})) {
            return $value->{$key};
        }
        return $fallbackValue;
    };
    $normalizeThemeRecord = function ($theme, $enabled, $active) use ($apiBasePath, $readValue) {
        $machineName = trim((string) $readValue($theme, 'machineName', ''));
        if ($machineName == '') {
            $machineName = trim((string) $readValue($theme, 'element', ''));
        }
        if ($machineName == '') {
            $machineName = trim((string) $readValue($theme, 'name', ''));
        }
        $record = array(
            'machineName' => $machineName,
            'name' => trim((string) $readValue($theme, 'name', $machineName)),
            'description' => (string) $readValue($theme, 'description', ''),
            'enabled' => $enabled ? true : false,
            'active' => $active ? true : false,
            'hidden' => !$enabled,
            'screenshot' => (string) $readValue($theme, 'screenshot', $readValue($theme, 'thumbnail', '')),
            'path' => (string) $readValue($theme, 'path', ''),
            'element' => (string) $readValue($theme, 'element', $machineName),
            'links' => array(
                'self' => $apiBasePath . '/v1/themes/' . rawurlencode($machineName),
            ),
        );
        $supportedPalettes = $readValue($theme, 'supportedPalettes', null);
        if (is_array($supportedPalettes) && count($supportedPalettes) > 0) {
            $record['supportedPalettes'] = $supportedPalettes;
        }
        return $record;
    };
    $discoverThemeRecords = function () use ($site, $apiBasePath, $getActiveThemeName, $normalizeThemeRecord) {
        $records = array();
        $activeThemeName = strtolower($getActiveThemeName($site));
        if (
            class_exists('HAXCMSThemeSettingsService') &&
            isset($GLOBALS['HAXCMS']) &&
            is_object($GLOBALS['HAXCMS'])
        ) {
            $discovered = HAXCMSThemeSettingsService::discoverThemes($GLOBALS['HAXCMS']);
            $detectedThemeNames = array();
            foreach ($discovered as $item) {
                if (is_array($item) && isset($item['machineName'])) {
                    $detectedThemeNames[] = $item['machineName'];
                }
            }
            $enabledThemes = HAXCMSThemeSettingsService::readEnabledThemeMap($GLOBALS['HAXCMS']);
            $visibleThemeNames = array();
            foreach ($discovered as $item) {
                if (!is_array($item) || !isset($item['machineName'])) {
                    continue;
                }
                $machineName = (string) $item['machineName'];
                if (
                    !HAXCMSThemeSettingsService::isThemeHidden($item) &&
                    !HAXCMSThemeSettingsService::isThemeTerrible($item, $machineName)
                ) {
                    $visibleThemeNames[] = $machineName;
                }
            }
            $withDefaults = HAXCMSThemeSettingsService::reconcileDetectedThemeMap(
                $GLOBALS['HAXCMS'],
                $enabledThemes,
                $detectedThemeNames,
                $visibleThemeNames
            );
            if (isset($withDefaults['enabledThemes']) && is_array($withDefaults['enabledThemes'])) {
                $enabledThemes = $withDefaults['enabledThemes'];
            }
            if (isset($withDefaults['changed']) && $withDefaults['changed']) {
                HAXCMSThemeSettingsService::writeEnabledThemeMap($GLOBALS['HAXCMS'], $enabledThemes);
            }
            foreach ($discovered as $item) {
                $machineName = isset($item['machineName']) ? (string) $item['machineName'] : '';
                if (
                    HAXCMSThemeSettingsService::isThemeHidden($item) ||
                    HAXCMSThemeSettingsService::isThemeTerrible($item, $machineName)
                ) {
                    continue;
                }
                $enabled = HAXCMSThemeSettingsService::isThemeEnabled($GLOBALS['HAXCMS'], $machineName, $enabledThemes);
                $active = strtolower($machineName) === $activeThemeName;
                $records[] = $normalizeThemeRecord($item, $enabled, $active);
            }
        }
        if (count($records) == 0) {
            $fallbackTheme = array(
                'machineName' => $getActiveThemeName($site),
                'name' => $getActiveThemeName($site),
                'description' => '',
                'path' => (isset($site->manifest->metadata->theme->path) ? $site->manifest->metadata->theme->path : ''),
                'element' => $getActiveThemeName($site),
                'screenshot' => '',
            );
            $records[] = $normalizeThemeRecord($fallbackTheme, true, true);
        }
        return $records;
    };
    $routeSuffix = isset($context->routeSuffix) ? (string) $context->routeSuffix : '';
    $includeDisabled = SiteRouteUtils::getBooleanQuery('includeDisabled', false);
    $fields = SiteRouteUtils::getCsvQuery('fields');
    $records = $discoverThemeRecords();
    if (!$includeDisabled) {
        $records = array_values(array_filter($records, function ($record) {
            return isset($record['enabled']) && $record['enabled'];
        }));
    }
    if ($routeSuffix === 'v1/themes/active') {
        $target = null;
        foreach ($records as $record) {
            if (isset($record['active']) && $record['active']) {
                $target = $record;
                break;
            }
        }
        if (!is_array($target)) {
            SiteRouteUtils::sendFormattedResponse(
                array('message' => 'Active theme not found'),
                array('statusCode' => 404, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
                $routeSuffix,
                $apiBasePath
            );
            return;
        }
        $outputRecord = SiteRouteUtils::projectRecord($target, $fields);
        SiteRouteUtils::sendFormattedResponse(
            $outputRecord,
            array('allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $routeSuffix,
            $apiBasePath
        );
        return;
    }
    $themeName = isset($context->params['themeName']) ? trim((string) $context->params['themeName']) : '';
    if ($themeName != '') {
        $target = null;
        foreach ($records as $record) {
            if (
                isset($record['machineName']) &&
                strtolower((string) $record['machineName']) === strtolower($themeName)
            ) {
                $target = $record;
                break;
            }
        }
        if (!is_array($target)) {
            SiteRouteUtils::sendFormattedResponse(
                array('message' => 'Theme "' . $themeName . '" not found'),
                array('statusCode' => 404, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
                $routeSuffix,
                $apiBasePath
            );
            return;
        }
        $outputRecord = SiteRouteUtils::projectRecord($target, $fields);
        SiteRouteUtils::sendFormattedResponse(
            $outputRecord,
            array('allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $routeSuffix,
            $apiBasePath
        );
        return;
    }
    $records = SiteRouteUtils::sortRecords($records, SiteRouteUtils::getQueryValue('sort', ''), 'machineName');
    $paged = SiteRouteUtils::paginateRecords($records, 50, 500);
    $outputRecords = SiteRouteUtils::projectCollection($paged['records'], $fields);
    SiteRouteUtils::sendFormattedResponse(
        array(
            'count' => count($outputRecords),
            'total' => $paged['page']['total'],
            'page' => $paged['page'],
            'themes' => $outputRecords,
            'links' => array('self' => $apiBasePath . '/v1/themes'),
        ),
        array('allowedFormats' => array('json'), 'defaultFormat' => 'json'),
        $routeSuffix,
        $apiBasePath
    );
};
