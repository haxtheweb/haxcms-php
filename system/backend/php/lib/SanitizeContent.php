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
    ];

    private const ALLOWED_PROTOCOLS = [
        'http',
        'https',
        'mailto',
        'tel',
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
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
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
                if ($safeValue === '') {
                    $element->removeAttribute($attributeOriginalName);
                }
                else if ($safeValue !== $attributeValue) {
                    $element->setAttribute($attributeOriginalName, $safeValue);
                }
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
        $wrappedHTML = '<!DOCTYPE html><html><body>' . $html . '</body></html>';
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
