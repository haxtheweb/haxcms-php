<?php
include_once dirname(__FILE__) . "/../vendor/autoload.php";
use \Gumlet\ImageResize;

// a site object
class HAXCMSFile
{
    private $allowedUploadPattern = '/\.(jpg|jpeg|png|gif|webm|webp|mp4|mp3|mov|csv|ppt|pptx|xlsx|doc|xls|docx|pdf|rtf|txt|vtt|html|md)$/i';
    private $allowedMimeByExtension = array(
        'jpg' => array('image/jpeg'),
        'jpeg' => array('image/jpeg'),
        'png' => array('image/png'),
        'gif' => array('image/gif'),
        'webp' => array('image/webp'),
        'webm' => array('video/webm', 'audio/webm'),
        'mp4' => array('video/mp4'),
        'mp3' => array('audio/mpeg', 'audio/mp3'),
        'mov' => array('video/quicktime'),
        'csv' => array('text/csv', 'text/plain', 'application/vnd.ms-excel'),
        'ppt' => array('application/vnd.ms-powerpoint', 'application/x-ole-storage', 'application/octet-stream'),
        'pptx' => array('application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'),
        'xlsx' => array('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'),
        'doc' => array('application/msword', 'application/x-ole-storage', 'application/octet-stream'),
        'xls' => array('application/vnd.ms-excel', 'application/x-ole-storage', 'application/octet-stream'),
        'docx' => array('application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'),
        'pdf' => array('application/pdf'),
        'rtf' => array('application/rtf', 'text/rtf', 'text/plain'),
        'txt' => array('text/plain'),
        'vtt' => array('text/vtt', 'text/plain'),
        'html' => array('text/html', 'application/xhtml+xml'),
        'md' => array('text/markdown', 'text/x-markdown', 'text/plain')
    );
    private $imageExtensions = array(
        'jpg',
        'jpeg',
        'png',
        'gif',
        'webp'
    );
    private $dangerousExecutableExtensions = array(
        'php',
        'php3',
        'php4',
        'php5',
        'php7',
        'php8',
        'phtml',
        'phar',
        'phpt',
        'cgi',
        'pl',
        'py',
        'rb',
        'sh',
        'bash',
        'zsh',
        'ksh',
        'csh',
        'tcsh',
        'asp',
        'aspx',
        'jsp',
        'exe',
        'dll',
        'com',
        'bat',
        'cmd',
        'msi'
    );

