<?php
/**
 *
 * @see https://github.com/markdown-it/markdown-it/blob/master/test/token.js
 */
namespace Kaoken\Test\MarkdownIt;

use Kaoken\MarkdownIt\Token;

trait TokenTrait
{
    /**
     * @see test/token.js
     */
    private function token()
    {
        $this->group('Token', function ($g) {
            $g->group('attr', function ($gg) {
                $t = new Token('test_token', 'tok', 1);

                $gg->strictEqual($t->attrs, null);
                $gg->equal($t->attrIndex('foo'), -1);

                $t->attrPush([ 'foo', 'bar' ]);
                $t->attrPush([ 'baz', 'bad' ]);

                $gg->equal($t->attrIndex('foo'), 0);
                $gg->equal($t->attrIndex('baz'), 1);
                $gg->equal($t->attrIndex('none'), -1);
            });
        });
    }
}