<?php
/**
 * Mint a one-time sign-in link for this HAXcms install (haxtheweb/issues#2854).
 *
 * For when you're locked out: run it on the server as a user that can write
 * _config. That access already lets you edit config.php, so no password is
 * required. The link works once and expires (10 minutes by default); minting a
 * new one cancels the previous one for that account.
 *
 *   php system/backend/php/scripts/magic-link.php --base-url=https://cms.example.org
 *   php system/backend/php/scripts/magic-link.php --user=admin --ttl=30m --json
 *   php system/backend/php/scripts/magic-link.php --revoke
 *
 * Options:
 *   --base-url=URL  URL HAXcms answers on (prints a path to prefix if omitted)
 *   --user=NAME     account to sign in as (defaults to the configured user)
 *   --ttl=N         lifetime: seconds, or 10m / 1h (clamped to 60s..24h)
 *   --json          machine-readable output
 *   --revoke        cancel every outstanding link instead of minting one
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}
// read our options before HAXCMS's constructor rewrites $argv
$opts = getopt('', array('base-url:', 'user:', 'ttl:', 'json', 'revoke', 'help'));
if (isset($opts['help'])) {
    $doc = file_get_contents(__FILE__);
    preg_match('/\/\*\*(.*?)\*\//s', $doc, $m);
    fwrite(STDOUT, preg_replace('/^\s*\* ?/m', '', $m[1]) . "\n");
    exit(0);
}
$asJson = isset($opts['json']);

function magicLinkFail($message, $asJson)
{
    if ($asJson) {
        fwrite(STDOUT, json_encode(array('error' => $message)) . "\n");
    }
    else {
        fwrite(STDERR, $message . "\n");
    }
    exit(1);
}

function magicLinkParseTtl($value)
{
    if (!preg_match('/^(\d+)\s*([smh]?)$/i', trim((string) $value), $m)) {
        return null;
    }
    $amount = intval($m[1]);
    $unit = strtolower($m[2]);
    if ($unit === 'h') {
        return $amount * 3600;
    }
    if ($unit === 'm') {
        return $amount * 60;
    }
    return $amount;
}

$ttlSeconds = null;
if (isset($opts['ttl'])) {
    $ttlSeconds = magicLinkParseTtl($opts['ttl']);
    if ($ttlSeconds === null) {
        magicLinkFail('Invalid --ttl "' . $opts['ttl'] . '". Use seconds (600) or minutes/hours (10m, 1h).', $asJson);
    }
}
$baseUrl = '';
if (isset($opts['base-url']) && trim($opts['base-url']) !== '') {
    $parts = parse_url(trim($opts['base-url']));
    if (!is_array($parts) || !isset($parts['scheme']) || !isset($parts['host']) || !in_array(strtolower($parts['scheme']), array('http', 'https'), true)) {
        magicLinkFail('Invalid --base-url; expected something like https://cms.example.org', $asJson);
    }
    $baseUrl = strtolower($parts['scheme']) . '://' . $parts['host'] .
        (isset($parts['port']) ? ':' . $parts['port'] : '') .
        (isset($parts['path']) ? rtrim($parts['path'], '/') : '');
}

include_once dirname(__DIR__) . '/bootstrapHAX.php';
// accounts live in config.php, loaded after bootstrap like every entry point
if (!file_exists($HAXCMS->configDirectory . '/config.php')) {
    magicLinkFail('HAXcms is not installed here yet (no ' . $HAXCMS->configDirectory . '/config.php).', $asJson);
}
include_once $HAXCMS->configDirectory . '/config.php';
include_once dirname(__DIR__) . '/lib/MagicLinkService.php';
$haxcms = $GLOBALS['HAXCMS'];

if (isset($opts['revoke'])) {
    $removed = MagicLinkService::revoke($haxcms, isset($opts['user']) ? $opts['user'] : null);
    if ($asJson) {
        fwrite(STDOUT, json_encode(array('revoked' => $removed)) . "\n");
    }
    else {
        fwrite(STDOUT, 'Cancelled ' . $removed . " outstanding sign-in link(s).\n");
    }
    exit(0);
}

$options = array('channel' => 'cli');
if (isset($opts['user'])) {
    $options['user'] = $opts['user'];
}
if ($ttlSeconds !== null) {
    $options['ttlSeconds'] = $ttlSeconds;
}
try {
    $issued = MagicLinkService::issue($haxcms, $options);
}
catch (RuntimeException $e) {
    magicLinkFail($e->getMessage(), $asJson);
}
$path = '/system/api/v1/session/magic-link?token=' . rawurlencode($issued['token']);
$result = array(
    'url' => $baseUrl !== '' ? $baseUrl . $path : null,
    'path' => $path,
    'user' => $issued['user'],
    'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', $issued['expiresAt']),
    'ttlSeconds' => $issued['ttlSeconds'],
);
if ($asJson) {
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES) . "\n");
    exit(0);
}
fwrite(STDOUT, "\nOne-time login link\n\n");
if ($result['url'] !== null) {
    fwrite(STDOUT, '  ' . $result['url'] . "\n\n");
}
else {
    fwrite(STDOUT, '  ' . $result['path'] . "\n\n  Prefix it with the URL HAXcms answers on, or re-run with --base-url=https://your-site.example\n\n");
}
fwrite(STDOUT, '  Signs in as ' . $issued['user'] . ' once, until ' . date('Y-m-d H:i T', $issued['expiresAt']) .
    ' (' . round($issued['ttlSeconds'] / 60) . " min).\n" .
    "  Generating another link cancels this one. Anyone with this link can edit the site,\n" .
    "  so don't paste it into chats or tickets.\n\n");
