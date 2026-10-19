<?php
// Security (SEC-SSRF): requires the PSR-7 interfaces; SsrfGuard.php loads
// composer's autoloader and requires this file via SsrfGuard::loadCappedStream.
$__cappedStreamAutoload = dirname(__FILE__) . '/../vendor/autoload.php';
if (file_exists($__cappedStreamAutoload)) {
    require_once $__cappedStreamAutoload;
}
unset($__cappedStreamAutoload);

/**
 * Security (SEC-SSRF): Guzzle sink that refuses writes past a byte cap.
 * Returning 0 from write() makes cURL abort the transfer (CURLE_WRITE_ERROR)
 * and the stream handler stop copying; `exceeded` lets
 * SsrfGuard::safeGuzzleRequest turn that into SSRF_TOO_LARGE.
 */
if (!class_exists('SsrfGuardCappedStream', false)) {
    class SsrfGuardCappedStream implements \Psr\Http\Message\StreamInterface
    {
        use \GuzzleHttp\Psr7\StreamDecoratorTrait;

        /** @var \Psr\Http\Message\StreamInterface */
        private $stream;
        private $limit;
        private $written = 0;
        public $exceeded = false;

        public function __construct(\Psr\Http\Message\StreamInterface $stream, $limit)
        {
            $this->stream = $stream;
            $this->limit = (int) $limit;
        }

        public function write($string): int
        {
            $length = strlen($string);
            if ($this->exceeded || $this->written + $length > $this->limit) {
                $this->exceeded = true;
                return 0;
            }
            $this->written += $length;
            return $this->stream->write($string);
        }
    }
}
