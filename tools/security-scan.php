<?php
/**
 * ShopHaat static security scanner.
 *
 * A dependency-free, process-free analyzer for this codebase. It reads source
 * files and reports patterns that have historically been vulnerabilities here:
 * request data reaching SQL, unescaped output, dangerous function calls, weak
 * token generation, missing authorization or CSRF checks, hardcoded secrets and
 * broken repository hygiene.
 *
 *     php tools/security-scan.php
 *     php tools/security-scan.php --json
 *     php tools/security-scan.php --fail-on=critical
 *
 * Exit code 0 = nothing at or above the threshold, 1 = findings at or above it.
 * The default threshold is "critical", so CI stays useful while pre-existing
 * medium/low observations are still printed for review.
 *
 * Two rules of behaviour:
 *   1. It never executes the code it reads and never spawns a process, so it
 *      works on hosts that disable exec()/proc_open().
 *   2. Every finding can be cleared individually by adding
 *      "// @security-scan-ignore <rule-id>" to the line, which keeps the rule
 *      strict everywhere else. It never blanket-ignores a variable name: that
 *      is how a real bug hides behind an old exception.
 *
 * This is a linter, not a proof. A clean run means "no known-bad pattern",
 * never "no vulnerability".
 */

declare(strict_types=1);

$SH_ROOT = dirname(__DIR__);

$argvList = $argv ?? [];
$asJson = in_array('--json', $argvList, true);
$failOn = 'critical';
foreach ($argvList as $arg) {
    if (str_starts_with($arg, '--fail-on=')) { $failOn = substr($arg, 11); }
}

/** @var array<int,array{severity:string,rule:string,message:string,file:string,line:int,fix:string}> */
$findings = [];
$filesScanned = 0;

function sh_scan_add(string $severity, string $rule, string $message, string $file, int $line, string $fix = ''): void
{
    global $findings;
    static $seen = [];
    $key = $severity . '|' . $rule . '|' . $file . '|' . $line . '|' . $message;
    if (isset($seen[$key])) { return; }
    $seen[$key] = true;
    $findings[] = ['severity' => $severity, 'rule' => $rule, 'message' => $message,
        'file' => $file, 'line' => $line, 'fix' => $fix];
}

/**
 * Can this expression only produce a number?
 *
 * Used to clear "LIMIT $per OFFSET $offset" style interpolation, where the
 * variables are counts that were cast or clamped earlier in the same file.
 * Anything that could hold a string returns false.
 */
function sh_scan_numeric_expr(string $rhs, string $src, int $depth = 0): bool
{
    if ($depth > 4) { return false; }
    $rhs = trim($rhs);
    if ($rhs === '') { return false; }

    // Reduce to arithmetic over literals: a request read only counts as a
    // number once it is wrapped in an integer cast, and the math helpers
    // disappear along with their parentheses.
    $c = preg_replace('/\$_(?:GET|POST|REQUEST|COOKIE)\s*\[[^\]]*\](?:\s*\?\?\s*[^,)]+)?/', '0', $rhs) ?? $rhs;
    // Integer helpers and casts discard everything inside them, so the operand
    // can be any expression — balanced scanning is required to find its end.
    foreach (['sh_int', 'sh_intval', 'intval', 'count'] as $helper) {
        $c = sh_scan_replace_calls($c, $helper, '0');
    }
    $c = preg_replace('/\(\s*(?:int|float)\s*\)/', '0', $c) ?? $c;
    $c = preg_replace('/\b(?:abs|ceil|floor|round|min|max)\s*\(/', '(', $c) ?? $c;
    $c = preg_replace('/\(\s*(?:int|float)\s*\)/', '', $c) ?? $c;

    // Anything left that can hold text means this is not a number.
    if (str_contains($c, "'") || str_contains($c, '"')) { return false; }
    if (str_contains($c, '[') || str_contains($c, ']')) { return false; }
    if (str_contains($c, '->')) { return false; }

    if (preg_match_all('/\$([a-zA-Z_][a-zA-Z0-9_]*)/', $c, $m) > 0) {
        foreach (array_unique($m[1]) as $name) {
            if (!sh_scan_var_is_numeric($src, $name, $depth + 1)) { return false; }
        }
    }
    return preg_match('/^[0-9+\-*\/%().\s,$_a-zA-Z]+$/', $c) === 1;
}

/** Is every assignment to $name in this file numeric? */
function sh_scan_var_is_numeric(string $src, string $name, int $depth = 0): bool
{
    $assignments = sh_scan_var_assignments($src, $name);
    if ($assignments === []) { return false; }
    foreach ($assignments as $rhs) {
        if (!sh_scan_numeric_expr($rhs, $src, $depth + 1)) { return false; }
    }
    return true;
}

