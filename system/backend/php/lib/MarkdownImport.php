<?php
/**
 * Markdown (.md / .markdown) and plain text (.txt) to HTML for the import
 * routes.
 *
 * Plain text is read as Markdown: prose comes out as paragraphs, and a .txt
 * that uses `#` headings, lists or links (as many notes do) keeps that
 * structure, so it can become a multi-page site. This matches what the site
 * editor has always done with a .txt dropped on a page (md-to-html).
 *
 * Parity: a port of haxcms-nodejs src/lib/markdownImport.js. Rendering uses
 * Michelf MarkdownExtra set up like the Node side's markdown-it (raw HTML on,
 * footnotes on, `language-` code classes), the same pairing the Quarto and
 * VitePress importers use.
 */
class MarkdownImport
{
    /**
     * Bytes to text: UTF-8 (BOM dropped), or Windows-1252 when the bytes are
     * not valid UTF-8 (older Windows .txt files).
     */
    public static function decodeText($bytes)
    {
        $text = (string) $bytes;
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        if (strncmp($text, "\xEF\xBB\xBF", 3) === 0) {
            $text = substr($text, 3);
        }
        return str_replace(array("\r\n", "\r"), "\n", $text);
    }

    /**
     * Take a leading YAML front matter block off; its `title:` (if any) is
     * returned so a single-page import can use it.
     * @return array array('title' => string, 'body' => string)
     */
    public static function splitFrontMatter($markdown)
    {
        if (!preg_match('/^---[ \t]*\n(.*?)\n(?:---|\.\.\.)[ \t]*(?:\n|\z)/s', $markdown, $match)) {
            return array('title' => '', 'body' => $markdown);
        }
        $title = '';
        if (preg_match('/^title:[ \t]*(.+?)[ \t]*$/m', $match[1], $titleLine)) {
            $title = preg_replace('/^(["\'])(.*)\1\z/', '$2', $titleLine[1]);
            $title = trim($title);
        }
        return array('title' => $title, 'body' => (string) substr($markdown, strlen($match[0])));
    }

    public static function render($markdown)
    {
        $parser = new \Michelf\MarkdownExtra();
        $parser->code_class_prefix = 'language-';
        $parser->empty_element_suffix = '>';
        return $parser->transform($markdown);
    }
}
