<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Characterization tests for lib/systemRoutes/v1/imports/importUtils.php.
 *
 * Not auto-loaded by tests/phpunit-bootstrap.php (everything under
 * systemRoutes/v1/ is skipped there to avoid sibling-file redeclaration
 * fatals), so it must be require_once'd directly here.
 *
 * Expected values are derived from each helper's documented purpose (slug
 * generation, JSON Outline Schema item shape, heading-hierarchy parsing)
 * and were cross-checked against the function's actual current behavior via
 * direct invocation before being written into assertions, since these are
 * characterization tests of existing procedural code with no other spec.
 */
require_once __DIR__ . '/../../lib/systemRoutes/v1/imports/importUtils.php';

class ImportUtilsTest extends TestCase
{
    // ------------------------------------------------------------------
    // haxcmsImportCleanTitle
    // ------------------------------------------------------------------

    public static function cleanTitleProvider(): array
    {
        return [
            'simple title with space' => ['My Page', 'my-page'],
            'empty string falls back to blank' => ['', 'blank'],
            'relative path prefix stripped' => ['./Notes', 'notes'],
            // str_replace(['./', '../'], ...) processes './' first, which
            // matches the './' substring inside '../Notes' (index 1-2),
            // leaving a leading '.' that the subsequent non-word-char regex
            // turns into a hyphen. This is a real quirk of the current
            // implementation, not the naively-expected 'notes'.
            'parent path prefix only partially stripped (known quirk)' => ['../Notes', '-notes'],
            'multiple spaces collapse' => ['A   B', 'a-b'],
            'already-clean slug passes through' => ['already-clean', 'already-clean'],
        ];
    }

    #[DataProvider('cleanTitleProvider')]
    public function testHaxcmsImportCleanTitle(string $input, string $expected): void
    {
        $this->assertSame($expected, haxcmsImportCleanTitle($input));
    }

    // ------------------------------------------------------------------
    // haxcmsImportBuildItem
    // ------------------------------------------------------------------

    public function testHaxcmsImportBuildItemProducesExpectedShape(): void
    {
        $item = haxcmsImportBuildItem('Title', 'slug', 2, 'parent-id', 1, '<p>c</p>');

        $this->assertSame('Title', $item['title']);
        $this->assertSame('slug', $item['slug']);
        $this->assertSame(2, $item['order']);
        $this->assertSame('parent-id', $item['parent']);
        $this->assertSame(1, $item['indent']);
        $this->assertSame('<p>c</p>', $item['contents']);
        $this->assertSame('', $item['location']);
        $this->assertSame('', $item['description']);
        $this->assertInstanceOf(stdClass::class, $item['metadata']);
        $this->assertMatchesRegularExpression('/^item-/', $item['id']);
    }

    public function testHaxcmsImportBuildItemGeneratesUniqueIds(): void
    {
        $a = haxcmsImportBuildItem('A', 'a', 0, null, 0, '');
        $b = haxcmsImportBuildItem('B', 'b', 0, null, 0, '');
        $this->assertNotSame($a['id'], $b['id']);
    }

    // ------------------------------------------------------------------
    // haxcmsImportSimpleHtmlToElements
    // ------------------------------------------------------------------

    public function testHaxcmsImportSimpleHtmlToElementsParsesTopLevelElements(): void
    {
        $elements = haxcmsImportSimpleHtmlToElements('<h1>Title</h1><p>Body content</p>');

        $this->assertCount(2, $elements);
        $this->assertSame('H1', $elements[0]['tagName']);
        $this->assertSame('<h1>Title</h1>', $elements[0]['html']);
        $this->assertSame('Title', $elements[0]['text']);
        $this->assertSame('P', $elements[1]['tagName']);
        $this->assertSame('<p>Body content</p>', $elements[1]['html']);
        $this->assertSame('Body content', $elements[1]['text']);
    }

    public function testHaxcmsImportSimpleHtmlToElementsEmptyStringReturnsEmptyArray(): void
    {
        $this->assertSame([], haxcmsImportSimpleHtmlToElements(''));
    }

