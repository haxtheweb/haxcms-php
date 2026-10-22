<?php
use PHPUnit\Framework\TestCase;

/**
 * HAXCMSLicenses::identify, normalize and terms. Mirrors haxcms-nodejs
 * test/unit/licenses-detect.test.cjs, test for test, plus the cases where
 * PHP strings differ from JavaScript's (bytes, multibyte text, white space).
 */
require_once __DIR__ . '/../../lib/Licenses.php';

class LicenseIdentifyTest extends TestCase
{
    private const MIT = 'Permission is hereby granted, free of charge, to any person obtaining a copy';

    private function code($text): ?string
    {
        $info = HAXCMSLicenses::identify($text);
        return $info['code'];
    }

    public function testNormalizeExactSpdxSuffixAndCcSpellings(): void
    {
        $this->assertSame('by-sa', HAXCMSLicenses::normalize('by-sa'));
        $this->assertSame('mit', HAXCMSLicenses::normalize('MIT'));
        $this->assertSame('gpl-3.0', HAXCMSLicenses::normalize('GPL-3.0-or-later'));
        $this->assertSame('by-nc-sa', HAXCMSLicenses::normalize('CC BY-NC-SA'));
        $this->assertSame('by-nc', HAXCMSLicenses::normalize('https://creativecommons.org/licenses/by-nc/4.0/'));
        $this->assertNull(HAXCMSLicenses::normalize('nonsense'));
        $this->assertNull(HAXCMSLicenses::normalize(''));
        $this->assertNull(HAXCMSLicenses::normalize(null));
    }

    public function testNormalizeTrimsUnicodeWhiteSpaceLikeJavascript(): void
    {
        $this->assertSame('mit', HAXCMSLicenses::normalize("\u{00A0}MIT\u{3000}"));
    }

    public function testCreativeCommonsSpelledOutAndAbbreviated(): void
    {
        $this->assertSame('by', $this->code('CC BY 4.0'));
        $this->assertSame('by-sa', $this->code('CC BY-SA'));
        $this->assertSame('by-nc-nd', $this->code('cc-by-nc-nd'));
        $this->assertSame('by-nc-nd', $this->code('Creative Commons Attribution-NonCommercial-NoDerivs 4.0 United States License'));
        $this->assertSame('by-nc-nd', $this->code('Attribution-NonCommercial-NoDerivatives 4.0 International Public License'));
        $this->assertSame('by-sa', $this->code('Creative Commons Attribution-ShareAlike 4.0 International'));
    }

    public function testAnotherCreativeCommonsVersionGetsNoCode(): void
    {
        $info = HAXCMSLicenses::identify('Creative Commons Attribution-ShareAlike 3.0 Unported');
        $this->assertNull($info['code']);
        $this->assertSame('3.0', $info['version']);
        $this->assertMatchesRegularExpression('/3\.0/', $info['label']);
        $this->assertNull($this->code('https://creativecommons.org/licenses/by/2.5/'));
        $this->assertNull($this->code('CC BY-NC 3.0'));
    }

    public function testPublicDomainToolsAndAnSpdxTag(): void
    {
        $this->assertSame('cc0', $this->code('CC0 1.0 Universal'));
        $this->assertSame('cc0', $this->code('https://creativecommons.org/publicdomain/zero/1.0/'));
        $this->assertSame('pdm', $this->code('https://creativecommons.org/publicdomain/mark/1.0/'));
        $this->assertSame('apache-2.0', $this->code("// SPDX-License-Identifier: Apache-2.0\n"));
    }

    public function testWellKnownSoftwareLicenseTexts(): void
    {
        $cases = array(
            'mit' => self::MIT,
            'isc' => 'Permission to use, copy, modify, and/or distribute this software for any purpose with or without fee is hereby granted',
            'apache-2.0' => "Apache License\n Version 2.0, January 2004",
            'gpl-2.0' => "GNU GENERAL PUBLIC LICENSE\n Version 2, June 1991",
            'gpl-3.0' => "GNU GENERAL PUBLIC LICENSE\n Version 3, 29 June 2007",
            'lgpl-3.0' => "GNU LESSER GENERAL PUBLIC LICENSE\n Version 3, 29 June 2007",
            'agpl-3.0' => "GNU AFFERO GENERAL PUBLIC LICENSE\n Version 3, 19 November 2007",
            'mpl-2.0' => 'Mozilla Public License Version 2.0',
            'unlicense' => 'This is free and unencumbered software released into the public domain.',
            'bsd-2-clause' => 'Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met: 1. a 2. b',
            'bsd-3-clause' => 'Redistribution and use in source and binary forms, with or without modification, are permitted provided that: 1. a 2. b 3. Neither the name of the copyright holder',
        );
        foreach ($cases as $expected => $text) {
            $this->assertSame($expected, $this->code($text), $expected);
        }
    }

