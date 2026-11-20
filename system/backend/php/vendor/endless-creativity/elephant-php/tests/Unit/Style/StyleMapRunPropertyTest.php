<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Document\Document;
use EndlessCreativity\ElephantPhp\Document\DocumentConverter;
use EndlessCreativity\ElephantPhp\Document\Paragraph;
use EndlessCreativity\ElephantPhp\Document\Run;
use EndlessCreativity\ElephantPhp\Document\Text;
use EndlessCreativity\ElephantPhp\Style\MatcherKind;
use EndlessCreativity\ElephantPhp\Style\RunProperty;
use EndlessCreativity\ElephantPhp\Style\StyleMap;
use EndlessCreativity\ElephantPhp\Style\StyleMapParser;

dataset('runPropertyMatchers', [
    'b' => ['b', RunProperty::Bold],
    'i' => ['i', RunProperty::Italic],
    'u' => ['u', RunProperty::Underline],
    'strike' => ['strike', RunProperty::Strikethrough],
    'all-caps' => ['all-caps', RunProperty::AllCaps],
    'small-caps' => ['small-caps', RunProperty::SmallCaps],
]);

it('parses run-property matchers as Run + runProperty', function (string $keyword, RunProperty $property): void {
    $mapping = StyleMapParser::parse("{$keyword} => mark");

    expect($mapping->from->kind)->toBe(MatcherKind::Run);
    expect($mapping->from->runProperty)->toBe($property);
    expect($mapping->from->styleId)->toBeNull();
    expect($mapping->from->styleName)->toBeNull();
})->with('runPropertyMatchers');

function htmlOfRun(Run $run, ?StyleMap $styleMap = null): string
{
    return (new DocumentConverter(styleMap: $styleMap))
        ->convertToHtml(new Document(children: [new Paragraph(children: [$run])]))
        ->value;
}

it('overrides the default <strong> wrapper when "b => mark" is mapped', function (): void {
    $styleMap = StyleMap::default()->prepend([StyleMapParser::parse('b => mark')]);

    expect(htmlOfRun(
        new Run(children: [new Text(value: 'x')], isBold: true),
        $styleMap,
    ))->toBe('<p><mark>x</mark></p>');
});

it('still wraps in default <strong> when no "b" rule is mapped', function (): void {
    expect(htmlOfRun(new Run(children: [new Text(value: 'x')], isBold: true)))
        ->toBe('<p><strong>x</strong></p>');
});

it('wraps an underlined run only when a "u" rule is mapped', function (): void {
    $styleMap = StyleMap::default()->prepend([StyleMapParser::parse('u => u')]);
    $run = new Run(children: [new Text(value: 'x')], isUnderline: true);

    expect(htmlOfRun($run))->toBe('<p>x</p>');
    expect(htmlOfRun($run, $styleMap))->toBe('<p><u>x</u></p>');
});

it('layers run-property mappings respecting mammoth\'s outer-bold-inner-strike order', function (): void {
    $styleMap = StyleMap::default()->prepend([
        StyleMapParser::parse('b => b'),
        StyleMapParser::parse('strike => del'),
    ]);

    $run = new Run(
        children: [new Text(value: 'x')],
        isBold: true,
        isStrikethrough: true,
    );

    expect(htmlOfRun($run, $styleMap))->toBe('<p><b><del>x</del></b></p>');
});

it('character-style lookup ignores run-property matchers', function (): void {
    $styleMap = StyleMap::default()->prepend([
        StyleMapParser::parse('b => mark'),
        StyleMapParser::parse("r[style-name='Code'] => code"),
    ]);

    $run = new Run(
        children: [new Text(value: 'x')],
        styleName: 'Code',
        isBold: true,
    );

    expect(htmlOfRun($run, $styleMap))
        ->toBe('<p><code><mark>x</mark></code></p>');
});
