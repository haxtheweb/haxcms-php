<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Reader\ZipFile;

it('opens a zip from a file path and reads an entry', function (): void {
    $zip = ZipFile::openPath(fixture('hello.zip'));

    expect($zip->exists('hello'))->toBeTrue();
    expect($zip->read('hello'))->toBe("Hello world\n");
});

it('opens a zip from an in-memory buffer', function (): void {
    $buffer = (string) file_get_contents(fixture('hello.zip'));

    $zip = ZipFile::openBuffer($buffer);

    expect($zip->read('hello'))->toBe("Hello world\n");
});

it('reports missing entries as not existing', function (): void {
    $zip = ZipFile::openPath(fixture('hello.zip'));

    expect($zip->exists('does-not-exist'))->toBeFalse();
});

it('throws when reading a missing entry', function (): void {
    $zip = ZipFile::openPath(fixture('hello.zip'));

    $zip->read('does-not-exist');
})->throws(RuntimeException::class);

it('throws when opening a non-existent file', function (): void {
    ZipFile::openPath('/no/such/path.zip');
})->throws(RuntimeException::class);

it('exposes the standard parts of a docx', function (): void {
    $zip = ZipFile::openPath(fixture('single-paragraph.docx'));

    expect($zip->exists('word/document.xml'))->toBeTrue();
    expect($zip->exists('word/styles.xml'))->toBeTrue();
    expect($zip->exists('[Content_Types].xml'))->toBeTrue();
    expect($zip->exists('word/_rels/document.xml.rels'))->toBeTrue();
});
