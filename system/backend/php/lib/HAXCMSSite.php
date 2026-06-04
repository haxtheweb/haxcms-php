<?php
// working with RSS
include_once 'RSS.php';
include_once 'SanitizeContent.php';
use \Gumlet\ImageResize;
// a site object
class HAXCMSSite
{
    public $name;
    public $manifest;
    public $directory;
    public $basePath = '/';
    public $lastPathLookupMiss = false;
    /**
     * Load a site based on directory and name
     */
    public function load($directory, $siteBasePath, $name)
    {
        $this->name = $name;
        $tmpname = urldecode($name);
        $tmpname = $GLOBALS['HAXCMS']->cleanTitle($tmpname, false);
        $this->basePath = $siteBasePath;
        $this->directory = $directory;
        $this->manifest = new JSONOutlineSchema();
        $this->manifest->load($this->directory . '/' . $tmpname . '/site.json');
    }
    /**
     * Load a site based on being in a docker root which has the project as /var/www/html
     */
    public function loadInDocker($directory)
    {
        $this->directory = $directory;
        $this->basePath = "/";
        $this->manifest = new JSONOutlineSchema();
        // presumably this is /var/www/html
        $this->manifest->load($this->directory . '/site.json');
        // pull name out of site.json
        $this->name = $this->manifest->metadata->site->name;
    }
    /**
     * Initialize a new site with a single page to start the outline
     * @var $directory string file system path
     * @var $siteBasePath string web based url / base_path
     * @var $name string name of the site
     * @var $gitDetails git details
     * @var $domain domain information
     *
     * @return HAXCMSSite object
     */
    public function newSite(
        $directory,
        $siteBasePath,
        $name,
        $gitDetails,
        $domain = null,
        $build = null
    ) {
        // calls must set basePath internally to avoid page association issues
        $this->basePath = $siteBasePath;
        $this->directory = $directory;
        $this->name = $name;
        // clean up name so it can be in a URL / published
        $tmpname = urldecode($name);
        $tmpname = $GLOBALS['HAXCMS']->cleanTitle($tmpname, false);
        $loop = 0;
        $newName = $tmpname;
        while (file_exists($directory . '/' . $newName)) {
            $loop++;
            $newName = $tmpname . '-' . $loop;
        }
        $tmpname = $newName;
        // attempt to shift it on the file system
        $this->recurseCopy(
            HAXCMS_ROOT . '/system/boilerplate/site',
            $directory . '/' . $tmpname
        );
        // create symlink to make it easier to resolve things to single built asset buckets
        @symlink('../../build', $directory . '/' . $tmpname . '/build');
        // symlink to do local development if needed
        @symlink('../../dist', $directory . '/' . $tmpname . '/dist');
        // symlink to do project development if needed
        if (is_link(HAXCMS_ROOT . '/node_modules') || is_dir(HAXCMS_ROOT . '/node_modules')) {
            @symlink(
            '../../node_modules',
            $directory . '/' . $tmpname . '/node_modules'
            );
        }
        // links babel files so that unification is easier
        @symlink(
            '../../wc-registry.json',
            $directory . '/' . $tmpname . '/wc-registry.json'
        );
        @symlink(
            '../../../babel/babel-top.js',
            $directory . '/' . $tmpname . '/assets/babel-top.js'
        );
        @symlink(
            '../../../babel/babel-bottom.js',
            $directory . '/' . $tmpname . '/assets/babel-bottom.js'
        );
        // default support is for gh-pages
        if (is_null($domain) && isset($gitDetails->user)) {
            $domain = 'https://' . $gitDetails->user . '.github.io/' . $tmpname;
        } else {
            // put domain into CNAME not the github.io address if that exists
            @file_put_contents($directory . '/' . $tmpname . '/CNAME', $domain);
        }
        // load what we just created
        $this->manifest = new JSONOutlineSchema();
        // where to save it to
        $this->manifest->file = $directory . '/' . $tmpname . '/site.json';
        // start updating the schema to match this new item we got
        $this->manifest->title = $name;
        $this->manifest->location = $this->basePath . $tmpname . '/index.html';
        $this->manifest->metadata = new stdClass();
        $this->manifest->metadata->author = new stdClass();
        $this->manifest->metadata->site = new stdClass();
        $this->manifest->metadata->site->settings = new stdClass();
        $this->manifest->metadata->site->settings->lang = 'en'; // default but changed via settings
        $this->manifest->metadata->site->settings->private = false; // default all sites are open
        $this->manifest->metadata->site->settings->canonical = true; // default all sites to include a canonical URL to reduce duplication
        $this->manifest->metadata->site->name = $tmpname;
        $this->manifest->metadata->site->domain = $domain;
        $this->manifest->metadata->site->created = time();
        $this->manifest->metadata->site->updated = time();
        $this->manifest->metadata->theme = new stdClass();
        $this->manifest->metadata->theme->variables = new stdClass();
        $this->manifest->metadata->node = new stdClass();
        $this->manifest->metadata->node->fields = new stdClass();
        // platform settings scaffold (prevents front-end null handling)
        $this->manifest->metadata->platform = new stdClass();
        $this->manifest->metadata->platform->audience = 'expert';
        $this->manifest->metadata->platform->features = new stdClass();
        $this->manifest->metadata->platform->allowedBlocks = array();
        // create an initial page to make sense of what's there
        // this will double as saving our location and other updated data
        // accept a schema which can generate an array of pages to start
        if ($build == null) {
          $this->addPage(null, 'Welcome', 'init', 'welcome');
        }
        else {
          switch ($build->structure) {
            case 'from-skeleton':
            case 'import':
              $pageSchema = array();
              // implies we had a backend service process much of what we are to build for an import
              // from-skeleton uses same structure as import but comes from skeleton files
              if ($build->items) {
                for ($i=0; $i < count($build->items); $i++) {
                  array_push($pageSchema, array(
                    "parent" => $build->items[$i]['parent'],
                    "title" => $build->items[$i]['title'],
                    "template" => "html",
                    "slug" => $build->items[$i]['slug'],
                    "id" => $build->items[$i]['id'],
                    "indent" => $build->items[$i]['indent'],
                    "contents" => isset($build->items[$i]['content']) ? $build->items[$i]['content'] : (isset($build->items[$i]['contents']) ? $build->items[$i]['contents'] : ''),
                    "order" => $build->items[$i]['order'],
                    "metadata" => isset($build->items[$i]['metadata']) ? $build->items[$i]['metadata'] : NULL,
                  ));
                }
              }
              for ($i=0; $i < count($pageSchema); $i++) {
                if ($pageSchema[$i]['template'] == 'html') {
                  $this->addPage(
                    $pageSchema[$i]['parent'], 
                    $pageSchema[$i]['title'], 
                    $pageSchema[$i]['template'], 
                    $pageSchema[$i]['slug'],
                    $pageSchema[$i]['id'],
                    $pageSchema[$i]['indent'],
                    $pageSchema[$i]['contents'],
                    $pageSchema[$i]['order'],
                    $pageSchema[$i]['metadata'],
                  );
                }
                else {
                  $this->addPage($pageSchema[$i]['parent'], $pageSchema[$i]['title'], $pageSchema[$i]['template'], $pageSchema[$i]['slug']);
                }
              }
            break;
            case 'course':
              $pageSchema = array(
                array(
                  "parent" => null,
                  "title" => "Welcome to " . $name,
                  "template" => "course",
                  "slug" => "welcome"
                )
              );
              switch ($build->type) {
                case 'docx import':
                    // ensure we have items
                  if ($build->items) {
                    for ($i=0; $i < count($build->items); $i++) {
                      array_push($pageSchema, array(
                        "parent" => $build->items[$i]['parent'],
                        "title" => $build->items[$i]['title'],
                        "template" => "html",
                        "slug" => $build->items[$i]['slug'],
                        "id" => $build->items[$i]['id'],
                        "indent" => $build->items[$i]['indent'],
                        "contents" => $build->items[$i]['contents'],
                        "order" => $build->items[$i]['order'],
                        "metadata" => isset($build->items[$i]['metadata']) ? $build->items[$i]['metadata'] : NULL,
                      ));
                    }
                  }
                break;
                case '6w':
                  for ($i=0; $i < 6; $i++) {
                    array_push($pageSchema, array(
                      "parent" => null,
                      "title" => "Lesson " . ($i+1),
                      "template" => "lesson",
                      "slug" => "lesson-" . ($i+1)
                    ));
                  }
                break;
                case '15w':
                  for ($i=0; $i < 15; $i++) {
                    array_push($pageSchema, array(
                      "parent" => null,
                      "title" => "Lesson " . ($i+1),
                      "template" => "lesson",
                      "slug" => "lesson-" . ($i+1)
                    ));
                  }
                break;
                default:
                  /*array_push($pageSchema, array(
                    "parent" => null,
                    "title" => "Lessons",
                    "template" => "default",
                    "slug" => "lessons"
                  ));*/
                break;
              }
              /*array_push($pageSchema, array(
                "parent" => null,
                "title" => "Glossary",
                "template" => "glossary",
                "slug" => "glossary"
              ));*/
              for ($i=0; $i < count($pageSchema); $i++) {
                if ($pageSchema[$i]['template'] == 'html') {
                  $this->addPage(
                    $pageSchema[$i]['parent'], 
                    $pageSchema[$i]['title'], 
                    $pageSchema[$i]['template'], 
                    $pageSchema[$i]['slug'],
                    $pageSchema[$i]['id'],
                    $pageSchema[$i]['indent'],
                    $pageSchema[$i]['contents'],
                    $pageSchema[$i]['order'],
                    $pageSchema[$i]['metadata'],
                  );
                }
                else {
                  $this->addPage($pageSchema[$i]['parent'], $pageSchema[$i]['title'], $pageSchema[$i]['template'], $pageSchema[$i]['slug']);
                }
              }
            break;
            case 'blog':
              $this->addPage(null, 'Article 1', 'init', 'article-1');
              $this->addPage(null, 'Article 2', 'init', 'article-2');
              $this->addPage(null, 'Meet the author', 'init', 'meet-the-author');
            break;
            case 'website':
              switch ($build->type) {
                default:
                  $this->addPage(null, 'Home', 'init', 'home');
                break;
              }
            break;
            case 'collection':
              $this->addPage(null, 'Home', 'collection', 'home');
            break;
            case 'training':
              $this->addPage(null, 'Start', 'init', 'start');
              break;
            case 'portfolio':
              switch ($build->type) {
                case 'art':
                  $this->addPage(null, 'Gallery 1', 'init', 'gallery-1');
                  $this->addPage(null, 'Gallery 2', 'init', 'gallery-2');
                  $this->addPage(null, 'Meet the artist', 'init', 'meet-the-artist');
                break;
                case 'business':
                case 'technology':
                default:
                  $this->addPage(null, 'Article 1', 'init', 'article-1');
                  $this->addPage(null, 'Article 2', 'init', 'article-2');
                  $this->addPage(null, 'Meet the author', 'init', 'meet-the-author');
                break;
              }
            break;
          }
        }
        // put this in version control :) :) :)
        $git = new Git();
        $repo = $git->create($directory . '/' . $tmpname);
        if (
            !isset($this->manifest->metadata->site->git->url) &&
            isset($gitDetails->url)
        ) {
            $this->gitSetRemote($gitDetails);
        }
        // write the managed files to ensure we get happy copies
        $this->rebuildManagedFiles();
        $this->updateAlternateFormats();
        $this->gitCommit('Managed files updated');
        return $this;
    }
    /**
     * Return the forceUpgrade status which is whether to force end users to upgrade their browser
     * @return string status of forced upgrade, string as boolean since it'll get written into a JS file
     */
    public function getForceUpgrade() {
        if (isset($this->manifest->metadata->site->settings->forceUpgrade) && $this->manifest->metadata->site->settings->forceUpgrade) {
            return "true";
        }
        return "false";
    }
    /**
     * Return the gaID which is the (optional) Google Analytics ID
     * @return string gaID the user put in
     */
    public function getGaID() {
      if (isset($this->manifest->metadata->site->settings->gaID) && $this->manifest->metadata->site->settings->gaID) {
          return $this->manifest->metadata->site->settings->gaID;
      }
      return null;
    }
    /**
     * Return the gaIDCode if we have a gaID
     */
    public function getGaCode() {
      if (!is_null($this->getGaID())) {
        return "<script async src=\"https://www.googletagmanager.com/gtag/js?id=" . $this->getGaID() . "\"></script>
        <script>
          window.dataLayer = window.dataLayer || [];
          function gtag(){dataLayer.push(arguments);}
          gtag('js', new Date());
      
          gtag('config', '" . $this->getGaID() . "');
        </script>";
      }
      return '';
    }
    /**
     * Return the sw status
     * @return string status of forced upgrade, string as boolean since it'll get written into a JS file
     */
    public function getServiceWorkerStatus() {
        if (isset($this->manifest->metadata->site->settings->sw) && $this->manifest->metadata->site->settings->sw) {
            return TRUE;
        }
        return FALSE;
    }
    /**
     * Return an array of files we care about rebuilding on managed file operations
     * @return array keyed array of files we wish to pull from the boilerplate and keep in sync
     */
    public function getManagedTemplateFiles() {
      // not everything is templated but ensures self refreshing if we need to bulk tweak / enhance
        return array(
          // HAX core / application / PWA requirements
            'htaccess' => '.htaccess',
            'build' => 'build.js',
            'buildlegacy' => 'assets/build-legacy.js',
            'buildpolyfills' => 'assets/build-polyfills.js',
            'buildhaxcms' => 'build-haxcms.js',
            'outdated' => 'assets/upgrade-browser.html',
            'index' => 'index.html', // static published fallback
            '404' => '404.html', // github / static published redirect appropriately
            // seo / performance
            'push' => 'push-manifest.json',
            'robots' => 'robots.txt',
            'llms' => 'llms.txt',
            'securitytxt' => '.well-known/security.txt',
            'apicatalog' => '.well-known/api-catalog',
            // pwa related files
            'msbc' => 'browserconfig.xml',
            'manifest' => 'manifest.json',
            'sw' => 'service-worker.js',
            'offline' => 'offline.html', // pwa offline page
            // local development tooling
            'webdevserverhaxcmsconfigcjs' => 'web-dev-server.haxcms.config.cjs',
            'package' => 'package.json',
            'polymer' => 'polymer.json',
            // SCORM 1.2
            'imsmdrootv1p2p1' => 'imsmd_rootv1p2p1.xsd',
            'imscprootv1p1p2' => 'imscp_rootv1p1p2.xsd',
            'adlcprootv1p2' => 'adlcp_rootv1p2.xsd',
            'imsxml' => 'ims_xml.xsd',
            'imsmanifest' => 'imsmanifest.xml',
            // files directory execution hardening
            'fileshtaccess' => 'files/.htaccess',
        );
    }
    /**
     * Reprocess the files that twig helps set in their static
     * form that the user is not in control of.
     */
    public function rebuildManagedFiles($templates = array()) {
      // support for calling with a set of predefined templates
      if (count($templates) === 0) {
        $templates = $this->getManagedTemplateFiles();
        // this can't be there by default since it's a dynamic file and we only
        // want to update this when we are refreshing the managed files directly
        $templates['indexphp'] = 'index.php';
        $templates['configphp'] = 'config.php';
      }
      $siteDirectoryPath = $this->directory . '/' . $this->manifest->metadata->site->name;
      $boilerPath = HAXCMS_ROOT . '/system/boilerplate/site/';
      foreach ($templates as $file) {
        $destinationPath = $siteDirectoryPath . '/' . $file;
        $destinationDirectory = dirname($destinationPath);
        if (!is_dir($destinationDirectory)) {
          @mkdir($destinationDirectory, 0775, TRUE);
        }
        copy($boilerPath . $file, $destinationPath);
      }
      $licenseData = $this->getLicenseData('all');
      $licenseLink = '';
      $licenseName = '';
      if (isset($this->manifest->license) && isset($licenseData[$this->manifest->license])) {
        $licenseLink = $licenseData[$this->manifest->license]['link'];
        $licenseName = 'License: ' . $licenseData[$this->manifest->license]['name'];
      }
      // don't assume privacy flag exists
      $privateSetting = FALSE;
      if (isset($this->manifest->metadata->site->settings->private)) {
        $privateSetting = $this->manifest->metadata->site->settings->private;
      }
      $domain = NULL;
      if (isset($this->manifest->metadata->site->domain) && !empty($this->manifest->metadata->site->domain)) {
        $domain = $this->manifest->metadata->site->domain;
      }
      if (is_null($domain) || $domain == "") {
        $fallbackDomain = $GLOBALS['HAXCMS']->getDomain();
        $siteBasePath = $this->getDefaultSiteBasePath();
        if (empty($fallbackDomain)) {
          $domain = $siteBasePath;
        } else {
          $fallbackDomain = str_replace('iam.','oer.', $fallbackDomain);
          if (!preg_match('/^https?:\/\//', $fallbackDomain)) {
            $fallbackDomain = 'https://' . $fallbackDomain;
          }
          $domain = rtrim($fallbackDomain, '/') . '/' . ltrim($siteBasePath, '/');
        }
      }
      $domain = rtrim($domain, '/') . '/';
      
      $templateVars = array(
          'hexCode' => HAXCMS_FALLBACK_HEX,
          'version' => $GLOBALS['HAXCMS']->getHAXCMSVersion(),
          'basePath' =>
              $this->basePath . $this->manifest->metadata->site->name . '/',
          'domain' => $domain,
          'title' => $this->manifest->title,
          'short' => $this->manifest->metadata->site->name,
          'privateSite' => $privateSetting,
          'description' => $this->manifest->description,
          'forceUpgrade' => $this->getForceUpgrade(),
          'getGaID' => $this->getGaID(),
          'swhash' => array(),
          'ghPagesURLParamCount' => 0,
          'licenseLink' => $licenseLink,
          'licenseName' => $licenseName,
          'securityTxtExpires' => gmdate('Y-m-d\TH:i:s\Z', strtotime('+180 days')),
          'serviceWorkerScript' => $this->getServiceWorkerScript($this->basePath . $this->manifest->metadata->site->name . '/'),
          'bodyAttrs' => $this->getSitePageAttributes(),
          'metadata' => $this->getSiteMetadata(),
          'lang' => $this->getLanguage(),
          'logo512x512' => $this->getLogoSize('512','512'),
          'logo256x256' => $this->getLogoSize('256','256'),
          'logo192x192' => $this->getLogoSize('192','192'),
          'logo144x144' => $this->getLogoSize('144','144'),
          'logo96x96' => $this->getLogoSize('96','96'),
          'logo72x72' => $this->getLogoSize('72','72'),
          'logo48x48' => $this->getLogoSize('48','48'),
          'favicon' => $this->getLogoSize('32','32'),
      );
      $swItems = $this->manifest->items;
      // the core files you need in every SW manifest
      $coreFiles = array(
          'index.html',
          $this->getLogoSize('512','512'),
          $this->getLogoSize('256','256'),
          $this->getLogoSize('192','192'),
          $this->getLogoSize('144','144'),
          $this->getLogoSize('96','96'),
          $this->getLogoSize('72','72'),
          $this->getLogoSize('48','48'),
          'manifest.json',
          'site.json',
          '404.html',
      );
      // loop through files directory so we can cache those things too
      if ($handle = opendir($siteDirectoryPath . '/files')) {
          while (false !== ($file = readdir($handle))) {
              if (
                  $file != "." &&
                  $file != ".." &&
                  $file != '.gitkeep' &&
                  $file != '._.DS_Store' &&
                  $file != '.DS_Store' &&
                  $file != '.htaccess'
              ) {
                  // ensure this is a file
                  if (
                      is_file($siteDirectoryPath . '/files/' . $file)
                  ) {
                      $coreFiles[] = 'files/' . $file;
                  } else {
                      // @todo maybe step into directories?
                  }
              }
          }
          closedir($handle);
      }
      foreach ($coreFiles as $itemLocation) {
          $coreItem = new stdClass();
          $coreItem->location = $itemLocation;
          $swItems[] = $coreItem;
      }
      // generate a legit hash value that's the same for each file name + file size
      foreach ($swItems as $item) {
          if (
              $item->location === '' ||
              $item->location === $templateVars['basePath']
          ) {
              $filesize = filesize(
                  $siteDirectoryPath . '/index.html'
              );
          } elseif (
              file_exists($siteDirectoryPath . '/' . $item->location)
          ) {
              $filesize = filesize(
                  $siteDirectoryPath . '/' . $item->location
              );
          } else {
              // ?? file referenced but doesn't exist
              $filesize = 0;
          }
          if ($filesize !== 0) {
              $templateVars['swhash'][] = array(
                  $item->location,
                  strtr(
                      base64_encode(
                          hash_hmac(
                              'md5',
                              (string) $item->location . $filesize,
                              (string) 'haxcmsswhash',
                              true
                          )
                      ),
                      array(
                          '+' => '',
                          '/' => '',
                          '=' => '',
                          '-' => ''
                      )
                  )
              );
          }
      }
      if (isset($this->manifest->metadata->theme->variables->hexCode)) {
          $templateVars['hexCode'] =
              $this->manifest->metadata->theme->variables->hexCode;
      }
      // put the twig written output into the file
      $loader = new \Twig\Loader\FilesystemLoader($siteDirectoryPath);
      $twig = new \Twig\Environment($loader);
      foreach ($templates as $location) {
          if (file_exists($siteDirectoryPath . '/' . $location)) {
            @file_put_contents(
                $siteDirectoryPath . '/' . $location,
                $twig->render($location, $templateVars)
            );
          }
      }
      if (array_search('llms.txt', $templates) !== FALSE) {
          try {
              $this->updateAlternateFormats('llms');
          } catch (Exception $e) {
              // best effort only; llms serialization should never break managed-file rebuilds
          }
      }
    }
    /**
     * Rename a page from one location to another
     * This ensures that folders are moved but not the final index.html involved
     * It also helps secure the sites by ensuring movement is only within
     * their folder tree
     */
    public function renamePageLocation($old, $new)
    {
        $siteDirectory =
            $this->directory . '/' . $this->manifest->metadata->site->name;
        $old = str_replace('./', '', str_replace('../', '', $old));
        $new = str_replace('./', '', str_replace('../', '', $new));
        global $fileSystem;
        // ensure the path to the new folder is valid
        if (file_exists($siteDirectory . '/' . $old)) {
            $fileSystem->mirror(
                str_replace('/index.html', '', $siteDirectory . '/' . $old),
                str_replace('/index.html', '', $siteDirectory . '/' . $new)
            );
            $fileSystem->remove($siteDirectory . '/' . $old);
        }
    }
    /**
     * Basic wrapper to commit current changes to version control of the site
     */
    public function gitCommit($msg = 'Committed changes')
    {
        $git = new Git();
        // commit, true flag will attempt to make this a git repo if it currently isn't
        $repo = $git->open(
            $this->directory . '/' . $this->manifest->metadata->site->name, true
        );
        $repo->add('.');
        $repo->commit($msg);
        // commit should execute the automatic push flag if it's on
        if (isset($this->manifest->metadata->site->git->autoPush) && $this->manifest->metadata->site->git->autoPush && isset($this->manifest->metadata->site->git->branch)) {
            $repo->push('origin', $this->manifest->metadata->site->git->branch);
        }
        return true;
    }
    /**
     * Basic wrapper to revert top commit of the site
     */
    public function gitRevert($count = 1)
    {
        $git = new Git();
        $repo = $git->open(
            $this->directory . '/' . $this->manifest->metadata->site->name, true
        );
        $repo->revert($count);
        return true;
    }
    /**
     * Basic wrapper to commit current changes to version control of the site
     */
    public function gitPush()
    {
        $git = new Git();
        $repo = $git->open(
            $this->directory . '/' . $this->manifest->metadata->site->name, true
        );
        $repo->add('.');
        $repo->commit("commit forced");
        return true;
    }

