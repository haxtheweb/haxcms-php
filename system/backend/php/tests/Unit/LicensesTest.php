<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for the shared license list (HAXCMSLicenses) and its consumers:
 * HAXCMSSite::getLicenseData and JSONOutlineSchema::getLicenseDetails.
 * Expected values follow the canonical 19 entry grouped spec shared with the
 * Node.js backend and the web components client.
 */
class LicensesTest extends TestCase
{
    private const CODES = [
        'by', 'by-sa', 'by-nd', 'by-nc', 'by-nc-sa', 'by-nc-nd',
        'cc0', 'pdm',
        'mit', 'apache-2.0', 'bsd-2-clause', 'bsd-3-clause', 'isc',
        'mpl-2.0', 'gpl-2.0', 'gpl-3.0', 'lgpl-3.0', 'agpl-3.0', 'unlicense',
    ];

    private const LINKS = [
        'by' => 'https://creativecommons.org/licenses/by/4.0/',
        'by-sa' => 'https://creativecommons.org/licenses/by-sa/4.0/',
        'by-nd' => 'https://creativecommons.org/licenses/by-nd/4.0/',
        'by-nc' => 'https://creativecommons.org/licenses/by-nc/4.0/',
        'by-nc-sa' => 'https://creativecommons.org/licenses/by-nc-sa/4.0/',
        'by-nc-nd' => 'https://creativecommons.org/licenses/by-nc-nd/4.0/',
        'cc0' => 'https://creativecommons.org/publicdomain/zero/1.0/',
        'pdm' => 'https://creativecommons.org/publicdomain/mark/1.0/',
        'mit' => 'https://opensource.org/license/mit',
        'apache-2.0' => 'https://www.apache.org/licenses/LICENSE-2.0',
        'bsd-2-clause' => 'https://opensource.org/license/bsd-2-clause',
        'bsd-3-clause' => 'https://opensource.org/license/bsd-3-clause',
        'isc' => 'https://opensource.org/license/isc-license-txt',
        'mpl-2.0' => 'https://www.mozilla.org/MPL/2.0/',
        'gpl-2.0' => 'https://www.gnu.org/licenses/old-licenses/gpl-2.0.html',
        'gpl-3.0' => 'https://www.gnu.org/licenses/gpl-3.0.html',
        'lgpl-3.0' => 'https://www.gnu.org/licenses/lgpl-3.0.html',
        'agpl-3.0' => 'https://www.gnu.org/licenses/agpl-3.0.html',
        'unlicense' => 'https://unlicense.org/',
    ];

    public static function codeProvider(): array
    {
        $rows = [];
        foreach (self::CODES as $code) {
            $rows[$code] = [$code];
        }
        return $rows;
    }

    public function testAllHasNineteenCodesInSpecOrder(): void
    {
        $this->assertSame(self::CODES, array_keys(HAXCMSLicenses::all()));
        $this->assertSame(self::CODES, array_keys(HAXCMSLicenses::getLicenseData('select')));
    }

    public function testGroupsAndGroupingOrder(): void
    {
        $this->assertSame(
            ['creative-commons' => 'Creative Commons', 'public-domain' => 'Public domain', 'software' => 'Software'],
            HAXCMSLicenses::groups()
        );
        $groups = array_map(function ($item) { return $item['group']; }, array_values(HAXCMSLicenses::all()));
        $this->assertSame(
            array_merge(array_fill(0, 6, 'creative-commons'), array_fill(0, 2, 'public-domain'), array_fill(0, 11, 'software')),
            $groups
        );
    }

    #[DataProvider('codeProvider')]
    public function testEntryShape(string $code): void
    {
        $item = HAXCMSLicenses::all()[$code];
        $this->assertSame(['name', 'link', 'image', 'group'], array_keys($item));
        $this->assertSame(self::LINKS[$code], $item['link']);
        $groups = HAXCMSLicenses::groups();
        $this->assertStringStartsWith($groups[$item['group']] . ': ', $item['name']);
        if ($item['group'] === 'software') {
            $this->assertSame('', $item['image']);
        } else {
            $this->assertStringStartsWith('https://', $item['image']);
            $this->assertStringEndsWith('/88x31.png', $item['image']);
        }
    }

    public function testDisplayNames(): void
    {
        $select = HAXCMSLicenses::getLicenseData('select');
        $this->assertSame('Creative Commons: Attribution', $select['by']);
        $this->assertSame('Creative Commons: Attribution Non-commercial No derivatives', $select['by-nc-nd']);
        $this->assertSame('Public domain: CC0 1.0 Universal', $select['cc0']);
        $this->assertSame('Public domain: Public Domain Mark 1.0', $select['pdm']);
        $this->assertSame('Software: MIT License', $select['mit']);
        $this->assertSame('Software: The Unlicense', $select['unlicense']);
    }

