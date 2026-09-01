<?php
include_once dirname(__FILE__) . '/../../siteRoutes/SiteRouteUtils.php';
include_once dirname(__FILE__) . '/imports/importUtils.php';
include_once dirname(__FILE__) . '/imports/convertHaxcmsToSite.php';
include_once dirname(__FILE__) . '/imports/convertHtmlToSite.php';
include_once dirname(__FILE__) . '/imports/convertPressbooksToSite.php';
include_once dirname(__FILE__) . '/imports/convertGitbookToSite.php';
include_once dirname(__FILE__) . '/imports/convertNotionToSite.php';
include_once dirname(__FILE__) . '/imports/convertWordpressToSite.php';
include_once dirname(__FILE__) . '/imports/convertElmslnToSite.php';
include_once dirname(__FILE__) . '/imports/convertDrupalBookToSite.php';
include_once dirname(__FILE__) . '/imports/convertPloneToSite.php';

/**
 * POST /system/api/v1/site/import/:platform
 * Dispatcher that routes platform import requests to the correct converter.
 *
 * Supported platforms: haxcms, html, pressbooks, gitbook, notion, wordpress,
 * elmsln, drupal-book, plone.
 * Returns { status: 200, data: { items: [...], filename: string, ... } }
 */
return function ($context) {
    $apiBasePath = isset($context->apiBasePath) ? $context->apiBasePath : '/system/api';
    $platform    = strtolower(trim($context->getParam('platform', '')));

    switch ($platform) {
        case 'haxcms':
            haxcmsImportConvertHaxcmsToSite($context);
            break;
        case 'html':
            haxcmsImportConvertHtmlToSite($context);
            break;
        case 'pressbooks':
            haxcmsImportConvertPressbooksToSite($context);
            break;
        case 'gitbook':
            haxcmsImportConvertGitbookToSite($context);
            break;
        case 'notion':
            haxcmsImportConvertNotionToSite($context);
            break;
        case 'wordpress':
            haxcmsImportConvertWordpressToSite($context);
            break;
        case 'elmsln':
            haxcmsImportConvertElmslnToSite($context);
            break;
        case 'drupal-book':
            haxcmsImportConvertDrupalBookToSite($context);
            break;
        case 'plone':
            haxcmsImportConvertPloneToSite($context);
            break;
        default:
            SiteRouteUtils::sendFormattedResponse(
                array('status' => 400, 'data' => array(
                    'error'    => 'Unsupported import platform "' . $platform . '"',
                    'items'    => array(),
                    'filename' => null,
                )),
                array('statusCode' => 400, 'allowedFormats' => array('json'), 'defaultFormat' => 'json', 'envelope' => false),
                $context->routeSuffix,
                $apiBasePath
            );
            break;
    }
};
