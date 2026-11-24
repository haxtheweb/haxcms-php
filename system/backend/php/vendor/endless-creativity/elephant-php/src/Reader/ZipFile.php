<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/zipfile.js + lib/unzip.js

namespace EndlessCreativity\ElephantPhp\Reader;

use RuntimeException;
use ZipArchive;

final class ZipFile
{
    private function __construct(private readonly ZipArchive $archive)
    {
    }

    public static function openPath(string $path): self
    {
        if (! is_file($path)) {
            throw new RuntimeException("Could not open zip: file not found at {$path}");
        }

        $archive = new ZipArchive();
        $status = $archive->open($path);
        if ($status !== true) {
            throw new RuntimeException("Could not open zip at {$path} (ZipArchive error code {$status})");
        }

        return new self($archive);
    }

    public static function openBuffer(string $buffer): self
    {
        $tmp = tempnam(sys_get_temp_dir(), 'elephant-php-zip-');
        if ($tmp === false) {
            throw new RuntimeException('Could not allocate a temporary file to open zip from buffer');
        }
        file_put_contents($tmp, $buffer);

        try {
            return self::openPath($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function exists(string $name): bool
    {
        return $this->archive->locateName($name) !== false;
    }

    public function read(string $name): string
    {
        $contents = $this->archive->getFromName($name);
        if ($contents === false) {
            throw new RuntimeException("Zip entry not found: {$name}");
        }

        return $contents;
    }
}
