<?php
// a private key to do an additional hash via
$HAXCMS->privateKey = '969c824f-7b29-4384-a89d-108237fb787f';
// super admin account
$HAXCMS->superUser->name = 'admin';
// super admin password, you must set this in order for HAX to work
$HAXCMS->superUser->password = 'admin';
// set basePath to be the haxCMS location we've got this placed at
$HAXCMS->basePath = '/';
// see system/lib/HAXCMS.php for additional deeper options
// including $HAXCMS->user and $HAXCMS->password which can be used
// to allow for lower permissioned users to login to specific sites

// API keys - uncomment these in order to wire up more advanced API
// functionality in HAX like youtube integration
$HAXCMS->apiKeys['youtube'] = 'AIzaSyAF9zKXv-fxus9GNqn40SHzTn6F8A7h-Yo';
$HAXCMS->apiKeys['googlepoly'] = 'AIzaSyBeEqSbaxDB8KHCnDfNqepefnQe8fxRqjw';
$HAXCMS->apiKeys['memegenerator'] = 'e7fbcd7f-8d76-4513-9698-e20de4362d99';
$HAXCMS->apiKeys['vimeo'] = '0a718b853bad87571d52e9fb554e0a43';
$HAXCMS->apiKeys['giphy'] = 'mr3blNkTT0HeTvtyPPT4TIftqUSgyHoO';
$HAXCMS->apiKeys['unsplash'] = '0e1fa3a203724415c10c03581e8db8a43e8bc8906ad934e0f321d28be16281ff';
$HAXCMS->apiKeys['flickr'] = '43ccc969703b7afd4e2a1b16f02ce84e';
$HAXCMS->apiKeys['pixabay'] = '7839766-f49bb4174cd49cb587944a5f7';