<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Document\Document;
use EndlessCreativity\ElephantPhp\Document\DocumentConverter;
use EndlessCreativity\ElephantPhp\Document\Hyperlink;
use EndlessCreativity\ElephantPhp\Document\Paragraph;
use EndlessCreativity\ElephantPhp\Document\Run;
use EndlessCreativity\ElephantPhp\Document\Text;

function convertWithHyperlink(Hyperlink $hyperlink): string
{
    $document = new Document(children: [new Paragraph(children: [$hyperlink])]);

    return (new DocumentConverter())->convertToHtml($document)->value;
}

it('renders a hyperlink with href as <a href="...">', function (): void {
    $hyperlink = new Hyperlink(
        children: [new Run(children: [new Text(value: 'click me')])],
        href: 'http://example.com/',
    );

    expect(convertWithHyperlink($hyperlink))
        ->toBe('<p><a href="http://example.com/">click me</a></p>');
});

it('renders a hyperlink with anchor as <a href="#anchor">', function (): void {
    $hyperlink = new Hyperlink(
        children: [new Run(children: [new Text(value: 'jump')])],
        anchor: 'section-2',
    );

    expect(convertWithHyperlink($hyperlink))
        ->toBe('<p><a href="#section-2">jump</a></p>');
});

it('renders a hyperlink with targetFrame as a target attribute', function (): void {
    $hyperlink = new Hyperlink(
        children: [new Run(children: [new Text(value: 'click')])],
        href: 'http://example.com/',
        targetFrame: '_blank',
    );

    expect(convertWithHyperlink($hyperlink))
        ->toBe('<p><a href="http://example.com/" target="_blank">click</a></p>');
});

it('escapes special characters in href', function (): void {
    $hyperlink = new Hyperlink(
        children: [new Run(children: [new Text(value: 'q')])],
        href: 'http://example.com/?x=1&y="z"',
    );

    expect(convertWithHyperlink($hyperlink))
        ->toBe('<p><a href="http://example.com/?x=1&amp;y=&quot;z&quot;">q</a></p>');
});

it('unwraps a hyperlink with no href and no anchor', function (): void {
    $hyperlink = new Hyperlink(
        children: [new Run(children: [new Text(value: 'plain')])],
    );

    expect(convertWithHyperlink($hyperlink))->toBe('<p>plain</p>');
});

it('strips the <a> wrapper when the href is a javascript: URL (XSS guard)', function (): void {
    $hyperlink = new Hyperlink(
        children: [new Run(children: [new Text(value: 'click')])],
        href: 'javascript:alert(1)',
    );

    expect(convertWithHyperlink($hyperlink))->toBe('<p>click</p>');
});

it('strips the <a> wrapper when the href is a data: URL', function (): void {
    $hyperlink = new Hyperlink(
        children: [new Run(children: [new Text(value: 'click')])],
        href: 'data:text/html,<script>alert(1)</script>',
    );

    expect(convertWithHyperlink($hyperlink))->toBe('<p>click</p>');
});

it('strips the <a> wrapper when the href is vbscript:', function (): void {
    $hyperlink = new Hyperlink(
        children: [new Run(children: [new Text(value: 'click')])],
        href: 'vbscript:msgbox 1',
    );

    expect(convertWithHyperlink($hyperlink))->toBe('<p>click</p>');
});

it('matches dangerous schemes case-insensitively', function (): void {
    $hyperlink = new Hyperlink(
        children: [new Run(children: [new Text(value: 'click')])],
        href: 'JaVaScRiPt:alert(1)',
    );

    expect(convertWithHyperlink($hyperlink))->toBe('<p>click</p>');
});

it('strips the <a> wrapper when leading whitespace / control chars hide the scheme', function (): void {
    // Browsers historically tolerate `\tjavascript:` / leading control
    // characters before the scheme. The filter ignores them too.
    $hyperlink = new Hyperlink(
        children: [new Run(children: [new Text(value: 'click')])],
        href: "  \tjavascript:alert(1)",
    );

    expect(convertWithHyperlink($hyperlink))->toBe('<p>click</p>');
});

it('keeps mailto: links untouched (not a script scheme)', function (): void {
    $hyperlink = new Hyperlink(
        children: [new Run(children: [new Text(value: 'mail')])],
        href: 'mailto:foo@example.com',
    );

    expect(convertWithHyperlink($hyperlink))
        ->toBe('<p><a href="mailto:foo@example.com">mail</a></p>');
});

it('keeps https: links untouched', function (): void {
    $hyperlink = new Hyperlink(
        children: [new Run(children: [new Text(value: 'docs')])],
        href: 'https://example.com/',
    );

    expect(convertWithHyperlink($hyperlink))
        ->toBe('<p><a href="https://example.com/">docs</a></p>');
});
