<?php
  include_once '../system/lib/bootstrapHAX.php';
  include_once $HAXCMS->configDirectory . '/config.php';
  // test if this is a valid user login
  if ($HAXCMS->validateJWT()) {
    header('Content-Type: application/json');
    $output = shell_exec('bash ' . HAXCMS_ROOT . '/scripts/surgepublish.sh ' . $HAXCMS->safePost['siteName']);
    // load the site from name
    $site = $HAXCMS->loadSite($HAXCMS->safePost['siteName']);
    $site->manifest->metadata->lastPublished = time();
    $site->manifest->save();
    header('Status: 200');
    $return = array(
      'status' => 200,
      'url' => $site->manifest->metadata->domain,
      'label' => 'Click to access ' . $site->manifest->title,
      'response' => 'Site published!',
      'output' => $output,
    );
    print json_encode($return);
    exit;
  }
?>