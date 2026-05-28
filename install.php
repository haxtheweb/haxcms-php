<?php
$failed = false;
include_once __DIR__ . '/system/backend/php/lib/SystemStatusService.php';

if (!function_exists('haxcmsInstallerStatusToneClass')) {
  function haxcmsInstallerStatusToneClass($tone)
  {
    if ($tone === 'ok') {
      return 'status-tone-ok';
    }
    if ($tone === 'warning') {
      return 'status-tone-warning';
    }
    if ($tone === 'error') {
      return 'status-tone-error';
    }
    return 'status-tone-info';
  }
}
if (!function_exists('haxcmsInstallerStatusEscape')) {
  function haxcmsInstallerStatusEscape($value)
  {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
  }
}
if (!function_exists('haxcmsInstallerStatusRender')) {
  function haxcmsInstallerStatusRender($statusReport)
  {
    if (!is_array($statusReport) || !isset($statusReport['rows']) || !is_array($statusReport['rows'])) {
      return;
    }
    $summary = isset($statusReport['summary']) && is_array($statusReport['summary'])
      ? $statusReport['summary']
      : array();
    $runtime = isset($summary['programmingLanguage']) ? $summary['programmingLanguage'] : 'unknown';
    $server = isset($summary['serverVersion']) ? $summary['serverVersion'] : 'unknown';
    $currentVersion = isset($summary['haxcmsVersionCurrent']) ? $summary['haxcmsVersionCurrent'] : 'unknown';
    $latestVersion = isset($summary['haxcmsVersionLatest']) ? $summary['haxcmsVersionLatest'] : 'unknown';
    print '<div class="status-panel">';
    print '<h2>System status checks</h2>';
    print '<p class="status-summary">';
    print 'Runtime: ' . haxcmsInstallerStatusEscape($runtime);
    print ' · Server: ' . haxcmsInstallerStatusEscape($server);
    print ' · HAXcms: ' . haxcmsInstallerStatusEscape($currentVersion);
    print ' (latest: ' . haxcmsInstallerStatusEscape($latestVersion) . ')';
    print '</p>';
    print '<table class="status-table" aria-label="Installer system status checks">';
    print '<thead><tr><th>Check</th><th>Status</th><th>Details</th></tr></thead><tbody>';
    foreach ($statusReport['rows'] as $row) {
      if (!is_array($row)) {
        continue;
      }
      $tone = isset($row['tone']) ? $row['tone'] : 'info';
      $title = isset($row['title']) ? $row['title'] : '';
      $value = isset($row['value']) ? $row['value'] : '';
      $description = isset($row['description']) ? $row['description'] : '';
      print '<tr class="' . haxcmsInstallerStatusToneClass($tone) . '">';
      print '<td>' . haxcmsInstallerStatusEscape($title) . '</td>';
      print '<td>' . haxcmsInstallerStatusEscape($value) . '</td>';
      print '<td>' . haxcmsInstallerStatusEscape($description) . '</td>';
      print '</tr>';
    }
    print '</tbody></table></div>';
  }
}
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
    <link rel="preload" href="./build/es6/node_modules/@haxtheweb/app-hax/app-hax.js"
      as="script" crossorigin="anonymous">
    <link rel="preconnect" crossorigin href="https://fonts.googleapis.com">
    <link rel="preconnect" crossorigin href="https://cdnjs.cloudflare.com">   
    <style>
      body {
        margin: 0;
        padding: 0;
        overflow-x: hidden;
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

      }
      .status-panel {
        margin-top: 32px;
        background-color: #f6f6f6;
        border: 2px solid #d8d8d8;
        border-radius: 8px;
        padding: 12px;
      }
      .status-panel h2 {
        margin: 0 0 8px;
        font-size: 24px;
      }
      .status-summary {
        margin: 0 0 12px;
        font-size: 16px;
      }
      .status-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
      }
      .status-table th,
      .status-table td {
        padding: 8px;
        text-align: left;
        border-bottom: 1px solid #cfcfcf;
        vertical-align: top;
      }
      .status-table tbody tr.status-tone-ok td:first-child {
        border-left: 4px solid #2e7d32;
      }
      .status-table tbody tr.status-tone-warning td:first-child {
        border-left: 4px solid #f9a825;
      }
      .status-table tbody tr.status-tone-error td:first-child {
        border-left: 4px solid #c62828;
      }
      .status-table tbody tr.status-tone-info td:first-child {
        border-left: 4px solid #1565c0;
      }
    </style>
  </head>
  <body no-js>
    <git-corner alt="Join HAX on Github!" source="https://github.com/haxtheweb/haxcms"></git-corner>
    <div class="wrapper">
      <div class="card">
<?php
  include_once 'system/backend/php/lib/Git.php';
  // add git library
  $generateSecureSecret = function () {
    $parts = array();
    for ($i = 0; $i < 4; $i++) {
      $parts[] = bin2hex(random_bytes(16));
    }
    return implode('-', $parts);
  };
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
    mkdir('_config/settings');
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
    // identifies this as a config directory for HAXcms since it is a generic name
    file_put_contents(
      '_config/.isHAXcmsConfig', ""
    );
    // set SALT
    file_put_contents(
      '_config/SALT.txt',
      $generateSecureSecret()
    );

    // set things in config file from the norm
    $configFile = file_get_contents('_config/config.php');
    // private key
    $configFile = str_replace(
      'HAXTHEWEBPRIVATEKEY',
      $generateSecureSecret(),
      $configFile
    );
    // refresh private key
    $configFile = str_replace(
      'HAXTHEWEBREFRESHPRIVATEKEY',
      $generateSecureSecret(),
      $configFile
    );
    // user
    if(isset($_POST['user'])){
      $configFile = str_replace('jeff', filter_var($_POST['user'], FILTER_SANITIZE_FULL_SPECIAL_CHARS), $configFile);
    }
    else{
      $configFile = str_replace('jeff', 'admin', $configFile);
    }
    // support POST for password in this setup phase
    // this is typial of hosting environments that need
    // to see the login details ahead of time in order
    // to set things up correctly
    if(isset($_POST['pass'])){
      $pass = filter_var($_POST['pass'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    }
    else {
      // pass
      $alphabet =
          'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ123456789';
      $pass = array(); //remember to declare $pass as an array
      $alphaLength = strlen($alphabet) - 1; //put the length -1 in cache
      for ($i = 0; $i < 12; $i++) {
          $n = random_int(0, $alphaLength);
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
$installerStatusReport = HAXCMSSystemStatusService::buildInstallerStatusReport(__DIR__);
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
        <?php haxcmsInstallerStatusRender($installerStatusReport); ?>
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
        <?php haxcmsInstallerStatusRender($installerStatusReport); ?>
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