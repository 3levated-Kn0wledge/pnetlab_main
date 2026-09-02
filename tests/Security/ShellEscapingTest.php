<?php
/**
 * Guards the shell-injection sweep against regression, by walking the tokens of
 * every PHP file in the tree and deciding, per interpolation, whether the value
 * that reaches an exec-family call was escaped.
 *
 * WHAT THIS REPLACES, AND WHY.
 *
 * The first version of this test read 16 hand-listed files line by line and
 * reported a line when it matched `$cmd .= `, contained `'" . $`, and did NOT
 * contain the substring `escapeshellarg` anywhere on it. Measured against the
 * tree it was guarding: of 247 lines matching `$cmd .=`, 82 reached the last
 * filter, 80 were dropped by that substring test and 2 were exempted. Zero lines
 * decided the assertion. It passed because it inspected nothing, and it could
 * not see:
 *
 *   - a command built in a variable not called $cmd ($bin, $flags, $cpucommand);
 *   - exec() called on an expression with no assignment at all;
 *   - continuation lines of a multi-line statement;
 *   - an unescaped sibling riding along on a line that escaped something else —
 *     one escapeshellarg() whitelisted the whole line;
 *   - any of the 44 files, holding 174 of the 364 exec sites, that were not on
 *     the list.
 *
 * WHAT IT DOES NOW.
 *
 *   1. token_get_all() over every first-party PHP file (the same tree
 *      tools/php-lint.sh checks: .git, store/vendor and store/node_modules are
 *      pruned, nothing else). Comments are dropped by the tokenizer, so
 *      commented-out code still cannot satisfy or trip an assertion — that
 *      property of the old test is deliberately kept.
 *   2. Every exec/shell_exec/system/passthru/popen/proc_open call site and every
 *      backtick operator becomes a root. The FIRST argument is the expression the
 *      shell parses, so that is the one walked.
 *   3. The expression is resolved: a plain local variable is replaced by every
 *      `$v = ...` and `$v .= ...` assigned to it in the same function scope, and a
 *      method call by what every method of that name returns anywhere in the tree.
 *      Name-based method resolution is deliberate and deliberately coarse —
 *      `exec($this->command())` in devices/device.php dispatches to about sixty
 *      subclass command() bodies, and a per-file walk cannot see any of them.
 *   4. Each surviving interpolation is judged on its own: escapeshellarg(),
 *      intval()/floatval() or an (int)/(float) cast make it safe; nothing else
 *      does. The decision is per value, never per line, and the word
 *      "escapeshellarg" appearing elsewhere on the line means nothing.
 *
 * THE sweep-exempt CONVENTION.
 *
 * Template option strings (qemu_options, docker_options, iol_options, getFlag())
 * are argument injection by design: they exist to supply several arguments and
 * escaping them as one would break every template. They stay marked
 * `// sweep-exempt: <reason>` on the preceding comment lines. One rule changed:
 * the marker now exempts an interpolation only if its stated reason NAMES that
 * interpolation. A marker can no longer silently cover a sibling that happens to
 * share the line, which is what
 *   $cmd .= $this->docker_options . ' ' . $consoleCmd . ' ' . $consoleCmd2nd
 * did. The cost of that rule is recorded in the baseline: several markers whose
 * prose names `qemu_options` are in fact sitting on `$this->getFlag()`, so they
 * no longer take effect and the sites they were meant to cover are listed
 * explicitly instead.
 *
 * THE BASELINE.
 *
 * This rewrite surfaces real, pre-existing violations. They are NOT silenced:
 * tests/Security/shell-escaping-baseline.txt lists every one of them with a
 * reason, and that file must only ever shrink. Anything not in it fails the
 * test. See the header of that file.
 */

require_once __DIR__ . '/../bootstrap.php';

$root = realpath(__DIR__ . '/../..');

// ---------------------------------------------------------------- the analyser

/** First argument is the one the shell parses; the rest are output/status. */
const SWEEP_EXEC_FUNCTIONS = [
    'exec' => true, 'shell_exec' => true, 'system' => true, 'passthru' => true,
    'popen' => true, 'proc_open' => true,
];

/** The only things in PHP that make an interpolated value safe to hand a shell. */
const SWEEP_ESCAPERS = ['escapeshellarg' => true, 'intval' => true, 'floatval' => true];

