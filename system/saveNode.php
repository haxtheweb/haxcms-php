<?php
include_once '../system/lib/bootstrapHAX.php';
include_once $HAXCMS->configDirectory . '/config.php';
// test if this is a valid user login
if ($HAXCMS->validateJWT()) {
    header('Content-Type: application/json');
    $site = $HAXCMS->loadSite($HAXCMS->safePost['siteName']);
    if (isset($_POST['body'])) {
        $body = $_POST['body'];
        // we ship the schema with the body post
        if (isset($_POST['schema'])) {
            $schema = $_POST['schema'];
        }
    }
    if (isset($_POST['details'])) {
        $details = $_POST['details'];
    }
    // update the page's content, using manifest to find it
    // this ensures that writing is always to what the file system
    // determines to be the correct page
    if ($page = $site->loadNode($HAXCMS->safePost['nodeId'])) {
        // convert web location for loading into file location for writing
        if (isset($body)) {
            $bytes = $page->writeLocation(
                $body,
                HAXCMS_ROOT .
                    '/' .
                    $HAXCMS->sitesDirectory .
                    '/' .
                    $site->name .
                    '/'
            );
            if ($bytes === false) {
                header('Status: 500');
                print json_encode('failed to write');
            } else {
                // update the updated timestamp
                $page->metadata->updated = time();
                // auto generate a text only description from first 200 chars
                $clean = strip_tags($body);
                $page->description = str_replace(
                    "\n",
                    '',
                    substr($clean, 0, 200)
                );
                $readtime = round(str_word_count($clean) / 200);
                // account for uber small posts
                if ($readtime == 0) {
                  $readtime = 1;
                }
                $page->metadata->readtime = $readtime;
                $site->updateNode($page);
                $site->gitCommit(
                    'Page updated: ' . $page->title . ' (' . $page->id . ')'
                );
                header('Status: 200');
                print json_encode($bytes);
            }
        } elseif (isset($details)) {
            // update the updated timestamp
            $page->metadata->updated = time();
            foreach ($details as $key => $value) {
                // sanitize both sides
                $key = filter_var($key, FILTER_SANITIZE_STRING);
                switch ($key) {
                    case 'location':
                        // check on name
                        $value = filter_var($value, FILTER_SANITIZE_STRING);
                        $cleanTitle = $HAXCMS->cleanTitle($value);
                        // ensure this isn't just saying to keep the same name
                        if (
                            $cleanTitle !=
                            str_replace(
                                'pages/',
                                '',
                                str_replace('/index.html', '', $page->location)
                            )
                        ) {
                            $tmpTitle = $site->getUniqueLocationName(
                                $cleanTitle
                            );
                            $location = 'pages/' . $tmpTitle . '/index.html';
                            // move the folder
                            $site->renamePageLocation(
                                $page->location,
                                $location
                            );
                            $page->location = $location;
                        }
                        break;
                    case 'title':
                    case 'description':
                        $value = filter_var($value, FILTER_SANITIZE_STRING);
                        $page->{$key} = $value;
                        break;
                    case 'created':
                        $value = filter_var($value, FILTER_VALIDATE_INT);
                        $page->metadata->created = $value;
                        break;
                    case 'theme':
                        $themes = $GLOBALS['HAXCMS']->getThemes();
                        $value = filter_var($value, FILTER_SANITIZE_STRING);
                        if (isset($themes->{$value})) {
                            $page->metadata->theme = $themes->{$value};
                            $page->metadata->theme->key = $value;
                        }
                        break;
                    default:
                        // ensure ID is never changed
                        if ($key != 'id') {
                            // support for saving fields
                            if (!isset($page->metadata->fields)) {
                                $page->metadata->fields = new stdClass();
                            }
                            switch (gettype($value)) {
                                case 'array':
                                case 'object':
                                    $page->metadata->fields->{$key} = new stdClass();
                                    foreach ($value as $key2 => $val) {
                                        $page->metadata->fields->{$key}->{$key2} = new stdClass();
                                        $key2 = filter_var(
                                            $key2,
                                            FILTER_VALIDATE_INT
                                        );
                                        foreach ($val as $key3 => $deepVal) {
                                            $key3 = filter_var(
                                                $key3,
                                                FILTER_SANITIZE_STRING
                                            );
                                            $deepVal = filter_var(
                                                $deepVal,
                                                FILTER_SANITIZE_STRING
                                            );
                                            $page->metadata->fields->{$key}->{$key2}->{$key3} = $deepVal;
                                        }
                                    }
                                    break;
                                case 'integer':
                                case 'double':
                                    $value = filter_var(
                                        $value,
                                        FILTER_VALIDATE_INT
                                    );
                                    $page->metadata->fields->{$key} = $value;
                                    break;
                                default:
                                    $value = filter_var(
                                        $value,
                                        FILTER_SANITIZE_STRING
                                    );
                                    $page->metadata->fields->{$key} = $value;
                                    break;
                            }
                        }
                        break;
                }
            }
            $site->updateNode($page);
            $site->gitCommit(
                'Page details updated: ' . $page->title . ' (' . $page->id . ')'
            );
            header('Status: 200');
            print json_encode($page);
        }
        exit();
    }
}
?>
