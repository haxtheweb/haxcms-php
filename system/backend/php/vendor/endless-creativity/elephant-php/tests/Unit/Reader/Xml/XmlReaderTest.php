<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Reader\Xml\Text;
use EndlessCreativity\ElephantPhp\Reader\Xml\XmlReader;

it('parses elements without namespaces', function (): void {
    $element = XmlReader::readString('<root><child name="alpha"/></root>');

    expect($element->name)->toBe('root');
    expect($element->first('child')?->attributes['name'])->toBe('alpha');
});

it('maps known namespaces to their prefix', function (): void {
    $xml = '<r:Relationships xmlns:r="http://example.com/rel"><r:Relationship Id="rId1"/></r:Relationships>';

    $element = XmlReader::readString($xml, ['http://example.com/rel' => 'relationships']);

    expect($element->name)->toBe('relationships:Relationships');
    expect($element->first('relationships:Relationship')?->attributes['Id'])->toBe('rId1');
});

it('falls back to {namespace}localName when the namespace is not in the map', function (): void {
    $xml = '<r:Foo xmlns:r="http://example.com/unknown"/>';

    $element = XmlReader::readString($xml);

    expect($element->name)->toBe('{http://example.com/unknown}Foo');
});

it('captures text content as Text nodes', function (): void {
    $element = XmlReader::readString('<t>Hello world</t>');

    expect($element->children)->toHaveCount(1);
    expect($element->children[0])->toBeInstanceOf(Text::class);
    expect($element->text())->toBe('Hello world');
});

it('throws on malformed xml', function (): void {
    XmlReader::readString('<not><closed>');
})->throws(RuntimeException::class);

it('refuses XML with a DOCTYPE declaration (XXE / billion-laughs guard)', function (): void {
    // Real OOXML never has a DOCTYPE; rejecting any document that does
    // closes the door on entity-expansion and external-entity attacks.
    $payload = '<?xml version="1.0"?>'
        ."\n".'<!DOCTYPE root [<!ENTITY x "lol">]>'
        ."\n".'<root>&x;</root>';

    expect(fn () => XmlReader::readString($payload))
        ->toThrow(RuntimeException::class, 'DOCTYPE declarations are not allowed');
});

it('refuses an external entity declaration even when it points to a local file', function (): void {
    $payload = '<?xml version="1.0"?>'
        ."\n".'<!DOCTYPE root [<!ENTITY x SYSTEM "file:///etc/passwd">]>'
        ."\n".'<root>&x;</root>';

    expect(fn () => XmlReader::readString($payload))
        ->toThrow(RuntimeException::class, 'DOCTYPE declarations are not allowed');
});