/**
 * Calls whose return value cannot carry attacker bytes. Kept deliberately short:
 * anything absent from it is reported, so this list is the only place where a
 * "that one is fine" judgement can be made, and it is reviewable.
 *
 * escapeshellcmd() is NOT here and NOT an escaper: it neutralises metacharacters
 * but still lets one argument become several, which is the shape that matters.
 */
const SWEEP_HARMLESS = [
    'count' => true, 'sizeof' => true, 'strlen' => true, 'time' => true,
    'getmypid' => true, 'uniqid' => true, 'php_uname' => true, 'phpversion' => true,
];

/** Resolution depth. Six is past the deepest chain in the tree (exec -> command() -> $cmd -> $bin -> $qversion). */
const SWEEP_MAX_DEPTH = 6;

function sweep_files($root)
{
    $out = [];
    $skip = [$root . '/.git', $root . '/.claude', $root . '/store/vendor', $root . '/store/node_modules'];
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            function ($cur) use ($skip) {
                if ($cur->isDir()) return !in_array($cur->getPathname(), $skip, true);
                return substr($cur->getFilename(), -4) === '.php';
            }
        )
    );
    foreach ($it as $f) $out[] = $f->getPathname();
    sort($out);
    return $out;
}

/**
 * Compact token stream: whitespace and comments removed, line numbers kept.
 * Dropping comments here is what stops commented-out code from being analysed,
 * in either direction — it can neither hide a violation nor invent one.
 */
function sweep_tokenize($src, &$markers)
{
    $markers = [];
    $toks = [];
    $line = 1;
    foreach (token_get_all($src) as $t) {
        if (!is_array($t)) { $toks[] = ['id' => $t, 't' => $t, 'l' => $line]; continue; }
        [$id, $text, $line] = $t;
        if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
            if (strpos($text, 'sweep-exempt:') !== false) {
                // A block comment marker applies to every line it spans.
                for ($i = 0, $n = substr_count($text, "\n"); $i <= $n; $i++) {
                    $markers[$line + $i] = (isset($markers[$line + $i]) ? $markers[$line + $i] . ' ' : '') . $text;
                }
            }
            $line += substr_count($text, "\n");
            continue;
        }
        if ($id === T_WHITESPACE) { $line += substr_count($text, "\n"); continue; }
        $toks[] = ['id' => $id, 't' => $text, 'l' => $line];
        $line += substr_count($text, "\n");
    }
    return $toks;
}

/**
 * Innermost enclosing function scope for every token, so `$cmd` in one method is
 * never resolved against an assignment to `$cmd` in another. T_CURLY_OPEN and
 * T_DOLLAR_OPEN_CURLY_BRACES are the `{` of "{$a}" and "${a}": they close with a
 * plain '}' and would otherwise pop a scope that was never pushed.
 */
/**
 * Names of the parameters a function declares as `array`, given the index of its
 * T_FUNCTION token. Used only to recognise an argv array handed to proc_open.
 */
function sweep_array_params($toks, $fnTok)
{
    $n = count($toks);
    $open = -1;
    for ($i = $fnTok; $i < $n; $i++) {
        if ($toks[$i]['id'] === '(') { $open = $i; break; }
        if ($toks[$i]['id'] === '{' || $toks[$i]['id'] === ';') return [];
    }
    if ($open < 0) return [];
    $close = sweep_match($toks, $open);
    $out = [];
    for ($i = $open + 1; $i < $close; $i++) {
        if ($toks[$i]['id'] !== T_ARRAY) continue;
        // `array $x`, `array &$x`, `array ...$x` — skip the by-reference and
        // variadic markers, but stop at anything else so `array $x = array()`
        // in a later parameter cannot be misread.
        for ($j = $i + 1; $j < $close; $j++) {
            $id = $toks[$j]['id'];
            if ($id === '&' || $id === T_ELLIPSIS) continue;
            if ($id === T_VARIABLE) $out[$toks[$j]['t']] = true;
            break;
        }
    }
    return $out;
}

