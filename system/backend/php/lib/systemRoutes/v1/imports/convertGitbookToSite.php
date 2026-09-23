<?php
include_once dirname(__FILE__) . '/../../../siteRoutes/SiteRouteUtils.php';
include_once dirname(__FILE__) . '/importUtils.php';

$__vendorAutoload = dirname(__FILE__) . '/../../../../vendor/autoload.php';
if (file_exists($__vendorAutoload)) {
    require_once $__vendorAutoload;
}
include_once dirname(__FILE__) . '/../../../SsrfGuard.php';

// ---------------------------------------------------------------------------
// Pure helpers (no network I/O). Extracted so the SUMMARY.md -> items logic
// and the files-map / reference-rewrite logic are unit-testable without
// Guzzle mocking, mirroring convert-gitbook-to-site.test.cjs on the Node side.
// ---------------------------------------------------------------------------

if (!function_exists('haxcmsGitbookBuildFileMaps')) {
    /**
     * Build the `downloads` and `fileMap` maps from a GitHub recursive tree.
     * Mirrors Node convertGitbookToSite.js: markdown files are skipped (they
     * become pages), extensionless entries are skipped (folders), everything
     * else with a `.` becomes a staged download keyed `files/<path>` pointing
     * at its raw.githubusercontent.com URL. fileMap maps that key to the
     * repo-relative path as it appears in page content so the rewrite matches.
     *
     * @param array  $tree   GitHub tree blob (`[{ path: '...' }, ...]`)
     * @param string $owner
     * @param string $repo
     * @param string $branch
     * @return array [$downloads, $fileMap]
     */
    function haxcmsGitbookBuildFileMaps($tree, $owner, $repo, $branch)
    {
        $downloads = array();
        $fileMap   = array();
        if (!is_array($tree)) {
            return array($downloads, $fileMap);
        }
        $rawBase = 'https://raw.githubusercontent.com/' . $owner . '/' . $repo . '/' . $branch . '/';
        foreach ($tree as $entry) {
            if (!is_array($entry) || !isset($entry['path']) || !is_string($entry['path'])) {
                continue;
            }
            $path = $entry['path'];
            // skip markdown files (they become pages, not downloaded assets)
            if (strpos($path, '.md') !== false) {
                continue;
            }
            // skip folders (no extension)
            if (strpos($path, '.') === false) {
                continue;
            }
            $fileKey = 'files/' . $path;
            // encode each segment so spaces / special chars are safe in the URL
            $encodedPath = implode('/', array_map('rawurlencode', explode('/', $path)));
            $downloads[$fileKey] = $rawBase . $encodedPath;
            $fileMap[$fileKey]   = $path;
        }
        return array($downloads, $fileMap);
    }
}

if (!function_exists('haxcmsGitbookFindSummaryPath')) {
    /**
     * Locate SUMMARY.md (case-insensitive) in a GitHub recursive tree,
     * preferring a root-level match. Returns the repo-relative path or null.
     *
     * @param array $tree
     * @return string|null
     */
    function haxcmsGitbookFindSummaryPath($tree)
    {
        if (!is_array($tree)) {
            return null;
        }
        $rootMatch = null;
        $deepMatch = null;
        foreach ($tree as $entry) {
            if (!is_array($entry) || !isset($entry['path']) || !is_string($entry['path'])) {
                continue;
            }
            $path = $entry['path'];
            if (strtolower(basename($path)) === 'summary.md') {
                if (strpos($path, '/') === false) {
                    $rootMatch = $path;
                } elseif ($deepMatch === null) {
                    $deepMatch = $path;
                }
            }
        }
        if ($rootMatch !== null) {
            return $rootMatch;
        }
        return $deepMatch;
    }
}

if (!function_exists('haxcmsGitbookRewriteFileReferences')) {
    /**
     * Rewrite page content file references so they point at the `files/<path>`
     * key the bulk import will stage. Matches both the bare relative form
     * (`assets/x.png`) and the leading-slash form (`/assets/x.png`) that
     * gitbook markdown renders, with a negative lookbehind for `files/` so an
     * already-rewritten reference can't double-rewrite. Mirrors the Node
     * single-regex-per-file rewrite exactly.
     *
     * @param string $content
     * @param array  $fileMap  key `files/<path>` => repo-relative stripped path
     * @return string
     */
    function haxcmsGitbookRewriteFileReferences($content, $fileMap)
    {
        if (!is_string($content) || $content === '') {
            return $content;
        }
        foreach ($fileMap as $fileKey => $stripped) {
            $pattern = '/(?<!files\/)\/?' . preg_quote($stripped, '/') . '/';
            $content = preg_replace($pattern, $fileKey, $content);
        }
        return $content;
    }
}

