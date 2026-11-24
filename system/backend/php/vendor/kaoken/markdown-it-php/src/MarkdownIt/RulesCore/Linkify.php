<?php
namespace Kaoken\MarkdownIt\RulesCore;

use Kaoken\MarkdownIt\Common\Utils;

class Linkify
{
    /**
     * @param $str
     * @return false|int
     */
    protected function isLinkOpen(&$str) {
        return preg_match("/^<a[>\s]/i", $str);
    }

    /**
     * @param $str
     * @return false|int
     */
    protected function isLinkClose(&$str) {
        return preg_match("/^<\/a\s*>/i", $str);
    }

    /**
     * @param StateCore $state
     */
    public function set(StateCore &$state): void
    {
        if (!$state->md->options->linkify) { return; }

        foreach( $state->tokens as &$blockToken ){
            if ( $blockToken->type !== 'inline' ||
                !$state->md->linkify->pretest( $blockToken->content)) {
                continue;
            }

            $tokens = &$blockToken->children;

            $htmlLinkLevel = 0;

            // We scan from the end, to keep position when new tags added.
            // Use reversed logic in $links start/end match
            for ($i = count($tokens) - 1; $i >= 0; $i--) {
                $currentToken = &$tokens[$i];

                // Skip content of markdown $links
                if ($currentToken->type === 'link_close') {
                    $i--;
                    while ($tokens[$i]->level !== $currentToken->level && $tokens[$i]->type !== 'link_open') {
                        $i--;
                    }
                    continue;
                }

                // Skip content of html tag $links
                if ($currentToken->type === 'html_inline') {
                    if ($this->isLinkOpen($currentToken->content) && $htmlLinkLevel > 0) {
                        $htmlLinkLevel--;
                    }
                    if ($this->isLinkClose($currentToken->content)) {
                        $htmlLinkLevel++;
                    }
                }
                if ($htmlLinkLevel > 0) { continue; }

                if ($currentToken->type === 'text' && $state->md->linkify->test($currentToken->content)) {

                    $text = $currentToken->content;
                    $links = $state->md->linkify->match($text);

                    // Now split string to nodes
                    $nodes = [];
                    $level = $currentToken->level;
                    $lastPos = 0;

                    // forbid escape sequence at the start of the string,
                    // this avoids http\://example.com/ from being linkified as
                    // http:<a href="//example.com/">//example.com/</a>
                    if (count($links) > 0 &&
                        $links[0]->index === 0 &&
                        $i > 0 &&
                        $tokens[$i - 1]->type === 'text_special') {
                        $links = array_slice($links, 1);
                    }

                    foreach( $links as &$link ) {

                        $url = $link->url;
                        $fullUrl = $state->md->normalizeLink($url);
                        if (!$state->md->validateLink($fullUrl)) { continue; }

                        $urlText = $link->text;

                        // Linkifier might send raw hostnames like "example.com", where $url
                        // starts with domain name. So we prepend http:// in those cases,
                        // and remove it afterwards.
                        //
                        if (!$link->schema) {
                            $urlText = preg_replace("/^http:\/\//", '', $state->md->normalizeLinkText('http://' . $urlText));
                        } else if ($link->schema === 'mailto:' && !preg_match("/^mailto:/i", $urlText)) {
                            $urlText = preg_replace("/^mailto:/", '', $state->md->normalizeLinkText('mailto:' . $urlText));
                        } else {
                            $urlText = $state->md->normalizeLinkText($urlText);
                        }

                        $pos = $link->index;

                        if ($pos > $lastPos) {
                            $token         = $state->createToken('text', '', 0);
                            $token->content = substr($text, $lastPos, $pos-$lastPos);
                            $token->level   = $level;
                            $nodes[] = $token;
                        }

                        $token_o            = $state->createToken('link_open', 'a', 1);
                        $token_o->attrs     = [ [ 'href', $fullUrl ] ];
                        $token_o->level     = $level++;
                        $token_o->markup    = 'linkify';
                        $token_o->info      = 'auto';
                        $nodes[] = $token_o;

                        $token_t            = $state->createToken('text', '', 0);
                        $token_t->content   = $urlText;
                        $token_t->level     = $level;
                        $nodes[] = $token_t;

                        $token_c            = $state->createToken('link_close', 'a', -1);
                        $token_c->level     = --$level;
                        $token_c->markup    = 'linkify';
                        $token_c->info      = 'auto';
                        $nodes[] = $token_c;

                        $lastPos = $link->lastIndex;
                    }
                    if ($lastPos < strlen ($text)) {
                        $token         = $state->createToken('text', '', 0);
                        $token->content = substr($text,$lastPos);
                        $token->level   = $level;
                        $nodes[] = $token;
                    }

                    // replace current node
                    $blockToken->children = $state->md->utils->arrayReplaceAt($tokens, $i, $nodes);
                }
            }
        }
    }
}