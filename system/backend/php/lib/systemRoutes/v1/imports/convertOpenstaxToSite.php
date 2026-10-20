<?php
include_once dirname(__FILE__) . '/../../../siteRoutes/SiteRouteUtils.php';
include_once dirname(__FILE__) . '/importUtils.php';

$__vendorAutoload = dirname(__FILE__) . '/../../../../vendor/autoload.php';
if (file_exists($__vendorAutoload)) {
    require_once $__vendorAutoload;
}
include_once dirname(__FILE__) . '/../../../SsrfGuard.php';
include_once dirname(__FILE__) . '/../../../SanitizeContent.php';
include_once dirname(__FILE__) . '/../../../stageRemoteFile.php';

if (!defined('HAXCMS_OPENSTAX_ORIGIN')) {
    define('HAXCMS_OPENSTAX_ORIGIN', 'https://openstax.org');
    // image types HAXCMSFile accepts, keyed by the content type the archive serves
    define('HAXCMS_OPENSTAX_EXTENSION_BY_CONTENT_TYPE', serialize(array(
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    )));
    // license codes site.license understands (mirrors convertPressbooksToSite)
    define('HAXCMS_OPENSTAX_SITE_LICENSES', serialize(array('by', 'by-sa', 'by-nd', 'by-nc', 'by-nc-sa', 'by-nc-nd')));
    // attributes worth keeping, by tag. Everything else (os-* classes, fs-id
    // ids, data-type hooks, inline styles) is dropped so HAX gets clean markup
    define('HAXCMS_OPENSTAX_KEEP_ATTRIBUTES', serialize(array(
        'a'           => array('href'),
        'img'         => array('src', 'alt', 'width', 'height'),
        'td'          => array('colspan', 'rowspan', 'headers'),
        'th'          => array('colspan', 'rowspan', 'scope'),
        'ol'          => array('start', 'type'),
        'math'        => array('display'),
        'media-image' => array('source', 'alt'),
    )));
    // dropped outright: presentation and script material with no content value
    define('HAXCMS_OPENSTAX_DROP_ELEMENTS', serialize(array('style', 'script', 'link', 'meta', 'noscript', 'head')));
}

