<?php
// basic global debugging
function hax500Debug()
{
    if ($err = error_get_last()) {
        die('<pre>' . print_r($err, true) . '</pre>');
    }
}
register_shutdown_function('hax500Debug');
// register our global CMS variable for the whole damn thing
global $HAXCMS;
global $config;
// support for config.php to override core capabilities
$config['connection'] = array();
// calculate where we are in the file system, accurately
$here = str_replace('/system/lib/bootstrapHAX.php', '', __FILE__);
// core support for IAM symlinked core which follows a similar pattern at a custom base path
if (file_exists($here . '/IAM')) {
  $pieces = explode('/', $_SERVER['REQUEST_URI']);
  array_shift($pieces);
  $here = str_replace('cores/HAXcms', 'users/' . $pieces[0], $here);
}
define('HAXCMS_ROOT', $here);
// the whole CMS as one object
include_once 'HAXCMS.php';
// invoke the CMS
$HAXCMS = new HAXCMS();
// support IAM config
if (file_exists($here . '/IAM')) {
    $HAXCMS->config->iam = true;
    if (file_exists($here . '/../../_iamConfig/config.php')) {
        include_once $here . '/../../_iamConfig/config.php';
    }
}