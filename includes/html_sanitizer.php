<?php
/**
 * includes/html_sanitizer.php
 *
 * An allowlist HTML sanitizer for the workbook, and the JavaScript escape()
 * / unescape() pair the workbook's encoding needs around it.
 *
 * WHY THIS EXISTS
 *
 * A lab's HTML workbook is CKEditor output. The editor stores each page as
 * btoa(escape(html)), api.php's workbook/update handed the pages to
 * Lab::updateContent() untouched, and the viewer (HTMLView.js) put
 * unescape(atob(page)) into the DOM through dangerouslySetInnerHTML after
 * passing it through output_secure() -- which was
 *
 *     string.replace('/<\/?script>?/gmi', '')
 *
 * a string-literal first argument, so it looked for those twenty characters
 * and removed nothing. Anyone with edit permission on a lab could store
 * <img src=x onerror=...> and have it run, with the viewer's session, in the
 * browser of every user who opened the workbook -- an administrator included.
 *
 * So: the server keeps only markup from a fixed list, on the way IN, and the
 * viewer runs DOMPurify over what comes back OUT (default.js). Neither layer
 * trusts the other. This file is the first of the two.
 *
 * WHAT IS KEPT
 *
 * The elements and attributes the workbook editor produces: headings, inline
 * formatting, lists, block quotes, code, links, images (as data: URIs -- the
 * editor's image plugin inlines uploads), tables, figures, CKEditor's oembed
 * placeholder, and the `menu_id` attribute the workbook's contents menu scrolls
 * to. Inline `style` is kept because the editor's padding, margin, border,
 * colour, font and alignment tools all write it, but only for properties on a
 * list and never with url(), expression(), an escape or an @-rule in the value.
 *
 * Everything else is dropped. An element that is not on the list is unwrapped
 * -- its children are kept, in its place -- except the ones whose CONTENT is
 * the problem (script, style, iframe, object, embed, svg, math, and the like),
 * which go with everything inside them. Comments and processing instructions
 * go. Every on* attribute goes, whatever the element. An href or src whose
 * scheme is not on the list goes.
 *
 * WHY NOT A LIBRARY
 *
 * api.php is the legacy layer; it has no composer autoloader and no vendor
 * tree, and the appliance installs offline. ext-dom is part of the php-xml
 * package the installer already requires. A DOM walk over a fixed allowlist is
 * small enough to read in one sitting, which is what a sanitizer that has to
 * be trusted should be.
 *
 * No dependency on init.php: tests/Security/WorkbookHtmlTest.php requires this
 * file on its own and feeds it payloads.
 */

/** Elements kept, with the attributes kept on each beyond the global set. */
function unl_html_allowed_elements()
{
    static $allowed = null;
    if ($allowed !== null) return $allowed;
    $none = [];
    $allowed = [
        'p' => $none, 'br' => $none, 'hr' => $none, 'div' => $none, 'span' => $none,
        'h1' => $none, 'h2' => $none, 'h3' => $none, 'h4' => $none, 'h5' => $none, 'h6' => $none,
        'strong' => $none, 'b' => $none, 'em' => $none, 'i' => $none, 'u' => $none,
        's' => $none, 'strike' => $none, 'del' => $none, 'ins' => $none,
        'sub' => $none, 'sup' => $none, 'mark' => $none, 'small' => $none,
        'ul' => $none, 'ol' => ['start', 'type', 'reversed'], 'li' => ['value'],
        'blockquote' => $none, 'pre' => $none, 'code' => $none,
        'a' => ['href', 'target', 'rel', 'name'],
        'img' => ['src', 'alt', 'width', 'height'],
        'figure' => $none, 'figcaption' => $none,
        'table' => ['border', 'cellpadding', 'cellspacing', 'width'],
        'caption' => $none, 'thead' => $none, 'tbody' => $none, 'tfoot' => $none,
        'tr' => $none, 'td' => ['colspan', 'rowspan', 'width', 'height'],
        'th' => ['colspan', 'rowspan', 'width', 'height', 'scope'],
        'colgroup' => ['span'], 'col' => ['span', 'width'],
        // CKEditor's media-embed placeholder. Inert in the viewer: the browser
        // knows no such element, and the url is not fetched.
        'oembed' => ['url'],
    ];
    return $allowed;
}

/** Attributes kept on every allowed element. */
function unl_html_global_attributes()
{
    return ['class', 'style', 'id', 'title', 'dir', 'lang', 'menu_id'];
}

/**
 * Elements removed together with everything inside them. Anything else that is
 * not allowed is unwrapped instead, so stray or unknown wrappers lose their tag
 * and keep their text.
 */
function unl_html_dropped_with_content()
{
    return ['script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed',
            'applet', 'svg', 'math', 'template', 'noscript', 'head', 'title',
            'meta', 'link', 'base', 'form', 'input', 'button', 'select',
            'textarea', 'option', 'video', 'audio', 'source', 'track', 'canvas',
            'xmp', 'plaintext', 'noembed', 'noframes'];
}

