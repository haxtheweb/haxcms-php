<?php
class SanitizeContent
{
    public static function sanitizeURLValue($value, $fallback = '')
    {
        if ($value === null) {
            return $fallback;
        }
        $stringValue = trim((string)$value);
        if ($stringValue === '') {
            return $fallback;
        }
        if (preg_match('/^\s*(javascript|vbscript|data\s*:\s*text\/html|data\s*:\s*application\/xhtml\+xml)\s*:/i', $stringValue)) {
            return $fallback;
        }
        return $stringValue;
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

    public static function sanitizeHTMLForStorage($html)
    {
        if (!is_string($html)) {
            return '';
        }
        $clean = $html;
        $clean = preg_replace('/<script\b[^>]*>[\s\S]*?<\/script>/i', '', $clean);
        $clean = preg_replace('/(\s+|(?<=[\"\']))srcdoc\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/i', '$1', $clean);
        $clean = preg_replace('/(\s+|(?<=[\"\']))on[a-z0-9_-]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/i', '$1', $clean);
        $clean = preg_replace_callback(
            '/(\s+|(?<=[\"\']))([a-zA-Z_:][a-zA-Z0-9_.:-]*)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/',
            function ($matches) {
                $attributeBoundary = $matches[1];
                $attributeName = strtolower($matches[2]);
                if (preg_match('/^on[a-z0-9_-]+$/i', $attributeName)) {
                    return $attributeBoundary;
                }
                $value = '';
                if (isset($matches[4]) && $matches[4] !== '') {
                    $value = $matches[4];
                }
                else if (isset($matches[5]) && $matches[5] !== '') {
                    $value = $matches[5];
                }
                else if (isset($matches[6])) {
                    $value = $matches[6];
                }
                if (
                    preg_match('/(?:^|[-_:])(?:href|src|action|formaction|poster|data|url)(?:$|[-_:])/i', $attributeName) &&
                    self::sanitizeURLValue($value, '') === ''
                ) {
                    return $attributeBoundary;
                }
                return $matches[0];
            },
            $clean
        );
        $clean = preg_replace_callback(
            '/(<template\b[^>]*>)([\s\S]*?)(<\/template>)/i',
            function ($matches) {
                return $matches[1] . self::sanitizeHTMLForStorage($matches[2]) . $matches[3];
            },
            $clean
        );
        return $clean;
    }
}
