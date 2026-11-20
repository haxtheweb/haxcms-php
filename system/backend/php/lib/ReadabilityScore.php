<?php
/**
 * Readability metrics for the overview report, matching haxcms-nodejs.
 *
 * haxcms-nodejs reports readability with the text-readability npm package.
 * This class reproduces the five metrics the report uses (lexicon, sentence,
 * syllable and difficult word counts, Dale-Chall score) with the same
 * algorithms, so both backends answer the same numbers for the same site.
 * test/fixtures/readability-parity.json (shared with haxcms-nodejs) is the
 * contract both backends are tested against.
 *
 * Ported from, and keeping the behavior of:
 *   text-readability 1.1.1  Copyright (c) 2019 Utkarsh Raj
 *   syllable 5.0.1          Copyright (c) 2014 Titus Wormer
 *   pluralize 8.0.0         Copyright (c) 2013 Blake Embrey
 *   normalize-strings 1.1.1 Copyright (c) Pablo Terradillos
 * All four are MIT licensed:
 *
 *   Permission is hereby granted, free of charge, to any person obtaining a
 *   copy of this software and associated documentation files (the
 *   "Software"), to deal in the Software without restriction, including
 *   without limitation the rights to use, copy, modify, merge, publish,
 *   distribute, sublicense, and/or sell copies of the Software, and to
 *   permit persons to whom the Software is furnished to do so, subject to
 *   the following conditions:
 *
 *   The above copyright notice and this permission notice shall be included
 *   in all copies or substantial portions of the Software.
 *
 *   THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS
 *   OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 *   MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
 *   IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY
 *   CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT,
 *   TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE
 *   SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */
include_once dirname(__FILE__) . '/DaleChallWordList.php';
include_once dirname(__FILE__) . '/ReadabilityScoreData.php';

class ReadabilityScore
{
    // text-readability's punctuation class (removed before counting words)
    const PUNCTUATION = '/[\x{2000}-\x{206F}\x{2E00}-\x{2E7F}\\\\\'!"#$%&()*+,\-.\/:;<=>?@\[\]^_`{|}~]/u';
    // JavaScript's \s, spelled out so both runtimes collapse the same spaces
    const WHITESPACE = '/[\t\n\x{0B}\f\r \x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+/u';

    // elements whose content is never prose
    const RAW_TEXT_ELEMENTS = array('script', 'style', 'template');

    /**
     * The text the report scores, from page HTML: the same steps as
     * readabilityTextFromHTML in haxcms-nodejs. Markup is dropped
     * (stripMarkup), entities are decoded, pages are joined with a space and
     * runs of white space collapse to one space.
     *
     * @param array $pages page HTML strings, in outline order
     * @return string
     */
    public static function textFromHTML($pages = array())
    {
        $parts = array();
        foreach ($pages as $html) {
            $parts[] = self::decodeEntities(self::stripMarkup(self::scrub((string) $html)));
        }
        $text = preg_replace(self::WHITESPACE, ' ', implode(' ', $parts));
        // white space is collapsed, so at most one space at each end
        return trim((string) $text, ' ');
    }

    /**
     * Markup to text, scanning forward only (strpos), so the time it takes
     * grows with the page size whatever the page holds. Every tag, comment
     * and script / style / template block becomes one space. A comment,
     * block or tag that is never closed runs to the end of the page, as a
     * browser reads it. Same scan as stripMarkup in haxcms-nodejs.
     *
     * @param string $html
     * @return string
     */
    public static function stripMarkup($html)
    {
        // strtolower is ASCII only (PHP 8.2+): offsets stay where they were
        $lower = strtolower($html);
        $length = strlen($html);
        $out = '';
        $i = 0;
        while ($i < $length) {
            $lt = strpos($html, '<', $i);
            if ($lt === false) {
                $out .= substr($html, $i);
                break;
            }
            $out .= substr($html, $i, $lt - $i) . ' ';
            if (substr($html, $lt, 4) === '<!--') {
                $close = strpos($html, '-->', $lt + 4);
                $i = $close === false ? $length : $close + 3;
                continue;
            }
            $rawText = '';
            foreach (self::RAW_TEXT_ELEMENTS as $name) {
                $after = substr($lower, $lt + 1 + strlen($name), 1);
                if (substr($lower, $lt + 1, strlen($name)) === $name && !preg_match('/^[a-z0-9_]$/', $after)) {
                    $rawText = $name;
                    break;
                }
            }
            if ($rawText !== '') {
                $close = strpos($lower, '</' . $rawText, $lt + 1 + strlen($rawText));
                $gt = $close === false ? false : strpos($html, '>', $close);
                $i = $gt === false ? $length : $gt + 1;
                continue;
            }
            $gt = strpos($html, '>', $lt + 1);
            $i = $gt === false ? $length : $gt + 1;
        }
        return $out;
    }

