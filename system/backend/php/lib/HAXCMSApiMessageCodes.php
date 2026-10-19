<?php
/**
 * Translatable API messages (haxtheweb/issues#3157).
 *
 * Responses keep their English `message` (or `error`) text. This adds a
 * stable `code` and any `params` next to it, so the editor and the hax CLI
 * can show the message in the user's language (locales/haxcms-api.<lang>.json
 * in @haxtheweb/haxcms-elements) and fall back to the English text.
 *
 * Codes come from the shared list in haxcms-api-codes.json (the same file the
 * Node backend and the front end use). A route can also set `code` and
 * `params` itself; those are never overwritten.
 */
class HAXCMSApiMessageCodes
{
    // longer text is never one of our messages; skip it rather than run regexes on it
    const MAX_MESSAGE_LENGTH = 1000;
    private static $rules = null;

    private static function rules()
    {
        if (self::$rules === null) {
            self::$rules = array();
            $raw = @file_get_contents(dirname(__FILE__) . '/haxcms-api-codes.json');
            $catalog = $raw === false ? null : json_decode($raw, true);
            if (is_array($catalog) && isset($catalog['codes']) && is_array($catalog['codes'])) {
                foreach ($catalog['codes'] as $entry) {
                    $patterns = array();
                    foreach ($entry['match'] as $source) {
                        $patterns[] = '~^(?:' . $source . ')[.!]?$~iu';
                    }
                    self::$rules[] = array(
                        'code' => $entry['code'],
                        'en' => $entry['en'],
                        'info' => isset($entry['kind']) && $entry['kind'] === 'info',
                        'patterns' => $patterns,
                    );
                }
            }
        }
        return self::$rules;
    }

    /**
     * Find the code for an English message.
     * @return array|null array('code' => ..., 'params' => array(...), 'info' => bool)
     */
    public static function match($message)
    {
        if (!is_string($message) || strlen($message) > self::MAX_MESSAGE_LENGTH) {
            return null;
        }
        $text = trim($message);
        if ($text === '') {
            return null;
        }
        foreach (self::rules() as $rule) {
            foreach ($rule['patterns'] as $pattern) {
                $found = array();
                if (@preg_match($pattern, $text, $found) === 1) {
                    $params = array();
                    foreach ($found as $name => $value) {
                        if (is_string($name) && is_string($value) && $value !== '') {
                            $params[$name] = $value;
                        }
                    }
                    return array('code' => $rule['code'], 'params' => $params, 'info' => $rule['info']);
                }
            }
        }
        return null;
    }

    private static function annotateHolder(&$holder, $status)
    {
        if (!is_array($holder) || array_key_exists('code', $holder) || self::isList($holder)) {
            return;
        }
        $text = null;
        if (isset($holder['message']) && is_string($holder['message'])) {
            $text = $holder['message'];
        }
        else if (isset($holder['error']) && is_string($holder['error'])) {
            $text = $holder['error'];
        }
        if ($text === null) {
            return;
        }
        $found = self::match($text);
        if ($found === null) {
            return;
        }
        // successful responses only get codes for messages meant for success
        $holderStatus = isset($holder['status']) && is_numeric($holder['status']) ? (int) $holder['status'] : $status;
        if ($holderStatus < 400 && !$found['info']) {
            return;
        }
        $holder['code'] = $found['code'];
        if (count($found['params']) > 0 && !array_key_exists('params', $holder)) {
            $holder['params'] = $found['params'];
        }
    }

    private static function isList($value)
    {
        return is_array($value) && count($value) > 0 && array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * Add `code` / `params` next to the message in a response payload.
     * Looks at the payload itself, `data` and `__failed`.
     */
    public static function annotate($payload, $status = 200)
    {
        if (!is_array($payload)) {
            return $payload;
        }
        $httpStatus = is_numeric($status) ? (int) $status : 200;
        self::annotateHolder($payload, $httpStatus);
        if (isset($payload['data']) && is_array($payload['data'])) {
            self::annotateHolder($payload['data'], $httpStatus);
        }
        if (isset($payload['__failed']) && is_array($payload['__failed'])) {
            self::annotateHolder($payload['__failed'], $httpStatus);
        }
        return $payload;
    }

    /**
     * English text for a code, with params filled in.
     */
    public static function text($code, $params = array())
    {
        foreach (self::rules() as $rule) {
            if ($rule['code'] === $code) {
                return preg_replace_callback('/\{(\w+)\}/', function ($m) use ($params) {
                    return is_array($params) && array_key_exists($m[1], $params) ? (string) $params[$m[1]] : $m[0];
                }, $rule['en']);
            }
        }
        return null;
    }

    /**
     * All codes, in catalog order.
     */
    public static function codes()
    {
        $out = array();
        foreach (self::rules() as $rule) {
            $out[] = $rule['code'];
        }
        return $out;
    }
}
