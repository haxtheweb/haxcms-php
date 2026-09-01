<?php
if (!is_dir('_config') || !is_dir('_sites') || !is_dir('_archived') || !is_dir('_published')) {
    header("Location: install.php");
}
// CSP to prevent click-jacking on login page
header("Content-Security-Policy: frame-ancestors 'none'");
// Phase 3 (M1): this page may embed a per-user access JWT via appSettings.jwt
// (HAXiam / server-injected bootstrap). Prevent browser/proxy caching so the
// token is never served to a different user from cache.
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

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
    <!-- build.js is loaded via script at the end of body; removing redundant preload -->
    <link rel="preload" href="<?php print $HAXCMS->getCDNForDynamic();?>wc-registry.json" as="fetch" crossorigin="anonymous" />
    <link rel="preload" href="<?php print $HAXCMS->getCDNForDynamic();?>build/es6/node_modules/@haxtheweb/dynamic-import-registry/dynamic-import-registry.js" as="script" crossorigin="anonymous" />
    <link rel="modulepreload" href="<?php print $HAXCMS->getCDNForDynamic();?>build/es6/node_modules/@haxtheweb/dynamic-import-registry/dynamic-import-registry.js" />
    <link rel="preload" href="<?php print $HAXCMS->getCDNForDynamic();?>build/es6/node_modules/@haxtheweb/wc-autoload/wc-autoload.js" as="script" crossorigin="anonymous" />
    <link rel="modulepreload" href="<?php print $HAXCMS->getCDNForDynamic();?>build/es6/node_modules/@haxtheweb/wc-autoload/wc-autoload.js" />
    <link rel="modulepreload" href="<?php print $HAXCMS->getCDNForDynamic();?>build/es6/node_modules/@haxtheweb/app-hax/app-hax.js" crossorigin="anonymous" />
    <link rel="modulepreload" href="<?php print $HAXCMS->getCDNForDynamic();?>build/es6/node_modules/lit/index.js" crossorigin="anonymous" />
    <link rel="modulepreload" href="<?php print $HAXCMS->getCDNForDynamic();?>build/es6/node_modules/mobx/dist/mobx.esm.js" crossorigin="anonymous" />
    <link rel="modulepreload" href="<?php print $HAXCMS->getCDNForDynamic();?>build/es6/node_modules/@haxtheweb/app-hax/lib/v2/AppHaxStore.js" crossorigin="anonymous" />
    <link rel="modulepreload" href="<?php print $HAXCMS->getCDNForDynamic();?>build/es6/node_modules/@haxtheweb/app-hax/lib/v2/AppHaxBackendAPI.js" crossorigin="anonymous" />
    <link rel="modulepreload" href="<?php print $HAXCMS->getCDNForDynamic();?>build/es6/node_modules/@haxtheweb/app-hax/lib/v2/AppHaxRouter.js" crossorigin="anonymous" />
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
        color-scheme: light;
        margin: 0;
        padding: 0;
        font-family: var(--ddd-font-primary, sans-serif);
        overflow-x: hidden;
        --app-hax-accent-color: black;
        --app-hax-background-color: white;
        --simple-tooltip-background: #000000;
        --simple-tooltip-opacity: 1;
        --simple-tooltip-text-color: #ffffff;
        --simple-tooltip-delay-in: 0;
        --simple-tooltip-duration-in: 300ms;
        --simple-tooltip-duration-out: 0;
        --simple-tooltip-border-radius: 0;
        --simple-tooltip-font-size: 14px;
      }
      body.dark-mode {
        color-scheme: dark;
        background-color: black;
        --app-hax-accent-color: white;
        --app-hax-background-color: black;
      }
      div[slot="externalproviders"] {
        display: none;
      }
      body.app-loaded div[slot="externalproviders"] {
        display: unset;
      }
      app-hax {
        display: block;
        min-height: 100vh;
      }
      #loading {
        color-scheme: light dark;
        background-color: light-dark(#dadada, #15202b);
        color: light-dark(#15202b, #dadada);
        inset: 0;
        opacity: 1;
        position: fixed;
        z-index: 99999999;
      }
      #loading .loading-circle {
        width: 20vw;
        height: 20vw;
        background-color: #06b9a5;
        border-radius: 50%;
        animation: spin 1s linear infinite;
        margin: 24px auto;
      }
      #loading.loaded {
        animation: fade-out .1s ease-in-out;
        animation-fill-mode: forwards;
      }
      #loading div.messaging {
        left: 0px;
        position: absolute;
        right: 0px;
        text-align: center;
        top: 10vh;
      }
      #loading div.messaging h1 {
        font-family: Helvetica, Verdana, sans-serif !important;
        font-size: 4vw !important;
        margin: 24px auto 0;
        padding: 0;
      }

      .progress-line,
      .progress-line:before {
        border-radius: 8px;
        height: 20px;
        width: 100%;
        margin: auto;
      }
      .progress-line {
        background-color: #ab2101;
        border-radius: 8px;
        display: -webkit-flex;
        display: flex;
        width: 40vw;
        margin: 0 auto;
      }
      .progress-line:before {
        border-radius: 8px;
        background-color: #930d0d;
        content: '';
        animation: running-progress 2s cubic-bezier(0.4, 0, 0.2, 1) infinite;
      }
      @keyframes running-progress {
        0% {
          margin-left: 0px;
          margin-right: 100%;
        }
        50% {
          margin-left: 25%;
          margin-right: 0%;
        }
        100% {
          margin-left: 100%;
          margin-right: 0;
        }
      }
      @keyframes fade-out {
        0% {
          opacity: 1;
        }
        99% {
          opacity: 0;
        }
        100% {
          opacity: 0;
        }
      }
      @keyframes spin {
        0% {
          transform: rotate(0deg);
        }
        100% {
          transform: rotate(360deg);
        }
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
    </style>
  </head>
  <body>
    
    <section role="alert" id="loading" aria-busy="true">
      <div class="messaging">
        <div class="loading-circle"></div>
        <div class="progress-line"></div>
        <h1>Loading HAX..</h1>
      </div>
    </section>
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
    function applyRuntimeOverrides() {
      if (window.__haxRuntimeOverridesApplied) {
        return;
      }
      window.__haxRuntimeOverridesApplied = true;
      // support for overriding values in the registry via config object
      // fire testing in local dev
      <?php 
        // support for local dev overrides of where microservices / other JS comes from
        if (file_exists('_config/.local.microservice.config.php')) {
          include_once '_config/.local.microservice.config.php';
        }
      ?>
    }
    var loadingResolved = false;
    function resolveDashboardLoading() {
      if (loadingResolved) {
        return;
      }
      loadingResolved = true;
      applyRuntimeOverrides();
      var loadingEl = document.querySelector("#loading");
      if (!loadingEl) {
        return;
      }
      window.requestAnimationFrame(function () {
        window.requestAnimationFrame(function () {
          loadingEl.classList.add('loaded');
          loadingEl.setAttribute('aria-busy', 'false');
          setTimeout(function () {
            loadingEl.remove();
          }, 120);
        });
      });
    }
    window.addEventListener('app-hax-loaded', function() {
      resolveDashboardLoading();
    });
    if (window.customElements && window.customElements.whenDefined) {
      window.customElements.whenDefined('app-hax').then(function() {
        setTimeout(function() {
          var appHaxEl = document.querySelector('app-hax');
          if (appHaxEl) {
            resolveDashboardLoading();
          }
        }, 0);
      });
    }
    setTimeout(function() {
      if (loadingResolved) {
        return;
      }
      if (window.customElements && window.customElements.get) {
        var appHaxDefinition = window.customElements.get('app-hax');
        var appHaxEl = document.querySelector('app-hax');
        if (appHaxDefinition && appHaxEl) {
          resolveDashboardLoading();
        }
      }
    }, 4000);
    </script>
    <div id="visuallist"></div>
    <app-hax token="<?php print $HAXCMS->getRequestToken(); ?>" base-path="<?php print $HAXCMS->basePath; ?>" <?php print $HAXCMS->siteListing->attr; ?>>
      <?php print $HAXCMS->siteListing->slot; ?>
    </app-hax>
    <noscript>Enable JavaScript to use HAXcms.</noscript>
    <script>window.__appCDN="<?php print $HAXCMS->getCDNForDynamic();?>";window.HAXCMSContext="php";window.__appForceUpgrade=true;</script>
    <script type="module">
      import "<?php print $HAXCMS->getCDNForDynamic();?>build/es6/node_modules/@haxtheweb/app-hax/app-hax.js";
    </script>
    <script src="<?php print $HAXCMS->getCDNForDynamic();?>build.js"></script>
    <?php $bottom = ''; $HAXCMS->dispatchEvent('haxcms-app-bottom', $bottom); print $bottom;?>
  </body>
</html>