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
        $repo = Git::open($site->directory . '/' . $site->manifest->metadata->siteName);
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
            unlink($site->directory . '/' . $site->manifest->metadata->siteName . '/build');
            unlink($site->directory . '/' . $site->manifest->metadata->siteName . '/dist');
            unlink($site->directory . '/' . $site->manifest->metadata->siteName . '/node_modules');
            unlink($site->directory . '/' . $site->manifest->metadata->siteName . '/assets/babel-top.js');
            unlink($site->directory . '/' . $site->manifest->metadata->siteName . '/assets/babel-bottom.js');
            rename($site->directory . '/' . $site->manifest->metadata->siteName . '/index.html', $site->directory . '/' . $site->manifest->metadata->siteName . '/_index.html');
            copy(HAXCMS_ROOT . '/system/boilerplate/cdns/' . $site->manifest->metadata->publishing->git->cdn . '.html', $site->directory . '/' . $site->manifest->metadata->siteName . '/index.html');
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
            symlink('../../build', $site->directory . '/' . $site->manifest->metadata->siteName . '/build');
            symlink('../../dist', $site->directory . '/' . $site->manifest->metadata->siteName . '/dist');
            symlink('../../node_modules', $site->directory . '/' . $site->manifest->metadata->siteName . '/node_modules');
            symlink('../../babel/babel-top.js', $site->directory . '/' . $site->manifest->metadata->siteName . '/assets/babel-top.js');
            symlink('../../babel/babel-bottom.js', $site->directory . '/' . $site->manifest->metadata->siteName . '/assets/babel-bottom.js');
            unlink($site->directory . '/' . $site->manifest->metadata->siteName . '/index.html');
            rename($site->directory . '/' . $site->manifest->metadata->siteName . '/_index.html', $site->directory . '/' . $site->manifest->metadata->siteName . '/index.html');
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
            unlink($site->directory . '/' . $site->manifest->metadata->siteName . '/build');
            unlink($site->directory . '/' . $site->manifest->metadata->siteName . '/assets/babel-top.js');
            unlink($site->directory . '/' . $site->manifest->metadata->siteName . '/assets/babel-bottom.js');
            copy(HAXCMS_ROOT . '/babel/babel-top.js', $site->directory . '/' . $site->manifest->metadata->siteName . '/assets/babel-top.js');
            copy(HAXCMS_ROOT . '/babel/babel-bottom.js', $site->directory . '/' . $site->manifest->metadata->siteName . '/assets/babel-bottom.js');
            rename(HAXCMS_ROOT . '/build', $site->directory . '/' . $site->manifest->metadata->siteName . '/build');
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
            rename($site->directory . '/' . $site->manifest->metadata->siteName . '/build', HAXCMS_ROOT . '/build');
            symlink ("../../build", $site->directory . '/' . $site->manifest->metadata->siteName . '/build');
            symlink('../../babel/babel-top.js', $site->directory . '/' . $site->manifest->metadata->siteName . '/assets/babel-top.js');
            symlink('../../babel/babel-bottom.js', $site->directory . '/' . $site->manifest->metadata->siteName . '/assets/babel-bottom.js');
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
        header('Status: 200');
        $return = array(
          'status' => 200,
          'url' => $site->manifest->metadata->domain,
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