if (!function_exists('haxcmsGitbookWalkUl')) {
    /**
     * Walk a rendered SUMMARY.md `<ul>` recursively, building JSONOutlineSchema
     * item arrays. Mirrors Node recurseToJOS: each `<li>`'s first `<a>` becomes
     * a page (title/parent/indent/slug/location/contents); a nested `<ul>` under
     * the `<li>` recurses one level deeper, parented to this item (or '' when
     * the `<li>` had no link, matching Node's `{ id: '' }` fallback).
     *
     * @param DOMNode   $ul
     * @param string    $parentItemId
     * @param int       $depth
     * @param callable  $pageFetcher  fn($href) => markdown string (or '')
     * @param array     $fileMap
     * @param array     $items        items accumulator (by reference)
     */
    function haxcmsGitbookWalkUl($ul, $parentItemId, $depth, $pageFetcher, $fileMap, &$items)
    {
        foreach ($ul->childNodes as $node) {
            if ($node->nodeType !== XML_ELEMENT_NODE || strtolower($node->tagName) !== 'li') {
                continue;
            }
            $a   = $node->getElementsByTagName('a')->item(0);
            $item = null;
            if ($a) {
                $title = trim($a->textContent);
                $href  = trim($a->getAttribute('href'));
                $itemId = SiteRouteUtils::generateUUID();
                $markdown = call_user_func($pageFetcher, $href);
                $content = '';
                if (is_string($markdown) && $markdown !== '') {
                    $content = \Michelf\Markdown::defaultTransform($markdown);
                }
                $content = haxcmsGitbookRewriteFileReferences($content, $fileMap);
                $item = array(
                    'id'          => $itemId,
                    'title'       => $title,
                    'order'       => count($items),
                    'parent'      => $parentItemId,
                    'indent'      => $depth,
                    'slug'        => $href,
                    'location'    => 'content/' . $href,
                    'contents'    => $content,
                    'description' => '',
                    'metadata'    => array(),
                );
                $items[] = $item;
            }
            $nestedUl = $node->getElementsByTagName('ul')->item(0);
            if ($nestedUl) {
                $parentForNested = $item ? $item['id'] : '';
                haxcmsGitbookWalkUl($nestedUl, $parentForNested, $depth + 1, $pageFetcher, $fileMap, $items);
            }
        }
    }
}

if (!function_exists('haxcmsGitbookSummaryHtmlToItems')) {
    /**
     * Render SUMMARY.md markdown to HTML and walk it into JSONOutlineSchema
     * item arrays. $pageFetcher is invoked per `<a>` href to pull page
     * markdown, keeping this helper network-free and unit-testable.
     *
     * @param string   $summaryMarkdown
     * @param callable $pageFetcher   fn($href) => markdown string (or '')
     * @param array    $fileMap
     * @param string|null $parentId   optional parent override for top-level items
     * @return array
     */
    function haxcmsGitbookSummaryHtmlToItems($summaryMarkdown, $pageFetcher, $fileMap, $parentId)
    {
        $items = array();
        if (!is_string($summaryMarkdown) || $summaryMarkdown === '') {
            return $items;
        }
        $html = \Michelf\Markdown::defaultTransform($summaryMarkdown);
        if ($html === '') {
            return $items;
        }
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        @$dom->loadHTML('<?xml encoding="UTF-8"?><div id="gitbook-summary">' . $html . '</div>');
        $wrapper = $dom->getElementById('gitbook-summary');
        if (!$wrapper) {
            return $items;
        }
        $topUl = null;
        foreach ($wrapper->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE && strtolower($child->tagName) === 'ul') {
                $topUl = $child;
                break;
            }
        }
        if (!$topUl) {
            return $items;
        }
        $rootParentId = ($parentId !== null && $parentId !== '') ? $parentId : '';
        haxcmsGitbookWalkUl($topUl, $rootParentId, 0, $pageFetcher, $fileMap, $items);
        return $items;
    }
}