function sweep_scopes($toks, &$arrayParams = null)
{
    $n = count($toks);
    $scope = array_fill(0, $n, 0);
    $stack = [0];
    $braces = [];
    $next = 1;
    $pendingFn = false;
    $fnTok = -1;
    if ($arrayParams === null) $arrayParams = [];
    for ($i = 0; $i < $n; $i++) {
        $id = $toks[$i]['id'];
        if ($id === T_FUNCTION || (defined('T_FN') && $id === T_FN)) { $pendingFn = true; $fnTok = $i; }
        elseif ($id === ';') $pendingFn = false;   // an abstract/interface declaration has no body
        elseif ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) $braces[] = null;
        elseif ($id === '{') {
            if ($pendingFn) {
                $stack[] = $next; $braces[] = $next;
                $arrayParams[$next] = $fnTok >= 0 ? sweep_array_params($toks, $fnTok) : [];
                $next++; $pendingFn = false;
            }
            else $braces[] = null;
        } elseif ($id === '}') {
            $opened = array_pop($braces);
            if ($opened !== null && count($stack) > 1) array_pop($stack);
        }
        $scope[$i] = end($stack);
    }
    return $scope;
}

/** Index of the bracket matching the one at $i. */
function sweep_match($toks, $i)
{
    $pairs = ['(' => ')', '[' => ']', '{' => '}'];
    $open = $toks[$i]['id'];
    if (!isset($pairs[$open])) return $i;
    $close = $pairs[$open];
    $d = 0;
    for ($j = $i, $n = count($toks); $j < $n; $j++) {
        $id = $toks[$j]['id'];
        if ($id === $open) $d++;
        elseif ($open === '{' && ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES)) $d++;
        elseif ($id === $close) { $d--; if ($d === 0) return $j; }
    }
    return count($toks) - 1;
}

/** Token range of the expression starting at $i, up to the statement terminator. */
function sweep_statement($toks, $i)
{
    $d = 0;
    for ($j = $i, $n = count($toks); $j < $n; $j++) {
        $id = $toks[$j]['id'];
        if ($id === '(' || $id === '[' || $id === '{' || $id === T_CURLY_OPEN
            || $id === T_DOLLAR_OPEN_CURLY_BRACES) $d++;
        elseif ($id === ')' || $id === ']' || $id === '}') { if ($d === 0) return $j; $d--; }
        elseif ($id === ';' && $d === 0) return $j;
    }
    return count($toks) - 1;
}

/** Every `$v = ...` and `$v .= ...`, keyed by scope then variable name. */
function sweep_assignments($toks, $scope)
{
    $out = [];
    for ($i = 0, $n = count($toks); $i < $n; $i++) {
        if ($toks[$i]['id'] !== T_VARIABLE) continue;
        $prev = $i > 0 ? $toks[$i - 1]['id'] : null;
        if ($prev === T_OBJECT_OPERATOR || $prev === T_DOUBLE_COLON) continue;
        if (defined('T_NULLSAFE_OBJECT_OPERATOR') && $prev === T_NULLSAFE_OBJECT_OPERATOR) continue;
        if (!isset($toks[$i + 1])) continue;
        // '=' and '.=' only. '==' and '=>' are separate token ids, so they cannot reach here.
        if ($toks[$i + 1]['id'] !== '=' && $toks[$i + 1]['id'] !== T_CONCAT_EQUAL) continue;
        $end = sweep_statement($toks, $i + 2);
        if ($end > $i + 2) $out[$scope[$i]][$toks[$i]['t']][] = [$i + 2, $end - 1];
    }
    return $out;
}

/** `return <expr>;` ranges per scope, and the name of every function definition. */
function sweep_returns($toks, $scope, &$methods, $rel)
{
    $out = [];
    for ($i = 0, $n = count($toks); $i < $n; $i++) {
        $id = $toks[$i]['id'];
        if ($id === T_FUNCTION && isset($toks[$i + 1]) && $toks[$i + 1]['id'] === T_STRING) {
            for ($j = $i + 2; $j < $n; $j++) {
                // The body's scope is the one in force just inside its opening brace.
                if ($toks[$j]['id'] === '{') { $methods[strtolower($toks[$i + 1]['t'])][] = [$rel, $scope[$j]]; break; }
                if ($toks[$j]['id'] === ';') break;
            }
            continue;
        }
        if ($id !== T_RETURN) continue;
        $end = sweep_statement($toks, $i + 1);
        if ($end > $i + 1) $out[$scope[$i]][] = [$i + 1, $end - 1];
    }
    return $out;
}

