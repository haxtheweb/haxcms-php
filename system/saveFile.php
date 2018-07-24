<?php
  include_once '../system/lib/bootstrapHAX.php';
  include_once $HAXCMS->configDirectory . '/config.php';
  // test if this is a valid user login
  if ($HAXCMS->validateJWT() && isset($_FILES['file-upload'])) {
    header('Content-Type: application/json');
    $site = $HAXCMS->loadSite($HAXCMS->safeGet['siteName']);
    // update the page's content, using manifest to find it
    // this ensures that writing is always to what the file system
    // determines to be the correct page
    if ($page = $site->loadPage($HAXCMS->safeGet['page'])) {
      $status = 403;
      $upload = $_FILES['file-upload'];
      // check for a file upload
      if (isset($upload['tmp_name']) && is_uploaded_file($upload['tmp_name'])) {
        // get contents of the file if it was uploaded into a variable
        $filedata = file_get_contents($upload['tmp_name']);
        // attempt to save the file
        $fullpath = HAXCMS_ROOT . '/' . $HAXCMS->sitesDirectory . '/' . $site->name . '/files/' . $upload['name'];
        if ($size = file_put_contents($fullpath, $filedata)) {
          // @todo fake the file object creation stuff from CMS land
          $return = array(
            'file' => array(
              'path' => $fullpath,
              'fullUrl' => $HAXCMS->basePath . $HAXCMS->sitesDirectory . '/' . $site->name . '/files/' . $upload['name'],
              'url' => 'files/' . $upload['name'],
              'type' => mime_content_type($fullpath),
              'name' => $upload['name'],
              'size' => $size,
            )
          );
          // now update the page's metadata to suggest it uses this file. FTW!
          if (!isset($page->metadata->files)) {
            $page->metadata->files = array();
          }
          $page->metadata->files[] = $return['file'];
          $site->updatePage($page);
          $status = 200;
        }
      }
      if ($size === FALSE) {
        header('Status: 500');
        print 'failed to write';
      }
      else {
        header('Status: 200');
        print json_encode(array(
          'status' => $status,
          'data' => $return,
        ));
      }
      exit;
    }
  }
?>