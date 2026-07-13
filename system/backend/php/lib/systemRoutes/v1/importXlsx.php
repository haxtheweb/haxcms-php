<?php
include_once dirname(__FILE__) . '/../../siteRoutes/SiteRouteUtils.php';
include_once dirname(__FILE__) . '/../../JSONOutlineSchemaItem.php';

$__vendorAutoload = dirname(__FILE__) . '/../../../vendor/autoload.php';
if (file_exists($__vendorAutoload)) {
    require_once $__vendorAutoload;
}

return function ($context) {
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/system/api';

    $fileKey = null;
    foreach (array('upload', 'file', 'file-upload') as $key) {
        if (isset($_FILES[$key]) && is_array($_FILES[$key]) && isset($_FILES[$key]['tmp_name']) && $_FILES[$key]['tmp_name'] !== '') {
            $fileKey = $key;
            break;
        }
    }

    if ($fileKey === null) {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'data' => array('error' => 'No file uploaded', 'items' => array(), 'filename' => null)),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }

    $file     = $_FILES[$fileKey];
    $filename = isset($file['name']) ? (string) $file['name'] : 'file.xlsx';

    if (!preg_match('/\.(xlsx|xls)$/i', $filename)) {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'data' => array('error' => 'Invalid file type. Expected .xlsx or .xls, got: ' . $filename, 'items' => array(), 'filename' => $filename)),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }

    $tmpPath = $file['tmp_name'];

    try {
        $spreadsheet  = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpPath);
        $sheetNames   = $spreadsheet->getSheetNames();
        if (empty($sheetNames)) {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 400, 'data' => array('error' => 'No sheets found in Excel file', 'items' => array(), 'filename' => $filename)),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix,
                $apiBasePath
            );
            return;
        }
        $selectedSheet = $sheetNames[0];
        $sheet         = $spreadsheet->getActiveSheet();
        $rows          = $sheet->toArray(null, true, true, false);

        // Find header row (first non-empty row)
        $headerRowIndex = -1;
        foreach ($rows as $rowIdx => $row) {
            foreach ($row as $cell) {
                if (trim((string) $cell) !== '') {
                    $headerRowIndex = $rowIdx;
                    break 2;
                }
            }
        }
        if ($headerRowIndex === -1) {
            throw new \RuntimeException('Spreadsheet has no header row');
        }

        // Build header lookup
        $headerRow    = $rows[$headerRowIndex];
        $headerLookup = array('title' => -1, 'slug' => -1, 'parent' => -1, 'content' => -1);
        foreach ($headerRow as $colIdx => $cellValue) {
            $normalized = strtolower(preg_replace('/\s+/', '', trim((string) $cellValue)));
            if ($normalized === 'title')   { $headerLookup['title']   = $colIdx; }
            elseif ($normalized === 'slug')    { $headerLookup['slug']    = $colIdx; }
            elseif ($normalized === 'parent')  { $headerLookup['parent']  = $colIdx; }
            elseif ($normalized === 'content') { $headerLookup['content'] = $colIdx; }
        }

        $items   = array();
        $slugMap = array();
        $rowNum  = $headerRowIndex + 1;

        foreach ($rows as $rowIdx => $row) {
            if ($rowIdx <= $headerRowIndex) { continue; }
            // Skip empty rows
            $hasData = false;
            foreach ($row as $cell) { if (trim((string) $cell) !== '') { $hasData = true; break; } }
            if (!$hasData) { continue; }

            $rowNum++;
            $titleIdx   = $headerLookup['title'];
            $slugIdx    = $headerLookup['slug'];
            $parentIdx  = $headerLookup['parent'];
            $contentIdx = $headerLookup['content'];

            $title   = $titleIdx   >= 0 ? trim((string) $row[$titleIdx])   : '';
            $rawSlug = $slugIdx    >= 0 ? trim((string) $row[$slugIdx])    : '';
            $rawParent = $parentIdx >= 0 ? trim((string) $row[$parentIdx])  : '';
            $content = $contentIdx >= 0 ? (string) $row[$contentIdx]       : '';

            if ($title === '') { throw new \RuntimeException("Row {$rowNum}: title is required"); }
            if ($rawSlug === '') { throw new \RuntimeException("Row {$rowNum}: slug is required"); }

            // Normalize slug
            $slug = strtolower(preg_replace('/[^a-z0-9\-_]+/i', '-', $rawSlug));
            $slug = preg_replace('/-+/', '-', trim($slug, '-'));
            if ($slug === '') { throw new \RuntimeException("Row {$rowNum}: slug is required"); }

            $slugKey = strtolower($slug);
            if (isset($slugMap[$slugKey])) {
                throw new \RuntimeException("Row {$rowNum}: duplicate slug \"{$slug}\" (already used on row {$slugMap[$slugKey]['rowNum']})");
            }

            $parentSlug    = strtolower(preg_replace('/[^a-z0-9\-_]+/i', '-', $rawParent));
            $parentSlug    = preg_replace('/-+/', '-', trim($parentSlug, '-'));
            $parentSlugKey = strtolower($parentSlug);

            $jos = new JSONOutlineSchemaItem();
            $item = array(
                'id'          => $jos->id,
                'title'       => $title,
                'slug'        => $slug,
                'order'       => count($items),
                'parent'      => null,
                'indent'      => 0,
                'location'    => $jos->location,
                'description' => $jos->description,
                'metadata'    => $jos->metadata,
                'contents'    => $content,
            );
            $items[]        = $item;
            $slugMap[$slugKey] = array('item' => &$items[count($items) - 1], 'rowNum' => $rowNum, 'parentSlugKey' => $parentSlugKey);
        }

        // Resolve parent references
        foreach ($slugMap as $slugKey => $entry) {
            if ($entry['parentSlugKey'] !== '' && isset($slugMap[$entry['parentSlugKey']])) {
                $entry['item']['parent'] = $slugMap[$entry['parentSlugKey']]['item']['id'];
                $entry['item']['indent'] = 1;
            }
        }

        SiteRouteUtils::sendFormattedResponse(
            array('status' => 200, 'data' => array('items' => $items, 'filename' => $filename, 'selectedSheet' => $selectedSheet)),
            array('statusCode' => 200, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
    } catch (\Exception $e) {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'data' => array('error' => 'Error processing Excel import: ' . $e->getMessage(), 'items' => array(), 'filename' => $filename)),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
    }
};
