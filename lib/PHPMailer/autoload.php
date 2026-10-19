<?php
/**
 * PHPMailer autoloader.
 *
 * The application prefers a Composer install when the host has one
 * (vendor/autoload.php wins, so an operator who runs `composer require
 * phpmailer/phpmailer` gets managed updates) and otherwise falls back to the
 * copy vendored next to this file. Composer is deliberately not required:
 * shared cPanel hosting frequently has neither Composer nor shell access, and
 * the repository ignores vendor/ so a checkout stays complete.
 *
 * Loading is lazy — registering the autoloader costs nothing, and PHPMailer's
 * source is only read when a message is actually sent.
 */
declare(strict_types=1);

if (!defined('SH_ROOT')) {
    define('SH_ROOT', dirname(__DIR__, 2));
}

if (is_file(SH_ROOT . '/vendor/autoload.php')) {
    require_once SH_ROOT . '/vendor/autoload.php';
}

if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class, false)
    && !in_array('sh_phpmailer_autoload', spl_autoload_functions() ?: [], true)
) {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'PHPMailer\\PHPMailer\\';
        if (!str_starts_with($class, $prefix)) { return; }
        $short = substr($class, strlen($prefix));
        // One class name, no namespace separators, no dots: nothing that could
        // walk out of src/ with a crafted class name.
        if ($short === '' || preg_match('~^[A-Za-z0-9_]+$~', $short) !== 1) { return; }
        $file = __DIR__ . '/src/' . $short . '.php';
        if (is_file($file)) { require_once $file; }
    }, true, true);
}
