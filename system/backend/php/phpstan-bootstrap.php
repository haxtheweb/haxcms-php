<?php
// PHPStan bootstrap: CLI only (static analysis), never reachable over HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// bootstrapHAX.php defines HAXCMS_ROOT at runtime from the install path.
// Declare it here so the analyser knows it is a string constant.
if (!defined('HAXCMS_ROOT')) {
    define('HAXCMS_ROOT', dirname(__DIR__, 3));
}
