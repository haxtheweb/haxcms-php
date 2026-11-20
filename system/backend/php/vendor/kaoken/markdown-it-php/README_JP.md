# markdown-it-php

[![Build Status](https://img.shields.io/badge/build-passing-brightgreen)](https://github.com/kaoken/markdown-it-php)
[![composer version](https://img.shields.io/badge/version-14.3.0.0-blue.svg)](https://github.com/kaoken/markdown-it-php)
[![licence](https://img.shields.io/badge/licence-MIT-blue.svg)](https://github.com/kaoken/markdown-it-php)
[![php version](https://img.shields.io/badge/php%20version-≧7.4.0-red.svg)](https://github.com/kaoken/markdown-it-php)


このジェムは、Puzrin とアレックス Kocharin による  [markdown-it Javascript package](https://github.com/markdown-it/markdown-it)ポートになります。現在、markdown-it 14.3.0 と同期しています。

__[Javascript Live demo](https://markdown-it.github.io)__

- Follows the __[CommonMark spec](http://spec.commonmark.org/)__ + に続く構文拡張機能 & シュガー (URL 自動、タイポグラファー) を追加します。
- 設定可能な構文!新しい規則を追加したり、既存のルールを置き換えたりすることもできます。
- デフォルトで [安全](https://github.com/markdown-it/markdown-it/tree/master/docs/security.md)です。


__コンテンツの表__

- [インストール](#インストール)
- [構文拡張](#構文拡張)
- [参照 / 謝辞](#参照--謝辞)
- [ライセンス](#ライセンス)

## インストール

**コンポ-サー**:

```bash
composer require kaoken/markdown-it-php
```


### シンプル版

```php
$md = new MarkdownIt();
$result = $md->render('# markdown-it rulezz!');
```

段落折り返しなしの単一行レンダリング:

```php
$md = new MarkdownIt();
$result = $md->renderInline('__markdown-it__ rulezz!');
```


### プリセットとオプションを使用した初期化

(*) プリセットは、アクティブなルールとオプションの組み合わせを定義します。`"commonmark"`、`"zero"`、または `"default"` (スキップされた場合) を設定できます。

```php
// コモンマークモード
$md = new MarkdownIt('commonmark');

// デフォルト モード
$md = new MarkdownIt();

// すべてを有効にする
$md = new MarkdownIt([
  "html"=>        true,
  "linkify"=>     true,
  "typographer"=> true
]);

// 全オプションリスト (デフォルト)
$md = new MarkdownIt([
  "html"=>         false,        // ソースで HTML タグを有効にする
  "xhtmlOut"=>     false,        // 単一のタグを閉じるには、'/' を使用します。(<br/>)
                                 // これは、CommonMarkの完全な互換性のためだけです。
  "breaks"=>       false,        // 段落の '\n'を<br>に変換する
  "langPrefix"=>   'language-',  // フェンスで囲まれたブロックの CSS 言語プレフィックス。
                                 // 外部ハイライトで便利です。
  "linkify"=>      false,        // URLに似たテキストをリンクに自動変換する

  // 言語に依存しないきれいな 置換 + 引用符 を有効にします。
  // 置換の完全なリストについては、 https://github.com/markdown-it/markdown-it/blob/master/lib/rules_core/replacements.js を参照してください。
  "typographer"=>  false,

  // タイポグラフィが有効になっているときにダブル+シングルクォートの置換ペア
  // とスマート引用符で。 StringまたはArrayのいずれかになります。
  //
  // たとえば、ロシア語は '«»„“'、ドイツ語は '„“‚‘'、
  // それと、フランス語の場合は ['«\xA0', '\xA0»', '‹\xA0', '\xA0›'] （nbspを含む）。
  "quotes"=> '“”‘’',

  // ソース文字列が変更されておらず、外部からエスケープする必要がある場合は
  // ハイライト関数。 エスケープされたHTMLを返す必要がある
  // $result が <pre... で始まる場合、内部ラッパーはスキップされます。
  "highlight"=> function (/*str, lang*/) { return ''; }
]);
```

### プラグインの読み込み

```php
$md = new MarkdownIt()
            ->plugin(plugin1)
            ->plugin(plugin2, opts, ...)
            ->plugin(plugin3);
```


### 構文のハイライト

`highlight` オプションを使用して、フェンスで囲まれたコードブロックに構文強調表示を適用します。:  
**ここのサンプルは、PHP言語のハイライトです。**

```php
// 実際のデフォルト値
$md = new MarkdownIt([
  "highlight"=> function ($str, $lang) {
    if ( $lang ) {
      try {
        return highlight_string($str);
      } catch (Exception $e) {}
    }

    return ''; // 外部デフォルトエスケープの使用
  }
]);
```

または、完全なラッパーのオーバーライド（`<pre>`にクラスを割り当てる必要がある場合）：

```php
// 実際のデフォルト値
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

 [linkify-it](https://github.com/markdown-it/linkify-it)を使用する場合 `linkify: true`。
 linkify-itを設定するには、`$md->linkify`を通してlinkifyインスタンスにアクセスします：

```php
$md->linkify->set(['fuzzyEmail'=>false]);  // トップレベルドメインとして.pyを無効にする
```



## 構文拡張

埋め込み（デフォルトで有効）：

- [Tables](https://help.github.com/articles/organizing-information-with-tables/) (GFM)
- [Strikethrough](https://help.github.com/articles/basic-writing-and-formatting-syntax/#styling-text) (GFM)

以下のプラグインは **kaoken\markdown-it-php\MarkdownIt\Plugins** ディレクトリにあります：

- [subscript](https://github.com/markdown-it/markdown-it-sub) ``\MarkdownItSub``
- [superscript](https://github.com/markdown-it/markdown-it-sup) ``\MarkdownItSup``
- [footnote](https://github.com/markdown-it/markdown-it-footnote) ``\MarkdownItFootnote``
- [definition list](https://github.com/markdown-it/markdown-it-deflist) ``\MarkdownItDeflist``
- [abbreviation](https://github.com/markdown-it/markdown-it-abbr) ``\MarkdownItAbbr``
- [emoji](https://github.com/markdown-it/markdown-it-emoji) ``\MarkdownItEmoji``
- [custom container](https://github.com/markdown-it/markdown-it-container) ``\MarkdownItContainer``
- [insert](https://github.com/markdown-it/markdown-it-ins) ``\MarkdownItIns``
- [mark](https://github.com/markdown-it/markdown-it-mark) ``\MarkdownItMark``



### ルールの管理

デフォルトでは、すべてのルールが有効になっていますが、オプションによって制限することができます。
プラグインのロード時には、すべてのルールが自動的に有効になります。

```php
// ルールを有効/無効にする
$md = (new MarkdownIt())
            ->disable([ 'link', 'image' ])
            ->enable([ 'link' ])
            ->enable('image');

// すべてを有効にする
$md = new MarkdownIt([
  "html"        => true,
  "linkify"     => true,
  "typographer" => true,
]);
```

ソース内のすべてのルールを見つけることができます:
[ParserCore](src/MarkdownIt/ParserCore.php), [ParserBlock](src/MarkdownIt/ParserBlock.php),
[ParserInline](src/MarkdownIt/ParserInline.php).


## 参照 / 謝辞

Javascriptのオリジナル版を実装をしてくれた作者に感謝！ [markdown-it](https://github.com/markdown-it/markdown-it):

- Alex Kocharin [github/rlidwka](https://github.com/rlidwka)
- Vitaly Puzrin [github/puzrin](https://github.com/puzrin)

それと、CommonMarkの仕様と実装リファレンスに関する[John MacFarlane](https://github.com/jgm)の仕事を参考にしてください。

**関連リンク:**

- https://github.com/jgm/CommonMark - C & JS のリファレンス CommonMark 実装、
  また、最新のスペック & オンラインデモが含まれています。
- http://talk.commonmark.org - CommonMarkフォーラムで、開発者が協力する良い場所です。
  
## ライセンス

[MIT](https://github.com/markdown-it/markdown-it/blob/master/LICENSE)