<?php
/**
 * The masterminds/html5 parser with two HTML5 rules it leaves out, both
 * met in real page content. Used by SanitizeContent on PHP 8.3, where no
 * HTML5 parser is built in.
 */

/**
 * - A "<" that does not open a tag is the character it is: HTML5 keeps it
 *   as text ("1 < 2", a "<" at the end of the input); the stock tokenizer
 *   drops it.
 * - Raw text (<script>, <style>, <iframe>) and <textarea> / <title> end at
 *   their end tag as HTML5 reads it: the name in any case, then white
 *   space, "/" or ">". The stock tokenizer misses "</script >" and takes
 *   "</textareas" for the end.
 */
class SanitizeHTML5Tokenizer extends \Masterminds\HTML5\Parser\Tokenizer
{
    protected function consumeData()
    {
        // 0: normal text, outside <textarea>, <title> and raw text elements
        if ($this->textMode === 0) {
            $tok = $this->scanner->current();
            if ('&' === $tok) {
                // on its own, so a "<" right after the reference is seen here
                $this->buffer($this->decodeCharacterReference());
                return $this->carryOn;
            }
            if ('<' === $tok) {
                $next = $this->scanner->peek();
                if ($next === false || ($next !== '!' && $next !== '/' && $next !== '?' && !ctype_alpha($next))) {
                    $this->buffer('<');
                    $this->scanner->consume();
                    return $this->carryOn;
                }
            }
        }
        return parent::consumeData();
    }

    protected function rawText($tok)
    {
        if (is_null($this->untilTag)) {
            return $this->text($tok);
        }
        $txt = '';
        while (true) {
            $txt .= $this->scanner->charsUntil('<');
            if (false === $this->scanner->current() || $this->atEndTag()) {
                break;
            }
            $txt .= '<';
            $this->scanner->consume();
        }
        $this->events->text($txt);
        $this->setTextMode(0);

        return $this->endTag();
    }

    protected function rcdata($tok)
    {
        if (is_null($this->untilTag)) {
            return $this->text($tok);
        }
        $txt = '';
        while (false !== ($tok = $this->scanner->current()) && !('<' === $tok && $this->atEndTag())) {
            if ('&' === $tok) {
                $txt .= $this->decodeCharacterReference();
            }
            else {
                $txt .= $tok;
                $this->scanner->consume();
            }
        }
        $this->events->text($txt);
        $this->setTextMode(0);

        return $this->endTag();
    }

    // the scanner is on the "<" of the end tag for the open text element
    private function atEndTag()
    {
        $sequence = '</' . $this->untilTag;
        if (!$this->scanner->sequenceMatches($sequence, false)) {
            return false;
        }
        foreach (array('>', ' ', '/', "\n", "\t", "\f", "\r") as $after) {
            if ($this->scanner->sequenceMatches($sequence . $after, false)) {
                return true;
            }
        }
        return false;
    }
}

/**
 * - A </p> with no <p> open makes an empty paragraph, as HTML5 has it; the
 *   stock tree builder drops the tag.
 * - An end tag's name stops at a "/" ("</title/>" ends the title); the
 *   stock tokenizer hands the "/" over as part of the name.
 */
class SanitizeHTML5TreeBuilder extends \Masterminds\HTML5\Parser\DOMTreeBuilder
{
    public function endTag($name)
    {
        $slash = strpos((string)$name, '/');
        if ($slash !== false && $slash > 0) {
            $name = substr($name, 0, $slash);
        }
        if (strtolower((string)$name) === 'p' && $this->insertMode === static::IM_IN_BODY && !$this->isAncestor('p')) {
            $this->startTag('p');
        }
        return parent::endTag($name);
    }
}