/** CSS properties an inline style may set. */
function unl_html_allowed_css_properties()
{
    static $props = null;
    if ($props !== null) return $props;
    $list = [
        'color', 'background', 'background-color',
        'font', 'font-family', 'font-size', 'font-style', 'font-weight', 'font-variant',
        'text-align', 'text-decoration', 'text-indent', 'text-transform',
        'line-height', 'letter-spacing', 'word-spacing', 'white-space', 'vertical-align',
        'width', 'height', 'min-width', 'max-width', 'min-height', 'max-height',
        'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'border', 'border-top', 'border-right', 'border-bottom', 'border-left',
        'border-width', 'border-style', 'border-color', 'border-radius',
        'border-collapse', 'border-spacing', 'table-layout',
        'float', 'clear', 'display', 'list-style', 'list-style-type', 'text-shadow',
    ];
    $props = array_flip($list);
    return $props;
}

/**
 * The value of a style attribute, reduced to allowed properties with plain
 * values. Returns '' when nothing survives.
 */
function unl_sanitize_css($style)
{
    $out = [];
    foreach (explode(';', (string) $style) as $decl) {
        $colon = strpos($decl, ':');
        if ($colon === false) continue;
        $prop = strtolower(trim(substr($decl, 0, $colon)));
        $value = trim(substr($decl, $colon + 1));
        if ($prop === '' || $value === '') continue;
        if (!isset(unl_html_allowed_css_properties()[$prop])) continue;
        // No urls, no behaviours, no escapes, no nested rules, no comments.
        // The checks are on the raw value: a browser's CSS tokenizer would
        // decode `\75 rl(` back into url(, so the backslash itself is refused.
        if (preg_match('/[\\\\{}@<>]|\/\*|url\s*\(|expression\s*\(|javascript|vbscript|-moz-binding|behavior\s*:|import/i', $value)) continue;
        if (!preg_match('/^[A-Za-z0-9 #%().,\-\'",\/!+:]+$/', $value)) continue;
        $out[] = $prop . ': ' . $value;
    }
    return implode('; ', $out);
}

/**
 * A URL in an href or src, or '' when its scheme is not allowed.
 *
 * Links may be http, https, mailto or ftp, or relative (a path, a query, a
 * fragment). Images may be http or https, relative, or a data: URI carrying a
 * raster image -- which is how the editor stores uploads. Not SVG, whose data:
 * form can carry script.
 */
function unl_sanitize_url($url, $image)
{
    $url = trim((string) $url);
    if ($url === '') return '';
    // Control and whitespace characters are stripped by browsers before the
    // scheme is read, so "java\tscript:" is javascript:. Strip them first.
    $probe = preg_replace('/[\x00-\x20\x7f]+/', '', $url);
    if (!preg_match('/^([A-Za-z][A-Za-z0-9+.\-]*):/', $probe, $m)) {
        // No scheme: relative. A protocol-relative //host is still same-scheme
        // to the browser, which is fine for both.
        return $url;
    }
    $scheme = strtolower($m[1]);
    if ($image) {
        if ($scheme === 'http' || $scheme === 'https') return $url;
        if ($scheme === 'data'
            && preg_match('#^data:image/(png|jpe?g|gif|webp|bmp);base64,[A-Za-z0-9+/=\s]+$#i', $probe)) {
            return $url;
        }
        return '';
    }
    if (in_array($scheme, ['http', 'https', 'mailto', 'ftp'], true)) return $url;
    return '';
}

/**
 * The fragment, reduced to the allowlist above.
 *
 * @param   string  $html   A fragment: the body of one workbook page
 * @return  string          The fragment with only allowed markup left
 */
function unl_sanitize_html($html)
{
    $html = (string) $html;
    if (trim($html) === '') return '';

    $doc = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    // The encoding declaration is how libxml's HTML parser is told the bytes
    // are UTF-8; without it, anything beyond ASCII is read as Latin-1. The
    // wrapper div gives the fragment one root to serialise from. NONET: no
    // external entity or DTD fetch, ever.
    $flags = LIBXML_NONET;
    if (defined('LIBXML_HTML_NOIMPLIED')) $flags |= LIBXML_HTML_NOIMPLIED;
    if (defined('LIBXML_HTML_NODEFDTD')) $flags |= LIBXML_HTML_NODEFDTD;
    $loaded = $doc->loadHTML('<?xml encoding="UTF-8"?><div id="unl-sanitize-root">' . $html . '</div>', $flags);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) return '';

    $root = $doc->getElementById('unl-sanitize-root');
    if ($root === null) {
        // getElementById needs the id to have been declared; walk instead.
        foreach ($doc->getElementsByTagName('div') as $div) {
            if ($div->getAttribute('id') === 'unl-sanitize-root') { $root = $div; break; }
        }
    }
    if ($root === null) return '';

    unl_sanitize_node_children($root);

    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }
    return $out;
}

