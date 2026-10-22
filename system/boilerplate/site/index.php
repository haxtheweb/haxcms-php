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
    :root {
      color-scheme: light dark;
    }
    body {
      margin: 0;
      padding: 0;
      min-height: 98vh;
    }
    haxcms-site-builder {
      display: block;
      min-height: 100svh;
    }
    .haxcms-theme-element:not(:defined) {
      display: block;
      min-height: 100svh;
      padding: 48px 32px;
      font-size: 20px;
      background-color: light-dark(#dadada, #222222);
      color: light-dark(#222222, #dadada);
    }

  </style>
  <style id="loadingstyles">
    haxcms-site-builder {
      display: block;
    }
    /* The loading screen: a page that builds itself (blocks drop onto a sheet,
       a caret blinks once), looping until the site is ready, then finishing
       and dissolving into the site. Plain CSS + the loadingscript below; no
       images, fonts or other requests. Colors follow the system light/dark
       setting. */
    #loading {
      color-scheme: light dark;
      --ld-accent: <?php print $HAXSiteConfig->color;?>;
      --ld-bg: light-dark(#f2f2f4, #000321); /* limestoneMaxLight, potentialMidnight */
      --ld-ink: light-dark(#314d64, #ccdae6); /* slateGray, slateLight */
      --ld-strong: light-dark(#001e44, #ffffff); /* nittanyNavy, white */
      --ld-sheet: light-dark(#ffffff, #001e44); /* white, nittanyNavy */
      --ld-line: light-dark(#a2aaad, #314d64); /* limestoneGray, slateGray */
      --ld-faint: light-dark(#e4e5e7, #1e407c); /* limestoneLight, beaverBlue */
      --ld-bar: light-dark(#ccdae6, #314d64); /* slateLight, slateGray */
      --ld-head: light-dark(#a2aaad, #96bee6); /* limestoneGray, pughBlue */
      --ld-size: 320px;
      background-color: var(--ld-bg);
      color: var(--ld-ink);
      inset: 0;
      opacity: 1;
      position: fixed;
      z-index: 99999999;
    }
    #loading div.messaging {
      align-items: center;
      display: flex;
      flex-direction: column;
      gap: 12px;
      left: 0px;
      position: absolute;
      right: 0px;
      text-align: center;
      top: 50%;
      transform: translateY(-54%);
    }
    #loading div.messaging h1 {
      color: var(--ld-ink);
      font-family: "Roboto", system-ui, -apple-system, "Segoe UI", Helvetica, sans-serif !important;
      font-size: 14px !important;
      font-weight: 500;
      letter-spacing: 0.01em;
      line-height: 1.5;
      margin: 0;
      padding: 0;
    }
    /* the figure: positions and sizes are in a 300 x 210 grid (--x --y --w --h),
       scaled by --u so it fits narrow screens; only opacity and transform move */
    #loading .ld-fig {
      --u: calc(min(var(--ld-size), 68vw) / 300);
      flex: none;
      height: calc(210 * var(--u));
      position: relative;
      width: calc(300 * var(--u));
    }
    :where(#loading .ld-fig) > span {
      border-radius: calc(3 * var(--u));
      box-sizing: border-box;
      display: block;
      height: calc(var(--h) * var(--u));
      left: calc(var(--x) * var(--u));
      position: absolute;
      top: calc(var(--y) * var(--u));
      width: calc(var(--w) * var(--u));
    }
    #loading .ld-back {
      border: 1px solid var(--ld-faint);
    }
    #loading .ld-page {
      background: var(--ld-sheet);
      border: 1px solid var(--ld-line);
      border-radius: calc(8 * var(--u)); /* ddd-radius-sm */
      box-shadow: 0 calc(8 * var(--u)) calc(16 * var(--u)) light-dark(rgba(0, 3, 33, 0.15), rgba(150, 190, 230, 0.1)); /* ddd-boxShadow-md */
    }
    #loading .ld-l {
      animation: 3600ms linear infinite;
      background: var(--ld-bar);
      will-change: transform, opacity;
    }
    #loading .ld-h {
      background: var(--ld-head);
    }
    #loading .ld-img,
    #loading .ld-thumb {
      background: var(--ld-faint);
      border: 1px solid var(--ld-line);
      overflow: hidden;
    }
    #loading .ld-img::before {
      background: var(--ld-accent);
      border-radius: 50%;
      content: '';
      height: calc(14 * var(--u));
      position: absolute;
      right: calc(16 * var(--u));
      top: calc(9 * var(--u));
      width: calc(14 * var(--u));
    }
    #loading .ld-img::after,
    #loading .ld-thumb::after {
      background: var(--ld-line);
      clip-path: polygon(0 100%, 0 80%, 24% 44%, 44% 70%, 64% 40%, 100% 78%, 100% 100%);
      content: '';
      inset: 0;
      position: absolute;
    }
    #loading .ld-caret {
      animation: ldc 3600ms step-end infinite;
      background: var(--ld-strong);
      border-radius: 1px;
    }
    #loading .ld1 { animation-name: ld1; }
    #loading .ld2 { animation-name: ld2; }
    #loading .ld3 { animation-name: ld3; }
    #loading .ld4 { animation-name: ld4; }
    #loading .ld5 { animation-name: ld5; }
    #loading .ld6 { animation-name: ld6; }
    #loading .ld7 { animation-name: ld7; }
    #loading .ld8 { animation-name: ld8; }
    #loading .ld9 { animation-name: ld9; }
    /* one 3600ms loop: each block drops in (staggered), the caret blinks once
       and stays (built at 2850ms), everything rests, fades, and starts again.
       Keep LOOP_MS / BUILT_MS in the loadingscript in step with this. */
    @keyframes ld1 { 0%, 2.8% { opacity: 0; transform: translateY(calc(-16 * var(--u))); animation-timing-function: cubic-bezier(0.2, 0, 0, 1); } 13.3%, 91.7% { opacity: 1; transform: none; } 100% { opacity: 0; transform: none; } }
    @keyframes ld2 { 0%, 15.6% { opacity: 0; transform: translateY(calc(-16 * var(--u))); animation-timing-function: cubic-bezier(0.2, 0, 0, 1); } 26.1%, 91.7% { opacity: 1; transform: none; } 100% { opacity: 0; transform: none; } }
    @keyframes ld3 { 0%, 19.4% { opacity: 0; transform: translateY(calc(-16 * var(--u))); animation-timing-function: cubic-bezier(0.2, 0, 0, 1); } 29.9%, 91.7% { opacity: 1; transform: none; } 100% { opacity: 0; transform: none; } }
    @keyframes ld4 { 0%, 23.3% { opacity: 0; transform: translateY(calc(-16 * var(--u))); animation-timing-function: cubic-bezier(0.2, 0, 0, 1); } 33.8%, 91.7% { opacity: 1; transform: none; } 100% { opacity: 0; transform: none; } }
    @keyframes ld5 { 0%, 33.3% { opacity: 0; transform: translateY(calc(-16 * var(--u))); animation-timing-function: cubic-bezier(0.2, 0, 0, 1); } 43.8%, 91.7% { opacity: 1; transform: none; } 100% { opacity: 0; transform: none; } }
    @keyframes ld6 { 0%, 45% { opacity: 0; transform: translateY(calc(-16 * var(--u))); animation-timing-function: cubic-bezier(0.2, 0, 0, 1); } 55.5%, 91.7% { opacity: 1; transform: none; } 100% { opacity: 0; transform: none; } }
    @keyframes ld7 { 0%, 48.3% { opacity: 0; transform: translateY(calc(-16 * var(--u))); animation-timing-function: cubic-bezier(0.2, 0, 0, 1); } 58.8%, 91.7% { opacity: 1; transform: none; } 100% { opacity: 0; transform: none; } }
    @keyframes ld8 { 0%, 51.7% { opacity: 0; transform: translateY(calc(-16 * var(--u))); animation-timing-function: cubic-bezier(0.2, 0, 0, 1); } 62.2%, 91.7% { opacity: 1; transform: none; } 100% { opacity: 0; transform: none; } }
    @keyframes ld9 { 0%, 55% { opacity: 0; transform: translateY(calc(-16 * var(--u))); animation-timing-function: cubic-bezier(0.2, 0, 0, 1); } 65.5%, 91.7% { opacity: 1; transform: none; } 100% { opacity: 0; transform: none; } }
    @keyframes ldc { 0% { opacity: 0; } 68% { opacity: 1; } 73.6% { opacity: 0; } 79.2% { opacity: 1; } 91.7% { opacity: 1; animation-timing-function: linear; } 100% { opacity: 0; } }
    /* ready: the figure finishes (loadingscript), then the screen dissolves */
    #loading.loaded {
      animation: fade-out .1s ease-in-out;
      animation-fill-mode: forwards;
    }
    #loading.ld-out {
      animation: none;
      opacity: 0;
      pointer-events: none;
      transition: opacity 600ms cubic-bezier(0.4, 0, 1, 1) 150ms; /* duration-slow, timing-exit */
    }
    #loading.ld-out div.messaging {
      opacity: 0;
      transform: translateY(-54%) scale(0.985);
      transition: opacity 300ms cubic-bezier(0.4, 0, 1, 1), transform 600ms cubic-bezier(0.4, 0, 0.2, 1); /* duration-normal/slow, timing-exit/ease */
    }
    #loading.ld-out.ld-quick {
      transition: opacity 150ms linear;
    }
    #loading.ld-out.ld-quick div.messaging {
      transform: translateY(-54%);
      transition: opacity 150ms linear;
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
    /* reduced motion: a still, built page and a quick fade */
    @media (prefers-reduced-motion: reduce) {
      #loading .ld-l,
      #loading .ld-caret {
        animation: none;
        opacity: 1;
        transform: none;
      }
    }
  </style>
  <script id="loadingscript">
    (function () {
      var loadingDismissed = false;
      // The loading figure is a page that builds itself, on a 3600ms loop of CSS
      // animations (the ld* keyframes in #loadingstyles). Once the site is
      // ready, the build wraps up (played faster if it has far to go), rests a
      // moment on the built page, and the screen dissolves.
      var LOOP_MS = 3600; // one loop of the figure
      var BUILT_MS = 2850; // when, in a loop, the page is fully built
      var FINISH_MS = 600; // ready: the build wraps up in at most this long...
      var MAX_RATE = 6; // ...by playing faster, but never more than this
      var HOLD_MS = 150; // then rests on the built page this long
      function finishFigure(figure, done) {
        var anims = figure.getAnimations({ subtree: true });
        if (!anims.length) {
          done();
          return;
        }
        var now = Number(anims[0].currentTime) || 0;
        var phase = now % LOOP_MS;
        // already built (resting or fading): go straight to the built page
        var left = phase >= BUILT_MS ? 0 : BUILT_MS - phase;
        var rate = Math.min(Math.max(left / FINISH_MS, 1), MAX_RATE);
        if (rate > 1) {
          anims.forEach(function (a) {
            if (a.updatePlaybackRate) {
              a.updatePlaybackRate(rate);
            } else {
              a.playbackRate = rate;
            }
          });
        }
        setTimeout(function () {
          anims.forEach(function (a) {
            a.currentTime = now - phase + BUILT_MS;
            a.pause();
          });
          setTimeout(done, HOLD_MS);
        }, left / rate);
      }
      function dissolveLoading(loadingEl, quick) {
        // .loaded is still how HAX says "ready" to themes; .ld-out is the fade
        loadingEl.classList.add('loaded', 'ld-out');
        if (quick) {
          loadingEl.classList.add('ld-quick');
        }
        loadingEl.setAttribute('aria-busy', 'false');
        setTimeout(function () {
          loadingEl.setAttribute('hidden', 'hidden');
        }, quick ? 200 : 750);
      }
      function revealSite(loadingEl) {
        var figure = loadingEl.querySelector('.ld-fig');
        var quick =
          !figure ||
          !figure.getAnimations ||
          document.visibilityState === 'hidden' ||
          !!(globalThis.matchMedia && globalThis.matchMedia('(prefers-reduced-motion: reduce)').matches);
        if (quick) {
          dissolveLoading(loadingEl, true);
        } else {
          finishFigure(figure, function () {
            dissolveLoading(loadingEl, false);
          });
        }
      }
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
            revealSite(loadingEl);
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
      <div class="ld-fig" aria-hidden="true">
        <span class="ld-back" style="--x:78;--y:0;--w:168;--h:192"></span>
        <span class="ld-page" style="--x:66;--y:6;--w:168;--h:198"></span>
        <span class="ld-l ld-h ld1" style="--x:80;--y:22;--w:140;--h:16"></span>
        <span class="ld-l ld2" style="--x:80;--y:50;--w:132;--h:5"></span>
        <span class="ld-l ld3" style="--x:80;--y:61;--w:140;--h:5"></span>
        <span class="ld-l ld4" style="--x:80;--y:72;--w:96;--h:5"></span>
        <span class="ld-l ld-img ld5" style="--x:80;--y:90;--w:140;--h:56"></span>
        <span class="ld-l ld-thumb ld6" style="--x:80;--y:158;--w:42;--h:34"></span>
        <span class="ld-l ld7" style="--x:132;--y:160;--w:88;--h:5"></span>
        <span class="ld-l ld8" style="--x:132;--y:171;--w:88;--h:5"></span>
        <span class="ld-l ld9" style="--x:132;--y:182;--w:60;--h:5"></span>
        <span class="ld-caret" style="--x:196;--y:179;--w:2;--h:11"></span>
      </div>
      <h1>Loading <?php print $HAXSiteConfig->name; ?>...</h1>
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
    // reduce FOUC for dark mode so it starts in dark rapidly if selected
    (function () {
      try {
        var ls = globalThis.localStorage;
        var stored = ls ? ls.getItem('app-hax-darkMode') : null;
        var dark = stored !== null
          ? stored === 'true'
          : !!(globalThis.matchMedia && globalThis.matchMedia('(prefers-color-scheme: dark)').matches);
        if (dark) {
          document.body.classList.add('dark-mode');
        } else {
          document.body.classList.remove('dark-mode');
        }
      } catch (e) {}
    })();
    globalThis.HAXCMSContext="php";globalThis.__appCDN="<?php print $HAXSiteConfig->getCDNForDynamic();?>";
  </script>
  <script src="<?php print $HAXSiteConfig->getCDNForDynamic();?>build-haxcms.js"></script>
  <script src="<?php print $HAXSiteConfig->getCDNForDynamic();?>build.js"></script>
  <?php print $HAXSiteConfig->getGaCode(); ?>
</body>
</html>