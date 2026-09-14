<?php
use PHPUnit\Framework\TestCase;

/**
 * Phase 2 tests for FileContentScanner (issue #3043).
 *
 * extractFileReferences extracts deduped files/... paths from HTML src/href
 * attributes, ignoring external URLs and non-files paths. normalizeFileReference
 * strips query strings, fragments, basePath prefixes, and rejects traversal.
 */
class FileContentScannerTest extends TestCase
{
    public function testExtractsFilesPathsFromSrcAndHref(): void
    {
        $html = '<img src="files/banner.jpg"><a href="files/doc.pdf">doc</a>';
        $paths = FileContentScanner::extractFileReferences($html);
        sort($paths);
        $this->assertSame(array('files/banner.jpg', 'files/doc.pdf'), $paths);
    }

    public function testDedupesRepeatedPaths(): void
    {
        $html = '<img src="files/banner.jpg"><img src="files/banner.jpg">';
        $paths = FileContentScanner::extractFileReferences($html);
        $this->assertSame(array('files/banner.jpg'), $paths);
    }

    public function testIgnoresExternalUrls(): void
    {
        $html = '<img src="https://example.com/banner.jpg"><img src="//cdn.example.com/x.png"><img src="data:image/png;base64,abc">';
        $paths = FileContentScanner::extractFileReferences($html);
        $this->assertSame(array(), $paths);
    }

    public function testIgnoresNonFilesPaths(): void
    {
        $html = '<a href="pages/about/index.html">about</a><a href="index.html">home</a>';
        $paths = FileContentScanner::extractFileReferences($html);
        $this->assertSame(array(), $paths);
    }

    public function testStripsQueryStringAndFragment(): void
    {
        $html = '<img src="files/banner.jpg?ver=123#anchor">';
        $paths = FileContentScanner::extractFileReferences($html);
        $this->assertSame(array('files/banner.jpg'), $paths);
    }

    public function testStripsLeadingDotSlash(): void
    {
        $html = '<img src="./files/photo.png">';
        $paths = FileContentScanner::extractFileReferences($html);
        $this->assertSame(array('files/photo.png'), $paths);
    }

    public function testStripsSitesDirectoryPrefix(): void
    {
        $html = '<img src="/_sites/mysite/files/photo.png">';
        $paths = FileContentScanner::extractFileReferences($html);
        $this->assertSame(array('files/photo.png'), $paths);
    }

    public function testRejectsTraversalInNormalize(): void
    {
        $this->assertSame('', FileContentScanner::normalizeFileReference('files/../secret.txt'));
        $this->assertSame('', FileContentScanner::normalizeFileReference("files/\0evil.txt"));
    }

    public function testEmptyHtmlReturnsEmptyArray(): void
    {
        $this->assertSame(array(), FileContentScanner::extractFileReferences(''));
        $this->assertSame(array(), FileContentScanner::extractFileReferences('<p>no files here</p>'));
    }

    public function testSingleQuotedAttributes(): void
    {
        $html = "<img src='files/quote.jpg'>";
        $paths = FileContentScanner::extractFileReferences($html);
        $this->assertSame(array('files/quote.jpg'), $paths);
    }

    // ------------------------------------------------------------------
    // HAX media elements use source= (media-image, video-player) and
    // poster= (video-player poster frame), not src/href. The scanner must
    // match these or media file refs never reach page.metadata.files.
    // ------------------------------------------------------------------

    public function testExtractsFilePathsFromSourceAttribute(): void
    {
        // media-image and video-player store the file ref in source="files/..."
        $html = '<media-image source="files/hero.jpg"></media-image><video-player source="files/clip.mp4"></video-player>';
        $paths = FileContentScanner::extractFileReferences($html);
        sort($paths);
        $this->assertSame(array('files/clip.mp4', 'files/hero.jpg'), $paths);
    }

    public function testExtractsFilePathsFromPosterAttribute(): void
    {
        // video-player poster frame
        $html = '<video-player source="files/clip.mp4" poster="files/poster.jpg"></video-player>';
        $paths = FileContentScanner::extractFileReferences($html);
        sort($paths);
        $this->assertSame(array('files/clip.mp4', 'files/poster.jpg'), $paths);
    }

    public function testSourceAndSrcDedupeWhenSameFile(): void
    {
        // An img src and a media-image source pointing at the same file should
        // dedupe to one uuid reference.
        $html = '<img src="files/banner.jpg"><media-image source="files/banner.jpg"></media-image>';
        $paths = FileContentScanner::extractFileReferences($html);
        $this->assertSame(array('files/banner.jpg'), $paths);
    }
}
