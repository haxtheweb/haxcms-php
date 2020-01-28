const HAXCMS = require('../lib/HAXCMS.js');
/**
 * @OA\Post(
 *     path="/createNode",
 *     tags={"cms","authenticated","node"},
 *     @OA\Parameter(
 *         name="jwt",
 *         description="JSON Web token, obtain by using  /login",
 *         in="query",
 *         required=true,
 *         @OA\Schema(type="string")
 *     ),
 *     @OA\RequestBody(
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
 *                 @OA\Property(
 *                     property="indent",
 *                     type="number"
 *                 ),
 *                 @OA\Property(
 *                     property="order",
 *                     type="number"
 *                 ),
 *                 @OA\Property(
 *                     property="parent",
 *                     type="string"
 *                 ),
 *                 @OA\Property(
 *                     property="description",
 *                     type="string"
 *                 ),
 *                 @OA\Property(
 *                     property="metadata",
 *                     type="object"
 *                 ),
 *                 required={"site","node"},
 *                 example={
 *                    "site": {
 *                      "name": "mysite"
 *                    },
 *                    "node": {
 *                      "id": null,
 *                      "title": "Cool post",
 *                      "location": null
 *                    },
 *                    "indent": null,
 *                    "order": null,
 *                    "parent": null,
 *                    "description": "An example description for the post",
 *                    "metadata": {"tags": "metadata,can,be,whatever,you,want","other":"stuff"}
 *                 }
 *             )
 *         )
 *     ),
 *    @OA\Response(
 *        response="200",
 *        description="object with full properties returned"
 *   )
 * )
 */
async function createNode(req, res) {
  let site = HAXCMS.loadSite(strtolower(req.query['site']['name']));
  // get a new item prototype
  let item = HAXCMS.outlineSchema.newItem();
  // set the title
  item.title = req.query['node']['title'].replace("\n", '');
  if ((req.query['node']['id']) && req.query['node']['id'] != '' && req.query['node']['id'] != null) {
      item.id = req.query['node']['id'];
  }
  let cleanTitle = HAXCMS.cleanTitle(item.title);
  if ((req.query['node']['location']) && req.query['node']['location'] != '' && req.query['node']['location'] != null) {
      cleanTitle = HAXCMS.cleanTitle(req.query['node']['location']);
  }
  // ensure this location doesn't exist already
  item.location =
      'pages/' + site.getUniqueLocationName(cleanTitle) + '/index.html';

  if ((req.query['indent']) && req.query['indent'] != '' && req.query['indent'] != null) {
      item.indent = req.query['indent'];
  }
  if ((req.query['order']) && req.query['order'] != '' && req.query['order'] != null) {
      item.order = req.query['order'];
  }
  if ((req.query['parent']) && req.query['parent'] != '' && req.query['parent'] != null) {
      item.parent = req.query['parent'];
  } else {
      item.parent = null;
  }
  if ((req.query['description']) && req.query['description'] != '' && req.query['description'] != null) {
      item.description = str_replace("\n", '', req.query['description']);
  }
  if ((req.query['order']) && req.query['metadata'] != '' && req.query['metadata'] != null) {
      item.metadata = req.query['metadata'];
  }
  item.metadata.created = Date.now();
  item.metadata.updated = Date.now();
  // add the item back into the outline schema
  // @todo fix logic here to actually create the page based on 1 call
  // this logic should be cleaned up in addPage to allow for
  // passing in arguments
  site.recurseCopy(
      HAXCMS.HAXCMS_ROOT + '/system/boilerplate/page/default',
      site.directory +
          '/' +
          site.manifest.metadata.site.name +
          '/' +
          item.location.replace('/index.html', '')
  );
  site.manifest.addItem(item);
  site.manifest.save();
  site.gitCommit('Page added:' + item.title + ' (' + item.id + ')');
  return item;
}
module.exports = createNode;