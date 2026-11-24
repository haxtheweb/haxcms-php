<?php
/**
 * @see https://github.com/markdown-it/markdown-it/blob/master/test/misc.js
 */
namespace Kaoken\Test\MarkdownIt;

use Kaoken\MarkdownIt\MarkdownIt;
use Kaoken\MarkdownIt\Plugins\MarkdownItForInline;

trait MiscTrait
{
    private function misc()
    {
        $this->group("Misc", function($g){
            $g->group("API", function ($gg) { $this->miscAPI($gg); });
            $g->group("Plugins", function ($gg) { $this->miscPlugins($gg); });
            $g->group("Misc", function ($gg) { $this->miscMisc($gg); });
            $g->group("Url normalization", function ($gg) { $this->miscUrlNormalization($gg); });
            $g->group("Links validation", function ($gg) { $this->miscLinksValidation($gg); });
            $g->group("maxNesting", function ($gg) { $this->miscMaxNesting($gg); });
            $g->group("smartquotes", function ($gg) { $this->miscSmartquotes($gg); });;
            $g->group("Ordered list info", function ($gg) { $this->miscOrderedListInfo($gg); });
            $g->group("Token attributes", function ($gg) { $this->miscTokenAttributes($gg); });
        });
    }
    private function miscAPI($g)
    {
        $g->group("constructor", function ($gg) {
            $gg->throws(function () {
                new MarkdownIt("bad preset");
            });

            // options should override preset
            $md = new MarkdownIt("commonmark", ["html" => false]);
            $gg->strictEqual($md->render("<!-- -->"), "<p>&lt;!-- --&gt;</p>\n");
        });
        //-------------------------------------------------------------------
        $g->group("configure coverage", function ($gg) {
            $md = new MarkdownIt();

            // conditions coverage
            $md->configure([]);
            $gg->strictEqual($md->render("123"), "<p>123</p>\n");
            $gg->strictEqual($md->render("123\n"), "<p>123</p>\n");

            $gg->strictEqual($md->render("    codeblock"), "<pre><code>codeblock\n</code></pre>\n");
            $gg->strictEqual($md->render("    codeblock\n"), "<pre><code>codeblock\n</code></pre>\n");
            $gg->throws(function () use($md) {
                $md->configure();
            });
        });
        //-------------------------------------------------------------------
        $g->group("plugin", function ($gg) {
            $succeeded = false;

            $plugin = function($slf, $opts) use(&$succeeded) {
                if ($opts === "bar") { $succeeded = true; }
            };

            $md = new MarkdownIt();

            $md->plugin($plugin, "foo");
            $gg->strictEqual($succeeded, false);
            $md->plugin($plugin, "bar");
            $gg->strictEqual($succeeded, true);
        });
        //-------------------------------------------------------------------
        $g->group("highlight", function ($gg) {
            $md = new MarkdownIt([
                "highlight"=> function ($str) {
                    return "<pre><code>==" . $str . "==</code></pre>";
                }
            ]);

            $gg->strictEqual($md->render("```\nhl\n```"), "<pre><code>==hl\n==</code></pre>\n");
        });
        //-------------------------------------------------------------------
        $g->group("highlight escape by default", function ($gg) {
            $md = new MarkdownIt([
                "highlight"=> function ($str) {
                    return '';
                }
            ]);
            $gg->strictEqual($md->render("```\n&\n```"), "<pre><code>&amp;\n</code></pre>\n");
        });
        //-------------------------------------------------------------------
        $g->group("highlight arguments", function ($gg) {
            $md = new MarkdownIt([
                "highlight"=> function ($str, $lang, $attrs) use($gg) {
                    $gg->strictEqual($lang, "a");
                    $gg->strictEqual($attrs, "b  c  d");
                    return "<pre><code>==" . $str . "==</code></pre>";
                }
            ]);

            $gg->strictEqual($md->render("``` a  b  c  d \nhl\n```"), "<pre><code>==hl\n==</code></pre>\n");
        });
        //-------------------------------------------------------------------
        $g->group("force hardbreaks", function ($gg) {
            $md = new MarkdownIt([ "breaks" => true ]);

            $gg->strictEqual($md->render("a\nb"), "<p>a<br>\nb</p>\n");
            $md->set([ "xhtmlOut"=> true ]);
            $gg->strictEqual($md->render("a\nb"), "<p>a<br />\nb</p>\n");
        });
        //-------------------------------------------------------------------
        $g->group("xhtmlOut enabled", function ($gg) {
            $md = new MarkdownIt([ "xhtmlOut"=> true ]);

            $gg->strictEqual($md->render("---"), "<hr />\n");
            $gg->strictEqual($md->render("![]()"), "<p><img src=\"\" alt=\"\" /></p>\n");
            $gg->strictEqual($md->render("a  \\\nb"), "<p>a  <br />\nb</p>\n");
        });
        //-------------------------------------------------------------------
        $g->group("xhtmlOut disabled", function ($gg) {
            $md = new MarkdownIt();

            $gg->strictEqual($md->render("---"), "<hr>\n");
            $gg->strictEqual($md->render("![]()"), "<p><img src=\"\" alt=\"\"></p>\n");
            $gg->strictEqual($md->render("a  \\\nb"), "<p>a  <br>\nb</p>\n");
        });
        //-------------------------------------------------------------------
        $g->group("bulk enable/disable rules in different chains", function ($gg) {
            $md = new MarkdownIt();

            $was = new \stdClass();
            $was->core = count($md->core->ruler->getRules(''));
            $was->block = count($md->block->ruler->getRules(''));
            $was->inline = count($md->inline->ruler->getRules(''));

            // Disable 2 rule in each chain & compare result
            $md->disable([ "block", "inline", "code", "fence", "emphasis", "entity" ]);

            $now = new \stdClass();
            $now->core = count($md->core->ruler->getRules('')) + 2;
            $now->block = count($md->block->ruler->getRules('')) + 2;
            $now->inline = count($md->inline->ruler->getRules('')) + 2;

            $gg->deepEqual($was, $now);

            // Enable the same rules back
            $md->enable([ "block", "inline", "code", "fence", "emphasis", "entity" ]);

            $back = new \stdClass();
            $back->core = count($md->core->ruler->getRules(''));
            $back->block = count($md->block->ruler->getRules(''));
            $back->inline = count($md->inline->ruler->getRules(''));

            $gg->deepEqual($was, $back);
        });
        //-------------------------------------------------------------------
        $g->group("bulk enable/disable with errors control", function ($gg) {
            $md = new MarkdownIt();

            $gg->throws(function () use(&$md) {
                $md->enable([ "link", "code", "invalid" ]);
            });
            $gg->throws(function () use(&$md) {
                $md->disable([ "link", "code", "invalid" ]);
            });
            $gg->doesNotThrow(function () use(&$md) {
                $md->enable([ "link", "code" ]);
            });
            $gg->doesNotThrow(function () use(&$md) {
                $md->disable([ "link", "code" ]);
            });
        });
        //-------------------------------------------------------------------
        $g->group("bulk enable/disable should understand strings", function ($gg) {
            $md = new MarkdownIt();

            $md->disable("emphasis");
            $gg->strictEqual($md->renderInline("_foo_"), "_foo_");

            $md->enable("emphasis");
            $gg->strictEqual($md->renderInline("_foo_"), "<em>foo</em>");
        });
        //-------------------------------------------------------------------
        $g->group("input type check", function ($gg) {
            $md = new MarkdownIt();
            $gg->throws(function () use(&$md) {
                $md->render(null);
            },'Input data should be a String');
        });
    }

