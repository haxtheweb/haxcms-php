<?php
include_once '../../bootstrapHAX.php';
include_once '../../lib/JSONOutlineSchemaItem.php';
include_once $HAXCMS->configDirectory . '/config.php';
$response = array(
    "data" => array(
        "filename" => "",
        "items" => array(),
    ),
    "status" => 400,
);
// verify this was uploaded with a name of some kind
if (isset($_FILES["upload"]["name"])) {
    $filename = strtolower($_FILES["upload"]["name"]);
    $source = $_FILES["upload"]["tmp_name"];
    $type = $_FILES["upload"]["type"];

    $name = explode(".", $filename);
    // sanitization for the file name
    $actual_name = mb_ereg_replace("([^\w\s\d\-_~,;\[\]\(\).])", '', $name[0]);
    // Remove any runs of periods (thanks falstro!)
    $actual_name = mb_ereg_replace("([\.]{2,})", '', $actual_name);
    $accepted_types = array('application/zip', 'application/x-zip-compressed', 'multipart/x-zip', 'application/x-compressed');
    foreach($accepted_types as $mime_type) {
        if($mime_type == $type) {
            $okay = true;
            break;
        }
    }

    $continue = strtolower($name[1]) == 'zip' ? true : false;
    if(!$continue) {
        $message = "The file you are trying to upload is not a .zip file. Please try again.";
    }

    $path = $HAXCMS->configDirectory . '/tmp/';  // path to tmp directory
    $filenoext = basename ($filename, '.zip');  // absolute path to the directory

    $targetdir = $path . $filenoext; // target directory
    $targetzip = $path . $filename; // target zip file
    $response['data']['filename'] = $filename;
    $zip = new ZipArchive;
    $res = $zip->open($source);
    if ($res === TRUE) {
        // extract it to the path we determined above
        $zip->extractTo($path);
        $zip->close();
        //echo "WOOT! $targetzip extracted to $path";
    } else {
        //echo "Doh! I couldn't open $targetzip";
    }
    // now let's work against the XML structure
    $source = $targetdir . '/e_data/content.xml';
    $name = $actual_name;
    // parse the file
    $xmlfile = file_get_contents($source);
    $ob = simplexml_load_string($xmlfile);
    $json = json_encode($ob);
    $configData = json_decode($json, true);
    // load lessons
    $lessons = $configData['courseContent']['lesson'];
    foreach ($lessons as $key => $lesson) {
        $page = new JSONOutlineSchemaItem();
        $page->title = $lesson['@attributes']['title'];
        $body = "<p><br/></p>";
        $page->contents = $body;
        $page->parent = null;
        $page->indent = 0;
        $page->order = $key;
        $parent = $page->id;
        $cleanTitleParent = $lesson['@attributes']['directory'];
        $page->location = 'pages/' . $cleanTitleParent . '/index.html';
        $page->slug = $cleanTitleParent;
        $page->metadata->created = time();
        $page->metadata->updated = time();
        array_push($response['data']['items'], $page);
        // look for child pages
        if (isset($lesson['page'])) {
            foreach ($lesson['page'] as $key2 => $item) {
                if (isset($item['title'])) {
                    // get a fake item
                    $page = $HAXCMS->outlineSchema->newItem();
                    $page->title = $item['title'];
                    if (isset($item['pagecontent'])) {
                        $body = html_entity_decode($item['pagecontent']);
                        $body = str_replace(
                            ' src="./images/',
                            ' src="files/' . $cleanTitleParent . '/images/',
                            $body
                        );
                        $body = str_replace(
                            ' src="./corefiles/',
                            ' src="files/' . $cleanTitleParent . '/corefiles/',
                            $body
                        );
                        $body = str_replace(
                            ' href="./corefiles/',
                            ' href="files/' . $cleanTitleParent . '/corefiles/',
                            $body
                        );
                        $body = str_replace(
                            ' src="images/',
                            ' src="files/' . $cleanTitleParent . '/images/',
                            $body
                        );
                        $body = str_replace(
                            ' src="corefiles/',
                            ' src="files/' . $cleanTitleParent . '/corefiles/',
                            $body
                        );
                        $body = str_replace(
                            ' href="corefiles/',
                            ' href="files/' . $cleanTitleParent . '/corefiles/',
                            $body
                        );
                    } else {
                        $body = "<p><br/></p>";
                    }
                    $page->contents = $body;
                    $page->parent = $parent;
                    $page->indent = 1;
                    $page->order = $key2;
                    // ensure this location doesn't exist already
                    $loop = 0;
                    $cleanTitle = str_replace(
                        '.html',
                        '',
                        $item['@attributes']['filename']
                    );
                    $page->location =
                        'pages/' .
                        $cleanTitleParent .
                        '/' .
                        $cleanTitle .
                        '/index.html';
                    $page->metadata->created = time();
                    $page->metadata->updated = time();
                    array_push($response['data']['items'], $page);
                }
            }
        }
        $response["status"] = 200;
    }
}
header('Content-Type: application/json');
print json_encode($response);
http_response_code($response["status"]);
exit;
?>
