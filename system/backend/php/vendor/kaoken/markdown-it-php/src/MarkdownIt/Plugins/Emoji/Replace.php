<?php
// Emojies & $this->shortcuts replacement logic.
//
// Note: In theory, it could be faster to parse :smile: in inline chain and
// leave only $this->shortcuts here. But, who care...
//
namespace Kaoken\MarkdownIt\Plugins\Emoji;

use Kaoken\MarkdownIt\MarkdownIt;
use Kaoken\MarkdownIt\RulesCore\StateCore;

class Replace
{
    /**
     * @var array
     */
    protected array $emojies;
    /**
     * @var array
     */
    protected array $shortcuts;
    /**
     * @var string
     */
    protected string $scanRE;
    /**
     * @var string
     */
    protected string $replaceRE;

    /**
     * Replace constructor.
     * @param MarkdownIt $md
     * @param array $emojies
     * @param array $shortcuts
     * @param string $scanRE
     * @param string $replaceRE
     */
    public function __construct(MarkdownIt $md, array &$emojies, array &$shortcuts, string &$scanRE, string &$replaceRE)
    {
        $this->emojies = $emojies;
        $this->shortcuts = $shortcuts;
        $this->scanRE = $scanRE;
        $this->replaceRE = $replaceRE;
    }


    /**
     * @param StateCore $state
     * @param string $text
     * @param int $level
     * @return array
     */
    protected function splitTextToken(StateCore &$state, string $text, int $level): array
    {
        $last_pos = 0;
        $nodes = [];

        if(preg_match_all($this->replaceRE,$text,$m,PREG_SET_ORDER|PREG_OFFSET_CAPTURE))
        {
            foreach ($m as &$match) {
                $match = $match[0];
                $offset = $match[1];
                // Validate emoji name
                if ( isset($this->shortcuts[$match[0]]) ) {
                    // replace shortcut with full name
                    $emoji_name = $this->shortcuts[$match[0]];

                    // Don't allow letters before any shortcut (as in no ":/" in http://)
                    if ($offset > 0 && !preg_match("/\p{Z}|\p{P}|\p{Cc}/u", $text[$offset - 1] )) {
                        continue;
                    }

                    // Don't allow letters after any shortcut
                    if ($offset + strlen($match[0]) < strlen($text) && !preg_match("/\p{Z}|\p{P}|\p{Cc}/u", $text[$offset + strlen($match[0])] )) {
                        continue;
                    }
                } else {
                    $emoji_name = substr($match[0], 1, -1);
                }

                // Add new $tokens to pending list
                if ($offset > $last_pos) {
                    $token         = $state->createToken('text', '', 0);
                    $token->content = substr($text, $last_pos, $offset-$last_pos);
                    $nodes[] = $token;
                }

                $token          = $state->createToken('emoji', '', 0);
                $token->markup  = $emoji_name;
                $token->content = $this->emojies[$emoji_name] ?? 'error';
                $nodes[]        = $token;

                $last_pos = $offset + strlen($match[0]);
            }
        }

        if ($last_pos < strlen($text)) {
            $token         = $state->createToken('text', '', 0);
            $token->content = substr($text, $last_pos);
            $nodes[]       = $token;
        }

        return $nodes;
    }

    /**
     * @param StateCore $state
     */
    public function replace(StateCore &$state)
    {
        $blockTokens = &$state->tokens;
        $autolinkLevel = 0;

        for ($j = 0, $l = count($blockTokens); $j < $l; $j++) {
            if ($blockTokens[$j]->type !== 'inline') { continue; }
            $tokens = &$blockTokens[$j]->children;

            // We scan from the end, to keep position when new tags added.
            // Use reversed logic in links start/end $match
            for ($i = count($tokens) - 1; $i >= 0; $i--) {
                $token = $tokens[$i];

                if ($token->type === 'link_open' || $token->type === 'link_close') {
                    if ($token->info === 'auto') { $autolinkLevel -= $token->nesting; }
                }

                if ($token->type === 'text' && $autolinkLevel === 0 && preg_match($this->scanRE, $token->content)) {
                    // replace current node
                    $blockTokens[$j]->children = $tokens = $state->md->utils->arrayReplaceAt(
                        $tokens, $i, $this->splitTextToken($state, $token->content, $token->level)
                    );
                }
            }
        }
    }
}