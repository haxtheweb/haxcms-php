const HAXCMS = require('../lib/HAXCMS.js');
const fs = require('fs');
const YAML = require('yaml')

/**
   * Generate the swagger API documentation for this site
   * 
   * @OA\Post(
   *    path="/openapi/json",
   *    tags={"api"},
   *    @OA\Response(
   *        response="200",
   *        description="API documentation in JSON"
   *    )
   * )
   */
  async function openapi(req, res) {
    // return json / yalm generated from php version
    let format = 'yaml';
    let formatClass = YAML;
    // default is yaml but also support JSON output
    if (req.route.path === "/system/api/openapi/json") {
      format = 'json';
      formatClass = JSON;
    }
    else {
      res.setHeader('Content-Type', 'application/yaml');
    }
    let openapi = {};
    try {
      let fileContents = fs.readFileSync(`./lib/openapi/spec.${format}`,
        {encoding:'utf8', flag:'r'}, 'utf8');
      openapi = formatClass.parse(fileContents);
    } catch (e) {
        console.warn(e);
    }
    // dynamically add the version
    openapi.info.version = await HAXCMS.getHAXCMSVersion();
    openapi.servers = [];
    openapi.servers[0] = {};
    // generate url dynamically w/ path to the API route
    openapi.servers[0].url = HAXCMS.protocol + '://' + HAXCMS.domain + HAXCMS.basePath + HAXCMS.systemRequestBase;
    openapi.servers[0].description = "Site list / dashboard for administrator user";
    // output, yaml we have to exit early or we'll get encapsulation
    res.send(formatClass.stringify(openapi));
  }
  module.exports = openapi;