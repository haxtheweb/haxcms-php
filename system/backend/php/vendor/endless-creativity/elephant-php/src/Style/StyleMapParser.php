<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/style-reader.js + lib/styles/parser/tokeniser.js
//
// Hand-written tokeniser + recursive-descent parser for the v0.1 subset of
// mammoth's style-map DSL:
//
//   matcher    := ('p' | 'r') matcher_suffix*
//   suffix     := '.' IDENT                                # styleId
//               | '[' 'style-name' ('=' | '^=') STRING ']' # styleName
//   path       := '!'                                      # ignore
//               | element ('>' element)*
//   element    := IDENT (modifier)* (':' 'fresh')?
//   modifier   := '.' IDENT                                # class
//               | '[' IDENT '=' STRING ']'                 # attribute
//
// Out of scope for v0.1 (TODO): list / table / b / i / u / strike / all-caps /
// small-caps / highlight / comment-reference / br matchers, the multi-tag
// element form (`ul|ol`), and `:separator(...)`.

namespace EndlessCreativity\ElephantPhp\Style;

use InvalidArgumentException;

final class StyleMapParser
{
    public static function parse(string $rule): StyleMapping
    {
        $tokens = self::tokenise($rule);
        $parser = new self($tokens, $rule);
        $mapping = $parser->parseRule();
        $parser->expectEof();

        return $mapping;
    }

    /**
     * @param  list<string>  $rules
     */
    public static function parseAll(array $rules): StyleMap
    {
        $mappings = [];
        foreach ($rules as $rule) {
            $mappings[] = self::parse($rule);
        }

        return new StyleMap($mappings);
    }

    /** @var list<array{type: string, value: string, position: int}> */
    private array $tokens;

    private int $position = 0;

    /**
     * @param  list<array{type: string, value: string, position: int}>  $tokens
     */
    private function __construct(array $tokens, private readonly string $source)
    {
        $this->tokens = $tokens;
    }

    private function parseRule(): StyleMapping
    {
        $matcher = $this->parseMatcher();
        $this->skipWhitespace();
        if (! $this->consumeIfType('arrow')) {
            return new StyleMapping(from: $matcher, to: HtmlPath::empty());
        }
        $this->skipWhitespace();
        $path = $this->parsePath();

        return new StyleMapping(from: $matcher, to: $path);
    }

    /** @var array<string, RunProperty> */
    private const RUN_PROPERTY_MATCHERS = [
        'b' => RunProperty::Bold,
        'i' => RunProperty::Italic,
        'u' => RunProperty::Underline,
        'strike' => RunProperty::Strikethrough,
        'all-caps' => RunProperty::AllCaps,
        'small-caps' => RunProperty::SmallCaps,
    ];

    private function parseMatcher(): Matcher
    {
        $kindToken = $this->expectType('identifier');

        // The run-property matchers (b, i, u, strike, all-caps, small-caps)
        // accept no styleId / styleName suffixes -- mammoth treats them as
        // self-contained alternatives at the matcher-kind level.
        if (isset(self::RUN_PROPERTY_MATCHERS[$kindToken['value']])) {
            return new Matcher(
                kind: MatcherKind::Run,
                runProperty: self::RUN_PROPERTY_MATCHERS[$kindToken['value']],
            );
        }

        // Same shape: comment-reference is a self-contained matcher kind.
        if ($kindToken['value'] === 'comment-reference') {
            return new Matcher(kind: MatcherKind::CommentReference);
        }

        // br[type='line|page|column'] -- type is required (mammoth too).
        if ($kindToken['value'] === 'br') {
            $this->expectType('open-square-bracket');
            $name = $this->expectType('identifier');
            if ($name['value'] !== 'type') {
                throw $this->error("Expected 'type' inside br[...], got '{$name['value']}'", $name['position']);
            }
            $this->expectType('equals');
            $value = $this->expectType('string');
            $this->expectType('close-square-bracket');

            $breakType = \EndlessCreativity\ElephantPhp\Document\BreakType::tryFrom($value['value']);
            if ($breakType === null) {
                throw $this->error(
                    "Unknown br type '{$value['value']}' (expected line, page, or column)",
                    $value['position'],
                );
            }

            return new Matcher(kind: MatcherKind::BreakKind, breakType: $breakType);
        }

        // highlight or highlight[color='X']
        if ($kindToken['value'] === 'highlight') {
            $color = null;
            if ($this->consumeIfType('open-square-bracket')) {
                $name = $this->expectType('identifier');
                if ($name['value'] !== 'color') {
                    throw $this->error("Expected 'color' inside highlight[...], got '{$name['value']}'", $name['position']);
                }
                $this->expectType('equals');
                $color = $this->expectType('string')['value'];
                $this->expectType('close-square-bracket');
            }

            return new Matcher(kind: MatcherKind::Highlight, highlightColor: $color);
        }

        $kind = match ($kindToken['value']) {
            'p' => MatcherKind::Paragraph,
            'r' => MatcherKind::Run,
            'table' => MatcherKind::Table,
            default => throw $this->error("Expected 'p', 'r' or 'table' as matcher kind, got '{$kindToken['value']}'", $kindToken['position']),
        };

        $styleId = null;
        $styleName = null;
        $styleNameMatch = StyleNameMatch::Equal;

        while (true) {
            if ($this->consumeIfType('dot')) {
                $ident = $this->expectType('identifier');
                $styleId = $ident['value'];

                continue;
            }
            if ($this->consumeIfType('open-square-bracket')) {
                $name = $this->expectType('identifier');
                if ($name['value'] !== 'style-name') {
                    throw $this->error("Expected 'style-name' inside [...], got '{$name['value']}'", $name['position']);
                }
                if ($this->consumeIfType('starts-with')) {
                    $styleNameMatch = StyleNameMatch::StartsWith;
                } else {
                    $this->expectType('equals');
                }
                $styleName = $this->expectType('string')['value'];
                $this->expectType('close-square-bracket');

                continue;
            }
            break;
        }

        return new Matcher(
            kind: $kind,
            styleId: $styleId,
            styleName: $styleName,
            styleNameMatch: $styleNameMatch,
        );
    }

