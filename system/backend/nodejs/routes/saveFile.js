const path = require('path');
const HAXCMS = require('../lib/HAXCMS.js');
const HAXCMSFile = require('../lib/HAXCMSFile.js');
/**
   * @OA\Post(
   *    path="/saveFile",
   *    tags={"hax","authenticated","file"},
   *    @OA\Parameter(
   *         name="jwt",
   *         description="JSON Web token, obtain by using  /login",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Parameter(
   *         name="file-upload",
   *         description="File to upload",
   *         in="header",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\RequestQuery(
   *        @OA\MediaType(
   *             mediaType="application/json",
   *             @OA\Schema(
   *                 @OA\Property(
   *                     property="site",
   *                     type="object"
   *                 ),
   *                 @OA\Property(
   *                     property="node",
   *                     type="object"
   *                 ),
   *                 required={"site"},
   *                 example={
   *                    "site": {
   *                      "name": "mynewsite"
   *                    },
   *                    "node": {
   *                      "id": ""
   *                    }
   *                 }
   *             )
   *         )
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="User is uploading a file to present in a site"
   *   )
   * )
   */
  async function saveFile(req, res, next) {
    if (req.file.fieldname == 'file-upload') {
      let site = await HAXCMS.loadSite(req.query['siteName']);
      // update the page's content, using manifest to find it
      // this ensures that writing is always to what the file system
      // determines to be the correct page
      let page = site.loadNode(req.query['nodeId']);
      let upload = req.file;
      upload.name = upload.originalname;
      upload.tmp_name = path.join("./", upload.path);
      let file = new HAXCMSFile();
      let fileResult = await file.save(upload, site, page);
      if (!fileResult || fileResult['status'] == 500) {
        res.send(500);
      }
      else {
        await site.gitCommit('File added: ' + upload['name']);
        res.send(fileResult);  
      }
    }
    else {
      res.send(500);
    }
  }
  module.exports = saveFile;