<?php
// include config which is used to derive site state
// if we don't have config, ignore this file and force index.html to
// be the output
if (file_exists('config.php')) {
  include_once 'config.php';
  $HAXSiteConfig = $GLOBALS["HAXSiteConfig"];
}
else {
  print file_get_contents('index.html');
  exit();
}
?>
<!DOCTYPE html>
<html lang="<?php print $HAXSiteConfig->getLanguage(); ?>">
<head>
  <?php print $HAXSiteConfig->getBaseTag(); ?>
  <script type="importmap">
    {
      "scopes": {
        "./custom/build/": {
          "@haxtheweb/": "./build/es6/node_modules/@haxtheweb/"
        }
      }
    }
  </script>
  <?php print $HAXSiteConfig->getSiteMetadata($HAXSiteConfig->page); ?>
  <?php print $HAXSiteConfig->getServiceWorkerScript(null, FALSE, $HAXSiteConfig->getServiceWorkerStatus()); ?>
  <style>
    body {
      margin: 0;
      padding: 0;
      min-height: 98vh;
    }
    haxcms-site-builder {
      display: block;
      min-height: 100svh;
    }
    body[no-js] haxcms-site-builder {
      display: none !important;
    }
  </style>
  <style id="loadingstyles">
    haxcms-site-builder {
      display: block;
    }
    body {
      background-color: #ffffff;
      color: rgba(0,0,0, 0.2);
    }
    #loading {
      background-color: #ffffff;
      inset: 0;
      opacity: 1;
      position: fixed;
      z-index: 99999999;
    }
    #loading.loaded {
      animation: fade-out .1s ease-in-out;
      animation-fill-mode: forwards;
    }
    #loading div.messaging {
      color: rgba(0,0,0, 0.2);
      left: 0px;
      position: absolute;
      right: 0px;
      text-align: center;
      top: 25vh;
    }
    #loading div.messaging h1 {
      font-family: Helvetica, "Trebuchet MS", Verdana, sans-serif !important;
      line-height: 2;
      font-size: 18px !important;
      margin: 0;
      padding: 0;
    }

    .progress-line,
    .progress-line:before {
      height: 8px;
      width: 100%;
      margin: auto;
    }
    .progress-line {
      background-color: rgba(0,0,0, 0.1);
      display: -webkit-flex;
      display: flex;
      width: 50vw;
    }
    .progress-line:before {
      background-color: <?php print $HAXSiteConfig->color;?>;
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
    @media (prefers-color-scheme: dark) {
      #loading {
        background-color: #333333;
      }
      #loading div.messaging {
        color: rgba(255,255,255, 0.2);
      }
    }
  </style>
  <script id="loadingscript">
    (function () {
      var loadingDismissed = false;
      function hideLoading() {
        if (loadingDismissed) {
          return;
        }
        loadingDismissed = true;
        var loadingEl = document.querySelector('#loading');
        if (!loadingEl) {
          return;
        }
        globalThis.requestAnimationFrame(function () {
          globalThis.requestAnimationFrame(function () {
            loadingEl.classList.add('loaded');
            loadingEl.setAttribute('aria-busy', 'false');
            setTimeout(function () {
              loadingEl.setAttribute('hidden', 'hidden');
            }, 120);
          });
        });
      }
      globalThis.addEventListener(
        'json-outline-schema-active-body-changed',
        function () {
          hideLoading();
        },
        { once: true }
      );
      globalThis.addEventListener(
        'haxcms-ready',
        function () {
          setTimeout(function () {
            hideLoading();
          }, 2500);
        },
        { once: true }
      );
    })();
  </script>
</head>
<body <?php print $HAXSiteConfig->getSitePageAttributes();?>>
  <section role="alert" id="loading" aria-busy="true">
    <div class="messaging">
      <div class="progress-line"></div>
      <h1>Loading <?php print $HAXSiteConfig->name; ?>..</h1>
    </div>
  </section>
  <haxcms-site-builder id="site" file="site.json<?php print $HAXSiteConfig->cacheBusterHash();?>">
    <?php
      if (
        method_exists($HAXSiteConfig, 'isPageNotFound') &&
        $HAXSiteConfig->isPageNotFound() &&
        method_exists($HAXSiteConfig, 'getPageMissShellMarkup')
      ) {
        print $HAXSiteConfig->getPageMissShellMarkup();
      }
      else {
        print $HAXSiteConfig->getPageContent($HAXSiteConfig->page);
      }
    ?>
  </haxcms-site-builder>
  <script>
    <?php 
      // support for local dev overrides of where microservices / other JS comes from
      if (file_exists('../../_config/.local.microservice.config.php')) {
        include_once '../../_config/.local.microservice.config.php';
      }
    ?>
    globalThis.HAXCMSContext="php";globalThis.__appCDN="<?php print $HAXSiteConfig->getCDNForDynamic();?>";
  </script>
  <script src="<?php print $HAXSiteConfig->getCDNForDynamic();?>build-haxcms.js"></script>
  <script src="<?php print $HAXSiteConfig->getCDNForDynamic();?>build.js"></script>
  <?php print $HAXSiteConfig->getGaCode(); ?>
</body>
</html>