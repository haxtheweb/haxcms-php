#!/usr/bin/env php
<?php
/**
 * HAXcms platform upgrades from the shell (haxtheweb/issues#1768).
 *
 *   php scripts/platform-upgrade.php status   [--json]
 *   php scripts/platform-upgrade.php verify   [--json]
 *   php scripts/platform-upgrade.php upgrade  [--to <version>] [--dry-run] [--y]
 *                                             [--overwrite-modified] [--allow-downgrade]
 *   php scripts/platform-upgrade.php rollback [--y]
 *
 * Offline: upgrade --archive <haxcms-php-X.tar.gz> --sums <SHA256SUMS>
 *          --manifest <platform-manifest.json>
 *
 * Run it as the user who owns the files. The web server never runs it: the
 * admin panel only tells you that an update exists and which command to run.
 * Site data (_config, _sites, _published, _archived) is never touched; code
 * files are backed up to _config/platform-upgrade/backups first. Not
 * available on HAXiam (haxiam-managed) deployments, where the host manages
 * HAXcms: upgrade, verify and rollback refuse there.
 *
 * Exit code 0 on success, 1 on an error or a refused upgrade, 2 when status
 * finds a missing security fix.
 */
// Security: CLI only; an upgrade must never be triggerable over HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$haxcmsRoot = dirname(__DIR__);
if (!defined('HAXCMS_ROOT')) {
    define('HAXCMS_ROOT', $haxcmsRoot);
}
include_once $haxcmsRoot . '/system/backend/php/lib/PlatformUpdateService.php';
include_once $haxcmsRoot . '/system/backend/php/lib/PlatformUpgrader.php';

function haxcmsPlatformUsage($message = '')
{
    if ($message !== '') {
        fwrite(STDERR, $message . PHP_EOL);
    }
    fwrite(STDERR, 'Usage: php scripts/platform-upgrade.php status|verify|upgrade|rollback [--to <version>] [--dry-run] [--y] [--json]' . PHP_EOL);
    exit(1);
}

function haxcmsPlatformArgs($argv)
{
    $args = array('command' => isset($argv[1]) ? $argv[1] : 'status', 'flags' => array(), 'values' => array());
    $valued = array('--to', '--archive', '--sums', '--manifest');
    for ($i = 2; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if (in_array($arg, $valued, true)) {
            if (!isset($argv[$i + 1])) {
                haxcmsPlatformUsage($arg . ' needs a value.');
            }
            $args['values'][substr($arg, 2)] = $argv[++$i];
        }
        elseif (strpos($arg, '--') === 0) {
            $args['flags'][substr($arg, 2)] = true;
        }
        else {
            haxcmsPlatformUsage('Unknown argument: ' . $arg);
        }
    }
    return $args;
}

function haxcmsPlatformConfirm($question, $flags)
{
    if (!empty($flags['y'])) {
        return true;
    }
    if (function_exists('posix_isatty') && !posix_isatty(STDIN)) {
        fwrite(STDERR, 'Not a terminal: pass --y to confirm.' . PHP_EOL);
        return false;
    }
    fwrite(STDOUT, $question . ' [y/N] ');
    $answer = strtolower(trim((string) fgets(STDIN)));
    return $answer === 'y' || $answer === 'yes';
}

function haxcmsPlatformPrintList($title, $items, $limit = 20)
{
    if (count($items) === 0) {
        return;
    }
    fwrite(STDOUT, $title . ' (' . count($items) . '):' . PHP_EOL);
    foreach (array_slice($items, 0, $limit) as $item) {
        fwrite(STDOUT, '  ' . $item . PHP_EOL);
    }
    if (count($items) > $limit) {
        fwrite(STDOUT, '  …and ' . (count($items) - $limit) . ' more' . PHP_EOL);
    }
}

$args = haxcmsPlatformArgs($argv);
$json = !empty($args['flags']['json']);
$upgrader = new HAXCMSPlatformUpgrader($haxcmsRoot, function ($message) use ($json) {
    if (!$json) {
        fwrite(STDOUT, $message . PHP_EOL);
    }
});