    /**
     * Basic wrapper to commit current changes to version control of the site
     *
     * @var $git a stdClass containing repo details
     */
    public function gitSetRemote($gitDetails)
    {
        $git = new Git();
        $repo = $git->open(
            $this->directory . '/' . $this->manifest->metadata->site->name, true
        );
        $repo->set_remote("origin", $gitDetails->url);
        return true;
    }
    /**
     * Validate that a page's location is in a valid space (aka pages/whatever/index.html)
     * and not outside the current site directory.
     */
    public function validatePageLocation($location)
    {
      // ensure the path to the new folder is valid
      $siteDirectoryPath = $this->directory . '/' . $this->manifest->metadata->site->name;
      // force removal of anything that might try to move out of the location of pages
      $location = str_replace('./', '', str_replace('../', '', $location));
      if (file_exists($siteDirectoryPath . '/' . $location)) {
        return true;
      }
      return false;
    }
    /**
     * Add a page to the site's file system and reflect it in the outine schema.
     *
     * @var $parent JSONOutlineSchemaItem representing a parent to add this page under
     * @var $title title of the new page to create
     * @var $template string which boilerplate page template / directory to load
     *
     * @return $page repesented as JSONOutlineSchemaItem
     */
    public function addPage($parent = null, $title = 'New page', $template = "default", $slug = 'welcome', $id = null, $indent = null, $html = '<p></p>', $order = null, $metadata = null)
    {
        // draft an outline schema item
        $page = new JSONOutlineSchemaItem();
        // support direct ID setting, useful for parent associations calculated ahead of time
        if (!is_null($id)) {
          $page->id = $id;
        }
        // set a crappy default title
        $page->title = $title;
        if ($parent == null) {
          $page->parent = null;
          $page->indent = 0;
        }
        else if (is_string($parent)) {
          // set to the parent id
          $page->parent = $parent;
          // move it one indentation below the parent; this can be changed later if desired
          $page->indent = $indent;
        } else {
          // set to the parent id
          $page->parent = $parent->id;
          // move it one indentation below the parent; this can be changed later if desired
          $page->indent = $parent->indent + 1;
        }
        // set order to the page's count for default add to end ordering
        if (!is_null($order)) {
          $page->order = $order;
        }
        else {
          $page->order = count($this->manifest->items);
        }
        // location is the html file we just copied and renamed
        $page->location = 'pages/' . $page->id . '/index.html';
        // sanitize slug but dont trust it was anything
        if ($slug == '') {
          $slug = $title;
        }
        $page->slug = $this->getUniqueSlugName($GLOBALS['HAXCMS']->cleanTitle($slug));
        // support presetting multiple metadata attributes like tags, pageType, etc
        if (!is_null($metadata)) {
          foreach ($metadata as $key => $value) {
            $page->metadata->{$key} = $value;
          }
        }
        $page->metadata->created = time();
        $page->metadata->updated = time();
        $location = $this->directory . '/' .
            $this->manifest->metadata->site->name .
            '/pages' . '/' . $page->id;
        // copy the page we use for simplicity (or later complexity if we want)
        switch ($template) {
            case 'course':
            case 'glossary':
            case 'collection':
            case 'init':
            case 'lesson':
            case 'default':
              $this->recurseCopy(HAXCMS_ROOT . '/system/boilerplate/page/' . $template, $location);
            break;
            // didn't understand it, just go default
            default:
              $this->recurseCopy(HAXCMS_ROOT . '/system/boilerplate/page/default', $location);
            break;
        }
        $this->manifest->addItem($page);
        $this->manifest->save();
        // support direct HTML setting
        $alternateContent = '';
        if ($template == 'html') {
          // now this should exist if it didn't a minute ago
          $alternateContent = SanitizeContent::sanitizeHTMLForStorage($html);
          $bytes = $page->writeLocation(
            $alternateContent,
            HAXCMS_ROOT .
            '/' .
            $GLOBALS['HAXCMS']->sitesDirectory .
            '/' .
            $this->manifest->metadata->site->name .
            '/'
          );
        }
        $this->writePageAlternateFormats($page, $alternateContent);
        $this->updateAlternateFormats();
        return $page;
    }
    /**
     * Save the site, though this basically is just a mapping to the manifest site.json saving
     */
    public function save() {
      $this->manifest->save();
    }
    /**
     * Build the default public base path for this site without protocol/host.
     */
    private function getDefaultSiteBasePath()
    {
      $basePath = '/';
      if (isset($this->basePath) && is_string($this->basePath) && $this->basePath != '') {
        $basePath = $this->basePath;
      }
      if (substr($basePath, 0, 1) != '/') {
        $basePath = '/' . $basePath;
      }
      if (substr($basePath, -1) != '/') {
        $basePath .= '/';
      }
      return $basePath . $this->manifest->metadata->site->name . '/';
    }
    /**
     * Ensure we can build an absolute URL for sitemap generation.
     */
    private function getAbsoluteSiteDomainForSitemap($domain = '')
    {
      if (is_string($domain) && preg_match('/^https?:\\/\\//i', $domain)) {
        return $domain;
      }
      $fallbackDomain = $GLOBALS['HAXCMS']->getDomain();
      if (!empty($fallbackDomain)) {
        if (!preg_match('/^https?:\\/\\//i', $fallbackDomain)) {
          $fallbackDomain = 'https://' . $fallbackDomain;
        }
        $relativePath = is_string($domain) && $domain != ''
          ? $domain
          : $this->getDefaultSiteBasePath();
        $relativePath = '/' . ltrim($relativePath, '/');
        return rtrim($fallbackDomain, '/') . $relativePath;
      }
      return '';
    }
    /**
     * Fallback sitemap XML writer when absolute-domain sitemap generation is unavailable.
     */
    private function buildSitemapFallbackXml($domain = '')
    {
      $base = is_string($domain) ? $domain : '';
      if ($base == '') {
        $base = $this->getDefaultSiteBasePath();
      }
      $base = rtrim($base, '/') . '/';
      $xml = array();
      $xml[] = '<?xml version="1.0" encoding="UTF-8"?>';
      $xml[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
      foreach ($this->manifest->items as $item) {
        if (!isset($item->slug) || $item->slug == '') {
          continue;
        }
        $loc = rtrim($base, '/') . '/' . ltrim($item->slug, '/');
        $lastmod = '';
        if (isset($item->metadata->updated) && is_numeric($item->metadata->updated)) {
          $lastmod = gmdate('c', intval($item->metadata->updated));
        }
        $xml[] = '  <url>';
        $xml[] = '    <loc>' . htmlspecialchars($loc, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>';
        if ($lastmod != '') {
          $xml[] = '    <lastmod>' . htmlspecialchars($lastmod, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</lastmod>';
        }
        $xml[] = '  </url>';
      }
      $xml[] = '</urlset>';
      return implode("\n", $xml) . "\n";
    }
    /**
     * Fallback sitemap-index XML writer when absolute-domain sitemap generation is unavailable.
     */
    private function buildSitemapIndexFallbackXml($domain = '')
    {
      $base = is_string($domain) ? $domain : '';
      if ($base == '') {
        $base = $this->getDefaultSiteBasePath();
      }
      $base = rtrim($base, '/') . '/';
      $loc = $base . 'sitemap.xml';
      $xml = array();
      $xml[] = '<?xml version="1.0" encoding="UTF-8"?>';
      $xml[] = '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
      $xml[] = '  <sitemap>';
      $xml[] = '    <loc>' . htmlspecialchars($loc, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>';
      $xml[] = '    <lastmod>' . gmdate('c') . '</lastmod>';
      $xml[] = '  </sitemap>';
      $xml[] = '</sitemapindex>';
      return implode("\n", $xml) . "\n";
    }
    /**
     * Update RSS, Atom feeds, site map, legacy outline, search index
     * which are physical files and need rebuilt on chnages to data structure
     */
    public function updateAlternateFormats($format = NULL)
    {
      $siteDirectory = $this->directory . '/' . $this->manifest->metadata->site->name . '/';
      $siteBasePath = $this->getDefaultSiteBasePath();
      // Determine domain for feeds
      $domain = NULL;
      if (isset($this->manifest->metadata->site->domain) && !empty($this->manifest->metadata->site->domain)) {
        $domain = $this->manifest->metadata->site->domain;
      }
      if (is_null($domain) || $domain == "") {
        // simple domain redirect, this is a bit of a hack but it works for now w/ haxiam
        $fallbackDomain = $GLOBALS['HAXCMS']->getDomain();
        if (empty($fallbackDomain)) {
            // CLI fallback or when SERVER_NAME is not available - use base path
            $domain = $siteBasePath;
        } else {
            $fallbackDomain = str_replace('iam.','oer.', $fallbackDomain);
            // Ensure we have a protocol
            if (!preg_match('/^https?:\/\//', $fallbackDomain)) {
                $fallbackDomain = 'https://' . $fallbackDomain;
            }
            $domain = rtrim($fallbackDomain, '/') . '/' . ltrim($siteBasePath, '/');
        }
      }
      
      if (is_null($format) || $format == 'rss') {
          try {
              // rip changes to feed urls
              $rss = new FeedMe();
              $siteDirectory =
                  $this->directory . '/' . $this->manifest->metadata->site->name . '/';
              @file_put_contents($siteDirectory . 'rss.xml', $rss->getRSSFeed($this, $domain));
              @file_put_contents(
                  $siteDirectory . 'atom.xml',
                  $rss->getAtomFeed($this, $domain)
              );
          } catch (Exception $e) {
              // some of these XML parsers are a bit unstable
          }
      }
      // build a sitemap if we have a domain, kinda required...
      if (is_null($format) || $format == 'sitemap') {
          try {
              $sitemapDomain = $this->getAbsoluteSiteDomainForSitemap($domain);
              if (!empty($sitemapDomain) && preg_match('/^https?:\\/\\//i', $sitemapDomain)) {
                  $generator = new \Icamys\SitemapGenerator\SitemapGenerator(
                      $sitemapDomain,
                      $siteDirectory
                  );
                  // will create also compressed (gzipped) sitemap
                  $generator->enableCompression();
                  // determine how many urls should be put into one file
                  // according to standard protocol 50000 is maximum value (see http://www.sitemaps.org/protocol.html)
                  $generator->setMaxUrlsPerSitemap(50000);
                  // sitemap file name
                  $generator->setSitemapFilename("sitemap.xml");
                  // sitemap index file name
                  $generator->setSitemapIndexFilename("sitemap-index.xml");
                  // adding url `loc`, `lastmodified`, `changefreq`, `priority`
                  foreach ($this->manifest->items as $key => $item) {
                      if ($item->parent == null) {
                          $priority = '1.0';
                      } elseif ($item->indent == 2) {
                          $priority = '0.7';
                      } else {
                          $priority = '0.5';
                      }
                      $updatedTime = new DateTime();
                      $updatedTime->setTimestamp($item->metadata->updated);
                      $updatedTime->format(DateTime::ATOM);
                      @$generator->addUrl(
                          rtrim($sitemapDomain, '/') . '/' . ltrim($item->slug, '/'),
                          $updatedTime,
                          'daily',
                          $priority
                      );
                  }
                  // writing early generated sitemap to file
                  @$generator->flush();
                  @$generator->finalize();
              }
              else if (!empty($domain)) {
                  @file_put_contents(
                      $siteDirectory . 'sitemap.xml',
                      $this->buildSitemapFallbackXml($domain)
                  );
                  @file_put_contents(
                      $siteDirectory . 'sitemap-index.xml',
                      $this->buildSitemapIndexFallbackXml($domain)
                  );
              }
          } catch (Exception $e) {
              // some of these XML parsers are a bit unstable
          }
      }
      if (is_null($format) || $format == 'search') {
          // now generate the search index
          @file_put_contents(
              $siteDirectory . 'lunrSearchIndex.json',
                  json_encode($this->lunrSearchIndex($this->manifest->items))
          );
      }
      if (is_null($format) || $format == 'llms') {
          try {
              @file_put_contents(
                  $siteDirectory . 'llms.txt',
                  $this->getLLMSTxt($domain)
              );
          } catch (Exception $e) {
              // best effort only; llms serialization should never block save operations
          }
      }
      // rebuild the service worker's hashed cache index because this file updated
      // this way users getting cached copies from local device will be informed
      // that this page updated since their last visit
      if (is_null($format) || $format == 'service-worker') {
        $this->rebuildManagedFiles(array('sw' => 'service-worker.js'));
      }
    }
    /**
     * Generate llms.txt content based on site structure and generated markdown pages.
     */
    public function getLLMSTxt($domain = '')
    {
      $title = '';
      if (isset($this->manifest->title)) {
        $title = $this->getLLMSSafeText($this->manifest->title);
      }
      if ($title == '' && isset($this->manifest->metadata->site->name)) {
        $title = $this->getLLMSSafeText($this->manifest->metadata->site->name);
      }
      if ($title == '') {
        $title = 'HAXcms site';
      }
      $lines = array('# ' . $title);
      $description = '';
      if (isset($this->manifest->description)) {
        $description = $this->getLLMSSafeText($this->manifest->description);
      }
      if ($description != '') {
        $lines[] = '';
        $lines[] = '> ' . $description;
      }
      $lines[] = '';
      $lines[] = 'HAXcms is a file-based CMS: authored pages and metadata are stored as portable files, not locked into a database-only workflow.';
      $lines[] = 'The canonical site structure is `site.json`, represented in JSON Outline Schema (`id`, `parent`, `order`, `slug`, `location`, and `metadata`).';
      $lines[] = 'Use HAX CLI and ecosystem tooling to maintain human-authored content while keeping machine-readable outputs synchronized.';
      $lines[] = 'Managed files (feeds, search indexes, manifests, and this `llms.txt`) are generated artifacts and should be rebuilt by tooling.';
      $lines[] = '';
      $lines[] = '## Core resources';
      $lines[] = '- [site.json](' . $this->getLLMSResourceURL($domain, 'site.json') . '): Canonical site manifest and navigation tree in JSON Outline Schema format.';
      $lines[] = '- [llms.txt](' . $this->getLLMSResourceURL($domain, 'llms.txt') . '): LLM-oriented guide to this site and its machine-readable resources.';
      $lines[] = '';
      $lines[] = '## Pages';
      $items = array();
      if (isset($this->manifest) && isset($this->manifest->items)) {
        $items = $this->manifest->orderTree($this->manifest->items);
      }
      $hasPages = false;
      foreach ($items as $item) {
        if (!isset($item->location) || $item->location == '') {
          continue;
        }
        $markdownLocation = $this->getPageAlternateLocation($item->location, 'md');
        if ($markdownLocation == '') {
          continue;
        }
        $itemTitle = 'Untitled page';
        if (isset($item->title) && $item->title != '') {
          $itemTitle = $item->title;
        }
        else if (isset($item->slug) && $item->slug != '') {
          $itemTitle = $item->slug;
        }
        else if (isset($item->id) && $item->id != '') {
          $itemTitle = $item->id;
        }
        $itemTitle = $this->getLLMSSafeLinkText($itemTitle);
        $itemDescription = '';
        if (isset($item->description)) {
          $itemDescription = $this->getLLMSSafeText($item->description);
        }
        $line = '- [' . $itemTitle . '](' . $this->getLLMSResourceURL($domain, $markdownLocation) . ')';
        if ($itemDescription != '') {
          $line .= ': ' . $itemDescription;
        }
        $lines[] = $line;
        $hasPages = true;
      }
      if (!$hasPages) {
        $lines[] = '- [Site outline](' . $this->getLLMSResourceURL($domain, 'site.json') . '): No page markdown files are currently available.';
      }
      $lines[] = '';
      $lines[] = '## Optional';
      $lines[] = '- [Search index](' . $this->getLLMSResourceURL($domain, 'lunrSearchIndex.json') . '): Lunr corpus for quick full-text retrieval.';
      $lines[] = '- [RSS feed](' . $this->getLLMSResourceURL($domain, 'rss.xml') . '): Site updates in RSS format.';
      $lines[] = '- [Atom feed](' . $this->getLLMSResourceURL($domain, 'atom.xml') . '): Site updates in Atom format.';
      $lines[] = '- [Sitemap](' . $this->getLLMSResourceURL($domain, 'sitemap.xml') . '): URL-level discovery map for the published site.';
      return implode("\n", $lines) . "\n";
    }
    /**
     * Build a normalized llms.txt link URL from domain/base and a relative resource path.
     */
    public function getLLMSResourceURL($domain = '', $location = '')
    {
      $baseURL = $this->getLLMSBaseURL($domain);
      $cleanLocation = '';
      if (is_string($location)) {
        $cleanLocation = str_replace('\\', '/', $location);
        $cleanLocation = ltrim($cleanLocation, '/');
      }
      if ($cleanLocation == '') {
        return $baseURL;
      }
      if ($baseURL == '/') {
        return '/' . $cleanLocation;
      }
      return $baseURL . $cleanLocation;
    }
    /**
     * Normalize domain/base values into a URL-safe prefix for llms.txt links.
     */
    public function getLLMSBaseURL($domain = '')
    {
      $baseURL = '';
      if (is_string($domain)) {
        $baseURL = trim($domain);
      }
      if ($baseURL == '') {
        return '/';
      }
      if (!preg_match('/^https?:\\/\\//', $baseURL) && substr($baseURL, 0, 1) != '/') {
        $baseURL = '/' . $baseURL;
      }
      if (substr($baseURL, -1) != '/') {
        $baseURL .= '/';
      }
      return $baseURL;
    }
    /**
     * Normalize text for llms.txt body content.
     */
    public function getLLMSSafeText($value = '')
    {
      if (is_null($value) || is_array($value) || is_object($value)) {
        return '';
      }
      $text = str_replace(array("\r", "\n"), ' ', (string) $value);
      return trim(preg_replace('/\\s+/', ' ', $text));
    }
    /**
     * Normalize markdown link text and escape bracket characters.
     */
    public function getLLMSSafeLinkText($value = '')
    {
      $text = $this->getLLMSSafeText($value);
      if ($text == '') {
        $text = 'Untitled page';
      }
      return str_replace(array('[', ']'), array('\\[', '\\]'), $text);
    }
    /**
     * Create Lunr.js style search index
     */
    public function jsonFeedFormat($limit = 25) {
      $domain = NULL;
      if (isset($this->manifest->metadata->site->domain)) {
        $domain = $this->manifest->metadata->site->domain;                
      }
      if (is_null($domain) || $domain == "") {
        // simple domain redirect, this is a bit of a hack but it works for now w/ haxiam
        $domain = str_replace('iam.','oer.', $GLOBALS['HAXCMS']->getDomain()) . "/sites/" . $this->manifest->metadata->site->name . "/";
      }
      $data = array(
        "version" => "https://jsonfeed.org/version/1.1",
        "title" => $this->manifest->title,
        "home_page_url" => $domain,
        "feed_url" => $domain . 'jsonfeed.json',
        "description" => $this->manifest->description,
        "items" => array(),
      );
      $count = 0;
      foreach ($this->manifest->items as $item) {
        if ($count < $limit) {
          $created = time();
          if (isset($item->metadata) && isset($item->metadata->created)) {
            $created = $item->metadata->created;
          }
          if (isset($item->slug)) {
            $slug = $item->slug;
          }
          else {
            // slug is now the URL canonical
            $slug = str_replace('pages/', '', str_replace('/index.html', '', $item->location));
          }
          // may seem silly but IDs in lunr have a size limit for some reason in our context..
          $jsonFeed = array(
            "guid" => substr(str_replace('-', '', str_replace('item-', '', $item->id)), 0, 29),
            "url" => $domain . $slug,
            "title" => $item->title,
            "summary" => $item->description,
            "content_html" => '',
            "date_published" => date('c', $created),
          );
          // test location is valid prior to adding it
          if ($this->validatePageLocation($item->location)) {
            $locationPath = str_replace('./', '', str_replace('../', '', $this->directory . '/' . $this->manifest->metadata->site->name . '/' . $item->location));
            $jsonFeed['content_html'] = @file_get_contents($locationPath);
          }
          $data["items"][] = $jsonFeed;
        }
        $count++;
      }
      return $data;
    }
    /**
     * Create Lunr.js style search index
     */
    public function lunrSearchIndex($items) {
      $data = array();
      foreach ($items as $item) {
        $created = time();
        if (isset($item->metadata) && isset($item->metadata->created)) {
          $created = $item->metadata->created;
        }
        // slug is now the URL canonical
        $slug = str_replace('pages/', '', str_replace('/index.html', '', $item->location));
        // if the item has a slug, use that instead of the location
        if (isset($item->slug)) {
          $slug = $item->slug;
        }
        // may seem silly but IDs in lunr have a size limit for some reason in our context..
        $lunrSearchItem = array(
          "id" => substr(str_replace('-', '', str_replace('item-', '', $item->id)), 0, 29),
          "title" => $item->title,
          "created" => $created,
          "location" => $slug,
          "description" => strip_tags($item->description),
          "text" => '',
        );
        // test location is valid prior to adding it
        if ($this->validatePageLocation($item->location)) {
          $locationPath = str_replace('./', '', str_replace('../', '', $this->directory . '/' . $this->manifest->metadata->site->name . '/' . $item->location));
          $lunrSearchItem['text'] = $this->cleanSearchData(@file_get_contents($locationPath));
        }

        $data[] = $lunrSearchItem;
      }
      return $data;
    }
    /**
     * Clean up data from a file and make it easy for us to index on the front end
     */
    private function cleanSearchData($text) {
      // clean up initial, small, trim, replace end lines, utf8 no tags
      $text = trim(strtolower(str_replace("\n", ' ', strip_tags((string) $text))));
      // all weird chars
      $text = preg_replace('/[^a-z0-9\']/', ' ', $text);
      $text = str_replace("'", '', $text);
      // all words 1 to 4 letters long
      $text = preg_replace('~\b[a-z]{1,4}\b\s*~', '', $text);
      // all excess white space
      $text = preg_replace('/\s+/', ' ', $text);
      // crush string to array and back to make an unique index
      $text = implode(' ', array_unique(explode(' ', $text)));
      return $text;
    }
    private function compareItemKeys($a, $b) {
      $key = $this->__compareItemKey;
      $dir = $this->__compareItemDir;
      if (isset($a->metadata->{$key})) {
        if ($dir == 'DESC') {
          if ($a->metadata->{$key} == $b->metadata->{$key}) {
            return 0;
          }
          return ($a->metadata->{$key} > $b->metadata->{$key}) ? -1 : 1;
        }
        else {
          if ($a->metadata->{$key} == $b->metadata->{$key}) {
            return 0;
          }
          return ($a->metadata->{$key} < $b->metadata->{$key}) ? -1 : 1;
        }
      }
    }
    /**
     * Sort items by a certain key value. Must be in the included list for safety of the sort
     * @var string $key - the key name to sort on, only some supported
     * @var string $dir - direction to sort, ASC default or DESC to reverse
     * @return array $items - sorted items based on the key used
     */
    public function sortItems($key, $dir = 'ASC') {
        $items = $this->manifest->items;
        switch ($key) {
            case 'created':
            case 'updated':
            case 'readtime':
              $this->__compareItemKey = $key;
              $this->__compareItemDir = $dir;
              usort($items, array($this,'compareItemKeys'));
            break;
            case 'id':
            case 'title':
            case 'indent':
            case 'location':
            case 'order':
            case 'parent':
            case 'description':
                usort($items, function ($a, $b) {
                  if ($dir == 'ASC') {
                    if ($a->{$key} == $b->{$key}) {
                      return 0;
                    }
                    return ($a->{$key} > $b->{$key}) ? -1 : 1;
                  }
                  else {
                    if ($a->{$key} == $b->{$key}) {
                      return 0;
                    }
                    return ($a->{$key} < $b->{$key}) ? -1 : 1;
                  }
                });
            break;
        }
        return $items;
    }
    /**
     * Build a JOS into a tree of links recursively
     */
    private function treeToNodes($current, &$rendered = array(), $html = '')
    {
        $loc = '';
        foreach ($current as $item) {
            if (!array_search($item->id, $rendered)) {
                $loc .=
                    '<li><a href="' .
                    $item->location .
                    '" target="content">' .
                    $item->title .
                    '</a>';
                array_push($rendered, $item->id);
                $children = array();
                foreach ($this->manifest->items as $child) {
                    if ($child->parent == $item->id) {
                        array_push($children, $child);
                    }
                }
                // sort the kids
                usort($children, function ($a, $b) {
                  if ($a->order == $b->order) {
                    return 0;
                  }
                  return ($a->order < $b->order) ? -1 : 1;
                });
                // only walk deeper if there were children for this page
                if (count($children) > 0) {
                    $loc .= $this->treeToNodes($children, $rendered);
                }
                $loc .= '</li>';
            }
        }
        // make sure we aren't empty here before wrapping
        if ($loc != '') {
            $loc = '<ul>' . $loc . '</ul>';
        }
        return $html . $loc;
    }
    /**
     * Load node by unique id
     */
    public function loadNode($uuid)
    {
        foreach ($this->manifest->items as $item) {
            if ($item->id == $uuid && $uuid != '') {
                return $item;
            }
        }
        return false;
    }
    /**
     * Get a social sharing image based on context of page or site having media
     * @var string $page page to mine the image from or attempt to
     * @return string full URL to an image
     */
    public function getSocialShareImage($page = null) {
      // resolve a JOS Item vs null
      if ($page != null) {
        $id = $page->id;
      }
      else {
        $id = null;
      }
      $fileName = &$GLOBALS['HAXCMS']->staticCache(__FUNCTION__ . $id);
      
      if (!isset($fileName)) {
        if (is_null($page)) {
          $page = $this->loadNodeByLocation();
        }
        if (isset($page->metadata->files)) {
          foreach ($page->metadata->files as $file) {
            if ($file->type == 'image/jpeg') {
              $fileName = $file->fullUrl;
            }
          }
        }
        // look for the theme banner
        if (isset($this->manifest->metadata->theme->variables->image)) {
          $fileName = filter_var($this->manifest->metadata->theme->variables->image, FILTER_SANITIZE_URL);
        }
      }
      return $fileName;
    }
    /**
     * Return attributes for the site
     * @todo make this mirror the drupal get attributes method
     * @return string eventually, array of data keyed by type of information
     */
    public function getSitePageAttributes() {
      return 'vocab="http://schema.org/" prefix="oer:http://oerschema.org cc:http://creativecommons.org/ns dc:http://purl.org/dc/terms/"';
    }
    public function getLanguage() {
      if (isset($this->manifest->metadata->site->settings->lang) && $this->manifest->metadata->site->settings->lang != "" && $this->manifest->metadata->site->settings->lang != null) {
        return $this->manifest->metadata->site->settings->lang;
      }
      return "en-US";
    }
    /**
     * Return the base tag accurately which helps with the PWA / SW side of things
     * @return string HTML blob for hte <base> tag
     */
    public function getBaseTag() {
      return '<base href="' . $this->getPWABaseTagPath() . '" />';
    }
    // get the path that sites in the <base> tag's href. This call is in the event
    // we want to start using this in the future
    public function getPWABaseTagPath() {
      if (isset($GLOBALS["HAXcmsInDocker"])) {
        return $this->basePath;
      }
      if (getenv('HAXSITE_BASE_URL')) {
        return getenv('HAXSITE_BASE_URL');
      }
      return $this->basePath . $this->manifest->metadata->site->name . '/';
    }
    /**
     * Return a standard service worker that takes into account
     * the context of the page it's been placed on.
     * @todo this will need additional vetting based on the context applied
     * @return string <script> tag that will be a rather standard service worker
     */
    public function getServiceWorkerScript($basePath = null, $ignoreDevMode = FALSE, $addSW = TRUE) {
      // because this can screw with caching, let's make sure we
      // can throttle it locally for developers as needed
      if (!$addSW || ($GLOBALS["HAXCMS"]->developerMode && !$ignoreDevMode)) {
        return "\n  <!-- Service worker disabled via settings -->\n";
      }
      // support dynamic calculation
      if (is_null($basePath)) {
        $basePath = $this->basePath . $this->manifest->metadata->site->name . '/';
      }
      return "
  <script>
    if ('serviceWorker' in navigator) {
      var sitePath = '" . $basePath . "';
      // discover this path downstream of the root of the domain
      var swScope = window.location.pathname.substring(0, window.location.pathname.indexOf(sitePath)) + sitePath;
      if (swScope != document.head.getElementsByTagName('base')[0].href) {
        document.head.getElementsByTagName('base')[0].href = swScope;
      }
      window.addEventListener('load', function () {
        navigator.serviceWorker.register('service-worker.js', {
          scope: swScope
        }).then(function (registration) {
          registration.onupdatefound = function () {
            // The updatefound event implies that registration.installing is set; see
            // https://slightlyoff.github.io/ServiceWorker/spec/service_worker/index.html#service-worker-container-updatefound-event
            var installingWorker = registration.installing;
            installingWorker.onstatechange = function () {
              switch (installingWorker.state) {
                case 'installed':
                  if (!navigator.serviceWorker.controller) {
                    window.dispatchEvent(new CustomEvent('haxcms-toast-show', {
                      bubbles: true,
                      cancelable: false,
                      detail: {
                        text: 'Pages you view are cached for offline use.',
                        duration: 4000
                      }
                    }));
                  }
                break;
                case 'redundant':
                  throw Error('The installing service worker became redundant.');
                break;
              }
            };
          };
        }).catch(function (e) {
          console.warn('Service worker registration failed:', e);
        });
        // Check to see if the service worker controlling the page at initial load
        // has become redundant, since this implies there's a new service worker with fresh content.
        if (navigator.serviceWorker.controller) {
          navigator.serviceWorker.controller.onstatechange = function(event) {
            if (event.target.state === 'redundant') {
              var b = document.createElement('paper-button');
              b.appendChild(document.createTextNode('Reload'));
              b.raised = true;
              b.addEventListener('click', function(e){ window.location.reload(true); });
              window.dispatchEvent(new CustomEvent('haxcms-toast-show', {
                bubbles: true,
                cancelable: false,
                detail: {
                  text: 'A site update is available. Reload for latest content.',
                  duration: 8000,
                  slot: b,
                  clone: false
                }
              }));
            }
          };
        }
      });
    }
  </script>";
    }
    /**
     * Generate the stub of a well formed site.json item
     * based on parameters
     */
    public function itemFromParams($params) {
        // get a new item prototype
        $item = $GLOBALS['HAXCMS']->outlineSchema->newItem();
        // set the title
        $item->title = str_replace("\n", '', $params['node']['title']);
        if (isset($params['node']['id']) && $params['node']['id'] != '' && $params['node']['id'] != null) {
            $item->id = $params['node']['id'];
        }
        $item->location = 'pages/' . $item->id . '/index.html';
        if (isset($params['indent']) && $params['indent'] != '' && $params['indent'] != null) {
            $item->indent = $params['indent'];
        }
        if (isset($params['order']) && $params['order'] != '' && $params['order'] != null) {
            $item->order = $params['order'];
        }
        if (isset($params['parent']) && $params['parent'] != '' && $params['parent'] != null) {
            $item->parent = $params['parent'];
        } else {
            $item->parent = null;
        }
        if (isset($params['description']) && $params['description'] != '' && $params['description'] != null) {
            $item->description = str_replace("\n", '', $params['description']);
        }
        if (isset($params['metadata']) && $params['metadata'] != '' && $params['metadata'] != null) {
            $item->metadata = $params['metadata'];
        }
        if (isset($params['node']['location']) && $params['node']['location'] != '' && $params['node']['location'] != null) {
          $cleanTitle = $GLOBALS['HAXCMS']->cleanTitle($params['node']['location']);
          $item->slug = $this->getUniqueSlugName($cleanTitle);
        } else {
          $cleanTitle = $GLOBALS['HAXCMS']->cleanTitle($item->title);
          $item->slug = $this->getUniqueSlugName($cleanTitle, $item, true);
        }
        $item->metadata->created = time();
        $item->metadata->updated = time();
        return $item;
      }
    /**
     * Load content of this page
     * @var JSONOutlineSchemaItem $page - a loaded page object
     * @return string HTML / contents of the page object
     */
    public function getPageContent($page) {
      if (isset($page->location) && $page->location != '') {
        $content = &$GLOBALS['HAXCMS']->staticCache(__FUNCTION__ . $page->location);
        if (!isset($content)) {
          // ensure path is not trying to escape the site directory
          $content = '';
          if ($this->validatePageLocation($page->location)) {
            $locationPath = str_replace('./', '', str_replace('../', '', HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $this->manifest->metadata->site->name . '/' . $page->location));
            $content = filter_var(@file_get_contents($locationPath));
          }
        }
        return $content;
      }
      return '';
    }
    /**
     * Generate per-page alternate format files beside index.html.
     * Best effort only: failures must never break page save responses.
     */
    public function writePageAlternateFormats($page, $htmlContent = '')
    {
      if (!isset($page) || !isset($page->location) || $page->location == '') {
        return false;
      }
      $siteDirectory = $this->directory . '/' . $this->manifest->metadata->site->name . '/';
      $content = '';
      if (is_string($htmlContent) && $htmlContent != '') {
        $content = $htmlContent;
      }
      else {
        $content = $this->getPageContent($page);
      }
      if (!is_string($content) || $content == '') {
        $content = '<p></p>';
      }
      // markdown
      try {
        $markdownLocation = $this->getPageAlternateLocation($page->location, 'md');
        @file_put_contents($siteDirectory . $markdownLocation, $this->htmlToMarkdown($content));
      }
      catch (Exception $e) {}
      // json
      try {
        $jsonPayload = $this->getPageAlternatePayload($page, $content, 'json');
        @file_put_contents(
          $siteDirectory . $jsonPayload['location'],
          json_encode($jsonPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
      }
      catch (Exception $e) {}
      // yaml
      try {
        $yamlPayload = $this->getPageAlternatePayload($page, $content, 'yaml');
        @file_put_contents(
          $siteDirectory . $yamlPayload['location'],
          $this->encodePageAlternateYaml($yamlPayload)
        );
      }
      catch (Exception $e) {}
      // xml
      try {
        $xmlPayload = $this->getPageAlternatePayload($page, $content, 'xml');
        @file_put_contents(
          $siteDirectory . $xmlPayload['location'],
          $this->getPageAlternateXml($xmlPayload)
        );
      }
      catch (Exception $e) {}
      return true;
    }
    /**
     * Build structured alternate payload with extension-specific location.
     */
    private function getPageAlternatePayload($page, $content, $extension = 'json')
    {
      $payload = json_decode(json_encode($page), true);
      if (!is_array($payload)) {
        $payload = array();
      }
      $payload['location'] = $this->getPageAlternateLocation($page->location, $extension);
      $payload['content'] = $content;
      return $payload;
    }
    /**
     * Derive sidecar file location from page location.
     */
    public function getPageAlternateLocation($location = '', $extension = 'json')
    {
      if (!is_string($location) || $location == '') {
        return '';
      }
      $normalizedLocation = str_replace('\\', '/', $location);
      $cleanExtension = strtolower(ltrim((string) $extension, '.'));
      if (preg_match('/\\.html?$/i', $normalizedLocation)) {
        return preg_replace('/\\.html?$/i', '.' . $cleanExtension, $normalizedLocation);
      }
      return $normalizedLocation . '.' . $cleanExtension;
    }
    /**
     * Request path without query string.
     */
    private function getRequestPathWithoutQuery()
    {
      if (isset($_SERVER['REQUEST_URI'])) {
        $requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        if (is_string($requestPath)) {
          return $requestPath;
        }
      }
      return '/';
    }
    /**
     * Request path relative to the site script directory.
     */
    private function getRequestRelativePath()
    {
      $requestPath = $this->getRequestPathWithoutQuery();
      $scriptDirectory = '';
      if (isset($_SERVER['SCRIPT_NAME']) && is_string($_SERVER['SCRIPT_NAME'])) {
        $scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
      }
      if ($scriptDirectory == '.' || $scriptDirectory == '/') {
        $scriptDirectory = '';
      }
      $scriptDirectory = rtrim($scriptDirectory, '/');
      if ($scriptDirectory != '' && strpos($requestPath, $scriptDirectory) === 0) {
        $requestPath = substr($requestPath, strlen($scriptDirectory));
      }
      if (!is_string($requestPath) || $requestPath == '') {
        return '/';
      }
      if (substr($requestPath, 0, 1) != '/') {
        $requestPath = '/' . $requestPath;
      }
      return $requestPath;
    }
    /**
     * Parse explicit extension-based format request.
     */
    private function getRequestedVariantInfo($path = '')
    {
      $matches = array();
      if (preg_match('/^(.*)\.(html|md|json|ya?ml|xml)$/i', $path, $matches)) {
        $format = strtolower($matches[2]);
        if ($format === 'yml') {
          $format = 'yaml';
        }
        return array(
          'format' => $format,
          'basePath' => isset($matches[1]) ? $matches[1] : '',
        );
      }
      return array(
        'format' => null,
        'basePath' => $path,
      );
    }
    /**
     * Variant content type map.
     */
    private function getRequestedVariantContentType($format = '')
    {
      switch ($format) {
        case 'html':
          return 'text/html; charset=utf-8';
        break;
        case 'md':
          return 'text/markdown; charset=utf-8';
        break;
        case 'json':
          return 'application/json; charset=utf-8';
        break;
        case 'yaml':
          return 'application/yaml; charset=utf-8';
        break;
        case 'xml':
          return 'application/xml; charset=utf-8';
        break;
      }
      return 'text/plain; charset=utf-8';
    }
    /**
     * Determine format from Accept header for negotiation.
     */
    private function getNegotiatedVariantFormat()
    {
      $accept = '';
      if (isset($_SERVER['HTTP_ACCEPT']) && is_string($_SERVER['HTTP_ACCEPT'])) {
        $accept = strtolower($_SERVER['HTTP_ACCEPT']);
      }
      $acceptsHtml =
        strpos($accept, 'text/html') !== false ||
        strpos($accept, 'application/xhtml+xml') !== false;
      if ($acceptsHtml) {
        return null;
      }
      if (strpos($accept, 'text/markdown') !== false) {
        return 'md';
      }
      if (
        strpos($accept, 'application/yaml') !== false ||
        strpos($accept, 'application/x-yaml') !== false ||
        strpos($accept, 'text/yaml') !== false
      ) {
        return 'yaml';
      }
      if (
        strpos($accept, 'application/xml') !== false ||
        strpos($accept, 'text/xml') !== false
      ) {
        return 'xml';
      }
      if (
        strpos($accept, 'application/json') !== false &&
        strpos($accept, 'text/html') === false
      ) {
        return 'json';
      }
      return null;
    }
    /**
     * Build route path to a slug from current script directory context.
     */
    private function getCanonicalPagePathForSlug($slug = '')
    {
      $scriptDirectory = '';
      if (isset($_SERVER['SCRIPT_NAME']) && is_string($_SERVER['SCRIPT_NAME'])) {
        $scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
      }
      if ($scriptDirectory == '.' || $scriptDirectory == '/') {
        $scriptDirectory = '';
      }
      $scriptDirectory = rtrim($scriptDirectory, '/');
      $normalizedSlug = trim((string) $slug, '/');
      if ($normalizedSlug == '') {
        if ($scriptDirectory != '') {
          return $scriptDirectory;
        }
        return '/';
      }
      if ($scriptDirectory != '') {
        return $scriptDirectory . '/' . $normalizedSlug;
      }
      return '/' . $normalizedSlug;
    }
    /**
     * Resolve sidecar file path for a page and format.
     */
    private function getAlternateFilePathForPageFormat($page, $format = 'json')
    {
      if (!isset($page) || !isset($page->location) || !is_string($page->location) || $page->location == '') {
        return null;
      }
      $variantLocation = $this->getPageAlternateLocation($page->location, $format);
      if (!is_string($variantLocation) || $variantLocation == '') {
        return null;
      }
      $siteDirectory = $this->directory . '/' . $this->manifest->metadata->site->name . '/';
      $filePath = $siteDirectory . $variantLocation;
      if (file_exists($filePath) && is_file($filePath)) {
        return $filePath;
      }
      return null;
    }
    /**
     * Resolve a manifest item by slug without requiring JSONOutlineSchema helper methods.
     */
    private function getManifestItemBySlug($slug = '')
    {
      if (
        !isset($this->manifest) ||
        !isset($this->manifest->items) ||
        !is_array($this->manifest->items) ||
        !is_string($slug) ||
        $slug == ''
      ) {
        return null;
      }
      foreach ($this->manifest->items as $item) {
        if (isset($item->slug) && $item->slug === $slug) {
          return $item;
        }
      }
      return null;
    }
    /**
     * Serve a requested page variant response early when explicit or negotiated format is requested.
     */
    public function respondWithRequestedPageVariant()
    {
      $relativePath = $this->getRequestRelativePath();
      if (strpos($relativePath, '/x/') === 0) {
        return false;
      }
      $variantInfo = $this->getRequestedVariantInfo($relativePath);
      $slug = trim($variantInfo['basePath'], '/');
      if ($slug == '') {
        return false;
      }
      $page = $this->getManifestItemBySlug($slug);
      if (!$page) {
        if ($variantInfo['format']) {
          http_response_code(404);
          header('Content-Type: text/plain; charset=utf-8');
          print 'Not found';
          return true;
        }
        return false;
      }
      $canonicalPath = $this->getCanonicalPagePathForSlug($slug);
      if ($variantInfo['format']) {
        $filePath = $this->getAlternateFilePathForPageFormat($page, $variantInfo['format']);
        if ($filePath) {
          header('Content-Type: ' . $this->getRequestedVariantContentType($variantInfo['format']));
          header('Content-Location: ' . $canonicalPath . '.' . $variantInfo['format']);
          readfile($filePath);
          return true;
        }
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        print 'Not found';
        return true;
      }
      $negotiatedFormat = $this->getNegotiatedVariantFormat();
      if ($negotiatedFormat) {
        $filePath = $this->getAlternateFilePathForPageFormat($page, $negotiatedFormat);
        if ($filePath) {
          header('Vary: Accept');
          header('Content-Type: ' . $this->getRequestedVariantContentType($negotiatedFormat));
          header('Content-Location: ' . $canonicalPath . '.' . $negotiatedFormat);
          readfile($filePath);
          return true;
        }
      }
      return false;
    }
    /**
     * Send Link headers advertising available alternate page formats.
     */
    public function sendPageAlternateHeaders($page = null)
    {
      if (!isset($page) || !isset($page->slug) || !is_string($page->slug) || $page->slug == '') {
        return;
      }
      $canonicalPath = $this->getCanonicalPagePathForSlug($page->slug);
      $formats = array('html', 'md', 'json', 'yaml', 'xml');
      $linkParts = array();
      foreach ($formats as $format) {
        $filePath = $this->getAlternateFilePathForPageFormat($page, $format);
        if ($filePath) {
          $linkParts[] = '<' . $canonicalPath . '.' . $format . '>; rel="alternate"; type="' . str_replace('; charset=utf-8', '', $this->getRequestedVariantContentType($format)) . '"';
        }
      }
      if (count($linkParts) > 0) {
        header('Link: ' . implode(', ', $linkParts));
        header('Vary: Accept');
      }
    }
    /**
     * Build HTML <link rel="alternate"> tags for available page sidecar formats.
     */
    private function getPageAlternateLinkTags($page = null)
    {
      if (!isset($page) || !isset($page->slug) || !is_string($page->slug) || $page->slug == '') {
        return '';
      }
      $canonicalPath = $this->getCanonicalPagePathForSlug($page->slug);
      $formats = array('html', 'md', 'json', 'yaml', 'xml');
      $tags = '';
      foreach ($formats as $format) {
        $filePath = $this->getAlternateFilePathForPageFormat($page, $format);
        if ($filePath) {
          $mimeType = str_replace('; charset=utf-8', '', $this->getRequestedVariantContentType($format));
          $tags .=
            '  <link rel="alternate" type="' .
            SanitizeContent::escapeHTMLAttribute($mimeType) .
            '" href="' .
            SanitizeContent::escapeHTMLAttribute($canonicalPath . '.' . $format) .
            '" />' .
            "\n";
        }
      }
      return $tags;
    }
    /**
     * HTML to markdown conversion with tolerant handling of custom tags.
     */
    private function htmlToMarkdown($html = '')
    {
      if (!is_string($html) || trim($html) == '') {
        return '';
      }
      $markdown = str_replace("\r\n", "\n", $html);
      $markdown = preg_replace_callback('/<pre[^>]*>\\s*<code[^>]*>([\\s\\S]*?)<\\/code>\\s*<\\/pre>/i', function ($matches) {
        return "\n```\n" . trim($matches[1], "\n") . "\n```\n";
      }, $markdown);
      $markdown = preg_replace('/<img[^>]*src=["\']([^"\']+)["\'][^>]*alt=["\']([^"\']*)["\'][^>]*>/i', '![$2]($1)', $markdown);
      $markdown = preg_replace('/<img[^>]*alt=["\']([^"\']*)["\'][^>]*src=["\']([^"\']+)["\'][^>]*>/i', '![$1]($2)', $markdown);
      $markdown = preg_replace('/<img[^>]*src=["\']([^"\']+)["\'][^>]*>/i', '![]($1)', $markdown);
      $markdown = preg_replace('/<a[^>]*href=["\']([^"\']+)["\'][^>]*>([\\s\\S]*?)<\\/a>/i', '[$2]($1)', $markdown);
      $markdown = preg_replace('/<(strong|b)[^>]*>([\\s\\S]*?)<\\/\\1>/i', '**$2**', $markdown);
      $markdown = preg_replace('/<(em|i)[^>]*>([\\s\\S]*?)<\\/\\1>/i', '*$2*', $markdown);
      $markdown = preg_replace('/<code[^>]*>([\\s\\S]*?)<\\/code>/i', '`$1`', $markdown);
      $markdown = preg_replace('/<br\\s*\\/?\\s*>/i', "\n", $markdown);
      for ($level = 6; $level > 0; $level--) {
        $markdown = preg_replace(
          '/<h' . $level . '[^>]*>([\\s\\S]*?)<\\/h' . $level . '>/i',
          "\n" . str_repeat('#', $level) . " $1\n\n",
          $markdown
        );
      }
      $markdown = preg_replace_callback('/<li[^>]*>([\\s\\S]*?)<\\/li>/i', function ($matches) {
        return "\n- " . trim($matches[1]);
      }, $markdown);
      $markdown = preg_replace('/<\\/?(ul|ol)[^>]*>/i', "\n", $markdown);
      $markdown = preg_replace('/<p[^>]*>([\\s\\S]*?)<\\/p>/i', "\n$1\n\n", $markdown);
      $markdown = preg_replace('/<\\/?(div|section|article|main)[^>]*>/i', "\n", $markdown);
      $markdown = preg_replace('/<\\/?span[^>]*>/i', '', $markdown);
      $markdown = preg_replace('/[ \t]+\n/', "\n", $markdown);
      $markdown = preg_replace('/\n{3,}/', "\n\n", $markdown);
      return trim($markdown);
    }
    /**
     * Encode payload as YAML. Falls back to JSON (valid YAML 1.2) if ext-yaml is unavailable.
     */
    private function encodePageAlternateYaml($payload)
    {
      if (function_exists('yaml_emit')) {
        $yamlOutput = @yaml_emit($payload);
        if (is_string($yamlOutput) && $yamlOutput != '') {
          return $yamlOutput;
        }
      }
      return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    /**
     * Build XML output from structured payload.
     */
    private function getPageAlternateXml($payload)
    {
      $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<item>";
      foreach ($payload as $key => $value) {
        $xml .= $this->getPageAlternateXmlNode($key, $value);
      }
      $xml .= "\n</item>\n";
      return $xml;
    }
    /**
     * XML recursive serializer.
     */
    private function getPageAlternateXmlNode($key, $value)
    {
      $tagName = $this->getSafeXmlTagName($key);
      if ($key === 'content') {
        return "\n<" . $tagName . "><![CDATA[" . $this->getSafeXmlCdata($value) . "]]></" . $tagName . ">";
      }
      if (is_object($value)) {
        $value = get_object_vars($value);
      }
      if (is_array($value)) {
        $output = '';
        $isAssoc = array_keys($value) !== range(0, count($value) - 1);
        if ($isAssoc) {
          foreach ($value as $childKey => $childValue) {
            $output .= $this->getPageAlternateXmlNode($childKey, $childValue);
          }
        }
        else {
          foreach ($value as $childValue) {
            $output .= $this->getPageAlternateXmlNode('item', $childValue);
          }
        }
        return "\n<" . $tagName . ">" . $output . "\n</" . $tagName . ">";
      }
      if (is_null($value)) {
        return "\n<" . $tagName . "></" . $tagName . ">";
      }
      return "\n<" . $tagName . ">" . SanitizeContent::escapeXMLValue((string) $value) . "</" . $tagName . ">";
    }
    /**
     * Ensure generated XML tags are valid.
     */
    private function getSafeXmlTagName($name = '')
    {
      $tagName = preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $name);
      if ($tagName == '') {
        $tagName = 'item';
      }
      if (preg_match('/^[0-9]/', $tagName)) {
        $tagName = 'item-' . $tagName;
      }
      return $tagName;
    }
    /**
     * Prevent CDATA close token breakouts.
     */
    private function getSafeXmlCdata($value = '')
    {
      return str_replace(']]>', ']]]]><![CDATA[>', (string) $value);
    }
    /**
     * Return accurate, rendered site metadata
     * @var JSONOutlineSchemaItem $page - a loaded page object, most likely whats active
     * @return string an html chunk of tags for the head section
     * @todo move this to a render function / section / engine
     */
    public function getSiteMetadata($page = NULL, $domain = NULL, $cdn = '') {
      $preloadTags = array();
      if (is_null($page)) {
        $page = new JSONOutlineSchemaItem();
      }
      else {
        $content = $this->getPageContent($page);
        if (!is_null($content)) {
          preg_match_all("/<(?:\"-[^\"]*\"['\"]*|'[^']*'['\"]*|[^'\">])+>/", $content, $matches);
          foreach ($matches[0] as $match) {
            if (strpos($match, '-')) {
              $tag = str_replace('>', '', str_replace('</', '', $match));
              $preloadTags[$tag] = $tag;
            }
          }
        }
      }
      if (is_null($domain)) {
        $domain = $GLOBALS['HAXCMS']->getURI();
      }
      $preconnect = '';
      $base = './';
      if ($cdn == '' && $GLOBALS['HAXCMS']->cdn != './') {
        $cdn = $GLOBALS['HAXCMS']->cdn;
      }
      if ($cdn != '') {
        $preconnect = '<link rel="preconnect" crossorigin href="' . SanitizeContent::escapeHTMLAttribute(SanitizeContent::sanitizeURLValue($cdn, '')) . '">';
        $base = SanitizeContent::sanitizeURLValue($cdn, '');
      }
      $contentPreload = '';
      $wcMap = $GLOBALS['HAXCMS']->getWCRegistryJson($this, $base);
      foreach ($preloadTags as $tag) {
        if (isset($wcMap->{$tag})) {
          $contentPreload .= "\n" . '  <link rel="preload" href="' . $base . 'build/es6/node_modules/' . $wcMap->{$tag} . '" as="script" crossorigin="anonymous" />
  <link rel="modulepreload" href="' . $base . 'build/es6/node_modules/' . $wcMap->{$tag} . '" />';
        }
      }
      $rawTitle = '';
      if (isset($page->title)) {
        $rawTitle = (string) $page->title;
      }
      $rawDescription = '';
      if (isset($page->description)) {
        $rawDescription = (string) $page->description;
      }
      $title = SanitizeContent::escapeHTMLAttribute($rawTitle);
      $siteTitle = SanitizeContent::escapeHTMLAttribute($this->manifest->title) . ' | ' . SanitizeContent::escapeHTMLAttribute($rawTitle);
      $description = SanitizeContent::escapeHTMLAttribute($rawDescription);
      $hexCode = HAXCMS_FALLBACK_HEX;
      $themePreload = '';
      if (isset($this->manifest->metadata->theme->path)) {
        $themePreload = '  <link rel="preload" href="' . $base . 'build/es6/node_modules/' . str_replace("@lrnwebcomponents/", "@haxtheweb/", $this->manifest->metadata->theme->path) . '" as="script" crossorigin="anonymous" />
  <link rel="modulepreload" href="' . $base . 'build/es6/node_modules/' . str_replace("@lrnwebcomponents/", "@haxtheweb/", $this->manifest->metadata->theme->path) . '" />';
      }
      if ($rawDescription == '' && isset($this->manifest->description)) {
        $rawDescription = (string) $this->manifest->description;
      }
      if ($description == '') {
        $description = SanitizeContent::escapeHTMLAttribute($rawDescription);
      }
      if ($rawTitle == '' || $rawTitle == 'New item') {
        $rawTitle = (string) $this->manifest->title;
        $title = SanitizeContent::escapeHTMLAttribute($rawTitle);
        $siteTitle = $title;
      }
      if (isset($this->manifest->metadata->theme->variables->hexCode)) {
        $hexCode = SanitizeContent::escapeHTMLAttribute($this->manifest->metadata->theme->variables->hexCode);
      }
      if (isset($this->manifest->metadata->site->settings->private) && $this->manifest->metadata->site->settings->private) {
        $robots = '<meta name="robots" content="none" />';
      }
      else {
        $robots = '<meta name="robots" content="index, follow" />';
      }
      $canonicalBase = SanitizeContent::sanitizeURLValue($domain, '');
      if (isset($this->manifest->metadata->site->domain) && $this->manifest->metadata->site->domain != '') {
        $canonicalBase = SanitizeContent::sanitizeURLValue($this->manifest->metadata->site->domain, '');
      }
      $siteUrlForStructuredData = $canonicalBase;
      $pageUrlForStructuredData = SanitizeContent::sanitizeURLValue($domain, '');
      if (isset($this->manifest->metadata->site->settings->canonical) && $this->manifest->metadata->site->settings->canonical) {
        if (isset($this->manifest->metadata->site->domain) && $this->manifest->metadata->site->domain != '') {
          $pageSlug = '';
          if (isset($page->slug)) {
            $pageSlug = ltrim((string) $page->slug, '/');
          }
          $pageUrlForStructuredData = SanitizeContent::sanitizeURLValue(rtrim((string) $this->manifest->metadata->site->domain, '/') . '/' . $pageSlug, '');
          $canonical = '  <link rel="canonical" href="' . SanitizeContent::escapeHTMLAttribute($pageUrlForStructuredData) . '" />' . "\n";
        }
        else {
          $pageUrlForStructuredData = SanitizeContent::sanitizeURLValue($domain, '');
          $canonical = '  <link rel="canonical" href="' . SanitizeContent::escapeHTMLAttribute($pageUrlForStructuredData) . '" />' . "\n";
        }
      }
      else {
        $canonical = '';
      }
      if ($siteUrlForStructuredData == '') {
        $siteUrlForStructuredData = $pageUrlForStructuredData;
      }
      if ($pageUrlForStructuredData == '') {
        $pageUrlForStructuredData = $siteUrlForStructuredData;
      }
      $prevResource = '';
      $nextResource = '';
      if ($page->id && $this->manifest->getItemKeyById($page->id) !== FALSE) {
        $currentId = $this->manifest->getItemKeyById($page->id);
        if ($currentId > 0 && isset($this->manifest->items[$currentId-1]->slug)) {
          $prevResource = '  <link rel="prev" href="' . SanitizeContent::escapeHTMLAttribute($this->manifest->items[$currentId-1]->slug) . '" />' . "\n";
        }
        if ($currentId < count($this->manifest->items)-1 && isset($this->manifest->items[$currentId+1]->slug)) {
          $nextResource = '  <link rel="next" href="' . SanitizeContent::escapeHTMLAttribute($this->manifest->items[$currentId+1]->slug) . '" />' . "\n";
        }
      }
      $safeDomain = SanitizeContent::escapeHTMLAttribute($pageUrlForStructuredData);
      $safeSocialImage = SanitizeContent::escapeHTMLAttribute(SanitizeContent::sanitizeURLValue($this->getSocialShareImage($page), ''));
      $alternateLinks = $this->getPageAlternateLinkTags($page);
      $canonical .= $alternateLinks;
      $metadata = '
  <meta charset="utf-8">' . $preconnect . '
  <link rel="preconnect" crossorigin href="https://fonts.googleapis.com">
  <link rel="preconnect" crossorigin href="https://cdnjs.cloudflare.com">
  <link rel="preload" href="' . $base . 'build.js" as="script" />
  <link rel="preload" href="' . $base . 'build-haxcms.js" as="script" />
  <link rel="preload" href="' . $base . 'wc-registry.json" as="fetch" crossorigin="anonymous" fetchpriority="high" />
  <link rel="modulepreload" href="' . $base . 'build/es6/node_modules/@haxtheweb/wc-autoload/wc-autoload.js" crossorigin="anonymous" />
  <link rel="modulepreload" href="' . $base . 'build/es6/node_modules/@haxtheweb/dynamic-import-registry/dynamic-import-registry.js" crossorigin="anonymous" />
  <link rel="modulepreload" href="' . $base . 'build/es6/node_modules/@haxtheweb/haxcms-elements/lib/core/haxcms-site-builder.js" crossorigin="anonymous" />
  <link rel="modulepreload" href="' . $base . 'build/es6/node_modules/@haxtheweb/haxcms-elements/lib/core/haxcms-site-store.js" crossorigin="anonymous" />
  <link rel="modulepreload" href="' . $base . 'build/es6/node_modules/@haxtheweb/haxcms-elements/lib/core/haxcms-site-router.js" crossorigin="anonymous" />
  <link rel="modulepreload" href="' . $base . 'build/es6/node_modules/@haxtheweb/haxcms-elements/lib/core/HAXCMSThemeWiring.js" crossorigin="anonymous" />
  <link rel="modulepreload" href="' . $base . 'build/es6/node_modules/@haxtheweb/haxcms-elements/lib/core/HAXCMSLitElementTheme.js" crossorigin="anonymous" />
  <link rel="modulepreload" href="' . $base . 'build/es6/node_modules/@haxtheweb/utils/utils.js" crossorigin="anonymous" />
' . $themePreload . $contentPreload . '
  <link rel="preload" href="' . $base . 'build/es6/node_modules/@haxtheweb/haxcms-elements/lib/base.css" as="style" />
  <link rel="llms" href="llms.txt" title="LLM Content Map" />
  <link rel="alternate" type="text/markdown" href="llms.txt" title="Markdown Summary" />
  <meta name="generator" content="HAXcms" />
' . $canonical . $prevResource . $nextResource . '  <link rel="manifest" href="manifest.json" />
  <meta name="viewport" content="width=device-width, minimum-scale=1, initial-scale=1, user-scalable=yes" />
  <title>' . $siteTitle . '</title>
  <link rel="icon" href="' . SanitizeContent::escapeHTMLAttribute(SanitizeContent::sanitizeURLValue($this->getLogoSize('16', '16'), '')) . '" />
  <meta name="theme-color" content="' . $hexCode . '" />
  ' . $robots . '
  <meta name="mobile-web-app-capable" content="yes" />
  <meta name="application-name" content="' . $title . '" />
  <meta name="apple-mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
  <meta name="apple-mobile-web-app-title" content="' . $title . '" />
  <link rel="apple-touch-icon" sizes="48x48" href="' . SanitizeContent::escapeHTMLAttribute(SanitizeContent::sanitizeURLValue($this->getLogoSize('48', '48'), '')) . '" />
  <link rel="apple-touch-icon" sizes="72x72" href="' . SanitizeContent::escapeHTMLAttribute(SanitizeContent::sanitizeURLValue($this->getLogoSize('72', '72'), '')) . '" />
  <link rel="apple-touch-icon" sizes="96x96" href="' . SanitizeContent::escapeHTMLAttribute(SanitizeContent::sanitizeURLValue($this->getLogoSize('96', '96'), '')) . '" />
  <link rel="apple-touch-icon" sizes="144x144" href="' . SanitizeContent::escapeHTMLAttribute(SanitizeContent::sanitizeURLValue($this->getLogoSize('144', '144'), '')) . '" />
  <link rel="apple-touch-icon" sizes="192x192" href="' . SanitizeContent::escapeHTMLAttribute(SanitizeContent::sanitizeURLValue($this->getLogoSize('192', '192'), '')) . '" />
  <meta name="msapplication-TileImage" content="' . SanitizeContent::escapeHTMLAttribute(SanitizeContent::sanitizeURLValue($this->getLogoSize('144', '144'), '')) . '" />
  <meta name="msapplication-TileColor" content="' . $hexCode . '" />
  <meta name="msapplication-tap-highlight" content="no" />
  <meta name="description" content="' . $description . '" />
  <meta name="og:sitename" property="og:sitename" content="' . SanitizeContent::escapeHTMLAttribute($this->manifest->title) . '" />
  <meta name="og:title" property="og:title" content="' . $title . '" />
  <meta name="og:type" property="og:type" content="article" />
  <meta name="og:url" property="og:url" content="' . $safeDomain . '" />
  <meta name="og:description" property="og:description" content="' . $description . '" />
  <meta name="og:image" property="og:image" content="' . $safeSocialImage . '" />
  <meta name="twitter:card" property="twitter:card" content="summary_large_image" />
  <meta name="twitter:site" property="twitter:site" content="' . $safeDomain . '" />
  <meta name="twitter:title" property="twitter:title" content="' . $title . '" />
  <meta name="twitter:description" property="twitter:description" content="' . $description . '" />
  <meta name="twitter:image" property="twitter:image" content="' . $safeSocialImage . '" />';
      $inLanguage = 'en-US';
      if (
        isset($this->manifest->metadata->site->settings->lang) &&
        trim((string) $this->manifest->metadata->site->settings->lang) != ''
      ) {
        $inLanguage = trim((string) $this->manifest->metadata->site->settings->lang);
      }
      $joinStructuredDataUrl = function ($baseValue = '', $segmentValue = '') {
        $normalizedBase = (string) $baseValue;
        $normalizedSegment = (string) $segmentValue;
        if ($normalizedSegment == '') {
          return $normalizedBase;
        }
        if (preg_match('/^https?:\\/\\//i', $normalizedSegment)) {
          return $normalizedSegment;
        }
        if ($normalizedBase == '') {
          return $normalizedSegment;
        }
        return rtrim($normalizedBase, '/') . '/' . ltrim($normalizedSegment, '/');
      };
      $toAbsoluteStructuredDataUrl = function ($value = '') use ($siteUrlForStructuredData, $joinStructuredDataUrl) {
        $sanitizedValue = SanitizeContent::sanitizeURLValue($value, '');
        if ($sanitizedValue == '') {
          return '';
        }
        if (preg_match('/^https?:\\/\\//i', $sanitizedValue)) {
          return $sanitizedValue;
        }
        if ($siteUrlForStructuredData != '') {
          return SanitizeContent::sanitizeURLValue($joinStructuredDataUrl($siteUrlForStructuredData, $sanitizedValue), '');
        }
        return $sanitizedValue;
      };
      $socialImageForStructuredData = $toAbsoluteStructuredDataUrl($this->getSocialShareImage($page));
      $siteLogoForStructuredData = $toAbsoluteStructuredDataUrl($this->getLogoSize('512', '512'));
      $pageUpdatedISO = '';
      if (isset($page->metadata) && isset($page->metadata->updated) && is_numeric($page->metadata->updated)) {
        $updatedTimestamp = intval($page->metadata->updated);
        if ($updatedTimestamp > 0) {
          $pageUpdatedISO = gmdate('c', $updatedTimestamp);
        }
      }
      $pageCreatedISO = '';
      if (isset($page->metadata) && isset($page->metadata->created) && is_numeric($page->metadata->created)) {
        $createdTimestamp = intval($page->metadata->created);
        if ($createdTimestamp > 0) {
          $pageCreatedISO = gmdate('c', $createdTimestamp);
        }
      }
      $authorName = '';
      if (isset($this->manifest->metadata->author->name) && trim((string) $this->manifest->metadata->author->name) != '') {
        $authorName = trim((string) $this->manifest->metadata->author->name);
      }
      else if (isset($this->manifest->author) && is_string($this->manifest->author) && trim((string) $this->manifest->author) != '') {
        $authorName = trim((string) $this->manifest->author);
      }
      else if (isset($this->manifest->author) && is_object($this->manifest->author) && isset($this->manifest->author->name) && trim((string) $this->manifest->author->name) != '') {
        $authorName = trim((string) $this->manifest->author->name);
      }
      $authorEmail = '';
      if (isset($this->manifest->metadata->author->email) && trim((string) $this->manifest->metadata->author->email) != '') {
        $authorEmail = trim((string) $this->manifest->metadata->author->email);
      }
      else if (isset($this->manifest->author) && is_object($this->manifest->author) && isset($this->manifest->author->email) && trim((string) $this->manifest->author->email) != '') {
        $authorEmail = trim((string) $this->manifest->author->email);
      }
      $authorSocialLink = '';
      if (isset($this->manifest->metadata->author->socialLink) && trim((string) $this->manifest->metadata->author->socialLink) != '') {
        $authorSocialLink = SanitizeContent::sanitizeURLValue(trim((string) $this->manifest->metadata->author->socialLink), '');
      }
      else if (isset($this->manifest->author) && is_object($this->manifest->author) && isset($this->manifest->author->socialLink) && trim((string) $this->manifest->author->socialLink) != '') {
        $authorSocialLink = SanitizeContent::sanitizeURLValue(trim((string) $this->manifest->author->socialLink), '');
      }
      $authorImageForStructuredData = '';
      if (isset($this->manifest->metadata->author->image) && trim((string) $this->manifest->metadata->author->image) != '') {
        $authorImageForStructuredData = $toAbsoluteStructuredDataUrl(trim((string) $this->manifest->metadata->author->image));
      }
      else if (isset($this->manifest->author) && is_object($this->manifest->author) && isset($this->manifest->author->image) && trim((string) $this->manifest->author->image) != '') {
        $authorImageForStructuredData = $toAbsoluteStructuredDataUrl(trim((string) $this->manifest->author->image));
      }
      $breadcrumbTrail = array();
      if (isset($page->id) && $page->id && isset($this->manifest->items) && is_array($this->manifest->items)) {
        $itemBuilder = $page;
        if ((!isset($itemBuilder->title) || trim((string) $itemBuilder->title) == '') && method_exists($this->manifest, 'getItemKeyById')) {
          $activeItemIndex = $this->manifest->getItemKeyById($page->id);
          if ($activeItemIndex !== FALSE && isset($this->manifest->items[$activeItemIndex])) {
            $itemBuilder = $this->manifest->items[$activeItemIndex];
          }
        }
        $safetyCounter = 0;
        while ($itemBuilder && $safetyCounter < 200) {
          array_unshift($breadcrumbTrail, $itemBuilder);
          if (!isset($itemBuilder->parent) || is_null($itemBuilder->parent)) {
            break;
          }
          $parentItem = null;
          foreach ($this->manifest->items as $manifestItem) {
            if (isset($manifestItem->id) && $manifestItem->id == $itemBuilder->parent) {
              $parentItem = $manifestItem;
              break;
            }
          }
          $itemBuilder = $parentItem;
          $safetyCounter++;
        }
      }
      else if (isset($page->title) || isset($page->slug)) {
        $breadcrumbTrail[] = $page;
      }
      $breadcrumbListElements = array();
      $breadcrumbPosition = 1;
      if ($siteUrlForStructuredData != '') {
        $breadcrumbListElements[] = array(
          '@type' => 'ListItem',
          'position' => $breadcrumbPosition,
          'name' => isset($this->manifest->title) ? (string) $this->manifest->title : 'Home',
          'item' => $siteUrlForStructuredData,
        );
        $breadcrumbPosition++;
      }
      foreach ($breadcrumbTrail as $index => $breadcrumbItem) {
        $breadcrumbName = '';
        if (isset($breadcrumbItem->title) && trim((string) $breadcrumbItem->title) != '') {
          $breadcrumbName = trim((string) $breadcrumbItem->title);
        }
        else if (isset($breadcrumbItem->slug) && trim((string) $breadcrumbItem->slug) != '') {
          $breadcrumbName = trim((string) $breadcrumbItem->slug);
        }
        else {
          $breadcrumbName = $rawTitle;
        }
        $breadcrumbUrl = '';
        if (isset($breadcrumbItem->slug) && trim((string) $breadcrumbItem->slug) != '') {
          $breadcrumbUrl = $toAbsoluteStructuredDataUrl($breadcrumbItem->slug);
        }
        if ($breadcrumbUrl == '' && $index === (count($breadcrumbTrail) - 1)) {
          $breadcrumbUrl = $pageUrlForStructuredData;
        }
        if ($breadcrumbUrl == '') {
          $breadcrumbUrl = $siteUrlForStructuredData;
        }
        if ($breadcrumbPosition === 2 && $siteUrlForStructuredData != '' && $breadcrumbUrl == $siteUrlForStructuredData) {
          continue;
        }
        if ($breadcrumbUrl == '') {
          continue;
        }
        $breadcrumbListElements[] = array(
          '@type' => 'ListItem',
          'position' => $breadcrumbPosition,
          'name' => $breadcrumbName,
          'item' => $breadcrumbUrl,
        );
        $breadcrumbPosition++;
      }
      $structuredDataBaseUrl = $siteUrlForStructuredData != '' ? $siteUrlForStructuredData : $pageUrlForStructuredData;
      $siteStructuredDataId = rtrim((string) $siteUrlForStructuredData, '/') . '#website';
      $pageStructuredDataId = rtrim((string) $pageUrlForStructuredData, '/') . '#webpage';
      $breadcrumbStructuredDataId = rtrim((string) $pageUrlForStructuredData, '/') . '#breadcrumb';
      $authorStructuredDataId = rtrim((string) $structuredDataBaseUrl, '/') . '#author';
      $publisherStructuredDataId = rtrim((string) $structuredDataBaseUrl, '/') . '#publisher';
      $jsonLdGraph = array();
      $authorNode = null;
      $publisherNode = null;
      if ($authorName != '' || $authorEmail != '' || $authorSocialLink != '' || $authorImageForStructuredData != '') {
        $authorNode = array(
          '@type' => 'Person',
          '@id' => $authorStructuredDataId,
        );
        if ($authorName != '') {
          $authorNode['name'] = $authorName;
        }
        if ($authorEmail != '') {
          $authorNode['email'] = $authorEmail;
        }
        if ($authorSocialLink != '') {
          $authorNode['sameAs'] = array($authorSocialLink);
        }
        if ($authorImageForStructuredData != '') {
          $authorNode['image'] = array(
            '@type' => 'ImageObject',
            'url' => $authorImageForStructuredData,
          );
        }
        $jsonLdGraph[] = $authorNode;
      }
      if ($siteUrlForStructuredData != '') {
        $publisherNode = array(
          '@type' => 'Organization',
          '@id' => $publisherStructuredDataId,
          'url' => $siteUrlForStructuredData,
          'name' => isset($this->manifest->title) ? (string) $this->manifest->title : $rawTitle,
        );
        if ($siteLogoForStructuredData != '') {
          $publisherNode['logo'] = array(
            '@type' => 'ImageObject',
            'url' => $siteLogoForStructuredData,
          );
        }
        if ($authorNode !== null && isset($authorNode['@id'])) {
          $publisherNode['founder'] = array(
            '@id' => $authorNode['@id'],
          );
        }
        $jsonLdGraph[] = $publisherNode;
      }
      if ($siteUrlForStructuredData != '') {
        $webSiteNode = array(
          '@type' => 'WebSite',
          '@id' => $siteStructuredDataId,
          'url' => $siteUrlForStructuredData,
          'name' => isset($this->manifest->title) ? (string) $this->manifest->title : $rawTitle,
          'inLanguage' => $inLanguage,
        );
        $searchTargetUrl = SanitizeContent::sanitizeURLValue(
          rtrim((string) $siteUrlForStructuredData, '/') . '/x/search?search={search_term_string}',
          ''
        );
        if ($searchTargetUrl != '') {
          $webSiteNode['potentialAction'] = array(
            '@type' => 'SearchAction',
            'target' => array(
              '@type' => 'EntryPoint',
              'urlTemplate' => $searchTargetUrl,
            ),
            'query-input' => 'required name=search_term_string',
          );
        }
        if ($publisherNode !== null && isset($publisherNode['@id'])) {
          $webSiteNode['publisher'] = array(
            '@id' => $publisherNode['@id'],
          );
        }
        if ($authorNode !== null && isset($authorNode['@id'])) {
          $webSiteNode['author'] = array(
            '@id' => $authorNode['@id'],
          );
        }
        if ($siteLogoForStructuredData != '') {
          $webSiteNode['image'] = array(
            '@type' => 'ImageObject',
            'url' => $siteLogoForStructuredData,
          );
        }
        $jsonLdGraph[] = $webSiteNode;
      }
      if (count($breadcrumbListElements) > 0 && $pageUrlForStructuredData != '') {
        $jsonLdGraph[] = array(
          '@type' => 'BreadcrumbList',
          '@id' => $breadcrumbStructuredDataId,
          'itemListElement' => $breadcrumbListElements,
        );
      }
      if ($pageUrlForStructuredData != '') {
        $webPageNode = array(
          '@type' => 'WebPage',
          '@id' => $pageStructuredDataId,
          'url' => $pageUrlForStructuredData,
          'name' => $rawTitle,
          'description' => $rawDescription,
          'inLanguage' => $inLanguage,
        );
        if ($siteUrlForStructuredData != '') {
          $webPageNode['isPartOf'] = array(
            '@id' => $siteStructuredDataId,
          );
        }
        if ($socialImageForStructuredData != '') {
          $webPageNode['primaryImageOfPage'] = array(
            '@type' => 'ImageObject',
            'url' => $socialImageForStructuredData,
          );
          $webPageNode['image'] = $socialImageForStructuredData;
        }
        if ($pageCreatedISO != '') {
          $webPageNode['datePublished'] = $pageCreatedISO;
        }
        if ($pageUpdatedISO != '') {
          $webPageNode['dateModified'] = $pageUpdatedISO;
        }
        if ($authorNode !== null && isset($authorNode['@id'])) {
          $webPageNode['author'] = array(
            '@id' => $authorNode['@id'],
          );
        }
        if ($publisherNode !== null && isset($publisherNode['@id'])) {
          $webPageNode['publisher'] = array(
            '@id' => $publisherNode['@id'],
          );
        }
        if (count($breadcrumbListElements) > 0) {
          $webPageNode['breadcrumb'] = array(
            '@id' => $breadcrumbStructuredDataId,
          );
        }
        if (isset($page->id) && $page->id) {
          $webPageNode['identifier'] = (string) $page->id;
        }
        $jsonLdGraph[] = $webPageNode;
      }
      if (count($jsonLdGraph) > 0) {
        $jsonLdData = array(
          '@context' => 'https://schema.org',
          '@graph' => $jsonLdGraph,
        );
        $jsonLdString = json_encode($jsonLdData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($jsonLdString !== FALSE) {
          $jsonLdString = str_replace('<', '\\u003c', $jsonLdString);
          $metadata .= "\n  <script type=\"application/ld+json\">" . $jsonLdString . "</script>";
        }
      }
      $licenseData = $this->getLicenseData('all');
      if (isset($this->manifest->license) && isset($licenseData[$this->manifest->license])) {
        $metadata .= "\n" . '  <meta rel="cc:license" href="' . SanitizeContent::escapeHTMLAttribute(SanitizeContent::sanitizeURLValue($licenseData[$this->manifest->license]['link'], '')) . '" content="License: ' . SanitizeContent::escapeHTMLAttribute($licenseData[$this->manifest->license]['name']) . '"/>' . "\n";
      }
      if (isset($this->manifest->metadata->author->socialLink) && (strpos($this->manifest->metadata->author->socialLink, 'https://twitter.com/') === 0 || strpos($this->manifest->metadata->author->socialLink, 'https://x.com/') === 0)) {
        $socialLink = str_replace('https://twitter.com/', '@', $this->manifest->metadata->author->socialLink);
        $socialLink = str_replace('https://x.com/', '@', $socialLink);
        $metadata .= '  <meta name="twitter:creator" content="' . SanitizeContent::escapeHTMLAttribute($socialLink) . '" />';
      }
      $GLOBALS['HAXCMS']->dispatchEvent('haxcms-site-metadata', $metadata);
      return $metadata;
    }
    /**
     * Load a node based on a path
     * @var $path the path to try loading based on or search for the active from address
     * @return new JSONOutlineSchemaItem() a blank JOS item
     */
    public function loadNodeByLocation($path = NULL) {
        $this->lastPathLookupMiss = false;
        $opPath = '';
        // load from the active address if we have one
        if (is_null($path)) {
          $opPath = str_replace($GLOBALS['HAXCMS']->basePath . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $this->manifest->metadata->site->name . '/', '', $GLOBALS['HAXCMS']->request_uri());
          if (getenv('HAXSITE_BASE_URL')) {
            $opPath = str_replace(getenv('HAXSITE_BASE_URL'), '', $opPath);
          }
          $path = $opPath;
        } else {
          $opPath = trim($path, '/');
        }
        $path .= "/index.html";
        // failsafe in case someone had closing /
        $path = 'pages/' . str_replace('//', '/', $path);
        foreach ($this->manifest->items as $item) {
            if ($item->location == $path || $item->slug == $opPath) {
                $this->lastPathLookupMiss = false;
                return $item;
            }
        }
       $this->lastPathLookupMiss = true;
       return new JSONOutlineSchemaItem();
    }
    /**
     * Generate or load the path to variations on the logo
     * @var string $height height of the icon as a string
     * @var string $width width of the icon as a string
     * @var string $format (optional) png or jpeg format to return image as
     * @return string path to the image (web visible) that was created or pulled together
     */
    public function getLogoSize($height, $width, $format = "png") {
      $fileName = &$GLOBALS['HAXCMS']->staticCache(__FUNCTION__ . $height . $width);
      if (!isset($fileName)) {
        // if no logo, just bail with an easy standard one
        if (!isset($this->manifest->metadata->site->logo) || (isset($this->manifest->metadata->site) && ($this->manifest->metadata->site->logo == '' || $this->manifest->metadata->site->logo == null || $this->manifest->metadata->site->logo == "null"))) {
            $fileName = 'assets/icon-' . $height . 'x' . $width . '.png';
        }
        else {
          // ensure this path exists otherwise let's create it on the fly
          $path = HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $this->manifest->metadata->site->name . '/';
          // support for default so we compress it using same engine
          if ($this->manifest->metadata->site->logo == 'assets/banner.jpg') {
            $fileName = str_replace('assets/', 'files/haxcms-managed/' . $height . 'x' . $width . '-', $this->manifest->metadata->site->logo);
          }
          else {
            $fileName = str_replace('files/', 'files/haxcms-managed/' . $height . 'x' . $width . '-', $this->manifest->metadata->site->logo);
          }
          // always replace this terrible name
          $fileName = str_replace('.jpeg', '.jpg', $fileName);
          if ($format == "jpg") {
            $fileName = str_replace('.png', '.jpg', $fileName);
          }
          else {
            $fileName = str_replace('.jpg', '.png', $fileName);
          }
          if (file_exists($path . $this->manifest->metadata->site->logo) && !file_exists($path . $fileName)) {
            global $fileSystem;
            $fileSystem->mkdir($path . 'files/haxcms-managed');
            $image = new ImageResize($path . $this->manifest->metadata->site->logo);
            if ($format == "png") {
              $image->resizeToBestFit($height, $width)
              ->crop($height, $width, TRUE)
              ->save($path . $fileName, IMAGETYPE_PNG, 9); // 9 is max compression on images
            }
            else if ($format == "jpg") {
              $image->resizeToBestFit($height, $width)
              ->crop($height, $width, TRUE)
              ->save($path . $fileName, IMAGETYPE_JPEG, 70); // jpeg compression
            }
          }
        }
      }
      return filter_var($fileName, FILTER_SANITIZE_URL);
    }
    /**
     * License data for common open license
     */
    public function getLicenseData($type = 'select')
    {
        $list = array(
            "by" => array(
                'name' => "Creative Commons: Attribution",
                'link' => "https://creativecommons.org/licenses/by/4.0/",
                'image' => "https://i.creativecommons.org/l/by/4.0/88x31.png"
            ),
            "by-sa" => array(
                'name' => "Creative Commons: Attribution Share a like",
                'link' => "https://creativecommons.org/licenses/by-sa/4.0/",
                'image' => "https://i.creativecommons.org/l/by-sa/4.0/88x31.png"
            ),
            "by-nd" => array(
                'name' => "Creative Commons: Attribution No derivatives",
                'link' => "https://creativecommons.org/licenses/by-nd/4.0/",
                'image' => "https://i.creativecommons.org/l/by-nd/4.0/88x31.png"
            ),
            "by-nc" => array(
                'name' => "Creative Commons: Attribution non-commercial",
                'link' => "https://creativecommons.org/licenses/by-nc/4.0/",
                'image' => "https://i.creativecommons.org/l/by-nc/4.0/88x31.png"
            ),
            "by-nc-sa" => array(
                'name' =>
                    "Creative Commons: Attribution non-commercial share a like",
                'link' => "https://creativecommons.org/licenses/by-nc-sa/4.0/",
                'image' =>
                    "https://i.creativecommons.org/l/by-nc-sa/4.0/88x31.png"
            ),
            "by-nc-nd" => array(
                'name' =>
                    "Creative Commons: Attribution Non-commercial No derivatives",
                'link' => "https://creativecommons.org/licenses/by-nc-nd/4.0/",
                'image' =>
                    "https://i.creativecommons.org/l/by-nc-nd/4.0/88x31.png"
            )
        );
        $data = array();
        if ($type == 'select') {
            foreach ($list as $key => $item) {
                $data[$key] = $item['name'];
            }
        }
        else {
            $data = $list;
        }
        return $data;
    }
    /**
     * Update page in the manifest list of items. useful if updating some
     * data about an existing entry.
     * @return JSONOutlineSchemaItem or FALSE
     */
    public function updateNode($page)
    {
        foreach ($this->manifest->items as $key => $item) {
            if ($item->id === $page->id) {
                $this->manifest->items[$key] = $page;
                $this->manifest->save(false);
                $this->updateAlternateFormats();
                return $page;
            }
        }
        return false;
    }
    /**
     * Delete a page from the manifest
     * @return JSONOutlineSchemaItem or FALSE
     */
    public function deleteNode($page)
    {
        foreach ($this->manifest->items as $key => $item) {
            if ($item->id === $page->id) {
                unset($this->manifest->items[$key]);
                $this->manifest->save(false);
                $this->updateAlternateFormats();
                return true;
            }
        }
        return false;
    }
    /**
     * Change the directory this site is located in
     */
    public function changeName($new)
    {
        $new = str_replace('./', '', str_replace('../', '', $new));
        // attempt to shift it on the file system
        if ($new != $this->manifest->metadata->site->name) {
            $this->manifest->metadata->site->name = $new;
            return @rename($this->manifest->metadata->site->name, $new);
        }
    }
    /**
     * Test and ensure the name being returned is a slug currently unused
     */
    public function getUniqueSlugName($slug, $page = null, $pathAuto = false)
    {
      $rSlug = $slug;
      // check for pathauto setting and this having a parent
      if ($page != null && $page->parent != null && $page->parent != '' && $pathAuto) {
        $item = $page;
        $pieces = array($slug);
        while ($item = $this->manifest->getItemById($item->parent)) {
            $tmp = explode('/', $item->slug);
            array_unshift($pieces, array_pop($tmp));
        }
        $slug = implode('/', $pieces);
        $rSlug = $slug;
      }
      // trap for a / as 1st character if we had empty pieces
      while (substr($rSlug, 0, 1) == "/") {
        $rSlug = substr($rSlug, 1);
      }
      $loop = 0;
      $ready = false;
      // while not ready, keep checking
      while (!$ready) {
        $ready = true;
        // loop through items
        foreach ($this->manifest->items as $key => $item) {
          // if our slug matches an existing
          if ($rSlug == $item->slug) {
            // if we have a page, and it matches that, bail out cause we have it already
            if ($page != null && $item->id == $page->id) {
              return $rSlug;
            }
            else {
              // increment the number
              $loop++;
              // append to the new slug
              $rSlug = $slug . '-' . $loop;
              // force a new test
              $ready = false;
            }
          }
        }
      }
      return $rSlug;
    }
    /**
     * Recursive copy to rename high level but copy all files
     */
    public function recurseCopy($src, $dst, $skip = array())
    {
        $dir = opendir($src);
        // see if we can make the directory to start off
        if (!is_dir($dst) && array_search($dst, $skip) === FALSE && @mkdir($dst, 0755, true)) {
            while (false !== ($file = readdir($dir))) {
                if ($file != '.' && $file != '..') {
                    if (is_dir($src . '/' . $file) && array_search($file, $skip) === FALSE) {
                        $this->recurseCopy(
                            $src . '/' . $file,
                            $dst . '/' . $file
                        );
                    } else {
                        copy($src . '/' . $file, $dst . '/' . $file);
                    }
                }
            }
        } else {
            return false;
        }
        closedir($dir);
    }
}