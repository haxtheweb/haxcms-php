<?php
trait OperationsRouteSaveNode {
  /**
   * @OA\Post(
   *    path="/saveNode",
   *    tags={"cms","authenticated","node"},
   *    @OA\Parameter(
   *         name="site_token",
   *         description="Site-specific validation token",
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
    if (isset($this->params['site_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['site_token'], $GLOBALS['HAXCMS']->getActiveUserName() . ':' . $this->params['site']['name'])) {
      $site = $GLOBALS['HAXCMS']->loadSite($this->params['site']['name']);
      
      // Special handling for style guide endpoint through saveNode
      if (isset($this->params['node']['id']) && $this->params['node']['id'] === 'x/theme/style-guide') {
        return $this->handleStyleGuideSave($site);
      }
      
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
              if (!$this->platformAllows($site, 'addPage')) {
                return array(
                  '__failed' => array(
                    'status' => 403,
                    'message' => 'Adding pages is disabled for this site',
                  )
                );
              }
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
            $sanitizedContent = SanitizeContent::sanitizeHTMLForStorage($data['content']);
            // @todo make sure that we stripped off page-break
            // and now save WITHOUT the top level page-break
            // to avoid duplication issues
            $bytes = $page->writeLocation(
              $sanitizedContent,
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
                  // decode entities and strip tags so manifest stores clean text
                  $page->title = html_entity_decode(strip_tags($data["attributes"]["title"]));
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
                  // @todo need to verify this doesn't already exist
                  $page->slug = $GLOBALS['HAXCMS']->generateSlugName($data["attributes"]["slug"]);
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
                  $value = filter_var($data["attributes"]["developer-theme"], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
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
                  $page->metadata->relatedItems = SanitizeContent::sanitizeMetadataValue(
                    $data["attributes"]["related-items"]
                  );
                }
                // they sent across nothing but we had something previously
                else if (isset($page->metadata->relatedItems)) {
                  unset($page->metadata->relatedItems);
                }
                // support for defining and updating image
                if (isset($data["attributes"]["image"]) && $data["attributes"]["image"] != '') {
                  $page->metadata->image = SanitizeContent::sanitizeURLValue(
                    $data["attributes"]["image"],
                    ''
                  );
                }
                // they sent across nothing but we had something previously
                else if (isset($page->metadata->image)) {
                  unset($page->metadata->image);
                }
                // support for defining and updating page type
                if (isset($data["attributes"]["tags"]) && $data["attributes"]["tags"] != '') {
                  $page->metadata->tags = SanitizeContent::sanitizeMetadataValue(
                    $data["attributes"]["tags"]
                  );
                }
                // they sent across nothing but we had something previously
                else if (isset($page->metadata->tags)) {
                  unset($page->metadata->tags);
                }
                // support for defining and updating page accentColor
                if (isset($data["attributes"]["accent-color"]) && $data["attributes"]["accent-color"] != '') {
                  $page->metadata->accentColor = SanitizeContent::sanitizeMetadataValue(
                    $data["attributes"]["accent-color"]
                  );
                }
                // they sent across nothing but we had something previously
                else if (isset($page->metadata->accentColor)) {
                  unset($page->metadata->accentColor);
                }
                // support for defining and updating page type
                if (isset($data["attributes"]["icon"]) && $data["attributes"]["icon"] != '') {
                  $page->metadata->icon = SanitizeContent::sanitizeMetadataValue(
                    $data["attributes"]["icon"]
                  );
                }
                // they sent across nothing but we had something previously
                else if (isset($page->metadata->icon)) {
                  unset($page->metadata->icon);
                }
                // support for defining an image to represent the page
                if (isset($data["attributes"]["image"]) && $data["attributes"]["image"] != '') {
                  $page->metadata->image = SanitizeContent::sanitizeURLValue(
                    $data["attributes"]["image"],
                    ''
                  );
                }
                // they sent across nothing but we had something previously
                else if (isset($page->metadata->image)) {
                  unset($page->metadata->image);
                }
                // support for defining and updating author
                if (isset($data["attributes"]["author"]) && $data["attributes"]["author"] != '') {
                  $page->metadata->author = SanitizeContent::sanitizeMetadataValue(
                    $data["attributes"]["author"]
                  );
                }
                // they sent across nothing but we had something previously
                else if (isset($page->metadata->author)) {
                  unset($page->metadata->author);
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
                  $page->description = html_entity_decode(strip_tags($data["attributes"]["description"]));
                }
                else {
                  $decodedClean = html_entity_decode($clean);
                  $page->description = str_replace(
                    "\n",
                    '',
                    substr($decodedClean, 0, 200)
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
                $site->writePageAlternateFormats($page, $sanitizedContent);
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
      else {
        return array(
          '__failed' => array(
            'status' => 500,
            'message' => 'failed to write',
          )
        );
      }
    }
    else {
      return array(
        '__failed' => array(
          'status' => 500,
          'message' => 'failed to write',
        )
      );
    }
  }
}
