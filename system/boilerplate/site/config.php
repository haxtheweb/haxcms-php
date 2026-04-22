<?php
/**
 * This includes a fallback mechanism for deciphering where the root of the site
 * is located. If placed in a HAXcms deployment (assumed multi-site) then
 * it first looks for this location 2 levels below the site.
 * 
 * HAXiam (SaaS HAXcms) also has this structure and will discover where it is
 * and bootstrap appropriately. To pull in all local (HAXcms) and global (HAXiam)
 * configuration so that the user is appropriately delivered the site AND potential
 * authoring experience via wiring required to do so in AppStore specification.
 * 
 * In the event we are on a PHP based host that DOES NOT have HAXcms we are
 * running as a "single site" context. In this mode the HAXsite needs to be able to
 * bootstrap appropriately but with a dramatically trimmed down version of the
 * platform as they cannot log into just a single site for authoring. This
 * dection file accounts for HAXiam, HAXcms, Docker methods as well as the
 * provided single file fall back to operate stand alone.
 * 
 * When not in "single site" context, HAXcms/HAXiam will supply the HAXSiteConfig
 * global variables and classed object. When in Single Site, this classed
 * object can be found below. Modification of this is not recommended however,
 * everything required to get a HAXsite to bootstrap is included in this file
 * and can be modified in "single site" context to do whatever you desire to
 * customize the site's output along with modification of index.php for where
 * things are rendered.
 */
