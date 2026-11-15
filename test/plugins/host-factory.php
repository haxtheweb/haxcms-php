<?php
/**
 * DRAFT (plugin platform). A fresh HAXcms site for each case: a temp HAXcms root with a site folder holding a
 * site.json outline (no pages), loaded through JSONOutlineSchema like any site, and HAXCMSPluginHost over it. System
 * and siteData stores and plugin settings go to that temp root, so cases never share state.
 */
$backend = dirname(__DIR__, 2) . '/system/backend/php';
require_once $backend . '/lib/JSONOutlineSchemaItem.php';
require_once $backend . '/lib/JSONOutlineSchema.php';
require_once $backend . '/lib/SsrfGuard.php';
require_once $backend . '/lib/plugins/HAXCMSPluginHost.php';

function haxcms_plugin_test_host(array $o)
{
    $root = HAXCMSPluginEngine::tempDir('haxcms-php-plugin-cases-');
    $name = 'conformance-' . getmypid() . '-' . bin2hex(random_bytes(3));
    $dir = "$root/_sites/$name";
    mkdir("$dir/pages", 0775, true);
    $outline = [
        'id' => sprintf('%08x-%04x-4%03x-8%03x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0, 0xfff), random_int(0, 0xffffffffffff)),
        'title' => $name,
        'author' => '',
        'description' => '',
        'license' => 'by-sa',
        'metadata' => ['site' => ['name' => $name, 'created' => 1767225600, 'updated' => 1767225600], 'theme' => ['element' => 'clean-one']],
        'items' => [],
    ];
    file_put_contents("$dir/site.json", json_encode($outline, JSON_PRETTY_PRINT));
    $m = new JSONOutlineSchema();
    $m->load("$dir/site.json");
    $host = new HAXCMSPluginHost([
        'manifest' => $m,
        'siteDirectory' => $dir,
        'root' => $root,
        'version' => $o['haxcmsVersion'] ?? '26.10.1',
        'hookTimeout' => $o['hookTimeout'] ?? 5000,
        // the reference's frozen clock, so time-based cases mean the same thing (a case's run.now overrides it)
        'now' => HAXCMSPluginEngine::parseTime('2026-01-01T00:00:00Z'),
    ] + (array_key_exists('user', $o) ? ['user' => $o['user']] : []));
    return $host;
}