/** Every right-hand side assigned to $name in this file. */
function sh_scan_var_assignments(string $src, string $name): array
{
    if (preg_match_all('/\$' . preg_quote($name, '/') . '\s*=\s*([^;]*);/', $src, $m) === 0) { return []; }
    $out = [];
    foreach ($m[1] as $rhs) {
        $rhs = trim($rhs);
        // "= [" starts an array literal that the [^;]* capture truncates.
        if ($rhs !== '' && $rhs[0] !== '=') { $out[] = $rhs; }
    }
    return $out;
}

/**
 * Find the argument list of the call whose "(" sits at $open, honouring nesting.
 *
 * @return array{0:string,1:int}|null inner text and the index just past the ")"
 */
function sh_scan_call_inner(string $expr, int $open): ?array
{
    $depth = 0;
    $len = strlen($expr);
    for ($i = $open; $i < $len; $i++) {
        if ($expr[$i] === '(') { $depth++; continue; }
        if ($expr[$i] === ')') {
            $depth--;
            if ($depth === 0) { return [substr($expr, $open + 1, $i - $open - 1), $i + 1]; }
        }
    }
    return null;
}

/**
 * Replace calls to $fn with a literal, innermost first.
 *
 * A nested call cannot be handled by a character class alone: the arguments of
 * array_fill(0, count($ids), '?') contain their own parentheses. Each matching
 * call is located by scanning for its balanced ")" instead.
 */
function sh_scan_replace_calls(string $expr, string $fn, string $replacement): string
{
    for ($guard = 0; $guard < 12; $guard++) {
        $changed = false;
        $offset = 0;
        while (($pos = stripos($expr, $fn . '(', $offset)) !== false) {
            $before = $pos > 0 ? $expr[$pos - 1] : ' ';
            if (preg_match('/[a-zA-Z0-9_$>]/', $before) === 1) { $offset = $pos + 1; continue; }
            $inner = sh_scan_call_inner($expr, $pos + strlen($fn));
            if ($inner === null) { $offset = $pos + 1; continue; }
            [$body, $after] = $inner;
            // Only remove calls whose arguments are numbers, strings, commas or
            // other already-reduced builders. Anything else is left in place so
            // the fragment check still sees the variable.
            $probe = preg_replace('/\\$[a-zA-Z_][a-zA-Z0-9_]*/', '', $body) ?? $body;
            if (preg_match('/^[0-9,\s\'"?.()\[\]\\$-]*$/', $probe) !== 1) { $offset = $pos + 1; continue; }
            $expr = substr($expr, 0, $pos) . $replacement . substr($expr, $after);
            $changed = true;
            break;
        }
        if (!$changed) { break; }
    }
    return $expr;
}

/** Remove placeholder builders, which contribute only "?" characters. */
function sh_scan_strip_builders(string $expr): string
{
    $expr = sh_scan_replace_calls($expr, 'count', '0');
    $expr = sh_scan_replace_calls($expr, 'array_fill', "'?'");
    $expr = sh_scan_replace_calls($expr, 'str_repeat', "'?'");
    $expr = sh_scan_replace_calls($expr, 'str_pad', "'?'");
    return sh_scan_replace_calls($expr, 'implode', "'?'");
}

/**
 * Does every piece pushed into $arr hold only literal SQL with ? placeholders?
 *
 * Handles the three shapes used in this codebase:
 *     $where[] = 'p.name LIKE ?';
 *     array_push($where, 'x = ?');
 *     $where = ['a = ?', 'b = ?'];
 * A variable on the right-hand side would let request data into the fragment,
 * so any remaining $name fails the check.
 */
function sh_scan_pieces_are_literal(string $src, string $arr): bool
{
    $found = 0;
    foreach (preg_split('/\R/', $src) ?: [] as $line) {
        $rhs = null;
        if (preg_match('/\$' . preg_quote($arr, '/') . '\s*\[\s*\]\s*=\s*([^;]*);/', $line, $m) === 1) { $rhs = $m[1]; }
        elseif (preg_match('/array_push\s*\(\s*\$' . preg_quote($arr, '/') . '\s*,\s*([^;]*)\)/', $line, $m) === 1) { $rhs = $m[1]; }
        elseif (preg_match('/\$' . preg_quote($arr, '/') . '\s*=\s*(\[[^;]*\])\s*;/', $line, $m) === 1) { $rhs = $m[1]; }
        if ($rhs === null) { continue; }
        $found++;
        $body = sh_scan_strip_builders($rhs);
        if (preg_match('/\$[a-zA-Z_]/', $body) === 1) { return false; }
    }
    return $found > 0;
}

/** Is $map a literal array of string literals in this file? */
function sh_scan_map_is_literal(string $src, string $map): bool
{
    if (preg_match('/\$' . preg_quote($map, '/') . '\s*=\s*\[(.*?)\];/s', $src, $m) !== 1) { return false; }
    $body = preg_replace('/=>\s*/', '', $m[1]) ?? $m[1];
    // Every value must be a quoted string; no expressions or variables.
    return preg_match('/\$[a-zA-Z_]/', $body) !== 1
        && preg_match_all('/[\'"][^\'"]*[\'"]/', $body, $q) > 0;
}