    // ------------------------------------------------------------------
    // haxcmsImportCollectSiblingsUntil
    // ------------------------------------------------------------------

    public function testHaxcmsImportCollectSiblingsUntilStopsAtStopTag(): void
    {
        $elements = [
            ['tagName' => 'H1', 'text' => 'root'],
            ['tagName' => 'P', 'text' => 'a'],
            ['tagName' => 'P', 'text' => 'b'],
            ['tagName' => 'H1', 'text' => 'next-root'],
            ['tagName' => 'P', 'text' => 'c'],
        ];

        $siblings = haxcmsImportCollectSiblingsUntil($elements, 0, ['H1']);

        $this->assertCount(2, $siblings);
        $this->assertSame('a', $siblings[0]['text']);
        $this->assertSame('b', $siblings[1]['text']);
    }

    public function testHaxcmsImportCollectSiblingsUntilReturnsAllWhenNoStopTagFound(): void
    {
        $elements = [
            ['tagName' => 'H1', 'text' => 'root'],
            ['tagName' => 'P', 'text' => 'a'],
            ['tagName' => 'P', 'text' => 'b'],
        ];

        $siblings = haxcmsImportCollectSiblingsUntil($elements, 0, ['H2']);

        $this->assertCount(2, $siblings);
    }

    public function testHaxcmsImportCollectSiblingsUntilAtLastIndexReturnsEmpty(): void
    {
        $elements = [['tagName' => 'H1', 'text' => 'root']];
        $this->assertSame([], haxcmsImportCollectSiblingsUntil($elements, 0, ['H1']));
    }

    // ------------------------------------------------------------------
    // haxcmsImportGetHighestHeadingLevel
    // ------------------------------------------------------------------

    public function testHaxcmsImportGetHighestHeadingLevelReturnsLowestNumericLevelPresent(): void
    {
        $elements = [['tagName' => 'H2'], ['tagName' => 'H1']];
        $this->assertSame(1, haxcmsImportGetHighestHeadingLevel($elements));
    }

    public function testHaxcmsImportGetHighestHeadingLevelReturnsH2WhenNoH1Present(): void
    {
        $elements = [['tagName' => 'P'], ['tagName' => 'H2']];
        $this->assertSame(2, haxcmsImportGetHighestHeadingLevel($elements));
    }

    public function testHaxcmsImportGetHighestHeadingLevelReturnsNullWhenNoHeadings(): void
    {
        $elements = [['tagName' => 'P'], ['tagName' => 'DIV']];
        $this->assertNull(haxcmsImportGetHighestHeadingLevel($elements));
    }

    // ------------------------------------------------------------------
    // haxcmsImportGetFallbackContent
    // ------------------------------------------------------------------

    public function testHaxcmsImportGetFallbackContentPortfolio(): void
    {
        $content = haxcmsImportGetFallbackContent('portfolio');
        $this->assertStringContainsString('Enjoy my portfolio', $content);
        $this->assertStringContainsString('<lesson-highlight smart="pages">', $content);
    }

    public function testHaxcmsImportGetFallbackContentCourse(): void
    {
        $content = haxcmsImportGetFallbackContent('course');
        $this->assertStringContainsString('Welcome to the lesson.', $content);
        $this->assertStringContainsString('<lesson-highlight smart="readTime">', $content);
        $this->assertStringContainsString('<lesson-highlight smart="selfChecks">', $content);
        $this->assertStringContainsString('<lesson-highlight smart="audio">', $content);
        $this->assertStringContainsString('<lesson-highlight smart="video">', $content);
    }

    public function testHaxcmsImportGetFallbackContentDefault(): void
    {
        $this->assertSame('<p></p>', haxcmsImportGetFallbackContent('other'));
        $this->assertSame('<p></p>', haxcmsImportGetFallbackContent(''));
    }

    // ------------------------------------------------------------------
    // haxcmsImportConvertDocxXmlToHtml
    // ------------------------------------------------------------------

