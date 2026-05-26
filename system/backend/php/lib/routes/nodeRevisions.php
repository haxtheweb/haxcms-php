<?php
trait OperationsRouteNodeRevisions {
  private function nodeRevisionFailed($status, $message) {
    return array(
      '__failed' => array(
        'status' => $status,
        'message' => $message,
      )
    );
  }
  private function normalizeNodeRevisionLocation($location) {
    if (!is_string($location)) {
      return false;
    }
    $normalized = str_replace('\\', '/', $location);
    $normalized = preg_replace('/^\/+/', '', $normalized);
    $normalized = preg_replace('/\/+/', '/', $normalized);
    $normalized = trim($normalized);
    if (
      $normalized === '' ||
      strpos($normalized, "\0") !== false ||
      strpos($normalized, '..') !== false
    ) {
      return false;
    }
    $parts = explode('/', $normalized);
    foreach ($parts as $part) {
      if ($part === '' || $part === '.' || $part === '..') {
        return false;
      }
    }
    return $normalized;
  }
  private function resolveNodeRevisionFileContext($site, $location) {
    if (
      !isset($site->directory) ||
      !isset($site->manifest) ||
      !isset($site->manifest->metadata) ||
      !isset($site->manifest->metadata->site) ||
      !isset($site->manifest->metadata->site->name)
    ) {
      return false;
    }
    $siteRoot = realpath(
      $site->directory . '/' . $site->manifest->metadata->site->name
    );
    if ($siteRoot === false || !is_dir($siteRoot)) {
      return false;
    }
    $normalizedLocation = $this->normalizeNodeRevisionLocation($location);
    if ($normalizedLocation === false) {
      return false;
    }
    $candidatePath = $siteRoot . '/' . $normalizedLocation;
    $resolvedPath = realpath($candidatePath);
    if ($resolvedPath === false || !is_file($resolvedPath)) {
      return false;
    }
    $normalizedSiteRoot = rtrim(str_replace('\\', '/', $siteRoot), '/');
    $normalizedResolvedPath = str_replace('\\', '/', $resolvedPath);
    if (
      $normalizedResolvedPath !== $normalizedSiteRoot &&
      strpos($normalizedResolvedPath, $normalizedSiteRoot . '/') !== 0
    ) {
      return false;
    }
    return array(
      'siteRoot' => $normalizedSiteRoot,
      'location' => $normalizedLocation,
      'absolutePath' => $normalizedResolvedPath,
    );
  }
  private function runNodeRevisionGitCommand($siteDirectory, $args, $trim = true) {
    $git = new Git();
    $gitBin = Git::get_bin();
    if (!is_string($gitBin) || $gitBin === '') {
      $gitBin = 'git';
    }
    $parts = array_merge(array($gitBin, '--no-pager'), $args);
    $escapedParts = array();
    foreach ($parts as $part) {
      $escapedParts[] = escapeshellarg((string) $part);
    }
    $command = implode(' ', $escapedParts);
    $descriptorSpec = array(
      1 => array('pipe', 'w'),
      2 => array('pipe', 'w'),
    );
    $pipes = array();
    $process = proc_open($command, $descriptorSpec, $pipes, $siteDirectory);
    if (!is_resource($process)) {
      throw new Exception('Unable to execute git command');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    foreach ($pipes as $pipe) {
      fclose($pipe);
    }
    $status = proc_close($process);
    if ($status !== 0) {
      $message = trim((string) $stderr);
      if ($message === '') {
        $message = 'Git command failed';
      }
      throw new Exception($message);
    }
    if ($trim) {
      return trim((string) $stdout);
    }
    return (string) $stdout;
  }
  private function parseNodeRevisionLimit($value) {
    $limit = filter_var($value, FILTER_VALIDATE_INT);
    if ($limit === false || $limit < 1) {
      $limit = 25;
    }
    if ($limit > 200) {
      $limit = 200;
    }
    return $limit;
  }
  private function parseNodeRevisionOffset($value) {
    $offset = filter_var($value, FILTER_VALIDATE_INT);
    if ($offset === false || $offset < 0) {
      $offset = 0;
    }
    return $offset;
  }
  private function buildNodeRevisionContext($siteName, $nodeId) {
    if (
      !isset($this->params['site_token']) ||
      !$GLOBALS['HAXCMS']->validateRequestToken(
        $this->params['site_token'],
        $GLOBALS['HAXCMS']->getActiveUserName() . ':' . $siteName
      )
    ) {
      return $this->nodeRevisionFailed(403, 'Invalid site token');
    }
    $site = $GLOBALS['HAXCMS']->loadSite($siteName);
    if (!$site) {
      return $this->nodeRevisionFailed(404, 'Site not found');
    }
    $page = $site->loadNode($nodeId);
    if (!$page) {
      return $this->nodeRevisionFailed(404, 'Node not found');
    }
    $fileContext = $this->resolveNodeRevisionFileContext(
      $site,
      isset($page->location) ? $page->location : ''
    );
    if ($fileContext === false) {
      return $this->nodeRevisionFailed(400, 'Invalid node file location');
    }
    return array(
      'site' => $site,
      'page' => $page,
      'fileContext' => $fileContext,
    );
  }
  /**
   * @OA\Post(
   *    path="/getNodeRevisions",
   *    tags={"cms","authenticated","node","git"},
   *    @OA\Parameter(
   *         name="site_token",
   *         description="Site-specific validation token",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="List git revisions for a single node file"
   *   )
   * )
   */
  public function getNodeRevisions() {
    if (
      !isset($this->params['site']) ||
      !is_array($this->params['site']) ||
      !isset($this->params['site']['name']) ||
      !isset($this->params['node']) ||
      !is_array($this->params['node']) ||
      !isset($this->params['node']['id'])
    ) {
      return $this->nodeRevisionFailed(
        400,
        'Missing required body fields: site.name and node.id'
      );
    }
    $siteName = trim((string) $this->params['site']['name']);
    $nodeId = trim((string) $this->params['node']['id']);
    if ($siteName === '' || $nodeId === '') {
      return $this->nodeRevisionFailed(
        400,
        'Missing required body fields: site.name and node.id'
      );
    }
    $context = $this->buildNodeRevisionContext($siteName, $nodeId);
    if (isset($context['__failed'])) {
      return $context;
    }
    $limit = $this->parseNodeRevisionLimit(
      isset($this->params['limit']) ? $this->params['limit'] : null
    );
    $offset = $this->parseNodeRevisionOffset(
      isset($this->params['offset']) ? $this->params['offset'] : null
    );
    $logFormat = '%H%x1f%h%x1f%an%x1f%ae%x1f%at%x1f%ad%x1f%s';
    try {
      $logRaw = $this->runNodeRevisionGitCommand(
        $context['fileContext']['siteRoot'],
        array(
          'log',
          '--date=iso-strict',
          '--pretty=format:' . $logFormat,
          '--max-count=' . $limit,
          '--skip=' . $offset,
          '--',
          $context['fileContext']['location'],
        ),
        false
      );
      $revisions = array();
      if ($logRaw !== '') {
        $lines = explode("\n", $logRaw);
        foreach ($lines as $line) {
          if ($line === '') {
            continue;
          }
          $parts = explode("\x1f", $line);
          if (count($parts) < 7) {
            continue;
          }
          $timestamp = intval($parts[4]);
          $revisions[] = array(
            'revisionNumber' => $offset + count($revisions) + 1,
            'hash' => $parts[0],
            'shortHash' => $parts[1],
            'author' => $parts[2],
            'authorEmail' => $parts[3],
            'timestamp' => $timestamp > 0 ? $timestamp : 0,
            'date' => $parts[5],
            'message' => $parts[6],
          );
        }
      }
      $total = count($revisions);
      try {
        $totalRaw = $this->runNodeRevisionGitCommand(
          $context['fileContext']['siteRoot'],
          array(
            'log',
            '--pretty=format:%H',
            '--',
            $context['fileContext']['location'],
          ),
          false
        );
        if (trim($totalRaw) === '') {
          $total = 0;
        }
        else {
          $totalLines = array_filter(
            array_map('trim', explode("\n", $totalRaw)),
            function($item) {
              return $item !== '';
            }
          );
          $total = count($totalLines);
        }
      }
      catch (Exception $e) {}
      return array(
        'status' => 200,
        'data' => array(
          'nodeId' => $context['page']->id,
          'nodeTitle' => isset($context['page']->title) ? $context['page']->title : '',
          'limit' => $limit,
          'offset' => $offset,
          'total' => $total,
          'revisions' => $revisions,
        ),
      );
    }
    catch (Exception $e) {
      return $this->nodeRevisionFailed(
        500,
        $e->getMessage() ? $e->getMessage() : 'Unable to load node revisions'
      );
    }
  }
  /**
   * @OA\Post(
   *    path="/getNodeRevision",
   *    tags={"cms","authenticated","node","git"},
   *    @OA\Parameter(
   *         name="site_token",
   *         description="Site-specific validation token",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Get page content for a specific git hash"
   *   )
   * )
   */
  public function getNodeRevision() {
    if (
      !isset($this->params['site']) ||
      !is_array($this->params['site']) ||
      !isset($this->params['site']['name']) ||
      !isset($this->params['node']) ||
      !is_array($this->params['node']) ||
      !isset($this->params['node']['id']) ||
      !isset($this->params['hash'])
    ) {
      return $this->nodeRevisionFailed(
        400,
        'Missing required body fields: site.name, node.id and hash'
      );
    }
    $siteName = trim((string) $this->params['site']['name']);
    $nodeId = trim((string) $this->params['node']['id']);
    $hash = trim((string) $this->params['hash']);
    if ($siteName === '' || $nodeId === '' || $hash === '') {
      return $this->nodeRevisionFailed(
        400,
        'Missing required body fields: site.name, node.id and hash'
      );
    }
    if (preg_match('/^[a-fA-F0-9]{7,64}$/', $hash) !== 1) {
      return $this->nodeRevisionFailed(400, 'Invalid revision hash');
    }
    $context = $this->buildNodeRevisionContext($siteName, $nodeId);
    if (isset($context['__failed'])) {
      return $context;
    }
    try {
      $metadataRaw = $this->runNodeRevisionGitCommand(
        $context['fileContext']['siteRoot'],
        array(
          'show',
          '--quiet',
          '--date=iso-strict',
          '--pretty=format:%H%x1f%h%x1f%an%x1f%ae%x1f%at%x1f%ad%x1f%s',
          $hash,
        ),
        false
      );
      if (trim($metadataRaw) === '') {
        return $this->nodeRevisionFailed(404, 'Revision not found');
      }
      $metadataParts = explode("\x1f", trim($metadataRaw));
      $revisionMetadata = array(
        'hash' => isset($metadataParts[0]) && $metadataParts[0] !== '' ? $metadataParts[0] : $hash,
        'shortHash' => isset($metadataParts[1]) && $metadataParts[1] !== '' ? $metadataParts[1] : substr($hash, 0, 7),
        'author' => isset($metadataParts[2]) ? $metadataParts[2] : '',
        'authorEmail' => isset($metadataParts[3]) ? $metadataParts[3] : '',
        'timestamp' => isset($metadataParts[4]) ? intval($metadataParts[4]) : 0,
        'date' => isset($metadataParts[5]) ? $metadataParts[5] : '',
        'message' => isset($metadataParts[6]) ? $metadataParts[6] : '',
      );
      $fileContent = $this->runNodeRevisionGitCommand(
        $context['fileContext']['siteRoot'],
        array(
          'show',
          $hash . ':' . $context['fileContext']['location'],
        ),
        false
      );
      return array(
        'status' => 200,
        'data' => array(
          'nodeId' => $context['page']->id,
          'nodeTitle' => isset($context['page']->title) ? $context['page']->title : '',
          'revision' => $revisionMetadata,
          'content' => $fileContent,
        ),
      );
    }
    catch (Exception $e) {
      $message = $e->getMessage() ? $e->getMessage() : '';
      if (
        strpos($message, 'does not exist') !== false ||
        strpos($message, 'exists on disk, but not in') !== false ||
        strpos($message, 'bad object') !== false ||
        strpos($message, 'unknown revision') !== false
      ) {
        return $this->nodeRevisionFailed(404, 'Revision content for this page was not found');
      }
      return $this->nodeRevisionFailed(
        500,
        $message !== '' ? $message : 'Unable to load node revision'
      );
    }
  }
  /**
   * @OA\Post(
   *    path="/restoreNodeRevision",
   *    tags={"cms","authenticated","node","git"},
   *    @OA\Parameter(
   *         name="site_token",
   *         description="Site-specific validation token",
   *         in="query",
   *         required=true,
   *         @OA\Schema(type="string")
   *    ),
   *    @OA\Response(
   *        response="200",
   *        description="Restore a page revision as a new commit"
   *   )
   * )
   */
  public function restoreNodeRevision() {
    if (
      !isset($this->params['site']) ||
      !is_array($this->params['site']) ||
      !isset($this->params['site']['name']) ||
      !isset($this->params['node']) ||
      !is_array($this->params['node']) ||
      !isset($this->params['node']['id']) ||
      !isset($this->params['hash'])
    ) {
      return $this->nodeRevisionFailed(
        400,
        'Missing required body fields: site.name, node.id and hash'
      );
    }
    $siteName = trim((string) $this->params['site']['name']);
    $nodeId = trim((string) $this->params['node']['id']);
    $hash = trim((string) $this->params['hash']);
    if ($siteName === '' || $nodeId === '' || $hash === '') {
      return $this->nodeRevisionFailed(
        400,
        'Missing required body fields: site.name, node.id and hash'
      );
    }
    if (preg_match('/^[a-fA-F0-9]{7,64}$/', $hash) !== 1) {
      return $this->nodeRevisionFailed(400, 'Invalid revision hash');
    }
    $context = $this->buildNodeRevisionContext($siteName, $nodeId);
    if (isset($context['__failed'])) {
      return $context;
    }
    try {
      $revisionContent = $this->runNodeRevisionGitCommand(
        $context['fileContext']['siteRoot'],
        array(
          'show',
          $hash . ':' . $context['fileContext']['location'],
        ),
        false
      );
      $siteDirectory = $context['site']->directory . '/' . $context['site']->manifest->metadata->site->name . '/';
      $bytes = $context['page']->writeLocation($revisionContent, $siteDirectory);
      if ($bytes === false) {
        return $this->nodeRevisionFailed(500, 'Failed writing restored revision');
      }
      $context['site']->writePageAlternateFormats($context['page'], $revisionContent);
      if (!isset($context['page']->metadata) || !is_object($context['page']->metadata)) {
        $context['page']->metadata = new stdClass();
      }
      if (!isset($context['site']->manifest->metadata) || !is_object($context['site']->manifest->metadata)) {
        $context['site']->manifest->metadata = new stdClass();
      }
      if (!isset($context['site']->manifest->metadata->site) || !is_object($context['site']->manifest->metadata->site)) {
        $context['site']->manifest->metadata->site = new stdClass();
      }
      $now = time();
      $context['page']->metadata->updated = $now;
      $context['site']->manifest->metadata->site->updated = $now;
      $context['site']->manifest->save(false);
      $context['site']->gitCommit(
        'Page revision restored: ' .
        (isset($context['page']->title) && $context['page']->title !== '' ? $context['page']->title : 'Untitled') .
        ' (' .
        $context['page']->id .
        ') from ' .
        substr($hash, 0, 12)
      );
      return array(
        'status' => 200,
        'data' => array(
          'nodeId' => $context['page']->id,
          'nodeTitle' => isset($context['page']->title) ? $context['page']->title : '',
          'restoredFromHash' => $hash,
        ),
      );
    }
    catch (Exception $e) {
      $message = $e->getMessage() ? $e->getMessage() : '';
      if (
        strpos($message, 'does not exist') !== false ||
        strpos($message, 'exists on disk, but not in') !== false ||
        strpos($message, 'bad object') !== false ||
        strpos($message, 'unknown revision') !== false
      ) {
        return $this->nodeRevisionFailed(404, 'Revision content for this page was not found');
      }
      return $this->nodeRevisionFailed(
        500,
        $message !== '' ? $message : 'Unable to restore node revision'
      );
    }
  }
}
