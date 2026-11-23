<?php
/**
 * Resolves which HAXiam tenant (users/<name>) a request belongs to. Loaded by
 * bootstrapHAX.php before HAXCMS_ROOT is defined, so it has no dependencies.
 */
class IamTenant
{
    /**
     * Security (SEC-PATH): a tenant name becomes a single path component
     * (users/<name>), so it must be a plain name. Same rule HAXiam applies when
     * it creates the directory (IAM::isValidUserName): starts with a letter or
     * digit, then letters, digits, '.', '_', '@' or '-', no '..', at most 128
     * bytes. '@' and '.' stay allowed because Shibboleth REMOTE_USER values are
     * commonly email addresses.
     */
    public static function isValidName($name)
    {
        return is_string($name)
            && strlen($name) <= 128
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._@-]*$/', $name) === 1
            && strpos($name, '..') === false;
    }

    /**
     * Tenant name from the first path segment of a request URI, or null when
     * there is none or it is not a valid name. The query string and fragment
     * never take part.
     */
    public static function fromRequestUri($uri)
    {
        if (!is_string($uri) || $uri === '') {
            return null;
        }
        $path = strtok($uri, '?#');
        if ($path === false) {
            return null;
        }
        $pieces = explode('/', $path);
        array_shift($pieces);
        $segment = isset($pieces[0]) ? $pieces[0] : '';
        return self::isValidName($segment) ? $segment : null;
    }

    /**
     * Tenant name from a vanity-domain DOCUMENT_ROOT, which HAXiam lays out as
     * .../users/<name>/<three more levels>. Null when the value is not a valid
     * name.
     */
    public static function fromDocumentRoot($documentRoot)
    {
        if (!is_string($documentRoot) || $documentRoot === '') {
            return null;
        }
        $pieces = explode('/', $documentRoot);
        $index = count($pieces) - 4;
        if ($index < 0) {
            return null;
        }
        return self::isValidName($pieces[$index]) ? $pieces[$index] : null;
    }

    /**
     * Security (SEC-SESSION): decide CLI from the SAPI only, the same rule as
     * HAXCMS::isCLI(). $_SERVER['argc'] is filled from the query string on web
     * SAPIs when register_argc_argv is on, so it must never make a web request
     * look like the CLI.
     */
    public static function isCli()
    {
        return in_array(PHP_SAPI, array('cli', 'phpdbg'), true) && !isset($_SERVER['SERVER_SOFTWARE']);
    }
}
