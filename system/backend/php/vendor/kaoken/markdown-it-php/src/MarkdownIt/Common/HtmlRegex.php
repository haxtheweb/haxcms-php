<?php
namespace Kaoken\MarkdownIt\Common;


class HtmlRegex
{
	 const ATTR_NAME     = "[a-zA-Z_:][a-zA-Z0-9:._-]*";

	 const UNQUOTED      = "[^\"'=<>`\p{Cc} ]+";
	 const SINGLE_QUOTED = "'[^']*'";
	 const DOUBLE_QUOTED = '"[^"]*"';

	 const ATTR_VALUE  = '(?:' . self::UNQUOTED . '|' . self::SINGLE_QUOTED . '|' . self::DOUBLE_QUOTED . ')';

	 const ATTRIBUTE   = "(?:\s." . self::ATTR_NAME . "(?:\s*=\s*" . self::ATTR_VALUE . ')?)';

	 const OPEN_TAG    = "<[A-Za-z][A-Za-z0-9\-]*" . self::ATTRIBUTE . "*\s*\/?>";

	 const CLOSE_TAG   = "<\/[A-Za-z][A-Za-z0-9\-]*\s*>";
	 const COMMENT     = '<!---?>|<!--(?:[^-]|-[^-]|--[^>])*-->';
	 const PROCESSING  = '<[?][\\s\\S]*?[?]>';
	 const DECLARATION = "<![A-Za-z][^>]*>";
	 const CDATA       = "<!\[CDATA\[[\s\S]*?\]\]>";

	 const HTML_TAG_RE = '/^(?:' . self::OPEN_TAG . '|' . self::CLOSE_TAG . '|' . self::COMMENT . '|' . self::PROCESSING . '|' . self::DECLARATION . '|' . self::CDATA . ')/u';
	 const HTML_OPEN_CLOSE_TAG = '^(?:' . self::OPEN_TAG . '|' . self::CLOSE_TAG . ')';
}