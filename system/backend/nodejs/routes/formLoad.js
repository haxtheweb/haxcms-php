const HAXCMS = require('../lib/HAXCMS.js');

/**
   * @OA\Post(
   *    path="/formLoad",
   *    tags={"cms","authenticated","form"},
   *    @OA\Parameter(
   *         name="jwt",
   *         description="JSON Web token, obtain by using  /login",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Load a form based on ID"
   *   )
   * )
   */
  function formLoad(req, res) {
    if (HAXCMS.validateRequestToken(null, 'form')) {
      let context = {
        'site':[],
        'node': [],
      };
      if ((req.query['site'])) {
        context['site'] = req.query['site'];
      }
      if ((req.query['node'])) {
        context['node'] = req.query['node'];
      }
      // @todo add support for hooking in multiple
      form = HAXCMS.loadForm(req.query['haxcms_form_id'], context);
      if ((form.fields['__failed'])) {
        res.send(
          form.fields
        );
      }
      res.send({
        'status': 200,
        'data': form
      });
    }
    else {
        req.send(403);
    }
  }
  module.exports = formLoad;