/** Render `$a`, `$this->b`, `$this->c()`, `$a['k']` as a stable name; advances $i past it. */
function sweep_name($toks, &$i)
{
    $name = $toks[$i]['t'];
    $n = count($toks);
    $i++;
    while ($i < $n) {
        $id = $toks[$i]['id'];
        if ($id === T_OBJECT_OPERATOR || $id === T_DOUBLE_COLON
            || (defined('T_NULLSAFE_OBJECT_OPERATOR') && $id === T_NULLSAFE_OBJECT_OPERATOR)) {
            $name .= $toks[$i]['t'];
            $i++;
            if ($i < $n && ($toks[$i]['id'] === T_STRING || $toks[$i]['id'] === T_VARIABLE)) {
                $name .= $toks[$i]['t'];
                $i++;
            } elseif ($i < $n && $toks[$i]['id'] === '{') {
                $name .= '{}';                       // $o->{CONST}, a dynamic property
                $i = sweep_match($toks, $i) + 1;
            }
            continue;
        }
        if ($id === '(') { $name .= '()'; $i = sweep_match($toks, $i) + 1; break; }
        if ($id === '[') {
            $close = sweep_match($toks, $i);
            // A literal subscript is part of the identity: $p['qemu_arch'].
            $key = ($close === $i + 2 && ($toks[$i + 1]['id'] === T_CONSTANT_ENCAPSED_STRING
                    || $toks[$i + 1]['id'] === T_LNUMBER)) ? $toks[$i + 1]['t'] : '';
            $name .= '[' . $key . ']';
            $i = $close + 1;
            continue;
        }
        break;
    }
    return $name;
}

/**
 * A `sweep-exempt:` marker on the preceding comment lines (or trailing on the
 * same line) exempts an interpolation only when its stated reason names it.
 * Matching is on the trailing identifier: $this->qemu_options -> qemu_options.
 */
function sweep_exempts($ctx, $line, $name)
{
    $text = isset($ctx['markers'][$line]) ? $ctx['markers'][$line] : null;
    for ($k = $line - 1; $text === null && $k >= 1 && $k >= $line - 4; $k--) {
        if (isset($ctx['markers'][$k])) { $text = $ctx['markers'][$k]; break; }
        $s = isset($ctx['lines'][$k - 1]) ? trim($ctx['lines'][$k - 1]) : '';
        if ($s !== '' && strpos($s, '//') !== 0 && strpos($s, '*') !== 0 && strpos($s, '#') !== 0) break;
    }
    if ($text === null) return false;
    $ident = preg_replace('/(\(\)|\[[^\]]*\])$/', '', $name);
    $ident = ltrim(preg_replace('/^.*(->|::)/', '', $ident), '$');
    return $ident !== '' && stripos($text, $ident) !== false;
}

/**
 * Identity is FILE plus SYMBOL, not file plus line. Line numbers churn under
 * every unrelated edit, and several people work this tree at once; a baseline
 * keyed on them would be stale the day it landed and would fail the build for
 * changes that touched nothing. The lines are carried alongside for the report.
 * The cost is real and stated here: a second unescaped use of a symbol already
 * listed for that file does not register as new.
 */
function sweep_report($ctx, $line, $name, &$out)
{
    $out[$ctx['rel'] . ' ' . $name][$line] = true;
}

/** Follow every `return` of every function of this name, anywhere in the tree. */
function sweep_returns_of($ix, $m, $depth, &$seen, &$out)
{
    if (!isset($ix['methods'][$m]) || $depth >= SWEEP_MAX_DEPTH) return false;
    $key = "m $m";
    if (isset($seen[$key])) return true;      // already unrolled for this root
    $seen[$key] = true;
    foreach ($ix['methods'][$m] as [$rel, $scope]) {
        if (!isset($ix['ctx'][$rel]['returns'][$scope])) continue;
        foreach ($ix['ctx'][$rel]['returns'][$scope] as [$a, $b]) {
            sweep_expr($ix, $rel, $scope, $a, $b, $depth + 1, $seen, $out);
        }
    }
    return true;
}