    public function testImagesForPublicDomain(): void
    {
        $all = HAXCMSLicenses::all();
        $this->assertSame('https://licensebuttons.net/p/zero/1.0/88x31.png', $all['cc0']['image']);
        $this->assertSame('https://licensebuttons.net/p/mark/1.0/88x31.png', $all['pdm']['image']);
        $this->assertSame('https://i.creativecommons.org/l/by-sa/4.0/88x31.png', $all['by-sa']['image']);
    }

    public function testSelectVersusFull(): void
    {
        $site = new HAXCMSSite();
        $select = $site->getLicenseData('select');
        $this->assertSame('Software: MIT License', $select['mit']);
        $this->assertIsString($select['mit']);
        $full = $site->getLicenseData('all');
        $this->assertCount(19, $full);
        $this->assertSame('software', $full['mit']['group']);
        $this->assertSame(HAXCMSLicenses::all(), $full);
        $this->assertSame($full, $site->getLicenseData('anything-else'));
    }

    public function testGetLicenseDetailsForNewCode(): void
    {
        $jos = new JSONOutlineSchema();
        $jos->license = 'gpl-3.0';
        $details = $jos->getLicenseDetails();
        $this->assertSame('Software: GNU General Public License v3.0', $details['name']);
        $this->assertSame('https://www.gnu.org/licenses/gpl-3.0.html', $details['link']);
        $this->assertSame('', $details['image']);
        $this->assertSame('software', $details['group']);
        $this->assertCount(19, $jos->getLicenseDetails(true));
    }

    public static function normalizeProvider(): array
    {
        return [
            'exact' => ['by-sa', 'by-sa'],
            'exact uppercase and underscores' => ['  BSD_3_Clause ', 'bsd-3-clause'],
            'exact trimmed lowercased' => ['  MIT  ', 'mit'],
            'exact new code' => ['cc0', 'cc0'],
            'spdx only' => ['GPL-3.0-only', 'gpl-3.0'],
            'spdx or later' => ['AGPL-3.0-or-later', 'agpl-3.0'],
            'spdx mit' => ['MIT', 'mit'],
            'spdx apache' => ['Apache-2.0', 'apache-2.0'],
            'cc url' => ['https://creativecommons.org/licenses/by-nc-sa/4.0/', 'by-nc-sa'],
            'cc url by' => ['https://creativecommons.org/licenses/by/4.0/', 'by'],
            'longest match cc-dash' => ['cc-by-nc-sa-4.0', 'by-nc-sa'],
            'longest match cc space' => ['CC by-nd 4.0', 'by-nd'],
            'longest match cc colon' => ['cc:by-nc-nd', 'by-nc-nd'],
            'unknown' => ['not-a-license', null],
            'empty' => ['', null],
            'non string' => [null, null],
        ];
    }

    #[DataProvider('normalizeProvider')]
    public function testNormalize($raw, $expected): void
    {
        $this->assertSame($expected, HAXCMSLicenses::normalize($raw));
    }

    public function testNormalizeRespectsSuppliedSupportedList(): void
    {
        $this->assertNull(HAXCMSLicenses::normalize('mit', ['by', 'by-sa']));
        $this->assertSame('by', HAXCMSLicenses::normalize('cc-by-nc-sa-4.0', ['by']));
    }

    public function testListComesFromSharedJson(): void
    {
        $path = dirname(__DIR__, 2) . '/lib/licenses.json';
        $data = json_decode(file_get_contents($path), true);
        $this->assertSame(1, $data['version']);
        $this->assertSame(
            array_column($data['licenses'], 'code'),
            array_column(HAXCMSLicenses::entries(), 'code')
        );
        $this->assertSame(
            array_column($data['groups'], 'name', 'id'),
            HAXCMSLicenses::groups()
        );
    }

    public function testApplyOptionsFillsSiteSettingsField(): void
    {
        $fields = json_decode(
            '[{"fields":[{"property":"manifest-license","options":{}}]}]'
        );
        $this->assertTrue(HAXCMSLicenses::applyOptions($fields));
        $options = (array) $fields[0]->fields[0]->options;
        $this->assertCount(count(self::CODES), $options);
        $other = json_decode('[{"property":"x"}]');
        $this->assertFalse(HAXCMSLicenses::applyOptions($other));
    }

    public function testStandaloneBoilerplateStaysInSync(): void
    {
        $file = dirname(__DIR__, 4) . '/boilerplate/site/config.php';
        $text = file_get_contents($file);
        $this->assertStringContainsString('BEGIN GENERATED: licenses', $text);
        foreach (self::CODES as $code) {
            $this->assertStringContainsString("'" . $code . "'", $text, $code);
        }
    }
}
