<?php
/**
 * DRAFT (plugin platform, haxtheweb/issues#3356; mirrors haxcms-nodejs#138 src/lib/plugins/pluginRoutes.js).
 *
 * Plugin operations (spec/05): each plugin's config/openapi.yaml paths live under
 *   site scope    x/api/v1/plugins/<id>/...    (and <sitesDirectory>/<site>/x/api/v1/plugins/<id>/...)
 *   system scope  system/api/v1/plugins/<id>/...
 * plus GET system/api/v1/plugins (the plugin list) and GET .../plugins/contributions (spec/07). The engine enforces
 * each operation's declared security from the credentials resolved here; routes never decide auth themselves.
 * and GET <basePath>.well-known/<name> for a public system GET a plugin marks with x-hax-well-known (spec/05). A
 * request for a file that does not exist already reaches index.php (the .htaccess catch-all, nginx try_files), which
 * hands reserved names here; a real file of that name still wins, as in haxcms-nodejs.
 */
require_once __DIR__ . '/HAXCMSPluginSystem.php';

class HAXCMSPluginRoutes
{
    /** @return bool true when the request was a plugin route and has been answered */
    public static function dispatchSite($context, $site)
    {
        $suffix = (string) $context->routeSuffix;
        if (!HAXCMSPluginSystem::enabled() || strpos($suffix, 'v1/plugins/') !== 0) {
            return false;
        }
        $rest = substr($suffix, strlen('v1/plugins/'));
        if ($rest === 'contributions' && $context->method === 'GET') {
            self::contributions('site', $site, $context);
            return true;
        }
        self::operation('site', $rest, $site, $context);
        return true;
    }

    /** @return bool */
    public static function dispatchSystem($context)
    {
        $suffix = (string) $context->routeSuffix;
        if (!HAXCMSPluginSystem::enabled() || ($suffix !== 'v1/plugins' && strpos($suffix, 'v1/plugins/') !== 0)) {
            return false;
        }
        // system-scope work runs on the system engine, never on some site's (spec/13 section 8b)
        if ($suffix === 'v1/plugins') {
            $auth = self::resolveAuth($context, null);
            if (!$auth['jwt']) {
                self::send(401, ['status' => 401, 'data' => ['message' => 'access denied']]);
                return true;
            }
            $h = HAXCMSPluginSystem::systemHost(['user' => HAXCMSPluginSystem::userRecord($auth['userName'])]);
            self::send(200, ['status' => 200, 'data' => $h ? $h->status() : []]);
            return true;
        }
        $rest = substr($suffix, strlen('v1/plugins/'));
        if ($rest === 'contributions' && $context->method === 'GET') {
            self::contributions('dashboard', null, $context);
            return true;
        }
        self::operation('system', $rest, null, $context);
        return true;
    }

    /** Reserved names a plugin may serve at /.well-known/ (spec/05); anything else is never a plugin's. */
    const WELL_KNOWN = ['oauth-authorization-server', 'oauth-protected-resource', 'openid-configuration'];

