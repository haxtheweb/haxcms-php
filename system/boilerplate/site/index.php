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
  <script type="importmap">
    {
      "scopes": {
        "./custom/build/": {
          "@haxtheweb/": "./build/es6/node_modules/@haxtheweb/"
        }
      }
    }
  </script>
  <?php print $HAXSiteConfig->getBaseTag(); ?>
  <?php print $HAXSiteConfig->getSiteMetadata($HAXSiteConfig->page); ?>
  <?php print $HAXSiteConfig->getServiceWorkerScript(null, FALSE, $HAXSiteConfig->getServiceWorkerStatus()); ?>
  <style>
    body {
      margin: 0;
      padding: 0;
      min-height: 98vh;
    }
    haxcms-site-builder:not([theme-loaded]) * {
      margin-top: 100px;
      display: block;
      max-width: 50vw;
      margin-left: auto;
      margin-right: auto;
    }
    haxcms-site-builder[theme-loaded] .haxcms-theme-element:not(:defined) {
      margin-top: 100px;
    }
    haxcms-site-builder[theme-loaded] .haxcms-theme-element:not(:defined) * {
      max-width: 50vw;
      margin-left: auto;
      margin-right: auto;
    }
    haxcms-site-builder[theme-loaded] .haxcms-theme-element:not(:defined) *:not(:defined) {
      display: block;
      min-height: 50px;
      min-width: 200px;
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
      bottom: 0px;
      left: 0px;
      opacity: 1;
      position: absolute;
      right: 0px;
      top: 0px;
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
      body {
        background-color: #333333;
        color: rgba(255,255,255, 0.2);
      }
      #loading {
        background-color: #333333;
      }
      #loading div.messaging {
        color: rgba(255,255,255, 0.2);
      }
    }
  </style>
  <script id="loadingscript">
    globalThis.addEventListener('haxcms-ready', function(e) {
      // give the web components a second to build
      setTimeout(function() {
        document.querySelector('#loading').classList.add('loaded');
        setTimeout(function() {
          document.querySelector('#loading').parentNode.removeChild(document.querySelector('#loading'));
          document.querySelector('#loadingstyles').parentNode.removeChild(document.querySelector('#loadingstyles'));
          document.querySelector('#loadingscript').parentNode.removeChild(document.querySelector('#loadingscript'));
        }, 100);
      }, 300);
    });
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
    <?php print $HAXSiteConfig->getPageContent($HAXSiteConfig->page); ?>
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