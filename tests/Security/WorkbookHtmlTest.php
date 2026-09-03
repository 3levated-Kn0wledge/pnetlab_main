<?php
/**
 * Workbook HTML is sanitized on the way in and on the way out.
 *
 * A lab's HTML workbook is CKEditor output, stored per page as
 * btoa(escape(html)). api.php's workbook/update handed it to
 * Lab::updateContent() as it came, and HTMLView.js rendered it with
 * dangerouslySetInnerHTML after output_secure(), which was
 *
 *     string.replace('/<\/?script>?/gmi', '')
 *
 * -- a string literal, not a RegExp, so it removed nothing. Any lab editor
 * could store <img src=x onerror=...> and run it in the browser of every user
 * who opened the workbook, an administrator included, with that user's
 * session and XSRF cookie.
 *
 * Two layers now, neither trusting the other:
 *
 *   IN   includes/html_sanitizer.php reduces every page to an allowlist in
 *        Lab::updateContent(). Behavioural here: the file is required on its
 *        own and fed payloads.
 *   OUT  default.js output_secure() is DOMPurify. Source-level here, since a
 *        browser is not available: the call, its options, the script tag that
 *        loads the library before default.js, and that the public copy of both
 *        files is the resources copy.
 */

require_once __DIR__ . '/../bootstrap.php';

$root = realpath(__DIR__ . '/../..');

echo "workbook HTML\n";

// ------------------------------------------------------------- the server side

echo "  -- the sanitizer\n";

require_once $root . '/includes/html_sanitizer.php';

/** True when $needle is nowhere in the sanitized $html, case-insensitively. */
function gone($html, $needle)
{
    return stripos(unl_sanitize_html($html), $needle) === false;
}

