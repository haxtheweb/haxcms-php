<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Converter;

it('converts single-paragraph.docx into a single <p> element', function (): void {
    $result = (new Converter())->convertToHtml(fixture('single-paragraph.docx'));

    expect($result->value)->toBe('<p>Walking on imported air</p>');
});

it('converts strikethrough.docx with a strikethrough run wrapped in <s>', function (): void {
    $result = (new Converter())->convertToHtml(fixture('strikethrough.docx'));

    expect($result->value)->toBe("<p><s>Today's Special: Salmon</s> Sold out</p>");
});

it('converts simple-list.docx into a single <ul> with two <li> items', function (): void {
    $result = (new Converter())->convertToHtml(fixture('simple-list.docx'));

    expect($result->value)->toBe('<ul><li>Apple</li><li>Banana</li></ul>');
});

it('converts tables.docx into a 2x2 <table>', function (): void {
    $result = (new Converter())->convertToHtml(fixture('tables.docx'));

    expect($result->value)->toBe(
        '<p>Above</p>'
        .'<table>'
        .'<tr><td><p>Top left</p></td><td><p>Top right</p></td></tr>'
        .'<tr><td><p>Bottom left</p></td><td><p>Bottom right</p></td></tr>'
        .'</table>'
        .'<p>Below</p>',
    );
});

it('converts single-paragraph.docx to a markdown paragraph', function (): void {
    $result = (new Converter())->convertToMarkdown(fixture('single-paragraph.docx'));

    expect($result->value)->toBe("Walking on imported air\n\n");
});

it('converts simple-list.docx to a markdown bullet list', function (): void {
    $result = (new Converter())->convertToMarkdown(fixture('simple-list.docx'));

    expect($result->value)->toBe("- Apple\n- Banana\n\n");
});

it('extracts raw text from single-paragraph.docx', function (): void {
    $result = (new Converter())->extractRawText(fixture('single-paragraph.docx'));

    expect($result->value)->toBe("Walking on imported air\n\n");
});

it('extracts raw text from simple-list.docx with each item as its own paragraph', function (): void {
    $result = (new Converter())->extractRawText(fixture('simple-list.docx'));

    expect($result->value)->toBe("Apple\n\nBanana\n\n");
});

it('converts tiny-picture.docx into a paragraph with an embedded data-URI <img>', function (): void {
    $result = (new Converter())->convertToHtml(fixture('tiny-picture.docx'));

    $zip = new ZipArchive();
    $zip->open(fixture('tiny-picture.docx'));
    $imageBytes = $zip->getFromName('word/media/image1.png');
    $zip->close();

    expect($imageBytes)->not->toBeFalse();
    $expectedSrc = 'data:image/png;base64,'.base64_encode((string) $imageBytes);

    expect($result->value)->toBe('<p><img src="'.$expectedSrc.'" /></p>');
});

it('converts split-ordered-list.docx honouring <w:start> in HTML', function (): void {
    // Two ordered items backed by separate abstractNums with start=1 and
    // start=2, separated by a plain paragraph -- the pattern Word produces
    // when the user inserts content between list items.
    $result = (new Converter())->convertToHtml(fixture('split-ordered-list.docx'));

    expect($result->value)->toBe(
        '<ol><li>first</li></ol>'
        .'<p>in between</p>'
        .'<ol start="2"><li>second</li></ol>',
    );
});

it('converts split-ordered-list.docx honouring <w:start> in Markdown', function (): void {
    $result = (new Converter())->convertToMarkdown(fixture('split-ordered-list.docx'));

    expect($result->value)->toBe("1. first\n\nin between\n\n2. second\n\n");
});

it('converts footnotes.docx with note references and a notes section', function (): void {
    $html = (new Converter())->convertToHtml(fixture('footnotes.docx'))->value;

    expect($html)->toContain('Ouch');
    expect($html)->toContain('<sup><a href="#footnote-1" id="footnote-ref-1">[1]</a></sup>');
    expect($html)->toContain('<sup><a href="#footnote-2" id="footnote-ref-2">[2]</a></sup>');
    expect($html)->toContain('<ol>');
    expect($html)->toContain('<li id="footnote-1">');
    expect($html)->toContain('A tachyon walks into a bar.');
    expect($html)->toContain('<li id="footnote-2">');
    expect($html)->toContain('Fin.');
    expect($html)->toContain('<a href="#footnote-ref-1">↑</a>');
});