if (!function_exists('haxcmsImportConvertOpenstaxToSite')) {
    /**
     * Safety valves, mirroring the exported LIMITS in haxcms-nodejs
     * convertOpenstaxToSite.js. A book runs to hundreds of pages and thousands
     * of images. $GLOBALS['HAXCMS_OPENSTAX_LIMITS'] overrides them so tests can
     * exercise each cap without a book of that size.
     */
    function haxcmsImportOpenstaxLimits()
    {
        $defaults = array(
            'maxPages'           => 500,
            'maxImages'          => 1000,
            'maxImageBytes'      => 250 * 1024 * 1024,
            'fetchBudgetSeconds' => 900,
            'requestDelayMs'     => 200,
        );
        if (isset($GLOBALS['HAXCMS_OPENSTAX_LIMITS']) && is_array($GLOBALS['HAXCMS_OPENSTAX_LIMITS'])) {
            return array_merge($defaults, $GLOBALS['HAXCMS_OPENSTAX_LIMITS']);
        }
        return $defaults;
    }

    /**
     * POST /system/api/v1/site/import/openstax
     * Convert an OpenStax textbook into a HAXcms site schema.
     *
     * PHP port of haxcms-nodejs src/systemRoutes/v1/routes/imports/
     * convertOpenstaxToSite.js (haxtheweb/issues#3168), kept structurally
     * identical: same outline, slugs, file map, status codes and error strings.
     *
     * Expects a JSON body with a `repoUrl` param: https://openstax.org/details/
     * books/<slug> or https://openstax.org/books/<slug>/pages/<page-slug>.
     * Only openstax.org is accepted, and every request goes to the fixed
     * OpenStax origin through SsrfGuard, never to a host taken from the body.
     *
     * Returns { status: 200, data: { items, filename, files, site, truncated } }.
     */
    function haxcmsImportConvertOpenstaxToSite($context)
    {
        $client = new \GuzzleHttp\Client(array('timeout' => 30, 'connect_timeout' => 10));
        haxcmsImportOpenstaxRun($context, $client);
    }

    /**
     * The import, with the HTTP client supplied by the caller so tests can
     * drive the whole route through a Guzzle MockHandler. $origin overrides the
     * OpenStax host, which lets tests use an IP literal and keep the SSRF check
     * off DNS.
     */
    function haxcmsImportOpenstaxRun($context, $client, $origin = null)
    {
        $origin = $origin === null ? HAXCMS_OPENSTAX_ORIGIN : $origin;
        $body = isset($context->body) && is_array($context->body) ? $context->body : array();
        $repoUrl = isset($body['repoUrl']) && is_string($body['repoUrl']) ? trim($body['repoUrl']) : '';
        if ($repoUrl === '') {
            haxcmsImportOpenstaxSendError($context, 400, 'missing `repoUrl` param');
            return;
        }
        try {
            $bookSlug = haxcmsImportOpenstaxBookSlugFromUrl($repoUrl);
            $book = haxcmsImportOpenstaxResolveBook($client, $origin, $bookSlug);
            $imported = haxcmsImportOpenstaxImportBook($client, $origin, $book);
        }
        catch (\Exception $error) {
            $status = (int) $error->getCode();
            if ($status !== 400 && $status !== 422) {
                $status = 422;
            }
            $message = $error->getMessage() !== '' ? $error->getMessage() : 'Unable to import this OpenStax book';
            haxcmsImportOpenstaxSendError($context, $status, $message);
            return;
        }
        $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/system/api';
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 200, 'data' => array(
                'items'     => $imported['items'],
                'filename'  => $bookSlug,
                'files'     => (object) $imported['files'],
                'site'      => array('license' => $book['licenseCode']),
                // true when the page cap or time budget stopped the import
                // early; those pages carry a link to their source instead
                'truncated' => $imported['truncated'],
            )),
            array('statusCode' => 200, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
    }

    function haxcmsImportOpenstaxSendError($context, $status, $message)
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

    /** Pull the book slug out of a details or reader URL, rejecting other hosts. */
    function haxcmsImportOpenstaxBookSlugFromUrl($sourceUrl)
    {
        $parsed = @parse_url($sourceUrl);
        if ($parsed === false || !isset($parsed['scheme']) || !isset($parsed['host'])) {
            throw new \RuntimeException('`repoUrl` is not a valid URL: ' . $sourceUrl, 400);
        }
        $host = strtolower($parsed['host']);
        if ($host !== 'openstax.org' && $host !== 'www.openstax.org') {
            throw new \RuntimeException('`repoUrl` must be an openstax.org URL, got: ' . $parsed['host'], 400);
        }
        $path = isset($parsed['path']) ? $parsed['path'] : '';
        $segments = array_values(array_filter(explode('/', $path), function ($segment) {
            return $segment !== '';
        }));
        // /details/books/<slug> and /books/<slug>/pages/<page-slug>
        $booksIndex = array_search('books', $segments, true);
        if ($booksIndex === false || !isset($segments[$booksIndex + 1]) || $segments[$booksIndex + 1] === '') {
            throw new \RuntimeException(
                'unable to read a book slug from `repoUrl`; expected /details/books/<slug> or /books/<slug>/pages/<page>',
                400
            );
        }
        return rawurldecode($segments[$booksIndex + 1]);
    }

    /** GET JSON through SsrfGuard. */
    function haxcmsImportOpenstaxFetchJson($client, $url, $description)
    {
        try {
            $response = SsrfGuard::safeGuzzleRequest($client, 'GET', $url, array(
                'headers'     => array('Accept' => 'application/json', 'User-Agent' => 'HAXcms-Import/1.0'),
                'http_errors' => false,
            ));
        }
        catch (\Exception $e) {
            throw new \RuntimeException('unable to reach OpenStax for ' . $description . ': ' . $e->getMessage(), 422);
        }
        $status = (int) $response->getStatusCode();
        if ($status < 200 || $status > 299) {
            throw new \RuntimeException('OpenStax returned ' . $status . ' for ' . $description, 422);
        }
        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('OpenStax returned unreadable data for ' . $description, 422);
        }
        return $decoded;
    }

    /**
     * Resolve a book slug to everything needed to read it: content id, version,
     * archive base, title, license and the page tree.
     */
    function haxcmsImportOpenstaxResolveBook($client, $origin, $bookSlug)
    {
        $release = haxcmsImportOpenstaxFetchJson($client, $origin . '/rex/release.json', 'the OpenStax release manifest');
        $archivePath = isset($release['archiveUrl']) && is_scalar($release['archiveUrl']) ? (string) $release['archiveUrl'] : '';
        if ($archivePath === '') {
            throw new \RuntimeException('the OpenStax release manifest did not name an archive', 422);
        }
        $lookup = haxcmsImportOpenstaxFetchJson(
            $client,
            $origin . '/apps/cms/api/v2/pages/?type=books.Book&fields=title,cnx_id&slug=' . rawurlencode($bookSlug),
            'the book "' . $bookSlug . '"'
        );
        $entries = isset($lookup['items']) && is_array($lookup['items']) ? $lookup['items'] : array();
        if (count($entries) === 0 || !is_array($entries[0]) || empty($entries[0]['cnx_id'])) {
            throw new \RuntimeException('OpenStax has no book with the slug "' . $bookSlug . '"', 422);
        }
        $contentId = (string) $entries[0]['cnx_id'];
        $books = isset($release['books']) && is_array($release['books']) ? $release['books'] : array();
        $bookRelease = isset($books[$contentId]) && is_array($books[$contentId]) ? $books[$contentId] : null;
        if ($bookRelease === null || empty($bookRelease['defaultVersion'])) {
            throw new \RuntimeException('OpenStax is not currently publishing "' . $bookSlug . '"', 422);
        }
        $archiveUrl = $origin . $archivePath;
        $version = (string) $bookRelease['defaultVersion'];
        $contents = haxcmsImportOpenstaxFetchJson(
            $client,
            $archiveUrl . '/contents/' . $contentId . '@' . $version . '.json',
            'the contents of "' . $bookSlug . '"'
        );
        if (!isset($contents['tree']) || !is_array($contents['tree']) || !isset($contents['tree']['contents']) || !is_array($contents['tree']['contents'])) {
            throw new \RuntimeException('OpenStax returned no table of contents for "' . $bookSlug . '"', 422);
        }
        $license = isset($contents['license']) && is_array($contents['license']) ? $contents['license'] : array();
        return array(
            'slug'        => $bookSlug,
            'contentId'   => $contentId,
            'version'     => $version,
            'archiveUrl'  => $archiveUrl,
            'title'       => haxcmsImportOpenstaxPlainText(!empty($contents['title']) ? $contents['title'] : $bookSlug),
            'license'     => $license,
            'licenseCode' => haxcmsImportOpenstaxLicenseCode($license),
            'tree'        => $contents['tree'],
        );
    }

    /** Map an OpenStax license record onto a site.license code, defaulting to by. */
    function haxcmsImportOpenstaxLicenseCode($license)
    {
        $url = isset($license['url']) && is_scalar($license['url']) ? (string) $license['url'] : '';
        $name = isset($license['name']) && is_scalar($license['name']) ? (string) $license['name'] : '';
        $value = strtolower($url . ' ' . $name);
        foreach (unserialize(HAXCMS_OPENSTAX_SITE_LICENSES) as $code) {
            if (strpos($value, '/licenses/' . $code . '/') !== false) {
                return $code;
            }
        }
        $compact = preg_replace('/[^a-z]/', '', $value);
        $nonCommercial = strpos($compact, 'noncommercial') !== false;
        $noDerivatives = strpos($compact, 'noderivatives') !== false;
        $shareAlike = strpos($compact, 'sharealike') !== false;
        if ($nonCommercial && $noDerivatives) {
            return 'by-nc-nd';
        }
        if ($nonCommercial && $shareAlike) {
            return 'by-nc-sa';
        }
        if ($nonCommercial) {
            return 'by-nc';
        }
        if ($noDerivatives) {
            return 'by-nd';
        }
        if ($shareAlike) {
            return 'by-sa';
        }
        return 'by';
    }

    /**
     * Slug for one page title. Section titles end in punctuation often enough
     * ("What Is Finance?") that cleaning leaves a trailing separator, so trim
     * the edges; keep the cleaned slug if trimming would empty it.
     */
    function haxcmsImportOpenstaxTitleSlug($title)
    {
        $cleaned = haxcmsImportCleanTitle($title);
        $trimmed = trim($cleaned, '-');
        return $trimmed === '' ? $cleaned : $trimmed;
    }

    /** Strip markup and collapse whitespace; tree titles carry os-* span markup. */
    function haxcmsImportOpenstaxPlainText($value)
    {
        $raw = is_scalar($value) ? (string) $value : '';
        // tags become spaces first: OpenStax titles are adjacent spans ("1.1",
        // "What Is Finance?") that would otherwise run together
        $text = html_entity_decode(preg_replace('/<[^>]+>/', ' ', $raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Walk the tree into a flat, ordered list of nodes. Units and chapters keep
     * their children, so the JOS parent/indent relationship follows the book.
     */
    function haxcmsImportOpenstaxFlattenTree($tree)
    {
        $nodes = array();
        $walk = function ($branch, $depth, $parentIndex) use (&$walk, &$nodes) {
            $children = isset($branch['contents']) && is_array($branch['contents']) ? $branch['contents'] : array();
            $order = 0;
            foreach ($children as $child) {
                if (!is_array($child)) {
                    continue;
                }
                $title = haxcmsImportOpenstaxPlainText(isset($child['title']) ? $child['title'] : '');
                if ($title === '') {
                    continue;
                }
                $hasChildren = isset($child['contents']) && is_array($child['contents']) && count($child['contents']) > 0;
                $index = count($nodes);
                $id = isset($child['id']) && is_scalar($child['id']) ? (string) $child['id'] : '';
                $idParts = explode('@', $id);
                $nodes[] = array(
                    'title'       => $title,
                    'depth'       => $depth,
                    'order'       => $order,
                    'parentIndex' => $parentIndex,
                    // leaf nodes are pages; "<uuid>@<version>" needs the version trimmed
                    'pageId'      => $hasChildren ? null : $idParts[0],
                    'sourceSlug'  => isset($child['slug']) && is_scalar($child['slug']) && $child['slug'] ? (string) $child['slug'] : '',
                    'itemIndex'   => null,
                );
                $order++;
                if ($hasChildren) {
                    $walk($child, $depth + 1, $index);
                }
            }
        };
        $walk($tree, 0, null);
        return $nodes;
    }

    /** Fetch and convert every page in the tree into JOS items plus staged files. */
    function haxcmsImportOpenstaxImportBook($client, $origin, $book)
    {
        $nodes = haxcmsImportOpenstaxFlattenTree($book['tree']);
        if (count($nodes) === 0) {
            throw new \RuntimeException('OpenStax returned an empty table of contents for "' . $book['slug'] . '"', 422);
        }
        $limits = haxcmsImportOpenstaxLimits();
        $run = array(
            'book'              => $book,
            'client'            => $client,
            'limits'            => $limits,
            'accessed'          => gmdate('Y-m-d\TH:i:s.000\Z'),
            // slugs by page id, so in-book links can point at the imported pages
            'slugByPageId'      => array(),
            // staged image downloads, keyed by the archive resource path
            'imagesByResource'  => array(),
            'files'             => array(),
            'imageCount'        => 0,
            'imageBytes'        => 0,
            'stagingRoot'       => null,
            // keeps this import's staged names apart from any other import's
            'importId'          => bin2hex(random_bytes(8)),
            'startedAt'         => microtime(true),
            'truncated'         => false,
        );
        $items = array();
        try {
            // first pass: items and slugs, so page bodies can link to siblings by slug
            foreach ($nodes as $index => $node) {
                $parentItem = $node['parentIndex'] === null ? null : $items[$nodes[$node['parentIndex']]['itemIndex']];
                $slug = haxcmsImportOpenstaxTitleSlug($node['title']);
                if ($parentItem !== null) {
                    // nested slugs carry the parent path, matching convertPressbooksToSite
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
                    'sourceType' => 'openstax',
                    'openstax'   => array(
                        'bookSlug'  => $book['slug'],
                        'bookTitle' => $book['title'],
                        'pageSlug'  => $node['sourceSlug'],
                        'license'   => count($book['license']) ? $book['license'] : new stdClass(),
                        'publisher' => 'OpenStax / Rice University',
                        'accessed'  => $run['accessed'],
                    ),
                );
                if ($node['pageId'] !== null && $node['pageId'] !== '') {
                    $item['metadata']['source'] = HAXCMS_OPENSTAX_ORIGIN . '/books/' . $book['slug'] . '/pages/' . $node['sourceSlug'];
                    $run['slugByPageId'][strtolower($node['pageId'])] = $slug;
                }
                $items[] = $item;
                $nodes[$index]['itemIndex'] = count($items) - 1;
            }
            // second pass: page bodies, bounded by the page cap and the time budget
            $fetched = 0;
            foreach ($nodes as $node) {
                $itemIndex = $node['itemIndex'];
                if ($node['pageId'] === null || $node['pageId'] === '') {
                    // a unit or chapter heading: a landing page with no body of its own
                    $items[$itemIndex]['contents'] = '<p></p>';
                    continue;
                }
                if ($fetched >= $limits['maxPages'] || (microtime(true) - $run['startedAt']) > $limits['fetchBudgetSeconds']) {
                    $run['truncated'] = true;
                    $items[$itemIndex]['contents'] = haxcmsImportOpenstaxSourceFallback($items[$itemIndex]['metadata']['source']);
                    continue;
                }
                if ($fetched > 0 && $limits['requestDelayMs'] > 0) {
                    usleep($limits['requestDelayMs'] * 1000);
                }
                $page = haxcmsImportOpenstaxFetchJson(
                    $client,
                    $book['archiveUrl'] . '/contents/' . $book['contentId'] . '@' . $book['version'] . ':' . $node['pageId'] . '.json',
                    'the page "' . $node['title'] . '"'
                );
                $fetched++;
                $items[$itemIndex]['contents'] = haxcmsImportOpenstaxCleanPage(
                    isset($page['content']) ? $page['content'] : '',
                    $run
                );
                if (!empty($page['abstract'])) {
                    $items[$itemIndex]['description'] = mb_substr(haxcmsImportOpenstaxPlainText($page['abstract']), 0, 280);
                }
            }
        }
        catch (\Exception $e) {
            // nothing will consume the staged images of a failed import
            haxcms_import_unlink_staged_files(array_values($run['files']));
            throw $e;
        }
        return array('items' => $items, 'files' => $run['files'], 'truncated' => $run['truncated']);
    }

    /** Pages that could not be fetched still link out to their source. */
    function haxcmsImportOpenstaxSourceFallback($source)
    {
        if (!$source) {
            return '<p></p>';
        }
        return '<p>Read this page on <a href="' . $source . '">OpenStax</a>.</p>';
    }

    /**
     * Turn one page of OpenStax XHTML into clean semantic HTML: drop the styling
     * layer, keep the structure, stage images into the site and point in-book
     * links at the imported pages.
     */
    function haxcmsImportOpenstaxCleanPage($content, &$run)
    {
        if (!is_string($content) || trim($content) === '') {
            return '<p></p>';
        }
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $content, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);
        // the body of the page, minus the xhtml wrapper the archive serves
        $container = $xpath->query('//*[@data-type="page"]')->item(0);
        if (!$container) {
            $container = $xpath->query('//body')->item(0);
        }
        if (!$container) {
            return '<p></p>';
        }
        foreach (unserialize(HAXCMS_OPENSTAX_DROP_ELEMENTS) as $tag) {
            foreach (iterator_to_array($xpath->query('.//' . $tag, $container)) as $dropped) {
                if ($dropped->parentNode) {
                    $dropped->parentNode->removeChild($dropped);
                }
            }
        }
        // the page title becomes the item title, so it does not repeat in the body
        $documentTitle = $xpath->query('.//*[@data-type="document-title"]', $container)->item(0);
        if ($documentTitle && $documentTitle->parentNode) {
            $documentTitle->parentNode->removeChild($documentTitle);
        }
        haxcmsImportOpenstaxStageImages($dom, $xpath, $container, $run);
        haxcmsImportOpenstaxRewriteLinks($xpath, $container, $run);
        haxcmsImportOpenstaxStripPresentationAttributes($xpath, $container);
        // collapse only the archive's pretty-printing between tags: a blanket
        // whitespace collapse would rewrite the inside of pre/code blocks
        $html = '';
        foreach ($container->childNodes as $child) {
            $html .= $dom->saveHTML($child);
        }
        $html = trim(preg_replace('/>\s*\n\s*</', '><', $html));
        return $html === '' ? '<p></p>' : $html;
    }

    /**
     * Download each image into the bulk-import staging directory and render it
     * as media-image at its site path, the same markup the docx import
     * produces. createSite only accepts staged local paths in build.files (see
     * haxtheweb/issues#3060). Images that cannot be staged keep an absolute
     * source.
     */
    function haxcmsImportOpenstaxStageImages($dom, $xpath, $container, &$run)
    {
        foreach (iterator_to_array($xpath->query('.//img', $container)) as $image) {
            $source = $image->getAttribute('src');
            if ($source === '') {
                continue;
            }
            $resource = haxcmsImportOpenstaxResourcePath($source);
            if ($resource === '') {
                continue;
            }
            $absolute = $run['book']['archiveUrl'] . '/' . $resource;
            if (!array_key_exists($resource, $run['imagesByResource'])) {
                $run['imagesByResource'][$resource] = haxcmsImportOpenstaxDownloadImage($absolute, $resource, $run);
            }
            $staged = $run['imagesByResource'][$resource];
            if ($staged) {
                $replacement = $dom->createElement('media-image');
                $replacement->setAttribute('source', $staged['sitePath']);
                $replacement->setAttribute('alt', $image->getAttribute('alt'));
                $image->parentNode->replaceChild($replacement, $image);
            }
            else {
                $image->setAttribute('src', $absolute);
            }
        }
    }

    /** Normalize an archive-relative image src ("../resources/<name>") to a path. */
    function haxcmsImportOpenstaxResourcePath($source)
    {
        $value = trim((string) $source);
        if ($value === '' || preg_match('#^(https?:)?//#i', $value) || strpos($value, 'data:') === 0) {
            return '';
        }
        return preg_match('#resources/([A-Za-z0-9._-]+)$#', $value, $match) ? 'resources/' . $match[1] : '';
    }

    /**
     * Fetch one image into <configDirectory>/tmp/imports and record it in the
     * files map. Returns null when it cannot be stored, so the caller can fall
     * back to the source URL.
     */
    function haxcmsImportOpenstaxDownloadImage($url, $resource, &$run)
    {
        if ($run['imageCount'] >= $run['limits']['maxImages']) {
            return null;
        }
        try {
            $response = SsrfGuard::safeGuzzleRequest($run['client'], 'GET', $url, array('http_errors' => false));
        }
        catch (\Exception $e) {
            return null;
        }
        $status = (int) $response->getStatusCode();
        if ($status < 200 || $status > 299) {
            return null;
        }
        $parts = explode(';', $response->getHeaderLine('Content-Type'));
        $contentType = strtolower(trim($parts[0]));
        $types = unserialize(HAXCMS_OPENSTAX_EXTENSION_BY_CONTENT_TYPE);
        // archive resources are extension-less hashes, so the type names the file
        if (!isset($types[$contentType])) {
            return null;
        }
        $extension = $types[$contentType];
        $bytes = (string) $response->getBody();
        // stop before writing anything that would push the import past the budget
        if ($bytes === '' || $run['imageBytes'] + strlen($bytes) > $run['limits']['maxImageBytes']) {
            return null;
        }
        // staged flat in the bulk-import root, like convertHaxcmsToSite, so the
        // moves createSite makes on ingest leave nothing behind
        if ($run['stagingRoot'] === null) {
            $root = haxcms_import_get_staging_root();
            if ($root === false) {
                return null;
            }
            haxcms_import_sweep_staging_root($root);
            $run['stagingRoot'] = $root;
        }
        $name = basename($resource) . '.' . $extension;
        // haxbi_ keeps the stale-file sweep in stageRemoteFile.php in charge of it
        $stagedPath = $run['stagingRoot'] . '/haxbi_openstax_' . $run['importId'] . '_' . $name;
        if (@file_put_contents($stagedPath, $bytes) === false) {
            @unlink($stagedPath);
            return null;
        }
        $sitePath = 'files/' . $name;
        $run['files'][$sitePath] = $stagedPath;
        $run['imageCount']++;
        $run['imageBytes'] += strlen($bytes);
        return array('sitePath' => $sitePath, 'stagedPath' => $stagedPath);
    }

    /**
     * Point in-book links at the imported pages and make every other relative
     * link absolute, so nothing resolves against the new site by accident.
     */
    function haxcmsImportOpenstaxRewriteLinks($xpath, $container, $run)
    {
        foreach (iterator_to_array($xpath->query('.//a', $container)) as $link) {
            $value = trim($link->getAttribute('href'));
            if ($value === '' || $value[0] === '#' || preg_match('/^[a-z][a-z0-9+.-]*:/i', $value)) {
                continue;
            }
            // in-book links carry the target page id, with an optional fragment
            $pageSlug = null;
            if (preg_match('#/contents/([0-9a-f-]{36})#i', $value, $target)) {
                $key = strtolower($target[1]);
                $pageSlug = isset($run['slugByPageId'][$key]) ? $run['slugByPageId'][$key] : null;
            }
            if ($pageSlug) {
                $hash = strpos($value, '#');
                $link->setAttribute('href', $pageSlug . ($hash === false ? '' : substr($value, $hash)));
                continue;
            }
            $resolved = SsrfGuard::resolveRedirectUrl($run['book']['archiveUrl'] . '/', $value);
            if ($resolved === false) {
                $link->removeAttribute('href');
            }
            else {
                $link->setAttribute('href', $resolved);
            }
        }
    }

    /**
     * Drop the OpenStax styling layer (os-* classes, fs-id ids, data-type
     * hooks, inline styles) while keeping the attributes that carry meaning.
     * MathML is left untouched: its attributes are part of the notation.
     */
    function haxcmsImportOpenstaxStripPresentationAttributes($xpath, $container)
    {
        $keepByTag = unserialize(HAXCMS_OPENSTAX_KEEP_ATTRIBUTES);
        foreach (iterator_to_array($xpath->query('.//*', $container)) as $element) {
            $tag = strtolower($element->nodeName);
            if ($tag === '' || haxcmsImportOpenstaxInsideMath($element)) {
                continue;
            }
            $keep = isset($keepByTag[$tag]) ? $keepByTag[$tag] : array();
            $names = array();
            foreach ($element->attributes as $attribute) {
                $names[] = $attribute->nodeName;
            }
            foreach ($names as $name) {
                if (!in_array(strtolower($name), $keep, true)) {
                    $element->removeAttribute($name);
                }
            }
        }
    }

    function haxcmsImportOpenstaxInsideMath($node)
    {
        for ($parent = $node->parentNode; $parent; $parent = $parent->parentNode) {
            if (strtolower($parent->nodeName) === 'math') {
                return true;
            }
        }
        return false;
    }
}
