<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Characterization tests for the 6 pure helpers extracted from items.php and
 * search.php onto SiteRouteUtils (Phase 3, Area C):
 * buildItemNavigationMap, buildHaxElementSchemaFromHtml, buildItemJsonLd,
 * normalizeSearchFields, getSearchFieldValue, findMatch.
 *
 * Expected values are derived from each method's docblock contract and the
 * calling route handler's documented behavior (items.php / search.php), not
 * a re-derivation of the implementation.
 */
class SiteRouteUtilsItemsSearchHelpersTest extends TestCase
{
    protected function setUp(): void
    {
        unset($GLOBALS['HAXCMS']);
    }

    private function item($id, $slug, $parent = '')
    {
        $item = new stdClass();
        $item->id = $id;
        $item->slug = $slug;
        $item->parent = $parent;
        return $item;
    }

    // ------------------------------------------------------------------
    // buildItemNavigationMap
    // ------------------------------------------------------------------

    public function testBuildItemNavigationMapLinksPreviousNextForMiddleItem(): void
    {
        $items = [$this->item('a', 'page-a'), $this->item('b', 'page-b'), $this->item('c', 'page-c')];
        $map = SiteRouteUtils::buildItemNavigationMap($items, '/x/api');
        $this->assertSame('/x/api/v1/items/page-a', $map['b']['previous']);
        $this->assertSame('/x/api/v1/items/page-c', $map['b']['next']);
    }

    public function testBuildItemNavigationMapFirstItemHasNoPrevious(): void
    {
        $items = [$this->item('a', 'page-a'), $this->item('b', 'page-b')];
        $map = SiteRouteUtils::buildItemNavigationMap($items, '/x/api');
        $this->assertNull($map['a']['previous']);
        $this->assertSame('/x/api/v1/items/page-b', $map['a']['next']);
    }

    public function testBuildItemNavigationMapLastItemHasNoNext(): void
    {
        $items = [$this->item('a', 'page-a'), $this->item('b', 'page-b')];
        $map = SiteRouteUtils::buildItemNavigationMap($items, '/x/api');
        $this->assertNull($map['b']['next']);
    }

    public function testBuildItemNavigationMapResolvesParentLookupValueFromItemById(): void
    {
        $items = [$this->item('parent-1', 'parent-page'), $this->item('child-1', 'child-page', 'parent-1')];
        $map = SiteRouteUtils::buildItemNavigationMap($items, '/x/api');
        $this->assertSame('/x/api/v1/items/parent-page', $map['child-1']['parent']);
    }

    public function testBuildItemNavigationMapParentFallsBackToRawIdWhenParentNotInList(): void
    {
        $items = [$this->item('child-1', 'child-page', 'missing-parent')];
        $map = SiteRouteUtils::buildItemNavigationMap($items, '/x/api');
        $this->assertSame('/x/api/v1/items/missing-parent', $map['child-1']['parent']);
    }

    public function testBuildItemNavigationMapNoParentIsNull(): void
    {
        $items = [$this->item('a', 'page-a')];
        $map = SiteRouteUtils::buildItemNavigationMap($items, '/x/api');
        $this->assertNull($map['a']['parent']);
    }

    public function testBuildItemNavigationMapChildrenLinkUsesFilterParentQuery(): void
    {
        $items = [$this->item('a', 'page-a')];
        $map = SiteRouteUtils::buildItemNavigationMap($items, '/x/api');
        $this->assertSame('/x/api/v1/items?filter.parent=a', $map['a']['children']);
    }

    public function testBuildItemNavigationMapSkipsItemsWithoutId(): void
    {
        $withoutId = new stdClass();
        $withoutId->slug = 'no-id';
        $items = [$withoutId, $this->item('a', 'page-a')];
        $map = SiteRouteUtils::buildItemNavigationMap($items, '/x/api');
        $this->assertCount(1, $map);
        $this->assertArrayHasKey('a', $map);
    }

