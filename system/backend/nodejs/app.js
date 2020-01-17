// lib dependencies
const express = require('express');
const bodyParser = require('body-parser');
const app = express();
const cookieParser = require('cookie-parser');
const server = require('http').Server(app);
const helmet = require('helmet');
const path = require('path');
const fs = require('fs-extra');
// HAXcms core settings
const HAXCMS = require('./lib/HAXCMS.js');
// app settings
const port = 3000;
app.use(helmet());
app.use(cookieParser());
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
// app routes
const routes = {
  post: {
    login: require('./routes/login.js'),
    logout: require('./routes/logout.js'),
    getUserData: require('./routes/getUserData.js'),
    refreshAccessToken: require('./routes/refreshAccessToken.js'),
  },
  get: {
    listSites: require('./routes/listSites.js'),
    connectionSettings: require('./routes/connectionSettings.js'),
    generateAppstore: require('./routes/generateAppstore.js'),
  }
};

// loop through methods and apply the route to the file to deliver it
// @todo ensure that we apply the same JWT checking that we do in the PHP side
// instead of a simple array of what to let go through we could put it into our
// routes object above and apply JWT requirement on paths in a better way
for (var method in routes) {
  for (var route in routes[method]) {
    app[method](`${HAXCMS.apiBase}/${route}`, routes[method][route]);
  }
}

server.listen(port, (err) => {
	if (err) {
		throw err;
	}
	/* eslint-disable no-console */
	console.log('http://localhost:3000');
});