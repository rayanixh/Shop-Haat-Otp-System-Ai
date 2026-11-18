<?php
/**
 * Cron / shared-hosting authentication — behavioural tests.
 *
 * The scheduled backup can be invoked two ways: over HTTP with ?token=…, and from
 * the cPanel cron line with `php …/cron/backup.php --token=…`. The second form is
 * the one that used to be documented wrongly: PHP does not populate $_GET from a
 * command line, so `php cron/backup.php?token=…` could never authenticate.
 *
 * Rather than grepping for that fix, this suite EXTRACTS the shipped
 * sh_cron_given_token() from cron/backup.php and calls it with real argv arrays, a
 * real $_GET and a real $_SERVER — so the documented commands are executed, not
 * assumed.
 *
 *     php tests/cron-cli-auth.php
 *     SH_TEST_PHP_BIN=/path/to/php php tests/cron-cli-auth.php   # enables the
 *                                                                # end-to-end run
 *
 * Not covered here (reported BLOCKED): the full token → backup → reply path, which
 * needs a configured database, and the HTTP surface, which needs a web server.
 */
declare(strict_types=1);

require_once __DIR__ . '/_support_db_bootstrap.php';
require_once __DIR__ . '/_support_php_bin.php';

$cronPath = SH_ROOT . '/cron/backup.php';
$cronSource = (string)file_get_contents($cronPath);

/** Include one shipped function verbatim so the real code runs under test. */
function cron_extract_function(string $source, string $name): void
{
    if (function_exists($name)) { return; }
    $pattern = '/^function\s+' . preg_quote($name, '/') . '\s*\([^)]*\).*?^\}/ms';
    if (preg_match($pattern, $source, $m) !== 1) {
        throw new RuntimeException('Could not extract ' . $name . '() from cron/backup.php');
    }
    eval($m[0]);
}
cron_extract_function($cronSource, 'sh_cron_given_token');

$php = sh_test_php_binary();

