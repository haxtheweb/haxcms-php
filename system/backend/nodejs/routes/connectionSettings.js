const fs = require('fs-extra');
const path = require('path');
const HAXCMS = require('../lib/HAXCMS.js');

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
function connectionSettings(req, res) {
  res.setHeader('Content-Type', 'application/javascript');
  const themes = JSON.parse(fs.readFileSync(path.join(HAXCMS.HAXCMS_ROOT, "system/coreConfig/themes.json"), 'utf8'));
  const returnData = JSON.stringify({
    getFormToken: HAXCMS.getRequestToken('form'),
    appStore: { 
          url: "/system/api/generateAppStore?app-store-token=" + HAXCMS.getRequestToken('appstore')
    },
    themes: themes,
    login: "/system/api/login",
    refreshUrl: "/system/api/refreshAccessToken",
    logout: "/system/api/logout",
    redirectUrl: HAXCMS.basePath,
    saveNodePath: "/system/api/saveNode",
    saveManifestPath: "/system/api/saveManifest",
    saveOutlinePath: "/system/api/saveOutline",
    publishSitePath: "/system/api/publishSite",
    syncSitePath:"/system/api/syncSite",
    setConfigPath:"/system/api/setConfig",
    getConfigPath:"/system/api/getConfig",
    getNodeFieldsPath:"/system/api/getNodeFields",
    getSiteFieldsPath:"/system/api/formLoad?haxcms_form_id=siteSettings",
    revertSitePath:"/system/api/revertCommit",
    createNodePath:"/system/api/createNode",
    getUserDataPath:"/system/api/getUserData",
    setUserPhotoPath:"/system/api/setUserPhoto",
    deleteNodePath:"/system/api/deleteNode",
    createNewSitePath:"/system/api/createSite",
    gitImportSitePath:"/system/api/gitImportSite",
    downloadSitePath:"/system/api/downloadSite",
    archiveSitePath:"/system/api/archiveSite",
    cloneSitePath:"/system/api/cloneSite",
    deleteSitePath:"/system/api/deleteSite",
  });
  res.send(`window.appSettings =${returnData};`);
}

module.exports = connectionSettings;