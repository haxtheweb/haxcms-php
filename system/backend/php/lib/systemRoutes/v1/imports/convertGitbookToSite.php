<?php
include_once dirname(__FILE__) . '/../../../siteRoutes/SiteRouteUtils.php';
include_once dirname(__FILE__) . '/importUtils.php';

$__vendorAutoload = dirname(__FILE__) . '/../../../../vendor/autoload.php';
if (file_exists($__vendorAutoload)) {
    require_once $__vendorAutoload;
}
include_once dirname(__FILE__) . '/../../../SsrfGuard.php';

if (!function_exists('haxcmsImportConvertGitbookToSite')) {
    function haxcmsImportConvertGitbookToSite($context)
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
                array('status' => 400, 'data' => array('error' => 'missing `repoUrl` param', 'items' => array(), 'filename' => null, 'files' => array())),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix, $apiBasePath
            );
            return;
        }

        // Parse github.com/owner/repo -> use GitHub API
        $parsedRepo = haxcmsParseGithubRepoUrl($repoUrl);
        if (!$parsedRepo) {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 400, 'data' => array('error' => 'Invalid GitHub repository URL: ' . $repoUrl, 'items' => array(), 'filename' => null, 'files' => array())),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix, $apiBasePath
            );
            return;
        }

        list($owner, $repo, $branch) = $parsedRepo;

        $client = new \GuzzleHttp\Client(['timeout' => 30, 'connect_timeout' => 10]);

        // Parity with Node convertGitbookToSite.js: when the URL does not
        // specify a branch, resolve the repo's default_branch from the GitHub
        // API (falling back to 'main'). Hardcoding 'main' breaks repos whose
        // default branch is 'master' (e.g. haxtheweb/gitbook-v1-example),
        // which 400 with "Could not find SUMMARY.md" because the raw URL
        // points at a ref that does not exist.
        if (!$branch) {
            $branch = '';
            try {
                $repoMetaResponse = SsrfGuard::safeGuzzleRequest($client, 'GET', 'https://api.github.com/repos/' . $owner . '/' . $repo, [
                    'headers' => ['Accept' => 'application/vnd.github.v3+json', 'User-Agent' => 'HAXcms-Import/1.0'],
                ]);
                $repoMeta = json_decode((string) $repoMetaResponse->getBody(), true);
                if (is_array($repoMeta) && isset($repoMeta['default_branch']) && is_string($repoMeta['default_branch']) && $repoMeta['default_branch'] !== '') {
                    $branch = (string) $repoMeta['default_branch'];
                }
            } catch (\Exception $e) {
                // fall through to the 'main' default below
            }
            if ($branch === '') {
                $branch = 'main';
            }
        }

        $rawBase = 'https://raw.githubusercontent.com/' . $owner . '/' . $repo . '/' . $branch . '/';
        $apiBase = 'https://api.github.com/repos/' . $owner . '/' . $repo . '/contents/';

        // Locate SUMMARY.md (case-insensitive search)
        $summaryMd = null;
        try {
            $contentsResponse = SsrfGuard::safeGuzzleRequest($client, 'GET', $apiBase, [
                'headers' => ['Accept' => 'application/vnd.github.v3+json', 'User-Agent' => 'HAXcms-Import/1.0'],
                'query'   => array('ref' => $branch),
            ]);
            $contents = json_decode((string) $contentsResponse->getBody(), true);
            if (is_array($contents)) {
                foreach ($contents as $entry) {
                    if (isset($entry['name']) && strtolower($entry['name']) === 'summary.md') {
                        $summaryMd = (string) $rawBase . $entry['name'];
                        break;
                    }
                }
            }
        } catch (\Exception $e) {
            // fall through - will report error below
        }

        if (!$summaryMd) {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 400, 'data' => array('error' => 'Could not find SUMMARY.md in ' . $repoUrl, 'items' => array(), 'filename' => null, 'files' => array())),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix, $apiBasePath
            );
            return;
        }

        try {
            $summaryResponse = SsrfGuard::safeGuzzleRequest($client, 'GET', $summaryMd);
            $summaryContent  = (string) $summaryResponse->getBody();
        } catch (\Exception $e) {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 400, 'data' => array('error' => 'Failed to fetch SUMMARY.md: ' . $e->getMessage(), 'items' => array(), 'filename' => null, 'files' => array())),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix, $apiBasePath
            );
            return;
        }

        // Parse GitBook SUMMARY.md lines: '* [Title](path.md)' or '  * [Title](path.md)'
        $items   = array();
        $lines   = explode("\n", $summaryContent);
        $idStack = array(null); // stack of parent IDs per indent level

        foreach ($lines as $line) {
            if (!preg_match('/^(\s*)[*-]\s+\[([^\]]+)\]\(([^)]+)\)/', $line, $matches)) {
                continue;
            }
            $indent  = strlen($matches[1]);
            $title   = trim($matches[2]);
            $relPath = trim($matches[3]);

            // Determine nesting level (2 spaces = 1 level)
            $level = (int) floor($indent / 2);

            // Trim stack to current level
            while (count($idStack) > $level + 1) {
                array_pop($idStack);
            }
            $currentParent = end($idStack);
            if ($currentParent === false) { $currentParent = null; }

            $itemId  = SiteRouteUtils::generateUUID();
            $content = '';

            if ($relPath !== '' && $relPath !== '#') {
                try {
                    $mdUrl   = $rawBase . ltrim($relPath, '/');
                    $mdResp  = SsrfGuard::safeGuzzleRequest($client, 'GET', $mdUrl);
                    $mdText  = (string) $mdResp->getBody();
                    $content = \Michelf\Markdown::defaultTransform($mdText);
                } catch (\Exception $e) {
                    // continue with empty content
                }
            }

            $item = array(
                'id'          => $itemId,
                'title'       => $title,
                'order'       => count($items),
                'parent'      => ($currentParent === null && $parentId !== null) ? $parentId : $currentParent,
                'slug'        => SiteRouteUtils::cleanSlug($title),
                'contents'    => $content,
                'description' => '',
                'metadata'    => array(),
            );
            $items[] = $item;

            // Push this item's ID as the potential parent for child items
            $idStack[$level + 1] = $itemId;
        }

        SiteRouteUtils::sendFormattedResponse(
            array('status' => 200, 'data' => array('items' => $items, 'filename' => $repo, 'files' => array())),
            array('statusCode' => 200, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix, $apiBasePath
        );
    }

    function haxcmsParseGithubRepoUrl($url)
    {
        // Accepts: https://github.com/owner/repo or https://github.com/owner/repo/tree/branch
        if (!preg_match('#github\.com/([^/]+)/([^/]+)(?:/tree/([^/]+))?#', $url, $m)) {
            return null;
        }
        $owner  = $m[1];
        $repo   = rtrim($m[2], '/');
        $branch = isset($m[3]) && $m[3] !== '' ? $m[3] : null;
        return array($owner, $repo, $branch);
    }
}