$bootstrapPath = "/../..";
// see if we are in docker, tweak path
if (file_exists("/.dockerenv") && __DIR__ == "/var/www/html") {
  $GLOBALS["HAXcmsInDocker"] = true;
  $bootstrapPath = "";
}
// look for a local / larger copy of HAXcms in multi-site mode
if (file_exists(__DIR__ . $bootstrapPath . '/system/backend/php/bootstrapHAX.php')) {
  include_once __DIR__ . $bootstrapPath . '/system/backend/php/bootstrapHAX.php';
  include_once __DIR__ . $bootstrapPath . '/system/backend/php/lib/HAXSiteConfig.php';
  include_once $GLOBALS['HAXCMS']->configDirectory . '/config.php';
  $site = $HAXCMS->loadSite(basename(__DIR__));
  $page = $site->loadNodeByLocation();
  $color = 'var(' . $site->manifest->metadata->theme->variables->cssVariable . ', #FF2222)';
  $HAXSiteConfig = new HAXSiteConfig($site);
}
// local slim config loader off of site.json
// Without a global HAXcms instance this implies the above did not autoload one
// and that we are operating in HAXsite "single site" context and must supply
// all our configuration below to power the page.
// this only runs if broken off from a larger deploy that hosts a PHP env
if (!isset($GLOBALS['HAXCMS'])) {
  /**
   * A slim version of HAXcms that is just config driven off site.json
   */
  define("HAXCMS_FALLBACK_HEX", "#3f51b5");
  class HAXSiteConfig {
    public function __construct($basePath = NULL, $location = "./site.json") {
      $this->basePath = $basePath;
      if ($this->basePath === NULL) {
        $this->basePath = $this->request_uri();
      }
      $this->cdn = './';
      $this->developerMode = FALSE;
      $this->developerModeAdminOnly = FALSE;
      $this->sitesDirectory = 'sites';
      $this->color = "blue";
      $this->manifest = new stdClass();
      $this->manifest->items = array();
      // ensure we have site.json to load config from
      if (file_exists($location)) {
        $this->file = $location;
        $fileData = json_decode(file_get_contents($location));
        $vars = get_object_vars($fileData);
        foreach ($vars as $key => $var) {
            if ($key != 'items') {
                $this->manifest->{$key} = $var;
            }
        }
        // also ensures data matches only what is supported
        if (isset($vars['items'])) {
            foreach ($vars['items'] as $key => $item) {
                $newItem = new stdClass();
                $newItem->id = $item->id;
                $newItem->indent = $item->indent;
                $newItem->location = $item->location;
                $newItem->slug = (isset($item->slug) ? $item->slug : str_replace('pages/', '', str_replace('/index.html','', $item->location)));
                $newItem->order = $item->order;
                $newItem->parent = $item->parent;
                $newItem->title = $item->title;
                $newItem->description = $item->description;
                // metadata can be anything so whatever
                $newItem->metadata = $item->metadata;
                $this->manifest->items[$key] = $newItem;
            }
        }
        $this->page = $this->loadNodeByLocation();
        if (isset($this->manifest->metadata->theme->variables->cssVariable)) {
          $this->color = 'var(' . $this->manifest->metadata->theme->variables->cssVariable . ', #FF2222)';
        }
        $this->name = $this->manifest->metadata->site->name;
      }
    }
    /**
     * Return the base tag accurately which helps with the PWA / SW side of things
     * @return string HTML blob for hte <base> tag
     */
    public function getBaseTag() {
      return '<base href="' . $this->basePath . '" />';
    }
    public function cacheBusterHash() {
      return '?';
    }
    /**
     * Return attributes for the site
     * @todo make this mirror the drupal get attributes method
     * @return string eventually, array of data keyed by type of information
     */
    public function getSitePageAttributes() {
      return 'vocab="http://schema.org/" prefix="oer:http://oerschema.org cc:http://creativecommons.org/ns dc:http://purl.org/dc/terms/"';
    }
    /**
     * Language of the site
     */
    public function getLanguage() {
      if (isset($this->manifest->metadata->site->settings->lang) && $this->manifest->metadata->site->settings->lang != "" && $this->manifest->metadata->site->settings->lang != null) {
        return $this->manifest->metadata->site->settings->lang;
      }
      return "en-US";
    }

    /**
     * Return the active URI if it exists
     */
    public function getURI() {
      if (isset($_SERVER['SCRIPT_URI'])) {
        return $_SERVER['SCRIPT_URI'];
      }
    }
    /**
     * Return the active domain if it exists
     */
    public function getDomain() {
      if (isset($_SERVER['SERVER_NAME'])) {
        return 'https://' . $_SERVER['SERVER_NAME'];
      }
    }
    /**
     * Return accurate, rendered site metadata
     * @return string an html chunk of tags for the head section
     * @todo move this to a render function / section / engine
     */
    public function getSiteMetadata($page = NULL, $domain = NULL, $cdn = '') {
      $preloadTags = array();
      $content = $this->getPageContent($page);
      preg_match_all("/<(?:\"-[^\"]*\"['\"]*|'[^']*'['\"]*|[^'\">])+>/", $content, $matches);
      foreach ($matches[0] as $match) {
        if (strpos($match, '-')) {
          $tag = str_replace('>', '', str_replace('</', '', $match));
          $preloadTags[$tag] = $tag;
        }
      }
      // domain's need to inject their own full path for OG metadata (which is edge case)
      // most of the time this is the actual usecase so use the active path
      if (is_null($domain)) {
        $domain = $this->getURI();
      }
      // support preconnecting CDNs, sets us up for dynamic CDN switching too
      $preconnect = '';
      $base = './';
      if ($cdn == '' && $this->cdn != './') {
        $cdn = $this->cdn;
      }
      if ($cdn != '') {
        // preconnect for faster DNS lookup
        $preconnect = '<link rel="preconnect" crossorigin href="' . $cdn . '">';
        // base is preload for the calls below
        $base = $cdn;
      }
      $contentPreload = '';
      $wcMap = $this->getWCRegistryJson($base);
      foreach ($preloadTags as $tag) {
        // means the tag is known in our registry
        if (isset($wcMap->{$tag})) {
          $contentPreload .= '
          <link rel="preload" href="' . $base . 'build/es6/node_modules/' . $wcMap->{$tag} . '" as="script" crossorigin="anonymous" />
          <link rel="modulepreload" href="' . $base . 'build/es6/node_modules/' . $wcMap->{$tag} . '" />';
        }
      }
      $title = filter_var($page->title, FILTER_SANITIZE_STRING);
      $siteTitle = filter_var($this->manifest->title, FILTER_SANITIZE_STRING) . ' | ' . filter_var($page->title, FILTER_SANITIZE_STRING);
      $description = filter_var($page->description, FILTER_SANITIZE_STRING);;
      $hexCode = HAXCMS_FALLBACK_HEX;
      $themePreload = '';
      // sanity check, then preload the theme
      if (isset($this->manifest->metadata->theme->path)) {
        $themePreload = '  <link rel="preload" href="' . $base . 'build/es6/node_modules/' . str_replace("@lrnwebcomponents/", "@haxtheweb/", $this->manifest->metadata->theme->path) . '" as="script" crossorigin="anonymous" />
          <link rel="modulepreload" href="' . $base . 'build/es6/node_modules/' . str_replace("@lrnwebcomponents/", "@haxtheweb/", $this->manifest->metadata->theme->path) . '" />';
      }
      if ($description == '') {
        $description = filter_var($this->manifest->description, FILTER_SANITIZE_STRING);
      }
      if ($title == '' || $title == 'New item') {
        $title = filter_var($this->manifest->title, FILTER_SANITIZE_STRING);
        $siteTitle = $title;
      }
      if (isset($this->manifest->metadata->theme->variables->hexCode)) {
          $hexCode = filter_var($this->manifest->metadata->theme->variables->hexCode, FILTER_SANITIZE_STRING);
      }
      // if we have a privacy flag, then tell robots not to index this were it to be found
      // which in HAXiam this isn't possible
      if (isset($this->manifest->metadata->site->settings->private) && $this->manifest->metadata->site->settings->private) {
        $robots = '<meta name="robots" content="none" />';
      }
      else {
        $robots = '<meta name="robots" content="index, follow" />';
      }
      // canonical flag, if set we use the domain field
      if (isset($this->manifest->metadata->site->settings->canonical) && $this->manifest->metadata->site->settings->canonical) {
        if (isset($this->manifest->metadata->site->domain) && $this->manifest->metadata->site->domain != '') {
          $canonical = '  <link name="canonical" href="' . filter_var($this->manifest->metadata->site->domain . '/' . $page->slug, FILTER_SANITIZE_URL) . '" />' . "\n";
        }
        else {
          $canonical = '  <link name="canonical" href="' . filter_var($domain, FILTER_SANITIZE_URL). '" />' . "\n";
        }
      }
      else {
        $canonical = '';
      }
      $prevResource = '';
      $nextResource = '';
      // if we have a place in the array bc it's a page, then we can get next / prev
      if ($page->id && $this->manifest->getItemKeyById($page->id) !== FALSE) {
        $currentId = $this->manifest->getItemKeyById($page->id);
        if ($currentId > 0 && isset($this->manifest->items[$currentId-1]->slug)) {
          $prevResource = '  <link rel="prev" href="' . $this->manifest->items[$currentId-1]->slug . '" />' . "\n";
        }
        if ($currentId < count($this->manifest->items)-1 && isset($this->manifest->items[$currentId+1]->slug)) {
          $nextResource = '  <link rel="next" href="' . $this->manifest->items[$currentId+1]->slug . '" />' . "\n";
        }
      }
      $metadata = '
          <meta charset="utf-8">' . $preconnect . '
          <link rel="preconnect" crossorigin href="https://fonts.googleapis.com">
          <link rel="preconnect" crossorigin href="https://cdnjs.cloudflare.com">
          <link rel="preload" href="' . $base . 'build.js" as="script" />
          <link rel="preload" href="' . $base . 'build-haxcms.js" as="script" />
          <link rel="preload" href="' . $base . 'wc-registry.json" as="fetch" crossorigin="anonymous" fetchpriority="high" />
          <link rel="preload" href="' . $base . 'build/es6/node_modules/@haxtheweb/dynamic-import-registry/dynamic-import-registry.js" as="script" crossorigin="anonymous" />
          <link rel="modulepreload" href="' . $base . 'build/es6/node_modules/@haxtheweb/dynamic-import-registry/dynamic-import-registry.js" />
          <link rel="preload" href="' . $base . 'build/es6/node_modules/@haxtheweb/wc-autoload/wc-autoload.js" as="script" crossorigin="anonymous" />
          <link rel="modulepreload" href="' . $base . 'build/es6/node_modules/@haxtheweb/wc-autoload/wc-autoload.js" />
        ' . $themePreload . $contentPreload . '
          <link rel="preload" href="' . $base . 'build/es6/node_modules/@haxtheweb/haxcms-elements/lib/base.css" as="style" />
          <meta name="generator" content="HAXcms" />
        ' . $canonical . $prevResource . $nextResource . '  <link rel="manifest" href="manifest.json" />
          <meta name="viewport" content="width=device-width, minimum-scale=1, initial-scale=1, user-scalable=yes" />
          <title>' . $siteTitle . '</title>
          <link rel="icon" href="' . $this->getLogoSize('16', '16') . '" />
          <meta name="theme-color" content="' . $hexCode . '" />
          ' . $robots . '
          <meta name="mobile-web-app-capable" content="yes" />
          <meta name="application-name" content="' . $title . '" />
          <meta name="apple-mobile-web-app-capable" content="yes" />
          <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
          <meta name="apple-mobile-web-app-title" content="' . $title . '" />
          <link rel="apple-touch-icon" sizes="48x48" href="' . $this->getLogoSize('48', '48') . '" />
          <link rel="apple-touch-icon" sizes="72x72" href="' . $this->getLogoSize('72', '72') . '" />
          <link rel="apple-touch-icon" sizes="96x96" href="' . $this->getLogoSize('96', '96') . '" />
          <link rel="apple-touch-icon" sizes="144x144" href="' . $this->getLogoSize('144', '144') . '" />
          <link rel="apple-touch-icon" sizes="192x192" href="' . $this->getLogoSize('192', '192') . '" />
          <meta name="msapplication-TileImage" content="' . $this->getLogoSize('144', '144') . '" />
          <meta name="msapplication-TileColor" content="' . $hexCode . '" />
          <meta name="msapplication-tap-highlight" content="no" />
          <meta name="description" content="' . $description . '" />
          <meta name="og:sitename" property="og:sitename" content="' . filter_var($this->manifest->title, FILTER_SANITIZE_STRING) . '" />
          <meta name="og:title" property="og:title" content="' . $title . '" />
          <meta name="og:type" property="og:type" content="article" />
          <meta name="og:url" property="og:url" content="' . filter_var($domain, FILTER_SANITIZE_URL) . '" />
          <meta name="og:description" property="og:description" content="' . $description . '" />
          <meta name="og:image" property="og:image" content="' . $this->getSocialShareImage($page) . '" />
          <meta name="twitter:card" property="twitter:card" content="summary_large_image" />
          <meta name="twitter:site" property="twitter:site" content="' . filter_var($domain, FILTER_SANITIZE_URL) . '" />
          <meta name="twitter:title" property="twitter:title" content="' . $title . '" />
          <meta name="twitter:description" property="twitter:description" content="' . $description . '" />
          <meta name="twitter:image" property="twitter:image" content="' . $this->getSocialShareImage($page) . '" />';  
      // mix in license metadata if we have it
      $licenseData = $this->getLicenseData('all');
      if (isset($this->manifest->license) && isset($licenseData[$this->manifest->license])) {
          $metadata .= "\n" . '  <meta rel="cc:license" href="' . $licenseData[$this->manifest->license]['link'] . '" content="License: ' . $licenseData[$this->manifest->license]['name'] . '"/>' . "\n";
      }
      // add in twitter link if they provided one
      if (isset($this->manifest->metadata->author->socialLink) && strpos($this->manifest->metadata->author->socialLink, 'https://twitter.com/') === 0) {
          $metadata .= '  <meta name="twitter:creator" content="' . str_replace('https://twitter.com/', '@', $this->manifest->metadata->author->socialLink) . '" />';
      }
      return $metadata;
    }
    /**
     * Generate or load the path to variations on the logo
     * @var string $height height of the icon as a string
     * @var string $width width of the icon as a string
     * @var string $format (optional) png or jpeg format to return image as
     * @return string path to the image (web visible) that was created or pulled together
     */
    public function getLogoSize($height, $width, $format = "png") {
      $fileName = &$this->staticCache(__FUNCTION__ . $height . $width);
      if (!isset($fileName)) {
        // if no logo, just bail with an easy standard one
        if (!isset($this->manifest->metadata->site->logo) || (isset($this->manifest->metadata->site) && ($this->manifest->metadata->site->logo == '' || $this->manifest->metadata->site->logo == null || $this->manifest->metadata->site->logo == "null"))) {
          $fileName = 'assets/icon-' . $height . 'x' . $width . '.png';
        }
        else {
          // ensure this path exists otherwise let's create it on the fly
          $path = '/';
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
        }
      }
      return $fileName;
    }
    /**
     * License data for common open license
     */
    public function getLicenseData($type = 'select') {
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
          'name' => "Creative Commons: Attribution non-commercial share a like",
          'link' => "https://creativecommons.org/licenses/by-nc-sa/4.0/",
          'image' => "https://i.creativecommons.org/l/by-nc-sa/4.0/88x31.png"
        ),
        "by-nc-nd" => array(
          'name' => "Creative Commons: Attribution Non-commercial No derivatives",
          'link' => "https://creativecommons.org/licenses/by-nc-nd/4.0/",
          'image' => "https://i.creativecommons.org/l/by-nc-nd/4.0/88x31.png"
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
     * Request URI resolution
     */
    public function request_uri() {
      if (isset($_SERVER['REQUEST_URI'])) {
        $uri = $_SERVER['REQUEST_URI'];
      }
      else {
        if (isset($_SERVER['argv'])) {
          $uri = $_SERVER['SCRIPT_NAME'] . '?' . $_SERVER['argv'][0];
        }
        elseif (isset($_SERVER['QUERY_STRING'])) {
          $uri = $_SERVER['SCRIPT_NAME'] . '?' . $_SERVER['QUERY_STRING'];
        }
        else {
          $uri = $_SERVER['SCRIPT_NAME'];
        }
      }
      $uri = '/' . ltrim($uri, '/');
      return $uri;
    }
    /**
     * Load a node based on a path
     * @var $path the path to try loading based on or search for the active from address
     */
    public function loadNodeByLocation($path = NULL) {
      // load from the active address if we have one
      if (is_null($path)) {
        $opPath = str_replace($this->basePath . $this->sitesDirectory . '/' . $this->manifest->metadata->site->name . '/', '', $this->request_uri());
        $path = $opPath;
      }
      $path .= "/index.html";
      // failsafe in case someone had closing /
      $path = 'pages/' . str_replace('//', '/', $path);
      foreach ($this->manifest->items as $item) {
        if ($item->location == $path || $item->slug == $opPath) {
          return $item;
        }
      }
      return $this->manifest->items[0];
    }
    /**
     * Load wc-registry.json relative to the site in question
     */
    public function getWCRegistryJson($base = './') {
      $wcMap = &$this->staticCache(__FUNCTION__ . $this->manifest->metadata->site->name . $base);
      if (!isset($wcMap)) {
        // need to make the request relative to site
        if ($base == './') {
          // possible this comes up empty
          if (file_exists('./wc-registry.json')) {
            $wcPath = './wc-registry.json';
          }
        }
        else {
          $wcPath = $base . 'wc-registry.json';
        }
        // support private IP space which will block this ever going through
        if (!defined('IAM_PRIVATE_ADDRESS_SPACE')) {
          $wcMap = json_decode(file_get_contents($wcPath));
        }
      }
      return $wcMap;
    }
    /**
     * Load content of this page
     * @return string HTML / contents of the page object
     */
    public function getPageContent($page) {
      if (isset($page->location) && $page->location != '') {
        $content = &$this->staticCache(__FUNCTION__ . $page->location);
        if (!isset($content)) {
          $content = filter_var(file_get_contents('./' . $page->location));
        }
        return $content;
      }
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
      $fileName = &$this->staticCache(__FUNCTION__ . $id);
      
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
          $fileName = $this->manifest->metadata->theme->variables->image;
        }
      }
      return $fileName;
    }
    /**
     * Static cache a variable that may be called multiple times
     * in one transaction yet has same result
     */
    public function &staticCache($name, $default_value = NULL, $reset = FALSE) {
      static $data = array(), $default = array();
      if (isset($data[$name]) || array_key_exists($name, $data)) {
        if ($reset) {
          $data[$name] = $default[$name];
        }
        return $data[$name];
      }
      if (isset($name)) {
        if ($reset) {
          return $data;
        }
        $default[$name] = $data[$name] = $default_value;
        return $data[$name];
      }
      foreach ($default as $name => $value) {
        $data[$name] = $value;
      }
      return $data;
    }

    /**
     * Return the link to the cdn to use for serving dynamic pages
     * if $site defines a dynamicCDN endpoint then this overrides
     * any global setting. Useful for locally developed custom builds.
     */
    public function getCDNForDynamic() {
      if (isset($this->manifest->metadata->site->dynamicCDN)) {
        return $this->manifest->metadata->site->dynamicCDN;
      }
      return $this->cdn;
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
     * Return a standard service worker that takes into account
     * the context of the page it's been placed on.
     * @todo this will need additional vetting based on the context applied
     * @return string <script> tag that will be a rather standard service worker
     */
    public function getServiceWorkerScript($basePath = null, $ignoreDevMode = FALSE, $addSW = TRUE) {
      // because this can screw with caching, let's make sure we
      // can throttle it locally for developers as needed
      if (!$addSW || ($this->developerMode && !$ignoreDevMode)) {
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
  }
  $HAXSiteConfig = new HAXSiteConfig();
}