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
    if (
      !(
        isset($this->params['site_token']) &&
        $GLOBALS['HAXCMS']->validateRequestToken(
          $this->params['site_token'],
          $GLOBALS['HAXCMS']->getActiveUserName() . ':' . $siteName
        )
      )
    ) {
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
    $operation = $this->normalizeSiteSearchOperation(
      isset($this->params['operation']) ? $this->params['operation'] : null
    );
    if ($operation == 'replace' && strlen($searchTerm) <= 1) {
      return array(
        '__failed' => array(
          'status' => 400,
          'message' => 'Search text must be more than 1 character for replacement operations',
        )
      );
    }
    $selectorMode = false;
    if ($operation != 'replace') {
      $selectorMode = $this->parseSiteSearchBoolean(
        isset($this->params['searchSelector']) ? $this->params['searchSelector'] : false
      );
      if (
        !$selectorMode &&
        isset($this->params['searchMode']) &&
        strtolower((string) $this->params['searchMode']) == 'selector'
      ) {
        $selectorMode = true;
      }
    }
    $caseSensitive = $this->parseSiteSearchBoolean(
      isset($this->params['searchCaseSensitive']) ? $this->params['searchCaseSensitive'] : false
    );
    $searchLimit = $this->parseSiteSearchLimit(
      isset($this->params['searchLimit']) ? $this->params['searchLimit'] : null,
      25
    );
    $searchFields = $this->normalizeSiteSearchFields(
      isset($this->params['searchField']) ? $this->params['searchField'] : null
    );
    if ($selectorMode) {
      $searchFields = array('content');
    }
    if ($operation == 'replace') {
      $searchFields = array('content');
    }
    $mode = 'text';
    if ($selectorMode) {
      $mode = 'selector';
    }
    if ($operation == 'replace') {
      $mode = 'replace';
    }
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
        'operation' => $operation,
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
    if ($operation == 'replace') {
      $replacement = '';
      if (isset($this->params['replace'])) {
        $replacement = trim((string) $this->params['replace']);
      }
      if (strlen($replacement) == 1) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'Replacement text must be empty or more than 1 character',
          )
        );
      }
      $replaceConfirmed = $this->parseSiteSearchBoolean(
        isset($this->params['replaceConfirm']) ? $this->params['replaceConfirm'] : false
      );
      if (!$replaceConfirmed) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'Replacement requires confirmation',
          )
        );
      }
      if ($replacement === '') {
        $destroyConfirmed = $this->parseSiteSearchBoolean(
          isset($this->params['replaceDestroyConfirm']) ? $this->params['replaceDestroyConfirm'] : false
        );
        if (!$destroyConfirmed) {
          return array(
            '__failed' => array(
              'status' => 400,
              'message' => 'Removing matched text requires a second confirmation',
            )
          );
        }
      }
      $siteDirectory = HAXCMS_ROOT . '/' . $GLOBALS['HAXCMS']->sitesDirectory . '/' . $site->manifest->metadata->site->name . '/';
      $totalMatches = 0;
      $updatedItems = 0;
      $totalReplacements = 0;
      $changedItems = array();
      foreach ($items as $item) {
        if (!isset($item->id) || $item->id == '') {
          continue;
        }
        $page = $site->loadNode($item->id);
        if (!$page) {
          continue;
        }
        $content = $site->getPageContent($page);
        if (!is_string($content)) {
          $content = '';
        }
        $replacementData = $this->siteSearchTextReplace(
          $content,
          $searchTerm,
          $replacement,
          $caseSensitive
        );
        $replacementCount = isset($replacementData['total'])
          ? intval($replacementData['total'])
          : 0;
        if ($replacementCount < 1) {
          continue;
        }
        $totalMatches += $replacementCount;
        $replacedContent = isset($replacementData['content'])
          ? $replacementData['content']
          : '';
        $sanitizedContent = SanitizeContent::sanitizeHTMLForStorage($replacedContent);
        $writeResult = $page->writeLocation($sanitizedContent, $siteDirectory);
        if ($writeResult === false) {
          continue;
        }
        $updatedItems++;
        $totalReplacements += $replacementCount;
        if (!isset($page->metadata) || !is_object($page->metadata)) {
          $page->metadata = new stdClass();
        }
        $page->metadata->updated = time();
        if (isset($page->location) && $page->location != '') {
          $GLOBALS['HAXCMS']->staticCache(
            'getPageContent' . $page->location,
            null,
            true
          );
        }
        $changedItems[] = array(
          'id' => isset($item->id) ? $item->id : '',
          'title' => isset($item->title) ? $item->title : '',
          'slug' => isset($item->slug) ? $item->slug : '',
          'replacements' => $replacementCount,
        );
        try {
          $site->writePageAlternateFormats($page, $sanitizedContent);
        }
        catch (Exception $e) {}
      }
      if ($totalMatches < 1) {
        return array(
          '__failed' => array(
            'status' => 400,
            'message' => 'Search term not found in site content',
          )
        );
      }
      if ($updatedItems < 1) {
        return array(
          '__failed' => array(
            'status' => 500,
            'message' => 'No pages could be updated',
          )
        );
      }
      if (!isset($site->manifest->metadata) || !is_object($site->manifest->metadata)) {
        $site->manifest->metadata = new stdClass();
      }
      if (!isset($site->manifest->metadata->site) || !is_object($site->manifest->metadata->site)) {
        $site->manifest->metadata->site = new stdClass();
      }
      $site->manifest->metadata->site->updated = time();
      $site->manifest->save();
      $site->updateAlternateFormats();
      $commitReplacement = $replacement;
      if ($commitReplacement === '') {
        $commitReplacement = '[removed]';
      }
      $pageLabelSuffix = '';
      if ($updatedItems !== 1) {
        $pageLabelSuffix = 's';
      }
      $site->gitCommit(
        'Bulk replace "' .
        $searchTerm .
        '" -> "' .
        $commitReplacement .
        '" across ' .
        $updatedItems .
        ' page' .
        $pageLabelSuffix
      );
      return array(
        'status' => 200,
        'data' => array(
          'operation' => 'replace',
          'query' => $searchTerm,
          'replace' => $replacement,
          'caseSensitive' => $caseSensitive,
          'total' => $totalMatches,
          'updatedItems' => $updatedItems,
          'totalReplacements' => $totalReplacements,
          'items' => $changedItems,
        ),
      );
    }
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
          $textMatch = $this->siteSearchTextMatch(
            $fieldValue,
            $searchTerm,
            $caseSensitive
          );
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
