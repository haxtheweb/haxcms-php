<?php
  include_once '../system/lib/bootstrapHAX.php';
  include_once $HAXCMS->configDirectory . '/config.php';
  // test if this is a valid user login
  if ($HAXCMS->validateJWT()) {
    header('Content-Type: application/json');
    $site = $HAXCMS->loadSite($HAXCMS->safePost['siteName']);
    if (isset($_POST['body'])) {
      $body = $_POST['body'];
    }
    if (isset($_POST['details'])) {
      $details = $_POST['details'];
    }
    // update the page's content, using manifest to find it
    // this ensures that writing is always to what the file system
    // determines to be the correct page
    if ($page = $site->loadPage($HAXCMS->safePost['page'])) {
      // convert web location for loading into file location for writing
      if (isset($body)) {
        $bytes = $page->writeLocation($body, HAXCMS_ROOT . '/' . $HAXCMS->sitesDirectory . '/' . $site->name . '/');
        if ($bytes === FALSE) {
          header('Status: 500');
          print json_encode('failed to write');
        }
        else {
          // update the updated timestamp
          $page->metadata->updated = time();
          // auto generate a text only description from first 200 chars
          $clean = strip_tags($body);
          $page->description = str_replace("\n", '', substr($clean, 0, 200));
          // update the item in the metadata to indicate when content was last set
          $site->manifest->updateItem($page, TRUE);
          $site->gitCommit('Page updated: ' . $page->title . ' (' . $page->id . ')');
          header('Status: 200');
          print json_encode($bytes);
        }
      }
      else if (isset($details)) {
        // update the updated timestamp
        $page->metadata->updated = time();
        foreach ($details as $key => $value) {
          // sanitize both sides
          $key = filter_var($key, FILTER_SANITIZE_STRING);
          $value = filter_var($value, FILTER_SANITIZE_STRING);
          if ($key == 'location') {
            // check on name
            $cleanTitle = $HAXCMS->cleanTitle($value);
            // ensure this isn't just saying to keep the same name
            if ($cleanTitle != str_replace('pages/', '', str_replace('/index.html', '', $page->location))) {
              $tmpTitle = $site->getUniqueLocationName($cleanTitle);
              $location = 'pages/' . $tmpTitle . '/index.html';
              // move the folder
              $site->renamePageLocation($page->location, $location);
              $page->location = $location;
            }
          }
          // don't allow key to be changed
          else if ($key != 'id') {
            $page->{$key} = $value;
          }
        }
        $site->manifest->updateItem($page, TRUE);
        $site->gitCommit('Page details updated: ' . $page->title . ' (' . $page->id . ')');
        header('Status: 200');
        print json_encode($page);
      }
      exit;
    }
  }
?>