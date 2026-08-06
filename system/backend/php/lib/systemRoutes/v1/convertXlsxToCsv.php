<?php
include_once dirname(__FILE__) . '/../../siteRoutes/SiteRouteUtils.php';

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
            array('status' => 400, 'data' => array('error' => 'No file uploaded', 'contents' => '', 'filename' => null)),
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
            array('status' => 400, 'data' => array('error' => 'Invalid file type. Expected .xlsx or .xls, got: ' . $filename, 'contents' => '', 'filename' => $filename)),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
        return;
    }

    if (preg_match('/\.xls$/i', $filename)) {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'data' => array('error' => 'Legacy .xls files are not supported. Please save as .xlsx and retry.', 'contents' => '', 'filename' => $filename)),
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
                array('status' => 400, 'data' => array('error' => 'No sheets found in Excel file', 'contents' => '', 'filename' => $filename)),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix,
                $apiBasePath
            );
            return;
        }
        $sheetParam    = isset($_GET['sheet']) ? (string) $_GET['sheet'] : null;
        $selectedSheet = null;
        if ($sheetParam !== null && in_array($sheetParam, $sheetNames, true)) {
            $selectedSheet = $sheetParam;
        } else {
            $selectedSheet = $sheetNames[0];
        }
        $sheet = $spreadsheet->getSheetByName($selectedSheet);
        if (!$sheet) {
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 400, 'data' => array('error' => 'Unable to access worksheet: ' . $selectedSheet, 'contents' => '', 'filename' => $filename)),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix,
                $apiBasePath
            );
            return;
        }
        $includeHeaders = !(isset($_GET['headers']) && $_GET['headers'] === 'false');
        $rows           = $sheet->toArray(null, true, true, false);

        // Collect non-empty rows (skip rows where all cells are blank)
        $csvRows = array();
        foreach ($rows as $row) {
            $cells     = array();
            $hasValues = false;
            foreach ($row as $cell) {
                $cellValue = $cell === null ? '' : $cell;
                if (trim((string) $cellValue) !== '') {
                    $hasValues = true;
                }
                $cells[] = $cellValue;
            }
            if ($hasValues) {
                $csvRows[] = $cells;
            }
        }

        // Optionally drop the header row
        if (!$includeHeaders && count($csvRows) > 0) {
            array_shift($csvRows);
        }

        // Build CSV string
        $csvOutput = '';
        ob_start();
        $fp = fopen('php://output', 'w');
        foreach ($csvRows as $row) {
            fputcsv($fp, $row);
        }
        fclose($fp);
        $csvOutput = ob_get_clean();

        SiteRouteUtils::sendFormattedResponse(
            array('status' => 200, 'data' => array(
                'contents'         => $csvOutput,
                'filename'         => $filename,
                'originalFilename' => $filename,
                'sheetNames'       => $sheetNames,
                'selectedSheet'    => $selectedSheet,
                'format'           => 'csv',
            )),
            array('statusCode' => 200, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
    } catch (\Exception $e) {
        SiteRouteUtils::sendFormattedResponse(
            array('status' => 400, 'data' => array('error' => 'Error converting XLSX to CSV: ' . $e->getMessage(), 'contents' => '', 'filename' => $filename)),
            array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
            $context->routeSuffix,
            $apiBasePath
        );
    }
};