    public function testTheLesserAndAfferoTextsAreNotReadAsPlainGpl(): void
    {
        $this->assertSame(
            'lgpl-3.0',
            $this->code("GNU LESSER GENERAL PUBLIC LICENSE Version 3\n ... GNU GENERAL PUBLIC LICENSE")
        );
    }

    public function testUnknownTextIsReportedNeverGuessed(): void
    {
        $this->assertSame(
            array('code' => null, 'version' => '', 'label' => 'All rights reserved.'),
            HAXCMSLicenses::identify('All rights reserved.')
        );
        $this->assertNull($this->code(''));
        $this->assertNull($this->code(null));
        $this->assertNull($this->code(array()));
    }

    public function testOnlyTheStartOfAVeryLargeFileIsRead(): void
    {
        $this->assertNull($this->code(str_repeat('x', 70000) . self::MIT));
        $this->assertSame('mit', $this->code(str_repeat('x', 65000) . self::MIT));
    }

    public function testTheLimitCountsUtf16UnitsLikeJavascript(): void
    {
        // 40000 two-byte characters are 80000 bytes but 40000 units: still read
        $this->assertSame('mit', $this->code(str_repeat("\u{00E9}", 40000) . self::MIT));
        // an emoji is two units: 40000 of them push the text past the limit
        $this->assertNull($this->code(str_repeat("\u{1F600}", 40000) . self::MIT));
    }

    public function testJavascriptWhiteSpaceSeparatesWordsAndCrlfIsWhiteSpace(): void
    {
        $this->assertSame('by-sa', $this->code("Creative\u{00A0}Commons\u{00A0}Attribution-ShareAlike\u{00A0}4.0"));
        $this->assertSame('by-sa', $this->code("Creative\r\nCommons\r\nAttribution-ShareAlike\r\n4.0"));
        $this->assertSame('mit', $this->code("Permission\u{00A0}is hereby granted, free of charge, to any person obtaining a copy"));
        // U+0085 is not white space to JavaScript's \s
        $this->assertNull($this->code("CC\u{0085}BY 4.0"));
    }

    public function testWordBoundariesAreAsciiLikeJavascript(): void
    {
        $this->assertSame('cc0', $this->code('CC0'));
        $this->assertSame('cc0', $this->code("\u{00E9}CC0"));
        $this->assertNull($this->code('CC0_'));
        $this->assertSame('cc0', $this->code("CC0\u{00E9}"));
    }

    public function testLabelsAndVersions(): void
    {
        $this->assertSame(array('code' => 'by-nc-sa', 'version' => '4.0', 'label' => 'CC BY-NC-SA 4.0'), HAXCMSLicenses::identify('cC bY-nC-sA 4.0'));
        $this->assertSame(array('code' => 'by-sa', 'version' => '4.0', 'label' => 'CC BY-SA'), HAXCMSLicenses::identify('CC BY-SA'));
        $this->assertSame(array('code' => null, 'version' => '3.0', 'label' => 'CC BY-NC 3.0'), HAXCMSLicenses::identify('CC BY-NC 3.0'));
        $this->assertSame(array('code' => 'mit', 'version' => '', 'label' => 'MIT License'), HAXCMSLicenses::identify(self::MIT));
        $this->assertSame(array('code' => 'cc0', 'version' => '1.0', 'label' => 'CC0 1.0'), HAXCMSLicenses::identify('CC0'));
        $this->assertSame(array('code' => 'gpl-3.0', 'version' => '', 'label' => 'GPL-3.0-or-later'), HAXCMSLicenses::identify('SPDX-License-Identifier: GPL-3.0-or-later'));
    }

    public function testABareCodeLongerThanOneHundredTwentyUnitsIsNotLabelled(): void
    {
        $this->assertSame('a', substr(HAXCMSLicenses::identify(str_repeat('a', 120))['label'], 0, 1));
        $this->assertSame('', HAXCMSLicenses::identify(str_repeat('a', 121))['label']);
        $this->assertSame('', HAXCMSLicenses::identify(str_repeat("\u{1F600}", 61))['label']);
    }

    public function testInvalidUtf8DoesNotBreakIdentification(): void
    {
        $this->assertSame('mit', $this->code("\xFF\xFE " . self::MIT));
    }

    public function testTermsTellWhatACreativeCommonsCodeAsksOfReuse(): void
    {
        $this->assertSame(array(), HAXCMSLicenses::terms('by'));
        $this->assertSame(array('share-alike'), HAXCMSLicenses::terms('by-sa'));
        $this->assertSame(array('non-commercial', 'no-derivatives'), HAXCMSLicenses::terms('by-nc-nd'));
        $this->assertSame(array('non-commercial', 'share-alike'), HAXCMSLicenses::terms('by-nc-sa'));
        $this->assertSame(array(), HAXCMSLicenses::terms('mit'));
        $this->assertSame(array(), HAXCMSLicenses::terms('cc0'));
        $this->assertSame(array(), HAXCMSLicenses::terms(null));
    }
}