    private function miscPlugins($g)
    {
        $g->group('should not loop infinitely if all rules are disabled', function ($gg) {

            $md = new MarkdownIt();

            $md->inline->ruler->enableOnly([]);
            $md->inline->ruler2->enableOnly([]);
            $md->block->ruler->enableOnly([]);

            $gg->throws(function () use(&$md) {
                $md->render(' - *foo*\n - `bar`');
            },'none of the block rules matched');
        });
        //-------------------------------------------------------------------
        $g->group("should not loop infinitely if inline rule doesn't increment pos", function ($gg) {
            $md = new MarkdownIt();
            $md->inline->ruler->after('text', 'custom', function (&$state/*, silent*/) {
                if ($state->src[$state->pos] !== '@') return false;
                return true;
            });

            $gg->throws(function () use(&$md) {
                $md->render('foo@bar');
            },"inline rule didn't increment state.pos");
            $gg->throws(function () use(&$md) {
                $md->render('[foo@bar]()');
            },"inline rule didn't increment state.pos");
        });
        //-------------------------------------------------------------------
        $g->group("should not loop infinitely if block rule doesn't increment pos", function ($gg) {
            $md = new MarkdownIt();
            $md->block->ruler->before('paragraph', 'custom', function (&$state, $startLine/*, $endLine, $silent*/) {
                $pos = $state->bMarks[$startLine] + $state->tShift[$startLine];
                if ($state->src[$pos] !== '@') return false;
                return true;
            }, [ 'alt' => [ 'paragraph' ] ]);

            $gg->throws(function () use(&$md) {
                $md->render("foo\n@bar\nbaz");
            },"block rule didn't increment state.line");
            $gg->throws(function () use(&$md) {
                $md->render("foo\n\n@bar\n\nbaz");
            },"block rule didn't increment state.line");
        });
    }