/** Walk one expression, reporting every interpolation in it that is not escaped. */
function sweep_expr($ix, $rel, $scopeId, $from, $to, $depth, &$seen, &$out)
{
    $ctx  = $ix['ctx'][$rel];
    $toks = $ctx['toks'];
    $i = $from;
    while ($i <= $to) {
        $tok = $toks[$i];
        $id  = $tok['id'];

        // escapeshellarg(...) / intval(...): the whole call is safe. This is the
        // one place escaping is recognised, and it is recognised structurally —
        // the value must be INSIDE the call, not merely on the same line.
        if ($id === T_STRING && isset(SWEEP_ESCAPERS[strtolower($tok['t'])])
            && isset($toks[$i + 1]) && $toks[$i + 1]['id'] === '(') {
            $i = sweep_match($toks, $i + 1) + 1;
            continue;
        }
        // (int)$x / (float)$x: the value cannot carry a metacharacter.
        if ($id === T_INT_CAST || $id === T_DOUBLE_CAST || $id === T_BOOL_CAST) {
            $i++;
            if ($i <= $to && $toks[$i]['id'] === '(') $i = sweep_match($toks, $i) + 1;
            elseif ($i <= $to && $toks[$i]['id'] === T_VARIABLE) sweep_name($toks, $i);
            continue;
        }
        // Any other call. Its RESULT reaches the shell; its arguments do not, so
        // do not descend into them — preg_replace($patterns, $replacements, $file)
        // puts none of its three arguments into a command, only what it returns.
        if ($id === T_STRING && isset($toks[$i + 1]) && $toks[$i + 1]['id'] === '('
            && !isset(SWEEP_HARMLESS[strtolower($tok['t'])])) {
            $prev = $i > 0 ? $toks[$i - 1]['id'] : null;
            if ($prev !== T_OBJECT_OPERATOR && $prev !== T_DOUBLE_COLON && $prev !== T_FUNCTION) {
                $close = sweep_match($toks, $i + 1);
                if (!sweep_exempts($ctx, $tok['l'], $tok['t'] . '()')
                    && !sweep_returns_of($ix, strtolower($tok['t']), $depth, $seen, $out)) {
                    sweep_report($ctx, $tok['l'], $tok['t'] . '()', $out);
                }
                $i = $close + 1;
                continue;
            }
        }
        if ($id !== T_VARIABLE) { $i++; continue; }

        $line = $tok['l'];
        $base = $tok['t'];
        $name = sweep_name($toks, $i);

        // Consulted BEFORE resolution, not after: a marker sitting on
        //     $flags .= ' ' . $this->getFlag();
        // has to stop the walk there, or it would be bypassed by descending into
        // what getFlag() returns and the marker would mean nothing.
        if (sweep_exempts($ctx, $line, $name)) continue;

        if ($name === $base) {
            $found = null;
            if (isset($ctx['assign'][$scopeId][$base])) $found = $ctx['assign'][$scopeId][$base];
            elseif (isset($ctx['assign'][0][$base]))    $found = $ctx['assign'][0][$base];
            if ($found !== null) {
                $key = "v $rel $scopeId $base";
                if (isset($seen[$key])) continue;
                if ($depth < SWEEP_MAX_DEPTH) {
                    $seen[$key] = true;
                    foreach ($found as [$a, $b]) sweep_expr($ix, $rel, $scopeId, $a, $b, $depth + 1, $seen, $out);
                    continue;
                }
            }
            // No assignment in scope: a parameter, a foreach value, a global.
            // Unresolvable is not the same as safe, so it is reported.
        } elseif (substr($name, -2) === '()' && strpos($name, '->') !== false) {
            $m = strtolower(substr(strrchr(substr($name, 0, -2), '>'), 1));
            if (sweep_returns_of($ix, $m, $depth, $seen, $out)) continue;
        }

        sweep_report($ctx, $line, $name, $out);
    }
}

/** Tokenize a set of sources once and index every function definition by name. */
function sweep_index($sources)
{
    $ix = ['ctx' => [], 'methods' => []];
    foreach ($sources as $rel => $src) {
        $toks  = sweep_tokenize($src, $markers);
        $arrayParams = [];
        $scope = sweep_scopes($toks, $arrayParams);
        $ix['ctx'][$rel] = [
            'rel' => $rel, 'toks' => $toks, 'scope' => $scope, 'markers' => $markers,
            'arrayParams' => $arrayParams,
            'lines' => explode("\n", $src),
            'returns' => sweep_returns($toks, $scope, $ix['methods'], $rel),
            'assign' => sweep_assignments($toks, $scope),
        ];
    }
    return $ix;
}

