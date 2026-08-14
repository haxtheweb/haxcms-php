<?php
/**
 * PHPUnit bootstrap for haxcms-php backend.
 *
 * The application loads lib/ classes via include_once (composer.json has no
 * PSR-4 autoload mapping for lib/), so we require the classes under test
 * explicitly here, mirroring how the standalone test scripts work. composer's
 * autoloader is also loaded for any third-party dependencies a class may need.
 */
$base = dirname(__DIR__);
require_once $base . '/vendor/autoload.php';
require_once $base . '/lib/SanitizeContent.php';
