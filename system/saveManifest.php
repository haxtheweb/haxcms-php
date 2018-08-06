<?php
  include_once '../system/lib/bootstrapHAX.php';
  include_once $HAXCMS->configDirectory . '/config.php';
  // test if this is a valid user login
  if ($HAXCMS->validateJWT()) {
    header('Content-Type: application/json');
    // load the site from name
    $site = $HAXCMS->loadSite($HAXCMS->safePost['siteName']);
    // @todo update the items from the POST in the manifest
    // leave the items array alone
    $site->manifest->title = $HAXCMS->safePost['manifest']['title'];
    $site->manifest->metadata->updated = time();
    $site->manifest->save();
    header('Status: 200');
    print json_encode($site->manifest);
    exit;
  }
?>