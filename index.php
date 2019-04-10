<?php
if (!is_dir('_config') || !is_dir('_sites')) {
    header("Location: install.php");
}
include_once 'system/lib/bootstrapHAX.php';
include_once $HAXCMS->configDirectory . '/config.php';
$appSettings = $HAXCMS->appJWTConnectionSettings();
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="generator" content="HAXCMS">
    <meta name="viewport" content="width=device-width, minimum-scale=1, initial-scale=1, user-scalable=yes">
    <title>HAXCMS site list</title>
    <meta name="description" content="My HAXCMS site description">

    <script type="text/javascript">
      document.write("<base href='" + document.location.pathname.replace('index.html', '') + "' />");
    </script>
    
    <link rel="icon" href="assets/favicon.ico">

    <link rel="manifest" href="manifest.json">

    <meta name="theme-color" content="#3f51b5">

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
    <meta name="msapplication-TileColor" content="#3f51b5">
    <meta name="msapplication-tap-highlight" content="no">

    <meta name="twitter:card" content="summary">
    <meta name="twitter:site" content="@elmsln">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="books-app">
    <meta property="og:image" content="assets/icon-144x144.png" />

    <link rel="preconnect" crossorigin href="https://fonts.googleapis.com">
    <link rel="preconnect" crossorigin href="https://cdnjs.cloudflare.com">
    <style>
      body {
        margin: 0;
        min-height: 100vh;
      }
      haxcms-site-listing {
        transition: all 1s linear;
      }
      haxcms-site-listing:not(:defined) {
        width: 100vw;
        display: block;
        position: fixed;
        height: 100vh;
        background: #23D5AB;
      }
      haxcms-site-listing:not(:defined) div {
        font-size: 6vw;
        line-height: 1;
        margin: 0 auto;
        top: calc(50vh - 8vw);
        width: 100%;
        justify-content: center;
        display: grid;
        text-align: center;
        padding: 0;
        position: relative;
        font-family: "Courier New", Courier, monospace;
        color: black;
      }
    </style>
  </head>
  <body>
    <script>window.appSettings = <?php print json_encode(
        $appSettings
    ); ?>; </script>
    <haxcms-site-listing create-params='{"token":"<?php print $HAXCMS->getRequestToken(); ?>"}' base-path="<?php print $HAXCMS->basePath; ?>" data-source="<?php print $HAXCMS->sitesJSON; ?>"><div>HAXcms</div><div>loading</div></haxcms-site-listing>
    <noscript>Please enable JavaScript to view this website.</noscript>
    <script src="babel/babel-top.js"></script>
    <script>if (!window.customElements) { document.write("<!--") }</script>
    <script src="build/es6/node_modules/@webcomponents/webcomponentsjs/custom-elements-es5-adapter.js"></script>
    <!--! do not remove -->
    <script src="build/es6/node_modules/@webcomponents/webcomponentsjs/webcomponents-loader.js"></script>
    <script async src="build/es6/node_modules/web-animations-js/web-animations-next-lite.min.js"></script>
    <script src="babel/babel-bottom.js"></script>
    <script>function supportsImports() { try { new Function('import("")'); return true; } catch (err) { return false; } }</script>
    <script nomodule>window.nomodule = true;</script>
    <script>
      if (window.nomodule || !supportsImports()) {
        define(["build/es5-amd/dist/build-home.js"], function () { "use strict" });
        document.write("<!--")
      }
    </script>
    <script type="module" src="build/es6/dist/build-home.js"></script>
    <!--! do not remove -->
    <script>
      var link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = 'build/es6/node_modules/@lrnwebcomponents/haxcms-elements/lib/base.css';
      link.type = 'text/css';
      var def = document.getElementsByTagName('link')[0];
      def.parentNode.insertBefore(link, def);
    </script>
  </body>
</html>