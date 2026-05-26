<?php
trait OperationsRouteSiteSearch {
  /**
   * @OA\Get(
   *    path="/siteSearch",
   *    tags={"hax","authenticated","site"},
   *    @OA\Parameter(
   *         name="site_token",
   *         description="Site-specific validation token",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Parameter(
   *         name="siteName",
   *         description="Name of the site to search",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Parameter(
   *         name="search",
   *         description="Search query string",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Search site content and metadata fields"
   *   )
   * )
   */
  public function siteSearch() {
    $siteName = $this->getSiteSearchSiteName();
    if ($siteName == '') {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'siteName is required',
        )
      );
    }
    if (!(isset($this->params['site_token']) && $GLOBALS['HAXCMS']->validateRequestToken($this->params['site_token'], $GLOBALS['HAXCMS']->getActiveUserName() . ':' . $siteName))) {
      return array(
        '__failed' => array(
          'status' => 403,
          'message' => 'invalid request token',
        )
      );
    }
    $searchTerm = isset($this->params['search']) ? trim((string) $this->params['search']) : '';
    if ($searchTerm == '') {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'Search query is required',
        )
      );
    }
    if (strlen($searchTerm) > 256) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'Search query is too long (max 256 characters)',
        )
      );
    }
    $selectorMode = $this->parseSiteSearchBoolean(isset($this->params['searchSelector']) ? $this->params['searchSelector'] : false);
    if (!$selectorMode && isset($this->params['searchMode']) && strtolower((string) $this->params['searchMode']) == 'selector') {
      $selectorMode = true;
    }
    $caseSensitive = $this->parseSiteSearchBoolean(isset($this->params['searchCaseSensitive']) ? $this->params['searchCaseSensitive'] : false);
    $searchLimit = $this->parseSiteSearchLimit(isset($this->params['searchLimit']) ? $this->params['searchLimit'] : null, 25);
    $searchFields = $selectorMode
      ? array('content')
      : $this->normalizeSiteSearchFields(isset($this->params['searchField']) ? $this->params['searchField'] : null);
    $mode = $selectorMode ? 'selector' : 'text';
    $selectorData = null;
    if ($selectorMode) {
      $selectorData = $this->parseSimpleSiteSearchSelector($searchTerm);
      if (!$selectorData['valid']) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => $selectorData['reason'],
          )
        );
      }
    }
    $response = array(
      'status' => 200,
      'data' => array(
        'query' => $searchTerm,
        'fields' => $searchFields,
        'mode' => $mode,
        'caseSensitive' => $caseSensitive,
        'limit' => $searchLimit,
        'total' => 0,
        'matches' => array(),
      )
    );
    $site = $GLOBALS['HAXCMS']->loadSite($siteName);
    if (!isset($site) || !isset($site->manifest) || !isset($site->manifest->items)) {
      return $response;
    }
    $items = $site->manifest->orderTree($site->manifest->items);
    $contentCache = array();
    $matches = array();
    foreach ($items as $item) {
      if ($searchLimit > 0 && count($matches) >= $searchLimit) {
        break;
      }
      $fieldMatches = array();
      foreach ($searchFields as $field) {
        $content = '';
        if ($field == 'content') {
          if (isset($item->id) && isset($contentCache[$item->id])) {
            $content = $contentCache[$item->id];
          }
          else if (isset($item->id)) {
            $page = $site->loadNode($item->id);
            if ($page) {
              $content = $site->getPageContent($page);
            }
            if (!is_string($content)) {
              $content = '';
            }
            $contentCache[$item->id] = $content;
          }
        }
        $fieldValue = $this->getSiteSearchFieldValue($field, $item, $content);
        if ($selectorMode) {
          $selectorMatch = $this->siteSearchSelectorMatch($fieldValue, $selectorData);
          if (!is_null($selectorMatch)) {
            $fieldMatches[] = array(
              'field' => 'content',
              'type' => 'selector',
              'selector' => $selectorData['selector'],
              'count' => $selectorMatch['count'],
              'snippets' => $selectorMatch['snippets'],
            );
          }
        }
        else {
          $textMatch = $this->siteSearchTextMatch($fieldValue, $searchTerm, $caseSensitive);
          if (!is_null($textMatch)) {
            $fieldMatches[] = array(
              'field' => $field,
              'type' => 'text',
              'index' => $textMatch['index'],
              'length' => $textMatch['length'],
              'snippet' => $textMatch['snippet'],
            );
          }
        }
      }
      if (count($fieldMatches) > 0) {
        $matches[] = array(
          'id' => isset($item->id) ? $item->id : null,
          'title' => isset($item->title) ? $item->title : '',
          'slug' => isset($item->slug) ? $item->slug : '',
          'location' => isset($item->location) ? $item->location : '',
          'parent' => isset($item->parent) ? $item->parent : null,
          'description' => isset($item->description) ? $item->description : '',
          'tags' => $this->siteSearchTagsValue($item),
          'matches' => $fieldMatches,
        );
      }
    }
    $response['data']['matches'] = $matches;
    $response['data']['total'] = count($matches);
    return $response;
  }
}
