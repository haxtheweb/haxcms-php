<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Document\Image;
use EndlessCreativity\ElephantPhp\Reader\BodyReader;
use EndlessCreativity\ElephantPhp\Reader\ContentTypes;
use EndlessCreativity\ElephantPhp\Reader\Relationship;
use EndlessCreativity\ElephantPhp\Reader\Relationships;
use EndlessCreativity\ElephantPhp\Reader\Xml\Element;

const IMAGE_REL_TYPE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image';

/**
 * @param  array<string, string>  $docPrAttributes
 */
function drawingXml(string $embedId, array $docPrAttributes = []): Element
{
    $blip = new Element(name: 'a:blip', attributes: ['r:embed' => $embedId]);
    $picPic = new Element(name: 'pic:pic', children: [
        new Element(name: 'pic:blipFill', children: [$blip]),
    ]);
    $graphic = new Element(name: 'a:graphic', children: [
        new Element(name: 'a:graphicData', children: [$picPic]),
    ]);

    return new Element(name: 'w:drawing', children: [
        new Element(name: 'wp:inline', children: [
            new Element(name: 'wp:docPr', attributes: $docPrAttributes),
            $graphic,
        ]),
    ]);
}

function bodyReaderWithImage(string $entryName, string $bytes): BodyReader
{
    $relationships = new Relationships([
        new Relationship(relationshipId: 'rId5', target: 'media/image1.png', type: IMAGE_REL_TYPE),
    ]);

    return new BodyReader(
        relationships: $relationships,
        contentTypes: new ContentTypes(),
        imageReader: static function (string $path) use ($entryName, $bytes): string {
            if ($path !== $entryName) {
                throw new RuntimeException("Unexpected image path: {$path}");
            }

            return $bytes;
        },
    );
}

it('reads a w:drawing into an Image with resolved content type and bytes', function (): void {
    $reader = bodyReaderWithImage('word/media/image1.png', 'PNGBYTES');

    $result = $reader->readXmlElement(drawingXml(embedId: 'rId5'));

    expect($result->messages)->toBe([]);
    expect($result->value)->toBeInstanceOf(Image::class);
    /** @var Image $image */
    $image = $result->value;
    expect($image->contentType)->toBe('image/png');
    expect(($image->readBytes)())->toBe('PNGBYTES');
    expect($image->altText)->toBeNull();
});

it('uses wp:docPr/@descr as alt text when present', function (): void {
    $reader = bodyReaderWithImage('word/media/image1.png', 'x');

    $result = $reader->readXmlElement(drawingXml(
        embedId: 'rId5',
        docPrAttributes: ['descr' => 'A small logo'],
    ));

    $image = $result->value;
    expect($image)->toBeInstanceOf(Image::class);
    expect($image instanceof Image ? $image->altText : null)
        ->toBe('A small logo');
});

it('falls back to wp:docPr/@title when descr is blank', function (): void {
    $reader = bodyReaderWithImage('word/media/image1.png', 'x');

    $result = $reader->readXmlElement(drawingXml(
        embedId: 'rId5',
        docPrAttributes: ['descr' => '   ', 'title' => 'Logo'],
    ));

    $image = $result->value;
    expect($image)->toBeInstanceOf(Image::class);
    expect($image instanceof Image ? $image->altText : null)->toBe('Logo');
});

it('warns and drops the image when the relationship is unknown', function (): void {
    $reader = bodyReaderWithImage('word/media/image1.png', 'x');

    $result = $reader->readXmlElement(drawingXml(embedId: 'unknown'));

    expect($result->value)->toBeNull();
    expect($result->messages)->toHaveCount(1);
    expect($result->messages[0]->message)
        ->toBe('Could not find image file for a:blip element');
});

