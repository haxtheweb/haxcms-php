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

    // Security (SEC-SANITIZE): schemes that execute script or render active
    // documents. Attributes that are NOT URL-named are only scrubbed when
    // their value starts with one of these, so plain text such as
    // title="Note: hi" keeps working. URL-named attributes use the stricter
    // ALLOWED_PROTOCOLS allowlist instead.
    private const DANGEROUS_PROTOCOLS = [
        'javascript',
        'vbscript',
        'livescript',
        'mocha',
        'data',
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
     * Security (SEC-SANITIZE): detect the URL scheme a value would be
     * interpreted with. Decodes HTML entities once (so &#106;avascript: and
     * javascript&colon; are caught), strips ASCII whitespace and control
     * characters (U+0000-U+0020, U+007F) anywhere in the value, lowercases,
     * then matches ^[a-z][a-z0-9+.-]*: . Returns the scheme or null.
     */
    public static function detectURLScheme($value)
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }
        $stringValue = html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $stringValue = preg_replace('/[\x00-\x20\x7f]+/', '', $stringValue);
        if ($stringValue === null || $stringValue === '') {
            return null;
        }
        if (!preg_match('/^([a-z][a-z0-9+.-]*):/', strtolower($stringValue), $matches)) {
            return null;
        }
        return $matches[1];
    }

    /**
     * Security (SEC-SANITIZE): true when the value carries a scheme outside
     * the URL allowlist (http, https, mailto, tel).
     */
    public static function hasDisallowedURLScheme($value)
    {
        $scheme = self::detectURLScheme($value);
        return $scheme !== null && !in_array($scheme, self::ALLOWED_PROTOCOLS, true);
    }

    /**
     * Security (SEC-SANITIZE): true when the value carries a script-capable
     * scheme (javascript:, vbscript:, data:, ...).
     */
    public static function hasDangerousURLScheme($value)
    {
        $scheme = self::detectURLScheme($value);
        return $scheme !== null && in_array($scheme, self::DANGEROUS_PROTOCOLS, true);
    }

    public static function sanitizeMetadataValue($value)
    {
        return self::escapeHTMLAttribute($value);
    }

    public static function escapeHTMLAttribute($value)
    {
        if ($value === null) {
            return '';
        }
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

    private const METADATA_URL_KEYS = ['image', 'linkUrl'];
    private const METADATA_FORBIDDEN_KEYS = ['__proto__', 'constructor', 'prototype'];
    private const METADATA_MAX_DEPTH = 8;

    /**
     * Security (SEC-SANITIZE): sanitize client-supplied page metadata the
     * same way saveNode does per field, for bulk paths (saveOutline,
     * createNode items[] / addPage). Values identical to what is already
     * stored are kept as-is so round-tripped metadata is not re-escaped on
     * every save.
     *
     * @param mixed $incoming metadata object/array from the request
     * @param object|null $existing the page's current metadata
     * @param object|null $themes result of HAXCMS::getThemes()
     * @return stdClass sanitized key => value pairs to apply
     */
    public static function sanitizePageMetadata($incoming, $existing = null, $themes = null)
    {
        $result = new \stdClass();
        if (!is_array($incoming) && !is_object($incoming)) {
            return $result;
        }
        foreach ($incoming as $key => $value) {
            $key = (string)$key;
            if ($key === '' || in_array($key, self::METADATA_FORBIDDEN_KEYS, true)) {
                continue;
            }
            $hasExisting = is_object($existing) && property_exists($existing, $key);
            if ($key === 'theme') {
                $theme = self::sanitizeThemeMetadata($value, $themes);
                if ($theme !== null) {
                    $result->theme = $theme;
                }
                continue;
            }
            // unchanged values were already sanitized when first stored
            if ($hasExisting && $existing->{$key} == $value) {
                $result->{$key} = $existing->{$key};
                continue;
            }
            if (in_array($key, self::METADATA_URL_KEYS, true)) {
                if (is_string($value)) {
                    $result->{$key} = self::sanitizeURLValue($value, '');
                }
                continue;
            }
            $result->{$key} = self::sanitizeMetadataTree($value, 0);
        }
        return $result;
    }

    private static function sanitizeThemeMetadata($value, $themes)
    {
        $themeKey = null;
        if (is_string($value)) {
            $themeKey = $value;
        }
        else if (is_object($value) && isset($value->key) && is_string($value->key)) {
            $themeKey = $value->key;
        }
        else if (is_array($value) && isset($value['key']) && is_string($value['key'])) {
            $themeKey = $value['key'];
        }
        if ($themeKey === null || !is_object($themes) || !isset($themes->{$themeKey})) {
            return null;
        }
        $theme = json_decode(json_encode($themes->{$themeKey}));
        if (!is_object($theme)) {
            return null;
        }
        $theme->key = $themeKey;
        return $theme;
    }

    private static function sanitizeMetadataTree($value, $depth)
    {
        if (is_string($value)) {
            return self::sanitizeMetadataValue($value);
        }
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }
        if ($depth >= self::METADATA_MAX_DEPTH) {
            return null;
        }
        if (is_array($value) || is_object($value)) {
            $isList = is_array($value) && array_is_list($value);
            $out = $isList ? array() : new \stdClass();
            foreach ($value as $childKey => $childValue) {
                if ($isList) {
                    $out[] = self::sanitizeMetadataTree($childValue, $depth + 1);
                    continue;
                }
                $childKey = (string)$childKey;
                if ($childKey === '' || in_array($childKey, self::METADATA_FORBIDDEN_KEYS, true)) {
                    continue;
                }
                $out->{$childKey} = self::sanitizeMetadataTree($childValue, $depth + 1);
            }
            return $out;
        }
        return null;
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

            if (self::isURLLikeAttribute($attributeName)) {
                $safeValue = self::sanitizeURLValue($attributeValue, '');
                // Security (SEC-SANITIZE): also apply the entity-decoding
                // scheme detection so encoded schemes cannot slip past.
                if ($safeValue !== '' && self::hasDisallowedURLScheme($attributeValue)) {
                    $safeValue = '';
                }
                if ($safeValue === '') {
                    $element->removeAttribute($attributeOriginalName);
                }
                else if ($safeValue !== $attributeValue) {
                    $element->setAttribute($attributeOriginalName, $safeValue);
                }
            }
            // Security (SEC-SANITIZE): custom elements consume URLs on
            // arbitrary attribute names; any attribute whose value starts with
            // a script-capable scheme is removed, exactly as a disallowed href is.
            else if (self::hasDangerousURLScheme($attributeValue)) {
                $element->removeAttribute($attributeOriginalName);
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
