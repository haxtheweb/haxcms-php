<?php
/**
Copyright (c) 2016 Kaoken, Vitaly Puzrin.

This software is released under the MIT License.
http://opensource.org/licenses/mit-license.php
 */
namespace Kaoken\Test\LinkifyIt;

use Kaoken\LinkifyIt\MatchResult;
use Kaoken\Test\EasyTest;
use Kaoken\LinkifyIt\LinkifyIt;

class LinkifyItTest extends LinkifyIt{
    public function normalize(MatchResult &$match) { }
}

class CTest extends EasyTest
{
    public function __construct()
    {
        parent::__construct("PHP LinkifyIt test case !!");
        $this->links();
        $this->notLink();
        $this->api();
    }
    private function path($name){
        return __DIR__."/Fixtures/".$name.".txt";
    }
    private function links()
    {
        $this->group('Links', function ($g){
            $l = new LinkifyItTest(["fuzzyIP"=>true]);
            $skipNext = false;
            $lines = $this->getLinesFromFile($this->path('links'));
            foreach ( $lines as $idx => &$line ){
                if ($skipNext) {
                    $skipNext = false;
                    continue;
                }
                $line = preg_replace("/^%.*/", '',$line);

                $next = isset($lines[$idx + 1]) ? preg_replace("/^%.*/", '',($lines[$idx + 1])) : '';

                if (!trim($line)) { continue; }
                if (trim($next)) {
                    $g->group('line' . ($idx + 1) . " ##############  {$line}", function($gg) use($l, $line, $next){
                        $gg->ok($l->pretest($line), '(pretest failed in `' . $line . '`)');
                        $gg->ok($l->match($line), '(link not found in `' . $line . '`)');
                        $gg->ok($l->match("\n" . $line . "\n"), "(link not found in `\n" . $line . "\n`)");
                        $gg->equal($l->match($line)[0]->url, $next);
                    });
                    $skipNext = true;
                } else {
                    $g->group('line' . ($idx + 1) . " ##############  {$line}", function($gg) use($l, $line, $next){
                        $gg->ok($l->pretest($line), '(pretest failed in `' . $line . '`)');
                        $gg->ok($l->match($line), '(link not found in `' . $line . '`)');
                        $gg->ok($l->match("\n" . $line . "\n"), "(link not found in `\n" . $line . "\n`)");
                        $gg->equal($l->match($line)[0]->url, $line);
                    });
                }
            }
        });
    }
    private function notLink()
    {
        $this->group('not Links', function ($g) {
            $l = new LinkifyItTest();
            $lines = $this->getLinesFromFile($this->path('not_links'));

            foreach ($lines as $idx => &$line) {
                $line = preg_replace("/^%.*/", '', $line);

                if (!trim($line)) continue;

                $g->group('line' . ($idx + 1) . " ##############  {$line}", function($gg) use($l, $line) {
                    $gg->notOk($l->match($line),
                        '(should not find link in `' . $line . '`, but found `' .
                        json_encode($l->match($line)) . '`)');
                });
            }
        });
    }
    private function api()
    {
        $this->group('API', function ($g) {
            $g->group("xtend tlds", function($gg) {
                $l = new LinkifyIt();

                $gg->notOk($l->test('google.myroot'));

                $l->tlds('myroot', true);

                $gg->ok($l->test('google.myroot'));
                $gg->notOk($l->test('google.xyz'));

                $l->tlds(['xyz']);

                $gg->ok($l->test('google.xyz'));
                $gg->notOk($l->test('google.myroot'));
            });
            //-----------------------------------------------
            $g->group("add rule as regexp, with default normalizer", function($gg) {
                $l = (new LinkifyIt())->add('my:', ['validate' => "/^\/\/[a-z]+/"]);
                $match = $l->match('google.com. my:// my://asdf!');

                $gg->equal($match[0]->text, 'google.com');
                $gg->equal($match[1]->text, 'my://asdf');
            });
            //-----------------------------------------------
            $g->group("add rule with normalizer", function($gg) {
                $l = (new LinkifyIt())->add('my:', [
                        "validate" =>"/^\/\/[a-z]+/",
                        "normalize"=> function (&$m) {
                            $m->text = strtoupper(preg_replace("/^my:\/\//u", '', $m->text));
                            $m->url  = strtoupper($m->url);
                        }]
                    );


                $match = $l->match('google.com. my:// my://asdf!');

                $gg->equal($match[1]->text, 'ASDF');
                $gg->equal($match[1]->url, 'MY://ASDF');
            });
            //-----------------------------------------------
            $g->group("disable rule", function($gg) {
                $l = (new LinkifyIt());

                $gg->ok($l->test('http://google.com'));
                $gg->ok($l->test('foo@bar.com'));
                $l->add('http:', null);
                $l->add('mailto:', null);
                $gg->notOk($l->test('http://google.com'));
                $gg->notOk($l->test('foo@bar.com'));
            });
            //-----------------------------------------------
            $g->group("add bad definition", function($gg) {
                $l = (new LinkifyIt());

                $gg->throws(function () use($l) {
                    $l->add('test:', []);
                });

                $l = (new LinkifyIt());

                $gg->throws(function () use($l) {
                    $l->add('test:', [ "validate" =>[] ]);
                });

                $l = (new LinkifyIt());

                $gg->throws(function () use($l){
                    $l->add('test:', [
                    "validate" =>function () { return false; },
                    "normalize"=> 'bad'
                    ]);
                });
            });
            //-----------------------------------------------
            $g->group("test at position", function($gg) {
                $l = (new LinkifyIt());

                $gg->ok($l->testSchemaAt('http://google.com', 'http:', 5));
                $gg->ok($l->testSchemaAt('http://google.com', 'HTTP:', 5));
                $gg->notOk($l->testSchemaAt('http://google.com', 'http:', 6));

                $gg->notOk($l->testSchemaAt('http://google.com', 'bad_schema:', 6));
            });
            //-----------------------------------------------
            $g->group("correct cache value", function($gg) {
                $l = (new LinkifyIt());

                $match = $l->match('.com. http://google.com google.com ftp://google.com');

                $gg->equal($match[0]->text, 'http://google.com');
                $gg->equal($match[1]->text, 'google.com');
                $gg->equal($match[2]->text, 'ftp://google.com');
            });
            //-----------------------------------------------
            $g->group("normalize", function($gg) {
                $l = (new LinkifyIt());

                $m = $l->match('mailto:foo@bar.com')[0];

                // $gg->equal($m.text, 'foo@bar.com');
                $gg->equal($m->url,  'mailto:foo@bar.com');

                $m = $l->match('foo@bar.com')[0];

                // $gg->equal($m.text, 'foo@bar.com');
                $gg->equal($m->url,  'mailto:foo@bar.com');
            });
            //-----------------------------------------------
            $g->group("test @twitter rule", function($gg) {
                $l = (new LinkifyIt());
                $l->add('@', [
                    "validate" =>function ($text, $pos) use($l) {
                        $tail = substr($text, $pos);

                        if (!isset($l->re->twitter)) {
                            $l->re->twitter = '/^([a-zA-Z0-9_]){1,15}(?!_)(?=$|' . $l->re->src_ZPCc . ')/u';
                        }
                        if (preg_match($l->re->twitter, $tail, $m) ){
                            if ($pos >= 2 && $tail[$pos - 2] === '@') {
                                return false;
                            }
                            return strlen($m[0]);
                        }
                        return 0;
                    },
                    "normalize"=> function ($m) {
                        $m->url = 'https://twitter.com/' . preg_replace("/^@/u", '', $m->url);
                    }
                ]);

                $gg->equal($l->match('hello, @gamajoba_!')[0]->text, '@gamajoba_');
                $gg->equal($l->match(':@givi')[0]->text, '@givi');
                $gg->equal($l->match(':@givi')[0]->url, 'https://twitter.com/givi');
                $gg->notOk($l->test('@@invalid'));
            });
            //-----------------------------------------------
            $g->group("fuzzyLink", function($gg) {
                $l = new LinkifyIt([ "fuzzyLink"=> false ]);

                $gg->equal($l->test('google.com.'), false);

                $l->set([ "fuzzyLink"=> true ]);

                $gg->equal($l->test('google.com.'), true);
                $gg->equal($l->match('google.com.')[0]->text, 'google.com');
            });
            //-----------------------------------------------
            $g->group("set option: fuzzyEmail", function($gg) {
                $l = new LinkifyIt([ "fuzzyEmail"=> false ]);

                $gg->equal($l->test('foo@bar.com.'), false);

                $l->set([ "fuzzyEmail"=> true ]);

                $gg->equal($l->test('foo@bar.com.'), true);
                $gg->equal($l->match('foo@bar.com.')[0]->text, 'foo@bar.com');
            });
            //-----------------------------------------------
            $g->group("set option: fuzzyIP", function($gg) {
                $l = new LinkifyIt();

                $gg->equal($l->test('1.1.1.1.'), false);

                $l->set([ "fuzzyIP"=> true ]);

                $gg->equal($l->test('1.1.1.1.'), true);
                $gg->equal($l->match('1.1.1.1.')[0]->text, '1.1.1.1');
            });
            //-----------------------------------------------
            $g->group("should not hang in fuzzy mode with sequences of astrals", function($gg) {
                $l = (new LinkifyIt());

                $l->set([ "fuzzyLink"=> true ]);

                $l->match('😡😡😡😡😡😡😡😡😡😡😡😡😡😡😡😡😡😡😡😡😡😡😡😡😡😡😡😡😡😡😡😡😡😡😡 .com');
            });
            //-----------------------------------------------
            $g->group("should accept `---` if enabled", function($gg) {
                $l = (new LinkifyIt());

                $gg->equal($l->match('http://e.com/foo---bar')[0]->text, 'http://e.com/foo---bar');
                $gg->strictEqual($l->match('text@example.com---foo'), null);

                $l = (new LinkifyIt(null, [ '---'=> true ]));

                $gg->equal($l->match('http://e.com/foo---bar')[0]->text, 'http://e.com/foo');
                $gg->strictEqual($l->match('text@example.com---foo')[0]->text, 'text@example.com');
            });
            //-----------------------------------------------
            $g->group("should accept `---` if enabled", function($gg) {
                $l = (new LinkifyIt());

                $l->set(["fuzzyLink" => true ]);

                $gg->strictEqual($l->matchAtStart('http://google.com 123')->text, 'http://google.com');
                $gg->ok(!$l->matchAtStart('google.com 123'));
                $gg->ok(!$l->matchAtStart('  http://google.com 123'));
            });
            //-----------------------------------------------
            $g->group("matchAtStart should not interfere with normal match", function($gg) {
                $l = (new LinkifyIt());

                $str = 'http://google.com http://google.com';
                $gg->ok($l->matchAtStart($str));
                $gg->strictEqual(count($l->match($str)), 2);

                $str = 'aaa http://google.com http://google.com';
                $gg->ok(!$l->matchAtStart($str));
                $gg->strictEqual(count($l->match($str)), 2);
            });
            //-----------------------------------------------
            $g->group("should not match incomplete links", function($gg) {
                // regression test for https://github.com/markdown-it/markdown-it/issues/868
                $l = (new LinkifyIt());

                $gg->ok(!$l->matchAtStart('http://'));
                $gg->ok(!$l->matchAtStart('https://'));
            });
        });
    }
}