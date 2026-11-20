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
 * Parity: a port of haxcms-nodejs src/lib/markdownImport.js. Both render with
 * markdown-it 14 (kaoken/markdown-it-php here, the PHP port of the same
 * engine) with the same options and footnote plugin.
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
        // same engine and options as haxcms-nodejs (markdown-it 14 with
        // html on, linkify off, and the footnote plugin), so both backends
        // render the same HTML
        $parser = new \Kaoken\MarkdownIt\MarkdownIt(array('html' => true, 'linkify' => false));
        // markdown-it escapes & < > " only; PHP 8.1+ htmlspecialchars also
        // escapes ' by default, which the port's code and attribute output
        // inherit. Escape like markdown-it does.
        $parser->renderer = new MarkdownImportRenderer();
        // the port drops a run of text that is just "0" (PHP treats "0" as
        // empty), e.g. a table cell or <kbd>0</kbd>
        $parser->inline = new MarkdownImportParserInline();
        $parser->plugin(new \Kaoken\MarkdownIt\Plugins\MarkdownItFootnote());
        return $parser->render($markdown);
    }
}

/**
 * markdown-it's escaping for the port's code, fence and attribute output. A
 * `&#039;` these write can only come from a `'` (a literal `&` is written as
 * `&amp;`), so turning it back is exact.
 */
class MarkdownImportRules extends \Kaoken\MarkdownIt\Rules\DefaultRules
{
    public function code_inline(array &$tokens, int $idx, object $options, ?object $env, \Kaoken\MarkdownIt\Renderer $slf): string
    {
        return str_replace('&#039;', "'", parent::code_inline($tokens, $idx, $options, $env, $slf));
    }

    public function code_block(array &$tokens, int $idx, object $options, ?object $env, \Kaoken\MarkdownIt\Renderer $slf): string
    {
        return str_replace('&#039;', "'", parent::code_block($tokens, $idx, $options, $env, $slf));
    }

    public function fence(array &$tokens, int $idx, object $options, ?object $env, \Kaoken\MarkdownIt\Renderer $slf): string
    {
        return str_replace('&#039;', "'", parent::fence($tokens, $idx, $options, $env, $slf));
    }
}

class MarkdownImportRenderer extends \Kaoken\MarkdownIt\Renderer
{
    public function __construct()
    {
        parent::__construct();
        $this->rules = new MarkdownImportRules();
    }

    public function renderAttrs(\Kaoken\MarkdownIt\Token $token): string
    {
        return str_replace('&#039;', "'", parent::renderAttrs($token));
    }
}

/**
 * StateInline that flushes pending text "0" (markdown-it checks for a
 * non-empty string; the port's `if ($this->pending)` reads "0" as empty).
 */
class MarkdownImportStateInline extends \Kaoken\MarkdownIt\RulesInline\StateInline
{
    public function push(string $type, string $tag, int $nesting): \Kaoken\MarkdownIt\Token
    {
        if ($this->pending === '0') {
            $this->pushPending();
        }
        return parent::push($type, $tag, $nesting);
    }
}

class MarkdownImportParserInline extends \Kaoken\MarkdownIt\ParserInline
{
    public function tokenize(\Kaoken\MarkdownIt\RulesInline\StateInline &$state)
    {
        parent::tokenize($state);
        if ($state->pending === '0') {
            $state->pushPending();
        }
    }

    public function parse(string $str, \Kaoken\MarkdownIt\MarkdownIt $md, ?object $env, array &$outTokens)
    {
        $state = new MarkdownImportStateInline($str, $md, $env, $outTokens);
        $this->tokenize($state);
        $rules = $this->ruler2->getRules('');
        foreach ($rules as &$rule) {
            if (is_array($rule)) {
                $rule[0]->{$rule[1]}($state);
            } else {
                $rule($state);
            }
        }
    }
}