    private function miscMisc($g)
    {
        $g->group("Should replace NULL characters", function ($gg) {

            $md = new MarkdownIt();

            $gg->strictEqual($md->render(json_decode('"foo\u0000bar"')), json_decode('"<p>foo\uFFFDbar</p>\n"'));
        });
        //-------------------------------------------------------------------
        $g->group("Should correctly parse strings without tailing \\n", function ($gg) {
            $md = new MarkdownIt();

            $gg->strictEqual($md->render("123"), "<p>123</p>\n");
            $gg->strictEqual($md->render("123\n"), "<p>123</p>\n");
        });
        //-------------------------------------------------------------------
        $g->group("Should quickly exit on empty string", function ($gg) {
            $md = new MarkdownIt();

            $gg->strictEqual($md->render(''), "");
        });
        //-------------------------------------------------------------------
        $g->group("Should parse inlines only", function ($gg) {
            $md = new MarkdownIt();

            $gg->strictEqual($md->renderInline("a *b* c"), "a <em>b</em> c");
        });
        //-------------------------------------------------------------------
        $g->group("Renderer should have pluggable inline and block rules", function ($gg) {
            $md = new MarkdownIt();

            $md->renderer->rules->em_open = function () { return '<it>'; };
            $md->renderer->rules->em_close = function () { return '</it>'; };
            $md->renderer->rules->paragraph_open = function () { return '<par>'; };
            $md->renderer->rules->paragraph_close = function () { return '</par>'; };

            $gg->strictEqual($md->render("*b*"), "<par><it>b</it></par>");
        });
        //-------------------------------------------------------------------
        $g->group("Zero preset should disable everything", function ($gg) {
            $md = new MarkdownIt("zero");

            $gg->strictEqual($md->render("___foo___"), "<p>___foo___</p>\n");
            $gg->strictEqual($md->renderInline("___foo___"), "___foo___");

            $md->enable("emphasis");

            $gg->strictEqual($md->render("___foo___"), "<p><em><strong>foo</strong></em></p>\n");
            $gg->strictEqual($md->renderInline("___foo___"), "<em><strong>foo</strong></em>");
        });
        //-------------------------------------------------------------------
        $g->group("Should correctly check block termination rules when those are disabled (#13)", function ($gg) {
            $md = new MarkdownIt("zero");

            $gg->strictEqual($md->render("foo\nbar"), "<p>foo\nbar</p>\n");
        });
        //-------------------------------------------------------------------
        $g->group("Should render link target attr", function ($gg) {
            $md = (new MarkdownIt())
                ->plugin(new MarkdownItForInline(), "target", "link_open", function ($tokens, $idx) {
                    $tokens[$idx]->attrs[] = [ "target", "_blank" ];
                });

            $gg->strictEqual($md->render("[foo](bar)"), "<p><a href=\"bar\" target=\"_blank\">foo</a></p>\n");
        });
        //-------------------------------------------------------------------
        $g->group("Should normalize CR to LF", function ($gg) {
            $md = new MarkdownIt();

            $gg->strictEqual(
                $md->render("# test\r\r - hello\r - world\r"),
                $md->render("# test\n\n - hello\n - world\n")
            );
        });
        //-------------------------------------------------------------------
        $g->group("Should normalize CR+LF to LF", function ($gg) {
            $md = new MarkdownIt();

            $gg->strictEqual(
                $md->render("# test\r\n\r\n - hello\r\n - world\r\n"),
                $md->render("# test\n\n - hello\n - world\n")
            );
        });
        //-------------------------------------------------------------------
        $g->group("Should escape surrogate pairs (coverage)", function ($gg) {
            $md = new MarkdownIt();

            $gg->strictEqual($md->render("\\".json_decode('"\uD835\uDC9C"')), "<p>\\".json_decode('"\uD835\uDC9C"')."</p>\n");
            $gg->strictEqual($md->render("\\".json_decode('"\uD835x"')), "<p>\\".json_decode('"\uD835x"')."</p>\n");
            $gg->strictEqual($md->render("\\".json_decode('"\uD835"')), "<p>\\".json_decode('"\uD835"')."</p>\n");
        });
    }
    private function miscUrlNormalization($g)
    {
        $g->group("Should be overridable", function ($gg) {
            $md = new MarkdownIt([ "linkify"=> true ]);

            $md->normalizeLink = function ($url) use($gg) {
                $gg->ok(preg_match("/example\.com/", $url), "wrong url passed");
                return 'LINK';
            };
            $md->normalizeLinkText = function ($url) use($gg) {
                $gg->ok(preg_match("/example\.com/", $url), "wrong url passed");
                return 'TEXT';
            };

            $gg->strictEqual($md->render("foo@example.com"), "<p><a href=\"LINK\">TEXT</a></p>\n", "foo@example.com");
            $gg->strictEqual($md->render("http://example.com"), "<p><a href=\"LINK\">TEXT</a></p>\n", "http://example.com");
            $gg->strictEqual($md->render("<foo@example.com>"), "<p><a href=\"LINK\">TEXT</a></p>\n", "<foo@example.com>");
            $gg->strictEqual($md->render("<http://example.com>"), "<p><a href=\"LINK\">TEXT</a></p>\n", "<http://example.com>");
            $gg->strictEqual($md->render("[test](http://example.com)"), "<p><a href=\"LINK\">test</a></p>\n", "[test](http://example.com)");
            $gg->strictEqual($md->render("![test](http://example.com)"), "<p><img src=\"LINK\" alt=\"test\"></p>\n", "![test](http://example.com)");
        });
    }
    private function miscLinksValidation($g)
    {
        $g->group("Override validator, disable everything", function ($gg) {
            $md = new MarkdownIt([ "linkify"=> true ]);

            $md->validateLink = function () { return false; };

            $gg->strictEqual($md->render("foo@example.com"), "<p>foo@example.com</p>\n");
            $gg->strictEqual($md->render("http://example.com"), "<p>http://example.com</p>\n");
            $gg->strictEqual($md->render("<foo@example.com>"), "<p>&lt;foo@example.com&gt;</p>\n");
            $gg->strictEqual($md->render("<http://example.com>"), "<p>&lt;http://example.com&gt;</p>\n");
            $gg->strictEqual($md->render("[test](http://example.com)"), "<p>[test](http://example.com)</p>\n");
            $gg->strictEqual($md->render("![test](http://example.com)"), "<p>![test](http://example.com)</p>\n");
        });
    }
    private function miscMaxNesting($g)
    {
        $md = new MarkdownIt([ "maxNesting"=> 2 ]);
        $g->strictEqual(
            $md->render(">foo\n>>bar\n>>>baz"),
            "<blockquote>\n<p>foo</p>\n<blockquote></blockquote>\n</blockquote>\n",
            "Block parser should not nest above limit"
        );
        //-------------------------------------------------------------------
        $md = new MarkdownIt([ "maxNesting"=> 1 ]);
        $g->strictEqual(
            $md->render("[`foo`]()"),
            "<p><a href=\"\">`foo`</a></p>\n",
            "Inline parser should not nest above limit"
        );
        //-------------------------------------------------------------------
        $md = new MarkdownIt([ "maxNesting"=> 2 ]);
        $g->strictEqual(
            $md->render("[[[[[[[[[[[[[[[[[[foo]()"),
            "<p>[[[[[[[[[[[[[[[[[[foo]()</p>\n",
            "Inline nesting coverage"
        );
    }
    private function miscSmartquotes($g)
    {
        $md = new MarkdownIt([
            "typographer" => true,

            // all strings have different length to make sure
            // we didn't accidentally count the wrong one
            "quotes"=> [ "[[[", "]]", "(((((", "))))" ]
        ]);
        //-------------------------------------------------------------------
        $g->strictEqual(
            $md->render("\"foo\" 'bar'"),
            "<p>[[[foo]] (((((bar))))</p>\n",
            "Should support multi-character quotes"
        );
        //-------------------------------------------------------------------
        $g->strictEqual(
            $md->render("\"foo 'bar' baz\""),
            "<p>[[[foo (((((bar)))) baz]]</p>\n",
            "Should support nested multi-character quotes"
        );
        //-------------------------------------------------------------------
        $g->strictEqual(
            $md->render("\"a *b 'c *d* e' f* g\""),
            "<p>[[[a <em>b (((((c <em>d</em> e)))) f</em> g]]</p>\n",
            "Should support multi-character quotes in different tags"
        );
    }
    private function miscOrderedListInfo($g)
    {
        $g->group("Should mark ordered list item tokens with info", function ($gg) {
            $md = new MarkdownIt();

            $type_filter = function($tokens, $type) {
                return array_values(array_filter($tokens, function ($t) use($type) { return $t->type === $type; }));
            };

            $tokens = $md->parse("1. Foo\n2. Bar\n20. Fuzz");
            $gg->strictEqual(count($type_filter($tokens, "ordered_list_open")), 1);
            $tokens = $type_filter($tokens, "list_item_open");
            $gg->strictEqual(count($tokens), 3);
            $gg->strictEqual($tokens[0]->info, "1");
            $gg->strictEqual($tokens[0]->markup, ".");
            $gg->strictEqual($tokens[1]->info, "2");
            $gg->strictEqual($tokens[1]->markup, ".");
            $gg->strictEqual($tokens[2]->info, "20");
            $gg->strictEqual($tokens[2]->markup, ".");

            $tokens = $md->parse(" 1. Foo\n2. Bar\n  20. Fuzz\n 199. Flp");
            $gg->strictEqual(count($type_filter($tokens, "ordered_list_open")), 1);
            $tokens = $type_filter($tokens, "list_item_open");
            $gg->strictEqual(count($tokens), 4);
            $gg->strictEqual($tokens[0]->info, "1");
            $gg->strictEqual($tokens[0]->markup, ".");
            $gg->strictEqual($tokens[1]->info, "2");
            $gg->strictEqual($tokens[1]->markup, ".");
            $gg->strictEqual($tokens[2]->info, "20");
            $gg->strictEqual($tokens[2]->markup, ".");
            $gg->strictEqual($tokens[3]->info, "199");
            $gg->strictEqual($tokens[3]->markup, ".");
        });

    }
    private function miscTokenAttributes($g)
    {
        $md = new MarkdownIt();

        $tokens = $md->parse("```");
        $t = $tokens[0];

        $t->attrJoin("class", "foo");
        $t->attrJoin("class", "bar");

        $g->strictEqual(
            $md->renderer->render($tokens, $md->options),
            "<pre><code class=\"foo bar\"></code></pre>\n",
            ".attrJoin"
        );
        //-------------------------------------------------------------------
        $g->group(".attrSet", function ($gg) {
            $md = new MarkdownIt();

            $tokens = $md->parse("```");
            $t = $tokens[0];

            $t->attrSet("class", "foo");

            $gg->strictEqual(
                $md->renderer->render($tokens, $md->options),
                "<pre><code class=\"foo\"></code></pre>\n"
            );

            $t->attrSet("class", "bar");

            $gg->strictEqual(
                $md->renderer->render($tokens, $md->options),
                "<pre><code class=\"bar\"></code></pre>\n"
            );
        });
        //-------------------------------------------------------------------
        $g->group(".attrGet", function ($gg) {
            $md = new MarkdownIt();

            $tokens = $md->parse("```");
            $t = $tokens[0];

            $gg->strictEqual($t->attrGet("myattr"), null);

            $t->attrSet("myattr", "myvalue");

            $gg->strictEqual($t->attrGet("myattr"), "myvalue");
        });
    }
}