/** Returns [sorted violations, exec sites seen, files holding an exec site]. */
function sweep_run($ix)
{
    $out = [];
    $sites = 0;
    $files = 0;
    foreach ($ix['ctx'] as $rel => $ctx) {
        $toks = $ctx['toks'];
        $n = count($toks);
        $before = $sites;
        for ($i = 0; $i < $n; $i++) {
            $id = $toks[$i]['id'];

            if ($id === '`') {                       // the backtick operator is exec()
                $end = $i + 1;
                while ($end < $n && $toks[$end]['id'] !== '`') $end++;
                $sites++;
                if ($end > $i + 1) { $seen = []; sweep_expr($ix, $rel, $ctx['scope'][$i], $i + 1, $end - 1, 0, $seen, $out); }
                $i = $end;
                continue;
            }
            if ($id !== T_STRING || !isset(SWEEP_EXEC_FUNCTIONS[strtolower($toks[$i]['t'])])) continue;
            if (!isset($toks[$i + 1]) || $toks[$i + 1]['id'] !== '(') continue;
            $prev = $i > 0 ? $toks[$i - 1]['id'] : null;
            if ($prev === T_OBJECT_OPERATOR || $prev === T_DOUBLE_COLON || $prev === T_FUNCTION
                || $prev === T_NEW
                || (defined('T_NULLSAFE_OBJECT_OPERATOR') && $prev === T_NULLSAFE_OBJECT_OPERATOR)) continue;

            $close  = sweep_match($toks, $i + 1);
            $argEnd = $close - 1;
            $d = 0;
            for ($j = $i + 2; $j < $close; $j++) {   // first argument only
                $t = $toks[$j]['id'];
                if ($t === '(' || $t === '[' || $t === '{' || $t === T_CURLY_OPEN
                    || $t === T_DOLLAR_OPEN_CURLY_BRACES) $d++;
                elseif ($t === ')' || $t === ']' || $t === '}') $d--;
                elseif ($t === ',' && $d === 0) { $argEnd = $j - 1; break; }
            }
            $sites++;

            // proc_open() is the one exec-family function whose first argument
            // is not necessarily a shell command. Given an array it execs the
            // program directly with that argv and no shell is involved, so
            // nothing in it can be parsed as syntax and escaping it would be
            // wrong -- escapeshellarg() on an argv element corrupts the
            // argument while making the code look safer. Recognise the array
            // form so the sweep does not push new code towards a string.
            //
            // Only two shapes count: a literal, and a variable the enclosing
            // function declares as `array`. A bare variable of unknown type is
            // still reported, because a string built elsewhere and passed in
            // here is exactly the case that must not slip through.
            $isArgvArray = false;
            if (strtolower($toks[$i]['t']) === 'proc_open' && $argEnd >= $i + 2) {
                $first = $toks[$i + 2]['id'];
                if ($first === T_ARRAY || $first === '[') {
                    $isArgvArray = true;
                } elseif ($first === T_VARIABLE && $argEnd === $i + 2) {
                    $params = isset($ctx['arrayParams'][$ctx['scope'][$i]])
                        ? $ctx['arrayParams'][$ctx['scope'][$i]] : [];
                    $isArgvArray = isset($params[$toks[$i + 2]['t']]);
                }
            }

            if (!$isArgvArray && $argEnd >= $i + 2) { $seen = []; sweep_expr($ix, $rel, $ctx['scope'][$i], $i + 2, $argEnd, 0, $seen, $out); }
            $i = $close;
        }
        if ($sites > $before) $files++;
    }
    ksort($out);
    foreach ($out as $k => $lines) { $l = array_keys($lines); sort($l); $out[$k] = $l; }
    return [$out, $sites, $files];
}

// ------------------------------------------------- the analyser can, in fact, fail

/**
 * Synthetic fixtures. The old test asserted over an empty set for its whole life
 * without anyone noticing, so the analyser is first pointed at code whose answer
 * is known. If these stop holding, the sweep below means nothing.
 */
