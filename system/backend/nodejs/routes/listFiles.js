const HAXCMS = require('../lib/HAXCMS.js');
const fs = require('fs');
const mime = require('mime');
/**
   * @OA\Post(
   *    path="/listFiles",
   *    tags={"hax","authenticated","file"},
   *    @OA\Parameter(
   *         name="jwt",
   *         description="JSON Web token, obtain by using  /login",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Load existing files for presentation in HAX find area"
   *   )
   * )
   */
  async function listFiles(req, res) {
    let files = [];
    let site = await HAXCMS.loadSite(req.query['siteName']);
    let search = (typeof req.query['filename'] !== 'undefined') ? req.query['filename'] : '';
    // build files directory path
    let siteDirectoryPath = site.directory + '/' + site.manifest.metadata.site.name + '/files';
    let handle;
    if (handle = fs.readdirSync(siteDirectoryPath)) {
      handle.forEach(file => {
        if (
            file != "." &&
            file != ".." &&
            file != '.gitkeep' &&
            file != '.DS_Store'
        ) {
          // ensure this is a file
          if (
            fs.lstatSync(siteDirectoryPath + '/' + file).isFile()
          ) {
            // ensure this is a file and if we are searching for results then return only exact ones
            if (search == "" || file.indexOf(search) !== -1) {
              files.push({
                'path' : 'files/' + file,
                'fullUrl' :
                  HAXCMS.basePath +
                HAXCMS.sitesDirectory + '/' +
                site.manifest.metadata.site.name + '/files/' +
                    file,
                'url' : 'files/' + file,
                'mimetype' : mime.getType(siteDirectoryPath + '/' + file),
                'name' : file
              });
            }
          } else {
              // @todo maybe step into directories?
          }
        }
      });
    }
    res.send(files);
  }
  module.exports = listFiles;