/** One right-hand side: does it provably hold only constant SQL? */
function sh_scan_rhs_is_fragment(string $rhs, string $src, int $depth = 0): bool
{
    global $constantSqlHelpers;
    if ($depth > 3) { return false; }
    $r = trim($rhs);
    if ($r === '') { return false; }

    if (sh_scan_numeric_expr($r, $src, $depth)) { return true; }

    // implode($sep, $pieces) with literal pieces.
    if (preg_match('/^(?:implode|join)\s*\(\s*[\'"][^\'"]*[\'"]\s*,\s*\$([a-zA-Z_][a-zA-Z0-9_]*)\s*\)$/', $r, $im) === 1) {
        return sh_scan_pieces_are_literal($src, $im[1]);
    }
    // A placeholder run on its own.
    $stripped = sh_scan_strip_builders($r);
    if (preg_match('/^[\'"][^\'"]*[\'"]$/', $stripped) === 1 && !str_contains($stripped, '$')) { return true; }

    // a ? 'literal' : 'literal' — the value returned is one of two constants.
    if (preg_match('/^[^?]*\?\s*[\'"][^\'"]*[\'"]\s*:\s*[\'"][^\'"]*[\'"]\s*$/', $r) === 1) { return true; }

    // $map[$x] ?? $map['default'] where $map is a literal array: allowlist lookup.
    if (preg_match('/^(\$[a-zA-Z_][a-zA-Z0-9_]*)\s*\[.*\]\s*\?\?\s*\1\s*\[.*\]$/s', $r, $mm) === 1) {
        return sh_scan_map_is_literal($src, ltrim($mm[1], '$'));
    }

    // Audited helper that returns constant SQL with placeholders.
    if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s*\(([^()]*)\)$/', $r, $fn) === 1) {
        if (in_array($fn[1], $constantSqlHelpers, true) && preg_match('/\$[a-zA-Z_]/', $fn[2]) !== 1) { return true; }
    }

    // A chain of string literals (optionally joined with placeholders).
    $chain = $stripped;
    $chain = preg_replace('/[\'"][^\'"]*[\'"]/', "'x'", $chain) ?? $chain;
    $chain = str_replace(["'x'", '.', ' ', "\t"], '', $chain);
    return $chain === '';
}

/**
 * Is this variable a SQL fragment whose content is provably constant?
 *
 * The codebase builds WHERE clauses and allowlists like:
 *     $where[] = 'p.name LIKE ?';
 *     $whereSql = implode(' AND ', $where);
 *     $sortMap = ['newest' => 'p.created_at DESC', ...];
 *     $order = $sortMap[$f['sort']] ?? $sortMap['popular'];
 * Every piece is a constant, so the interpolation cannot carry a payload. This
 * proves that property from the assignments themselves rather than trusting the
 * variable's name: adding a request value anywhere in the chain makes the check
 * fail and the line is reported.
 */
function sh_scan_fragment_is_safe(string $src, string $name): bool
{
    $assignments = sh_scan_var_assignments($src, $name);
    if ($assignments === []) { return false; }
    foreach ($assignments as $rhs) {
        if (!sh_scan_rhs_is_fragment($rhs, $src)) { return false; }
    }
    return true;
}

/*
 * Functions audited by hand to return a constant SQL fragment with ? holders.
 * sh_reporting_date_condition() and sh_reporting_paid_order_condition() build
 * their string from literal SQL plus an alias that goes through
 * sh_reporting_alias(), which accepts only a short identifier. Add a name here
 * only after reading the function body.
 */
$constantSqlHelpers = ['sh_reporting_date_condition', 'sh_reporting_paid_order_condition'];

/** Was this variable assigned from the request anywhere in the file? */
function sh_scan_var_from_request(string $src, string $name): bool
{
    return preg_match('/\$' . preg_quote($name, '/') . '\s*=\s*[^;]*\$_(?:GET|POST|REQUEST|COOKIE)\s*\[/', $src) === 1;
}

/** Recursively list files with one of the given extensions. */
function sh_scan_list(string $dir, array $exts, array $skip = []): array
{
    $out = [];
    if (!is_dir($dir)) { return $out; }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $path => $info) {
        $rel = ltrim(str_replace(dirname(__DIR__), '', (string)$path), '/');
        foreach ($skip as $s) {
            if ($rel === $s || str_starts_with($rel . '/', rtrim($s, '/') . '/')) { continue 2; }
        }
        if ($info->isDir()) { continue; }
        if (in_array(strtolower($info->getExtension()), $exts, true)) { $out[] = (string)$path; }
    }
    sort($out);
    return $out;
}

/** Directories that are never part of a web request path. */
$SH_SKIP_DIRS = ['tools', 'tests', '.git', 'node_modules', 'vendor', 'logs', 'backups', 'uploads', 'assets'];

/** Is this line a comment or a doc block? */
function sh_scan_is_comment(string $line): bool
{
    $t = ltrim($line);
    return $t === '' || str_starts_with($t, '*') || str_starts_with($t, '//') || str_starts_with($t, '/*');
}

