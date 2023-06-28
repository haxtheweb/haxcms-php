<?php
$failed = false;
if (file_exists(__DIR__ . '/VERSION.txt')) {
  $version = filter_var(file_get_contents(__DIR__ . '/VERSION.txt'));
}
// check for core directories existing, redirect if we do
if (is_dir('_sites') && is_dir('_config') && is_dir('_published') && is_dir('_archived')) {
  header("Location: index.php");
  exit();
} else { ?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <title>HAXcms Installation</title>
    <link rel="preload" href="./build/es6/dist/build-install.js" as="script" crossorigin="anonymous">
    <link rel="preload" href="./build/es6/node_modules/@lrnwebcomponents/app-hax/app-hax.js"
      as="script" crossorigin="anonymous">
    <link rel="preconnect" crossorigin href="https://fonts.googleapis.com">
    <link rel="preconnect" crossorigin href="https://cdnjs.cloudflare.com">   
    <style>
      body {
        margin: 0;
        padding: 0;
        overflow-x: hidden;
        background-image: url('/build/es6/node_modules/@lrnwebcomponents/app-hax/lib/assets/images/LMGridBox.svg');
        background-repeat: repeat;
        background-position: center center;
        background-size: auto, 20% auto, 20% auto;
        --app-hax-accent-color: black;
        --app-hax-background-color: white;
        --simple-tooltip-background: #000000;
        --simple-tooltip-opacity: 1;
        --simple-tooltip-text-color: #ffffff;
        --simple-tooltip-delay-in: 0;
        --simple-tooltip-duration-in: 200ms;
        --simple-tooltip-duration-out: 0;
        --simple-tooltip-border-radius: 0;
        --simple-tooltip-font-size: 14px;
      }
      body {
        background-image: url('/build/es6/node_modules/@lrnwebcomponents/app-hax/lib/assets/images/LMGridBox.svg'), url('build/es6/node_modules/@lrnwebcomponents/app-hax/lib/assets/images/DesignLightModeLeft.svg'), url('build/es6/node_modules/@lrnwebcomponents/app-hax/lib/assets/images/DesignLightModeRight.svg');
        background-repeat: repeat, repeat-y, repeat-y;
        background-position: center center, top left, top right;
        background-size: auto, 20% auto, 20% auto;
      }
      pre {
        background-color: #333333;
        color: yellow;
        padding: 8px;
      }
      .version {
        position: fixed;
        left: 0;
        bottom: 0;
        background-color:  yellow;
        display: inline-block;
        padding: 8px;
        font-family: 'Press Start 2P', sans-serif;
        color: black;
        border-right: 3px solid black;
        border-top: 3px solid black;
      }
      button.hax-btn {
        font-size: 40px;
        transition: .2s all ease-in-out;
        padding: 8px;
        margin: 4px;
        color: white;
        background-color: darkblue;
        border: 4px solid black;
        border-radius: 8px;
        font-family: 'Press Start 2P', sans-serif;
      }
      button.hax-btn.smaller {
        font-size: 20px;
      }
      button.hax-btn:focus,
      button.hax-btn:hover {
        cursor: pointer;
        background-color: green;
      }

      button.hax-btn:active {
        background-color: blue;
        border: 6px solid blue;
      }

      p,ul,li {
        font-size: 24px;
      }
      hax-logo {
        --hax-logo-letter-spacing: 1px;
        text-align: center;
        --hax-logo-font-size: 60px;
        margin: 16px 0 50px;
      }
      @media screen and (max-width: 600px) {
        hax-logo {
          --hax-logo-font-size: 20px;
        }
      }
      .version {
        float:right;
        font-weight: bold;
      }
      ul li {
        padding: 4px;
      }
      ul li strong {
        font-family: 'Press Start 2P', sans-serif;
        padding: 8px;
        font-size: 40px;
        line-height: 1.5;
        background-color: rgba(12,12,12,.05);
        margin-left: 16px;
      }
      .wrapper {
        padding: 16px;
        margin: 5vh 15vw;
        display: flex;
        justify-content: center;
      }
      .card {
        width: 60vw;
        background-color: white;
        padding: 0 16px;
      }
      git-corner {
        right: 0;
        top: 0;
        position: fixed;
      }
      button {
        text-transform: none;
      }
      h1 {
        margin: 16px;
        padding: 0;
        font-size: 30px;
        text-align: center;
        font-family: 'Press Start 2P', sans-serif;

      }
    </style>
  </head>
  <body no-js>
    <git-corner alt="Join HAX on Github!" source="https://github.com/elmsln/haxcms"></git-corner>
    <div class="wrapper">
      <div class="card">
<?php
  include_once 'system/backend/php/lib/Git.php';
  // add git library
  if (!is_dir('_config')) {
    // gotta config some place now don't we
    if (!mkdir('_config')) {
      $failed = true;
    }
    // place for the ssh key chain specific to haxcms if desired
    mkdir('_config/.ssh');
    // tmp directory for uploads and other file management
    mkdir('_config/tmp');
    mkdir('_config/cache');
    mkdir('_config/user');
    mkdir('_config/user/files');
    // node modules for local theme development if desired
    mkdir('_config/node_modules');
    // make config.json boilerplate
    copy(
      'system/boilerplate/systemsetup/config.json',
      '_config/config.json'
    );
    // make a file to do custom theme development in
    copy(
      'system/boilerplate/systemsetup/my-custom-elements.js',
      '_config/my-custom-elements.js'
    );
    // make a file for userData to reside
    copy(
      'system/boilerplate/systemsetup/userData.json',
      '_config/userData.json'
    );
    // make a config.php boilerplate for larger overrides
    copy('system/boilerplate/systemsetup/config.php', '_config/config.php');
    // htaccess files
    copy('system/boilerplate/systemsetup/.htaccess', '_config/.htaccess');
    copy('system/boilerplate/systemsetup/.user-files-htaccess', '_config/user/files/.htaccess');
    // set permissions
    chmod("_config", 0755);
    chmod("_config/tmp", 0755);
    chmod("_config/config.json", 0644);
    chmod("_config/userData.json", 0644);
    // set SALT
    file_put_contents(
      '_config/SALT.txt',
      uniqid() . '-' . uniqid() . '-' . uniqid() . '-' . uniqid()
    );
    // set things in config file from the norm
    $configFile = file_get_contents('_config/config.php');
    // private key
    $configFile = str_replace(
      'HAXTHEWEBPRIVATEKEY',
      uniqid() . '-' . uniqid() . '-' . uniqid() . '-' . uniqid(),
      $configFile
    );
    // refresh private key
    $configFile = str_replace(
      'HAXTHEWEBREFRESHPRIVATEKEY',
      uniqid() . '-' . uniqid() . '-' . uniqid() . '-' . uniqid(),
      $configFile
    );
    // user
    if(isset($_POST['user'])){
      $configFile = str_replace('jeff', $_POST['user'], $configFile);
    }
    else{
      $configFile = str_replace('jeff', 'admin', $configFile);
    }
    // support POST for password in this setup phase
    // this is typial of hosting environments that need
    // to see the login details ahead of time in order
    // to set things up correctly
    if(isset($_POST['pass'])){
      $pass = $_POST['pass'];
    }
    else {
      // pass
      $alphabet =
          'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ123456789';
      $pass = array(); //remember to declare $pass as an array
      $alphaLength = strlen($alphabet) - 1; //put the length -1 in cache
      for ($i = 0; $i < 12; $i++) {
          $n = rand(0, $alphaLength);
          $pass[] = $alphabet[$n];
      }
      $pass = implode($pass);
    }
    $configFile = str_replace('jimmerson', $pass, $configFile);
    // work on base path relative to where this was just launched from
    // super sneaky and locks it to where it's currently installed but required
    // or we don't know where to look for anything
    $basePath = str_replace('install.php', '', $_SERVER['SCRIPT_NAME']); 
    $configFile = str_replace("->basePath = '/'", "->basePath = '$basePath'", $configFile);
    file_put_contents('_config/config.php', $configFile);
    $git = new Git();
    $git->create('_config');
  }
  if (!is_dir('_sites')) {
    // make sites directory
    mkdir('_sites');
    chmod("_sites", 0755);
    // attempt to set the user / group on sites
    // these probaly won't work
    @chown('_sites', get_current_user());
    @chgrp('_sites', get_current_user());
    $git = new Git();
    $git->create('_sites');
  }
  if (!is_dir('_published')) {
    // make published directory so you can have a copy of these files
    mkdir('_published');
    chmod("_published", 0755);
    // attempt to set the user / group on sites
    // these probaly won't work
    @chown('_published', get_current_user());
    @chgrp('_published', get_current_user());
  }
  if (!is_dir('_archived')) {
    // make published directory so you can have a copy of these files
    mkdir('_archived');
    chmod("_archived", 0755);
    // attempt to set the user / group on sites
    // these probaly won't work
    @chown('_archived', get_current_user());
    @chgrp('_archived', get_current_user());
  }
}
if ($failed) { ?>
        <hax-logo hide-hax>install-issue</hax-logo><div class="version">V<?php print $version;?></div>
        <h1>HAXcms folder needs to be writeable</h1>
        <p>
          You can modify permissions in order to achieve this
          <pre>chmod 0755 <?php print __DIR__; ?></pre>
          Or the prefered method is to run:
          <pre><?php print "bash " . __DIR__ . "/scripts/haxtheweb.sh"; ?></pre>
          A complete installation guide can be read on 
          <a href="https://haxtheweb.org/installation" target="_blank"  rel="noopener noreferrer">
          <button raised><iron-icon icon="icons:build"></iron-icon> HAXTheWeb</simple-button></a>.
        </p>
<?php } else { ?>
        <hax-logo hide-hax>HAX</hax-logo><div class="version">V<?php print $version;?></div>
        <h1>Install successful</h1>
        <p>If you don' see any errors then that means HAXcms has been successfully installed!
        Configuration settings were saved to <strong>_config/config.php</strong></p>
        <ul>
          <li>Username: <strong>admin</strong></li>
          <li>Password: <strong><?php print $pass; ?></strong></li>
        </ul>
        <a href="index.php" tabindex="-1"><button class="hax-btn">Access HAXcms</button></a>
        <p>Ideas to share or experiencing issues? <a href="http://github.com/elmsln/issues/issues" target="_blank" rel="noopener noreferrer" tabindex="-1">
        <button class="hax-btn smaller">Join our community</button></a></p>
<?php } ?>
</div>
    </div>
    <noscript>Enable JavaScript to experience HAXcms.</noscript>
    <script>document.body.removeAttribute('no-js');var cdn="";var old=false;var ancient=false;
      if (typeof Symbol == "undefined") { // IE 11, at least try to serve a watered down site
        ancient = true;
      }
      try {
        new Function('let a;'); // bizarre but needed for Safari 9 bc of when it was made
      }
      catch (err) {
        ancient = true;
      }
    </script>
    <script>if(old)document.write('<!--');</script>
    <script type="module">
      import "./build/es6/dist/build-install.js";
    </script>
    <script>
    //<!--! do not remove -->
    </script>
  </body>
</html>