<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for accepting .ics calendars and .vcf contact cards as uploads
 * (haxtheweb/issues#2941).
 *
 * Both formats are plain text and are gated by the same things as every other
 * allowed type: the upload extension allowlist, the bulk-import extension
 * allowlist, and the extension -> MIME table checked against the type
 * libmagic reports for the file's own bytes.
 *
 * The parity case is the one worth pinning. The node backend sniffs type from
 * the bytes and reports text/html for a text upload carrying an HTML document
 * marker, so it refuses such a file. libmagic recognises the vCalendar / vCard
 * envelope and reports text/calendar or text/vcard whatever the fields hold,
 * so without the markup guard the same upload would be accepted here and
 * refused there. These tests assert both backends refuse it.
 */
class UploadAllowlistIcsVcfTest extends TestCase
{
    private $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/haxcms_icsvcf_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir && is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') as $file) {
                @unlink($file);
            }
            @rmdir($this->tmpDir);
        }
    }

    private function writeSample(string $name, string $body): string
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, $body);
        return $path;
    }

    private static function calendar(string $summary = 'Project Kickoff Meeting'): string
    {
        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Example Corp//Example Calendar//EN',
            'BEGIN:VEVENT',
            'UID:uid-1234567890@example.com',
            'DTSTART:20261015T180000Z',
            'DTEND:20261015T190000Z',
            'SUMMARY:' . $summary,
            'LOCATION:Conference Room A',
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);
    }

    private static function card(string $note = 'Supports faculty adopting open courseware.'): string
    {
        return implode("\r\n", [
            'BEGIN:VCARD',
            'VERSION:3.0',
            'N:Doe;John;Q.,Public',
            'FN;CHARSET=UTF-8:John Doe',
            'TEL;TYPE=WORK,VOICE:(111) 555-1212',
            'EMAIL;TYPE=PREF,INTERNET:forrestgump@example.com',
            'NOTE:' . $note,
            'END:VCARD',
            '',
        ]);
    }

    private const MARKUP = '<html><body><script>alert(1)</script></body></html>';

    // ---------------------------------------------------------------- the map

    public static function newMimeEntryProvider(): array
    {
        return [
            'ics' => ['ics', ['text/calendar', 'text/x-vcalendar', 'text/plain']],
            'uppercase ICS' => ['ICS', ['text/calendar', 'text/x-vcalendar', 'text/plain']],
            'vcf' => ['vcf', ['text/vcard', 'text/x-vcard', 'text/directory', 'text/plain']],
            'uppercase VCF' => ['VCF', ['text/vcard', 'text/x-vcard', 'text/directory', 'text/plain']],
            'ical is a different extension and was not added' => ['ical', null],
            'vcard is a different extension and was not added' => ['vcard', null],
            'php still never allowed' => ['php', null],
        ];
    }

    #[DataProvider('newMimeEntryProvider')]
    public function testMimeTableEntries(string $ext, ?array $expected): void
    {
        $this->assertSame($expected, HAXCMSFile::getAllowedMimeByExtension($ext));
    }

    public function testNeitherNewTypeAcceptsHtml(): void
    {
        foreach (['ics', 'vcf'] as $ext) {
            $this->assertNotContains(
                'text/html',
                HAXCMSFile::getAllowedMimeByExtension($ext),
                '.' . $ext . ' must not accept text/html'
            );
        }
    }

    // ------------------------------------------------------- the two patterns

    private function uploadPattern(): string
    {
        $property = new ReflectionProperty('HAXCMSFile', 'allowedUploadPattern');
        return $property->getValue(new HAXCMSFile());
    }

    private function bulkImportPattern(): string
    {
        $property = new ReflectionProperty('Operations', 'safeBulkImportFilePattern');
        return $property->getValue(new Operations());
    }

    public static function extensionGateProvider(): array
    {
        return [
            'calendar' => ['schedule.ics', true],
            'uppercase calendar' => ['SCHEDULE.ICS', true],
            'contact card' => ['directory.vcf', true],
            'uppercase card' => ['DIRECTORY.VCF', true],
            'nested calendar' => ['files/calendars/term.ics', true],
            'a type that was already allowed' => ['photo.png', true],
            'ical was not added' => ['notes.ical', false],
            'vcard was not added' => ['notes.vcard', false],
            'executable still refused' => ['evil.php', false],
            'shell still refused' => ['evil.sh', false],
            'double extension still refused' => ['evil.ics.php', false],
        ];
    }

    #[DataProvider('extensionGateProvider')]
    public function testUploadExtensionGate(string $name, bool $expected): void
    {
        $this->assertSame(
            $expected,
            (bool) preg_match($this->uploadPattern(), $name),
            $name . ' upload gate'
        );
    }

    #[DataProvider('extensionGateProvider')]
    public function testBulkImportExtensionGate(string $name, bool $expected): void
    {
        $this->assertSame(
            $expected,
            (bool) preg_match($this->bulkImportPattern(), $name),
            $name . ' bulk import gate'
        );
    }

    public function testBothPatternsStayIdentical(): void
    {
        // the upload gate and the bulk-import gate are two copies of one list;
        // they drift apart silently if only one is edited
        $this->assertSame($this->uploadPattern(), $this->bulkImportPattern());
    }

    // -------------------------------------------------- content, end to end

    private function validate(string $name, string $body, ?string &$error = null): bool
    {
        $path = $this->writeSample($name, $body);
        $method = new ReflectionMethod('HAXCMSFile', 'validateUploadMimeAndContent');
        $error = null;
        $detected = null;
        $args = [$name, $path, &$error, &$detected];
        return (bool) $method->invokeArgs(new HAXCMSFile(), $args);
    }

    public function testRealCalendarIsAccepted(): void
    {
        $this->assertTrue($this->validate('schedule.ics', self::calendar()));
    }

    public function testRealContactCardIsAccepted(): void
    {
        $this->assertTrue($this->validate('directory.vcf', self::card()));
    }

    public function testCalendarCarryingMarkupIsRefused(): void
    {
        $error = null;
        $this->assertFalse(
            $this->validate('hostile.ics', self::calendar(self::MARKUP), $error),
            'a calendar whose fields carry an HTML document marker is refused, as it is on the node backend'
        );
        $this->assertStringContainsString('markup', strtolower((string) $error));
    }

    public function testContactCardCarryingMarkupIsRefused(): void
    {
        $error = null;
        $this->assertFalse(
            $this->validate('hostile.vcf', self::card(self::MARKUP), $error),
            'a card whose fields carry an HTML document marker is refused, as it is on the node backend'
        );
        $this->assertStringContainsString('markup', strtolower((string) $error));
    }

    public function testFileThatIsOnlyMarkupIsRefused(): void
    {
        // no vCard envelope at all, so libmagic reports text/html and the MIME
        // table refuses it before the markup guard is reached
        $this->assertFalse($this->validate('fake.vcf', self::MARKUP . "\n"));
    }

    public function testAngleBracketsThatAreNotAnHtmlDocumentAreStillFine(): void
    {
        // the guard looks for document markers, not for every angle bracket, so
        // ordinary punctuation in a field does not cost a legitimate card
        $this->assertTrue(
            $this->validate('fine.vcf', self::card('Reach me at <john@example.com> any time'))
        );
    }

    public function testMarkupGuardLeavesOtherTypesAlone(): void
    {
        $method = new ReflectionMethod('HAXCMSFile', 'passesMarkupSniffParity');
        $path = $this->writeSample('notes.txt', self::MARKUP);
        // .txt is not one of the two extensions this guard covers; libmagic
        // already reports text/html for it, which the MIME table refuses
        $this->assertTrue($method->invokeArgs(new HAXCMSFile(), ['txt', $path]));
    }

    public function testExecutableContentIsStillRefused(): void
    {
        $this->assertFalse($this->validate('evil.php', "<?php echo 'x';"));
    }
}
