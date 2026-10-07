<?php
include_once dirname(__FILE__) . '/../../../siteRoutes/SiteRouteUtils.php';
include_once dirname(__FILE__) . '/importUtils.php';

$__vendorAutoload = dirname(__FILE__) . '/../../../../vendor/autoload.php';
if (file_exists($__vendorAutoload)) {
    require_once $__vendorAutoload;
}
include_once dirname(__FILE__) . '/../../../SsrfGuard.php';
include_once dirname(__FILE__) . '/../../../SanitizeContent.php';

// PHP port of haxcms-nodejs src/systemRoutes/v1/routes/imports/
// convertDrupalToSite.js, kept structurally identical: same collections,
// file map, media elements, outline strategies, status codes, stats and
// error strings.
if (!defined('HAXCMS_DRUPAL_IMPORT_LIMITS_MAXFILES')) {
    // createSite's build.files pipeline only accepts these extensions, so
    // anything else is never handed over in the first place (mirrors
    // SAFE_BULK_IMPORT_EXTENSION_REGEX in createSite.js / convertDrupalToSite.js).
    define('HAXCMS_DRUPAL_SAFE_BULK_IMPORT_EXTENSION_REGEX', '/\.(jpg|jpeg|png|gif|webm|webp|mp4|mp3|mov|csv|ppt|pptx|xlsx|doc|xls|docx|pdf|rtf|txt|vtt|html|md|xml|ics|vcf)$/i');
    // Drupal public file URLs look like /sites/default/files/2024-06/x.jpg.
    // The captured remainder becomes the files-relative key because
    // HAXCMSFile.save preserves the bulk-import directory tree.
    define('HAXCMS_DRUPAL_FILES_PATH_REGEX', '#^/(?:sites/[^/]+/)?files/(.+)$#i');
    // system menus never describe the site outline
    define('HAXCMS_DRUPAL_MENU_ENDPOINT_PATTERNS', serialize(array(
        '/jsonapi/menu_items/',
        '/jsonapi/menu/',
        '/jsonapi/jsonapi_menu/',
    )));
}

