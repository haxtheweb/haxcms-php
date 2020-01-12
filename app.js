
const express = require('express');
const bodyParser = require('body-parser');
const app = express();
const server = require('http').Server(app);
const port = 3000;
const helmet = require('helmet');
const path = require('path');
const fs = require('fs');

server.listen(port, (err) => {
	if (err) {
		throw err;
	}
	/* eslint-disable no-console */
	console.log('http://localhost:3000');
});

module.exports = server;

app.use(helmet());
app.use(express.static('app'))
app.use(bodyParser.json());
app.use(bodyParser.urlencoded({ extended: true }));

app.use((req, res, next) => {
	res.setHeader('Access-Control-Allow-Origin', 'http://localhost:8080');
	res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS, PUT, PATCH, DELETE');
  res.setHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept');
});

//pre-flight requests
app.options('*', function(req, res) {
	res.send(200);
});
/**
 * site-listing / login page
 */
app.get('/', (req, res) => {
  res.sendFile(path.join(__dirname + '/index.html'));
});

app.post('/system/api/login', (req, res) => {
  res.send('fake-jwt');
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
app.get('/system/api/connectionSettings', (req, res) => {
  const themes = JSON.parse(fs.readFileSync(__dirname + "/system/coreConfig/themes.json", 'utf8'));
  res.send(
    {
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
    }
  );
});