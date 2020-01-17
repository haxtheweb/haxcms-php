
const express = require('express');
const bodyParser = require('body-parser');
const app = express();
const server = require('http').Server(app);
const port = 3000;
const helmet = require('helmet');
const path = require('path');
const fs = require('fs-extra');
const HAXCMS_ROOT = process.env.HAXCMS_ROOT || "../../../";
const apiBase = '/system/api';
const sitesDirectory = 'sites';
const basePath = '/';
app.use(helmet());
app.use(express.static("public"))
app.use(bodyParser.json());
app.use(bodyParser.urlencoded({ extended: true }));

app.use((req, res, next) => {
	res.setHeader('Access-Control-Allow-Origin', 'http://localhost:8080');
	res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS, PUT, PATCH, DELETE');
  res.setHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept');
  res.setHeader('Content-Type', 'application/json');
  next();
});

//pre-flight requests
app.options('*', function(req, res) {
	res.send(200);
});

/**
 * Login
 */
app.post(`${apiBase}/login`, (req, res) => {
  if (req.body && req.body.u && req.body.p) {
    // @todo need to read off and validate the jwt
    res.send('"fake-jwt"');
  }
});
/**
 * Logout
 */
app.post(`${apiBase}/logout`, (req, res) => {
  res.send('"user-logged-out"');
});

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
  // @todo this looks correct but its not doing it async so never returns the right value
  app.get(`${apiBase}/listSites`, async (req, res) => {
    // top level fake JOS
    let returnData = {
      id: '123-123-123-123',
      title: 'My sites',
      author: 'me',
      description: 'All of my micro sites I know and love',
      license: 'by-sa',
      metadata: {},
      items: []
    };
    // Loop through all the files in the temp directory
    const files = fs.readdirSync(HAXCMS_ROOT + 'sites');
    // Need to use a for loop to remain syncronous
    for (const item of files) {
      const stat = fs.statSync(HAXCMS_ROOT + 'sites/' + item)
      if (stat.isDirectory() && item != '.git') {
        try {
          let site = JSON.parse(await fs.readFileSync(path.join(__dirname, HAXCMS_ROOT, `${sitesDirectory}/${item}/site.json`), 'utf8'));
          site.location = `${basePath}${sitesDirectory}/${item}/`;
          site.metadata.pageCount = site.items.length;
          delete site.items;
          returnData.items.push(site);  
        }
        catch(err) {
          console.error(err)
        }
      }
    }
    res.send(returnData);
  });

/**
 * @OA\Post(
 *    path="/connectionSettings",
 *    tags={"cms"},
 *    @OA\Response(
 *        response="200",
 *        description="Generate the connection settings dynamically for implying we have a backend"
 *   )
 * )
 */
app.get(`${apiBase}/connectionSettings`, (req, res) => {
  res.setHeader('Content-Type', 'application/javascript');
  const themes = JSON.parse(fs.readFileSync(path.join(__dirname, HAXCMS_ROOT, "system/coreConfig/themes.json"), 'utf8'));
  const returnData = JSON.stringify({
    getFormToken:"_fKooppQmgxzaRhIx7s2OWt7atWUJiHICt4vksHkB6E",
    jwt: null,
    appStore: { 
      url: "/system/api/generateAppStore?app-store-token=dxBSYYShFGgmxdII6NxM0F9ZFPHA7P_hh0izsdu3B5s"
    },
    themes: themes,
    login: "/system/api/login",
    refreshUrl: "/system/api/refreshAccessToken",
    logout: "/system/api/logout",
    redirectUrl: "/",
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
});

server.listen(port, (err) => {
	if (err) {
		throw err;
	}
	/* eslint-disable no-console */
	console.log('http://localhost:3000');
});

// module.exports = app;