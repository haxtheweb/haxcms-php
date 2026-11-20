<?php
namespace Kaoken\Test\Punycode;

use Kaoken\Test\EasyTest;
use Kaoken\Punycode\Punycode;

class CTest extends EasyTest
{
    const TEST_DATA = [

        "strings" => [
            [
                "description" => "a single basic code point",
                'decoded' => 'Bach',
                'encoded' => 'Bach-'
            ],
            [
                "description" => "a single non-ASCII character",
                'decoded' => "ü",
                'encoded' => 'tda'
            ],
            [
                "description" => "multiple non-ASCII characters",
                'decoded' => "üëäö♥",
                'encoded' => '4can8av2009b'
            ],
            [
                "description" => "mix of ASCII and non-ASCII characters",
                'decoded' => "bücher",
                'encoded' => 'bcher-kva'
            ],
            [
                "description" => "long string with both ASCII and non-ASCII characters",
                'decoded' => "Willst du die Blüthe des frühen, die Früchte des späteren Jahres",
                'encoded' => 'Willst du die Blthe des frhen, die Frchte des spteren Jahres-x9e96lkal'
            ],
            // https =>//tools.ietf.org/html/rfc3492#section-7.1
            [
                "description" => "Arabic (Egyptian)",
                'decoded' => 'ليهمابتكلموشعربي؟',
                'encoded' => 'egbpdaj6bu4bxfgehfvwxn'
            ],
            [
                "description" => "Chinese (simplified)",
                'decoded' => '他们为什么不说中文',
                'encoded' => 'ihqwcrb4cv8a8dqg056pqjye'
            ],
            [
                "description" => "Chinese (traditional)",
                'decoded' => '他們爲什麽不說中文',
                'encoded' => 'ihqwctvzc91f659drss3x8bo0yb'
            ],
            [
                "description" => "Czech",
                'decoded' => "Pročprostěnemluvíčesky",
                'encoded' => 'Proprostnemluvesky-uyb24dma41a'
            ],
            [
                "description" => "Hebrew",
                'decoded' => 'למההםפשוטלאמדבריםעברית',
                'encoded' => '4dbcagdahymbxekheh6e0a7fei0b'
            ],
            [
                "description" => "Hindi (Devanagari)",
                'decoded' => 'यहलोगहिन्दीक्योंनहींबोलसकतेहैं',
                'encoded' => 'i1baa7eci9glrd9b2ae1bj0hfcgg6iyaf8o0a1dig0cd'
            ],
            [
                "description" => "Japanese (kanji and hiragana)",
                'decoded' => 'なぜみんな日本語を話してくれないのか',
                'encoded' => 'n8jok5ay5dzabd5bym9f0cm5685rrjetr6pdxa'
            ],
            [
                "description" => "Korean (Hangul syllables)",
                'decoded' => '세계의모든사람들이한국어를이해한다면얼마나좋을까',
                'encoded' => '989aomsvi5e83db1d2a355cv1e0vak1dwrv93d5xbh15a0dt30a5jpsd879ccm6fea98c'
            ],
            /**
             * As there's no way to do $g->group in JavaScript, Punycode.js doesn't support
             * mixed-case annotation (which is entirely optional as per the RFC).
             * So, while the RFC sample string encodes to:
             * `b1abfaaepdrnnbgefbaDotcwatmq2g4l`
             * Without mixed-case annotation $g->group has to encode to:
             * `b1abfaaepdrnnbgefbadotcwatmq2g4l`
             * https://github.com/mathiasbynens/punycode.js/issues/3
             */
            [
                "description" => "Russian (Cyrillic)",
                'decoded' => 'почемужеонинеговорятпорусски',
                'encoded' => 'b1abfaaepdrnnbgefbadotcwatmq2g4l'
            ],
            [
                "description" => "Spanish",
                'decoded' => "PorquénopuedensimplementehablarenEspañol",
                'encoded' => 'PorqunopuedensimplementehablarenEspaol-fmd56a'
            ],
            [
                "description" => "Vietnamese",
                'decoded' => "TạisaohọkhôngthểchỉnóitiếngViệt",
                'encoded' => 'TisaohkhngthchnitingVit-kjcr8268qyxafd2f1b9g'
            ],
            [
                'decoded' => '3年B組金八先生',
                'encoded' => '3B-ww4c5e180e575a65lsy2b'
            ],
            [
                'decoded' => '安室奈美恵-with-SUPER-MONKEYS',
                'encoded' => '-with-SUPER-MONKEYS-pc58ag80a8qai00g7n9n'
            ],
            [
                'decoded' => 'Hello-Another-Way-それぞれの場所',
                'encoded' => 'Hello-Another-Way--fc4qua05auwb3674vfr0b'
            ],
            [
                'decoded' => 'ひとつ屋根の下2',
                'encoded' => '2-u9tlzr9756bt3uc0v'
            ],
            [
                'decoded' => 'MajiでKoiする5秒前',
                'encoded' => 'MajiKoi5-783gue6qz075azm5e'
            ],
            [
                'decoded' => 'パフィーdeルンバ',
                'encoded' => 'de-jg4avhby1noc0d'
            ],
            [
                'decoded' => 'そのスピードで',
                'encoded' => 'd9juau41awczczp'
            ],
            /**
             * This example is an ASCII string that breaks the existing rules for host
             * name labels. (It"s not a realistic example for IDNA, because IDNA never
             * encodes pure ASCII labels.)
             */
            [
                "description" => "ASCII string that breaks the existing rules for host-name labels",
                'decoded' => '-> $1.00 <-',
                'encoded' => '-> $1.00 <--'
            ]
        ],
        "ucs2" => [
            // Every Unicode symbol is tested separately. These are just the extra
            // tests for symbol combinations =>
            [
                "description" => "Consecutive astral symbols",
                "decoded" => [127829, 119808, 119558, 119638],
                'encoded' => [0xd8,0x3c,0xdf,0x55,0xd8,0x35,0xdc,0x00,0xd8,0x34,0xdf,0x06,0xd8,0x34,0xdf,0x56]
            ],
            [
                "description" => "U+D800 (high surrogate) followed by non-surrogates",
                "decoded" => [0xD800, 0x61, 0x62],
                'encoded' => [0xD8, 0x00, 0x00, 0x61, 0x00, 0x62]
            ],
            [
                "description" => "U+DC00 (low surrogate) followed by non-surrogates",
                "decoded" => [0xDC00, 0x61, 0x62],
                'encoded' => [0xDC, 0x00, 0x00, 0x61, 0x00, 0x62]
            ],
            [
                "description" => "High surrogate followed by another high surrogate",
                "decoded" => [0xD800, 0xD800],
                'encoded' => [0xD8, 0x00, 0xD8, 0x00]
            ],
            [
                "description" => "Unmatched high surrogate, followed by a surrogate pair, followed by an unmatched high surrogate",
                "decoded" => [0xD800, 0x1D306, 0xD800],
                'encoded' => [0xD8,0x00,0xD8,0x34,0xDF,0x06,0xD8,0x00]
            ],
            [
                "description" => "Low surrogate followed by another low surrogate",
                "decoded" => [0xDC00, 0xDC00],
                'encoded' => [0xDC, 0x00, 0xDC, 0x00]
            ],
            [
                "description" => "Unmatched low surrogate, followed by a surrogate pair, followed by an unmatched low surrogate",
                "decoded" => [0xDC00, 0x1D306, 0xDC00],
                'encoded' => [0xDC,0x00,0xD8,0x34,0xDF,0x06,0xDC,0x00]
            ]
        ],
        "domains" => [
            [
                'decoded' => "mañana.com",
                'encoded' => 'xn--maana-pta.com'
            ],
            [ // https =>//github.com/bestiejs/$this->punycode->js/issues/17
                'decoded' => 'example.com.',
                'encoded' => 'example.com.'
            ],
            [
                'decoded' => "bücher.com",
                'encoded' => 'xn--bcher-kva.com'
            ],
            [
                'decoded' => "café.com",
                'encoded' => 'xn--caf-dma.com'
            ],
            [
                'decoded' => '☃-⌘.com',
                'encoded' => 'xn----dqo34k.com'
            ],
            [
                'decoded' => '퐀☃-⌘.com',
                'encoded' => 'xn----dqo34kn65z.com'
            ],
            [
                "description" => "Emoji",
                'decoded' => '💩.la',
                'encoded' => 'xn--ls8h.la'
            ],
            [
                'description' => 'Non-printable ASCII',
                'decoded' => "\x0\x01\x02foo.bar",
                'encoded' => "\x0\x01\x02foo.bar",
            ],
            [
                "description" => "Email address",
                'decoded' => 'джумла@джpумлатест.bрфa',
                'encoded' => 'джумла@xn--p-8sbkgc5ag7bhce.xn--ba-lmcq'
            ],
            [ // https://github.com/mathiasbynens/punycode.js/pull/115
                'decoded'=> "foo\x7F.example",
                'encoded'=> "foo\x7F.example"
            ],
        ],
        "separators" => [
            [
                "description" => "Using U+002E as separator",
                'decoded' => "mañana.com",
                'encoded' => 'xn--maana-pta.com'
            ],
            [
                "description" => "Using U+3002 as separator",
                'decoded' => "mañana。com",
                'encoded' => 'xn--maana-pta.com'
            ],
            [
                "description" => "Using U+FF0E as separator",
                'decoded' => "mañana．com",
                'encoded' => 'xn--maana-pta.com'
            ],
            [
                "description" => "Using U+FF61 as separator",
                'decoded' => "mañana｡com",
                'encoded' => 'xn--maana-pta.com'
            ]
        ]
    ];

