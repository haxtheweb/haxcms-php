<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/docx/content-types-reader.js

namespace EndlessCreativity\ElephantPhp\Reader;

final readonly class ContentTypes
{
    private const FALLBACK_IMAGE_TYPES = [
        'png' => 'png',
        'gif' => 'gif',
        'jpeg' => 'jpeg',
        'jpg' => 'jpeg',
        'tif' => 'tiff',
        'tiff' => 'tiff',
        'bmp' => 'bmp',
    ];

    /**
     * @param  array<string, string>  $overrides  part path (no leading slash) => content type
     * @param  array<string, string>  $extensionDefaults  extension => content type
     */
    public function __construct(
        private array $overrides = [],
        private array $extensionDefaults = [],
    ) {
    }

    public static function default(): self
    {
        return new self();
    }

    public function findContentType(string $path): ?string
    {
        if (isset($this->overrides[$path])) {
            return $this->overrides[$path];
        }

        $extension = self::extensionOf($path);
        if (isset($this->extensionDefaults[$extension])) {
            return $this->extensionDefaults[$extension];
        }

        $fallback = self::FALLBACK_IMAGE_TYPES[mb_strtolower($extension)] ?? null;

        return $fallback !== null ? 'image/'.$fallback : null;
    }

    private static function extensionOf(string $path): string
    {
        $position = mb_strrpos($path, '.');

        return $position === false ? '' : mb_substr($path, $position + 1);
    }
}
