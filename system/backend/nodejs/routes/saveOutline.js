const fs = require('fs-extra');
const HAXCMS = require('../lib/HAXCMS.js');
const JSONOutlineSchemaItem = require('../lib/JSONOutlineSchemaItem.js');
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
  async function saveOutline(req, res) {
    // items from the POST
    let site = await HAXCMS.loadSite(req.body['site']['name']);
    let siteDirectory = site.directory + '/' + site.manifest.metadata.site.name;
    let original = [...site.manifest.items];
    let items = [...req.body['items']];
    let itemMap = {};
    var page, bytes, cleanTitle;
    // items from the POST
    for (var key in items) {
      let item = items[key];
      page = site.loadNode(item.id);
      // get a fake item of the existing
      if (!page) {
        page = HAXCMS.outlineSchema.newItem();
        // we don't trust the front end UUID if it wasn't existing already
        itemMap[item.id] = page.id;
      }
      // set a title if we have one
      if (item.title != '' && item.title) {
        page.title = item.title;
      }
      cleanTitle = HAXCMS.cleanTitle(page.title);
      if (item.parent == null) {
        page.parent = null;
        page.indent = 0;
      } else {
        // check the item map as backend dictates unique ID
        if (typeof itemMap[item.parent] !== 'undefined') {
          page.parent = itemMap[item.parent];
        } else {
          // set to the parent id
          page.parent = item.parent;
        }
        // move it one indentation below the parent; this can be changed later if desired
        page.indent = item.indent;
      }
      if (typeof item.order !== 'undefined') {
        page.order = parseInt(item.order);
      } else {
        page.order = parseInt(key);
      }
      // keep location if we get one already
      if (typeof item.location !== 'undefined' && item.location != '') {
        page.location = item.location;
      } else {
        // generate a logical page slug
        page.location = 'pages/' + page.id + '/index.html';
      }
      // keep location if we get one already
      if (typeof item.slug !== 'undefined' && item.slug != '') {
      } else {
          // generate a logical page slug
          page.slug = site.getUniqueSlugName(cleanTitle, page, true);
      }
      // verify this exists, front end could have set what they wanted
      // or it could have just been renamed
      // if it doesn't exist currently make sure the name is unique
      if (!site.loadNode(page.id)) {
        await HAXCMS.recurseCopy(
            HAXCMS.HAXCMS_ROOT + '/system/boilerplate/page/default',
            siteDirectory + '/' + page.location.replace('/index.html', '')
        );
      }
      // this would imply existing item, lets see if it moved or needs moved
      else {
          moved = false;
          for( var key in original) {
            let tmpItem = original[key];
              // see if this is something moving as opposed to brand new
              if (
                  tmpItem.id == page.id &&
                  tmpItem.slug != ''
              ) {
                  // core support for automatically managing paths to make them nice
                  if (typeof site.manifest.metadata.site.settings.pathauto !== 'undefined' && site.manifest.metadata.site.settings.pathauto) {
                      moved = true;
                      page.slug = site.getUniqueSlugName(HAXCMS.cleanTitle(page.title), page, true);
                  }
                  else if (tmpItem.slug != page.slug) {
                      moved = true;
                      page.slug = HAXCMS.generateSlugName(tmpItem.slug);
                  }
              }
          }
          // it wasn't moved and it doesn't exist... let's fix that
          // this is beyond an edge case
          if (
            !moved &&
            !fs.existsSync(siteDirectory + '/' + page.location)
        ) {
              pAuto = false;
              if (typeof site.manifest.metadata.site.settings.pathauto !== 'undefined' && site.manifest.metadata.site.settings.pathauto) {
                pAuto = true;
              }
              tmpTitle = site.getUniqueSlugName(cleanTitle, page, pAuto);
              page.location = 'pages/' + page.id + '/index.html';
              page.slug = tmpTitle;
              await HAXCMS.recurseCopy(
                  HAXCMS.HAXCMS_ROOT + '/system/boilerplate/page/default',
                  siteDirectory + '/' + page.location.replace('/index.html', '')
              );
          }
      }
      // check for any metadata keys that did come over
      for (let key in item.metadata) {
          page.metadata[key] = item.metadata[key];
      }
    }
    // safety check for new things
    if (typeof page.metadata.created === 'undefined') {
        page.metadata.created = Date.now();
        page.metadata.images = [];
        page.metadata.videos = [];
    }
    // always update at this time
    page.metadata.updated = Date.now();
    let tmp = site.loadNode(page.id);
    if (site.loadNode(page.id)) {
        await site.updateNode(page);
    } else {
        site.manifest.addItem(page);
    }
    // process any duplicate / contents requests we had now that structure is sane
    // including potentially duplication of material from something
    // we are about to act on and now that we have the map
    items = [...req.body['items']];
    for (let key in items) {
      let item = items[key];
      // load the item, or the item as built out of the itemMap
      // since we reset the UUID on creation
      page = site.loadNode(item.id);
      if (!page) {
        page = site.loadNode(itemMap[item.id]);
      }
      if (typeof item.duplicate !== 'undefined') {
        let nodeToDuplicate = site.loadNode(item.duplicate);
        // load the node we are duplicating with support for the same map needed for page loading
        if (!nodeToDuplicate) {
          nodeToDuplicate = site.loadNode(itemMap[item.duplicate]);
        }
        content = await site.getPageContent(nodeToDuplicate);
        // write it to the file system
        bytes = await page.writeLocation(
          content,
          HAXCMS.HAXCMS_ROOT +
          '/' +
          HAXCMS.sitesDirectory +
          '/' +
          site.manifest.metadata.site.name +
          '/'
        );
      }
      // contents that were shipped across, and not null, take priority over a dup request
      if (typeof item.contents !== 'undefined' && item.contents && item.contents != '') {
        // write it to the file system
        bytes = await page.writeLocation(
          item.contents,
          HAXCMS.HAXCMS_ROOT +
          '/' +
          HAXCMS.sitesDirectory +
          '/' +
          site.manifest.metadata.site.name +
          '/'
        );
      }
    }
    items = [...req.body['items']];
    // now, we can finally delete as content operations have finished
    for (let key in items) {
      let item = items[key];
      // verify if we were told to delete this item via flag not in the real spec
      if (typeof item.delete !== 'undefined' && item.delete == TRUE) {
        // load the item, or the item as built out of the itemMap
        // since we reset the UUID on creation
        if (!(page = site.loadNode(item.id))) {
          page = site.loadNode(itemMap[item.id]);
        }
        site.deleteNode(page);
        site.gitCommit(
          'Page deleted: ' + page.title + ' (' + page.id + ')'
        );
      }
    }
    await site.manifest.save();
    // now, we need to look for orphans if we deleted anything
    let orphanCheck = [...site.manifest.items];
    for (let key in orphanCheck) {
      let item = orphanCheck[key];
      // just to be safe..
      if (page = site.loadNode(item.id)) {
        // ensure that parent is valid to rescue orphan items
        if (page.parent != null && !(parentPage = site.loadNode(page.parent))) {
          page.parent = null;
          // force to bottom of things while still being in old order if lots of things got axed
          page.order = parseInt(page.order) + site.manifest.items.length - 1;
          await site.updateNode(page);
        }
      }
    }
    site.manifest.metadata.site.updated = Date.now();
    await site.manifest.save();
    // update alt formats like rss as we did massive changes
    site.updateAlternateFormats();
    site.gitCommit('Outline updated in bulk');
    await site.gitCommit('Outline updated in bulk');
    res.send(site.manifest.items);
  }
  module.exports = saveOutline;