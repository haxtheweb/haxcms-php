<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Reader\Xml\XmlReader;

const MC_NS_URI = 'http://schemas.openxmlformats.org/markup-compatibility/2006';

it('replaces mc:AlternateContent with the children of mc:Fallback', function (): void {
    $xml = "<root xmlns:mc='".MC_NS_URI."'>"
        .'<mc:AlternateContent>'
        ."<mc:Choice Requires='wp14'><newer/></mc:Choice>"
        .'<mc:Fallback><older>kept</older></mc:Fallback>'
        .'</mc:AlternateContent>'
        .'</root>';

    $element = XmlReader::readString($xml, [MC_NS_URI => 'mc']);

    expect($element->children)->toHaveCount(1);
    expect($element->first('older')?->text())->toBe('kept');
});

it('drops a mc:AlternateContent that has no mc:Fallback', function (): void {
    $xml = "<root xmlns:mc='".MC_NS_URI."'>"
        .'<mc:AlternateContent>'
        ."<mc:Choice Requires='wp14'><newer/></mc:Choice>"
        .'</mc:AlternateContent>'
        .'</root>';

    $element = XmlReader::readString($xml, [MC_NS_URI => 'mc']);

    expect($element->children)->toBe([]);
});

it('walks into nested mc:AlternateContent elements recursively', function (): void {
    $xml = "<root xmlns:mc='".MC_NS_URI."'>"
        .'<wrap>'
        .'<mc:AlternateContent>'
        .'<mc:Fallback><deep>x</deep></mc:Fallback>'
        .'</mc:AlternateContent>'
        .'</wrap>'
        .'</root>';

    $element = XmlReader::readString($xml, [MC_NS_URI => 'mc']);

    $deep = $element->first('wrap')?->first('deep');
    expect($deep?->text())->toBe('x');
});
