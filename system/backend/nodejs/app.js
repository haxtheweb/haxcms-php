// lib dependencies
process.env.haxcms_middleware = "node-express";
const express = require('express');
const cookieParser = require('cookie-parser');
const helmet = require('helmet');
const app = express();
const server = require('http').Server(app);
// HAXcms core settings
const HAXCMS = require('./lib/HAXCMS.js');
// routes with all requires
const routesMap = require('./routesMap.js');
// app settings
const port = 3000;
const multer = require('multer')
const upload = multer({ dest: './public/tmp/' })

app.use(express.urlencoded({limit: '50mb',  extended: false, parameterLimit: 50000 }));
app.use(helmet({
  contentSecurityPolicy: false,
  referrerPolicy: {
    policy: ["origin", "unsafe-url"],
  },
}));
app.use(cookieParser());
app.use(express.static("public"));
app.use('/', (req, res, next) => {
  res.setHeader('Access-Control-Allow-Origin', 'http://localhost:8080');
	res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS, PUT, PATCH, DELETE');
  res.setHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept');
  res.setHeader('Content-Type', 'application/json');
  // dynamic step routes in HAXcms site list UI
  if (req.url === req.url.replace(/\/createSite-step-(.*)/, "/").replace(/\/home/, "/")) {
    next();
  }
  else {
    res.setHeader('Content-Type', 'text/html');
    res.sendFile(req.url.replace(/\/createSite-step-(.*)/, "/").replace(/\/home/, "/"),
    {
      root: __dirname + "/public"
    });
  }
});
// sites need rewriting to work with PWA routes without failing file location
// similar to htaccess
app.use('/sites/',(req, res, next) => {
  // previous will catch as json, undo that
  res.setHeader('Content-Type', 'text/html');
  // send file for the index even tho route says it's a path not on our file system
  // this way internal routing picks up and loads the correct content while
  // at the same time express has delivered us SOMETHING as the path in the request
  // url doesn't actually exist
  res.sendFile(req.url.replace(/\/(.*?)\/(.*)/, "/sites/$1/index.html"),
  {
    root: __dirname + "/public"
  });
});
//pre-flight requests
app.options('*', function(req, res, next) {
	res.send(200);
});

// these routes need to return a response without a JWT validation
const openRoutes = [
  'generateAppStore',
  'connectionSettings',
  'getSitesList',
  'login',
  'logout',
  'api',
  'options',
  'openapi',
  'openapi/json',
  'refreshAccessToken'
];
// loop through methods and apply the route to the file to deliver it
// @todo ensure that we apply the same JWT checking that we do in the PHP side
// instead of a simple array of what to let go through we could put it into our
// routesMap object above and apply JWT requirement on paths in a better way
for (var method in routesMap) {
  for (var route in routesMap[method]) {
    let extra = express.json({
      type: "*/*",
      limit: '50mb'
    });
    if (route === "saveFile") {
      extra = upload.single('file-upload');
    }
    app[method](`${HAXCMS.basePath}${HAXCMS.systemRequestBase}${route}`, extra ,(req, res, next) => {
      const op = req.route.path.replace(`${HAXCMS.basePath}${HAXCMS.systemRequestBase}`, '');
      const rMethod = req.method.toLowerCase();
      if (openRoutes.includes(op) || HAXCMS.validateJWT(req, res)) {
        // call the method
        routesMap[rMethod][op](req, res, next);
      }
      else {
        res.sendStatus(403);
      }
    });
  }
}

server.listen(port, async (err) => {
	if (err) {
		throw err;
	}
  const openPkg = await import('open');
  const open = openPkg.default;
  // opens the url in the default browser 
  open('http://localhost:3000');
	/* eslint-disable no-console */
	console.log('open: http://localhost:3000');
});