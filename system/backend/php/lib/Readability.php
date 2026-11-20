<?php
include_once dirname(__FILE__) . '/DaleChallWordList.php';

/**
 * Readability for the overview report (haxtheweb/issues#3389).
 *
 * haxcms-nodejs src/lib/readability.js is the same algorithm, step for step,
 * so both backends answer the same numbers for the same site.
 * tests/Unit/ReadabilityTest.php and haxcms-nodejs
 * test/unit/readability.test.cjs check the same expected values
 * (tests/fixtures/readability-vectors.json); change both together.
 *
 * 1. Text: each page's HTML, every tag replaced by a space (so words in
 *    neighbouring blocks stay apart), HTML entities decoded, no-break spaces
 *    made plain spaces. Pages are joined with a space and runs of ASCII
 *    whitespace collapse to one space.
 * 2. Words: the text split on ASCII whitespace, each token trimmed of leading
 *    and trailing characters other than a-z, A-Z and the apostrophe, then
 *    ASCII-lowercased. Empty tokens are not words.
 * 3. Difficult words: words not in the Dale-Chall familiar list
 *    (DaleChallWordList.php).
 * 4. Syllables: per word, the number of runs of a/e/i/o/u/y, at least 1.
 * 5. Sentences: runs of . ! or ?; text with words but no such mark is one
 *    sentence.
 * 6. Dale-Chall score: 0.1579 * (difficult words / words * 100)
 *    + 0.0496 * (words / sentences), plus 3.6365 when more than 5% of the
 *    words are difficult. No words scores 0.
 */
class Readability
{
    /**
     * Readable text of one page's HTML (step 1, without the joining).
     *
     * @param string $html
     * @return string
     */
    public static function pageText($html)
    {
        if (!is_string($html) || $html === '') {
            return '';
        }
        $text = preg_replace('/<[^>]*>/', ' ', $html);
        $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xC2\xA0", ' ', $text);
        return trim($text, " \t\n\r\f\v");
    }

    /**
     * The text readability is measured on, from each page's HTML (step 1).
     *
     * @param array $pagesHtml
     * @return string
     */
    public static function readableText($pagesHtml)
    {
        $parts = array();
        foreach (is_array($pagesHtml) ? $pagesHtml : array() as $html) {
            $parts[] = self::pageText($html);
        }
        $text = preg_replace('/[ \t\n\r\f\v]+/', ' ', implode(' ', $parts));
        return trim((string) $text, " \t\n\r\f\v");
    }

    /**
     * @param float $score
     * @return string
     */
    public static function gradeLevelFor($score)
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

    /**
     * Readability of text from readableText (steps 2 to 6).
     *
     * @param string $text
     * @return array gradeLevel, difficultWords, syllableCount, lexiconCount, sentenceCount
     */
    public static function readabilityOf($text)
    {
        $value = is_string($text) ? trim($text, " \t\n\r\f\v") : '';
        $tokens = $value === '' ? array() : preg_split('/[ \t\n\r\f\v]+/', $value);
        $lexiconCount = 0;
        $difficultWords = 0;
        $syllableCount = 0;
        foreach (is_array($tokens) ? $tokens : array() as $token) {
            $word = strtolower(trim((string) preg_replace("/^[^a-zA-Z']+|[^a-zA-Z']+$/", '', (string) $token)));
            if ($word === '') {
                continue;
            }
            $lexiconCount++;
            if (!DaleChallWordList::isFamiliarWord($word)) {
                $difficultWords++;
            }
            $vowelRuns = preg_match_all('/[aeiouy]+/i', $word);
            $syllableCount += is_int($vowelRuns) && $vowelRuns > 0 ? $vowelRuns : 1;
        }
        $marks = preg_match_all('/[.!?]+/', $value);
        $sentenceCount = is_int($marks) ? $marks : 0;
        if ($lexiconCount > 0 && $sentenceCount === 0) {
            $sentenceCount = 1;
        }
        $score = 0.0;
        if ($lexiconCount > 0) {
            $difficultPercent = ($difficultWords / $lexiconCount) * 100;
            $score = (0.1579 * $difficultPercent) + (0.0496 * ($lexiconCount / $sentenceCount));
            if ($difficultPercent > 5) {
                $score += 3.6365;
            }
        }
        return array(
            'gradeLevel' => self::gradeLevelFor($score),
            'difficultWords' => $difficultWords,
            'syllableCount' => $syllableCount,
            'lexiconCount' => $lexiconCount,
            'sentenceCount' => $sentenceCount,
        );
    }
}
