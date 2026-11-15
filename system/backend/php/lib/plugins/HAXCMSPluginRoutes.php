<?php
/**
 * DRAFT (plugin platform, haxtheweb/issues#3356; mirrors haxcms-nodejs#138 src/lib/plugins/pluginRoutes.js).
 *
 * Plugin operations (spec/05): each plugin's config/openapi.yaml paths live under
 *   site scope    x/api/v1/plugins/<id>/...    (and <sitesDirectory>/<site>/x/api/v1/plugins/<id>/...)
 *   system scope  system/api/v1/plugins/<id>/...
 * plus GET system/api/v1/plugins (the plugin list) and GET .../plugins/contributions (spec/07). The engine enforces
 * each operation's declared security from the credentials resolved here; routes never decide auth themselves.
 *
 * Not served yet (parity gap with haxcms-nodejs#138): /.well-known/<name> for x-hax-well-known operations, which
 * needs a rewrite rule in front of the static file server.
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
        // TODO(draft, same as haxcms-nodejs#138): one engine per site, so system-scope operations borrow a site's
        // engine (the first site); a system-level engine is an open item (spec/13 section 9)
        $site = self::anySite();
        if ($suffix === 'v1/plugins') {
            $auth = self::resolveAuth($context, $site);
            if (!$auth['jwt']) {
                self::send(401, ['status' => 401, 'data' => ['message' => 'access denied']]);
                return true;
            }
            $h = $site ? HAXCMSPluginSystem::host($site, ['user' => HAXCMSPluginSystem::userRecord($auth['userName'])]) : null;
            self::send(200, ['status' => 200, 'data' => $h ? $h->status() : []]);
            return true;
        }
        $rest = substr($suffix, strlen('v1/plugins/'));
        if ($rest === 'contributions' && $context->method === 'GET') {
            self::contributions('dashboard', $site, $context);
            return true;
        }
        if (!$site) {
            self::send(404, ['status' => 404, 'data' => ['message' => 'Unable to resolve site context for plugin route']]);
            return true;
        }
        self::operation('system', $rest, $site, $context);
        return true;
    }

    private static function anySite()
    {
        if (!isset($GLOBALS['HAXCMS']) || !defined('HAXCMS_ROOT')) {
            return null;
        }
        $dir = HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory;
        $names = [];
        foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $n) {
            if ($n[0] !== '.' && is_file("$dir/$n/site.json")) {
                $names[] = $n;
            }
        }
        sort($names, SORT_STRING);
        return $names ? $GLOBALS['HAXCMS']->loadSite($names[0]) : null;
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
            $h = HAXCMSPluginSystem::host($site, ['user' => HAXCMSPluginSystem::userRecord($auth['userName'])]);
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
        $h = $site ? HAXCMSPluginSystem::host($site, ['user' => HAXCMSPluginSystem::userRecord($auth['userName'])]) : null;
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
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
