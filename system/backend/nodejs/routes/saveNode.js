const HAXCMS = require('../lib/HAXCMS.js');
const gettype = require('locutus/php/var/gettype');
const filter_var = require('../lib/filter_var.js');
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
  async function saveNode(req, res) {
    let site = await HAXCMS.loadSite(req.body['site']['name']);
    let schema = [];
    if ((this.params['node']['body'])) {
      body = this.params['node']['body'];
      // we ship the schema with the body
      if ((this.params['node']['schema'])) {
        schema = this.params['node']['schema'];
      }
    }
    details = array();
    // if we have details object then merge configure and advanced
    if ((this.params['node']['details'])) {
      foreach (this.params['node']['details']['node']['configure'] as key => value) {
        details[key] = value;
      }
      foreach (this.params['node']['details']['node']['advanced'] as key => value) {
        details[key] = value;
      }
    }
    // update the page's content, using manifest to find it
    // this ensures that writing is always to what the file system
    // determines to be the correct page
    // @todo review this step by step
    if (page = site.loadNode(this.params['node']['id'])) {
      // convert web location for loading into file location for writing
      if ((body)) {
        bytes = 0;
        // see if we have multiple pages / this page has been told to split into multiple
        pageData = GLOBALS['HAXCMS'].pageBreakParser(body);
        foreach(pageData as data) {
          // trap to ensure if front-end didnt send a UUID for id then we make it
          if (!(data["attributes"]["title"])) {
            data["attributes"]["title"] = 'New page';
          }
          // to avoid critical error in parsing, we defer to the POST's ID always
          // this also blocks multiple page breaks if it doesn't exist as we don't allow
          // the front end to dictate what gets created here
          if (!(data["attributes"]["item-id"])) {
            data["attributes"]["item-id"] = this.params['node']['id'];
          }
          if (!(data["attributes"]["path"]) || data["attributes"]["path"] == '#') {
            data["attributes"]["path"] = data["attributes"]["title"];
          }
          // verify this pages does not exist; this is only possible if we parse multiple page-break
          // a capability that is not supported currently beyond experiments
          if (!page = site.loadNode(data["attributes"]["item-id"])) {
            // generate a new item based on the site
            nodeParams = array(
              "node" => array(
                "title" => data["attributes"]["title"],
                "id" => data["attributes"]["item-id"],
                "location" => data["attributes"]["path"],
              )
            );
            item = site.itemFromParams(nodeParams);
            // generate the boilerplate to fill this page
            site.recurseCopy(
                HAXCMS_ROOT + '/system/boilerplate/page/default',
                site.directory .
                    '/' .
                    site.manifest.metadata.site.name .
                    '/' .
                    str_replace('/index.html', '', item.location)
            );
            // add the item back into the outline schema
            site.manifest.addItem(item);
            site.manifest.save();
            site.gitCommit('Page added:' + item.title + ' (' + item.id + ')');
            // possible the item-id had to be made by back end
            data["attributes"]["item-id"] = item.id;
          }
          // now this should exist if it didn't a minute ago
          page = site.loadNode(data["attributes"]["item-id"]);
          // @todo make sure that we stripped off page-break
          // and now save WITHOUT the top level page-break
          // to avoid duplication issues
          bytes = page.writeLocation(
            data['content'],
            HAXCMS_ROOT .
            '/' .
            GLOBALS['HAXCMS'].sitesDirectory .
            '/' .
            site.manifest.metadata.site.name .
            '/'
          );
          if (bytes === false) {
            return array(
              '__failed' => array(
                'status' => 500,
                'message' => 'failed to write',
              )
            );
          } else {
              // sanity check
              if (!(page.metadata)) {
                page.metadata = new stdClass();
              }
              // update attributes in the page
              if ((data["attributes"]["title"])) {
                page.title = data["attributes"]["title"];
              }
              if ((data["attributes"]["slug"])) {
                // account for x being the only front end reserved route
                if (data["attributes"]["slug"] == "x") {
                  data["attributes"]["slug"] = "x-x";
                }
                // same but trying to force a sub-route; paths cannot conflict with front end
                if (substr( data["attributes"]["slug"], 0, 2 ) == "x/") {
                  data["attributes"]["slug"] = str_replace('x/', 'x-x/', data["attributes"]["slug"]);
                }
                // machine name should more aggressively scrub the slug than clean title
                // @todo need to verify this doesn't already exist
                page.slug = GLOBALS['HAXCMS'].generateSlugName(data["attributes"]["slug"]);
              }
              if ((data["attributes"]["parent"])) {
                page.parent = data["attributes"]["parent"];
              }
              else {
                page.parent = null;
              }
              // allow setting theme via page break
              if ((data["attributes"]["developer-theme"]) && data["attributes"]["developer-theme"] != '') {
                themes = GLOBALS['HAXCMS'].getThemes();
                value = filter_var(data["attributes"]["developer-theme"], FILTER_SANITIZE_STRING);
                // support for removing the custom theme or applying none
                if (value == '_none_' || value == '' || !value || !themes[value]) {
                  delete page.metadata.theme;
                }
                // ensure it exists
                else if (themes[value]) {
                  page.metadata.theme = themes[value];
                  page.metadata.theme.key = value;
                }
              }
              else if ((page.metadata.theme)) {
                delete page.metadata.theme;
              }
              if ((data["attributes"]["depth"])) {
                page.indent = parseInt(data["attributes"]["depth"]);
              }
              if ((data["attributes"]["order"])) {
                page.order = parseInt(data["attributes"]["order"]);
              }
              // boolean so these are either there or not
              // historically we are published if this value is not set
              // and that will remain true however as we save / update pages
              // this will ensure that we set things to published
              if ((data["attributes"]["published"])) {
                page.metadata.published = true;
              }
              else {
                page.metadata.published = false;
              }
              // support for defining and updating page type
              if ((data["attributes"]["page-type"]) && data["attributes"]["page-type"] != '') {
                page.metadata.pageType = data["attributes"]["page-type"];
              }
              // they sent across nothing but we had something previously
              else if ((page.metadata.pageType)) {
                delete page.metadata.pageType;
              }
              // support for defining and updating hideInMenu
              if ((data["attributes"]["hide-in-menu"])) {
                page.metadata.hideInMenu = true;
              }
              else {
                page.metadata.hideInMenu = false;
              }
              // support for defining and updating related-items
              if ((data["attributes"]["related-items"]) && data["attributes"]["related-items"] != '') {
                page.metadata.relatedItems = data["attributes"]["related-items"];
              }
              // they sent across nothing but we had something previously
              else if ((page.metadata.relatedItems)) {
                delete page.metadata.relatedItems;
              }
              // support for defining and updating image
              if ((data["attributes"]["image"]) && data["attributes"]["image"] != '') {
                page.metadata.image = data["attributes"]["image"];
              }
              // they sent across nothing but we had something previously
              else if ((page.metadata.image)) {
                delete page.metadata.image;
              }
              // support for defining and updating page type
              if ((data["attributes"]["tags"]) && data["attributes"]["tags"] != '') {
                page.metadata.tags = data["attributes"]["tags"];
              }
              // they sent across nothing but we had something previously
              else if ((page.metadata.tags)) {
                delete page.metadata.tags;
              }
              // support for defining and updating page accentColor
              if ((data["attributes"]["accent-color"]) && data["attributes"]["accent-color"] != '') {
                page.metadata.accentColor = data["attributes"]["accent-color"];
              }
              // they sent across nothing but we had something previously
              else if ((page.metadata.accentColor)) {
                delete page.metadata.accentColor;
              }
              // support for defining and updating page type
              if ((data["attributes"]["icon"]) && data["attributes"]["icon"] != '') {
                page.metadata.icon = data["attributes"]["icon"];
              }
              // they sent across nothing but we had something previously
              else if ((page.metadata.icon)) {
                delete page.metadata.icon;
              }
              // support for defining an image to represent the page
              if ((data["attributes"]["image"]) && data["attributes"]["image"] != '') {
                page.metadata.image = data["attributes"]["image"];
              }
              // they sent across nothing but we had something previously
              else if ((page.metadata.image)) {
                delete page.metadata.image;
              }
              if (!(data["attributes"]["locked"])) {
                page.metadata.locked = false;
              }
              else {
                page.metadata.locked = true;
              }
              // update the updated timestamp
              page.metadata.updated = time();
              clean = strip_tags(body);
              // auto generate a text only description from first 200 chars
              // unless we were sent one to use
              if ((data["attributes"]["description"]) && data["attributes"]["description"] != '') {
                page.description = data["attributes"]["description"];
              }
              else {
                page.description = str_replace(
                  "\n",
                  '',
                  substr(clean, 0, 200)
              );
              }
              readtime = round(str_word_count(clean) / 200);
              // account for uber small body
              if (readtime == 0) {
                readtime = 1;
              }
              page.metadata.readtime = readtime;
              // reset bc we rebuild this each page save
              page.metadata.videos = array();
              page.metadata.images = array();
              // pull schema apart and seee if we have any images
              // that other things could use for metadata / theming purposes
              foreach (schema as element) {
                switch(element['tag']) {
                  case 'img':
                    if ((element['properties']['src'])) {
                      array_push(page.metadata.images, element['properties']['src']);
                    }
                  break;
                  case 'a11y-gif-player':
                    if ((element['properties']['src'])) {
                      array_push(page.metadata.images, element['properties']['src']);
                    }
                  break;
                  case 'media-image':
                    if ((element['properties']['source'])) {
                      array_push(page.metadata.images, element['properties']['source']);
                    }
                  break;
                  case 'video-player':
                    if ((element['properties']['source'])) {
                      array_push(page.metadata.videos, element['properties']['source']);
                    }
                  break;
                }
              }
              await site.updateNode(page);
              await site.gitCommit(
                'Page details updated: ' + page.title + ' (' + page.id + ')'
              );
          }
        }
        res.send(page);
      }
    }
  function countWords(str) {
    return str.trim().split(/\s+/).length;
  }
  module.exports = saveNode;