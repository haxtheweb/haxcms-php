<?php
// basic global debugging
function haxGlobalDebug()
{
    if ($err = error_get_last()) {
      if ($err['type'] === E_ERROR) {
        // Security (SEC-ROUTE): avoid leaking paths/stack to clients via print_r
        // in production. Log server-side and emit a generic 500 unless the
        // deployment is in developerMode.
        $devMode = isset($GLOBALS['HAXCMS']) && is_object($GLOBALS['HAXCMS']) && isset($GLOBALS['HAXCMS']->developerMode) && $GLOBALS['HAXCMS']->developerMode;
        if ($devMode) {
          die('<pre>' . print_r($err, true) . '</pre>');
        }
        @error_log('haxcms fatal error: ' . print_r($err, true));
        http_response_code(500);
        die('A server error occurred.');
      }
      else if ($err['type'] === E_WARNING) {
        //die('<pre>' . print_r($err, true) . '</pre>');
      }
      else if ($err['type'] === E_PARSE) {
        //die('<pre>' . print_r($err, true) . '</pre>');
      }
      else if ($err['type'] === E_NOTICE) {
        //die('<pre>' . print_r($err, true) . '</pre>');
      }
    }
}
register_shutdown_function('haxGlobalDebug');
// register our global CMS variable for the whole damn thing
global $HAXCMS;
global $config;
// support for config.php to override core capabilities
$config['connection'] = array();
// calculate where we are in the file system, accurately
$here = str_replace('/system/backend/php/bootstrapHAX.php', '', __FILE__);
// core support for IAM symlinked core which follows a similar pattern at a custom base path
// this is needed because of how PHP resolves paths when in symlinked patterns
// @todo need to support HAXiam in the CLI
if (file_exists($here . '/_config/IAM')) {
  include_once __DIR__ . '/lib/IamTenant.php';
  // Security (SEC-PATH): the tenant name becomes users/<name> in HAXCMS_ROOT,
  // so only a plain name is used. Anything else resolves to the empty tenant
  // (the users/ directory itself, which has no _config), the same place a
  // bare "/" always resolved to, so it is answered like an unprovisioned user
  if (isset($_SERVER['REQUEST_URI'])) {
    $userDir = (string) IamTenant::fromRequestUri($_SERVER['REQUEST_URI']);
  }
  // account for CLI
  // Security (SEC-SESSION): CLI is decided from the SAPI, never from argc
  else if (IamTenant::isCli()) {
    $cliOpts = getopt('', array('op:', 'siteName::', 'iamUser::', 'theme:')) + array('args' => $GLOBALS['argv']);
    if (isset($cliOpts['iamUser'])) {
      $userDir = IamTenant::isValidName($cliOpts['iamUser']) ? $cliOpts['iamUser'] : '';
    }
  }

  // account for vanity domains
  if (isset($_SERVER['HAXSITE_BASE_URL']) && isset($_SERVER['DOCUMENT_ROOT'])) {
    $userDir = (string) IamTenant::fromDocumentRoot($_SERVER['DOCUMENT_ROOT']);
  }

  // leverage BRANCH in order to calculate the correct directory name here
  if ($branch = file_get_contents($here . '/BRANCH.txt')) {
    // intenals rewrite for things that are login in nature
    if (!defined('IAM_INTERNALS') && isset($userDir)) {
      $here = str_replace('cores/HAXcms-' . $branch, 'users/' . $userDir, $here);
    }
  }
}
define('HAXCMS_ROOT', $here);
// the whole CMS as one object
include_once 'lib/HAXCMS.php';
// invoke the CMS
$HAXCMS = new HAXCMS();
// support IAM config now apply these changes
if (file_exists($here . '/../../_iamConfig/HAXcmsConfig.php')) {
    $HAXCMS->config->iam = true;
    if (file_exists($here . '/../../_iamConfig/HAXcmsConfig.php')) {
        include_once $here . '/../../_iamConfig/HAXcmsConfig.php';
    }
}