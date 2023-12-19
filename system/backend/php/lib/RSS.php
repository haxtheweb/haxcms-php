<?php
// simple RSS / Atom feed generator from a JSON outline schema object
class FeedMe
{
    /**
     * Generate the RSS 2.0 header
     */
    public function getRSSFeed($site, $domain = "")
    {
        return '<?xml version="1.0" encoding="utf-8"?>
<rss xmlns:atom="http://www.w3.org/2005/Atom" version="2.0">
  <channel>
    <title>' . $site->manifest->title . '</title>
    <link>' . $domain . '</link>
    <description>' . $site->manifest->description . '</description>
    <copyright>Copyright (C) ' .
            date('Y') .
            ' ' .
            $domain .
            '</copyright>
    <language>' .
           $site->getLanguage() .
            '</language>
    <lastBuildDate>' .
            date(\DateTime::RSS, $site->manifest->metadata->site->updated) .
            '</lastBuildDate>
    <atom:link href="' .
            $domain .'" rel="self" type="application/rss+xml"/>' .
            $this->rssItems($site, $domain) .
            '
  </channel>
</rss>';
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
                    $tags = implode(',', $item->metadata->tags);
                }
                else {
                    $tags = $item->metadata->tags;
                }
            }
            if ($count < $limit) {
            $output .=
                '
    <item>
      <title>' .
                $item->title .
                '</title>
      <link>' .
                $domain . $item->slug .
                '</link>
      <description>
          <![CDATA[ ' .
                file_get_contents($siteDirectory . '/' . $item->location) .
                ' ]]>
      </description>
      <category>' .
                $tags .
                '</category>
      <guid>' . $item->id . '</guid>
      <pubDate>' .
                date(\DateTime::RSS, $item->metadata->created) .
                '</pubDate>
    </item>';
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
                file_get_contents($siteDirectory . '/' . $item->location) .
                ' ]]>
    </content>
  </entry>';
            }
            $count++;
        }
        return $output;
    }
}
