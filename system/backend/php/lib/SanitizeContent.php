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

    private const IFRAME_DEFAULT_SANDBOX = 'allow-scripts allow-same-origin allow-popups allow-forms';

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

    private static function saveNodeHTML($ownerDocument, $node)
    {
        if (is_object($ownerDocument)) {
            if (method_exists($ownerDocument, 'saveHTML')) {
                return (string)$ownerDocument->saveHTML($node);
            }
            if (method_exists($ownerDocument, 'saveHtml')) {
                return (string)$ownerDocument->saveHtml($node);
            }
        }
        return '';
    }

    private static function getInnerHTML($node)
    {
        $output = '';
        foreach (self::getChildNodes($node) as $childNode) {
            $ownerDocument = $node->ownerDocument ? $node->ownerDocument : $node;
            $output .= self::saveNodeHTML($ownerDocument, $childNode);
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
        $node->appendChild($dom->createTextNode($textContent));
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
                    if (self::hasElementDescendant($templateNode)) {
                        $templateInnerHTML = self::getInnerHTML($templateNode);
                        self::replaceNodeChildrenWithText($dom, $templateNode, $templateInnerHTML);
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

    private static function sanitizeElementAttributes($element)
    {
        $tagName = strtolower($element->tagName);
        $attributeNodes = [];
        foreach ($element->attributes as $attributeNode) {
            $attributeName = '';
            if (isset($attributeNode->name)) {
                $attributeName = (string)$attributeNode->name;
            }
            else if (isset($attributeNode->nodeName)) {
                $attributeName = (string)$attributeNode->nodeName;
            }
            if ($attributeName === '') {
                continue;
            }
            $attributeValue = '';
            if (isset($attributeNode->value)) {
                $attributeValue = (string)$attributeNode->value;
            }
            else if (isset($attributeNode->nodeValue) && $attributeNode->nodeValue !== null) {
                $attributeValue = (string)$attributeNode->nodeValue;
            }
            $attributeNodes[] = [
                'name' => $attributeName,
                'value' => $attributeValue,
            ];
        }

        foreach ($attributeNodes as $attributeNode) {
            $attributeOriginalName = $attributeNode['name'];
            $attributeName = strtolower($attributeOriginalName);
            $attributeValue = $attributeNode['value'];

            if (
                preg_match('/^on[a-z0-9_-]+$/i', $attributeName) ||
                in_array($attributeName, self::FORBIDDEN_ATTRIBUTES, true)
            ) {
                $element->removeAttribute($attributeOriginalName);
                continue;
            }

            if ($tagName === 'iframe' && !in_array($attributeName, self::IFRAME_ALLOWED_ATTRIBUTES, true)) {
                $element->removeAttribute($attributeOriginalName);
                continue;
            }

            // Security (SEC-SANITIZE): URL-named attributes use the allowlist,
            // every attribute (custom elements consume URLs on arbitrary names)
            // is removed when it starts with a script-capable scheme, exactly
            // as a disallowed href is.
            $safeValue = self::sanitizeAttributeValue($attributeName, $attributeValue);
            if ($safeValue === null) {
                $element->removeAttribute($attributeOriginalName);
            }
            else if ($safeValue !== $attributeValue) {
                $element->setAttribute($attributeOriginalName, $safeValue);
            }
        }

        if ($tagName === 'iframe') {
            self::normalizeIframeAttributes($element);
        }
    }

    private static function sanitizeNodeTree($dom, $rootNode)
    {
        $childElements = self::getElementChildren($rootNode);
        foreach ($childElements as $childElement) {
            $tagName = strtolower($childElement->tagName);
            if (in_array($tagName, self::FORBIDDEN_TAGS, true)) {
                if ($childElement->parentNode !== null) {
                    $childElement->parentNode->removeChild($childElement);
                }
                continue;
            }

            self::sanitizeElementAttributes($childElement);

            if ($tagName === 'template') {
                $parentTagName = '';
                if (
                    $childElement->parentNode !== null &&
                    isset($childElement->parentNode->nodeType) &&
                    $childElement->parentNode->nodeType === 1
                ) {
                    $parentTagName = strtolower($childElement->parentNode->tagName);
                }
                if (in_array($parentTagName, self::TEXT_TEMPLATE_HOSTS, true)) {
                    if (self::hasElementDescendant($childElement)) {
                        $templateInnerHTML = self::getInnerHTML($childElement);
                        self::replaceNodeChildrenWithText($dom, $childElement, $templateInnerHTML);
                    }
                }
                else {
                    self::sanitizeNodeTree($dom, $childElement);
                }
                continue;
            }

            self::sanitizeNodeTree($dom, $childElement);
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
        $wrappedHTML = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>';
        if (class_exists('\Dom\HTMLDocument') && method_exists('\Dom\HTMLDocument', 'createFromString')) {
            try {
                $dom = \Dom\HTMLDocument::createFromString(
                    $wrappedHTML,
                    LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
                );
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

    public static function sanitizeHTMLForStorage($html)
    {
        if (!is_string($html)) {
            return '';
        }

        $dom = self::loadHTMLDocument($html);
        if ($dom === null) {
            return '';
        }

        self::preprocessTextTemplates($dom);
        $body = self::getBodyElement($dom);
        if ($body === null) {
            return '';
        }

        self::sanitizeNodeTree($dom, $body);
        return self::getInnerHTML($body);
    }
}
