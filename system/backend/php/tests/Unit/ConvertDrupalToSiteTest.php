<?php
use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Unit tests for lib/systemRoutes/v1/imports/convertDrupalToSite.php.
 * Mirrors haxcms-nodejs test/unit/convert-drupal-to-site.test.cjs.
 *
 * Not covered by tests/phpunit-bootstrap.php's auto-load (it skips
 * systemRoutes/v1), so the converter is require_once'd directly here, as the
 * other imports/*.php converter tests do.
 *
 * haxcmsImportDrupalRun takes the HTTP client, so the whole route is answered
 * by a Guzzle handler over in-memory fixtures - the MockHandler pattern
 * ConvertVitepressToSiteTest established. Each fixture site lives on its own
 * public IP literal so SsrfGuard's address check needs no DNS.
 */
require_once __DIR__ . '/../../lib/systemRoutes/v1/imports/convertDrupalToSite.php';

class ConvertDrupalToSiteTest extends TestCase
{
    // grovecenter-style site: no menu-items module, menu_link_content forest
    const HOST = '93.184.215.14';
    // jsonapi_menu_items installed (flat collection)
    const HOST_MENUITEMS = '93.184.215.15';
    // jsonapi_frontend_menu installed (nested children payload)
    const HOST_FRONTENDMENU = '93.184.215.16';
    // node--book only, book-field forest
    const HOST_BOOKFIELDS = '93.184.215.17';
    // no menu structure at all, flat fallback
    const HOST_FLAT = '93.184.215.18';
    // discovery fails entirely
    const HOST_DEAD = '93.184.215.19';
    // discovery has neither node--page nor node--book
    const HOST_NONODES = '93.184.215.20';
    // node--page collection readable but empty
    const HOST_NORECORDS = '93.184.215.21';

    private $requests = array();

