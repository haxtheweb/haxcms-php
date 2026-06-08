<?php
include_once dirname(__FILE__) . '/siteRoutes/SiteApiRouter.php';
// HAXSiteConfig
// this is a bridge between HAXcms, HAXsite and page rendering in index.php
class HAXSiteConfig {

  public $site;     // site object based on current content
  public $page;     // Page object based on current location
  public $color;    // color, leveraged for a primary site color to use in loading / accents
  public $name;     // name of the site
  public $pageNotFound = false; // request resolved to shell but not a known page
  
  public function __construct($site = null) {
    $this->site = $site;
    if (
      isset($this->site) &&
      class_exists('SiteApiRouter') &&
      SiteApiRouter::dispatch($this->site)
    ) {
      exit;
    }
    if (
      isset($this->site) &&
      method_exists($this->site, 'respondWithRequestedPageVariant') &&
      $this->site->respondWithRequestedPageVariant()
    ) {
      exit;
    }
    $this->page = $this->site->loadNodeByLocation();
    if (
      isset($this->site) &&
      isset($this->site->lastPathLookupMiss) &&
      $this->site->lastPathLookupMiss
    ) {
      $this->pageNotFound = true;
      http_response_code(404);
    }
    if (
      isset($this->site) &&
      method_exists($this->site, 'sendPageAlternateHeaders')
    ) {
      $this->site->sendPageAlternateHeaders($this->page);
    }
    $this->color = 'var(' . $this->site->manifest->metadata->theme->variables->cssVariable . ', #FF2222)';
    $this->name = $this->site->name;
  }
  public function getLanguage() {
    return $this->site->getLanguage();
  }
  public function getBaseTag() {
    return $this->site->getBaseTag();
  }
  public function getSiteMetadata($page = null) {
    return $this->site->getSiteMetadata($page);
  }
  public function getServiceWorkerScript($basePath = null, $ignoreDevMode = FALSE, $addSW = TRUE) {
    return $this->site->getServiceWorkerScript(null, FALSE, $this->getServiceWorkerStatus());
  }
  public function getServiceWorkerStatus() {
    return $this->site->getServiceWorkerStatus();
  }
  public function getSitePageAttributes() {
    return $this->site->getSitePageAttributes();
  }
  public function cacheBusterHash() {
    return $GLOBALS['HAXCMS']->cacheBusterHash();
  }
  public function getPageContent($page = null) {
    return $this->site->getPageContent($page);
  }
  public function isPageNotFound() {
    return $this->pageNotFound;
  }
  public function getPageMissShellMarkup() {
    return '<style>
  .haxcms-page-miss {
    min-height: 60vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    gap: 12px;
    padding: 24px;
    font-family: "Press Start 2P", "Courier New", monospace;
  }
  .haxcms-page-miss__fire {
    font-size: 64px;
    line-height: 1;
    margin: 0;
  }
  .haxcms-page-miss__pixel {
    margin: 0;
    white-space: pre;
    line-height: 1.1;
    font-size: 16px;
  }
  .haxcms-page-miss__text {
    margin: 0;
    font-size: 15px;
    line-height: 1.4;
  }
</style>
<section class="haxcms-page-miss" role="alert" aria-live="polite">
  <p class="haxcms-page-miss__fire" aria-hidden="true">🔥</p>
  <pre class="haxcms-page-miss__pixel" aria-hidden="true">  ▗▄▖
 ▐█▀█▌
 ▐█▄█▌
  ▜█▛
  ▐▌▐▌</pre>
  <p class="haxcms-page-miss__text">The page miss, it burns!</p>
</section>';
  }
  public function getCDNForDynamic() {
    return $GLOBALS['HAXCMS']->getCDNForDynamic($this->site);
  }
  public function getGaCode() {
    return $this->site->getGaCode();
  }
  public function getGaID() {
    return $this->site->getGaID();
  }
}