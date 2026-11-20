<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Document\Checkbox;
use EndlessCreativity\ElephantPhp\Document\Paragraph;
use EndlessCreativity\ElephantPhp\Document\Run;
use EndlessCreativity\ElephantPhp\Document\Text;
use EndlessCreativity\ElephantPhp\Reader\BodyReader;
use EndlessCreativity\ElephantPhp\Reader\Xml\Element;
use EndlessCreativity\ElephantPhp\Reader\Xml\Text as XmlText;

/**
 * @return list<\EndlessCreativity\ElephantPhp\Document\Node>
 */
function readBody(Element $root): array
{
    $reader = new BodyReader();
    $result = $reader->readXmlElements($root->children);

    return $result->value;
}

/**
 * Walks a list of Document nodes recursively, collecting every Checkbox.
 * Needed because FORMCHECKBOX is emitted by the run that holds the closing
 * `<w:fldChar end>`, which makes the Checkbox a Run child rather than a
 * direct paragraph child.
 *
 * @param  list<\EndlessCreativity\ElephantPhp\Document\Node>  $nodes
 * @return list<Checkbox>
 */
function collectCheckboxes(array $nodes): array
{
    $found = [];
    foreach ($nodes as $node) {
        if ($node instanceof Checkbox) {
            $found[] = $node;
        }
        if ($node instanceof Run || $node instanceof Paragraph) {
            foreach (collectCheckboxes($node->children) as $inner) {
                $found[] = $inner;
            }
        }
    }

    return $found;
}

it('promotes <w:sdt> with <wordml:checkbox> to a Checkbox node', function (): void {
    $sdt = new Element(name: 'w:sdt', children: [
        new Element(name: 'w:sdtPr', children: [
            new Element(name: 'wordml:checkbox', children: [
                new Element(name: 'wordml:checked', attributes: ['wordml:val' => 'true']),
            ]),
        ]),
        new Element(name: 'w:sdtContent', children: [
            new Element(name: 'w:r', children: [
                new Element(name: 'w:t', children: [new XmlText(value: '☒')]),
            ]),
        ]),
    ]);

    $body = readBody(new Element(name: 'w:body', children: [$sdt]));

    expect($body)->toHaveCount(1);
    $run = $body[0];
    expect($run)->toBeInstanceOf(Run::class);
    assert($run instanceof Run);
    // The Run wraps the Checkbox: the original `<w:t>☒</w:t>` was
    // replaced by the checkbox in-place, preserving the run wrapper.
    expect($run->children)->toHaveCount(1);
    $checkbox = $run->children[0];
    expect($checkbox)->toBeInstanceOf(Checkbox::class);
    assert($checkbox instanceof Checkbox);
    expect($checkbox->checked)->toBeTrue();
});

it('treats <w:sdt> without checkbox as transparent (passes children through)', function (): void {
    $sdt = new Element(name: 'w:sdt', children: [
        new Element(name: 'w:sdtPr', children: []),
        new Element(name: 'w:sdtContent', children: [
            new Element(name: 'w:r', children: [
                new Element(name: 'w:t', children: [new XmlText(value: 'plain')]),
            ]),
        ]),
    ]);

    $body = readBody(new Element(name: 'w:body', children: [$sdt]));

    expect($body)->toHaveCount(1);
    $run = $body[0];
    expect($run)->toBeInstanceOf(Run::class);
    assert($run instanceof Run);
    $text = $run->children[0];
    expect($text)->toBeInstanceOf(Text::class);
    assert($text instanceof Text);
    expect($text->value)->toBe('plain');
});

it('reads an unchecked sdt checkbox when the wordml:val attribute is "false"', function (): void {
    $sdt = new Element(name: 'w:sdt', children: [
        new Element(name: 'w:sdtPr', children: [
            new Element(name: 'wordml:checkbox', children: [
                new Element(name: 'wordml:checked', attributes: ['wordml:val' => 'false']),
            ]),
        ]),
        new Element(name: 'w:sdtContent', children: []),
    ]);

    $body = readBody(new Element(name: 'w:body', children: [$sdt]));

    expect($body)->toHaveCount(1);
    $checkbox = $body[0];
    expect($checkbox)->toBeInstanceOf(Checkbox::class);
    assert($checkbox instanceof Checkbox);
    expect($checkbox->checked)->toBeFalse();
});

it('reads a checked FORMCHECKBOX from a complex field begin/separate/end run', function (): void {
    // Mammoth-style sequence: <w:fldChar begin> with ffData/checkBox,
    // <w:instrText>FORMCHECKBOX</w:instrText>, <w:fldChar separate>,
    // <w:fldChar end>. Wrapped in a paragraph since fldChars only
    // appear inside runs in real docs.
    $paragraph = new Element(name: 'w:p', children: [
        new Element(name: 'w:r', children: [
            new Element(name: 'w:fldChar', attributes: ['w:fldCharType' => 'begin'], children: [
                new Element(name: 'w:ffData', children: [
                    new Element(name: 'w:checkBox', children: [
                        new Element(name: 'w:default', attributes: ['w:val' => '0']),
                        new Element(name: 'w:checked', attributes: ['w:val' => '1']),
                    ]),
                ]),
            ]),
        ]),
        new Element(name: 'w:r', children: [
            new Element(name: 'w:instrText', children: [new XmlText(value: 'FORMCHECKBOX')]),
        ]),
        new Element(name: 'w:r', children: [
            new Element(name: 'w:fldChar', attributes: ['w:fldCharType' => 'separate']),
        ]),
        new Element(name: 'w:r', children: [
            new Element(name: 'w:fldChar', attributes: ['w:fldCharType' => 'end']),
        ]),
    ]);

    $body = readBody(new Element(name: 'w:body', children: [$paragraph]));

    expect($body)->toHaveCount(1);
    $paragraphNode = $body[0];
    expect($paragraphNode)->toBeInstanceOf(Paragraph::class);
    assert($paragraphNode instanceof Paragraph);
    // The end-fldChar lives inside a Run, so the Checkbox is one level
    // below the paragraph. Walk both layers to find it.
    $checkboxes = collectCheckboxes($paragraphNode->children);
    expect($checkboxes)->toHaveCount(1);
    expect($checkboxes[0]->checked)->toBeTrue();
});

it('falls back to <w:default> when <w:checked> is absent on FORMCHECKBOX', function (): void {
    $paragraph = new Element(name: 'w:p', children: [
        new Element(name: 'w:r', children: [
            new Element(name: 'w:fldChar', attributes: ['w:fldCharType' => 'begin'], children: [
                new Element(name: 'w:ffData', children: [
                    new Element(name: 'w:checkBox', children: [
                        new Element(name: 'w:default', attributes: ['w:val' => '1']),
                    ]),
                ]),
            ]),
        ]),
        new Element(name: 'w:r', children: [
            new Element(name: 'w:instrText', children: [new XmlText(value: 'FORMCHECKBOX')]),
        ]),
        new Element(name: 'w:r', children: [
            new Element(name: 'w:fldChar', attributes: ['w:fldCharType' => 'end']),
        ]),
    ]);

    $body = readBody(new Element(name: 'w:body', children: [$paragraph]));

    $paragraphNode = $body[0];
    expect($paragraphNode)->toBeInstanceOf(Paragraph::class);
    assert($paragraphNode instanceof Paragraph);
    $checkboxes = collectCheckboxes($paragraphNode->children);
    expect($checkboxes)->toHaveCount(1);
    expect($checkboxes[0]->checked)->toBeTrue();
});
