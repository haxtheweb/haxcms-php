const fs = require('fs-extra');
const path = require('path');
const HAXCMS = require('../lib/HAXCMS.js');
const { Git } = require('git-interface');
const JSONOutlineSchemaItem = require('../lib/JSONOutlineSchemaItem.js');

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
   *                     property="theme",
   *                     type="object"
   *                 ),
   *                 required={"site","node"},
   *                 example={
   *                    "site": {
   *                      "name": "mynewsite",
   *                      "domain": ""
   *                    },
   *                    "theme": {
   *                      "name": "learn-two-theme",
   *                      "variables": {
   *                        "image":"",
   *                        "icon":"",
   *                        "hexCode":"",
   *                        "cssVariable":"",
   *                        }                   
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
async function createSite(req, res) {
  if (HAXCMS.validateRequestToken()) {
    let domain = null;
    // woohoo we can edit this thing!
    if (req.body['site']['domain'] && req.body['site']['domain'] != null && req.body['site']['domain'] != '') {
      domain = req.body['site']['domain'];
    }
    // null in the event we get hits that don't have this
    let build = null;
    let filesToDownload = [];
    // support for build info. the details used to actually create this site originally
    if (req.body['build']) {
      build = {};
      // version of the platform used when originally created
      build.version = await HAXCMS.getHAXCMSVersion();
      // course, website, portfolio, etc
      build.structure = req.body['build']['structure'];
      // TYPE of structure we are creating
      build.type = req.body['build']['type'];
      if (build.type == 'docx import' || build.structure == "import") {
        // JSONOutlineSchemaItem Array
        build.items = req.body['build']['items'];
      }
      if (req.body['build']['files']) {
        filesToDownload = req.body['build']['files'];
      }
    }
    // sanitize name
    let name = HAXCMS.generateMachineName(req.body['site']['name']);
    let site = await HAXCMS.loadSite(
        name.toLowerCase(),
        true,
        domain,
        build
    );
    // now get a new item to reference this into the top level sites listing
    let schema = new JSONOutlineSchemaItem();
    schema.id = site.manifest.id;
    schema.title = name;
    schema.location =
        HAXCMS.basePath +
        HAXCMS.sitesDirectory +
        '/' +
        site.manifest.metadata.site.name +
        '/index.html';
    schema.slug = schema.location;
    schema.metadata = {
        site: {},
        theme: {}
    }
    // store build data in case we need it down the road
    schema.metadata.build = build;
    schema.metadata.site.name = site.manifest.metadata.site.name;
    let theme = HAXCMS.HAXCMS_DEFAULT_THEME;
    if (req.body['site']['theme'] && typeof req.body['site']['theme'] === "string") {
      theme = req.body['site']['theme'];
    }
    let themesAry = HAXCMS.getThemes();
    // look for a match so we can set the correct data
    for (var key in themesAry) {
      if (theme == key) {
        schema.metadata.theme = themesAry[key];
      }
    }
    schema.metadata.theme.variables = {};
    // description for an overview if desired
    if (req.body['site']['description'] && req.body['site']['description'] != '' && req.body['site']['description'] != null) {
        schema.description = req.body['site']['description'].replace(/<\/?[^>]+(>|$)/g, "");
    }
    // background image / banner
    if (req.body['theme']['image'] && req.body['theme']['image'] != '' && req.body['theme']['image'] != null) {
      schema.metadata.site.logo = req.body['theme']['image'];
    }
    else {
      schema.metadata.site.logo = 'assets/banner.jpg';
    }
    // icon to express the concept / visually identify site
    if ((req.body['theme']['icon']) && req.body['theme']['icon'] != '' && req.body['theme']['icon'] != null) {
      schema.metadata.theme.variables.icon = req.body['theme']['icon'];
    }
    let hex = HAXCMS.HAXCMS_FALLBACK_HEX;
    // slightly style the site based on css vars and hexcode
    if ((req.body['theme']['hexCode']) && req.body['theme']['hexCode'] != '' && req.body['theme']['hexCode'] != null) {
       hex = req.body['theme']['hexCode'];
    }
    schema.metadata.theme.variables.hexCode = hex;
    let cssvar = '--simple-colors-default-theme-light-blue-7';
    if ((req.body['theme']['cssVariable']) && req.body['theme']['cssVariable'] != '' && req.body['theme']['cssVariable'] != null) {
        cssvar = req.body['theme']['cssVariable'];
    }
    schema.metadata.theme.variables.cssVariable = cssvar;
    schema.metadata.site.settings = {};
    schema.metadata.site.settings.lang = 'en-US';
    schema.metadata.site.settings.publishPagesOn = true;
    schema.metadata.site.created = Math.floor(Date.now() / 1000);
    schema.metadata.site.updated = Math.floor(Date.now() / 1000);
    // check for publishing settings being set globally in HAXCMS
    // this would allow them to fork off to different locations down stream
    schema.metadata.site.git = {};
    if (HAXCMS.config.site.git.vendor) {
        schema.metadata.site.git = HAXCMS.config.site.git;
        delete schema.metadata.site.git.keySet;
        delete schema.metadata.site.git.email;
        delete schema.metadata.site.git.user;
    }
    // mirror the metadata information into the site's info
    // this means that this info is available to the full site listing
    // as well as this individual site. saves on performance / calls
    // later on if we only need to hit 1 file each time to get all the
    // data we need.
    for (var key in schema.metadata) {
        site.manifest.metadata[key] = schema.metadata[key];
    }
    site.manifest.metadata.node = {};
    site.manifest.metadata.node.fields = {};
    site.manifest.description = schema.description;
    // save the outline into the new site
    await site.manifest.save(false);
    // walk through files if any came across and save each of them
    if (filesToDownload && filesToDownload.isArray && filesToDownload.isArray()) {
      for (var locationName in filesToDownload) {
        let downloadLocation = filesToDownload[locationName];
        let file = new HAXCMSFile();
        // check for a file upload; we block a few formats by design
        let fileResult = file.save({
          "name" : locationName,
          "tmp_name" : downloadLocation,
          "bulk-import" : true
        }, site);
      }
    }
    // main site schema doesn't care about publishing settings
    delete schema.metadata.site.git;

    const git = new Git({
        dir: site.directory + '/' + site.manifest.metadata.site.name
    });
    git.setDir(site.directory + '/' + site.manifest.metadata.site.name);
    await git.init();
    try {
        await git.add();
        await git.commit('A new journey begins: ' + site.manifest.title + ' (' + site.manifest.id + ')');
        // make a branch but dont use it
        if (site.manifest.metadata.site.git && site.manifest.metadata.site.git.staticBranch) {
            await git.createBranch(
                site.manifest.metadata.site.git.staticBranch
            );
        }
        if (site.manifest.metadata.site.git && site.manifest.metadata.site.git.branch) {
            await git.createBranch(
                site.manifest.metadata.site.git.branch
            );
        }
    }
    catch(e) {
        console.warn(e);
    }
    
    res.send({
      "status": 200,
      "data": schema
    });
  }
  else {
      res.send(403);
  }
}
module.exports = createSite;