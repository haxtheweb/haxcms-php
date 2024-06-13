const HAXCMS = require('../lib/HAXCMS.js');

/**
   * @OA\Post(
   *    path="/deleteNode",
   *    tags={"cms","authenticated","node"},
   *    @OA\Parameter(
   *         name="jwt",
   *         description="JSON Web token, obtain by using  /login",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Delete a node"
   *   )
   * )
   */
  async function deleteNode(req, res) {
    let site = await HAXCMS.loadSite(req.body['site']['name']);
    // update the page's content, using manifest to find it
    // this ensures that writing is always to what the file system
    // determines to be the correct page
    let page;
    if (page = site.loadNode(req.body['node']['id'])) {
        if (await site.deleteNode(page) === false) {
          res.send(500);
        } else {
          // now, we need to look for orphans if we deleted anything
          for (var key in site.manifest.items) {
            // just to be safe..
            let pageUpdate;
            if (pageUpdate = site.loadNode(site.manifest.items[key].id)) {
              // ensure that parent is valid to rescue orphan items
              let parentPage;
              if (pageUpdate.parent != null && !(parentPage = site.loadNode(pageUpdate.parent))) {
                pageUpdate.parent = null;
                // force to bottom of things while still being in old order if lots of things got axed
                pageUpdate.order = parseInt(pageUpdate.order) + site.manifest.items.length - 1;
                site.updateNode(pageUpdate);
              }
            }
          }
          await site.gitCommit(
            'Page deleted: ' + page.title + ' (' + page.id + ')'
          );
          res.send({
            status: 200,
            data: page
          });
        }
    } else {
        res.send(500);
    }
  }
  module.exports = deleteNode;