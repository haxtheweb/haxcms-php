<?php
require_once dirname(__FILE__) . '/../../../vendor/autoload.php';
// Security (SEC-01): SsrfGuard used by fetchCoverData to validate the cover
// image URL before fetching, blocking SSRF to internal/metadata addresses.
include_once dirname(__FILE__) . '/../../SsrfGuard.php';
// Security (SEC-14): SanitizeContent used by sanitizeExportHtml for DOM-based
// HTML sanitization in the export path.
include_once dirname(__FILE__) . '/../../SanitizeContent.php';

class ExportConverters
{
    const ITEM_EXPORT_FORMATS = array('pdf', 'docx', 'html', 'md', 'json', 'yaml', 'xml', 'epub');

    public static function getItemExportFormats()
    {
        return self::ITEM_EXPORT_FORMATS;
    }

    public static function sanitizeDownloadFileName($value = '', $fallback = 'export')
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9._-]+/', '-', (string) $value);
        $sanitized = preg_replace('/-+/', '-', $sanitized);
        $sanitized = preg_replace('/^-+/', '', $sanitized);
        $sanitized = preg_replace('/-+$/', '', $sanitized);
        if ($sanitized != '') {
            return $sanitized;
        }
        return $fallback;
    }

    public static function getSiteExportFileBaseName($site)
    {
        if (
            isset($site) &&
            isset($site->manifest) &&
            isset($site->manifest->metadata) &&
            isset($site->manifest->metadata->site) &&
            isset($site->manifest->metadata->site->name) &&
            $site->manifest->metadata->site->name != ''
        ) {
            return self::sanitizeDownloadFileName($site->manifest->metadata->site->name, 'site');
        }
        if (isset($site) && isset($site->manifest) && isset($site->manifest->title) && $site->manifest->title != '') {
            return self::sanitizeDownloadFileName($site->manifest->title, 'site');
        }
        if (isset($site) && isset($site->name) && $site->name != '') {
            return self::sanitizeDownloadFileName($site->name, 'site');
        }
        return 'site';
    }

    public static function getItemExportFileBaseName($item)
    {
        if (isset($item) && isset($item->slug) && $item->slug != '') {
            return self::sanitizeDownloadFileName($item->slug, 'item');
        }
        if (isset($item) && isset($item->title) && $item->title != '') {
            return self::sanitizeDownloadFileName($item->title, 'item');
        }
        if (isset($item) && isset($item->id) && $item->id != '') {
            return self::sanitizeDownloadFileName($item->id, 'item');
        }
        return 'item';
    }

    public static function buildSiteExportDocumentTitle($site)
    {
        if (isset($site) && isset($site->manifest) && isset($site->manifest->title) && $site->manifest->title != '') {
            return (string) $site->manifest->title;
        }
        if (isset($site) && isset($site->name) && $site->name != '') {
            return (string) $site->name;
        }
        return 'Site export';
    }

    public static function buildItemExportDocumentTitle($item)
    {
        if (isset($item) && isset($item->title) && $item->title != '') {
            return (string) $item->title;
        }
        if (isset($item) && isset($item->slug) && $item->slug != '') {
            return (string) $item->slug;
        }
        if (isset($item) && isset($item->id) && $item->id != '') {
            return (string) $item->id;
        }
        return 'Item export';
    }

    public static function escapeHtmlValue($value = '')
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function sanitizeExportHtml($html = '')
    {
        // Security (SEC-14): use the DOM-based SanitizeContent sanitizer instead
        // of bypassable regex stripping (the content is already sanitized on
        // write; this is defense-in-depth for the export path).
        $sanitized = SanitizeContent::sanitizeHTMLForStorage((string) $html);
        $sanitized = trim($sanitized);
        if ($sanitized == '') {
            $sanitized = '<p>No content available</p>';
        }
        return $sanitized;
    }

    public static function resolveExportMediaType($format = 'pdf')
    {
        $map = array(
            'pdf' => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'html' => 'text/html; charset=utf-8',
            'md' => 'text/markdown; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'yaml' => 'application/yaml; charset=utf-8',
            'xml' => 'application/xml; charset=utf-8',
            'epub' => 'application/epub+zip',
        );
        $key = strtolower(trim((string) $format));
        if (array_key_exists($key, $map)) {
            return $map[$key];
        }
        return 'application/octet-stream';
    }

    public static function sendFileDownload($content, $mediaType, $filename)
    {
        $safeMediaType = (string) $mediaType;
        $safeFilename = str_replace('"', '', (string) $filename);
        $buffer = is_string($content) ? $content : (string) $content;
        http_response_code(200);
        header('Content-Type: ' . $safeMediaType);
        header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
        header('Content-Length: ' . strlen($buffer));
        print $buffer;
        return;
    }

    public static function buildItemExportHtml($item, $content = '')
    {
        $itemTitle = self::buildItemExportDocumentTitle($item);
        $itemContent = (string) $content;
        $parts = array();
        $parts[] = '<!doctype html>';
        $parts[] = '<html>';
        $parts[] = '<head>';
        $parts[] = '<meta charset="utf-8" />';
        $parts[] = '<title>' . self::escapeHtmlValue($itemTitle) . '</title>';
        $parts[] = '</head>';
        $parts[] = '<body>';
        $parts[] = '<article data-haxcms-export="item" data-item-id="' . self::escapeHtmlValue(isset($item->id) ? (string) $item->id : '') . '" data-item-slug="' . self::escapeHtmlValue(isset($item->slug) ? (string) $item->slug : '') . '">';
        $parts[] = '<h1>' . self::escapeHtmlValue($itemTitle) . '</h1>';
        $parts[] = $itemContent;
        $parts[] = '</article>';
        $parts[] = '</body>';
        $parts[] = '</html>';
        return implode("\n", $parts);
    }

    public static function buildSiteExportHtml($site, $ancestor = '', $magic = '')
    {
        $orderedItems = SiteRouteUtils::getOrderedItems($site);
        $siteTitle = self::buildSiteExportDocumentTitle($site);

        $itemsToExport = $orderedItems;
        if (
            $ancestor != '' &&
            isset($site) &&
            isset($site->manifest) &&
            method_exists($site->manifest, 'findBranch')
        ) {
            try {
                $branch = $site->manifest->findBranch($ancestor);
                if (is_array($branch)) {
                    $branchIds = array();
                    foreach ($branch as $branchItem) {
                        if (isset($branchItem->id)) {
                            $branchIds[(string) $branchItem->id] = true;
                        }
                    }
                    $itemsToExport = array_values(array_filter($orderedItems, function ($item) use ($branchIds) {
                        return isset($item->id) && array_key_exists((string) $item->id, $branchIds);
                    }));
                }
            }
            catch (Exception $e) {
            }
        }

        if ($magic != '') {
            $content = self::buildSiteExportHtmlContent($site, $ancestor);
            $parts = array();
            $parts[] = '<!DOCTYPE html>';
            $parts[] = '<html lang="en">';
            $parts[] = '<head>';
            $parts[] = '<meta charset="utf-8">';
            $parts[] = '<link rel="preconnect" crossorigin href="' . self::escapeHtmlValue($magic) . '">';
            $parts[] = '<link rel="preconnect" crossorigin href="https://fonts.googleapis.com">';
            $parts[] = '<link rel="preload" href="' . self::escapeHtmlValue($magic) . 'build.js" as="script" />';
            $parts[] = '<link rel="preload" href="' . self::escapeHtmlValue($magic) . 'wc-registry.json" as="fetch" crossorigin="anonymous" />';
            $parts[] = '<link rel="preload" href="' . self::escapeHtmlValue($magic) . 'build/es6/node_modules/@haxtheweb/dynamic-import-registry/dynamic-import-registry.js" as="script" crossorigin="anonymous" />';
            $parts[] = '<link rel="modulepreload" href="' . self::escapeHtmlValue($magic) . 'build/es6/node_modules/@haxtheweb/dynamic-import-registry/dynamic-import-registry.js" />';
            $parts[] = '<link rel="preload" href="' . self::escapeHtmlValue($magic) . 'build/es6/node_modules/@haxtheweb/wc-autoload/wc-autoload.js" as="script" crossorigin="anonymous" />';
            $parts[] = '<link rel="modulepreload" href="' . self::escapeHtmlValue($magic) . 'build/es6/node_modules/@haxtheweb/wc-autoload/wc-autoload.js" />';
            $parts[] = '<link rel="stylesheet" href="' . self::escapeHtmlValue($magic) . 'build/es6/node_modules/@haxtheweb/haxcms-elements/lib/base.css" />';
            $parts[] = '<meta name="viewport" content="width=device-width, minimum-scale=1, initial-scale=1, user-scalable=yes">';
            $parts[] = '</head>';
            $parts[] = '<body>';
            $parts[] = '<haxcms-print-theme>';
            $parts[] = $content;
            $parts[] = '</haxcms-print-theme>';
            $parts[] = '</body>';
            $parts[] = '<script>window.__appCDN="' . self::escapeHtmlValue($magic) . '";</script>';
            $parts[] = '<script src="' . self::escapeHtmlValue($magic) . 'build.js"></script>';
            $parts[] = '</html>';
            return implode("\n", $parts);
        }

        $parts = array();
        $parts[] = '<!doctype html>';
        $parts[] = '<html>';
        $parts[] = '<head>';
        $parts[] = '<meta charset="utf-8" />';
        $parts[] = '<title>' . self::escapeHtmlValue($siteTitle) . '</title>';
        $parts[] = '</head>';
        $parts[] = '<body>';
        $parts[] = '<main data-haxcms-export="site" data-title="' . self::escapeHtmlValue($siteTitle) . '">';
        $parts[] = '<h1>' . self::escapeHtmlValue($siteTitle) . '</h1>';
        foreach ($itemsToExport as $item) {
            if (!$item) {
                continue;
            }
            $itemTitle = self::buildItemExportDocumentTitle($item);
            $itemContent = SiteRouteUtils::getItemContent($site, $item);
            $parts[] = '<article data-item-id="' . self::escapeHtmlValue(isset($item->id) ? (string) $item->id : '') . '" data-item-slug="' . self::escapeHtmlValue(isset($item->slug) ? (string) $item->slug : '') . '">';
            $parts[] = '<h2>' . self::escapeHtmlValue($itemTitle) . '</h2>';
            $parts[] = (string) $itemContent;
            $parts[] = '</article>';
        }
        $parts[] = '</main>';
        $parts[] = '</body>';
        $parts[] = '</html>';
        return implode("\n", $parts);
    }

    public static function buildSiteExportHtmlContent($site, $ancestor = '')
    {
        $orderedItems = SiteRouteUtils::getOrderedItems($site);
        $parts = array();
        $parts[] = '<h1>' . self::escapeHtmlValue(self::buildSiteExportDocumentTitle($site)) . '</h1>';

        $itemsToExport = $orderedItems;
        if (
            $ancestor != '' &&
            isset($site) &&
            isset($site->manifest) &&
            method_exists($site->manifest, 'findBranch')
        ) {
            try {
                $branch = $site->manifest->findBranch($ancestor);
                if (is_array($branch)) {
                    $branchIds = array();
                    foreach ($branch as $branchItem) {
                        if (isset($branchItem->id)) {
                            $branchIds[(string) $branchItem->id] = true;
                        }
                    }
                    $itemsToExport = array_values(array_filter($orderedItems, function ($item) use ($branchIds) {
                        return isset($item->id) && array_key_exists((string) $item->id, $branchIds);
                    }));
                }
            }
            catch (Exception $e) {
            }
        }

        foreach ($itemsToExport as $item) {
            if (!$item) {
                continue;
            }
            $itemContent = SiteRouteUtils::getItemContent($site, $item);
            $parts[] = '<div data-jos-item-id="' . self::escapeHtmlValue(isset($item->id) ? (string) $item->id : '') . '">';
            $parts[] = (string) $itemContent;
            $parts[] = '</div>';
        }
        return implode("\n", $parts);
    }

    public static function htmlToPdfString($html = '', $base = '/', $site = null)
    {
        $sanitized = self::sanitizeExportHtml($html);
        $tempDir = sys_get_temp_dir();
        if (!is_dir($tempDir) || !is_writable($tempDir)) {
            throw new Exception('PDF export requires a writable temp directory');
        }
        $normalizedBase = '/';
        if ($base != '') {
            $normalizedBase = SiteRouteUtils::normalizeBasePath($base);
        }
        $siteDirectory = '';
        if ($site !== null) {
            $siteDirectory = SiteRouteUtils::getSiteDirectory($site);
        }
        $chrootPaths = array();
        if ($siteDirectory != '' && is_dir($siteDirectory)) {
            $realSiteDir = realpath($siteDirectory);
            if (is_string($realSiteDir) && $realSiteDir !== '') {
                // Convert HAX media components (media-image, simple-img) into
                // real <img> elements so Dompdf can see them — same
                // normalization EPUB and DOCX exports already use.
                $normalized = self::normalizeHtmlForDocumentExport($sanitized, $normalizedBase, array(), 'pdf');
                // Resolve each img src to an absolute filesystem path under the
                // site directory so Dompdf can read the file via the file://
                // protocol. Without this, Dompdf treats the URL-space base path
                // (e.g. /aa121/) as a filesystem path via realpath(), which
                // returns false and triggers "Image not found or type unknown".
                $sanitized = self::resolvePdfImageSources($normalized, $normalizedBase, $realSiteDir);
                $chrootPaths[] = $realSiteDir;
            }
        }
        // Dompdf produces a blank, empty-page PDF when handed an HTML fragment
        // with no <head> element (sanitizeExportHtml strips the document
        // wrapper, leaving a bare fragment). Always ensure a <head> exists so
        // the body content renders. Include a <base> tag when a non-root base
        // path is available so relative URLs resolve.
        $baseTag = '';
        if ($normalizedBase != '' && $normalizedBase != '/') {
            $baseTag = '<base href="' . htmlspecialchars($normalizedBase, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" />';
        }
        if (stripos($sanitized, '<head>') !== false) {
            if ($baseTag != '') {
                $sanitized = preg_replace('/<head>/i', '<head>' . $baseTag, $sanitized, 1);
            }
        }
        else {
            $sanitized = '<head>' . $baseTag . '<meta charset="utf-8" /></head>' . $sanitized;
        }
        $options = array(
            'isRemoteEnabled' => false,
            'defaultFont' => 'serif',
            'tempDir' => $tempDir,
        );
        if (count($chrootPaths) > 0) {
            // Allow Dompdf to read local image files under the site directory.
            // Options::validateLocalUri always appends the Dompdf rootDir to
            // the chroot check, so bundled fonts still load.
            $options['chroot'] = $chrootPaths;
        }
        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->set_option('isHtml5ParserEnabled', true);
        $dompdf->loadHtml($sanitized);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $output = $dompdf->output();
        if (!is_string($output) || $output == '') {
            throw new Exception('PDF export conversion returned empty output');
        }
        return $output;
    }

    /**
     * Rewrite <img> src attributes in the given HTML fragment to absolute
     * filesystem paths under the site directory so Dompdf can load them via
     * the file:// protocol. Data URIs and remote URLs are left untouched.
     *
     * @param string $html
     * @param string $basePath
     * @param string $siteDirectory Realpath-resolved site directory
     * @return string
     */
    public static function resolvePdfImageSources($html, $basePath, $siteDirectory)
    {
        if ((string) $html == '') {
            return '';
        }
        if (!is_string($siteDirectory) || $siteDirectory == '' || !is_dir($siteDirectory)) {
            return (string) $html;
        }
        $realSiteDir = realpath($siteDirectory);
        if (!is_string($realSiteDir) || $realSiteDir === '') {
            return (string) $html;
        }
        $normalizedBase = SiteRouteUtils::normalizeBasePath($basePath);
        $html5 = new \Masterminds\HTML5();
        $doc = $html5->loadHTML('<div id="haxcms-pdf-wrapper">' . (string) $html . '</div>');
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('x', 'http://www.w3.org/1999/xhtml');
        $images = $xpath->query("//x:img[@src]");
        foreach ($images as $img) {
            $src = trim((string) $img->getAttribute('src'));
            if ($src == '') {
                continue;
            }
            // Leave data URIs, remote URLs, and protocol-relative URLs untouched.
            if (preg_match('/^(data:|https?:\/\/|\/\/)/i', $src)) {
                continue;
            }
            $resolved = self::resolveLocalImageFile($src, $normalizedBase, $realSiteDir);
            if ($resolved !== '') {
                $img->setAttribute('src', $resolved);
            }
        }
        $wrapper = $doc->getElementById('haxcms-pdf-wrapper');
        if ($wrapper) {
            $inner = '';
            foreach ($wrapper->childNodes as $child) {
                $inner .= $doc->saveXML($child);
            }
            return $inner;
        }
        return (string) $html;
    }

    /**
     * Resolve a relative or site-relative image URL to an absolute filesystem
     * path under the site directory. Returns the realpath or an empty string
     * if the file cannot be found, is unreadable, or escapes the site dir.
     *
     * @param string $src
     * @param string $normalizedBase
     * @param string $realSiteDir
     * @return string
     */
    public static function resolveLocalImageFile($src, $normalizedBase, $realSiteDir)
    {
        $value = trim((string) $src);
        if ($value == '' || strpos($value, chr(0)) !== false) {
            return '';
        }
        // Strip the base path prefix (e.g. /aa121/ → files/x.png).
        $relative = $value;
        if ($normalizedBase != '/' && strpos($relative, $normalizedBase) === 0) {
            $relative = substr($relative, strlen($normalizedBase));
        }
        $relative = ltrim($relative, '/');
        if ($relative == '' || strpos($relative, '..') !== false) {
            return '';
        }
        // Try the site root first, then the files/ subdirectory where
        // HAXcms uploads are stored.
        $candidates = array(
            $realSiteDir . '/' . $relative,
            $realSiteDir . '/files/' . $relative,
        );
        foreach ($candidates as $candidate) {
            $realCandidate = realpath($candidate);
            if ($realCandidate === false || !is_file($realCandidate) || !is_readable($realCandidate)) {
                continue;
            }
            if (strpos($realCandidate, $realSiteDir . '/') !== 0 && $realCandidate !== $realSiteDir) {
                continue;
            }
            return $realCandidate;
        }
        return '';
    }

    public static function extractBodyInnerHtml($html)
    {
        $raw = (string) $html;
        if ($raw == '') {
            return '';
        }
        try {
            $html5 = new \Masterminds\HTML5();
            $doc = $html5->loadHTML($raw);
            $body = $doc->getElementsByTagName('body')->item(0);
            if ($body) {
                $inner = '';
                foreach ($body->childNodes as $child) {
                    $inner .= $doc->saveHTML($child);
                }
                if (trim($inner) != '') {
                    return $inner;
                }
            }
        }
        catch (Exception $e) {
            // fall through and return the raw html unchanged
        }
        return $raw;
    }

    public static function htmlToDocxString($html = '')
    {
        // PhpWord's HTML parser expects a fragment, not a full HTML document.
        // buildItemExportHtml/buildSiteExportHtml produce full documents, so
        // extract the <body> contents first to avoid parser warnings/errors on
        // <html>/<head>/<meta>/<title>.
        $bodyHtml = self::extractBodyInnerHtml($html);
        $normalized = self::normalizeHtmlForDocumentExport($bodyHtml, '/', array(), 'docx');
        $tempDir = sys_get_temp_dir();
        if (!is_dir($tempDir) || !is_writable($tempDir)) {
            throw new Exception('DOCX export requires a writable temp directory');
        }
        $docxContent = self::renderDocxFromHtml($normalized, $tempDir);
        if ($docxContent !== null) {
            return $docxContent;
        }
        // Fallback: the source HTML could not be converted, so emit a clean
        // notice document instead of a corrupt binary.
        $fallbackHtml = '<div><h1>Document Export</h1><p>The original document could not be fully converted. Please try exporting individual pages instead of the entire site.</p></div>';
        $fallbackContent = self::renderDocxFromHtml($fallbackHtml, $tempDir);
        if ($fallbackContent !== null) {
            return $fallbackContent;
        }
        throw new Exception('Unable to complete DOCX export conversion');
    }

    /**
     * Convert sanitized HTML into a Word2007 DOCX binary string.
     *
     * PhpWord's Html::addHtml emits E_WARNING messages on some constructs
     * (unknown elements, edge-case attributes). With display_errors enabled
     * those warnings would print to stdout and corrupt the binary DOCX stream,
     * so a local error handler suppresses them and an output buffer discards
     * any stray output. Returns null when conversion fails so callers can fall
     * back to a simpler document.
     */
    public static function renderDocxFromHtml($html, $tempDir)
    {
        $warnings = array();
        $previousHandler = set_error_handler(function ($severity, $message) use (&$warnings) {
            $warnings[] = $message;
            return true;
        }, E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE);
        ob_start();
        try {
            $phpWord = new \PhpOffice\PhpWord\PhpWord();
            $section = $phpWord->addSection();
            // $html here is an HTML fragment (no <html>/<body> wrapper), so
            // fullHTML must be false. PhpWord's Html::addHtml() wraps fragments
            // in a synthetic <body> tag itself when fullHTML is false; passing
            // true against a fragment left it looking for a <body> element that
            // was never present, so parseNode() received null and silently
            // produced an empty document (the bug behind blank DOCX exports).
            \PhpOffice\PhpWord\Shared\Html::addHtml($section, (string) $html, false);
            $tmpDocx = tempnam($tempDir, 'haxcms_docx_') . '.docx';
            $objWriter = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
            $objWriter->save($tmpDocx);
            $docxContent = @file_get_contents($tmpDocx);
            @unlink($tmpDocx);
            if ($docxContent === false || $docxContent == '') {
                return null;
            }
            return $docxContent;
        }
        catch (Throwable $e) {
            return null;
        }
        finally {
            ob_end_clean();
            restore_error_handler();
        }
    }

    public static function htmlToMarkdown($html = '')
    {
        $body = (string) $html;
        try {
            $converter = new \League\HTMLToMarkdown\HtmlConverter(array(
                'strip_tags' => false,
                'header_style' => 'atx',
                'hard_break' => false,
                'bold_style' => '**',
                'italic_style' => '_',
                'list_item_style' => '-',
            ));
            $markdown = $converter->convert($body);
            return $markdown;
        }
        catch (Exception $e) {
            return $body;
        }
    }

    public static function convertItemHtmlToMarkdown($item, $html)
    {
        $title = self::buildItemExportDocumentTitle($item);
        $markdown = self::htmlToMarkdown((string) $html);
        $parts = array();
        $parts[] = '# ' . $title;
        $parts[] = '';
        $parts[] = $markdown;
        return trim(implode("\n", $parts));
    }

    public static function resolveUrlForEpub($attributeValue, $basePath)
    {
        $value = trim((string) $attributeValue);
        if ($value == '') {
            return '';
        }
        if (preg_match('/^(https?:)?\/\//i', $value)) {
            return $value;
        }
        $normalizedBase = SiteRouteUtils::normalizeBasePath($basePath);
        if (substr($value, 0, 1) == '/') {
            return $value;
        }
        return $normalizedBase . $value;
    }

    public static function normalizeHtmlForDocumentExport($html = '', $basePath = '/', $items = array(), $mode = 'epub')
    {
        if ((string) $html == '') {
            return '';
        }
        $html5 = new \Masterminds\HTML5();
        $doc = $html5->loadHTML('<div id="haxcms-export-wrapper">' . (string) $html . '</div>');

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('x', 'http://www.w3.org/1999/xhtml');
        $xhtmlNs = 'http://www.w3.org/1999/xhtml';
        $videos = $xpath->query("//x:video-player | //x:a11y-media-player | //x:iframe[contains(@src,'youtube.com') or contains(@src,'youtube-nocookie.com') or contains(@src,'vimeo.com')] | //x:video[@src] | //x:video/x:source[@src]");
        foreach ($videos as $el) {
            $source = $el->hasAttribute('source') ? $el->getAttribute('source') : '';
            $src = $el->hasAttribute('src') ? $el->getAttribute('src') : '';
            $videoUrl = '';
            if ($source != '') {
                $videoUrl = self::resolveUrlForEpub($source, $basePath);
            }
            else if ($src != '') {
                $videoUrl = self::resolveUrlForEpub($src, $basePath);
            }

            if ($videoUrl != '') {
                $videoId = '';
                $parsedUrl = parse_url($videoUrl);
                $hostname = isset($parsedUrl['host']) ? strtolower($parsedUrl['host']) : '';
                if (
                    $hostname == 'www.youtube.com' ||
                    $hostname == 'youtube.com' ||
                    $hostname == 'www.youtube-nocookie.com'
                ) {
                    if (isset($parsedUrl['query'])) {
                        parse_str($parsedUrl['query'], $queryParams);
                        if (isset($queryParams['v']) && $queryParams['v'] != '') {
                            $videoId = $queryParams['v'];
                        }
                    }
                    if ($videoId == '' && isset($parsedUrl['path']) && strpos($parsedUrl['path'], '/embed/') === 0) {
                        $videoId = substr($parsedUrl['path'], 7);
                    }
                    if ($videoId != '') {
                        $videoId = 'https://www.youtube-nocookie.com/embed/' . $videoId;
                    }
                }
                else if ($hostname == 'youtu.be') {
                    $videoId = 'https://www.youtube-nocookie.com/embed/' . ltrim($parsedUrl['path'], '/');
                }
                else {
                    $videoId = $videoUrl;
                }

                if ($videoId != '') {
                    $embed = $doc->createDocumentFragment();
                    $embed->appendXML(
                        '<div xmlns="' . $xhtmlNs . '" class="responsive-iframe-container"><iframe class="responsive-iframe" width="100%" height="100%" frameborder="0" src="' . self::escapeHtmlValue($videoId) . '" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen="allowfullscreen"></iframe></div>'
                    );
                    $el->parentNode->replaceChild($embed, $el);
                }
                else {
                    $el->parentNode->removeChild($el);
                }
            }
            else {
                $el->parentNode->removeChild($el);
            }
        }

        $images = $xpath->query("//x:media-image | //x:simple-img | //x:img");
        foreach ($images as $el) {
            $source = $el->hasAttribute('source') ? $el->getAttribute('source') : '';
            $src = $el->hasAttribute('src') ? $el->getAttribute('src') : '';
            $imageUrl = '';
            if ($source != '') {
                $imageUrl = self::resolveUrlForEpub($source, $basePath);
            }
            else if ($src != '') {
                $imageUrl = self::resolveUrlForEpub($src, $basePath);
            }

            if ($imageUrl != '') {
                $alt = $el->hasAttribute('alt') ? $el->getAttribute('alt') : '';
                $img = $doc->createElementNS($xhtmlNs, 'img');
                $img->setAttribute('src', $imageUrl);
                $img->setAttribute('alt', $alt);
                $el->parentNode->replaceChild($img, $el);
            }
            else {
                $el->parentNode->removeChild($el);
            }
        }

        $tables = $xpath->query("//x:table | //x:tr | //x:td | //x:th");
        foreach ($tables as $el) {
            if ($el->hasAttribute('style')) {
                $el->removeAttribute('style');
            }
        }

        $slugSet = array();
        foreach ($items as $item) {
            if (isset($item->slug) && $item->slug != '') {
                $slugSet[(string) $item->slug] = true;
            }
        }

        $links = $xpath->query("//x:a");
        foreach ($links as $el) {
            if (!$el->hasAttribute('href')) {
                $el->parentNode->removeChild($el);
                continue;
            }
            $href = $el->getAttribute('href');
            $value = trim((string) $href);
            if ($value == '') {
                $el->parentNode->removeChild($el);
                continue;
            }
            if (strtolower($mode) == 'epub') {
                $parsedHref = parse_url($value);
                if (isset($parsedHref['scheme'])) {
                    // absolute external link - keep as-is
                    continue;
                }
                $pathname = isset($parsedHref['path']) ? ltrim($parsedHref['path'], '/') : '';
                $query = isset($parsedHref['query']) ? $parsedHref['query'] : '';
                if ($pathname != '') {
                    if (array_key_exists($pathname, $slugSet)) {
                        $el->setAttribute('href', str_replace('/', '-', $pathname) . '.xhtml');
                    }
                }
                else if ($query != '') {
                    parse_str($query, $queryParams);
                    if (isset($queryParams['q']) && array_key_exists((string) $queryParams['q'], $slugSet)) {
                        $el->setAttribute('href', str_replace('/', '-', (string) $queryParams['q']) . '.xhtml');
                    }
                }
            }
        }

        $wrapper = $doc->getElementById('haxcms-export-wrapper');
        if ($wrapper) {
            $inner = '';
            foreach ($wrapper->childNodes as $child) {
                $inner .= $doc->saveXML($child);
            }
            return $inner;
        }
        return (string) $html;
    }

    public static function buildEpubZipString($bookMeta, $chapters, $css = '')
    {
        $title = isset($bookMeta['title']) ? (string) $bookMeta['title'] : '';
        $author = isset($bookMeta['author']) ? (string) $bookMeta['author'] : 'HAX The Web';
        $publisher = isset($bookMeta['publisher']) ? (string) $bookMeta['publisher'] : 'HAX The Web';
        $description = isset($bookMeta['description']) ? (string) $bookMeta['description'] : '';
        $lang = isset($bookMeta['lang']) ? (string) $bookMeta['lang'] : 'en';
        $date = isset($bookMeta['date']) ? (string) $bookMeta['date'] : gmdate('c');
        $identifier = isset($bookMeta['identifier']) ? (string) $bookMeta['identifier'] : 'urn:uuid:' . self::generateUUID();
        $coverPath = isset($bookMeta['coverPath']) ? (string) $bookMeta['coverPath'] : '';
        $basePath = isset($bookMeta['basePath']) ? (string) $bookMeta['basePath'] : '/';
        $siteDirectory = isset($bookMeta['siteDirectory']) ? (string) $bookMeta['siteDirectory'] : '';

        $css = $css != '' ? $css : self::defaultEpubCss();

        $tempDir = sys_get_temp_dir();
        if (!is_dir($tempDir) || !is_writable($tempDir)) {
            throw new Exception('EPUB export requires a writable temp directory');
        }
        $tmpEpub = tempnam($tempDir, 'haxcms_epub_') . '.epub';
        $zip = new ZipArchive();
        if ($zip->open($tmpEpub, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception('Unable to create EPUB archive');
        }

        $zip->addFromString('mimetype', 'application/epub+zip');
        $zip->setCompressionIndex(0, ZipArchive::CM_STORE);
        $zip->addFromString('META-INF/container.xml', '<?xml version="1.0" encoding="UTF-8"?><container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>');

        $coverId = '';
        $coverMediaType = '';
        $coverItemXml = '';
        if ($coverPath != '') {
            $coverData = self::fetchCoverData($coverPath, $basePath, $siteDirectory);
            if ($coverData != null && $coverData['data'] != '') {
                $ext = strtolower(pathinfo($coverData['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, array('png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'), true)) {
                    $ext = 'jpg';
                }
                $coverName = 'cover.' . $ext;
                $coverId = 'cover-image';
                $coverMediaType = self::extensionToMimeType($ext, 'image/jpeg');
                $zip->addFromString('OEBPS/' . $coverName, $coverData['data']);
                $coverItemXml = '<item id="' . $coverId . '" href="' . $coverName . '" media-type="' . $coverMediaType . '"/>';
            }
        }

        $manifestItems = '';
        $spineItems = '';
        $navItems = '';
        $ncxItems = '';
        foreach ($chapters as $index => $chapter) {
            $chapterTitle = isset($chapter['title']) ? (string) $chapter['title'] : 'Chapter ' . ($index + 1);
            $chapterFilename = isset($chapter['filename']) ? (string) $chapter['filename'] : ('chapter-' . ($index + 1) . '.xhtml');
            $chapterId = 'chapter-' . ($index + 1);
            $chapterContent = isset($chapter['xhtmlContent']) ? (string) $chapter['xhtmlContent'] : '';
            $chapterXhtml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
                '<!DOCTYPE html>' . "\n" .
                '<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="' . $lang . '" lang="' . $lang . '">' . "\n" .
                '<head>' . "\n" .
                '<meta charset="UTF-8" />' . "\n" .
                '<title>' . htmlspecialchars($chapterTitle, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8') . '</title>' . "\n" .
                '<link rel="stylesheet" type="text/css" href="styles.css" />' . "\n" .
                '</head>' . "\n" .
                '<body>' . "\n" .
                '<section>' . "\n" .
                '<h1>' . htmlspecialchars($chapterTitle, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8') . '</h1>' . "\n" .
                $chapterContent . "\n" .
                '</section>' . "\n" .
                '</body>' . "\n" .
                '</html>';
            $zip->addFromString('OEBPS/' . $chapterFilename, $chapterXhtml);
            $manifestItems .= '<item id="' . $chapterId . '" href="' . $chapterFilename . '" media-type="application/xhtml+xml"/>';
            $spineItems .= '<itemref idref="' . $chapterId . '"/>';
            $navItems .= '<li><a href="' . $chapterFilename . '">' . htmlspecialchars($chapterTitle, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8') . '</a></li>';
            $ncxItems .= '<navPoint id="' . $chapterId . '" playOrder="' . ($index + 1) . '"><navLabel><text>' . htmlspecialchars($chapterTitle, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8') . '</text></navLabel><content src="' . $chapterFilename . '"/></navPoint>';
        }

        $zip->addFromString('OEBPS/styles.css', $css);
        $manifestItems .= '<item id="styles" href="styles.css" media-type="text/css"/>';
        if ($coverItemXml != '') {
            $manifestItems .= $coverItemXml;
        }
        $coverMeta = $coverItemXml != '' ? '<meta name="cover" content="cover-image"/>' : '';

        $nav = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
            '<!DOCTYPE html>' . "\n" .
            '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" xml:lang="' . $lang . '" lang="' . $lang . '">' . "\n" .
            '<head>' . "\n" .
            '<meta charset="UTF-8" />' . "\n" .
            '<title>' . htmlspecialchars($title, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8') . '</title>' . "\n" .
            '<link rel="stylesheet" type="text/css" href="styles.css" />' . "\n" .
            '</head>' . "\n" .
            '<body>' . "\n" .
            '<nav epub:type="toc" id="toc">' . "\n" .
            '<h1>Table of Contents</h1>' . "\n" .
            '<ol>' . "\n" . $navItems . "\n" . '</ol>' . "\n" .
            '</nav>' . "\n" .
            '</body>' . "\n" .
            '</html>';
        $zip->addFromString('OEBPS/nav.xhtml', $nav);
        $ncx = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
            '<ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1">' . "\n" .
            '<head>' . "\n" .
            '<meta name="dtb:uid" content="' . htmlspecialchars($identifier, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8') . '" />' . "\n" .
            '<meta name="dtb:depth" content="1" />' . "\n" .
            '<meta name="dtb:totalPageCount" content="0" />' . "\n" .
            '<meta name="dtb:maxPageNumber" content="0" />' . "\n" .
            '</head>' . "\n" .
            '<docTitle>' . "\n" .
            '<text>' . htmlspecialchars($title, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8') . '</text>' . "\n" .
            '</docTitle>' . "\n" .
            '<navMap>' . "\n" . $ncxItems . "\n" . '</navMap>' . "\n" .
            '</ncx>';
        $zip->addFromString('OEBPS/toc.ncx', $ncx);
        $manifestItems .= '<item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>';
        $manifestItems .= '<item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>';

        $opf = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
            '<package version="3.0" xmlns="http://www.idpf.org/2007/opf" unique-identifier="book-id">' . "\n" .
            '<metadata xmlns:dc="http://purl.org/dc/elements/1.1/">' . "\n" .
            '<dc:title>' . htmlspecialchars($title, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8') . '</dc:title>' . "\n" .
            '<dc:creator>' . htmlspecialchars($author, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8') . '</dc:creator>' . "\n" .
            '<dc:publisher>' . htmlspecialchars($publisher, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8') . '</dc:publisher>' . "\n" .
            '<dc:description>' . htmlspecialchars($description, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8') . '</dc:description>' . "\n" .
            '<dc:language>' . htmlspecialchars($lang, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8') . '</dc:language>' . "\n" .
            '<dc:date>' . htmlspecialchars($date, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8') . '</dc:date>' . "\n" .
            '<dc:identifier id="book-id">' . htmlspecialchars($identifier, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8') . '</dc:identifier>' . "\n" .
            $coverMeta . "\n" .
            '</metadata>' . "\n" .
            '<manifest>' . "\n" . $manifestItems . "\n" . '</manifest>' . "\n" .
            '<spine toc="ncx">' . "\n" . $spineItems . "\n" . '</spine>' . "\n" .
            '</package>';
        $zip->addFromString('OEBPS/content.opf', $opf);

        $zip->close();
        $epubData = @file_get_contents($tmpEpub);
        @unlink($tmpEpub);
        if ($epubData === false || $epubData == '') {
            throw new Exception('EPUB export conversion returned empty output');
        }
        return $epubData;
    }

    public static function defaultEpubCss()
    {
        return "body { font-family: serif; line-height: 1.6; margin: 0; padding: 1em; }\n" .
            "h1, h2, h3, h4, h5, h6 { font-family: sans-serif; margin-top: 1.5em; margin-bottom: 0.5em; }\n" .
            "p { margin: 0.5em 0; }\n" .
            "img { max-width: 100%; height: auto; }\n" .
            "table { border-collapse: collapse; width: 100%; }\n" .
            "td, th { border: 1px solid #ccc; padding: 0.5em; }\n" .
            "blockquote { margin: 1em; padding: 0.5em 1em; border-left: 3px solid #ccc; }\n" .
            "pre { background: #f4f4f4; padding: 1em; overflow-x: auto; }\n" .
            ".responsive-iframe-container { position: relative; overflow: hidden; width: 100%; padding-top: 56.25%; }\n" .
            ".responsive-iframe { position: absolute; top: 0; left: 0; bottom: 0; right: 0; width: 100%; height: 100%; }";
    }

    public static function generateUUID()
    {
        // Security (SEC-12): use a CSPRNG (random_bytes) instead of mt_rand so
        // identifiers are not predictable, matching SiteRouteUtils::generateUUID.
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public static function fetchCoverData($coverPath, $basePath = '/', $siteDirectory = '')
    {
        $resolvedPath = self::resolveUrlForEpub($coverPath, $basePath);
        if ($resolvedPath == '') {
            return null;
        }
        $data = '';
        $name = basename($resolvedPath);
        if (preg_match('/^(https?:)?\/\//i', $resolvedPath)) {
            // Security (SEC-01): validate the cover URL via SsrfGuard before
            // fetching so a crafted logo cannot SSRF internal services or cloud
            // metadata endpoints. safeFileGetContents disables redirects and
            // rejects private/loopback/link-local/metadata IPs.
            try {
                $data = SsrfGuard::safeFileGetContents($resolvedPath);
            } catch (SsrfGuardException $e) {
                return null;
            }
        }
        else {
            // Security (SEC-01): local file reads are constrained to the site
            // directory. Reject null bytes, '..', and absolute filesystem paths;
            // realpath-contain every candidate against the site directory so a
            // crafted logo cannot read arbitrary files (e.g. _config/config.php).
            if (strpos($resolvedPath, chr(0)) !== false || $siteDirectory === '') {
                return null;
            }
            $realSiteDir = realpath($siteDirectory);
            if (!is_string($realSiteDir) || $realSiteDir === '' || !is_dir($realSiteDir)) {
                return null;
            }
            $normalizedBase = SiteRouteUtils::normalizeBasePath($basePath);
            $relative = $resolvedPath;
            if ($normalizedBase != '/' && strpos($relative, $normalizedBase) === 0) {
                $relative = substr($relative, strlen($normalizedBase));
            }
            $relative = ltrim($relative, '/');
            if ($relative === '' || strpos($relative, '..') !== false) {
                return null;
            }
            $candidates = array(
                $realSiteDir . '/' . $relative,
                $realSiteDir . '/files/' . $relative,
            );
            foreach ($candidates as $candidate) {
                $realCandidate = realpath($candidate);
                if ($realCandidate === false || !is_file($realCandidate) || !is_readable($realCandidate)) {
                    continue;
                }
                if (strpos($realCandidate, $realSiteDir . '/') !== 0 && $realCandidate !== $realSiteDir) {
                    continue;
                }
                $data = @file_get_contents($realCandidate);
                if ($data !== false && $data !== '') {
                    break;
                }
            }
        }
        if ($data === false || $data == '') {
            return null;
        }
        return array('data' => $data, 'name' => $name);
    }

    public static function extensionToMimeType($ext, $fallback = 'application/octet-stream')
    {
        $map = array(
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
        );
        if (array_key_exists($ext, $map)) {
            return $map[$ext];
        }
        return $fallback;
    }

    public static function getSiteAuthor($site)
    {
        if (
            isset($site) &&
            isset($site->manifest) &&
            isset($site->manifest->metadata) &&
            isset($site->manifest->metadata->author) &&
            isset($site->manifest->metadata->author->name) &&
            $site->manifest->metadata->author->name != ''
        ) {
            return (string) $site->manifest->metadata->author->name;
        }
        return 'HAX The Web';
    }

    public static function getSiteDescription($site)
    {
        if (isset($site) && isset($site->manifest) && isset($site->manifest->description) && $site->manifest->description != '') {
            return (string) $site->manifest->description;
        }
        return '';
    }

    public static function getSiteCover($site, $basePath = '/')
    {
        if (
            isset($site) &&
            isset($site->manifest) &&
            isset($site->manifest->metadata) &&
            isset($site->manifest->metadata->site) &&
            isset($site->manifest->metadata->site->logo) &&
            $site->manifest->metadata->site->logo != ''
        ) {
            return (string) $site->manifest->metadata->site->logo;
        }
        return '';
    }

    public static function getSiteUpdatedDate($site)
    {
        if (
            isset($site) &&
            isset($site->manifest) &&
            isset($site->manifest->metadata) &&
            isset($site->manifest->metadata->site) &&
            isset($site->manifest->metadata->site->updated) &&
            is_numeric($site->manifest->metadata->site->updated)
        ) {
            $unix = intval($site->manifest->metadata->site->updated);
            if ($unix > 0) {
                return gmdate('c', $unix);
            }
        }
        return gmdate('c');
    }

    public static function buildSiteEpubString($site, $basePath = '/', $ancestor = '')
    {
        $orderedItems = SiteRouteUtils::getOrderedItems($site);
        $itemsToExport = $orderedItems;

        if (
            $ancestor != '' &&
            isset($site) &&
            isset($site->manifest) &&
            method_exists($site->manifest, 'findBranch')
        ) {
            try {
                $branch = $site->manifest->findBranch($ancestor);
                if (is_array($branch)) {
                    $branchIds = array();
                    foreach ($branch as $branchItem) {
                        if (isset($branchItem->id)) {
                            $branchIds[(string) $branchItem->id] = true;
                        }
                    }
                    $itemsToExport = array_values(array_filter($orderedItems, function ($item) use ($branchIds) {
                        if (!isset($item->id) || !array_key_exists((string) $item->id, $branchIds)) {
                            return false;
                        }
                        return true;
                    }));
                }
            }
            catch (Exception $e) {
            }
        }

        // Exclude unpublished items and children of unpublished parents
        $itemsToExport = array_values(array_filter($itemsToExport, function ($item) use ($site) {
            if (!isset($item->id)) {
                return false;
            }
            $itemCheck = $item;
            while ($itemCheck) {
                if (!SiteRouteUtils::isItemPublished($itemCheck)) {
                    return false;
                }
                if (!isset($itemCheck->parent) || $itemCheck->parent == '') {
                    break;
                }
                $itemCheck = SiteRouteUtils::findItemByIdOrSlug($site, $itemCheck->parent);
                if (!$itemCheck) {
                    break;
                }
            }
            return true;
        }));

        $basePath = SiteRouteUtils::normalizeBasePath($basePath);
        $chapters = array();
        foreach ($itemsToExport as $item) {
            if (!$item) {
                continue;
            }
            $itemTitle = self::buildItemExportDocumentTitle($item);
            $itemContent = SiteRouteUtils::getItemContent($site, $item);
            $chapterContent = self::normalizeHtmlForDocumentExport($itemContent, $basePath, $itemsToExport, 'epub');
            $chapters[] = array(
                'title' => $itemTitle,
                'content' => '',
                'filename' => (isset($item->slug) && $item->slug != '' ? str_replace('/', '-', (string) $item->slug) : (string) $item->id) . '.xhtml',
                'xhtmlContent' => $chapterContent,
            );
        }

        $siteTitle = self::buildSiteExportDocumentTitle($site);
        $author = self::getSiteAuthor($site);
        $description = self::getSiteDescription($site);
        $lang = SiteRouteUtils::getSiteLanguage($site);
        $date = self::getSiteUpdatedDate($site);
        $cover = self::getSiteCover($site, $basePath);

        $bookMeta = array(
            'title' => $siteTitle,
            'author' => $author,
            'publisher' => 'HAX The Web',
            'description' => $description,
            'lang' => $lang,
            'date' => $date,
            'identifier' => 'urn:uuid:' . self::generateUUID(),
            'coverPath' => $cover,
            'basePath' => $basePath,
            'siteDirectory' => SiteRouteUtils::getSiteDirectory($site),
        );
        return self::buildEpubZipString($bookMeta, $chapters, self::defaultEpubCss());
    }

    public static function buildItemEpubString($site, $item, $basePath = '/')
    {
        $basePath = SiteRouteUtils::normalizeBasePath($basePath);
        $itemTitle = self::buildItemExportDocumentTitle($item);
        $itemContent = SiteRouteUtils::getItemContent($site, $item);
        $chapterContent = self::normalizeHtmlForDocumentExport($itemContent, $basePath, array($item), 'epub');
        $filename = (isset($item->slug) && $item->slug != '' ? str_replace('/', '-', (string) $item->slug) : (string) $item->id) . '.xhtml';
        $chapters = array(
            array(
                'title' => $itemTitle,
                'content' => '',
                'filename' => $filename,
                'xhtmlContent' => $chapterContent,
            ),
        );
        $author = self::getSiteAuthor($site);
        $lang = SiteRouteUtils::getSiteLanguage($site);
        $date = self::getSiteUpdatedDate($site);
        $bookMeta = array(
            'title' => $itemTitle,
            'author' => $author,
            'publisher' => 'HAX The Web',
            'description' => '',
            'lang' => $lang,
            'date' => $date,
            'identifier' => 'urn:uuid:' . self::generateUUID(),
            'coverPath' => self::getSiteCover($site, $basePath),
            'basePath' => $basePath,
            'siteDirectory' => SiteRouteUtils::getSiteDirectory($site),
        );
        return self::buildEpubZipString($bookMeta, $chapters, self::defaultEpubCss());
    }
}