try {
    switch ($args['command']) {
        case 'status':
            $current = $upgrader->installedVersion();
            if ($upgrader->isHostManaged()) {
                // no outbound check on a host-managed install, as in the admin panel
                $update = HAXCMSPlatformUpdateService::buildUpdateInfo(array(
                    'currentVersion' => $current,
                    'managed' => true,
                    'installMethod' => HAXCMSPlatformUpdateService::detectInstallMethod($haxcmsRoot),
                ));
                fwrite(STDOUT, $json
                    ? json_encode($update, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
                    : 'HAXcms ' . $current . ' · Updates for this install are managed by your host (HAXiam).' . PHP_EOL);
                exit(0);
            }
            $update = HAXCMSPlatformUpdateService::buildUpdateInfo(array(
                'currentVersion' => $current,
                'checkEnabled' => true,
                'release' => HAXCMSPlatformUpdateService::fetchLatestRelease(),
                'advisories' => HAXCMSPlatformUpdateService::fetchPublishedAdvisories(),
                'installMethod' => HAXCMSPlatformUpdateService::detectInstallMethod($haxcmsRoot),
            ));
            if ($json) {
                fwrite(STDOUT, json_encode($update, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
            }
            else {
                fwrite(STDOUT, 'HAXcms ' . $update['current'] . ' · Latest ' . $update['latest'] . ' · ' . $update['installMethod'] . PHP_EOL);
                foreach ($update['advisories'] as $advisory) {
                    fwrite(STDOUT, 'Security: ' . ($advisory['cve'] !== '' ? $advisory['cve'] : $advisory['id'])
                        . ' (' . $advisory['severity'] . ') fixed in ' . $advisory['patched'] . ' ' . $advisory['url'] . PHP_EOL);
                }
                if ($update['updateAvailable']) {
                    fwrite(STDOUT, 'Update with: php scripts/platform-upgrade.php upgrade' . PHP_EOL);
                }
                elseif ($update['latest'] === 'unknown') {
                    fwrite(STDOUT, 'Could not reach GitHub to check for updates.' . PHP_EOL);
                }
                else {
                    fwrite(STDOUT, 'Up to date.' . PHP_EOL);
                }
            }
            exit($update['security'] ? 2 : 0);

        case 'verify':
            $manifest = isset($args['values']['manifest'])
                ? HAXCMSPlatformUpgrader::parseManifest(file_get_contents($args['values']['manifest']))
                : null;
            $result = $upgrader->verify($manifest);
            if ($json) {
                fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
            }
            else {
                haxcmsPlatformPrintList('Changed by hand', $result['modified']);
                haxcmsPlatformPrintList('Missing', $result['missing']);
                haxcmsPlatformPrintList('Not part of this release (leftovers or your own files)', $result['extra']);
                if (!count($result['modified']) && !count($result['missing'])) {
                    fwrite(STDOUT, 'Code files match release ' . $upgrader->installedVersion() . '.' . PHP_EOL);
                }
            }
            exit(count($result['modified']) || count($result['missing']) ? 1 : 0);

        case 'upgrade':
            $options = array(
                'to' => isset($args['values']['to']) ? $args['values']['to'] : null,
                'archive' => isset($args['values']['archive']) ? $args['values']['archive'] : null,
                'sums' => isset($args['values']['sums']) ? $args['values']['sums'] : null,
                'manifest' => isset($args['values']['manifest']) ? $args['values']['manifest'] : null,
                'overwriteModified' => !empty($args['flags']['overwrite-modified']),
                'allowDowngrade' => !empty($args['flags']['allow-downgrade']),
            );
            $describe = function ($plan) {
                fwrite(STDOUT, 'Upgrade ' . $plan['from'] . ' → ' . $plan['to'] . ': write ' . $plan['write'] . ' code file(s), remove ' . count($plan['remove']) . '.' . PHP_EOL);
                haxcmsPlatformPrintList('Changed by hand (backed up, then replaced)', $plan['modified']);
                haxcmsPlatformPrintList('Removed (no longer in the release)', $plan['remove']);
                if (!$plan['previousManifest']) {
                    fwrite(STDOUT, 'This install has no manifest yet, so leftover files from older versions are kept. Run verify afterwards to list them.' . PHP_EOL);
                }
            };
            if (!empty($args['flags']['dry-run'])) {
                $plan = $upgrader->upgrade(array_merge($options, array('dryRun' => true)));
                $describe($plan);
                if (!empty($plan['blocked'])) {
                    fwrite(STDERR, 'Re-run with --overwrite-modified to replace the files changed by hand.' . PHP_EOL);
                    exit(1);
                }
                exit(0);
            }
            $flags = $args['flags'];
            $options['confirm'] = function ($plan) use ($describe, $flags) {
                $describe($plan);
                return haxcmsPlatformConfirm('Back up the code and upgrade now?', $flags);
            };
            $done = $upgrader->upgrade($options);
            if (!empty($done['cancelled'])) {
                exit(1);
            }
            fwrite(STDOUT, 'Upgraded to ' . $done['to'] . '. Backup: ' . $done['backup'] . PHP_EOL);
            fwrite(STDOUT, 'Undo with: php scripts/platform-upgrade.php rollback' . PHP_EOL);
            exit(0);

        case 'rollback':
            $backups = $upgrader->listBackups();
            if (count($backups) === 0) {
                throw new RuntimeException('No upgrade backup to roll back to.');
            }
            if (!haxcmsPlatformConfirm('Restore the code from ' . basename($backups[0]) . '?', $args['flags'])) {
                exit(1);
            }
            $meta = $upgrader->rollback();
            fwrite(STDOUT, 'Rolled back to ' . $meta['from'] . '.' . PHP_EOL);
            exit(0);

        default:
            haxcmsPlatformUsage('Unknown command: ' . $args['command']);
    }
}
catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
