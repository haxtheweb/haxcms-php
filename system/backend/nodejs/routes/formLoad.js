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
  async function formLoad(req, res) {
    if (HAXCMS.validateRequestToken(null, 'form')) {
      let context = {
        'site':[],
        'node': [],
      };
      if ((req.body['site'])) {
        context['site'] = req.body['site'];
      }
      if ((req.body['node'])) {
        context['node'] = req.body['node'];
      }
      // @todo add support for hooking in multiple
      let id = req.query['haxcms_form_id'];
      if (!id) {
        id = req.body['haxcms_form_id'];
      }
      let form = await HAXCMS.loadForm(id, context);
      if (form.fields['__failed']) {
        res.send(
          form.fields
        );
      }
      else {
        res.send({
          'status': 200,
          'data': form
        });  
      }
    }
    else {
      res.send(403);
    }
  }
  module.exports = formLoad;