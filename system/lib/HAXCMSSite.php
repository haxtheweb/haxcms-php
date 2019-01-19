<?php
define('HAXCMS_DEFAULT_THEME', 'simple-blog');
// a site object
class HAXCMSSite {
  public $name;
  public $manifest;
  public $directory;
  public $basePath = '/';
  /**
   * Load a site based on directory and name
   */
  public function load($directory, $siteBasePath, $name) {
    $this->name = $name;
    $tmpname = urldecode($name);
    $tmpname = preg_replace('/[^A-Za-z0-9]/', '', $name);
    $tmpname = strtolower($tmpname);
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
    $tmpname = preg_replace('/[^A-Za-z0-9]/', '', $tmpname);
    $tmpname = strtolower($tmpname);
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
    if (is_null($domain) && isset($git->user)) {
      $domain = 'https://' . $git->user . '.github.io/' . $tmpname;
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
    return $page;
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
   * Update page in the manifest list of items. useful if updating some
   * data about an existing entry.
   * @return JSONOutlineSchemaItem or FALSE
   */
  public function updatePage($page) {
    foreach ($this->manifest->items as $key => $item) {
      if ($item->id === $page->id) {
        $this->manifest->items[$key] = $page;
        $this->manifest->save();
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
        return TRUE;
      }
    }
    return FALSE;
  }
  /**
   * Change the directory this site is located in
   */
  public function changeName($new) {
    // attempt to shift it on the file system
    if ($new != $this->manifest->metadata->siteName) {
      return rename($this->manifest->metadata->siteName, $new);
    }
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