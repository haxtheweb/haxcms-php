<?php
/**
 * HAXCMS - The worlds smallest, most nothing yet most empowering CMS.
 * Simply a tremendous CMS. The greatest.
 */
// service creation / HAX app store service abstraction
include_once 'HAXService.php';
// working with sites
include_once 'HAXCMSSite.php';
// working with files
include_once 'HAXCMSFile.php';
// working with JSON Outline Schema
include_once 'JSONOutlineSchema.php';
// working with json web tokens
include_once 'JWT.php';
// working with git operators
include_once 'Git.php';
// composer...ugh
include_once dirname(__FILE__) . "/../../vendor/autoload.php";

class HAXCMS {
  public $appStoreFile;
  public $salt;
  public $outlineSchema;
  public $privateKey;
  public $config;
  public $superUser;
  public $user;
  public $sitesDirectory;
  public $sites;
  public $data;
  public $configDirectory;
  public $sitesJSON;
  public $domain;
  public $protocol;
  public $basePath;
  public $safePost;
  public $safeGet;
  /**
   * Establish defaults for HAXCMS
   */
  public function __construct() {
    // stupid session less handling thing
    $_POST = (array)json_decode(file_get_contents('php://input'));
    // handle sanitization on request data, drop security things
    $this->safePost = filter_var_array($_POST, FILTER_SANITIZE_STRING);
    unset($this->safePost['jwt']);
    unset($this->safePost['token']);
    $this->safeGet = filter_var_array($_GET, FILTER_SANITIZE_STRING);
    // Get HTTP/HTTPS (the possible values for this vary from server to server)
    $this->protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] && !in_array(strtolower($_SERVER['HTTPS']),array('off','no'))) ? 'https' : 'http';
    $this->domain = $_SERVER['HTTP_HOST'];
    // auto generate base path
    $this->basePath = '/';
    $this->config = new stdClass();
    // set up user account stuff
    $this->superUser = new stdClass();
    $this->superUser->name = NULL;
    $this->superUser->password = NULL;
    $this->user = new stdClass();
    $this->user->name = NULL;
    $this->user->password = NULL;
    $this->outlineSchema = new JSONOutlineSchema();
    // set default sites directory to look in if there
    if (is_dir(HAXCMS_ROOT . '/_sites')) {
      $this->sitesDirectory = '_sites';
    }
    // set default config directory to look in if there
    if (is_dir(HAXCMS_ROOT . '/_config')) {
      $this->configDirectory = HAXCMS_ROOT . '/_config';
      // add in the auto-generated app store file
      $this->appStoreFile = 'system/generateAppStore.php';
      // ensure appstore file is there, then make salt size of this file
      if (file_exists($this->configDirectory . '/SALT.txt')) {
        $this->salt = file_get_contents($this->configDirectory . '/SALT.txt');
      }
      if (file_exists(HAXCMS_ROOT . '/' . $this->sitesDirectory . '/sites.json')) {
        $this->sitesJSON = $this->sitesDirectory . '/sites.json?' . $this->getRequestToken(time());
        if (!$this->outlineSchema->load(HAXCMS_ROOT . '/' . $this->sitesDirectory . '/sites.json')) {
          print $this->sitesDirectory . '/sites.json missing';
        }
      }
      // check for a config json file to populate all configurable settings
      if (!$this->config = json_decode(file_get_contents($this->configDirectory . '/config.json'))) {
        print $this->configDirectory . '/config.json missing';
      }
    }
  }
  /**
   * Build valid JSON Schema for the config we have knowledge of
   */
  public function getConfigSchema() {
    $schema = new stdClass();
    $schema->{'$schema'} = "http://json-schema.org/schema#";
    $schema->title = "HAXCMS Config";
    $schema->type = "object";
    $schema->properties = new stdClass();
    $schema->properties->publishing = new stdClass();
    $schema->properties->publishing->title = "Publishing settings";
    $schema->properties->publishing->type = "object";
    $schema->properties->publishing->properties =  new stdClass();
    $schema->properties->apis = new stdClass();
    $schema->properties->apis->title = "API Connectivity";
    $schema->properties->apis->type = "object";
    $schema->properties->apis->properties =  new stdClass();
    // establish some defaults if nothing set internally
    $publishing = array(
      'vendor' => array(
        'name' => 'Vendor',
        'description' => 'Name for this provided (github currently supported)',
        'value' => 'github'
      ),
      'branch' => array(
        'name' => 'Branch',
        'description' => 'Project code branch (like master or gh-pages)',
        'value' => 'gh-pages'
      ),
      'url' => array(
        'name' => 'Repo url',
        'description' => 'Base address / organization that new sites will be saved under',
        'value' => 'git@github.com:elmsln'
      ),
      'user' => array(
        'name' => 'User / Org',
        'description' => 'User name or organization to publish to',
        'value' => ''
      ),
      'email' => array(
        'name' => 'Email',
        'description' => 'Email address of your github account',
        'value' => ''
      ),
      'pass' => array(
        'name' => 'Password',
        'description' => 'Password for the account THIS IS NOT STORED. See ',
        'value' => ''
      ),
      'cdn' => array(
        'name' => 'CDN',
        'description' => 'A CDN address that supports HAXCMS',
        'value' => 'webcomponents.psu.edu'
      ),
    );
    // publishing
    foreach ($publishing as $key => $value) {
      $props = new stdClass();
      $props->title = $value['name'];
      $props->type = 'string';
      if (isset($this->config->publishing->git->{$key})) {
        $props->value = $this->config->publishing->git->{$key};
      }
      else {
        $props->value = $value['value'];
      }
      $props->component = new stdClass();
      $props->component->name = "paper-input";
      $props->component->valueProperty = "value";
      $props->component->slot = '<div slot="suffix">' . $value['description'] . '</div>';
      if ($key == 'pass') {
        $props->component->attributes = new stdClass();
        $props->component->attributes->type = 'password';
      }
      if ($key == 'pass' && isset($this->config->publishing->git->user)) {
        // keep moving but if we already have a user name we don't need this
        // we only ask for a password on the very first run through
        $schema->properties->publishing->properties->user->component->slot = '<div slot="suffix">Set, to change this manually edit _config/config.json.</div>';
        $schema->properties->publishing->properties->user->component->attributes = new stdClass();
        $schema->properties->publishing->properties->user->component->attributes->disabled = 'disabled';
        $schema->properties->publishing->properties->email->component->attributes = new stdClass();
        $schema->properties->publishing->properties->email->component->attributes->disabled = 'disabled';
        $schema->properties->publishing->properties->email->component->slot = '<div slot="suffix">Set, to change this manually edit _config/config.json.</div>';
      }
      else {
        $schema->properties->publishing->properties->{$key} = $props;
      }    
    }
    // API keys
    $hax = new HAXService();
    $apiDocs = $hax->baseSupportedApps();
    foreach ($apiDocs as $key => $value) {
      $props = new stdClass();
      $props->title = $key;
      $props->type = 'string';
      // if we have this value loaded internally then set it
      if (isset($this->config->appStore->apiKeys->{$key})) {
        $props->value = $this->config->appStore->apiKeys->{$key};
      }
      $props->component = new stdClass();
      // look for our documentation object name
      if (isset($apiDocs[$key])) {
        $props->title = $apiDocs[$key]['name'];
        $props->component->slot = '<div slot="suffix"><a href="' . $apiDocs[$key]['docs'] . '" target="_blank">See ' . $props->title . ' developer docs.</a></div>';
      }
      $props->component->name = "paper-input";
      $props->component->valueProperty = "value";
      $schema->properties->apis->properties->{$key} = $props;
    }
    return $schema;
  }
  /**
   * Set and validate config
   */
  public function setConfig($values) {
    if (isset($values->apis)) {
      foreach ($values->apis as $key => $val) {
        $this->config->appStore->apiKeys->{$key} = $val;
      }
    }
    if (!isset($this->config->publishing)) {
        $this->config->publishing = new stdClass();
    }
    if (!isset($this->config->publishing->git)) {
      $this->config->publishing->git = new stdClass();
    }
    if ($values->publishing) {
      foreach ($values->publishing as $key => $val) {
        $this->config->publishing->git->{$key} = $val;
      }
    }
    // test for a password in order to do the git hook up this one time
    if (isset($this->config->publishing->git->email) && isset($this->config->publishing->git->pass)) {
      $email = $this->config->publishing->git->email;
      $pass = $this->config->publishing->git->pass;
      // ensure we never save the password, this is just a 1 time pass through
      unset($this->config->publishing->git->pass);
    }
    // save config to the file
    $this->saveConfigFile();
    $json = new stdClass();
    $json->title = 'HAXCMS Publishing key';
    $json->key = $this->getSSHKey();
    // see if we need to set a github key for publishing
    // this is a one time thing that helps with the workflow
    if (!isset($this->config->publishing->git->keySet) && isset($this->config->publishing->git->vendor) && $this->config->publishing->git->vendor == 'github') {
      $client = new GuzzleHttp\Client();
      $body = json_encode($json);
      $response = $client->request('POST', 'https://api.github.com/user/keys', 
      [
          'auth' => [$email, $pass],
          'body' => $body,
      ]);
      // we did it, now store that it worked so we can skip all this setup in the future
      if ($response->getStatusCode() == 201) {
        $this->config->publishing->git->keySet = true;
        $this->saveConfigFile();
        $gitRepo = new GitRepo();
        $gitRepo->run('config --global user.name "' . $this->config->publishing->git->user . '"');
        $gitRepo->run('config --global user.email "' . $this->config->publishing->git->email . '"');
      }
      return $response->getStatusCode();
    }
    return 'saved';
  }
  /**
   * Write configuration to the config file
   */
  private function saveConfigFile() {
    return file_put_contents($this->configDirectory . '/config.json', json_encode($this->config, JSON_PRETTY_PRINT));
  }
  /**
   * get SSH Key that was created during install
   */
  private function getSSHKey() {
    if (file_exists($this->configDirectory . '/.ssh/haxyourweb.pub')) {
      return @file_get_contents($this->configDirectory . '/.ssh/haxyourweb.pub');
    }
    else {
      // try to build it dynamically
      shell_exec('ssh-keygen -f ' . $this->configDirectory . '/.ssh/haxyourweb -t rsa -N "" -C "' . $this->config->publishing->git->email . '"');
      // check if it exists now
      if (file_exists($this->configDirectory . '/.ssh/haxyourweb.pub')) {
        $git = new GitRepo();
        // establish this new key location as the one to use for all git calls
        $git->run("config core.sshCommand " . $this->configDirectory . "/.ssh/haxyourweb");
        return @file_get_contents($this->configDirectory . '/.ssh/haxyourweb.pub');
      } 
    }
    return FALSE;
  }
  /**
   * Generate a valid HAX App store specification schema for connecting to this site via JSON.
   */
  public function siteConnectionJSON() {
    return '{
      "details": {
        "title": "Local files",
        "icon": "perm-media",
        "color": "light-blue",
        "author": "HAXCMS",
        "description": "HAXCMS integration for HAX",
        "tags": ["media", "hax"]
      },
      "connection": {
        "protocol": "' . $this->protocol . '",
        "url": "' . $this->domain . $this->basePath . '",
        "operations": {
          "browse": {
            "method": "GET",
            "endPoint": "system/loadFiles.php",
            "pagination": {
              "style": "link",
              "props": {
                "first": "page.first",
                "next": "page.next",
                "previous": "page.previous",
                "last": "page.last"
              }
            },
            "search": {
            },
            "data": {
            },
            "resultMap": {
              "defaultGizmoType": "image",
              "items": "list",
              "preview": {
                "title": "name",
                "details": "mime",
                "image": "url",
                "id": "uuid"
              },
              "gizmo": {
                "source": "url",
                "id": "uuid",
                "title": "name",
                "type": "type"
              }
            }
          },
          "add": {
            "method": "POST",
            "endPoint": "system/saveFile.php",
            "acceptsGizmoTypes": [
              "image",
              "video",
              "audio",
              "pdf",
              "svg",
              "document",
              "csv"
            ],
            "resultMap": {
              "item": "data.file",
              "defaultGizmoType": "image",
              "gizmo": {
                "source": "url",
                "id": "uuid"
              }
            }
          }
        }
      }
    }';
  }
  /**
   * Generate appstore connection information. This has to happen at run time.
   * to get into account _config / environmental overrides
   */
  public function appStoreConnection() {
    $connection = new stdClass();
    $connection->url = $this->basePath . $this->appStoreFile . '?app-store-token=' . $this->getRequestToken('appstore');
    return $connection;
  }
  /**
   * Load a site off the file system with option to create
   */
  public function loadSite($name, $create = FALSE, $domain = NULL) {
    $tmpname = urldecode($name);
    $tmpname = preg_replace('/[^A-Za-z0-9]/', '', $tmpname);
    $tmpname = strtolower($tmpname);
    // check if this exists, load but fallback for creating on the fly
    if (is_dir(HAXCMS_ROOT . '/' . $this->sitesDirectory . '/' . $tmpname)) {
      $site = new HAXCMSSite();
      $site->load(HAXCMS_ROOT . '/' . $this->sitesDirectory, $this->basePath . $this->sitesDirectory . '/', $tmpname);
      return $site;
    }
    else if ($create) {
      // attempt to create site
      return $this->createNewSite($name, $domain);
    }
    return FALSE;
  }
  /**
   * Attempt to create a new site on the file system
   * 
   * @var $name name of the new site to create
   * @var $domain optional domain name to utilize during setup
   * @var $git git object details
   * 
   * @return boolean true for success, false for failed
   */
  private function createNewSite($name, $domain = NULL, $git = NULL) {
    // try and make the folder
    $site = new HAXCMSSite();
    // see if we can get a remote setup on the fly
    if (!isset($git->url) && isset($this->config->publishing->git)) {
      $git = $this->config->publishing->git;
      $git->url .= '/' . $name . '.git';
    }

    if ($site->newSite(HAXCMS_ROOT . '/' . $this->sitesDirectory, $this->basePath . $this->sitesDirectory . '/', $name, $git, $domain)) {
      return $site;
    }
    return FALSE;
  }
  /**
   * Validate a security token
   */
  public function validateRequestToken($token = NULL, $value = '') {
    // default token is POST
    if ($token == NULL && isset($_POST['token'])) {
      $token = $_POST['token'];
    }
    if ($token != NULL) {
      if ($token == $this->getRequestToken($value)) {
        return TRUE;
      }
    }
    return FALSE;
  }
  /**
   * test the active user login based on session.
   */
  public function testLogin($adminFallback = FALSE) {
    if ($this->user->name === $_SERVER['PHP_AUTH_USER'] && $this->user->password === $_SERVER['PHP_AUTH_PW']) {
      return TRUE;
    }
    // if fallback is allowed, meaning the super admin then let them in
    // the default is to strictly test for the login in question
    // the fallback being allowable is useful for managed environments
    else if ($adminFallback && $this->superUser->name === $_SERVER['PHP_AUTH_USER'] && $this->superUser->password === $_SERVER['PHP_AUTH_PW']) {
      return TRUE;
    }
    return FALSE;
  }
  /**
   * Get a secure key based on session and two private values
   */
  public function getRequestToken($value = '') {
    return $this->hmacBase64($value, $this->privateKey . $this->salt);
  }
  /**
   * Get user's JWT
   */
  public function getJWT() {
    $token = array();
    $token['id'] = $this->getRequestToken('user');
    $token['user'] = $_SERVER['PHP_AUTH_USER'];
    return JWT::encode($token, $this->privateKey . $this->salt);
  }
  /**
   * Get Front end JWT based connection settings
   */
  public function appJWTConnectionSettings() {
    $settings = new stdClass();
    $settings->login = $this->basePath . 'system/login.php';
    $settings->logout = $this->basePath . 'system/logout.php';
    $settings->savePagePath = $this->basePath . 'system/savePage.php';
    $settings->saveManifestPath = $this->basePath . 'system/saveManifest.php';
    $settings->saveOutlinePath = $this->basePath . 'system/saveOutline.php';
    $settings->publishSitePath = $this->basePath . 'system/publishSite.php';
    $settings->setConfigPath = $this->basePath . 'system/setConfig.php';
    $settings->getConfigPath = $this->basePath . 'system/getConfig.php';
    $settings->createPagePath = $this->basePath . 'system/createPage.php';
    $settings->deletePagePath = $this->basePath . 'system/deletePage.php';
    $settings->createNewSitePath = $this->basePath . 'system/createNewSite.php';
    $settings->downloadSitePath = $this->basePath . 'system/downloadSite.php';
    $settings->appStore = $this->appStoreConnection();
    return $settings;
  }
  /**
   * Validate a JTW during POST
   */
  public function validateJWT($endOnInvalid = TRUE) {
    if (isset($_POST['jwt']) && $_POST['jwt'] != NULL) {
      $post = JWT::decode($_POST['jwt'],  $this->privateKey . $this->salt);
      if ($post->id == $this->getRequestToken('user')) {
        return TRUE;
      }
    }
    // fallback is GET requests
    if (isset($_GET['jwt']) && $_GET['jwt'] != NULL) {
      $get = JWT::decode($_GET['jwt'],  $this->privateKey . $this->salt);
      if ($get->id == $this->getRequestToken('user')) {
        return TRUE;
      }
    }
    // kick back the end if its invalid
    if ($endOnInvalid) {
      print 'Invalid token';
      header('Status: 403');
      exit;
    }
    return FALSE;
  }

  /**
   * Generate a base 64 hash
   */
  private function hmacBase64($data, $key) {
    // generate the hash
    $hmac = base64_encode(hash_hmac('sha256', (string) $data, (string) $key, TRUE));
    // strip unsafe content post encoding
    return strtr($hmac, array(
      '+' => '-',
      '/' => '_',
      '=' => '',
    ));
  }
}