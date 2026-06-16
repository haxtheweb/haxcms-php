<?php
include_once dirname(__FILE__) . '/backend/php/bootstrapHAX.php';
include_once $HAXCMS->configDirectory . '/config.php';
include_once dirname(__FILE__) . '/backend/php/lib/systemRoutes/SystemApiRouter.php';
// Try v1 system routes first; fall back to legacy op routing
if (SystemApiRouter::dispatch()) {
    return;
}
// Legacy op routing for backward compatibility
$HAXCMS->executeRequest();
