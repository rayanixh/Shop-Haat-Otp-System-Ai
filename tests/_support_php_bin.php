<?php
/**
 * Locate a PHP binary that can actually run a script in a child process.
 *
 * Several suites (admin access URL, request-level probes) must start a fresh PHP
 * process, because the code under test terminates the request with a 404 and only
 * a child process can observe that. Choosing that binary is surprisingly easy to
 * get wrong:
 *
 *   * `PHP_BINARY` is the right answer on a normal install;
 *   * inside php-wasm (the sandbox used for this project's automated runs) it is a
 *     temporary path that a subprocess cannot execute, and `is_executable()` even
 *     says true for it;
 *   * on some hosts there is no `php` on PATH at all.
 *
 * So a candidate is only accepted after it has actually produced output. When
 * nothing works the caller is told WHY, and the dependent checks are reported as
 * BLOCKED — never as a failure of the application, and never as a pass.
 *
 *     require_once __DIR__ . '/_support_php_bin.php';
 *     $php = sh_test_php_binary();
 *     if ($php['ok']) { …run subprocesses… } else { $blocked($php['reason']); }
 */
declare(strict_types=1);

if (!function_exists('sh_test_php_binary')) {
    /**
     * @return array{ok:bool,bin:string,source:string,reason:string}
     */
    function sh_test_php_binary(): array
    {
        static $result = null;
        if (is_array($result)) { return $result; }

        $candidates = [];
        $fromEnv = getenv('SH_TEST_PHP_BIN');
        if (is_string($fromEnv) && $fromEnv !== '') {
            $candidates[] = [$fromEnv, 'SH_TEST_PHP_BIN'];
        }
        if (defined('PHP_BINARY') && PHP_BINARY !== '') {
            $candidates[] = [(string)PHP_BINARY, 'PHP_BINARY'];
        }
        $fromPath = trim((string)@shell_exec('command -v php 2>/dev/null'));
        if ($fromPath !== '') {
            $candidates[] = [$fromPath, 'PATH'];
        }

        $reasons = [];
        foreach ($candidates as [$bin, $source]) {
            if (!is_file($bin) || !is_readable($bin)) {
                $reasons[] = $source . ' (' . $bin . ') is not a readable file';
                continue;
            }
            // Prove it runs. `-r` avoids depending on a temp file the sandbox may
            // not let the child open.
            $cmd = escapeshellarg($bin) . ' -r ' . escapeshellarg('echo "SH_PHP_OK";') . ' 2>/dev/null';
            $out = trim((string)@shell_exec($cmd));
            if (str_contains($out, 'SH_PHP_OK')) {
                return $result = ['ok' => true, 'bin' => $bin, 'source' => $source, 'reason' => ''];
            }
            $reasons[] = $source . ' (' . $bin . ') could not execute a script';
        }

        return $result = [
            'ok' => false,
            'bin' => '',
            'source' => '',
            'reason' => 'no runnable PHP binary for a child process: '
                . ($reasons === [] ? 'none found' : implode('; ', $reasons))
                . '. Set SH_TEST_PHP_BIN=/path/to/php to enable these checks.',
        ];
    }
}
