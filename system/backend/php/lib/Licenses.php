<?php
/**
 * HAXCMSLicenses - the license list HAXcms offers, grouped as Creative
 * Commons, Public domain and Software. The list is data: licenses.json next
 * to this file is a byte for byte copy of the canonical
 * @haxtheweb/license-element lib/licenses.json (webcomponents repo), which
 * haxcms-nodejs and the client also use. Edit that file and run
 * `yarn run language-registry-sync` there; do not edit the copy.
 */
if (!class_exists('HAXCMSLicenses')) {
    class HAXCMSLicenses
    {
        /** the code an owner chooses to keep a work to themselves */
        const ALL_RIGHTS_RESERVED = 'all-rights-reserved';

        /**
         * The decoded licenses.json, read once. licenses.json is a byte for
         * byte copy of the canonical @haxtheweb/license-element
         * lib/licenses.json in the webcomponents repo; do not edit it here.
         */
        protected static function data()
        {
            static $data = null;
            if ($data === null) {
                $raw = @file_get_contents(dirname(__FILE__) . '/licenses.json');
                $data = is_string($raw) ? json_decode($raw, true) : null;
                if (!is_array($data) || !isset($data['groups']) || !isset($data['licenses'])) {
                    $data = array('groups' => array(), 'licenses' => array());
                }
            }
            return $data;
        }

        /**
         * Group machine name => group label, in display order.
         */
        public static function groups()
        {
            $groups = array();
            foreach (self::data()['groups'] as $group) {
                $groups[$group['id']] = $group['name'];
            }
            return $groups;
        }

        /**
         * Raw entries in canonical order (name has no group prefix).
         */
        public static function entries()
        {
            $entries = array();
            foreach (self::data()['licenses'] as $license) {
                $entries[] = array(
                    'code' => $license['code'],
                    'group' => $license['group'],
                    'name' => $license['name'],
                    'link' => $license['link'],
                    'image' => $license['image'],
                );
            }
            return $entries;
        }

        /**
         * code => array(name, link, image, group). The name carries the
         * group label as a prefix, e.g. "Software: MIT License".
         * Key order is spec order.
         */
        public static function all()
        {
            $groups = self::groups();
            $list = array();
            foreach (self::entries() as $entry) {
                $list[$entry['code']] = array(
                    'name' => $groups[$entry['group']] . ': ' . $entry['name'],
                    'link' => $entry['link'],
                    'image' => $entry['image'],
                    'group' => $entry['group'],
                );
            }
            return $list;
        }

/**
         * Fill the license choices of a site settings schema from the list so
         * the options are never a second copy. Finds the field by its
         * property name ("manifest-license") wherever it sits in the schema.
         * @param mixed $fields array/object schema, changed in place
         * @return bool true when a license field was found
         */
        public static function applyOptions(&$fields, $depth = 0)
        {
            $applied = false;
            if ($depth > 8 || (!is_array($fields) && !is_object($fields))) {
                return false;
            }
            $isObject = is_object($fields);
            $property = $isObject
                ? (isset($fields->property) ? $fields->property : null)
                : (isset($fields['property']) ? $fields['property'] : null);
            if ($property === 'manifest-license') {
                if ($isObject) {
                    $fields->options = (object) self::getLicenseData('select');
                } else {
                    $fields['options'] = self::getLicenseData('select');
                }
                $applied = true;
            }
            if ($isObject) {
                foreach (get_object_vars($fields) as $key => $value) {
                    if (is_array($value) || is_object($value)) {
                        if (self::applyOptions($fields->{$key}, $depth + 1)) {
                            $applied = true;
                        }
                    }
                }
            } else {
                foreach ($fields as $key => $value) {
                    if (is_array($value) || is_object($value)) {
                        if (self::applyOptions($fields[$key], $depth + 1)) {
                            $applied = true;
                        }
                    }
                }
            }
            return $applied;
        }

        
        /**
         * 'select' => code => display name; anything else => full map.
         */
        public static function getLicenseData($type = 'select')
        {
            $list = self::all();
            if ($type == 'select') {
                $data = array();
                foreach ($list as $key => $item) {
                    $data[$key] = $item['name'];
                }
                return $data;
            }
            return $list;
        }

        /**
         * Normalize a user / importer supplied license value to a code.
         * @param mixed $rawValue string such as "by-sa", "GPL-3.0-only" or a
         *   creativecommons.org/licenses/... URL
         * @param array|null $supported codes allowed (defaults to all)
         * @return string|null license code or null when not recognized
         */
        public static function normalize($rawValue, $supported = null)
        {
            if (!is_string($rawValue)) {
                return null;
            }
            if (!is_array($supported)) {
                $supported = array_keys(self::all());
            }
            $value = self::lowercase(self::jsTrim(str_replace('_', '-', $rawValue)));
            if ($value === '') {
                return null;
            }
            // short wordings of an owner keeping the work to themselves
            if (in_array(self::ALL_RIGHTS_RESERVED, $supported, true)
                && preg_match('~^(?:all[- ]rights[- ]reserved|proprietary|copyright(?:ed)?)$~', $value)) {
                return self::ALL_RIGHTS_RESERVED;
            }
            // SPDX style suffixes: GPL-3.0-only, GPL-3.0-or-later
            $value = preg_replace('/-(only|or-later)$/', '', $value);
            if ($value === '') {
                return null;
            }
            if (in_array($value, $supported, true)) {
                return $value;
            }
            // longest code first so cc-by-nc-sa-4.0 is not claimed by "by"
            $codes = array_values($supported);
            usort($codes, function ($a, $b) {
                return strlen($b) - strlen($a);
            });
            foreach ($codes as $code) {
                if (
                    strpos($value, '/licenses/' . $code) !== false ||
                    strpos($value, 'cc ' . $code) !== false ||
                    strpos($value, 'cc-' . $code) !== false ||
                    strpos($value, 'cc:' . $code) !== false
                ) {
                    return $code;
                }
            }
            return null;
        }

        // --- identifying a license from text ------------------------------
        // Mirrors identifyLicense, getLicenseTerms and ccCodeFromTerms in
        // haxcms-nodejs src/lib/licenses.js. The patterns run on bytes, with
        // the non-ASCII white space JavaScript's \s knows about folded to a
        // plain space first, so \s, \b and the i flag mean exactly what they
        // mean in JavaScript (ASCII word characters, ASCII case folding).

        /** Characters JavaScript's String.prototype.trim and \s treat as white space. */
        const JS_SPACE_CLASS = '\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

        /** Valid UTF-8 text; bad bytes become U+FFFD like fetch().text(). */
        public static function utf8($text)
        {
            $text = (string) $text;
            if (mb_check_encoding($text, 'UTF-8')) {
                return $text;
            }
            $previous = mb_substitute_character();
            mb_substitute_character(0xFFFD);
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            mb_substitute_character($previous);
            return $text;
        }

        /** String.prototype.length: UTF-16 code units. */
        public static function utf16Length($text)
        {
            $text = (string) $text;
            if ($text === '') {
                return 0;
            }
            return intdiv(strlen(mb_convert_encoding(self::utf8($text), 'UTF-16LE', 'UTF-8')), 2);
        }

        /**
         * String.prototype.slice(0, $units): the first UTF-16 code units. A cut
         * that would split a surrogate pair drops the lone half (PHP strings
         * cannot hold it).
         */
        public static function sliceUtf16($text, $units)
        {
            $text = self::utf8($text);
            if (strlen($text) <= $units) {
                return $text;
            }
            $encoded = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
            if (strlen($encoded) <= $units * 2) {
                return $text;
            }
            $encoded = substr($encoded, 0, $units * 2);
            if ($units > 0) {
                $last = ord($encoded[$units * 2 - 1]);
                if ($last >= 0xD8 && $last <= 0xDB) {
                    $encoded = substr($encoded, 0, $units * 2 - 2);
                }
            }
            return mb_convert_encoding($encoded, 'UTF-8', 'UTF-16LE');
        }

        /** String.prototype.trim. */
        public static function jsTrim($text)
        {
            $text = (string) $text;
            $trimmed = preg_replace('~^[' . self::JS_SPACE_CLASS . ']+|[' . self::JS_SPACE_CLASS . ']+$~uD', '', $text);
            return $trimmed === null ? trim($text) : $trimmed;
        }

        /** String.prototype.toLowerCase. */
        private static function lowercase($text)
        {
            return mb_strtolower(self::utf8($text), 'UTF-8');
        }

        /** "Attribution-NonCommercial-ShareAlike" flags to a CC code, or null. */
        private static function ccCodeFromTerms($terms)
        {
            $text = preg_replace('~[^a-z]~', '', strtolower($terms));
            if (strpos($text, 'attribution') === false) {
                return null;
            }
            $code = 'by';
            if (strpos($text, 'noncommercial') !== false) {
                $code .= '-nc';
            }
            if (strpos($text, 'noderiv') !== false) {
                $code .= '-nd';
            }
            elseif (strpos($text, 'sharealike') !== false) {
                $code .= '-sa';
            }
            return $code;
        }

        /** Spec name of a code (no group prefix), or ''. */
        private static function specName($code)
        {
            foreach (self::entries() as $entry) {
                if ($entry['code'] === $code) {
                    return $entry['name'];
                }
            }
            return '';
        }

        /**
         * What a license statement says: array('code' => ?string,
         * 'version' => string, 'label' => string). A Creative Commons license
         * at a version other than 4.0 gets no code, because the links and
         * badges carried are for 4.0. Works on a short statement ("CC BY-SA
         * 4.0", "MIT") and on the text of a LICENSE file; only well known
         * wording is recognized, anything else is reported, never guessed.
         */
        public static function identify($rawText)
        {
            $result = array('code' => null, 'version' => '', 'label' => '');
            if (!is_string($rawText)) {
                return $result;
            }
            $text = self::sliceUtf16($rawText, 65536);
            // the copy the patterns read: JavaScript's non-ASCII white space as ' '
            $subject = preg_replace('~[\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]~u', ' ', $text);
            if ($subject === null) {
                $subject = $text;
            }
            if (preg_match('~SPDX-License-Identifier:\s*([A-Za-z0-9.+-]+)~', $subject, $spdx)) {
                $code = self::normalize($spdx[1]);
                if ($code) {
                    return array('code' => $code, 'version' => '', 'label' => $spdx[1]);
                }
            }
            // a Creative Commons deed URL carries the version: .../licenses/by-sa/3.0/
            if (preg_match('~creativecommons\.org/licenses/([a-z-]+)/(\d\.\d)~i', $subject, $ccUrl)) {
                $urlCode = self::normalize('cc-' . $ccUrl[1]);
                if ($urlCode && $ccUrl[2] === '4.0') {
                    return array('code' => $urlCode, 'version' => '4.0', 'label' => 'CC ' . strtoupper($ccUrl[1]) . ' 4.0');
                }
                return array('code' => null, 'version' => $ccUrl[2], 'label' => 'CC ' . strtoupper($ccUrl[1]) . ' ' . $ccUrl[2]);
            }
            // Creative Commons, spelled out or abbreviated
            $hasLong = preg_match(
                '~(?:Creative\s+Commons\s+)?(Attribution(?:[\s-]+(?:Non-?\s?Commercial|No\s?Derivs|No\s?Derivatives|Share\s?Alike))*)\s+(\d\.\d)\b~i',
                $subject,
                $longCc
            );
            $hasShort = preg_match('~\bCC[\s-]+(BY(?:[\s-]+(?:NC|ND|SA))*)(?:[\s-]+(\d\.\d))?~i', $subject, $shortCc);
            $ccCode = null;
            $ccVersion = '';
            $ccLabel = '';
            if ($hasLong) {
                $ccCode = self::ccCodeFromTerms($longCc[1]);
                $ccVersion = $longCc[2];
                $ccLabel = 'Creative Commons ' . preg_replace('~\s+~', ' ', $longCc[1]) . ' ' . $ccVersion;
            }
            elseif ($hasShort) {
                $terms = $shortCc[1];
                $terms = preg_replace('~NC~i', 'NonCommercial', $terms, 1);
                $terms = preg_replace('~ND~i', 'NoDerivs', $terms, 1);
                $terms = preg_replace('~SA~i', 'ShareAlike', $terms, 1);
                $terms = preg_replace('~BY~i', 'Attribution', $terms, 1);
                $ccCode = self::ccCodeFromTerms($terms);
                $ccVersion = isset($shortCc[2]) ? $shortCc[2] : '';
                $ccLabel = 'CC ' . preg_replace('~\s+~', '-', strtoupper($shortCc[1])) . ($ccVersion !== '' ? ' ' . $ccVersion : '');
            }
            if ($ccCode) {
                // no stated version means the current one; any other version is not ours
                if ($ccVersion !== '' && $ccVersion !== '4.0') {
                    return array('code' => null, 'version' => $ccVersion, 'label' => $ccLabel);
                }
                return array('code' => $ccCode, 'version' => $ccVersion !== '' ? $ccVersion : '4.0', 'label' => $ccLabel);
            }
            if (preg_match('~(?<![A-Za-z0-9_])CC0(?![A-Za-z0-9_])|CC0\s*1\.0|creativecommons\.org/publicdomain/zero~i', $subject)) {
                return array('code' => 'cc0', 'version' => '1.0', 'label' => 'CC0 1.0');
            }
            if (preg_match('~creativecommons\.org/publicdomain/mark~i', $subject)) {
                return array('code' => 'pdm', 'version' => '1.0', 'label' => 'Public Domain Mark 1.0');
            }
            $known = array(
                array('~GNU\s+AFFERO\s+GENERAL\s+PUBLIC\s+LICENSE[\s,]+Version\s+3~i', 'agpl-3.0'),
                array('~GNU\s+LESSER\s+GENERAL\s+PUBLIC\s+LICENSE[\s,]+Version\s+3~i', 'lgpl-3.0'),
                array('~GNU\s+GENERAL\s+PUBLIC\s+LICENSE[\s,]+Version\s+3~i', 'gpl-3.0'),
                array('~GNU\s+GENERAL\s+PUBLIC\s+LICENSE[\s,]+Version\s+2~i', 'gpl-2.0'),
                array('~Apache\s+License[\s,]+Version\s+2\.0~i', 'apache-2.0'),
                array('~Mozilla\s+Public\s+License[\s,]+(?:Version|v\.?)\s*2\.0~i', 'mpl-2.0'),
                array('~free\s+and\s+unencumbered\s+software\s+released\s+into\s+the\s+public\s+domain~i', 'unlicense'),
            );
            foreach ($known as $entry) {
                if (preg_match($entry[0], $subject)) {
                    return array('code' => $entry[1], 'version' => '', 'label' => self::specName($entry[1]));
                }
            }
            if (preg_match('~Redistribution\s+and\s+use\s+in\s+source\s+and\s+binary\s+forms,\s+with\s+or\s+without\s+modification,\s+are\s+permitted~i', $subject)) {
                $code = preg_match('~Neither\s+the\s+name\s+of~i', $subject) ? 'bsd-3-clause' : 'bsd-2-clause';
                return array('code' => $code, 'version' => '', 'label' => self::specName($code));
            }
            if (preg_match('~Permission\s+to\s+use,\s+copy,\s+modify,\s+and/or\s+distribute\s+this\s+software\s+for\s+any\s+purpose\s+with\s+or\s+without\s+fee~i', $subject)) {
                return array('code' => 'isc', 'version' => '', 'label' => 'ISC License');
            }
            if (preg_match('~Permission\s+is\s+hereby\s+granted,\s+free\s+of\s+charge,\s+to\s+any\s+person\s+obtaining\s+a\s+copy~i', $subject)) {
                return array('code' => 'mit', 'version' => '', 'label' => 'MIT License');
            }
            // an owner keeping the work to themselves. Checked after every known
            // license, so a file that grants rights is never read as reserving them.
            if (preg_match('~\ball\s+rights\s+(?:are\s+)?reserved\b~i', $subject)) {
                return array('code' => self::ALL_RIGHTS_RESERVED, 'version' => '', 'label' => self::specName(self::ALL_RIGHTS_RESERVED));
            }
            // a bare code or URL, such as the value of a `license:` setting
            $trimmed = self::jsTrim($text);
            if (self::utf16Length($trimmed) <= 120) {
                $code = self::normalize($trimmed);
                if ($code) {
                    return array('code' => $code, 'version' => '', 'label' => $trimmed);
                }
                if ($trimmed !== '') {
                    $result['label'] = $trimmed;
                }
            }
            return $result;
        }

        /**
         * True for the license an owner uses to say the work may not be copied,
         * adapted or reused without their permission. Importers refuse such sources.
         */
        public static function isAllRightsReserved($code)
        {
            return $code === self::ALL_RIGHTS_RESERVED;
        }

        /** Terms of a license code the person importing should know about. */
        public static function terms($code)
        {
            $terms = array();
            if (!is_string($code)) {
                return $terms;
            }
            if (self::isAllRightsReserved($code)) {
                return array('all-rights-reserved');
            }
            $list = self::all();
            if (isset($list[$code]) && $list[$code]['group'] === 'creative-commons') {
                if (strpos($code, 'nc') !== false) {
                    $terms[] = 'non-commercial';
                }
                if (strpos($code, 'nd') !== false) {
                    $terms[] = 'no-derivatives';
                }
                if (strpos($code, 'sa') !== false) {
                    $terms[] = 'share-alike';
                }
            }
            return $terms;
        }
    }
}