    protected function setUp(): void
    {
        $this->requests = array();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['HAXCMS_DRUPAL_LIMITS']);
    }

    // ------------------------------------------------------------------
    // the fixture sites
    // ------------------------------------------------------------------

    private function pageRecords(): array
    {
        return array(
            array(
                'id' => 'page-8-uuid',
                'type' => 'node--page',
                'attributes' => array(
                    'drupal_internal__nid' => 8,
                    'title' => 'Home',
                    'body' => array('value' => ''
                        . '<p>Welcome text.</p>'
                        . '<p><img src="/sites/default/files/2024-06/photo.jpg" alt="photo"></p>'
                        . '<p><drupal-media data-entity-uuid="media-audio-1" data-view-mode="default"></drupal-media></p>'
                        . '<p><a href="/about-us">About</a></p>'),
                    'path' => array('alias' => '/home'),
                    'status' => true,
                    'created' => '2024-06-01T10:00:00+00:00',
                ),
            ),
            array(
                'id' => 'page-10-uuid',
                'type' => 'node--page',
                'attributes' => array(
                    'drupal_internal__nid' => 10,
                    'title' => 'Student Development',
                    'body' => null,
                    'path' => array('alias' => '/undergrads/student-development'),
                    'status' => true,
                    'created' => '2024-06-02T10:00:00+00:00',
                ),
                'relationships' => array(
                    'field_media' => array('data' => array('type' => 'media--image', 'id' => 'media-image-1')),
                ),
            ),
            array(
                'id' => 'page-12-uuid',
                'type' => 'node--page',
                'attributes' => array(
                    'drupal_internal__nid' => 12,
                    'title' => 'Community',
                    'body' => array('value' => '<p>Community page text.</p>'),
                    'path' => array('alias' => '/community'),
                    'created' => '2024-06-03T10:00:00+00:00',
                ),
            ),
            array(
                'id' => 'page-14-uuid',
                'type' => 'node--page',
                'attributes' => array(
                    'drupal_internal__nid' => 14,
                    'title' => 'Orphan Page',
                    'body' => array('value' => '<p>Not in any menu.</p>'),
                    'created' => '2024-06-04T10:00:00+00:00',
                ),
            ),
            array(
                'id' => 'page-16-uuid',
                'type' => 'node--page',
                'attributes' => array(
                    'drupal_internal__nid' => 16,
                    'title' => 'Footer Only',
                    'body' => array('value' => '<p>Footer linked content.</p>'),
                    'created' => '2024-06-05T10:00:00+00:00',
                ),
            ),
        );
    }

    private function pageDup(): array
    {
        return array(
            array(
                'id' => 'page-10-dup-uuid',
                'type' => 'node--page',
                'attributes' => array(
                    'drupal_internal__nid' => 10,
                    'title' => 'Duplicate Student Development',
                    'body' => array('value' => '<p>dup</p>'),
                ),
            ),
        );
    }

    private function fileRecords(): array
    {
        return array(
            array('id' => 'file-photo', 'type' => 'file--file', 'attributes' => array(
                'drupal_internal__fid' => 1, 'filename' => 'photo.jpg',
                'uri' => array('value' => 'public://2024-06/photo.jpg', 'url' => '/sites/default/files/2024-06/photo.jpg'),
                'filemime' => 'image/jpeg', 'filesize' => 16499, 'status' => true,
            )),
            array('id' => 'file-temp', 'type' => 'file--file', 'attributes' => array(
                'drupal_internal__fid' => 2, 'filename' => 'temp.jpg',
                'uri' => array('value' => 'public://2024-06/temp.jpg', 'url' => '/sites/default/files/2024-06/temp.jpg'),
                'filemime' => 'image/jpeg', 'status' => false,
            )),
            array('id' => 'file-exe', 'type' => 'file--file', 'attributes' => array(
                'drupal_internal__fid' => 3, 'filename' => 'virus.exe',
                'uri' => array('value' => 'public://2024-06/virus.exe', 'url' => '/sites/default/files/2024-06/virus.exe'),
                'filemime' => 'application/octet-stream', 'status' => true,
            )),
            array('id' => 'file-private', 'type' => 'file--file', 'attributes' => array(
                'drupal_internal__fid' => 4, 'filename' => 'secret.pdf',
                'uri' => array('value' => 'private://docs/secret.pdf'),
                'filemime' => 'application/pdf', 'status' => true,
            )),
            array('id' => 'file-doc', 'type' => 'file--file', 'attributes' => array(
                'drupal_internal__fid' => 5, 'filename' => 'report.pdf',
                'uri' => array('value' => 'public://2024-06/report.pdf', 'url' => '/sites/default/files/2024-06/report.pdf'),
                'filemime' => 'application/pdf', 'status' => true,
            )),
            array('id' => 'file-audio', 'type' => 'file--file', 'attributes' => array(
                'drupal_internal__fid' => 6, 'filename' => 'track.mp3',
                'uri' => array('value' => 'public://2024-06/track.mp3', 'url' => '/sites/default/files/2024-06/track.mp3'),
                'filemime' => 'audio/mpeg', 'status' => true,
            )),
        );
    }

    private function mediaRecords(): array
    {
        return array(
            array('id' => 'media-image-1', 'type' => 'media--image',
                'attributes' => array('drupal_internal__mid' => 1, 'name' => 'photo.jpg'),
                'relationships' => array('field_media_image' => array('data' => array(
                    'type' => 'file--file', 'id' => 'file-photo',
                    'meta' => array('alt' => 'cartoon cat', 'title' => '', 'width' => 220, 'height' => 220),
                ))),
            ),
            array('id' => 'media-audio-1', 'type' => 'media--audio',
                'attributes' => array('drupal_internal__mid' => 2, 'name' => 'track.mp3'),
                'relationships' => array('field_media_audio' => array('data' => array(
                    'type' => 'file--file', 'id' => 'file-audio', 'meta' => array('title' => 'Track one'),
                ))),
            ),
            array('id' => 'media-video-1', 'type' => 'media--remote_video',
                'attributes' => array(
                    'drupal_internal__mid' => 3, 'name' => 'Intro video',
                    'field_media_oembed_video' => 'https://www.youtube.com/watch?v=abc123',
                ),
            ),
            array('id' => 'media-doc-1', 'type' => 'media--document',
                'attributes' => array('drupal_internal__mid' => 4, 'name' => 'report.pdf'),
                'relationships' => array('field_media_document' => array('data' => array(
                    'type' => 'file--file', 'id' => 'file-doc', 'meta' => array(),
                ))),
            ),
        );
    }

    private function menuLinkRecords(): array
    {
        return array(
            array('id' => 'ml-home', 'type' => 'menu_link_content--menu_link_content',
                'attributes' => array('menu_name' => 'main', 'link' => array('uri' => 'entity:node/8'), 'weight' => -10)),
            array('id' => 'ml-student', 'type' => 'menu_link_content--menu_link_content',
                'attributes' => array(
                    'menu_name' => 'main', 'link' => array('uri' => 'internal:/node/10'), 'weight' => 0,
                    'parent' => 'menu_link_content:ml-home',
                )),
            array('id' => 'ml-community', 'type' => 'menu_link_content--menu_link_content',
                'attributes' => array('menu_name' => 'main', 'link' => array('uri' => 'http://' . self::HOST . '/node/12'), 'weight' => 1),
                'relationships' => array('parent' => array('data' => array(
                    'id' => 'ml-home', 'type' => 'menu_link_content--menu_link_content',
                ))),
            ),
            array('id' => 'ml-footer-community', 'type' => 'menu_link_content--menu_link_content',
                'attributes' => array('menu_name' => 'footer', 'link' => array('uri' => 'entity:node/12'), 'weight' => 0)),
            array('id' => 'ml-footer-only', 'type' => 'menu_link_content--menu_link_content',
                'attributes' => array('menu_name' => 'footer', 'link' => array('uri' => 'entity:node/16'), 'weight' => 5)),
            array('id' => 'ml-account', 'type' => 'menu_link_content--menu_link_content',
                'attributes' => array('menu_name' => 'account', 'link' => array('uri' => 'entity:node/8'), 'weight' => 0)),
        );
    }

    private function menuEntityRecords(): array
    {
        return array(
            array('id' => 'menu-uuid-main', 'type' => 'menu--menu',
                'attributes' => array('drupal_internal__id' => 'main', 'label' => 'Main navigation')),
            array('id' => 'menu-uuid-footer', 'type' => 'menu--menu',
                'attributes' => array('drupal_internal__id' => 'footer', 'label' => 'Footer')),
            array('id' => 'menu-uuid-account', 'type' => 'menu--menu',
                'attributes' => array('drupal_internal__id' => 'account', 'label' => 'User account menu')),
        );
    }

    private function menuItemsPageRecords(): array
    {
        return array(
            array('id' => 'mi-page-400', 'type' => 'node--page', 'attributes' => array(
                'drupal_internal__nid' => 400, 'title' => 'Landing',
                'body' => array('value' => '<p>Landing body text.</p>'),
                'path' => array('alias' => '/landing'), 'created' => '2024-01-01T00:00:00+00:00',
            )),
            array('id' => 'mi-page-401', 'type' => 'node--page', 'attributes' => array(
                'drupal_internal__nid' => 401, 'title' => 'Sub One',
                'body' => array('value' => '<p>Sub one body.</p>'),
                'path' => array('alias' => '/sub-one'), 'created' => '2024-01-02T00:00:00+00:00',
            )),
            array('id' => 'mi-page-402', 'type' => 'node--page', 'attributes' => array(
                'drupal_internal__nid' => 402, 'title' => 'Sub Two',
                'body' => array('value' => '<p>Sub two body.</p>'),
                'path' => array('alias' => '/sub-two'), 'created' => '2024-01-03T00:00:00+00:00',
            )),
            array('id' => 'mi-page-403', 'type' => 'node--page', 'attributes' => array(
                'drupal_internal__nid' => 403, 'title' => 'News Item',
                'body' => array('value' => '<p>A news article body.</p>'),
                'path' => array('alias' => '/news-item'), 'created' => '2024-01-04T00:00:00+00:00',
            )),
        );
    }

    /**
     * jsonapi_menu_items payload: flat collection keyed by plugin id, parent
     * plugin-id refs, entity.node.canonical route parameters (one as a
     * string), and a views link whose nid-linked child promotes to the
     * nearest resolvable ancestor.
     */
    private function menuItemsPayload(): array
    {
        return array(
            'jsonapi' => array('version' => '1.1'),
            'data' => array(
                array('type' => 'menu_link_content--menu_link_content', 'id' => 'menu_link_content:link-a',
                    'attributes' => array('menu_name' => 'main', 'parent' => '',
                        'route' => array('name' => 'entity.node.canonical', 'parameters' => array('node' => 400)),
                        'title' => 'Landing', 'url' => '/landing', 'weight' => 0)),
                array('type' => 'menu_link_content--menu_link_content', 'id' => 'menu_link_content:link-b',
                    'attributes' => array('menu_name' => 'main', 'parent' => 'menu_link_content:link-a',
                        'route' => array('name' => 'entity.node.canonical', 'parameters' => array('node' => '401')),
                        'title' => 'Sub One', 'url' => '/sub-one', 'weight' => 0)),
                array('type' => 'menu_link_content--menu_link_content', 'id' => 'menu_link_content:link-c',
                    'attributes' => array('menu_name' => 'main', 'parent' => 'menu_link_content:link-a',
                        'route' => array('name' => 'entity.node.canonical', 'parameters' => array('node' => 402)),
                        'title' => 'Sub Two', 'url' => '/sub-two', 'weight' => 1)),
                array('type' => 'menu_link_content--menu_link_content', 'id' => 'views_view:view.news_page',
                    'attributes' => array('menu_name' => 'main', 'parent' => '',
                        'route' => array('name' => 'view.news.page', 'parameters' => array()),
                        'title' => 'News', 'url' => '/news', 'weight' => 10)),
                array('type' => 'menu_link_content--menu_link_content', 'id' => 'menu_link_content:link-d',
                    'attributes' => array('menu_name' => 'main', 'parent' => 'views_view:view.news_page',
                        'route' => array('name' => 'entity.node.canonical', 'parameters' => array('node' => 403)),
                        'title' => 'News Item', 'url' => '/news-item', 'weight' => 0)),
            ),
        );
    }

    private function frontendMenuPageRecords(): array
    {
        return array(
            array('id' => 'fm-page-400', 'type' => 'node--page', 'attributes' => array(
                'drupal_internal__nid' => 400, 'title' => 'Landing',
                'body' => array('value' => '<p>Landing body.</p>'),
                'path' => array('alias' => '/landing'), 'created' => '2024-02-01T00:00:00+00:00',
            )),
            array('id' => 'fm-page-401', 'type' => 'node--page', 'attributes' => array(
                'drupal_internal__nid' => 401, 'title' => 'Sub One',
                'body' => array('value' => '<p>Sub one body.</p>'),
                'path' => array('alias' => '/sub-one'), 'created' => '2024-02-02T00:00:00+00:00',
            )),
        );
    }

    /** nested-children payload; nids resolve through the alias map fallback */
    private function frontendMenuPayload(): array
    {
        return array(
            array('title' => 'Landing', 'url' => '/landing', 'weight' => 0, 'children' => array(
                array('title' => 'Sub One', 'url' => '/sub-one', 'weight' => 0, 'children' => array()),
            )),
            array('title' => 'External', 'url' => 'https://elsewhere.example.org/', 'weight' => 5),
        );
    }

    private function bookFieldsRecords(): array
    {
        return array(
            array('id' => 'uuid-300', 'type' => 'node--book', 'attributes' => array(
                'drupal_internal__nid' => 300, 'title' => 'Handbook', 'body' => null,
                'book' => array('pid' => 0, 'weight' => -10), 'path' => array('alias' => '/handbook'),
            )),
            array('id' => 'uuid-301', 'type' => 'node--book', 'attributes' => array(
                'drupal_internal__nid' => 301, 'title' => 'Chapter One',
                'body' => array('value' => '<p>Chapter one body text here.</p>'),
                'book' => array('pid' => 300, 'weight' => 0), 'path' => array('alias' => '/chapter-one'),
            )),
            array('id' => 'uuid-302', 'type' => 'node--book', 'attributes' => array(
                'drupal_internal__nid' => 302, 'title' => 'Chapter Two',
                'body' => array('value' => '<p>Chapter two body text here.</p>'),
                'book' => array('pid' => 300, 'weight' => 1), 'path' => array('alias' => '/chapter-two'),
            )),
            array('id' => 'uuid-310', 'type' => 'node--book', 'attributes' => array(
                'drupal_internal__nid' => 310, 'title' => 'Second Book',
                'body' => array('value' => '<p>Second book intro text.</p>'),
                'book' => array('pid' => 0, 'weight' => 0), 'path' => array('alias' => '/second-book'),
            )),
            array('id' => 'uuid-311', 'type' => 'node--book', 'attributes' => array(
                'drupal_internal__nid' => 311, 'title' => 'Second Book Child',
                'body' => array('value' => '<p>Child page body text.</p>'),
                'book' => array('pid' => 310, 'weight' => 0), 'path' => array('alias' => '/second-book-child'),
            )),
        );
    }

    private function flatPageRecords(): array
    {
        return array(
            array('id' => 'flat-50', 'type' => 'node--page', 'attributes' => array(
                'drupal_internal__nid' => 50, 'title' => 'Zebra',
                'body' => array('value' => '<p>Zebra body.</p>'), 'created' => '2024-01-02T00:00:00+00:00',
            )),
            array('id' => 'flat-51', 'type' => 'node--page', 'attributes' => array(
                'drupal_internal__nid' => 51, 'title' => 'Alpha',
                'body' => array('value' => '<p>Alpha body.</p>'), 'created' => '2024-01-01T00:00:00+00:00',
            )),
            array('id' => 'flat-52', 'type' => 'node--page', 'attributes' => array(
                'drupal_internal__nid' => 52, 'title' => 'Middle',
                'body' => array('value' => '<p>Middle body.</p>'), 'created' => '2024-01-03T00:00:00+00:00',
            )),
        );
    }

    // ------------------------------------------------------------------
    // the fixture HTTP handler
    // ------------------------------------------------------------------

    private function discoveryPayload(string $host): array
    {
        if ($host === self::HOST) {
            return array('jsonapi' => array(), 'data' => array(), 'links' => array(
                'node--page' => array('href' => 'http://' . $host . '/jsonapi/node/page'),
                'file--file' => array('href' => 'http://' . $host . '/jsonapi/file/file'),
                'media--image' => array('href' => 'http://' . $host . '/jsonapi/media/image'),
                'media--audio' => array('href' => 'http://' . $host . '/jsonapi/media/audio'),
                'media--remote_video' => array('href' => 'http://' . $host . '/jsonapi/media/remote_video'),
                'media--document' => array('href' => 'http://' . $host . '/jsonapi/media/document'),
                'menu_link_content--menu_link_content' => array(
                    'href' => 'http://' . $host . '/jsonapi/menu_link_content/menu_link_content'),
                'menu--menu' => array('href' => 'http://' . $host . '/jsonapi/menu/menu'),
            ));
        }
        if ($host === self::HOST_MENUITEMS || $host === self::HOST_FRONTENDMENU) {
            return array('links' => array(
                'node--page' => array('href' => 'http://' . $host . '/jsonapi/node/page'),
                'menu--menu' => array('href' => 'http://' . $host . '/jsonapi/menu/menu'),
            ));
        }
        if ($host === self::HOST_BOOKFIELDS) {
            return array('links' => array(
                'node--book' => array('href' => 'http://' . $host . '/jsonapi/node/book'),
            ));
        }
        if ($host === self::HOST_FLAT) {
            return array('links' => array(
                'node--page' => array('href' => 'http://' . $host . '/jsonapi/node/page'),
            ));
        }
        if ($host === self::HOST_NONODES) {
            return array('links' => array(
                'node--news' => array('href' => 'http://' . $host . '/jsonapi/node/news'),
            ));
        }
        if ($host === self::HOST_NORECORDS) {
            return array('links' => array(
                'node--page' => array('href' => 'http://' . $host . '/jsonapi/node/page'),
            ));
        }
        return array();
    }

    private function collectionPayload(string $host, string $path, string $query): ?array
    {
        if ($host === self::HOST) {
            if ($path === '/jsonapi/node/page') {
                if (strpos($query, 'page2=1') !== false) {
                    return array('data' => $this->pageDup(), 'links' => array());
                }
                return array('data' => $this->pageRecords(), 'links' => array(
                    'next' => array('href' => 'http://' . $host . '/jsonapi/node/page?page2=1'),
                ));
            }
            if ($path === '/jsonapi/file/file') {
                return array('data' => $this->fileRecords(), 'links' => array());
            }
            if ($path === '/jsonapi/media/image') {
                return array('data' => array($this->mediaRecords()[0]), 'links' => array());
            }
            if ($path === '/jsonapi/media/audio') {
                return array('data' => array($this->mediaRecords()[1]), 'links' => array());
            }
            if ($path === '/jsonapi/media/remote_video') {
                return array('data' => array($this->mediaRecords()[2]), 'links' => array());
            }
            if ($path === '/jsonapi/media/document') {
                return array('data' => array($this->mediaRecords()[3]), 'links' => array());
            }
            if ($path === '/jsonapi/menu_link_content/menu_link_content') {
                return array('data' => $this->menuLinkRecords(), 'links' => array());
            }
            if ($path === '/jsonapi/menu/menu') {
                return array('data' => $this->menuEntityRecords(), 'links' => array());
            }
            return null;
        }
        if ($host === self::HOST_MENUITEMS) {
            if ($path === '/jsonapi/node/page') {
                return array('data' => $this->menuItemsPageRecords(), 'links' => array());
            }
            if ($path === '/jsonapi/menu/menu') {
                return array('data' => array($this->menuEntityRecords()[0], $this->menuEntityRecords()[1]), 'links' => array());
            }
            if ($path === '/jsonapi/menu_items/main') {
                return $this->menuItemsPayload();
            }
            return null;
        }
        if ($host === self::HOST_FRONTENDMENU) {
            if ($path === '/jsonapi/node/page') {
                return array('data' => $this->frontendMenuPageRecords(), 'links' => array());
            }
            if ($path === '/jsonapi/menu/menu') {
                return array('data' => array($this->menuEntityRecords()[0]), 'links' => array());
            }
            if ($path === '/jsonapi/menu/main') {
                return $this->frontendMenuPayload();
            }
            return null;
        }
        if ($host === self::HOST_BOOKFIELDS && $path === '/jsonapi/node/book') {
            return array('data' => $this->bookFieldsRecords(), 'links' => array());
        }
        if ($host === self::HOST_FLAT && $path === '/jsonapi/node/page') {
            return array('data' => $this->flatPageRecords(), 'links' => array());
        }
        if ($host === self::HOST_NORECORDS && $path === '/jsonapi/node/page') {
            return array('data' => array(), 'links' => array());
        }
        return null;
    }

    private function client(): Client
    {
        $requests = &$this->requests;
        $handler = function (RequestInterface $request, array $options) use (&$requests) {
            $uri = $request->getUri();
            $host = $uri->getHost();
            $path = $uri->getPath();
            $query = $uri->getQuery();
            $requests[] = $host . $path . ($query !== '' ? '?' . $query : '');
            if ($host === self::HOST_DEAD) {
                return new FulfilledPromise(new Response(404, array(), 'not found'));
            }
            if ($path === '/jsonapi') {
                return new FulfilledPromise(new Response(200, array(), json_encode($this->discoveryPayload($host))));
            }
            // menu-items endpoint probes 404 unless a fixture serves one; the
            // menu--menu collection URL must not fall into the probe branch
            if (preg_match('#^/jsonapi/(menu_items|menu|jsonapi_menu)/[^/]+$#', $path) === 1
                && ($path !== '/jsonapi/menu/menu'
                    || ($host !== self::HOST && $host !== self::HOST_MENUITEMS && $host !== self::HOST_FRONTENDMENU))) {
                $payload = $this->collectionPayload($host, $path, $query);
                if ($payload !== null) {
                    return new FulfilledPromise(new Response(200, array(), json_encode($payload)));
                }
                return new FulfilledPromise(new Response(404, array(), 'not found'));
            }
            $payload = $this->collectionPayload($host, $path, $query);
            if ($payload !== null) {
                return new FulfilledPromise(new Response(200, array(), json_encode($payload)));
            }
            return new FulfilledPromise(new Response(404, array(), 'not found'));
        };
        return new Client(array('handler' => HandlerStack::create($handler)));
    }

    private function import(array $body): array
    {
        $context = new stdClass();
        $context->apiBasePath = '/system/api';
        $context->routeSuffix = '';
        $context->body = $body;
        $client = $this->client();
        ob_start();
        try {
            haxcmsImportDrupalRun($context, $client);
        } finally {
            $printed = ob_get_clean();
        }
        return json_decode($printed, true);
    }

    private function itemBy(array $items, string $title): ?array
    {
        foreach ($items as $item) {
            if ($item['title'] === $title) {
                return $item;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------
    // request validation
    // ------------------------------------------------------------------

    public function testMissingRepoUrlReturns400(): void
    {
        $response = $this->import(array());
        $this->assertSame(400, $response['status']);
        $this->assertSame('missing `repoUrl` param', $response['data']['error']);
        $this->assertSame(array(), $response['data']['items']);
        $this->assertNull($response['data']['filename']);
        $this->assertSame(array(), $response['data']['files']);
    }

    public function testBlankRepoUrlAfterTrimReturns400(): void
    {
        $response = $this->import(array('repoUrl' => '   '));
        $this->assertSame(400, $response['status']);
        $this->assertSame('missing `repoUrl` param', $response['data']['error']);
    }

    public function testFailedDiscoveryReturns400(): void
    {
        $response = $this->import(array('repoUrl' => 'http://' . self::HOST_DEAD));
        $this->assertSame(400, $response['status']);
        $this->assertSame(
            'Unable to discover Drupal JSON:API from `repoUrl`; expected `<base>/jsonapi`',
            $response['data']['error']
        );
    }

    public function testDiscoveryWithoutPageOrBookCollectionsReturns400(): void
    {
        $response = $this->import(array('repoUrl' => 'http://' . self::HOST_NONODES));
        $this->assertSame(400, $response['status']);
        $this->assertSame(
            'Drupal JSON:API discovered but neither `node--page` nor `node--book` collections were exposed (found: node--news)',
            $response['data']['error']
        );
    }

    public function testEmptyPageCollectionReturns400(): void
    {
        $response = $this->import(array('repoUrl' => 'http://' . self::HOST_NORECORDS));
        $this->assertSame(400, $response['status']);
        $this->assertSame(
            'Drupal JSON:API is available but neither `node--page` nor `node--book` has accessible records',
            $response['data']['error']
        );
    }

    // ------------------------------------------------------------------
    // menu_link_content forest with files, media, and additional pages
    // ------------------------------------------------------------------

    public function testMenuLinkForestBuildsTheOutline(): void
    {
        $response = $this->import(array('repoUrl' => 'http://' . self::HOST));
        $this->assertSame(200, $response['status']);
        $this->assertSame('93-184-215-14', $response['data']['filename']);

        // files map: allowed extensions keyed by Drupal-relative path
        $this->assertSame(array(
            '2024-06/photo.jpg' => 'http://' . self::HOST . '/sites/default/files/2024-06/photo.jpg',
            '2024-06/report.pdf' => 'http://' . self::HOST . '/sites/default/files/2024-06/report.pdf',
            '2024-06/track.mp3' => 'http://' . self::HOST . '/sites/default/files/2024-06/track.mp3',
        ), $response['data']['files']);

        $items = $response['data']['items'];
        $this->assertCount(6, $items);

        $home = $items[0];
        $this->assertSame('Home', $home['title']);
        $this->assertSame('home', $home['slug']);
        $this->assertSame(0, $home['order']);
        $this->assertSame(0, $home['indent']);
        $this->assertNull($home['parent']);
        $this->assertSame('drupal-node', $home['metadata']['sourceType']);
        $this->assertSame('http://' . self::HOST . '/node/8', $home['metadata']['source']);
        $this->assertSame(true, $home['metadata']['published']);
        $this->assertSame(8, $home['metadata']['drupal']['nid']);
        $this->assertSame('page-8-uuid', $home['metadata']['drupal']['uuid']);
        $this->assertSame('node--page', $home['metadata']['drupal']['type']);
        $this->assertSame(true, $home['metadata']['drupal']['inOutline']);

        // body file URL rewritten, media embed resolved to media-playlist +
        // audio-player, unlinked href absolutized
        $this->assertStringContainsString('src="files/2024-06/photo.jpg"', $home['contents']);
        $this->assertStringContainsString(
            '<media-playlist><audio-player source="files/2024-06/track.mp3" media-title="Track one"></audio-player></media-playlist>',
            $home['contents']
        );
        $this->assertStringContainsString('href="http://' . self::HOST . '/about-us"', $home['contents']);
        $this->assertStringNotContainsString('sites/default/files', $home['contents']);

        // body-null page renders its field_media image as content
        $student = $items[1];
        $this->assertSame('Student Development', $student['title']);
        $this->assertSame('home/student-development', $student['slug']);
        $this->assertSame(0, $student['order']);
        $this->assertSame(1, $student['indent']);
        $this->assertSame($home['id'], $student['parent']);
        $this->assertSame(
            '<media-image source="files/2024-06/photo.jpg" alt="cartoon cat"></media-image>',
            $student['contents']
        );

        $community = $items[2];
        $this->assertSame('Community', $community['title']);
        $this->assertSame('home/community', $community['slug']);
        $this->assertSame(1, $community['order']);
        $this->assertSame(1, $community['indent']);
        $this->assertSame($home['id'], $community['parent']);
        $this->assertSame('<p>Community page text.</p>', $community['contents']);

        // footer-only link appends as a top-level item after the forest
        $footerOnly = $items[3];
        $this->assertSame('Footer Only', $footerOnly['title']);
        $this->assertSame('footer-only', $footerOnly['slug']);
        $this->assertSame(1, $footerOnly['order']);
        $this->assertSame(0, $footerOnly['indent']);
        $this->assertNull($footerOnly['parent']);

        // unlinked pages land under a hidden additional-pages group
        $additional = $items[4];
        $this->assertSame('additional pages', $additional['title']);
        $this->assertSame('additional-pages', $additional['slug']);
        $this->assertSame('<p></p>', $additional['contents']);
        $this->assertSame(true, $additional['metadata']['hideInMenu']);
        $this->assertSame('drupal-additional-pages', $additional['metadata']['sourceType']);

        $orphan = $items[5];
        $this->assertSame('Orphan Page', $orphan['title']);
        $this->assertSame('additional-pages/orphan-page', $orphan['slug']);
        $this->assertSame(0, $orphan['order']);
        $this->assertSame(1, $orphan['indent']);
        $this->assertSame($additional['id'], $orphan['parent']);
        $this->assertSame(false, $orphan['metadata']['drupal']['inOutline']);

        $this->assertSame(array(
            'base' => 'http://' . self::HOST,
            'pagesTotal' => 5,
            'booksTotal' => 0,
            'outlineSource' => 'menu-link-content',
            'outlineNodes' => 4,
            'additionalNodes' => 1,
            'filesTotal' => 6,
            'filesImported' => 3,
            'filesSkipped' => array('temporary' => 1, 'extension' => 1, 'no-url' => 1),
            'mediaTotal' => 4,
            'mediaResolved' => 4,
            'mediaUnresolved' => 0,
            'truncated' => false,
        ), $response['data']['drupal']);

        // the paged node--page collection followed links.next (first-wins
        // dedupe kept the original nid 10 title)
        $pageFetches = array_values(array_filter($this->requests, function ($u) {
            return strpos($u, '/jsonapi/node/page') !== false;
        }));
        $this->assertCount(2, $pageFetches);
        $this->assertNull($this->itemBy($items, 'Duplicate Student Development'));

        // menu-items probes all 404ed for main + footer (account denylisted)
        $probeFetches = array_values(array_filter($this->requests, function ($u) {
            return preg_match('#^93\.184\.215\.14/jsonapi/(menu_items|menu|jsonapi_menu)/#', $u) === 1
                && strpos($u, '/jsonapi/menu/menu?') === false;
        }));
        $this->assertCount(6, $probeFetches);
        $this->assertNotEmpty($probeFetches);
        foreach ($probeFetches as $probe) {
            $this->assertStringEndsNotWith('/account', $probe);
        }
    }

    public function testJsonapiMenuItemsPayloadDrivesTheOutline(): void
    {
        $response = $this->import(array('repoUrl' => 'http://' . self::HOST_MENUITEMS));
        $this->assertSame(200, $response['status']);
        $this->assertSame('93-184-215-15', $response['data']['filename']);
        $this->assertSame(array(), $response['data']['files']);

        $items = $response['data']['items'];
        $this->assertCount(4, $items);

        $landing = $items[0];
        $this->assertSame('Landing', $landing['title']);
        $this->assertSame('landing', $landing['slug']);
        $this->assertSame(0, $landing['order']);
        $this->assertSame(0, $landing['indent']);
        $this->assertNull($landing['parent']);

        // children nest via parent plugin-id refs; string node params normalize
        $subOne = $items[1];
        $this->assertSame('Sub One', $subOne['title']);
        $this->assertSame('landing/sub-one', $subOne['slug']);
        $this->assertSame(0, $subOne['order']);
        $this->assertSame(1, $subOne['indent']);
        $this->assertSame($landing['id'], $subOne['parent']);

        $subTwo = $items[2];
        $this->assertSame('Sub Two', $subTwo['title']);
        $this->assertSame('landing/sub-two', $subTwo['slug']);
        $this->assertSame(1, $subTwo['order']);
        $this->assertSame(1, $subTwo['indent']);
        $this->assertSame($landing['id'], $subTwo['parent']);

        // the news item's parent is a views link (no nid) so it promotes to
        // the nearest resolvable ancestor: top level
        $newsItem = $items[3];
        $this->assertSame('News Item', $newsItem['title']);
        $this->assertSame('news-item', $newsItem['slug']);
        $this->assertSame(1, $newsItem['order']);
        $this->assertSame(0, $newsItem['indent']);
        $this->assertNull($newsItem['parent']);

        $this->assertSame(array(
            'base' => 'http://' . self::HOST_MENUITEMS,
            'pagesTotal' => 4,
            'booksTotal' => 0,
            'outlineSource' => 'menu-items',
            'outlineNodes' => 4,
            'additionalNodes' => 0,
            'filesTotal' => 0,
            'filesImported' => 0,
            'filesSkipped' => array(),
            'mediaTotal' => 0,
            'mediaResolved' => 0,
            'mediaUnresolved' => 0,
            'truncated' => false,
        ), $response['data']['drupal']);

        // the main probe won immediately; later menus were never probed
        $this->assertContains(self::HOST_MENUITEMS . '/jsonapi/menu_items/main', $this->requests);
        $this->assertNotContains(self::HOST_MENUITEMS . '/jsonapi/menu_items/footer', $this->requests);
    }

    public function testNestedChildrenMenuPayloadDrivesTheOutline(): void
    {
        $response = $this->import(array('repoUrl' => 'http://' . self::HOST_FRONTENDMENU));
        $this->assertSame(200, $response['status']);

        $items = $response['data']['items'];
        $this->assertCount(2, $items);

        $landing = $items[0];
        $this->assertSame('Landing', $landing['title']);
        $this->assertSame('landing', $landing['slug']);
        $this->assertSame(0, $landing['indent']);
        $this->assertNull($landing['parent']);

        $subOne = $items[1];
        $this->assertSame('Sub One', $subOne['title']);
        $this->assertSame('landing/sub-one', $subOne['slug']);
        $this->assertSame(1, $subOne['indent']);
        $this->assertSame($landing['id'], $subOne['parent']);

        $this->assertSame('menu-items', $response['data']['drupal']['outlineSource']);

        // endpoint pattern preference: menu_items 404ed before /jsonapi/menu
        $menuItemsProbe = array_search(self::HOST_FRONTENDMENU . '/jsonapi/menu_items/main', $this->requests, true);
        $menuProbe = array_search(self::HOST_FRONTENDMENU . '/jsonapi/menu/main', $this->requests, true);
        $this->assertNotFalse($menuItemsProbe);
        $this->assertNotFalse($menuProbe);
        $this->assertLessThan($menuProbe, $menuItemsProbe);
    }

    public function testBookFieldsBuildAForestWithStructuralRootDemotion(): void
    {
        $response = $this->import(array('repoUrl' => 'http://' . self::HOST_BOOKFIELDS));
        $this->assertSame(200, $response['status']);
        $this->assertSame('93-184-215-17', $response['data']['filename']);

        $items = $response['data']['items'];
        // the empty Handbook root demotes its chapters; Second Book keeps its intro
        $this->assertCount(4, $items);

        $this->assertSame('Chapter One', $items[0]['title']);
        $this->assertSame('chapter-one', $items[0]['slug']);
        $this->assertSame(0, $items[0]['order']);
        $this->assertSame(0, $items[0]['indent']);
        $this->assertNull($items[0]['parent']);
        $this->assertSame('<p>Chapter one body text here.</p>', $items[0]['contents']);

        $this->assertSame('Chapter Two', $items[1]['title']);
        $this->assertSame('chapter-two', $items[1]['slug']);
        $this->assertSame(1, $items[1]['order']);

        $this->assertSame('Second Book', $items[2]['title']);
        $this->assertSame('second-book', $items[2]['slug']);
        $this->assertSame(2, $items[2]['order']);
        $this->assertSame('<p>Second book intro text.</p>', $items[2]['contents']);

        $this->assertSame('Second Book Child', $items[3]['title']);
        $this->assertSame('second-book/second-book-child', $items[3]['slug']);
        $this->assertSame(0, $items[3]['order']);
        $this->assertSame(1, $items[3]['indent']);
        $this->assertSame($items[2]['id'], $items[3]['parent']);

        $this->assertSame(array(
            'base' => 'http://' . self::HOST_BOOKFIELDS,
            'pagesTotal' => 0,
            'booksTotal' => 5,
            'outlineSource' => 'book-fields',
            'outlineNodes' => 4,
            'additionalNodes' => 0,
            'filesTotal' => 0,
            'filesImported' => 0,
            'filesSkipped' => array(),
            'mediaTotal' => 0,
            'mediaResolved' => 0,
            'mediaUnresolved' => 0,
            'truncated' => false,
        ), $response['data']['drupal']);
    }

    public function testNoMenuStructureFallsBackToAFlatCreatedOrderedOutline(): void
    {
        $response = $this->import(array('repoUrl' => 'http://' . self::HOST_FLAT));
        $this->assertSame(200, $response['status']);

        $items = $response['data']['items'];
        $this->assertCount(3, $items);
        // created order wins over title order
        $this->assertSame('Alpha', $items[0]['title']);
        $this->assertSame('alpha', $items[0]['slug']);
        $this->assertSame(0, $items[0]['order']);
        $this->assertSame(0, $items[0]['indent']);
        $this->assertNull($items[0]['parent']);
        $this->assertSame('Zebra', $items[1]['title']);
        $this->assertSame(1, $items[1]['order']);
        $this->assertSame('Middle', $items[2]['title']);
        $this->assertSame(2, $items[2]['order']);

        $this->assertSame('flat', $response['data']['drupal']['outlineSource']);
        $this->assertSame(0, $response['data']['drupal']['additionalNodes']);
    }

    public function testParentIdThreadsThroughToOutlineRootItems(): void
    {
        $response = $this->import(array('repoUrl' => 'http://' . self::HOST, 'parentId' => 'node-77'));
        $this->assertSame(200, $response['status']);
        $items = $response['data']['items'];
        $this->assertSame('node-77', $items[0]['parent']);
        $this->assertSame('node-77', $items[3]['parent']);
        $this->assertSame($items[0]['id'], $items[1]['parent']);
    }

    public function testTheFilesCapMarksTheImportTruncated(): void
    {
        $GLOBALS['HAXCMS_DRUPAL_LIMITS'] = array('maxFiles' => 1);
        $response = $this->import(array('repoUrl' => 'http://' . self::HOST));
        $this->assertSame(200, $response['status']);
        $this->assertCount(1, $response['data']['files']);
        $this->assertSame(true, $response['data']['drupal']['truncated']);
    }
}
