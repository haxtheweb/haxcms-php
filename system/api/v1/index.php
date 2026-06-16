<?php
include_once dirname(__FILE__) . '/../../backend/php/bootstrapHAX.php';
include_once $HAXCMS->configDirectory . '/config.php';
include_once dirname(__FILE__) . '/../../backend/php/lib/systemRoutes/SystemApiRouter.php';
SystemApiRouter::dispatch();