    private function parsePath(): HtmlPath
    {
        if ($this->consumeIfType('bang')) {
            return HtmlPath::ignore();
        }

        $elements = [];
        $elements[] = $this->parsePathElement();
        while (true) {
            $checkpoint = $this->position;
            $this->skipWhitespace();
            if (! $this->consumeIfType('gt')) {
                $this->position = $checkpoint;
                break;
            }
            $this->skipWhitespace();
            $elements[] = $this->parsePathElement();
        }

        return new HtmlPath(elements: $elements);
    }

    private function parsePathElement(): HtmlPathElement
    {
        $tag = $this->expectType('identifier');
        $attributes = [];
        $fresh = false;
        $separator = null;

        while (true) {
            if ($this->consumeIfType('dot')) {
                $ident = $this->expectType('identifier');
                $attributes['class'] = isset($attributes['class'])
                    ? $attributes['class'].' '.$ident['value']
                    : $ident['value'];

                continue;
            }
            if ($this->consumeIfType('open-square-bracket')) {
                $name = $this->expectType('identifier');
                $this->expectType('equals');
                $value = $this->expectType('string');
                $this->expectType('close-square-bracket');
                $attributes[$name['value']] = $value['value'];

                continue;
            }
            if ($this->peekType() === 'colon') {
                $checkpoint = $this->position;
                $this->position++;
                $next = $this->peek();
                if ($next !== null && $next['type'] === 'identifier' && $next['value'] === 'fresh') {
                    $this->position++;
                    $fresh = true;

                    continue;
                }
                if ($next !== null && $next['type'] === 'identifier' && $next['value'] === 'separator') {
                    $this->position++;
                    $this->expectType('open-paren');
                    $value = $this->expectType('string');
                    $this->expectType('close-paren');
                    $separator = $value['value'];

                    continue;
                }
                $this->position = $checkpoint;
            }
            break;
        }

        return new HtmlPathElement(
            tagName: $tag['value'],
            attributes: $attributes,
            fresh: $fresh,
            separator: $separator,
        );
    }

    private function expectEof(): void
    {
        $this->skipWhitespace();
        if ($this->position !== count($this->tokens)) {
            $token = $this->tokens[$this->position];
            throw $this->error("Unexpected '{$token['value']}' after rule", $token['position']);
        }
    }

    /** @return array{type: string, value: string, position: int} */
    private function expectType(string $type): array
    {
        $token = $this->peek();
        if ($token === null || $token['type'] !== $type) {
            $actual = $token === null ? 'end of input' : "'{$token['value']}'";
            throw $this->error("Expected {$type}, got {$actual}", $token['position'] ?? mb_strlen($this->source));
        }
        $this->position++;

        return $token;
    }

    private function consumeIfType(string $type): bool
    {
        $token = $this->peek();
        if ($token !== null && $token['type'] === $type) {
            $this->position++;

            return true;
        }

        return false;
    }

    /** @return ?array{type: string, value: string, position: int} */
    private function peek(): ?array
    {
        return $this->tokens[$this->position] ?? null;
    }

