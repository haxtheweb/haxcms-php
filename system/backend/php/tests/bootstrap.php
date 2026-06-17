<?php
// Test bootstrap for siteRoutes v1 mutations and security layer
// Usage: cd /path/to/haxcms-php && php system/backend/php/tests/run.php

// Suppress notices during tests
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);

// Load the classes under test
$baseDir = dirname(__DIR__);
include_once $baseDir . '/lib/siteRoutes/SiteRouteUtils.php';
include_once $baseDir . '/lib/siteRoutes/SiteApiRequestContext.php';
include_once $baseDir . '/lib/siteRoutes/SiteApiSecurity.php';
include_once $baseDir . '/lib/siteRoutes/SiteApiRouter.php';

// Minimal JWT stub for testing
if (!class_exists('JWT')) {
    class JWT
    {
        public static function decode($jwt, $key = null, $verify = true)
        {
            $parts = explode('.', $jwt);
            if (count($parts) != 3) {
                throw new UnexpectedValueException('Wrong number of segments');
            }
            $body = self::urlsafeB64Decode($parts[1]);
            $obj = json_decode($body);
            if ($obj === null && $body !== 'null') {
                throw new DomainException('Null result with non-null input');
            }
            return $obj;
        }
        public static function urlsafeB64Decode($input)
        {
            $remainder = strlen($input) % 4;
            if ($remainder) {
                $padlen = 4 - $remainder;
                $input .= str_repeat('=', $padlen);
            }
            return base64_decode(strtr($input, '-_', '+/'));
        }
        public static function encode($payload, $key, $algo = 'HS256')
        {
            $header = array('typ' => 'JWT', 'alg' => $algo);
            $segments = array();
            $segments[] = self::urlsafeB64Encode(self::jsonEncode($header));
            $segments[] = self::urlsafeB64Encode(self::jsonEncode($payload));
            $signing_input = implode('.', $segments);
            $signature = hash_hmac('sha256', $signing_input, $key, true);
            $segments[] = self::urlsafeB64Encode($signature);
            return implode('.', $segments);
        }
        public static function jsonEncode($input)
        {
            $json = json_encode($input);
            if ($json === 'null' && $input !== null) {
                throw new DomainException('Null result with non-null input');
            }
            return $json;
        }
        public static function urlsafeB64Encode($input)
        {
            return str_replace('=', '', strtr(base64_encode($input), '+/', '-_'));
        }
    }
}

// Minimal HAXCMS stub for testing
class HAXCMSTestStub
{
    public $privateKey = 'test-secret-key';
    public $salt = 'test-salt';
    public $systemRequestBase = 'system/api';
    public $basePath = '/';
    public $sitesDirectory = '_sites';
    public $publishedDirectory = '_published';
    public $configDirectory = '_config';

    public function isCLI()
    {
        return false;
    }
    public function validateRequestToken($token = null, $value = '')
    {
        return $token !== null && $token === 'valid-site-token';
    }
    public function getRequestTokenUserName()
    {
        return 'testuser';
    }
    public function generateMachineName($name)
    {
        return strtolower(preg_replace('/[^a-z0-9_-]+/', '-', (string) $name));
    }
    public function decodeJWT($key)
    {
        try {
            return JWT::decode($key, $this->privateKey . $this->salt);
        }
        catch (Exception $e) {
            return false;
        }
    }
}

// Set up global HAXCMS stub
if (!isset($GLOBALS['HAXCMS']) || !is_object($GLOBALS['HAXCMS'])) {
    $GLOBALS['HAXCMS'] = new HAXCMSTestStub();
}

// Minimal HAXCMSSite stub for testing
class HAXCMSSiteTestStub
{
    public $manifest;
    public $name = 'testsite';
    public function __construct()
    {
        $this->manifest = new stdClass();
        $this->manifest->metadata = new stdClass();
        $this->manifest->metadata->site = new stdClass();
        $this->manifest->metadata->site->name = 'testsite';
    }
}

// Simple test harness
class SimpleTestRunner
{
    public $tests = 0;
    public $passed = 0;
    public $failed = 0;
    public $errors = array();