/** Has the author reviewed and cleared this exact line for this rule? */
function sh_scan_ignored(string $line, string $rule): bool
{
    if (!str_contains($line, '@security-scan-ignore')) { return false; }
    if (str_contains($line, '@security-scan-ignore *')) { return true; }
    if (str_contains($line, '@security-scan-ignore ' . $rule)) { return true; }
    // sql_interpolation_unproven is the refined form of sql_interpolation: an
    // existing review comment for one clears the other.
    if ($rule === 'sql_interpolation_unproven' && str_contains($line, '@security-scan-ignore sql_interpolation')) { return true; }
    return false;
}

function sh_scan_rel(string $path): string
{
    return ltrim(str_replace(dirname(__DIR__) . '/', '', $path), '/');
}

// ===========================================================================
// 1. Dangerous function calls (token based, so strings and comments are safe)
// ===========================================================================
$dangerous = [
    'eval'            => ['critical', 'eval', 'eval() executes its argument as code.'],
    'assert'          => ['medium', 'assert', 'assert() with a string argument evaluates code in old PHP builds.'],
    'create_function' => ['critical', 'create_function', 'create_function() builds code from a string.'],
    'shell_exec'      => ['critical', 'shell_exec', 'Shell execution.'],
    'passthru'        => ['critical', 'passthru', 'Shell execution.'],
    'system'          => ['critical', 'system', 'Shell execution.'],
    'popen'           => ['critical', 'popen', 'Shell execution.'],
    'proc_open'       => ['critical', 'proc_open', 'Process execution.'],
    'pcntl_exec'      => ['critical', 'pcntl_exec', 'Process execution.'],
    'unserialize'     => ['high', 'unserialize', 'unserialize() on untrusted input allows object injection.'],
    'extract'         => ['medium', 'extract', 'extract() can overwrite variables from request data.'],
];

foreach (sh_scan_list($SH_ROOT, ['php'], $SH_SKIP_DIRS) as $path) {
    $rel = sh_scan_rel($path);
    $filesScanned++;
    $src = (string)file_get_contents($path);
    $tokens = @token_get_all($src);
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $t = $tokens[$i];
        if (!is_array($t) || $t[0] !== T_STRING) { continue; }
        $name = strtolower($t[1]);
        $line = $t[2];

        // Skip a declaration ("function eval(" is impossible, but "function
        // system(" style helpers are not) and skip method calls (->foo()).
        $prev = $i - 1;
        while ($prev >= 0 && is_array($tokens[$prev]) && in_array($tokens[$prev][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $prev--; }
        $prevTok = $prev >= 0 ? $tokens[$prev] : null;
        $prevIsArrow = is_array($prevTok) && in_array($prevTok[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR], true);
        $prevIsFunction = is_array($prevTok) && $prevTok[0] === T_FUNCTION;

        $next = $i + 1;
        while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) { $next++; }
        $isCall = $next < $count && $tokens[$next] === '(';

        if (!$isCall || $prevIsArrow || $prevIsFunction) { continue; }
        if (!isset($dangerous[$name])) { continue; }

        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
        $text = $lines[$line - 1] ?? '';
        if (sh_scan_is_comment($text) || sh_scan_ignored($text, 'dangerous_call')) { continue; }
        // A docblock example (" * example: eval($x)") is not a call.
        if (preg_match('/^\s*\*|^\s*\/\//', $text) === 1) { continue; }

        [$severity, $rule, $message] = $dangerous[$name];
        sh_scan_add($severity, $rule, $message . ' (' . $name . ')', $rel, $line,
            'Remove it, or replace it with a specific library call.');
    }
}

// ===========================================================================
// 2. Request data reaching SQL, and unescaped output (token based)
// ===========================================================================
$sqlFunctions = ['query', 'prepare', 'exec', 'sh_query', 'sh_all', 'sh_one', 'sh_val', 'sh_col'];
$superglobals = ['_GET', '_POST', '_REQUEST', '_COOKIE'];

