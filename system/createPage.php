<?php
  include_once '../system/lib/bootstrapHAX.php';
  include_once $HAXCMS->configDirectory . '/config.php';
  // test if this is a valid user login
  if ($HAXCMS->validateJWT()) {
    header('Content-Type: application/json');
    header('Status: 200');
    $params = $HAXCMS->safePost;
    // woohoo we can edit this thing!
    $site = $HAXCMS->loadSite(strtolower($params['siteName']));
    // get a new item prototype
    $item = $HAXCMS->outlineSchema->newItem();
    // set the title
    $item->title = str_replace("\n", '', $params['title']);
    if (isset($params['id']) && $params['id'] != '') {
      $item->id = $params['id'];
    }
    if (isset($params['location']) && $params['location'] != '') {
      $cleanTitle = strtolower(str_replace(' ', '-', $params['location']));
      $cleanTitle = preg_replace('/[^\w\-\/]+/u', '-', $cleanTitle);
      $cleanTitle = mb_strtolower(preg_replace('/--+/u', '-', $cleanTitle), 'UTF-8');
      $item->location = 'pages/' . $cleanTitle . '/index.html';
    }
    else {
      $cleanTitle = strtolower(str_replace(' ', '-', $item->title));
      $cleanTitle = preg_replace('/[^\w\-\/]+/u', '-', $cleanTitle);
      $cleanTitle = mb_strtolower(preg_replace('/--+/u', '-', $cleanTitle), 'UTF-8');
      $item->location = 'pages/' . $cleanTitle . '/index.html';
    }
    // ensure this location doesn't exist already
    $siteDirectory = $site->directory . '/' . $site->manifest->metadata->siteName;
    $loop = 0;
    while (file_exists($siteDirectory . '/' . $item->location)) {
      $loop++;
      $item->location = 'pages/' . $cleanTitle . '-' . $loop . '/index.html';
    }

    if (isset($params['indent']) && $params['indent'] != '') {
      $item->indent = $params['indent'];
    }
    if (isset($params['order']) && $params['order'] != '') {
      $item->order = $params['order'];
    }
    if (isset($params['parent']) && $params['parent'] != '') {
      $item->parent = $params['parent'];
    }
    else {
      $item->parent = null;
    }
    if (isset($params['description']) && $params['description'] != '') {
      $item->description = str_replace("\n", '', $params['description']);
    }
    if (isset($params['order']) && $params['metadata'] != '') {
      $item->metadata = $params['metadata'];
    }
    $item->metadata->siteName = strtolower($params['siteName']);
    $item->metadata->created = time();
    $item->metadata->updated = time();
    // add the item back into the outline schema
    // @todo fix logic here to actually create the page based on 1 call
    // this logic should be cleaned up in addPage to allow for
    // passing in arguments
    $site->recurseCopy(HAXCMS_ROOT . '/system/boilerplate/page', $site->directory . '/' . $site->manifest->metadata->siteName . '/' . str_replace('/index.html', '', $item->location));
    $site->manifest->addItem($item);
    $site->manifest->save();
    $site->gitCommit('Page added:' . $item->title . ' (' . $item->id . ')');
    print json_encode($item);      
  }
  exit;
?>