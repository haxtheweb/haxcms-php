<?php
/**
 * Shared collaborators for SystemRoutesIntegrationsTest and
 * SystemRoutesHaxiamTest (required via require_once from both so the classes
 * are declared exactly once across the suite).
 *
 * Note: the composer getallheaders polyfill (pulled in via vendor/autoload.php)
 * already defines getallheaders() under plain CLI, so the
 * haxcmsResolveIntegrationsSiteToken fallback loop is directly exercisable —
 * no shim needed or wanted here.
 */

/**
 * Shared fake for both route files. generateAppStore()/appStoreSearch() need
 * the broker-rewrite connection fields; haxiamAddUserAccess() needs machine
 * name + request token behavior.
 */
class RoutesIntegrationsHaxiamFakeHaxcms extends OperationsTestHaxcms
{
    public $protocol = 'https';
    public $domain = 'localhost:3000';
    public $systemRequestBase = '/system/api';
    public $siteConnectionJson = '{"jwt":"conn-jwt","user":"testuser"}';

    public function getRequestTokenUserName()
    {
        return $this->activeUserName;
    }

    public function getRequestToken($name)
    {
        return 'request-token-for-' . $name;
    }

    public function generateMachineName($name)
    {
        $machine = preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $name));
        return trim($machine, '-');
    }

    public function siteConnectionJSON($token, $siteName)
    {
        return $this->siteConnectionJson;
    }
}

/**
 * Token decision bound to getRequestToken() output: only the token the route
 * injected (request-token-for-<activeUser>) validates. Used to prove the
 * haxiam route strips a forged body user_token and injects its own.
 */
class HaxiamTokenBindingFakeHaxcms extends RoutesIntegrationsHaxiamFakeHaxcms
{
    public function validateRequestToken($token, $value)
    {
        return $token === 'request-token-for-' . $value;
    }
}