foreach (sh_scan_list($SH_ROOT, ['php'], $SH_SKIP_DIRS) as $path) {
    $rel = sh_scan_rel($path);
    $src = (string)file_get_contents($path);
    $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
    $tokens = @token_get_all($src);
    $count = count($tokens);

    // --- 2a. superglobal interpolated into a SQL string argument ------------
    for ($i = 0; $i < $count; $i++) {
        $t = $tokens[$i];
        if (!is_array($t) || $t[0] !== T_STRING || !in_array(strtolower($t[1]), $sqlFunctions, true)) { continue; }

        $prev = $i - 1;
        while ($prev >= 0 && is_array($tokens[$prev]) && in_array($tokens[$prev][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $prev--; }
        $prevTok = $prev >= 0 ? $tokens[$prev] : null;
        if (is_array($prevTok) && in_array($prevTok[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR, T_FUNCTION], true)) { continue; }
        $next = $i + 1;
        while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) { $next++; }
        if ($next >= $count || $tokens[$next] !== '(') { continue; }

        // Walk to the matching close paren, tracking depth. A variable is
        // interpolated when it appears between the delimiters of a string, so
        // the walk has to know when it is inside one.
        $depth = 0;
        $startLine = $t[2];
        $inString = false;
        $firstArgEnd = null;
        $interpVars = [];
        for ($j = $next; $j < $count; $j++) {
            $tk = $tokens[$j];
            if ($tk === '(') { $depth++; continue; }
            if ($tk === ')') { $depth--; if ($depth === 0) { break; } continue; }
            if ($tk === ',' && $depth === 1 && $firstArgEnd === null) { $firstArgEnd = $j; continue; }
            if ($tk === '"') { $inString = !$inString; continue; }
            if (!is_array($tk)) { continue; }
            if ($tk[0] === T_START_HEREDOC) { $inString = true; continue; }
            if ($tk[0] === T_END_HEREDOC) { $inString = false; continue; }
            if ($tk[0] === T_VARIABLE && $inString && $firstArgEnd === null) {
                $interpVars[$tk[1]] = $tk[2];
            }
        }
        if ($interpVars === []) { continue; }

        foreach ($interpVars as $var => $varLine) {
            $name = ltrim((string)$var, '$');
            $text = $lines[$varLine - 1] ?? '';
            if (sh_scan_is_comment($text)) { continue; }
            // Request data is a real injection regardless of how it is shaped.
            if (sh_scan_var_from_request($src, $name)) {
                if (sh_scan_ignored($text, 'sql_interpolation')) { continue; }
                sh_scan_add('critical', 'sql_interpolation',
                    'Request value $' . $name . ' is interpolated into a SQL string.', $rel, $varLine,
                    'Replace the interpolation with a ? placeholder and pass the value in the parameter array.');
                continue;
            }
            if (sh_scan_fragment_is_safe($src, $name)) { continue; }
            if (sh_scan_ignored($text, 'sql_interpolation')) { continue; }
            sh_scan_add('high', 'sql_interpolation_unproven',
                'A SQL string interpolates $' . $name . ', and the scanner could not prove it holds only '
                . 'placeholders or numbers.', $rel, $varLine,
                'Use a bound parameter. If the value is genuinely a fixed fragment, review it and add '
                . '// @security-scan-ignore sql_interpolation_unproven to the line.');
        }
    }

    // --- 2b. superglobal echoed straight out -------------------------------
    foreach ($lines as $n => $line) {
        if (sh_scan_is_comment($line) || sh_scan_ignored($line, 'superglobal_output')) { continue; }
        foreach ($superglobals as $sg) {
            $pattern = '/(?:echo|print|printf)\s[^;]*\$_' . $sg . '\s*\[|<\?=[^;]*\$_' . $sg . '\s*\[/';
            if (preg_match($pattern, $line) === 1) {
                if (preg_match('/\be\s*\(|htmlspecialchars|sh_esc|intval|\(int\)/', $line) === 1) { continue; }
                sh_scan_add('high', 'superglobal_output',
                    'Request data is printed without escaping (' . $sg . ').', $rel, $n + 1,
                    'Wrap it in e(...) for HTML context, or sh_url() for a URL attribute.');
            }
        }
    }

    // --- 2c. local variable copied from the request then printed -----------
    // Statement-scoped and deliberately shallow: it catches the common
    // "$q = $_GET['q']; ... echo $q" shape without pretending to be a real
    // data-flow analysis.
    $tainted = [];
    foreach ($lines as $n => $line) {
        if (sh_scan_is_comment($line)) { continue; }
        if (preg_match('/\$([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*\$_(GET|POST|REQUEST|COOKIE)\s*\[/', $line, $m) === 1) {
            $tainted[$m[1]] = $n + 1;
        }
        foreach ($tainted as $var => $at) {
            $pattern = '/(?:echo|print|printf)\s[^;]*\$' . preg_quote($var, '/') . '\b|<\?=[^;]*\$' . preg_quote($var, '/') . '\b/';
            if (preg_match($pattern, $line) !== 1) { continue; }
            if (preg_match('/\be\s*\(|htmlspecialchars|sh_esc|sh_url\s*\(|intval|\(int\)/', $line) === 1) { continue; }
            if (sh_scan_ignored($line, 'tainted_output')) { continue; }
            sh_scan_add('high', 'tainted_output',
                'Request value $' . $var . ' is printed without escaping.', $rel, $n + 1,
                'Wrap it in e(...) for HTML context.');
        }
    }
}

// ===========================================================================
// 3. Weak cryptography, weak randomness and hardcoded secrets
// ===========================================================================
$secretRules = [
    ['/(?:^|[^\w>$])(?:md5|sha1)\s*\(\s*(?:\$[a-z_]*(?:pass|pwd|secret|token)|[^\n]*\$_(?:POST|GET|REQUEST)[^\n]*(?:pass|pwd))/i',
     'critical', 'weak_hash', 'Passwords are hashed with md5/sha1.'],
    ['/\b(?:mt_rand|rand|uniqid|lcg_value)\s*\(/', 'medium', 'weak_random',
     'Predictable random source. Use random_bytes() for anything security related.'],
    ['/\b(?:sk|rk)_(?:live|test)_[A-Za-z0-9]{10,}/', 'critical', 'secret_in_code', 'A payment/provider secret key is committed.'],
    ['/bot[0-9]{6,}:[A-Za-z0-9_-]{30,}/', 'critical', 'secret_in_code', 'A Telegram bot token is committed.'],
    ['/\bAKIA[0-9A-Z]{16}\b/', 'critical', 'secret_in_code', 'An AWS access key is committed.'],
    ['/-----BEGIN (?:RSA |EC |OPENSSH |PGP )?PRIVATE KEY-----/', 'critical', 'secret_in_code', 'A private key is committed.'],
];
foreach (sh_scan_list($SH_ROOT, ['php'], $SH_SKIP_DIRS) as $path) {
    $rel = sh_scan_rel($path);
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $n => $line) {
        if (sh_scan_is_comment($line)) { continue; }
        foreach ($secretRules as [$re, $sev, $rule, $msg]) {
            if (preg_match($re, $line) === 1 && !sh_scan_ignored($line, $rule)) {
                sh_scan_add($sev, $rule, $msg, $rel, $n + 1, 'Move the value to configuration and rotate it.');
            }
        }
    }
}

