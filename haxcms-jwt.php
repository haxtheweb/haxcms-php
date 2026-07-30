<?php
// This is all the PHP / JS code we need in order to make HAX CMS work with PHP
// The variable below is global and then elements look for it for it's configuration
// and unpack from there
include_once dirname(__FILE__) . '/system/backend/php/bootstrapHAX.php';
include_once $HAXCMS->configDirectory . '/config.php';
$appSettings = $HAXCMS->appJWTConnectionSettings();
// Phase 3 (M1): this response may embed a per-user access JWT via
// appSettings.jwt (HAXiam / server-injected bootstrap). Prevent browser/proxy
// caching so the token is never served to a different user from cache.
header('Content-Type: application/javascript');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
?>
window.appSettings = <?php print json_encode($appSettings); ?>;