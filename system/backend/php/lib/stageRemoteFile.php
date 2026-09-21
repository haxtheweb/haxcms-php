<?php
include_once dirname(__FILE__) . '/SsrfGuard.php';

// Bulk-import staging shared by the site importers and createSite (#3060).
// HAXCMSFile::isValidBulkImportTmpPath only accepts real files under
// <configDirectory>/tmp/imports, so a remote file is downloaded here first
// and the staged path then takes the same bulk-import save as any other file.
// Mirrors haxcms-nodejs src/lib/stageRemoteFile.js.
if (!function_exists('haxcms_import_get_staging_root')) {
    function haxcms_import_get_staging_root() {
        global $HAXCMS;
        if (!isset($HAXCMS) || !isset($HAXCMS->configDirectory)) {
            return false;
        }
        $root = $HAXCMS->configDirectory . '/tmp/imports';
        if (!is_dir($root)) {
            @mkdir($root, 0755, true);
        }
        if (!is_dir($root)) {
            return false;
        }
        return $root;
    }
}

// Fetch a remote file via SsrfGuard::safeGuzzleRequest (SSRF-guarded,
// redirects disabled) and stage it under the bulk-import root. Reuses the
// caller's Guzzle $client. Returns the absolute staged path, or false on
// any fetch/write failure or empty body (the file is simply skipped).
if (!function_exists('haxcms_import_stage_remote_file')) {
    function haxcms_import_stage_remote_file($client, $url, $relPath) {
        $root = haxcms_import_get_staging_root();
        if ($root === false) {
            return false;
        }
        try {
            $resp  = SsrfGuard::safeGuzzleRequest($client, 'GET', $url);
            $body  = (string) $resp->getBody();
        } catch (\Exception $e) {
            return false;
        }
        if ($body === '') {
            return false;
        }
        $ext      = pathinfo($relPath, PATHINFO_EXTENSION);
        $extPart  = ($ext !== '') ? '.' . $ext : '';
        $staged   = $root . '/haxbi_' . uniqid() . $extPart;
        if (@file_put_contents($staged, $body) === false) {
            return false;
        }
        return $staged;
    }
}
