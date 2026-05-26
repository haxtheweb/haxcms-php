<?php
trait OperationsRouteFormLoad {
  /**
   * @OA\Post(
   *    path="/formLoad",
   *    tags={"cms","authenticated","form"},
   *    @OA\Parameter(
   *         name="haxcms_form_id",
   *         description="Form identifier to load",
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
  public function formLoad() {
    if ($GLOBALS['HAXCMS']->validateRequestToken(null, 'form')) {
      $context = array(
        'site' => array(),
        'node' => array(),
      );
      if (isset($this->params['site'])) {
        $context['site'] = $this->params['site'];
      }
      if (isset($this->params['node'])) {
        $context['node'] = $this->params['node'];
      }
      // @todo add support for hooking in multiple
      $form = $GLOBALS['HAXCMS']->loadForm($this->params['haxcms_form_id'], $context);
      if (isset($form->fields['__failed'])) {
        return array(
          $form->fields
        );
      }
      return array(
        'status' => 200,
        'data' => $form
      );
    }
    else {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid request token',
        )
      );
    }
  }
}
