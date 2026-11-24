<?php
include_once dirname(__FILE__) . '/SsrfGuard.php';
include_once dirname(__FILE__) . '/MediaSettingsService.php';

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

// Security (SEC-SSRF): staged files are normally consumed (and removed) by
// createSite in a later request; an abandoned import would otherwise leave
// them in the staging root forever. Sweep haxbi_* files older than this.
if (!defined('HAXCMS_IMPORT_STAGED_MAX_AGE')) {
    define('HAXCMS_IMPORT_STAGED_MAX_AGE', 86400);
}

// Security (SEC-SSRF): a staged file only keeps an extension from the one
// upload allowlist (HAXCMSMediaSettingsService::SAFE_UPLOAD_FORMATS).
// Anything else is staged with no extension.
if (!function_exists('haxcms_import_staged_extension')) {
    function haxcms_import_staged_extension($relPath) {
        $ext = strtolower((string) pathinfo((string) $relPath, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, HAXCMSMediaSettingsService::SAFE_UPLOAD_FORMATS, true)) {
            return '';
        }
        return $ext;
    }
}

// Security (SEC-SSRF): delete staged haxbi_* files older than $maxAge
// seconds. Best-effort; only touches files this module names.
if (!function_exists('haxcms_import_sweep_staging_root')) {
    function haxcms_import_sweep_staging_root($root, $maxAge = HAXCMS_IMPORT_STAGED_MAX_AGE) {
        if (!is_string($root) || $root === '' || !is_dir($root)) {
            return 0;
        }
        $removed = 0;
        $cutoff = time() - (int) $maxAge;
        $entries = @glob($root . '/haxbi_*');
        if (!is_array($entries)) {
            return 0;
        }
        foreach ($entries as $path) {
            if (is_link($path) || !is_file($path)) {
                continue;
            }
            $mtime = @filemtime($path);
            if ($mtime !== false && $mtime < $cutoff && @unlink($path)) {
                $removed++;
            }
        }
        return $removed;
    }
}

// Remove staged files (e.g. on an importer error path). Only paths inside the
// staging root are unlinked.
if (!function_exists('haxcms_import_unlink_staged_files')) {
    function haxcms_import_unlink_staged_files($paths) {
        $root = haxcms_import_get_staging_root();
        if ($root === false || !is_array($paths)) {
            return;
        }
        $realRoot = realpath($root);
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '' || is_link($path)) {
                continue;
            }
            $real = realpath($path);
            if ($real !== false && $realRoot !== false && strpos($real, $realRoot . '/') === 0 && is_file($real)) {
                @unlink($real);
            }
        }
    }
}

// Fetch a remote file via SsrfGuard::safeGuzzleRequest (SSRF-guarded,
// redirects re-validated per hop, IP pinned, byte-capped) and stage it under
// the bulk-import root. Reuses the caller's Guzzle $client. Returns the
// absolute staged path, or false on any fetch/write failure, an over-cap
// body, or an empty body (the file is simply skipped).
if (!function_exists('haxcms_import_stage_remote_file')) {
    function haxcms_import_stage_remote_file($client, $url, $relPath) {
        $root = haxcms_import_get_staging_root();
        if ($root === false) {
            return false;
        }
        haxcms_import_sweep_staging_root($root);
        // Security (SEC-SSRF): byte cap. The SsrfGuard default (20 MiB) unless
        // the site media settings allow larger uploads, so the import never
        // rejects a file HAXCMSFile::save would have accepted.
        $maxBytes = SsrfGuard::DEFAULT_MAX_RESPONSE_BYTES;
        global $HAXCMS;
        if (class_exists('HAXCMSMediaSettingsService') && isset($HAXCMS)) {
            try {
                $settings = HAXCMSMediaSettingsService::readMediaSettings($HAXCMS);
                if (is_array($settings) && isset($settings['maxUploadSizeMb']) && is_numeric($settings['maxUploadSizeMb'])) {
                    $configured = (int) $settings['maxUploadSizeMb'] * 1024 * 1024;
                    if ($configured > $maxBytes) {
                        $maxBytes = $configured;
                    }
                }
            } catch (\Throwable $e) {
                // keep the default cap
            }
        }
        try {
            $resp  = SsrfGuard::safeGuzzleRequest($client, 'GET', $url, array('max_bytes' => $maxBytes));
            $body  = (string) $resp->getBody();
        } catch (\Exception $e) {
            return false;
        }
        if ($body === '') {
            return false;
        }
        // Security (SEC-SSRF): the extension comes from the remote-supplied
        // path, so only an allowlisted extension is kept.
        $ext      = haxcms_import_staged_extension($relPath);
        $extPart  = ($ext !== '') ? '.' . $ext : '';
        // Security (SEC-UPLOAD, SEC-EXPORT): an unguessable name from a CSPRNG,
        // created exclusively ('x' never follows or reuses an existing path)
        // and readable by the server user only, like haxcms-nodejs
        // stageRemoteFile.js (crypto.randomUUID, flag 'wx', mode 0600)
        $staged   = $root . '/haxbi_' . bin2hex(random_bytes(16)) . $extPart;
        $handle   = @fopen($staged, 'xb');
        if ($handle === false) {
            return false;
        }
        @chmod($staged, 0600);
        $written = @fwrite($handle, $body);
        $closed  = @fclose($handle);
        if ($written !== strlen($body) || !$closed) {
            @unlink($staged);
            return false;
        }
        return $staged;
    }
}
