<?php
include_once dirname(__FILE__) . '/../../../siteRoutes/SiteRouteUtils.php';
include_once dirname(__FILE__) . '/importUtils.php';

$__vendorAutoload = dirname(__FILE__) . '/../../../../vendor/autoload.php';
if (file_exists($__vendorAutoload)) {
    require_once $__vendorAutoload;
}
include_once dirname(__FILE__) . '/../../../SsrfGuard.php';
include_once dirname(__FILE__) . '/../../../SanitizeContent.php';
include_once dirname(__FILE__) . '/../../../Licenses.php';

// Constants and limits mirror haxcms-nodejs convertQuartoToSite.js. Page bodies
// are fetched one at a time; assets travel to createSite as URLs, which
// downloads them behind SsrfGuard (haxtheweb/issues#3060), so only their count
// is capped here.
if (!defined('HAXCMS_QUARTO_CONFIG_PATH_REGEX')) {
    define('HAXCMS_QUARTO_API_ORIGIN', 'https://api.github.com');
    define('HAXCMS_QUARTO_RAW_ORIGIN', 'https://raw.githubusercontent.com');
    // Quarto keeps its project file at the root of the project it renders
    define('HAXCMS_QUARTO_CONFIG_PATH_REGEX', '#(^|/)_quarto\.ya?ml$#D');
    // document types Quarto renders that this importer reads as pages
    define('HAXCMS_QUARTO_PAGE_EXTENSION_REGEX', '/\.(qmd|rmd|md|ipynb)$/iD');
    define('HAXCMS_QUARTO_NOTEBOOK_REGEX', '/\.ipynb$/iD');
    // createSite only accepts these extensions in build.files, so anything else
    // (an .svg diagram, a .zip download) keeps its source URL instead
    define('HAXCMS_QUARTO_IMPORTABLE_ASSET_REGEX', '/\.(jpg|jpeg|png|gif|webm|webp|mp4|mp3|mov|csv|ppt|pptx|xlsx|doc|xls|docx|pdf|rtf|txt|vtt|xml)$/iD');
    define('HAXCMS_QUARTO_IMAGE_EXTENSION_REGEX', '/\.(jpg|jpeg|png|gif|webp)$/iD');
    define('HAXCMS_QUARTO_MAX_INCLUDE_DEPTH', 3);
    // shown when the source reserves all rights, same text as haxcms-nodejs
    define('HAXCMS_QUARTO_RESERVED_MESSAGE', 'The source states all rights reserved, so it was not imported. Ask the owner for permission, or for a license that allows reuse.');
    // repository files that state a license, best first; read only when the
    // project file does not declare one. The dot is spelled out because
    // JavaScript's does not match \r or U+2028/U+2029.
    define('HAXCMS_QUARTO_LICENSE_FILE_REGEX', '/^(license|licence|copying|unlicense)([-._ ](?:(?!\xE2\x80[\xA8\xA9])[^\n\r])*)?(\.(md|txt|rst))?$/iD');
    define('HAXCMS_QUARTO_MAX_LICENSE_FILE_BYTES', 65536);
    // the output format this import stands in for, used by content-visible and
    // content-hidden divs
    define('HAXCMS_QUARTO_TARGET_FORMAT', 'html');
}

if (!class_exists('HaxcmsQuartoImportError')) {
    /** An intentional import failure: its message is safe to show the client. */
    class HaxcmsQuartoImportError extends \RuntimeException
    {
    }
}

