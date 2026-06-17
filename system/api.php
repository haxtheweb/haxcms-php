<?php
include_once dirname(__FILE__) . '/backend/php/bootstrapHAX.php';
include_once $HAXCMS->configDirectory . '/config.php';
include_once dirname(__FILE__) . '/backend/php/lib/systemRoutes/SystemApiRouter.php';
if (!SystemApiRouter::dispatch()) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    print json_encode(array(
        'status' => 404,
        'message' => 'Unknown system API route',
    ));
}
