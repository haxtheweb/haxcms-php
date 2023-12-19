<?php
  $bootstrapPath = "/../..";
  if (file_exists("/.dockerenv") && __DIR__ == "/var/www/html") {
    $GLOBALS["HAXcmsInDocker"] = true;
    $bootstrapPath = "";
  }
  include_once __DIR__ . $bootstrapPath . '/system/backend/php/bootstrapHAX.php';
  include_once $HAXCMS->configDirectory . '/config.php';
  $site = $HAXCMS->loadSite(basename(__DIR__));
  $page = $site->loadNodeByLocation();
  $color = 'var(' . $site->manifest->metadata->theme->variables->cssVariable . ', #FF2222)';
?>
<!DOCTYPE html>
<html lang="<?php print $site->getLanguage(); ?>">
<head>
  <?php print $site->getBaseTag(); ?>
  <?php print $site->getSiteMetadata($page); ?>
  <?php print $site->getServiceWorkerScript(null, FALSE, $site->getServiceWorkerStatus()); ?>
  <style>
    body {
      margin: 0;
      min-height: 98vh;
    }
    .use-modern-browser a {
      font-size: 22px;
    }
    .use-modern-browser {
      font-size: 22px;
      text-align: center;
      width: 100%;
    }
  </style>
  <style id="loadingstyles">
    haxcms-site-builder {
      display: block;
    }
    body[no-js] haxcms-site-builder {
      display: none !important;
    }
    #loading {
      background-color: white;
      bottom: 0px;
      left: 0px;
      opacity: 1;
      position: absolute;
      right: 0px;
      top: 0px;
      transition: all linear 300ms;
      -webkit-transition: all linear 300ms;
      z-index: 99999999;
    }

    #loading.loaded {
      animation: fade-out .7s ease-in-out;
      animation-fill-mode: forwards;
    }
    #loading div.messaging {
      color: rgba(255,255,255, 0.7);
      font-family: Roboto;
      left: 0px;
      margin-top: -75px;
      position: absolute;
      right: 0px;
      text-align: center;
      top: 50%;
      transform: translateY(-50%);
    }

    .progress-line,
    .progress-line:before {
      height: 8px;
      width: 100%;
      margin: auto;
    }
    .progress-line {
      background-color: rgba(0,0,0, 0.05);
      display: -webkit-flex;
      display: flex;
      width: 30vw;
    }
    .progress-line:before {
      background-color: <?php print $color;?>;
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
  </style>
  <script id="loadingscript">
    window.addEventListener('haxcms-ready', function(e) {
      // give the web components a second to build
      setTimeout(function() {
        document.querySelector('#loading').classList.add('loaded');
        setTimeout(function() {
          document.querySelector('#loading').parentNode.removeChild(document.querySelector('#loading'));
          document.querySelector('#loadingstyles').parentNode.removeChild(document.querySelector('#loadingstyles'));
          document.querySelector('#loadingscript').parentNode.removeChild(document.querySelector('#loadingscript'));
        }, 600);
      }, 300);
    });
  </script>
</head>
<body no-js <?php print $site->getSitePageAttributes();?>>
  <div id="loading">
    <div class="messaging">
      <div class="progress-line"></div>
      <h1 role="alert" aria-busy="true">Loading <?php print $site->name; ?>..</h1>
    </div>
  </div>
  <haxcms-site-builder id="site" file="site.json<?php print $HAXCMS->cacheBusterHash();?>">
    <?php print $site->getPageContent($page); ?>
  </haxcms-site-builder>
  <div id="haxcmsoutdatedfallback">
    <div id="haxcmsoutdatedfallbacksuperold"> 
      <iframe id="outline" style="width:18%;float:left;height:90vh;padding:0;margin:0;" name="outline" id="frame1"
        src="legacy-outline.html" loading="lazy"></iframe>
      <iframe id="content" style="width:80%;float:left;height:90vh;padding:0;margin:0;" name="content" id="frame2" src="" loading="lazy"></iframe>
      <div class="use-modern-browser">Please use a modern browser to
        view our website correctly. <a href="http://outdatedbrowser.com/">Update my browser now</a></div>
    </div>
  </div>
  <script>
    <?php 
      // support for local dev overrides of where microservices / other JS comes from
      if (file_exists('../../_config/.local.microservice.config.php')) {
        include_once '../../_config/.local.microservice.config.php';
      }
    ?>
    window.HAXCMSContext="php";document.body.removeAttribute('no-js');window.__appCDN="<?php print $HAXCMS->getCDNForDynamic($site);?>";window.__appForceUpgrade=<?php print $site->getForceUpgrade();?>;</script>
  <script src="<?php print $HAXCMS->getCDNForDynamic($site);?>build-haxcms.js"></script>
  <script src="<?php print $HAXCMS->getCDNForDynamic($site);?>build.js"></script>
<?php if ($site->getGaID()) { ?>
  <script async src="https://www.googletagmanager.com/gtag/js?id=<?php print $site->getGaID();?>"></script>
  <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());

    gtag('config', '<?php print $site->getGaID();?>');
  </script>
<?php } ?>
</body>
</html>