it('wraps the image in a Hyperlink when wp:docPr/a:hlinkClick has r:id', function (): void {
    $relationships = new Relationships([
        new Relationship(relationshipId: 'rId5', target: 'media/image1.png', type: IMAGE_REL_TYPE),
        new Relationship(
            relationshipId: 'rId7',
            target: 'http://example.com/',
            type: 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink',
        ),
    ]);
    $reader = new BodyReader(
        relationships: $relationships,
        contentTypes: new ContentTypes(),
        imageReader: static fn (): string => 'PNG',
    );

    $blip = new Element(name: 'a:blip', attributes: ['r:embed' => 'rId5']);
    $picPic = new Element(name: 'pic:pic', children: [
        new Element(name: 'pic:blipFill', children: [$blip]),
    ]);
    $graphic = new Element(name: 'a:graphic', children: [
        new Element(name: 'a:graphicData', children: [$picPic]),
    ]);
    $hlink = new Element(name: 'a:hlinkClick', attributes: ['r:id' => 'rId7']);
    $docPr = new Element(name: 'wp:docPr', children: [$hlink]);
    $drawingXml = new Element(name: 'w:drawing', children: [
        new Element(name: 'wp:inline', children: [$docPr, $graphic]),
    ]);

    $result = $reader->readXmlElement($drawingXml);

    $hyperlink = $result->value;
    expect($hyperlink)->toBeInstanceOf(\EndlessCreativity\ElephantPhp\Document\Hyperlink::class);
    expect($hyperlink instanceof \EndlessCreativity\ElephantPhp\Document\Hyperlink ? $hyperlink->href : null)
        ->toBe('http://example.com/');
    expect($hyperlink instanceof \EndlessCreativity\ElephantPhp\Document\Hyperlink ? $hyperlink->children[0] : null)
        ->toBeInstanceOf(Image::class);
});

it('refuses to read r:link bytes for security reasons (no SSRF/LFI/phar)', function (): void {
    // r:link points outside the docx zip and the target is attacker-
    // controlled in any user-uploaded scenario. Mammoth's `fs.readFile`
    // approach exposes SSRF (http://internal/...), LFI (file:///etc/...),
    // and phar:// deserialisation. We refuse to load and let the caller
    // decide what to do via the path on the Image node.
    $tmp = (string) tempnam(sys_get_temp_dir(), 'elephant-img-');
    file_put_contents($tmp, 'SHOULD-NOT-BE-READ');

    $relationships = new Relationships([
        new Relationship(relationshipId: 'rId9', target: $tmp, type: IMAGE_REL_TYPE),
    ]);
    $reader = new BodyReader(
        relationships: $relationships,
        contentTypes: new ContentTypes(),
    );

    $blip = new Element(name: 'a:blip', attributes: ['r:link' => 'rId9']);
    $picPic = new Element(name: 'pic:pic', children: [
        new Element(name: 'pic:blipFill', children: [$blip]),
    ]);
    $graphic = new Element(name: 'a:graphic', children: [
        new Element(name: 'a:graphicData', children: [$picPic]),
    ]);
    $drawingXml = new Element(name: 'w:drawing', children: [
        new Element(name: 'wp:inline', children: [$graphic]),
    ]);

    $result = $reader->readXmlElement($drawingXml);

    $image = $result->value;
    expect($image)->toBeInstanceOf(Image::class);
    // The path is preserved on the Image so a transformDocument hook can
    // make its own decision; the readBytes closure refuses outright.
    assert($image instanceof Image);
    expect(fn () => ($image->readBytes)())
        ->toThrow(RuntimeException::class, 'Linked images (r:link) are not loaded');

    @unlink($tmp);
});

it('warns when the image content type is not browser-friendly', function (): void {
    $relationships = new Relationships([
        new Relationship(relationshipId: 'rId5', target: 'media/image1.bmp', type: IMAGE_REL_TYPE),
    ]);
    $reader = new BodyReader(
        relationships: $relationships,
        contentTypes: new ContentTypes(),
        imageReader: static fn (): string => '',
    );

    $result = $reader->readXmlElement(drawingXml(embedId: 'rId5'));

    expect($result->value)->toBeInstanceOf(Image::class);
    expect($result->messages)->toHaveCount(1);
    expect($result->messages[0]->message)
        ->toBe('Image of type image/bmp is unlikely to display in web browsers');
});
