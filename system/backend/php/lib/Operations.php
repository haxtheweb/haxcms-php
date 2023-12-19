<?php
include_once "JSONOutlineSchemaItem.php";
/**
 * @OA\Info(
 *     title="HAXcms API",
 *     version="",
 *     description="API for interfacing with HAXcms end points",
 *     termsOfService="https://haxtheweb.org",
 *     @OA\Contact(
 *       email="hax@psu.edu"
 *     ),
 *     @OA\License(
 *       name="Apache 2.0",
 *       url="http://www.apache.org/licenses/LICENSE-2.0.html"
 *     )
 * ),
 * @OA\ExternalDocumentation(
 *     description="HAXcms and all things HAX documentations",
 *     url="https://haxtheweb.org/"
 * ),
 * @OA\Tag(
 *     name="hax",
 *     description="Operations required for HAX editor to work",
 *     @OA\ExternalDocumentation(
 *         description="Find out more about hax editor integrations",
 *         url="https://haxtheweb.org/integrations/create-new-ones"
 *     )
 * ),
 * @OA\Tag(
 *     name="cms",
 *     description="Operations for the CMS side"
 * ),
 * @OA\Tag(
 *     name="site",
 *     description="Operations for sites"
 * ),
 * @OA\Tag(
 *     name="node",
 *     description="Operations for individual nodes in a site"
 * ),
 * @OA\Tag(
 *     name="file",
 *     description="Operations for files related to CMS or HAX"
 * ),
 * @OA\Tag(
 *     name="form",
 *     description="Operations related to form submission or generation"
 * ),
 * @OA\Tag(
 *     name="meta",
 *     description="Operations related to metadata management or processes"
 * ),
 * @OA\Tag(
 *     name="git",
 *     description="Operations related to git / version control of the site"
 * ),
 * @OA\Tag(
 *     name="user",
 *     description="Operations for the user account / object"
 * ),
 * @OA\Tag(
 *     name="api",
 *     description="endpoint to generate the API or surrounding API callbacks"
 * ),
 * @OA\Tag(
 *     name="settings",
 *     description="Internal settings related to configuration of this HAXcms deployment"
 * ),
 * @OA\Tag(
 *     name="authenticated",
 *     description="Operations requiring authentication"
 * )
 */