    private $punycode;

    private function punycodeUcs2Decode($g)
    {
        foreach (self::TEST_DATA['ucs2'] as &$object ) {
            $g->group($object['description'], function($gg) use($object) {
                $en='';
                foreach($object['encoded']as&$v)$en .= chr($v);
                $gg->strictEqual(
                    $this->punycode->ucs2Decode($en),
                    $object['decoded'],
                    $object['description']
                );
            });
        }
        $g->group('throws RangeError: Illegal input >= 0x80 (not a basic code point)', function($gg) {
        $gg->throws(
                function() {
                    $this->punycode->decode("\x81-");
                },
                \Exception::class
            );
        });
        $g->group('throws RangeError: Overflow: input needs wider integers to process', function($gg) {
            $gg->throws(
                function() {
                    $this->punycode->decode("\x81");
                },
                \Exception::class
            );
        });
    }
    
    private function punycodeUcs2Encode($g)
    {
        foreach (self::TEST_DATA['ucs2'] as &$object) {
            $g->group($object['description'], function($gg) use($object){
                $str = $this->punycode->ucs2Encode($object['decoded']);
                $en = [];
                for($i=0,$l=strlen($str);$i<$l;$i++)$en[] = ord($str[$i]);

                $gg->strictEqual(
                    $en,
                    $object['encoded']
                );
            });
        }
        $g->group('does not mutate argument array', function($gg) {
            $codePoints = [0x61, 0x62, 0x63];
            $t = $this->punycode->ucs2Encode($codePoints);
            $result = mb_convert_encoding($this->punycode->ucs2Encode($codePoints), 'UTF-8', 'UTF-16');
            $gg->strictEqual($result, 'abc');
            $gg->strictEqual($codePoints, [0x61, 0x62, 0x63]);
        });
    }

