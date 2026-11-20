<?php
namespace Kaoken\LinkifyIt;


class Re extends \stdClass
{
    public function __construct($opts)
    {
        $opts = empty($opts) ? [] : $opts;

        $ff5c = "\x{ff5c}";
        if( PHP_VERSION_ID >= 70000){
            $ff5c = "\u{ff5c}";
        }
            // Use direct extract instead of `regenerate` to reduse browserified size
        // (0xD800 <=  x <= 0xDFFF)
        // [\0-\uD7FF\uE000-\uFFFF]|[\uD800-\uDBFF][\uDC00-\uDFFF]|[\uD800-\uDBFF](?![\uDC00-\uDFFF])|(?:[^\uD800-\uDBFF]|^)[\uDC00-\uDFFF]
//        $this->src_Any = "[\x{0000}-\x{D7FF}\x{E000}-\x{FFFF}]|[\x{DC00}-\x{DFFF}][\x{DC00}-\x{DFFF}]|[\x{D800}-\x{DBFF}](?![\x{DC00}-\x{DFFF}])|(?:[^\x{D800}-\x{DBFF}]|^)[\x{DC00}-\x{DFFF}]";
        //$this->src_Any = "[\x{0000}-\x{D7FF}\x{E000}-\x{FFFF}]|\p{Cs}\p{Cs}|\p{Cs}(?!\p{Cs})|(?:\P{Cs}|^)\p{Cs}";
        $this->src_Any = "[\x{0000}-\x{D7FF}\x{E000}-\x{FFFF}]";
        // [\0-\x1F\x7F-\x9F]
        $this->src_Cc  = "\p{Cc}";
        // [ \xA0\u1680\u2000-\u200A\u202F\u205F\u3000]
        $this->src_Z  = "\p{Z}";
        // [!-#%-\*,-\/:;\?@\[-\]_\{\}\xA1\xA7\xAB\xB6\xB7\xBB\xBF\u037E\u0387\u055A-\u055F\u0589\u058A\u05BE\u05C0\u05C3\u05C6\u05F3\u05F4\u0609\u060A\u060C\u060D\u061B\u061E\u061F\u066A-\u066D\u06D4\u0700-\u070D\u07F7-\u07F9\u0830-\u083E\u085E\u0964\u0965\u0970\u0AF0\u0DF4\u0E4F\u0E5A\u0E5B\u0F04-\u0F12\u0F14\u0F3A-\u0F3D\u0F85\u0FD0-\u0FD4\u0FD9\u0FDA\u104A-\u104F\u10FB\u1360-\u1368\u1400\u166D\u166E\u169B\u169C\u16EB-\u16ED\u1735\u1736\u17D4-\u17D6\u17D8-\u17DA\u1800-\u180A\u1944\u1945\u1A1E\u1A1F\u1AA0-\u1AA6\u1AA8-\u1AAD\u1B5A-\u1B60\u1BFC-\u1BFF\u1C3B-\u1C3F\u1C7E\u1C7F\u1CC0-\u1CC7\u1CD3\u2010-\u2027\u2030-\u2043\u2045-\u2051\u2053-\u205E\u207D\u207E\u208D\u208E\u2308-\u230B\u2329\u232A\u2768-\u2775\u27C5\u27C6\u27E6-\u27EF\u2983-\u2998\u29D8-\u29DB\u29FC\u29FD\u2CF9-\u2CFC\u2CFE\u2CFF\u2D70\u2E00-\u2E2E\u2E30-\u2E44\u3001-\u3003\u3008-\u3011\u3014-\u301F\u3030\u303D\u30A0\u30FB\uA4FE\uA4FF\uA60D-\uA60F\uA673\uA67E\uA6F2-\uA6F7\uA874-\uA877\uA8CE\uA8CF\uA8F8-\uA8FA\uA8FC\uA92E\uA92F\uA95F\uA9C1-\uA9CD\uA9DE\uA9DF\uAA5C-\uAA5F\uAADE\uAADF\uAAF0\uAAF1\uABEB\uFD3E\uFD3F\uFE10-\uFE19\uFE30-\uFE52\uFE54-\uFE61\uFE63\uFE68\uFE6A\uFE6B\uFF01-\uFF03\uFF05-\uFF0A\uFF0C-\uFF0F\uFF1A\uFF1B\uFF1F\uFF20\uFF3B-\uFF3D\uFF3F\uFF5B\uFF5D\uFF5F-\uFF65]|\uD800[\uDD00-\uDD02\uDF9F\uDFD0]|\uD801\uDD6F|\uD802[\uDC57\uDD1F\uDD3F\uDE50-\uDE58\uDE7F\uDEF0-\uDEF6\uDF39-\uDF3F\uDF99-\uDF9C]|\uD804[\uDC47-\uDC4D\uDCBB\uDCBC\uDCBE-\uDCC1\uDD40-\uDD43\uDD74\uDD75\uDDC5-\uDDC9\uDDCD\uDDDB\uDDDD-\uDDDF\uDE38-\uDE3D\uDEA9]|\uD805[\uDC4B-\uDC4F\uDC5B\uDC5D\uDCC6\uDDC1-\uDDD7\uDE41-\uDE43\uDE60-\uDE6C\uDF3C-\uDF3E]|\uD807[\uDC41-\uDC45\uDC70\uDC71]|\uD809[\uDC70-\uDC74]|\uD81A[\uDE6E\uDE6F\uDEF5\uDF37-\uDF3B\uDF44]|\uD82F\uDC9F|\uD836[\uDE87-\uDE8B]|\uD83A[\uDD5E\uDD5F]
        $this->src_P   ="\p{P}";
        // \p{\Z\P\Cc\CF} (white spaces + control + format + punctuation)
        $this->src_ZPCc = "\p{Z}|\p{P}|\p{Cc}";
        // \p{\Z\Cc} (white spaces + control)
        $this->src_ZCc = "\p{Z}|\p{Cc}";
        // Experimental. List of chars, completely prohibited in links
        // because can separate it from other part of text
        $this->text_separators = "[><{$ff5c}]";

        // All possible word characters (everything without punctuation, spaces & controls)
        // Defined via punctuation & spaces to save space
        // Should be something like \p{\L\N\S\M} (\w but without `_`)
        $this->src_pseudo_letter = "(?:(?!".$this->text_separators."|\p{Z}|\p{P}|\p{Cc})".$this->src_Any.")";
        // The same as abothe but without [0-9]
        // var src_pseudo_letter_non_d = "(?:(?![0-9]|" + src_ZPCc + ")" + src_Any + ")";
        ////////////////////////////////////////////////////////////////////////////////

        $this->src_ip4 = "(?:(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)";
        $this->src_port = "(?::(?:6(?:[0-4]\d{3}|5(?:[0-4]\d{2}|5(?:[0-2]\d|3[0-5])))|[1-5]?\d{1,4}))?";

        // Prohibit any of "@/[]()" in user/pass to avoid wrong domain fetch.

        $this->src_auth    = "(?:(?:(?!" . $this->src_ZCc . "|[@\/\[\]()]).)+@)?";
        $this->src_host_terminator =  "(?=$|" . $this->text_separators . "|" . $this->src_ZPCc . ")" .
                                      "(?!" . (isset($opts['---']) ? '-(?!--)|' : '-|') . "_|:\d|\.-|\.(?!$|" . $this->src_ZPCc . "))";

        $this->src_path =
            "(?:" .
            "[\/?#]" .
            "(?:" .
            "(?!" . $this->src_ZCc . "|" . $this->text_separators . "|[()[\]{}.,\"'?!\-;]).|" .
            "\[(?:(?!" . $this->src_ZCc . "|\]).)*\]|" .
            "\((?:(?!" . $this->src_ZCc . "|[)]).)*\)|" .
            "\{(?:(?!" . $this->src_ZCc . "|[}]).)*\}|" .
            "\\\"(?:(?!" . $this->src_ZCc . "|[\"]).)+\\\"|" .
            "\\'(?:(?!" . $this->src_ZCc . "|[']).)+\\'|" .
            // allow `I'm_king` if no pair found
            "\\'(?=" . $this->src_pseudo_letter . '|[-])|' .
            // google has many dots in "google search" links (#66, #81).
            // github has ... in commit range links,
            // Restrict to
            // - english
            // - percent-encoded
            // - parts of file path
            // - params separator
            // until more examples found.
            "\.{2,}[a-zA-Z0-9%\/&]|" .
            "\.(?!" . $this->src_ZCc . "|[.]|$)|" .
            (isset($opts) && isset($opts["---"]) ?
                "\-(?!--(?:[^-]|$))(?:-*)|" // `---` => long dash, terminate
                :
                "\-+|"
            ) .
            ",(?!" . $this->src_ZCc . "|$)|" .       // allow `,,,` in paths
            ';(?!' . $this->src_ZCc . '|$)|' .       // allow `;` if not followed by space-like char
            "\!+(?!" . $this->src_ZCc . "|[!]|$)|" . // allow `!!!` in paths, but not at the end
            "\?(?!" . $this->src_ZCc . "|[?]|$)" .
            ")+" .
            "|\/" .
            ")?";
        // Allow anything in markdown spec, forbid quote (") at the first position
        // because emails enclosed in quotes are far more common
        $this->src_email_name = "[\-;:&=\+\$,\.a-zA-Z0-9_][\-;:&=\+\$,\\\"\.a-zA-Z0-9_]{0,63}";

        $this->src_xn = "xn--[a-z0-9\-]{1,59}";



        // More to read about domain names
        // http://serverfault.com/questions/638260/
        $this->src_domain_root =

            // Allow letters & digits (http://test1)
            "(?:" .
            $this->src_xn .
            "|" .
            $this->src_pseudo_letter . "{1,63}" .
            ")";

        $this->src_domain =

            "(?:" .
            $this->src_xn .
            "|" .
            "(?:" . $this->src_pseudo_letter . ")" .
            "|" .
            "(?:" . $this->src_pseudo_letter . "(?:-|" . $this->src_pseudo_letter . "){0,61}" . $this->src_pseudo_letter . ")" .
            ")";

        $this->src_host =

            "(?:" .
            // Don"t need IP check, because digits are already allowed in normal domain names
            //   src_ip4 +
            // "|" +
            "(?:(?:(?:" . $this->src_domain . ")\.)*" . $this->src_domain/*_root*/ . ")" .
            ")";

        $this->tpl_host_fuzzy =

            "(?:" .
            $this->src_ip4 .
            "|" .
            "(?:(?:(?:" . $this->src_domain . ")\.)+(?:%TLDS%))" .
            ")";

        $this->tpl_host_no_ip_fuzzy =

            "(?:(?:(?:" . $this->src_domain . ")\.)+(?:%TLDS%))";

        $this->src_host_strict =

            $this->src_host . $this->src_host_terminator;

        $this->tpl_host_fuzzy_strict =

            $this->tpl_host_fuzzy . $this->src_host_terminator;

        $this->src_host_port_strict =

            $this->src_host . $this->src_port . $this->src_host_terminator;

        $this->tpl_host_port_fuzzy_strict =

            $this->tpl_host_fuzzy . $this->src_port . $this->src_host_terminator;

        $this->tpl_host_port_no_ip_fuzzy_strict =

            $this->tpl_host_no_ip_fuzzy . $this->src_port . $this->src_host_terminator;


        //
        // Main rules
        //

        // Rude test fuzzy links by host, for quick deny
        $this->tpl_host_fuzzy_test =
            "localhost|www\.|\.\d{1,3}\.|(?:\.(?:%TLDS%)(?:" . $this->src_ZPCc . "|>|$))";

        $this->tpl_email_fuzzy =
            "(^|" . $this->text_separators . "|\"|\\(|" . $this->src_ZCc . ")" .
            "(" . $this->src_email_name . "@" . $this->tpl_host_fuzzy_strict . ")";

        $this->tpl_link_fuzzy =
            // Fuzzy link can"t be prepended with .:/\- and non punctuation.
            // but can start with > (markdown blockquote)
            "(^|(?![.:\/\-_@])(?:[$+<=>^`|$ff5c}]|" . $this->src_ZPCc . "))" .
            "((?![$+<=>^`|$ff5c}])" . $this->tpl_host_port_fuzzy_strict . $this->src_path . ")";

        $this->tpl_link_no_ip_fuzzy =
            // Fuzzy link can"t be prepended with .:/\- and non punctuation.
            // but can start with > (markdown blockquote)
            "(^|(?![.:\/\-_@])(?:[$+<=>^`|$ff5c}]|" . $this->src_ZPCc . "))" .
            "((?![$+<=>^`|$ff5c}])" . $this->tpl_host_port_no_ip_fuzzy_strict . $this->src_path . ")";
    }
}