<?php
/**
 * Shared HAXCMS collaborator mock for the Operations seam tests.
 *
 * Configurable properties steer the token / IDOR / platform decisions so
 * tests pin the route gate logic, not the HMAC token crypto or config file
 * parsing. Required via require_once from each Operations test file so the
 * class is declared exactly once across the suite.
 */
class OperationsTestHaxcms
{
    public $configDirectory;
    public $config;
    public $superUser;
    public $user;
    public $userData;
    public $activeUserName = 'testuser';
    public $superUserName = 'admin';
    public $validRequestToken = true;
    public $canAccessSite = true;
    public $loadedSite = null;
    // Sequence of bools returned by validateRequestToken, one per call. When
    // exhausted, falls back to $validRequestToken. Lets a test make the
    // site_token check pass (call 1) while the form-token check fails (call 2).
    public $requestTokenSequence = array();
    public $basePath = '/';
    public $sitesDirectory = '_sites';
    public $archivedDirectory = '_archived';

    public function __construct()
    {
        $this->config = new stdClass();
        $this->superUser = new stdClass();
        $this->superUser->name = 'admin';
        $this->user = new stdClass();
        $this->user->name = 'testuser';
        $this->userData = new stdClass();
    }

    public function validateRequestToken($token, $value)
    {
        if (count($this->requestTokenSequence) > 0) {
            return (bool) array_shift($this->requestTokenSequence);
        }
        return $this->validRequestToken;
    }

    public function getActiveUserName()
    {
        return $this->activeUserName;
    }

    public function validateSiteToken($siteName, $token)
    {
        return $this->validRequestToken;
    }

    public function userCanAccessSite($siteName)
    {
        return $this->canAccessSite;
    }

    public function loadSite($name, $create = false, $domain = null, $build = null)
    {
        return $this->loadedSite;
    }

    public function getUniqueName($name)
    {
        return $name . '-copy';
    }

    public function generateUUID()
    {
        return 'uuid-' . bin2hex(random_bytes(4));
    }

    public function generateSlugName($name)
    {
        return strtolower(preg_replace('/[^a-z0-9_-]+/i', '-', $name));
    }

    public function getHAXCMSVersion()
    {
        return '0.0.0-test';
    }

    public function getThemes()
    {
        return new stdClass();
    }

    public function pageBreakParser($body)
    {
        return array(array('attributes' => array(), 'content' => $body));
    }

    public function cleanTitle($value, $stripPage = true)
    {
        $clean = trim((string) $value);
        if ($stripPage) {
            $clean = str_replace(array('pages/', '/index.html'), '', $clean);
        }
        $clean = str_replace(array('./', '../'), '', $clean);
        $clean = strtolower(str_replace(' ', '-', $clean));
        $clean = preg_replace('/[^\w\-\/]+/u', '-', $clean);
        $clean = mb_strtolower(preg_replace('/--+/u', '-', $clean), 'UTF-8');
        $clean = trim($clean, '-./');
        return $clean !== '' ? $clean : 'blank';
    }
}
