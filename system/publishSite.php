<?php
include_once '../system/lib/bootstrapHAX.php';
  include_once $HAXCMS->configDirectory . '/config.php';
  // test if this is a valid user login
  if ($HAXCMS->validateJWT()) {
    header('Content-Type: application/json');
    // ensure we have something we can load and ship back out the door
    if ($site = $HAXCMS->loadSite($HAXCMS->safePost['siteName'])) {
      // see if the site wants to publish to git
      if (isset($site->manifest->metadata->publishing->git)) {
        $git = new GitRepo();
        $siteDirectoryPath = $site->directory . '/' . $site->manifest->metadata->siteName;
        $repo = Git::open($siteDirectoryPath);
        // ensure we're on master and everything is added
        $repo->checkout('master');
        // set last published time to now
        $site->manifest->metadata->lastPublished = time();
        $site->manifest->save();
        // just to be safe in case the push isn't successful
        try {
          $repo->add('.');
          $repo->commit('Clean up pre-publishing..');
          $repo->push('origin', 'master');
        }
        catch(Exception $e) {
          // do nothing, we might be offline or something
          // @tood when we get into logging this would be worth logging
        }
        // now check out the publishing branch
        if ($site->manifest->metadata->publishing->git->branch != 'master') {
          // try to check it out, if not then we need to create it
          try {
            $repo->checkout($site->manifest->metadata->publishing->git->branch);
            // on that branch now we need to forcibly get the master branch over top of this
            $repo->reset('master', 'origin');
            // now we can merge safely because we've already got the files over top
            // as if they originated here
            $repo->merge('master');
          }
          catch(Exception $e) {
            $repo->create_branch($site->manifest->metadata->publishing->git->branch);
            $repo->checkout($site->manifest->metadata->publishing->git->branch);
          }
          // werid looking I know but if we have a CDN then we need to "rewrite" this file
          if ($site->manifest->metadata->publishing->git->cdn && file_exists(HAXCMS_ROOT . '/system/boilerplate/cdns/' . $site->manifest->metadata->publishing->git->cdn . '.html')) {
            // move the index.html and unlink the symlinks otherwise we'll get build failures
            @unlink($siteDirectoryPath . '/build');
            @unlink($siteDirectoryPath . '/dist');
            @unlink($siteDirectoryPath . '/node_modules');
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
                $boilerPath = HAXCMS_ROOT . '/system/boilerplate/cdns/' . $site->manifest->metadata->publishing->git->cdn . '.html';
              }
              else {
                $boilerPath = HAXCMS_ROOT . '/system/boilerplate/site/' . $path;
              }
              copy($boilerPath, $siteDirectoryPath . '/' . $path);            
            }
            // process twig variables and templates for static publishing
            $templateVars = array(
              'hexCode' => '#3f51b5',
              'basePath' => '/' . $site->manifest->metadata->siteName . '/',
              'title' => $site->manifest->title,
              'short' => $site->manifest->metadata->siteName,
              'description' => $site->manifest->description,
              'swhash' => array(),
              'segmentCount' => 1,
              'cdnRegex' => '^https:\/\/' .$site->manifest->metadata->publishing->git->cdn . '\/',
            );
            // if we have a custom domain, try and engineer the base path
            // correctly for the manifest / service worker
            if (isset($site->manifest->metadata->domain)) {
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
              '404.html',
            );
            foreach ($coreFiles as $itemLocation) {
              $coreItem = new stdClass();
              $coreItem->location = $itemLocation;
              $swItems[] = $coreItem;
            }
            // generate a legit hash value that's the same for each file name + file size
            foreach ($swItems as $item) {
              $templateVars['swhash'][] = array(
                $item->location,
                strtr(
                  base64_encode(
                    hash_hmac('md5', (string) $item->location . filesize($siteDirectoryPath . '/' . $item->location), (string) 'haxcmsswhash', TRUE)
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
              $repo->commit('Published using CDN: ' . $site->manifest->metadata->publishing->git->cdn);
            }
            catch(Exception $e) {
              // do nothing, maybe there was nothing to commit
            }
            $repo->add_tag('version-' . $site->manifest->metadata->lastPublished);
            $repo->push('origin', $site->manifest->metadata->publishing->git->branch, "--force");
            $repo->push('origin', 'version-' . $site->manifest->metadata->lastPublished, "--force");
            // put it back like it was after our version goes up
            symlink('../../build', $siteDirectoryPath . '/build');
            symlink('../../dist', $siteDirectoryPath . '/dist');
            symlink('../../node_modules', $siteDirectoryPath . '/node_modules');
            symlink('../../../babel/babel-top.js', $siteDirectoryPath . '/assets/babel-top.js');
            symlink('../../../babel/babel-bottom.js', $siteDirectoryPath . '/assets/babel-bottom.js');
            foreach ($templates as $path) {
              @unlink($siteDirectoryPath . '/' . $path);
              rename($siteDirectoryPath . '/_' . $path, $siteDirectoryPath . '/' . $path);
            }
            try {
              $repo->add('.');
              $repo->commit('Reset for next time');
            }
            catch(Exception $e) {
            // do nothing, maybe there was nothing to commit
            }
          }
          else {
            // even more trickery; swap out the symlinks w/ the real assets, publish, switch back
            @unlink($siteDirectoryPath . '/build');
            @unlink($siteDirectoryPath . '/assets/babel-top.js');
            @unlink($siteDirectoryPath . '/assets/babel-bottom.js');
            copy(HAXCMS_ROOT . '/babel/babel-top.js', $siteDirectoryPath . '/assets/babel-top.js');
            copy(HAXCMS_ROOT . '/babel/babel-bottom.js', $siteDirectoryPath . '/assets/babel-bottom.js');
            rename(HAXCMS_ROOT . '/build', $siteDirectoryPath . '/build');
            try {
              $repo->add('.');
              $repo->commit('Published using: local assets');
            }
            catch(Exception $e) {
            // do nothing, maybe there was nothing to commit
            }
            $repo->add_tag('version-' . $site->manifest->metadata->lastPublished);
            $repo->push('origin', $site->manifest->metadata->publishing->git->branch);
            $repo->push('origin', 'version-' . $site->manifest->metadata->lastPublished);
            rename($siteDirectoryPath . '/build', HAXCMS_ROOT . '/build');
            symlink ("../../build", $siteDirectoryPath . '/build');
            symlink('../../../babel/babel-top.js', $siteDirectoryPath . '/assets/babel-top.js');
            symlink('../../../babel/babel-bottom.js', $siteDirectoryPath . '/assets/babel-bottom.js');
            try {
              $repo->add('.');
              $repo->commit('Reset for next time');
            }
            catch(Exception $e) {
            // do nothing, maybe there was nothing to commit
            }
          }
          // now put it back plz... and master shouldn't notice any source changes
          $repo->checkout('master');
        }
        $domain = $site->manifest->metadata->publishing->git->url;
        if (isset($site->manifest->metadata->domain)) {
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
          'url' => $site->manifest->metadata->domain,
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