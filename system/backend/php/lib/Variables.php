<?php
// default theme name
define("HAXCMS_DEFAULT_THEME", "clean-two");
define("HAXCMS_FALLBACK_HEX", "#3f51b5");
// Security (H1 rotation): grace window in seconds during which the immediately
// previous refresh-token jti is still accepted, so concurrent multi-tab
// refreshes don't mutually invalidate. Mirrors NodeJS HAXCMS_REFRESH_GRACE_SECONDS.
define("HAXCMS_REFRESH_GRACE_SECONDS", 30);
