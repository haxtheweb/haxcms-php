<?php
namespace Kaoken\Test\MarkdownItTestgen;

use Kaoken\Test\EasyTest;

class CTest extends EasyTest
{
    protected $testgen;
    protected $options;

    public function __construct()
    {
        parent::__construct("MarkdownIt test case !!");
        $this->testgen = new MarkdownItTestgen();
        $this->options = new \stdClass();
        $this->group("Generator", function($g){
            $this->generator($g);
        });
    }
    protected function generator($g)
    {
        $g->group('should parse meta', function ($gg) {

            $this->testgen->load(__DIR__ . '/Fixtures/meta.txt', function ($data) use($gg) {
                $gg->deepEqual($data->meta, (object)[ "desc"=> 123, "skip"=> true ]);

                $gg->strictEqual(count($data->fixtures), 1);
                $gg->strictEqual($data->fixtures[0]->first->text, "123\n");
                $gg->strictEqual($data->fixtures[0]->second->text, "456\n");
            });
        });

        $g->group('should parse headers', function ($gg) {
            $this->testgen->load(__DIR__ . '/Fixtures/headers.txt', function ($data) use($gg) {

                $gg->strictEqual(count($data->fixtures), 3);

                $gg->strictEqual($data->fixtures[0]->header, '');
                $gg->strictEqual($data->fixtures[0]->first->text, "123\n");
                $gg->strictEqual($data->fixtures[0]->second->text, "456\n");

                $gg->strictEqual($data->fixtures[1]->header, "header1");
                $gg->strictEqual($data->fixtures[1]->first->text, "qwe\n");
                $gg->strictEqual($data->fixtures[1]->second->text, "rty\n");

                $gg->strictEqual($data->fixtures[2]->header, "header2");
                $gg->strictEqual($data->fixtures[2]->first->text, "zxc\n");
                $gg->strictEqual($data->fixtures[2]->second->text, "vbn\n");
            });
        });

        $g->group('should parse multilines', function ($gg) {
            $this->testgen->load(__DIR__ . '/Fixtures/multilines.txt', function ($data) use($gg) {

                $gg->strictEqual(count($data->fixtures), 1);

                $gg->strictEqual($data->fixtures[0]->header, '');
                $gg->strictEqual($data->fixtures[0]->first->text, "123\n \n456\n");
                $gg->strictEqual($data->fixtures[0]->second->text, "789\n\n098\n");
            });
        });

        $g->group('should not add \\n at empty to end of empty line', function ($gg) {
            $this->testgen->load(__DIR__ . '/Fixtures/empty.txt', function ($data) use($gg) {

                $gg->strictEqual($data->fixtures[0]->first->text, "a\n");
                $gg->strictEqual($data->fixtures[0]->second->text, '');
            });
        });

        $g->group('should scan dir', function ($gg) {
            $files = 0;

            $this->testgen->load(__DIR__ . '/Fixtures', function () use(&$files) {
                $files++;
            });
            $gg->strictEqual($files, 4);
        });
    }
}