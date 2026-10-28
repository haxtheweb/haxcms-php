<?php
/**
 * Commit op codes (haxtheweb/issues#3191). Mirrors
 * haxcms-nodejs src/lib/commitContext.js; keep the two in step.
 *
 * Every commit HAXcms makes is prefixed with who made it and which API
 * operation produced it, so a site's git history becomes a replayable
 * transaction log ("recipe") and the revision history UI can explain what
 * changed a page:
 *
 *   [<actor>:<op>[:<txn>]] <message>
 *
 *   actor  H human | A agent | S system (no request)
 *   op     OpenAPI operationId (site-spec.yaml / system-spec.yaml) or a
 *          reserved op (siteCreate, direct, system, upgradeManagedFiles);
 *          letters and digits only
 *   txn    4 lowercase letters/digits, shared by every commit one request makes
 *
 * Anything that does not match this format (commits made by hand, by other
 * tools, and every commit from before this change) parses as actor "X"
 * (external). HAX never writes X.
 *
 * The actor is self-reported (X-HAX-Actor header, or HAX_ACTOR for CLI use).
 * It is for transparency only and must never be used to decide permissions.
 *
 * No version information ever goes into a commit message.
 *
 * PHP serves one request per process, so the request context is static.
 */
class CommitContext
{
    const COMMIT_MESSAGE_MAX_LENGTH = 200;
    const OP_MAX_LENGTH = 48;
    const TXN_LENGTH = 4;
    const PREFIX_PATTERN = '/^\[([HAS]):([A-Za-z][A-Za-z0-9]{0,47})(?::([a-z0-9]{4}))?\] (.*)$/s';
    const TXN_ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    private static $context = null;
    private static $operationIdIndex = null;

    /**
     * Keep letters and digits only.
     */
    public static function alphanumeric($value)
    {
        if (!is_string($value)) {
            return '';
        }
        return preg_replace('/[^A-Za-z0-9]/', '', $value);
    }

    /**
     * Normalize a self-reported actor value (header or env) to H or A, or ''
     * when unrecognized.
     */
    public static function normalizeActor($value)
    {
        $key = strtolower(self::alphanumeric($value));
        if ($key === 'human' || $key === 'h') {
            return 'H';
        }
        if ($key === 'agent' || $key === 'a') {
            return 'A';
        }
        return '';
    }

    public static function normalizeOp($value)
    {
        $op = substr(self::alphanumeric($value), 0, self::OP_MAX_LENGTH);
        if ($op !== '' && preg_match('/^[A-Za-z]/', $op)) {
            return $op;
        }
        return '';
    }

    public static function normalizeTxn($value)
    {
        $txn = strtolower(self::alphanumeric($value));
        if (strlen($txn) === self::TXN_LENGTH) {
            return $txn;
        }
        return '';
    }

    /**
     * Collapse whitespace and trim the human-readable part of the message.
     */
    public static function normalizeSummary($value)
    {
        return trim(preg_replace('/\s+/', ' ', (string) $value));
    }

    /**
     * Same conservative sanitizer haxcms-nodejs applies before every commit:
     * drop control characters, quotes and backslashes; cap at 200 chars.
     */
    public static function sanitizeMessage($msg)
    {
        // whitespace (tabs, newlines) becomes one space first so words never
        // get glued together when control characters are dropped
        $safe = preg_replace('/\s+/', ' ', (string) $msg);
        $safe = preg_replace('/[\x00-\x1f\x7f\'"\\\\]/', '', $safe);
        $safe = substr(trim($safe), 0, self::COMMIT_MESSAGE_MAX_LENGTH);
        if ($safe === '') {
            $safe = 'Committed changes';
        }
        return $safe;
    }

    public static function createTxn()
    {
        $bytes = random_bytes(self::TXN_LENGTH);
        $alphabet = self::TXN_ALPHABET;
        $txn = '';
        for ($i = 0; $i < self::TXN_LENGTH; $i++) {
            $txn .= $alphabet[ord($bytes[$i]) % strlen($alphabet)];
        }
        return $txn;
    }

    /**
     * Set the context for this request. No X-HAX-Actor header means a human.
     */
    public static function startRequest($actorHeader, $op)
    {
        $actor = self::normalizeActor(is_string($actorHeader) ? $actorHeader : '');
        self::$context = array(
            'actor' => $actor !== '' ? $actor : 'H',
            'op' => self::normalizeOp($op),
            'txn' => self::createTxn(),
        );
        return self::$context;
    }

    public static function set($context)
    {
        self::$context = $context;
    }

    public static function get()
    {
        return self::$context;
    }

    public static function clear()
    {
        self::$context = null;
    }

    /**
     * Context for commits made outside any request (CLI scripts, maintenance).
     */
    public static function getFallback()
    {
        $actor = self::normalizeActor((string) getenv('HAX_ACTOR'));
        return array(
            'actor' => $actor !== '' ? $actor : 'S',
            'op' => '',
            'txn' => '',
        );
    }

