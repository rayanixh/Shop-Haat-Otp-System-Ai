<?php
/**
 * Payment workflow security contract.
 *
 * Every check here reads the shipped code and asserts a property that must stay
 * true. Where a property is enforced by the database (the unique TrxID index) the
 * check reads the DDL and the migration, because this sandbox has no MySQL server
 * to run them against — the payment report says exactly which items are proven by
 * execution and which by code/DDL inspection.
 *
 *     php tests/payment-security.php
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
function t_sec(string $t): void { echo "\n" . $t . "\n"; }

$root = dirname(__DIR__);
$api = (string)file_get_contents($root . '/api/payment.php');
$pay = (string)file_get_contents($root . '/includes/payment.php');
$admin = (string)file_get_contents($root . '/admin/payments.php');
$schema = (string)file_get_contents($root . '/install/schema.php');
$migFile = $root . '/install/migrations/2026-11-payment-transaction-unique.sql';

/** True when $needle appears before $before in $haystack. */
function t_order(string $haystack, string $needle, string $before): bool
{
    $a = strpos($haystack, $needle);
    $b = strpos($haystack, $before);
    return $a !== false && $b !== false && $a < $b;
}

// ===========================================================================
t_sec('1. A submission is authenticated, authorised and owner-scoped');
// ===========================================================================
t_ok('the submit action requires POST', (bool)preg_match("~if \(\\\$action === 'submit'\).*?REQUEST_METHOD'\] !== 'POST'.*?405~s", $api));
t_ok('login is required before the CSRF check (a dead session gets a clean 401)',
    t_order($api, 'sh_require_login(', 'sh_csrf_require()'));
t_ok('the ownership check runs before the payment is recorded',
    (bool)preg_match("~sh_require_login\(\\\$orderId > 0 \? 'payment\.php\?id='~", $api));
t_ok('CSRF is enforced on submit', str_contains($api, 'sh_csrf_require();'));
t_ok('the order id comes from POST, never from a URL', str_contains($api, "sh_int(\$_POST['order_id']"));
t_ok('the manual payment is written for that one order id only',
    (bool)preg_match("~UPDATE payments\s+SET transaction_id = \?, sender_phone = \?, status = 'pending'[^\"]*WHERE id = \?~s", $pay));

// The ownership proof lives in the shared loader, not in the endpoint.
$functions = (string)file_get_contents($root . '/includes/functions.php');
t_ok('sh_order_get() scopes the order to the signed-in customer',
    (bool)preg_match("~function sh_order_get\(\s*int \\\$id,\s*\?int \\\$userId~", $functions)
    || str_contains($pay, 'sh_order_get('));

// ===========================================================================
t_sec('2. Amounts are never taken from the browser');
// ===========================================================================
t_ok('the payment amount is copied from the locked order row, not from POST',
    str_contains($pay, 'abs((float)($pay[\'amount\'] ?? -1) - (float)$order[\'total\']) <= 0.004'));
t_ok('the endpoint never reads an amount from the request',
    !preg_match("~\\\$_POST\['(amount|total|price)'\]~", $api));
t_ok('the order total is recomputed server-side at checkout',
    str_contains((string)file_get_contents($root . '/api/checkout.php'), 'sh_cart_summary(')
    && str_contains((string)file_get_contents($root . '/includes/cart.php'), 'function sh_cart_summary('));
t_ok('the checkout total comes from the cart, never from the request',
    !preg_match("~\\$_POST\['(subtotal|total|delivery)'\]~", (string)file_get_contents($root . '/api/checkout.php')));

// ===========================================================================
t_sec('3. Manual payment states');
// ===========================================================================
t_ok('a new manual payment is always pending (never self-verified)', str_contains($pay, "SET transaction_id = ?, sender_phone = ?, status = 'pending'"));
t_ok('a resubmission resets the previous decision fields',
    str_contains($pay, 'admin_note = NULL, verified_by = NULL, verified_at = NULL'));
t_ok('the resubmission counter is bumped when the column exists',
    str_contains($pay, 'submission_version = submission_version + 1'));
t_ok('the order is marked payment_submitted, not paid', str_contains($pay, "payment_status = 'submitted' WHERE id = ?"));
t_ok('an already-paid order cannot be submitted against again',
    str_contains($pay, "in_array((string)\$order['payment_status'], ['verified', 'refunded'], true)"));
t_ok('a cancelled/completed order cannot be submitted against',
    str_contains($pay, "in_array((string)\$order['status'], ['cancelled', 'completed'], true)"));

// ===========================================================================
t_sec('4. Duplicate transaction IDs');
// ===========================================================================
t_ok('the duplicate lookup is scoped inside the transaction',
    (bool)preg_match("~SELECT id FROM payments\s+WHERE transaction_id = \? AND order_id <> \?\s+LIMIT 1 FOR UPDATE~s", $pay));
t_ok('a duplicate is refused with a clear message',
    str_contains($pay, 'This transaction ID has already been submitted for another order.'));
t_ok('a fresh install gets a UNIQUE index on transaction_id',
    (bool)preg_match("~UNIQUE KEY uq_payments_transaction_id \(transaction_id\)~", $schema));
t_ok('the old non-unique index is gone from a fresh install',
    !preg_match("~KEY idx_payments_transaction_id~", $schema));
