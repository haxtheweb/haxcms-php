<?php
/**
 * Bounded zip check for uploaded documents (docx, xlsx, ods, odt, odp, pptx).
 *
 * Security (SEC-DOS, SEC-UPLOAD): an uploaded zip is measured under real byte
 * limits before any parser reads it. The sizes a zip's directory declares are
 * written by the uploader, so they only ever lower a limit: the declared total
 * must fit the budget, then every entry is streamed (never held in memory) and
 * must inflate to exactly its declared size, reading at most one byte past it.
 * After the check, a declared size is a true size, so no reader (ZipArchive,
 * the zip:// stream wrapper PhpSpreadsheet uses, PhpWord) can expand past the
 * budget (zip bomb).
 *
 * Parity: haxcms-nodejs src/lib/boundedZip.js keeps the same limits and the
 * same messages.
 */
class BoundedZipException extends \Exception
{
    /** @var int */
    public $status = 400;
    /** @var string */
    public $reason;

    public function __construct($message, $reason)
    {
        parent::__construct($message);
        $this->reason = $reason;
    }
}

class BoundedZip
{
    const MAX_ENTRIES = 10000;
    const MAX_TOTAL_BYTES = 268435456; // 256 MiB
    // Office XML compresses roughly 5-30x and media barely at all; a zip that
    // claims more than this much content per compressed byte is a bomb, not a
    // document. The floor keeps small, highly compressible documents working.
    const MAX_RATIO = 100;
    const MIN_BUDGET_BYTES = 33554432; // 32 MiB
    const READ_CHUNK_BYTES = 65536;

    /**
     * Budget for an upload of $uploadBytes bytes.
     * @param int $uploadBytes
     * @return int
     */
    public static function budget($uploadBytes)
    {
        return (int) min(
            self::MAX_TOTAL_BYTES,
            max(self::MIN_BUDGET_BYTES, ((int) $uploadBytes) * self::MAX_RATIO)
        );
    }

    /**
     * Throw unless the zip at $path fits the limits above.
     * @param string $path uploaded file
     * @param string $ext file type for messages ("docx", "ods", ...)
     * @throws BoundedZipException
     */
    public static function assertWithinLimits($path, $ext = 'zip')
    {
        $size = @filesize($path);
        if (!is_string($path) || $path === '' || $size === false || $size === 0) {
            throw self::invalid($ext);
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw self::invalid($ext);
        }
        try {
            self::checkArchive($zip, (int) $size, $ext);
        } finally {
            $zip->close();
        }
    }

    /**
     * True when the file starts with the ZIP local file header signature.
     * @param string $path
     * @return bool
     */
    public static function hasZipSignature($path)
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $head = fread($handle, 4);
        fclose($handle);
        return $head === "PK\x03\x04";
    }

    /**
     * @param \ZipArchive $zip
     * @param int $uploadBytes
     * @param string $ext
     * @throws BoundedZipException
     */
    private static function checkArchive($zip, $uploadBytes, $ext)
    {
        $count = $zip->numFiles;
        if ($count > self::MAX_ENTRIES) {
            throw self::overBudget($ext);
        }
        $budget = self::budget($uploadBytes);
        $declared = array();
        $declaredTotal = 0;
        for ($i = 0; $i < $count; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false || !isset($stat['size']) || $stat['size'] < 0) {
                throw self::invalid($ext);
            }
            $declared[$i] = (int) $stat['size'];
            $declaredTotal += $declared[$i];
            if ($declaredTotal > $budget) {
                throw self::overBudget($ext);
            }
        }
        for ($i = 0; $i < $count; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if ($name !== '' && substr($name, -1) === '/' && $declared[$i] === 0) {
                continue;
            }
            $stream = $zip->getStreamIndex($i);
            if ($stream === false) {
                throw self::invalid($ext);
            }
            $actual = 0;
            try {
                // never read more than one byte past the claim
                while (!feof($stream) && $actual <= $declared[$i]) {
                    $chunk = fread($stream, (int) min(self::READ_CHUNK_BYTES, $declared[$i] - $actual + 1));
                    if ($chunk === false) {
                        throw self::invalid($ext);
                    }
                    if ($chunk === '') {
                        break;
                    }
                    $actual += strlen($chunk);
                }
            } finally {
                fclose($stream);
            }
            if ($actual > $declared[$i]) {
                throw self::overBudget($ext);
            }
            if ($actual !== $declared[$i]) {
                throw self::invalid($ext);
            }
        }
    }

    private static function overBudget($ext)
    {
        return new BoundedZipException(
            'The .' . $ext . ' file expands to more content than can be imported.',
            'ZIP_BUDGET'
        );
    }

    private static function invalid($ext)
    {
        return new BoundedZipException(
            'Uploaded file is not a valid .' . $ext . ' file.',
            'ZIP_INVALID'
        );
    }
}
