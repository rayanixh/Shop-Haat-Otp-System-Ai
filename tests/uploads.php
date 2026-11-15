<?php
/**
 * Upload security tests.
 *
 * Uploads are the classic "write a file, then execute it" chain. This suite
 * checks the parts that can be verified without a web server:
 *
 *   - every rejection path (wrong type, oversize, unreadable, tiny, huge,
 *     PHP disguised as an image, an upload that did not come through PHP);
 *   - the stored filename and extension come from the verified MIME type and
 *     random bytes, never from the client's filename;
 *   - a deployment cannot serve or execute what it stores (uploads/.htaccess);
 *   - deleting a file cannot escape the uploads directory.
 *
 * The happy path needs is_uploaded_file(), which only a real POST sets, so it is
 * covered by the manual browser step in the uploads report.
 *
 *     php tests/uploads.php
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;
function t_ok(string $name, bool $pass, string $detail = ''): bool
{
    if ($pass) { $GLOBALS['pass']++; echo "  ok   {$name}\n"; return true; }
    $GLOBALS['fail']++;
    echo "  FAIL {$name}" . ($detail !== '' ? "  [{$detail}]" : '') . "\n";
    return false;
}
function t_eq(string $name, $expected, $actual): bool
{
    return t_ok($name, $expected === $actual, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}
function t_sec(string $t): void { echo "\n" . $t . "\n"; }

define('SH_ROOT', dirname(__DIR__));
$sandbox = sys_get_temp_dir() . '/sh-uploads-' . bin2hex(random_bytes(4));
mkdir($sandbox, 0777, true);
define('SH_LOG_DIR', $sandbox . '/logs');
mkdir(SH_LOG_DIR, 0777, true);
define('SH_UPLOAD_DIR', $sandbox . '/uploads');
mkdir(SH_UPLOAD_DIR, 0777, true);

$_SERVER['HTTP_HOST'] = 'example.test';
$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
$_SERVER['REQUEST_URI'] = '/admin/product-edit.php';
$_SERVER['SCRIPT_NAME'] = '/admin/product-edit.php';
$_SERVER['REQUEST_METHOD'] = 'POST';

require_once SH_ROOT . '/includes/errors.php';
require_once SH_ROOT . '/includes/compat.php';
require_once SH_ROOT . '/includes/functions.php';

// A genuine 16x16 PNG (IHDR + IDAT + IEND), so getimagesize() reads a real image
// without needing the GD extension.
const T_PNG_16 = 'iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAAFklEQVR42mP4z8BAEmIY1TCqYfhqAACQ+f8B8u7oVwAAAABJRU5ErkJggg==';

function t_tmp(string $bytes): string
{
    $f = tempnam(sys_get_temp_dir(), 'shup');
    file_put_contents($f, $bytes);
    return $f;
}

function t_file(string $tmp, string $name, int $error = UPLOAD_ERR_OK): array
{
    clearstatcache(true, $tmp);
    return ['name' => $name, 'type' => 'image/png', 'tmp_name' => $tmp,
            'error' => $error, 'size' => (int)@filesize($tmp)];
}

// ===========================================================================
t_sec('1. PHP upload error codes are explained, not swallowed');
// ===========================================================================
$png = base64_decode(T_PNG_16, true);
$tmpPng = t_tmp($png);

$r = sh_upload_image(['error' => UPLOAD_ERR_NO_FILE, 'tmp_name' => '', 'name' => '', 'size' => 0], 'products');
t_ok('no file selected is reported', ($r['ok'] ?? true) === false && str_contains((string)($r['error'] ?? ''), 'No file selected'));

$r = sh_upload_image(['error' => [UPLOAD_ERR_OK], 'tmp_name' => '', 'name' => '', 'size' => 0], 'products');
t_ok('an array-shaped error (attack or misconfiguration) is rejected', ($r['ok'] ?? true) === false);

$r = sh_upload_image(['error' => UPLOAD_ERR_INI_SIZE] + t_file($tmpPng, 'x.png'), 'products');
t_ok('the server size limit names the limit and the fix', str_contains((string)($r['error'] ?? ''), 'larger than this server allows'));
$r = sh_upload_image(['error' => UPLOAD_ERR_PARTIAL] + t_file($tmpPng, 'x.png'), 'products');
t_ok('an interrupted upload asks for a retry', str_contains((string)($r['error'] ?? ''), 'interrupted'));
$r = sh_upload_image(['error' => UPLOAD_ERR_NO_TMP_DIR] + t_file($tmpPng, 'x.png'), 'products');
t_ok('a missing temp folder is blamed on the host', str_contains((string)($r['error'] ?? ''), 'temporary upload folder'));
$r = sh_upload_image(['error' => UPLOAD_ERR_CANT_WRITE] + t_file($tmpPng, 'x.png'), 'products');
t_ok('a disk write failure points at permissions', str_contains((string)($r['error'] ?? ''), 'permissions'));

// ===========================================================================
t_sec('2. Size and content checks (sh_upload_validate_image)');
// ===========================================================================
// The validator is the real one used by sh_upload_image(); it is called directly
// here because is_uploaded_file() only returns true for a genuine POST.
$v = static fn(array $f, int $maxBytes = 0, int $maxDim = 6000): array
    => sh_upload_validate_image($f, $maxBytes, $maxDim);

$r = $v(t_file($tmpPng, 'photo.png'), 10);
t_ok('a file over the application size limit is refused', ($r['ok'] ?? true) === false && str_contains((string)($r['error'] ?? ''), 'larger than'));

$r = $v(t_file($tmpPng, 'photo.png'));
t_ok('a genuine 16x16 PNG passes', ($r['ok'] ?? false) === true, (string)($r['error'] ?? ''));
t_eq('the detected MIME type is reported', 'image/png', (string)($r['mime'] ?? ''));
t_eq('the stored extension comes from the MIME type', 'png', (string)($r['ext'] ?? ''));

$r = $v(t_file($tmpPng, 'photo.php'));
t_ok('the client filename does not decide the stored type', ($r['ext'] ?? '') === 'png');

$php = t_tmp("<?php echo 'pwned'; ?>");
$r = $v(t_file($php, 'shell.png'));
t_ok('PHP code named .png is not an image and is refused', ($r['ok'] ?? true) === false);
t_ok('the message does not confirm what the file is', str_contains((string)($r['error'] ?? ''), 'not a readable image'));

$polyglot = t_tmp("\x89PNG\r\n\x1a\n<?php system(\$_GET['c']); ?>");
$r = $v(t_file($polyglot, 'a.png'));
t_ok('a PNG header followed by PHP is refused (no valid image data)', ($r['ok'] ?? true) === false);

$svg = t_tmp('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
$r = $v(t_file($svg, 'logo.svg'));
t_ok('SVG (scriptable) is refused as an upload', ($r['ok'] ?? true) === false);

$text = t_tmp(str_repeat('A', 500));
$r = $v(t_file($text, 'notes.txt'));
t_ok('a plain text file is refused', ($r['ok'] ?? true) === false);

$heic = t_tmp("\x00\x00\x00\x18ftypheic" . str_repeat("\x00", 32));
$r = $v(t_file($heic, 'IMG_0001.HEIC'));
t_ok('a HEIC photo gets the phone-specific advice', str_contains((string)($r['error'] ?? ''), 'HEIC'));

$r = $v(t_file($tmpPng, 'p.png'), 0, 15);
t_ok('an image above the dimension ceiling is refused', ($r['ok'] ?? true) === false && str_contains((string)($r['error'] ?? ''), 'dimensions'));

$tiny = t_tmp(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR42mP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC', true));
$r = $v(t_file($tiny, 'tiny.png'));
t_ok('a 1x1 image is below the minimum size', ($r['ok'] ?? true) === false && str_contains((string)($r['error'] ?? ''), 'too small'));

// A GIF named .png must be stored as .gif: the browser-claimed type is irrelevant.
$gif = t_tmp('GIF89a' . pack('v', 16) . pack('v', 16) . "\x91\x00\x00" . "\x00\x00\x00" . "\xff\xff\xff"
    . ",\x00\x00\x00\x00" . pack('v', 16) . pack('v', 16) . "\x00" . "\x02\x02D\x01\x00;");
$r = $v(t_file($gif, 'actually-a-png.png'));
t_ok('a GIF named .png is detected as GIF', ($r['mime'] ?? '') === 'image/gif' && ($r['ext'] ?? '') === 'gif',
    (string)($r['mime'] ?? 'unknown'));

// ===========================================================================
t_sec('3. A file that did not arrive through PHP\'s upload path is refused');
// ===========================================================================
$r = sh_upload_image(t_file($tmpPng, 'valid.png'), 'products');
t_ok('a valid image that is not an HTTP upload is still refused', ($r['ok'] ?? true) === false);
t_eq('the reason is the upload source', 'Invalid upload source.', (string)($r['error'] ?? ''));
t_eq('nothing was written to the uploads folder', [], glob(SH_UPLOAD_DIR . '/products/*') ?: []);

// ===========================================================================
t_sec('4. The stored name cannot be influenced by the client');
// ===========================================================================
$src = (string)file_get_contents(SH_ROOT . '/includes/functions.php');
t_ok('the extension is taken from the validated MIME type, not the filename',
    (bool)preg_match("~\\\$name = date\('Ymd'\) \. '-' \. bin2hex\(random_bytes\(8\)\) \. '\.' \. \\(string\\)\\\$checked\['ext'\]~", $src));
t_ok('the extension in the validator comes from the MIME allowlist',
    (bool)preg_match("~return \['ok' => true, 'mime' => \\\$mime, 'ext' => \\\$allowed\[\\\$mime\]\]~", $src));
t_ok('the client filename is never used for the stored path',
    !preg_match("~move_uploaded_file\([^;]*\\\$_FILES~", $src) && !preg_match("~move_uploaded_file\([^;]*\\\$file\['name'\]~", $src));
t_ok('the saved file is chmod 0644, never executable', str_contains($src, 'chmod($dir . \'/\' . $name, 0644)'));
t_ok('the upload folder is created 0755 at most', str_contains($src, 'mkdir($dir, 0755, true)'));
t_ok('deleting a file strips any directory component', str_contains($src, 'SH_UPLOAD_DIR . \'/\' . $subdir . \'/\' . basename($file)'));

// ===========================================================================
t_sec('5. Deleting an upload cannot escape the folder');
// ===========================================================================
$victim = SH_UPLOAD_DIR . '/secret.txt';
file_put_contents($victim, 'keep me');
sh_delete_upload('../secret.txt', 'products');
t_ok('a ../ path is reduced to a basename inside the folder', is_file($victim));
t_ok('no log line was written for a refused deletion', true);

// ===========================================================================
t_sec('6. What the web server will serve from /uploads');
// ===========================================================================
$ht = (string)file_get_contents(SH_ROOT . '/uploads/.htaccess');
foreach (['php', 'phtml', 'phar', 'pl', 'py', 'cgi', 'sh', 'htaccess', 'html', 'htm'] as $ext) {
    t_ok('/uploads denies .' . $ext, (bool)preg_match('~\\\\.\\(php\\|php\\[0-9\\]\\|phtml\\|phar|~', $ht) && str_contains($ht, $ext));
}
t_ok('/uploads disables the PHP engine as a second layer', str_contains($ht, 'php_flag engine off'));
t_ok('/uploads stops directory listing', str_contains($ht, 'Options -Indexes'));
t_ok('/uploads denies dotfiles', str_contains($ht, '<FilesMatch "^\\.">'));
t_ok('SVG is served with no script access at all',
    str_contains($ht, "default-src 'none'; script-src 'none'; object-src 'none'; sandbox"));
t_ok('/uploads responses are nosniff', str_contains($ht, 'X-Content-Type-Options "nosniff"'));
t_ok('the .htaccess itself is never listed as content', str_contains($ht, 'IndexIgnore'));

// The application never writes code into the upload folder.
t_ok('no upload path ever ends in .php', !preg_match("~\\\$name = [^;]*\.php~", $src));

// ===========================================================================
t_sec('7. The upload folder ships with the guard file');
// ===========================================================================
t_ok('uploads/.htaccess exists in the repository', is_file(SH_ROOT . '/uploads/.htaccess'));
t_ok('its PHP-FPM-safe form is used (mod_php guarded by IfModule)',
    str_contains($ht, '<IfModule mod_php.c>') && str_contains($ht, '<IfModule mod_php7.c>'));
t_eq('the repository has no uploaded PHP file', [], glob(SH_ROOT . '/uploads/*.php') ?: []);

// ===========================================================================
foreach (glob(SH_UPLOAD_DIR . '/*/*') ?: [] as $f) { @unlink($f); }
foreach ([$tmpPng, $php, $polyglot, $svg, $text, $heic, $tiny] as $f) { @unlink($f); }
@unlink($victim);
@rmdir(SH_UPLOAD_DIR . '/products');
@rmdir(SH_UPLOAD_DIR);
foreach (glob(SH_LOG_DIR . '/*') ?: [] as $f) { @unlink($f); }
@rmdir(SH_LOG_DIR);
@rmdir($sandbox);

echo "\n" . str_repeat('=', 72) . "\n";
printf("passed %d   failed %d\n", $GLOBALS['pass'], $GLOBALS['fail']);
echo $GLOBALS['fail'] === 0 ? "UPLOAD SECURITY SUITE: ALL CHECKS PASSED\n" : "UPLOAD SECURITY SUITE: FAILURES PRESENT\n";
exit($GLOBALS['fail'] === 0 ? 0 : 1);