    /**
     * Character references, decoded as haxcms-nodejs decodes them: named ones
     * from the HTML5 table (PHP's ENT_HTML5 table and the npm `he` table
     * agree), numeric ones to their code point unless it is NUL, a surrogate
     * half or past the Unicode range, which stay as written. One pass, so
     * `&amp;lt;` decodes to the text `&lt;` and no further.
     *
     * @param string $text
     * @return string
     */
    public static function decodeEntities($text)
    {
        return (string) preg_replace_callback(
            '/&(#[xX][0-9a-fA-F]{1,6}|#[0-9]{1,7}|[a-zA-Z][a-zA-Z0-9]{1,31});/u',
            function ($m) {
                $entity = $m[1];
                if ($entity[0] !== '#') {
                    return html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                $hex = $entity[1] === 'x' || $entity[1] === 'X';
                $codePoint = $hex ? hexdec(substr($entity, 2)) : intval(substr($entity, 1), 10);
                if (!($codePoint > 0 && $codePoint <= 0x10FFFF) || ($codePoint >= 0xD800 && $codePoint <= 0xDFFF)) {
                    return $m[0];
                }
                return mb_chr($codePoint, 'UTF-8');
            },
            $text
        );
    }

    /**
     * All report metrics for a text, as haxcms-nodejs answers them.
     *
     * @param string $text
     * @return array
     */
    public static function metrics($text = '')
    {
        $text = self::scrub((string) $text);
        return array(
            'gradeLevel' => self::gradeLevel(self::daleChallReadabilityScore($text)),
            'difficultWords' => self::difficultWords($text),
            'syllableCount' => self::syllableCount($text),
            'lexiconCount' => self::lexiconCount($text),
            'sentenceCount' => self::sentenceCount($text),
        );
    }

    public static function gradeLevel($score)
    {
        if ($score <= 4.9) {
            return '4th grade or lower';
        }
        if ($score <= 5.9) {
            return '5th / 6th grade';
        }
        if ($score <= 6.9) {
            return '7th / 8th grade';
        }
        if ($score <= 7.9) {
            return '9th / 10th grade';
        }
        if ($score <= 8.9) {
            return '11th / 12th grade';
        }
        return 'college level reading';
    }

    public static function removePunctuation($text)
    {
        return (string) preg_replace(self::PUNCTUATION, '', $text);
    }

    public static function lexiconCount($text)
    {
        $words = preg_split('/,| |\n|\r/u', self::removePunctuation($text));
        $count = 0;
        foreach ($words as $word) {
            if ($word !== '') {
                $count++;
            }
        }
        return $count;
    }

    public static function sentenceCount($text)
    {
        $ignoreCount = 0;
        $sentences = preg_split('/ *[.?!][\'")\]]*[ |\n](?=[A-Z])/u', $text);
        foreach ($sentences as $sentence) {
            if (self::lexiconCount($sentence) <= 2) {
                $ignoreCount++;
            }
        }
        $valid = count($sentences) - $ignoreCount;
        return $valid > 1 ? $valid : 1;
    }

    public static function syllableCount($text)
    {
        $text = self::removePunctuation(mb_strtolower($text, 'UTF-8'));
        if ($text === '') {
            return 0;
        }
        return self::syllable($text);
    }

    public static function averageSentenceLength($text)
    {
        return self::legacyRound(self::lexiconCount($text) / self::sentenceCount($text), 1);
    }

    public static function difficultWords($text)
    {
        return count(self::difficultWordsSet($text));
    }

    /**
     * Distinct (case-sensitive) words that are not Dale-Chall familiar words
     * once singular and present tense, and have 2 or more syllables.
     */
    public static function difficultWordsSet($text)
    {
        $set = array();
        if (!preg_match_all('/[A-Za-z0-9_=\x{2018}\x{2019}]+/u', $text, $matches)) {
            return $set;
        }
        foreach ($matches[0] as $word) {
            if (isset($set[$word])) {
                continue;
            }
            $normalized = self::presentTense(self::singular(mb_strtolower($word, 'UTF-8')));
            if (!DaleChallWordList::isFamiliarWord($normalized) && self::syllableCount($word) >= 2) {
                $set[$word] = true;
            }
        }
        return array_map('strval', array_keys($set));
    }

    public static function daleChallReadabilityScore($text)
    {
        $wordCount = self::lexiconCount($text);
        $difficult = self::difficultWords($text);
        if ($wordCount === 0) {
            // JavaScript: 0/0 is NaN (answers 0), n/0 is -Infinity
            return $difficult === 0 ? 0.0 : INF;
        }
        $per = ($wordCount - $difficult) / $wordCount * 100;
        $difficultPercent = 100 - $per;
        $score = (0.1579 * $difficultPercent) + (0.0496 * self::averageSentenceLength($text));
        if ($difficultPercent > 5) {
            $score += 3.6365;
        }
        return self::legacyRound($score, 2);
    }

    /**
     * text-readability's Math.legacyRound: half away from zero, then floor.
     */
    public static function legacyRound($number, $points = 0)
    {
        $p = pow(10, $points);
        $half = $number == 0 ? 0 : ($number > 0 ? 0.5 : -0.5);
        return floor(($number * $p) + $half) / $p;
    }

    public static function presentTense($word)
    {
        if (strlen($word) < 6) {
            return $word;
        }
        if (substr($word, -2) === 'ed') {
            if (DaleChallWordList::isFamiliarWord(substr($word, 0, -1))) {
                return substr($word, 0, -1);
            }
            return substr($word, 0, -2);
        }
        if (substr($word, -3) === 'ing') {
            $suffixIngToE = substr($word, 0, -3) . 'e';
            if (DaleChallWordList::isFamiliarWord($suffixIngToE)) {
                return $suffixIngToE;
            }
            return substr($word, 0, -3);
        }
        return $word;
    }

    // ---- pluralize 8.0.0 (singular only; callers pass lowercase words) ----

    private static $irregularSingles = null;
    private static $irregularPlurals = null;
    private static $uncountables = null;
    private static $singularRules = null;

    private static function pluralizeRules()
    {
        if (self::$singularRules !== null) {
            return;
        }
        $irregular = array(
            array('I', 'we'), array('me', 'us'), array('he', 'they'), array('she', 'they'),
            array('them', 'them'), array('myself', 'ourselves'), array('yourself', 'yourselves'),
            array('itself', 'themselves'), array('herself', 'themselves'), array('himself', 'themselves'),
            array('themself', 'themselves'), array('is', 'are'), array('was', 'were'), array('has', 'have'),
            array('this', 'these'), array('that', 'those'),
            array('echo', 'echoes'), array('dingo', 'dingoes'), array('volcano', 'volcanoes'),
            array('tornado', 'tornadoes'), array('torpedo', 'torpedoes'),
            array('genus', 'genera'), array('viscus', 'viscera'),
            array('stigma', 'stigmata'), array('stoma', 'stomata'), array('dogma', 'dogmata'),
            array('lemma', 'lemmata'), array('schema', 'schemata'), array('anathema', 'anathemata'),
            array('ox', 'oxen'), array('axe', 'axes'), array('die', 'dice'), array('yes', 'yeses'),
            array('foot', 'feet'), array('eave', 'eaves'), array('goose', 'geese'), array('tooth', 'teeth'),
            array('quiz', 'quizzes'), array('human', 'humans'), array('proof', 'proofs'),
            array('carve', 'carves'), array('valve', 'valves'), array('looey', 'looies'),
            array('thief', 'thieves'), array('groove', 'grooves'), array('pickaxe', 'pickaxes'),
            array('passerby', 'passersby'),
        );
        self::$irregularSingles = array();
        self::$irregularPlurals = array();
        foreach ($irregular as $pair) {
            $single = strtolower($pair[0]);
            $plural = strtolower($pair[1]);
            self::$irregularSingles[$single] = $plural;
            self::$irregularPlurals[$plural] = $single;
        }
        $rules = array(
            array('/s$/iu', ''),
            array('/(ss)$/iu', '$1'),
            array('/(wi|kni|(?:after|half|high|low|mid|non|night|[^\w]|^)li)ves$/iu', '$1fe'),
            array('/(ar|(?:wo|[ae])l|[eo][ao])ves$/iu', '$1f'),
            array('/ies$/iu', 'y'),
            array('/\b([pl]|zomb|(?:neck|cross)?t|coll|faer|food|gen|goon|group|lass|talk|goal|cut)ies$/iu', '$1ie'),
            array('/\b(mon|smil)ies$/iu', '$1ey'),
            array('/\b((?:tit)?m|l)ice$/iu', '$1ouse'),
            array('/(seraph|cherub)im$/iu', '$1'),
            array('/(x|ch|ss|sh|zz|tto|go|cho|alias|[^aou]us|t[lm]as|gas|(?:her|at|gr)o|[aeiou]ris)(?:es)?$/iu', '$1'),
            array('/(analy|diagno|parenthe|progno|synop|the|empha|cri|ne)(?:sis|ses)$/iu', '$1sis'),
            array('/(movie|twelve|abuse|e[mn]u)s$/iu', '$1'),
            array('/(test)(?:is|es)$/iu', '$1is'),
            array('/(alumn|syllab|vir|radi|nucle|fung|cact|stimul|termin|bacill|foc|uter|loc|strat)(?:us|i)$/iu', '$1us'),
            array('/(agend|addend|millenni|dat|extrem|bacteri|desiderat|strat|candelabr|errat|ov|symposi|curricul|quor)a$/iu', '$1um'),
            array('/(apheli|hyperbat|periheli|asyndet|noumen|phenomen|criteri|organ|prolegomen|hedr|automat)a$/iu', '$1on'),
            array('/(alumn|alg|vertebr)ae$/iu', '$1a'),
            array('/(cod|mur|sil|vert|ind)ices$/iu', '$1ex'),
            array('/(matr|append)ices$/iu', '$1ix'),
            array('/(pe)(rson|ople)$/iu', '$1rson'),
            array('/(child)ren$/iu', '$1'),
            array('/(eau)x?$/iu', '$1'),
            array('/men$/iu', 'man'),
            // uncountable patterns are added last, so they are tried first
            array('/pok[e\x{00E9}]mon$/iu', '$0'),
            array('/[^aeiou]ese$/iu', '$0'),
            array('/deer$/iu', '$0'),
            array('/fish$/iu', '$0'),
            array('/measles$/iu', '$0'),
            array('/o[iu]s$/iu', '$0'),
            array('/pox$/iu', '$0'),
            array('/sheep$/iu', '$0'),
        );
        self::$singularRules = $rules;
        self::$uncountables = array_fill_keys(array(
            'adulthood', 'advice', 'agenda', 'aid', 'aircraft', 'alcohol', 'ammo', 'analytics', 'anime',
            'athletics', 'audio', 'bison', 'blood', 'bream', 'buffalo', 'butter', 'carp', 'cash', 'chassis',
            'chess', 'clothing', 'cod', 'commerce', 'cooperation', 'corps', 'debris', 'diabetes', 'digestion',
            'elk', 'energy', 'equipment', 'excretion', 'expertise', 'firmware', 'flounder', 'fun', 'gallows',
            'garbage', 'graffiti', 'hardware', 'headquarters', 'health', 'herpes', 'highjinks', 'homework',
            'housework', 'information', 'jeans', 'justice', 'kudos', 'labour', 'literature', 'machinery',
            'mackerel', 'mail', 'media', 'mews', 'moose', 'music', 'mud', 'manga', 'news', 'only', 'personnel',
            'pike', 'plankton', 'pliers', 'police', 'pollution', 'premises', 'rain', 'research', 'rice',
            'salmon', 'scissors', 'series', 'sewage', 'shambles', 'shrimp', 'software', 'species', 'staff',
            'swine', 'tennis', 'traffic', 'transportation', 'trout', 'tuna', 'wealth', 'welfare', 'whiting',
            'wildebeest', 'wildlife', 'you',
        ), true);
    }

    /**
     * pluralize(word, 1) for a lowercase word.
     */
    public static function singular($word)
    {
        self::pluralizeRules();
        $token = mb_strtolower($word, 'UTF-8');
        if (isset(self::$irregularSingles[$token])) {
            return $token;
        }
        if (isset(self::$irregularPlurals[$token])) {
            return self::$irregularPlurals[$token];
        }
        if ($token === '' || isset(self::$uncountables[$token])) {
            return $word;
        }
        for ($i = count(self::$singularRules) - 1; $i >= 0; $i--) {
            $rule = self::$singularRules[$i];
            if (preg_match($rule[0], $word)) {
                return mb_strtolower((string) preg_replace($rule[0], $rule[1], $word, 1), 'UTF-8');
            }
        }
        return $word;
    }

    // ---- syllable 5.0.1 ----

    private static $syllableExpressions = null;

    private static function syllableExpressions()
    {
        if (self::$syllableExpressions !== null) {
            return self::$syllableExpressions;
        }
        $monoOne = implode('|', array(
            'awe($|d|so)', 'cia(?:l|$)', 'tia', 'cius', 'cious', '[^aeiou]giu', '[aeiouy][^aeiouy]ion',
            'iou', 'sia$', 'eous$', '[oa]gue$', '.[^aeiuoycgltdb]{2,}ed$', '.ely$', '^jua', 'uai', 'eau',
            '^busi$',
            '(?:[aeiouy](?:' . implode('|', array(
                '[bcfgklmnprsvwxyz]', 'ch', 'dg', 'g[hn]', 'lch', 'l[lv]', 'mm', 'nch', 'n[cgn]',
                'r[bcnsv]', 'squ', 's[chkls]', 'th',
            )) . ')ed$)',
            '(?:[aeiouy](?:' . implode('|', array(
                '[bdfklmnprstvy]', 'ch', 'g[hn]', 'lch', 'l[lv]', 'mm', 'nch', 'nn', 'r[nsv]', 'squ',
                's[cklst]', 'th',
            )) . ')es$)',
        ));
        $monoTwo = '[aeiouy](?:' . implode('|', array(
            '[bcdfgklmnprstvyz]', 'ch', 'dg', 'g[hn]', 'l[lv]', 'mm', 'n[cgns]', 'r[cnsv]', 'squ',
            's[cklst]', 'th',
        )) . ')e$';
        $doubleOne = '(?:' . implode('|', array(
            '([^aeiouy])\1l', '[^aeiouy]ie(?:r|s?t)', '[aeiouym]bl', 'eo', 'ism', 'asm', 'thm', 'dnt',
            'snt', 'uity', 'dea', 'gean', 'oa', 'ua', 'react?', 'orbed', 'shred', 'eings?',
            '[aeiouy]sh?e[rs]',
        )) . ')$';
        $doubleTwo = implode('|', array(
            'creat(?!u)', '[^gq]ua[^auieo]', '[aeiou]{3}', '^(?:ia|mc|coa[dglx].)', '^re(app|es|im|us)',
            '(th|d)eist',
        ));
        $doubleThree = implode('|', array(
            '[^aeiou]y[ae]', '[^l]lien', 'riet', 'dien', 'iu', 'io', 'ii', 'uen', '[aeilotu]real',
            'real[aeilotu]', 'iell', 'eo[^aeiou]', '[aeiou]y[aeiou]',
        ));
        $single = implode('|', array(
            '^(?:' . implode('|', array(
                'un', 'fore', 'ware', 'none?', 'out', 'post', 'sub', 'pre', 'pro', 'dis', 'side', 'some',
            )) . ')',
            '(?:' . implode('|', array(
                'ly', 'less', 'some', 'ful', 'ers?', 'ness', 'cians?', 'ments?', 'ettes?', 'villes?',
                'ships?', 'sides?', 'ports?', 'shires?', '[gnst]ion(?:ed|s)?',
            )) . ')$',
        ));
        $double = implode('|', array(
            '^(?:' . implode('|', array(
                'above', 'anti', 'ante', 'counter', 'hyper', 'afore', 'agri', 'infra', 'intra', 'inter',
                'over', 'semi', 'ultra', 'under', 'extra', 'dia', 'micro', 'mega', 'kilo', 'pico', 'nano',
                'macro', 'somer',
            )) . ')',
            '(?:fully|berry|woman|women|edly|union|((?:[bcdfghjklmnpqrstvwxz])|[aeiou])ye?ing)$',
        ));
        self::$syllableExpressions = array(
            'monoOne' => '/' . $monoOne . '/D',
            'monoTwo' => '/' . $monoTwo . '/D',
            'doubleOne' => '/' . $doubleOne . '/D',
            'doubleTwo' => '/' . $doubleTwo . '/D',
            'doubleThree' => '/' . $doubleThree . '/D',
            'doubleFour' => '/[^s]ia/D',
            'single' => '/' . $single . '/D',
            'double' => '/' . $double . '/D',
            'triple' => '/(creations?|ology|ologist|onomy|onomist)$/D',
        );
        return self::$syllableExpressions;
    }

    /**
     * syllable(value): accents folded to ASCII, lowercased, apostrophes
     * dropped, then each run of word characters counted on its letters.
     */
    public static function syllable($value)
    {
        $value = strtr((string) $value, ReadabilityScoreData::CHARMAP);
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace("/['\x{2019}]/u", '', $value);
        $sum = 0;
        if (preg_match_all('/[A-Za-z0-9_]+/', $value, $matches)) {
            foreach ($matches[0] as $part) {
                $sum += self::syllableOne(preg_replace('/[^a-z]/', '', $part));
            }
        }
        return $sum;
    }

    private static function syllableOne($value)
    {
        $length = strlen($value);
        if ($length === 0) {
            return 0;
        }
        if ($length < 3) {
            return 1;
        }
        if (isset(ReadabilityScoreData::PROBLEMATIC[$value])) {
            return ReadabilityScoreData::PROBLEMATIC[$value];
        }
        $singular = self::singular($value);
        if (isset(ReadabilityScoreData::PROBLEMATIC[$singular])) {
            return ReadabilityScoreData::PROBLEMATIC[$singular];
        }
        $expressions = self::syllableExpressions();
        $count = 0;
        // count some prefixes and suffixes, removing what they matched
        $value = preg_replace_callback($expressions['triple'], function () use (&$count) {
            $count += 3;
            return '';
        }, $value);
        $value = preg_replace_callback($expressions['double'], function () use (&$count) {
            $count += 2;
            return '';
        }, $value);
        $value = preg_replace_callback($expressions['single'], function () use (&$count) {
            $count += 1;
            return '';
        }, $value);
        // each run of vowels
        foreach (preg_split('/[^aeiouy]+/', $value) as $part) {
            if ($part !== '') {
                $count++;
            }
        }
        // counted as two but said as one, then counted as one but said as two
        $count -= preg_match_all($expressions['monoOne'], $value);
        $count -= preg_match_all($expressions['monoTwo'], $value);
        $count += preg_match_all($expressions['doubleOne'], $value);
        $count += preg_match_all($expressions['doubleTwo'], $value);
        $count += preg_match_all($expressions['doubleThree'], $value);
        $count += preg_match($expressions['doubleFour'], $value);
        return $count ? $count : 1;
    }

    /**
     * Invalid UTF-8 becomes U+FFFD, as Node does when it reads a file, so the
     * /u expressions above always run.
     */
    private static function scrub($text)
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }
        $previous = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        mb_substitute_character($previous);
        return $text;
    }
}