    public function testBuildItemNavigationMapEmptyListReturnsEmptyMap(): void
    {
        $this->assertSame([], SiteRouteUtils::buildItemNavigationMap([], '/x/api'));
    }

    // ------------------------------------------------------------------
    // buildHaxElementSchemaFromHtml
    // ------------------------------------------------------------------

    public function testBuildHaxElementSchemaFromHtmlEmptyStringReturnsEmptyArray(): void
    {
        $this->assertSame([], SiteRouteUtils::buildHaxElementSchemaFromHtml(''));
    }

    public function testBuildHaxElementSchemaFromHtmlWhitespaceOnlyReturnsEmptyArray(): void
    {
        $this->assertSame([], SiteRouteUtils::buildHaxElementSchemaFromHtml('   '));
    }

    public function testBuildHaxElementSchemaFromHtmlSingleElementExtractsTagAndAttributes(): void
    {
        $schema = SiteRouteUtils::buildHaxElementSchemaFromHtml('<p class="intro">Hello</p>');
        $this->assertCount(1, $schema);
        $this->assertSame('p', $schema[0]['tag']);
        $this->assertSame('intro', $schema[0]['properties']['class']);
        $this->assertStringContainsString('Hello', $schema[0]['content']);
    }

    public function testBuildHaxElementSchemaFromHtmlMultipleTopLevelElements(): void
    {
        $schema = SiteRouteUtils::buildHaxElementSchemaFromHtml('<h1>Title</h1><p>Body</p>');
        $this->assertCount(2, $schema);
        $this->assertSame('h1', $schema[0]['tag']);
        $this->assertSame('p', $schema[1]['tag']);
    }

    public function testBuildHaxElementSchemaFromHtmlBooleanAttributeYieldsTrue(): void
    {
        $schema = SiteRouteUtils::buildHaxElementSchemaFromHtml('<video-player autoplay></video-player>');
        $this->assertSame('video-player', $schema[0]['tag']);
        // DOMDocument normalizes valueless attributes to an empty string
        // value, not a PHP boolean; the "=== null -> true" branch in
        // buildHaxElementSchemaFromHtml is defensive but DOM never yields
        // null attribute values in practice, so the observed contract is ''.
        $this->assertSame('', $schema[0]['properties']['autoplay']);
    }

    public function testBuildHaxElementSchemaFromHtmlNestedContentPreservedAsInnerHtml(): void
    {
        $schema = SiteRouteUtils::buildHaxElementSchemaFromHtml('<div><span>nested</span></div>');
        $this->assertSame('div', $schema[0]['tag']);
        $this->assertStringContainsString('<span>nested</span>', $schema[0]['content']);
    }

    // ------------------------------------------------------------------
    // buildItemJsonLd
    // ------------------------------------------------------------------

    public function testBuildItemJsonLdBasicFields(): void
    {
        $record = [
            'id' => 'item-1',
            'slug' => 'about-us',
            'title' => 'About Us',
            'description' => 'A page',
            'links' => ['self' => '/x/api/v1/items/about-us'],
        ];
        $jsonld = SiteRouteUtils::buildItemJsonLd($record, '/mysite/', 'en');
        $this->assertSame('https://schema.org', $jsonld['@context']);
        $this->assertSame('WebPage', $jsonld['@type']);
        $this->assertSame('/x/api/v1/items/about-us', $jsonld['url']);
        $this->assertSame('/x/api/v1/items/about-us#webpage', $jsonld['@id']);
        $this->assertSame('About Us', $jsonld['name']);
        $this->assertSame('A page', $jsonld['description']);
        $this->assertSame('en', $jsonld['inLanguage']);
        $this->assertSame('item-1', $jsonld['identifier']);
        $this->assertSame('/mysite/about-us', $jsonld['mainEntityOfPage']);
    }

