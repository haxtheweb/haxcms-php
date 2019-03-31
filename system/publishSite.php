<?php
  include_once '../system/lib/bootstrapHAX.php';
  include_once $HAXCMS->configDirectory . '/config.php';
  // test if this is a valid user login
  if ($HAXCMS->validateJWT()) {
    header('Content-Type: application/json');
    // ensure we have something we can load and ship back out the door
    if ($site = $HAXCMS->loadSite($HAXCMS->safePost['siteName'])) {
      // local publishing options, then defer to system, then make some up...
      if (isset($site->manifest->metadata->publishing->git)) {
        $gitSettings = $site->manifest->metadata->publishing->git;
      }
      else if (isset($HAXCMS->config->publishing->git)) {
        $gitSettings = $HAXCMS->config->publishing->git;
      }
      else {
        $gitSettings = new stdClass();
        $gitSettings->vendor = 'github';
        $gitSettings->branch = 'gh-pages';
        $gitSettings->url = '';
        $gitSettings->cdn = false;
      }
      if (isset($gitSettings)) {
        $git = new Git();
        $siteDirectoryPath = $site->directory . '/' . $site->manifest->metadata->siteName;
        $repo = $git->open($siteDirectoryPath);
        // ensure we're on master and everything is added
        $repo->checkout('master');
        // set last published time to now
        $site->manifest->metadata->lastPublished = time();
        $site->manifest->save();
        // just to be safe in case the push isn't successful
        try {
          $repo->add('.');
          $repo->commit('Clean up pre-publishing..');
          @$repo->push('origin', 'master');
        }
        catch(Exception $e) {
          // do nothing, we might be offline or something
          // @tood when we get into logging this would be worth logging
        }
        // now check out the publishing branch
        if ($gitSettings->branch != 'master') {
          // try to check it out, if not then we need to create it
          try {
            $repo->checkout($gitSettings->branch);
            // on that branch now we need to forcibly get the master branch over top of this
            $repo->reset('master', 'origin');
            // now we can merge safely because we've already got the files over top
            // as if they originated here
            $repo->merge('master');
          }
          catch(Exception $e) {
            $repo->create_branch($gitSettings->branch);
            $repo->checkout($gitSettings->branch);
          }
          // werid looking I know but if we have a CDN then we need to "rewrite" this file
          if ($gitSettings->cdn && file_exists(HAXCMS_ROOT . '/system/boilerplate/cdns/' . $gitSettings->cdn . '.html')) {
            // move the index.html and unlink the symlinks otherwise we'll get build failures
            if (is_link($siteDirectoryPath . '/build')) {
              @unlink($siteDirectoryPath . '/build');
            }
            if (is_link($siteDirectoryPath . '/dist')) {
              @unlink($siteDirectoryPath . '/dist');
            }
            if (is_link($siteDirectoryPath . '/node_modules')) {
              @unlink($siteDirectoryPath . '/node_modules');
            }
            @unlink($siteDirectoryPath . '/assets/babel-top.js');
            @unlink($siteDirectoryPath . '/assets/babel-bottom.js');
            // additional files to move to ensure we don't screw things up
            $templates = array(
              'sw' => 'service-worker.js',
              'index' => 'index.html',
              'manifest' => 'manifest.json',
              '404' => '404.html',
              'msbc' =>'browserconfig.xml'
            );
            foreach ($templates as $path) {
              rename($siteDirectoryPath . '/' . $path, $siteDirectoryPath . '/_' . $path);
              // special support for index as that comes from a CDN defining what to do
              if ($path === 'index.html') {
                $boilerPath = HAXCMS_ROOT . '/system/boilerplate/cdns/' . $gitSettings->cdn . '.html';
              }
              else {
                $boilerPath = HAXCMS_ROOT . '/system/boilerplate/site/' . $path;
              }
              copy($boilerPath, $siteDirectoryPath . '/' . $path);            
            }
            // process twig variables and templates for static publishing
            $templateVars = array(
              'hexCode' => '#3f51b5',
              // @todo support user setting a twitter account for themselves / site if desired
              'twitter' => '',
              'basePath' => '/' . $site->manifest->metadata->siteName . '/',
              'title' => $site->manifest->title,
              'short' => $site->manifest->metadata->siteName,
              'description' => $site->manifest->description,
              'swhash' => array(),
              'segmentCount' => 1,
              'cdn' => $gitSettings->cdn,
              'cdnRegex' => "(https?:\/\/" .str_replace('.', '\.', $gitSettings->cdn) . "(\/[A-Za-z0-9\-\._~:\/\?#\[\]@!$&'\(\)\*\+,;\=]*)?)",
            );
            // special fallback for HAXtheWeb since it cheats in order to demo the product
            if ($gitSettings->cdn === 'haxtheweb.org') {
              $templateVars['cdn'] = 'cdn.waxam.io';
              $templateVars['cdnRegex'] = "(https?:\/\/" .str_replace('.', '\.', 'cdn.waxam.io') . "(\/[A-Za-z0-9\-\._~:\/\?#\[\]@!$&'\(\)\*\+,;\=]*)?)";
            }
            // if we have a custom domain, try and engineer the base path
            // correctly for the manifest / service worker
            // @todo need to support domains that have subdomains in them
            if (isset($site->manifest->metadata->domain) && $site->manifest->metadata->domain != '') {
              $parts = parse_url($site->manifest->metadata->domain);
              $templateVars['basePath'] = '/';
              if (isset($parts['base'])) {
                $templateVars['basePath'] = $parts['base'];
              }
              if ($templateVars['basePath'] == '/') {
                $templateVars['segmentCount'] = 0;
              }
            }
            if (isset($site->manifest->metadata->hexCode)) {
              $templateVars['hexCode'] = $site->manifest->metadata->hexCode;
            }
            $swItems = $site->manifest->items;
            // the core files you need in every SW manifest
            $coreFiles = array(
              '',
              $templateVars['basePath'],
              'index.html',
              'manifest.json',
              'site.json',
              'assets/favicon.ico',
              '404.html',
            );
            // loop through files directory so we can cache those things too
            if ($handle = opendir($siteDirectoryPath . '/files')) {
              while (false !== ($file = readdir($handle))) {
                if ($file != "." && $file != ".." && $file != '.gitkeep' && $file != '.DS_Store') {
                  $coreFiles[] = 'files/' . $file;
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
              if ($item->location === '' || $item->location === $templateVars['basePath']) {
                $filesize = filesize($siteDirectoryPath . '/index.html');
              }
              else if (file_exists($siteDirectoryPath . '/' . $item->location)) {
                $filesize = filesize($siteDirectoryPath . '/' . $item->location);
              }
              else {
                // ?? file referenced but doesn't exist
                $filesize = 0;
              }
              $templateVars['swhash'][] = array(
                $item->location,
                strtr(
                  base64_encode(
                    hash_hmac('md5', (string) $item->location . $filesize, (string) 'haxcmsswhash', TRUE)
                  ),
                  array('+' => '','/' => '','=' => '','-' => '')
                )
              );
            }
            // put the twig written output into the file
            $loader = new \Twig\Loader\FilesystemLoader($siteDirectoryPath);
            $twig = new \Twig\Environment($loader);
            foreach ($templates as $location) {
              @file_put_contents($siteDirectoryPath . '/' . $location, $twig->render($location, $templateVars));
            }
            try {
              $repo->add('.');
              $repo->commit('Published using CDN: ' . $gitSettings->cdn);
            }
            catch(Exception $e) {
              // do nothing, maybe there was nothing to commit
            }
          }
          else {
            // even more trickery; swap out the symlinks w/ the real assets, publish, switch back
            @unlink($siteDirectoryPath . '/build');
            if (is_link($siteDirectoryPath . '/dist')) {
              @unlink($siteDirectoryPath . '/dist');
            }
            if (is_link($siteDirectoryPath . '/node_modules')) {
              @unlink($siteDirectoryPath . '/node_modules');
            }
            @unlink($siteDirectoryPath . '/assets/babel-top.js');
            @unlink($siteDirectoryPath . '/assets/babel-bottom.js');
            @copy(HAXCMS_ROOT . '/babel/babel-top.js', $siteDirectoryPath . '/assets/babel-top.js');
            @copy(HAXCMS_ROOT . '/babel/babel-bottom.js', $siteDirectoryPath . '/assets/babel-bottom.js');
            @rename(HAXCMS_ROOT . '/build', $siteDirectoryPath . '/build');
            try {
              $repo->add('.');
              $repo->commit('Published using: local assets');
            }
            catch(Exception $e) {
            // do nothing, maybe there was nothing to commit
            }
          }
          // mirror over to the publishing directory
          // @todo need to make a way of doing this in a variable fashion
          // this way we could publish to multiple locations or intentionally to a location
          // which will be important when allowing for open, closed, or other server level configurations
          // that happen automatically as opposed to when the user hits publish
          // also for delivery of the "click to access site" link
          $GLOBALS['fileSystem']->mirror($siteDirectoryPath, '../_published/' . $site->manifest->metadata->siteName);
          // remove the .git version control from this, it's not needed
          $GLOBALS['fileSystem']->remove(['../_published/' . $site->manifest->metadata->siteName . '/.git']);
          // rewrite the base path to ensure it is accurate based on a local build publish vs web
          $index = file_get_contents('../_published/' . $site->manifest->metadata->siteName . '/index.html');
          // replace if it was publishing with the name in it
          $index = str_replace('<base href="/' . $site->manifest->metadata->siteName . '/"', '<base href="/published/' . $site->manifest->metadata->siteName . '/"', $index);
          // replace if it has a vanity domain
          $index = str_replace('<base href="/"', '<base href="/published/' . $site->manifest->metadata->siteName . '/"', $index);
          // rewrite the file
          @file_put_contents('../_published/' . $site->manifest->metadata->siteName . '/index.html', $index);   
          // tag, attempt to push, and set things up for next time
          $repo->add_tag('version-' . $site->manifest->metadata->lastPublished);
          @$repo->push('origin', $gitSettings->branch, "--force");
          @$repo->push('origin', 'version-' . $site->manifest->metadata->lastPublished, "--force");       
          // now put it back plz... and master shouldn't notice any source changes
          $repo->checkout('master');
          // restore these silly things if we need to
          if (!is_link($siteDirectoryPath . '/dist')) {
            @symlink('../../dist', $siteDirectoryPath . '/dist');
          }
          if (!is_link($siteDirectoryPath . '/node_modules')) {
            @symlink('../../node_modules', $siteDirectoryPath . '/node_modules');
          }
        }
        $domain = $gitSettings->url;
        if (isset($site->manifest->metadata->domain) && $site->manifest->metadata->domain != '') {
          $domain = $site->manifest->metadata->domain;
        }
        else {
          // support blowing up github addresses correctly
          $parts = explode('/', str_replace('git@github.com:', '', str_replace('.git', '', $domain)));
          if (count($parts) === 2) {
            $domain = 'https://' . $parts[0] . '.github.io/' . $parts[1];
          }
        }
        header('Status: 200');
        $return = array(
          'status' => 200,
          'url' => $domain,
          'label' => 'Click to access ' . $site->manifest->title,
          'response' => 'Site published!',
          'output' => 'Site published successfully if no errors!',
        );
      }
      // see if this was surge being asked for
      if (isset($HAXCMS->config->publishing->surge)) {
        $path = HAXCMS_ROOT . '/scripts/surgepublish.sh';
        $name = escapeshellarg($site->manifest->metadata->siteName);
        // attempt to publish
        $output = shell_exec("bash $path $name");
        // load the site from name
        $site->manifest->metadata->lastPublished = time();
        $site->manifest->save();
        header('Status: 200');
        $return = array(
          'status' => 200,
          'url' => $domain,
          'label' => 'Click to access ' . $site->manifest->title,
          'response' => 'Site published!',
          'output' => $output,
        );
      }
    }
    else {
      header('Status: 500');
      $return = array(
        'status' => 500,
        'url' => NULL,
        'label' => NULL,
        'response' => 'Unable to load site',
        'output' => '',
      ); 
    }
    print json_encode($return);
    exit;
  }
?>