// The classes Codex asked to see covered, plus the ones a sanitizer that only
// strips <script> would miss.
$payloads = [
    'event handler on img'          => ['<img src=x onerror="alert(1)">', 'onerror'],
    'event handler, no quotes'      => ['<img src=x onerror=alert(1)>', 'onerror'],
    'event handler, mixed case'     => ['<img src=x OnErRoR=alert(1)>', 'onerror'],
    'svg onload'                    => ['<svg onload=alert(1)><circle/></svg>', 'svg'],
    'svg content dropped whole'     => ['<svg><script>alert(1)</script></svg>', 'alert'],
    'math'                          => ['<math><mi xlink:href="javascript:alert(1)">x</mi></math>', 'math'],
    'javascript: href'              => ['<a href="javascript:alert(1)">x</a>', 'javascript'],
    'javascript: with a tab'        => ["<a href=\"java\tscript:alert(1)\">x</a>", 'script'],
    'javascript: with a newline'    => ["<a href=\"java\nscript:alert(1)\">x</a>", 'script'],
    'JAVASCRIPT: upper case'        => ['<a href="JAVASCRIPT:alert(1)">x</a>', 'javascript'],
    'vbscript: href'                => ['<a href="vbscript:msgbox(1)">x</a>', 'vbscript'],
    'data:text/html href'           => ['<a href="data:text/html,<script>alert(1)</script>">x</a>', 'data:'],
    'data:image/svg+xml src'        => ['<img src="data:image/svg+xml;base64,PHN2Zz4=">', 'svg'],
    'script element'                => ['<p>a<script>alert(1)</script>b</p>', 'script'],
    'script content dropped'        => ['<p>a<script>alert(1)</script>b</p>', 'alert'],
    'script, unclosed'              => ['<script>alert(1)', 'alert'],
    'style element'                 => ['<style>body{display:none}</style>', 'style'],
    'iframe'                        => ['<iframe src="https://e/"></iframe>', 'iframe'],
    'iframe srcdoc'                 => ['<iframe srcdoc="<script>alert(1)</script>"></iframe>', 'alert'],
    'object'                        => ['<object data="x"></object>', 'object'],
    'embed'                         => ['<embed src="x">', 'embed'],
    'form and autofocus input'      => ['<form><input onfocus=alert(1) autofocus></form>', 'input'],
    'base'                          => ['<base href="https://e/">', 'base'],
    'meta refresh'                  => ['<meta http-equiv="refresh" content="0;url=javascript:alert(1)">', 'meta'],
    'link stylesheet'               => ['<link rel="stylesheet" href="https://e/x.css">', 'link'],
    'style url()'                   => ['<p style="background: url(https://e/x)">x</p>', 'url('],
    'style expression()'            => ['<p style="width: expression(alert(1))">x</p>', 'expression'],
    'style with a CSS escape'       => ['<p style="background: \75 rl(https://e/x)">x</p>', '\\'],
    'style behavior'                => ['<p style="behavior: url(x.htc)">x</p>', 'behavior'],
    'style -moz-binding'            => ['<p style="-moz-binding: url(x)">x</p>', 'binding'],
    'style property not listed'     => ['<p style="position: fixed; top: 0">x</p>', 'position'],
    'style @import'                 => ['<p style="color: red; @import url(x)">x</p>', 'import'],
    'comment'                       => ['<!-- <script>alert(1)</script> -->', 'alert'],
    'conditional comment'           => ['<!--[if IE]><script>alert(1)</script><![endif]-->', 'script'],
    'processing instruction'        => ['<?php echo 1; ?><p>x</p>', 'php'],
    'CDATA'                         => ['<![CDATA[<script>alert(1)</script>]]>', '<script'],
    'attribute with markup'         => ['<p title="&quot;&gt;&lt;img src=x onerror=alert(1)&gt;">x</p>', 'onerror'],
    'unknown element unwrapped'     => ['<custom-x onclick=alert(1)>x</custom-x>', 'custom'],
    'unknown element handler gone'  => ['<custom-x onclick=alert(1)>x</custom-x>', 'onclick'],
    'nested unclosed tags'          => ['<p><b><i>x</p><img src=x onerror=alert(1)', 'onerror'],
    'malformed tag soup'            => ['<<img src=x onerror=alert(1)//>', 'onerror'],
    'entity-encoded handler'        => ['<img src=x &#111;nerror=alert(1)>', 'onerror'],
    'template'                      => ['<template><img src=x onerror=alert(1)></template>', 'onerror'],
    'noscript'                      => ['<noscript><p title="</noscript><img src=x onerror=alert(1)>">', 'onerror'],
    'target other than _blank'      => ['<a href="https://x/" target="top">x</a>', 'target'],
    'xlink:href'                    => ['<a xlink:href="javascript:alert(1)">x</a>', 'xlink'],
    'srcset'                        => ['<img srcset="x 1x, javascript:alert(1) 2x">', 'srcset'],
    'formaction'                    => ['<button formaction="javascript:alert(1)">x</button>', 'formaction'],
    'video with an event handler'   => ['<video src=x onerror=alert(1)></video>', 'onerror'],
    'a data- attribute'             => ['<p data-x="1">x</p>', 'data-x'],
];
foreach ($payloads as $name => $case) {
    assert_true(gone($case[0], $case[1]), "$name: '{$case[1]}' does not survive");
}

// The markup the editor makes has to survive, or the sanitizer is a way to
// lose every workbook on the next save.
echo "  -- what the editor writes\n";

$kept = [
    '<p>hello <strong>bold</strong> <i>it</i> <u>u</u> <s>s</s> <sub>a</sub><sup>b</sup></p>',
    '<h2>Heading</h2><h3>Sub</h3>',
    '<ul><li>one</li><li>two</li></ul><ol start="3"><li>three</li></ol>',
    '<blockquote><p>quoted</p></blockquote><pre><code>code</code></pre>',
    '<a href="https://example.test/path?q=1#f">link</a>',
    '<a href="mailto:someone@example.test">mail</a>',
    '<a href="/relative/path">rel</a>',
    '<figure class="image image-style-side"><img src="data:image/png;base64,iVBORw0KGgo=" alt="alt"><figcaption>cap</figcaption></figure>',
    '<img src="https://example.test/x.png" width="100" height="50">',
    '<figure class="table"><table><thead><tr><th>h</th></tr></thead><tbody><tr><td colspan="2" rowspan="2">c</td></tr></tbody></table></figure>',
    '<figure class="media"><oembed url="https://www.youtube.com/watch?v=x"></oembed></figure>',
    '<p style="text-align: center; color: red; font-size: 14px; font-family: Arial, sans-serif">styled</p>',
    '<p style="margin: 10px 0; padding: 4px; border: 1px solid #ccc; background-color: #f0f0f0; width: 50%">boxed</p>',
    '<span style="color: hsl(0, 75%, 60%); background-color: rgb(255, 255, 0)">c</span>',
    '<h2 menu_id="m1" id="s1" class="x">menu target</h2>',
    '<hr><br><p>&amp; &lt; &gt; entities</p>',
    '<p>é ü ñ 日本語 🙂 non-ASCII</p>',
];
foreach ($kept as $html) {
    assert_same($html, unl_sanitize_html($html), 'kept: ' . mb_substr($html, 0, 60));
}

