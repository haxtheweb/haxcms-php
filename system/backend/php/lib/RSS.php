<?php
// simple RSS / Atom feed generator from a JSON outline schema object
class FeedMe
{
    /**
     * Escape text for XML contexts.
     */
    private function xmlEscape($value = '')
    {
        return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
    /**
     * Normalize timestamps that may be unix seconds, milliseconds, or parseable dates.
     */
    private function normalizeTimestamp($value = null)
    {
        if ($value === null || $value === '') {
            return time();
        }
        if (is_numeric($value)) {
            $numeric = (int) $value;
            if ($numeric > 1000000000000) {
                return (int) floor($numeric / 1000);
            }
            return $numeric;
        }
        $parsed = strtotime((string) $value);
        if ($parsed !== false) {
            return $parsed;
        }
        return time();
    }
    /**
     * Generate the RSS 2.0 header
     */
    public function getRSSFeed($site, $domain = "")
    {
        // Ensure we have a proper domain
        if (empty($domain)) {
            if (isset($site->manifest->metadata->site->domain) && !empty($site->manifest->metadata->site->domain)) {
                $domain = $site->manifest->metadata->site->domain;
            } else {
                // fallback domain construction
                $fallbackDomain = $GLOBALS['HAXCMS']->getDomain();
                if (empty($fallbackDomain)) {
                    // CLI fallback or when SERVER_NAME is not available - use root path
                    $domain = "/sites/" . $site->manifest->metadata->site->name . "/";
                } else {
                    $fallbackDomain = str_replace('iam.','oer.', $fallbackDomain);
                    // Ensure we have a protocol
                    if (!preg_match('/^https?:\/\//', $fallbackDomain)) {
                        $fallbackDomain = 'https://' . $fallbackDomain;
                    }
                    $domain = rtrim($fallbackDomain, '/') . "/sites/" . $site->manifest->metadata->site->name . "/";
                }
            }
        }
        // Ensure domain ends with /
        if (!empty($domain) && substr($domain, -1) !== '/') {
            $domain .= '/';
        }
        // Strip HTML from description
        $description = strip_tags($site->manifest->description ?? '');
        // Generate categories from site tags if available
        $categories = '';
        if (isset($site->manifest->metadata->tags) && is_array($site->manifest->metadata->tags)) {
            foreach ($site->manifest->metadata->tags as $tag) {
                if (!empty(trim($tag))) {
                    $categories .= "\n    <category>" . $this->xmlEscape(trim($tag)) . "</category>";
                }
            }
        }
        // Only add copyright if we have a domain
        $copyright = '';
        if (!empty($domain)) {
            $copyright = "\n    <copyright>Copyright (C) " . date('Y') . ' ' . $this->xmlEscape(rtrim($domain, '/')) . "</copyright>";
        }
        // Add generator element per RSS spec
        $generator = "\n    <generator>HAXcms PHP</generator>";
        return '<?xml version="1.0" encoding="utf-8"?>
<rss xmlns:atom="http://www.w3.org/2005/Atom" version="2.0">
  <channel>
    <title>' . $this->xmlEscape($site->manifest->title ?? '') . '</title>
    <link>' . $this->xmlEscape($domain) . '</link>
    <description>' . $this->xmlEscape($description) . '</description>' .
            $copyright .
            "\n    <language>" . $this->xmlEscape($site->getLanguage()) . "</language>
    <lastBuildDate>" .
            date(\DateTime::RSS, $this->normalizeTimestamp($site->manifest->metadata->site->updated ?? null)) .
            '</lastBuildDate>' .
            $generator .
            $categories .
            "\n    <atom:link href=\"" .
            $this->xmlEscape($domain . 'rss.xml') . "\" rel=\"self\" type=\"application/rss+xml\"/>" .
            $this->rssItems($site, $domain) .
            "\n  </channel>
</rss>";
    }
    /**
     * Generate RSS items.
     */
    public function rssItems($site, $domain, $limit = 25)
    {
        $output = '';
        $count = 0;
        $items = $site->sortItems('created');
        $siteDirectory = $site->directory . '/' . $site->manifest->metadata->site->name;
        // Ensure domain is properly formatted
        if (!empty($domain) && substr($domain, -1) !== '/') {
            $domain .= '/';
        }
        foreach ($items as $key => $item) {
            // beyond edge but don't want this to error on write
            if (!isset($item->metadata)) {
              $item->metadata = new stdClass();
            }
            if (!isset($item->metadata->created)) {
              $item->metadata->created = time();
              $item->metadata->updated = time();
            }
            if ($count < $limit) {
                // Build absolute link
                $itemLink = $domain . ltrim((string) $item->slug, '/');
                // Handle categories properly - create separate category elements
                $categoryElements = '';
                if (isset($item->metadata->tags) && !empty($item->metadata->tags)) {
                    if (is_array($item->metadata->tags)) {
                        foreach ($item->metadata->tags as $tag) {
                            $tag = trim((string) $tag);
                            if (!empty($tag)) {
                                $categoryElements .= "\n      <category>" . $this->xmlEscape($tag) . "</category>";
                            }
                        }
                    } else if (is_string($item->metadata->tags)) {
                        $tag = trim($item->metadata->tags);
                        if (!empty($tag)) {
                            $categoryElements .= "\n      <category>" . $this->xmlEscape($tag) . "</category>";
                        }
                    }
                }
                // Read and clean content for description
                $contentPath = $siteDirectory . '/' . str_replace('./', '', str_replace('../', '', (string) $item->location));
                $content = '';
                if (file_exists($contentPath)) {
                    $rawContent = file_get_contents($contentPath);
                    // Strip all HTML and web component tags, decode entities, and clean up whitespace
                    $content = html_entity_decode(strip_tags($rawContent), ENT_HTML5, 'UTF-8');
                    $content = preg_replace('/\s+/', ' ', $content); // normalize whitespace
                    $content = trim($content);
                    // Truncate if too long (RSS readers prefer shorter descriptions)
                    if (strlen($content) > 500) {
                        $content = substr($content, 0, 497) . '...';
                    }
                }
                // Use absolute URL for GUID to make it valid
                $guid = $itemLink;
                $output .= "\n    <item>" .
                    "\n      <title>" . $this->xmlEscape($item->title ?? '') . "</title>" .
                    "\n      <link>" . $this->xmlEscape($itemLink) . "</link>" .
                    "\n      <description>" . $this->xmlEscape($content) . "</description>" .
                    $categoryElements .
                    "\n      <guid>" . $this->xmlEscape($guid) . "</guid>" .
                    "\n      <pubDate>" . date(\DateTime::RSS, $this->normalizeTimestamp($item->metadata->created ?? null)) . "</pubDate>" .
                    "\n    </item>";
            }
            $count++;
        }
        return $output;
    }
    /**
     * Generate the atom feed
     */
    public function getAtomFeed($site, $domain = "")
    {
        if (!empty($domain) && substr($domain, -1) !== '/') {
            $domain .= '/';
        }
        $updated = $this->normalizeTimestamp($site->manifest->metadata->site->updated ?? null);
        $title = $this->xmlEscape($site->manifest->title ?? '');
        $subtitle = $this->xmlEscape($site->manifest->description ?? '');
        $authorName = '';
        if (isset($site->manifest->author) && !empty($site->manifest->author)) {
            $authorName = $site->manifest->author;
        } else if (isset($site->manifest->metadata->author->name)) {
            $authorName = $site->manifest->metadata->author->name;
        }
        $authorName = $this->xmlEscape($authorName);
        $safeDomain = $this->xmlEscape($domain);
        return '<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <title>' . $title . '</title>
  <link href="' . $safeDomain . '" rel="self" />
  <subtitle>' . $subtitle . '</subtitle>
  <updated>' . date(\DateTime::ATOM, $updated) . '</updated>
  <author>
      <name>' . $authorName . '</name>
  </author>
  <id>' . $safeDomain . '</id>' .
            $this->atomItems($site, $domain) .
            '
</feed>';
    }
    /**
     * Generate Atom items.
     */
    public function atomItems($site, $domain, $limit = 25)
    {
        $output = '';
        $count = 0;
        $items = $site->sortItems('created');
        $siteDirectory = $site->directory . '/' . $site->manifest->metadata->site->name;
        if (!empty($domain) && substr($domain, -1) !== '/') {
            $domain .= '/';
        }
        foreach ($items as $key => $item) {
            $tags = '';
            // beyond edge but don't want this to erorr on write
            if (!isset($item->metadata)) {
              $item->metadata = new stdClass();
            }
            if (!isset($item->metadata->created)) {
              $item->metadata->created = time();
              $item->metadata->updated = time();
            }
            if (isset($item->metadata->tags)) {
                if (is_array($item->metadata->tags)) {
                    foreach ($item->metadata->tags as $tag) {
                        $tag = trim((string) $tag);
                        if ($tag !== '') {
                            $tags .= '<category term="' . $this->xmlEscape($tag) . '" label="' . $this->xmlEscape($tag) . '" />';
                        }
                    }
                } else if (is_string($item->metadata->tags)) {
                    $tag = trim($item->metadata->tags);
                    if ($tag !== '') {
                        $tags .= '<category term="' . $this->xmlEscape($tag) . '" label="' . $this->xmlEscape($tag) . '" />';
                    }
                }
            }
            if ($count < $limit) {
                $itemLink = $domain . ltrim((string) $item->slug, '/');
                $safeLocation = str_replace('./', '', str_replace('../', '', (string) $item->location));
                $itemContent = '';
                if ($safeLocation != '' && file_exists($siteDirectory . '/' . $safeLocation)) {
                    $itemContent = file_get_contents($siteDirectory . '/' . $safeLocation);
                    $itemContent = str_replace(']]>', ']]]]><![CDATA[>', (string) $itemContent);
                }
                $output .=
                    '\n  <entry>
    <title>' .
                    $this->xmlEscape($item->title ?? '') .
                    '</title>
    <id>' .
                    $this->xmlEscape($item->id ?? $itemLink) .
                    '</id>
    <updated>' .
                    date(\DateTime::ATOM, $this->normalizeTimestamp($item->metadata->updated ?? null)) .
                    '</updated>
    <published>' .
                    date(\DateTime::ATOM, $this->normalizeTimestamp($item->metadata->created ?? null)) .
                    '</published>
    <summary>' .
                    $this->xmlEscape($item->description ?? '') .
                    '</summary>
    <link href="' .
                    $this->xmlEscape($itemLink) .
                    '"/>
    ' .
                    $tags .
                    '\n    <content type="html">
      <![CDATA[ ' .
                    $itemContent .
                    ' ]]>
    </content>
  </entry>';
            }
            $count++;
        }
        return $output;
    }
}
