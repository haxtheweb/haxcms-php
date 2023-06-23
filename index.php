<?php
if (!is_dir('_config') || !is_dir('_sites') || !is_dir('_archived') || !is_dir('_published')) {
    header("Location: install.php");
}
include_once dirname(__FILE__) . '/system/backend/php/bootstrapHAX.php';
include_once $HAXCMS->configDirectory . '/config.php';
$appSettings = $HAXCMS->appJWTConnectionSettings('');
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <base href="<?php print $HAXCMS->basePath;?>" />
    <link rel="preconnect" crossorigin href="<?php print $HAXCMS->getCDNForDynamic();?>">
    <link rel="preconnect" crossorigin href="https://fonts.googleapis.com">
    <link rel="preconnect" crossorigin href="https://cdnjs.cloudflare.com">
    <link rel="preload" href="<?php print $HAXCMS->getCDNForDynamic();?>build.js" as="script" />
    <link rel="preload" href="<?php print $HAXCMS->getCDNForDynamic();?>wc-registry.json" as="fetch" crossorigin="anonymous" />
    <link rel="preload" href="<?php print $HAXCMS->getCDNForDynamic();?>build/es6/node_modules/@lrnwebcomponents/dynamic-import-registry/dynamic-import-registry.js" as="script" crossorigin="anonymous" />
    <link rel="modulepreload" href="<?php print $HAXCMS->getCDNForDynamic();?>build/es6/node_modules/@lrnwebcomponents/dynamic-import-registry/dynamic-import-registry.js" />
    <link rel="preload" href="<?php print $HAXCMS->getCDNForDynamic();?>build/es6/node_modules/@lrnwebcomponents/wc-autoload/wc-autoload.js" as="script" crossorigin="anonymous" />
    <link rel="modulepreload" href="<?php print $HAXCMS->getCDNForDynamic();?>build/es6/node_modules/@lrnwebcomponents/wc-autoload/wc-autoload.js" />
    <meta name="generator" content="HAXcms">
    <meta name="viewport" content="width=device-width, minimum-scale=1, initial-scale=1, user-scalable=yes">
    <title>Welcome to HAX</title>
    <meta name="description" content="My HAXCMS site list">
    
    <link rel="icon" href="assets/favicon.ico">

    <link rel="manifest" href="manifest.json">

    <meta name="theme-color" content="#37474f">

    <meta name="mobile-web-app-capable" content="yes">
    <meta name="application-name" content="My site">

    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="My App">

    <link rel="apple-touch-icon" href="assets/icon-48x48.png">
    <link rel="apple-touch-icon" sizes="72x72" href="assets/icon-72x72.png">
    <link rel="apple-touch-icon" sizes="96x96" href="assets/icon-96x96.png">
    <link rel="apple-touch-icon" sizes="144x144" href="assets/icon-144x144.png">
    <link rel="apple-touch-icon" sizes="192x192" href="assets/icon-192x192.png">

    <meta name="msapplication-TileImage" content="assets/icon-144x144.png">
    <meta name="msapplication-TileColor" content="#37474f">
    <meta name="msapplication-tap-highlight" content="no">

    <meta name="twitter:card" content="summary">
    <meta name="twitter:site" content="@elmsln">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="books-app">
    <meta property="og:image" content="assets/icon-144x144.png" />
    <style>
      body {
        margin: 0;
        padding: 0;
        font-family: 'Press Start 2P', sans-serif;
        overflow-x: hidden;
        background-image: url('<?php print $HAXCMS->basePath;?>build/es6/node_modules/@lrnwebcomponents/app-hax/lib/assets/images/LMGridBox.svg');
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
      body.app-hax-create {
        overflow: hidden;
      }
      body.dark-mode {
        background-color: black;
        background-image: url('<?php print $HAXCMS->basePath;?>build/es6/node_modules/@lrnwebcomponents/app-hax/lib/assets/images/DMGridBox.svg');
        --app-hax-accent-color: white;
        --app-hax-background-color: black;
        --simple-tooltip-background: #ffffff;
        --simple-tooltip-text-color: #000000;
      }
      body.app-loaded:not(.bad-device) {
        background-image: url('<?php print $HAXCMS->basePath;?>build/es6/node_modules/@lrnwebcomponents/app-hax/lib/assets/images/LMGridBox.svg'), url('<?php print $HAXCMS->basePath;?>build/es6/node_modules/@lrnwebcomponents/app-hax/lib/assets/images/DesignLightModeLeft.svg'), url('<?php print $HAXCMS->basePath;?>build/es6/node_modules/@lrnwebcomponents/app-hax/lib/assets/images/DesignLightModeRight.svg');
        background-repeat: repeat, repeat-y, repeat-y;
        background-position: center center, top left, top right;
        background-size: auto, 20% auto, 20% auto;
        background-attachment: fixed, fixed, fixed;
      }
      div[slot="externalproviders"] {
        display: none;
      }
      body.app-loaded div[slot="externalproviders"] {
        display: unset;
      }
      body.app-loaded.dark-mode:not(.bad-device) {
        background-image: url('<?php print $HAXCMS->basePath;?>build/es6/node_modules/@lrnwebcomponents/app-hax/lib/assets/images/DMGridBox.svg'), url('<?php print $HAXCMS->basePath;?>build/es6/node_modules/@lrnwebcomponents/app-hax/lib/assets/images/DesignDarkModeLeft.svg'), url('<?php print $HAXCMS->basePath;?>build/es6/node_modules/@lrnwebcomponents/app-hax/lib/assets/images/DesignDarkModeRight.svg');
      }

      #loading {
        font-family: 'Press Start 2P', sans-serif;
        text-align: center;
        margin-top: 100px;
      }

      #loading .title {
        -webkit-text-stroke: 1px
          var(--app-hax-accent-color, var(--accent-color));
        -webkit-text-fill-color: var(
          --app-hax-background-color,
          var(--background-color)
        );
        font-weight: normal;
        font-size: 4vw;
        display: inline-flex;
        align-items: center;
      }

      #loading .subtitle {
        color: var(--app-hax-accent-color, var(--accent-color));
        font-weight: normal;
        margin-top: 2.5px;
        font-size: 20px;
      }
      @media (max-width: 700px) {
        #loading .subtitle {
          font-size: 12px;
        }
      }

      #loading .bracket {
        font-size: 10vw;
        font-weight: normal;
        vertical-align: middle;
        -webkit-text-stroke: 0px;
        -webkit-text-fill-color: var(
          --app-hax-accent-color,
          var(--accent-color)
        );
      }

      @media (min-width: 721px) {
        :root {
          background-size: auto, 23% auto, 23% auto;
        }
      }

      @media (min-width: 601px) and (max-width: 720px) {
        :root {
          background-size: auto, 26% auto, 26% auto;
        }
      }

      @media (min-width: 481px) and (max-width: 600px) {
        :root {
          background-size: auto, 30% auto, 30% auto;
        }
      }

      @media (min-width: 371px) and (max-width: 480px) {
        :root {
          background-size: auto, 35% auto, 35% auto;
        }
      }

      @media (max-width: 370px) {
        :root {
          background-size: auto, 37% auto, 37% auto;
        }
      }
      .version {
        position: fixed;
        left: 0;
        bottom: 0;
        background-color:  var(--simple-colors-default-theme-yellow-6);
        display: inline-block;
        padding: 8px;
        color: var(--simple-colors-default-theme-grey-12);
        border-right: 3px solid var(--simple-colors-default-theme-grey-12);
        border-top: 3px solid  var(--simple-colors-default-theme-grey-12);
      }
      body.dark-mode .version {
        background-color:  var(--simple-colors-default-theme-yellow-8);
        color: var(--simple-colors-default-theme-grey-1);
        border-right: 3px solid var(--simple-colors-default-theme-grey-1);
        border-top: 3px solid  var(--simple-colors-default-theme-grey-1);
      }

      simple-modal::part(title) {
        background-color: transparent;
        margin: 0;
        padding: 0;
        text-align: center;
        font-size: 20px;
        line-height: 20px;
        color: black;
      }
      simple-modal button.hax-modal-btn {
        font-size: 30px;
        padding: 8px;
        margin: 4px;
        color: white;
        background-color: green;
        border: 4px solid black;
        border-radius: 8px;
        font-family: 'Press Start 2P', sans-serif;
      }
      simple-modal button.hax-modal-btn.cancel {
        background-color: red;
      }
      simple-modal button.hax-modal-btn:hover,
      simple-modal button.hax-modal-btn:focus {
        outline: 2px solid black;
        cursor: pointer;
        background-color: darkgreen;
      }
      simple-modal button.hax-modal-btn.cancel:hover,
      simple-modal button.hax-modal-btn.cancel:focus {
        background-color: darkred;
      }

    </style>
  </head>
  <body>
    
    <div id="loading">
      <div class="title">
        <span class="bracket">&#60;</span>Loading..<span class="bracket">&#62;</span>
      </div>
      <div class="subtitle">HAX is loading</div>
    </div>
    <script>window.appSettings = <?php print json_encode(
        $appSettings
    ); ?>;
    // reduce FOUC for dark mode so it starts in dark rapidly if selected
    if (window.localStorage && window.localStorage.getItem('app-hax-darkMode')) {
      if (window.localStorage.getItem('app-hax-darkMode') == 'true') {
        document.body.classList.add('dark-mode');
      }
      else {
        document.body.classList.remove('dark-mode');
      }
    }
    // remove loading text
    window.addEventListener('app-hax-loaded',() => {
      // support for overriding values in the registry via config object
      // fire testing in local dev
      <?php 
        // support for local dev overrides of where microservices / other JS comes from
        if (file_exists('_config/.local.microservice.config.php')) {
          include_once '_config/.local.microservice.config.php';
        }
      ?>
      document.querySelector("#loading").remove();
      // make sure we load the font if we have a good device
      setTimeout(() => {
        if (!document.body.classList.contains('bad-device')) {
        const link = document.createElement("link");
        link.setAttribute(
          "href",
          "https://fonts.googleapis.com/css2?family=Press+Start+2P&display=swap"
        );
        link.setAttribute("rel", "stylesheet");
        link.setAttribute("fetchpriority", "low");
        document.head.appendChild(link);
      }
      }, 50);
    });
    </script>
    <div id="visuallist"></div>
    <app-hax token="<?php print $HAXCMS->getRequestToken(); ?>" base-path="<?php print $HAXCMS->basePath; ?>" <?php print $HAXCMS->siteListing->attr; ?>>
      <?php print $HAXCMS->siteListing->slot; ?>
    </app-hax>
    <div class="version">V<?php print $HAXCMS->getHAXCMSVersion();?></div>
    <noscript>Enable JavaScript to use HAXcms.</noscript>
    <script>document.body.removeAttribute('no-js');window.__appCDN="<?php print $HAXCMS->getCDNForDynamic();?>";window.__appForceUpgrade=true;</script>
    <script src="<?php print $HAXCMS->getCDNForDynamic();?>build.js"></script>
    <?php $bottom = ''; $HAXCMS->dispatchEvent('haxcms-app-bottom', $bottom); print $bottom;?>
  </body>
</html>