    public function assert($condition, $message = 'Assertion failed')
    {
        $this->tests++;
        if ($condition) {
            $this->passed++;
        } else {
            $this->failed++;
            $this->errors[] = $message;
        }
    }
    public function assertEquals($expected, $actual, $message = null)
    {
        $this->tests++;
        if ($expected === $actual) {
            $this->passed++;
        } else {
            $this->failed++;
            $msg = $message ? $message : "Expected " . var_export($expected, true) . " but got " . var_export($actual, true);
            $this->errors[] = $msg;
        }
    }
    public function report($name)
    {
        echo "\n=== $name ===\n";
        echo "Tests: $this->tests, Passed: $this->passed, Failed: $this->failed\n";
        if (count($this->errors) > 0) {
            foreach ($this->errors as $e) {
                echo "  FAIL: $e\n";
            }
        }
        return $this->failed === 0;
    }
}

// Helper to build a JWT
function buildTestJWT($user = 'testuser', $key = 'test-secret-key', $salt = 'test-salt')
{
    $payload = new stdClass();
    $payload->iat = time();
    $payload->exp = time() + 3600;
    $payload->id = 'user-id';
    $payload->user = $user;
    return JWT::encode($payload, $key . $salt);
}

// Helper to set server vars for a test
function setServerVars($vars = array())
{
    foreach ($vars as $k => $v) {
        $_SERVER[$k] = $v;
    }
}

function resetServerVars()
{
    $keep = array('HOME', 'PWD', 'SHELL', 'PATH', 'TERM', 'LANG', 'XDG_SESSION_TYPE', 'XDG_CURRENT_DESKTOP', 'SSH_CLIENT', 'SSH_CONNECTION', 'SSH_TTY', 'LOGNAME', 'USER', 'HOSTNAME', 'OLDPWD', 'DBUS_SESSION_BUS_ADDRESS', 'XDG_RUNTIME_DIR', 'GNOME_DESKTOP_SESSION_ID', 'DESKTOP_SESSION', 'DISPLAY', 'XAUTHORITY', 'GNOME_TERMINAL_SCREEN', 'GNOME_TERMINAL_SERVICE', 'VTE_VERSION', 'COLORTERM', 'LS_COLORS', 'LESSCLOSE', 'LESSOPEN', 'SHLVL', 'PWD', 'OLDPWD', 'GDMSESSION', 'SESSION_MANAGER', 'XDG_CONFIG_DIRS', 'XDG_DATA_DIRS', 'GTK_MODULES', 'GPG_AGENT_INFO', 'GNOME_KEYRING_CONTROL', 'GNOME_KEYRING_PID', 'XDG_SESSION_PATH', 'XDG_SEAT_PATH', 'DEFAULTS_PATH', 'IM_CONFIG_PHASE', 'QT_ACCESSIBILITY', 'QT_IM_MODULE', 'CLUTTER_IM_MODULE', 'TEXTDOMAIN', 'TEXTDOMAINDIR', 'XMODIFIERS', 'GTK_IM_MODULE', 'PWD', 'OLDPWD', 'LS_COLORS', 'LESSCLOSE', 'LESSOPEN', 'GNOME_DESKTOP_SESSION_ID', 'XDG_CURRENT_DESKTOP', 'XDG_SESSION_TYPE', 'XDG_SESSION_CLASS', 'XDG_SESSION_DESKTOP', 'XDG_SEAT', 'XDG_VTNR', 'SSH_AGENT_LAUNCHER', 'SSH_AGENT_LAUNCHER', 'SSH_AGENT_LAUNCHER', 'GPG_AGENT_LAUNCHER', 'GNOME_DESKTOP_SESSION_ID', 'XDG_MENU_PREFIX', 'GNOME_DESKTOP_SESSION_ID', 'GNOME_DESKTOP_SESSION_ID', 'GNOME_DESKTOP_SESSION_ID', 'GNOME_DESKTOP_SESSION_ID', 'GNOME_DESKTOP_SESSION_ID', 'GNOME_DESKTOP_SESSION_ID', 'GNOME_DESKTOP_SESSION_ID', 'GNOME_DESKTOP_SESSION_ID', 'GNOME_DESKTOP_SESSION_ID', 'GNOME_DESKTOP_SESSION_ID', 'GNOME_DESKTOP_SESSION_ID', 'GNOME_DESKTOP_SESSION_ID', 'GNOME_DESKTOP_SESSION_ID', 'GNOME_DESKTOP_SESSION_ID', 'GNOME_DESKTOP_SESSION_ID');
    foreach ($_SERVER as $k => $v) {
        if (!in_array($k, $keep, true)) {
            unset($_SERVER[$k]);
        }
    }
    $_GET = array();
    $_POST = array();
    $_FILES = array();
    $_COOKIE = array();
    $_REQUEST = array();
}