$fixtures = [
    'bare.php'      => '<?php exec("rm -rf " . $tmp);',
    'escaped.php'   => '<?php exec("rm -rf " . escapeshellarg($tmp));',
    'sibling.php'   => '<?php exec("cp " . escapeshellarg($a) . " " . $b);',
    'renamed.php'   => '<?php function f($x){ $bin = "/bin/q-" . $x; $c = $bin . " -y"; exec($c); }',
    'interp.php'    => '<?php function g($x){ exec("kill -9 $x"); }',
    'method.php'    => '<?php class C { function cmd(){ return "id " . $this->arch; } function go(){ exec($this->cmd()); } }',
    'cast.php'      => '<?php function h($p){ exec("kill " . (int) $p); }',
    'commented.php' => "<?php function i(\$x){ // exec('rm ' . \$x);\n /* exec('rm ' . \$x); */ }",
    'scoped.php'    => '<?php function a(){ $c = "safe"; } function b($y){ exec($c . $y); }',
    'exempt.php'    => "<?php function j(\$x){\n // sweep-exempt: \$x supplies several arguments by design.\n exec('q ' . \$x);\n}",
    'exempt_sibling.php' => "<?php function k(\$x, \$z){\n // sweep-exempt: \$x supplies several arguments by design.\n exec('q ' . \$x . ' ' . \$z);\n}",

    // proc_open() given an array execs directly with no shell, so these three
    // fix the boundary: the two array forms are safe, and a string handed to
    // the same function is not.
    'argv_literal.php' => '<?php function l($x){ proc_open(["/bin/q", $x], $d, $p); }',
    'argv_param.php'   => '<?php function m(array $argv){ proc_open($argv, $d, $p); }',
    'argv_string.php'  => '<?php function n($x){ proc_open("/bin/q " . $x, $d, $p); }',
    'argv_untyped.php' => '<?php function o($argv){ proc_open($argv, $d, $p); }',
];
[$fv] = sweep_run(sweep_index($fixtures));
$fset = [];
foreach (array_keys($fv) as $v) $fset[strstr($v, ' ', true)] = true;

assert_true(isset($fset['bare.php']),      'flags a bare interpolation into exec()');
assert_true(!isset($fset['escaped.php']),  'accepts escapeshellarg() around the value');
assert_true(isset($fset['sibling.php']),   'flags an unescaped sibling on a line that escapes something else');
assert_true(isset($fset['renamed.php']),   'follows a command built in a variable not called $cmd');
assert_true(isset($fset['interp.php']),    'flags a variable interpolated inside a double-quoted command');
assert_true(isset($fset['method.php']),    'follows exec($this->method()) into what the method returns');
assert_true(!isset($fset['cast.php']),     'accepts an (int) cast');
assert_true(!isset($fset['commented.php']), 'ignores commented-out exec calls');
assert_true(isset($fset['scoped.php']),    'does not borrow an assignment from another function scope');
assert_true(!isset($fset['exempt.php']),   'honours a sweep-exempt marker that names the value');
assert_true(isset($fset['exempt_sibling.php']),
    'a sweep-exempt marker does not cover a sibling it does not name');
assert_true(!isset($fset['argv_literal.php']),
    'accepts proc_open() with a literal argv array, which spawns no shell');
assert_true(!isset($fset['argv_param.php']),
    'accepts proc_open() with an array-typed parameter');
assert_true(isset($fset['argv_string.php']),
    'still flags proc_open() given a built string');
assert_true(isset($fset['argv_untyped.php']),
    'still flags proc_open() given a variable of unknown type');

// ------------------------------------------------------------------ the real sweep

$sources = [];
foreach (sweep_files($root) as $path) {
    $src = @file_get_contents($path);
    if ($src !== false) $sources[substr($path, strlen($root) + 1)] = $src;
}
[$violations, $sites, $execFiles] = sweep_run(sweep_index($sources));

// Guards against the sweep silently narrowing the way the old $swept list did.
assert_true(count($sources) > 250, sprintf('sweeps the whole tree (%d files)', count($sources)));
assert_true($sites > 300, sprintf('reaches every exec-family call site (%d sites in %d files)', $sites, $execFiles));

$baselineFile = __DIR__ . '/shell-escaping-baseline.txt';
$baseline = [];
foreach (file($baselineFile, FILE_IGNORE_NEW_LINES) as $l) {
    $l = trim($l);
    if ($l !== '' && $l[0] !== '#') $baseline[$l] = true;
}