/** Walk the children of $node, in place. Called for the root and recursively. */
function unl_sanitize_node_children(DOMNode $node)
{
    $allowed = unl_html_allowed_elements();
    $global = unl_html_global_attributes();
    $dropped = unl_html_dropped_with_content();

    // Children are collected first: the walk replaces and removes nodes, and a
    // live NodeList shifts under a foreach that does that.
    $children = [];
    foreach ($node->childNodes as $child) $children[] = $child;

    foreach ($children as $child) {
        if ($child instanceof DOMText) continue;
        if ($child instanceof DOMCdataSection) {
            $node->replaceChild($node->ownerDocument->createTextNode($child->data), $child);
            continue;
        }
        if (!($child instanceof DOMElement)) {
            // Comments, processing instructions, anything else.
            $node->removeChild($child);
            continue;
        }

        $tag = strtolower($child->tagName);
        if (in_array($tag, $dropped, true)) {
            $node->removeChild($child);
            continue;
        }
        if (!isset($allowed[$tag])) {
            // Unwrap: walk the element's own children first, under the same
            // rules, then lift them into its place and drop the element. An
            // unknown wrapper loses its tag and keeps its (clean) text.
            unl_sanitize_node_children($child);
            $inner = [];
            foreach ($child->childNodes as $grand) $inner[] = $grand;
            foreach ($inner as $grand) $node->insertBefore($grand, $child);
            $node->removeChild($child);
            continue;
        }

        unl_sanitize_attributes($child, array_merge($global, $allowed[$tag]));
        unl_sanitize_node_children($child);
    }
}

/** Strip an element to the attributes in $keep, with their values checked. */
function unl_sanitize_attributes(DOMElement $el, array $keep)
{
    $names = [];
    foreach ($el->attributes as $attr) $names[] = $attr->name;
    $tag = strtolower($el->tagName);

    foreach ($names as $name) {
        $lower = strtolower($name);
        $value = $el->getAttribute($name);

        if (!in_array($lower, $keep, true) || strpos($lower, 'on') === 0) {
            $el->removeAttribute($name);
            continue;
        }

        if ($lower === 'style') {
            $clean = unl_sanitize_css($value);
            if ($clean === '') $el->removeAttribute($name); else $el->setAttribute($name, $clean);
        } elseif ($lower === 'href' || $lower === 'src' || $lower === 'url') {
            $clean = unl_sanitize_url($value, $tag === 'img');
            if ($clean === '') $el->removeAttribute($name); else $el->setAttribute($name, $clean);
        } elseif ($lower === 'target') {
            // Only a new tab, and only with the opener cut.
            if ($value !== '_blank') {
                $el->removeAttribute($name);
            } else {
                $el->setAttribute('rel', 'noopener noreferrer');
            }
        } elseif ($lower === 'rel') {
            // Rewritten above when target is _blank; otherwise harmless, kept.
        } elseif (preg_match('/[<>"\']/', $value)) {
            // Nothing on the list takes markup or quotes in its value.
            $el->removeAttribute($name);
        }
    }
}

/* ------------------------------------------------- JavaScript escape/unescape */

/**
 * JavaScript's unescape(): %XX and %uXXXX are UTF-16 code units, everything
 * else is literal. The workbook editor stores btoa(escape(html)), and this is
 * how the html is read back.
 */
function unl_js_unescape($s)
{
    $s = (string) $s;
    $units = [];
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $c = $s[$i];
        if ($c === '%') {
            if ($i + 5 < $len && $s[$i + 1] === 'u' && ctype_xdigit(substr($s, $i + 2, 4))) {
                $units[] = hexdec(substr($s, $i + 2, 4));
                $i += 5;
                continue;
            }
            if ($i + 2 < $len && ctype_xdigit(substr($s, $i + 1, 2))) {
                $units[] = hexdec(substr($s, $i + 1, 2));
                $i += 2;
                continue;
            }
        }
        // A literal byte. escape() only leaves ASCII unencoded, so a byte here
        // is one code unit -- unless the input was never escape()d, in which
        // case UTF-8 sequences are passed through by the branch below.
        $units[] = ord($c);
    }
    $bin = '';
    foreach ($units as $u) $bin .= pack('n', $u);
    $out = @mb_convert_encoding($bin, 'UTF-8', 'UTF-16BE');
    return $out === false ? '' : $out;
}

/**
 * JavaScript's escape(): A-Z a-z 0-9 @ * _ + - . / stay, code units below 256
 * become %XX, the rest %uXXXX. Upper-case hex, as the browser writes it.
 */
function unl_js_escape($s)
{
    $bin = mb_convert_encoding((string) $s, 'UTF-16BE', 'UTF-8');
    $out = '';
    foreach (unpack('n*', $bin) as $u) {
        if ($u < 128) {
            $c = chr($u);
            if (ctype_alnum($c) || strpos('@*_+-./', $c) !== false) { $out .= $c; continue; }
        }
        $out .= $u < 256 ? sprintf('%%%02X', $u) : sprintf('%%u%04X', $u);
    }
    return $out;
}

/**
 * One stored workbook page -- btoa(escape(html)) -- sanitized and re-encoded
 * the same way. A page that does not decode is replaced by an empty page
 * rather than stored as it came.
 */
function unl_sanitize_workbook_page($page)
{
    if (!is_string($page)) return '';
    $raw = base64_decode($page, true);
    if ($raw === false) return '';
    $html = unl_sanitize_html(unl_js_unescape($raw));
    return base64_encode(unl_js_escape($html));
}
