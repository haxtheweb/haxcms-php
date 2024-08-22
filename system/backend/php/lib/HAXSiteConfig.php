<?php
// HAXSiteConfig
// this is a bridge between HAXcms, HAXsite and page rendering in index.php
class HAXSiteConfig {
  public function __construct($site = null) {
    $this->site = $site;
    $this->page = $this->site->loadNodeByLocation();
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