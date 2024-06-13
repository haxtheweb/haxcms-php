// cli bridge
process.env.haxcms_middleware = "node-cli";
// HAXcms core settings
const HAXCMS = require('./lib/HAXCMS.js');
const routesMap = require('./routesMap.js');

// process arguments from commandline appropriately
const args = process.argv;
args.shift();
args.shift();
let body = {};
let cliOp = 'listCalls';
// 1st 2 args call the program itself
for (var i in args) {
  let arg = args[i].split('=');
  let param = arg[0].replace('--','');
  let value = arg[1];
  switch (param) {
    case 'op':
      cliOp = value;
    break;
    case "nodeTitle":
      body.node = body.node || {};
      body.node.title = value;
    break;
    case "site":
      body.site = {
        name: value
      };
    break;
    // set everything else as parameters
    default:
      body[param] = value;
    break;
  }
}
const cli = {
  post: (path, callback) => callback({
    route: {
      path: path
    },
    body: body,
    method: "post"
  },
  {
    query: {},
    send: (data) => console.log(data),
  }),
  get: (path, callback) => callback({
    route: {
      path: path
    },
    body: body,
    method: "get"
  },
  {
    query: {},
    send: (data) => console.log(data),
  }),
};
// loop through methods and apply the route to the file to deliver it
// @todo ensure that we apply the same JWT checking that we do in the PHP side
// instead of a simple array of what to let go through we could put it into our
// routes object above and apply JWT requirement on paths in a better way
for (var method in routesMap) {
  for (var route in routesMap[method]) {
    if (cliOp === 'listCalls') {
      console.log(route);
    }
    else if (route === cliOp) {
      cli[method](`${HAXCMS.basePath}${HAXCMS.systemRequestBase}${route}`, (req, res) => {
        const op = req.route.path.replace(`${HAXCMS.basePath}${HAXCMS.systemRequestBase}`, '');
        const rMethod = req.method.toLowerCase();
        if (HAXCMS.validateJWT(req, res)) {
          // call the method
          routesMap[rMethod][op](req, res);
        }
        else {
          console.error("route connection issue");
        }
      });
    }
  }
}