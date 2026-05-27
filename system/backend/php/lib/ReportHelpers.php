<?php
class HAXCMSReportHelpers
{
  private static $wordsPerMinute = 225;
  public static function getSiteName($params = array())
  {
    if (isset($params['site']) && is_array($params['site']) && isset($params['site']['name'])) {
      return trim((string) $params['site']['name']);
    }
    if (isset($params['siteName'])) {
      return trim((string) $params['siteName']);
    }
    return '';
  }
  public static function validateSiteToken($params = array(), $siteName = '')
  {
    if (!isset($params['site_token']) || $siteName == '') {
      return false;
    }
    return $GLOBALS['HAXCMS']->validateRequestToken(
      $params['site_token'],
      $GLOBALS['HAXCMS']->getActiveUserName() . ':' . $siteName
    );
  }
  public static function normalizeActiveId($params = array())
  {
    if (!isset($params['activeId']) || is_null($params['activeId'])) {
      return null;
    }
    $activeId = trim((string) $params['activeId']);
    if ($activeId == '' || $activeId == 'null') {
      return null;
    }
    return $activeId;
  }
  public static function normalizeSiteLocation($params = array())
  {
    $siteLocation = '';
    if (isset($params['link']) && is_string($params['link']) && $params['link'] != '') {
      $siteLocation = $params['link'];
    }
    else if (
      isset($params['site']) &&
      is_array($params['site']) &&
      isset($params['site']['file']) &&
      is_string($params['site']['file'])
    ) {
      $siteLocation = $params['site']['file'];
    }
    if ($siteLocation == '') {
      return '';
    }
    $siteLocation = trim($siteLocation);
    if (substr($siteLocation, -10) == '/site.json') {
      $siteLocation = substr($siteLocation, 0, -10);
    }
    return rtrim($siteLocation, '/');
  }
  public static function buildSummaryData($site, $params = array())
  {
    return self::buildReportData($site, $params, 'summary');
  }
  public static function buildLinkData($site, $params = array())
  {
    return self::buildReportData($site, $params, 'link');
  }
  public static function buildContentData($site, $params = array())
  {
    return self::buildReportData($site, $params, 'content');
  }
  public static function buildMediaData($site, $params = array())
  {
    return self::buildReportData($site, $params, 'media');
  }
  private static function buildReportData($site, $params = array(), $mode = 'summary')
  {
    $mode = strtolower((string) $mode);
    $activeId = self::normalizeActiveId($params);
    $siteLocation = self::normalizeSiteLocation($params);
    $items = self::getSelectedItems($site, $activeId);
    if ($mode == 'link') {
      $data = array('linkData' => array());
    }
    else if ($mode == 'content') {
      $data = array('contentData' => array());
    }
    else if ($mode == 'media') {
      $data = array('mediaData' => array());
    }
    else {
      $data = array(
        'pages' => count($items),
        'objectives' => 0,
        'authorNotes' => 0,
        'specialTags' => 0,
        'dataTables' => 0,
        'headings' => 0,
        'video' => 0,
        'videoLength' => 0,
        'h5p' => 0,
        'audio' => 0,
        'links' => 0,
        'readTime' => 0,
        'readability' => null,
        'updatedItems' => array(),
        'created' => '',
        'updated' => '',
        'title' => '',
      );
    }
    $totalWords = 0;
    foreach ($items as $item) {
      $content = self::getPageContent($site, $item);
      $pageAnalysis = self::analyzePage($content, $item, $siteLocation);
      if ($mode == 'link') {
        foreach ($pageAnalysis['linkData'] as $href => $entries) {
          if (!isset($data['linkData'][$href])) {
            $data['linkData'][$href] = array();
          }
          foreach ($entries as $entry) {
            $data['linkData'][$href][] = $entry;
          }
        }
      }
      else if ($mode == 'content') {
        $data['contentData'][] = $pageAnalysis['contentData'];
      }
      else if ($mode == 'media') {
        foreach ($pageAnalysis['mediaData'] as $mediaItem) {
          $data['mediaData'][] = $mediaItem;
        }
      }
      else {
        $data['objectives'] += $pageAnalysis['objectives'];
        $data['authorNotes'] += $pageAnalysis['authorNotes'];
        $data['specialTags'] += $pageAnalysis['specialTags'];
        $data['dataTables'] += $pageAnalysis['dataTables'];
        $data['headings'] += $pageAnalysis['headings'];
        $data['video'] += $pageAnalysis['videos'];
        $data['h5p'] += $pageAnalysis['h5p'];
        $data['audio'] += $pageAnalysis['audio'];
        $data['links'] += $pageAnalysis['links'];
        $totalWords += $pageAnalysis['wordCount'];
      }
    }
    if ($mode != 'summary') {
      return $data;
    }
    if ($totalWords > 0) {
      $data['readTime'] = (int) ceil($totalWords / self::$wordsPerMinute);
    }
    $siteUpdated = 0;
    $siteCreated = 0;
    if (
      isset($site->manifest) &&
      isset($site->manifest->metadata) &&
      isset($site->manifest->metadata->site)
    ) {
      if (isset($site->manifest->metadata->site->updated)) {
        $siteUpdated = (int) $site->manifest->metadata->site->updated;
      }
      if (isset($site->manifest->metadata->site->created)) {
        $siteCreated = (int) $site->manifest->metadata->site->created;
      }
    }
    $data['created'] = self::toISOTime($siteCreated);
    $data['updated'] = self::toISOTime($siteUpdated);
    if (isset($site->manifest) && isset($site->manifest->title)) {
      $data['title'] = (string) $site->manifest->title;
    }
    if (!is_null($activeId) && method_exists($site->manifest, 'getItemById')) {
      $activeItem = $site->manifest->getItemById($activeId);
      if ($activeItem) {
        $data['title'] = isset($activeItem->title) ? (string) $activeItem->title : $data['title'];
        $data['created'] = self::toISOTime(self::getItemMetaValue($activeItem, 'created', 0));
        $data['updated'] = self::toISOTime(self::getItemMetaValue($activeItem, 'updated', 0));
      }
    }
    $data['updatedItems'] = self::getUpdatedItems($items);
    return $data;
  }
  private static function getUpdatedItems($items = array())
  {
    if (!is_array($items) || count($items) == 0) {
      return array();
    }
    usort($items, function ($a, $b) {
      $aUpdated = (int) HAXCMSReportHelpers::getItemMetaValue($a, 'updated', 0);
      $bUpdated = (int) HAXCMSReportHelpers::getItemMetaValue($b, 'updated', 0);
      if ($aUpdated == $bUpdated) {
        return 0;
      }
      return ($aUpdated < $bUpdated) ? 1 : -1;
    });
    $updatedItems = array();
    $slice = array_slice($items, 0, 6);
    foreach ($slice as $item) {
      $updatedItems[] = array(
        'id' => isset($item->id) ? $item->id : '',
        'title' => isset($item->title) ? $item->title : '',
        'slug' => isset($item->slug) ? $item->slug : '',
        'metadata' => array(
          'updated' => self::toISOTime(self::getItemMetaValue($item, 'updated', 0)),
        ),
      );
    }
    return $updatedItems;
  }
  private static function getSelectedItems($site, $activeId = null)
  {
    $orderedItems = self::getOrderedItems($site);
    if (is_null($activeId)) {
      $selected = array();
      foreach ($orderedItems as $item) {
        if (self::getItemMetaValue($item, 'published', null) === false) {
          continue;
        }
        $selected[] = $item;
      }
      return $selected;
    }
    $parentMap = array();
    foreach ($orderedItems as $item) {
      if (!isset($item->id) || $item->id === '') {
        continue;
      }
      $parentMap[$item->id] = isset($item->parent) ? $item->parent : null;
    }
    $selected = array();
    foreach ($orderedItems as $item) {
      if (!isset($item->id) || $item->id === '') {
        continue;
      }
      if (!self::isBranchMember($item->id, $activeId, $parentMap)) {
        continue;
      }
      if (!self::getItemMetaValue($item, 'published', false)) {
        continue;
      }
      $selected[] = $item;
    }
    return $selected;
  }
  private static function getOrderedItems($site)
  {
    if (!isset($site->manifest) || !isset($site->manifest->items) || !is_array($site->manifest->items)) {
      return array();
    }
    if (method_exists($site->manifest, 'orderTree')) {
      return $site->manifest->orderTree($site->manifest->items);
    }
    return $site->manifest->items;
  }
  private static function isBranchMember($itemId, $activeId, $parentMap = array())
  {
    if ((string) $itemId === (string) $activeId) {
      return true;
    }
    if (!isset($parentMap[$itemId])) {
      return false;
    }
    $current = $parentMap[$itemId];
    $guard = 0;
    while (!is_null($current) && $guard < 5000) {
      if ((string) $current === (string) $activeId) {
        return true;
      }
      if (!isset($parentMap[$current])) {
        break;
      }
      $current = $parentMap[$current];
      $guard++;
    }
    return false;
  }
  private static function getItemMetaValue($item, $key, $default = null)
  {
    if (!isset($item->metadata)) {
      return $default;
    }
    if (is_object($item->metadata) && isset($item->metadata->{$key})) {
      return $item->metadata->{$key};
    }
    if (is_array($item->metadata) && isset($item->metadata[$key])) {
      return $item->metadata[$key];
    }
    return $default;
  }
  private static function getPageContent($site, $item)
  {
    if (!isset($item->id)) {
      return '';
    }
    if (!method_exists($site, 'loadNode') || !method_exists($site, 'getPageContent')) {
      return '';
    }
    $page = $site->loadNode($item->id);
    if (!$page) {
      return '';
    }
    $content = $site->getPageContent($page);
    if (!is_string($content)) {
      return '';
    }
    return $content;
  }
  private static function analyzePage($content = '', $item = null, $siteLocation = '')
  {
    $emptyContentData = array(
      'id' => isset($item->id) ? $item->id : '',
      'created' => self::toISOTime(self::getItemMetaValue($item, 'created', 0)),
      'updated' => self::toISOTime(self::getItemMetaValue($item, 'updated', 0)),
      'title' => isset($item->title) ? $item->title : '',
      'slug' => isset($item->slug) ? $item->slug : '',
      'location' => isset($item->location) ? $item->location : '',
      'videos' => 0,
      'audio' => 0,
      'placeholders' => 0,
      'siteremotecontent' => 0,
      'selfChecks' => 0,
      'h5p' => 0,
      'objectives' => 0,
      'authorNotes' => 0,
      'pageType' => self::getItemMetaValue($item, 'pageType', ''),
      'images' => 0,
      'dataTables' => 0,
      'specialTags' => 0,
      'links' => 0,
      'readTime' => 0,
    );
    $emptyData = array(
      'audio' => 0,
      'videos' => 0,
      'selfChecks' => 0,
      'h5p' => 0,
      'objectives' => 0,
      'authorNotes' => 0,
      'images' => 0,
      'headings' => 0,
      'dataTables' => 0,
      'specialTags' => 0,
      'links' => 0,
      'placeholders' => 0,
      'siteremotecontent' => 0,
      'wordCount' => 0,
      'linkData' => array(),
      'contentData' => $emptyContentData,
      'mediaData' => array(),
    );
    if (!is_string($content) || trim($content) == '') {
      return $emptyData;
    }
    $previousState = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $html = '<!DOCTYPE html><html><body>' . $content . '</body></html>';
    if (function_exists('mb_convert_encoding')) {
      $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
    }
    $loaded = $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    if (!$loaded) {
      libxml_clear_errors();
      libxml_use_internal_errors($previousState);
      return $emptyData;
    }
    libxml_clear_errors();
    libxml_use_internal_errors($previousState);
    $xpath = new DOMXPath($dom);
    $audio = self::countQuery($xpath, '//audio|//audio-player');
    $selfChecks = self::countQuery(
      $xpath,
      '//iframe[contains(concat(" ", normalize-space(@class), " "), " entity_iframe ") and not(contains(concat(" ", normalize-space(@class), " "), " elmsmedia_h5p_content "))]|//self-check|//multiple-choice'
    );
    $h5p = self::countQuery(
      $xpath,
      '//iframe[contains(concat(" ", normalize-space(@class), " "), " elmsmedia_h5p_content ") or contains(@src, "h5p/embed")]'
    );
    $objectives = self::countQuery($xpath, '//instruction-card[@type="objectives"]//li');
    $authorNotes = self::countQuery($xpath, '//page-flag');
    $images = self::countQuery($xpath, '//media-image|//img|//simple-img');
    $headings = self::countQuery($xpath, '//h1|//h2|//h3|//h4|//h5|//h6|//relative-heading');
    $dataTables = self::countQuery($xpath, '//table');
    $specialTags = self::countSpecialTags($xpath);
    $links = self::countQuery($xpath, '//a[starts-with(@href, "http://") or starts-with(@href, "https://")]');
    $placeholders = self::countQuery($xpath, '//place-holder');
    $siteRemoteContent = self::countQuery($xpath, '//site-remote-content');
    $videos = self::countQuery(
      $xpath,
      '//video-player|//iframe[contains(@src, "youtube.com") or contains(@src, "youtube-nocookie.com") or contains(@src, "vimeo.com")]|//video[@src]|//video/source[@src]|//a11y-media-player'
    );
    $wordCount = self::countWords($dom->textContent);
    $readTime = 0;
    if ($wordCount > 0) {
      $readTime = (int) ceil($wordCount / self::$wordsPerMinute);
    }
    $linkData = array();
    $linkNodes = $xpath->query('//a[starts-with(@href, "http://") or starts-with(@href, "https://")]');
    if ($linkNodes instanceof DOMNodeList) {
      foreach ($linkNodes as $linkNode) {
        if (!($linkNode instanceof DOMElement)) {
          continue;
        }
        $href = trim((string) $linkNode->getAttribute('href'));
        if ($href == '') {
          continue;
        }
        $linkTitle = trim(preg_replace('/\s+/u', ' ', (string) $linkNode->textContent));
        if ($linkTitle == '') {
          $linkTitle = $href;
        }
        if (!isset($linkData[$href])) {
          $linkData[$href] = array();
        }
        $linkData[$href][] = array(
          'linkTitle' => $linkTitle,
          'itemId' => isset($item->id) ? $item->id : null,
        );
      }
    }
    $mediaData = array();
    $mediaNodes = $xpath->query(
      '//audio[@src]|//audio/source[@src]|//audio-player|//video[@src]|//video/source[@src]|//video-player|//a11y-media-player|//embed|//object|//iframe[@src]|//media-image|//img|//simple-img|//meme-maker'
    );
    if ($mediaNodes instanceof DOMNodeList) {
      foreach ($mediaNodes as $mediaNode) {
        $mediaItem = self::buildMediaRecord($mediaNode, $siteLocation, isset($item->id) ? $item->id : null);
        if (!is_null($mediaItem)) {
          $mediaData[] = $mediaItem;
        }
      }
    }
    $contentData = $emptyContentData;
    $contentData['videos'] = $videos;
    $contentData['audio'] = $audio;
    $contentData['placeholders'] = $placeholders;
    $contentData['siteremotecontent'] = $siteRemoteContent;
    $contentData['selfChecks'] = $selfChecks;
    $contentData['h5p'] = $h5p;
    $contentData['objectives'] = $objectives;
    $contentData['authorNotes'] = $authorNotes;
    $contentData['images'] = $images;
    $contentData['dataTables'] = $dataTables;
    $contentData['specialTags'] = $specialTags;
    $contentData['links'] = $links;
    $contentData['readTime'] = $readTime;
    return array(
      'audio' => $audio,
      'videos' => $videos,
      'selfChecks' => $selfChecks,
      'h5p' => $h5p,
      'objectives' => $objectives,
      'authorNotes' => $authorNotes,
      'images' => $images,
      'headings' => $headings,
      'dataTables' => $dataTables,
      'specialTags' => $specialTags,
      'links' => $links,
      'placeholders' => $placeholders,
      'siteremotecontent' => $siteRemoteContent,
      'wordCount' => $wordCount,
      'linkData' => $linkData,
      'contentData' => $contentData,
      'mediaData' => $mediaData,
    );
  }
  private static function countWords($value = '')
  {
    if (!is_string($value)) {
      return 0;
    }
    $text = trim(preg_replace('/\s+/u', ' ', $value));
    if ($text == '') {
      return 0;
    }
    $parts = preg_split('/\s+/u', $text);
    if (!is_array($parts)) {
      return 0;
    }
    return count($parts);
  }
  private static function countQuery($xpath, $query = '')
  {
    if (!($xpath instanceof DOMXPath) || !is_string($query) || $query == '') {
      return 0;
    }
    $nodes = @$xpath->query($query);
    if (!($nodes instanceof DOMNodeList)) {
      return 0;
    }
    return (int) $nodes->length;
  }
  private static function countSpecialTags($xpath)
  {
    if (!($xpath instanceof DOMXPath)) {
      return 0;
    }
    $allowed = array(
      'html',
      'body',
      'p',
      'div',
      'h1',
      'h2',
      'h3',
      'h4',
      'h5',
      'h6',
      'table',
      'bold',
      'li',
      'ul',
      'ol',
      'span',
      'a',
      'em',
      'b',
      'i',
      'strike',
      'u',
      'code',
      'pre',
      'img',
      'hr',
      'tr',
      'td',
      'th',
    );
    $all = $xpath->query('//*');
    if (!($all instanceof DOMNodeList)) {
      return 0;
    }
    $count = 0;
    foreach ($all as $node) {
      if (!($node instanceof DOMElement)) {
        continue;
      }
      $tag = strtolower($node->tagName);
      if (in_array($tag, $allowed, true)) {
        continue;
      }
      $count++;
    }
    return $count;
  }
  private static function buildMediaRecord($node, $siteLocation = '', $itemId = null)
  {
    if (!($node instanceof DOMElement)) {
      return null;
    }
    $source = '';
    if ($node->hasAttribute('source')) {
      $source = trim((string) $node->getAttribute('source'));
    }
    if ($source == '' && $node->hasAttribute('src')) {
      $source = trim((string) $node->getAttribute('src'));
    }
    if ($source == '' && strtolower($node->tagName) == 'source' && $node->parentNode instanceof DOMElement) {
      if ($node->parentNode->hasAttribute('src')) {
        $source = trim((string) $node->parentNode->getAttribute('src'));
      }
    }
    $sourceData = self::resolveMediaSource($source, $siteLocation);
    $type = self::typeFromElement($node);
    if ($type == 'other' && strtolower($node->tagName) == 'source' && $node->parentNode instanceof DOMElement) {
      $parentType = self::typeFromElement($node->parentNode);
      if ($parentType != 'other') {
        $type = $parentType;
      }
    }
    $alt = null;
    if ($node->hasAttribute('alt')) {
      $alt = trim((string) $node->getAttribute('alt'));
    }
    else if ($node->parentNode instanceof DOMElement && $node->parentNode->hasAttribute('alt')) {
      $alt = trim((string) $node->parentNode->getAttribute('alt'));
    }
    $title = null;
    if ($node->hasAttribute('title')) {
      $title = trim((string) $node->getAttribute('title'));
    }
    if (is_null($title) && $node->hasAttribute('media-title')) {
      $title = trim((string) $node->getAttribute('media-title'));
    }
    if (is_null($title) && $node->parentNode instanceof DOMElement) {
      if ($node->parentNode->hasAttribute('title')) {
        $title = trim((string) $node->parentNode->getAttribute('title'));
      }
      else if ($node->parentNode->hasAttribute('media-title')) {
        $title = trim((string) $node->parentNode->getAttribute('media-title'));
      }
    }
    $mediaItem = array(
      'source' => $sourceData['source'],
      'name' => $sourceData['name'],
      'alt' => $alt,
      'title' => $title,
      'locType' => $sourceData['locType'],
      'type' => $type,
      'itemId' => $itemId,
    );
    $mediaItem['status'] = self::mediaStatus($mediaItem);
    return $mediaItem;
  }
  private static function typeFromElement($element)
  {
    if (!($element instanceof DOMElement)) {
      return 'other';
    }
    $tag = strtolower($element->tagName);
    if ($tag == 'audio' || $tag == 'audio-player') {
      return 'audio';
    }
    if ($tag == 'video' || $tag == 'video-player' || $tag == 'a11y-media-player') {
      return 'video';
    }
    if ($tag == 'img' || $tag == 'simple-img' || $tag == 'media-image') {
      return 'image';
    }
    if ($tag == 'iframe' || $tag == 'embed' || $tag == 'object') {
      $src = '';
      $className = '';
      if ($element->hasAttribute('src')) {
        $src = strtolower((string) $element->getAttribute('src'));
      }
      if ($element->hasAttribute('class')) {
        $className = strtolower((string) $element->getAttribute('class'));
      }
      if (
        strpos($src, 'youtube.com') !== false ||
        strpos($src, 'youtube-nocookie.com') !== false ||
        strpos($src, 'vimeo.com') !== false
      ) {
        return 'video';
      }
      if (
        strpos($className, 'elmsmedia_h5p_content') !== false ||
        strpos($src, 'h5p/embed') !== false
      ) {
        return 'h5p';
      }
      return 'other';
    }
    return 'other';
  }
  private static function mediaStatus($mediaItem = array())
  {
    if (!isset($mediaItem['type']) || $mediaItem['type'] != 'image') {
      return 'info';
    }
    $alt = isset($mediaItem['alt']) ? $mediaItem['alt'] : null;
    $name = isset($mediaItem['name']) ? $mediaItem['name'] : '';
    $source = isset($mediaItem['source']) ? $mediaItem['source'] : '';
    $title = isset($mediaItem['title']) ? $mediaItem['title'] : null;
    if (is_null($alt) || $alt === 'null') {
      return 'error';
    }
    if ($name === $alt || $source === $alt) {
      return 'error';
    }
    if (!is_null($title) && $title === $alt) {
      return 'error';
    }
    if ($alt === '') {
      return 'warning';
    }
    $altLower = strtolower((string) $alt);
    if (strpos($altLower, 'image') !== false || strpos($altLower, 'picture') !== false) {
      return 'warning';
    }
    return 'info';
  }
  private static function resolveMediaSource($source = '', $siteLocation = '')
  {
    $cleanSource = trim((string) $source);
    if ($cleanSource == '') {
      return array(
        'source' => 'unknown',
        'name' => 'unknown',
        'locType' => 'external',
      );
    }
    $isAbsolute = (preg_match('/^https?:\/\//i', $cleanSource) === 1);
    $resolvedSource = $cleanSource;
    $locType = 'external';
    if (!$isAbsolute) {
      $locType = 'internal';
      $resolvedSource = self::resolveLocalSource($cleanSource, $siteLocation);
    }
    $pathValue = parse_url($resolvedSource, PHP_URL_PATH);
    if (!is_string($pathValue) || $pathValue == '') {
      $pathValue = $resolvedSource;
    }
    $name = basename($pathValue);
    if (!is_string($name) || trim($name) == '') {
      $name = 'unknown';
    }
    return array(
      'source' => $resolvedSource,
      'name' => $name,
      'locType' => $locType,
    );
  }
  private static function resolveLocalSource($source = '', $siteLocation = '')
  {
    $source = trim((string) $source);
    if ($source == '') {
      return $source;
    }
    $siteLocation = trim((string) $siteLocation);
    if ($siteLocation == '' || preg_match('/^https?:\/\//i', $siteLocation) !== 1) {
      return $source;
    }
    $parts = parse_url($siteLocation);
    if (!is_array($parts) || !isset($parts['scheme']) || !isset($parts['host'])) {
      return $source;
    }
    $origin = $parts['scheme'] . '://' . $parts['host'];
    if (isset($parts['port'])) {
      $origin .= ':' . $parts['port'];
    }
    if (substr($source, 0, 1) == '/') {
      return $origin . $source;
    }
    $basePath = '';
    if (isset($parts['path'])) {
      $basePath = rtrim($parts['path'], '/');
    }
    if ($basePath == '') {
      return $origin . '/' . ltrim($source, '/');
    }
    return $origin . $basePath . '/' . ltrim($source, '/');
  }
  private static function toISOTime($value = 0)
  {
    $timestamp = 0;
    if (is_numeric($value)) {
      $timestamp = (int) $value;
    }
    if ($timestamp < 0) {
      $timestamp = 0;
    }
    return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
  }
}
