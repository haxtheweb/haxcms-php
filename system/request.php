<?php
include_once 'lib/bootstrapHAX.php';
include_once $HAXCMS->configDirectory . '/config.php';
// this will broker the request off of the op parameter
$HAXCMS->executeRequest();