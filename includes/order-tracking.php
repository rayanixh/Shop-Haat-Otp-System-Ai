<?php
/**
 * Public order-tracking abuse controls.
 *
 * Both the HTML tracker and JSON tracker use these same locked file counters,
 * so switching between endpoints cannot reset the public tracking budget. The
 * limiter keys contain hashes of customer identifiers; raw phone numbers and
 * order references are never used as filenames or written by this helper.
 */
declare(strict_types=1);

/**
 * Return true when the public lookup should be refused.
 *
 * Limits are intentionally applied by source IP, target order reference, and
 * exact normalized lookup pair. All counters are atomic and fail closed if the
 * file-backed storage cannot safely record a request.
 */
function sh_order_tracking_rate_limited(string $orderNumber, string $phone): bool
{
    $ip = function_exists('sh_client_ip') ? sh_client_ip() : (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip === '') { $ip = 'unknown-client'; }

    // Hyphenated legacy references and their accepted normalized form share
    // one target bucket; otherwise separator variants could dilute this limit.
    $orderKey = str_replace('-', '', strtoupper(trim(mb_substr($orderNumber, 0, 40))));
    $phoneKey = function_exists('sh_phone_normalize') ? sh_phone_normalize($phone) : trim($phone);
    $targetKey = hash('sha256', $orderKey);
    $pairKey = hash('sha256', $targetKey . "\0" . hash('sha256', $phoneKey));

    // Shared across track.php and api/orders.php. The target bucket slows
    // distributed phone guessing against a known/legacy sequential reference.
    if (!sh_file_rate_limit('public-order-track-ip', $ip, 40, 600, '', true)) { return true; }
    if (!sh_file_rate_limit('public-order-track-target', $targetKey, 20, 3600, '', true)) { return true; }
    if (!sh_file_rate_limit('public-order-track-pair', $pairKey, 6, 900, '', true)) { return true; }
    return false;
}
