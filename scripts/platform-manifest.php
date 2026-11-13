#!/usr/bin/env php
<?php
/**
 * Write the release manifest for an unpacked release tree
 * (haxtheweb/issues#1768). Used by .github/workflows/release.yml.
 *
 *   php scripts/platform-manifest.php <release-dir> <version> <output.json>
 *
 * The manifest lists every code file with its SHA-256; site data folders
 * (_config, _sites, _published, _archived and their old names) are never
 * listed. scripts/platform-upgrade.php upgrades and verifies installs
 * against it.
 */
// Security: CLI only.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
include_once dirname(__DIR__) . '/system/backend/php/lib/PlatformUpgrader.php';

if (count($argv) !== 4 || !is_dir($argv[1]) || !HAXCMSPlatformUpgrader::isReleaseVersion($argv[2])) {
    fwrite(STDERR, 'Usage: php scripts/platform-manifest.php <release-dir> <x.y.z> <output.json>' . PHP_EOL);
    exit(1);
}
$manifest = HAXCMSPlatformUpgrader::buildManifest($argv[1], $argv[2]);
$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
// prove the result parses with the same rules the upgrader applies
HAXCMSPlatformUpgrader::parseManifest($json, $argv[2]);
if (file_put_contents($argv[3], $json . PHP_EOL) === false) {
    fwrite(STDERR, 'Cannot write ' . $argv[3] . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, 'Manifest: ' . count($manifest['files']) . ' files, ' . count($manifest['links']) . ' links' . PHP_EOL);
