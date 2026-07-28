<?php
include_once dirname(__FILE__) . '/../../../siteRoutes/SiteRouteUtils.php';
include_once dirname(__FILE__) . '/importUtils.php';
include_once dirname(__FILE__) . '/../../../SsrfGuard.php';

if (!function_exists('haxcmsImportConvertRecipeToSite')) {
    function haxcmsImportConvertRecipeToSite($context)
    {
        $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/system/api';
        $body        = $context->body;
        if (!is_array($body)) { $body = array(); }

        $parentId = (isset($body['parentId']) && $body['parentId'] !== null && $body['parentId'] !== 'null')
            ? (string) $body['parentId'] : null;

        // Accept uploaded recipe file or inline recipe content
        $recipeContent = '';
        $filename      = 'recipe.json';

        $fileKey = null;
        foreach (array('upload', 'file', 'file-upload') as $key) {
            if (isset($_FILES[$key]) && is_array($_FILES[$key]) && isset($_FILES[$key]['tmp_name']) && $_FILES[$key]['tmp_name'] !== '') {
                $fileKey = $key;
                break;
            }
        }

        if ($fileKey !== null) {
            $file          = $_FILES[$fileKey];
            $filename      = isset($file['name']) ? (string) $file['name'] : 'recipe.json';
            $recipeContent = (string) @file_get_contents($file['tmp_name']);
        } elseif (isset($body['recipe'])) {
            $recipeContent = is_string($body['recipe']) ? $body['recipe'] : json_encode($body['recipe']);
            $filename      = 'recipe.json';
        } elseif (isset($body['repoUrl']) && $body['repoUrl'] !== '') {
            // Fetch recipe from URL — SSRF guarded, redirects disabled
            try {
                $recipeContent = (string) SsrfGuard::safeCurlExec(
                    (string) $body['repoUrl'],
                    array(CURLOPT_TIMEOUT => 30)
                );
                $parts    = explode('/', $body['repoUrl']);
                $filename = end($parts) ?: 'recipe.json';
            } catch (\Exception $e) {
                SiteRouteUtils::sendFormattedResponse(
                    array('status' => 400, 'data' => array('error' => 'Unable to fetch recipe: ' . $e->getMessage(), 'items' => array(), 'filename' => null)),
                    array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                    $context->routeSuffix, $apiBasePath
                );
                return;
            }
        } else {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 400, 'data' => array('error' => 'missing recipe content, file upload, or `repoUrl` param', 'items' => array(), 'filename' => null)),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix, $apiBasePath
            );
            return;
        }

        if (trim($recipeContent) === '') {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 400, 'data' => array('error' => 'Empty recipe content', 'items' => array(), 'filename' => null)),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix, $apiBasePath
            );
            return;
        }

        // Parse recipe - expected to be JSON with items or a site structure
        $recipe = json_decode($recipeContent, true);

        if (!is_array($recipe)) {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 400, 'data' => array('error' => 'Invalid recipe format - expected JSON', 'items' => array(), 'filename' => $filename)),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix, $apiBasePath
            );
            return;
        }

        // Try to write recipe to temp file and run HAX CLI to process it
        $tmpFile = sys_get_temp_dir() . '/haxcms-recipe-' . uniqid() . '.json';
        $items   = array();

        @file_put_contents($tmpFile, $recipeContent);

        // Check if HAX CLI is available and try to process
        $haxBin = trim((string) @shell_exec('which hax 2>/dev/null'));
        if ($haxBin !== '' && file_exists($tmpFile)) {
            $cmd    = escapeshellcmd($haxBin) . ' recipe --file ' . escapeshellarg($tmpFile) . ' --format json --quiet --no-i 2>/dev/null';
            $output = (string) @shell_exec($cmd);
            $parsed = json_decode($output, true);
            if (is_array($parsed) && isset($parsed['items'])) {
                $items = $parsed['items'];
            }
        }

        // Fallback: if recipe already has items array, use directly
        if (empty($items)) {
            if (isset($recipe['items']) && is_array($recipe['items'])) {
                $order = 0;
                foreach ($recipe['items'] as $ri) {
                    $title = isset($ri['title']) ? (string) $ri['title'] : 'Page';
                    $items[] = array(
                        'id'          => SiteRouteUtils::generateUUID(),
                        'title'       => $title,
                        'order'       => isset($ri['order']) ? (int) $ri['order'] : $order,
                        'parent'      => isset($ri['parent']) ? (string) $ri['parent'] : $parentId,
                        'slug'        => isset($ri['slug']) ? (string) $ri['slug'] : SiteRouteUtils::cleanSlug($title),
                        'contents'    => isset($ri['contents']) ? (string) $ri['contents'] : '',
                        'description' => isset($ri['description']) ? (string) $ri['description'] : '',
                        'metadata'    => isset($ri['metadata']) && is_array($ri['metadata']) ? $ri['metadata'] : array(),
                    );
                    $order++;
                }
            } elseif (isset($recipe['pages']) && is_array($recipe['pages'])) {
                $order = 0;
                foreach ($recipe['pages'] as $page) {
                    $title = isset($page['title']) ? (string) $page['title'] : 'Page';
                    $items[] = array(
                        'id'          => SiteRouteUtils::generateUUID(),
                        'title'       => $title,
                        'order'       => $order++,
                        'parent'      => $parentId,
                        'slug'        => isset($page['slug']) ? (string) $page['slug'] : SiteRouteUtils::cleanSlug($title),
                        'contents'    => isset($page['content']) ? (string) $page['content'] : '',
                        'description' => '',
                        'metadata'    => array(),
                    );
                }
            }
        }

        @unlink($tmpFile);

        $recipeName = preg_replace('/\.(json|yaml|yml)$/i', '', $filename);

        SiteRouteUtils::sendFormattedResponse(
            array('status' => 200, 'data' => array('items' => $items, 'filename' => $recipeName)),
            array('statusCode' => 200, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix, $apiBasePath
        );
    }
}