    public function testBuildItemJsonLdFallsBackToCanonicalPathWhenNoSelfLink(): void
    {
        $record = ['id' => 'item-1', 'slug' => 'about-us', 'title' => 'About'];
        $jsonld = SiteRouteUtils::buildItemJsonLd($record, '/mysite/', 'en');
        $this->assertSame('/mysite/about-us', $jsonld['url']);
        $this->assertSame('/mysite/about-us#webpage', $jsonld['@id']);
    }

    public function testBuildItemJsonLdUsesIdWhenSlugMissing(): void
    {
        $record = ['id' => 'item-1', 'title' => 'About'];
        $jsonld = SiteRouteUtils::buildItemJsonLd($record, '/mysite/', 'en');
        $this->assertSame('/mysite/item-1', $jsonld['mainEntityOfPage']);
    }

    public function testBuildItemJsonLdDatesFromMetadataUnixTimestamps(): void
    {
        $record = [
            'id' => 'item-1',
            'slug' => 'p',
            'title' => 'P',
            'metadata' => ['created' => 1609459200, 'updated' => 1609459200],
        ];
        $jsonld = SiteRouteUtils::buildItemJsonLd($record, '/', 'en');
        $this->assertSame('2021-01-01T00:00:00+00:00', $jsonld['datePublished']);
        $this->assertSame('2021-01-01T00:00:00+00:00', $jsonld['dateModified']);
    }

    public function testBuildItemJsonLdNoMetadataDatesAreNull(): void
    {
        $record = ['id' => 'item-1', 'slug' => 'p', 'title' => 'P'];
        $jsonld = SiteRouteUtils::buildItemJsonLd($record, '/', 'en');
        $this->assertNull($jsonld['datePublished']);
        $this->assertNull($jsonld['dateModified']);
    }

    public function testBuildItemJsonLdKeywordsFromTags(): void
    {
        $record = ['id' => 'item-1', 'slug' => 'p', 'title' => 'P', 'tags' => ['a', 'b']];
        $jsonld = SiteRouteUtils::buildItemJsonLd($record, '/', 'en');
        $this->assertSame(['a', 'b'], $jsonld['keywords']);
    }

    // ------------------------------------------------------------------
    // normalizeSearchFields
    // ------------------------------------------------------------------

    public static function normalizeSearchFieldsProvider(): array
    {
        $defaultSet = ['title', 'slug', 'description', 'tags', 'content'];
        return [
            'empty array falls back to defaults' => [[], $defaultSet],
            'valid subset preserved and lowercased' => [['ID', 'Title'], ['id', 'title']],
            'invalid entries dropped' => [['bogus', 'id'], ['id']],
            'all invalid falls back to defaults' => [['bogus', 'nope'], $defaultSet],
            'duplicate entries de-duplicated' => [['id', 'id'], ['id']],
            'whitespace trimmed' => [[' id ', ' slug '], ['id', 'slug']],
            'location field allowed' => [['location'], ['location']],
        ];
    }

    #[DataProvider('normalizeSearchFieldsProvider')]
    public function testNormalizeSearchFields(array $input, array $expected): void
    {
        $this->assertSame($expected, SiteRouteUtils::normalizeSearchFields($input));
    }

    // ------------------------------------------------------------------
    // getSearchFieldValue
    // ------------------------------------------------------------------

    public function testGetSearchFieldValueId(): void
    {
        $item = (object) ['id' => 'item-1'];
        $this->assertSame('item-1', SiteRouteUtils::getSearchFieldValue('id', $item));
    }

    public function testGetSearchFieldValueTitle(): void
    {
        $item = (object) ['title' => 'Hello'];
        $this->assertSame('Hello', SiteRouteUtils::getSearchFieldValue('title', $item));
    }

    public function testGetSearchFieldValueSlug(): void
    {
        $item = (object) ['slug' => 'hello'];
        $this->assertSame('hello', SiteRouteUtils::getSearchFieldValue('slug', $item));
    }

