<?php
class SanitizeContent
{
    private const FORBIDDEN_TAGS = [
        'script',
        'svg',
        'frame',
        'frameset',
        'applet',
        'meta',
        'link',
        'base',
        'style',
    ];

    private const FORBIDDEN_ATTRIBUTES = [
        'srcdoc',
        'style',
    ];

    private const LEADING_NEWLINE_ELEMENTS = ['pre', 'listing', 'textarea'];

    // libxml (PHP 8.3) parses as HTML4: it does not know these HTML5 void
    // elements and puts the content that follows inside them
    private const LIBXML_UNKNOWN_VOID_ELEMENTS = ['bgsound', 'embed', 'keygen', 'source', 'track', 'wbr'];

    // libxml fills these HTML4 boolean attributes with their own name when
    // the source gives no value (disabled -> disabled="disabled"); HTML5
    // leaves them empty, and HAX content writes them bare or empty
    private const LIBXML_BOOLEAN_ATTRIBUTES = [
        'checked', 'compact', 'declare', 'defer', 'disabled', 'ismap', 'multiple',
        'nohref', 'noresize', 'noshade', 'nowrap', 'readonly', 'selected',
    ];

    private static $policy = null;

    /**
     * The storage policy shared with haxcms-nodejs
     * (src/lib/haxcmsSanitizePolicy.json, haxtheweb/issues#3273): which tags
     * stay, which go with their content, and the attribute rules.
     */
    private static function policy()
    {
        if (self::$policy === null) {
            $raw = @file_get_contents(dirname(__FILE__) . '/haxcms-sanitize-policy.json');
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($decoded)) {
                throw new \RuntimeException('haxcms-sanitize-policy.json is missing or invalid');
            }
            $decoded['allowedTagSet'] = array_fill_keys($decoded['allowedTags'], true);
            $decoded['removeWithContentSet'] = array_fill_keys($decoded['removeWithContent'], true);
            $decoded['reservedSet'] = array_fill_keys($decoded['reservedCustomElementNames'], true);
            $decoded['clobberTagSet'] = array_fill_keys($decoded['clobberTags'], true);
            $decoded['clobberNameSet'] = array_fill_keys($decoded['clobberNames'], true);
            $decoded['forbiddenAttributeSet'] = array_fill_keys($decoded['forbiddenAttributes'], true);
            self::$policy = $decoded;
        }
        return self::$policy;
    }

    private const URL_ATTRIBUTE_NAMES = [
        'href',
        'src',
        'action',
        'formaction',
        'poster',
        'srcset',
        'xlink:href',
        // Security (SEC-SANITIZE): HAX custom elements take URLs on these
        // attribute names (simple-cta link, media-image source,
        // pdf-browser-viewer file, video-player sources/tracks, h5p source).
        'link',
        'source',
        'file',
        'sources',
        'tracks',
    ];

    private const ALLOWED_PROTOCOLS = [
        'http',
        'https',
        'mailto',
        'tel',
    ];

    // Security (SEC-SANITIZE): script-capable schemes, rejected on EVERY
    // attribute regardless of name. This is a denylist on purpose: HAX
    // attribute values are routinely scheme-shaped (icon="icons:check",
    // title="Note: hi") so the http/https/mailto/tel allowlist can only apply
    // to URL-named attributes. Parity contract with Node SCRIPT_CAPABLE_SCHEMES.
    private const SCRIPT_CAPABLE_SCHEMES = [
        'javascript',
        'vbscript',
        'livescript',
        'mocha',
    ];

    // Security (SEC-SANITIZE): data: URIs are only rejected when they carry
    // an explicit media type that is not one of these raster image types.
    private const SAFE_DATA_MEDIA_TYPES = [
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
        'image/avif',
        'image/bmp',
    ];

    // Security (SEC-SANITIZE): URL names added for SEC-SANITIZE. They were
    // unchecked on master, where HAX content relies on raster data: URIs
    // (media-image source="data:image/png;..."), so raster data:image URIs
    // keep working on them; every other value uses the URL allowlist.
    private const DATA_IMAGE_URL_ATTRIBUTE_NAMES = [
        'link',
        'source',
        'file',
        'sources',
        'tracks',
    ];

    // Security (SEC-SANITIZE): human-readable text attributes are never
    // treated as URLs (alt="JavaScript: the good parts" must survive).
    private const TEXT_ATTRIBUTE_NAMES = [
        'alt',
        'title',
        'label',
        'caption',
        'placeholder',
        'summary',
    ];

    private const TEXT_TEMPLATE_HOSTS = [
        'code-sample',
        'runkit-embed',
        'web-container',
    ];

    private const IFRAME_ALLOWED_ATTRIBUTES = [
        'src',
        'title',
        'width',
        'height',
        'loading',
        'allow',
        'allowfullscreen',
        'credentialless',
        'referrerpolicy',
        'sandbox',
    ];

    private const IFRAME_SANDBOX_ALLOWED_TOKENS = [
        'allow-downloads',
        'allow-forms',
        'allow-modals',
        'allow-pointer-lock',
        'allow-popups',
        'allow-popups-to-escape-sandbox',
        'allow-presentation',
        'allow-same-origin',
        'allow-scripts',
    ];

    // Security: no allow-same-origin by default. Together with allow-scripts
    // it lets a same-origin framed document reach the parent and remove its
    // own sandbox, and it hands cross-origin embeds their cookies/storage.
    // An author who needs it sets sandbox explicitly (that value is kept as
    // written, filtered to the allowed tokens). Mirrors haxcms-nodejs.
    private const IFRAME_DEFAULT_SANDBOX = 'allow-scripts allow-popups allow-forms';

    private const REFERRER_POLICY_ALLOWED = [
        'no-referrer',
        'origin',
        'strict-origin',
        'same-origin',
        'strict-origin-when-cross-origin',
        'origin-when-cross-origin',
        'unsafe-url',
    ];

    public static function sanitizeURLValue($value, $fallback = '')
    {
        if ($value === null) {
            return $fallback;
        }
        $stringValue = trim((string)$value);
        if ($stringValue === '') {
            return $fallback;
        }
        if ($stringValue[0] === '#') {
            return $stringValue;
        }

        $normalizedValue = preg_replace('/[\x00-\x20\x7f]+/u', '', $stringValue);
        if ($normalizedValue === null || $normalizedValue === '') {
            return $fallback;
        }
        $normalizedValue = strtolower($normalizedValue);

        if (!preg_match('/^([a-z0-9+.-]+):/i', $normalizedValue, $matches)) {
            return $stringValue;
        }
        $protocol = strtolower($matches[1]);
        if (in_array($protocol, self::ALLOWED_PROTOCOLS, true)) {
            return $stringValue;
        }
        return $fallback;
    }

    /**
     * Security (SEC-SANITIZE): single-pass decode of the entity forms that can
     * spell a scheme: numeric &#NNN / &#xHH with optional ';', plus the
     * case-sensitive named &colon; &Tab; &NewLine; &plus; &period; (';'
     * required). Invalid code points (0, > 0x10FFFF, surrogates) decode to ''.
     * Deliberately NOT html_entity_decode: parity contract with Node
     * decodeSchemeEntities. Because the DOM already decoded the attribute once,
     * this second pass also catches double-encoded `&amp;#106;avascript:`.
     */
    public static function decodeSchemeEntities($value)
    {
        $decoded = preg_replace_callback(
            '/&#[xX]([0-9a-fA-F]+);?|&#([0-9]+);?|&(colon|Tab|NewLine|plus|period);/',
            function ($match) {
                if (isset($match[3]) && $match[3] !== '') {
                    $named = array(
                        'colon' => ':',
                        'Tab' => "\t",
                        'NewLine' => "\n",
                        'plus' => '+',
                        'period' => '.',
                    );
                    return $named[$match[3]];
                }
                if (isset($match[1]) && $match[1] !== '') {
                    $digits = ltrim($match[1], '0');
                    $codePoint = (strlen($digits) > 6) ? PHP_INT_MAX : (int)hexdec($digits === '' ? '0' : $digits);
                }
                else {
                    $digits = ltrim($match[2], '0');
                    $codePoint = (strlen($digits) > 7) ? PHP_INT_MAX : (int)($digits === '' ? '0' : $digits);
                }
                if (
                    $codePoint <= 0 ||
                    $codePoint > 0x10FFFF ||
                    ($codePoint >= 0xD800 && $codePoint <= 0xDFFF)
                ) {
                    return '';
                }
                return mb_chr($codePoint, 'UTF-8');
            },
            (string)$value
        );
        return $decoded === null ? '' : $decoded;
    }

    /**
     * Security (SEC-SANITIZE): normalised value used for scheme detection,
     * mirroring the browser URL parser: decodeSchemeEntities, remove TAB /
     * LF / CR anywhere, strip LEADING [\x00-\x20], lowercase. Interior spaces
     * are kept, so "javascript: the good parts" is not "javascript:".
     * Parity contract with Node.
     */
    public static function normalizeSchemeValue($value)
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return '';
        }
        $normalized = str_replace(array("\t", "\n", "\r"), '', self::decodeSchemeEntities($value));
        $normalized = ltrim($normalized, "\x00..\x20");
        return strtolower($normalized);
    }

    /**
     * Security (SEC-SANITIZE): leading ^[a-z][a-z0-9+.-]*: scheme of
     * normalizeSchemeValue($value), or '' when there is none.
     */
    public static function getNormalizedScheme($value)
    {
        if (!preg_match('/^([a-z][a-z0-9+.-]*):/', self::normalizeSchemeValue($value), $matches)) {
            return '';
        }
        return $matches[1];
    }

    /**
     * Security (SEC-SANITIZE): true when the value starts with a
     * script-capable scheme (javascript:, vbscript:, livescript:, mocha:).
     */
    public static function hasScriptCapableScheme($value)
    {
        return in_array(self::getNormalizedScheme($value), self::SCRIPT_CAPABLE_SCHEMES, true);
    }

    /**
     * Security (SEC-SANITIZE): true for a script-capable scheme, or for a
     * data: URI with an explicit media type (^data:type/subtype) that is not
     * an allowed raster image type (so data:text/html and
     * data:image/svg+xml are dangerous, data:image/png is not).
     */
    public static function hasDangerousScheme($value)
    {
        $scheme = self::getNormalizedScheme($value);
        if (in_array($scheme, self::SCRIPT_CAPABLE_SCHEMES, true)) {
            return true;
        }
        if ($scheme === 'data') {
            if (preg_match('/^data:([a-z0-9.+-]+\/[a-z0-9.+-]+)/', self::normalizeSchemeValue($value), $matches)) {
                return !in_array($matches[1], self::SAFE_DATA_MEDIA_TYPES, true);
            }
        }
        return false;
    }

    /**
     * Security (SEC-SANITIZE): data: URI with an explicit raster image type
     * (png, jpeg, gif, webp, avif, bmp).
     */
    public static function isSafeRasterDataURI($value)
    {
        return preg_match('/^data:([a-z0-9.+-]+\/[a-z0-9.+-]+)/', self::normalizeSchemeValue($value), $matches) === 1 &&
            in_array($matches[1], self::SAFE_DATA_MEDIA_TYPES, true);
    }

    private static function isTextAttribute($attributeName)
    {
        return in_array($attributeName, self::TEXT_ATTRIBUTE_NAMES, true) ||
            str_starts_with($attributeName, 'aria-');
    }

    /**
     * Security (SEC-SANITIZE): value to keep, or null when the attribute must
     * be removed. URL-named attributes go through the sanitizeURLValue
     * allowlist (link/source/file/sources/tracks additionally accept raster
     * data:image URIs, as on master) and then the dangerous-scheme check; text attributes (alt,
     * title, label, caption, placeholder, summary, aria-*) are kept as-is;
     * every other attribute is removed when hasDangerousScheme(). Empty
     * (boolean) values are kept. Parity contract with Node.
     */
    public static function sanitizeAttributeValue($attributeName, $value)
    {
        $attributeName = strtolower((string)$attributeName);
        $safeValue = ($value === null) ? '' : (string)$value;
        if (self::isURLLikeAttribute($attributeName)) {
            if (!(
                in_array($attributeName, self::DATA_IMAGE_URL_ATTRIBUTE_NAMES, true) &&
                self::isSafeRasterDataURI($safeValue)
            )) {
                $safeValue = self::sanitizeURLValue($safeValue, '');
                if ($safeValue === '') {
                    return null;
                }
            }
        }
        else if (self::isTextAttribute($attributeName)) {
            return $safeValue;
        }
        if (self::hasDangerousScheme($safeValue)) {
            return null;
        }
        return $safeValue;
    }

    // Security (SEC-SANITIZE): $doubleEncode = false leaves an '&' that
    // already starts an entity alone, so metadata round-tripped by the
    // outline designer does not accumulate &amp;amp;... (parity with Node).
    public static function sanitizeMetadataValue($value, $doubleEncode = true)
    {
        return self::escapeHTMLAttribute($value, $doubleEncode);
    }

    public static function escapeHTMLAttribute($value, $doubleEncode = true)
    {
        if ($value === null) {
            return '';
        }
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', $doubleEncode !== false);
    }

    public static function escapeXMLValue($value)
    {
        if ($value === null) {
            return '';
        }
        // Security (SEC-SANITIZE): drop characters XML 1.0 forbids
        // (allowed: #x9 #xA #xD [#x20-#xD7FF] [#xE000-#xFFFD] [#x10000-#x10FFFF])
        // so feeds/sitemaps stay well-formed.
        return htmlspecialchars(
            self::stripXMLIllegalChars((string)$value),
            ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }

    /**
     * Security (SEC-SANITIZE): remove characters not allowed in XML 1.0.
     * Invalid UTF-8 is first replaced with U+FFFD so the /u regex can run.
     */
    public static function stripXMLIllegalChars($value)
    {
        $stringValue = (string)$value;
        if (!mb_check_encoding($stringValue, 'UTF-8')) {
            $stringValue = mb_convert_encoding($stringValue, 'UTF-8', 'UTF-8');
        }
        $cleaned = preg_replace(
            '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u',
            '',
            $stringValue
        );
        return $cleaned === null ? '' : $cleaned;
    }

    /**
     * Security (SEC-SANITIZE): turn user-supplied text (titles, descriptions)
     * into plain text. Entities are decoded FIRST and tags stripped after, so
     * an encoded payload like &lt;img onerror=...&gt; cannot re-emerge as
     * markup after stripping.
     */
    public static function plainText($value)
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return '';
        }
        return strip_tags(html_entity_decode((string)$value));
    }

    /**
     * Security (SEC-SANITIZE): an auto page description is plain text
     * derived from the page body only. The caller passes the body without
     * its <page-break> element (pageBreakParser content), so page attributes
     * can never reach the description. Tags are stripped, entities decoded,
     * then tags stripped again so decoded markup cannot survive; every CR /
     * LF is removed and the result is cut at 200 characters (code points,
     * not bytes). Mirrors pageDescriptionFromBody in haxcms-nodejs
     * (src/lib/sanitizePageMetadata.js).
     */
    public static function pageDescription($body)
    {
        if (!is_string($body)) {
            return '';
        }
        $text = self::plainText(strip_tags($body));
        $text = str_replace(array("\r", "\n"), '', $text);
        return mb_substr($text, 0, 200, 'UTF-8');
    }

    /**
     * Security (SEC-SANITIZE): values interpolated into managed JSON / XML /
     * text files (manifest.json, package.json, imsmanifest.xml, llms.txt).
     * Twig's HTML autoescape handles quotes, but a raw newline or backslash
     * still breaks JSON string literals. CR/LF/tab become a space; other
     * control characters and backslashes are dropped.
     */
    public static function sanitizeManagedTemplateText($value)
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return '';
        }
        $stringValue = str_replace(array("\r", "\n", "\t"), ' ', (string)$value);
        $stringValue = preg_replace('/[\x00-\x1f\x7f]/', '', $stringValue);
        return str_replace('\\', '', (string)$stringValue);
    }

    /**
     * Security (SEC-SANITIZE): title / description for one managed template.
     * JSON templates get JSON string contents (json_encode without the
     * surrounding quotes), imsmanifest.xml gets escapeXMLValue, llms.txt
     * gets the plain control-char-stripped text. Returns null for any other
     * template (Twig's default HTML autoescape applies). Parity with Node PR8.
     */
    public static function escapeManagedTemplateValue($value, $templateLocation)
    {
        $text = self::sanitizeManagedTemplateText($value);
        switch (basename((string)$templateLocation)) {
            case 'manifest.json':
            case 'package.json':
                $encoded = json_encode(
                    $text,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
                );
                return ($encoded === false) ? '' : substr($encoded, 1, -1);
            case 'imsmanifest.xml':
                return self::escapeXMLValue($text);
            case 'llms.txt':
                return $text;
        }
        return null;
    }

    /**
     * Security (SEC-SANITIZE): template variables for one managed template.
     * title / description are pre-escaped for the template's format and
     * wrapped as Twig Markup so Twig's HTML autoescape does not turn
     * `Tom's R&D` into `Tom&#039;s R&amp;D`; every other variable keeps
     * Twig's existing autoescape.
     */
    public static function managedTemplateVars(array $templateVars, $templateLocation)
    {
        foreach (array('title', 'description') as $key) {
            if (!array_key_exists($key, $templateVars)) {
                continue;
            }
            $escaped = self::escapeManagedTemplateValue($templateVars[$key], $templateLocation);
            if ($escaped !== null) {
                $templateVars[$key] = new \Twig\Markup($escaped, 'UTF-8');
            }
        }
        return $templateVars;
    }

    private const METADATA_URL_KEYS = ['image', 'linkUrl'];
    private const METADATA_TEXT_KEYS = ['tags', 'icon', 'accentColor', 'linkTarget', 'relatedItems'];
    private const METADATA_FORBIDDEN_KEYS = ['__proto__', 'constructor', 'prototype'];
    private const METADATA_MAX_DEPTH = 5;

    /**
     * Security (SEC-SANITIZE): sanitize client-supplied page metadata the
     * same way saveNode does per field, for bulk paths (saveOutline,
     * createNode items[] / addPage). Parity contract with Node
     * sanitizePageMetadata.
     *
     * @param mixed $incoming metadata object/array from the request
     * @param object|null $themes result of HAXCMS::getThemes()
     * @return array ['values' => stdClass of keys to set, 'remove' => keys to delete]
     */
    public static function sanitizePageMetadata($incoming, $themes = null)
    {
        $result = array('values' => new \stdClass(), 'remove' => array());
        if (!self::isMetadataMap($incoming)) {
            return $result;
        }
        foreach ($incoming as $key => $value) {
            $key = (string)$key;
            if (in_array($key, self::METADATA_FORBIDDEN_KEYS, true)) {
                continue;
            }
            if (in_array($key, self::METADATA_URL_KEYS, true)) {
                if ($value === null || $value === '') {
                    $result['remove'][] = $key;
                }
                else if (is_string($value)) {
                    $clean = self::sanitizeURLValue($value, '');
                    if ($clean === '') {
                        $result['remove'][] = $key;
                    }
                    else {
                        $result['values']->{$key} = $clean;
                    }
                }
                // non-string URL values are ignored (existing value kept)
                continue;
            }
            if (in_array($key, self::METADATA_TEXT_KEYS, true)) {
                if ($value === null || $value === '') {
                    $result['remove'][] = $key;
                }
                else if (is_string($value) || is_int($value) || is_float($value)) {
                    $result['values']->{$key} = self::sanitizeMetadataValue((string)$value, false);
                }
                else {
                    $clean = self::sanitizeMetadataNested($value, 0, true);
                    if ($clean['keep']) {
                        $result['values']->{$key} = $clean['value'];
                    }
                }
                continue;
            }
            if ($key === 'theme') {
                $theme = self::resolveThemeMetadata($value, $themes);
                if ($theme === null) {
                    $result['remove'][] = $key;
                }
                else {
                    $result['values']->theme = $theme;
                }
                continue;
            }
            $clean = self::sanitizeMetadataNested($value, 0, false);
            if ($clean['keep']) {
                $result['values']->{$key} = $clean['value'];
            }
        }
        return $result;
    }

    /**
     * Security (SEC-SANITIZE): apply sanitized incoming metadata onto
     * $page->metadata (merge: keys not sent are left untouched).
     */
    public static function applyPageMetadata($page, $incoming, $themes = null)
    {
        if (!is_object($page)) {
            return $page;
        }
        if (!isset($page->metadata) || !is_object($page->metadata)) {
            $page->metadata = new \stdClass();
        }
        $sanitized = self::sanitizePageMetadata($incoming, $themes);
        foreach ($sanitized['remove'] as $key) {
            unset($page->metadata->{$key});
        }
        foreach ($sanitized['values'] as $key => $value) {
            $page->metadata->{$key} = $value;
        }
        return $page;
    }

    // stdClass or associative array (JSON object); lists are not maps
    private static function isMetadataMap($value)
    {
        if (is_object($value)) {
            return $value instanceof \stdClass;
        }
        return is_array($value) && (count($value) === 0 || !array_is_list($value));
    }

    private static function resolveThemeMetadata($value, $themes)
    {
        $themeKey = '';
        if (is_string($value)) {
            $themeKey = $value;
        }
        else if (is_object($value) && isset($value->key) && is_string($value->key)) {
            $themeKey = $value->key;
        }
        else if (is_array($value) && isset($value['key']) && is_string($value['key'])) {
            $themeKey = $value['key'];
        }
        if (
            $themeKey === '' ||
            $themeKey === '_none_' ||
            in_array($themeKey, self::METADATA_FORBIDDEN_KEYS, true) ||
            !is_object($themes) ||
            !property_exists($themes, $themeKey) ||
            !(is_object($themes->{$themeKey}) || is_array($themes->{$themeKey}))
        ) {
            return null;
        }
        // copy so the shared themes entry is never mutated
        $theme = json_decode(json_encode($themes->{$themeKey}));
        if (!is_object($theme)) {
            return null;
        }
        $theme->key = $themeKey;
        return $theme;
    }

    /**
     * Security (SEC-SANITIZE): metadata strings under unknown keys. Only
     * < > " are escaped (& and ' are left alone so URLs such as
     * files/a.png?x=1&y=2 in metadata.images round-trip unchanged); values
     * starting with a script-capable scheme are dropped (returns null).
     */
    public static function sanitizeUnknownMetadataString($value)
    {
        $stringValue = (string)$value;
        if (self::hasScriptCapableScheme($stringValue)) {
            return null;
        }
        return str_replace(array('<', '>', '"'), array('&lt;', '&gt;', '&quot;'), $stringValue);
    }

    // finite numbers / booleans / null kept, lists and maps walked (forbidden
    // keys dropped), anything else dropped. Strings: known text fields use
    // saveNode's escaping (htmlspecialchars, no double-encode), unknown keys
    // use sanitizeUnknownMetadataString. Returns ['keep' => bool, 'value' => mixed].
    private static function sanitizeMetadataNested($value, $depth, $textField)
    {
        if (is_string($value)) {
            if ($textField) {
                return array('keep' => true, 'value' => self::sanitizeMetadataValue($value, false));
            }
            $clean = self::sanitizeUnknownMetadataString($value);
            return array('keep' => $clean !== null, 'value' => $clean);
        }
        if ($value === null || is_bool($value) || is_int($value)) {
            return array('keep' => true, 'value' => $value);
        }
        if (is_float($value)) {
            return array('keep' => is_finite($value), 'value' => $value);
        }
        if ($depth >= self::METADATA_MAX_DEPTH) {
            return array('keep' => false, 'value' => null);
        }
        if (is_array($value) && array_is_list($value)) {
            $out = array();
            foreach ($value as $entry) {
                $clean = self::sanitizeMetadataNested($entry, $depth + 1, $textField);
                if ($clean['keep']) {
                    $out[] = $clean['value'];
                }
            }
            return array('keep' => true, 'value' => $out);
        }
        if (self::isMetadataMap($value)) {
            $out = new \stdClass();
            foreach ($value as $childKey => $childValue) {
                $childKey = (string)$childKey;
                if (in_array($childKey, self::METADATA_FORBIDDEN_KEYS, true)) {
                    continue;
                }
                $clean = self::sanitizeMetadataNested($childValue, $depth + 1, $textField);
                if ($clean['keep']) {
                    $out->{$childKey} = $clean['value'];
                }
            }
            return array('keep' => true, 'value' => $out);
        }
        return array('keep' => false, 'value' => null);
    }

    private static function isURLLikeAttribute($attributeName)
    {
        if (in_array($attributeName, self::URL_ATTRIBUTE_NAMES, true)) {
            return true;
        }
        if (str_contains($attributeName, 'url')) {
            return true;
        }
        if (str_ends_with($attributeName, '-src')) {
            return true;
        }
        if (str_ends_with($attributeName, '-href')) {
            return true;
        }
        return false;
    }

    /**
     * Parse an HTML fragment with the same parser the storage sanitizer uses
     * and return its body element (null if it cannot be parsed). Used by
     * BlockOperations to compare a page before and after a block change.
     */
    public static function parseBodyForComparison($html)
    {
        $dom = self::loadHTMLDocument(is_string($html) ? $html : '');
        if ($dom === null) {
            return null;
        }
        return self::getBodyElement($dom);
    }

    private static function getBodyElement($dom)
    {
        $bodyList = $dom->getElementsByTagName('body');
        if ($bodyList->length === 0) {
            return null;
        }
        $body = $bodyList->item(0);
        if ($body === null || !is_object($body)) {
            return null;
        }
        return $body;
    }

    private static function getChildNodes($node)
    {
        $children = [];
        foreach ($node->childNodes as $childNode) {
            $children[] = $childNode;
        }
        return $children;
    }

    private static function getElementChildren($node)
    {
        $children = [];
        foreach (self::getChildNodes($node) as $childNode) {
            if (isset($childNode->nodeType) && $childNode->nodeType === 1) {
                $children[] = $childNode;
            }
        }
        return $children;
    }

    // ---- HTML5 serialization ----------------------------------------
    // Written here rather than with saveHTML(): libxml percent-encodes URL
    // attributes and follows HTML4 rules, while browsers and haxcms-nodejs
    // (jsdom) serialize as HTML5. This matches jsdom byte for byte.

    private const VOID_ELEMENTS = [
        'area', 'base', 'basefont', 'bgsound', 'br', 'col', 'embed', 'frame', 'hr',
        'img', 'input', 'keygen', 'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    private const RAW_TEXT_ELEMENTS = [
        'style', 'script', 'xmp', 'iframe', 'noembed', 'noframes', 'plaintext',
    ];

    private static function nodeLocalName($node)
    {
        $name = isset($node->localName) && $node->localName !== null ? $node->localName : $node->nodeName;
        return strtolower((string)$name);
    }

    // the nodes that hold an element's content (a template's content
    // fragment when the parser made one, otherwise the element itself)
    // PHP 8.4 (Dom\HTMLDocument) keeps a template's content in a fragment
    // that is neither child nodes nor a property; it is read through
    // innerHTML once per sanitize call, parsed, and kept here so the walk and
    // the serializer use the same nodes
    private static $templateContents = null;

    private static function contentNode($node)
    {
        if (!isset($node->nodeType) || $node->nodeType !== 1 || self::nodeLocalName($node) !== 'template') {
            return $node;
        }
        if (isset($node->content) && is_object($node->content)) {
            return $node->content;
        }
        if ($node instanceof \DOMNode || $node->firstChild !== null) {
            // libxml (PHP 8.3) parses template content as children
            return $node;
        }
        if (self::$templateContents === null) {
            self::$templateContents = new \SplObjectStorage();
        }
        if (self::$templateContents->contains($node)) {
            return self::$templateContents[$node];
        }
        $inner = isset($node->innerHTML) ? (string)$node->innerHTML : '';
        if ($inner === '') {
            return $node;
        }
        $doc = self::loadHTMLDocument($inner);
        $body = $doc === null ? null : self::getBodyElement($doc);
        if ($body === null) {
            return $node;
        }
        self::$templateContents[$node] = $body;
        return $body;
    }

    private static function escapeAttributeHTML($value)
    {
        return str_replace(array('&', "\u{00A0}", '"'), array('&amp;', '&nbsp;', '&quot;'), (string)$value);
    }

    private static function escapeTextHTML($value)
    {
        return str_replace(array('&', "\u{00A0}", '<', '>'), array('&amp;', '&nbsp;', '&lt;', '&gt;'), (string)$value);
    }

    private static function serializeNode($node, $parentName = '')
    {
        $type = isset($node->nodeType) ? $node->nodeType : 0;
        if ($type === XML_TEXT_NODE || $type === XML_CDATA_SECTION_NODE) {
            if (in_array($parentName, self::RAW_TEXT_ELEMENTS, true)) {
                return (string)$node->nodeValue;
            }
            return self::escapeTextHTML($node->nodeValue);
        }
        if ($type === XML_COMMENT_NODE) {
            return '<!--' . $node->nodeValue . '-->';
        }
        if ($type !== XML_ELEMENT_NODE) {
            return '';
        }
        $name = self::nodeLocalName($node);
        $out = '<' . $name;
        if ($node->attributes) {
            foreach ($node->attributes as $attribute) {
                $out .= ' ' . strtolower((string)$attribute->nodeName) . '="' . self::escapeAttributeHTML($attribute->value) . '"';
            }
        }
        $out .= '>';
        if (in_array($name, self::VOID_ELEMENTS, true)) {
            return $out;
        }
        return $out . self::getInnerHTML(self::contentNode($node), $name) . '</' . $name . '>';
    }

    private static function getInnerHTML($node, $name = null)
    {
        if ($name === null) {
            $name = isset($node->nodeType) && $node->nodeType === XML_ELEMENT_NODE ? self::nodeLocalName($node) : '';
        }
        $output = '';
        foreach (self::getChildNodes(self::contentNode($node)) as $childNode) {
            $output .= self::serializeNode($childNode, $name);
        }
        return $output;
    }

    private static function hasElementDescendant($element)
    {
        foreach (self::getChildNodes($element) as $childNode) {
            if (isset($childNode->nodeType) && $childNode->nodeType === 1) {
                return true;
            }
        }
        return false;
    }

    private static function replaceNodeChildrenWithText($dom, $node, $textContent)
    {
        foreach (self::getChildNodes($node) as $childNode) {
            $node->removeChild($childNode);
        }
        // a template's content may live in its own document (PHP 8.4)
        $owner = $node->ownerDocument !== null ? $node->ownerDocument : $dom;
        $node->appendChild($owner->createTextNode($textContent));
    }

    private static function preprocessTextTemplates($dom)
    {
        foreach (self::TEXT_TEMPLATE_HOSTS as $hostTagName) {
            $hostNodes = [];
            foreach ($dom->getElementsByTagName($hostTagName) as $hostNode) {
                if (isset($hostNode->nodeType) && $hostNode->nodeType === 1) {
                    $hostNodes[] = $hostNode;
                }
            }
            foreach ($hostNodes as $hostNode) {
                $templateNodes = [];
                foreach ($hostNode->getElementsByTagName('template') as $templateNode) {
                    if (isset($templateNode->nodeType) && $templateNode->nodeType === 1) {
                        $templateNodes[] = $templateNode;
                    }
                }
                foreach ($templateNodes as $templateNode) {
                    if (self::hasElementDescendant(self::contentNode($templateNode))) {
                        $templateInnerHTML = self::getInnerHTML($templateNode);
                        self::replaceNodeChildrenWithText($dom, self::contentNode($templateNode), $templateInnerHTML);
                    }
                }
            }
        }
    }

    private static function normalizeSandboxValue($value)
    {
        if ($value === null) {
            return '';
        }
        $tokens = preg_split('/\s+/', strtolower(trim((string)$value)));
        if (!is_array($tokens)) {
            return '';
        }
        $normalizedTokens = [];
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (
                in_array($token, self::IFRAME_SANDBOX_ALLOWED_TOKENS, true) &&
                !in_array($token, $normalizedTokens, true)
            ) {
                $normalizedTokens[] = $token;
            }
        }
        return implode(' ', $normalizedTokens);
    }

    private static function normalizeIframeAttributes($iframe)
    {
        if ($iframe->hasAttribute('src')) {
            $safeSrc = self::sanitizeURLValue($iframe->getAttribute('src'), '');
            if ($safeSrc === '') {
                $iframe->removeAttribute('src');
            }
            else if ($safeSrc !== $iframe->getAttribute('src')) {
                $iframe->setAttribute('src', $safeSrc);
            }
        }

        if (!$iframe->hasAttribute('loading') || trim($iframe->getAttribute('loading')) === '') {
            $iframe->setAttribute('loading', 'lazy');
        }
        else {
            $loadingValue = strtolower(trim($iframe->getAttribute('loading')));
            if ($loadingValue !== 'lazy' && $loadingValue !== 'eager') {
                $iframe->setAttribute('loading', 'lazy');
            }
            else {
                $iframe->setAttribute('loading', $loadingValue);
            }
        }

        if (!$iframe->hasAttribute('referrerpolicy') || trim($iframe->getAttribute('referrerpolicy')) === '') {
            $iframe->setAttribute('referrerpolicy', 'no-referrer');
        }
        else {
            $referrerPolicyValue = strtolower(trim($iframe->getAttribute('referrerpolicy')));
            if (!in_array($referrerPolicyValue, self::REFERRER_POLICY_ALLOWED, true)) {
                $iframe->setAttribute('referrerpolicy', 'no-referrer');
            }
            else {
                $iframe->setAttribute('referrerpolicy', $referrerPolicyValue);
            }
        }

        $sandboxValue = self::normalizeSandboxValue($iframe->getAttribute('sandbox'));
        if ($sandboxValue === '') {
            $iframe->setAttribute('sandbox', self::IFRAME_DEFAULT_SANDBOX);
        }
        else {
            $iframe->setAttribute('sandbox', $sandboxValue);
        }

        if ($iframe->hasAttribute('allowfullscreen')) {
            $iframe->setAttribute('allowfullscreen', 'allowfullscreen');
        }
    }

    private static function isCustomElementName($tagName)
    {
        $policy = self::policy();
        return preg_match('/' . $policy['customElementName'] . '/', $tagName) === 1 &&
            !isset($policy['reservedSet'][$tagName]);
    }

    // A comment stays only when it cannot hide markup: no < or >, and not a
    // processing instruction an HTML parser turns into a comment (a PHP tag).
    private static function isSafeComment($data)
    {
        $value = (string)$data;
        return strpos($value, '<') === false && strpos($value, '>') === false &&
            ($value === '' || $value[0] !== '?');
    }

    /**
     * Shared attribute policy, in order: invalid name, forbidden name or
     * event handler, iframe allowlist, mXSS value, DOM clobbering name, then
     * the value rules. Returns null to remove. Mirrors Node
     * policyAttributeValue.
     */
    private static function policyAttributeValue($tagName, $attributeName, $value)
    {
        $policy = self::policy();
        if (preg_match('/' . $policy['attributeName'] . '/', $attributeName) !== 1) {
            return null;
        }
        if (
            preg_match('/^on[a-z0-9_-]+$/i', $attributeName) ||
            isset($policy['forbiddenAttributeSet'][$attributeName]) ||
            in_array($attributeName, self::FORBIDDEN_ATTRIBUTES, true) ||
            str_starts_with($attributeName, 'xmlns:')
        ) {
            return null;
        }
        if ($tagName === 'iframe' && !in_array($attributeName, self::IFRAME_ALLOWED_ATTRIBUTES, true)) {
            return null;
        }
        // ASCII case only, like the JavaScript /i regex
        if (preg_match('/' . $policy['unsafeAttributeValue'] . '/i', (string)$value) === 1) {
            return null;
        }
        if (
            ($attributeName === 'id' || $attributeName === 'name') &&
            isset($policy['clobberTagSet'][$tagName]) &&
            isset($policy['clobberNameSet'][(string)$value])
        ) {
            return null;
        }
        return self::sanitizeAttributeValue($attributeName, $value);
    }

    private static function sanitizeElementAttributes($element)
    {
        $tagName = self::nodeLocalName($element);
        $attributeNodes = [];
        foreach ($element->attributes as $attributeNode) {
            $attributeNodes[] = $attributeNode;
        }
        foreach ($attributeNodes as $attributeNode) {
            $attributeName = strtolower((string)$attributeNode->nodeName);
            $attributeValue = (string)$attributeNode->value;
            $safeValue = self::policyAttributeValue($tagName, $attributeName, $attributeValue);
            if ($safeValue === null) {
                $element->removeAttributeNode($attributeNode);
            }
            else if ($safeValue !== $attributeValue) {
                $attributeNode->value = $safeValue;
            }
        }

        if ($tagName === 'iframe') {
            self::normalizeIframeAttributes($element);
        }
    }

    /**
     * The shared policy walk (Node sanitizeElementTree). For each child, in
     * order: comments that could hide markup and processing instructions
     * go; elements in removeWithContent (or the extra forbidden tags) go with
     * their content; elements that are neither allowed nor custom elements
     * are unwrapped (their children, already sanitized, take their place);
     * every other element has its attributes sanitized and its children
     * walked. An iframe keeps no children.
     */
    private static function sanitizeNodeTree($dom, $rootNode, $extraRemove = array(), $dropComments = false)
    {
        $policy = self::policy();
        foreach (self::getChildNodes($rootNode) as $child) {
            $type = isset($child->nodeType) ? $child->nodeType : 0;
            if ($type === XML_COMMENT_NODE) {
                if ($dropComments || !self::isSafeComment($child->nodeValue)) {
                    $child->parentNode->removeChild($child);
                }
                continue;
            }
            if ($type === XML_PI_NODE) {
                $child->parentNode->removeChild($child);
                continue;
            }
            if ($type !== XML_ELEMENT_NODE) {
                continue;
            }
            $tagName = self::nodeLocalName($child);
            $namespace = isset($child->namespaceURI) ? $child->namespaceURI : null;
            if (
                isset($policy['removeWithContentSet'][$tagName]) ||
                in_array($tagName, self::FORBIDDEN_TAGS, true) ||
                in_array($tagName, $extraRemove, true) ||
                ($namespace !== null && $namespace !== '' && $namespace !== 'http://www.w3.org/1999/xhtml')
            ) {
                $child->parentNode->removeChild($child);
                continue;
            }
            if (!isset($policy['allowedTagSet'][$tagName]) && !self::isCustomElementName($tagName)) {
                $content = self::contentNode($child);
                self::sanitizeNodeTree($dom, $content, $extraRemove, $dropComments);
                foreach (self::getChildNodes($content) as $grandChild) {
                    $child->parentNode->insertBefore($grandChild, $child);
                }
                $child->parentNode->removeChild($child);
                continue;
            }

            self::sanitizeElementAttributes($child);

            if ($tagName === 'iframe') {
                foreach (self::getChildNodes($child) as $grandChild) {
                    $child->removeChild($grandChild);
                }
                continue;
            }
            if ($tagName === 'template') {
                $parentTagName = '';
                if (
                    $child->parentNode !== null &&
                    isset($child->parentNode->nodeType) &&
                    $child->parentNode->nodeType === XML_ELEMENT_NODE
                ) {
                    $parentTagName = self::nodeLocalName($child->parentNode);
                }
                if (in_array($parentTagName, self::TEXT_TEMPLATE_HOSTS, true)) {
                    if (self::hasElementDescendant(self::contentNode($child))) {
                        $templateInnerHTML = self::getInnerHTML($child);
                        self::replaceNodeChildrenWithText($dom, self::contentNode($child), $templateInnerHTML);
                    }
                }
                else {
                    self::sanitizeNodeTree($dom, self::contentNode($child), $extraRemove, $dropComments);
                }
                continue;
            }

            self::sanitizeNodeTree($dom, $child, $extraRemove, $dropComments);
        }
    }

    /**
     * DOMPurify's markup probe (Node removeMarkupProbeElements), checked
     * parent before children: an element with child nodes but no element
     * children, whose text and whose serialized content both look like
     * markup, is removed with its content.
     */
    private static function removeMarkupProbeElements($rootNode)
    {
        foreach (self::getChildNodes($rootNode) as $child) {
            if (!isset($child->nodeType) || $child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            $content = self::contentNode($child);
            $children = self::getChildNodes($content);
            $text = '';
            $hasElementChild = false;
            foreach ($children as $grandChild) {
                if ($grandChild->nodeType === XML_ELEMENT_NODE) {
                    $hasElementChild = true;
                }
                else if ($grandChild->nodeType === XML_TEXT_NODE || $grandChild->nodeType === XML_CDATA_SECTION_NODE) {
                    $text .= $grandChild->nodeValue;
                }
            }
            if (
                count($children) > 0 &&
                !$hasElementChild &&
                preg_match('/<[\/\w!]/', $text) === 1 &&
                preg_match('/<[\/\w!]/', self::getInnerHTML($child)) === 1
            ) {
                $child->parentNode->removeChild($child);
                continue;
            }
            self::removeMarkupProbeElements($content);
        }
    }

    /**
     * An HTML parser drops one newline right after <pre>, <listing> and
     * <textarea>, so stored content starting with a newline there would lose
     * it on the next save. Drop it now so saving is idempotent (Node
     * dropLeadingNewlines).
     */
    private static function dropLeadingNewlines($rootNode)
    {
        foreach (self::getChildNodes($rootNode) as $child) {
            if (!isset($child->nodeType) || $child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            self::dropLeadingNewline($child);
            self::dropLeadingNewlines(self::contentNode($child));
        }
    }

    private static function dropLeadingNewline($element)
    {
        if (!in_array(self::nodeLocalName($element), self::LEADING_NEWLINE_ELEMENTS, true)) {
            return;
        }
        $first = $element->firstChild;
        if ($first !== null && $first->nodeType === XML_TEXT_NODE && substr((string)$first->nodeValue, 0, 1) === "\n") {
            $first->nodeValue = substr((string)$first->nodeValue, 1);
            if ($first->nodeValue === '') {
                $element->removeChild($first);
            }
        }
    }

    /**
     * libxml (PHP 8.3) builds an HTML4 tree. Reshape the parts that HAX
     * content meets so the tree is the one an HTML5 parser (browsers,
     * haxcms-nodejs, PHP 8.4) builds: the newline an HTML5 parser drops
     * after <pre>/<listing>/<textarea>, HTML5 void elements libxml put
     * content inside, and the <tbody> implied around a table's rows.
     */
    private static function reshapeLibxmlTree($rootNode)
    {
        // a live walk: content hoisted out of an element is visited next
        $child = $rootNode->firstChild;
        while ($child !== null) {
            if (!isset($child->nodeType) || $child->nodeType !== XML_ELEMENT_NODE) {
                $child = $child->nextSibling;
                continue;
            }
            $name = self::nodeLocalName($child);
            if ($name === 'image') {
                // an HTML5 parser reads <image> as <img>
                $img = $child->ownerDocument->createElement('img');
                foreach ($child->attributes as $attribute) {
                    $img->setAttribute($attribute->nodeName, $attribute->value);
                }
                $child->parentNode->insertBefore($img, $child);
                foreach (self::getChildNodes($child) as $grandChild) {
                    $child->parentNode->insertBefore($grandChild, $child);
                }
                $child->parentNode->removeChild($child);
                $child = $img;
                $name = 'img';
            }
            if ($child->attributes) {
                foreach ($child->attributes as $attribute) {
                    $attributeName = strtolower((string)$attribute->nodeName);
                    if (in_array($attributeName, self::LIBXML_BOOLEAN_ATTRIBUTES, true) && strtolower((string)$attribute->value) === $attributeName) {
                        $attribute->value = '';
                    }
                }
            }
            if (in_array($name, self::LIBXML_UNKNOWN_VOID_ELEMENTS, true) || $name === 'img') {
                $next = $child->nextSibling;
                foreach (self::getChildNodes($child) as $grandChild) {
                    if ($next === null) {
                        $child->parentNode->appendChild($grandChild);
                    }
                    else {
                        $child->parentNode->insertBefore($grandChild, $next);
                    }
                }
            }
            self::dropLeadingNewline($child);
            if (in_array($name, self::TABLE_PART_ELEMENTS, true) && !self::insideTableOrTemplate($child)) {
                // an HTML5 parser ignores table tags outside a table
                $next = $child->nextSibling;
                $first = $child->firstChild;
                foreach (self::getChildNodes($child) as $grandChild) {
                    $child->parentNode->insertBefore($grandChild, $child);
                }
                $child->parentNode->removeChild($child);
                $child = $first !== null ? $first : $next;
                continue;
            }
            if ($name === 'table') {
                // elements moved in front of the table are visited first
                $moved = self::reshapeTable($child);
                if ($moved !== null) {
                    $child = $moved;
                    continue;
                }
            }
            self::reshapeLibxmlTree($child);
            $child = $child->nextSibling;
        }
    }

    private const TABLE_PART_ELEMENTS = ['caption', 'col', 'colgroup', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr'];

    // what an HTML5 parser accepts directly inside each table element;
    // anything else is moved in front of the table ("foster parenting")
    private const TABLE_CONTENT = [
        'table' => ['caption', 'colgroup', 'col', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'template', 'script', 'style'],
        'thead' => ['tr', 'td', 'th', 'template', 'script', 'style'],
        'tbody' => ['tr', 'td', 'th', 'template', 'script', 'style'],
        'tfoot' => ['tr', 'td', 'th', 'template', 'script', 'style'],
        'tr' => ['td', 'th', 'template', 'script', 'style'],
    ];

    private static function insideTableOrTemplate($node)
    {
        for ($n = $node->parentNode; $n !== null && isset($n->nodeType) && $n->nodeType === XML_ELEMENT_NODE; $n = $n->parentNode) {
            $name = self::nodeLocalName($n);
            if ($name === 'table' || $name === 'template') {
                return true;
            }
        }
        return false;
    }

    private static function isWhitespaceOrComment($node)
    {
        return $node->nodeType === XML_COMMENT_NODE ||
            ($node->nodeType === XML_TEXT_NODE && trim((string)$node->nodeValue, " \t\n\f\r") === '');
    }

    /**
     * The table shape haxcms-nodejs' HTML5 parser (parse5) builds: elements
     * that cannot sit in a table section move in front of the table and text
     * moves right after it ("foster parenting"); cells get an implied <tr>,
     * rows an implied <tbody>, a bare <col> an implied <colgroup>.
     */
    private static function reshapeTable($table)
    {
        $textAnchor = null;
        $before = $table->previousSibling;
        self::fosterParent($table, $table, $textAnchor);
        // the first node moved in front of the table, if any
        $first = $before === null ? $table->parentNode->firstChild : $before->nextSibling;
        self::wrapRuns($table, ['col'], 'colgroup');
        self::wrapRuns($table, ['td', 'th'], 'tr');
        self::wrapTableRows($table);
        foreach (self::getChildNodes($table) as $section) {
            if ($section->nodeType === XML_ELEMENT_NODE && in_array(self::nodeLocalName($section), ['thead', 'tbody', 'tfoot'], true)) {
                self::wrapRuns($section, ['td', 'th'], 'tr');
            }
        }
        return $first === $table ? null : $first;
    }

    // one walk in document order over the table, its sections and rows
    private static function fosterParent($table, $container, &$textAnchor)
    {
        $allowed = self::TABLE_CONTENT[self::nodeLocalName($container)];
        foreach (self::getChildNodes($container) as $child) {
            if (self::isWhitespaceOrComment($child)) {
                continue;
            }
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $name = self::nodeLocalName($child);
                if (in_array($name, $allowed, true)) {
                    if (isset(self::TABLE_CONTENT[$name])) {
                        self::fosterParent($table, $child, $textAnchor);
                    }
                    continue;
                }
                if ($name === 'input' && strtolower((string)$child->getAttribute('type')) === 'hidden') {
                    continue;
                }
                $table->parentNode->insertBefore($child, $table);
                continue;
            }
            if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                // text goes right after the table (after text moved before it)
                $after = $textAnchor !== null ? $textAnchor : $table;
                if ($after->nextSibling === null) {
                    $table->parentNode->appendChild($child);
                }
                else {
                    $table->parentNode->insertBefore($child, $after->nextSibling);
                }
                $textAnchor = $child;
            }
        }
    }

    // consecutive $names children (with whitespace and comments between)
    // go into one implied $wrapper element
    private static function wrapRuns($parent, $names, $wrapper)
    {
        $current = null;
        foreach (self::getChildNodes($parent) as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE && in_array(self::nodeLocalName($child), $names, true)) {
                if ($current === null) {
                    $current = $parent->ownerDocument->createElement($wrapper);
                    $parent->insertBefore($current, $child);
                }
                $current->appendChild($child);
            }
            else if ($current !== null && self::isWhitespaceOrComment($child)) {
                $current->appendChild($child);
            }
            else {
                $current = null;
            }
        }
    }

    // rows directly in a <table> go into an implied <tbody>, with the
    // whitespace and comments between them, as an HTML5 parser does
    private static function wrapTableRows($table)
    {
        $tbody = null;
        foreach (self::getChildNodes($table) as $child) {
            $isRow = $child->nodeType === XML_ELEMENT_NODE && self::nodeLocalName($child) === 'tr';
            $isFiller = $child->nodeType === XML_COMMENT_NODE ||
                ($child->nodeType === XML_TEXT_NODE && trim((string)$child->nodeValue, " \t\n\f\r") === '');
            if ($isRow) {
                if ($tbody === null) {
                    $tbody = $table->ownerDocument->createElement('tbody');
                    $table->insertBefore($tbody, $child);
                }
                $tbody->appendChild($child);
            }
            else if ($isFiller && $tbody !== null) {
                $tbody->appendChild($child);
            }
            else {
                $tbody = null;
            }
        }
    }

    private static function loadHTMLDocument($html)
    {
        // libxml's HTML parser follows the legacy HTML4 default of assuming
        // ISO-8859-1 (Latin-1) input whenever no charset is declared in the
        // markup. Constructing DOMDocument with a 'UTF-8' encoding only
        // controls how the DOM is *serialized* back out, not how the input
        // bytes are interpreted while parsing. Without an explicit
        // <meta charset="utf-8">, multi-byte UTF-8 sequences (e.g. curly
        // quotes/smart punctuation) get parsed as several separate Latin-1
        // characters, then re-encoded as UTF-8 on save, producing mojibake
        // (e.g. "ââ") that compounds on every subsequent save.
        // no closing </body></html> for the HTML5 parser: an unclosed
        // <textarea> would take them as text, as it takes the rest of the input
        $wrappedHTML = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html;
        if (class_exists('\Dom\HTMLDocument') && method_exists('\Dom\HTMLDocument', 'createFromString')) {
            try {
                // createFromString accepts only LIBXML_NOERROR,
                // LIBXML_COMPACT, LIBXML_HTML_NOIMPLIED and
                // Dom\HTML_NO_DEFAULT_NS; any other flag throws, which used
                // to send PHP 8.4 to the libxml fallback below
                $dom = \Dom\HTMLDocument::createFromString($wrappedHTML, LIBXML_NOERROR);
                if ($dom !== null) {
                    return $dom;
                }
            }
            catch (\Throwable $exception) {
                // Fall through to legacy DOMDocument parser.
            }
        }

        if (!class_exists('\DOMDocument')) {
            return null;
        }
        $wrappedHTML .= '</body></html>';
        // an HTML5 parser reads CR LF and a lone CR as LF before anything
        // else; libxml keeps the CR
        $wrappedHTML = str_replace(array("\r\n", "\r"), "\n", $wrappedHTML);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $originalUseInternalErrors = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML(
            $wrappedHTML,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($originalUseInternalErrors);
        if (!$loaded) {
            return null;
        }
        return $dom;
    }

    /**
     * Storage sanitizer. Same policy and same output as haxcms-nodejs
     * sanitizeHTMLForStorage (haxtheweb/issues#3273). $options['forbidTags']
     * removes extra tags with their content (e.g. iframe for imports);
     * $options['dropComments'] removes every comment.
     */
    public static function sanitizeHTMLForStorage($html, $options = array())
    {
        if (!is_string($html)) {
            return '';
        }
        $extraRemove = array();
        $dropComments = is_array($options) && !empty($options['dropComments']);
        if (is_array($options) && isset($options['forbidTags']) && is_array($options['forbidTags'])) {
            foreach ($options['forbidTags'] as $tag) {
                $extraRemove[] = strtolower((string)$tag);
            }
        }

        self::$templateContents = new \SplObjectStorage();
        $dom = self::loadHTMLDocument($html);
        if ($dom === null) {
            return '';
        }
        $body = self::getBodyElement($dom);
        if ($body === null) {
            return '';
        }
        if ($dom instanceof \DOMDocument) {
            self::reshapeLibxmlTree($body);
        }

        self::preprocessTextTemplates($dom);
        self::sanitizeNodeTree($dom, $body, $extraRemove, $dropComments);
        self::removeMarkupProbeElements($body);
        self::dropLeadingNewlines($body);
        return self::getInnerHTML($body, 'body');
    }

    /**
     * Untrusted HTML (uploaded files, remote URLs) before it becomes page
     * content: the storage policy plus no iframes and no comments, NUL bytes
     * removed, outer whitespace trimmed. Mirrors Node convertUtils
     * sanitizeUntrustedHtml.
     */
    public static function sanitizeUntrustedHTML($html)
    {
        if (!is_string($html)) {
            return '';
        }
        return trim(self::sanitizeHTMLForStorage(str_replace("\0", '', $html), array('forbidTags' => array('iframe'), 'dropComments' => true)));
    }
}
