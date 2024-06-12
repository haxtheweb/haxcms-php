const HAXCMS = require('../lib/HAXCMS.js');
const JSONOutlineSchemaItem = require('../lib/JSONOutlineSchemaItem.js');
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
  let nodeParams = req.body;
  let site = await HAXCMS.loadSite(req.body['site']['name'].toLowerCase());
  // implies we've been TOLD to create nodes
  // this is typically from a docx import
  if (nodeParams['items']) {
    // create pages
    for (i=0; i < nodeParams['items'].length; i++) {
      // outline-designer allows delete + confirmation but we don't have anything
      // so instead, just don't process the thing in question if asked to delete it
      if (nodeParams['items'][i]['delete'] && nodeParams['items'][i]['delete'] == true) {
        // do nothing
      }
      else {
        item = site.addPage(
        nodeParams['items'][i]['parent'], 
        nodeParams['items'][i]['title'], 
        'html', 
        nodeParams['items'][i]['slug'],
        nodeParams['items'][i]['id'],
        nodeParams['items'][i]['indent'],
        nodeParams['items'][i]['contents']
        );  
      }
    }
    site.gitCommit(count(nodeParams['items']) + ' pages added'); 
  }
  else {
    // generate a new item based on the site
    item = site.itemFromParams(nodeParams);
    item.metadata.images = [];
    item.metadata.videos = [];
    // generate the boilerplate to fill this page
    site.recurseCopy(
      HAXCMS.HAXCMS_ROOT + '/system/boilerplate/page/default',
        site.directory +
            '/' +
            site.manifest.metadata.site.name +
            '/' +
            item.location.replace('/index.html', '')
    );
    // add the item back into the outline schema
    site.manifest.addItem(item);
    await site.manifest.save();
    // support for duplicating the content of another item
    if (nodeParams['node']['duplicate']) {
      // verify we can load this id
      if (nodeToDuplicate = site.loadNode(nodeParams['node']['duplicate'])) {
          content = site.getPageContent(nodeToDuplicate);
          // verify we actually have the id of an item that we just created
          if (page = site.loadNode(item.id)) {
          // write it to the file system
          // this all seems round about but it's more secure
          bytes = page.writeLocation(
              content,
              HAXCMS.HAXCMS_ROOT +
              '/' +
              HAXCMS.sitesDirectory +
              '/' +
              site.manifest.metadata.site.name +
              '/'
          );
          }
      }
    }
    // implies front end was told to generate a page with set content
    // this is possible when importing and processing a file to generate
    // html which becomes the boilerplated content in effect
    else if (nodeParams['node']['contents']) {
      if (page = site.loadNode(item.id)) {
          // write it to the file system
          bytes = page.writeLocation(
          nodeParams['node']['contents'],
          HAXCMS.HAXCMS_ROOT +
          '/' +
          HAXCMS.sitesDirectory +
          '/' +
          site.manifest.metadata.site.name +
          '/'
          );
      }
    }
    await site.gitCommit('Page added:' + item.title + ' (' + item.id + ')'); 
    // update the alternate formats as a new page exists
    await site.updateAlternateFormats();
  }
  res.send(item);
}
module.exports = createNode;