    private function normalizeExtensionPart($part)
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $part));
    }

    private function stripExecutableExtensionPatterns($name)
    {
        $directory = pathinfo($name, PATHINFO_DIRNAME);
        $basename = pathinfo($name, PATHINFO_BASENAME);
        $parts = explode('.', $basename);
        if (count($parts) <= 1) {
            return $name;
        }
        $safeParts = array();
        foreach ($parts as $index => $part) {
            if ($part === '') {
                continue;
            }
            if (
                $index > 0 &&
                in_array($this->normalizeExtensionPart($part), $this->dangerousExecutableExtensions, true)
            ) {
                continue;
            }
            $safeParts[] = $part;
        }
        if (count($safeParts) === 0) {
            return '';
        }
        $safeName = implode('.', $safeParts);
        if ($directory !== '' && $directory !== '.') {
            return $directory . '/' . $safeName;
        }
        return $safeName;
    }

    private function detectMimeType($tmpPath)
    {
        if (!is_string($tmpPath) || $tmpPath == '' || !file_exists($tmpPath)) {
            return false;
        }
        $mimeType = false;
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mimeType = finfo_file($finfo, $tmpPath);
                finfo_close($finfo);
            }
        }
        if (($mimeType === false || $mimeType === null || $mimeType === '') && function_exists('mime_content_type')) {
            $mimeType = mime_content_type($tmpPath);
        }
        if (!is_string($mimeType) || $mimeType === '') {
            return false;
        }
        if (strpos($mimeType, ';') !== false) {
            $mimeParts = explode(';', $mimeType);
            $mimeType = $mimeParts[0];
        }
        return strtolower(trim($mimeType));
    }

    private function mimeMatchesAllowed($actualMime, $allowedMimes)
    {
        foreach ($allowedMimes as $allowedMime) {
            $allowedMime = strtolower($allowedMime);
            if (substr($allowedMime, -2) === '/*') {
                $prefix = substr($allowedMime, 0, -1);
                if (strpos($actualMime, $prefix) === 0) {
                    return true;
                }
            }
            else if ($actualMime === $allowedMime) {
                return true;
            }
        }
        return false;
    }

    private function validateUploadMimeAndContent($name, $tmpPath, &$errorMessage)
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($extension === '' || !isset($this->allowedMimeByExtension[$extension])) {
            $errorMessage = 'File type not allowed';
            return false;
        }
        $detectedMime = $this->detectMimeType($tmpPath);
        if ($detectedMime === false) {
            $errorMessage = 'Unable to determine uploaded file MIME type';
            return false;
        }
        if (!$this->mimeMatchesAllowed($detectedMime, $this->allowedMimeByExtension[$extension])) {
            $errorMessage = 'Detected MIME type ' . $detectedMime . ' does not match allowed type for .' . $extension;
            return false;
        }
        if (in_array($extension, $this->imageExtensions, true) && @getimagesize($tmpPath) === false) {
            $errorMessage = 'Invalid image file content';
            return false;
        }
        return true;
    }
    private function getBulkImportStagingRootPath()
    {
        global $HAXCMS;
        if (!isset($HAXCMS) || !isset($HAXCMS->configDirectory)) {
            return false;
        }
        $stagingRoot = $HAXCMS->configDirectory . '/tmp/imports';
        $resolvedRoot = realpath($stagingRoot);
        if ($resolvedRoot === false || !is_dir($resolvedRoot)) {
            return false;
        }
        return rtrim(str_replace('\\', '/', $resolvedRoot), '/');
    }
    private function isPathWithinRoot($resolvedPath, $resolvedRoot)
    {
        $normalizedPath = rtrim(str_replace('\\', '/', $resolvedPath), '/');
        $normalizedRoot = rtrim(str_replace('\\', '/', $resolvedRoot), '/');
        if ($normalizedPath === $normalizedRoot) {
            return true;
        }
        return strpos($normalizedPath, $normalizedRoot . '/') === 0;
    }
    private function isValidBulkImportTmpPath($tmpPath)
    {
        if (!is_string($tmpPath)) {
            return false;
        }
        $normalized = trim($tmpPath);
        if ($normalized === '' || strpos($normalized, "\0") !== false) {
            return false;
        }
        if (preg_match('/^[A-Za-z][A-Za-z0-9+\.\-]*:/', $normalized)) {
            if (!preg_match('/^[A-Za-z]:[\\\\\/]/', $normalized)) {
                return false;
            }
        }
        if (
            substr($normalized, 0, 1) !== '/' &&
            !preg_match('/^[A-Za-z]:[\\\\\/]/', $normalized)
        ) {
            return false;
        }
        $resolvedSource = realpath($normalized);
        if ($resolvedSource === false || !is_file($resolvedSource)) {
            return false;
        }
        $stagingRoot = $this->getBulkImportStagingRootPath();
        if ($stagingRoot === false) {
            return false;
        }
        return $this->isPathWithinRoot($resolvedSource, $stagingRoot);
    }
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
        $name = $this->stripExecutableExtensionPatterns($upload['name']);
        $validationError = '';
        $isBulkImport = isset($upload['bulk-import']);
        $isUploadSourceValid = false;
        if (isset($upload['tmp_name'])) {
            if ($isBulkImport) {
                $isUploadSourceValid = $this->isValidBulkImportTmpPath($upload['tmp_name']);
            }
            else {
                $isUploadSourceValid = is_uploaded_file($upload['tmp_name']);
            }
        }
        $isAllowedExtension = preg_match($this->allowedUploadPattern, $name);
        $passesMimeValidation = false;
        if ($isUploadSourceValid && $isAllowedExtension) {
            $passesMimeValidation = $this->validateUploadMimeAndContent($name, $upload['tmp_name'], $validationError);
        }
        else if ($isUploadSourceValid && !$isAllowedExtension) {
            $validationError = 'File type not allowed';
        }
        else if (!$isUploadSourceValid) {
            if ($isBulkImport) {
                $validationError = 'Invalid bulk import source';
            }
            else {
                $validationError = 'Invalid upload source';
            }
        }
        // ensure file is an image, video, docx, pdf, etc. of safe file types to allow uploading
        if (
            $isUploadSourceValid &&
            $isAllowedExtension &&
            $passesMimeValidation
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
            if ($validationError !== '') {
                $return = $validationError;
            }
            else {
                $return = 'failed to write ' . $name;
            }
        }
        return array(
            'status' => $status,
            'data' => $return
        );
    }
}