// ---------------------------------------------------------------------------
// Endpoint handler
// ---------------------------------------------------------------------------

if (!function_exists('haxcmsImportConvertGitbookToSite')) {
    function haxcmsImportConvertGitbookToSite($context)
    {
        $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/system/api';
        $body        = $context->body;
        if (!is_array($body)) { $body = array(); }

        $repoUrl  = isset($body['repoUrl'])  ? trim((string) $body['repoUrl'])  : '';
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
        $apiHeaders = array(
            'headers' => array('Accept' => 'application/vnd.github.v3+json', 'User-Agent' => 'HAXcms-Import/1.0'),
        );

        // Parity with Node convertGitbookToSite.js: resolve the repo's
        // default_branch from the GitHub API (falling back to 'main') when the
        // URL does not specify a branch. Hardcoding 'main' breaks repos whose
        // default branch is 'master', which 400 with "Could not find SUMMARY.md"
        // because the raw URL points at a ref that does not exist.
        if (!$branch) {
            $branch = '';
            try {
                $repoMetaResponse = SsrfGuard::safeGuzzleRequest($client, 'GET', 'https://api.github.com/repos/' . $owner . '/' . $repo, $apiHeaders);
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
        $apiBase = 'https://api.github.com/repos/' . $owner . '/' . $repo . '/';

        // Fetch the recursive git tree once: it gives us both the SUMMARY.md
        // location and every non-markdown asset to stage as a downloaded file
        // (parity with Node, which builds `downloads`/`fileMap` from the tree).
        $tree = array();
        try {
            $treeResponse = SsrfGuard::safeGuzzleRequest($client, 'GET', $apiBase . 'git/trees/' . $branch . '?recursive=1', $apiHeaders);
            $treeData = json_decode((string) $treeResponse->getBody(), true);
            if (is_array($treeData) && isset($treeData['tree']) && is_array($treeData['tree'])) {
                $tree = $treeData['tree'];
            }
        } catch (\Exception $e) {
            // fall through - will report missing SUMMARY.md below
        }

        // build the files downloads map + content-rewrite map from the tree
        list($downloads, $fileMap) = haxcmsGitbookBuildFileMaps($tree, $owner, $repo, $branch);

        // Locate SUMMARY.md (case-insensitive, prefer root-level)
        $summaryPath = haxcmsGitbookFindSummaryPath($tree);

        // If the tree fetch was truncated/empty and didn't surface SUMMARY.md,
        // make a best-effort direct fetch of the conventional root SUMMARY.md
        // before bailing, so a truncated tree doesn't sink a valid repo.
        $summaryContent = '';
        if ($summaryPath !== null) {
            $summaryUrl = $rawBase . implode('/', array_map('rawurlencode', explode('/', $summaryPath)));
            try {
                $summaryResponse = SsrfGuard::safeGuzzleRequest($client, 'GET', $summaryUrl);
                $summaryContent  = (string) $summaryResponse->getBody();
            } catch (\Exception $e) {
                $summaryContent = '';
            }
        }
        if ($summaryContent === '') {
            try {
                $summaryResponse = SsrfGuard::safeGuzzleRequest($client, 'GET', $rawBase . 'SUMMARY.md');
                $summaryContent  = (string) $summaryResponse->getBody();
            } catch (\Exception $e) {
                $summaryContent = '';
            }
        }

        if ($summaryContent === '') {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 400, 'data' => array('error' => 'Could not find SUMMARY.md in ' . $repoUrl, 'items' => array(), 'filename' => null, 'files' => array())),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix, $apiBasePath
            );
            return;
        }

        // Per-page markdown fetcher closure for the walker.
        $pageFetcher = function ($href) use ($client, $rawBase) {
            if (!is_string($href) || $href === '' || $href === '#') {
                return '';
            }
            try {
                $mdUrl  = $rawBase . ltrim($href, '/');
                $mdResp = SsrfGuard::safeGuzzleRequest($client, 'GET', $mdUrl);
                return (string) $mdResp->getBody();
            } catch (\Exception $e) {
                return '';
            }
        };

        $items = haxcmsGitbookSummaryHtmlToItems($summaryContent, $pageFetcher, $fileMap, $parentId);

        SiteRouteUtils::sendFormattedResponse(
            array('status' => 200, 'data' => array('items' => $items, 'filename' => $repo, 'files' => $downloads)),
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