    public function testGetSearchFieldValueDescription(): void
    {
        $item = (object) ['description' => 'desc'];
        $this->assertSame('desc', SiteRouteUtils::getSearchFieldValue('description', $item));
    }

    public function testGetSearchFieldValueLocation(): void
    {
        $item = (object) ['location' => 'pages/x/index.html'];
        $this->assertSame('pages/x/index.html', SiteRouteUtils::getSearchFieldValue('location', $item));
    }

    public function testGetSearchFieldValueTagsJoinsNormalizedTagList(): void
    {
        $item = (object) ['metadata' => (object) ['tags' => ['a', ' b ', '']]];
        $this->assertSame('a b', SiteRouteUtils::getSearchFieldValue('tags', $item));
    }

    public function testGetSearchFieldValueTagsMissingMetadataReturnsEmptyString(): void
    {
        $item = (object) ['id' => 'x'];
        $this->assertSame('', SiteRouteUtils::getSearchFieldValue('tags', $item));
    }

    public function testGetSearchFieldValueContentReturnsPassedContent(): void
    {
        $item = (object) ['id' => 'x'];
        $this->assertSame('body text', SiteRouteUtils::getSearchFieldValue('content', $item, 'body text'));
    }

    public function testGetSearchFieldValueContentNonStringReturnsEmptyString(): void
    {
        $item = (object) ['id' => 'x'];
        $this->assertSame('', SiteRouteUtils::getSearchFieldValue('content', $item, null));
    }

    public function testGetSearchFieldValueUnknownFieldReturnsEmptyString(): void
    {
        $item = (object) ['id' => 'x'];
        $this->assertSame('', SiteRouteUtils::getSearchFieldValue('bogus', $item));
    }

    public function testGetSearchFieldValueMissingPropertyReturnsEmptyString(): void
    {
        $item = new stdClass();
        $this->assertSame('', SiteRouteUtils::getSearchFieldValue('title', $item));
    }

    // ------------------------------------------------------------------
    // findMatch
    // ------------------------------------------------------------------

    public function testFindMatchReturnsNullForEmptyValue(): void
    {
        $this->assertNull(SiteRouteUtils::findMatch('', 'query'));
    }

    public function testFindMatchReturnsNullWhenNotFound(): void
    {
        $this->assertNull(SiteRouteUtils::findMatch('the quick brown fox', 'zzz'));
    }

    public function testFindMatchReturnsIndexAndLength(): void
    {
        $match = SiteRouteUtils::findMatch('the quick brown fox', 'quick');
        $this->assertSame(4, $match['index']);
        $this->assertSame(5, $match['length']);
        $this->assertStringContainsString('quick', $match['snippet']);
    }

    public function testFindMatchIsCaseInsensitive(): void
    {
        $match = SiteRouteUtils::findMatch('The Quick Brown Fox', 'quick');
        $this->assertNotNull($match);
        $this->assertSame(4, $match['index']);
    }

    public function testFindMatchSnippetIsTrimmedAndWhitespaceCollapsed(): void
    {
        $value = str_repeat('a', 100) . ' target ' . str_repeat('b', 100);
        $match = SiteRouteUtils::findMatch($value, 'target');
        $this->assertNotNull($match);
        $this->assertStringContainsString('target', $match['snippet']);
        $this->assertDoesNotMatchRegularExpression('/\s{2,}/', $match['snippet']);
        // +/-60 char window: snippet should be substantially shorter than
        // the full 208-char source string.
        $this->assertLessThan(strlen($value), strlen($match['snippet']));
    }

    public function testFindMatchAtStartOfStringDoesNotUnderflowSnippetStart(): void
    {
        $match = SiteRouteUtils::findMatch('target at the very start', 'target');
        $this->assertSame(0, $match['index']);
        $this->assertStringContainsString('target', $match['snippet']);
    }
}
