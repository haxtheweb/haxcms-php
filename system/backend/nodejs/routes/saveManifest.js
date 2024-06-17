const HAXCMS = require('../lib/HAXCMS.js');
const filter_var = require('../lib/filter_var.js');
const fs = require('fs-extra');
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
  async function saveManifest(req, res) {
    // load the site from name
    let site = await HAXCMS.loadSite(req.body['site']['name']);
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
    /*if (HAXCMS.validateRequestToken(null, 'form')) {
      let context = {
        'site' : [],
        'node' : [],
      };
      if ((req.body['site'])) {
        context['site'] = req.body['site'];
      }
      if ((req.body['node'])) {
        context['node'] = req.body['node'];
      }
      form = HAXCMS.loadForm(req.body['haxcms_form_id'], context);
    }*/
    if (HAXCMS.validateRequestToken(req.body['haxcms_form_token'], req.body['haxcms_form_id'])) {
      site.manifest.title = req.body['manifest']['site']['manifest-title'].replace(/<\/?[^>]+(>|$)/g, "");
      site.manifest.description = req.body['manifest']['site']['manifest-description'].replace(/<\/?[^>]+(>|$)/g, "");
      // store some version data here just so we can find it later
      site.manifest.metadata.site.version = await HAXCMS.getHAXCMSVersion();
      site.manifest.metadata.site.domain = filter_var(
          req.body['manifest']['site']['manifest-metadata-site-domain'],
          "FILTER_SANITIZE_STRING"
      );
      site.manifest.metadata.site.logo = filter_var(
          req.body['manifest']['site']['manifest-metadata-site-logo'],
          "FILTER_SANITIZE_STRING"
      );
      site.manifest.metadata.site.tags = filter_var(
        req.body['manifest']['site']['manifest-metadata-site-tags'],
        "FILTER_SANITIZE_STRING"
      );
      if (!(site.manifest.metadata.site.static)) {
        site.manifest.metadata.site.static = {};
      }
      if (!(site.manifest.metadata.site.settings)) {
        site.manifest.metadata.site.settings = {};
      }
      if (typeof req.body['manifest']['site']['manifest-domain'] !== 'undefined') {
          let domain = filter_var(
              req.body['manifest']['site']['manifest-domain'],
              "FILTER_SANITIZE_STRING"
          );
          // support updating the domain CNAME value
          if (site.manifest.metadata.site.domain != domain) {
              site.manifest.metadata.site.domain = domain;
              fs.writeFileSync(
                  site.directory +
                      '/' +
                      site.manifest.site.name +
                      '/CNAME',
                  domain
              );
          }
      }
      let hThemes = await HAXCMS.getThemes();
      // look for a match so we can set the correct data
      for (var key in hThemes) {
        let theme = hThemes[key];
        if (
            filter_var(req.body['manifest']['theme']['manifest-metadata-theme-element'], "FILTER_SANITIZE_STRING") ==
            key
        ) {
            site.manifest.metadata.theme = theme;
        }
      }
      if (!(site.manifest.metadata.theme.variables)) {
        site.manifest.metadata.theme.variables = {};
      }

      if (typeof req.body['manifest']['theme']['manifest-metadata-theme-variables-image'] !== 'undefined') {
        site.manifest.metadata.theme.variables.image = filter_var(
          req.body['manifest']['theme']['manifest-metadata-theme-variables-image'],"FILTER_SANITIZE_STRING"
        );
      }
      if (typeof req.body['manifest']['theme']['manifest-metadata-theme-variables-imageAlt'] !== 'undefined') {
        site.manifest.metadata.theme.variables.imageAlt = filter_var(
          req.body['manifest']['theme']['manifest-metadata-theme-variables-imageAlt'], "FILTER_SANITIZE_STRING"
        );
      }
      if (typeof req.body['manifest']['theme']['manifest-metadata-theme-variables-imageLink'] !== 'undefined') {
        site.manifest.metadata.theme.variables.imageLink = filter_var(
          req.body['manifest']['theme']['manifest-metadata-theme-variables-imageLink'], "FILTER_SANITIZE_STRING"
        );
      }
      // REGIONS SUPPORT
      if (!(site.manifest.metadata.theme.regions)) {
        site.manifest.metadata.theme.regions = {};
      }
      // look for a match so we can set the correct data
      let validRegions = [
        "header",
        "sidebarFirst",
        "sidebarSecond",
        "contentTop",
        "contentBottom",
        "footerPrimary",
        "footerSecondary"
      ];
      for (var i in validRegions) {
        let value = validRegions[i];
        if (req.body['manifest']['theme']['manifest-metadata-theme-regions-' + value]) {
          for (var j in req.body['manifest']['theme']['manifest-metadata-theme-regions-' + value]) {
            let id = req.body['manifest']['theme']['manifest-metadata-theme-regions-' + value][j];
            req.body['manifest']['theme']['manifest-metadata-theme-regions-' + value][j] = filter_var(id, "FILTER_SANITIZE_STRING");
          }
          site.manifest.metadata.theme.regions[value] = req.body['manifest']['theme']['manifest-metadata-theme-regions-' + value];
        }
      }
      if (typeof req.body['manifest']['theme']['manifest-metadata-theme-variables-hexCode'] !== 'undefined') {
        site.manifest.metadata.theme.variables.hexCode = filter_var(
          req.body['manifest']['theme']['manifest-metadata-theme-variables-hexCode'],"FILTER_SANITIZE_STRING"
        );
      }
      site.manifest.metadata.theme.variables.cssVariable = "--simple-colors-default-theme-" + filter_var(
        req.body['manifest']['theme']['manifest-metadata-theme-variables-cssVariable'], "FILTER_SANITIZE_STRING"
      ) + "-7";
      site.manifest.metadata.theme.variables.icon = filter_var(
        req.body['manifest']['theme']['manifest-metadata-theme-variables-icon'],"FILTER_SANITIZE_STRING"
      );
      if (typeof req.body['manifest']['author']['manifest-license'] !== 'undefined') {
          site.manifest.license = filter_var(
              req.body['manifest']['author']['manifest-license'],
              "FILTER_SANITIZE_STRING"
          );
          if (!(site.manifest.metadata.author)) {
            site.manifest.metadata.author = {};
          }
          site.manifest.metadata.author.image = filter_var(
              req.body['manifest']['author']['manifest-metadata-author-image'],
              "FILTER_SANITIZE_STRING"
          );
          site.manifest.metadata.author.name = filter_var(
              req.body['manifest']['author']['manifest-metadata-author-name'],
              "FILTER_SANITIZE_STRING"
          );
          site.manifest.metadata.author.email = filter_var(
              req.body['manifest']['author']['manifest-metadata-author-email'],
              "FILTER_SANITIZE_STRING"
          );
          site.manifest.metadata.author.socialLink = filter_var(
              req.body['manifest']['author']['manifest-metadata-author-socialLink'],
              "FILTER_SANITIZE_STRING"
          );
      }
      if (typeof req.body['manifest']['seo']['manifest-metadata-site-settings-pathauto'] !== 'undefined') {
          site.manifest.metadata.site.settings.pathauto = filter_var(
          req.body['manifest']['seo']['manifest-metadata-site-settings-pathauto'],
          "FILTER_VALIDATE_BOOLEAN"
          );
      }
      if (typeof req.body['manifest']['seo']['manifest-metadata-site-settings-publishPagesOn'] !== 'undefined') {
        site.manifest.metadata.site.settings.publishPagesOn = filter_var(
        req.body['manifest']['seo']['manifest-metadata-site-settings-publishPagesOn'],
        "FILTER_VALIDATE_BOOLEAN"
        );
      }
      if (typeof req.body['manifest']['seo']['manifest-metadata-site-settings-sw'] !== 'undefined') {
        site.manifest.metadata.site.settings.sw = filter_var(
        req.body['manifest']['seo']['manifest-metadata-site-settings-sw'],
        "FILTER_VALIDATE_BOOLEAN"
        );
      }
      if (typeof req.body['manifest']['seo']['manifest-metadata-site-settings-forceUpgrade'] !== 'undefined') {
        site.manifest.metadata.site.settings.forceUpgrade = filter_var(
        req.body['manifest']['seo']['manifest-metadata-site-settings-forceUpgrade'],
        "FILTER_VALIDATE_BOOLEAN"
        );
      }
      if (typeof req.body['manifest']['seo']['manifest-metadata-site-settings-gaID'] !== 'undefined') {
        site.manifest.metadata.site.settings.gaID = filter_var(
        req.body['manifest']['seo']['manifest-metadata-site-settings-gaID'],
        "FILTER_SANITIZE_STRING"
        );
      }
      site.manifest.metadata.site.updated = Math.floor(Date.now() / 1000);
      // don't reorganize the structure
      await site.manifest.save(false);
      await site.gitCommit('Manifest updated');
      // rebuild the files that twig processes
      await site.rebuildManagedFiles();
      site.updateAlternateFormats();
      await site.gitCommit('Managed files updated');
      res.send(site.manifest);
    }
    else {
      res.send(403);
    }
  }
  module.exports = saveManifest;