class Operations {
  public $params;
  public $rawParams;
  /**
   * 
   * @OA\Post(
   *    path="/options",
   *    tags={"api"},
   *    @OA\Response(
   *        response="200",
   *        description="API bandaid till we get all the APIs documented. This is an array of callbacks"
   *    )
   * )
   */
  public function options() {
    return get_class_methods($this);
  }
  /**
   * Generate the swagger API documentation for this site
   * 
   * @OA\Post(
   *    path="/",
   *    tags={"api"},
   *    @OA\Response(
   *        response="200",
   *        description="API documentation in YAML"
   *    )
   * )
   * @todo generate JSON:API
   */   
  public function api() {
    $this->openapi();
  }
  /**
   * Generate the swagger API documentation for this site
   * 
   * @OA\Post(
   *    path="/openapi/json",
   *    tags={"api"},
   *    @OA\Response(
   *        response="200",
   *        description="API documentation in JSON"
   *    )
   * )
   */
  public function openapi() {
    // scan this document in order to build the Swagger docs
    // @todo make this scan multiple sources to surface user defined microservices
    $openapi = \OpenApi\scan(dirname(__FILE__) . '/Operations.php');
    // dynamically add the version
    $openapi->info->version = $GLOBALS['HAXCMS']->getHAXCMSVersion();
    $openapi->servers = Array();
    $openapi->servers[0] = new stdClass();
    // generate url dynamically w/ path to the API route
    $openapi->servers[0]->url = $GLOBALS['HAXCMS']->protocol . '://' . $GLOBALS['HAXCMS']->domain . $GLOBALS['HAXCMS']->basePath . $GLOBALS['HAXCMS']->systemRequestBase;
    $openapi->servers[0]->description = "Site list / dashboard for administrator user";
    // output, yaml we have to exit early or we'll get encapsulation
    if (isset($this->params['args']) && $this->params['args'][1] == 'json') {
      return json_decode($openapi->toJson());
    }
    else if (isset($this->params['args']) && $this->params['args'][1] == 'haxSchema') {
      $haxSchema = array('configure' => array());
      $target = null; 
      // support a specific endpoint that a form is desired for
      if (isset($this->params['args'][2]) && !is_null($this->params['args'][2])) {
        $target = $this->params['args'][2];
        $haxSchema = array();
      }
      foreach ($openapi->paths as $obj) {
        if (!is_null($target) && str_replace('/','', $obj->path) != $target) {
          continue;
        }
        $haxSchema[$obj->path] = array();
        $params = array();
        if (isset($obj->post) && isset($obj->post->parameters)) {
          $params = $obj->post->parameters;
        }
        else if (isset($obj->get) && isset($obj->get->parameters)) {
          $params = $obj->get->parameters;
        }
        if (is_array($params)) {
          foreach ($params as $param) {
            $haxSchema[$obj->path][] = json_decode('{
              "property": "' . $param->name . '",
              "title": "' . ucfirst($param->name) . '",
              "description": "' . $param->description . '",
              "inputMethod": "' . $GLOBALS['HAXCMS']->getInputMethod($param->schema->type) . '",
              "required": ' . (isset($param->required) ? (bool) $param->required : 'false') . '
            }');
          }
        }
      }
      return $haxSchema;
    }
    else {
      echo $openapi->toYaml();
      exit;
    }
  }
  /**
   * @OA\Post(
   *    path="/importEvolution",
   *    tags={"cms","authenticated"},
   *    @OA\Parameter(
   *         name="jwt",
   *         description="JSON Web token, obtain by using  /login",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Parameter(
   *         name="upload",
   *         description="zip file via POST to a valid evolution zip format",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="array of items that are valid JSONOutlineSchemaItem with contents included"
   *   )
   * )
   */
  public function importEvolution() {
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
  
      $path = $GLOBALS['HAXCMS']->configDirectory . '/tmp/';  // path to tmp directory
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
          $page->metadata->images = array();
          $page->metadata->videos = array();
          array_push($response['data']['items'], $page);
          // look for child pages
          if (isset($lesson['page'])) {
              foreach ($lesson['page'] as $key2 => $item) {
                  if (isset($item['title'])) {
                      // get a fake item
                      $page = $GLOBALS['HAXCMS']->outlineSchema->newItem();
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
                      $page->metadata->images = array();
                      $page->metadata->videos = array();
                      array_push($response['data']['items'], $page);
                  }
              }
          }
          $response["status"] = 200;
      }
  }
  return $response;
  }

  /**
   * @OA\Post(
   *    path="/rebuildManagedFiles",
   *    tags={"cms","authenticated"},
   *    @OA\Parameter(
   *         name="jwt",
   *         description="JSON Web token, obtain by using  /login",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Parameter(
   *         name="name",
   *         description="machine name of the site to rebuild managed files for",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Rebuild managed files for the site"
   *   )
   * )
   */
  public function rebuildManagedFiles() {
    $status = 200;
    // only allow * on CLI for safety reasons
    if ($GLOBALS['HAXCMS']->isCLI() && isset($this->params['site']['name']) && $this->params['site']['name'] === "__ALL__") {
      // parameter requested but without name
      if ($handle = opendir(HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory)) {
        while (false !== ($item = readdir($handle))) {
          if ($item != "." && $item != ".." && is_dir(HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $item) && file_exists(HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $item . '/site.json')) {
            $site = $GLOBALS['HAXCMS']->loadSite($item);
            // MUST have this value to ensure it's the right site
            if (isset($site->manifest->metadata->site->name)) {
              $site->rebuildManagedFiles();
              $site->updateAlternateFormats();
              $return['managedFilesRebuilt'][] = $item;  
            }
          }
        }
        closedir($handle);
      }
    }
    else if (isset($this->params['site']['name'])) {
      // rebuild a single site
      $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
      if ($site === false) {
        $status = 400;
      }
      else {
        $site->rebuildManagedFiles();
        $site->updateAlternateFormats();
        $return['managedFilesRebuilt'][] = $this->params['site']['name'];  
      }
    }

    if (!isset($return)) {
      $status = 400;
      $return = array();
    }
    
    return array(
      "status" => $status,
      "data" => $return
    );
  }

  /**
   * @OA\Post(
   *    path="/saveManifest",
   *    tags={"cms","authenticated"},
   *    @OA\Parameter(
   *         name="jwt",
   *         description="JSON Web token, obtain by using  /login",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Save the manifest of the site"
   *   )
   * )
   */
  public function saveManifest() {
    // load the site from name
    $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
    // standard form submit
    // @todo 
    // make the form point to a form submission endpoint with appropriate name
    // add a hidden field to the output that always has the haxcms_form_id as well
    // as a dynamically generated Request token relative to the name of the
    // form
    // pull the form schema for the form itself internally
    // ensure ONLY the things that appear in that schema get set
    // if something DID NOT COME ACROSS, don't unset it, only set what shows up
    // if something DID COME ACROSS WE DIDN'T SET, kill the transaction (xss)

    // - snag the form
    // @todo see if we can dynamically save the valus in the same format we loaded
    // the original form in. This would involve removing the vast majority of
    // what's below
    /*if ($GLOBALS['HAXCMS']->validateRequestToken(null, 'form')) {
      $context = array(
        'site' => array(),
        'node' => array(),
      );
      if (isset($this->params['site'])) {
        $context['site'] = $this->params['site'];
      }
      if (isset($this->params['node'])) {
        $context['node'] = $this->params['node'];
      }
      $form = $GLOBALS['HAXCMS']->loadForm($this->params['haxcms_form_id'], $context);
    }*/
    if ($GLOBALS['HAXCMS']->validateRequestToken($this->params['haxcms_form_token'], $this->params['haxcms_form_id'])) {
      $site->manifest->title = strip_tags(
          $this->params['manifest']['site']['manifest-title']
      );
      $site->manifest->description = strip_tags(
          $this->params['manifest']['site']['manifest-description']
      );
      // store version data here so we know where we were when last globally saved
      $site->manifest->metadata->site->version = $GLOBALS['HAXCMS']->getHAXCMSVersion();
      $site->manifest->metadata->site->domain = filter_var(
          $this->params['manifest']['site']['manifest-metadata-site-domain'],
          FILTER_SANITIZE_STRING
      );
      $site->manifest->metadata->site->logo = filter_var(
          $this->params['manifest']['site']['manifest-metadata-site-logo'],
          FILTER_SANITIZE_STRING
      );
      if (!isset($site->manifest->metadata->site->static)) {
        $site->manifest->metadata->site->static = new stdClass();
      }
      if (!isset($site->manifest->metadata->site->settings)) {
        $site->manifest->metadata->site->settings = new stdClass();
      }
      if (isset($this->params['manifest']['site']['manifest-domain'])) {
          $domain = filter_var(
              $this->params['manifest']['site']['manifest-domain'],
              FILTER_SANITIZE_STRING
          );
          // support updating the domain CNAME value
          if ($site->manifest->metadata->site->domain != $domain) {
              $site->manifest->metadata->site->domain = $domain;
              @file_put_contents(
                  $site->directory .
                      '/' .
                      $site->manifest->site->name .
                      '/CNAME',
                  $domain
              );
          }
      }
      // look for a match so we can set the correct data
      foreach ($GLOBALS['HAXCMS']->getThemes() as $key => $theme) {
        if (
            filter_var($this->params['manifest']['theme']['manifest-metadata-theme-element'], FILTER_SANITIZE_STRING) ==
            $key
        ) {
            $site->manifest->metadata->theme = $theme;
        }
      }
      if (!isset($site->manifest->metadata->theme->variables)) {
        $site->manifest->metadata->theme->variables = new stdClass();
      }
      if (isset($this->params['manifest']['theme']['manifest-metadata-theme-variables-image'])) {
        $site->manifest->metadata->theme->variables->image = filter_var(
          $this->params['manifest']['theme']['manifest-metadata-theme-variables-image'],FILTER_SANITIZE_STRING
        );
      }
      if (isset($this->params['manifest']['theme']['manifest-metadata-theme-variables-imageAlt'])) {
        $site->manifest->metadata->theme->variables->imageAlt = filter_var(
          $this->params['manifest']['theme']['manifest-metadata-theme-variables-imageAlt'], FILTER_SANITIZE_STRING
        );
      }
      if (isset($this->params['manifest']['theme']['manifest-metadata-theme-variables-imageLink'])) {
        $site->manifest->metadata->theme->variables->imageLink = filter_var(
          $this->params['manifest']['theme']['manifest-metadata-theme-variables-imageLink'], FILTER_SANITIZE_STRING
        );
      }
      // REGIONS SUPPORT
      if (!isset($site->manifest->metadata->theme->regions)) {
        $site->manifest->metadata->theme->regions = new stdClass();
      }
      // look for a match so we can set the correct data
      $validRegions = array(
        "header",
        "sidebarFirst",
        "sidebarSecond",
        "contentTop",
        "contentBottom",
        "footerPrimary",
        "footerSecondary"
      );
      foreach ($validRegions as $i => $value) {
        if (isset($this->params['manifest']['theme']['manifest-metadata-theme-regions-' . $value])) {
          foreach ($this->params['manifest']['theme']['manifest-metadata-theme-regions-' . $value] as $j => $id) {
            $this->params['manifest']['theme']['manifest-metadata-theme-regions-' . $value][$j] = filter_var($id, FILTER_SANITIZE_STRING);
          }
          $site->manifest->metadata->theme->regions->{$value} = $this->params['manifest']['theme']['manifest-metadata-theme-regions-' . $value];
        }
      }
      if (isset($this->params['manifest']['theme']['manifest-metadata-theme-variables-hexCode'])) {
        $site->manifest->metadata->theme->variables->hexCode = filter_var(
          $this->params['manifest']['theme']['manifest-metadata-theme-variables-hexCode'],FILTER_SANITIZE_STRING
        );
      }
      $site->manifest->metadata->theme->variables->cssVariable = "--simple-colors-default-theme-" . filter_var(
        $this->params['manifest']['theme']['manifest-metadata-theme-variables-cssVariable'], FILTER_SANITIZE_STRING
      ). "-7";
      $site->manifest->metadata->theme->variables->icon = filter_var(
        $this->params['manifest']['theme']['manifest-metadata-theme-variables-icon'],FILTER_SANITIZE_STRING
      );
      if (isset($this->params['manifest']['author']['manifest-license'])) {
          $site->manifest->license = filter_var(
              $this->params['manifest']['author']['manifest-license'],
              FILTER_SANITIZE_STRING
          );
          if (!isset($site->manifest->metadata->author)) {
            $site->manifest->metadata->author = new stdClass();
          }
          $site->manifest->metadata->author->image = filter_var(
              $this->params['manifest']['author']['manifest-metadata-author-image'],
              FILTER_SANITIZE_STRING
          );
          $site->manifest->metadata->author->name = filter_var(
              $this->params['manifest']['author']['manifest-metadata-author-name'],
              FILTER_SANITIZE_STRING
          );
          $site->manifest->metadata->author->email = filter_var(
              $this->params['manifest']['author']['manifest-metadata-author-email'],
              FILTER_SANITIZE_STRING
          );
          $site->manifest->metadata->author->socialLink = filter_var(
              $this->params['manifest']['author']['manifest-metadata-author-socialLink'],
              FILTER_SANITIZE_STRING
          );
      }
      if (isset($this->params['manifest']['seo']['manifest-metadata-site-settings-lang'])) {
        $site->manifest->metadata->site->settings->lang = filter_var(
        $this->params['manifest']['seo']['manifest-metadata-site-settings-lang'],
        FILTER_SANITIZE_STRING
        );
    }
      if (isset($this->params['manifest']['seo']['manifest-metadata-site-settings-pathauto'])) {
          $site->manifest->metadata->site->settings->pathauto = filter_var(
          $this->params['manifest']['seo']['manifest-metadata-site-settings-pathauto'],
          FILTER_VALIDATE_BOOLEAN
          );
      }
      if (isset($this->params['manifest']['seo']['manifest-metadata-site-settings-publishPagesOn'])) {
        $site->manifest->metadata->site->settings->publishPagesOn = filter_var(
        $this->params['manifest']['seo']['manifest-metadata-site-settings-publishPagesOn'],
        FILTER_VALIDATE_BOOLEAN
        );
      }
      if (isset($this->params['manifest']['seo']['manifest-metadata-site-settings-sw'])) {
        $site->manifest->metadata->site->settings->sw = filter_var(
        $this->params['manifest']['seo']['manifest-metadata-site-settings-sw'],
        FILTER_VALIDATE_BOOLEAN
        );
      }
      if (isset($this->params['manifest']['seo']['manifest-metadata-site-settings-forceUpgrade'])) {
        $site->manifest->metadata->site->settings->forceUpgrade = filter_var(
        $this->params['manifest']['seo']['manifest-metadata-site-settings-forceUpgrade'],
        FILTER_VALIDATE_BOOLEAN
        );
      }
      if (isset($this->params['manifest']['seo']['manifest-metadata-site-settings-gaID'])) {
        $site->manifest->metadata->site->settings->gaID = filter_var(
        $this->params['manifest']['seo']['manifest-metadata-site-settings-gaID'],
        FILTER_SANITIZE_STRING
        );
      }
      $site->manifest->metadata->site->updated = time();
      // don't reorganize the structure
      $site->manifest->save(false);
      $site->gitCommit('Manifest updated');
      // rebuild the files that twig processes
      $site->rebuildManagedFiles();
      $site->updateAlternateFormats();
      $site->gitCommit('Managed files updated');
      return $site->manifest;
    }
    else {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid request token',
        )
      );
    }
  }
  /**
   * @OA\Post(
   *    path="/saveOutline",
   *    tags={"cms","authenticated","site"},
   *    @OA\Parameter(
   *         name="jwt",
   *         description="JSON Web token, obtain by using  /login",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Save an entire site outline"
   *   )
   * )
   */
  public function saveOutline() {
    $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
    $siteDirectory = $site->directory . '/' . $site->manifest->metadata->site->name;
    $original = $site->manifest->items;
    $items = $this->rawParams['items'];
    $itemMap = array();
    // items from the POST
    foreach ($items as $key => $item) {
      // get a fake item of the existing
      if (!($page = $site->loadNode($item->id))) {
        $page = $GLOBALS['HAXCMS']->outlineSchema->newItem();
        // we don't trust the front end UUID if it wasn't existing already
        $itemMap[$item->id] = $page->id;
      }
      // set a title if we have one
      if ($item->title != '' && $item->title) {
        $page->title = $item->title;
      }
      $cleanTitle = $GLOBALS['HAXCMS']->cleanTitle($page->title);
      if ($item->parent == null) {
        $page->parent = null;
        $page->indent = 0;
      } else {
        // check the item map as backend dictates unique ID
        if (isset($itemMap[$item->parent])) {
          $page->parent = $itemMap[$item->parent];
        } else {
          // set to the parent id
          $page->parent = $item->parent;
        }
        // move it one indentation below the parent; this can be changed later if desired
        $page->indent = $item->indent;
      }
      if (isset($item->order)) {
        $page->order = (int)$item->order;
      } else {
        $page->order = (int)$key;
      }
      // keep location if we get one already
      if (isset($item->location) && $item->location != '') {
        $page->location = $item->location;
      } else {
        // generate a logical page slug
        $page->location = 'pages/' . $page->id . '/index.html';
      }
      // keep location if we get one already
      if (isset($item->slug) && $item->slug != '') {
      } else {
          // generate a logical page slug
          $page->slug = $site->getUniqueSlugName($cleanTitle, $page, true);
      }
      // verify this exists, front end could have set what they wanted
      // or it could have just been renamed
      // if it doesn't exist currently make sure the name is unique
      if (!$site->loadNode($page->id)) {
        $site->recurseCopy(
            HAXCMS_ROOT . '/system/boilerplate/page/default',
            $siteDirectory . '/' . str_replace('/index.html', '', $page->location)
        );
      }
      // this would imply existing item, lets see if it moved or needs moved
      else {
          $moved = false;
          foreach ($original as $key => $tmpItem) {
              // see if this is something moving as opposed to brand new
              if (
                  $tmpItem->id == $page->id &&
                  $tmpItem->slug != ''
              ) {
                  // core support for automatically managing paths to make them nice
                  if (isset($site->manifest->metadata->site->settings->pathauto) && $site->manifest->metadata->site->settings->pathauto) {
                      $moved = true;
                      $page->slug = $site->getUniqueSlugName($GLOBALS['HAXCMS']->cleanTitle($page->title), $page, true);
                  }
                  else if ($tmpItem->slug != $page->slug) {
                      $moved = true;
                      $page->slug = $GLOBALS['HAXCMS']->generateMachineName($tmpItem->slug);
                  }
              }
          }
          // it wasn't moved and it doesn't exist... let's fix that
          // this is beyond an edge case
          if (
              !$moved &&
              !file_exists($siteDirectory . '/' . $page->location)
          ) {
              $pAuto = false;
              if (isset($site->manifest->metadata->site->settings->pathauto) && $site->manifest->metadata->site->settings->pathauto) {
                $pAuto = true;
              }
              $tmpTitle = $site->getUniqueSlugName($cleanTitle, $page, $pAuto);
              $page->location = 'pages/' . $page->id . '/index.html';
              $page->slug = $tmpTitle;
              $site->recurseCopy(
                  HAXCMS_ROOT . '/system/boilerplate/page/default',
                  $siteDirectory . '/' . str_replace('/index.html', '', $page->location)
              );
          }
      }
      // check for any metadata keys that did come over
      foreach ($item->metadata as $key => $value) {
          $page->metadata->{$key} = $value;
      }
      // safety check for new things
      if (!isset($page->metadata->created)) {
          $page->metadata->created = time();
          $page->metadata->images = array();
          $page->metadata->videos = array();
      }
      // always update at this time
      $page->metadata->updated = time();
      if ($site->loadNode($page->id)) {
          $site->updateNode($page);
      } else {
          $site->manifest->addItem($page);
      }
    }
    // process any duplicate / contents requests we had now that structure is sane
    // including potentially duplication of material from something
    // we are about to act on and now that we have the map
    $items = $this->rawParams['items'];
    foreach ($items as $key => $item) {
      // load the item, or the item as built out of the itemMap
      // since we reset the UUID on creation
      if (!($page = $site->loadNode($item->id))) {
        $page = $site->loadNode($itemMap[$item->id]);
      }
      if (isset($item->duplicate)) {
        // load the node we are duplicating with support for the same map needed for page loading
        if (!$nodeToDuplicate = $site->loadNode($item->duplicate)) {
          $nodeToDuplicate = $site->loadNode($itemMap[$item->duplicate]);
        }
        $content = $site->getPageContent($nodeToDuplicate);
        // write it to the file system
        $bytes = $page->writeLocation(
          $content,
          HAXCMS_ROOT .
          '/' .
          $GLOBALS['HAXCMS']->sitesDirectory .
          '/' .
          $site->manifest->metadata->site->name .
          '/'
        );
      }
      // contents that were shipped across, and not null, take priority over a dup request
      if (isset($item->contents) && $item->contents && $item->contents != '') {
        // write it to the file system
        $bytes = $page->writeLocation(
          $item->contents,
          HAXCMS_ROOT .
          '/' .
          $GLOBALS['HAXCMS']->sitesDirectory .
          '/' .
          $site->manifest->metadata->site->name .
          '/'
        );
      }
    }
    $items = $this->rawParams['items'];
    // now, we can finally delete as content operations have finished
    foreach ($items as $key => $item) {
      // verify if we were told to delete this item via flag not in the real spec
      if (isset($item->delete) && $item->delete == TRUE) {
        // load the item, or the item as built out of the itemMap
        // since we reset the UUID on creation
        if (!($page = $site->loadNode($item->id))) {
          $page = $site->loadNode($itemMap[$item->id]);
        }
        $site->deleteNode($page);
        $site->gitCommit(
          'Page deleted: ' . $page->title . ' (' . $page->id . ')'
        );
      }
    }
    $site->manifest->save();
    // now, we need to look for orphans if we deleted anything
    $orphanCheck = $site->manifest->items;
    foreach ($orphanCheck as $key => $item) {
      // just to be safe..
      if ($page = $site->loadNode($item->id)) {
        // ensure that parent is valid to rescue orphan items
        if ($page->parent != null && !($parentPage = $site->loadNode($page->parent))) {
          $page->parent = null;
          // force to bottom of things while still being in old order if lots of things got axed
          $page->order = (int)$page->order + count($site->manifest->items) - 1;
          $site->updateNode($page);
        }
      }
    }
    $site->manifest->metadata->site->updated = time();
    $site->manifest->save();
    // update alt formats like rss as we did massive changes
    $site->updateAlternateFormats();
    $site->gitCommit('Outline updated in bulk');
    return $site->manifest->items;
  }
  /**
   * @OA\Post(
   *     path="/createNode",
   *     tags={"cms","authenticated","node"},
   *     @OA\Parameter(
   *         name="jwt",
   *         description="JSON Web token, obtain by using  /login",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *     ),
   *     @OA\RequestBody(
   *        @OA\MediaType(
   *             mediaType="application/json",
   *             @OA\Schema(
   *                 @OA\Property(
   *                     property="site",
   *                     type="object"
   *                 ),
   *                 @OA\Property(
   *                     property="items",
   *                     type="object"
   *                 ),
   *                 @OA\Property(
   *                     property="node",
   *                     type="object"
   *                 ),
   *                 @OA\Property(
   *                     property="indent",
   *                     type="number"
   *                 ),
   *                 @OA\Property(
   *                     property="order",
   *                     type="number"
   *                 ),
   *                 @OA\Property(
   *                     property="parent",
   *                     type="string"
   *                 ),
   *                 @OA\Property(
   *                     property="description",
   *                     type="string"
   *                 ),
   *                 @OA\Property(
   *                     property="metadata",
   *                     type="object"
   *                 ),
   *                 required={"site","node"},
   *                 example={
   *                    "site": {
   *                      "name": "mysite"
   *                    },
   *                    "items": [{},{}],
   *                    "node": {
   *                      "id": null,
   *                      "title": "Cool post",
   *                      "location": null,
   *                      "duplicate": "item-123-ddd-333"
   *                    },
   *                    "indent": null,
   *                    "order": null,
   *                    "parent": null,
   *                    "description": "An example description for the post",
   *                    "metadata": {"tags": "metadata,can,be,whatever,you,want","other":"stuff"}
   *                 }
   *             )
   *         )
   *     ),
   *    @OA\Response(
   *        response="200",
   *        description="object with full properties returned"
   *   )
   * )
   */
  public function createNode() {
    $nodeParams = $this->params;
    $site = $GLOBALS['HAXCMS']->loadSite(strtolower($nodeParams['site']['name']));
    // implies we've been TOLD to create nodes
    // this is typically from a docx import
    if (isset($nodeParams['items'])) {
      // create pages
      for ($i=0; $i < count($nodeParams['items']); $i++) {
        // outline-designer allows delete + confirmation but we don't have anything
        // so instead, just don't process the thing in question if asked to delete it
        if (isset($nodeParams['items'][$i]['delete']) && $nodeParams['items'][$i]['delete'] == TRUE) {
          // do nothing
        }
        else {
          $item = $site->addPage(
            $nodeParams['items'][$i]['parent'], 
            $nodeParams['items'][$i]['title'], 
            'html', 
            $nodeParams['items'][$i]['slug'],
            $nodeParams['items'][$i]['id'],
            $nodeParams['items'][$i]['indent'],
            $nodeParams['items'][$i]['contents']
          );  
        }
      }
      $site->gitCommit(count($nodeParams['items']) . ' pages added'); 
    }
    else {
      // generate a new item based on the site
      $item = $site->itemFromParams($nodeParams);
      $item->metadata->images = array();
      $item->metadata->videos = array();
      // generate the boilerplate to fill this page
      $site->recurseCopy(
          HAXCMS_ROOT . '/system/boilerplate/page/default',
          $site->directory .
              '/' .
              $site->manifest->metadata->site->name .
              '/' .
              str_replace('/index.html', '', $item->location)
      );
      // add the item back into the outline schema
      $site->manifest->addItem($item);
      $site->manifest->save();
      // support for duplicating the content of another item
      if (isset($nodeParams['node']['duplicate'])) {
        // verify we can load this id
        if ($nodeToDuplicate = $site->loadNode($nodeParams['node']['duplicate'])) {
          $content = $site->getPageContent($nodeToDuplicate);
          // verify we actually have the id of an item that we just created
          if ($page = $site->loadNode($item->id)) {
            // write it to the file system
            // this all seems round about but it's more secure
            $bytes = $page->writeLocation(
              $content,
              HAXCMS_ROOT .
              '/' .
              $GLOBALS['HAXCMS']->sitesDirectory .
              '/' .
              $site->manifest->metadata->site->name .
              '/'
            );
          }
        }
      }
      // implies front end was told to generate a page with set content
      // this is possible when importing and processing a file to generate
      // html which becomes the boilerplated content in effect
      else if (isset($nodeParams['node']['contents'])) {
        if ($page = $site->loadNode($item->id)) {
          // write it to the file system
          $bytes = $page->writeLocation(
            $nodeParams['node']['contents'],
            HAXCMS_ROOT .
            '/' .
            $GLOBALS['HAXCMS']->sitesDirectory .
            '/' .
            $site->manifest->metadata->site->name .
            '/'
          );
        }
      }
      $site->gitCommit('Page added:' . $item->title . ' (' . $item->id . ')'); 
      // update the alternate formats as a new page exists
      $site->updateAlternateFormats();
    }
    return array(
      'status' => 200,
      'data' => $item
    );
  }
  /**
   * @OA\Post(
   *    path="/saveNode",
   *    tags={"cms","authenticated","node"},
   *    @OA\Parameter(
   *         name="jwt",
   *         description="JSON Web token, obtain by using  /login",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Save a node"
   *   )
   * )
   */
  public function saveNode() {
    $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
    $schema = array();
    if (isset($this->params['node']['body'])) {
      $body = $this->params['node']['body'];
      // we ship the schema with the body
      if (isset($this->params['node']['schema'])) {
        $schema = $this->params['node']['schema'];
      }
    }
    $details = array();
    // if we have details object then merge configure and advanced
    if (isset($this->params['node']['details'])) {
      foreach ($this->params['node']['details']['node']['configure'] as $key => $value) {
        $details[$key] = $value;
      }
      foreach ($this->params['node']['details']['node']['advanced'] as $key => $value) {
        $details[$key] = $value;
      }
    }
    // update the page's content, using manifest to find it
    // this ensures that writing is always to what the file system
    // determines to be the correct page
    // @todo review this step by step
    if ($page = $site->loadNode($this->params['node']['id'])) {
      // convert web location for loading into file location for writing
      if (isset($body)) {
        $bytes = 0;
        // see if we have multiple pages / this page has been told to split into multiple
        $pageData = $GLOBALS['HAXCMS']->pageBreakParser($body);
        foreach($pageData as $data) {
          // trap to ensure if front-end didnt send a UUID for id then we make it
          if (!isset($data["attributes"]["title"])) {
            $data["attributes"]["title"] = 'New page';
          }
          // to avoid critical error in parsing, we defer to the POST's ID always
          // this also blocks multiple page breaks if it doesn't exist as we don't allow
          // the front end to dictate what gets created here
          if (!isset($data["attributes"]["item-id"])) {
            $data["attributes"]["item-id"] = $this->params['node']['id'];
          }
          if (!isset($data["attributes"]["path"]) || $data["attributes"]["path"] == '#') {
            $data["attributes"]["path"] = $data["attributes"]["title"];
          }
          // verify this pages does not exist; this is only possible if we parse multiple page-break
          // a capability that is not supported currently beyond experiments
          if (!$page = $site->loadNode($data["attributes"]["item-id"])) {
            // generate a new item based on the site
            $nodeParams = array(
              "node" => array(
                "title" => $data["attributes"]["title"],
                "id" => $data["attributes"]["item-id"],
                "location" => $data["attributes"]["path"],
              )
            );
            $item = $site->itemFromParams($nodeParams);
            // generate the boilerplate to fill this page
            $site->recurseCopy(
                HAXCMS_ROOT . '/system/boilerplate/page/default',
                $site->directory .
                    '/' .
                    $site->manifest->metadata->site->name .
                    '/' .
                    str_replace('/index.html', '', $item->location)
            );
            // add the item back into the outline schema
            $site->manifest->addItem($item);
            $site->manifest->save();
            $site->gitCommit('Page added:' . $item->title . ' (' . $item->id . ')');
            // possible the item-id had to be made by back end
            $data["attributes"]["item-id"] = $item->id;
          }
          // now this should exist if it didn't a minute ago
          $page = $site->loadNode($data["attributes"]["item-id"]);
          // @todo make sure that we stripped off page-break
          // and now save WITHOUT the top level page-break
          // to avoid duplication issues
          $bytes = $page->writeLocation(
            $data['content'],
            HAXCMS_ROOT .
            '/' .
            $GLOBALS['HAXCMS']->sitesDirectory .
            '/' .
            $site->manifest->metadata->site->name .
            '/'
          );
          if ($bytes === false) {
            return array(
              '__failed' => array(
                'status' => 500,
                'message' => 'failed to write',
              )
            );
          } else {
              // sanity check
              if (!isset($page->metadata)) {
                $page->metadata = new stdClass();
              }
              // update attributes in the page
              if (isset($data["attributes"]["title"])) {
                $page->title = $data["attributes"]["title"];
              }
              if (isset($data["attributes"]["slug"])) {
                // account for x being the only front end reserved route
                if ($data["attributes"]["slug"] == "x") {
                  $data["attributes"]["slug"] = "x-x";
                }
                // same but trying to force a sub-route; paths cannot conflict with front end
                if (substr( $data["attributes"]["slug"], 0, 2 ) == "x/") {
                  $data["attributes"]["slug"] = str_replace('x/', 'x-x/', $data["attributes"]["slug"]);
                }
                // machine name should more aggressively scrub the slug than clean title
                $page->slug = $GLOBALS['HAXCMS']->generateMachineName($data["attributes"]["slug"]);
              }
              if (isset($data["attributes"]["parent"])) {
                $page->parent = $data["attributes"]["parent"];
              }
              else {
                $page->parent = null;
              }
              // allow setting theme via page break
              if (isset($data["attributes"]["developer-theme"]) && $data["attributes"]["developer-theme"] != '') {
                $themes = $GLOBALS['HAXCMS']->getThemes();
                $value = filter_var($data["attributes"]["developer-theme"], FILTER_SANITIZE_STRING);
                // support for removing the custom theme or applying none
                if ($value == '_none_' || $value == '' || !$value || !isset($themes->{$value})) {
                  unset($page->metadata->theme);
                }
                // ensure it exists
                else if (isset($themes->{$value})) {
                  $page->metadata->theme = $themes->{$value};
                  $page->metadata->theme->key = $value;
                }
              }
              else if (isset($page->metadata->theme)) {
                unset($page->metadata->theme);
              }
              if (isset($data["attributes"]["depth"])) {
                $page->indent = (int)$data["attributes"]["depth"];
              }
              if (isset($data["attributes"]["order"])) {
                $page->order = (int)$data["attributes"]["order"];
              }
              // boolean so these are either there or not
              // historically we are published if this value is not set
              // and that will remain true however as we save / update pages
              // this will ensure that we set things to published
              if (isset($data["attributes"]["published"])) {
                $page->metadata->published = true;
              }
              else {
                $page->metadata->published = false;
              }
              // support for defining and updating page type
              if (isset($data["attributes"]["page-type"]) && $data["attributes"]["page-type"] != '') {
                $page->metadata->pageType = $data["attributes"]["page-type"];
              }
              // they sent across nothing but we had something previously
              else if (isset($page->metadata->pageType)) {
                unset($page->metadata->pageType);
              }
              // support for defining and updating hideInMenu
              if (isset($data["attributes"]["hide-in-menu"])) {
                $page->metadata->hideInMenu = true;
              }
              else {
                $page->metadata->hideInMenu = false;
              }
              // support for defining and updating related-items
              if (isset($data["attributes"]["related-items"]) && $data["attributes"]["related-items"] != '') {
                $page->metadata->relatedItems = $data["attributes"]["related-items"];
              }
              // they sent across nothing but we had something previously
              else if (isset($page->metadata->relatedItems)) {
                unset($page->metadata->relatedItems);
              }
              // support for defining and updating image
              if (isset($data["attributes"]["image"]) && $data["attributes"]["image"] != '') {
                $page->metadata->image = $data["attributes"]["image"];
              }
              // they sent across nothing but we had something previously
              else if (isset($page->metadata->image)) {
                unset($page->metadata->image);
              }
              // support for defining and updating page type
              if (isset($data["attributes"]["tags"]) && $data["attributes"]["tags"] != '') {
                $page->metadata->tags = $data["attributes"]["tags"];
              }
              // they sent across nothing but we had something previously
              else if (isset($page->metadata->tags)) {
                unset($page->metadata->tags);
              }
              // support for defining and updating page type
              if (isset($data["attributes"]["icon"]) && $data["attributes"]["icon"] != '') {
                $page->metadata->icon = $data["attributes"]["icon"];
              }
              // they sent across nothing but we had something previously
              else if (isset($page->metadata->icon)) {
                unset($page->metadata->icon);
              }
              // support for defining an image to represent the page
              if (isset($data["attributes"]["image"]) && $data["attributes"]["image"] != '') {
                $page->metadata->image = $data["attributes"]["image"];
              }
              // they sent across nothing but we had something previously
              else if (isset($page->metadata->image)) {
                unset($page->metadata->image);
              }
              if (!isset($data["attributes"]["locked"])) {
                $page->metadata->locked = false;
              }
              else {
                $page->metadata->locked = true;
              }
              // update the updated timestamp
              $page->metadata->updated = time();
              $clean = strip_tags($body);
              // auto generate a text only description from first 200 chars
              // unless we were sent one to use
              if (isset($data["attributes"]["description"]) && $data["attributes"]["description"] != '') {
                $page->description = $data["attributes"]["description"];
              }
              else {
                $page->description = str_replace(
                  "\n",
                  '',
                  substr($clean, 0, 200)
              );
              }
              $readtime = round(str_word_count($clean) / 200);
              // account for uber small body
              if ($readtime == 0) {
                $readtime = 1;
              }
              $page->metadata->readtime = $readtime;
              // reset bc we rebuild this each page save
              $page->metadata->videos = array();
              $page->metadata->images = array();
              // pull schema apart and seee if we have any images
              // that other things could use for metadata / theming purposes
              foreach ($schema as $element) {
                switch($element['tag']) {
                  case 'img':
                    if (isset($element['properties']['src'])) {
                      array_push($page->metadata->images, $element['properties']['src']);
                    }
                  break;
                  case 'a11y-gif-player':
                    if (isset($element['properties']['src'])) {
                      array_push($page->metadata->images, $element['properties']['src']);
                    }
                  break;
                  case 'media-image':
                    if (isset($element['properties']['source'])) {
                      array_push($page->metadata->images, $element['properties']['source']);
                    }
                  break;
                  case 'video-player':
                    if (isset($element['properties']['source'])) {
                      array_push($page->metadata->videos, $element['properties']['source']);
                    }
                  break;
                }
              }
              $site->updateNode($page);
              $site->gitCommit(
                'Page updated: ' . $page->title . ' (' . $page->id . ')'
              );
          }
        }
        return array(
          'status' => 200,
          'data' => $page
        );
      }
    }
  }
  /**
   * @OA\Post(
   *    path="/deleteNode",
   *    tags={"cms","authenticated","node"},
   *    @OA\Parameter(
   *         name="jwt",
   *         description="JSON Web token, obtain by using  /login",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Delete a node"
   *   )
   * )
   */
  public function deleteNode() {
    $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
    // update the page's content, using manifest to find it
    // this ensures that writing is always to what the file system
    // determines to be the correct page
    if ($page = $site->loadNode($this->params['node']['id'])) {
        if ($site->deleteNode($page) === false) {
          return array(
            '__failed' => array(
              'status' => 500,
              'message' => 'failed to delete',
            )
          );
        } else {
          // now, we need to look for orphans if we deleted anything
          $orphanCheck = $site->manifest->items;
          foreach ($orphanCheck as $key => $item) {
            // just to be safe..
            if ($page = $site->loadNode($item->id)) {
              // ensure that parent is valid to rescue orphan items
              if ($page->parent != null && !($parentPage = $site->loadNode($page->parent))) {
                $page->parent = null;
                // force to bottom of things while still being in old order if lots of things got axed
                $page->order = (int)$page->order + count($site->manifest->items) - 1;
                $site->updateNode($page);
              }
            }
          }
          $site->gitCommit(
            'Page deleted: ' . $page->title . ' (' . $page->id . ')'
          );
          return array(
            'status' => 200,
            'data' => $page
          );
        }
        exit();
    } else {
      return array(
        '__failed' => array(
          'status' => 500,
          'message' => 'failed to load',
        )
      );
    }
  }
  /**
   * @OA\Post(
   *    path="/siteUpdateAlternateFormats",
   *    tags={"cms","authenticated","meta"},
   *    @OA\Parameter(
   *         name="jwt",
   *         description="JSON Web token, obtain by using  /login",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Update the alternative formats surrounding a site"
   *   )
   * )
   */
  public function siteUpdateAlternateFormats() {
    $format = NULL;
    $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
    if (isset($this->params['format'])) {
      $format = $this->params['format'];
    }
    $site->updateAlternateFormats($format);
  }
  /**
   * @OA\Post(
   *    path="/revertCommit",
   *    tags={"cms","authenticated","meta","git","site"},
   *    @OA\Parameter(
   *         name="jwt",
   *         description="JSON Web token, obtain by using  /login",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Revert the last commit to the git repo backing the site"
   *   )
   * )
   */
  public function revertCommit() {
    $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
    // this will revert the top commit
    $site->gitRevert();
    return TRUE;
  }
  /**
   * @OA\Get(
   *    path="/connectionSettings",
   *    tags={"cms"},
   *    @OA\Response(
   *        response="200",
   *        description="Generate the connection settings dynamically for implying we have a PHP backend"
   *   )
   * )
   */
  public function connectionSettings() {
    // need to return this as if it was a javascript file, weird looking for sure
    return array(
      '__noencode' => array(
        'status' => 200,
        'contentType' => 'application/javascript',
        'message' => 'window.appSettings = ' . json_encode($GLOBALS['HAXCMS']->appJWTConnectionSettings($GLOBALS['HAXCMS']->basePath)) . ';',
      )
    );
  }
  /**
   * 
   * HAX EDITOR CALLBACKS
   * 
   */

  /**
   * @OA\GET(
   *    path="/generateAppStore",
   *    tags={"hax","api"},
   *    @OA\Parameter(
   *         name="app-store-token",
   *         description="security token for appstore",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Generate the AppStore spec for HAX editor directions"
   *   )
   * )
   */
  public function generateAppStore() {
    // test if this is a valid user login with this specialty token that HAX looks for
    if (
      isset($this->params['app-store-token']) &&
      $GLOBALS['HAXCMS']->validateRequestToken($this->params['app-store-token'], 'appstore')
    ) {
      $haxService = new HAXAppStoreService();
      $apikeys = array();
      $baseApps = $haxService->baseSupportedApps();
      foreach ($baseApps as $key => $app) {
        if (
          isset($GLOBALS['HAXCMS']->config->appStore->apiKeys->{$key}) &&
          $GLOBALS['HAXCMS']->config->appStore->apiKeys->{$key} != ''
        ) {
          $apikeys[$key] = $GLOBALS['HAXCMS']->config->appStore->apiKeys->{$key};
        }
      }
      $appStore = $haxService->loadBaseAppStore($apikeys);
      // pull in the core one we supply, though only upload works currently
      $tmp = json_decode($GLOBALS['HAXCMS']->siteConnectionJSON());
      array_push($appStore, $tmp);
      if (isset($GLOBALS['HAXCMS']->config->appStore->stax)) {
          $staxList = $GLOBALS['HAXCMS']->config->appStore->stax;
      } else {
          $staxList = $haxService->loadBaseStax();
      }
      if (isset($GLOBALS['HAXCMS']->config->appStore->blox)) {
          $bloxList = $GLOBALS['HAXCMS']->config->appStore->blox;
      } else {
          $bloxList = $haxService->loadBaseBlox();
      }
      if (isset($GLOBALS['HAXCMS']->config->appStore->autoloader)) {
          $autoloaderList = $GLOBALS['HAXCMS']->config->appStore->autoloader;
      } else {
          $autoloaderList = json_decode('
        [
          "lesson-overview",
          "lesson-highlight",
          "video-player",
          "meme-maker",
          "lrn-aside",
          "grid-plate",
          "magazine-cover",
          "image-compare-slider",
          "license-element",
          "self-check",
          "multiple-choice",
          "oer-schema",
          "hero-banner",
          "task-list",
          "lrn-table",
          "media-image",
          "lrndesign-blockquote",
          "a11y-gif-player",
          "wikipedia-query",
          "lrn-vocab",
          "full-width-image",
          "person-testimonial",
          "citation-element",
          "stop-note",
          "learning-component",
          "mark-the-words",
          "twitter-embed",
          "spotify-embed",
          "place-holder",
          "lrn-math",
          "q-r",
          "lrndesign-gallery",
          "lrndesign-timeline"
        ]
        ');
      }
      return array(
          'status' => 200,
          'apps' => $appStore,
          'stax' => $staxList,
          'blox' => $bloxList,
          'autoloader' => $autoloaderList
      );
    }
  }
  /**
   * @OA\Post(
   *    path="/getUserData",
   *    tags={"cms","authenticated","user","settings"},
   *    @OA\Parameter(
   *         name="jwt",
   *         description="JSON Web token, obtain by using  /login",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Load data about the logged in user"
   *   )
   * )
   */
  public function getUserData() {
    return array(
      'status' => 200,
      'data' => $GLOBALS['HAXCMS']->userData
    );
  }
  /**
   * @OA\Post(
   *    path="/formLoad",
   *    tags={"cms","authenticated","form"},
   *    @OA\Parameter(
   *         name="jwt",
   *         description="JSON Web token, obtain by using  /login",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Load a form based on ID"
   *   )
   * )
   */
  public function formLoad() {
    if ($GLOBALS['HAXCMS']->validateRequestToken(null, 'form')) {
      $context = array(
        'site' => array(),
        'node' => array(),
      );
      if (isset($this->params['site'])) {
        $context['site'] = $this->params['site'];
      }
      if (isset($this->params['node'])) {
        $context['node'] = $this->params['node'];
      }
      // @todo add support for hooking in multiple
      $form = $GLOBALS['HAXCMS']->loadForm($this->params['haxcms_form_id'], $context);
      if (isset($form->fields['__failed'])) {
        return array(
          $form->fields
        );
      }
      return array(
        'status' => 200,
        'data' => $form
      );
    }
    else {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid request token',
        )
      );
    }
  }
  /**
   * @OA\Post(
   *    path="/formProcess",
   *    tags={"cms","authenticated","form"},
   *    @OA\Parameter(
   *         name="jwt",
   *         description="JSON Web token, obtain by using  /login",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Process a form based on ID and input data"
   *   )
   * )
   */
  public function formProcess() {
    if ($GLOBALS['HAXCMS']->validateRequestToken($this->params['haxcms_form_token'], $this->params['haxcms_form_id'])) {
      $context = array(
        'site' => array(),
        'node' => array(),
      );
      if (isset($this->params['site'])) {
        $context['site'] = $this->params['site'];
      }
      if (isset($this->params['node'])) {
        $context['node'] = $this->params['node'];
      }
      $form = $GLOBALS['HAXCMS']->processForm($this->params['haxcms_form_id'], $this->params, $context);
      if (isset($form->fields['__failed'])) {
        return array(
          $form->fields
        );
      }
      return array(
        'status' => 200,
        'data' => $form
      );
    }
    else {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid request token',
        )
      );
    }
  }
  /**
   * @OA\Post(
   *    path="/listFiles",
   *    tags={"hax","authenticated","file"},
   *    @OA\Parameter(
   *         name="jwt",
   *         description="JSON Web token, obtain by using  /login",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Load existing files for presentation in HAX find area"
   *   )
   * )
   */
  public function listFiles() {
    $files = array();
    $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
    $search = (isset($this->params['filename'])) ? $this->params['filename'] : '';
    // build files directory path
    $fileDir = $site->directory . '/' . $site->manifest->metadata->site->name . '/files';
    if ($handle = opendir($fileDir)) {
      while (false !== ($file = readdir($handle))) {
        // ignore system files
          if (
              $file != "." &&
              $file != ".." &&
              $file != '.gitkeep' &&
              $file != '._.DS_Store' &&
              $file != '.DS_Store'
          ) {
              // ensure this is a file and if we are searching for results then return only exact ones
              if (is_file($fileDir . '/' . $file) && ($search == "" || strpos($file, $search) || strpos($file, $search) === 0)) {
                // @todo thumbnail support for non image media / thumbnails in general via internal cache / file call
                $files[] = array(
                  'path' => 'files/' . $file,
                  'fullUrl' =>
                      $GLOBALS['HAXCMS']->basePath .
                      $GLOBALS['HAXCMS']->sitesDirectory .
                      $fileDir . '/' .
                      $file,
                  'url' => 'files/' . $file,
                  'mimetype' => mime_content_type($fileDir . '/' . $file),
                  'name' => $file
                );
              }
          }
      }
      closedir($handle);
  }
    return $files;
  }
  /**
   * @OA\Post(
   *    path="/login",
   *    tags={"cms","user"},
   *    description="Attempt a user login",
   *    @OA\Parameter(
   *     description="User name",
   *     example="admin",
   *     name="u",
   *     in="query",
   *     required=true,
   *     @OA\Schema(type="string")
   *   ),
   *   @OA\Parameter(
   *     description="Password",
   *     example="admin",
   *     name="p",
   *     in="query",
   *     required=true,
   *     @OA\Schema(type="string")
   *   ),
   *    @OA\Response(
   *        response="200",
   *        description="JWT token as response"
   *   ),
   *    @OA\Response(
   *        response="403",
   *        description="Invalid token / Login is required"
   *   )
   * )
   */
  public function login() {
    // if we don't have a user and the don't answer, bail
    if (isset($this->params['username']) && isset($this->params['password'])) {
      // _ paranoia
      $u = $this->params['username'];
      // driving me insane
      $p = $this->params['password'];
      // _ paranoia ripping up my brain
      // test if this is a valid user login
      if (!$GLOBALS['HAXCMS']->testLogin($u, $p, true)) {
        return array(
          '__failed' => array(
            'status' => 403,
            'message' => 'Access denied',
          )
        );
      } else {
          // set a refresh_token COOKIE that will ship w/ all calls automatically
          setcookie('haxcms_refresh_token', $GLOBALS['HAXCMS']->getRefreshToken($u), $_expires = 0, $_path = '/', $_domain = '', $_secure = false, $_httponly = true);
          return array(
            "status" => 200,
            "jwt" => $GLOBALS['HAXCMS']->getJWT($u),
          );
      }
    }
    //old way
    // if we don't have a user and the don't answer, bail
    else if (isset($this->params['u']) && isset($this->params['p'])) {
      // _ paranoia
      $u = $this->params['u'];
      // driving me insane
      $p = $this->params['p'];
      // _ paranoia ripping up my brain
      // test if this is a valid user login
      if (!$GLOBALS['HAXCMS']->testLogin($u, $p, true)) {
        return array(
          '__failed' => array(
            'status' => 403,
            'message' => 'Access denied',
          )
        );
      } else {
          // set a refresh_token COOKIE that will ship w/ all calls automatically
          setcookie('haxcms_refresh_token', $GLOBALS['HAXCMS']->getRefreshToken($u), $_expires = 0, $_path = '/', $_domain = '', $_secure = false, $_httponly = true);
          return $GLOBALS['HAXCMS']->getJWT($u);
      }
    }
    // login end point requested yet a jwt already exists
    // this is something of a revalidate case
    else if (isset($this->params['jwt'])) {
      return array(
        "status" => 200,
        "jwt" => $GLOBALS['HAXCMS']->validateJWT(),
      );
    }
    else {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'Login is required',
        )
      );
    } 
  }
  /**
   * @OA\Post(
   *    path="/logout",
   *    tags={"cms","user"},
   *    @OA\Response(
   *        response="200",
   *        description="User logout, front end will kill token"
   *   )
   * )
   */
  public function logout() {
    return array(
      "status" => 200,
      "data" => 'loggedout',
    );
  }
  /**
   * @OA\Post(
   *    path="/refreshAccessToken",
   *    tags={"cms","user"},
   *    @OA\Response(
   *        response="200",
   *        description="User access token for refreshing JWT when it goes stale"
   *   )
   * )
   */
  public function refreshAccessToken() {
    // check that we have a valid refresh token
    $validRefresh = $GLOBALS['HAXCMS']->validateRefreshToken(FALSE);
    // if we have a valid refresh token then issue a new access token
    if ($validRefresh) {
      return array(
        "status" => 200,
        "jwt" => $GLOBALS['HAXCMS']->getJWT($validRefresh->user),
      );
    }
    else {
      // this failed so unset the cookie
      setcookie('haxcms_refresh_token', '', 1);
      return array(
        '__failed' => array(
          'status' => 401,
          'message' => 'haxcms_refresh_token:invalid',
        )
      );
    }
  }
  /**
   * @OA\Post(
   *    path="/setUserPhoto",
   *    tags={"cms","authenticated","user"},
   *    @OA\Parameter(
   *         name="jwt",
   *         description="JSON Web token, obtain by using  /login",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Set the user's uploaded photo"
   *   )
   * )
   */
  public function setUserPhoto() {
    // @todo might want to scrub prior to this level but not sure
    if (isset($_FILES['file-upload'])) {
      $upload = $_FILES['file-upload'];
      $file = new HAXCMSFile();
      $fileResult = $file->save($upload, 'system/user/files', null, 'thumbnail');
      if ($fileResult['status'] == 500) {
        return array(
          '__failed' => array(
            'status' => 500,
            'message' => 'failed to write',
          )
        );
      }
      // save this back to the user data object
      $values = new stdClass();
      $values->userPicture = $fileResult['data']['file']['fullUrl'];
      $GLOBALS['HAXCMS']->setUserData($values);
      return $fileResult;
    }
  }
  /**
   * @OA\Post(
   *    path="/saveFile",
   *    tags={"hax","authenticated","file"},
   *    @OA\Parameter(
   *         name="jwt",
   *         description="JSON Web token, obtain by using  /login",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Parameter(
   *         name="file-upload",
   *         description="File to upload",
   *         in="header",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\RequestBody(
   *        @OA\MediaType(
   *             mediaType="application/json",
   *             @OA\Schema(
   *                 @OA\Property(
   *                     property="site",
   *                     type="object"
   *                 ),
   *                 @OA\Property(
   *                     property="node",
   *                     type="object"
   *                 ),
   *                 required={"site"},
   *                 example={
   *                    "site": {
   *                      "name": "mynewsite"
   *                    },
   *                    "node": {
   *                      "id": ""
   *                    }
   *                 }
   *             )
   *         )
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="User is uploading a file to present in a site"
   *   )
   * )
   */
  public function saveFile() {
    // @todo might want to scrub prior to this level but not sure
    if (isset($_FILES['file-upload'])) {
      $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
      // update the page's content, using manifest to find it
      // this ensures that writing is always to what the file system
      // determines to be the correct page
      $page = $site->loadNode($this->params['node']['id']);
      $upload = $_FILES['file-upload'];
      $file = new HAXCMSFile();
      $fileResult = $file->save($upload, $site, $page);
      if ($fileResult['status'] == 500) {
        return array(
          '__failed' => array(
            'status' => 500,
            'message' => $fileResult['data'],
          )
        );
      }
      $site->gitCommit('File added: ' . $upload['name']);
      return $fileResult;
    }
  }

  /**
   * 
   * SITE LISTING CALLBACKS
   * 
   */

  /**
   * @OA\Get(
   *    path="/listSites",
   *    tags={"cms"},
   *    @OA\Response(
   *        response="200",
   *        description="Load a list of all sites the user has created"
   *   )
   * )
   */
  public function listSites() {
    // top level fake JOS
    $return = array(
      "id" => "123-123-123-123",
      "title" => "My sites",
      "author" => "me",
      "description" => "All of my micro sites I know and love.",
      "license" => "by-sa",
      "metadata" => array(),
      "items" => array()
    );
    // loop through files directory so we can cache those things too
    if ($handle = opendir(HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory)) {
      while (false !== ($item = readdir($handle))) {
        if ($item != "." && $item != ".." && is_dir(HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $item) && file_exists(HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $item . '/site.json')) {
          $json = file_get_contents(HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $item . '/site.json');
          $site = json_decode($json);
          if (isset($site->title)) {
            $site->indent = 0;
            $site->order = 0;
            $site->parent = null;
            $site->location = $GLOBALS['HAXCMS']->basePath . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $item . '/';
            $site->slug = $GLOBALS['HAXCMS']->basePath . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $item . '/';
            $site->metadata->pageCount = count($site->items);
            // we don't need all items stored here
            unset($site->items);
            // unset other things we don't need to send across in this meta site.json response
            if (isset($site->metadata->dynamicElementLoader)) {
              unset($site->metadata->dynamicElementLoader);
            }
            
            if (isset($site->metadata->node)) {
              unset($site->metadata->node);
            }
            if (isset($site->metadata->build->items)) {
              unset($site->metadata->build->items);
            }
            $return['items'][] = $site;
          }
        }
      }
      closedir($handle);
    }
    return array(
      "status" => 200,
      "data" => $return
    );
  }
  /**
   * @OA\Post(
   *    path="/createSite",
   *    tags={"cms","authenticated","site"},
   *    @OA\Parameter(
   *         name="jwt",
   *         description="JSON Web token, obtain by using  /login",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *     @OA\RequestBody(
   *        @OA\MediaType(
   *             mediaType="application/json",
   *             @OA\Schema(
   *                 @OA\Property(
   *                     property="site",
   *                     type="object"
   *                 ),
   *                 @OA\Property(
   *                     property="build",
   *                     type="object"
   *                 ),
   *                 @OA\Property(
   *                     property="theme",
   *                     type="object"
   *                 ),
   *                 required={"site","node"},
   *                 example={
   *                    "site": {
   *                      "name": "mynewsite",
   *                      "description": "The description",
   *                      "theme": "theme name"
   *                    },
   *                    "build": {
   *                      "type": "course",
   *                      "structure": "docx import",
   *                      "items": [{},{}]
   *                    },
   *                    "theme": {
   *                      "color": "blue",
   *                      "icon": "icons:save"
   *                    }
   *                 }
   *             )
   *         )
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Create a new site"
   *   )
   * )
   */
  public function createSite() {
    if ($GLOBALS['HAXCMS']->validateRequestToken()) {
      $domain = null;
      // woohoo we can edit this thing!
      if (isset($this->params['site']['domain']) && $this->params['site']['domain'] != null && $this->params['site']['domain'] != '') {
        $domain = $this->params['site']['domain'];
      }
      // null in the event we get hits that don't have this
      $build = null;
      $filesToDownload = Array();
      // support for build info. the details used to actually create this site originally
      if (isset($this->params['build'])) {
        $build = new stdClass();
        // version of the platform used when originally created
        $build->version = $GLOBALS['HAXCMS']->getHAXCMSVersion();
        // course, website, portfolio, etc
        $build->structure = $this->params['build']['structure'];
        // TYPE of structure we are creating
        $build->type = $this->params['build']['type'];
        if ($build->type == 'docx import' || $build->structure == "import") {
          // JSONOutlineSchemaItem Array
          $build->items = $this->params['build']['items'];
        }
        if (isset($this->params['build']['files'])) {
          $filesToDownload = $this->params['build']['files'];
        }
      }
      // sanitize name
      $name = $GLOBALS['HAXCMS']->generateMachineName($this->params['site']['name']);
      $site = $GLOBALS['HAXCMS']->loadSite(
          strtolower($name),
          true,
          $domain,
          $build
      );
      // this could have changed after creation because of on file system
      $name = $site->manifest->metadata->site->name;
      // now get a new item to reference this into the top level sites listing
      $schema = $GLOBALS['HAXCMS']->outlineSchema->newItem();
      $schema->id = $site->manifest->id;
      $schema->title = $name;
      $schema->location =
          $GLOBALS['HAXCMS']->basePath .
          $GLOBALS['HAXCMS']->sitesDirectory .
          '/' .
          $site->manifest->metadata->site->name .
          '/index.html';
      $schema->slug = $schema->location;
      $schema->metadata->site = new stdClass();
      $schema->metadata->theme = new stdClass();
      // store build data in case we need it down the road
      $schema->metadata->build = $build;
      // we don't need to store replication of all items imported on site creation
      if (isset($schema->metadata->build->items)) {
        unset($schema->metadata->build->items);
      }
      $schema->metadata->site->name = $site->manifest->metadata->site->name;
      if (isset($this->params['site']['theme']) && is_string($this->params['site']['theme'])) {
        $theme = $this->params['site']['theme'];
      }
      else {
        $theme = HAXCMS_DEFAULT_THEME;
      }
      // look for a match so we can set the correct data
      foreach ($GLOBALS['HAXCMS']->getThemes() as $key => $themeObj) {
          if ($theme == $key) {
              $schema->metadata->theme = $themeObj;
          }
      }
      $schema->metadata->theme->variables = new stdClass();
      // description for an overview if desired
      if (isset($this->params['site']['description']) && $this->params['site']['description'] != '' && $this->params['site']['description'] != null) {
          $schema->description = strip_tags($this->params['site']['description']);
      }
      // background image / banner
      if (isset($this->params['theme']['image']) && $this->params['theme']['image'] != '' && $this->params['theme']['image'] != null) {
        $schema->metadata->site->logo = $this->params['theme']['image'];
      }
      else {
        $schema->metadata->site->logo = 'assets/banner.jpg';
      }
      // icon to express the concept / visually identify site
      if (isset($this->params['theme']['icon']) && $this->params['theme']['icon'] != '' && $this->params['theme']['icon'] != null) {
          $schema->metadata->theme->variables->icon = $this->params['theme']['icon'];
      }
      // slightly style the site based on css vars and hexcode
      if (isset($this->params['theme']['hexCode']) && $this->params['theme']['hexCode'] != '' && $this->params['theme']['hexCode'] != null) {
          $hex = $this->params['theme']['hexCode'];
      } else {
          $hex = '#aeff00';
      }
      $schema->metadata->theme->variables->hexCode = $hex;
      if (isset($this->params['theme']['cssVariable']) && $this->params['theme']['cssVariable'] != '' && $this->params['theme']['cssVariable'] != null) {
          $cssvar = $this->params['theme']['cssVariable'];
      } else {
          $cssvar = '--simple-colors-default-theme-light-blue-7';
      }
      $schema->metadata->theme->variables->cssVariable = $cssvar;
      $schema->metadata->site->settings = new stdClass();
      $schema->metadata->site->settings->lang = 'en-US';
      $schema->metadata->site->settings->publishPagesOn = true;
      $schema->metadata->site->created = time();
      $schema->metadata->site->updated = time();
      // check for publishing settings being set globally in HAXCMS
      // this would allow them to fork off to different locations down stream
      $schema->metadata->site->git = new stdClass();
      if (isset($GLOBALS['HAXCMS']->config->site->git->vendor)) {
          $schema->metadata->site->git =
              $GLOBALS['HAXCMS']->config->site->git;
          unset($schema->metadata->site->git->keySet);
          unset($schema->metadata->site->git->email);
          unset($schema->metadata->site->git->user);
      }
      // mirror the metadata information into the site's info
      // this means that this info is available to the full site listing
      // as well as this individual site. saves on performance / calls
      // later on if we only need to hit 1 file each time to get all the
      // data we need.
      foreach ($schema->metadata as $key => $value) {
          $site->manifest->metadata->{$key} = $value;
      }
      $site->manifest->metadata->node = new stdClass();
      $site->manifest->metadata->node->fields = new stdClass();
      $site->manifest->description = $schema->description;
      // save the outline into the new site
      $site->manifest->save(false);
      // walk through files if any came across and save each of them
      if (is_array($filesToDownload)) {
        foreach ($filesToDownload as $locationName => $downloadLocation) {
          $file = new HAXCMSFile();
          // check for a file upload; we block a few formats by design
          $fileResult = $file->save(Array(
            "name" => $locationName,
            "tmp_name" => $downloadLocation,
            "bulk-import" => TRUE
          ), $site);
        }
      }
      // main site schema doesn't care about publishing settings
      unset($schema->metadata->site->git);
      $git = new Git();
      $repo = $git->open(
          $site->directory . '/' . $site->manifest->metadata->site->name
      );
      $repo->add('.');
      $site->gitCommit(
          'A new journey begins: ' .
              $site->manifest->title .
              ' (' .
              $site->manifest->id .
              ')'
      );
      // make a branch but dont use it
      if (isset($site->manifest->metadata->site->git->staticBranch)) {
          $repo->create_branch(
              $site->manifest->metadata->site->git->staticBranch
          );
      }
      if (isset($site->manifest->metadata->site->git->branch)) {
          $repo->create_branch(
              $site->manifest->metadata->site->git->branch
          );
      }
      return array(
        "status" => 200,
        "data" => $schema
      );
    }
    else {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid request token',
        )
      );
    }
  }
  /**
   * @OA\Post(
   *    path="/gitImportSite",
   *    tags={"cms","authenticated","site"},
   *    @OA\Parameter(
   *         name="jwt",
   *         description="JSON Web token, obtain by using  /login",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\RequestBody(
   *        @OA\MediaType(
   *             mediaType="application/json",
   *             @OA\Schema(
   *                 @OA\Property(
   *                     property="site",
   *                     type="object"
   *                 ),
   *                 required={"site"},
   *                 example={
   *                    "site": {
   *                      "git": {
   *                        "url": ""
   *                      }
   *                    },
   *                 }
   *             )
   *         )
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Create a new site from a git repo reference"
   *   )
   * )
   */
  public function gitImportSite() {
    if ($GLOBALS['HAXCMS']->validateRequestToken()) {
      if (isset($this->params['site']['git']['url'])) {
        $repoUrl = $this->params['site']['git']['url'];
        // make sure there's a .git in the address
        if (filter_var($repoUrl, FILTER_VALIDATE_URL) !== false &&
            strpos($repoUrl, '.git')
          ) {
          $ary = explode('/', str_replace('.git', '', $repoUrl));
          $repo_path = array_pop($ary);
          $git = new Git();
          // @todo check if this fails
          $directory = HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $repo_path;
          $repo = @$git->create($directory);
          $repo = @$git->open($directory, true);
          @$repo->set_remote("origin", $repoUrl);
          @$repo->pull('origin', 'master');
          
          // this ensures that our repo doesn't get squashed by a sanitization
          // check that's baked into site loading.
          // This is safe / nessecary because the git repo url could be
          // any name for the repo but we do a lot of security checks when
          // user input is involved. As this is user input but from a valid git
          // url (which would have failed above if it wasn't real)
          // working with JSON Outline Schema instead of the extrapolations
          include_once 'JSONOutlineSchema.php';
          $manifest = new JSONOutlineSchema();
          $manifest->load(HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $repo_path . '/site.json');
          // repo name matches site->manifest->site->name value as they don't
          // have to be identical but also is a crud test for if this is a valid
          // site.json format in the first place
          if (isset($manifest->metadata) && $repo_path != $manifest->metadata->site->name) {
            // move directory from the git repo based name to the folder name
            // that the system will expect
            rename(
              HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $repo_path,
              HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $manifest->metadata->site->name);
            // update this to ensure it works when we do a full site load
            $repo_path = $manifest->metadata->site->name;
          }
          // load the site that we SHOULD have just pulled in
          if ($site = $GLOBALS['HAXCMS']->loadSite($repo_path)) {
            return array(
              'manifest' => $site->manifest
            );
          }
          else {
            return array(
              '__failed' => array(
                'status' => 500,
                'message' => 'invalid url',
              )
            );
          }
        }
      }
      return array(
        '__failed' => array(
          'status' => 500,
          'message' => 'invalid url',
        )
      );
    }
    else {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid request token',
        )
      );
    }
  }
  /**
   * Get configuration related to HAXcms itself
   */
  /**
   * @OA\Post(
   *    path="/getConfig",
   *    tags={"cms","authenticated","settings"},
   *    @OA\Parameter(
   *         name="jwt",
   *         description="JSON Web token, obtain by using  /login",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Get configuration for HAXcms itself"
   *   )
   * )
   */
  public function getConfig() {
    $response = new stdClass();
    $response->schema = $GLOBALS['HAXCMS']->getConfigSchema();
    $response->values = $GLOBALS['HAXCMS']->config;
    foreach ($response->values->appStore as $key => $val) {
      if ($key !== 'apiKeys') {
        unset($response->values->appStore->{$key});
      }
    }
    return $response;
  }
  /**
   * Get configuration related to HAX appstore. This allows the user to set valid
   * configuration via a front-end presented form.
   */
  /**
   * @OA\Get(
   *    path="/haxConfigurationSelectionData",
   *    tags={"editor","authenticated","settings"},
   *    @OA\Parameter(
   *         name="jwt",
   *         description="JSON Web token, obtain by using  /login",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Get configuration for HAX in how appstore is constructed"
   *   )
   * )
   */
  public function haxConfigurationSelectionData() {
    $response = new stdClass();
    return $response;
  }
  /**
   * @OA\Post(
   *    path="/setConfig",
   *    tags={"cms","authenticated","form","settings"},
   *    @OA\Parameter(
   *         name="jwt",
   *         description="JSON Web token, obtain by using  /login",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\RequestBody(
   *        @OA\MediaType(
   *             mediaType="application/json",
   *             @OA\Schema(
   *                 @OA\Property(
   *                     property="values",
   *                     type="object"
   *                 ),
   *                 required={"site"},
   *                 example={
   *                    "values": {}
   *                 }
   *             )
   *         )
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Set configuration for HAXcms"
   *   )
   * )
   */
  public function setConfig() {
    if ($GLOBALS['HAXCMS']->validateRequestToken()) {
      $values = $this->rawParams['values'];
      $val = new stdClass();
      if (isset($values->apis) && isset($values->appStore->apiKeys)) {
        $val->apis = $values->apis;
      }
      if (isset($values->publishing)) {
        $val->publishing = $values->publishing;
      }
      $response = $GLOBALS['HAXCMS']->setConfig($val);
      return $response;
    } else {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'failed to validate request token',
        )
      );
    }
  }
  /**
   * @OA\Post(
   *    path="/syncSite",
   *    tags={"cms","authenticated","git","site"},
   *    @OA\Parameter(
   *         name="jwt",
   *         description="JSON Web token, obtain by using  /login",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\RequestBody(
   *        @OA\MediaType(
   *             mediaType="application/json",
   *             @OA\Schema(
   *                 @OA\Property(
   *                     property="site",
   *                     type="object"
   *                 ),
   *                 required={"site"},
   *                 example={
   *                    "site": {
   *                      "name": "mynewsite"
   *                    },
   *                 }
   *             )
   *         )
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Sync the site using the git config settings in the site.json file"
   *   )
   * )
   */
  public function syncSite() {
    // ensure we have something we can load and ship back out the door
    if ($site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name'])) {
      // local publishing options, then defer to system, then make some up...
      if (isset($site->manifest->metadata->site->git)) {
          $gitSettings = $site->manifest->metadata->site->git;
      } elseif (isset($GLOBALS['HAXCMS']->config->site->git)) {
          $gitSettings = $GLOBALS['HAXCMS']->config->site->git;
      } else {
          $gitSettings = new stdClass();
          $gitSettings->vendor = 'github';
          $gitSettings->branch = 'master';
          $gitSettings->staticBranch = 'gh-pages';
          $gitSettings->url = '';
      }
      if (isset($gitSettings)) {
          $git = new Git();
          $siteDirectoryPath = $site->directory . '/' . $site->manifest->metadata->site->name;
          $repo = $git->open($siteDirectoryPath, true);
          // ensure we're on branch, most likley master
          $repo->checkout($gitSettings->branch);
          $repo->pull('origin', $gitSettings->branch);
          $repo->push('origin', $gitSettings->branch);
          return array(
            'status' => 200,
            'detail' => true
          );
      }
    } else {
      return array(
        '__failed' => array(
          'status' => 500,
          'message' => 'Unable to load site',
        )
      );
    }
  }
  /**
   * @OA\Post(
   *    path="/cloneSite",
   *    tags={"cms","authenticated","site"},
   *    @OA\Parameter(
   *         name="jwt",
   *         description="JSON Web token, obtain by using  /login",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\RequestBody(
   *        @OA\MediaType(
   *             mediaType="application/json",
   *             @OA\Schema(
   *                 @OA\Property(
   *                     property="site",
   *                     type="object"
   *                 ),
   *                 required={"site"},
   *                 example={
   *                    "site": {
   *                      "name": "mynewsite"
   *                    },
   *                 }
   *             )
   *         )
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Clone a site by copying and renaming the folder on file system"
   *   )
   * )
   */
  public function cloneSite() {
    $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
    $siteDirectoryPath = $site->directory . '/' . $site->manifest->metadata->site->name;
    $originalPathForReplacement = "/sites/" . $site->manifest->metadata->site->name . "/files/";
    $cloneName = $GLOBALS['HAXCMS']->getUniqueName($site->name);
    // ensure the path to the new folder is valid
    $GLOBALS['fileSystem']->mirror(
        HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $site->manifest->metadata->site->name,
        HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $cloneName
    );
    // we need to then load and rewrite the site name var or it will conflict given the name change
    $site = $GLOBALS['HAXCMS']->loadSite($cloneName);
    $site->manifest->metadata->site->name = $cloneName;
    $site->manifest->id = $GLOBALS['HAXCMS']->generateUUID();
    // loop through all items and rewrite the path to files as we cloned it
    foreach ($site->manifest->items as $delta => $item) {
      if (isset($item->metadata->files)) {
        foreach ($item->metadata->files as $delta2 => $file) {
          $site->manifest->items[$delta]->metadata->files[$delta2]->path = str_replace(
            $originalPathForReplacement,
            '/sites/' . $cloneName . '/files/',
            $site->manifest->items[$delta]->metadata->files[$delta2]->path
          );
          $site->manifest->items[$delta]->metadata->files[$delta2]->fullUrl = str_replace(
            $originalPathForReplacement,
            '/sites/' . $cloneName . '/files/',
            $site->manifest->items[$delta]->metadata->files[$delta2]->fullUrl
          );
        }
      }
    }
    $site->save();
    return array(
      'status' => 200,
      'data' => array(
        'detail' =>
          $GLOBALS['HAXCMS']->basePath .
          $GLOBALS['HAXCMS']->sitesDirectory .
          '/' .
          $cloneName,
        'name' => $cloneName
      ),
    );
  }
  /**
   * @OA\Post(
   *    path="/deleteSite",
   *    tags={"cms","authenticated","site"},
   *    @OA\Parameter(
   *         name="jwt",
   *         description="JSON Web token, obtain by using  /login",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\RequestBody(
   *        @OA\MediaType(
   *             mediaType="application/json",
   *             @OA\Schema(
   *                 @OA\Property(
   *                     property="site",
   *                     type="object"
   *                 ),
   *                 required={"site"},
   *                 example={
   *                    "site": {
   *                      "name": "mynewsite"
   *                    },
   *                 }
   *             )
   *         )
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Delete a site from the file system"
   *   )
   * )
   */
  public function deleteSite() {
    $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
    if ($site->manifest->metadata->site->name) {
      $GLOBALS['fileSystem']->remove([
        $site->directory . '/' . $site->manifest->metadata->site->name
      ]);
      return array(
        'status' => 200,
        'data' => array(
          'name' => $site->name,
          'detail' => 'Site deleted',
        )
      );
    }
    else {
      return array(
        '__failed' => array(
          'status' => 500,
          'message' => 'Site does not exist!',
        )
      );
    }
  }
  /**
   * @OA\Post(
   *    path="/downloadSite",
   *    tags={"cms","authenticated","site","meta"},
   *    @OA\Parameter(
   *         name="jwt",
   *         description="JSON Web token, obtain by using  /login",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\RequestBody(
   *        @OA\MediaType(
   *             mediaType="application/json",
   *             @OA\Schema(
   *                 @OA\Property(
   *                     property="site",
   *                     type="object"
   *                 ),
   *                 required={"site"},
   *                 example={
   *                    "site": {
   *                      "name": "mynewsite"
   *                    },
   *                 }
   *             )
   *         )
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Download the site folder as a zip file"
   *   )
   * )
   */
  public function downloadSite() {
    // load site
    $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
    // helpful boilerplate https://stackoverflow.com/questions/29873248/how-to-zip-a-whole-directory-and-download-using-php
    $dir = HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $site->manifest->metadata->site->name;
    // form a basic name
    $zip_file =
      HAXCMS_ROOT .
      '/' .
      $GLOBALS['HAXCMS']->publishedDirectory .
      '/' .
      $site->manifest->metadata->site->name .
      '.zip';
    // Get real path for our folder
    $rootPath = realpath($dir);
    // Initialize archive object
    $zip = new ZipArchive();
    $zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    // Create recursive directory iterator
    $directory = new RecursiveDirectoryIterator($rootPath);
    $filtered = new DirFilter($directory, array('node_modules'));
    $files = new RecursiveIteratorIterator($filtered);
    foreach ($files as $name => $file) {
      // Skip directories (they would be added automatically)
      if (!$file->isDir()) {
        // Get real and relative path for current file
        $filePath = $file->getRealPath();
        $relativePath = substr($filePath, strlen($rootPath) + 1);
        // Add current file to archive
        if ($filePath != '' && $relativePath != '') {
          $zip->addFile($filePath, $relativePath);
        }
      }
    }
    // Zip archive will be created only after closing object
    $zip->close();
    return array(
      'status' => 200,
      'data' => array(
        'link' =>
          $GLOBALS['HAXCMS']->basePath .
          $GLOBALS['HAXCMS']->publishedDirectory .
          '/' .
          basename($zip_file),
        'name' => basename($zip_file)
      )
    );
  }
  /**
   * @OA\Post(
   *    path="/archiveSite",
   *    tags={"cms","authenticated","site"},
   *    @OA\Parameter(
   *         name="jwt",
   *         description="JSON Web token, obtain by using  /login",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\RequestBody(
   *        @OA\MediaType(
   *             mediaType="application/json",
   *             @OA\Schema(
   *                 @OA\Property(
   *                     property="site",
   *                     type="object"
   *                 ),
   *                 required={"site"},
   *                 example={
   *                    "site": {
   *                      "name": "mynewsite"
   *                    },
   *                 }
   *             )
   *         )
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Archive a site by moving it on the file system"
   *   )
   * )
   */
  public function archiveSite() {
    $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
    if ($site->manifest->metadata->site->name) {
      rename(
        HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $site->manifest->metadata->site->name,
        HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->archivedDirectory . '/' . $site->manifest->metadata->site->name);
      return array(
        'status' => 200,
        'data' => array(
          'name' => $site->name,
          'detail' => 'Site archived',
        )
      );
    }
    else {
      return array(
        '__failed' => array(
          'status' => 500,
          'message' => 'Site does not exist',
        )
      );
    }
  }
}