    /**
     * GET <basePath>.well-known/<name>: the first active plugin on the system engine whose public GET declares
     * x-hax-well-known: <name> answers it, with no credentials. Same rule as haxcms-nodejs pluginRoutes.js. $opts go to
     * systemHost (root, for tests).
     * @return bool true when a plugin answered
     */
    public static function dispatchWellKnown($requestPath, $basePath, $method, array $opts = [])
    {
        if (!HAXCMSPluginSystem::enabled() || strtoupper((string) $method) !== 'GET') {
            return false;
        }
        $prefix = rtrim((string) $basePath, '/') . '/.well-known/';
        if (strpos((string) $requestPath, $prefix) !== 0) {
            return false;
        }
        $name = substr((string) $requestPath, strlen($prefix));
        if (!in_array($name, self::WELL_KNOWN, true)) {
            return false;
        }
        try {
            $h = HAXCMSPluginSystem::systemHost(['user' => null] + $opts);
            if (!$h) {
                return false;
            }
            foreach ($h->plugins as $p) {
                if ($p->status !== 'active' || !is_file("{$p->dir}/config/openapi.yaml")) {
                    continue;
                }
                $api = HAXCMSPluginEngine::readAny("{$p->dir}/config/openapi.yaml");
                foreach ((is_array($api) ? ($api['paths'] ?? []) : []) as $verbs) {
                    $op = is_array($verbs) ? ($verbs['get'] ?? null) : null;
                    if (is_array($op) && ($op['x-hax-well-known'] ?? null) === $name && isset($op['operationId'])) {
                        $r = $h->call($op['operationId'], ['params' => [], 'query' => $_GET, 'body' => [], 'headers' => self::requestHeaders()], 'none');
                        self::send($r['status'] ?? 200, array_key_exists('body', $r) ? $r['body'] : null);
                        return true;
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('HAXcms plugin well-known: ' . $e->getMessage());
            self::send(500, ['status' => 500, 'data' => ['message' => 'server error']]);
            return true;
        }
        return false;
    }

    /**
     * The credentials on this request: ['userName', 'jwt', 'userToken', 'siteToken'] (booleans are "present and
     * valid"). The CLI has every credential, as in haxcms-nodejs.
     */
    public static function resolveAuth($context, $site)
    {
        $haxcms = $GLOBALS['HAXCMS'] ?? null;
        $cli = $haxcms && method_exists($haxcms, 'isCLI') && $haxcms->isCLI();
        $userName = '';
        if ($cli) {
            $userName = 'admin';
        } elseif ($haxcms && !is_null(SiteRouteUtils::getBearerTokenFromRequest())) {
            $userName = (string) $haxcms->getBearerTokenUserName();
        }
        $userToken = (string) $context->getHeader('X-HAXCMS-User-Token');
        $siteToken = (string) $context->getHeader('X-HAXCMS-Site-Token');
        $siteName = $site && isset($site->manifest->metadata->site->name) ? (string) $site->manifest->metadata->site->name : '';
        return [
            'userName' => $userName,
            'jwt' => $cli || $userName !== '',
            'userToken' => $cli || ($userToken !== '' && $userName !== '' && $haxcms->validateRequestToken($userToken, $userName)),
            'siteToken' => $cli || ($siteToken !== '' && $userName !== '' && $siteName !== '' && $haxcms->validateSiteToken($siteName, $siteToken)),
        ];
    }

    public static function credentialsOf(array $auth)
    {
        return $auth['jwt'] && ($auth['userToken'] || $auth['siteToken']) ? 'both' : ($auth['jwt'] ? 'jwt' : 'none');
    }

    /** Finds the operation whose path template matches; scope must match too. */
    public static function matchOperation($p, $method, $rest, $scope)
    {
        foreach ($p->ops as $operationId => $op) {
            if ($op['verb'] !== strtolower($method) || $op['scope'] !== $scope) {
                continue;
            }
            $names = [];
            $re = preg_replace_callback('/\\\\\{([^}]+)\\\\\}/', function ($m) use (&$names) {
                $names[] = $m[1];
                return '([^/]+)';
            }, preg_quote($op['path'], '#'));
            if (preg_match('#^' . $re . '$#', $rest, $mm)) {
                $params = [];
                foreach ($names as $i => $n) {
                    $params[$n] = rawurldecode($mm[$i + 1]);
                }
                return ['operationId' => $operationId, 'params' => $params];
            }
        }
        return null;
    }

    private static function requestBody($context)
    {
        $raw = (string) file_get_contents('php://input');
        $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $context->getHeader('Content-Type') ?? ''));
        // plugin operations may declare form-encoded bodies (OAuth token and revocation endpoints, RFC 6749)
        if (strpos($type, 'application/x-www-form-urlencoded') !== false) {
            parse_str($raw, $form);
            return $form;
        }
        $json = json_decode($raw, true);
        return is_array($json) ? $json : [];
    }

    private static function requestHeaders()
    {
        $out = [];
        foreach (function_exists('getallheaders') ? (getallheaders() ?: []) : [] as $k => $v) {
            $out[strtolower($k)] = $v;
        }
        foreach ($_SERVER as $k => $v) {
            if (strpos($k, 'HTTP_') === 0) {
                $out[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
            }
        }
        return $out;
    }

    private static function operation($scope, $rest, $site, $context)
    {
        try {
            $auth = self::resolveAuth($context, $site);
            $user = ['user' => HAXCMSPluginSystem::userRecord($auth['userName'])];
            $h = $scope === 'system' ? HAXCMSPluginSystem::systemHost($user) : HAXCMSPluginSystem::host($site, $user);
            $slash = strpos($rest, '/');
            $id = $slash === false ? $rest : substr($rest, 0, $slash);
            $path = '/' . ltrim($slash === false ? '' : substr($rest, $slash + 1), '/');
            $p = $h ? $h->get($id) : null;
            $match = $p && $p->status === 'active' ? self::matchOperation($p, $context->method, $path, $scope) : null;
            if (!$match) {
                self::send(404, ['status' => 404, 'data' => ['message' => 'not found']]);
                return;
            }
            $r = $h->call($match['operationId'], [
                'params' => $match['params'],
                'query' => $_GET,
                'body' => self::requestBody($context),
                'headers' => self::requestHeaders(),
            ], self::credentialsOf($auth));
            self::send($r['status'] ?? 200, array_key_exists('body', $r) ? $r['body'] : null, $r['headers'] ?? []);
        } catch (\Throwable $e) {
            error_log('HAXcms plugin route: ' . $e->getMessage());
            self::send(500, ['status' => 500, 'data' => ['message' => 'server error']]);
        }
    }

    // GET .../plugins/contributions (spec/07): declarative UI from enabled plugins for one surface, plus the
    // advertised core contracts by contract and result name
    private static function contributions($surface, $site, $context)
    {
        $auth = self::resolveAuth($context, $site);
        if (!$auth['jwt'] || ($surface === 'site' && !$site)) {
            self::send(401, ['status' => 401, 'data' => ['message' => 'access denied']]);
            return;
        }
        $user = ['user' => HAXCMSPluginSystem::userRecord($auth['userName'])];
        $h = $surface === 'site' ? HAXCMSPluginSystem::host($site, $user) : HAXCMSPluginSystem::systemHost($user);
        if (!$h) {
            self::send(200, ['status' => 200, 'data' => ['contributions' => [], 'contracts' => new stdClass()]]);
            return;
        }
        $items = [];
        foreach ($h->plugins as $p) {
            if ($p->status !== 'active' || !is_dir("{$p->dir}/registries/dashboard")) {
                continue;
            }
            $files = array_values(array_filter(scandir("{$p->dir}/registries/dashboard") ?: [], function ($f) {
                return (bool) preg_match('/\.(ya?ml|json)$/', $f);
            }));
            sort($files, SORT_STRING);
            foreach ($files as $f) {
                try {
                    $c = HAXCMSPluginEngine::readAny("{$p->dir}/registries/dashboard/$f");
                } catch (\Throwable $e) {
                    continue;
                }
                if (is_array($c) && ($c['surface'] ?? 'dashboard') === $surface) {
                    $items[] = ['plugin' => $p->id] + $c;
                }
            }
        }
        usort($items, function ($a, $b) {
            return ($a['order'] ?? 0) <=> ($b['order'] ?? 0);
        });
        self::send(200, ['status' => 200, 'data' => ['contributions' => $items, 'contracts' => (object) $h->advertisedContracts()]]);
    }

    private static function send($status, $body, array $headers = [])
    {
        if (!headers_sent()) {
            http_response_code((int) $status);
            header('Content-Type: application/json');
            foreach ($headers as $k => $v) {
                // a plugin names its own response headers; never let one carry a line break
                header(preg_replace('/[\r\n:]/', '', (string) $k) . ': ' . preg_replace('/[\r\n]/', '', (string) $v));
            }
        }
        // the same code / params next to the English message as every other API answer (haxtheweb/issues#3157)
        if (is_array($body)) {
            include_once dirname(__DIR__) . '/HAXCMSApiMessageCodes.php';
            $body = HAXCMSApiMessageCodes::annotate($body, (int) $status);
        }
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
