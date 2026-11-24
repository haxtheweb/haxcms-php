<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Reader\EmbeddedStyleMap;
use EndlessCreativity\ElephantPhp\Reader\ZipFile;

it('returns null when the style map part is absent', function (): void {
    $zip = ZipFile::openPath(fixture('single-paragraph.docx'));

    expect(EmbeddedStyleMap::read($zip))->toBeNull();
});

it('writes the style map part and registers it with rels + content types', function (): void {
    $rules = "p[style-name='Aside'] => p.aside";

    $bytes = EmbeddedStyleMap::embed(fixture('single-paragraph.docx'), $rules);

    $tmp = (string) tempnam(sys_get_temp_dir(), 'elephant-embedtest-');
    file_put_contents($tmp, $bytes);

    try {
        $zip = ZipFile::openPath($tmp);

        // The style map part itself
        expect($zip->exists('mammoth/style-map'))->toBeTrue();
        expect($zip->read('mammoth/style-map'))->toBe($rules);

        // Relationship referenced via the canonical Id
        $rels = $zip->read('word/_rels/document.xml.rels');
        expect($rels)->toContain(EmbeddedStyleMap::RELATIONSHIP_ID);
        expect($rels)->toContain(EmbeddedStyleMap::RELATIONSHIP_TYPE);
        expect($rels)->toContain(EmbeddedStyleMap::PART_ABSOLUTE_PATH);

        // Content type override registered
        $types = $zip->read('[Content_Types].xml');
        expect($types)->toContain(EmbeddedStyleMap::CONTENT_TYPE);
        expect($types)->toContain(EmbeddedStyleMap::PART_ABSOLUTE_PATH);
    } finally {
        @unlink($tmp);
    }
});

it('round-trips an embedded style map through read after embed', function (): void {
    $rules = "r[style-name='Code'] => code";

    $bytes = EmbeddedStyleMap::embed(fixture('single-paragraph.docx'), $rules);

    $tmp = (string) tempnam(sys_get_temp_dir(), 'elephant-embedtest-');
    file_put_contents($tmp, $bytes);

    try {
        expect(EmbeddedStyleMap::read(ZipFile::openPath($tmp)))->toBe($rules);
    } finally {
        @unlink($tmp);
    }
});

it('replaces an existing embedded style map rather than duplicating it', function (): void {
    $first = "p[style-name='X'] => p";
    $second = "p[style-name='Y'] => p";

    $bytes = EmbeddedStyleMap::embed(fixture('single-paragraph.docx'), $first);
    $tmp = (string) tempnam(sys_get_temp_dir(), 'elephant-embedtest-');
    file_put_contents($tmp, $bytes);

    try {
        $bytes = EmbeddedStyleMap::embed($tmp, $second);
        file_put_contents($tmp, $bytes);
        $zip = ZipFile::openPath($tmp);

        expect($zip->read('mammoth/style-map'))->toBe($second);

        // The relationship should appear exactly once.
        $rels = $zip->read('word/_rels/document.xml.rels');
        expect(substr_count($rels, EmbeddedStyleMap::RELATIONSHIP_ID))->toBe(1);

        $types = $zip->read('[Content_Types].xml');
        expect(substr_count($types, EmbeddedStyleMap::PART_ABSOLUTE_PATH))->toBe(1);
    } finally {
        @unlink($tmp);
    }
});