    private function peekType(): ?string
    {
        return $this->peek()['type'] ?? null;
    }

    private function skipWhitespace(): void
    {
        // Tokeniser emits no whitespace tokens; this is a no-op kept as a
        // documentation hook in case we restore them later.
    }

    private function error(string $message, int $position): InvalidArgumentException
    {
        return new InvalidArgumentException(
            "Could not parse style mapping at position {$position} of '{$this->source}': {$message}",
        );
    }

    /**
     * @return list<array{type: string, value: string, position: int}>
     */
    private static function tokenise(string $input): array
    {
        $tokens = [];
        $length = strlen($input);
        $i = 0;
        while ($i < $length) {
            $ch = $input[$i];
            if (ctype_space($ch)) {
                $i++;

                continue;
            }
            if ($ch === '.') {
                $tokens[] = ['type' => 'dot', 'value' => '.', 'position' => $i];
                $i++;

                continue;
            }
            if ($ch === '[') {
                $tokens[] = ['type' => 'open-square-bracket', 'value' => '[', 'position' => $i];
                $i++;

                continue;
            }
            if ($ch === ']') {
                $tokens[] = ['type' => 'close-square-bracket', 'value' => ']', 'position' => $i];
                $i++;

                continue;
            }
            if ($ch === ':') {
                $tokens[] = ['type' => 'colon', 'value' => ':', 'position' => $i];
                $i++;

                continue;
            }
            if ($ch === '>') {
                $tokens[] = ['type' => 'gt', 'value' => '>', 'position' => $i];
                $i++;

                continue;
            }
            if ($ch === '!') {
                $tokens[] = ['type' => 'bang', 'value' => '!', 'position' => $i];
                $i++;

                continue;
            }
            if ($ch === '(') {
                $tokens[] = ['type' => 'open-paren', 'value' => '(', 'position' => $i];
                $i++;

                continue;
            }
            if ($ch === ')') {
                $tokens[] = ['type' => 'close-paren', 'value' => ')', 'position' => $i];
                $i++;

                continue;
            }
            if (ctype_digit($ch)) {
                $start = $i;
                while ($i < $length && ctype_digit($input[$i])) {
                    $i++;
                }
                $tokens[] = [
                    'type' => 'integer',
                    'value' => substr($input, $start, $i - $start),
                    'position' => $start,
                ];

                continue;
            }
            if ($ch === '=' && $i + 1 < $length && $input[$i + 1] === '>') {
                $tokens[] = ['type' => 'arrow', 'value' => '=>', 'position' => $i];
                $i += 2;

                continue;
            }
            if ($ch === '=') {
                $tokens[] = ['type' => 'equals', 'value' => '=', 'position' => $i];
                $i++;

                continue;
            }
            if ($ch === '^' && $i + 1 < $length && $input[$i + 1] === '=') {
                $tokens[] = ['type' => 'starts-with', 'value' => '^=', 'position' => $i];
                $i += 2;

                continue;
            }
            if ($ch === "'") {
                $start = $i;
                $i++;
                $value = '';
                while ($i < $length && $input[$i] !== "'") {
                    // Backslash escapes mirror mammoth's: `\n`, `\r`, `\t`,
                    // `\\`, `\'`. Any other `\x` is left as the literal two
                    // characters so unknown escapes fail loudly downstream
                    // rather than silently dropping the backslash.
                    if ($input[$i] === '\\' && $i + 1 < $length) {
                        $value .= match ($input[$i + 1]) {
                            'n' => "\n",
                            'r' => "\r",
                            't' => "\t",
                            '\\' => '\\',
                            "'" => "'",
                            default => '\\'.$input[$i + 1],
                        };
                        $i += 2;

                        continue;
                    }
                    $value .= $input[$i];
                    $i++;
                }
                if ($i >= $length) {
                    throw new InvalidArgumentException(
                        "Unterminated string starting at position {$start} of '{$input}'",
                    );
                }
                $i++; // closing quote
                $tokens[] = ['type' => 'string', 'value' => $value, 'position' => $start];

                continue;
            }
            if (preg_match('/[A-Za-z_]/', $ch) === 1) {
                $start = $i;
                while ($i < $length && preg_match('/[A-Za-z0-9_-]/', $input[$i]) === 1) {
                    $i++;
                }
                $tokens[] = [
                    'type' => 'identifier',
                    'value' => substr($input, $start, $i - $start),
                    'position' => $start,
                ];

                continue;
            }
            throw new InvalidArgumentException("Unexpected character '{$ch}' at position {$i} of '{$input}'");
        }

        return $tokens;
    }
}