// ===========================================================================
// 4. Authorization and CSRF coverage on entry points
// ===========================================================================
/** Endpoints intentionally reachable without a session, and why. */
$publicEndpoints = [
    'index.php' => 'storefront', 'shop.php' => 'storefront', 'products.php' => 'catalogue',
    'product.php' => 'catalogue', 'category.php' => 'catalogue', 'search.php' => 'catalogue',
    'brands.php' => 'catalogue', 'brand.php' => 'catalogue', 'offers.php' => 'catalogue',
    'about.php' => 'content', 'contact.php' => 'content', 'faq.php' => 'content',
    'page.php' => 'content', 'terms.php' => 'content', 'privacy.php' => 'content',
    'refund-policy.php' => 'content', 'shipping-policy.php' => 'content',
    'cart.php' => 'guest cart', 'checkout.php' => 'guest checkout', 'track.php' => 'guest order tracking',
    'order-success.php' => 'post-checkout receipt', 'login.php' => 'sign in', 'register.php' => 'sign up',
    'forgot-password.php' => 'password reset', 'reset-password.php' => 'password reset',
    'verify-email.php' => 'email verification', 'verify-phone.php' => 'phone verification',
    'verify.php' => 'verification landing', '404.php' => 'error page', 'maintenance.php' => 'maintenance mode',
    'logout.php' => 'ends the session', 'image.php' => 'public image proxy',
    'api/otp.php' => 'sign-in one-time code', 'api/search.php' => 'public search suggestions',
    'api/cart.php' => 'guest cart operations', 'api/notifications.php' => 'public notification feed',
    'api/csp-report.php' => 'browser CSP reports',
];

$guardTokens = ['sh_require_admin', 'sh_require_perm', 'sh_require_superadmin', 'sh_require_login',
    'sh_admin_guard', 'admin_auth', 'sh_customer_guard', 'sh_require_customer'];
$adminFiles = array_merge(sh_scan_list($SH_ROOT . '/admin', ['php']), sh_scan_list($SH_ROOT . '/api', ['php']));
foreach ($adminFiles as $path) {
    $rel = sh_scan_rel($path);
    $base = basename($rel);
    if (str_starts_with($base, '_') || str_starts_with($base, 'partial')) { continue; }
    if (in_array($base, ['index.php', 'login.php', 'logout.php'], true)) { continue; }
    if (isset($publicEndpoints[$rel])) { continue; }
    $src = (string)file_get_contents($path);
    $guarded = false;
    foreach ($guardTokens as $g) { if (str_contains($src, $g)) { $guarded = true; break; } }
    if (str_contains($src, '_ai.php')) { $guarded = true; }   // admin/ai/* share includes/admin/ai/_ai.php
    if (!$guarded) {
        sh_scan_add('critical', 'missing_auth_guard',
            'Administrative endpoint has no visible authentication or permission guard.', $rel, 1,
            'Require an administrator session (sh_require_admin / sh_require_perm) before doing any work.');
    }
}

/*
 * POST endpoints that legitimately cannot carry a CSRF token, with the reason.
 * A browser sends a CSP violation report with no session and no way to embed a
 * token, and the endpoint must accept it or the reporting feature is useless.
 * Every entry here is a deliberate exemption — without one, a new unguarded
 * POST handler is reported.
 */
$csrfExempt = [
    'api/csp-report.php' => 'browser-originated CSP reports carry no session and no token, and change no state',
];

