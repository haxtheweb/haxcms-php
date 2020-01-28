const HAXCMS = require('../lib/HAXCMS.js');

/**
   * @OA\Post(
   *    path="/siteUpdateAlternateFormats",
   *    tags={"cms","authenticated","meta"},
   *    @OA\Parameter(
   *         name="jwt",
   *         description="JSON Web token, obtain by using  /login",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Update the alternative formats surrounding a site"
   *   )
   * )
   */
  function siteUpdateAlternateFormats(req, res) {
    format = NULL;
    site = HAXCMS.loadSite(req.query['site']['name']);
    if ((req.query['format'])) {
      format = req.query['format'];
    }
    site.updateAlternateFormats(format);
  }
  module.exports = siteUpdateAlternateFormats;