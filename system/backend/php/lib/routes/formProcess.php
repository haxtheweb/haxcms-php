<?php
trait OperationsRouteFormProcess {
  /**
   * @OA\Post(
   *    path="/formProcess",
   *    tags={"cms","authenticated","form"},
   *    @OA\Parameter(
   *         name="haxcms_form_id",
   *         description="Form identifier to process",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Parameter(
   *         name="haxcms_form_token",
   *         description="Form request token",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Process a form based on ID and input data"
   *   )
   * )
   */
  public function formProcess() {
    if ($GLOBALS['HAXCMS']->validateRequestToken($this->params['haxcms_form_token'], $this->params['haxcms_form_id'])) {
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
      $form = $GLOBALS['HAXCMS']->processForm($this->params['haxcms_form_id'], $this->params, $context);
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