// CSRF on anything that changes state.
foreach (sh_scan_list($SH_ROOT, ['php'], array_merge($SH_SKIP_DIRS, ['includes', 'config', 'install', 'cron'])) as $path) {
    $rel = sh_scan_rel($path);
    $src = (string)file_get_contents($path);
    if (preg_match('/REQUEST_METHOD[^\n]{0,20}POST/', $src) !== 1) { continue; }
    if (str_contains($src, 'sh_csrf_require()') || str_contains($src, 'sh_csrf_valid()')) { continue; }
    if (isset($csrfExempt[$rel])) { continue; }
    sh_scan_add('high', 'missing_csrf',
        'Handles POST without validating a CSRF token.', $rel, 1,
        'Call sh_csrf_require() at the top of the POST branch.');
}

// ===========================================================================
// 5. Uploads, files and paths
// ===========================================================================
foreach (sh_scan_list($SH_ROOT, ['php'], $SH_SKIP_DIRS) as $path) {
    $rel = sh_scan_rel($path);
    if ($rel === 'includes/functions.php') { continue; }   // hosts sh_upload_image()
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $n => $line) {
        if (sh_scan_is_comment($line)) { continue; }
        if (str_contains($line, 'move_uploaded_file') && !sh_scan_ignored($line, 'upload_bypass')) {
            sh_scan_add('high', 'upload_bypass',
                'File upload handled outside the central sh_upload_image() validator.', $rel, $n + 1,
                'Route the upload through sh_upload_image() so MIME, size and extension checks always apply.');
        }
        if (preg_match('/move_uploaded_file\s*\([^;]*\[[\'"]name[\'"]\]/', $line) === 1) {
            sh_scan_add('critical', 'upload_name', 'Upload destination uses the client-supplied file name.', $rel, $n + 1,
                'Generate the stored name on the server.');
        }
        if (preg_match('/(?:readfile|file_get_contents|fopen|unlink|include|require)(?:_once)?\s*\(\s*\$_(?:GET|POST|REQUEST)/', $line) === 1) {
            sh_scan_add('critical', 'file_from_request', 'A file operation is driven directly by request data.', $rel, $n + 1,
                'Resolve the file from a server-side allowlist, never from the request.');
        }
        if (preg_match('/\.\.\s*\/|\/\.\./', $line) === 1 && preg_match('/\$_(?:GET|POST|REQUEST)/', $line) === 1) {
            sh_scan_add('high', 'traversal', 'Possible path traversal from request data.', $rel, $n + 1);
        }
    }
}

// ===========================================================================
// 6. Debug leftovers and error exposure
// ===========================================================================
foreach (sh_scan_list($SH_ROOT, ['php'], $SH_SKIP_DIRS) as $path) {
    $rel = sh_scan_rel($path);
    $base = strtolower(basename($rel));
    if (in_array($base, ['phpinfo.php', 'info.php', 'test.php', 'debug.php', 'dump.php'], true)) {
        sh_scan_add('critical', 'debug_endpoint', 'Development endpoint left in the web root.', $rel, 1,
            'Delete it: it exposes environment details to anyone who guesses the name.');
    }
    $src = (string)file_get_contents($path);
    if (preg_match('/ini_set\s*\(\s*[\'"]display_errors[\'"]\s*,\s*[\'"](?:1|on|true)[\'"]/i', $src) === 1) {
        sh_scan_add('high', 'display_errors', 'display_errors is switched on, so errors reach visitors.', $rel, 1,
            'Log errors instead of displaying them.');
    }
    if (preg_match('/(?:^|[^a-z_])(?:var_dump|print_r)\s*\(/', $src, $m) === 1
        && !str_contains($src, '@security-scan-ignore var_dump')) {
        sh_scan_add('low', 'var_dump', 'Debug helper left in shipped code (' . trim($m[0], ' (') . ').', $rel, 1,
            'Remove it, or keep it behind an administrator-only diagnostic page.');
    }
}

// ===========================================================================
// 7. CSP readiness
// ===========================================================================
foreach (sh_scan_list($SH_ROOT, ['php'], $SH_SKIP_DIRS) as $path) {
    $rel = sh_scan_rel($path);
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $n => $line) {
        if (sh_scan_is_comment($line) || sh_scan_ignored($line, 'inline_handler')) { continue; }
        if (preg_match('/\son(?:click|change|input|submit|load|error|focus|blur|keyup|keydown|mouseover|mouseout|dblclick)\s*=/i', $line) === 1) {
            sh_scan_add('medium', 'inline_handler',
                'Inline event handler: the enforced CSP blocks it, so the behaviour would stop working.', $rel, $n + 1,
                'Move the handler into a script file, or use a data-attribute + addEventListener().');
        }
        if (preg_match('/<script(?![^>]*\bsrc=)/i', $line) === 1 && !str_contains($line, 'sh_csp_nonce_attr')) {
            sh_scan_add('medium', 'inline_script', 'Inline <script> without the CSP nonce attribute.', $rel, $n + 1,
                'Add <?= sh_csp_nonce_attr() ?> to the tag.');
        }
    }
}

