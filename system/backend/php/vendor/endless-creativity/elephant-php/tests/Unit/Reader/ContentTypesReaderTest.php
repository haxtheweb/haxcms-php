<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Reader\ContentTypes;
use EndlessCreativity\ElephantPhp\Reader\ContentTypesReader;
use EndlessCreativity\ElephantPhp\Reader\Xml\Element;

/**
 * @param  list<Element>  $children
 */
function contentTypes(array $children = []): ContentTypes
{
    return ContentTypesReader::readFromXml(
        new Element(name: 'content-types:Types', children: $children),
    );
}

it('reads default-per-extension from XML', function (): void {
    $contentTypes = contentTypes([
        new Element(
            name: 'content-types:Default',
            attributes: ['Extension' => 'png', 'ContentType' => 'image/png'],
        ),
    ]);

    expect($contentTypes->findContentType('word/media/hat.png'))->toBe('image/png');
});

it('reads overrides in preference to defaults', function (): void {
    $contentTypes = contentTypes([
        new Element(
            name: 'content-types:Default',
            attributes: ['Extension' => 'png', 'ContentType' => 'image/png'],
        ),
        new Element(
            name: 'content-types:Override',
            attributes: ['PartName' => '/word/media/hat.png', 'ContentType' => 'image/hat'],
        ),
    ]);

    expect($contentTypes->findContentType('word/media/hat.png'))->toBe('image/hat');
});

it('falls back to common image content types', function (): void {
    $contentTypes = contentTypes();

    expect($contentTypes->findContentType('word/media/hat.png'))->toBe('image/png');
    expect($contentTypes->findContentType('word/media/hat.gif'))->toBe('image/gif');
    expect($contentTypes->findContentType('word/media/hat.jpg'))->toBe('image/jpeg');
    expect($contentTypes->findContentType('word/media/hat.jpeg'))->toBe('image/jpeg');
    expect($contentTypes->findContentType('word/media/hat.bmp'))->toBe('image/bmp');
    expect($contentTypes->findContentType('word/media/hat.tif'))->toBe('image/tiff');
    expect($contentTypes->findContentType('word/media/hat.tiff'))->toBe('image/tiff');
});

it('treats fallback extensions case-insensitively', function (): void {
    expect(contentTypes()->findContentType('word/media/hat.PnG'))->toBe('image/png');
});

it('returns null for unknown extensions with no defaults', function (): void {
    expect(contentTypes()->findContentType('word/media/mystery.xyz'))->toBeNull();
});
