<?php
// simple RSS / Atom feed generator from a JSON outline schema object
class FeedMe
{
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
                    $categories .= "\n    <category>" . htmlspecialchars(trim($tag), ENT_XML1, 'UTF-8') . "</category>";
                }
            }
        }
        
        // Only add copyright if we have a domain
        $copyright = '';
        if (!empty($domain)) {
            $copyright = "\n    <copyright>Copyright (C) " . date('Y') . ' ' . rtrim($domain, '/') . "</copyright>";
        }
        
        // Add generator element per RSS spec
        $generator = "\n    <generator>HAXcms PHP</generator>";
        
        return '<?xml version="1.0" encoding="utf-8"?>
<rss xmlns:atom="http://www.w3.org/2005/Atom" version="2.0">
  <channel>
    <title>' . htmlspecialchars($site->manifest->title ?? '', ENT_XML1, 'UTF-8') . '</title>
    <link>' . htmlspecialchars($domain, ENT_XML1, 'UTF-8') . '</link>
    <description>' . htmlspecialchars($description, ENT_XML1, 'UTF-8') . '</description>' .
            $copyright .
            "\n    <language>" . htmlspecialchars($site->getLanguage(), ENT_XML1, 'UTF-8') . "</language>
    <lastBuildDate>" .
            date(\DateTime::RSS, $site->manifest->metadata->site->updated) .
            '</lastBuildDate>' .
            $generator .
            $categories .
            "\n    <atom:link href=\"" .
            htmlspecialchars($domain . 'rss.xml', ENT_XML1, 'UTF-8') .'" rel="self" type="application/rss+xml"/>' .
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
                $itemLink = $domain . ltrim($item->slug, '/');
                
                // Handle categories properly - create separate category elements
                $categoryElements = '';
                if (isset($item->metadata->tags) && !empty($item->metadata->tags)) {
                    if (is_array($item->metadata->tags)) {
                        foreach ($item->metadata->tags as $tag) {
                            $tag = trim($tag);
                            if (!empty($tag)) {
                                $categoryElements .= "\n      <category>" . htmlspecialchars($tag, ENT_XML1, 'UTF-8') . "</category>";
                            }
                        }
                    } else if (is_string($item->metadata->tags)) {
                        $tag = trim($item->metadata->tags);
                        if (!empty($tag)) {
                            $categoryElements .= "\n      <category>" . htmlspecialchars($tag, ENT_XML1, 'UTF-8') . "</category>";
                        }
                    }
                }
                
                // Read and clean content for description
                $contentPath = $siteDirectory . '/' . str_replace('./', '', str_replace('../', '', $item->location));
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
                    "\n      <title>" . htmlspecialchars($item->title ?? '', ENT_XML1, 'UTF-8') . "</title>" .
                    "\n      <link>" . htmlspecialchars($itemLink, ENT_XML1, 'UTF-8') . "</link>" .
                    "\n      <description>" . htmlspecialchars($content, ENT_XML1, 'UTF-8') . "</description>" .
                    $categoryElements .
                    "\n      <guid>" . htmlspecialchars($guid, ENT_XML1, 'UTF-8') . "</guid>" .
                    "\n      <pubDate>" . date(\DateTime::RSS, $item->metadata->created) . "</pubDate>" .
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
        return '<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <title>' .
            $site->manifest->title .
            '</title>
  <link href="' .
            $domain . '" rel="self" />
  <subtitle>' .
            $site->manifest->description .
            '</subtitle>
  <updated>' .
            date(\DateTime::ATOM, $site->manifest->metadata->site->updated) .
            '</updated>
  <author>
      <name>' .
            $site->manifest->author .
            '</name>
  </author>
  <id>' .
            $domain . '</id>' .
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
                foreach ($item->metadata->tags as $tag) {
                    $tags .=
                        '<category term="' . $tag . '" label="' . $tag . '" />';
                }
            }
            if ($count < $limit) {
            $output .=
                '
  <entry>
    <title>' .
                $item->title .
                '</title>
    <id>' .
                $item->id .
                '</id>
    <updated>' .
                date(\DateTime::ATOM, $item->metadata->updated) .
                '</updated>
    <published>' .
                date(\DateTime::ATOM, $item->metadata->created) .
                '</published>
    <summary>' .
                $item->description .
                '</summary>
    <link href="' .
                $domain . $item->slug .
                '"/>
    ' .
                $tags .
                '
    <content type="html">
      <![CDATA[ ' .
                file_get_contents($siteDirectory . '/' . str_replace('./', '', str_replace('../', '', $item->location))) .
                ' ]]>
    </content>
  </entry>';
            }
            $count++;
        }
        return $output;
    }
}
