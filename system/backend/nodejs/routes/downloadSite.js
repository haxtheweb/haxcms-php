const HAXCMS = require('../lib/HAXCMS.js');
const fs = require('fs-extra');
const archiver = require('archiver');
/**
   * @OA\Post(
   *    path="/downloadSite",
   *    tags={"cms","authenticated","site","meta"},
   *    @OA\Parameter(
   *         name="jwt",
   *         description="JSON Web token, obtain by using  /login",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\RequestBody(
   *        @OA\MediaType(
   *             mediaType="application/json",
   *             @OA\Schema(
   *                 @OA\Property(
   *                     property="site",
   *                     type="object"
   *                 ),
   *                 required={"site"},
   *                 example={
   *                    "site": {
   *                      "name": "mynewsite"
   *                    },
   *                 }
   *             )
   *         )
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Download the site folder as a zip file"
   *   )
   * )
   */
  async function downloadSite(req, res) {
    // load site
    let site = await HAXCMS.loadSite(req.body['site']['name']);
    // helpful boilerplate https://stackoverflow.com/questions/29873248/how-to-zip-a-whole-directory-and-download-using-php
    let dir = HAXCMS.HAXCMS_ROOT + '/' + HAXCMS.sitesDirectory + '/' + site.name;
    // form a basic name
    let zip_file =
      HAXCMS.HAXCMS_ROOT +
      '/' +
      HAXCMS.publishedDirectory +
      '/' +
      site.name +
      '.zip';
    // Get real path for our folder
    let rootPath = await fs.realpath(dir);
    // Initialize archive object
    await zipDirectory(rootPath, zip_file);
    res.send({
      status: 200,
      data: {
      'link':
        HAXCMS.basePath +
        HAXCMS.publishedDirectory +
        '/' + site.name +
        '.zip',
      'name': site.name
      }
    });
  }


/**
 * @param {String} sourceDir: /some/folder/to/compress
 * @param {String} outPath: /path/to/created.zip
 * @returns {Promise}
 */
function zipDirectory(sourceDir, outPath) {
  const archive = archiver('zip', { zlib: { level: 9 }});
  const stream = fs.createWriteStream(outPath);

  return new Promise((resolve, reject) => {
    archive
      .directory(sourceDir, false)
      .on('error', err => reject(err))
      .pipe(stream)
    ;

    stream.on('close', () => resolve());
    archive.finalize();
  });
}
  module.exports = downloadSite;