    private function punycodeDecode($g)
    {

        foreach (self::TEST_DATA['strings'] as &$object) {
            $g->group(!empty($object['description']) ? $object['description'] : $object['encoded'],
                function($gg) use(&$object) {
                    $gg->strictEqual(
                        $this->punycode->decode($object['encoded']),
                        $object['decoded']
                    );
            });
        }
        $g->group('handles uppercase Z', function($gg) {
            $gg->strictEqual($this->punycode->decode('ZZZ'), '箥');
        });
        $g->group('throws RangeError: Invalid input', function($gg) {
            $gg->throws(
                function() {
                    $this->punycode->decode("ls8h=");
                },
                \Exception::class
            );
        });
    }

    private function punycodeEncode($g)
    {
        foreach (self::TEST_DATA['strings'] as &$object) {
            $g->group(!empty($object['description']) ? $object['description'] : $object['decoded'],
                function($gg) use($object) {
                $gg->strictEqual(
                    $this->punycode->encode($object['decoded']),
                    $object['encoded']
                );
            });
        }
    }

    private function punycodeToUnicode($g)
    {
        foreach (self::TEST_DATA['domains'] as &$object) {
            $g->group(!empty($object['description']) ? $object['description'] : $object['encoded'],
                function($gg) use($object) {
                    $gg->strictEqual(
                        $this->punycode->toUnicode($object['encoded']),
                        $object['decoded']
                    );
            });
        }
        foreach (self::TEST_DATA['strings'] as &$object) {
            $g->group('does not convert names (or other strings) that don\'t start with `xn--`',
                function($gg) use($object) {
                    $gg->strictEqual(
                        $this->punycode->toUnicode($object['encoded']),
                        $object['encoded']
                    );
                    $gg->strictEqual(
                        $this->punycode->toUnicode($object['decoded']),
                        $object['decoded']
                    );
            });
        }
    }

    private function punycodeToASCII($g)
    {
        foreach (self::TEST_DATA['domains'] as &$object) {
            $g->group(!empty($object['description']) ? $object['description'] : $object['encoded'],
                function($gg) use($object) {
                    $gg->strictEqual(
                        $this->punycode->toASCII($object['decoded']),
                        $object['encoded']
                    );
            });
        }
        foreach (self::TEST_DATA['strings'] as &$object) {
            $g->group('does not convert domain names (or other strings) that are already in ASCII',
                function($gg) use($object) {
                    $gg->strictEqual(
                        $this->punycode->toASCII($object['encoded']),
                        $object['encoded']
                    );
            });
        }
        foreach (self::TEST_DATA['separators'] as &$object) {
            $g->group('supports IDNA2003 separators for backwards compatibility',
                function($gg) use($object) {
                    $gg->strictEqual(
                        $this->punycode->toASCII($object['decoded']),
                        $object['encoded']
                    );
            });
        }
    }


    public function __construct($pageTitle = "")
    {
        parent::__construct($pageTitle);

        $this->punycode = new Punycode();


        $this->group('punycode.ucs2Decode', function($g) {
            $this->punycodeUcs2Decode($g);
        });
        $this->group('punycode.ucs2Encode', function($g) {
            $this->punycodeUcs2Encode($g);
        });
        $this->group('punycode.decode', function($g) {
            $this->punycodeDecode($g);
        });
        $this->group('punycode.encode', function($g) {
            $this->punycodeEncode($g);
        });
        $this->group('punycode.toUnicode', function($g) {
            $this->punycodeToUnicode($g);
        });
        $this->group('punycode.toASCII', function($g) {
            $this->punycodeToASCII($g);
        });
    }
}