    public function testHaxcmsImportConvertDocxXmlToHtmlHandlesHeadingsAndRunFormatting(): void
    {
        $xml = '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            . '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Heading Text</w:t></w:r></w:p>'
            . '<w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Bold</w:t></w:r><w:r><w:t> normal</w:t></w:r></w:p>'
            . '<w:p></w:p>'
            . '</w:body></w:document>';

        $html = haxcmsImportConvertDocxXmlToHtml($xml);

        $this->assertSame(
            "<h1>Heading Text</h1>\n<p><strong>Bold</strong> normal</p>\n<p></p>",
            $html
        );
    }

    public function testHaxcmsImportConvertDocxXmlToHtmlEscapesTextContent(): void
    {
        $xml = '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            . '<w:p><w:r><w:t>&lt;script&gt;</w:t></w:r></w:p>'
            . '</w:body></w:document>';

        $html = haxcmsImportConvertDocxXmlToHtml($xml);

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    // ------------------------------------------------------------------
    // haxcmsImportHtmlToItems — method=site
    // ------------------------------------------------------------------

    public function testHaxcmsImportHtmlToItemsSiteMethodBuildsNestedHierarchy(): void
    {
        $html = '<h1>Root A</h1><p>Intro A</p><h2>Child A1</h2><p>Content A1</p><h1>Root B</h1><p>Intro B</p>';

        $items = haxcmsImportHtmlToItems($html, ['titleValue' => 't', 'method' => 'site']);

        $this->assertCount(3, $items);

        $this->assertSame('Root A', $items[0]['title']);
        $this->assertSame('root-a', $items[0]['slug']);
        $this->assertSame(0, $items[0]['order']);
        $this->assertNull($items[0]['parent']);
        $this->assertSame(0, $items[0]['indent']);
        $this->assertSame('<p>Intro A</p>', $items[0]['contents']);

        $this->assertSame('Child A1', $items[1]['title']);
        $this->assertSame('root-a/child-a1', $items[1]['slug']);
        $this->assertSame(0, $items[1]['order']);
        $this->assertSame($items[0]['id'], $items[1]['parent']);
        $this->assertSame(1, $items[1]['indent']);
        $this->assertSame('<p>Content A1</p>', $items[1]['contents']);

        $this->assertSame('Root B', $items[2]['title']);
        $this->assertSame('root-b', $items[2]['slug']);
        $this->assertSame(1, $items[2]['order']);
        $this->assertNull($items[2]['parent']);
        $this->assertSame(0, $items[2]['indent']);
        $this->assertSame('<p>Intro B</p>', $items[2]['contents']);
    }

    public function testHaxcmsImportHtmlToItemsSiteMethodUsesParentIdForRoots(): void
    {
        $items = haxcmsImportHtmlToItems('<h1>Only</h1><p>Body</p>', [
            'titleValue' => 't',
            'method' => 'site',
            'parentId' => 'existing-parent',
        ]);

        $this->assertCount(1, $items);
        $this->assertSame('existing-parent', $items[0]['parent']);
    }

    public function testHaxcmsImportHtmlToItemsSiteMethodEmptyRootContentUsesFallback(): void
    {
        // Root heading immediately followed by a child heading with no
        // sibling content in between falls back to haxcmsImportGetFallbackContent.
        $items = haxcmsImportHtmlToItems('<h1>Root</h1><h2>Child</h2><p>Child body</p>', [
            'titleValue' => 't',
            'method' => 'site',
            'type' => 'course',
        ]);

        $this->assertSame('<p>Welcome to the lesson.</p>', substr($items[0]['contents'], 0, strlen('<p>Welcome to the lesson.</p>')));
    }

    public function testHaxcmsImportHtmlToItemsSiteMethodNoHeadingsFallsBackToSingleItem(): void
    {
        $items = haxcmsImportHtmlToItems('<p>Just a paragraph</p>', [
            'titleValue' => 'MyImport',
            'method' => 'site',
        ]);

        $this->assertCount(1, $items);
        $this->assertSame('MyImport', $items[0]['title']);
        $this->assertSame('myimport', $items[0]['slug']);
        $this->assertSame(0, $items[0]['order']);
        $this->assertSame(0, $items[0]['indent']);
        $this->assertSame('<p>Just a paragraph</p>', $items[0]['contents']);
    }

    // ------------------------------------------------------------------
    // haxcmsImportHtmlToItems — method=branch
    // ------------------------------------------------------------------

    public function testHaxcmsImportHtmlToItemsBranchMethodProducesFlatRootItems(): void
    {
        $html = '<h1>Root A</h1><p>Intro A</p><h2>Child A1</h2><p>Content A1</p><h1>Root B</h1><p>Intro B</p>';

        $items = haxcmsImportHtmlToItems($html, ['titleValue' => 't', 'method' => 'branch']);

        $this->assertCount(2, $items);
        $this->assertSame('Root A', $items[0]['title']);
        $this->assertSame(0, $items[0]['indent']);
        $this->assertSame('<p>Intro A</p><h2>Child A1</h2><p>Content A1</p>', $items[0]['contents']);
        $this->assertSame('Root B', $items[1]['title']);
        $this->assertSame('<p>Intro B</p>', $items[1]['contents']);
    }

    public function testHaxcmsImportHtmlToItemsBranchMethodNoHeadingsFallsBack(): void
    {
        $items = haxcmsImportHtmlToItems('<p>Flat content</p>', [
            'titleValue' => 'Flat',
            'method' => 'branch',
        ]);

        $this->assertCount(1, $items);
        $this->assertSame('Flat', $items[0]['title']);
        $this->assertSame('<p>Flat content</p>', $items[0]['contents']);
    }

    // ------------------------------------------------------------------
    // haxcmsImportHtmlToItems — method=page (and default)
    // ------------------------------------------------------------------

    public function testHaxcmsImportHtmlToItemsPageMethodConcatenatesAllElements(): void
    {
        $items = haxcmsImportHtmlToItems('<h1>Heading</h1><p>Body</p>', [
            'titleValue' => 'PageTitle',
            'method' => 'page',
        ]);

        $this->assertCount(1, $items);
        $this->assertSame('PageTitle', $items[0]['title']);
        $this->assertSame('pagetitle', $items[0]['slug']);
        $this->assertSame('<h1>Heading</h1><p>Body</p>', $items[0]['contents']);
    }

    public function testHaxcmsImportHtmlToItemsDefaultsToPageMethodWhenUnspecified(): void
    {
        $items = haxcmsImportHtmlToItems('<p>Body</p>', ['titleValue' => 'X']);
        $this->assertCount(1, $items);
        $this->assertSame('<p>Body</p>', $items[0]['contents']);
    }

    public function testHaxcmsImportHtmlToItemsEmptyHtmlFallsBackToEmptyParagraph(): void
    {
        $items = haxcmsImportHtmlToItems('', ['titleValue' => 'Empty', 'method' => 'page']);

        $this->assertCount(1, $items);
        $this->assertSame('Empty', $items[0]['title']);
        $this->assertSame('empty', $items[0]['slug']);
        $this->assertSame('<p></p>', $items[0]['contents']);
    }

    public function testHaxcmsImportHtmlToItemsDefaultTitleValueIsImport(): void
    {
        $items = haxcmsImportHtmlToItems('<p>Body</p>', ['method' => 'page']);
        $this->assertSame('import', $items[0]['title']);
    }

    // ------------------------------------------------------------------
    // haxcmsImportHtmlToItems — sanitization (D36 central HTML sanitize pass)
    // ------------------------------------------------------------------

    public function testHaxcmsImportHtmlToItemsSanitizesScriptAndEventHandlers(): void
    {
        $html = '<h1>Title</h1><script>alert(1)</script><p onclick="x()">Body</p>';

        $items = haxcmsImportHtmlToItems($html, ['titleValue' => 't', 'method' => 'page']);

        $this->assertCount(1, $items);
        $this->assertStringNotContainsString('<script>', $items[0]['contents']);
        $this->assertStringNotContainsString('onclick', $items[0]['contents']);
        $this->assertStringContainsString('<h1>Title</h1>', $items[0]['contents']);
        $this->assertStringContainsString('<p>Body</p>', $items[0]['contents']);
    }
}