if (!function_exists('haxcmsImportConvertDrupalToSite')) {
    /**
     * Safety valves, mirroring LIMITS in haxcms-nodejs convertDrupalToSite.js.
     * $GLOBALS['HAXCMS_DRUPAL_LIMITS'] overrides them so tests can exercise
     * the file cap the way the Node tests mutate the exported object.
     */
    function haxcmsImportDrupalLimits()
    {
        $defaults = array('maxFiles' => 2000);
        if (isset($GLOBALS['HAXCMS_DRUPAL_LIMITS']) && is_array($GLOBALS['HAXCMS_DRUPAL_LIMITS'])) {
            return array_merge($defaults, $GLOBALS['HAXCMS_DRUPAL_LIMITS']);
        }
        return $defaults;
    }

    /**
     * POST /system/api/v1/site/import/drupal
     * Convert a general Drupal site (JSON:API) into a HAXcms site schema.
     *
     * Expects a JSON body with a `repoUrl` param pointing at any URL of a
     * Drupal site with JSON:API exposed. Pages come from node--page plus
     * node--book when present, files from file--file (handed to createSite's
     * build.files pipeline), and media embeds (<drupal-media> placeholders +
     * field_media references) resolve into HAX media elements. The outline
     * follows the site's own menu structure: a menu-items endpoint
     * (jsonapi_menu_items / jsonapi_frontend_menu / jsonapi_menu) when one
     * of those modules is installed, otherwise menu_link_content records,
     * book fields, or a flat page list.
     *
     * Returns { status: 200, data: { items, filename, files, drupal } }.
     */
    function haxcmsImportConvertDrupalToSite($context)
    {
        $client = new \GuzzleHttp\Client(array('timeout' => 30, 'connect_timeout' => 10));
        haxcmsImportDrupalRun($context, $client);
    }

    /**
     * The import, with the HTTP client supplied by the caller so tests can
     * drive the whole route through a Guzzle handler (the seam
     * ConvertVitepressToSiteTest established).
     */
    function haxcmsImportDrupalRun($context, $client)
    {
        $body = isset($context->body) && is_array($context->body) ? $context->body : array();
        $repoUrl = isset($body['repoUrl']) ? trim((string) $body['repoUrl']) : '';
        if ($repoUrl === '') {
            haxcmsImportDrupalSendError($context, 400, 'missing `repoUrl` param');
            return;
        }
        $parentId = (isset($body['parentId']) && $body['parentId'] !== null && $body['parentId'] !== 'null')
            ? (string) $body['parentId'] : null;

        $imported = haxcmsImportDrupalImportSite($client, $repoUrl, $parentId);
        if ($imported === null) {
            haxcmsImportDrupalSendError($context, 400, 'Drupal import failed to produce content');
            return;
        }
        if (isset($imported['error'])) {
            haxcmsImportDrupalSendError($context, 400, $imported['error']);
            return;
        }
        if (!is_array($imported['items']) || count($imported['items']) === 0) {
            haxcmsImportDrupalSendError($context, 400, 'Drupal import produced no pages to import');
            return;
        }

        $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/system/api';
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 200, 'data' => array(
                'items'    => $imported['items'],
                'filename' => $imported['filename'],
                'files'    => $imported['files'],
                'drupal'   => $imported['drupal'],
            )),
            array('statusCode' => 200, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
    }

    function haxcmsImportDrupalSendError($context, $status, $message)
    {
        $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/system/api';
        SiteRouteUtils::sendFormattedResponse(
            array('status' => $status, 'data' => array(
                'error'    => $message,
                'items'    => array(),
                'filename' => null,
                'files'    => array(),
            )),
            array('statusCode' => $status, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
    }

    // ---- JSON:API discovery and collection pagination ----

    function haxcmsImportDrupalFetchJSON($client, $url)
    {
        try {
            $response = SsrfGuard::safeGuzzleRequest($client, 'GET', $url, array(
                'headers'     => array('Accept' => 'application/vnd.api+json,application/json'),
                'http_errors' => false,
            ));
        } catch (\Exception $e) {
            return null;
        }
        $status = (int) $response->getStatusCode();
        if ($status < 200 || $status > 299) {
            return null;
        }
        $decoded = json_decode((string) $response->getBody(), true);
        return is_array($decoded) ? $decoded : null;
    }

    function haxcmsImportDrupalBuildBaseCandidates($inputUrl)
    {
        $candidates = array();
        $parsed = parse_url($inputUrl);
        if ($parsed === false || !isset($parsed['scheme']) || !isset($parsed['host'])) {
            return $candidates;
        }
        $origin = $parsed['scheme'] . '://' . $parsed['host'];
        if (isset($parsed['port'])) {
            $origin .= ':' . $parsed['port'];
        }
        $pathParts = isset($parsed['path'])
            ? array_values(array_filter(explode('/', $parsed['path']), function ($piece) {
                return $piece !== '';
            }))
            : array();
        for ($i = count($pathParts); $i >= 0; $i -= 1) {
            $candidate = $i > 0 ? $origin . '/' . implode('/', array_slice($pathParts, 0, $i)) : $origin;
            if (!in_array($candidate, $candidates, true)) {
                $candidates[] = $candidate;
            }
        }
        return $candidates;
    }

    function haxcmsImportDrupalDiscoverBase($client, $inputUrl)
    {
        $candidates = haxcmsImportDrupalBuildBaseCandidates($inputUrl);
        foreach ($candidates as $candidate) {
            $payload = haxcmsImportDrupalFetchJSON($client, $candidate . '/jsonapi');
            if (is_array($payload) && isset($payload['links']) && is_array($payload['links'])) {
                return array('base' => $candidate, 'discovery' => $payload);
            }
        }
        return null;
    }

    function haxcmsImportDrupalGetDiscoveryLinks($discoveryPayload)
    {
        if (!is_array($discoveryPayload) || !isset($discoveryPayload['links']) || !is_array($discoveryPayload['links'])) {
            return array();
        }
        return $discoveryPayload['links'];
    }

    function haxcmsImportDrupalWithPageLimit($url, $pageLimit = 50)
    {
        if (strpos($url, 'page[limit]') !== false || strpos($url, 'page%5Blimit%5D') !== false) {
            return $url;
        }
        return $url . (strpos($url, '?') === false ? '?' : '&') . 'page[limit]=' . intval($pageLimit);
    }

    function haxcmsImportDrupalFetchCollectionByHref($client, $href, $pageLimit = 50, $maxPages = 200)
    {
        if (!$href || !is_string($href)) {
            return array();
        }
        $requestUrl = haxcmsImportDrupalWithPageLimit($href, $pageLimit);
        $page = 0;
        $items = array();
        while ($requestUrl !== null && $requestUrl !== '' && $page < $maxPages) {
            $page += 1;
            $payload = haxcmsImportDrupalFetchJSON($client, $requestUrl);
            if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data'])) {
                break;
            }
            foreach ($payload['data'] as $record) {
                $items[] = $record;
            }
            $nextHref = null;
            if (isset($payload['links']['next']['href']) && is_string($payload['links']['next']['href'])) {
                $nextHref = $payload['links']['next']['href'];
            }
            $requestUrl = $nextHref;
        }
        return $items;
    }

    function haxcmsImportDrupalGetNodeCollectionLinks($discoveryLinks)
    {
        $links = array();
        foreach ($discoveryLinks as $key => $linkDef) {
            if (strpos((string) $key, 'node--') !== 0) {
                continue;
            }
            if (is_array($linkDef) && isset($linkDef['href'])) {
                $links[$key] = $linkDef['href'];
            }
        }
        return $links;
    }

    function haxcmsImportDrupalGetMenuLinkContentHref($discoveryLinks)
    {
        if (isset($discoveryLinks['menu_link_content--menu_link_content'])
            && is_array($discoveryLinks['menu_link_content--menu_link_content'])
            && isset($discoveryLinks['menu_link_content--menu_link_content']['href'])) {
            return $discoveryLinks['menu_link_content--menu_link_content']['href'];
        }
        return null;
    }

    // ---- node record helpers ----

    function haxcmsImportDrupalGetNodeNid($record)
    {
        if (!is_array($record) || !isset($record['attributes']) || !is_array($record['attributes'])) {
            return 0;
        }
        return (int) $record['attributes']['drupal_internal__nid'];
    }

    function haxcmsImportDrupalGetNodeTitle($record)
    {
        $title = is_array($record) && isset($record['attributes']['title'])
            ? trim((string) $record['attributes']['title']) : '';
        if ($title !== '') {
            return $title;
        }
        $nid = haxcmsImportDrupalGetNodeNid($record);
        return $nid > 0 ? 'Node ' . $nid : 'Node';
    }

    function haxcmsImportDrupalSortNodeRecords($records)
    {
        $sorted = array_values($records);
        usort($sorted, function ($a, $b) {
            $titleA = strtolower(haxcmsImportDrupalGetNodeTitle($a));
            $titleB = strtolower(haxcmsImportDrupalGetNodeTitle($b));
            if ($titleA === $titleB) {
                return haxcmsImportDrupalGetNodeNid($a) - haxcmsImportDrupalGetNodeNid($b);
            }
            return $titleA < $titleB ? -1 : 1;
        });
        return $sorted;
    }

    function haxcmsImportDrupalSortNodeRecordsByCreated($records)
    {
        $sorted = array_values($records);
        usort($sorted, function ($a, $b) {
            $createdA = is_array($a) && isset($a['attributes']['created']) && is_string($a['attributes']['created'])
                ? $a['attributes']['created'] : '';
            $createdB = is_array($b) && isset($b['attributes']['created']) && is_string($b['attributes']['created'])
                ? $b['attributes']['created'] : '';
            if ($createdA === $createdB) {
                return haxcmsImportDrupalGetNodeNid($a) - haxcmsImportDrupalGetNodeNid($b);
            }
            return $createdA < $createdB ? -1 : 1;
        });
        return $sorted;
    }

    function haxcmsImportDrupalGetNodeSegment($record)
    {
        $segment = '';
        if (is_array($record) && isset($record['attributes']['path']['alias'])
            && is_string($record['attributes']['path']['alias'])) {
            $parts = array_values(array_filter(explode('/', $record['attributes']['path']['alias']), function ($piece) {
                return $piece !== '';
            }));
            if (count($parts) > 0) {
                $segment = haxcmsImportCleanTitle($parts[count($parts) - 1]);
            }
        }
        if ($segment === '' || $segment === null) {
            $segment = haxcmsImportCleanTitle(haxcmsImportDrupalGetNodeTitle($record));
        }
        if ($segment === '' || $segment === null) {
            $nid = haxcmsImportDrupalGetNodeNid($record);
            $segment = $nid > 0 ? 'node-' . $nid : 'node';
        }
        return $segment;
    }

    function haxcmsImportDrupalUniqueSegment($segment, &$siblingMap, $nid)
    {
        $candidate = $segment;
        if (!isset($siblingMap[$candidate])) {
            $siblingMap[$candidate] = true;
            return $candidate;
        }
        $candidate = $segment . '-' . $nid;
        if (!isset($siblingMap[$candidate])) {
            $siblingMap[$candidate] = true;
            return $candidate;
        }
        $i = 2;
        while (isset($siblingMap[$candidate . '-' . $i])) {
            $i += 1;
        }
        $candidate = $candidate . '-' . $i;
        $siblingMap[$candidate] = true;
        return $candidate;
    }

    function haxcmsImportDrupalFormatNodeMetadata($record, $sourceType, $base, $extra = array())
    {
        $attrs = is_array($record) && isset($record['attributes']) ? $record['attributes'] : array();
        $nid = haxcmsImportDrupalGetNodeNid($record);
        $drupal = array(
            'nid'  => $nid,
            'uuid' => is_array($record) && isset($record['id']) ? $record['id'] : null,
            'type' => is_array($record) && isset($record['type']) ? $record['type'] : null,
        );
        foreach ($extra as $key => $value) {
            $drupal[$key] = $value;
        }
        return array(
            'sourceType' => $sourceType,
            'source'     => $nid > 0 ? $base . '/node/' . $nid : null,
            'published'  => (is_array($attrs) && isset($attrs['status']) && $attrs['status'] === false) ? false : true,
            'drupal'     => $drupal,
        );
    }

    function haxcmsImportDrupalGetFilenameFromUrl($repoUrl)
    {
        $parsed = parse_url($repoUrl);
        if ($parsed !== false && isset($parsed['host'])) {
            $pathParts = isset($parsed['path'])
                ? array_values(array_filter(explode('/', $parsed['path']), function ($piece) {
                    return $piece !== '';
                }))
                : array();
            if (count($pathParts) > 0) {
                $last = rawurldecode($pathParts[count($pathParts) - 1]);
                if (strtolower($last) === 'jsonapi') {
                    // /<base>/jsonapi alone names no site; fall to the host
                    $last = count($pathParts) > 1 ? rawurldecode($pathParts[count($pathParts) - 2]) : '';
                }
                if ($last !== '') {
                    $clean = haxcmsImportCleanTitle($last);
                    if ($clean !== '' && $clean !== null && $clean !== 'blank') {
                        return $clean;
                    }
                }
            }
            $hostClean = haxcmsImportCleanTitle($parsed['host']);
            if ($hostClean !== '' && $hostClean !== null && $hostClean !== 'blank') {
                return $hostClean;
            }
        }
        return 'drupal-import';
    }

    // ---- file map (file--file -> build.files entries) ----

    function haxcmsImportDrupalStripUrlExtras($value)
    {
        $v = trim((string) $value);
        $hashIndex = strpos($v, '#');
        if ($hashIndex !== false) {
            $v = substr($v, 0, $hashIndex);
        }
        $queryIndex = strpos($v, '?');
        if ($queryIndex !== false) {
            $v = substr($v, 0, $queryIndex);
        }
        return $v;
    }

    function haxcmsImportDrupalPathnameFromFileUrl($urlValue)
    {
        $v = haxcmsImportDrupalStripUrlExtras($urlValue);
        if ($v === '') {
            return null;
        }
        if (strpos($v, '//') === 0) {
            return null;
        }
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $v)) {
            $parsed = parse_url($v);
            if ($parsed === false || !isset($parsed['path'])) {
                return null;
            }
            return $parsed['path'];
        }
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $v)) {
            return null;
        }
        if (strlen($v) === 0 || $v[0] !== '/') {
            return null;
        }
        return $v;
    }

    function haxcmsImportDrupalFileKeyFromPath($pathname)
    {
        $matches = array();
        if (preg_match(HAXCMS_DRUPAL_FILES_PATH_REGEX, $pathname, $matches) && isset($matches[1]) && $matches[1] !== '') {
            $key = $matches[1];
        } else {
            $key = ltrim($pathname, '/');
        }
        $key = (string) $key;
        if ($key === '' || strpos($key, '..') !== false || strpos($key, "\0") !== false) {
            return null;
        }
        return $key;
    }

    function haxcmsImportDrupalAbsolutizeFileUrl($urlValue, $base)
    {
        $v = (string) $urlValue;
        if (preg_match('#^https?://#i', $v)) {
            return $v;
        }
        $parsed = parse_url($base);
        if ($parsed === false || !isset($parsed['scheme']) || !isset($parsed['host'])) {
            return $v;
        }
        $origin = $parsed['scheme'] . '://' . $parsed['host'];
        if (isset($parsed['port'])) {
            $origin .= ':' . $parsed['port'];
        }
        return $origin . ($v !== '' && $v[0] !== '/' ? '/' : '') . $v;
    }

    function haxcmsImportDrupalBuildFileMap($fileRecords, $base)
    {
        $files = array();
        $filesByUuid = array();
        $filesByPath = array();
        $skipped = array();
        $truncated = false;
        $limits = haxcmsImportDrupalLimits();
        if (!is_array($fileRecords)) {
            return array(
                'files' => $files, 'filesByUuid' => $filesByUuid, 'filesByPath' => $filesByPath,
                'skipped' => $skipped, 'truncated' => $truncated,
            );
        }
        foreach ($fileRecords as $record) {
            if (!is_array($record) || !isset($record['attributes']) || !is_array($record['attributes'])) {
                continue;
            }
            $attrs = $record['attributes'];
            if (!isset($attrs['status']) || $attrs['status'] !== true) {
                $skipped['temporary'] = (isset($skipped['temporary']) ? $skipped['temporary'] : 0) + 1;
                continue;
            }
            $urlValue = isset($attrs['uri']['url']) && is_string($attrs['uri']['url']) && $attrs['uri']['url'] !== ''
                ? $attrs['uri']['url'] : '';
            if ($urlValue === '') {
                // private streams and unserved files expose no url
                $skipped['no-url'] = (isset($skipped['no-url']) ? $skipped['no-url'] : 0) + 1;
                continue;
            }
            $pathname = haxcmsImportDrupalPathnameFromFileUrl($urlValue);
            if ($pathname === null) {
                $skipped['no-url'] = (isset($skipped['no-url']) ? $skipped['no-url'] : 0) + 1;
                continue;
            }
            $key = haxcmsImportDrupalFileKeyFromPath($pathname);
            if ($key === null) {
                $skipped['invalid-path'] = (isset($skipped['invalid-path']) ? $skipped['invalid-path'] : 0) + 1;
                continue;
            }
            if (!preg_match(HAXCMS_DRUPAL_SAFE_BULK_IMPORT_EXTENSION_REGEX, $key)) {
                $skipped['extension'] = (isset($skipped['extension']) ? $skipped['extension'] : 0) + 1;
                continue;
            }
            if (isset($files[$key])) {
                $skipped['duplicate'] = (isset($skipped['duplicate']) ? $skipped['duplicate'] : 0) + 1;
                continue;
            }
            if (count($files) >= $limits['maxFiles']) {
                $truncated = true;
                continue;
            }
            $absoluteUrl = haxcmsImportDrupalAbsolutizeFileUrl($urlValue, $base);
            $files[$key] = $absoluteUrl;
            $filesByPath[$pathname] = 'files/' . $key;
            if (isset($record['id'])) {
                $filesByUuid[$record['id']] = array(
                    'key'      => $key,
                    'ref'      => 'files/' . $key,
                    'url'      => $absoluteUrl,
                    'filename' => isset($attrs['filename']) && is_string($attrs['filename']) ? $attrs['filename'] : '',
                );
            }
        }
        return array(
            'files' => $files, 'filesByUuid' => $filesByUuid, 'filesByPath' => $filesByPath,
            'skipped' => $skipped, 'truncated' => $truncated,
        );
    }

    // ---- media resolution (media--* -> HAX media elements) ----

    function haxcmsImportDrupalMediaTitleFromAttrs($attrs)
    {
        return isset($attrs['name']) && is_string($attrs['name']) && $attrs['name'] !== '' ? $attrs['name'] : '';
    }

    function haxcmsImportDrupalFirstRelationshipData($rel)
    {
        if (!is_array($rel)) {
            return null;
        }
        if (isset($rel['data']) && is_array($rel['data']) && isset($rel['data']['id'])) {
            return $rel['data'];
        }
        if (isset($rel['data']) && is_array($rel['data']) && count($rel['data']) > 0
            && is_array($rel['data'][0]) && isset($rel['data'][0]['id'])) {
            return $rel['data'][0];
        }
        return null;
    }

    function haxcmsImportDrupalMediaBundleKinds()
    {
        return array(
            'image'        => 'image',
            'document'     => 'document',
            'video'        => 'video',
            'audio'        => 'audio',
            'remote_video' => 'remote-video',
        );
    }

    function haxcmsImportDrupalMediaFileFields()
    {
        return array(
            'image'    => array('field_media_image', 'thumbnail'),
            'document' => array('field_media_document'),
            'video'    => array('field_media_video'),
            'audio'    => array('field_media_audio'),
        );
    }

    function haxcmsImportDrupalRenderMediaElement($kind, $ref, $alt, $title)
    {
        if ($kind === 'image') {
            return '<media-image source="' . SanitizeContent::escapeHTMLAttribute($ref)
                . '" alt="' . SanitizeContent::escapeHTMLAttribute($alt) . '"></media-image>';
        }
        if ($kind === 'video') {
            return '<video-player source="' . SanitizeContent::escapeHTMLAttribute($ref)
                . '" media-title="' . SanitizeContent::escapeHTMLAttribute($title) . '"></video-player>';
        }
        if ($kind === 'audio') {
            return '<media-playlist><audio-player source="' . SanitizeContent::escapeHTMLAttribute($ref)
                . '" media-title="' . SanitizeContent::escapeHTMLAttribute($title)
                . '"></audio-player></media-playlist>';
        }
        // document (and unknown file-bearing bundles) resolve to a link
        return '<a href="' . SanitizeContent::escapeHTMLAttribute($ref) . '">'
            . SanitizeContent::escapeHTMLAttribute($title) . '</a>';
    }

    function haxcmsImportDrupalResolveMediaRecord($record, $fileMap)
    {
        $type = is_array($record) && isset($record['type']) && is_string($record['type']) ? $record['type'] : '';
        $bundle = strpos($type, '--') !== false ? substr($type, strrpos($type, '--') + 2) : '';
        $kinds = haxcmsImportDrupalMediaBundleKinds();
        $kind = isset($kinds[$bundle]) ? $kinds[$bundle] : 'file';
        $attrs = is_array($record) && isset($record['attributes']) && is_array($record['attributes'])
            ? $record['attributes'] : array();
        $rels = is_array($record) && isset($record['relationships']) && is_array($record['relationships'])
            ? $record['relationships'] : array();

        if ($kind === 'remote-video') {
            $oembed = isset($attrs['field_media_oembed_video']) ? $attrs['field_media_oembed_video'] : null;
            $url = is_string($oembed) ? $oembed
                : (is_array($oembed) && isset($oembed['value']) && is_string($oembed['value']) ? $oembed['value'] : '');
            if ($url !== '' && preg_match('#^https?://#i', $url)) {
                $title = haxcmsImportDrupalMediaTitleFromAttrs($attrs) !== '' ? haxcmsImportDrupalMediaTitleFromAttrs($attrs) : $url;
                return array(
                    'kind'     => $kind,
                    'ref'      => $url,
                    'external' => true,
                    'title'    => $title,
                    'alt'      => '',
                    'element'  => haxcmsImportDrupalRenderMediaElement('video', $url, '', $title),
                );
            }
            return null;
        }

        $fileData = null;
        $fields = haxcmsImportDrupalMediaFileFields();
        $preferred = isset($fields[$kind]) ? $fields[$kind] : array();
        foreach ($preferred as $fieldName) {
            $data = haxcmsImportDrupalFirstRelationshipData(isset($rels[$fieldName]) ? $rels[$fieldName] : null);
            if (is_array($data) && isset($data['type']) && $data['type'] === 'file--file') {
                $fileData = $data;
                break;
            }
        }
        if (!is_array($fileData)) {
            foreach ($rels as $rel) {
                $data = haxcmsImportDrupalFirstRelationshipData($rel);
                if (is_array($data) && isset($data['type']) && $data['type'] === 'file--file') {
                    $fileData = $data;
                    break;
                }
            }
        }
        if (!is_array($fileData) || !isset($fileData['id'])) {
            return null;
        }
        if (!isset($fileMap['filesByUuid'][$fileData['id']])) {
            // file was skipped (temporary, disallowed extension, private stream)
            return null;
        }
        $fileEntry = $fileMap['filesByUuid'][$fileData['id']];
        $meta = isset($fileData['meta']) && is_array($fileData['meta']) ? $fileData['meta'] : array();
        $title = '';
        if (isset($meta['title']) && is_string($meta['title']) && $meta['title'] !== '') {
            $title = $meta['title'];
        } elseif (haxcmsImportDrupalMediaTitleFromAttrs($attrs) !== '') {
            $title = haxcmsImportDrupalMediaTitleFromAttrs($attrs);
        } elseif ($fileEntry['filename'] !== '') {
            $title = $fileEntry['filename'];
        } else {
            $title = $fileEntry['key'];
        }
        $alt = isset($meta['alt']) && is_string($meta['alt']) ? $meta['alt'] : '';
        return array(
            'kind'    => $kind,
            'ref'     => $fileEntry['ref'],
            'title'   => $title,
            'alt'     => $alt,
            'element' => haxcmsImportDrupalRenderMediaElement($kind, $fileEntry['ref'], $alt, $title),
        );
    }

    function haxcmsImportDrupalBuildMediaMap($mediaRecords, $fileMap)
    {
        $mediaByUuid = array();
        $stats = array('total' => 0, 'resolved' => 0, 'unresolved' => 0);
        if (!is_array($mediaRecords)) {
            return array('mediaByUuid' => $mediaByUuid, 'stats' => $stats);
        }
        foreach ($mediaRecords as $record) {
            if (!is_array($record) || !isset($record['id'])) {
                continue;
            }
            $stats['total'] += 1;
            $resolved = haxcmsImportDrupalResolveMediaRecord($record, $fileMap);
            if ($resolved !== null) {
                $stats['resolved'] += 1;
                $mediaByUuid[$record['id']] = $resolved;
            } else {
                $stats['unresolved'] += 1;
                $mediaByUuid[$record['id']] = null;
            }
        }
        return array('mediaByUuid' => $mediaByUuid, 'stats' => $stats);
    }

    // ---- page content assembly ----

    function haxcmsImportDrupalGetNodeBodyValue($record)
    {
        if (!is_array($record) || !isset($record['attributes'])) {
            return '';
        }
        $attrs = $record['attributes'];
        if (isset($attrs['body']) && is_array($attrs['body']) && isset($attrs['body']['value'])
            && is_string($attrs['body']['value'])) {
            return $attrs['body']['value'];
        }
        if (isset($attrs['body']) && is_string($attrs['body'])) {
            return $attrs['body'];
        }
        return '';
    }

    function haxcmsImportDrupalExtractUuidFromAttrs($attrs)
    {
        if (preg_match('/data-entity-uuid\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', (string) $attrs, $matches)) {
            return isset($matches[1]) && $matches[1] !== '' ? $matches[1]
                : (isset($matches[2]) ? $matches[2] : '');
        }
        return '';
    }

    /**
     * Replace <drupal-media data-entity-uuid="..."> placeholders with the
     * resolved HAX media element (or nothing when the media cannot resolve).
     */
    function haxcmsImportDrupalReplaceMediaEmbeds($content, $mediaByUuid)
    {
        if ($content === '' || $content === null) {
            return $content;
        }
        if (strpos($content, '<drupal-media') === false) {
            return $content;
        }
        return preg_replace_callback(
            '/<drupal-media\b([^>]*)>(.*?)<\/drupal-media\s*>|<drupal-media\b([^>]*)\/>/is',
            function ($matches) use ($mediaByUuid) {
                $attrs = isset($matches[1]) && $matches[1] !== '' ? $matches[1]
                    : (isset($matches[3]) ? $matches[3] : '');
                $uuid = haxcmsImportDrupalExtractUuidFromAttrs($attrs);
                $media = $uuid !== '' && is_array($mediaByUuid) && isset($mediaByUuid[$uuid])
                    ? $mediaByUuid[$uuid] : null;
                if (is_array($media) && isset($media['element'])) {
                    return $media['element'];
                }
                return '';
            },
            $content
        );
    }

    function haxcmsImportDrupalFileReferenceFor($value, $fileMap)
    {
        $v = haxcmsImportDrupalStripUrlExtras($value);
        if ($v === '') {
            return null;
        }
        if (strpos($v, '//') === 0) {
            return null;
        }
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $v)) {
            $parsed = parse_url($v);
            if ($parsed === false || !isset($parsed['path'])) {
                return null;
            }
            return isset($fileMap['filesByPath'][$parsed['path']]) ? $fileMap['filesByPath'][$parsed['path']] : null;
        }
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $v)) {
            return null;
        }
        $pathname = ltrim($v, '/');
        if ($pathname === '') {
            return null;
        }
        $key = '/' . $pathname;
        return isset($fileMap['filesByPath'][$key]) ? $fileMap['filesByPath'][$key] : null;
    }

    function haxcmsImportDrupalRewriteSrcsetValue($srcset, $fileMap)
    {
        $parts = explode(',', (string) $srcset);
        $rewritten = array();
        foreach ($parts as $part) {
            $trimmed = trim($part);
            if ($trimmed === '') {
                continue;
            }
            $pieces = preg_split('/\s+/', $trimmed);
            $ref = haxcmsImportDrupalFileReferenceFor($pieces[0], $fileMap);
            if ($ref !== null) {
                $pieces[0] = $ref;
            }
            $rewritten[] = implode(' ', $pieces);
        }
        return implode(', ', $rewritten);
    }

    /**
     * Rewrite src/href/poster/source (and srcset candidates) that point at
     * known file--file URLs into their files/... site-relative references, so
     * the imported pages link at the downloaded assets and the PHP
     * FileContentScanner can resolve page.metadata.files after the
     * build.files ingest.
     */
    function haxcmsImportDrupalRewriteFileReferences($content, $fileMap)
    {
        if ($content === '' || $content === null) {
            return '';
        }
        if (!is_array($fileMap['filesByPath']) || count($fileMap['filesByPath']) === 0) {
            return $content;
        }
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->recover = true;
        $wrapped = '<?xml encoding="UTF-8"?><div id="drupal-import-wrapper">' . $content . '</div>';
        if (!@$dom->loadHTML($wrapped)) {
            return $content;
        }
        $wrapper = $dom->getElementById('drupal-import-wrapper');
        if (!$wrapper) {
            return $content;
        }
        $elements = $wrapper->getElementsByTagName('*');
        for ($i = 0; $i < $elements->length; $i += 1) {
            $el = $elements->item($i);
            foreach (array('src', 'href', 'poster', 'source') as $attr) {
                if (!$el->hasAttribute($attr)) {
                    continue;
                }
                $value = $el->getAttribute($attr);
                if ($value === '') {
                    continue;
                }
                $ref = haxcmsImportDrupalFileReferenceFor($value, $fileMap);
                if ($ref !== null) {
                    $el->setAttribute($attr, $ref);
                }
            }
            if ($el->hasAttribute('srcset')) {
                $srcset = $el->getAttribute('srcset');
                if ($srcset !== '') {
                    $rewritten = haxcmsImportDrupalRewriteSrcsetValue($srcset, $fileMap);
                    if ($rewritten !== $srcset) {
                        $el->setAttribute('srcset', $rewritten);
                    }
                }
            }
        }
        $inner = '';
        foreach ($wrapper->childNodes as $child) {
            $inner .= $dom->saveHTML($child);
        }
        return $inner;
    }

    /**
     * Pages that carry their content as a field_media reference instead of a
     * body (common on modern Drupal sites) render the referenced media.
     */
    function haxcmsImportDrupalRenderFieldMediaElements($record, $mediaByUuid)
    {
        $rels = is_array($record) && isset($record['relationships']) && is_array($record['relationships'])
            ? $record['relationships'] : array();
        if (!isset($rels['field_media'])) {
            return array();
        }
        $data = haxcmsImportDrupalFirstRelationshipData($rels['field_media']);
        if (!is_array($data) || !isset($data['id'])) {
            return array();
        }
        if (is_array($mediaByUuid) && isset($mediaByUuid[$data['id']]) && is_array($mediaByUuid[$data['id']])
            && isset($mediaByUuid[$data['id']]['element'])) {
            return array($mediaByUuid[$data['id']]['element']);
        }
        return array();
    }

    function haxcmsImportDrupalAbsolutizeRootUrls($content, $base)
    {
        $parsed = parse_url($base);
        if ($parsed === false || !isset($parsed['scheme']) || !isset($parsed['host'])) {
            return $content;
        }
        $origin = $parsed['scheme'] . '://' . $parsed['host'];
        if (isset($parsed['port'])) {
            $origin .= ':' . $parsed['port'];
        }
        $content = str_replace('href="/', 'href="' . $origin . '/', $content);
        $content = str_replace('src="/', 'src="' . $origin . '/', $content);
        $content = str_replace('poster="/', 'poster="' . $origin . '/', $content);
        $content = str_replace('srcset="/', 'srcset="' . $origin . '/', $content);
        return $content;
    }

    function haxcmsImportDrupalBuildPageContent($record, $base, $mediaByUuid, $fileMap)
    {
        $content = '';
        $raw = haxcmsImportDrupalGetNodeBodyValue($record);
        if ($raw !== '') {
            $withoutEmbeds = haxcmsImportDrupalReplaceMediaEmbeds($raw, $mediaByUuid);
            $content = haxcmsImportDrupalRewriteFileReferences($withoutEmbeds, $fileMap);
        }
        if ($content === '') {
            $elements = haxcmsImportDrupalRenderFieldMediaElements($record, $mediaByUuid);
            if (count($elements) > 0) {
                $content = implode("\n", $elements);
            }
        }
        if ($content === '' || $content === null) {
            return '<p></p>';
        }
        return haxcmsImportDrupalAbsolutizeRootUrls($content, $base);
    }

    // ---- menu uri / plugin id parsing ----

    function haxcmsImportDrupalParseNodeIdFromMenuUri($uriValue)
    {
        if (!is_string($uriValue) || $uriValue === '') {
            return 0;
        }
        $uri = trim($uriValue);
        if (preg_match('/^entity:node\/(\d+)$/i', $uri, $matches)) {
            return (int) $matches[1];
        }
        if (preg_match('#^internal:/node/(\d+)$#i', $uri, $matches)) {
            return (int) $matches[1];
        }
        if (preg_match('#/node/(\d+)#i', $uri, $matches)) {
            return (int) $matches[1];
        }
        return 0;
    }

    function haxcmsImportDrupalParseParentLinkId($record)
    {
        if (!is_array($record)) {
            return '';
        }
        $attrs = isset($record['attributes']) && is_array($record['attributes']) ? $record['attributes'] : array();
        $rels = isset($record['relationships']) && is_array($record['relationships']) ? $record['relationships'] : array();
        if (isset($attrs['parent']) && is_string($attrs['parent']) && trim($attrs['parent']) !== '') {
            $parentValue = trim($attrs['parent']);
            if (strpos($parentValue, ':') !== false) {
                $parts = explode(':', $parentValue);
                return array_pop($parts);
            }
            return $parentValue;
        }
        if (isset($rels['parent']['data']['id']) && is_string($rels['parent']['data']['id'])) {
            return (string) $rels['parent']['data']['id'];
        }
        return '';
    }

    function haxcmsImportDrupalParsePluginIdValue($value)
    {
        if (!is_string($value) || $value === '') {
            return '';
        }
        $v = trim($value);
        if ($v === '') {
            return '';
        }
        if (strpos($v, ':') !== false) {
            $parts = explode(':', $v);
            return array_pop($parts);
        }
        return $v;
    }

    // ---- outline forests ----

    function haxcmsImportDrupalAddRelation(&$relationByNid, $nid, $parentNid, $weight)
    {
        if (!isset($relationByNid[$nid])) {
            $relationByNid[$nid] = array('parentNid' => $parentNid, 'weight' => $weight);
        } elseif ($weight < $relationByNid[$nid]['weight']) {
            $relationByNid[$nid]['weight'] = $weight;
        }
    }

    function haxcmsImportDrupalChildrenByParentFromRelations($relationByNid)
    {
        $buckets = array();
        foreach ($relationByNid as $nid => $rel) {
            if (!isset($buckets[$rel['parentNid']])) {
                $buckets[$rel['parentNid']] = array();
            }
            $buckets[$rel['parentNid']][] = array('nid' => (int) $nid, 'weight' => $rel['weight']);
        }
        $childrenByParent = array();
        foreach ($buckets as $parentNid => $entries) {
            usort($entries, function ($a, $b) {
                if ($a['weight'] === $b['weight']) {
                    return $a['nid'] - $b['nid'];
                }
                return $a['weight'] - $b['weight'];
            });
            $childrenByParent[(int) $parentNid] = array_map(function ($entry) {
                return $entry['nid'];
            }, $entries);
        }
        return $childrenByParent;
    }

    /**
     * Walk a parent plugin-id chain upward until a link that resolves to a
     * page in the pages set is found; views/system/external parents (no nid)
     * are skipped so their children promote to the nearest resolvable
     * ancestor.
     */
    function haxcmsImportDrupalResolveAncestorNid($parentPluginId, $itemByPluginId, $pageSet, $maxHops = 20)
    {
        $hops = 0;
        $current = $parentPluginId;
        while ($current !== null && $current !== '' && $hops < $maxHops) {
            $hops += 1;
            if (!isset($itemByPluginId[$current])) {
                return 0;
            }
            $parentItem = $itemByPluginId[$current];
            if ($parentItem['nid'] > 0 && isset($pageSet[$parentItem['nid']])) {
                return $parentItem['nid'];
            }
            $current = $parentItem['parentPluginId'];
        }
        return 0;
    }

    /**
     * menu link plugin ids like `menu_link_content:<uuid>` reduce to `<uuid>`
     * so parent references and item ids share one id space.
     */
    function haxcmsImportDrupalNormalizeMenuItem($raw, $aliasMap)
    {
        if (!is_array($raw)) {
            return null;
        }
        $attrs = isset($raw['attributes']) && is_array($raw['attributes']) ? $raw['attributes'] : $raw;
        $pluginId = haxcmsImportDrupalParsePluginIdValue(
            isset($raw['id']) && is_string($raw['id']) ? $raw['id']
                : (isset($attrs['id']) && is_string($attrs['id']) ? $attrs['id'] : '')
        );
        $parentPluginId = haxcmsImportDrupalParsePluginIdValue(
            isset($attrs['parent']) && is_string($attrs['parent']) ? $attrs['parent'] : ''
        );
        $weight = isset($attrs['weight']) ? (int) $attrs['weight'] : 0;
        $url = isset($attrs['url']) && is_string($attrs['url']) ? $attrs['url'] : '';
        $route = isset($attrs['route']) && is_array($attrs['route']) ? $attrs['route'] : null;
        $nid = 0;
        if (is_array($route) && isset($route['name']) && $route['name'] === 'entity.node.canonical'
            && isset($route['parameters']) && is_array($route['parameters'])
            && isset($route['parameters']['node'])) {
            $nid = (int) $route['parameters']['node'];
        }
        if ($nid === 0 && $url !== '') {
            $nid = haxcmsImportDrupalParseNodeIdFromMenuUri($url);
        }
        if ($nid === 0 && $url !== '' && isset($aliasMap[$url])) {
            $nid = (int) $aliasMap[$url];
        }
        $children = null;
        if (isset($attrs['children']) && is_array($attrs['children'])) {
            $children = $attrs['children'];
        } elseif (isset($raw['children']) && is_array($raw['children'])) {
            $children = $raw['children'];
        }
        $normalized = array(
            'pluginId'       => $pluginId,
            'parentPluginId' => $parentPluginId,
            'weight'         => $weight,
            'title'          => isset($attrs['title']) && is_string($attrs['title']) ? $attrs['title'] : '',
            'url'            => $url,
            'nid'            => $nid,
            'children'       => null,
        );
        if ($children !== null) {
            $normalizedChildren = array();
            foreach ($children as $child) {
                $normalizedChild = haxcmsImportDrupalNormalizeMenuItem($child, $aliasMap);
                if ($normalizedChild !== null) {
                    $normalizedChildren[] = $normalizedChild;
                }
            }
            $normalized['children'] = $normalizedChildren;
        }
        return $normalized;
    }

    function haxcmsImportDrupalWalkMenuItemTree($items, $parentNid, $pageSet, &$relationByNid)
    {
        foreach ($items as $item) {
            if ($item['nid'] > 0 && isset($pageSet[$item['nid']])) {
                haxcmsImportDrupalAddRelation($relationByNid, $item['nid'], $parentNid, $item['weight']);
            }
            $nextParentNid = ($item['nid'] > 0 && isset($pageSet[$item['nid']])) ? $item['nid'] : $parentNid;
            if (is_array($item['children']) && count($item['children']) > 0) {
                haxcmsImportDrupalWalkMenuItemTree($item['children'], $nextParentNid, $pageSet, $relationByNid);
            }
        }
    }

    /**
     * Accepts jsonapi_menu_items-style flat collections (parent plugin ids)
     * and jsonapi_frontend_menu-style nested children payloads alike.
     */
    function haxcmsImportDrupalForestFromMenuItems($rawItems, $pageSet, $aliasMap)
    {
        $items = array();
        foreach ($rawItems as $raw) {
            $normalized = haxcmsImportDrupalNormalizeMenuItem($raw, $aliasMap);
            if ($normalized !== null) {
                $items[] = $normalized;
            }
        }
        if (count($items) === 0) {
            return null;
        }
        $itemByPluginId = array();
        foreach ($items as $item) {
            if ($item['pluginId'] !== '') {
                $itemByPluginId[$item['pluginId']] = $item;
            }
        }
        $relationByNid = array();
        $hasChildren = false;
        foreach ($items as $item) {
            if (is_array($item['children']) && count($item['children']) > 0) {
                $hasChildren = true;
                break;
            }
        }
        if ($hasChildren) {
            haxcmsImportDrupalWalkMenuItemTree($items, 0, $pageSet, $relationByNid);
        } else {
            foreach ($items as $item) {
                if ($item['nid'] <= 0 || !isset($pageSet[$item['nid']])) {
                    continue;
                }
                $parentNid = haxcmsImportDrupalResolveAncestorNid($item['parentPluginId'], $itemByPluginId, $pageSet);
                haxcmsImportDrupalAddRelation($relationByNid, $item['nid'], $parentNid, $item['weight']);
            }
        }
        if (count($relationByNid) === 0) {
            return null;
        }
        return array(
            'childrenByParent' => haxcmsImportDrupalChildrenByParentFromRelations($relationByNid),
            'source'            => 'menu-items',
        );
    }

    function haxcmsImportDrupalExtractMenuItemsList($payload)
    {
        if (is_array($payload)) {
            // a top-level JSON array decodes to a sequential array
            // (jsonapi_frontend_menu-style nested trees)
            $keys = array_keys($payload);
            if ($keys === range(0, count($payload) - 1)) {
                return $payload;
            }
        }
        if (is_array($payload) && isset($payload['data']) && is_array($payload['data'])) {
            return $payload['data'];
        }
        if (is_array($payload) && isset($payload['items']) && is_array($payload['items'])) {
            return $payload['items'];
        }
        return null;
    }

    function haxcmsImportDrupalMenuNameDenylist()
    {
        return array('admin' => true, 'tools' => true, 'account' => true, 'devel' => true);
    }

    function haxcmsImportDrupalBuildMenuNameCandidates($menuRecords)
    {
        $names = array('main');
        $seen = array('main' => true);
        $denylist = haxcmsImportDrupalMenuNameDenylist();
        if (is_array($menuRecords)) {
            foreach ($menuRecords as $record) {
                if (!is_array($record) || !isset($record['attributes'])) {
                    continue;
                }
                $name = isset($record['attributes']['drupal_internal__id']) && is_string($record['attributes']['drupal_internal__id'])
                    ? $record['attributes']['drupal_internal__id'] : '';
                if ($name === '' && isset($record['id']) && is_string($record['id'])) {
                    $name = $record['id'];
                }
                if ($name === '') {
                    continue;
                }
                $name = trim($name);
                if (isset($seen[$name]) || isset($denylist[$name])) {
                    continue;
                }
                $seen[$name] = true;
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * Probe the menu-item endpoint variants per candidate menu; the first
     * menu that yields page-linked items wins. Probes 404 cheaply when the
     * module is not installed (grovecenter-style sites) and fall through to
     * menu_link_content records.
     */
    function haxcmsImportDrupalFetchMenuItemsForest($client, $base, $menuRecords, $pageSet, $aliasMap)
    {
        $menuNames = haxcmsImportDrupalBuildMenuNameCandidates($menuRecords);
        $patterns = unserialize(HAXCMS_DRUPAL_MENU_ENDPOINT_PATTERNS);
        foreach ($menuNames as $menuName) {
            foreach ($patterns as $endpointPath) {
                $payload = haxcmsImportDrupalFetchJSON($client, $base . $endpointPath . rawurlencode($menuName));
                $rawItems = haxcmsImportDrupalExtractMenuItemsList($payload);
                if ($rawItems === null) {
                    continue;
                }
                $forest = haxcmsImportDrupalForestFromMenuItems($rawItems, $pageSet, $aliasMap);
                // a valid payload means this module serves this menu; other
                // endpoint patterns for the same menu are pointless even when
                // nothing linked
                if ($forest !== null) {
                    return $forest;
                }
                break;
            }
        }
        return null;
    }

    /**
     * menu_link_content records -> forest. All top-level links of the
     * preferred menu become top-level outline items; pages linked only in
     * other menus are appended after them so no menu-linked page drops out.
     */
    function haxcmsImportDrupalForestFromMenuLinks($pageSet, $menuLinkRecords)
    {
        if (!is_array($menuLinkRecords) || count($menuLinkRecords) === 0) {
            return null;
        }
        $groups = array();
        foreach ($menuLinkRecords as $record) {
            if (!is_array($record) || !isset($record['attributes'])) {
                continue;
            }
            $attrs = $record['attributes'];
            $menuName = (isset($attrs['menu_name']) && is_string($attrs['menu_name']) && trim($attrs['menu_name']) !== '')
                ? trim($attrs['menu_name']) : '__default__';
            $targetNid = (isset($attrs['link']) && is_array($attrs['link']) && isset($attrs['link']['uri']))
                ? haxcmsImportDrupalParseNodeIdFromMenuUri($attrs['link']['uri']) : 0;
            if (!isset($groups[$menuName])) {
                $groups[$menuName] = array();
            }
            $groups[$menuName][] = array(
                'record'         => $record,
                'nid'            => $targetNid,
                'weight'         => isset($attrs['weight']) ? (int) $attrs['weight'] : 0,
                'parentPluginId' => haxcmsImportDrupalParseParentLinkId($record),
            );
        }
        $menuNames = array_keys($groups);
        if (count($menuNames) === 0) {
            return null;
        }
        $selected = null;
        $mainHasPages = false;
        if (isset($groups['main'])) {
            foreach ($groups['main'] as $entry) {
                if ($entry['nid'] > 0 && isset($pageSet[$entry['nid']])) {
                    $mainHasPages = true;
                    break;
                }
            }
        }
        if ($mainHasPages) {
            $selected = 'main';
        } else {
            $bestCount = 0;
            foreach ($menuNames as $name) {
                $count = 0;
                foreach ($groups[$name] as $entry) {
                    if ($entry['nid'] > 0 && isset($pageSet[$entry['nid']])) {
                        $count += 1;
                    }
                }
                if ($count > $bestCount) {
                    $bestCount = $count;
                    $selected = $name;
                }
            }
        }
        if ($selected === null) {
            return null;
        }
        $selectedEntries = $groups[$selected];
        $entryByPluginId = array();
        foreach ($selectedEntries as $entry) {
            if (is_array($entry['record']) && isset($entry['record']['id'])) {
                $entryByPluginId[(string) $entry['record']['id']] = $entry;
            }
        }
        $relationByNid = array();
        foreach ($selectedEntries as $entry) {
            if ($entry['nid'] <= 0 || !isset($pageSet[$entry['nid']])) {
                continue;
            }
            $parentNid = haxcmsImportDrupalResolveAncestorNid($entry['parentPluginId'], $entryByPluginId, $pageSet);
            haxcmsImportDrupalAddRelation($relationByNid, $entry['nid'], $parentNid, $entry['weight']);
        }
        if (count($relationByNid) === 0) {
            return null;
        }
        $childrenByParent = haxcmsImportDrupalChildrenByParentFromRelations($relationByNid);
        // pages linked in non-selected menus, not already in the forest,
        // append as top-level items ordered by their own menu weight
        $otherMenuEntries = array();
        $seen = array();
        foreach ($menuNames as $name) {
            if ($name === $selected) {
                continue;
            }
            foreach ($groups[$name] as $entry) {
                if ($entry['nid'] <= 0 || !isset($pageSet[$entry['nid']])
                    || isset($relationByNid[$entry['nid']]) || isset($seen[$entry['nid']])) {
                    continue;
                }
                $seen[$entry['nid']] = true;
                $otherMenuEntries[] = $entry;
            }
        }
        if (count($otherMenuEntries) > 0) {
            usort($otherMenuEntries, function ($a, $b) {
                if ($a['weight'] === $b['weight']) {
                    return $a['nid'] - $b['nid'];
                }
                return $a['weight'] - $b['weight'];
            });
            $ordered = array_map(function ($entry) {
                return $entry['nid'];
            }, $otherMenuEntries);
            if (!isset($childrenByParent[0])) {
                $childrenByParent[0] = array();
            }
            $childrenByParent[0] = array_merge($childrenByParent[0], $ordered);
        }
        return array(
            'childrenByParent' => $childrenByParent,
            'source'           => 'menu-link-content',
        );
    }

    /** node--book book fields -> forest (book sites without menu links) */
    function haxcmsImportDrupalForestFromBookFields($bookRecords, $pageSet)
    {
        if (!is_array($bookRecords) || count($bookRecords) === 0) {
            return null;
        }
        $relationByNid = array();
        foreach ($bookRecords as $record) {
            if (!is_array($record) || !isset($record['attributes'])) {
                continue;
            }
            $attrs = $record['attributes'];
            $nid = haxcmsImportDrupalGetNodeNid($record);
            if ($nid <= 0 || !isset($pageSet[$nid])) {
                continue;
            }
            if (isset($attrs['book']) && is_array($attrs['book'])) {
                $parentNid = (int) (isset($attrs['book']['pid']) ? $attrs['book']['pid']
                    : (isset($attrs['book']['parent']) ? $attrs['book']['parent']
                    : (isset($attrs['book']['parent_nid']) ? $attrs['book']['parent_nid'] : 0)));
                $weight = (int) (isset($attrs['book']['weight']) ? $attrs['book']['weight']
                    : (isset($attrs['book']['menu_order']) ? $attrs['book']['menu_order'] : 0));
                haxcmsImportDrupalAddRelation(
                    $relationByNid,
                    $nid,
                    isset($pageSet[$parentNid]) ? $parentNid : 0,
                    $weight
                );
                continue;
            }
            $directParent = (int) (isset($attrs['book_parent']) ? $attrs['book_parent']
                : (isset($attrs['book_parent_id']) ? $attrs['book_parent_id']
                : (isset($attrs['book_parent_nid']) ? $attrs['book_parent_nid']
                : (isset($attrs['field_book_parent']) ? $attrs['field_book_parent']
                : (isset($attrs['field_book_parent_nid']) ? $attrs['field_book_parent_nid'] : 0)))));
            $directWeight = (int) (isset($attrs['book_weight']) ? $attrs['book_weight']
                : (isset($attrs['book_order']) ? $attrs['book_order'] : 0));
            if ($directParent > 0 || isset($attrs['book_parent']) || isset($attrs['book_parent_id'])) {
                haxcmsImportDrupalAddRelation(
                    $relationByNid,
                    $nid,
                    isset($pageSet[$directParent]) ? $directParent : 0,
                    $directWeight
                );
            }
        }
        if (count($relationByNid) === 0) {
            return null;
        }
        return array(
            'childrenByParent' => haxcmsImportDrupalChildrenByParentFromRelations($relationByNid),
            'source'           => 'book-fields',
        );
    }

    function haxcmsImportDrupalFlatForest($recordsByNid)
    {
        $sorted = haxcmsImportDrupalSortNodeRecordsByCreated(array_values($recordsByNid));
        return array(
            'childrenByParent' => array(0 => array_map('haxcmsImportDrupalGetNodeNid', $sorted)),
            'source'           => 'flat',
        );
    }

    // ---- outline assembly ----

    /**
     * A top-level node with completely empty content (no body, no media)
     * that has children is structural: its children are promoted instead.
     */
    function haxcmsImportDrupalExpandTopNids($topNids, $childrenByParent, $getContent, &$dropped)
    {
        $expanded = array();
        foreach ($topNids as $nid) {
            $childNids = isset($childrenByParent[$nid]) ? $childrenByParent[$nid] : array();
            if (call_user_func($getContent, $nid) === '<p></p>' && count($childNids) > 0) {
                $dropped[$nid] = true;
                foreach (haxcmsImportDrupalExpandTopNids($childNids, $childrenByParent, $getContent, $dropped) as $childNid) {
                    $expanded[] = $childNid;
                }
                continue;
            }
            $expanded[] = $nid;
        }
        return $expanded;
    }

    function haxcmsImportDrupalWalkOutlineLevel($nids, $parentItem, $slugPrefix, $indent, &$state)
    {
        $siblingMap = array();
        $order = 0;
        foreach ($nids as $nid) {
            if (!isset($state['recordsByNid'][$nid])) {
                continue;
            }
            $record = $state['recordsByNid'][$nid];
            $segment = haxcmsImportDrupalUniqueSegment(
                haxcmsImportDrupalGetNodeSegment($record),
                $siblingMap,
                $nid
            );
            $item = haxcmsImportBuildItem(
                haxcmsImportDrupalGetNodeTitle($record),
                $slugPrefix !== '' ? $slugPrefix . '/' . $segment : $segment,
                $order,
                $parentItem !== null ? $parentItem['id'] : $state['configuredParent'],
                $indent,
                call_user_func($state['getContent'], $nid)
            );
            $item['metadata'] = haxcmsImportDrupalFormatNodeMetadata($record, 'drupal-node', $state['base'], array(
                'inOutline' => true,
            ));
            $state['items'][] = $item;
            $state['consumed'][$nid] = true;
            $order += 1;
            $childNids = isset($state['childrenByParent'][$nid]) ? $state['childrenByParent'][$nid] : array();
            haxcmsImportDrupalWalkOutlineLevel($childNids, $item, $item['slug'], $indent + 1, $state);
        }
    }

    // ---- main import flow ----

    function haxcmsImportDrupalImportSite($client, $repoUrl, $parentId = null)
    {
        $discovered = haxcmsImportDrupalDiscoverBase($client, $repoUrl);
        if ($discovered === null) {
            return array('error' => 'Unable to discover Drupal JSON:API from `repoUrl`; expected `<base>/jsonapi`');
        }
        $links = haxcmsImportDrupalGetDiscoveryLinks($discovered['discovery']);
        $nodeLinks = haxcmsImportDrupalGetNodeCollectionLinks($links);
        $pageHref = isset($nodeLinks['node--page']) ? $nodeLinks['node--page'] : null;
        $bookHref = isset($nodeLinks['node--book']) ? $nodeLinks['node--book'] : null;
        if ($pageHref === null && $bookHref === null) {
            $exposed = array_keys($nodeLinks);
            return array(
                'error' => 'Drupal JSON:API discovered but neither `node--page` nor `node--book` collections were exposed'
                    . (count($exposed) > 0 ? ' (found: ' . implode(', ', $exposed) . ')' : ''),
            );
        }

        $pageRecords = $pageHref !== null ? haxcmsImportDrupalFetchCollectionByHref($client, $pageHref, 50, 200) : array();
        $bookRecords = $bookHref !== null ? haxcmsImportDrupalFetchCollectionByHref($client, $bookHref, 50, 200) : array();

        // pages set: node--page plus node--book, first record wins per nid
        $recordsByNid = array();
        $pagesTotal = 0;
        $booksTotal = 0;
        $addRecords = function ($records) use (&$recordsByNid, &$pagesTotal, &$booksTotal) {
            if (!is_array($records)) {
                return;
            }
            foreach ($records as $record) {
                $nid = haxcmsImportDrupalGetNodeNid($record);
                if ($nid <= 0 || isset($recordsByNid[$nid])) {
                    continue;
                }
                $recordsByNid[$nid] = $record;
                if (isset($record['type']) && $record['type'] === 'node--book') {
                    $booksTotal += 1;
                } else {
                    $pagesTotal += 1;
                }
            }
        };
        $addRecords($pageRecords);
        $addRecords($bookRecords);

        if (count($recordsByNid) === 0) {
            return array(
                'error' => 'Drupal JSON:API is available but neither `node--page` nor `node--book` has accessible records',
            );
        }
        $pageSet = array();
        foreach (array_keys($recordsByNid) as $nid) {
            $pageSet[(int) $nid] = true;
        }

        // files
        $fileHref = isset($links['file--file']['href']) ? $links['file--file']['href'] : null;
        $fileRecords = $fileHref !== null ? haxcmsImportDrupalFetchCollectionByHref($client, $fileHref, 50, 400) : array();
        $fileMap = haxcmsImportDrupalBuildFileMap($fileRecords, $discovered['base']);

        // media (every exposed media bundle)
        $mediaRecords = array();
        foreach ($links as $linkKey => $linkDef) {
            if (strpos((string) $linkKey, 'media--') !== 0) {
                continue;
            }
            if (!is_array($linkDef) || !isset($linkDef['href'])) {
                continue;
            }
            $records = haxcmsImportDrupalFetchCollectionByHref($client, $linkDef['href'], 50, 200);
            foreach ($records as $record) {
                $mediaRecords[] = $record;
            }
        }
        $mediaMap = haxcmsImportDrupalBuildMediaMap($mediaRecords, $fileMap);

        // menu link content records + menu machine names
        $menuLinkHref = haxcmsImportDrupalGetMenuLinkContentHref($links);
        $menuLinkRecords = $menuLinkHref !== null
            ? haxcmsImportDrupalFetchCollectionByHref($client, $menuLinkHref, 100, 200) : array();
        $menuRecords = isset($links['menu--menu']['href'])
            ? haxcmsImportDrupalFetchCollectionByHref($client, $links['menu--menu']['href'], 50, 20) : array();

        // page path aliases -> nid for menu item url fallback resolution
        $aliasMap = array();
        foreach ($pageSet as $nid => $flag) {
            $record = $recordsByNid[$nid];
            $alias = (is_array($record) && isset($record['attributes']['path']['alias'])
                && is_string($record['attributes']['path']['alias'])) ? $record['attributes']['path']['alias'] : '';
            if ($alias !== '' && !isset($aliasMap[$alias])) {
                $aliasMap[$alias] = $nid;
            }
        }

        // content cache so structural checks and outline building share work
        $contentByNid = array();
        $getContent = function ($nid) use (&$contentByNid, $recordsByNid, $discovered, $mediaMap, $fileMap) {
            if (!isset($contentByNid[$nid])) {
                $contentByNid[$nid] = isset($recordsByNid[$nid])
                    ? haxcmsImportDrupalBuildPageContent(
                        $recordsByNid[$nid],
                        $discovered['base'],
                        $mediaMap['mediaByUuid'],
                        $fileMap
                    )
                    : '<p></p>';
            }
            return $contentByNid[$nid];
        };

        // hierarchy: menu-items endpoint -> menu_link_content forest -> book fields -> flat
        $forest = haxcmsImportDrupalFetchMenuItemsForest($client, $discovered['base'], $menuRecords, $pageSet, $aliasMap);
        if ($forest === null) {
            $forest = haxcmsImportDrupalForestFromMenuLinks($pageSet, $menuLinkRecords);
        }
        if ($forest === null) {
            $forest = haxcmsImportDrupalForestFromBookFields($bookRecords, $pageSet);
        }
        if ($forest === null) {
            $forest = haxcmsImportDrupalFlatForest($recordsByNid);
        }

        $items = array();
        $consumed = array();
        $dropped = array();
        $state = array(
            'recordsByNid'     => $recordsByNid,
            'childrenByParent' => $forest['childrenByParent'],
            'configuredParent' => $parentId,
            'base'             => $discovered['base'],
            'items'            => &$items,
            'consumed'         => &$consumed,
            'getContent'       => $getContent,
        );

        $topNids = isset($forest['childrenByParent'][0]) ? $forest['childrenByParent'][0] : array();
        $topNids = haxcmsImportDrupalExpandTopNids($topNids, $forest['childrenByParent'], $getContent, $dropped);
        haxcmsImportDrupalWalkOutlineLevel($topNids, null, '', 0, $state);
        $outlineNodes = count($items);

        // pages that made no outline land under a hidden additional-pages group
        $additionalRecords = array();
        foreach ($recordsByNid as $nid => $record) {
            if ($nid <= 0 || isset($consumed[$nid]) || isset($dropped[$nid])) {
                continue;
            }
            $additionalRecords[] = $record;
        }
        $additionalRecords = haxcmsImportDrupalSortNodeRecords($additionalRecords);

        if (count($additionalRecords) > 0) {
            $topLevelItems = array();
            foreach ($items as $item) {
                if ($item['parent'] === $parentId) {
                    $topLevelItems[] = $item;
                }
            }
            $topLevelCount = count($topLevelItems);
            $topLevelSlugMap = array();
            foreach ($topLevelItems as $item) {
                $topLevelSlugMap[$item['slug']] = true;
            }
            $additionalSlug = haxcmsImportDrupalUniqueSegment('additional-pages', $topLevelSlugMap, 'group');
            $additionalParent = haxcmsImportBuildItem(
                'additional pages',
                $additionalSlug,
                $topLevelCount,
                $parentId,
                0,
                '<p></p>'
            );
            $additionalParent['metadata'] = array(
                'hideInMenu' => true,
                'sourceType' => 'drupal-additional-pages',
            );
            $items[] = $additionalParent;

            $siblingMap = array();
            foreach ($additionalRecords as $index => $record) {
                $nid = haxcmsImportDrupalGetNodeNid($record);
                $segment = haxcmsImportDrupalUniqueSegment(
                    haxcmsImportDrupalGetNodeSegment($record),
                    $siblingMap,
                    $nid
                );
                $item = haxcmsImportBuildItem(
                    haxcmsImportDrupalGetNodeTitle($record),
                    $additionalParent['slug'] . '/' . $segment,
                    $index,
                    $additionalParent['id'],
                    1,
                    call_user_func($getContent, $nid)
                );
                $item['metadata'] = haxcmsImportDrupalFormatNodeMetadata($record, 'drupal-node', $discovered['base'], array(
                    'inOutline' => false,
                ));
                $items[] = $item;
                $consumed[$nid] = true;
            }
        }

        return array(
            'items'    => $items,
            'files'    => $fileMap['files'],
            'filename' => haxcmsImportDrupalGetFilenameFromUrl($repoUrl),
            'drupal'   => array(
                'base'           => $discovered['base'],
                'pagesTotal'     => $pagesTotal,
                'booksTotal'     => $booksTotal,
                'outlineSource'  => $forest['source'],
                'outlineNodes'   => $outlineNodes,
                'additionalNodes' => count($additionalRecords),
                'filesTotal'     => count($fileRecords),
                'filesImported'  => count($fileMap['files']),
                'filesSkipped'   => $fileMap['skipped'],
                'mediaTotal'     => $mediaMap['stats']['total'],
                'mediaResolved'  => $mediaMap['stats']['resolved'],
                'mediaUnresolved' => $mediaMap['stats']['unresolved'],
                'truncated'      => $fileMap['truncated'],
            ),
        );
    }
}
