<?php
include_once dirname(__FILE__) . '/../../Operations.php';
return function ($context) {
    $body = $context->getBody();
    if (!is_array($body)) {
        $body = array();
    }
    unset($body['jwt']);
    unset($body['user_token']);
    unset($body['site_token']);
    $siteName = '';
    if (
        isset($context->site) &&
        isset($context->site->manifest) &&
        isset($context->site->manifest->metadata) &&
        isset($context->site->manifest->metadata->site) &&
        isset($context->site->manifest->metadata->site->name)
    ) {
        $siteName = (string) $context->site->manifest->metadata->site->name;
    }
    if (!isset($body['site']) || !is_array($body['site'])) {
        $body['site'] = array();
    }
    // Security (SEC-26): force the site name to the resolved site so a client
    // cannot target a foreign site (IDOR defense-in-depth).
    $body['site']['name'] = $siteName;
    $siteToken = $context->getHeader('X-HAXCMS-Site-Token');
    if (!is_string($siteToken)) {
        $siteToken = '';
    }
    $body['site_token'] = $siteToken;
    $idOrSlug = $context->getParam('idOrSlug', '');
    $method = strtoupper((string) $context->method);
    $operations = new Operations();
    $result = null;
    $resolvedItem = null;
    $resolvedItemId = $idOrSlug;
    if (($method === 'PATCH' || $method === 'DELETE') && $idOrSlug !== '' && isset($context->site)) {
        $resolvedItem = SiteRouteUtils::findItemByIdOrSlug($context->site, $idOrSlug);
        if (!$resolvedItem) {
            SiteRouteUtils::sendFormattedResponse(
                array(
                    'message' => 'Item not found for idOrSlug "' . $idOrSlug . '"',
                ),
                array(
                    'statusCode' => 404,
                    'allowedFormats' => array('json'),
                    'defaultFormat' => 'json',
                ),
                $context->routeSuffix,
                $context->apiBasePath
            );
            return;
        }
        if (isset($resolvedItem->id) && is_string($resolvedItem->id) && $resolvedItem->id !== '') {
            $resolvedItemId = $resolvedItem->id;
        }
    }
    if ($method === 'POST') {
        // E11: validate node/items payload presence mirroring Node items.js
        // createItem (555-563). Previously PHP delegated with no 400, so an
        // empty POST body silently created a blank node.
        $hasItemsPayload = isset($body['items']) && is_array($body['items']) && count($body['items']) > 0;
        $hasNodePayload = isset($body['node']) && is_array($body['node']);
        if (!$hasItemsPayload && !$hasNodePayload) {
            SiteRouteUtils::sendFormattedResponse(
                array(
                    'message' => 'Node payload is required',
                ),
                array(
                    'statusCode' => 400,
                    'allowedFormats' => array('json'),
                    'defaultFormat' => 'json',
                ),
                $context->routeSuffix,
                $context->apiBasePath
            );
            return;
        }
        $operations->params = $body;
        $operations->rawParams = $body;
        $result = $operations->createNode();
    } else if ($method === 'PATCH') {
        if (!isset($body['node']) || !is_array($body['node'])) {
            $body['node'] = array();
        }
        if (!isset($body['node']['id']) || $body['node']['id'] === '') {
            $body['node']['id'] = $resolvedItemId;
        }
        // D51: read top-level body.operation only (Node canonical).
        // The previous fallback to body.node.details.operation has been removed.
        $operation = '';
        if (isset($body['operation']) && is_string($body['operation'])) {
            $operation = trim($body['operation']);
        }
        if ($operation === '') {
            SiteRouteUtils::sendFormattedResponse(
                array(
                    'message' => 'Operation is required',
                ),
                array(
                    'statusCode' => 400,
                    'allowedFormats' => array('json'),
                    'defaultFormat' => 'json',
                ),
                $context->routeSuffix,
                $context->apiBasePath
            );
            return;
        }
        if (!isset($body['node']['details']) || !is_array($body['node']['details'])) {
            $body['node']['details'] = array();
        }
        // E10: stop honoring legacy nested node.details.* — reset to only the
        // operation so client-supplied node.details values are not preserved.
        // Align to the spec's top-level shape, matching Node
        // nodeDetailOperations.js which reads from the top-level payload only.
        $body['node']['details'] = array();
        $body['node']['details']['operation'] = $operation;
        $operationDetailKeys = array(
            'parent',
            'order',
            'title',
            'description',
            'tags',
            'icon',
            'media',
            'image',
            'relatedItems',
            'locked',
            'published',
            'hideInMenu',
            'slug',
            'overridePathauto',
        );
        foreach ($operationDetailKeys as $detailKey) {
            if (array_key_exists($detailKey, $body) && !array_key_exists($detailKey, $body['node']['details'])) {
                $body['node']['details'][$detailKey] = $body[$detailKey];
            }
        }
        if (
            $operation === 'setMedia' &&
            !array_key_exists('image', $body['node']['details']) &&
            array_key_exists('media', $body['node']['details'])
        ) {
            $body['node']['details']['image'] = $body['node']['details']['media'];
        }
        $operations->params = $body;
        $operations->rawParams = $body;
        $result = $operations->saveNodeDetails();
        // D50: build projected summary with navigation links (Node canonical).
        // Node's items.js updateItem returns itemToSummary + appendItemNavigationLinks
        // instead of the raw item. Replicate that projected shape here so PHP
        // returns the same fields: id, title, slug, parent, indent, order,
        // location, description, metadata, region, tags, published, links
        // (self, content, parent, children, previous, next), related.
        if (
            is_array($result) && !isset($result['__failed']) &&
            isset($result['status']) && intval($result['status']) === 200 &&
            isset($result['data'])
        ) {
            $updatedItem = $result['data'];
            $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/x/api';
            $record = SiteRouteUtils::itemToSummary($updatedItem, $apiBasePath);
            // Build navigation map from ordered items and append links,
            // mirroring items.php read route and Node's buildItemNavigationMap +
            // appendItemNavigationLinks.
            $orderedItems = SiteRouteUtils::getOrderedItems($context->site);
            $itemById = array();
            foreach ($orderedItems as $navItem) {
                if (isset($navItem->id)) {
                    $itemById[(string) $navItem->id] = $navItem;
                }
            }
            $navMap = array();
            for ($i = 0; $i < count($orderedItems); $i++) {
                $navItem = $orderedItems[$i];
                if (!isset($navItem->id)) {
                    continue;
                }
                $navId = (string) $navItem->id;
                $prevLookup = $i > 0 ? SiteRouteUtils::getItemLookupValue($orderedItems[$i - 1]) : '';
                $nextLookup = ($i + 1) < count($orderedItems) ? SiteRouteUtils::getItemLookupValue($orderedItems[$i + 1]) : '';
                $parentLookup = '';
                if (isset($navItem->parent) && $navItem->parent != '') {
                    $parentId = (string) $navItem->parent;
                    if (isset($itemById[$parentId])) {
                        $parentLookup = SiteRouteUtils::getItemLookupValue($itemById[$parentId]);
                    } else {
                        $parentLookup = $parentId;
                    }
                }
                $navMap[$navId] = array(
                    'previous' => $prevLookup !== '' ? $apiBasePath . '/v1/items/' . rawurlencode($prevLookup) : null,
                    'next' => $nextLookup !== '' ? $apiBasePath . '/v1/items/' . rawurlencode($nextLookup) : null,
                    'parent' => $parentLookup !== '' ? $apiBasePath . '/v1/items/' . rawurlencode($parentLookup) : null,
                    'children' => $apiBasePath . '/v1/items?filter.parent=' . rawurlencode($navId),
                );
            }
            $recordId = isset($record['id']) ? (string) $record['id'] : '';
            if ($recordId !== '' && isset($navMap[$recordId])) {
                $record['links']['previous'] = $navMap[$recordId]['previous'];
                $record['links']['next'] = $navMap[$recordId]['next'];
                $record['links']['parent'] = $navMap[$recordId]['parent'];
                $record['links']['children'] = $navMap[$recordId]['children'];
            }
            $result = array('status' => 200, 'data' => $record);
        }
    } else if ($method === 'DELETE') {
        if (!isset($body['node']) || !is_array($body['node'])) {
            $body['node'] = array();
        }
        if (!isset($body['node']['id']) || $body['node']['id'] === '') {
            $body['node']['id'] = $resolvedItemId;
        }
        $operations->params = $body;
        $operations->rawParams = $body;
        $result = $operations->deleteNode();
    } else {
        SiteRouteUtils::sendFormattedResponse(
            array('message' => 'Unsupported method for /v1/items'),
            array('statusCode' => 405, 'allowedFormats' => array('json'), 'defaultFormat' => 'json'),
            $context->routeSuffix,
            $context->apiBasePath
        );
        return;
    }
    if (is_array($result) && isset($result['__failed'])) {
        SiteRouteUtils::sendFormattedResponse(
            array(
                'message' => $result['__failed']['message'],
            ),
            array(
                'statusCode' => intval($result['__failed']['status']),
                'allowedFormats' => array('json'),
                'defaultFormat' => 'json',
            ),
            $context->routeSuffix,
            $context->apiBasePath
        );
        return;
    }
    if (is_array($result) && isset($result['status']) && isset($result['data'])) {
        SiteRouteUtils::sendFormattedResponse(
            $result,
            array(
                'statusCode' => 200,
                'allowedFormats' => array('json'),
                'defaultFormat' => 'json',
                'envelope' => false,
            ),
            $context->routeSuffix,
            $context->apiBasePath
        );
        return;
    }
    SiteRouteUtils::sendFormattedResponse(
        array('status' => 200, 'data' => $result),
        array(
            'statusCode' => 200,
            'allowedFormats' => array('json'),
            'defaultFormat' => 'json',
            'envelope' => false,
        ),
        $context->routeSuffix,
        $context->apiBasePath
    );
};