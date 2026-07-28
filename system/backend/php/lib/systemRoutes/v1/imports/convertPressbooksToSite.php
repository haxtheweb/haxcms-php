<?php
include_once dirname(__FILE__) . '/../../../siteRoutes/SiteRouteUtils.php';
include_once dirname(__FILE__) . '/importUtils.php';

$__vendorAutoload = dirname(__FILE__) . '/../../../../vendor/autoload.php';
if (file_exists($__vendorAutoload)) {
    require_once $__vendorAutoload;
}
include_once dirname(__FILE__) . '/../../../SsrfGuard.php';

if (!function_exists('haxcmsImportConvertPressbooksToSite')) {
    function haxcmsImportConvertPressbooksToSite($context)
    {
        $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/system/api';
        $body        = $context->body;
        if (!is_array($body)) { $body = array(); }

        $repoUrl  = isset($body['repoUrl'])  ? trim((string) $body['repoUrl'])  : '';
        $method   = isset($body['method'])   ? (string) $body['method']   : 'site';
        $type     = isset($body['type'])     ? (string) $body['type']     : '';
        $parentId = (isset($body['parentId']) && $body['parentId'] !== null && $body['parentId'] !== 'null')
            ? (string) $body['parentId'] : null;

        // Handle uploaded HTML file as fallback
        $fileKey = null;
        foreach (array('upload', 'file', 'file-upload') as $key) {
            if (isset($_FILES[$key]) && is_array($_FILES[$key]) && isset($_FILES[$key]['tmp_name']) && $_FILES[$key]['tmp_name'] !== '') {
                $fileKey = $key;
                break;
            }
        }

        if ($fileKey !== null) {
            $file     = $_FILES[$fileKey];
            $filename = isset($file['name']) ? (string) $file['name'] : 'file.html';
            $html     = (string) @file_get_contents($file['tmp_name']);
            $title    = preg_replace('/\.(html|htm)$/i', '', $filename);
            $items    = haxcmsImportHtmlToItems($html, array(
                'titleValue' => $title,
                'method'     => $method,
                'type'       => $type,
                'parentId'   => $parentId,
            ));
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 200, 'data' => array('items' => $items, 'filename' => $filename)),
                array('statusCode' => 200, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix, $apiBasePath
            );
            return;
        }

        if ($repoUrl === '') {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 400, 'data' => array('error' => 'missing `repoUrl` param', 'items' => array(), 'filename' => null)),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix, $apiBasePath
            );
            return;
        }

        // Normalize base URL
        $baseUrl = rtrim($repoUrl, '/');
        $tocUrl  = $baseUrl . '/wp-json/pressbooks/v2/toc';

        try {
            $client      = new \GuzzleHttp\Client(['timeout' => 30, 'connect_timeout' => 10]);
            $tocResponse = SsrfGuard::safeGuzzleRequest($client, 'GET', $tocUrl, [
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
                ]
            ]);
            $toc         = json_decode((string) $tocResponse->getBody(), true);
        } catch (\Exception $e) {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 400, 'data' => array('error' => 'Unable to fetch Pressbooks TOC: ' . $e->getMessage(), 'items' => array(), 'filename' => null)),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix, $apiBasePath
            );
            return;
        }

        if (!is_array($toc)) {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 400, 'data' => array('error' => 'Invalid Pressbooks TOC response', 'items' => array(), 'filename' => null)),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix, $apiBasePath
            );
            return;
        }

        $items = array();

        // Process parts and chapters
        $parts = isset($toc['parts']) ? $toc['parts'] : array();
        foreach ($parts as $part) {
            $partId    = SiteRouteUtils::generateUUID();
            $partTitle = isset($part['title']['rendered']) ? (string) $part['title']['rendered'] : (isset($part['title']) ? (string) $part['title'] : 'Part');
            $partItem  = array(
                'id'          => $partId,
                'title'       => $partTitle,
                'order'       => count($items),
                'parent'      => $parentId,
                'slug'        => SiteRouteUtils::cleanSlug($partTitle),
                'contents'    => '',
                'description' => '',
                'metadata'    => array(),
            );
            $items[] = $partItem;

            $chapters = isset($part['chapters']) ? $part['chapters'] : array();
            foreach ($chapters as $chapter) {
                $chapTitle   = isset($chapter['title']['rendered']) ? (string) $chapter['title']['rendered'] : (isset($chapter['title']) ? (string) $chapter['title'] : 'Chapter');
                $chapContent = isset($chapter['content']['rendered']) ? (string) $chapter['content']['rendered'] : '';
                $chapItem    = array(
                    'id'          => SiteRouteUtils::generateUUID(),
                    'title'       => $chapTitle,
                    'order'       => count($items),
                    'parent'      => $partId,
                    'slug'        => SiteRouteUtils::cleanSlug($chapTitle),
                    'contents'    => $chapContent,
                    'description' => '',
                    'metadata'    => array(),
                );
                $items[] = $chapItem;
            }
        }

        // Also handle front-matter / back-matter if present
        foreach (array('front-matter', 'back-matter') as $section) {
            if (isset($toc[$section]) && is_array($toc[$section])) {
                foreach ($toc[$section] as $entry) {
                    $entTitle   = isset($entry['title']['rendered']) ? (string) $entry['title']['rendered'] : (isset($entry['title']) ? (string) $entry['title'] : $section);
                    $entContent = isset($entry['content']['rendered']) ? (string) $entry['content']['rendered'] : '';
                    $items[]    = array(
                        'id'          => SiteRouteUtils::generateUUID(),
                        'title'       => $entTitle,
                        'order'       => count($items),
                        'parent'      => $parentId,
                        'slug'        => SiteRouteUtils::cleanSlug($entTitle),
                        'contents'    => $entContent,
                        'description' => '',
                        'metadata'    => array(),
                    );
                }
            }
        }

        $bookTitle = isset($toc['title']['rendered']) ? (string) $toc['title']['rendered'] : 'pressbooks-import';

        SiteRouteUtils::sendFormattedResponse(
            array('status' => 200, 'data' => array('items' => $items, 'filename' => $bookTitle)),
            array('statusCode' => 200, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix, $apiBasePath
        );
    }
}
