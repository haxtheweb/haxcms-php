const fs = require('fs-extra');
const path = require('path');
const HAXCMS = require('../lib/HAXCMS.js');
const url = require('url');

/**
 * @OA\Get(
 *    path="/connectionSettings",
 *    tags={"cms"},
 *    @OA\Response(
 *        response="200",
 *        description="Generate the connection settings dynamically for implying we have a backend"
 *   )
 * )
 */
async function connectionSettings(req, res) {
  res.setHeader('Content-Type', 'application/javascript');
  const themes = JSON.parse(await fs.readFileSync(path.join(HAXCMS.coreConfigPath, "themes.json"), 'utf8'));
  // this is the correct base if we're being called for connection from inside a site
  let baseAPIPath = HAXCMS.basePath + HAXCMS.systemRequestBase;
  // top level haxcms listing can't include basePath as it's the root already
  if (req.headers && req.headers.referer && !req.headers.referer.includes('/sites/')) {
    baseAPIPath = HAXCMS.systemRequestBase;
  }
  // express gives this up on requests but doesn't know it ahead of time
  if (req.headers && req.headers.referer) {
    let details = new url.URL(req.headers.referer);
    HAXCMS.protocol = details.protocol.replace(':', '');
    HAXCMS.domain = details.host;
    HAXCMS.request_url = details;
  }
  const returnData = JSON.stringify({
    token: HAXCMS.getRequestToken(),
    getFormToken: HAXCMS.getRequestToken('form'),
    appStore: {
      url: `${baseAPIPath}generateAppStore?app-store-token=${HAXCMS.getRequestToken('appstore')}`
    },
    themes: themes,
    connectionSettings: `${baseAPIPath}connectionSettings`,
    login: `${baseAPIPath}login`,
    refreshUrl: `${baseAPIPath}refreshAccessToken`,
    logout: `${baseAPIPath}logout`,
    redirectUrl: HAXCMS.basePath,
    saveNodePath: `${baseAPIPath}saveNode`,
    saveManifestPath: `${baseAPIPath}saveManifest`,
    saveOutlinePath: `${baseAPIPath}saveOutline`,
    publishSitePath: `${baseAPIPath}publishSite`,
    syncSitePath: `${baseAPIPath}syncSite`,
    setConfigPath:`${baseAPIPath}setConfig`,
    getConfigPath: `${baseAPIPath}getConfig`,
    getNodeFieldsPath: `${baseAPIPath}getNodeFields`,
    getSiteFieldsPath: `${baseAPIPath}formLoad?haxcms_form_id=siteSettings`,
    revertSitePath: `${baseAPIPath}revertCommit`,
    createNodePath: `${baseAPIPath}createNode`,
    getUserDataPath: `${baseAPIPath}getUserData`,
    setUserPhotoPath: `${baseAPIPath}setUserPhoto`,
    deleteNodePath: `${baseAPIPath}deleteNode`,
    createSite: `${baseAPIPath}createSite`,
    gitImportSite: `${baseAPIPath}gitImportSite`,
    downloadSite: `${baseAPIPath}downloadSite`,
    archiveSite: `${baseAPIPath}archiveSite`,
    copySite: `${baseAPIPath}cloneSite`,
    getSitesList: `${baseAPIPath}listSites`,
  });
  res.send(`window.appSettings =${returnData};`);
}

module.exports = connectionSettings;