<?php
include_once dirname(__FILE__) . '/../SiteRouteUtils.php';
include_once dirname(__FILE__) . '/../../BlockOperations.php';
include_once dirname(__FILE__) . '/../../FileContentScanner.php';
include_once dirname(__FILE__) . '/../../LinkChecker.php';

if (!class_exists('ContentBlocksRoute')) {
    /**
     * Helpers for x/api/v1/content/{idOrSlug}/blocks. Mirrors haxcms-nodejs
     * src/siteRoutes/v1/contentBlocks.js.
     */
    class ContentBlocksRoute
    {
        public static function isLocked($page)
        {
            return isset($page->metadata) && is_object($page->metadata) && isset($page->metadata->locked) &&
                ($page->metadata->locked === true || $page->metadata->locked === 'true');
        }

        // "alt on 3 img, href on 1 a" for the commit message
        public static function summarize($ops)
        {
            $counts = array();
            foreach ($ops as $op) {
                $tag = strtolower(isset($op['tag']) && is_string($op['tag']) ? $op['tag'] : '');
                $what = 'block';
                if (isset($op['op']) && $op['op'] === 'setAttributes' && isset($op['set']) && is_array($op['set'])) {
                    $names = array();
                    foreach (array_keys($op['set']) as $name) {
                        $names[] = strtolower((string) $name);
                    }
                    sort($names, SORT_STRING);
                    $what = implode('+', $names);
                }
                $key = $what . ' on ' . $tag;
                $counts[$key] = isset($counts[$key]) ? $counts[$key] + 1 : 1;
            }
            $parts = array();
            foreach ($counts as $key => $n) {
                $pair = explode(' on ', $key, 2);
                $parts[] = $pair[0] . ' on ' . $n . ' ' . $pair[1];
            }
            return implode(', ', $parts);
        }

        public static function normalizeNote($value)
        {
            if (!is_string($value)) {
                return '';
            }
            // a git commit message: one line of plain text, no < or >, no
            // control characters
            $value = preg_replace('/[\r\n\t]+/', ' ', $value);
            $value = preg_replace('/[<>\x00-\x1f\x7f]/', '', $value);
            $value = trim($value);
            return mb_substr($value, 0, 120, 'UTF-8');
        }

        /**
         * When a media URL changes, the page's image/video lists follow it.
         */
        public static function followMediaUrls($page, $ops, $results)
        {
            if (!isset($page->metadata) || !is_object($page->metadata)) {
                return;
            }
            foreach (array('images', 'videos') as $key) {
                if (!isset($page->metadata->{$key}) || !is_array($page->metadata->{$key})) {
                    continue;
                }
                foreach ($ops as $i => $op) {
                    $r = isset($results[$i]) ? $results[$i] : null;
                    if (!isset($op['op']) || $op['op'] !== 'setAttributes' || !$r || empty($r['set']) || !isset($r['previous'])) {
                        continue;
                    }
                    foreach ($r['set'] as $name => $after) {
                        $before = isset($r['previous'][$name]) ? $r['previous'][$name] : null;
                        if (!is_string($before) || $before === '' || !is_string($after) || $after === '') {
                            continue;
                        }
                        $list = array();
                        foreach ($page->metadata->{$key} as $v) {
                            $list[] = $v === $before ? $after : $v;
                        }
                        $page->metadata->{$key} = $list;
                    }
                }
            }
        }

        /**
         * One block write per page at a time, across PHP processes.
         */
        public static function withPageLock($key, $fn)
        {
            $path = rtrim(sys_get_temp_dir(), '/') . '/haxcms-blocks-' . sha1($key) . '.lock';
            $handle = @fopen($path, 'c');
            if ($handle === false) {
                return $fn();
            }
            try {
                flock($handle, LOCK_EX);
                return $fn();
            }
            finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }

        // the HAXCMS settings, or the defaults when HAXCMS is not loaded
        public static function rateSettings()
        {
            if (isset($GLOBALS['HAXCMS']) && method_exists($GLOBALS['HAXCMS'], 'getBlockOperationsRateLimitSettings')) {
                return $GLOBALS['HAXCMS']->getBlockOperationsRateLimitSettings();
            }
            $settings = new stdClass();
            $settings->enabled = true;
            $settings->windowMs = 60 * 1000;
            $settings->max = 1000;
            return $settings;
        }

        public static function selfLink($apiBasePath, $slugOrId)
        {
            return $apiBasePath . '/v1/content/' . rawurlencode((string) $slugOrId) . '/blocks';
        }
    }
}