if (!function_exists('haxcmsImportConvertQuartoToSite')) {
    /**
     * Safety valves, mirroring the exported LIMITS in haxcms-nodejs
     * convertQuartoToSite.js: a Quarto book runs to hundreds of pages.
     * $GLOBALS['HAXCMS_QUARTO_LIMITS'] overrides them so tests can exercise
     * each cap without a fixture of that size, the way the Node tests mutate
     * the exported object.
     */
    function haxcmsImportQuartoLimits()
    {
        $defaults = array(
            'maxPages'           => 500,
            'maxFiles'           => 2000,
            'maxIncludes'        => 200,
            'fetchBudgetSeconds' => 900,
            'requestDelayMs'     => 100,
        );
        if (isset($GLOBALS['HAXCMS_QUARTO_LIMITS']) && is_array($GLOBALS['HAXCMS_QUARTO_LIMITS'])) {
            return array_merge($defaults, $GLOBALS['HAXCMS_QUARTO_LIMITS']);
        }
        return $defaults;
    }

    /** Quarto's five callout types onto the stop-note statuses HAX offers. */
    function haxcmsImportQuartoCallouts()
    {
        return array(
            'note'      => array('status' => 'info', 'title' => 'Note'),
            'tip'       => array('status' => 'success', 'title' => 'Tip'),
            'important' => array('status' => 'stop', 'title' => 'Important'),
            'warning'   => array('status' => 'warning', 'title' => 'Warning'),
            'caution'   => array('status' => 'warning', 'title' => 'Caution'),
        );
    }

    /**
     * POST /system/api/v1/site/import/quarto
     * Convert a Quarto project (book or website) into a HAXcms site schema.
     *
     * PHP port of haxcms-nodejs src/systemRoutes/v1/routes/imports/
     * convertQuartoToSite.js (haxtheweb/issues#2189), kept structurally
     * identical: same outline, slugs, file map, elements, status codes and
     * error strings.
     *
     * Expects a JSON body with a `repoUrl` param: a GitHub repository URL, with
     * an optional /tree/<branch>.
     *
     * Quarto declares its outline in _quarto.yml: book.chapters (with parts) and
     * book.appendices for a book, website.sidebar or website.navbar for a site.
     * The YAML is read as data and the project is never rendered or executed:
     * repoUrl is caller-supplied, so running the repository's own code or the
     * Quarto CLI would be arbitrary code execution. A project with no readable
     * outline falls back to the pages on disk, which is what Quarto does when no
     * chapters are listed. Code chunks are imported as code; their outputs are
     * not computed, and the result says so.
     *
     * Returns { status: 200, data: { items, filename, files, site, truncated,
     * unmappedComponents, warnings } }.
     */
    function haxcmsImportConvertQuartoToSite($context)
    {
        $client = new \GuzzleHttp\Client(array('timeout' => 30, 'connect_timeout' => 10));
        haxcmsImportQuartoRun($context, $client);
    }

    /**
     * The import, with the HTTP client supplied by the caller so tests can
     * drive the whole route through a Guzzle handler (the seam
     * StageRemoteFileTest established). $origins overrides the GitHub hosts,
     * which lets those tests use an IP literal and keep the SSRF check off DNS.
     */
    function haxcmsImportQuartoRun($context, $client, $origins = null)
    {
        $apiOrigin = isset($origins['api']) ? $origins['api'] : HAXCMS_QUARTO_API_ORIGIN;
        $rawOrigin = isset($origins['raw']) ? $origins['raw'] : HAXCMS_QUARTO_RAW_ORIGIN;
        $body = isset($context->body) && is_array($context->body) ? $context->body : array();
        $repoUrl = isset($body['repoUrl']) && is_string($body['repoUrl']) ? haxcmsImportQuartoTrim($body['repoUrl']) : '';
        if ($repoUrl === '') {
            haxcmsImportQuartoSendError($context, 400, 'missing `repoUrl` param');
            return;
        }
        try {
            $repo = haxcmsImportQuartoParseRepoUrl($repoUrl);
            $source = haxcmsImportQuartoResolveRepository($client, $repo, $apiOrigin, $rawOrigin);
            // the owner kept the work to themselves: stop before reading any page
            if (HAXCMSLicenses::isAllRightsReserved($source['license'])) {
                throw haxcmsImportQuartoError(403, HAXCMS_QUARTO_RESERVED_MESSAGE);
            }
            $imported = haxcmsImportQuartoImportSite($client, $source);
        }
        catch (\Throwable $error) {
            $status = 400;
            if ($error instanceof HaxcmsQuartoImportError && in_array((int) $error->getCode(), array(403, 422), true)) {
                $status = (int) $error->getCode();
            }
            haxcmsImportQuartoSendError(
                $context,
                $status,
                haxcmsImportQuartoClientSafeDetail($error, 'Unable to import the Quarto project')
            );
            return;
        }
        $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/system/api';
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 200, 'data' => array(
                'items'              => $imported['items'],
                'filename'           => $source['title'],
                // an object, as on the Node side, even when no file is mapped
                'files'              => count($imported['files']) > 0 ? $imported['files'] : new \stdClass(),
                'site'               => array('license' => $source['license']),
                'truncated'          => $imported['truncated'],
                'unmappedComponents' => $imported['unmappedComponents'],
                'warnings'           => haxcmsImportQuartoLicenseWarnings($source),
            )),
            array('statusCode' => 200, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
    }

    function haxcmsImportQuartoSendError($context, $status, $message)
    {
        $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/system/api';
        SiteRouteUtils::sendFormattedResponse(
            array('status' => $status, 'data' => array(
                'error'    => $message,
                'items'    => array(),
                'filename' => null,
                'files'    => new \stdClass(),
            )),
            array('statusCode' => $status, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
    }

    function haxcmsImportQuartoError($status, $message)
    {
        return new HaxcmsQuartoImportError($message, $status);
    }

    /**
     * Client-safe text for an exception, like clientSafeErrorDetail in Node:
     * intentional import errors pass through, anything else is cut to its first
     * line, has absolute paths replaced with [path], and is truncated.
     */
    function haxcmsImportQuartoClientSafeDetail($error, $fallback = 'Unexpected error')
    {
        if ($error instanceof HaxcmsQuartoImportError && $error->getMessage() !== '') {
            return $error->getMessage();
        }
        $message = $error instanceof \Throwable ? (string) $error->getMessage() : (is_string($error) ? $error : '');
        $lines = preg_split('/\r?\n/', $message);
        $message = haxcmsImportQuartoTrim($lines[0]);
        if ($message === '') {
            return $fallback;
        }
        $message = preg_replace('#file://[^\s\'"(),]+#i', '[path]', $message);
        $message = preg_replace('#(^|[\s\'"(=,])/(?!/)[^\s\'"(),]+#', '$1[path]', $message);
        $message = preg_replace('#(^|[\s\'"(=,])[A-Za-z]:\\\\[^\s\'"(),]*#', '$1[path]', $message);
        if (mb_strlen($message, 'UTF-8') > 200) {
            $message = mb_substr($message, 0, 200, 'UTF-8') . "\u{2026}";
        }
        return $message;
    }

    // --- small JavaScript-shaped helpers --------------------------------------

    /** String.prototype.trim: Unicode white space and the byte order mark. */
    function haxcmsImportQuartoTrim($value)
    {
        $trimmed = preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u', '', (string) $value);
        return $trimmed === null ? trim((string) $value) : $trimmed;
    }

    /** JavaScript truthiness for the few values YAML can hand back. */
    function haxcmsImportQuartoTruthy($value)
    {
        return !($value === null || $value === false || $value === 0 || $value === 0.0 || $value === '');
    }

    function haxcmsImportQuartoIsObject($value)
    {
        return $value instanceof \stdClass;
    }

    function haxcmsImportQuartoObjectOf($value)
    {
        return $value instanceof \stdClass ? $value : new \stdClass();
    }

    /** A property of a parsed YAML/JSON map, or null. */
    function haxcmsImportQuartoGet($object, $key)
    {
        if ($object instanceof \stdClass && property_exists($object, $key)) {
            return $object->{$key};
        }
        return null;
    }

    /** A non-empty trimmed string, or "" for anything else. */
    function haxcmsImportQuartoStringOf($value)
    {
        return is_string($value) ? haxcmsImportQuartoTrim($value) : '';
    }

    /** Fetched text as valid UTF-8 without a byte order mark, as fetch().text() gives. */
    function haxcmsImportQuartoNormalizeText($text)
    {
        $text = (string) $text;
        if (!mb_check_encoding($text, 'UTF-8')) {
            $previous = mb_substitute_character();
            mb_substitute_character(0xFFFD);
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            mb_substitute_character($previous);
        }
        if (strncmp($text, "\xEF\xBB\xBF", 3) === 0) {
            $text = substr($text, 3);
        }
        return $text;
    }

    /** preg_replace_callback that keeps the subject when the engine gives up. */
    function haxcmsImportQuartoReplaceCallback($pattern, $callback, $subject, $flags = 0)
    {
        $result = preg_replace_callback($pattern, $callback, $subject, -1, $count, $flags);
        return $result === null ? $subject : $result;
    }

    function haxcmsImportQuartoReplace($pattern, $replacement, $subject)
    {
        $result = preg_replace($pattern, $replacement, $subject);
        return $result === null ? $subject : $result;
    }

    /**
     * encodeURI: escape what a URL cannot carry literally while leaving the
     * reserved characters alone, so raw.githubusercontent.com paths match what
     * the Node importer produces.
     */
    function haxcmsImportQuartoEncodeUri($value)
    {
        $encoded = rawurlencode((string) $value);
        return str_replace(
            array('%3B', '%2C', '%2F', '%3F', '%3A', '%40', '%26', '%3D', '%2B', '%24', '%21', '%2A', '%27', '%28', '%29', '%23'),
            array(';', ',', '/', '?', ':', '@', '&', '=', '+', '$', '!', '*', "'", '(', ')', '#'),
            $encoded
        );
    }

    /** encodeURIComponent: like encodeURI but also escaping the reserved set. */
    function haxcmsImportQuartoEncodeUriComponent($value)
    {
        $encoded = rawurlencode((string) $value);
        return str_replace(
            array('%21', '%2A', '%27', '%28', '%29'),
            array('!', '*', "'", '(', ')'),
            $encoded
        );
    }

    /**
     * String.prototype.localeCompare for the strings this importer sorts (file
     * paths): punctuation, then digits, then letters, case compared last with
     * lower case first, which is how ICU orders ASCII. Characters outside ASCII
     * follow by code point.
     */
    function haxcmsImportQuartoCollate($a, $b)
    {
        static $order = null;
        if ($order === null) {
            $symbols = " _-,;:!?.'\"()[]{}@*/\\&#%`^+<=>|~$0123456789abcdefghijklmnopqrstuvwxyz";
            $order = array();
            for ($i = 0; $i < strlen($symbols); $i++) {
                $order[$symbols[$i]] = $i;
            }
        }
        $primary = function ($text) use ($order) {
            $keys = array();
            $characters = preg_split('//u', (string) $text, -1, PREG_SPLIT_NO_EMPTY);
            if ($characters === false) {
                $characters = str_split((string) $text);
            }
            foreach ($characters as $character) {
                $lower = mb_strtolower($character, 'UTF-8');
                $keys[] = isset($order[$lower]) ? $order[$lower] : 1000 + (mb_ord($lower, 'UTF-8') ?: 0);
            }
            return $keys;
        };
        $keysA = $primary($a);
        $keysB = $primary($b);
        $shared = min(count($keysA), count($keysB));
        for ($i = 0; $i < $shared; $i++) {
            if ($keysA[$i] !== $keysB[$i]) {
                return $keysA[$i] < $keysB[$i] ? -1 : 1;
            }
        }
        if (count($keysA) !== count($keysB)) {
            return count($keysA) < count($keysB) ? -1 : 1;
        }
        // equal apart from case: lower case sorts before upper case
        $lengthA = strlen($a);
        for ($i = 0; $i < $lengthA && $i < strlen($b); $i++) {
            if ($a[$i] !== $b[$i]) {
                return $a[$i] === strtolower($a[$i]) ? -1 : 1;
            }
        }
        return 0;
    }

    // --- GitHub ---------------------------------------------------------------

    /** owner, repo and optional branch out of a GitHub repository URL. */
    function haxcmsImportQuartoParseRepoUrl($sourceUrl)
    {
        $parsed = @parse_url($sourceUrl);
        if ($parsed === false || !isset($parsed['scheme'])) {
            throw haxcmsImportQuartoError(400, 'Invalid repoUrl: ' . $sourceUrl);
        }
        $host = isset($parsed['host']) ? strtolower($parsed['host']) : '';
        if ($host !== 'github.com') {
            throw haxcmsImportQuartoError(400, 'repoUrl must be a github.com repository URL');
        }
        $path = isset($parsed['path']) ? $parsed['path'] : '';
        $segments = array_values(array_filter(explode('/', $path), function ($piece) {
            return $piece !== '';
        }));
        if (count($segments) < 2) {
            throw haxcmsImportQuartoError(400, 'repoUrl is missing the owner/repo path: ' . $sourceUrl);
        }
        // /tree/<branch> names a branch; deeper path segments are ignored
        // because the config discovery walks the whole tree anyway
        $branch = null;
        if (isset($segments[2]) && $segments[2] === 'tree' && isset($segments[3]) && $segments[3] !== '') {
            $branch = $segments[3];
        }
        return array(
            'owner'  => $segments[0],
            'name'   => preg_replace('/\.git$/iD', '', $segments[1]),
            'branch' => $branch,
        );
    }

    /**
     * The GitHub API 403s every call that arrives without a User-Agent, so the
     * repository and tree reads carry one (same string as the GitBook and
     * VitePress importers in both backends).
     */
    function haxcmsImportQuartoApiHeaders()
    {
        return array(
            'User-Agent' => 'HAXcms-Import/1.0',
            'Accept'     => 'application/vnd.github.v3+json',
        );
    }

    function haxcmsImportQuartoRawHeaders()
    {
        return array('User-Agent' => 'HAXcms-Import/1.0');
    }

    function haxcmsImportQuartoFetchJson($client, $url, $description)
    {
        try {
            $response = SsrfGuard::safeGuzzleRequest($client, 'GET', $url, array(
                'headers'     => haxcmsImportQuartoApiHeaders(),
                'http_errors' => false,
            ));
        }
        catch (\Throwable $e) {
            throw haxcmsImportQuartoError(
                400,
                'Unable to reach ' . $description . ': ' . haxcmsImportQuartoClientSafeDetail($e)
            );
        }
        $status = (int) $response->getStatusCode();
        if ($status < 200 || $status > 299) {
            throw haxcmsImportQuartoError(
                $status === 404 ? 422 : 400,
                'Unable to read ' . $description . ' (HTTP ' . $status . ')'
            );
        }
        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded)) {
            throw haxcmsImportQuartoError(400, 'Unable to parse ' . $description);
        }
        return $decoded;
    }

    /** Fetch text, or null when it cannot be read; page bodies are optional. */
    function haxcmsImportQuartoFetchText($client, $url)
    {
        try {
            $response = SsrfGuard::safeGuzzleRequest($client, 'GET', $url, array(
                'headers'     => haxcmsImportQuartoRawHeaders(),
                'http_errors' => false,
            ));
        }
        catch (\Throwable $e) {
            return null;
        }
        $status = (int) $response->getStatusCode();
        if ($status < 200 || $status > 299) {
            return null;
        }
        return haxcmsImportQuartoNormalizeText((string) $response->getBody());
    }

    /**
     * Resolve the repository: default branch, file tree, _quarto.yml and the
     * site level metadata the project file carries.
     */
    function haxcmsImportQuartoResolveRepository($client, $repo, $apiOrigin, $rawOrigin)
    {
        $repoData = haxcmsImportQuartoFetchJson(
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
        $treeData = haxcmsImportQuartoFetchJson(
            $client,
            $apiOrigin . '/repos/' . $repo['owner'] . '/' . $repo['name'] . '/git/trees/' . haxcmsImportQuartoEncodeUriComponent($branch) . '?recursive=1',
            'the file tree of ' . $repo['owner'] . '/' . $repo['name']
        );
        $tree = isset($treeData['tree']) && is_array($treeData['tree']) ? $treeData['tree'] : array();
        $paths = array();
        foreach ($tree as $entry) {
            if (is_array($entry) && isset($entry['type']) && $entry['type'] === 'blob' && isset($entry['path']) && is_string($entry['path'])) {
                $paths[$entry['path']] = true;
            }
        }
        $configPath = haxcmsImportQuartoFindConfigPath($paths);
        if ($configPath === null) {
            throw haxcmsImportQuartoError(422, 'No _quarto.yml file found in ' . $repo['owner'] . '/' . $repo['name']);
        }
        $root = strpos($configPath, '/') === false ? '' : substr($configPath, 0, strrpos($configPath, '/'));
        $rawBase = $rawOrigin . '/' . $repo['owner'] . '/' . $repo['name'] . '/' . $branch;
        $configSource = haxcmsImportQuartoFetchText($client, $rawBase . '/' . haxcmsImportQuartoEncodeUri($configPath));
        if ($configSource === null) {
            throw haxcmsImportQuartoError(422, 'Unable to read ' . $configPath);
        }
        $config = haxcmsImportQuartoReadConfig($configSource);
        // the project file wins; otherwise read what the repository itself says
        $licenseInfo = $config['licenseInfo'];
        $licenseSource = $licenseInfo['label'] !== '' ? 'config' : '';
        if ($licenseInfo['code'] === null && $licenseInfo['label'] === '') {
            $licenseFile = haxcmsImportQuartoFindLicenseFile($paths, $root);
            if ($licenseFile !== null) {
                $licenseText = haxcmsImportQuartoFetchText($client, $rawBase . '/' . haxcmsImportQuartoEncodeUri($licenseFile));
                if ($licenseText !== null) {
                    $fromFile = HAXCMSLicenses::identify(HAXCMSLicenses::sliceUtf16($licenseText, HAXCMS_QUARTO_MAX_LICENSE_FILE_BYTES));
                    $licenseInfo = $fromFile;
                    $licenseSource = 'license-file';
                    if ($fromFile['label'] === '') {
                        // a LICENSE file we do not recognize is still a statement
                        $licenseInfo = array('code' => null, 'version' => '', 'label' => $licenseFile);
                    }
                }
            }
        }
        $accessed = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
        return array(
            'owner'    => $repo['owner'],
            'name'     => $repo['name'],
            'branch'   => $branch,
            'paths'    => $paths,
            'root'     => $root,
            'rawBase'  => $rawBase,
            'config'   => $config,
            'title'    => $config['title'] !== '' ? $config['title'] : $repo['name'],
            'license'  => $licenseInfo['code'],
            'licenseInfo'   => $licenseInfo,
            'licenseSource' => $licenseSource,
            'accessed' => $accessed,
        );
    }

    /** Every repository path as a string (PHP turns numeric keys into ints). */
    function haxcmsImportQuartoPathList($paths)
    {
        return array_map('strval', array_keys($paths));
    }

    /**
     * The project file that describes the site: the shallowest _quarto.yml. A
     * repository can carry more than one (an example project, a fixture), and the
     * one at the top is the project Quarto renders.
     */
    function haxcmsImportQuartoFindConfigPath($paths)
    {
        $candidates = array();
        foreach (haxcmsImportQuartoPathList($paths) as $candidate) {
            if (preg_match(HAXCMS_QUARTO_CONFIG_PATH_REGEX, $candidate)) {
                $candidates[] = $candidate;
            }
        }
        if (count($candidates) === 0) {
            return null;
        }
        usort($candidates, function ($a, $b) {
            $depth = count(explode('/', $a)) - count(explode('/', $b));
            if ($depth !== 0) {
                return $depth;
            }
            return haxcmsImportQuartoCollate($a, $b);
        });
        return $candidates[0];
    }

    // --- _quarto.yml ----------------------------------------------------------

    /**
     * Parse untrusted YAML as data, the way loadYamlSafely does in Node: the
     * size cap and anchor/alias rejection come first, maps come back as objects
     * (so {} and [] stay distinct) and anything unparseable throws.
     */
    function haxcmsImportQuartoLoadYaml($text)
    {
        if (strlen($text) > SiteRouteUtils::YAML_MAX_INPUT_BYTES) {
            throw new \RuntimeException('YAML input exceeds the maximum size of ' . SiteRouteUtils::YAML_MAX_INPUT_BYTES . ' bytes');
        }
        if (SiteRouteUtils::yamlUsesAnchorsOrAliases($text)) {
            throw new \RuntimeException(SiteRouteUtils::YAML_ANCHOR_ALIAS_ERROR);
        }
        return \Symfony\Component\Yaml\Yaml::parse($text, \Symfony\Component\Yaml\Yaml::PARSE_OBJECT_FOR_MAP);
    }

    /**
     * Read the parts of _quarto.yml that describe the site. The YAML is parsed
     * with the size cap and without aliases; a file that cannot be parsed is the
     * same as an empty one, and the page tree is used instead.
     */
    function haxcmsImportQuartoReadConfig($text)
    {
        $data = new \stdClass();
        try {
            $parsed = haxcmsImportQuartoLoadYaml($text);
            if ($parsed instanceof \stdClass) {
                $data = $parsed;
            }
        }
        catch (\Throwable $e) {
            $data = new \stdClass();
        }
        $project = haxcmsImportQuartoObjectOf(haxcmsImportQuartoGet($data, 'project'));
        $book = haxcmsImportQuartoObjectOf(haxcmsImportQuartoGet($data, 'book'));
        $website = haxcmsImportQuartoObjectOf(haxcmsImportQuartoGet($data, 'website'));
        $render = null;
        $projectRender = haxcmsImportQuartoGet($project, 'render');
        if (is_array($projectRender)) {
            $render = array_values(array_filter($projectRender, 'is_string'));
        }
        $chapters = haxcmsImportQuartoGet($book, 'chapters');
        $appendices = haxcmsImportQuartoGet($book, 'appendices');
        $navbar = haxcmsImportQuartoGet($website, 'navbar');
        $author = haxcmsImportQuartoAuthorText(haxcmsImportQuartoGet($book, 'author'));
        if ($author === '') {
            $author = haxcmsImportQuartoAuthorText(haxcmsImportQuartoGet($data, 'author'));
        }
        $title = haxcmsImportQuartoStringOf(haxcmsImportQuartoGet($book, 'title'));
        if ($title === '') {
            $title = haxcmsImportQuartoStringOf(haxcmsImportQuartoGet($website, 'title'));
        }
        $siteUrl = haxcmsImportQuartoStringOf(haxcmsImportQuartoGet($book, 'site-url'));
        if ($siteUrl === '') {
            $siteUrl = haxcmsImportQuartoStringOf(haxcmsImportQuartoGet($website, 'site-url'));
        }
        return array(
            'type'       => mb_strtolower(haxcmsImportQuartoStringOf(haxcmsImportQuartoGet($project, 'type')), 'UTF-8'),
            'title'      => $title,
            'author'     => $author,
            'siteUrl'    => $siteUrl,
            'licenseInfo' => haxcmsImportQuartoLicenseStatement(haxcmsImportQuartoGet($data, 'license')),
            'outputDir'  => haxcmsImportQuartoReplace('#^\.?/|/$#D', '', haxcmsImportQuartoStringOf(haxcmsImportQuartoGet($project, 'output-dir'))),
            'render'     => $render,
            'chapters'   => is_array($chapters) ? $chapters : null,
            'appendices' => is_array($appendices) ? $appendices : null,
            'sidebar'    => haxcmsImportQuartoSidebarContents(haxcmsImportQuartoGet($website, 'sidebar')),
            'navbar'     => $navbar instanceof \stdClass ? $navbar : null,
        );
    }

    /** Quarto allows a string, a list of strings, or maps with a name. */
    function haxcmsImportQuartoAuthorText($value)
    {
        $names = array();
        $collect = function ($entry) use (&$names) {
            if (is_string($entry)) {
                $names[] = haxcmsImportQuartoTrim($entry);
            }
            elseif ($entry instanceof \stdClass) {
                $name = haxcmsImportQuartoGet($entry, 'name');
                if (is_string($name)) {
                    $names[] = haxcmsImportQuartoTrim($name);
                }
                elseif ($name instanceof \stdClass && is_string(haxcmsImportQuartoGet($name, 'literal'))) {
                    $names[] = haxcmsImportQuartoTrim($name->literal);
                }
                elseif ($name instanceof \stdClass && (haxcmsImportQuartoTruthy(haxcmsImportQuartoGet($name, 'given')) || haxcmsImportQuartoTruthy(haxcmsImportQuartoGet($name, 'family')))) {
                    $names[] = haxcmsImportQuartoTrim(
                        haxcmsImportQuartoStringOf(haxcmsImportQuartoGet($name, 'given')) . ' ' . haxcmsImportQuartoStringOf(haxcmsImportQuartoGet($name, 'family'))
                    );
                }
            }
        };
        if (is_array($value)) {
            foreach ($value as $entry) {
                $collect($entry);
            }
        }
        else {
            $collect($value);
        }
        return implode(', ', array_filter($names, function ($name) {
            return $name !== '';
        }));
    }

    /** website.sidebar is one map or a list of them; the first with contents wins. */
    function haxcmsImportQuartoSidebarContents($sidebar)
    {
        $candidates = is_array($sidebar) ? $sidebar : array($sidebar);
        foreach ($candidates as $candidate) {
            if ($candidate instanceof \stdClass && is_array(haxcmsImportQuartoGet($candidate, 'contents'))) {
                return $candidate->contents;
            }
        }
        return null;
    }

    /**
     * The license a `license:` setting states. Quarto allows a string, a list, or
     * a map with `text` and `url`; the first entry we can read wins, and one we
     * cannot (an unsupported Creative Commons version, a custom text) is kept as
     * a label so the person is told instead of the license silently vanishing.
     */
    function haxcmsImportQuartoLicenseStatement($value)
    {
        $candidates = array();
        $collect = function ($entry) use (&$candidates, &$collect) {
            if (is_string($entry)) {
                $candidates[] = $entry;
            }
            elseif (is_array($entry)) {
                foreach ($entry as $inner) {
                    $collect($inner);
                }
            }
            elseif ($entry instanceof \stdClass) {
                $collect(haxcmsImportQuartoGet($entry, 'url'));
                $collect(haxcmsImportQuartoGet($entry, 'text'));
                $collect(haxcmsImportQuartoGet($entry, 'type'));
            }
        };
        $collect($value);
        $fallback = array('code' => null, 'version' => '', 'label' => '');
        foreach ($candidates as $candidate) {
            $info = HAXCMSLicenses::identify($candidate);
            if ($info['code']) {
                return $info;
            }
            if ($fallback['label'] === '' && $info['label'] !== '') {
                $fallback = $info;
            }
        }
        return $fallback;
    }

    /** The license a page's front matter states, as a code, or null. */
    function haxcmsImportQuartoLicenseCode($value)
    {
        $info = haxcmsImportQuartoLicenseStatement($value);
        return $info['code'];
    }

    /**
     * The repository file that states a license: at the project root first, then
     * the repository root. LICENSE, LICENSE.md and LICENSE.txt come first.
     */
    function haxcmsImportQuartoFindLicenseFile($paths, $root)
    {
        $directories = $root === '' ? array('') : array($root, '');
        foreach ($directories as $directory) {
            $prefix = $directory === '' ? '' : $directory . '/';
            $names = array();
            foreach (haxcmsImportQuartoPathList($paths) as $path) {
                if ($prefix !== '' && strpos($path, $prefix) !== 0) {
                    continue;
                }
                $rest = substr($path, strlen($prefix));
                if (strpos($rest, '/') === false && preg_match(HAXCMS_QUARTO_LICENSE_FILE_REGEX, $rest)) {
                    $names[] = $path;
                }
            }
            // shortest first, then locale order; the length is JavaScript's
            usort($names, function ($a, $b) {
                $lengths = HAXCMSLicenses::utf16Length($a) - HAXCMSLicenses::utf16Length($b);
                return $lengths !== 0 ? $lengths : haxcmsImportQuartoCollate($a, $b);
            });
            if (count($names) > 0) {
                return $names[0];
            }
        }
        return null;
    }

    /**
     * What the person importing should know about the source's license. These
     * are notices, not a ruling: whether an import is permitted is theirs to
     * decide, and the codes let a client show the right words. Codes, order and
     * messages match haxcms-nodejs licenseWarnings.
     */
    function haxcmsImportQuartoLicenseWarnings($source)
    {
        $warnings = array();
        $info = isset($source['licenseInfo']) ? $source['licenseInfo'] : array('code' => null, 'label' => '');
        $add = function ($code, $message) use (&$warnings) {
            $warnings[] = array('code' => $code, 'message' => $message);
        };
        if ($info['code']) {
            $terms = HAXCMSLicenses::terms($info['code']);
            if (in_array('no-derivatives', $terms, true)) {
                $add(
                    'license:no-derivatives',
                    'The source is licensed No Derivatives. Converting it into a new site may count as an adaptation; check the license or ask the author before publishing.'
                );
            }
            if (in_array('non-commercial', $terms, true)) {
                $add(
                    'license:non-commercial',
                    'The source is licensed Non-commercial. The imported site must not be used commercially.'
                );
            }
            if (in_array('share-alike', $terms, true)) {
                $add(
                    'license:share-alike',
                    'The source is licensed Share Alike. Keep the same license on the imported site.'
                );
            }
            if (isset($source['licenseSource']) && $source['licenseSource'] === 'license-file') {
                $add(
                    'license:from-file',
                    'The license (' . $info['code'] . ") was read from the repository's license file because the project file declares none. Confirm it covers the written content and not only the code."
                );
            }
        }
        elseif ($info['label'] !== '') {
            $add(
                'license:unrecognized',
                'The source states a license HAX cannot apply (' . HAXCMSLicenses::sliceUtf16($info['label'], 80) . '). No license was set on the imported site.'
            );
        }
        else {
            $add(
                'license:not-found',
                'No license was declared in the project file or found in a repository license file. Without one, the work is all rights reserved by default; get permission before publishing the import.'
            );
        }
        return $warnings;
    }

    // --- paths and titles -----------------------------------------------------

    /** Slug for one page title, trimmed the way convertOpenstaxToSite trims. */
    function haxcmsImportQuartoTitleSlug($title)
    {
        $cleaned = haxcmsImportCleanTitle($title);
        $trimmed = trim($cleaned, '-');
        return $trimmed === '' ? $cleaned : $trimmed;
    }

    function haxcmsImportQuartoTitleFromPath($path)
    {
        $pieces = explode('/', $path);
        $name = haxcmsImportQuartoReplace(HAXCMS_QUARTO_PAGE_EXTENSION_REGEX, '', array_pop($pieces));
        $words = haxcmsImportQuartoTrim(haxcmsImportQuartoReplace('/[-_]+/', ' ', $name));
        if ($words === '' || mb_strtolower($words, 'UTF-8') === 'index') {
            return 'Home';
        }
        return mb_strtoupper(mb_substr($words, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($words, 1, null, 'UTF-8');
    }

    function haxcmsImportQuartoNormalizePath($path)
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

    function haxcmsImportQuartoRootPrefix($source)
    {
        return $source['root'] === '' ? '' : $source['root'] . '/';
    }

    function haxcmsImportQuartoDirectory($path)
    {
        $pieces = explode('/', $path);
        array_pop($pieces);
        return implode('/', $pieces);
    }

    function haxcmsImportQuartoIsExternal($reference)
    {
        return preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', (string) $reference) === 1;
    }

    /** A glob over project-relative paths: * stays in a folder, ** crosses them. */
    function haxcmsImportQuartoGlobToRegExp($pattern)
    {
        $source = '';
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            $character = $pattern[$i];
            if ($character === '*' && $i + 1 < $length && $pattern[$i + 1] === '*') {
                $source .= '.*';
                $i++;
            }
            elseif ($character === '*') {
                $source .= '[^/]*';
            }
            elseif ($character === '?') {
                $source .= '[^/]';
            }
            elseif (strpos('.+^${}()|[]\\~', $character) !== false) {
                $source .= '\\' . $character;
            }
            else {
                $source .= $character;
            }
        }
        return '~^' . $source . '$~uDs';
    }

    function haxcmsImportQuartoGlobMatches($glob, $relative)
    {
        $pattern = haxcmsImportQuartoReplace('#^\.?/#', '', $glob);
        return preg_match(haxcmsImportQuartoGlobToRegExp($pattern), $relative) === 1;
    }

    /**
     * Whether Quarto would skip a project-relative path when looking for pages:
     * anything under a folder or file starting with . or _, README and agent
     * instruction files, dependency and cache folders, and the output directory.
     */
    function haxcmsImportQuartoIsIgnoredPath($relative, $source)
    {
        $segments = explode('/', $relative);
        $last = count($segments) - 1;
        foreach ($segments as $i => $segment) {
            $first = substr($segment, 0, 1);
            if ($first === '.' || $first === '_') {
                return true;
            }
            if ($i < $last
                && (preg_match('/^(node_modules|renv|venv|site-packages)$/D', $segment)
                    || preg_match('/_(files|cache)$/D', $segment))) {
                return true;
            }
        }
        $name = $segments[$last];
        if (preg_match('/^readme\.(md|qmd|rmd|ipynb)$/iD', $name) || preg_match('/^(agents|claude)\.md$/iD', $name)) {
            return true;
        }
        $outputDir = $source['config']['outputDir'];
        return $outputDir !== ''
            && ($relative === $outputDir || strpos($relative, $outputDir . '/') === 0);
    }

    /** project.render narrows the pages; a ! entry removes matches. */
    function haxcmsImportQuartoIsRenderable($relative, $source)
    {
        $render = $source['config']['render'];
        if ($render === null || count($render) === 0) {
            return true;
        }
        $included = true;
        foreach ($render as $entry) {
            if (substr($entry, 0, 1) !== '!') {
                $included = false;
                break;
            }
        }
        foreach ($render as $entry) {
            $negated = substr($entry, 0, 1) === '!';
            $pattern = $negated ? substr($entry, 1) : $entry;
            if (haxcmsImportQuartoGlobMatches($pattern, $relative)) {
                $included = !$negated;
            }
        }
        return $included;
    }

    /** Every page Quarto would render under the project root, in tree order. */
    function haxcmsImportQuartoListPages($source)
    {
        $prefix = haxcmsImportQuartoRootPrefix($source);
        $pages = array();
        foreach (haxcmsImportQuartoPathList($source['paths']) as $candidate) {
            if (strpos($candidate, $prefix) !== 0) {
                continue;
            }
            $relative = substr($candidate, strlen($prefix));
            if (preg_match(HAXCMS_QUARTO_PAGE_EXTENSION_REGEX, $relative)
                && !haxcmsImportQuartoIsIgnoredPath($relative, $source)
                && haxcmsImportQuartoIsRenderable($relative, $source)) {
                $pages[] = $relative;
            }
        }
        usort($pages, function ($a, $b) {
            $indexA = preg_match('#(^|/)index\.[a-z]+$#iD', $a) ? 0 : 1;
            $indexB = preg_match('#(^|/)index\.[a-z]+$#iD', $b) ? 0 : 1;
            $depth = count(explode('/', $a)) - count(explode('/', $b));
            if ($depth !== 0) {
                return $depth;
            }
            if ($indexA !== $indexB) {
                return $indexA - $indexB;
            }
            return haxcmsImportQuartoCollate($a, $b);
        });
        return $pages;
    }

    /**
     * Repository path of a project-relative page reference, or null when the
     * repository does not carry it. References may be written root-relative.
     */
    function haxcmsImportQuartoResolvePagePath($reference, $source)
    {
        $clean = haxcmsImportQuartoTrim(explode('?', explode('#', (string) $reference)[0])[0]);
        if ($clean === '' || !preg_match(HAXCMS_QUARTO_PAGE_EXTENSION_REGEX, $clean)) {
            return null;
        }
        $repoPath = haxcmsImportQuartoNormalizePath(haxcmsImportQuartoRootPrefix($source) . $clean);
        return isset($source['paths'][$repoPath]) ? $repoPath : null;
    }

    // --- the outline ------------------------------------------------------------

    /**
     * One outline entry, whichever of the shapes Quarto writes it in:
     *   - a string path                      (chapters, contents)
     *   - { href, text }                      (chapters, contents, navbar)
     *   - { part: file | title, chapters }    (book parts)
     *   - { section: title, href?, contents } (sidebar sections)
     *   - { text, menu }                      (navbar menus)
     *   - { auto: glob | [globs] | true }     (generated sidebar entries)
     * target is the page reference, label an explicit title, children nested
     * entries and auto the generated-entry request.
     */
    function haxcmsImportQuartoDescribeEntry($entry)
    {
        if (is_string($entry)) {
            return array('target' => haxcmsImportQuartoTrim($entry), 'label' => '', 'children' => null, 'auto' => null);
        }
        if (!($entry instanceof \stdClass)) {
            return null;
        }
        $target = haxcmsImportQuartoStringOf(haxcmsImportQuartoGet($entry, 'href'));
        if ($target === '') {
            $target = haxcmsImportQuartoStringOf(haxcmsImportQuartoGet($entry, 'file'));
        }
        $label = haxcmsImportQuartoStringOf(haxcmsImportQuartoGet($entry, 'text'));
        $children = null;
        $part = haxcmsImportQuartoGet($entry, 'part');
        $section = haxcmsImportQuartoGet($entry, 'section');
        if (is_string($part)) {
            // a part is either a page that introduces its chapters or just a title
            $trimmedPart = haxcmsImportQuartoTrim($part);
            if (preg_match(HAXCMS_QUARTO_PAGE_EXTENSION_REGEX, $trimmedPart)) {
                if ($target === '') {
                    $target = $trimmedPart;
                }
            }
            elseif ($label === '') {
                $label = $trimmedPart;
            }
            $chapters = haxcmsImportQuartoGet($entry, 'chapters');
            $children = is_array($chapters) ? $chapters : array();
        }
        elseif (is_string($section)) {
            $trimmedSection = haxcmsImportQuartoTrim($section);
            if ($trimmedSection !== '') {
                $label = $trimmedSection;
            }
            $contents = haxcmsImportQuartoGet($entry, 'contents');
            $children = is_array($contents) ? $contents : array();
        }
        elseif (is_array(haxcmsImportQuartoGet($entry, 'menu'))) {
            $children = $entry->menu;
        }
        elseif (is_array(haxcmsImportQuartoGet($entry, 'contents'))) {
            $children = $entry->contents;
        }
        $auto = null;
        $autoValue = haxcmsImportQuartoGet($entry, 'auto');
        if ($autoValue === true) {
            $auto = array();
        }
        elseif (is_string($autoValue)) {
            $auto = array($autoValue);
        }
        elseif (is_array($autoValue)) {
            $auto = array_values(array_filter($autoValue, 'is_string'));
        }
        return array('target' => $target, 'label' => $label, 'children' => $children, 'auto' => $auto);
    }

    /**
     * Walk entries into flat, ordered nodes that point at their parent by index.
     * A group carrying a page and children becomes a page with children; a group
     * with only children becomes a landing page, the way convertOpenstaxToSite
     * treats chapter headings. A page already placed is not placed twice.
     * Returns the next order at this depth.
     */
    function haxcmsImportQuartoWalkEntries($entries, $depth, $parentIndex, &$builder, $startOrder)
    {
        $order = $startOrder ? $startOrder : 0;
        foreach ($entries as $entry) {
            $info = haxcmsImportQuartoDescribeEntry($entry);
            if ($info === null) {
                continue;
            }
            // separators and external links are navigation, not pages
            if (preg_match('/^-{3,}$/D', $info['target']) || preg_match('/^-{3,}$/D', $info['label'])) {
                continue;
            }
            if ($info['auto'] !== null) {
                $order = haxcmsImportQuartoAddGlobNodes($info['auto'], $depth, $parentIndex, $builder, $order);
                continue;
            }
            if (strpos($info['target'], '*') !== false) {
                $order = haxcmsImportQuartoAddGlobNodes(array($info['target']), $depth, $parentIndex, $builder, $order);
                continue;
            }
            $external = $info['target'] !== '' && haxcmsImportQuartoIsExternal($info['target']);
            if ($external && $info['children'] === null) {
                continue;
            }
            $path = $external ? null : haxcmsImportQuartoResolvePagePath($info['target'], $builder['source']);
            if ($path !== null && isset($builder['used'][$path])) {
                $path = null;
            }
            if ($path === null && $info['label'] === '' && $info['children'] === null) {
                // a reference to a page the repository does not carry, and
                // nothing to show for it
                continue;
            }
            $nodeIndex = haxcmsImportQuartoAddNode($builder, $path, $info['label'], $depth, $parentIndex, $order);
            $order++;
            if ($info['children'] !== null) {
                haxcmsImportQuartoWalkEntries($info['children'], $depth + 1, $nodeIndex, $builder, 0);
            }
        }
        return $order;
    }

    function haxcmsImportQuartoAddNode(&$builder, $path, $label, $depth, $parentIndex, $order)
    {
        $relative = $path === null ? null : substr($path, strlen(haxcmsImportQuartoRootPrefix($builder['source'])));
        if ($label !== '') {
            $title = $label;
        }
        elseif ($relative !== null && $relative !== '') {
            $title = haxcmsImportQuartoTitleFromPath($relative);
        }
        else {
            $title = 'Untitled';
        }
        $builder['nodes'][] = array(
            'title'       => $title,
            'explicit'    => $label !== '',
            'path'        => $path,
            'relative'    => $relative,
            'depth'       => $depth,
            'order'       => $order,
            'parentIndex' => $parentIndex,
        );
        if ($path !== null) {
            $builder['used'][$path] = true;
        }
        return count($builder['nodes']) - 1;
    }

    /** Glob entries ("chapters/*.qmd", auto: ...) into the pages they match. */
    function haxcmsImportQuartoAddGlobNodes($globs, $depth, $parentIndex, &$builder, $startOrder)
    {
        $order = $startOrder;
        $pages = haxcmsImportQuartoListPages($builder['source']);
        $patterns = count($globs) === 0 ? null : $globs;
        foreach ($pages as $relative) {
            if ($patterns !== null) {
                $matched = false;
                foreach ($patterns as $glob) {
                    if (haxcmsImportQuartoGlobMatches($glob, $relative)) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    continue;
                }
            }
            $path = haxcmsImportQuartoRootPrefix($builder['source']) . $relative;
            if (isset($builder['used'][$path])) {
                continue;
            }
            haxcmsImportQuartoAddNode($builder, $path, '', $depth, $parentIndex, $order);
            $order++;
        }
        return $order;
    }

    function haxcmsImportQuartoCreateBuilder($source)
    {
        return array('source' => $source, 'nodes' => array(), 'used' => array());
    }

    /** book.chapters (parts included) followed by book.appendices. */
    function haxcmsImportQuartoOutlineFromBook($source)
    {
        $builder = haxcmsImportQuartoCreateBuilder($source);
        $order = 0;
        if ($source['config']['chapters'] !== null) {
            $order = haxcmsImportQuartoWalkEntries($source['config']['chapters'], 0, null, $builder, $order);
        }
        if ($source['config']['appendices'] !== null) {
            haxcmsImportQuartoWalkEntries($source['config']['appendices'], 0, null, $builder, $order);
        }
        return $builder['nodes'];
    }

    function haxcmsImportQuartoOutlineFromSidebar($source)
    {
        $builder = haxcmsImportQuartoCreateBuilder($source);
        haxcmsImportQuartoWalkEntries($source['config']['sidebar'], 0, null, $builder, 0);
        return $builder['nodes'];
    }

    /** navbar.left then navbar.right; a menu becomes a page with children. */
    function haxcmsImportQuartoOutlineFromNavbar($source)
    {
        $builder = haxcmsImportQuartoCreateBuilder($source);
        $navbar = $source['config']['navbar'];
        $order = 0;
        foreach (array('left', 'right') as $side) {
            $entries = haxcmsImportQuartoGet($navbar, $side);
            if (is_array($entries)) {
                $order = haxcmsImportQuartoWalkEntries($entries, 0, null, $builder, $order);
            }
        }
        return $builder['nodes'];
    }

    /**
     * Every page in the project, in tree order, when no outline can be read.
     * Mirrors Quarto's own behavior of rendering whatever is on disk, index first.
     */
    function haxcmsImportQuartoOutlineFromTree($source)
    {
        $builder = haxcmsImportQuartoCreateBuilder($source);
        haxcmsImportQuartoAddGlobNodes(array(), 0, null, $builder, 0);
        return $builder['nodes'];
    }

    function haxcmsImportQuartoBuildOutline($source)
    {
        $config = $source['config'];
        $nodes = array();
        if ($config['chapters'] !== null || $config['appendices'] !== null) {
            $nodes = haxcmsImportQuartoOutlineFromBook($source);
        }
        elseif ($config['sidebar'] !== null) {
            $nodes = haxcmsImportQuartoOutlineFromSidebar($source);
        }
        elseif ($config['navbar'] !== null) {
            $nodes = haxcmsImportQuartoOutlineFromNavbar($source);
        }
        if (count($nodes) === 0) {
            $nodes = haxcmsImportQuartoOutlineFromTree($source);
        }
        return $nodes;
    }

    function haxcmsImportQuartoBudgetExhausted($run)
    {
        $limits = haxcmsImportQuartoLimits();
        return (microtime(true) - $run['startedAt']) > $limits['fetchBudgetSeconds'];
    }

    // --- the import -------------------------------------------------------------

    /** Build the items, page bodies and file map for one repository. */
    function haxcmsImportQuartoImportSite($client, $source)
    {
        $outline = haxcmsImportQuartoBuildOutline($source);
        if (count($outline) === 0) {
            throw haxcmsImportQuartoError(422, 'The Quarto project has no pages to import');
        }
        $run = array(
            'client'      => $client,
            'source'      => $source,
            'files'       => array(),
            'fileNames'   => array(),
            'filesByPath' => array(),
            'slugByPath'  => array(),
            'slugs'       => array(),
            'unmapped'    => array(),
            'includes'    => 0,
            'startedAt'   => microtime(true),
            'truncated'   => false,
            // placeholders for the HAX elements the markdown renderer must not
            // see; the nonce keeps page text from forging one
            'tokens'      => array(),
            'nonce'       => bin2hex(random_bytes(6)),
        );
        $limits = haxcmsImportQuartoLimits();
        $items = array();
        // first pass: items and slugs, so page bodies can link to siblings by slug
        foreach ($outline as $index => $node) {
            $parentItem = $node['parentIndex'] === null ? null : $items[$node['parentIndex']];
            $slug = haxcmsImportQuartoUniqueSlug(
                $parentItem !== null
                    ? $parentItem['slug'] . '/' . haxcmsImportQuartoTitleSlug($node['title'])
                    : haxcmsImportQuartoTitleSlug($node['title']),
                $run
            );
            $item = haxcmsImportBuildItem(
                $node['title'],
                $slug,
                $node['order'],
                $parentItem !== null ? $parentItem['id'] : null,
                $node['depth'],
                ''
            );
            $item['metadata'] = array(
                'sourceType' => 'quarto',
                'quarto'     => array(
                    'repo'      => $source['owner'] . '/' . $source['name'],
                    'branch'    => $source['branch'],
                    'path'      => $node['path'],
                    'license'   => $source['license'],
                    'author'    => $source['config']['author'],
                    'workTitle' => $source['config']['title'],
                    'accessed'  => $source['accessed'],
                ),
            );
            $sourceUrl = haxcmsImportQuartoPageSourceUrl($node, $source);
            if ($sourceUrl !== null) {
                $item['metadata']['source'] = $sourceUrl;
            }
            $items[] = $item;
            if ($node['path'] !== null) {
                $run['slugByPath'][$node['path']] = $slug;
            }
        }
        // second pass: page bodies, bounded by the page cap and the time budget
        $fetched = 0;
        foreach ($outline as $index => $node) {
            if ($node['path'] === null) {
                // a group with no page of its own: a landing page for its children
                $items[$index]['contents'] = '<p></p>';
                continue;
            }
            $sourceUrl = isset($items[$index]['metadata']['source']) ? $items[$index]['metadata']['source'] : null;
            if ($fetched >= $limits['maxPages'] || haxcmsImportQuartoBudgetExhausted($run)) {
                $run['truncated'] = true;
                $items[$index]['contents'] = haxcmsImportQuartoSourceFallback($sourceUrl);
                continue;
            }
            if ($fetched > 0) {
                usleep((int) ($limits['requestDelayMs'] * 1000));
            }
            $markdown = haxcmsImportQuartoFetchText(
                $client,
                $source['rawBase'] . '/' . haxcmsImportQuartoEncodeUri($node['path'])
            );
            $fetched++;
            if ($markdown === null) {
                $items[$index]['contents'] = haxcmsImportQuartoSourceFallback($sourceUrl);
                continue;
            }
            $items[$index]['contents'] = haxcmsImportQuartoRenderPage($markdown, $node, $items[$index], $run);
        }
        $unmapped = array_map('strval', array_keys($run['unmapped']));
        sort($unmapped, SORT_STRING);
        return array(
            'items'              => $items,
            'files'              => $run['files'],
            'truncated'          => $run['truncated'],
            'unmappedComponents' => $unmapped,
        );
    }

    /** Two pages with the same title under one parent must not share a slug. */
    function haxcmsImportQuartoUniqueSlug($slug, &$run)
    {
        $candidate = $slug;
        $counter = 1;
        while (isset($run['slugs'][$candidate])) {
            $counter++;
            $candidate = $slug . '-' . $counter;
        }
        $run['slugs'][$candidate] = true;
        return $candidate;
    }

    /** Where the page lives on the published Quarto site, when it says. */
    function haxcmsImportQuartoPageSourceUrl($node, $source)
    {
        if ($node['relative'] === null || $node['relative'] === '' || $source['config']['siteUrl'] === '') {
            return null;
        }
        return preg_replace('#/$#D', '', $source['config']['siteUrl']) . '/'
            . haxcmsImportQuartoReplace(HAXCMS_QUARTO_PAGE_EXTENSION_REGEX, '.html', $node['relative']);
    }

    function haxcmsImportQuartoSourceFallback($sourceUrl)
    {
        if ($sourceUrl === null || $sourceUrl === '') {
            return '<p></p>';
        }
        return '<p>Read this page on <a href="' . SanitizeContent::escapeHTMLAttribute($sourceUrl) . '">the source site</a>.</p>';
    }

    /** Split YAML front matter from the markdown body. */
    function haxcmsImportQuartoSplitFrontmatter($markdown)
    {
        if (!preg_match('/^---\r?\n(.*?)\r?\n---\r?\n?/s', $markdown, $matches)) {
            return array('data' => new \stdClass(), 'body' => $markdown);
        }
        $data = new \stdClass();
        try {
            // Security (SEC-SSRF / YAML alias amplification): size cap and
            // anchor/alias rejection; a rejected front matter falls back to {}
            // like any other unparseable front matter.
            $parsed = haxcmsImportQuartoLoadYaml($matches[1]);
            if ($parsed instanceof \stdClass) {
                $data = $parsed;
            }
        }
        catch (\Throwable $e) {
            $data = new \stdClass();
        }
        return array('data' => $data, 'body' => substr($markdown, strlen($matches[0])));
    }

    /** A MarkdownExtra set up like the markdown-it the Node side renders with. */
    function haxcmsImportQuartoRenderMarkdown($markdown)
    {
        $parser = new \Michelf\MarkdownExtra();
        $parser->code_class_prefix = 'language-';
        $parser->empty_element_suffix = '>';
        return $parser->transform($markdown);
    }

    /** name="value", name=value, #id, .class pairs out of an attribute block. */
    function haxcmsImportQuartoParseAttributes($source)
    {
        $result = array('id' => '', 'classes' => array(), 'values' => array());
        $pattern = '/(?:#([^\s.#}"\']+))|(?:\.([^\s.#}"\']+))|([A-Za-z][A-Za-z0-9_:-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'}]+)))?/u';
        if (!preg_match_all($pattern, (string) $source, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL)) {
            return $result;
        }
        foreach ($matches as $match) {
            if ($match[1] !== null) {
                $result['id'] = $match[1];
            }
            elseif ($match[2] !== null) {
                $result['classes'][] = $match[2];
            }
            elseif ($match[3] !== null) {
                $value = 'true';
                if (isset($match[4]) && $match[4] !== null) {
                    $value = $match[4];
                }
                elseif (isset($match[5]) && $match[5] !== null) {
                    $value = $match[5];
                }
                elseif (isset($match[6]) && $match[6] !== null) {
                    $value = $match[6];
                }
                $result['values'][$match[3]] = $value;
            }
        }
        return $result;
    }

    /**
     * One page into HAX-ready HTML. Quarto syntax that markdown does not know
     * (fenced divs, callouts, executable chunks, shortcodes, attribute blocks,
     * cross-references) is mapped or unwrapped before rendering, then assets,
     * links and images are rewritten against the imported site.
     */
    function haxcmsImportQuartoRenderPage($raw, $node, &$item, &$run)
    {
        $markdown = preg_match(HAXCMS_QUARTO_NOTEBOOK_REGEX, $node['path'])
            ? haxcmsImportQuartoNotebookToMarkdown($raw, $run)
            : $raw;
        $split = haxcmsImportQuartoSplitFrontmatter($markdown);
        $frontTitle = haxcmsImportQuartoStringOf(haxcmsImportQuartoGet($split['data'], 'title'));
        $body = $split['body'];
        // a chapter with no title in its front matter is titled by its first heading
        $headingTitle = '';
        if ($frontTitle === '') {
            $heading = haxcmsImportQuartoTakeLeadingHeading($body);
            if ($heading !== null) {
                $body = $heading['body'];
                $headingTitle = $heading['title'];
            }
        }
        // an outline label is the title the navigation shows, so it wins
        $pageTitle = $frontTitle !== '' ? $frontTitle : $headingTitle;
        if ($pageTitle !== '' && !$node['explicit']) {
            $item['title'] = $pageTitle;
        }
        $pageLicense = haxcmsImportQuartoLicenseCode(haxcmsImportQuartoGet($split['data'], 'license'));
        // a page can reserve its rights even when the project does not
        if (HAXCMSLicenses::isAllRightsReserved($pageLicense)) {
            throw haxcmsImportQuartoError(403, HAXCMS_QUARTO_RESERVED_MESSAGE);
        }
        if (!haxcmsImportQuartoTruthy($pageLicense)) {
            $pageLicense = $run['source']['license'];
        }
        $author = haxcmsImportQuartoAuthorText(haxcmsImportQuartoGet($split['data'], 'author'));
        if ($author === '') {
            $author = $run['source']['config']['author'];
        }
        if (haxcmsImportQuartoTruthy($pageLicense)) {
            $item['metadata']['quarto']['license'] = $pageLicense;
        }
        if ($author !== '') {
            $item['metadata']['quarto']['author'] = $author;
        }
        $body = haxcmsImportQuartoExpandIncludes($body, $node['path'], $run, 0);
        $body = haxcmsImportQuartoTransformCodeBlocks($body, $run);
        $body = haxcmsImportQuartoTransformShortcodes($body, $node, $run);
        $body = haxcmsImportQuartoTransformDivs($body, $run);
        $body = haxcmsImportQuartoMapText($body, function ($text) use (&$run) {
            return haxcmsImportQuartoTransformInline($text, $run);
        });
        $html = haxcmsImportQuartoRenderMarkdown($body);
        // the HAX elements lifted out before rendering go back in
        foreach ($run['tokens'] as $token => $replacement) {
            $html = str_replace($token, $replacement, $html);
        }
        $contents = haxcmsImportQuartoTrim(haxcmsImportQuartoRewriteHtml($html, $node, $run));
        if (haxcmsImportQuartoTruthy($pageLicense)) {
            $contents .= "\n" . haxcmsImportQuartoLicenseElement($pageLicense, $node, $item, $run, $author);
        }
        return $contents === '' ? '<p></p>' : $contents;
    }

    /** The first line as a level one heading: its text, and the body without it. */
    function haxcmsImportQuartoTakeLeadingHeading($body)
    {
        $lines = explode("\n", $body);
        $index = 0;
        while ($index < count($lines) && haxcmsImportQuartoTrim($lines[$index]) === '') {
            $index++;
        }
        if ($index >= count($lines)) {
            return null;
        }
        if (!preg_match('/^#\s+([^\n]+?)\s*$/uD', $lines[$index], $match)) {
            return null;
        }
        $title = haxcmsImportQuartoHeadingText($match[1]);
        if ($title === '') {
            return null;
        }
        array_splice($lines, $index, 1);
        return array('title' => $title, 'body' => implode("\n", $lines));
    }

    /** A heading's text without its pandoc attribute block or emphasis marks. */
    function haxcmsImportQuartoHeadingText($text)
    {
        $text = haxcmsImportQuartoReplace('/\s*\{[^}]*\}\s*$/uD', '', $text);
        $text = haxcmsImportQuartoReplace('/\*\*(.+?)\*\*/u', '$1', $text);
        $text = haxcmsImportQuartoReplace('/\*(.+?)\*/u', '$1', $text);
        $text = haxcmsImportQuartoReplace('/`(.+?)`/u', '$1', $text);
        return haxcmsImportQuartoTrim($text);
    }

    // --- fenced code and text segments ------------------------------------------

    /**
     * Markdown as alternating text and fenced-code segments, so the transforms
     * that rewrite Quarto syntax never touch what sits inside a code block.
     */
    function haxcmsImportQuartoSplitSegments($markdown)
    {
        $segments = array();
        $current = array('code' => false, 'lines' => array());
        $fence = null;
        foreach (explode("\n", $markdown) as $line) {
            if ($fence === null) {
                if (preg_match('/^\s{0,3}(`{3,}|~{3,})([^\r\n]*)$/uD', $line, $open)
                    && !(substr($open[1], 0, 1) === '`' && strpos($open[2], '`') !== false)) {
                    if (count($current['lines']) > 0) {
                        $segments[] = $current;
                    }
                    $current = array('code' => true, 'lines' => array($line));
                    $fence = array('character' => substr($open[1], 0, 1), 'length' => strlen($open[1]));
                    continue;
                }
                $current['lines'][] = $line;
            }
            else {
                $current['lines'][] = $line;
                if (preg_match('/^\s{0,3}(`{3,}|~{3,})\s*$/uD', $line, $close)
                    && substr($close[1], 0, 1) === $fence['character']
                    && strlen($close[1]) >= $fence['length']) {
                    $segments[] = $current;
                    $current = array('code' => false, 'lines' => array());
                    $fence = null;
                }
            }
        }
        if (count($current['lines']) > 0) {
            $segments[] = $current;
        }
        return $segments;
    }

    function haxcmsImportQuartoJoinSegments($segments)
    {
        $lines = array();
        foreach ($segments as $segment) {
            foreach ($segment['lines'] as $line) {
                $lines[] = $line;
            }
        }
        return implode("\n", $lines);
    }

    /** Apply a text transform to the segments that are not code. */
    function haxcmsImportQuartoMapText($markdown, $transform)
    {
        $segments = haxcmsImportQuartoSplitSegments($markdown);
        foreach ($segments as $key => $segment) {
            if (!$segment['code']) {
                $segments[$key]['lines'] = explode("\n", $transform(implode("\n", $segment['lines'])));
            }
        }
        return haxcmsImportQuartoJoinSegments($segments);
    }

    /** Apply a text transform to one line, leaving inline code alone. */
    function haxcmsImportQuartoOutsideInlineCode($line, $transform)
    {
        $pieces = preg_split('/(`+[^`]*`+)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($pieces === false) {
            return $line;
        }
        $out = '';
        foreach ($pieces as $index => $piece) {
            $out .= $index % 2 === 1 ? $piece : $transform($piece);
        }
        return $out;
    }

    /**
     * A placeholder for HTML the markdown renderer would otherwise wrap or
     * mangle (the wrappers around callouts, divs and videos). It is a comment on
     * a line of its own, which the renderer passes through untouched.
     */
    function haxcmsImportQuartoToken(&$run, $html)
    {
        $token = '<!--haxcms-quarto-' . $run['nonce'] . '-' . count($run['tokens']) . '-->';
        $run['tokens'][$token] = $html;
        return $token;
    }

    // --- notebooks, includes, code chunks -----------------------------------------

    /** Array.prototype.join("") over a notebook cell source. */
    function haxcmsImportQuartoCellSource($source)
    {
        if (is_array($source)) {
            $text = '';
            foreach ($source as $piece) {
                if (is_string($piece)) {
                    $text .= $piece;
                }
                elseif (is_bool($piece)) {
                    $text .= $piece ? 'true' : 'false';
                }
                elseif (is_int($piece) || is_float($piece)) {
                    $text .= (string) $piece;
                }
                elseif (is_array($piece) || is_object($piece)) {
                    $text .= '[object Object]';
                }
            }
            return $text;
        }
        return is_string($source) ? $source : '';
    }

    /**
     * A Jupyter notebook as the markdown Quarto would render: markdown cells as
     * they are, code cells as executable chunks, and a leading raw cell holding
     * YAML as the front matter. Outputs are not imported.
     */
    function haxcmsImportQuartoNotebookToMarkdown($raw, &$run)
    {
        $notebook = json_decode($raw, false);
        if (!($notebook instanceof \stdClass) || !is_array(haxcmsImportQuartoGet($notebook, 'cells'))) {
            return '';
        }
        $metadata = haxcmsImportQuartoObjectOf(haxcmsImportQuartoGet($notebook, 'metadata'));
        $language = haxcmsImportQuartoStringOf(haxcmsImportQuartoGet(haxcmsImportQuartoObjectOf(haxcmsImportQuartoGet($metadata, 'kernelspec')), 'language'));
        if ($language === '') {
            $language = haxcmsImportQuartoStringOf(haxcmsImportQuartoGet(haxcmsImportQuartoObjectOf(haxcmsImportQuartoGet($metadata, 'language_info')), 'name'));
        }
        if ($language === '') {
            $language = 'python';
        }
        $language = haxcmsImportQuartoReplace('/[^A-Za-z0-9_+-]/', '', $language);
        $frontMatter = '';
        $parts = array();
        foreach ($notebook->cells as $index => $cell) {
            if (!($cell instanceof \stdClass)) {
                continue;
            }
            $source = haxcmsImportQuartoCellSource(haxcmsImportQuartoGet($cell, 'source'));
            $type = haxcmsImportQuartoGet($cell, 'cell_type');
            if ($type === 'markdown') {
                $parts[] = $source;
            }
            elseif ($type === 'code') {
                $parts[] = '```{' . $language . "}\n" . $source . "\n```";
                $outputs = haxcmsImportQuartoGet($cell, 'outputs');
                if (is_array($outputs) && count($outputs) > 0) {
                    $run['unmapped']['code-outputs'] = true;
                }
            }
            elseif ($type === 'raw' && $index === 0 && preg_match('/^---\r?\n/', haxcmsImportQuartoTrim($source))) {
                $frontMatter = haxcmsImportQuartoTrim($source);
            }
        }
        $body = implode("\n\n", $parts);
        return $frontMatter === '' ? $body : $frontMatter . "\n\n" . $body;
    }

    /**
     * {{< include file.qmd >}} pulls another file's markdown into the page. The
     * included file is read relative to the file that includes it, runs through
     * the same pipeline as a page, and is bounded in depth and in count.
     */
    function haxcmsImportQuartoExpandIncludes($markdown, $currentPath, &$run, $depth)
    {
        $segments = haxcmsImportQuartoSplitSegments($markdown);
        foreach ($segments as $key => $segment) {
            if ($segment['code']) {
                continue;
            }
            $text = implode("\n", $segment['lines']);
            $matches = array();
            if (preg_match_all('/\{\{<\s*include\s+([^\s>]+)\s*>\}\}/u', $text, $found, PREG_SET_ORDER)) {
                foreach ($found as $match) {
                    $matches[] = array('token' => $match[0], 'reference' => $match[1]);
                }
            }
            foreach ($matches as $match) {
                $replacement = haxcmsImportQuartoReadInclude($match['reference'], $currentPath, $run, $depth);
                // String.replace with a function: the first occurrence, literally
                $at = strpos($text, $match['token']);
                if ($at !== false) {
                    $text = substr_replace($text, $replacement, $at, strlen($match['token']));
                }
            }
            $segments[$key]['lines'] = explode("\n", $text);
        }
        return haxcmsImportQuartoJoinSegments($segments);
    }

    function haxcmsImportQuartoReadInclude($reference, $currentPath, &$run, $depth)
    {
        $clean = haxcmsImportQuartoReplace('/^["\']|["\']$/D', '', $reference);
        if (!preg_match(HAXCMS_QUARTO_PAGE_EXTENSION_REGEX, $clean) || haxcmsImportQuartoIsExternal($clean)) {
            return '';
        }
        $directory = haxcmsImportQuartoDirectory($currentPath);
        $repoPath = substr($clean, 0, 1) === '/'
            ? haxcmsImportQuartoNormalizePath(haxcmsImportQuartoRootPrefix($run['source']) . $clean)
            : haxcmsImportQuartoNormalizePath($directory . '/' . $clean);
        if (!isset($run['source']['paths'][$repoPath])) {
            return '';
        }
        $limits = haxcmsImportQuartoLimits();
        if ($depth >= HAXCMS_QUARTO_MAX_INCLUDE_DEPTH || $run['includes'] >= $limits['maxIncludes']) {
            $run['truncated'] = true;
            return '';
        }
        $run['includes']++;
        $included = haxcmsImportQuartoFetchText(
            $run['client'],
            $run['source']['rawBase'] . '/' . haxcmsImportQuartoEncodeUri($repoPath)
        );
        if ($included === null) {
            return '';
        }
        $markdown = preg_match(HAXCMS_QUARTO_NOTEBOOK_REGEX, $repoPath)
            ? haxcmsImportQuartoNotebookToMarkdown($included, $run)
            : $included;
        $split = haxcmsImportQuartoSplitFrontmatter($markdown);
        $inner = haxcmsImportQuartoExpandIncludes($split['body'], $repoPath, $run, $depth + 1);
        return "\n\n" . $inner . "\n\n";
    }

    /**
     * Executable chunks ({r}, {python}, {julia}) become plain fenced code in the
     * language they name. Chunk options (#| lines and knitr header options) are
     * execution metadata and are dropped; a chunk that Quarto would hide
     * (echo or include false) is dropped with them. Raw {=html} blocks keep their
     * content, other raw blocks are dropped. Outputs are not computed, so a page
     * with executable chunks is reported as having none.
     */
    function haxcmsImportQuartoTransformCodeBlocks($markdown, &$run)
    {
        $segments = haxcmsImportQuartoSplitSegments($markdown);
        foreach ($segments as $key => $segment) {
            if (!$segment['code']) {
                continue;
            }
            if (!preg_match('/^(\s{0,3})(`{3,}|~{3,})\s*([^\n]*)$/uD', $segment['lines'][0], $open)) {
                continue;
            }
            $info = haxcmsImportQuartoTrim($open[3]);
            if (preg_match('/^\{=([A-Za-z0-9_-]+)\}$/D', $info, $raw)) {
                $inner = array_slice($segment['lines'], 1);
                $last = count($inner) > 0 ? $inner[count($inner) - 1] : '';
                if (preg_match('/^\s{0,3}(`{3,}|~{3,})\s*$/uD', $last)) {
                    array_pop($inner);
                }
                $segments[$key]['code'] = false;
                $segments[$key]['lines'] = strtolower($raw[1]) === 'html'
                    ? array_merge(array(''), $inner, array(''))
                    : array('');
                continue;
            }
            if (!preg_match('/^\{\s*\.?([A-Za-z0-9_+-]+)\s*(?:,\s*|\s+)?([^}]*)\}$/uD', $info, $chunk)) {
                continue;
            }
            $executable = !preg_match('/^\{\s*\./', $info) && !in_array($chunk[1], array('mermaid', 'dot'), true);
            $lines = array_slice($segment['lines'], 1);
            $closer = null;
            $last = count($lines) > 0 ? $lines[count($lines) - 1] : '';
            if (preg_match('/^\s{0,3}(`{3,}|~{3,})\s*$/uD', $last)) {
                $closer = array_pop($lines);
            }
            $options = array();
            while (count($lines) > 0 && preg_match('/^\s*#\|/', $lines[0])) {
                if (preg_match('/^\s*#\|\s*([A-Za-z0-9_.-]+)\s*:\s*([^\n]*)$/uD', $lines[0], $option)) {
                    $options[$option[1]] = mb_strtolower(haxcmsImportQuartoTrim($option[2]), 'UTF-8');
                }
                array_shift($lines);
            }
            $header = isset($chunk[2]) ? $chunk[2] : '';
            $hidden = (isset($options['echo']) && $options['echo'] === 'false')
                || (isset($options['include']) && $options['include'] === 'false')
                || preg_match('/\b(echo|include)\s*=\s*(FALSE|F|false)\b/', $header);
            if ($executable
                && !(isset($options['eval']) && $options['eval'] === 'false')
                && !preg_match('/\beval\s*=\s*(FALSE|F|false)\b/', $header)) {
                $run['unmapped']['code-outputs'] = true;
            }
            if ($hidden) {
                $segments[$key]['code'] = false;
                $segments[$key]['lines'] = array('');
                continue;
            }
            $newLines = array_merge(array($open[1] . $open[2] . $chunk[1]), $lines);
            if ($closer !== null) {
                $newLines[] = $closer;
            }
            $segments[$key]['lines'] = $newLines;
        }
        return haxcmsImportQuartoJoinSegments($segments);
    }

    // --- shortcodes -------------------------------------------------------------

    /** Positional and name="value" arguments of a shortcode. */
    function haxcmsImportQuartoParseShortcodeArguments($source)
    {
        $positional = array();
        $named = array();
        $pattern = '/([A-Za-z][A-Za-z0-9_-]*)=(?:"([^"]*)"|\'([^\']*)\'|(\S+))|"([^"]*)"|\'([^\']*)\'|(\S+)/u';
        if (preg_match_all($pattern, (string) $source, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL)) {
            foreach ($matches as $match) {
                if ($match[1] !== null) {
                    if ($match[2] !== null) {
                        $value = $match[2];
                    }
                    elseif ($match[3] !== null) {
                        $value = $match[3];
                    }
                    else {
                        $value = $match[4];
                    }
                    $named[$match[1]] = $value;
                }
                else {
                    if ($match[5] !== null) {
                        $value = $match[5];
                    }
                    elseif ($match[6] !== null) {
                        $value = $match[6];
                    }
                    else {
                        $value = $match[7];
                    }
                    $positional[] = $value;
                }
            }
        }
        return array('positional' => $positional, 'named' => $named);
    }

    /**
     * Quarto shortcodes. video has a HAX equivalent (video-player); the others
     * are unwrapped to nothing and reported in unmappedComponents rather than
     * left as text the site cannot render. include is handled earlier.
     */
    function haxcmsImportQuartoTransformShortcodes($markdown, $node, &$run)
    {
        $silent = array('pagebreak', 'lipsum');
        return haxcmsImportQuartoMapText($markdown, function ($text) use ($node, &$run, $silent) {
            return haxcmsImportQuartoReplaceCallback(
                '/\{\{<\s*([A-Za-z0-9_-]+)\s*(.*?)\s*>\}\}/us',
                function ($match) use ($node, &$run, $silent) {
                    $lower = mb_strtolower($match[1], 'UTF-8');
                    if ($lower === 'video') {
                        $args = haxcmsImportQuartoParseShortcodeArguments($match[2]);
                        $source = isset($args['positional'][0]) ? $args['positional'][0] : '';
                        if ($source === '') {
                            return '';
                        }
                        if (haxcmsImportQuartoIsExternal($source)) {
                            $resolved = $source;
                        }
                        else {
                            $resolved = haxcmsImportQuartoRegisterAsset($source, $node, $run);
                            if ($resolved === null) {
                                $resolved = haxcmsImportQuartoAbsoluteSourceUrl($source, $node, $run);
                            }
                        }
                        $tag = '<video-player source="' . SanitizeContent::escapeHTMLAttribute($resolved) . '"';
                        if (isset($args['named']['title']) && $args['named']['title'] !== '') {
                            $tag .= ' media-title="' . SanitizeContent::escapeHTMLAttribute($args['named']['title']) . '"';
                        }
                        // markdown-it leaves a complete tag pair on one line inside
                        // a paragraph, so the lifted element carries its own <p>
                        return "\n\n" . haxcmsImportQuartoToken($run, '<p>' . $tag . '></video-player></p>') . "\n\n";
                    }
                    if ($lower !== 'include' && !in_array($lower, $silent, true)) {
                        $run['unmapped']['shortcode:' . $lower] = true;
                    }
                    return '';
                },
                $text
            );
        });
    }

    // --- fenced divs and callouts -------------------------------------------------

    /** Whether a content-visible/hidden div applies to the html output. */
    function haxcmsImportQuartoAppliesToTarget($values)
    {
        $when = mb_strtolower(haxcmsImportQuartoStringOf(isset($values['when-format']) ? $values['when-format'] : null), 'UTF-8');
        $unless = mb_strtolower(haxcmsImportQuartoStringOf(isset($values['unless-format']) ? $values['unless-format'] : null), 'UTF-8');
        $names = function ($value) {
            return array_values(array_filter(preg_split('/[\s,]+/u', $value), function ($name) {
                return $name !== '';
            }));
        };
        if ($when !== '' && !in_array(HAXCMS_QUARTO_TARGET_FORMAT, $names($when), true)) {
            return false;
        }
        return !($unless !== '' && in_array(HAXCMS_QUARTO_TARGET_FORMAT, $names($unless), true));
    }

    function haxcmsImportQuartoCalloutOf($attributes)
    {
        $callouts = haxcmsImportQuartoCallouts();
        foreach ($attributes['classes'] as $class) {
            if (preg_match('/^callout-(note|tip|important|warning|caution)$/D', $class, $match)) {
                return $callouts[$match[1]];
            }
        }
        return null;
    }

    function haxcmsImportQuartoIsDropping($stack)
    {
        foreach ($stack as $frame) {
            if ($frame['kind'] === 'drop') {
                return true;
            }
        }
        return false;
    }

    /**
     * Pandoc fenced divs (::: {.class} ... :::). Callouts become stop-note (the
     * title comes from the title attribute, else a first heading, else the type);
     * content-visible/content-hidden divs are kept or dropped for html output;
     * every other div keeps its id and classes. Blank lines around each wrapper
     * let the markdown inside still render.
     */
    function haxcmsImportQuartoTransformDivs($markdown, &$run)
    {
        $segments = haxcmsImportQuartoSplitSegments($markdown);
        $out = array();
        $stack = array();
        foreach ($segments as $segment) {
            if ($segment['code']) {
                foreach ($segment['lines'] as $line) {
                    if (!haxcmsImportQuartoIsDropping($stack)) {
                        $out[] = $line;
                    }
                }
                continue;
            }
            $lines = $segment['lines'];
            $count = count($lines);
            for ($i = 0; $i < $count; $i++) {
                $line = $lines[$i];
                if ($line === null) {
                    continue;
                }
                $fenced = preg_match('/^\s{0,3}(:{3,})\s*([^\n]*?)\s*$/uD', $line, $fence);
                $dropping = haxcmsImportQuartoIsDropping($stack);
                if (!$fenced) {
                    if (!$dropping) {
                        $out[] = $line;
                    }
                    continue;
                }
                $attributeText = haxcmsImportQuartoTrim(haxcmsImportQuartoReplace('/\s*:+\s*$/uD', '', $fence[2]));
                if ($attributeText === '') {
                    $frame = array_pop($stack);
                    if ($frame !== null && count($frame['close']) > 0) {
                        foreach ($frame['close'] as $closeLine) {
                            $out[] = $closeLine;
                        }
                    }
                    elseif ($frame === null && !$dropping) {
                        $out[] = $line;
                    }
                    continue;
                }
                // ::: {.class key=value} carries an attribute block; ::: class is
                // the same thing with a bare class name
                if (substr($attributeText, 0, 1) === '{') {
                    $attributes = haxcmsImportQuartoParseAttributes(haxcmsImportQuartoReplace('/^\{|\}$/D', '', $attributeText));
                }
                else {
                    $attributes = array(
                        'id'      => '',
                        'classes' => array_values(array_filter(preg_split('/\s+/u', $attributeText), function ($name) {
                            return $name !== '';
                        })),
                        'values'  => array(),
                    );
                }
                if ($dropping) {
                    $stack[] = array('kind' => 'drop', 'close' => array());
                    continue;
                }
                $callout = haxcmsImportQuartoCalloutOf($attributes);
                if ($callout !== null) {
                    $title = haxcmsImportQuartoStringOf(isset($attributes['values']['title']) ? $attributes['values']['title'] : null);
                    if ($title === '') {
                        // Quarto takes the first heading inside a callout as its title
                        $next = $i + 1;
                        while ($next < $count && $lines[$next] !== null && haxcmsImportQuartoTrim($lines[$next]) === '') {
                            $next++;
                        }
                        $heading = $next < $count && $lines[$next] !== null
                            && preg_match('/^#{1,6}\s+([^\n]+?)\s*$/uD', $lines[$next], $headingMatch);
                        if ($heading) {
                            $title = haxcmsImportQuartoHeadingText($headingMatch[1]);
                            $lines[$next] = null;
                        }
                    }
                    $open = '<stop-note status="' . SanitizeContent::escapeHTMLAttribute($callout['status']) . '"'
                        . ' title="' . SanitizeContent::escapeHTMLAttribute($title !== '' ? $title : $callout['title']) . '">'
                        . "\n" . '<div slot="message">';
                    $out[] = '';
                    $out[] = haxcmsImportQuartoToken($run, $open);
                    $out[] = '';
                    $stack[] = array(
                        'kind'  => 'callout',
                        'close' => array('', haxcmsImportQuartoToken($run, "</div>\n</stop-note>"), ''),
                    );
                    continue;
                }
                $conditional = '';
                if (in_array('content-visible', $attributes['classes'], true)) {
                    $conditional = 'visible';
                }
                elseif (in_array('content-hidden', $attributes['classes'], true)) {
                    $conditional = 'hidden';
                }
                if ($conditional !== '') {
                    $applies = haxcmsImportQuartoAppliesToTarget($attributes['values']);
                    $keep = $conditional === 'visible' ? $applies : !$applies;
                    $stack[] = array('kind' => $keep ? 'unwrap' : 'drop', 'close' => array());
                    continue;
                }
                $open = '<div';
                if ($attributes['id'] !== '') {
                    $open .= ' id="' . SanitizeContent::escapeHTMLAttribute($attributes['id']) . '"';
                }
                if (count($attributes['classes']) > 0) {
                    $open .= ' class="' . SanitizeContent::escapeHTMLAttribute(implode(' ', $attributes['classes'])) . '"';
                }
                $out[] = '';
                $out[] = haxcmsImportQuartoToken($run, $open . '>');
                $out[] = '';
                $stack[] = array(
                    'kind'  => 'div',
                    'close' => array('', haxcmsImportQuartoToken($run, '</div>'), ''),
                );
            }
        }
        // a div left open at the end of the page is closed for it
        while (count($stack) > 0) {
            $frame = array_pop($stack);
            foreach ($frame['close'] as $closeLine) {
                $out[] = $closeLine;
            }
        }
        return implode("\n", $out);
    }

    // --- inline pandoc syntax -----------------------------------------------------

    /**
     * Inline syntax markdown does not know: attribute blocks on images, links
     * and headings, bracketed spans, and cross-references. Image attributes are
     * mined for fig-alt; the rest are dropped. A cross-reference keeps its
     * readable key without the @, since numbering needs a real render.
     */
    function haxcmsImportQuartoTransformInline($text, &$run)
    {
        $lines = explode("\n", $text);
        foreach ($lines as $key => $line) {
            $result = haxcmsImportQuartoReplaceCallback(
                '/^(#{1,6}\s+.*?)\s+\{[^}]*\}\s*$/uD',
                function ($match) {
                    return $match[1];
                },
                $line
            );
            // A captioned table: ": Caption {#tbl-key}" becomes an emphasized line.
            $result = haxcmsImportQuartoReplaceCallback(
                '/^:\s+(.+?)\s*\{#tbl-[^}]*\}\s*$/uD',
                function ($match) {
                    return '*' . $match[1] . '*';
                },
                $result
            );
            // Image attributes run before code spans are set aside, because a
            // caption can hold inline code (`qplot`) that would split the image.
            $subject = $result;
            $result = haxcmsImportQuartoReplaceCallback(
                '/!\[((?:[^\]`]|`[^`]*`)*)\]\(([^)\s]+)(?:\s+"[^"]*")?\)\{([^}]*)\}/u',
                function ($match) use ($subject) {
                    $before = substr($subject, 0, $match[0][1]);
                    if (substr_count($before, '`') % 2 === 1) {
                        return $match[0][0];
                    }
                    $attributes = haxcmsImportQuartoParseAttributes($match[3][0]);
                    $alt = isset($attributes['values']['fig-alt']) && $attributes['values']['fig-alt'] !== ''
                        ? $attributes['values']['fig-alt']
                        : $match[1][0];
                    return '![' . $alt . '](' . $match[2][0] . ')';
                },
                $subject,
                PREG_OFFSET_CAPTURE
            );
            $result = haxcmsImportQuartoOutsideInlineCode($result, function ($piece) use (&$run) {
                $value = haxcmsImportQuartoReplaceCallback(
                    '/(\[[^\]]*\]\([^)]*\))\{[^}]*\}/u',
                    function ($match) {
                        return $match[1];
                    },
                    $piece
                );
                $value = haxcmsImportQuartoReplaceCallback(
                    '/\[([^\]]+)\]\{([^}]*)\}/u',
                    function ($match) {
                        $attributes = haxcmsImportQuartoParseAttributes($match[2]);
                        if (count($attributes['classes']) === 0) {
                            return $match[1];
                        }
                        return '<span class="' . SanitizeContent::escapeHTMLAttribute(implode(' ', $attributes['classes'])) . '">' . $match[1] . '</span>';
                    },
                    $value
                );
                return haxcmsImportQuartoReplaceCallback(
                    '/(^|[^A-Za-z0-9_@])@((?:fig|tbl|sec|eq|lst|thm|lem|cor|prp|cnj|def|exm|exr)-[A-Za-z0-9_:.-]*[A-Za-z0-9_])/u',
                    function ($match) use (&$run) {
                        $run['unmapped']['cross-references'] = true;
                        return $match[1] . $match[2];
                    },
                    $value
                );
            });
            $lines[$key] = $result;
        }
        return implode("\n", $lines);
    }

    // --- media, links and assets ----------------------------------------------------

    /**
     * Rewrite the rendered page against the imported site: images become
     * media-image, in-project links point at the imported slugs. One DOM pass,
     * using the loadHTML idiom importUtils uses.
     */
    function haxcmsImportQuartoRewriteHtml($html, $node, &$run)
    {
        if (haxcmsImportQuartoTrim($html) === '') {
            return '';
        }
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"?><div id="haxcms-quarto-wrapper">' . $html . '</div>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $wrapper = $dom->getElementById('haxcms-quarto-wrapper');
        if ($wrapper === null) {
            $wrappers = $dom->getElementsByTagName('div');
            $wrapper = $wrappers->length > 0 ? $wrappers->item(0) : null;
        }
        if ($wrapper === null) {
            return $html;
        }
        // comments are not content (the Node side parses without them)
        $xpath = new \DOMXPath($dom);
        foreach (iterator_to_array($xpath->query('.//comment()', $wrapper)) as $comment) {
            $comment->parentNode->removeChild($comment);
        }
        haxcmsImportQuartoRewriteMedia($dom, $wrapper, $node, $run);
        haxcmsImportQuartoRewriteLinks($wrapper, $node, $run);
        haxcmsImportQuartoTightenWhitespace($wrapper);
        $rendered = '';
        foreach ($wrapper->childNodes as $child) {
            $rendered .= $dom->saveHTML($child);
        }
        return $rendered;
    }

    /**
     * The renderer separates blocks with a blank line where markdown-it uses a
     * single newline; whitespace-only text between nodes is brought back to one
     * newline so the stored HTML has the same shape on both backends. Text in
     * pre, textarea, script and style is content and stays as written.
     */
    function haxcmsImportQuartoTightenWhitespace($node)
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                if (strpos($child->nodeValue, "\n") !== false && trim($child->nodeValue) === '') {
                    $child->nodeValue = "\n";
                }
            }
            elseif ($child->nodeType === XML_ELEMENT_NODE
                && !in_array(strtolower($child->nodeName), array('pre', 'textarea', 'script', 'style'), true)) {
                haxcmsImportQuartoTightenWhitespace($child);
            }
        }
    }

    /** Images become media-image; anything else keeps its source. */
    function haxcmsImportQuartoRewriteMedia($dom, $wrapper, $node, &$run)
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
            $resolved = haxcmsImportQuartoRegisterAsset($source, $node, $run);
            if ($resolved === null) {
                $resolved = haxcmsImportQuartoAbsoluteSourceUrl($source, $node, $run);
            }
            if (preg_match(HAXCMS_QUARTO_IMAGE_EXTENSION_REGEX, $resolved) || strpos($resolved, 'files/') === 0) {
                $mediaImage = $dom->createElement('media-image');
                $mediaImage->setAttribute('source', $resolved);
                $mediaImage->setAttribute('alt', $image->getAttribute('alt'));
                $image->parentNode->replaceChild($mediaImage, $image);
            }
            else {
                $image->setAttribute('src', $resolved);
            }
        }
    }

    /**
     * In-project links point at the imported slugs; links to importable files join
     * the file map; everything else is absolutized against the source repository.
     */
    function haxcmsImportQuartoRewriteLinks($wrapper, $node, &$run)
    {
        $links = array();
        foreach ($wrapper->getElementsByTagName('a') as $link) {
            $links[] = $link;
        }
        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            if ($href === '' || strpos($href, '#') === 0 || haxcmsImportQuartoIsExternal($href)) {
                continue;
            }
            $docPath = haxcmsImportQuartoResolveDocPath($href, $run['source'], $node);
            if ($docPath !== null && isset($run['slugByPath'][$docPath])) {
                $hashAt = strpos($href, '#');
                $hash = $hashAt === false ? '' : substr($href, $hashAt);
                $link->setAttribute('href', $run['slugByPath'][$docPath] . $hash);
                continue;
            }
            $asset = haxcmsImportQuartoRegisterAsset($href, $node, $run);
            $link->setAttribute('href', $asset !== null ? $asset : haxcmsImportQuartoAbsoluteSourceUrl($href, $node, $run));
        }
    }

    /**
     * A link to the page that backs it. Links are written against the rendered
     * site (chapter.html) or the source (chapter.qmd), relative to the page that
     * carries them or to the project root.
     */
    function haxcmsImportQuartoResolveDocPath($link, $source, $node)
    {
        $target = explode('?', explode('#', (string) $link)[0])[0];
        if ($target === '') {
            return null;
        }
        $target = haxcmsImportQuartoReplace('/\.html$/iD', '', $target);
        $target = haxcmsImportQuartoReplace(HAXCMS_QUARTO_PAGE_EXTENSION_REGEX, '', $target);
        $directory = isset($node['path']) && $node['path'] !== null && $node['path'] !== ''
            ? haxcmsImportQuartoDirectory($node['path'])
            : '';
        if (substr($target, 0, 1) === '/') {
            $base = haxcmsImportQuartoNormalizePath(haxcmsImportQuartoRootPrefix($source) . $target);
        }
        else {
            $base = haxcmsImportQuartoNormalizePath(
                ($directory === '' ? haxcmsImportQuartoRootPrefix($source) : $directory) . '/' . $target
            );
        }
        $extensions = array('.qmd', '.Rmd', '.rmd', '.md', '.ipynb');
        $candidates = array();
        foreach ($extensions as $extension) {
            $candidates[] = $base . $extension;
        }
        foreach ($extensions as $extension) {
            $candidates[] = $base . '/index' . $extension;
        }
        foreach ($candidates as $candidate) {
            if (isset($source['paths'][$candidate])) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Resolve a page reference to a repository file and add it to the file map.
     * Returns the site-relative path, or null when the file is not importable.
     */
    function haxcmsImportQuartoRegisterAsset($reference, $node, &$run)
    {
        if (!is_string($reference) || $reference === '' || haxcmsImportQuartoIsExternal($reference)) {
            return null;
        }
        $clean = explode('?', explode('#', $reference)[0])[0];
        if ($clean === '' || !preg_match(HAXCMS_QUARTO_IMPORTABLE_ASSET_REGEX, $clean)) {
            return null;
        }
        $repoPath = haxcmsImportQuartoResolveAssetPath($clean, $node, $run);
        if ($repoPath === null) {
            return null;
        }
        if (isset($run['filesByPath'][$repoPath])) {
            return $run['filesByPath'][$repoPath];
        }
        $limits = haxcmsImportQuartoLimits();
        if (count($run['files']) >= $limits['maxFiles']) {
            $run['truncated'] = true;
            return null;
        }
        $name = haxcmsImportQuartoUniqueFileName($repoPath, $run);
        $sitePath = 'files/' . $name;
        $run['files'][$sitePath] = haxcmsImportQuartoEncodeUri($run['source']['rawBase'] . '/' . $repoPath);
        $run['filesByPath'][$repoPath] = $sitePath;
        return $sitePath;
    }

    /** An asset reference to a path inside the repository, or null. */
    function haxcmsImportQuartoResolveAssetPath($reference, $node, $run)
    {
        $prefix = haxcmsImportQuartoRootPrefix($run['source']);
        if (substr($reference, 0, 1) === '/') {
            // a leading slash is the project root, as it is for Quarto itself
            $candidate = haxcmsImportQuartoNormalizePath($prefix . $reference);
        }
        else {
            $directory = isset($node['path']) && $node['path'] !== null && $node['path'] !== ''
                ? haxcmsImportQuartoDirectory($node['path'])
                : preg_replace('#/$#D', '', $prefix);
            $candidate = haxcmsImportQuartoNormalizePath($directory . '/' . $reference);
        }
        return isset($run['source']['paths'][$candidate]) ? $candidate : null;
    }

    /** files/ is flat, so keep basenames unique across the import. */
    function haxcmsImportQuartoUniqueFileName($repoPath, &$run)
    {
        $pieces = explode('/', $repoPath);
        $base = haxcmsImportQuartoReplace('/[^A-Za-z0-9._-]+/', '-', array_pop($pieces));
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
    function haxcmsImportQuartoAbsoluteSourceUrl($reference, $node, $run)
    {
        if (haxcmsImportQuartoIsExternal($reference)) {
            return $reference;
        }
        $source = $run['source'];
        $clean = explode('?', explode('#', $reference)[0])[0];
        $repoPath = haxcmsImportQuartoResolveAssetPath($clean, $node, $run);
        if ($repoPath !== null) {
            return haxcmsImportQuartoEncodeUri($source['rawBase'] . '/' . $repoPath);
        }
        $prefix = haxcmsImportQuartoRootPrefix($source);
        if (substr($clean, 0, 1) === '/') {
            return haxcmsImportQuartoEncodeUri(
                $source['rawBase'] . '/' . haxcmsImportQuartoNormalizePath($prefix . $clean)
            );
        }
        $directory = isset($node['path']) && $node['path'] !== null && $node['path'] !== ''
            ? haxcmsImportQuartoDirectory($node['path'])
            : preg_replace('#/$#D', '', $prefix);
        return haxcmsImportQuartoEncodeUri(
            $source['rawBase'] . '/' . haxcmsImportQuartoNormalizePath($directory . '/' . $clean)
        );
    }

    /** Attribution for one page, using the license code the source declared. */
    function haxcmsImportQuartoLicenseElement($license, $node, $item, $run, $author)
    {
        $title = $item['title'];
        if ($title === null || $title === '') {
            $title = $run['source']['config']['title'] !== '' ? $run['source']['config']['title'] : $run['source']['title'];
        }
        $element = '<license-element license="' . SanitizeContent::escapeHTMLAttribute($license) . '"'
            . ' title="' . SanitizeContent::escapeHTMLAttribute($title) . '"';
        if ($author !== null && $author !== '') {
            $element .= ' creator="' . SanitizeContent::escapeHTMLAttribute($author) . '"';
        }
        if (isset($item['metadata']['source']) && $item['metadata']['source'] !== '') {
            $element .= ' source="' . SanitizeContent::escapeHTMLAttribute($item['metadata']['source']) . '"';
        }
        return $element . '></license-element>';
    }
}
