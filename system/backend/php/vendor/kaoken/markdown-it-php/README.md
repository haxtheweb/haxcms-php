# markdown-it-php

[![Build Status](https://img.shields.io/badge/build-passing-brightgreen)](https://github.com/kaoken/markdown-it-php)
[![composer version](https://img.shields.io/badge/version-14.3.0.0-blue.svg)](https://github.com/kaoken/markdown-it-php)
[![licence](https://img.shields.io/badge/licence-MIT-blue.svg)](https://github.com/kaoken/markdown-it-php)
[![php version](https://img.shields.io/badge/php%20version-≧7.4.0-red.svg)](https://github.com/kaoken/markdown-it-php)


This gem is a port of the [markdown-it Javascript package](https://github.com/markdown-it/markdown-it) by Vitaly Puzrin and Alex Kocharin. Currently synced with markdown-it 14.3.0

__[Javascript Live demo](https://markdown-it.github.io)__

- Follows the __[CommonMark spec](http://spec.commonmark.org/)__ + adds syntax extensions & sugar (URL autolinking, typographer).
- Configurable syntax! You can add new rules and even replace existing ones.
- [Safe](https://github.com/markdown-it/markdown-it/tree/master/docs/security.md) by default.


__Table of content__

- [Install](#install)
- [Syntax extensions](#syntax-extensions)
- [References / Thanks](#references--thanks)
- [License](#license)

## Install

**composer**:

```bash
composer require kaoken/markdown-it-php
```


### Simple

```php
$md = new MarkdownIt();
$result = $md->render('# markdown-it rulezz!');
```

Single line rendering, without paragraph wrap:

```php
$md = new MarkdownIt();
$result = $md->renderInline('__markdown-it__ rulezz!');
```


### Init with presets and options

(*) presets define combinations of active rules and options. Can be
`"commonmark"`, `"zero"` or `"default"` (if skipped).

```php
// commonmark mode
$md = new MarkdownIt('commonmark');

// default mode
$md = new MarkdownIt();

// enable everything
$md = new MarkdownIt([
  "html"=>        true,
  "linkify"=>     true,
  "typographer"=> true
]);

// full options list (defaults)
$md = new MarkdownIt([
  "html"=>         false,        // Enable HTML tags in source
  "xhtmlOut"=>     false,        // Use '/' to close single tags (<br />).
                                 // This is only for full CommonMark compatibility.
  "breaks"=>       false,        // Convert '\n' in paragraphs into <br>
  "langPrefix"=>   'language-',  // CSS language prefix for fenced blocks. Can be
                                 // useful for external highlighters.
  "linkify"=>      false,        // Autoconvert URL-like text to links

  // Enable some language-neutral replacement + quotes beautification
  // For the full list of replacements, see https://github.com/markdown-it/markdown-it/blob/master/lib/rules_core/replacements.js
  "typographer"=>  false,

  // Double + single quotes replacement pairs, when typographer enabled,
  // and smartquotes on. Could be either a String or an Array.
  //
  // For example, you can use '«»„“' for Russian, '„“‚‘' for German,
  // and ['«\xA0', '\xA0»', '‹\xA0', '\xA0›'] for French (including nbsp).
  "quotes"=> '“”‘’',

  // Highlighter function. Should return escaped HTML,
  // or '' if the source string is not changed and should be escaped externaly.
  // If $result starts with <pre... internal wrapper is skipped.
  "highlight"=> function (/*str, lang*/) { return ''; }
]);
```

### Plugins load

```php
$md = new MarkdownIt()
            ->plugin(plugin1)
            ->plugin(plugin2, opts, ...)
            ->plugin(plugin3);
```


### Syntax highlighting

Apply syntax highlighting to fenced code blocks with the `highlight` option:  
**The sample here is only the highlight of the PHP language.**
```php

// Actual default values
$md = new MarkdownIt([
  "highlight"=> function ($str, $lang) {
    if ( $lang ) {
      try {
        return highlight_string($str);
      } catch (Exception $e) {}
    }

    return ''; // use external default escaping
  }
]);
```

Or with full wrapper override (if you need assign class to `<pre>`):

```php
// Actual default values
$md = new MarkdownIt([
  "highlight"=> function ($str, $lang) {
    if ( $lang ) {
      try {
        return '<pre><code class="hljs">' .
               highlight_string($str) .
               '</code></pre>';
      } catch (Exception $e) {}
    }

    return '<pre><code class="hljs">' . $md->utils->escapeHtml($str) . '</code></pre>';
  }
]);
```

### Linkify

`linkify: true` uses [linkify-it](https://github.com/markdown-it/linkify-it). To
configure linkify-it, access the linkify instance through `$md->linkify`:

```php
$md->linkify->set(['fuzzyEmail'=>false]);  // disables .py as top level domain
```


## Syntax extensions

Embedded (enabled by default):

- [Tables](https://help.github.com/articles/organizing-information-with-tables/) (GFM)
- [Strikethrough](https://help.github.com/articles/basic-writing-and-formatting-syntax/#styling-text) (GFM)

The following plugins are in the **kaoken\markdown-it-php\MarkdownIt\Plugins** directory:

- [subscript](https://github.com/markdown-it/markdown-it-sub) ``\MarkdownItSub``
- [superscript](https://github.com/markdown-it/markdown-it-sup) ``\MarkdownItSup``
- [footnote](https://github.com/markdown-it/markdown-it-footnote) ``\MarkdownItFootnote``
- [definition list](https://github.com/markdown-it/markdown-it-deflist) ``\MarkdownItDeflist``
- [abbreviation](https://github.com/markdown-it/markdown-it-abbr) ``\MarkdownItAbbr``
- [emoji](https://github.com/markdown-it/markdown-it-emoji) ``\MarkdownItEmoji``
- [custom container](https://github.com/markdown-it/markdown-it-container) ``\MarkdownItContainer``
- [insert](https://github.com/markdown-it/markdown-it-ins) ``\MarkdownItIns``
- [mark](https://github.com/markdown-it/markdown-it-mark) ``\MarkdownItMark``



### Manage rules

By default all rules are enabled, but can be restricted by options. On plugin
load all its rules are enabled automatically.

```php
// Activate/deactivate rules, with currying
$md = (new MarkdownIt())
            ->disable([ 'link', 'image' ])
            ->enable([ 'link' ])
            ->enable('image');

// Enable everything
$md = new MarkdownIt([
  "html"        => true,
  "linkify"     => true,
  "typographer" => true,
]);
```
You can find all rules in sources:
[ParserCore](src/MarkdownIt/ParserCore.php), [ParserBlock](src/MarkdownIt/ParserBlock.php),
[ParserInline](src/MarkdownIt/ParserInline.php).

## References / Thanks

Thanks to the authors of the original implementation in Javascript, [markdown-it](https://github.com/markdown-it/markdown-it):

- Alex Kocharin [github/rlidwka](https://github.com/rlidwka)
- Vitaly Puzrin [github/puzrin](https://github.com/puzrin)

and to [John MacFarlane](https://github.com/jgm) for his work on the
CommonMark spec and reference implementations.

**Related Links:**

- https://github.com/jgm/CommonMark - reference CommonMark implementations in C & JS,
  also contains latest spec & online demo.
- http://talk.commonmark.org - CommonMark forum, good place to collaborate
  developers' efforts.
  
## License

[MIT](https://github.com/markdown-it/markdown-it/blob/master/LICENSE)
