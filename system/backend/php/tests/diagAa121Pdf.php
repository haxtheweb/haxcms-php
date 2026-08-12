<?php
/**
 * Diagnostic: trace the aa121 PDF export pipeline to find where content
 * disappears. Run:
 *   cd /home/bto108a/Documents/git/haxtheweb/haxcms-php
 *   php system/backend/php/tests/diagAa121Pdf.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('memory_limit', '2048M');

$repoRoot = dirname(dirname(dirname(dirname(__DIR__))));
require_once $repoRoot . '/system/backend/php/bootstrapHAX.php';
$configDir = $HAXCMS->configDirectory;
if (file_exists($configDir . '/config.php')) {
    require_once $configDir . '/config.php';
}
require_once dirname(__DIR__) . '/lib/siteRoutes/SiteRouteUtils.php';
require_once dirname(__DIR__) . '/lib/SanitizeContent.php';
require_once dirname(__DIR__) . '/lib/SsrfGuard.php';
require_once dirname(__DIR__) . '/lib/siteRoutes/v1/ExportConverters.php';

echo "=== aa121 PDF Export Diagnostic ===\n";
echo "HAXCMS_ROOT: " . HAXCMS_ROOT . "\n";
echo "sitesDirectory: " . $HAXCMS->sitesDirectory . "\n";
echo "basePath: " . $HAXCMS->basePath . "\n";
echo "memory_limit: " . ini_get('memory_limit') . "\n\n";

// Load the aa121 site
$site = $HAXCMS->loadSite('aa121');
if (!$site) {
    echo "FAIL: could not load aa121 site\n";
    exit(1);
}
echo "Loaded site: " . $site->manifest->metadata->site->name . "\n";
echo "Site directory: " . SiteRouteUtils::getSiteDirectory($site) . "\n";
$siteBasePath = SiteRouteUtils::getSiteBasePath($site);
echo "Site base path: $siteBasePath\n\n";

// Step 1: build the site export HTML
echo "[1] Building site export HTML...\n";
$siteHtml = ExportConverters::buildSiteExportHtml($site, '', '');
$htmlLen = strlen($siteHtml);
echo "    HTML length: $htmlLen bytes\n";
echo "    First 300 chars: " . substr($siteHtml, 0, 300) . "\n";
echo "    Contains <h1>: " . (strpos($siteHtml, '<h1>') !== false ? 'yes' : 'no') . "\n";
echo "    Contains <h2>: " . (strpos($siteHtml, '<h2>') !== false ? 'yes' : 'no') . "\n";
echo "    Contains <article: " . (strpos($siteHtml, '<article') !== false ? 'yes' : 'no') . "\n";
echo "    Contains <p>: " . (strpos($siteHtml, '<p>') !== false ? 'yes' : 'no') . "\n";
echo "    <img count: " . substr_count($siteHtml, '<img') . "\n";
echo "    <media-image count: " . substr_count($siteHtml, '<media-image') . "\n\n";

// Step 2: sanitize
echo "[2] Sanitizing HTML...\n";
$sanitized = ExportConverters::sanitizeExportHtml($siteHtml);
$sanLen = strlen($sanitized);
echo "    Sanitized length: $sanLen bytes\n";
echo "    First 300 chars: " . substr($sanitized, 0, 300) . "\n";
echo "    Contains <p>: " . (strpos($sanitized, '<p>') !== false ? 'yes' : 'no') . "\n\n";

// Step 3: feed to Dompdf directly (old approach) and report
echo "[3] Rendering with Dompdf (old approach, no site)...\n";
$memBefore = memory_get_usage(true);
$dompdf = new \Dompdf\Dompdf(array(
    'isRemoteEnabled' => false,
    'defaultFont' => 'serif',
    'tempDir' => sys_get_temp_dir(),
));
$dompdf->set_option('isHtml5ParserEnabled', true);
$baseTag = '<base href="' . htmlspecialchars($siteBasePath, ENT_QUOTES, 'UTF-8') . '" />';
if (stripos($sanitized, '<head>') !== false) {
    $loadHtml = preg_replace('/<head>/i', '<head>' . $baseTag, $sanitized, 1);
} else {
    $loadHtml = '<head>' . $baseTag . '</head>' . $sanitized;
}
echo "    loadHtml length: " . strlen($loadHtml) . "\n";
try {
    $dompdf->loadHtml($loadHtml);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $pdf = $dompdf->output();
    $memAfter = memory_get_usage(true);
    echo "    PDF length: " . strlen($pdf) . "\n";
    echo "    PDF starts with %PDF: " . (substr($pdf, 0, 4) === '%PDF' ? 'yes' : 'no') . "\n";
    echo "    Memory used: " . round(($memAfter - $memBefore) / 1024 / 1024, 2) . " MB\n";
    $pageCount = $dompdf->getCanvas()->get_page_count();
    echo "    Page count (canvas): $pageCount\n";
} catch (Exception $e) {
    echo "    EXCEPTION: " . $e->getMessage() . "\n";
}
echo "\n";

// Step 4: single item PDF with the new method
echo "[4] Single-item PDF (first item only)...\n";
$items = SiteRouteUtils::getOrderedItems($site);
$firstItem = $items[0];
echo "    First item: " . $firstItem->slug . " / " . $firstItem->title . "\n";
$itemContent = SiteRouteUtils::getItemContent($site, $firstItem);
echo "    Item content length: " . strlen($itemContent) . "\n";
$itemHtml = ExportConverters::buildItemExportHtml($firstItem, $itemContent);
echo "    Item HTML length: " . strlen($itemHtml) . "\n";
try {
    $itemPdf = ExportConverters::htmlToPdfString($itemHtml, $siteBasePath, $site);
    echo "    Item PDF length: " . strlen($itemPdf) . "\n";
    echo "    Item PDF starts with %PDF: " . (substr($itemPdf, 0, 4) === '%PDF' ? 'yes' : 'no') . "\n";
    file_put_contents(sys_get_temp_dir() . '/aa121-item-test.pdf', $itemPdf);
    echo "    Wrote single-item PDF to " . sys_get_temp_dir() . "/aa121-item-test.pdf\n";
} catch (Exception $e) {
    echo "    EXCEPTION: " . $e->getMessage() . "\n";
}
echo "\n";

// Step 5: full site with the NEW htmlToPdfString (with site)
echo "[5] Full site PDF with NEW htmlToPdfString (with site)...\n";
try {
    $fullPdf = ExportConverters::htmlToPdfString($siteHtml, $siteBasePath, $site);
    echo "    Full PDF length: " . strlen($fullPdf) . "\n";
    echo "    Full PDF starts with %PDF: " . (substr($fullPdf, 0, 4) === '%PDF' ? 'yes' : 'no') . "\n";
    file_put_contents(sys_get_temp_dir() . '/aa121-full-test.pdf', $fullPdf);
    echo "    Wrote full PDF to " . sys_get_temp_dir() . "/aa121-full-test.pdf\n";
} catch (Exception $e) {
    echo "    EXCEPTION: " . $e->getMessage() . "\n";
}

echo "\n=== Diagnostic complete ===\n";
