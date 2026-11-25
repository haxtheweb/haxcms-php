<?php
include_once dirname(__FILE__) . "/../vendor/autoload.php";
use \Gumlet\ImageResize;
use \Gumlet\ImageResizeException;

/**
 * Gumlet ImageResize with the same source-size limit as haxcms-nodejs.
 *
 * haxcms-nodejs resizes with sharp, which refuses inputs larger than
 * 16383 x 16383 pixels (its limitInputPixels default). gumlet 3.x checks
 * max_source_pixels in the constructor (default 15,000,000), so the limit
 * is set here, before construction, to match: both backends accept and
 * reject the same images. Larger inputs throw ImageResizeException.
 */
final class HAXCMSImageResize extends ImageResize
{
    public const MAX_SOURCE_PIXELS = 268402689; // 16383 * 16383, sharp's default

    public int $max_source_pixels = self::MAX_SOURCE_PIXELS;

    /**
     * Load an image from raw bytes. ImageResize::createFromString builds a
     * plain ImageResize (new self), which would use gumlet's default limit.
     *
     * @throws ImageResizeException
     */
    public static function createFromBytes(string $image_data): self
    {
        if ($image_data === '') {
            throw new ImageResizeException('image_data must not be empty');
        }
        return new self('data://application/octet-stream;base64,' . base64_encode($image_data));
    }

    /**
     * The decoded photo as GD holds it, EXIF orientation applied (gumlet
     * turns JPEGs upright when it loads them), for work gumlet's own
     * operations don't cover (simple-img variants: quarter turns, fits,
     * watermarks).
     */
    public function getSourceImage(): \GdImage
    {
        return $this->source_image;
    }
}
