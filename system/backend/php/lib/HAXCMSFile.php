<?php
include_once dirname(__FILE__) . "/../vendor/autoload.php";
use \Gumlet\ImageResize;

// a site object
class HAXCMSFIle
{
    /**
     * Save file into this site, optionally updating reference inside the page
     */
    public function save($upload, $site, $page = null, $imageOps = null)
    {
        global $HAXCMS;
        global $fileSystem;
        $size = false;
        $status = 0;
        $return = array();
        $name = $upload['name'];
        // ensure file is an image, video, docx, pdf, etc. of safe file types to allow uploading
        if (
            isset($upload['tmp_name']) &&
            (is_uploaded_file($upload['tmp_name']) || isset($upload['bulk-import'])) && 
            // ensure file extension is an image, video, docx, pdf, etc. of safe file types to allow uploading
            preg_match(
                '/.(jpg|jpeg|png|gif|webm|webp|mp4|mp3|mov|csv|ppt|pptx|xlsx|doc|xls|docx|pdf|rtf|txt|html|md)$/i',
                $upload['name']
            )
        ) {
            // get contents of the file if it was uploaded into a variable
            $filedata = @file_get_contents($upload['tmp_name']);
            // attempt to save the file either to site or system level
            if ($site == 'system/user/files') {
              $pathPart = str_replace(HAXCMS_ROOT . '/', '', $HAXCMS->configDirectory) . '/user/files/';
            }
            else if ($site == 'system/tmp') {
              $pathPart = str_replace(HAXCMS_ROOT . '/', '', $HAXCMS->configDirectory) . '/tmp/';
            }
            else {
              $pathPart = $HAXCMS->sitesDirectory . '/' . $site->manifest->metadata->site->name . '/files/';
            }
            $path = HAXCMS_ROOT . '/' . $pathPart;
            // ensure this path exists
            $fileSystem->mkdir($path);
            // account for name possibly matching on file system already
            $actual_name = pathinfo($name, PATHINFO_FILENAME);
            $original_name = $actual_name;
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $i = 1;
            while (file_exists($path . $actual_name . "." . $extension)) {           
                $actual_name = (string)$original_name . $i;
                $i++;
            }
            // sanitization for the file name
            $actual_name = mb_ereg_replace("([^\w\s\d\-_~,;\[\]\(\).])", '', $actual_name);
            // Remove any runs of periods (thanks falstro!)
            $actual_name = mb_ereg_replace("([\.]{2,})", '', $actual_name);
            $name = $actual_name . "." . $extension;
            // on bulk import we keep directory tree and apply changes to the name itself
            if (isset($upload['bulk-import'])) {
                // make path relative to the file
                $namePathTest = pathinfo(str_replace('files/', '', $upload['name']));
                $fileSystem->mkdir($path . $namePathTest['dirname'], 0755, true);
                // full path needs to include the cleaned up file name + the actual directory
                $fullpath = $path . $namePathTest['dirname']  . '/' . $name;
            }
            else {
                $fullpath = $path . $name;
            }            
            if ($size = @file_put_contents($fullpath, $filedata)) {
                //@todo make a way of defining these as returns as well as number to take
                // specialized support for images to do scale and crop stuff automatically
                if (
                    in_array(mime_content_type($fullpath), array(
                        'image/png',
                        'image/jpeg',
                        'image/gif'
                    ))
                ) {
                    // ensure folders exist
                    // @todo comment this all in once we have a better way of doing it
                    // front end should dictate stuff like this happening and probably
                    // can actually accomplish much of it on its own
                    /*try {
                        $fileSystem->mkdir($path . 'scale-50');
                        $fileSystem->mkdir($path . 'crop-sm');
                    } catch (IOExceptionInterface $exception) {
                        echo "An error occurred while creating your directory at " .
                            $exception->getPath();
                    }
                    $image = new ImageResize($fullpath);
                    $image
                        ->scale(50)
                        ->save($path . 'scale-50/' . $name)
                        ->crop(100, 100)
                        ->save($path . 'crop-sm/' . $name);*/
                    // fake the file object creation stuff from CMS land
                    $return = array(
                        'file' => array(
                            'path' => $path . $name,
                            'fullUrl' =>
                                $HAXCMS->basePath .
                                $pathPart .
                                $name,
                            'url' => 'files/' . $name,
                            'type' => mime_content_type($fullpath),
                            'name' => $name,
                            'size' => $size
                        )
                    );
                } else {
                    // fake the file object creation stuff from CMS land
                    $return = array(
                        'file' => array(
                            'path' => $path . $name,
                            'fullUrl' =>
                                $HAXCMS->basePath .
                                $pathPart .
                                $name,
                            'url' => 'files/' . $name,
                            'type' => mime_content_type($fullpath),
                            'name' => $name,
                            'size' => $size
                        )
                    );
                }
                // perform page level reference saving if available
                if ($page != null) {
                    // now update the page's metadata to suggest it uses this file. FTW!
                    if (!isset($page->metadata->files)) {
                        $page->metadata->files = array();
                    }
                    $page->metadata->files[] = array(
                        'fullUrl' =>
                            $HAXCMS->basePath .
                            $pathPart .
                            $name,
                        'url' => 'files/' . $name,
                        'type' => mime_content_type($fullpath),
                        'name' => $name,
                        'size' => $size
                    );
                    $site->updateNode($page);
                }
                // perform scale / crop operations if requested
                if ($imageOps != null) {
                  $image = new ImageResize($fullpath);
                  switch ($imageOps) {
                    case 'thumbnail':
                    $image
                      ->scale(75)
                      ->crop(250, 250)
                      ->save($fullpath);
                    break;
                  }
                }
                $status = 200;
            }
        }
        if ($size === false) {
            $status = 500;
            $return = 'failed to write ' . $name;
        }
        return array(
            'status' => $status,
            'data' => $return
        );
    }
}
