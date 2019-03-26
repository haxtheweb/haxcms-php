<?php
define('HAXCMS_DEFAULT_THEME', 'simple-blog');
// working with RSS
include_once 'RSS.php';
// a site object
class HAXCMSSite {
  public $name;
  public $manifest;
  public $directory;
  public $basePath = '/';
  public $language = 'en-us';
  /**
   * Load a site based on directory and name
   */
  public function load($directory, $siteBasePath, $name) {
    $this->name = $name;
    $tmpname = urldecode($name);
    $tmpname = $GLOBALS['HAXCMS']->cleanTitle($tmpname, false);
    $this->basePath = $siteBasePath;
    $this->directory = $directory;
    $this->manifest = new JSONOutlineSchema();
    $this->manifest->load($this->directory . '/' . $tmpname . '/site.json');
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
  public function newSite($directory, $siteBasePath, $name, $gitDetails, $domain = NULL) {
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
    $this->recurseCopy(HAXCMS_ROOT . '/system/boilerplate/site', $directory . '/' . $tmpname);
    // create symlink to make it easier to resolve things to single built asset buckets
    @symlink('../../build', $directory . '/' . $tmpname . '/build');
    // symlink to do local development if needed
    @symlink('../../dist', $directory . '/' . $tmpname . '/dist');
    @symlink('../../node_modules', $directory . '/' . $tmpname . '/node_modules');
    // links babel files so that unification is easier
    @symlink('../../../babel/babel-top.js', $directory . '/' . $tmpname . '/assets/babel-top.js');
    @symlink('../../../babel/babel-bottom.js', $directory . '/' . $tmpname . '/assets/babel-bottom.js');
    // default support is for gh-pages
    if (is_null($domain) && isset($gitDetails->user)) {
      $domain = 'https://' . $gitDetails->user . '.github.io/' . $tmpname;
    }
    // put domain into CNAME
    @file_put_contents($directory . '/' . $tmpname . '/CNAME', $domain);
    // load what we just created
    $this->manifest = new JSONOutlineSchema();
    // where to save it to
    $this->manifest->file = $directory . '/' . $tmpname . '/site.json';
    // start updating the schema to match this new item we got
    $this->manifest->title = $name;
    $this->manifest->location = $this->basePath . $tmpname . '/index.html';
    $this->manifest->metadata->siteName = $tmpname;
    $this->manifest->metadata->domain = $domain;
    $this->manifest->metadata->created = time();
    $this->manifest->metadata->updated = time();
    // create an initial page to make sense of what's there
    // this will double as saving our location and other updated data
    $this->addPage();
    // put this in version control :) :) :)
    $git = new Git();
    $repo = $git->create($directory . '/' . $tmpname);
    if (!isset($this->manifest->metadata->git->url) && isset($gitDetails->url)) {
      $this->gitSetRemote($gitDetails);
    }
    return $this;
  }
  /**
   * Rename a page from one location to another
   * This ensures that folders are moved but not the final index.html involved
   * It also helps secure the sites by ensuring movement is only within
   * their folder tree
   */
  public function renamePageLocation($old, $new) {
    $siteDirectory = $this->directory . '/' . $this->manifest->metadata->siteName;
    $old = str_replace('./', '', str_replace('../', '', $old));
    $new = str_replace('./', '', str_replace('../', '', $new));
    @rename(str_replace(
        '/index.html', '', $siteDirectory . '/' . $old
      ),
      str_replace(
        '/index.html', '', $siteDirectory . '/' . $new
      )
    );
  }
  /**
   * Basic wrapper to commit current changes to version control of the site
   */
  public function gitCommit($msg = 'Committed changes') {
    $git = new Git();
    $repo = $git->open($this->directory . '/' . $this->manifest->metadata->siteName);
    $repo->add('.');
    $repo->commit($msg);
    return true;
  }
  /**
   * Basic wrapper to commit current changes to version control of the site
   */
  public function gitPush() {
    $git = new Git();
    $repo = $git->open($this->directory . '/' . $this->manifest->metadata->siteName);
    $repo->add('.');
    $repo->commit($msg);
    return true;
  }

  /**
   * Basic wrapper to commit current changes to version control of the site
   * 
   * @var $git a stdClass containing repo details
   */
  public function gitSetRemote($gitDetails) {
    $git = new Git();
    $repo = $git->open($this->directory . '/' . $this->manifest->metadata->siteName);
    $repo->set_remote("origin", $gitDetails->url);
    return true;
  }
  /**
   * Add a page to the site's file system and reflect it in the outine schema.
   *
   * @var $parent JSONOutlineSchemaItem representing a parent to add this page under
   *
   * @return $page repesented as JSONOutlineSchemaItem
   */
  public function addPage($parent = NULL) {
    // draft an outline schema item
    $page = new JSONOutlineSchemaItem();
    // set a crappy default title
    $page->title = 'New page';
    if ($parent == NULL) {
      $page->parent = NULL;
      $page->indent = 0;
    }
    else {
      // set to the parent id
      $page->parent = $parent->id;
      // move it one indentation below the parent; this can be changed later if desired
      $page->indent = $parent->indent+1;
    }
    // set order to the page's count for default add to end ordering
    $page->order = count($this->manifest->items);
    // location is the html file we just copied and renamed
    $page->location = 'pages/' . $page->id . '/index.html';
    $page->metadata->created = time();
    $page->metadata->updated = time();
    $location = $this->directory . '/' . $this->manifest->metadata->siteName . '/pages/' . $page->id;
    // copy the page we use for simplicity (or later complexity if we want)
    $this->recurseCopy(HAXCMS_ROOT . '/system/boilerplate/page', $location);
    $this->manifest->addItem($page);
    $this->manifest->save();
    $this->updateRSS();
    return $page;
  }
  /**
   * Update RSS / Atom feeds which are physical files
   */
  public function updateRSS() {
    // rip changes to feed urls
    $rss = new FeedMe();
    $siteDirectory = $this->directory . '/' . $this->manifest->metadata->siteName;
    @file_put_contents($siteDirectory . '/rss.xml', $rss->getRSSFeed($this));
    @file_put_contents($siteDirectory . '/atom.xml', $rss->getAtomFeed($this));
  }
  /**
   * Load page by unique id
   */
  public function loadPage($uuid) {
    foreach ($this->manifest->items as $item) {
      if ($item->id == $uuid) {
        return $item;
      }
    }
    return FALSE;
  }
  /**
   * Load field schema for a page
   * Field cascade always follows Core -> Deploy -> Theme -> Site
   * Anything downstream can always override upstream but no one can remove fields
   */
  public function loadFieldSchema($page) {
    $fields = array(
      'configure' => array(),
      'advanced' => array()
    );
    // load core fields
    // it may seem silly but we seek to not brick any usecase so if this file is gone.. don't die
    if (file_exists(HAXCMS_ROOT . '/system/coreConfig/fields.json')) {
      $coreFields = json_decode(file_get_contents(HAXCMS_ROOT . '/system/coreConfig/fields.json'));
      $themes = array();
      foreach ($GLOBALS['HAXCMS']->getThemes() as $key => $item) {
        $themes[$key] = $item->name;
        $themes['key'] = $key;
      }
      // this needs to be set dynamically
      foreach ($coreFields->advanced as $key => $item) {
        if ($item->property === 'theme') {
          $coreFields->advanced[$key]->options = $themes;
        }
      }
      // CORE fields
      if (isset($coreFields->configure)) {
        foreach ($coreFields->configure as $item) {
          $fields['configure'][] = $item;
        }
      }
      if (isset($coreFields->advanced)) {
        foreach ($coreFields->advanced as $item) {
          $fields['advanced'][] = $item;
        }
      }
    }
    // fields can live globally in config
    if (isset($GLOBALS['HAXCMS']->config->fields)) {
      if (isset($GLOBALS['HAXCMS']->config->fields->configure)) {
        foreach ($GLOBALS['HAXCMS']->config->fields->configure as $item) {
          $fields['configure'][] = $item;
        }
      }
      if (isset($GLOBALS['HAXCMS']->config->fields->advanced)) {
        foreach ($GLOBALS['HAXCMS']->config->fields->advanced as $item) {
          $fields['advanced'][] = $item;
        }
      }
    }
    // fields can live in the theme
    if (isset($this->manifest->metadata->theme->fields) && file_exists(HAXCMS_ROOT . '/build/es6/node_modules/'. $this->manifest->metadata->theme->fields)) {
      // @todo thik of how to make this less brittle
      // not a fan of pegging loading this definition to our file system's publishing structure
      $themeFields = json_decode(file_get_contents(HAXCMS_ROOT . '/build/es6/node_modules/'. $this->manifest->metadata->theme->fields));
      if (isset($themeFields->configure)) {
        foreach ($themeFields->configure as $item) {
          $fields['configure'][] = $item;
        }
      }
      if (isset($themeFields->advanced)) {
        foreach ($themeFields->advanced as $item) {
          $fields['advanced'][] = $item;
        }
      }
    }
    // fields can live in the site itself
    if (isset($this->manifest->metadata->fields)) {
      if (isset($this->manifest->metadata->fields->configure)) {
        foreach ($this->manifest->metadata->fields->configure as $item) {
          $fields['configure'][] = $item;
        }
      }
      if (isset($this->manifest->metadata->fields->advanced)) {
        foreach ($this->manifest->metadata->fields->advanced as $item) {
          $fields['advanced'][] = $item;
        }
      }
    }
    // core values that live outside of the fields area
    $values = array(
      'title' => $page->title,
      'location' => str_replace('pages/', '', str_replace('/index.html', '', $page->location)),
      'description' => $page->description,
      'created' => $page->metadata->created,
    );
    // now get the field data from the page
    if (isset($page->metadata->fields)) {
      foreach ($page->metadata->fields as $key => $item) {
        if ($key == 'theme') {
          $values[$key] = $item['key'];
        }
        else {
          $values[$key] = $item;
        }
      }
    }
    // response as schema + values
    $response = new stdClass();
    $response->haxSchema = $fields;
    $response->values = $values;
    return $response;
  }
  /**
   * Update page in the manifest list of items. useful if updating some
   * data about an existing entry.
   * @return JSONOutlineSchemaItem or FALSE
   */
  public function updatePage($page) {
    foreach ($this->manifest->items as $key => $item) {
      if ($item->id === $page->id) {
        $this->manifest->items[$key] = $page;
        $this->manifest->save();
        $this->updateRSS();
        return $page;
      }
    }
    return FALSE;
  }
  /**
   * Delete a page from the manifest
   * @return JSONOutlineSchemaItem or FALSE
   */
  public function deletePage($page) {
    foreach ($this->manifest->items as $key => $item) {
      if ($item->id === $page->id) {
        unset($this->manifest->items[$key]);
        $this->manifest->save();
        $this->updateRSS();
        return TRUE;
      }
    }
    return FALSE;
  }
  /**
   * Change the directory this site is located in
   */
  public function changeName($new) {
    $new = str_replace('./', '', str_replace('../', '', $new));
    // attempt to shift it on the file system
    if ($new != $this->manifest->metadata->siteName) {
      return @rename($this->manifest->metadata->siteName, $new);
    }
  }
  /**
   * Test and ensure the name being returned is a location currently unused
   */
  public function getUniqueLocationName($location) {
    $siteDirectory = $this->directory . '/' . $this->manifest->metadata->siteName;
    $loop = 0;
    $original = $location;
    while (file_exists($siteDirectory . '/pages/' . $location . '/index.html')) {
      $loop++;
      $location = $original . '-' . $loop;
    }
    return $location;
  }
  /**
   * Recursive copy to rename high level but copy all files
   */
  public function recurseCopy($src, $dst) {
    $dir = opendir($src);
    // see if we can make the directory to start off
    if (!is_dir($dst) && @mkdir($dst, 0777, TRUE)) {
      while (FALSE !== ( $file = readdir($dir)) ) {
        if (($file != '.') && ($file != '..')) {
          if (is_dir($src . '/' . $file)) {
            $this->recurseCopy($src . '/' . $file, $dst . '/' . $file);
          }
          else {
            copy($src . '/' . $file, $dst . '/' . $file);
          }
        }
      }
    }
    else {
      return FALSE;
    }
    closedir($dir);
  }
}