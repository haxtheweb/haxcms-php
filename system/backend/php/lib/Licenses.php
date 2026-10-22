<?php
/**
 * HAXCMSLicenses - single source of truth for the license list HAXcms offers.
 * Grouped as Creative Commons, Public domain and Software. Mirrors the
 * canonical list shared with the Node.js backend and the web components
 * client; keep all three in sync.
 */
if (!class_exists('HAXCMSLicenses')) {
    class HAXCMSLicenses
    {
        /**
         * Group machine name => group label, in display order.
         */
        public static function groups()
        {
            return array(
            'creative-commons' => 'Creative Commons',
            'public-domain' => 'Public domain',
            'software' => 'Software',
            );
        }

        /**
         * Raw spec entries in canonical order (name has no group prefix).
         */
        public static function entries()
        {
            return array(
            array('code' => 'by', 'group' => 'creative-commons', 'name' => 'Attribution', 'link' => 'https://creativecommons.org/licenses/by/4.0/', 'image' => 'https://i.creativecommons.org/l/by/4.0/88x31.png'),
            array('code' => 'by-sa', 'group' => 'creative-commons', 'name' => 'Attribution Share a like', 'link' => 'https://creativecommons.org/licenses/by-sa/4.0/', 'image' => 'https://i.creativecommons.org/l/by-sa/4.0/88x31.png'),
            array('code' => 'by-nd', 'group' => 'creative-commons', 'name' => 'Attribution No derivatives', 'link' => 'https://creativecommons.org/licenses/by-nd/4.0/', 'image' => 'https://i.creativecommons.org/l/by-nd/4.0/88x31.png'),
            array('code' => 'by-nc', 'group' => 'creative-commons', 'name' => 'Attribution non-commercial', 'link' => 'https://creativecommons.org/licenses/by-nc/4.0/', 'image' => 'https://i.creativecommons.org/l/by-nc/4.0/88x31.png'),
            array('code' => 'by-nc-sa', 'group' => 'creative-commons', 'name' => 'Attribution non-commercial share a like', 'link' => 'https://creativecommons.org/licenses/by-nc-sa/4.0/', 'image' => 'https://i.creativecommons.org/l/by-nc-sa/4.0/88x31.png'),
            array('code' => 'by-nc-nd', 'group' => 'creative-commons', 'name' => 'Attribution Non-commercial No derivatives', 'link' => 'https://creativecommons.org/licenses/by-nc-nd/4.0/', 'image' => 'https://i.creativecommons.org/l/by-nc-nd/4.0/88x31.png'),
            array('code' => 'cc0', 'group' => 'public-domain', 'name' => 'CC0 1.0 Universal', 'link' => 'https://creativecommons.org/publicdomain/zero/1.0/', 'image' => 'https://licensebuttons.net/p/zero/1.0/88x31.png'),
            array('code' => 'pdm', 'group' => 'public-domain', 'name' => 'Public Domain Mark 1.0', 'link' => 'https://creativecommons.org/publicdomain/mark/1.0/', 'image' => 'https://licensebuttons.net/p/mark/1.0/88x31.png'),
            array('code' => 'mit', 'group' => 'software', 'name' => 'MIT License', 'link' => 'https://opensource.org/license/mit', 'image' => ''),
            array('code' => 'apache-2.0', 'group' => 'software', 'name' => 'Apache License 2.0', 'link' => 'https://www.apache.org/licenses/LICENSE-2.0', 'image' => ''),
            array('code' => 'bsd-2-clause', 'group' => 'software', 'name' => 'BSD 2-Clause License', 'link' => 'https://opensource.org/license/bsd-2-clause', 'image' => ''),
            array('code' => 'bsd-3-clause', 'group' => 'software', 'name' => 'BSD 3-Clause License', 'link' => 'https://opensource.org/license/bsd-3-clause', 'image' => ''),
            array('code' => 'isc', 'group' => 'software', 'name' => 'ISC License', 'link' => 'https://opensource.org/license/isc-license-txt', 'image' => ''),
            array('code' => 'mpl-2.0', 'group' => 'software', 'name' => 'Mozilla Public License 2.0', 'link' => 'https://www.mozilla.org/MPL/2.0/', 'image' => ''),
            array('code' => 'gpl-2.0', 'group' => 'software', 'name' => 'GNU General Public License v2.0', 'link' => 'https://www.gnu.org/licenses/old-licenses/gpl-2.0.html', 'image' => ''),
            array('code' => 'gpl-3.0', 'group' => 'software', 'name' => 'GNU General Public License v3.0', 'link' => 'https://www.gnu.org/licenses/gpl-3.0.html', 'image' => ''),
            array('code' => 'lgpl-3.0', 'group' => 'software', 'name' => 'GNU Lesser General Public License v3.0', 'link' => 'https://www.gnu.org/licenses/lgpl-3.0.html', 'image' => ''),
            array('code' => 'agpl-3.0', 'group' => 'software', 'name' => 'GNU Affero General Public License v3.0', 'link' => 'https://www.gnu.org/licenses/agpl-3.0.html', 'image' => ''),
            array('code' => 'unlicense', 'group' => 'software', 'name' => 'The Unlicense', 'link' => 'https://unlicense.org/', 'image' => ''),
            );
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
            $value = strtolower(trim(str_replace('_', '-', $rawValue)));
            if ($value === '') {
                return null;
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
    }
}
