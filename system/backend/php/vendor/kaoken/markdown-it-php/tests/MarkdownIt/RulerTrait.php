<?php
/**
 * @see https://github.com/markdown-it/markdown-it/blob/master/test/ruler.js
 */
namespace Kaoken\Test\MarkdownIt;

use Kaoken\MarkdownIt\Ruler;

trait RulerTrait
{
    /**
     * @see test/ruler.js
     */
    private function ruler()
    {
        $this->group('Ruler', function ($g) {
            $g->group('should replace rule (.at)', function ($gg) {
                $ruler = new Ruler();
                $res = 0;

                $ruler->push('test', function /*foo*/() use(&$res) { $res = 1; });
                $ruler->at('test', function /*bar*/() use(&$res) { $res = 2; });

                $rules = $ruler->getRules('');

                $gg->strictEqual(count($rules), 1);
                $rules[0]();
                $gg->strictEqual($res, 2);
            });
            //------------------------------------------------------------------
            $g->group('should inject before/after rule', function ($gg) {
                $ruler = new Ruler();
                $res = 0;

                $ruler->push('test', function /*foo*/() use(&$res) { $res = 1; });
                $ruler->before('test', 'before_test', function /*fooBefore*/() use(&$res) { $res = -10; });
                $ruler->after('test', 'after_test', function /*fooAfter*/() use(&$res) { $res = 10; });

                $rules = $ruler->getRules('');

                $gg->strictEqual(count($rules), 3);
                $rules[0]();
                $gg->strictEqual($res, -10);
                $rules[1]();
                $gg->strictEqual($res, 1);
                $rules[2]();
                $gg->strictEqual($res, 10);
            });
            //------------------------------------------------------------------
            $g->group('should enable/disable rule', function ($gg) {
                $ruler = new Ruler();

                $ruler->push('test', function /*foo*/() {});
                $ruler->push('test2', function /*bar*/() {});

                $rules = $ruler->getRules('');
                $gg->strictEqual(count($rules), 2);

                $ruler->disable('test');
                $rules = $ruler->getRules('');
                $gg->strictEqual(count($rules), 1);
                $ruler->disable('test2');
                $rules = $ruler->getRules('');
                $gg->strictEqual(count($rules), 0);

                $ruler->enable('test');
                $rules = $ruler->getRules('');
                $gg->strictEqual(count($rules), 1);
                $ruler->enable('test2');
                $rules = $ruler->getRules('');
                $gg->strictEqual(count($rules), 2);
            });
            //------------------------------------------------------------------
            $g->group('should enable/disable multiple rule', function ($gg) {
                $ruler = new Ruler();

                $ruler->push('test', function /*foo*/() {});
                $ruler->push('test2', function /*bar*/() {});

                $ruler->disable([ 'test', 'test2' ]);
                $rules = $ruler->getRules('');
                $gg->strictEqual(count($rules), 0);
                $ruler->enable([ 'test', 'test2' ]);
                $rules = $ruler->getRules('');
                $gg->strictEqual(count($rules), 2);
            });
            //------------------------------------------------------------------
            $g->group('should enable $rules by whitelist', function ($gg) {
                $ruler = new Ruler();

                $ruler->push('test', function /*foo*/() {});
                $ruler->push('test2', function /*bar*/() {});

                $ruler->enableOnly('test');
                $rules = $ruler->getRules('');
                $gg->strictEqual(count($rules), 1);
            });
            //------------------------------------------------------------------
            $g->group('should support multiple chains', function ($gg) {
                $ruler = new Ruler();

                $ruler->push('test', function /*foo*/() {});
                $ruler->push('test2', function /*bar*/() {}, ["alt"=> [ 'alt1' ] ]);
                $ruler->push('test2', function /*bar*/() {}, ["alt"=> [ 'alt1', 'alt2' ] ]);

                $rules = $ruler->getRules('');
                $gg->strictEqual(count($rules), 3);
                $rules = $ruler->getRules('alt1');
                $gg->strictEqual(count($rules), 2);
                $rules = $ruler->getRules('alt2');
                $gg->strictEqual(count($rules), 1);
            });
            //------------------------------------------------------------------
            $g->group('should fail on invalid rule name', function ($gg) {
                $ruler = new Ruler();

                $ruler->push('test', function /*foo*/() {});

                $gg->throws(function () use($ruler) {
                    $ruler->at('invalid name', function /*bar*/() {});
                });
                $gg->throws(function () use($ruler) {
                    $ruler->before('invalid name', '', null);
                });
                $gg->throws(function () use($ruler) {
                    $ruler->after('invalid name', '', null);
                });
                $gg->throws(function () use($ruler) {
                    $ruler->enable('invalid name');
                });
                $gg->throws(function () use($ruler) {
                    $ruler->disable('invalid name');
                });
            });
            //------------------------------------------------------------------
            $g->group('should not fail on invalid rule name in silent mode', function ($gg) {
                $ruler = new Ruler();

                $ruler->push('test', function /*foo*/() {});

                $gg->doesNotThrow(function () use($ruler) {
                    $ruler->enable('invalid name', true);
                });
                $gg->doesNotThrow(function () use($ruler) {
                    $ruler->enableOnly('invalid name', true);
                });
                $gg->doesNotThrow(function () use($ruler){
                    $ruler->disable('invalid name', true);
                });
            });
        });
    }
}