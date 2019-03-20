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
    $git = new GitRepo();
    $repo = Git::create($directory . '/' . $tmpname);
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
    $git = new GitRepo();
    $repo = Git::open($this->directory . '/' . $this->manifest->metadata->siteName);
    $repo->add('.');
    $repo->commit($msg);
    return true;
  }
  /**
   * Basic wrapper to commit current changes to version control of the site
   */
  public function gitPush() {
    $git = new GitRepo();
    $repo = Git::open($this->directory . '/' . $this->manifest->metadata->siteName);
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
    $git = new GitRepo();
    $repo = Git::open($this->directory . '/' . $this->manifest->metadata->siteName);
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
   */
  public function loadFieldSchema($page) {
    $fields = new stdClass();
    $fields->title = new stdClass();
    $fields->title->name = 'title';
    $fields->title->description = 'Title of the page';
    $fields->title->type = 'string';
    $fields->location = new stdClass();
    $fields->location->name = 'location';
    $fields->location->description = 'Location used in the URL';
    $fields->location->type = 'string';
    $fields->description = new stdClass();
    $fields->description->name = 'description';
    $fields->description->description = 'Description for the post';
    $fields->description->type = 'string';
    $fields->created = new stdClass();
    $fields->created->name = 'created';
    $fields->created->description = 'Created timestamp';
    $fields->created->type = 'number';
    $fields->updated = new stdClass();
    $fields->updated->name = 'updated';
    $fields->updated->description = 'Updated timestamp';
    $fields->updated->type = 'number';
    // fields can live globally
    if (isset($GLOBALS['HAXCMS']->config->fields)) {
      foreach ($GLOBALS['HAXCMS']->config->fields as $key => $item) {
        $fields->{$key} = $item;
      }
    }
    // fields can live in the site
    if (isset($this->manifest->metadata->fields)) {
      foreach ($this->manifest->metadata->fields as $key => $item) {
        $fields->{$key} = $item;
      }
    }
    // core values that live outside of the fields area
    $values = array(
      'title' => $page->title,
      'location' => str_replace('pages/', '', str_replace('/index.html', '', $page->location)),
      'description' => $page->description,
      'created' => $page->metadata->created,
      'updated' => $page->metadata->updated,
    );
    // now get the field data from the page
    if (isset($page->manifest->metadata->fields)) {
      foreach ($page->manifest->metadata->fields as $key => $item) {
        $values[$key] = $item;
      }
    }
    // core fields
    $schema = new stdClass();
    $schema->{'$schema'} = "http://json-schema.org/schema#";
    $schema->title = $page->title . " fields";
    $schema->type = "object";
    $schema->properties = new stdClass();
    // publishing
    foreach ($fields as $key => $value) {
      $props = new stdClass();
      $props->title = $value->name;
      $props->type = $value->type;
      if (isset($values[$value->name])) {
        $props->value = $values[$value->name];
      }
      switch ($value->type) {
        case 'string':
          $props->component = new stdClass();
          $props->component->name = "paper-input";
          $props->component->valueProperty = "value";
          if ($value->description) {
            $props->component->slot = '<div slot="suffix">' . $value->description . '</div>';
          }
        break;
        default:
          $props->component = new stdClass();
          $props->component->name = "paper-input";
          $props->component->valueProperty = "value";
          if ($value->description) {
            $props->component->slot = '<div slot="suffix">' . $value->description . '</div>';
          }
        break;
      }
      $schema->properties->{$key} = $props;
    }
    // response as schema + values
    $response = new stdClass();
    $response->schema = $schema;
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