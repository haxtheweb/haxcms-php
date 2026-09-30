<?php
include_once dirname(__FILE__) . '/../../../siteRoutes/SiteRouteUtils.php';
include_once dirname(__FILE__) . '/importUtils.php';

$__vendorAutoload = dirname(__FILE__) . '/../../../../vendor/autoload.php';
if (file_exists($__vendorAutoload)) {
    require_once $__vendorAutoload;
}
include_once dirname(__FILE__) . '/../../../SsrfGuard.php';
include_once dirname(__FILE__) . '/../../../SanitizeContent.php';

// Safety valves, mirroring LIMITS in haxcms-nodejs convertVitepressToSite.js.
// Page bodies are fetched one at a time; assets travel to createSite as URLs,
// which downloads them behind SsrfGuard (haxtheweb/issues#3060), so only their
// count is capped here.
if (!defined('HAXCMS_VITEPRESS_CONFIG_PATH_REGEX')) {
    // VitePress keeps its config beside the content root it serves
    define('HAXCMS_VITEPRESS_CONFIG_PATH_REGEX', '#(^|/)\.vitepress/config\.(mjs|mts|js|ts)$#');
    // createSite only accepts these extensions in build.files. html and md are
    // left out on purpose: they are pages, not assets, on both backends.
    define('HAXCMS_VITEPRESS_IMPORTABLE_ASSET_REGEX', '/\.(jpg|jpeg|png|gif|webm|webp|mp4|mp3|mov|csv|ppt|pptx|xlsx|doc|xls|docx|pdf|rtf|txt|vtt|xml)$/i');
    define('HAXCMS_VITEPRESS_IMAGE_EXTENSION_REGEX', '/\.(jpg|jpeg|png|gif|webp)$/i');
    define('HAXCMS_VITEPRESS_API_ORIGIN', 'https://api.github.com');
    define('HAXCMS_VITEPRESS_RAW_ORIGIN', 'https://raw.githubusercontent.com');
}

