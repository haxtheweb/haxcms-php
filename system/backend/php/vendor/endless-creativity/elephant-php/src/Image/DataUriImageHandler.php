<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/images.js (dataUri)

namespace EndlessCreativity\ElephantPhp\Image;

use EndlessCreativity\ElephantPhp\Document\Image;

final class DataUriImageHandler implements ImageHandler
{
    public function attributes(Image $image): array
    {
        $bytes = ($image->readBytes)();

        return [
            'src' => 'data:'.($image->contentType ?? 'application/octet-stream').';base64,'.base64_encode($bytes),
        ];
    }
}