// target=_blank gets rel=noopener whether or not the editor set it.
assert_same('<a href="https://x/" target="_blank" rel="noopener noreferrer">x</a>',
    unl_sanitize_html('<a href="https://x/" target="_blank">x</a>'),
    'target=_blank is kept with rel=noopener noreferrer');
assert_same('', unl_sanitize_html(''), 'empty in, empty out');
assert_same('', unl_sanitize_html('   '), 'whitespace in, empty out');
assert_same('plain text', unl_sanitize_html('plain text'), 'text without markup is untouched');
// libxml writes a quotation mark in TEXT as the character, not the entity. Same
// DOM, different spelling; not a loss.
assert_same('<p>a " b</p>', unl_sanitize_html('<p>a &quot; b</p>'), 'an encoded quote in text is re-serialised bare');

// ------------------------------------------- the encoding around the sanitizer

echo "  -- escape()/unescape()\n";

// What a browser's escape() writes for these, measured.
assert_same('%3Cp%3Ehi%3C/p%3E', unl_js_escape('<p>hi</p>'), 'ASCII markup');
assert_same('a+b/c@d*e_f-g.h', unl_js_escape('a+b/c@d*e_f-g.h'), 'the seven unencoded punctuation characters');
assert_same('%20%22%27%26%3D', unl_js_escape(' "\'&='), 'space, quotes, ampersand, equals');
assert_same('%E9%FC', unl_js_escape('éü'), 'Latin-1 range as %XX');
assert_same('%u65E5%u672C', unl_js_escape('日本'), 'BMP as %uXXXX');
assert_same('%uD83D%uDE42', unl_js_escape('🙂'), 'astral as a surrogate pair');
foreach (['<p>hi</p>', 'éü', '日本', '🙂', "a\tb\nc", '100%', '%u1234 literal', ''] as $s) {
    assert_same($s, unl_js_unescape(unl_js_escape($s)), 'round trip: ' . json_encode($s));
}
assert_same('<p>x</p>', unl_js_unescape('%3Cp%3Ex%3C/p%3E'), 'unescape of browser output');
assert_same('%zz%u12', unl_js_unescape('%zz%u12'), 'a % that is not an escape is literal, as in the browser');

// ------------------------------------------------ one page, end to end

echo "  -- a stored page\n";

$page = base64_encode(unl_js_escape('<h2 menu_id="m1">Title</h2><p>text</p><img src=x onerror=alert(1)>'));
$clean = unl_sanitize_workbook_page($page);
$html = unl_js_unescape(base64_decode($clean, true));
assert_same('<h2 menu_id="m1">Title</h2><p>text</p><img src="x">', $html,
    'the page comes back sanitized, and still btoa(escape())-encoded');
assert_same('', unl_sanitize_workbook_page('not base64!'), 'a page that does not decode becomes an empty page');
assert_same('', unl_sanitize_workbook_page(['array']), 'a page that is not a string becomes an empty page');

// ---------------------------------------------------- the call site, on write

echo "  -- Lab::updateContent()\n";

$lab = code_only($root . '/includes/__lab.php');
$fn = substr($lab, strpos($lab, 'function updateContent'));
$fn = substr($fn, 0, strpos($fn, "\n    public function "));
assert_true(strpos($fn, "array_map('unl_sanitize_workbook_page', \$content)") !== false,
    'updateContent() sanitizes every page of an html workbook');