if (!function_exists('haxcmsImportConvertVitepressToSite')) {
    /**
     * Safety valves, mirroring the exported LIMITS in haxcms-nodejs
     * convertVitepressToSite.js: a VitePress book runs to hundreds of pages.
     * $GLOBALS['HAXCMS_VITEPRESS_LIMITS'] overrides them so tests can exercise
     * each cap without a fixture of that size, the way the Node tests mutate
     * the exported object.
     */
    function haxcmsImportVitepressLimits()
    {
        $defaults = array(
            'maxPages'           => 500,
            'maxFiles'           => 2000,
            'fetchBudgetSeconds' => 900,
            'requestDelayMs'     => 100,
        );
        if (isset($GLOBALS['HAXCMS_VITEPRESS_LIMITS']) && is_array($GLOBALS['HAXCMS_VITEPRESS_LIMITS'])) {
            return array_merge($defaults, $GLOBALS['HAXCMS_VITEPRESS_LIMITS']);
        }
        return $defaults;
    }

    /**
     * POST /system/api/v1/site/import/vitepress
     * Convert a VitePress documentation site into a HAXcms site schema.
     *
     * PHP port of haxcms-nodejs src/systemRoutes/v1/routes/imports/
     * convertVitepressToSite.js (haxtheweb/issues#2923), kept structurally
     * identical: same outline, slugs, file map, elements, status codes and
     * error strings.
     *
     * Expects a JSON body with a `repoUrl` param: a GitHub repository URL,
     * with an optional /tree/<branch>.
     *
     * VitePress declares its outline in JavaScript rather than in a markdown
     * file the way GitBook does, so the converter reads themeConfig.sidebar out
     * of .vitepress/config.* as data. The config is never executed: repoUrl is
     * caller-supplied, so running the repository's own JavaScript would be
     * arbitrary code execution. A config whose sidebar cannot be read as plain
     * data falls back to the markdown file tree, which is also what VitePress
     * does when no sidebar is configured.
     *
     * Returns { status: 200, data: { items, filename, files, site, truncated,
     * unmappedComponents } }.
     */
    function haxcmsImportConvertVitepressToSite($context)
    {
        $client = new \GuzzleHttp\Client(array('timeout' => 30, 'connect_timeout' => 10));
        haxcmsImportVitepressRun($context, $client);
    }

    /**
     * The import, with the HTTP client supplied by the caller so tests can
     * drive the whole route through a Guzzle MockHandler (the seam
     * StageRemoteFileTest established). $origins overrides the GitHub hosts,
     * which lets those tests use an IP literal and keep the SSRF check off DNS.
     */
    function haxcmsImportVitepressRun($context, $client, $origins = null)
    {
        $apiOrigin = isset($origins['api']) ? $origins['api'] : HAXCMS_VITEPRESS_API_ORIGIN;
        $rawOrigin = isset($origins['raw']) ? $origins['raw'] : HAXCMS_VITEPRESS_RAW_ORIGIN;
        $body = isset($context->body) && is_array($context->body) ? $context->body : array();
        $repoUrl = isset($body['repoUrl']) ? trim((string) $body['repoUrl']) : '';
        if ($repoUrl === '') {
            haxcmsImportVitepressSendError($context, 400, 'missing `repoUrl` param');
            return;
        }
        try {
            $repo = haxcmsImportVitepressParseRepoUrl($repoUrl);
            $source = haxcmsImportVitepressResolveRepository($client, $repo, $apiOrigin, $rawOrigin);
            $imported = haxcmsImportVitepressImportSite($client, $source);
        }
        catch (\Exception $error) {
            $status = (int) $error->getCode();
            if ($status !== 400 && $status !== 422) {
                $status = 400;
            }
            $message = $error->getMessage() !== '' ? $error->getMessage() : 'Unable to import the VitePress site';
            haxcmsImportVitepressSendError($context, $status, $message);
            return;
        }
        $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/system/api';
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 200, 'data' => array(
                'items'              => $imported['items'],
                'filename'           => $source['title'],
                'files'              => $imported['files'],
                'site'               => array('license' => $source['license']),
                'truncated'          => $imported['truncated'],
                'unmappedComponents' => $imported['unmappedComponents'],
            )),
            array('statusCode' => 200, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
    }

    function haxcmsImportVitepressSendError($context, $status, $message)
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

    /** owner, repo and optional branch out of a GitHub repository URL. */
    function haxcmsImportVitepressParseRepoUrl($sourceUrl)
    {
        $parsed = @parse_url($sourceUrl);
        if ($parsed === false || !isset($parsed['scheme']) || !isset($parsed['host'])) {
            throw new \RuntimeException('Invalid repoUrl: ' . $sourceUrl, 400);
        }
        if (strtolower($parsed['host']) !== 'github.com') {
            throw new \RuntimeException('repoUrl must be a github.com repository URL', 400);
        }
        $path = isset($parsed['path']) ? $parsed['path'] : '';
        $segments = array_values(array_filter(explode('/', $path), function ($piece) {
            return $piece !== '';
        }));
        if (count($segments) < 2) {
            throw new \RuntimeException('repoUrl is missing the owner/repo path: ' . $sourceUrl, 400);
        }
        // /tree/<branch> names a branch; deeper path segments are ignored
        // because the config discovery walks the whole tree anyway
        $branch = null;
        if (isset($segments[2]) && $segments[2] === 'tree' && isset($segments[3]) && $segments[3] !== '') {
            $branch = $segments[3];
        }
        return array(
            'owner'  => $segments[0],
            'name'   => preg_replace('/\.git$/i', '', $segments[1]),
            'branch' => $branch,
        );
    }

    /**
     * The GitHub API 403s every call that arrives without a User-Agent, so the
     * repository and tree reads carry one. convertGitbookToSite settled on
     * HAXcms-Import/1.0 in both backends; same string here.
     */
    function haxcmsImportVitepressApiHeaders()
    {
        return array(
            'User-Agent' => 'HAXcms-Import/1.0',
            'Accept'     => 'application/vnd.github.v3+json',
        );
    }

    function haxcmsImportVitepressRawHeaders()
    {
        return array('User-Agent' => 'HAXcms-Import/1.0');
    }

    function haxcmsImportVitepressFetchJson($client, $url, $description)
    {
        try {
            $response = SsrfGuard::safeGuzzleRequest($client, 'GET', $url, array(
                'headers'     => haxcmsImportVitepressApiHeaders(),
                'http_errors' => false,
            ));
        }
        catch (\Exception $e) {
            throw new \RuntimeException('Unable to reach ' . $description . ': ' . $e->getMessage(), 400);
        }
        $status = (int) $response->getStatusCode();
        if ($status < 200 || $status > 299) {
            throw new \RuntimeException(
                'Unable to read ' . $description . ' (HTTP ' . $status . ')',
                $status === 404 ? 422 : 400
            );
        }
        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Unable to parse ' . $description, 400);
        }
        return $decoded;
    }

    /** Fetch text, or null when it cannot be read; page bodies are optional. */
    function haxcmsImportVitepressFetchText($client, $url)
    {
        try {
            $response = SsrfGuard::safeGuzzleRequest($client, 'GET', $url, array(
                'headers'     => haxcmsImportVitepressRawHeaders(),
                'http_errors' => false,
            ));
        }
        catch (\Exception $e) {
            return null;
        }
        $status = (int) $response->getStatusCode();
        if ($status < 200 || $status > 299) {
            return null;
        }
        return (string) $response->getBody();
    }

    /**
     * Resolve the repository: default branch, file tree, VitePress config and
     * the site level metadata the config carries.
     */
    function haxcmsImportVitepressResolveRepository($client, $repo, $apiOrigin, $rawOrigin)
    {
        $repoData = haxcmsImportVitepressFetchJson(
            $client,
            $apiOrigin . '/repos/' . $repo['owner'] . '/' . $repo['name'],
            'the repository ' . $repo['owner'] . '/' . $repo['name']
        );
        $branch = $repo['branch'];
        if ($branch === null || $branch === '') {
            $branch = isset($repoData['default_branch']) && is_string($repoData['default_branch']) && $repoData['default_branch'] !== ''
                ? $repoData['default_branch']
                : 'main';
        }
        $treeData = haxcmsImportVitepressFetchJson(
            $client,
            $apiOrigin . '/repos/' . $repo['owner'] . '/' . $repo['name'] . '/git/trees/' . rawurlencode($branch) . '?recursive=1',
            'the file tree of ' . $repo['owner'] . '/' . $repo['name']
        );
        $tree = isset($treeData['tree']) && is_array($treeData['tree']) ? $treeData['tree'] : array();
        $paths = array();
        foreach ($tree as $entry) {
            if (is_array($entry) && isset($entry['type']) && $entry['type'] === 'blob' && isset($entry['path']) && is_string($entry['path'])) {
                $paths[$entry['path']] = true;
            }
        }
        $configPath = haxcmsImportVitepressFindConfigPath($paths);
        if ($configPath === null) {
            throw new \RuntimeException('No .vitepress/config file found in ' . $repo['owner'] . '/' . $repo['name'], 422);
        }
        $docsRoot = rtrim(substr($configPath, 0, strpos($configPath, '.vitepress/')), '/');
        $rawBase = $rawOrigin . '/' . $repo['owner'] . '/' . $repo['name'] . '/' . $branch;
        $configSource = haxcmsImportVitepressFetchText($client, $rawBase . '/' . haxcmsImportVitepressEncodeUri($configPath));
        if ($configSource === null) {
            throw new \RuntimeException('Unable to read ' . $configPath, 422);
        }
        $config = haxcmsImportVitepressReadConfig($configSource);
        $accessed = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
        return array(
            'owner'    => $repo['owner'],
            'name'     => $repo['name'],
            'branch'   => $branch,
            'paths'    => $paths,
            'docsRoot' => $docsRoot,
            'rawBase'  => $rawBase,
            'config'   => $config,
            'title'    => $config['title'] !== null && $config['title'] !== '' ? $config['title'] : $repo['name'],
            'license'  => $config['license'],
            'accessed' => $accessed,
        );
    }

    /**
     * The config that describes the site: docs/.vitepress first, then a config
     * at the repository root, then the shallowest one left. A repository can
     * carry more than one (an example site, a plugin fixture), so order matters.
     */
    function haxcmsImportVitepressFindConfigPath($paths)
    {
        $candidates = array();
        foreach (array_keys($paths) as $candidate) {
            if (preg_match(HAXCMS_VITEPRESS_CONFIG_PATH_REGEX, $candidate)) {
                $candidates[] = $candidate;
            }
        }
        if (count($candidates) === 0) {
            return null;
        }
        usort($candidates, function ($a, $b) {
            $rank = haxcmsImportVitepressConfigRank($a) - haxcmsImportVitepressConfigRank($b);
            if ($rank !== 0) {
                return $rank;
            }
            $depth = count(explode('/', $a)) - count(explode('/', $b));
            if ($depth !== 0) {
                return $depth;
            }
            return strcmp($a, $b);
        });
        return $candidates[0];
    }

    function haxcmsImportVitepressConfigRank($path)
    {
        if (strpos($path, 'docs/.vitepress/') === 0) {
            return 0;
        }
        if (strpos($path, '.vitepress/') === 0) {
            return 1;
        }
        return 2;
    }

    /**
     * Read the parts of a VitePress config that describe the site: the sidebar
     * outline, the themeConfig metadata, and the site title/description/base.
     * Values that are not plain data (a function call, a variable) are skipped
     * rather than executed, so an unreadable sidebar just means the file tree
     * is used instead.
     */
    function haxcmsImportVitepressReadConfig($source)
    {
        $config = array(
            'sidebar'       => null,
            'title'         => haxcmsImportVitepressReadStringValue($source, 'title'),
            'description'   => haxcmsImportVitepressReadStringValue($source, 'description'),
            'base'          => haxcmsImportVitepressReadStringValue($source, 'base'),
            'license'       => haxcmsImportVitepressNormalizeLicense(haxcmsImportVitepressReadStringValue($source, 'license')),
            'defaultAuthor' => haxcmsImportVitepressReadStringValue($source, 'defaultAuthor'),
            'workTitle'     => haxcmsImportVitepressReadStringValue($source, 'workTitle'),
            'siteUrl'       => haxcmsImportVitepressReadStringValue($source, 'siteUrl'),
        );
        $literal = haxcmsImportVitepressReadLiteralValue($source, 'sidebar');
        if ($literal !== null) {
            try {
                $sidebar = haxcmsImportVitepressParseJsLiteral($literal);
                $config['sidebar'] = haxcmsImportVitepressNormalizeSidebar($sidebar);
            }
            catch (\Exception $e) {
                $config['sidebar'] = null;
            }
        }
        return $config;
    }

    /** VitePress allows one sidebar array or a map of path prefix to array. */
    function haxcmsImportVitepressNormalizeSidebar($sidebar)
    {
        if (is_array($sidebar)) {
            return $sidebar;
        }
        if (!is_object($sidebar)) {
            return null;
        }
        $merged = array();
        foreach (get_object_vars($sidebar) as $entries) {
            if (is_array($entries)) {
                foreach ($entries as $entry) {
                    $merged[] = $entry;
                }
            }
        }
        return count($merged) > 0 ? $merged : null;
    }

    /** The position just after `key:`, ignoring matches inside strings/comments. */
    function haxcmsImportVitepressFindKeyPosition($source, $key)
    {
        $pattern = '/(^|[^A-Za-z0-9_$.\'"`])' . preg_quote($key, '/') . '\s*:/';
        $offset = 0;
        while ($offset <= strlen($source) && preg_match($pattern, $source, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $matchStart = $matches[0][1];
            $keyStart = $matchStart + strlen($matches[1][0]);
            $colon = strpos($source, ':', $keyStart);
            if ($colon !== false && !haxcmsImportVitepressIsInsideStringOrComment($source, $keyStart)) {
                return $colon + 1;
            }
            $offset = $matchStart + strlen($matches[0][0]);
        }
        return -1;
    }

    /** Whether an offset sits inside a string literal or a comment. */
    function haxcmsImportVitepressIsInsideStringOrComment($source, $offset)
    {
        $index = 0;
        $quote = '';
        $comment = '';
        $length = strlen($source);
        while ($index < $offset && $index < $length) {
            $character = $source[$index];
            $next = $index + 1 < $length ? $source[$index + 1] : '';
            if ($comment === 'line') {
                if ($character === "\n") {
                    $comment = '';
                }
            }
            elseif ($comment === 'block') {
                if ($character === '*' && $next === '/') {
                    $comment = '';
                    $index++;
                }
            }
            elseif ($quote !== '') {
                if ($character === '\\') {
                    $index++;
                }
                elseif ($character === $quote) {
                    $quote = '';
                }
            }
            elseif ($character === '/' && $next === '/') {
                $comment = 'line';
                $index++;
            }
            elseif ($character === '/' && $next === '*') {
                $comment = 'block';
                $index++;
            }
            elseif ($character === '"' || $character === "'" || $character === '`') {
                $quote = $character;
            }
            $index++;
        }
        return $quote !== '' || $comment !== '';
    }

    /** The balanced [..] or {..} literal assigned to a key, as text. */
    function haxcmsImportVitepressReadLiteralValue($source, $key)
    {
        $start = haxcmsImportVitepressFindKeyPosition($source, $key);
        if ($start === -1) {
            return null;
        }
        $length = strlen($source);
        $index = $start;
        while ($index < $length && preg_match('/\s/', $source[$index])) {
            $index++;
        }
        if ($index >= $length) {
            return null;
        }
        $opener = $source[$index];
        if ($opener !== '[' && $opener !== '{') {
            return null;
        }
        $closer = $opener === '[' ? ']' : '}';
        $depth = 0;
        $quote = '';
        $cursor = $index;
        while ($cursor < $length) {
            $character = $source[$cursor];
            if ($quote !== '') {
                if ($character === '\\') {
                    $cursor++;
                }
                elseif ($character === $quote) {
                    $quote = '';
                }
            }
            elseif ($character === '"' || $character === "'" || $character === '`') {
                $quote = $character;
            }
            elseif ($character === $opener) {
                $depth++;
            }
            elseif ($character === $closer) {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $index, $cursor + 1 - $index);
                }
            }
            $cursor++;
        }
        return null;
    }

    /** The string assigned to a key, or null when it is not a plain string. */
    function haxcmsImportVitepressReadStringValue($source, $key)
    {
        $start = haxcmsImportVitepressFindKeyPosition($source, $key);
        if ($start === -1) {
            return null;
        }
        $rest = substr($source, $start);
        if (!preg_match('/^\s*([\'"`])((?:\\\\.|(?!\1)[^\\\\])*)\1/s', $rest, $matches)) {
            return null;
        }
        return preg_replace('/\\\\([\'"`\\\\])/', '$1', $matches[2]);
    }

    /**
     * Read a JavaScript data literal (arrays, objects, strings, numbers,
     * booleans, null) without evaluating it. Anything else - a function, an
     * identifier, a spread, a template with an expression - throws, and the
     * caller falls back to the file tree.
     */
    function haxcmsImportVitepressParseJsLiteral($source)
    {
        $state = array('text' => (string) $source, 'index' => 0);
        haxcmsImportVitepressSkipTrivia($state);
        $value = haxcmsImportVitepressReadValue($state);
        haxcmsImportVitepressSkipTrivia($state);
        if ($state['index'] !== strlen($state['text'])) {
            throw new \RuntimeException('unexpected content after the literal');
        }
        return $value;
    }

    function haxcmsImportVitepressSkipTrivia(&$state)
    {
        $length = strlen($state['text']);
        while ($state['index'] < $length) {
            $character = $state['text'][$state['index']];
            $next = $state['index'] + 1 < $length ? $state['text'][$state['index'] + 1] : '';
            if (preg_match('/\s/', $character)) {
                $state['index']++;
            }
            elseif ($character === '/' && $next === '/') {
                while ($state['index'] < $length && $state['text'][$state['index']] !== "\n") {
                    $state['index']++;
                }
            }
            elseif ($character === '/' && $next === '*') {
                $state['index'] += 2;
                while ($state['index'] < $length
                    && !($state['text'][$state['index']] === '*'
                        && $state['index'] + 1 < $length
                        && $state['text'][$state['index'] + 1] === '/')) {
                    $state['index']++;
                }
                $state['index'] += 2;
            }
            else {
                return;
            }
        }
    }

    function haxcmsImportVitepressReadValue(&$state)
    {
        haxcmsImportVitepressSkipTrivia($state);
        if ($state['index'] >= strlen($state['text'])) {
            throw new \RuntimeException('unexpected end of the literal');
        }
        $character = $state['text'][$state['index']];
        if ($character === '[') {
            return haxcmsImportVitepressReadArray($state);
        }
        if ($character === '{') {
            return haxcmsImportVitepressReadObject($state);
        }
        if ($character === '"' || $character === "'" || $character === '`') {
            return haxcmsImportVitepressReadString($state);
        }
        $literal = substr($state['text'], $state['index']);
        if (preg_match('/^(true|false|null)\b/', $literal, $keyword)) {
            $state['index'] += strlen($keyword[1]);
            if ($keyword[1] === 'true') {
                return true;
            }
            return $keyword[1] === 'false' ? false : null;
        }
        if (preg_match('/^-?\d+(\.\d+)?([eE][+-]?\d+)?/', $literal, $number)) {
            $state['index'] += strlen($number[0]);
            return $number[0] + 0;
        }
        throw new \RuntimeException('unsupported value at ' . $state['index']);
    }

    function haxcmsImportVitepressReadArray(&$state)
    {
        $values = array();
        $state['index']++;
        while (true) {
            haxcmsImportVitepressSkipTrivia($state);
            $character = haxcmsImportVitepressCurrent($state);
            if ($character === ']') {
                $state['index']++;
                return $values;
            }
            $values[] = haxcmsImportVitepressReadValue($state);
            haxcmsImportVitepressSkipTrivia($state);
            $character = haxcmsImportVitepressCurrent($state);
            if ($character === ',') {
                $state['index']++;
            }
            elseif ($character !== ']') {
                throw new \RuntimeException('expected , or ] at ' . $state['index']);
            }
        }
    }

    function haxcmsImportVitepressReadObject(&$state)
    {
        // an object, not an array: PHP arrays cannot express the difference and
        // normalizeSidebar needs it ({} falls back to the file tree, [] does not)
        $value = new \stdClass();
        $state['index']++;
        while (true) {
            haxcmsImportVitepressSkipTrivia($state);
            $character = haxcmsImportVitepressCurrent($state);
            if ($character === '}') {
                $state['index']++;
                return $value;
            }
            if ($character === '"' || $character === "'" || $character === '`') {
                $key = haxcmsImportVitepressReadString($state);
            }
            else {
                $rest = substr($state['text'], $state['index']);
                if (!preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*/', $rest, $identifier)) {
                    throw new \RuntimeException('expected a key at ' . $state['index']);
                }
                $key = $identifier[0];
                $state['index'] += strlen($key);
            }
            haxcmsImportVitepressSkipTrivia($state);
            if (haxcmsImportVitepressCurrent($state) !== ':') {
                throw new \RuntimeException('expected : after ' . $key);
            }
            $state['index']++;
            $value->{$key} = haxcmsImportVitepressReadValue($state);
            haxcmsImportVitepressSkipTrivia($state);
            $character = haxcmsImportVitepressCurrent($state);
            if ($character === ',') {
                $state['index']++;
            }
            elseif ($character !== '}') {
                throw new \RuntimeException('expected , or } at ' . $state['index']);
            }
        }
    }

    function haxcmsImportVitepressReadString(&$state)
    {
        $quote = $state['text'][$state['index']];
        $value = '';
        $state['index']++;
        $length = strlen($state['text']);
        while ($state['index'] < $length) {
            $character = $state['text'][$state['index']];
            if ($character === '\\') {
                $escaped = $state['index'] + 1 < $length ? $state['text'][$state['index'] + 1] : '';
                if ($escaped === 'n') {
                    $value .= "\n";
                }
                elseif ($escaped === 't') {
                    $value .= "\t";
                }
                else {
                    $value .= $escaped;
                }
                $state['index'] += 2;
                continue;
            }
            if ($character === $quote) {
                $state['index']++;
                return $value;
            }
            if ($quote === '`' && $character === '$'
                && $state['index'] + 1 < $length && $state['text'][$state['index'] + 1] === '{') {
                throw new \RuntimeException('template expressions are not plain data');
            }
            $value .= $character;
            $state['index']++;
        }
        throw new \RuntimeException('unterminated string');
    }

    /** The character at the cursor, or '' past the end. */
    function haxcmsImportVitepressCurrent($state)
    {
        return $state['index'] < strlen($state['text']) ? $state['text'][$state['index']] : '';
    }

    /** cc-by-sa, CC-BY-SA and by-sa all mean the same license code. */
    function haxcmsImportVitepressNormalizeLicense($value)
    {
        if (!is_string($value)) {
            return null;
        }
        $code = preg_replace('/^cc-/', '', strtolower(trim($value)));
        $supported = array('by', 'by-sa', 'by-nd', 'by-nc', 'by-nc-sa', 'by-nc-nd');
        return in_array($code, $supported, true) ? $code : null;
    }

    /** Slug for one page title, trimmed the way convertOpenstaxToSite trims. */
    function haxcmsImportVitepressTitleSlug($title)
    {
        $cleaned = haxcmsImportCleanTitle($title);
        $trimmed = trim($cleaned, '-');
        return $trimmed === '' ? $cleaned : $trimmed;
    }

    /**
     * Sidebar entries into flat, ordered nodes. A group carrying both a link
     * and items becomes a page with children; a group with only items becomes
     * a landing page, the way convertOpenstaxToSite treats chapter headings.
     */
    function haxcmsImportVitepressOutlineFromSidebar($sidebar, $source)
    {
        $nodes = array();
        haxcmsImportVitepressWalkSidebar($sidebar, 0, null, $nodes, $source);
        return $nodes;
    }

    function haxcmsImportVitepressWalkSidebar($entries, $depth, $parentIndex, &$nodes, $source)
    {
        $order = 0;
        foreach ($entries as $entry) {
            if (!is_object($entry) || !isset($entry->text) || !is_string($entry->text)) {
                continue;
            }
            $link = isset($entry->link) && is_string($entry->link) ? $entry->link : null;
            $nodes[] = array(
                'title'       => $entry->text,
                'path'        => $link !== null ? haxcmsImportVitepressResolveDocPath($link, $source) : null,
                'link'        => $link,
                'depth'       => $depth,
                'order'       => $order,
                'parentIndex' => $parentIndex,
            );
            $index = count($nodes) - 1;
            $order++;
            if (isset($entry->items) && is_array($entry->items)) {
                haxcmsImportVitepressWalkSidebar($entry->items, $depth + 1, $index, $nodes, $source);
            }
        }
    }

    /**
     * Every markdown page in the docs root, in tree order, when no sidebar can
     * be read. Mirrors VitePress's own behavior of serving whatever is on disk.
     */
    function haxcmsImportVitepressOutlineFromTree($source)
    {
        $prefix = $source['docsRoot'] === '' ? '' : $source['docsRoot'] . '/';
        $pages = array();
        foreach (array_keys($source['paths']) as $candidate) {
            if (strpos($candidate, $prefix) !== 0 || !preg_match('/\.md$/i', $candidate)) {
                continue;
            }
            if (strpos($candidate, '/.vitepress/') !== false || strpos($candidate, 'node_modules/') !== false) {
                continue;
            }
            $pages[] = $candidate;
        }
        usort($pages, function ($a, $b) {
            $depthA = count(explode('/', $a));
            $depthB = count(explode('/', $b));
            if ($depthA !== $depthB) {
                return $depthA - $depthB;
            }
            $indexA = preg_match('#(^|/)index\.md$#i', $a) ? 0 : 1;
            $indexB = preg_match('#(^|/)index\.md$#i', $b) ? 0 : 1;
            if ($indexA !== $indexB) {
                return $indexA - $indexB;
            }
            return strcmp($a, $b);
        });
        $nodes = array();
        $order = 0;
        foreach ($pages as $path) {
            $nodes[] = array(
                'title'       => haxcmsImportVitepressTitleFromPath($path),
                'path'        => $path,
                'link'        => '/' . preg_replace('/\.md$/i', '', substr($path, strlen($prefix))),
                'depth'       => 0,
                'order'       => $order,
                'parentIndex' => null,
            );
            $order++;
        }
        return $nodes;
    }

    function haxcmsImportVitepressTitleFromPath($path)
    {
        $pieces = explode('/', $path);
        $name = preg_replace('/\.md$/i', '', array_pop($pieces));
        $words = trim(preg_replace('/[-_]+/', ' ', $name));
        if ($words === '' || $words === 'index') {
            return 'Home';
        }
        return strtoupper(substr($words, 0, 1)) . substr($words, 1);
    }

    /**
     * A link to the markdown file that backs it. Sidebar links are
     * site-absolute; links inside a page may also be relative, so they resolve
     * against the page that carries them.
     */
    function haxcmsImportVitepressResolveDocPath($link, $source, $node = null)
    {
        $target = explode('?', explode('#', (string) $link)[0])[0];
        $base = $source['config']['base'];
        if ($base !== null && $base !== '' && strpos($target, $base) === 0) {
            $target = '/' . substr($target, strlen($base));
        }
        $target = rtrim(preg_replace('/\.(md|html)$/i', '', $target), '/');
        $prefix = $source['docsRoot'] === '' ? '' : $source['docsRoot'] . '/';
        if (strpos($target, '/') === 0) {
            $resolved = $prefix . preg_replace('#^/#', '', $target);
        }
        elseif ($node !== null && isset($node['path']) && $node['path'] !== null && $node['path'] !== '') {
            $pieces = explode('/', $node['path']);
            array_pop($pieces);
            $resolved = haxcmsImportVitepressNormalizePath(implode('/', $pieces) . '/' . $target);
        }
        else {
            $resolved = $prefix . $target;
        }
        $resolved = rtrim($resolved, '/');
        if ($resolved === '' || $resolved === rtrim($prefix, '/')) {
            return $prefix . 'index.md';
        }
        $direct = $resolved . '.md';
        if (isset($source['paths'][$direct])) {
            return $direct;
        }
        $nested = $resolved . '/index.md';
        if (isset($source['paths'][$nested])) {
            return $nested;
        }
        return $direct;
    }

    function haxcmsImportVitepressBudgetExhausted($run)
    {
        $limits = haxcmsImportVitepressLimits();
        return (microtime(true) - $run['startedAt']) > $limits['fetchBudgetSeconds'];
    }

    /** Build the items, page bodies and file map for one repository. */
    function haxcmsImportVitepressImportSite($client, $source)
    {
        $outline = $source['config']['sidebar'] !== null
            ? haxcmsImportVitepressOutlineFromSidebar($source['config']['sidebar'], $source)
            : haxcmsImportVitepressOutlineFromTree($source);
        if (count($outline) === 0) {
            throw new \RuntimeException('The VitePress site has no pages to import', 422);
        }
        $run = array(
            'source'      => $source,
            'files'       => array(),
            'fileNames'   => array(),
            'filesByPath' => array(),
            'slugByPath'  => array(),
            'unmapped'    => array(),
            'startedAt'   => microtime(true),
            'truncated'   => false,
        );
        $limits = haxcmsImportVitepressLimits();
        $items = array();
        // first pass: items and slugs, so page bodies can link to siblings by slug
        foreach ($outline as $index => $node) {
            $parentItem = $node['parentIndex'] === null ? null : $items[$outline[$node['parentIndex']]['itemIndex']];
            $slug = haxcmsImportVitepressTitleSlug($node['title']);
            if ($parentItem !== null) {
                $slug = $parentItem['slug'] . '/' . $slug;
            }
            $item = haxcmsImportBuildItem(
                $node['title'],
                $slug,
                $node['order'],
                $parentItem !== null ? $parentItem['id'] : null,
                $node['depth'],
                ''
            );
            $item['metadata'] = array(
                'sourceType' => 'vitepress',
                'vitepress'  => array(
                    'repo'      => $source['owner'] . '/' . $source['name'],
                    'branch'    => $source['branch'],
                    'path'      => $node['path'],
                    'license'   => $source['license'],
                    'author'    => $source['config']['defaultAuthor'],
                    'workTitle' => $source['config']['workTitle'],
                    'accessed'  => $source['accessed'],
                ),
            );
            $sourceUrl = haxcmsImportVitepressPageSourceUrl($node, $source);
            if ($sourceUrl !== null) {
                $item['metadata']['source'] = $sourceUrl;
            }
            $items[] = $item;
            $outline[$index]['itemIndex'] = count($items) - 1;
            if ($node['path'] !== null) {
                $run['slugByPath'][$node['path']] = $slug;
            }
        }
        // second pass: page bodies, bounded by the page cap and the time budget
        $fetched = 0;
        foreach ($outline as $index => $node) {
            $itemIndex = $node['itemIndex'];
            if ($node['path'] === null) {
                // a sidebar group with no link of its own: a landing page for children
                $items[$itemIndex]['contents'] = '<p></p>';
                continue;
            }
            $sourceUrl = isset($items[$itemIndex]['metadata']['source']) ? $items[$itemIndex]['metadata']['source'] : null;
            if ($fetched >= $limits['maxPages'] || haxcmsImportVitepressBudgetExhausted($run)) {
                $run['truncated'] = true;
                $items[$itemIndex]['contents'] = haxcmsImportVitepressSourceFallback($sourceUrl);
                continue;
            }
            if ($fetched > 0) {
                usleep($limits['requestDelayMs'] * 1000);
            }
            $markdown = haxcmsImportVitepressFetchText(
                $client,
                $source['rawBase'] . '/' . haxcmsImportVitepressEncodeUri($node['path'])
            );
            $fetched++;
            if ($markdown === null) {
                $items[$itemIndex]['contents'] = haxcmsImportVitepressSourceFallback($sourceUrl);
                continue;
            }
            $items[$itemIndex]['contents'] = haxcmsImportVitepressRenderPage($markdown, $node, $items[$itemIndex], $run);
        }
        $unmapped = array_keys($run['unmapped']);
        sort($unmapped);
        return array(
            'items'              => $items,
            'files'              => $run['files'],
            'truncated'          => $run['truncated'],
            'unmappedComponents' => $unmapped,
        );
    }

    /** Where the page lives on the published VitePress site, when it says. */
    function haxcmsImportVitepressPageSourceUrl($node, $source)
    {
        $siteUrl = $source['config']['siteUrl'];
        if ($node['link'] === null || $siteUrl === null || $siteUrl === '') {
            return null;
        }
        $base = $source['config']['base'] !== null ? rtrim($source['config']['base'], '/') : '';
        return rtrim($siteUrl, '/') . $base . $node['link'];
    }

    function haxcmsImportVitepressSourceFallback($sourceUrl)
    {
        if ($sourceUrl === null || $sourceUrl === '') {
            return '<p></p>';
        }
        return '<p>Read this page on <a href="' . SanitizeContent::escapeHTMLAttribute($sourceUrl) . '">the source site</a>.</p>';
    }

    /** Split YAML frontmatter from the markdown body. */
    function haxcmsImportVitepressSplitFrontmatter($markdown)
    {
        if (!preg_match('/^---\r?\n(.*?)\r?\n---\r?\n?/s', $markdown, $matches)) {
            return array('data' => array(), 'body' => $markdown);
        }
        $data = array();
        try {
            $parsed = \Symfony\Component\Yaml\Yaml::parse($matches[1]);
            if (is_array($parsed)) {
                $data = $parsed;
            }
        }
        catch (\Exception $e) {
            $data = array();
        }
        return array('data' => $data, 'body' => substr($markdown, strlen($matches[0])));
    }

    /**
     * The OER containers the VitePress OER plugin defines (vitepress-plugin in
     * the source repo). Each becomes an oer-schema element; the container's
     * attributes become nested oer-schema properties under the same names the
     * plugin emits as itemprops, so the imported page carries the same
     * vocabulary.
     */
    function haxcmsImportVitepressOerContainers()
    {
        return array(
            'learning-objective' => array(
                'resource'   => 'LearningObjective',
                'properties' => array('skill' => 'skill', 'course' => 'forCourse'),
            ),
            'assessment' => array(
                'resource'   => 'Assessment',
                'properties' => array('type' => 'additionalType', 'points' => 'gradingFormat', 'assessing' => 'assessing'),
            ),
            'practice' => array(
                'resource'   => 'Practice',
                'properties' => array('action' => 'typeOfAction', 'material' => 'material'),
            ),
            'learning-component' => array(
                'resource'   => 'LearningComponent',
                'properties' => array('action' => 'typeOfAction', 'objective' => 'hasLearningObjective'),
            ),
            'instructional-pattern' => array(
                'resource'   => 'InstructionalPattern',
                'properties' => array('type' => 'additionalType', 'title' => 'name'),
            ),
        );
    }

    /**
     * Render VitePress markdown. MarkdownExtra covers the footnotes the source
     * pages use; the ::: OER containers markdown-it-container handles on the
     * Node side have no PHP plugin, so they are lifted out to placeholders,
     * rendered on their own, and put back after the page renders.
     */
    function haxcmsImportVitepressRenderMarkdown($markdown)
    {
        $containers = haxcmsImportVitepressOerContainers();
        $placeholders = array();
        $body = $markdown;
        $names = implode('|', array_map(function ($name) {
            return preg_quote($name, '/');
        }, array_keys($containers)));
        $pattern = '/^:::[ \t]*(' . $names . ')[ \t]*([^\r\n]*)\r?\n(.*?)\r?\n:::[ \t]*$/ms';
        while (preg_match($pattern, $body, $matches, PREG_OFFSET_CAPTURE)) {
            $whole = $matches[0][0];
            $name = $matches[1][0];
            $container = $containers[$name];
            $attributes = haxcmsImportVitepressParseAttributes($matches[2][0]);
            $open = '<oer-schema typeof="' . SanitizeContent::escapeHTMLAttribute($container['resource']) . '">' . "\n";
            foreach ($attributes as $key => $value) {
                if (isset($container['properties'][$key])) {
                    $open .= '<oer-schema oer-property="' . SanitizeContent::escapeHTMLAttribute($container['properties'][$key]) . '"'
                        . ' text="' . SanitizeContent::escapeHTMLAttribute($value) . '"></oer-schema>' . "\n";
                }
            }
            $inner = haxcmsImportVitepressRenderMarkdown($matches[3][0]);
            $token = '<!--haxcms-oer-' . count($placeholders) . '-->';
            $placeholders[$token] = $open . $inner . "\n" . '</oer-schema>';
            $body = substr_replace($body, $token, $matches[0][1], strlen($whole));
        }
        $html = \Michelf\MarkdownExtra::defaultTransform($body);
        foreach ($placeholders as $token => $replacement) {
            $html = str_replace($token, $replacement, $html);
        }
        return $html;
    }

    /** name="value" pairs and bare flags out of a tag or container info string. */
    function haxcmsImportVitepressParseAttributes($source)
    {
        $attributes = array();
        $pattern = '/([A-Za-z][A-Za-z0-9_:-]*)(?:\s*=\s*("([^"]*)"|\'([^\']*)\'|\{([^}]*)\}))?/';
        if (!preg_match_all($pattern, (string) $source, $matches, PREG_SET_ORDER)) {
            return $attributes;
        }
        foreach ($matches as $match) {
            $name = $match[1];
            if (!isset($match[2]) || $match[2] === '') {
                $attributes[$name] = 'true';
                continue;
            }
            $value = '';
            if (isset($match[3]) && $match[3] !== '') {
                $value = $match[3];
            }
            elseif (isset($match[4]) && $match[4] !== '') {
                $value = $match[4];
            }
            elseif (isset($match[5])) {
                $value = $match[5];
            }
            $attributes[$name] = trim(trim(trim($value), '"\''));
        }
        return $attributes;
    }

    /**
     * One markdown page into HAX-ready HTML: Vue components are mapped or
     * unwrapped before rendering, then assets, links and images are rewritten
     * against the imported site.
     */
    function haxcmsImportVitepressRenderPage($markdown, $node, &$item, &$run)
    {
        $split = haxcmsImportVitepressSplitFrontmatter($markdown);
        if (isset($split['data']['title']) && is_string($split['data']['title']) && trim($split['data']['title']) !== '') {
            $item['title'] = trim($split['data']['title']);
        }
        $frontmatterLicense = isset($split['data']['license']) ? $split['data']['license'] : null;
        $pageLicense = haxcmsImportVitepressNormalizeLicense($frontmatterLicense);
        if ($pageLicense === null) {
            $pageLicense = $run['source']['license'];
        }
        $author = isset($split['data']['author']) && is_string($split['data']['author'])
            ? $split['data']['author']
            : $run['source']['config']['defaultAuthor'];
        if ($pageLicense !== null) {
            $item['metadata']['vitepress']['license'] = $pageLicense;
        }
        if ($author !== null && $author !== '') {
            $item['metadata']['vitepress']['author'] = $author;
        }
        $body = haxcmsImportVitepressMapVueComponents($split['body'], $node, $run);
        $html = haxcmsImportVitepressRenderMarkdown($body);
        $contents = trim(haxcmsImportVitepressRewriteHtml($html, $node, $run));
        if ($pageLicense !== null) {
            $contents .= "\n" . haxcmsImportVitepressLicenseElement($pageLicense, $item, $run, $author);
        }
        return $contents === '' ? '<p></p>' : $contents;
    }

    /**
     * VitePress pages embed Vue components. VideoEmbed is the one with a HAX
     * equivalent, so it becomes a video-player; anything else is unwrapped to
     * its content and reported in unmappedComponents rather than left as a tag
     * the site cannot render.
     */
    function haxcmsImportVitepressMapVueComponents($markdown, $node, &$run)
    {
        $body = preg_replace_callback(
            '#<VideoEmbed\b(.*?)/?>(?:\s*</VideoEmbed>)?#s',
            function ($match) use ($node, &$run) {
                $attributes = haxcmsImportVitepressParseAttributes($match[1]);
                $source = isset($attributes['src']) ? $attributes['src'] : '';
                if ($source === '') {
                    return '';
                }
                $resolved = haxcmsImportVitepressRegisterAsset($source, $node, $run);
                if ($resolved === null) {
                    $resolved = haxcmsImportVitepressAbsoluteSourceUrl($source, $node, $run);
                }
                $tag = '<video-player source="' . SanitizeContent::escapeHTMLAttribute($resolved) . '"';
                if (isset($attributes['title']) && $attributes['title'] !== '') {
                    $tag .= ' media-title="' . SanitizeContent::escapeHTMLAttribute($attributes['title']) . '"';
                }
                elseif (isset($attributes['caption']) && $attributes['caption'] !== '') {
                    $tag .= ' media-title="' . SanitizeContent::escapeHTMLAttribute($attributes['caption']) . '"';
                }
                $tag .= '>';
                if (isset($attributes['caption']) && $attributes['caption'] !== '') {
                    $tag .= '<div slot="caption">' . haxcmsImportVitepressEscapeHtml($attributes['caption']) . '</div>';
                }
                return $tag . '</video-player>';
            },
            $markdown
        );
        // any other PascalCase component: keep the content, drop the wrapper
        $body = preg_replace_callback(
            '#</?([A-Z][A-Za-z0-9]*)\b[^>]*>#',
            function ($match) use (&$run) {
                $run['unmapped'][$match[1]] = true;
                return '';
            },
            $body
        );
        return $body;
    }

    function haxcmsImportVitepressEscapeHtml($value)
    {
        return str_replace(
            array('&', '<', '>'),
            array('&amp;', '&lt;', '&gt;'),
            (string) $value
        );
    }

    /**
     * Rewrite the rendered page against the imported site: images become
     * media-image, local videos point at files/, in-book links point at the
     * imported slugs. One DOM pass, using the loadHTML idiom importUtils uses.
     */
    function haxcmsImportVitepressRewriteHtml($html, $node, &$run)
    {
        if (trim($html) === '') {
            return '';
        }
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"?><div id="haxcms-vitepress-wrapper">' . $html . '</div>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $wrapper = $dom->getElementById('haxcms-vitepress-wrapper');
        if ($wrapper === null) {
            $wrappers = $dom->getElementsByTagName('div');
            $wrapper = $wrappers->length > 0 ? $wrappers->item(0) : null;
        }
        if ($wrapper === null) {
            return $html;
        }
        haxcmsImportVitepressRewriteMedia($dom, $wrapper, $node, $run);
        haxcmsImportVitepressRewriteLinks($wrapper, $node, $run);
        $rendered = '';
        foreach ($wrapper->childNodes as $child) {
            $rendered .= $dom->saveHTML($child);
        }
        return $rendered;
    }

    /** Images become media-image; local videos become video-player sources. */
    function haxcmsImportVitepressRewriteMedia($dom, $wrapper, $node, &$run)
    {
        $images = array();
        foreach ($wrapper->getElementsByTagName('img') as $image) {
            $images[] = $image;
        }
        foreach ($images as $image) {
            $source = $image->getAttribute('src');
            if ($source === '') {
                continue;
            }
            $resolved = haxcmsImportVitepressRegisterAsset($source, $node, $run);
            if ($resolved === null) {
                $resolved = haxcmsImportVitepressAbsoluteSourceUrl($source, $node, $run);
            }
            if (preg_match(HAXCMS_VITEPRESS_IMAGE_EXTENSION_REGEX, $resolved) || strpos($resolved, 'files/') === 0) {
                $mediaImage = $dom->createElement('media-image');
                $mediaImage->setAttribute('source', $resolved);
                $mediaImage->setAttribute('alt', $image->getAttribute('alt'));
                $image->parentNode->replaceChild($mediaImage, $image);
            }
            else {
                $image->setAttribute('src', $resolved);
            }
        }
        $players = array();
        foreach ($wrapper->getElementsByTagName('video-player') as $player) {
            $players[] = $player;
        }
        foreach ($players as $player) {
            $source = $player->getAttribute('source');
            if ($source !== '' && strpos($source, 'files/') !== 0 && strpos($source, 'http') !== 0) {
                $resolved = haxcmsImportVitepressRegisterAsset($source, $node, $run);
                if ($resolved === null) {
                    $resolved = haxcmsImportVitepressAbsoluteSourceUrl($source, $node, $run);
                }
                $player->setAttribute('source', $resolved);
            }
        }
    }

    /**
     * In-book links point at the imported slugs; links to importable files join
     * the file map; everything else is absolutized against the source repo.
     */
    function haxcmsImportVitepressRewriteLinks($wrapper, $node, &$run)
    {
        $links = array();
        foreach ($wrapper->getElementsByTagName('a') as $link) {
            $links[] = $link;
        }
        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            if ($href === '' || strpos($href, '#') === 0 || preg_match('/^[a-z][a-z0-9+.-]*:/i', $href)) {
                continue;
            }
            $hashAt = strpos($href, '#');
            $withoutHash = $hashAt === false ? $href : substr($href, 0, $hashAt);
            $docPath = haxcmsImportVitepressResolveDocPath($withoutHash, $run['source'], $node);
            if (isset($run['slugByPath'][$docPath])) {
                $hash = $hashAt === false ? '' : substr($href, $hashAt);
                $link->setAttribute('href', $run['slugByPath'][$docPath] . $hash);
                continue;
            }
            $asset = haxcmsImportVitepressRegisterAsset($href, $node, $run);
            $link->setAttribute('href', $asset !== null ? $asset : haxcmsImportVitepressAbsoluteSourceUrl($href, $node, $run));
        }
    }

    /**
     * Resolve a page reference to a repository file and add it to the file map.
     * VitePress serves <docs>/public at the site root, so an absolute reference
     * is looked up there first and then under the docs root itself. Returns the
     * site-relative path, or null when the file is not importable.
     */
    function haxcmsImportVitepressRegisterAsset($reference, $node, &$run)
    {
        if (!is_string($reference) || $reference === '' || preg_match('/^[a-z][a-z0-9+.-]*:/i', $reference)) {
            return null;
        }
        $clean = explode('?', explode('#', $reference)[0])[0];
        if ($clean === '' || !preg_match(HAXCMS_VITEPRESS_IMPORTABLE_ASSET_REGEX, $clean)) {
            return null;
        }
        $repoPath = haxcmsImportVitepressResolveAssetPath($clean, $node, $run);
        if ($repoPath === null) {
            return null;
        }
        if (isset($run['filesByPath'][$repoPath])) {
            return $run['filesByPath'][$repoPath];
        }
        $limits = haxcmsImportVitepressLimits();
        if (count($run['files']) >= $limits['maxFiles']) {
            $run['truncated'] = true;
            return null;
        }
        $name = haxcmsImportVitepressUniqueFileName($repoPath, $run);
        $sitePath = 'files/' . $name;
        $run['files'][$sitePath] = haxcmsImportVitepressEncodeUri($run['source']['rawBase'] . '/' . $repoPath);
        $run['filesByPath'][$repoPath] = $sitePath;
        return $sitePath;
    }

    /** An asset reference to a path inside the repository, or null. */
    function haxcmsImportVitepressResolveAssetPath($reference, $node, $run)
    {
        $source = $run['source'];
        $prefix = $source['docsRoot'] === '' ? '' : $source['docsRoot'] . '/';
        $candidates = array();
        if (strpos($reference, '/') === 0) {
            $target = preg_replace('#^/#', '', $reference);
            $base = $source['config']['base'];
            if ($base !== null && $base !== '') {
                $base = preg_replace('#^/#', '', $base);
                if ($base !== '' && strpos($target, $base) === 0) {
                    $target = substr($target, strlen($base));
                }
            }
            // public/ is served at the site root, so it wins over the docs root
            $candidates = array($prefix . 'public/' . $target, $prefix . $target, $target);
        }
        else {
            if (isset($node['path']) && $node['path'] !== null && $node['path'] !== '') {
                $pieces = explode('/', $node['path']);
                array_pop($pieces);
                $directory = implode('/', $pieces);
            }
            else {
                $directory = rtrim($prefix, '/');
            }
            $candidates = array(haxcmsImportVitepressNormalizePath($directory . '/' . $reference));
        }
        foreach ($candidates as $candidate) {
            if (isset($source['paths'][$candidate])) {
                return $candidate;
            }
        }
        return null;
    }

    function haxcmsImportVitepressNormalizePath($path)
    {
        $parts = array();
        foreach (explode('/', $path) as $piece) {
            if ($piece === '' || $piece === '.') {
                continue;
            }
            if ($piece === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $piece;
        }
        return implode('/', $parts);
    }

    /** files/ is flat, so keep basenames unique across the import. */
    function haxcmsImportVitepressUniqueFileName($repoPath, &$run)
    {
        $pieces = explode('/', $repoPath);
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', array_pop($pieces));
        if (!isset($run['fileNames'][$base])) {
            $run['fileNames'][$base] = true;
            return $base;
        }
        $dot = strrpos($base, '.');
        $stem = $dot === false ? $base : substr($base, 0, $dot);
        $extension = $dot === false ? '' : substr($base, $dot);
        $counter = 1;
        $candidate = $stem . '-' . $counter . $extension;
        while (isset($run['fileNames'][$candidate])) {
            $counter++;
            $candidate = $stem . '-' . $counter . $extension;
        }
        $run['fileNames'][$candidate] = true;
        return $candidate;
    }

    /**
     * A reference that stays remote - an extension createSite will not import,
     * or a file the repository does not actually carry - pointed at the source
     * repository so the page still resolves.
     */
    function haxcmsImportVitepressAbsoluteSourceUrl($reference, $node, $run)
    {
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $reference)) {
            return $reference;
        }
        $source = $run['source'];
        $clean = explode('?', explode('#', $reference)[0])[0];
        $repoPath = haxcmsImportVitepressResolveAssetPath($clean, $node, $run);
        if ($repoPath !== null) {
            return haxcmsImportVitepressEncodeUri($source['rawBase'] . '/' . $repoPath);
        }
        $prefix = $source['docsRoot'] === '' ? '' : $source['docsRoot'] . '/';
        if (strpos($clean, '/') === 0) {
            return haxcmsImportVitepressEncodeUri($source['rawBase'] . '/' . $prefix . preg_replace('#^/#', '', $clean));
        }
        if (isset($node['path']) && $node['path'] !== null && $node['path'] !== '') {
            $pieces = explode('/', $node['path']);
            array_pop($pieces);
            $directory = implode('/', $pieces);
        }
        else {
            $directory = rtrim($prefix, '/');
        }
        return haxcmsImportVitepressEncodeUri($source['rawBase'] . '/' . haxcmsImportVitepressNormalizePath($directory . '/' . $clean));
    }

    /** Attribution for one page, using the license code the source declared. */
    function haxcmsImportVitepressLicenseElement($license, $item, $run, $author)
    {
        $title = $item['title'];
        if ($title === null || $title === '') {
            $title = $run['source']['config']['workTitle'] !== null && $run['source']['config']['workTitle'] !== ''
                ? $run['source']['config']['workTitle']
                : $run['source']['title'];
        }
        $element = '<license-element license="' . SanitizeContent::escapeHTMLAttribute($license) . '"'
            . ' title="' . SanitizeContent::escapeHTMLAttribute($title) . '"';
        if ($author !== null && $author !== '') {
            $element .= ' creator="' . SanitizeContent::escapeHTMLAttribute($author) . '"';
        }
        if (isset($item['metadata']['source'])) {
            $element .= ' source="' . SanitizeContent::escapeHTMLAttribute($item['metadata']['source']) . '"';
        }
        return $element . '></license-element>';
    }

    /**
     * encodeURI: escape what a URL cannot carry literally while leaving the
     * reserved characters alone, so raw.githubusercontent.com paths match what
     * the Node importer produces.
     */
    function haxcmsImportVitepressEncodeUri($value)
    {
        $encoded = rawurlencode((string) $value);
        return str_replace(
            array('%3B', '%2C', '%2F', '%3F', '%3A', '%40', '%26', '%3D', '%2B', '%24', '%21', '%2A', '%27', '%28', '%29', '%23', '%5B', '%5D'),
            array(';', ',', '/', '?', ':', '@', '&', '=', '+', '$', '!', '*', "'", '(', ')', '#', '[', ']'),
            $encoded
        );
    }
}