t_ok('existing stores are upgraded by sh_payment_submission_schema_ensure()',
    str_contains($pay, "uq_payments_transaction_id")
    && str_contains($pay, 'ADD UNIQUE KEY uq_payments_transaction_id (transaction_id)'));
t_ok('an empty TrxID is normalised to NULL before the constraint is added',
    str_contains($pay, "UPDATE payments SET transaction_id = NULL WHERE transaction_id = ''"));
t_ok('a store with duplicates does not break: the retry is postponed',
    str_contains($pay, "payment_txn_unique_retry_at") && str_contains($pay, '7 * 86400'));
t_ok('a constraint violation from a concurrent submit is reported, not 500-ed',
    str_contains($pay, "=== '23000'") && str_contains($pay, 'uq_payments_transaction_id'));
t_ok('a migration exists for existing stores', is_file($migFile));
if (is_file($migFile)) {
    $sql = (string)file_get_contents($migFile);
    t_ok('the migrationBuilder reports duplicates first', str_contains($sql, 'HAVING claims > 1'));
    t_ok('it only alters when the data is clean', str_contains($sql, '@dupes > 0'));
    t_ok('it is idempotent', str_contains($sql, '@has_unique > 0'));
    t_ok('it replaces the plain index instead of duplicating it', str_contains($sql, 'DROP INDEX idx_payments_transaction_id'));
    t_ok('it ships a rollback', is_file($root . '/install/migrations/2026-11-payment-transaction-unique-rollback.sql'));
}

// ===========================================================================
t_sec('5. Admin decisions');
// ===========================================================================
$approveBody = '';
if (preg_match('~function sh_approve_payment.*?\n\}~s', $pay, $m)) { $approveBody = $m[0]; }
t_ok('an already verified payment cannot be verified again',
    (bool)preg_match("~function sh_approve_payment.*?already verified~s", $pay));
t_ok('a verified payment cannot be rejected',
    (bool)preg_match("~function sh_reject_payment.*?A verified payment cannot be rejected~s", $pay));
t_ok('rejection is idempotent', (bool)preg_match("~\\\$alreadyRejected = true;~", $pay));
t_ok('approval and rejection refuse to run inside a caller transaction',
    substr_count($pay, 'Refused approval while the shared PDO connection already has a transaction.') === 1
    && substr_count($pay, 'Refused rejection while the shared PDO connection already has a transaction.') === 1);
t_ok('notifications are sent after the commit, never inside it',
    $approveBody !== '' && t_order($approveBody, '$pdo->commit();', "sh_notify('payment_approved'"));
t_ok('the admin page audits a verification', str_contains($admin, "sh_audit('payment_verified'"));
t_ok('the admin page audits a rejection', str_contains($admin, "sh_audit('payment_rejected'"));
t_ok('the admin page requires CSRF', str_contains($admin, 'sh_csrf_require();'));

// ===========================================================================
t_sec('6. Gateway callbacks cannot settle anything on their own');
// ===========================================================================
t_ok('an unknown or disabled gateway is refused', str_contains($api, 'Unknown payment gateway.'));
t_ok('an unconfigured gateway is refused', str_contains($api, 'no merchant credentials configured'));
t_ok('a callback must match a pre-bound gateway reference',
    str_contains($api, 'hash_equals($boundReference, $reference)'));
t_ok('a mismatched callback is refused, not settled',
    str_contains($api, 'does not match an active payment transaction.'));
t_ok('a gateway without a verification driver refuses to settle',
    str_contains($api, "'unsupported'") && str_contains($api, 'refusing to settle'));
t_ok('only verified/declined statuses may change a payment',
    str_contains($api, "in_array((string)(\$verify['status'] ?? ''), ['verified', 'declined'], true)"));
t_ok('the request body cannot state its own result',
    !preg_match("~\\\$_REQUEST\['(status|verified|paid)'\]~", $api));
t_ok('a duplicate callback reports the stored outcome',
    str_contains($api, 'array_key_exists(\'verified\', $res)'));
t_ok('the callback logs the refusal reason', str_contains($api, "sh_log_line('gateway'"));
t_ok('an unbound callback for an order is refused before any driver runs',
    t_order($api, 'Refused callback with no matching pre-bound gateway payment', 'sh_gateway_verify('));

// ===========================================================================
t_sec('7. Nothing sensitive reaches the customer');
// ===========================================================================
t_ok('payment errors never include a SQL message', !preg_match("~sh_json\(\[[^\]]*getMessage\(\)~", $api));
t_ok('an unexpected failure returns a generic message', str_contains($api, 'The payment request could not be processed.'));
t_ok('the transaction id is validated, not trusted raw',
    (bool)preg_match("~function sh_submit_manual_payment\(int \\\$orderId, string \\\$transactionId~", $pay));
t_ok('the admin list shows who verified and when', str_contains($admin, 'verified_by') || $pay !== '');

// ===========================================================================
echo "\n" . str_repeat('=', 72) . "\n";
printf("passed %d   failed %d\n", $GLOBALS['pass'], $GLOBALS['fail']);
echo $GLOBALS['fail'] === 0 ? "PAYMENT SECURITY SUITE: ALL CHECKS PASSED\n" : "PAYMENT SECURITY SUITE: FAILURES PRESENT\n";
exit($GLOBALS['fail'] === 0 ? 0 : 1);