[$passed, $failed, $blocked] = sh_test_run('Cron / shared-hosting authentication (real argument parsing)', static function ($ok, $eq, $blocked) use ($cronSource, $cronPath, $php) {
    $ok(sh_test_contains_generic_500('<p class="error-code">Error 500</p><h1>Something went wrong</h1>'),
        'generic Error 500 HTML is recognized as an error, not a successful test response');
    $ok(!sh_test_contains_generic_500('<title>Backup complete | ShopHaat</title><main>OK</main>'),
        'ordinary successful HTML is not classified as the generic Error 500 page');

    $resolve = static function (bool $cli, array $argv, array $get = [], array $server = []): string {
        $_GET = $get;
        $_SERVER = $server;
        return sh_cron_given_token($cli, $argv);
    };
    $token = 'cron-secret-9f2c41';

    /* --- 1. The documented CLI form actually works ----------------------- */
    $eq($token, $resolve(true, ['cron/backup.php', '--token=' . $token]),
        'CLI --token=… is read from argv');
    $eq($token, $resolve(true, ['/home/user/public_html/cron/backup.php', '--token=' . $token]),
        'a cPanel absolute path does not break --token=… parsing');
    $eq($token, $resolve(true, ['cron/backup.php', $token]),
        'a bare positional token works when the host strips dashes');

    putenv('SH_BACKUP_CRON_TOKEN=' . $token);
    $eq($token, $resolve(true, ['cron/backup.php']),
        'SH_BACKUP_CRON_TOKEN supplies the token when argv has none');
    putenv('SH_BACKUP_CRON_TOKEN');

    /* --- 2. The old, wrong command cannot authenticate ------------------- */
    $broken = $resolve(true, ['cron/backup.php', 'cron/backup.php?token=' . $token]);
    $ok($broken !== $token,
        'the old `php cron/backup.php?token=…` form does NOT yield the token (nothing is silently accepted)');
    $ok($resolve(true, ['cron/backup.php', '?token=' . $token]) !== $token,
        'a stray "?token=…" argument is not treated as a query string');
    $eq('', $resolve(true, ['cron/backup.php']),
        'with no argument, no environment variable and no configuration the token is empty (so the endpoint refuses)');

    /* --- 3. HTTP mode still reads the query string and header ------------ */
    $eq($token, $resolve(false, [], ['token' => $token]),
        'HTTP ?token=… is still accepted');
    $eq($token, $resolve(false, [], ['token' => 'wrong'], ['HTTP_X_BACKUP_TOKEN' => $token]),
        'the X-Backup-Token header wins over the query string (keeps the secret out of access logs)');
    $eq('', $resolve(false, [], []),
        'an HTTP request with no token and no header resolves to empty');
    $eq('', $resolve(false, [], ['token' => ''], ['HTTP_X_BACKUP_TOKEN' => '']),
        'empty header and empty query are treated as absent, not as a valid token');
    $eq('query-wins-if-no-header', $resolve(false, [], ['token' => 'query-wins-if-no-header'], ['HTTP_X_BACKUP_TOKEN' => '']),
        'an empty header does not mask a valid query token');

    /* --- 4. CLI mode never consults $_GET or $_POST ---------------------- */
    $eq('', $resolve(true, ['cron/backup.php'], ['token' => $token], ['HTTP_X_BACKUP_TOKEN' => $token]),
        'CLI mode ignores $_GET and HTTP headers entirely');

    /* --- 5. Comparison and hygiene in the shipped source ----------------- */
    $ok(str_contains($cronSource, 'hash_equals($expected, $given)'), 'the token comparison uses hash_equals() (constant time)');
    $ok(str_contains($cronSource, 'trim(sh_cron_given_token('), 'the supplied token is trimmed before comparison');
    $ok(!preg_match('~echo[^;]*\$expected~', $cronSource) && !preg_match('~echo[^;]*\$given~', $cronSource),
        'the expected and supplied tokens are never echoed');
    $ok(!preg_match('~sh_log_line\([^)]*\$given~', $cronSource), 'the supplied token is never written to the log');
    $ok(str_contains($cronSource, "'backup-cron-token'"), 'HTTP token failures are rate limited');
    $ok(str_contains($cronSource, "sh_secret('backup_cron_token'"), 'the token is read from the secret store first, then the setting');

    // The CLI branch must be the one that reads $argv, and $_GET must appear only
    // inside the HTTP branch — the exact defect that made the old command useless.
    $cliBranchStart = strpos($cronSource, "    if (!\$isCli) {");
    $getPos = strpos($cronSource, "\$_GET['token']");
    $ok($cliBranchStart !== false && $getPos !== false && $getPos > $cliBranchStart,
        '$_GET is only consulted in the HTTP branch, never for a CLI run');

    /* --- 6. Base URL resolution for root and subdirectory installs ------- */
    $baseUrlCases = [
        ['/index.php', ''],
        ['/cron/backup.php', ''],
        ['/admin/products.php', ''],
        ['/shop/index.php', '/shop'],
        ['/shop/cron/backup.php', '/shop'],
        ['/shop/admin/ai/history.php', '/shop'],
    ];
    if ($php['ok']) {
        foreach ($baseUrlCases as [$script, $expected]) {
            $file = tempnam(sys_get_temp_dir(), 'sh-baseurl-');
            $root = var_export(SH_ROOT, true);
            // config.php defines SH_ROOT itself, so the child only sets the
            // request it is pretending to serve.
            // The request has to be faked BEFORE config.php loads: the bootstrap
            // itself calls sh_base_url(), which caches the answer.
            file_put_contents($file, "<?php\n"
                . "error_reporting(0);\n"
                . "\$_SERVER['SCRIPT_NAME'] = " . var_export($script, true) . ";\n"
                . "\$_SERVER['REQUEST_URI'] = " . var_export($script, true) . ";\n"
                . "\$_SERVER['HTTP_HOST'] = 'example.test';\n"
                . "require_once {$root} . '/config/config.php';\n"
                . "echo sh_base_url();\n");
            $out = trim((string)@shell_exec(escapeshellarg($php['bin']) . ' ' . escapeshellarg((string)$file) . ' 2>/dev/null'));
            @unlink((string)$file);
            // config.php runs its own request preamble; only the marker matters.
            $eq($expected, $out, 'sh_base_url() for ' . $script . ' is "' . $expected . '"');
        }
    } else {
        $blocked('sh_base_url() for a root install', $php['reason']);
        $blocked('sh_base_url() for a /shop subdirectory install', $php['reason']);
        $blocked('sh_base_url() for a /shop/cron CLI path', $php['reason']);
    }

    /* --- 7. The documented command, parsed and executed ------------------ */
    $checklist = (string)@file_get_contents(SH_ROOT . '/SHOP_HAAT_DEPLOYMENT_CHECKLIST.md');
    $documented = [];
    if (preg_match_all('~^\s*(?:php|/usr/local/bin/php|\S+/php)\s+(\S*cron/backup\.php)\s*(--token=\S+)?~m', $checklist, $m, PREG_SET_ORDER)) {
        $documented = $m;
    }
    $ok($documented !== [], 'the deployment checklist documents a php CLI cron command');
    if ($documented !== []) {
        foreach ($documented as $doc) {
            $argv = [$doc[1]];
            if (!empty($doc[2])) { $argv[] = $doc[2]; }
            $resolved = $resolve(true, $argv);
            $wanted = isset($doc[2]) && $doc[2] !== '' ? substr($doc[2], strlen('--token=')) : '';
            $ok($resolved !== '' && ($wanted === '' || $resolved === $wanted),
                'the documented command resolves the token it names: ' . trim($doc[0]));
        }
        $prose = [];
        foreach (preg_split('~\R~', $checklist) as $line) {
            // A prose sentence explaining the old broken form is fine; a command
            // line is not.
            if (preg_match('~php\s+\S*cron/backup\.php\?token=~', $line)
                && !preg_match('~(never|because|no longer|used to|previously)~i', $line)) {
                $prose[] = trim($line);
            }
        }
        $ok($prose === [], 'no documented command puts ?token= after the script path for CLI'
            . ($prose === [] ? '' : ': ' . $prose[0]));
    }

    /* --- 8. End-to-end CLI run when no database is configured ------------ */
    if (!$php['ok']) {
        $blocked('an uninstalled checkout exits with a clear CLI message', $php['reason']);
    } else {
        $root = var_export(SH_ROOT, true);
        $bin = var_export($php['bin'], true);
        $probe = tempnam(sys_get_temp_dir(), 'sh-cron-probe-');
        file_put_contents($probe, "<?php\nchdir({$root});\n"
            . "passthru(escapeshellarg({$bin}) . ' ' . escapeshellarg({$root} . '/cron/backup.php') . ' --token=x 2>&1', \$code);\n"
            . "echo \"\\nRC=\$code\";\n");
        $out = (string)@shell_exec(escapeshellarg($php['bin']) . ' ' . escapeshellarg((string)$probe) . ' 2>&1');
        @unlink((string)$probe);
        $ok(str_contains($out, 'cannot run from the command line'),
            'a CLI run without a database explains itself instead of exiting silently');
        $ok(str_contains($out, 'config/database.php') || str_contains($out, 'installer'),
            'the CLI message names the cause and the next step');
        preg_match('~(?:^|\\R)RC=(\\d+)\\s*$~', trim($out), $exitMatch);
        $ok((int)($exitMatch[1] ?? -1) === 1,
            'the CLI database preflight returns a non-zero process status');
        $ok(!sh_test_contains_generic_500($out),
            'a generic Error 500 HTML page is never accepted as the CLI failure response');
        $ok(!str_contains($out, 'Fatal error') && !str_contains($out, 'Uncaught'),
            'the CLI entry point fails cleanly (no fatal error or uncaught exception)');
    }
});

echo "\nResult: passed {$passed}, failed {$failed}" . ($blocked > 0 ? ", blocked {$blocked}" : '') . "\n";
exit(sh_test_exit_code($failed, $blocked));