// The ratchet. Raising this number is the reviewable act; it must only ever fall.
// It is a count rather than a strict set comparison so that an unrelated edit
// shifting a line number cannot fail the build — a genuinely new violation
// still does, because it is not in the file.
// Ratchet. It has only ever moved downwards: 93 when the sweep was first made
// honest, 91 once signed packages removed DevicesController's two, 88 once
// `-a iol-keepalive` removed the ps|grep|cut -> `sudo kill -9 $pid` teardown in
// devices/interfc.php and devices/iol/device_iol.php, 77 once `-a image-commit`
// took the whole QEMU commit flow out of Node_sessionsController, 73 once
// `-a set-proxy` took the four proxy fields out of Query::setProxy() — which was
// the only entry in this file that was a root RCE rather than a route to one.
//
// 47 with the shell-layer pass that inverted secureCmd(). Twenty-six entries, in
// the roadmap's triage order: the two Laravel SystemHelper sites and
// LabsController's `sudo qemu-img` first (that one also retired a sudo grant),
// then the API-reachable folder and lab-import handlers in includes/, then the
// QEMU binary path — the entry this file called its highest-severity one — and
// the disk-flag and pid arithmetic around it. One entry was RENAMED rather than
// retired: `includes/functions.php $cmd` is now `$value`, because secureCmd()'s
// parameter was renamed. It still belongs here and the count is honest about it.
const SWEEP_BASELINE_MAX = 47;
assert_true(count($baseline) <= SWEEP_BASELINE_MAX,
    sprintf('the known-unfixed baseline has not grown (%d of %d)', count($baseline), SWEEP_BASELINE_MAX));

$new = [];
foreach ($violations as $v => $lines) {
    if (!isset($baseline[$v])) $new[] = $v . '  (line ' . implode(', ', $lines) . ')';
}
assert_same([], $new, 'no unescaped value reaches a shell outside the known-unfixed baseline');
foreach ($new as $v) echo "        NEW  $v\n";

foreach (array_keys($baseline) as $b) {
    if (!isset($violations[$b])) echo "        fixed — delete from the baseline: $b\n";
}

// ---------------------------------------------- the interface-name allowlist

// The allowlist validator must reject the shapes that matter.
require_once $root . '/includes/cli.php';

foreach (['vnet1_1', 'vunl12_0', 'pnet0', 'nat0', 'docker0', 'eth0'] as $good) {
    assert_true(unl_valid_ifname($good), "accepts a real interface name: $good");
}
foreach (['a;id', 'a$(id)', 'a`id`', "a\nid", "vnet1_1\n", 'a>b', 'a b', '../etc', '',
          'toolongtoolong16'] as $bad) {
    assert_true(!unl_valid_ifname($bad), 'rejects ' . json_encode($bad));
}

// ------------------------------------------------- the bridge sysfs validator

// unl_write_sysfs() replaced three `sudo echo N > /sys/.../bridge/<knob>` calls.
// It is the only thing standing between a bridge name and a root-side file
// write, so both halves of the path are checked here. Every case below must be
// refused BEFORE any write is attempted, which is why they can run unprivileged.
foreach ([
    ['/sys/class/net/vnet1_1/bridge/forward_delay', '0', 'a knob outside the three-value list'],
    ['/sys/class/net/../../../etc/hostname', '0',     'a path that leaves /sys/…/net'],
    ['/sys/class/net/a b/bridge/group_fwd_mask', '0', 'an interface name with a space'],
    ['/sys/class/net/a;id/bridge/group_fwd_mask', '0', 'an interface name with a semicolon'],
    ['/etc/hostname', '0',                            'a path that is not under /sys at all'],
    ['/sys/class/net/vnet1_1/bridge/group_fwd_mask', '1;id', 'a value that is not digits'],
    ['/sys/class/net/vnet1_1/bridge/group_fwd_mask', "1\n",  'a value with a trailing newline'],
    ['/sys/class/net/vnet1_1/bridge/group_fwd_mask', '', 'an empty value'],
] as [$path, $value, $why]) {
    assert_same(1, unl_write_sysfs($path, $value), "unl_write_sysfs refuses $why");
}

test_summary();