/**
 * x/api/v1/content/{idOrSlug}/blocks (haxtheweb/issues#3270)
 *
 * GET   lists the allowlisted elements on a page with the { tag, index,
 *       before } a client sends back to change one.
 * PATCH { ops: [...], note? } changes those elements without re-saving the
 *       page; see lib/BlockOperations.php for the rules. All ops succeed or
 *       none do; one git commit per page.
 */
return function ($context) {
    $site = isset($context->site) ? $context->site : null;
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/x/api';
    $send = function ($statusCode, $data) use ($context, $apiBasePath) {
        SiteRouteUtils::sendFormattedResponse(
            $data,
            array(
                'statusCode' => $statusCode,
                'allowedFormats' => array('json'),
                'defaultFormat' => 'json',
            ),
            $context->routeSuffix,
            $apiBasePath
        );
    };
    if (!isset($site) || !isset($site->manifest)) {
        $send(404, array('message' => 'Unable to resolve site context for /x/api/v1/content/:idOrSlug/blocks'));
        return;
    }
    $idOrSlug = (string) $context->getParam('idOrSlug', '');
    $item = SiteRouteUtils::findItemByIdOrSlug($site, $idOrSlug);
    if (!$item || !isset($item->id) || $item->id === '') {
        $send(404, array('message' => 'Content not found for idOrSlug "' . $idOrSlug . '"'));
        return;
    }
    $method = strtoupper((string) (isset($context->method) ? $context->method : 'GET'));
    if ($method !== 'PATCH') {
        $tag = strtolower((string) SiteRouteUtils::getQueryValue('tag', ''));
        $html = $site->getPageContent($item);
        $blocks = BlockOperations::listBlocks(is_string($html) ? $html : '', $tag);
        $send(200, array(
            'id' => $item->id,
            'slug' => isset($item->slug) ? $item->slug : '',
            'count' => count($blocks),
            'blocks' => $blocks,
            'links' => array(
                'self' => ContentBlocksRoute::selfLink($apiBasePath, isset($item->slug) && $item->slug !== '' ? $item->slug : $item->id),
            ),
        ));
        return;
    }
    $body = $context->getBody();
    $ops = is_array($body) && isset($body['ops']) && is_array($body['ops']) ? $body['ops'] : null;
    if (!is_array($ops) || count($ops) === 0 || array_keys($ops) !== range(0, count($ops) - 1)) {
        $send(400, array('message' => 'Missing `ops` param'));
        return;
    }
    if (count($ops) > BlockOperations::MAX_OPS) {
        $send(400, array('message' => 'invalid ops value (at most ' . BlockOperations::MAX_OPS . ' per page)'));
        return;
    }
    $page = $site->loadNode($item->id);
    if (!$page) {
        $send(404, array('message' => 'Content not found for idOrSlug "' . $idOrSlug . '"'));
        return;
    }
    if (ContentBlocksRoute::isLocked($page)) {
        $send(423, array('message' => 'Page "' . $idOrSlug . '" is locked', 'reason' => 'locked'));
        return;
    }
    $note = ContentBlocksRoute::normalizeNote(isset($body['note']) ? $body['note'] : '');
    $siteName = isset($site->manifest->metadata->site->name) ? $site->manifest->metadata->site->name : '';
    $siteDirectory = $site->directory . '/' . $siteName . '/';
    ContentBlocksRoute::withPageLock($siteDirectory . '::' . $page->id, function () use ($site, $page, $item, $ops, $note, $siteDirectory, $send, $apiBasePath) {
        $html = (string) $site->getPageContent($page);
        $outcome = BlockOperations::runBlockOperations($html, $ops);
        if (!$outcome['ok']) {
            $opIndex = isset($outcome['op']) && is_int($outcome['op']) ? $outcome['op'] : 0;
            if ($outcome['code'] === 'stale' || $outcome['code'] === 'ambiguous') {
                $send(409, array(
                    'message' => 'Block ' . $opIndex . ' no longer matches the stored page',
                    'reason' => $outcome['code'],
                    'detail' => $outcome['message'],
                    'op' => $opIndex,
                ));
                return;
            }
            $send(400, array(
                'message' => 'Block ' . $opIndex . ' refused (' . $outcome['code'] . '): ' . $outcome['message'],
                'reason' => $outcome['code'],
                'detail' => $outcome['message'],
                'op' => $opIndex,
            ));
            return;
        }
        // A budget of block changes per site per window, charged only for a
        // change that passed every check and is about to be written, so a
        // script or AI agent cannot flood the site with commits while refused
        // and stale requests cost nothing (Node contentBlocks.js).
        $rateSettings = ContentBlocksRoute::rateSettings();
        $cache = (isset($GLOBALS['HAXCMS']) && isset($GLOBALS['HAXCMS']->cache) && $GLOBALS['HAXCMS']->cache) ? $GLOBALS['HAXCMS']->cache : null;
        $budget = LinkChecker::changeBudget($cache, 'blocks::' . $siteDirectory, count($ops), intval(round(microtime(true) * 1000)), $rateSettings);
        if (!$budget['allowed']) {
            $retryAfter = intval($budget['retryAfterSeconds']);
            @header('Retry-After: ' . $retryAfter);
            $send(429, array(
                'message' => 'Block change limit reached: ' . intval($rateSettings->max) . ' changes per ' . intval(round(intval($rateSettings->windowMs) / 1000)) . ' seconds for this site. You can retry in ' . $retryAfter . ' seconds.',
                'reason' => 'rate-limited',
                'retryAfter' => $retryAfter,
                'remaining' => intval($budget['remaining']),
            ));
            return;
        }
        // what each set attribute held before, for metadata that follows URLs
        $scanBefore = BlockOperations::scanElements($html);
        $results = $outcome['results'];
        foreach ($results as $k => $r) {
            if (empty($r['set'])) {
                continue;
            }
            $same = array();
            foreach ($scanBefore as $e) {
                if ($e['tag'] === $r['tag']) {
                    $same[] = $e;
                }
            }
            $previous = array();
            if (isset($same[$r['index']])) {
                $el = $same[$r['index']];
                $decoded = BlockOperations::listBlocks(substr($html, $el['start'], $el['end'] - $el['start']), $r['tag']);
                if (count($decoded)) {
                    $previous = (array) $decoded[0]['attributes'];
                }
            }
            $results[$k]['previous'] = $previous;
        }
        $bytes = $page->writeLocation($outcome['content'], $siteDirectory);
        if ($bytes === false) {
            $send(500, array('message' => 'failed to write'));
            return;
        }
        FileContentScanner::rebuildPageFilesUuids($site, $page, $outcome['content']);
        if (!isset($page->metadata) || !is_object($page->metadata)) {
            $page->metadata = new stdClass();
        }
        ContentBlocksRoute::followMediaUrls($page, array_values($ops), $results);
        $now = time();
        $page->metadata->updated = $now;
        $site->updateNode($page, false);
        if (method_exists($site, 'writePageAlternateFormats')) {
            $site->writePageAlternateFormats($page, $outcome['content']);
        }
        if (isset($site->manifest->metadata->site) && is_object($site->manifest->metadata->site)) {
            $site->manifest->metadata->site->updated = $now;
        }
        $site->manifest->save(false);
        $site->gitCommit(
            'Block operations: ' . ContentBlocksRoute::summarize($ops) . ' on ' .
            (isset($page->title) && $page->title !== '' ? $page->title : 'Untitled') .
            ' (' . $page->id . ')' . ($note !== '' ? ': ' . $note : '')
        );
        $out = array();
        foreach ($outcome['results'] as $i => $r) {
            $out[] = array(
                'op' => $i,
                'tag' => $r['tag'],
                'index' => $r['index'],
                'relocated' => $r['relocated'],
                'before' => substr($outcome['content'], $r['start'], $r['end'] - $r['start']),
            );
        }
        $slug = isset($page->slug) && $page->slug !== '' ? $page->slug : (isset($item->slug) ? $item->slug : '');
        $send(200, array(
            'id' => $page->id,
            'slug' => $slug,
            'title' => isset($page->title) ? $page->title : '',
            'count' => count($out),
            'results' => $out,
            'links' => array(
                'self' => ContentBlocksRoute::selfLink($apiBasePath, $slug !== '' ? $slug : $page->id),
            ),
        ));
    });
};