    /**
     * Format the op-code prefix, e.g. "[A:updateItem:x9r1]".
     */
    public static function formatPrefix($context)
    {
        $ctx = is_array($context) ? $context : array();
        $actor = isset($ctx['actor']) && preg_match('/^[HAS]$/', (string) $ctx['actor']) ? $ctx['actor'] : 'S';
        $op = self::normalizeOp(isset($ctx['op']) ? (string) $ctx['op'] : '');
        if ($op === '') {
            $op = 'system';
        }
        $txn = self::normalizeTxn(isset($ctx['txn']) ? (string) $ctx['txn'] : '');
        return '[' . $actor . ':' . $op . ($txn !== '' ? ':' . $txn : '') . ']';
    }

    /**
     * Sanitize and prefix a message. The prefix is never cut by the length
     * cap. $opOverride replaces the context's op for this one commit. A
     * message that already carries a valid prefix is not prefixed again.
     */
    public static function formatCommitMessage($msg, $opOverride = '', $context = null)
    {
        $message = self::normalizeSummary(self::sanitizeMessage($msg));
        if (preg_match(self::PREFIX_PATTERN, $message)) {
            return substr($message, 0, self::COMMIT_MESSAGE_MAX_LENGTH);
        }
        $base = $context;
        if (!is_array($base)) {
            $base = self::$context !== null ? self::$context : self::getFallback();
        }
        $op = self::normalizeOp((string) $opOverride);
        $prefix = self::formatPrefix(array(
            'actor' => isset($base['actor']) ? $base['actor'] : 'S',
            'op' => $op !== '' ? $op : (isset($base['op']) ? $base['op'] : ''),
            'txn' => isset($base['txn']) ? $base['txn'] : '',
        ));
        $room = max(self::COMMIT_MESSAGE_MAX_LENGTH - strlen($prefix) - 1, 0);
        return $prefix . ' ' . trim(substr($message, 0, $room));
    }

    /**
     * Parse a commit subject. A subject without a valid prefix comes back as
     * actor "X" with the whole subject as its summary (older HAXcms-nodejs
     * stored subjects wrapped in literal single quotes; those are dropped).
     */
    public static function parse($subject)
    {
        $raw = (string) $subject;
        $len = strlen($raw);
        $unquoted = ($len > 1 && $raw[0] === "'" && $raw[$len - 1] === "'")
            ? substr($raw, 1, $len - 2)
            : $raw;
        if (!preg_match(self::PREFIX_PATTERN, $unquoted, $match)) {
            return array(
                'actor' => 'X',
                'op' => '',
                'txn' => '',
                'summary' => self::normalizeSummary($unquoted),
            );
        }
        return array(
            'actor' => $match[1],
            'op' => $match[2],
            'txn' => isset($match[3]) ? $match[3] : '',
            'summary' => self::normalizeSummary($match[4]),
        );
    }

    /**
     * "<method> <route key>" => operationId, read from the OpenAPI specs.
     *   site   "patch v1/site/outline" <- /x/api/v1/site/outline
     *   system "post v1/sites"         <- /system/api/v1/sites (PHP keeps v1/)
     */
    public static function buildOperationIdIndex($specFiles = null)
    {
        if (!is_array($specFiles)) {
            $specFiles = array(
                'site' => array(dirname(__FILE__) . '/siteRoutes/openapi/site-spec.yaml', '/x/api/'),
                'system' => array(dirname(__FILE__) . '/systemRoutes/openapi/system-spec.yaml', '/system/api/'),
            );
        }
        $index = array('site' => array(), 'system' => array());
        foreach ($specFiles as $scope => $source) {
            try {
                $spec = \Symfony\Component\Yaml\Yaml::parseFile($source[0]);
            } catch (\Throwable $e) {
                $spec = null;
            }
            if (!is_array($spec) || !isset($spec['paths']) || !is_array($spec['paths'])) {
                continue;
            }
            foreach ($spec['paths'] as $specPath => $pathItem) {
                if (strpos($specPath, $source[1]) !== 0 || !is_array($pathItem)) {
                    continue;
                }
                $routeKey = substr($specPath, strlen($source[1]));
                $routeKey = preg_replace('/\{([^}]+)\}/', ':$1', $routeKey);
                $routeKey = rtrim($routeKey, '/');
                foreach (array('get', 'post', 'put', 'patch', 'delete') as $method) {
                    if (isset($pathItem[$method]['operationId']) && is_string($pathItem[$method]['operationId'])) {
                        $index[$scope][$method . ' ' . $routeKey] = $pathItem[$method]['operationId'];
                    }
                }
            }
        }
        return $index;
    }

    /**
     * operationId for a registered route, falling back to the method plus the
     * route key's literal segments when the spec has no entry.
     */
    public static function getOperationId($scope, $method, $routeKey)
    {
        if (self::$operationIdIndex === null) {
            self::$operationIdIndex = self::buildOperationIdIndex();
        }
        $table = isset(self::$operationIdIndex[$scope]) ? self::$operationIdIndex[$scope] : array();
        $key = strtolower((string) $method) . ' ' . (string) $routeKey;
        if (isset($table[$key])) {
            $op = self::normalizeOp($table[$key]);
            return $op !== '' ? $op : 'system';
        }
        $fallback = '';
        foreach (explode('/', (string) $routeKey) as $part) {
            if ($part === '' || $part[0] === ':') {
                continue;
            }
            $fallback .= ucfirst(self::alphanumeric($part));
        }
        $op = self::normalizeOp(strtolower((string) $method) . $fallback);
        return $op !== '' ? $op : 'system';
    }
}
