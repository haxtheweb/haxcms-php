<?php
/**
Copyright (c) 2016 Kaoken, Vitaly Puzrin.

This software is released under the MIT License.
http://opensource.org/licenses/mit-license.php
 */
namespace Kaoken\LinkifyIt;


class Def
{
    /**
     * @var LinkifyIt
     */
    private LinkifyIt $linkifyIt;
    /**
     * @var array
     */
    protected array $options=[];
    protected array $schemas = [
        'http:'=>null,
        'https:'=>'http:',
        'ftp:'=>'http:',
        '//'=>null,
        'mailto:'=>null
    ];


    // DON'T try to make PRs with changes. Extend TLDs with LinkifyIt.tlds() instead
    // 'biz|com|edu|gov|net|org|pro|web|xxx|aero|asia|coop|info|museum|name|shop|рф'
    protected array $tlds_default = ['biz','com','edu','gov','net','org','pro','web','xxx','aero','asia','coop','info','museum','name','shop','рф'];

    /**
     * @return string
     */
    public function getTlds2chSrcRe(): string
    {
        return $this->tlds_2ch_src_re;
    }
    /**
     * @return array|string
     */
    public function getTldsDefault()
    {
        return $this->tlds_default;
    }
    /**
     * @return array
     */
    public function &getOption()
    {
        return $this->options;
    }

    /**
     * @return array
     */
    public function &getSchemas()
    {
        return $this->schemas;
    }

    /**
     * CDefault constructor.
     * @param LinkifyIt $linkifyIt
     */
    public function __construct(LinkifyIt $linkifyIt)
    {
        $this->linkifyIt = $linkifyIt;
        $this->options["fuzzyLink"] = true;
        $this->options["fuzzyEmail"] = true;
        $this->options["fuzzyIP"] = false;
        //-----------------------------
        // HTTP
        $http = new \stdClass();
        /**
         * @param string  $text
         * @param integer $pos
         * @return int
         */
        $http->validate = function ($text, $pos) {
            if (!isset($this->linkifyIt->re->http)) {
                // compile lazily, because "host"-containing variables can change on tlds update.
                $this->linkifyIt->re->http =
                    "/\G\/\/" . $this->linkifyIt->re->src_auth . $this->linkifyIt->re->src_host_port_strict . $this->linkifyIt->re->src_path . '/ui';
            }
            if (preg_match($this->linkifyIt->re->http, $text, $match, 0, $pos)) {
                return strlen ($match[0]);
            }
            return 0;
        };
        $this->schemas['http:'] = $http;

        //-----------------------------
        // '//'
        $slash = new \stdClass();
        $slash->validate = function ($text, $pos) {
            if (!isset($this->linkifyIt->re->no_http)) {
                // compile lazily, because "host"-containing variables can change on tlds update.
                $this->linkifyIt->re->no_http =
                    '/\G' .
                    $this->linkifyIt->re->src_auth .
                    // Don't allow single-level domains, because of false positives like '//test'
                    // with code comments
                    '(?:localhost|(?:(?:' . $this->linkifyIt->re->src_domain . ")\.)+" . $this->linkifyIt->re->src_domain_root . ')' .
                    $this->linkifyIt->re->src_port .
                    $this->linkifyIt->re->src_host_terminator .
                    $this->linkifyIt->re->src_path .
                    '/ui';
            }

            if (preg_match($this->linkifyIt->re->no_http, $text, $match, 0, $pos)) {
                // should not be `://` & `///`, that protects from errors in protocol name
                if ($pos >= 3 && $text[$pos - 3] === ':') { return 0; }
                if ($pos >= 3 && $text[$pos - 3] === '/') { return 0; }
                return strlen ($match[0]);
            }
            return 0;
        };
        $this->schemas['//'] = $slash;

        //-----------------------------
        // mailto:
        $mailto  = new \stdClass();
        $mailto->validate = function ($text, $pos) {
            if (!isset($this->linkifyIt->re->mailto)) {
                $this->linkifyIt->re->mailto =
                    '/\G' . $this->linkifyIt->re->src_email_name . '@' . $this->linkifyIt->re->src_host_strict . '/ui';
            }
            if (preg_match($this->linkifyIt->re->mailto, $text, $match, 0, $pos)) {
                return strlen ($match[0]);
            }
            return 0;
        };
        $this->schemas['mailto:'] = $mailto;
    }
}