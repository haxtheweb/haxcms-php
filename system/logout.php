<?php
// @todo need to run some kind of shut down routine for logging out
header('Content-Type: application/json');
header('Status: 200');
print json_encode('loggedout');
exit();
?>
