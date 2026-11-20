<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/docx/style-map.js

namespace EndlessCreativity\ElephantPhp\Reader;

use DOMDocument;
use RuntimeException;
use ZipArchive;

/**
 * Reads and writes mammoth's "style-map" docx part. The part lives at
 * `mammoth/style-map`, is registered with relationship Id
 * `rMammothStyleMap` and content type `text/prs.mammoth.style-map`.
 */
final class EmbeddedStyleMap
{
    public const PART_NAME = 'mammoth/style-map';

    public const PART_ABSOLUTE_PATH = '/mammoth/style-map';

    public const RELATIONSHIP_ID = 'rMammothStyleMap';

    public const RELATIONSHIP_TYPE = 'http://schemas.zwobble.org/mammoth/style-map';

    public const CONTENT_TYPE = 'text/prs.mammoth.style-map';

    public static function read(ZipFile $zip): ?string
    {
        return $zip->exists(self::PART_NAME) ? $zip->read(self::PART_NAME) : null;
    }

    /**
     * Writes the style map part into a fresh copy of $sourcePath and returns
     * the new docx bytes. The original file is untouched.
     */
    public static function embed(string $sourcePath, string $styleMap): string
    {
        if (! is_file($sourcePath)) {
            throw new RuntimeException("Could not embed style map: source file not found at {$sourcePath}");
        }

        $tmp = tempnam(sys_get_temp_dir(), 'elephant-php-embed-');
        if ($tmp === false) {
            throw new RuntimeException('Could not allocate a temporary file for embedStyleMap');
        }

        try {
            if (! copy($sourcePath, $tmp)) {
                throw new RuntimeException("Could not copy {$sourcePath} for embedStyleMap");
            }

            $archive = new ZipArchive();
            $status = $archive->open($tmp);
            if ($status !== true) {
                throw new RuntimeException("Could not open zip at {$tmp} (ZipArchive error code {$status})");
            }

            $archive->addFromString(self::PART_NAME, $styleMap);

            $rels = $archive->getFromName('word/_rels/document.xml.rels');
            if ($rels === false) {
                throw new RuntimeException('docx is missing word/_rels/document.xml.rels');
            }
            $archive->addFromString('word/_rels/document.xml.rels', self::injectRelationship($rels));

            $types = $archive->getFromName('[Content_Types].xml');
            if ($types === false) {
                throw new RuntimeException('docx is missing [Content_Types].xml');
            }
            $archive->addFromString('[Content_Types].xml', self::injectContentTypeOverride($types));

            $archive->close();

            $bytes = file_get_contents($tmp);
            if ($bytes === false) {
                throw new RuntimeException("Could not read modified docx at {$tmp}");
            }

            return $bytes;
        } finally {
            @unlink($tmp);
        }
    }

    private static function injectRelationship(string $xml): string
    {
        $doc = new DOMDocument();
        $doc->loadXML($xml);
        $root = $doc->documentElement;
        if ($root === null) {
            throw new RuntimeException('Malformed word/_rels/document.xml.rels');
        }

        $ns = 'http://schemas.openxmlformats.org/package/2006/relationships';
        foreach ($root->getElementsByTagNameNS($ns, 'Relationship') as $existing) {
            if ($existing->getAttribute('Id') === self::RELATIONSHIP_ID) {
                $existing->setAttribute('Type', self::RELATIONSHIP_TYPE);
                $existing->setAttribute('Target', self::PART_ABSOLUTE_PATH);

                return self::serialize($doc);
            }
        }

        $newRel = $doc->createElementNS($ns, 'Relationship');
        $newRel->setAttribute('Id', self::RELATIONSHIP_ID);
        $newRel->setAttribute('Type', self::RELATIONSHIP_TYPE);
        $newRel->setAttribute('Target', self::PART_ABSOLUTE_PATH);
        $root->appendChild($newRel);

        return self::serialize($doc);
    }

    private static function injectContentTypeOverride(string $xml): string
    {
        $doc = new DOMDocument();
        $doc->loadXML($xml);
        $root = $doc->documentElement;
        if ($root === null) {
            throw new RuntimeException('Malformed [Content_Types].xml');
        }

        $ns = 'http://schemas.openxmlformats.org/package/2006/content-types';
        foreach ($root->getElementsByTagNameNS($ns, 'Override') as $existing) {
            if ($existing->getAttribute('PartName') === self::PART_ABSOLUTE_PATH) {
                $existing->setAttribute('ContentType', self::CONTENT_TYPE);

                return self::serialize($doc);
            }
        }

        $newOverride = $doc->createElementNS($ns, 'Override');
        $newOverride->setAttribute('PartName', self::PART_ABSOLUTE_PATH);
        $newOverride->setAttribute('ContentType', self::CONTENT_TYPE);
        $root->appendChild($newOverride);

        return self::serialize($doc);
    }

    private static function serialize(DOMDocument $doc): string
    {
        $xml = $doc->saveXML();
        if ($xml === false) {
            throw new RuntimeException('Could not serialise modified XML');
        }

        return $xml;
    }
}