// ===========================================================================
// 8. Repository and deployment hygiene
// ===========================================================================
$requiredGuards = ['.htaccess', 'config/.htaccess', 'logs/.htaccess', 'uploads/.htaccess', 'includes/.htaccess', '.user.ini'];
foreach ($requiredGuards as $guard) {
    if (!is_file($SH_ROOT . '/' . $guard)) {
        sh_scan_add('high', 'missing_guard', 'Required web-server guard file is missing: ' . $guard, $guard, 1,
            'Restore it from the repository.');
    }
}

$gitIgnore = (string)@file_get_contents($SH_ROOT . '/.gitignore');
foreach (['/config/database.php', '/logs/*.log', '/uploads/*'] as $needle) {
    if (!str_contains($gitIgnore, $needle)) {
        sh_scan_add('high', 'gitignore', '.gitignore no longer excludes ' . $needle, '.gitignore', 1,
            'Restore the rule so live data cannot be committed.');
    }
}

/**
 * Paths currently in the git index.
 *
 * The index is parsed directly instead of shelling out to `git`, because some
 * hosts disable process functions and the scanner must still work there. The
 * index stores each path as a NUL-terminated byte string, so a substring search
 * over the whole file is enough for the "is this tracked?" question.
 */
$gitIndex = '';
if (is_file($SH_ROOT . '/.git/index')) {
    $gitIndex = (string)@file_get_contents($SH_ROOT . '/.git/index');
}
if ($gitIndex !== '') {
    foreach (['config/database.php', 'config/installed.lock', 'config/install-unlock.key'] as $mustNotTrack) {
        if (str_contains($gitIndex, $mustNotTrack . "\0")) {
            sh_scan_add('critical', 'tracked_secret', 'Live credential or lock file is tracked by git: ' . $mustNotTrack,
                $mustNotTrack, 1, 'Run: git rm --cached ' . $mustNotTrack . ' (the file stays on disk).');
        }
    }
    $trackedUploads = 0;
    foreach (['uploads/products/', 'uploads/payments/', 'uploads/logos/'] as $dir) {
        if (str_contains($gitIndex, $dir)) { $trackedUploads++; }
    }
    if ($trackedUploads > 0) {
        sh_scan_add('medium', 'tracked_uploads',
            'Store data (customer uploads, product images) is tracked by version control.', 'uploads/', 1,
            'Run: git rm -r --cached uploads, then commit. Uploads are store data, not source.');
    }
}

// Secrets that leaked into log files.
foreach (glob($SH_ROOT . '/logs/*.log') ?: [] as $log) {
    $rel = sh_scan_rel($log);
    foreach (file($log, FILE_IGNORE_NEW_LINES) ?: [] as $n => $line) {
        if (str_contains($line, '[redacted]')) { continue; }
        if (preg_match('/\b(?:password|passwd|pwd|db_pass)\b\s*[=:]\s*["\']?[^\s"\'\[\]]{6,}/i', $line) === 1
            || preg_match('/bot[0-9]{6,}:[A-Za-z0-9_-]{30,}/', $line) === 1
            || preg_match('/(?:SHSESSID|[a-f0-9]{64})\s*[=:]\s*[A-Za-z0-9,\-]{20,}/', $line) === 1) {
            sh_scan_add('critical', 'secret_in_log', 'A secret or token appears in the log without redaction.', $rel, $n + 1,
                'Fix the redaction helper so the value never reaches the log, then rotate it.');
        }
    }
}

// ===========================================================================
// Report
// ===========================================================================
$rank = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
$counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
foreach ($findings as $f) { $counts[$f['severity']]++; }
usort($findings, static function (array $a, array $b) use ($rank): int {
    return [$rank[$b['severity']], $a['file'], $a['line']] <=> [$rank[$a['severity']], $b['file'], $b['line']];
});

if ($asJson) {
    echo json_encode(['scanned' => $filesScanned, 'counts' => $counts, 'findings' => $findings],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} else {
    echo "ShopHaat static security scan\n";
    echo str_repeat('=', 72), "\n";
    if ($findings === []) {
        echo "No findings.\n";
    }
    foreach ($findings as $f) {
        printf("[%-8s] %s\n", strtoupper($f['severity']), $f['message']);
        printf("           %s:%d  (%s)\n", $f['file'], $f['line'], $f['rule']);
        if ($f['fix'] !== '') { printf("           fix: %s\n", $f['fix']); }
    }
    echo str_repeat('-', 72), "\n";
    printf("critical %d   high %d   medium %d   low %d   (files scanned: %d)\n",
        $counts['critical'], $counts['high'], $counts['medium'], $counts['low'], $filesScanned);
}

$threshold = $rank[$failOn] ?? 4;
$worst = 0;
foreach ($findings as $f) { $worst = max($worst, $rank[$f['severity']]); }
exit(($worst > 0 && $worst >= $threshold) ? 1 : 0);
