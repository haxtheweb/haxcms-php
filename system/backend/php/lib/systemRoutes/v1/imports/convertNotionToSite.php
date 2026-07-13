<?php
include_once dirname(__FILE__) . '/../../../siteRoutes/SiteRouteUtils.php';
include_once dirname(__FILE__) . '/importUtils.php';

$__vendorAutoload = dirname(__FILE__) . '/../../../../vendor/autoload.php';
if (file_exists($__vendorAutoload)) {
    require_once $__vendorAutoload;
}

if (!function_exists('haxcmsImportConvertNotionToSite')) {
    function haxcmsImportConvertNotionToSite($context)
    {
        $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/system/api';
        $body        = $context->body;
        if (!is_array($body)) { $body = array(); }

        $repoUrl  = isset($body['repoUrl'])  ? trim((string) $body['repoUrl'])  : '';
        $method   = isset($body['method'])   ? (string) $body['method']   : 'site';
        $type     = isset($body['type'])     ? (string) $body['type']     : '';
        $parentId = (isset($body['parentId']) && $body['parentId'] !== null && $body['parentId'] !== 'null')
            ? (string) $body['parentId'] : null;

        if ($repoUrl === '') {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 400, 'data' => array('error' => 'missing `repoUrl` param', 'items' => array(), 'filename' => null)),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix, $apiBasePath
            );
            return;
        }

        // Parse github.com/owner/repo URL
        if (!preg_match('#github\.com/([^/]+)/([^/]+)(?:/tree/([^/]+))?#', $repoUrl, $m)) {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 400, 'data' => array('error' => 'Invalid GitHub repository URL: ' . $repoUrl, 'items' => array(), 'filename' => null)),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix, $apiBasePath
            );
            return;
        }

        $owner  = $m[1];
        $repo   = rtrim($m[2], '/');
        $branch = (isset($m[3]) && $m[3] !== '') ? $m[3] : 'main';

        $client  = new \GuzzleHttp\Client(['timeout' => 30, 'connect_timeout' => 10]);
        $apiBase = 'https://api.github.com/repos/' . $owner . '/' . $repo . '/contents/';
        $rawBase = 'https://raw.githubusercontent.com/' . $owner . '/' . $repo . '/' . $branch . '/';

        // Fetch root contents
        try {
            $contentsResponse = $client->request('GET', $apiBase, [
                'headers' => ['Accept' => 'application/vnd.github.v3+json', 'User-Agent' => 'HAXcms-Import/1.0'],
                'query'   => array('ref' => $branch),
            ]);
            $contents = json_decode((string) $contentsResponse->getBody(), true);
        } catch (\Exception $e) {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 400, 'data' => array('error' => 'Unable to access GitHub repository: ' . $e->getMessage(), 'items' => array(), 'filename' => null)),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix, $apiBasePath
            );
            return;
        }

        if (!is_array($contents)) {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 400, 'data' => array('error' => 'Invalid GitHub repository contents', 'items' => array(), 'filename' => null)),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix, $apiBasePath
            );
            return;
        }

        $items = array();

        // Notion exports: each .md file at root is a page; subdirectory .md files are sub-pages
        foreach ($contents as $entry) {
            if (!isset($entry['type'])) { continue; }

            if ($entry['type'] === 'file' && isset($entry['name']) && preg_match('/\.md$/i', $entry['name'])) {
                $title   = preg_replace('/\.md$/i', '', $entry['name']);
                $title   = preg_replace('/ [a-f0-9]{32}$/i', '', $title); // strip Notion hash
                $content = '';
                try {
                    $mdResp  = $client->request('GET', $rawBase . $entry['name']);
                    $mdText  = (string) $mdResp->getBody();
                    $content = \Michelf\Markdown::defaultTransform($mdText);
                } catch (\Exception $e) {
                    // continue
                }
                $items[] = array(
                    'id'          => SiteRouteUtils::generateUUID(),
                    'title'       => $title,
                    'order'       => count($items),
                    'parent'      => $parentId,
                    'slug'        => SiteRouteUtils::cleanSlug($title),
                    'contents'    => $content,
                    'description' => '',
                    'metadata'    => array(),
                );
            } elseif ($entry['type'] === 'dir' && isset($entry['name'])) {
                $dirTitle  = $entry['name'];
                $dirTitle  = preg_replace('/ [a-f0-9]{32}$/i', '', $dirTitle);
                $dirId     = SiteRouteUtils::generateUUID();
                $items[]   = array(
                    'id'          => $dirId,
                    'title'       => $dirTitle,
                    'order'       => count($items),
                    'parent'      => $parentId,
                    'slug'        => SiteRouteUtils::cleanSlug($dirTitle),
                    'contents'    => '',
                    'description' => '',
                    'metadata'    => array(),
                );

                // Fetch sub-directory contents
                try {
                    $subResp     = $client->request('GET', $apiBase . $entry['name'], [
                        'headers' => ['Accept' => 'application/vnd.github.v3+json', 'User-Agent' => 'HAXcms-Import/1.0'],
                        'query'   => array('ref' => $branch),
                    ]);
                    $subContents = json_decode((string) $subResp->getBody(), true);
                    if (is_array($subContents)) {
                        foreach ($subContents as $sub) {
                            if ($sub['type'] === 'file' && preg_match('/\.md$/i', $sub['name'])) {
                                $subTitle   = preg_replace('/\.md$/i', '', $sub['name']);
                                $subTitle   = preg_replace('/ [a-f0-9]{32}$/i', '', $subTitle);
                                $subContent = '';
                                try {
                                    $subMdResp  = $client->request('GET', $rawBase . $entry['name'] . '/' . $sub['name']);
                                    $subMdText  = (string) $subMdResp->getBody();
                                    $subContent = \Michelf\Markdown::defaultTransform($subMdText);
                                } catch (\Exception $e) {
                                    // continue
                                }
                                $items[] = array(
                                    'id'          => SiteRouteUtils::generateUUID(),
                                    'title'       => $subTitle,
                                    'order'       => count($items),
                                    'parent'      => $dirId,
                                    'slug'        => SiteRouteUtils::cleanSlug($subTitle),
                                    'contents'    => $subContent,
                                    'description' => '',
                                    'metadata'    => array(),
                                );
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // continue
                }
            }
        }

        SiteRouteUtils::sendFormattedResponse(
            array('status' => 200, 'data' => array('items' => $items, 'filename' => $repo)),
            array('statusCode' => 200, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix, $apiBasePath
        );
    }
}
