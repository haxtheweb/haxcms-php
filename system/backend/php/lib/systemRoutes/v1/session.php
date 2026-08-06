<?php
include_once dirname(__FILE__) . '/../../routes/RoutesMap.php';
include_once dirname(__FILE__) . '/../../Operations.php';
include_once dirname(__FILE__) . '/../../siteRoutes/SiteRouteUtils.php';
if (!function_exists('haxcmsResolveSessionBearerToken')) {
    function haxcmsResolveSessionBearerToken()
    {
        if (
            !isset($GLOBALS['HAXCMS']) ||
            !is_object($GLOBALS['HAXCMS']) ||
            !method_exists($GLOBALS['HAXCMS'], 'getBearerTokenFromRequest')
        ) {
            return '';
        }
        $bearer = $GLOBALS['HAXCMS']->getBearerTokenFromRequest();
        if (!is_string($bearer)) {
            return '';
        }
        return trim($bearer);
    }
}
if (!function_exists('haxcmsResolveBearerSessionContext')) {
    function haxcmsResolveBearerSessionContext($bearer = '')
    {
        if (!is_string($bearer) || trim($bearer) === '') {
            return null;
        }
        $decoded = $GLOBALS['HAXCMS']->decodeJWT($bearer);
        if (
            $decoded === FALSE ||
            !isset($decoded->id) ||
            !isset($decoded->user) ||
            $decoded->user === '' ||
            $decoded->id != $GLOBALS['HAXCMS']->getRequestToken('user') ||
            !$GLOBALS['HAXCMS']->validateUser($decoded->user)
        ) {
            return null;
        }
        return array(
            'jwt' => $bearer,
            'user' => $GLOBALS['HAXCMS']->generateMachineName($decoded->user),
        );
    }
}
if (!function_exists('haxcmsResolveRefreshSessionContext')) {
    function haxcmsResolveRefreshSessionContext()
    {
        if (
            !isset($GLOBALS['HAXCMS']) ||
            !is_object($GLOBALS['HAXCMS']) ||
            !method_exists($GLOBALS['HAXCMS'], 'validateRefreshToken')
        ) {
            return null;
        }
        $refreshTokenDecoded = $GLOBALS['HAXCMS']->validateRefreshToken(FALSE);
        if (
            !$refreshTokenDecoded ||
            !isset($refreshTokenDecoded->user) ||
            $refreshTokenDecoded->user === '' ||
            !$GLOBALS['HAXCMS']->validateUser($refreshTokenDecoded->user)
        ) {
            return null;
        }
        // Security (H1 rotation): reject a revoked/stolen refresh family before
        // minting an access token. validateRefreshSession accepts legacy tokens
        // (no family/jti) so deploys don't log users out during upgrade.
        $sessionOk = $GLOBALS['HAXCMS']->validateRefreshSession(
            $refreshTokenDecoded->user,
            isset($refreshTokenDecoded->family) ? $refreshTokenDecoded->family : null,
            isset($refreshTokenDecoded->jti) ? $refreshTokenDecoded->jti : null
        );
        if (!$sessionOk) {
            if (isset($refreshTokenDecoded->user)) {
                $GLOBALS['HAXCMS']->revokeRefreshSession($refreshTokenDecoded->user);
            }
            $GLOBALS['HAXCMS']->setRefreshTokenCookie('', 1);
            return null;
        }
        // Rotate the refresh cookie on recovery so a stolen cookie is bounded;
        // fall back to a plain access token if rotation fails.
        $rotated = $GLOBALS['HAXCMS']->rotateRefreshTokenAndCookie($refreshTokenDecoded);
        $accessJwt = ($rotated !== null) ? $rotated : $GLOBALS['HAXCMS']->getJWT($refreshTokenDecoded->user);
        return array(
            'jwt' => $accessJwt,
            'user' => $GLOBALS['HAXCMS']->generateMachineName($refreshTokenDecoded->user),
        );
    }
}
if (!function_exists('haxcmsValidateSessionIAMAuthorization')) {
    function haxcmsValidateSessionIAMAuthorization()
    {
        if (
            !isset($GLOBALS['HAXCMS']) ||
            !is_object($GLOBALS['HAXCMS']) ||
            !method_exists($GLOBALS['HAXCMS'], 'validateIAMRouteAuthorization')
        ) {
            return array('allowed' => true, 'status' => 200, 'message' => '');
        }
        $authorization = $GLOBALS['HAXCMS']->validateIAMRouteAuthorization(TRUE);
        if ($authorization === false) {
            return array('allowed' => false, 'status' => 403, 'message' => 'Access denied');
        }
        if (is_array($authorization) && isset($authorization['allowed']) && !$authorization['allowed']) {
            return array(
                'allowed' => false,
                'status' => isset($authorization['status']) ? intval($authorization['status']) : 403,
                'message' => isset($authorization['message']) && $authorization['message'] != ''
                    ? $authorization['message']
                    : 'Access denied',
            );
        }
        return array('allowed' => true, 'status' => 200, 'message' => '');
    }
}
return function ($context) {
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/system/api';
    $operations = new Operations();
    $operations->params = array();
    $operations->rawParams = array();
    if (is_array($context->body)) {
        $operations->params = $context->body;
        $operations->rawParams = $context->body;
    }
    unset($operations->params['jwt']);
    unset($operations->params['user_token']);
    unset($operations->params['site_token']);
    unset($operations->rawParams['jwt']);
    unset($operations->rawParams['user_token']);
    unset($operations->rawParams['site_token']);
    $route = $context->routeSuffix;
    $response = null;
    if ($route === 'v1/session/login') {
        $response = $operations->login();
        // If login returns a flat {status, jwt} envelope, emit it directly
        if (is_array($response) && isset($response['status']) && isset($response['jwt']) && !isset($response['__failed'])) {
            header('Content-Type: application/json');
            print json_encode($response);
            exit;
        }
    }
    else if ($route === 'v1/session') {
        $requestedJWT = haxcmsResolveSessionBearerToken();
        if ($requestedJWT === '') {
            SiteRouteUtils::sendFormattedResponse(
                array(
                    'status' => 401,
                    'authenticated' => false,
                    'reason' => 'missing_jwt',
                    'message' => 'Authorization bearer token is required',
                ),
                array(
                    'statusCode' => 401,
                    'allowedFormats' => array('json'),
                    'defaultFormat' => 'json',
                    'envelope' => false,
                ),
                $context->routeSuffix,
                $apiBasePath
            );
            return;
        }
        $sessionContext = haxcmsResolveBearerSessionContext($requestedJWT);
        if ($sessionContext === null) {
            SiteRouteUtils::sendFormattedResponse(
                array(
                    'status' => 401,
                    'authenticated' => false,
                    'reason' => 'invalid_jwt',
                    'message' => 'Authentication failed',
                ),
                array(
                    'statusCode' => 401,
                    'allowedFormats' => array('json'),
                    'defaultFormat' => 'json',
                    'envelope' => false,
                ),
                $context->routeSuffix,
                $apiBasePath
            );
            return;
        }
        $authorization = haxcmsValidateSessionIAMAuthorization();
        if (!$authorization['allowed']) {
            SiteRouteUtils::sendFormattedResponse(
                array(
                    'status' => $authorization['status'],
                    'authenticated' => false,
                    'reason' => 'not_authorized',
                    'message' => $authorization['message'],
                ),
                array(
                    'statusCode' => $authorization['status'],
                    'allowedFormats' => array('json'),
                    'defaultFormat' => 'json',
                    'envelope' => false,
                ),
                $context->routeSuffix,
                $apiBasePath
            );
            return;
        }
        SiteRouteUtils::sendFormattedResponse(
            array(
                'status' => 200,
                'authenticated' => true,
                'jwt' => $sessionContext['jwt'],
                'user' => $sessionContext['user'],
            ),
            array(
                'statusCode' => 200,
                'allowedFormats' => array('json'),
                'defaultFormat' => 'json',
                'envelope' => false,
            ),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }
    else if ($route === 'v1/session/logout') {
        $response = $operations->logout();
    }
    else if ($route === 'v1/session/refresh') {
        $response = $operations->refreshAccessToken();
        // If refresh returns a flat {status, jwt} envelope, emit it directly
        // (same bypass as login — sendFormattedResponse would otherwise
        // double-wrap to {status, data:{status, jwt}} breaking the frontend's
        // response.jwt extraction). Fixes finding 4.7 / NEW-10.
        if (is_array($response) && isset($response['status']) && isset($response['jwt']) && !isset($response['__failed'])) {
            header('Content-Type: application/json');
            print json_encode($response);
            exit;
        }
    }
    else if ($route === 'v1/session/connection-settings') {
        $response = $operations->connectionSettings();
        // connectionSettings returns __noencode with JS content; emit it directly
        if (is_array($response) && isset($response['__noencode'])) {
            $contentType = isset($response['__noencode']['contentType']) ? $response['__noencode']['contentType'] : 'application/javascript';
            header('Content-Type: ' . $contentType);
            print $response['__noencode']['message'];
            exit;
        }
    }
    else if ($route === 'v1/session/connection-test') {
        // Security (Phase 3 Bearer-only): JWT must arrive via the Authorization
        // Bearer header, never request params/body. The body jwt/token fallback
        // is removed so HAXiam and other clients use appSettings.jwt as a Bearer
        // source instead of posting credentials in the body.
        $requestedJWT = haxcmsResolveSessionBearerToken();
        $sessionContext = null;
        $refreshed = false;
        if ($requestedJWT !== '') {
            $sessionContext = haxcmsResolveBearerSessionContext($requestedJWT);
        }
        if ($sessionContext === null) {
            $sessionContext = haxcmsResolveRefreshSessionContext();
            $refreshed = $sessionContext !== null;
        }
        if ($sessionContext === null) {
            $GLOBALS['HAXCMS']->setRefreshTokenCookie('', 1);
            SiteRouteUtils::sendFormattedResponse(
                array(
                    'status' => 401,
                    'authenticated' => false,
                    'reason' => 'invalid_session',
                    'message' => 'Authentication failed',
                ),
                array(
                    'statusCode' => 401,
                    'allowedFormats' => array('json'),
                    'defaultFormat' => 'json',
                    'envelope' => false,
                ),
                $context->routeSuffix,
                $apiBasePath
            );
            return;
        }
        $authorization = haxcmsValidateSessionIAMAuthorization();
        if (!$authorization['allowed']) {
            SiteRouteUtils::sendFormattedResponse(
                array(
                    'status' => $authorization['status'],
                    'authenticated' => false,
                    'reason' => 'not_authorized',
                    'message' => $authorization['message'],
                ),
                array(
                    'statusCode' => $authorization['status'],
                    'allowedFormats' => array('json'),
                    'defaultFormat' => 'json',
                    'envelope' => false,
                ),
                $context->routeSuffix,
                $apiBasePath
            );
            return;
        }
        SiteRouteUtils::sendFormattedResponse(
            array(
                'status' => 200,
                'authenticated' => true,
                'jwt' => $sessionContext['jwt'],
                'refreshed' => $refreshed,
                'user' => $sessionContext['user'],
            ),
            array(
                'statusCode' => 200,
                'allowedFormats' => array('json'),
                'defaultFormat' => 'json',
                'envelope' => false,
            ),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }
    else if ($route === 'v1/session/user') {
        $tokenUser = $GLOBALS['HAXCMS']->getRequestTokenUserName();
        $userToken = $GLOBALS['HAXCMS']->getRequestToken($tokenUser);
        $operations->params['user_token'] = $userToken;
        $operations->rawParams['user_token'] = $userToken;
        $response = $operations->getUserData();
    }
    else {
        SiteRouteUtils::sendFormattedResponse(
            array('message' => 'Unknown session route'),
            array('statusCode' => 404, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }
    if (is_array($response) && isset($response['__failed'])) {
        SiteRouteUtils::sendFormattedResponse(
            array('message' => isset($response['__failed']['message']) ? $response['__failed']['message'] : 'Request failed'),
            array('statusCode' => isset($response['__failed']['status']) ? $response['__failed']['status'] : 500, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }
    if (is_array($response) && isset($response['__noencode'])) {
        $response = array('status' => $response['__noencode']['status'], 'data' => $response['__noencode']['message']);
    }
    if (!is_array($response) || !isset($response['status'])) {
        $response = array('status' => 200, 'data' => $response);
    }
    SiteRouteUtils::sendFormattedResponse(
        $response,
        array('allowedFormats' => array('json'), 'defaultFormat' => 'json'),
        $context->routeSuffix,
        $apiBasePath
    );
};