assert_true(strpos($fn, 'unl_sanitize_workbook_page($content)') !== false,
    'and a page given as a bare string');
$pSan = strpos($fn, 'unl_sanitize_workbook_page');
$pSet = strpos($fn, '$workbook->content = $content');
assert_true($pSan !== false && $pSet !== false && $pSan < $pSet,
    'before it is stored');
$init = code_only($root . '/includes/init.php');
assert_true(strpos($init, "/html/includes/html_sanitizer.php") !== false,
    'init.php loads the sanitizer, so api.php has it');

// ---------------------------------------------------------- the client side

echo "  -- output_secure()\n";

$js = file_get_contents($root . '/store/resources/assets/js/default.js');
$pos = strpos($js, 'function output_secure(');
assert_true($pos !== false, 'default.js defines output_secure()');
$body = substr($js, $pos, strpos($js, "\n}\n", $pos) - $pos);
assert_true(strpos($body, "replace('/<") === false,
    'the string-literal replace() is gone');
assert_true(strpos($body, 'DOMPurify.sanitize(') !== false,
    'output_secure() is DOMPurify.sanitize()');
assert_true(preg_match('/USE_PROFILES:\s*\{\s*html:\s*true\s*\}/', $body) === 1,
    'restricted to the HTML profile: no SVG, no MathML');
assert_true(preg_match("/ADD_TAGS:\s*\['oembed'\]/", $body) === 1,
    "plus CKEditor's oembed placeholder");
assert_true(preg_match("/ADD_ATTR:\s*\['menu_id', 'url', 'target'\]/", $body) === 1,
    'plus menu_id, url and target -- what the workbook needs and DOMPurify strips by default');
assert_true(preg_match("/FORBID_TAGS:\s*\['style'/", $body) === 1,
    'and no <style> element, to match the server');
assert_true(preg_match('/typeof DOMPurify === .undefined.[^\n]*\n\s*return HtmlEncode\(string\)/s', $body) === 1,
    'without DOMPurify it returns the text ENCODED -- fail closed, never raw');
assert_true(preg_match('/return string\s*;/', $body) !== 1,
    'and there is no path that returns the input as it came');

// The library, and that it is loaded before the file that calls it.
$lib = $root . '/store/resources/assets/js/purify.min.js';
assert_true(is_file($lib), 'purify.min.js is in the tree');
$head = (string) file_get_contents($lib, false, null, 0, 200);
assert_true(strpos($head, 'DOMPurify 3.2.6') !== false, 'and is DOMPurify 3.2.6');
assert_same('89e1fa7647cb495370d3a997ace4387f5d15d9f4c5af12352c53daa400956287', hash_file('sha256', $lib),
    'and is the file that was reviewed (sha256 pinned; bump it with the version, deliberately)');
foreach (['store/resources/views/main/main.blade.php', 'store/resources/views/reactjs/document.blade.php'] as $view) {
    $src = file_get_contents($root . '/' . $view);
    $pPurify = strpos($src, 'purify.min.js');
    $pDefault = strpos($src, 'assets/js/default.js');
    assert_true($pPurify !== false && $pDefault !== false && $pPurify < $pDefault,
        "$view loads purify.min.js before default.js");
}

// Nothing runs from store/resources; the served files are the store/public
// copies, which webpack.mix.js copies on a build. A fix in resources that does
// not reach public is not a fix.
foreach (['default.js', 'purify.min.js'] as $f) {
    assert_same(hash_file('sha256', $root . '/store/resources/assets/js/' . $f),
        hash_file('sha256', $root . '/store/public/assets/js/' . $f),
        "store/public/assets/js/$f is the store/resources copy");
}

// The view that renders workbook pages goes through output_secure().
$view = file_get_contents($root . '/store/resources/react/components/lab/workbook/viewer/HTMLView.js');
assert_true(preg_match('/dangerouslySetInnerHTML=\{\{\s*__html:\s*output_secure\(/', $view) === 1,
    'HTMLView.js renders pages through output_secure()');

test_summary();
