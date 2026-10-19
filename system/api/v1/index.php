<?php
include_once dirname(__FILE__) . '/../../backend/php/bootstrapHAX.php';
include_once dirname(__FILE__) . '/../../backend/php/lib/HAXCMSInstallGate.php';
$haxcmsConfigPhpPath = $HAXCMS->configDirectory . '/config.php';
if (file_exists($haxcmsConfigPhpPath)) {
  include_once $haxcmsConfigPhpPath;
}
// Security (SEC-DEPLOY): refuse every API route while config.php is missing or
// still holds the installer's placeholder keys/credentials (jeff/jimmerson,
// HAXTHEWEBPRIVATEKEY). The installer wizard only calls install.php.
if (HAXCMSInstallGate::isNotInstalled($HAXCMS, $haxcmsConfigPhpPath)) {
  HAXCMSInstallGate::sendNotInstalledResponse();
  exit;
}
include_once dirname(__FILE__) . '/../../backend/php/lib/systemRoutes/SystemApiRouter.php';
SystemApiRouter::dispatch();
