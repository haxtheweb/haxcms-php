<?php
/**
 * Copyright (c) 2014 Vitaly Puzrin.
 *
 * This software is released under the MIT License.
 * http://opensource.org/licenses/mit-license.php
 */
/**
 * Copyright (c) 2016 kaoken
 *
 * This software is released under the MIT License.
 * http://opensource.org/licenses/mit-license.php
 *
 *
 * use javascript version 2.0.1
 * @see https://github.com/markdown-it/markdown-it-for-inline/tree/2.0.1
 */
namespace Kaoken\MarkdownIt\Plugins;


use Exception;
use Kaoken\MarkdownIt\MarkdownIt;

class MarkdownItForInline
{
    /**
     * MarkdownItForInline constructor.
     * @param MarkdownIt $md
     * @param string $ruleName
     * @param string $tokenType
     * @param callable $iteartor
     * @throws Exception
     */
    public function plugin(MarkdownIt $md, string $ruleName, string $tokenType, callable $iteartor)
    {
        $scan = function($state) use($md, $ruleName, $tokenType, $iteartor) {
            for ($blkIdx = count($state->tokens) - 1; $blkIdx >= 0; $blkIdx--) {
                if ($state->tokens[$blkIdx]->type !== 'inline') {
                    continue;
                }

                $inlineTokens = $state->tokens[$blkIdx]->children;

                for ($i = count($inlineTokens) - 1; $i >= 0; $i--) {
                    if ($inlineTokens[$i]->type !== $tokenType) {
                        continue;
                    }

                    $iteartor($inlineTokens, $i);
                }
            }
        };

        $md->core->ruler->push($ruleName, $scan);
    }
}