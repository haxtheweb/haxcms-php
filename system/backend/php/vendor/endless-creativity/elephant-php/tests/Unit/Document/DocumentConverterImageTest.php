<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Document\Document;
use EndlessCreativity\ElephantPhp\Document\DocumentConverter;
use EndlessCreativity\ElephantPhp\Document\Image;
use EndlessCreativity\ElephantPhp\Document\Paragraph;
use EndlessCreativity\ElephantPhp\Image\ImageHandler;

function htmlOfImage(Image $image, ?ImageHandler $handler = null): string
{
    $converter = $handler === null ? new DocumentConverter() : new DocumentConverter(imageHandler: $handler);

    return $converter
        ->convertToHtml(new Document(children: [new Paragraph(children: [$image])]))
        ->value;
}

it('renders an image as a base64 data URI by default', function (): void {
    $image = new Image(
        readBytes: static fn (): string => 'PNGBYTES',
        contentType: 'image/png',
    );

    expect(htmlOfImage($image))->toBe(
        '<p><img src="data:image/png;base64,'.base64_encode('PNGBYTES').'" /></p>',
    );
});

it('emits the alt attribute from the model when present', function (): void {
    $image = new Image(
        readBytes: static fn (): string => 'x',
        contentType: 'image/png',
        altText: 'A logo',
    );

    expect(htmlOfImage($image))->toContain('alt="A logo"');
});

it('lets a custom handler override or replace the src', function (): void {
    $handler = new class () implements ImageHandler {
        public function attributes(Image $image): array
        {
            return ['src' => 'https://cdn.example.com/x.png'];
        }
    };

    $image = new Image(
        readBytes: static fn (): string => '',
        contentType: 'image/png',
    );

    expect(htmlOfImage($image, $handler))
        ->toBe('<p><img src="https://cdn.example.com/x.png" /></p>');
});

it('records an error message and drops the image when the handler throws', function (): void {
    $handler = new class () implements ImageHandler {
        public function attributes(Image $image): array
        {
            throw new RuntimeException('disk full');
        }
    };

    $image = new Image(readBytes: static fn (): string => '', contentType: 'image/png');
    $result = (new DocumentConverter(imageHandler: $handler))
        ->convertToHtml(new Document(children: [new Paragraph(children: [$image])]));

    expect($result->value)->toBe('');
    expect($result->messages)->toHaveCount(1);
    expect($result->messages[0]->message)->toBe('disk full');
});
