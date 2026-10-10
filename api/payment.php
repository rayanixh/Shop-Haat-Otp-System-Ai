<?php
/**
 * Payment endpoints:
 *  - submit  : customer submits a manual bKash/Nagad/Rocket transaction
 *  - callback: server-side gateway callback / webhook (never trusts the browser)
 */
declare(strict_types=1);
define('SH_JSON_CONTEXT', true);
require_once dirname(__DIR__) . '/config/config.php';

if (sh_db_config() === null || !sh_is_locked()) {
    sh_json(['success' => false, 'error' => 'The store is not installed yet.'], 503);
}
try { sh_db(); } catch (Throwable $e) {
    sh_log_exception($e, 'api-payment-db');
    sh_json(['success' => false, 'error' => 'Service temporarily unavailable.'], 503);
}
require_once SH_ROOT . '/includes/auth.php';
require_once SH_ROOT . '/includes/payment.php';
require_once SH_ROOT . '/includes/notifications.php';

sh_session_start();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    // -----------------------------------------------------------------------
    if ($action === 'submit') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { sh_json(['success' => false, 'error' => 'POST required.'], 405); }
        sh_csrf_require();
        // Payment submission is an order-confirmation step: it requires an
        // authenticated account just like checkout. (The gateway callback below
        // is intentionally server-to-server and does not require a browser session.)
        if (sh_user_id() <= 0) {
            sh_json(['success' => false, 'error' => 'Please login to continue to checkout.', 'auth_required' => true], 401);
        }
        $orderId = sh_int($_POST['order_id'] ?? 0);
        $res = sh_submit_manual_payment($orderId, sh_post('transaction_id'), sh_post('sender_phone'));
        if (!$res['ok']) { sh_json(['success' => false, 'error' => $res['error']]); }
        sh_json([
            'success' => true,
            'message' => 'Payment submitted. Our team will verify it shortly.',
            'redirect' => sh_url('order-success.php?id=' . $orderId),
        ]);
    }

    // -----------------------------------------------------------------------
    // Gateway callback / webhook. Verification is server-side only.
    if ($action === 'callback') {
        $gatewayCode = (string)($_REQUEST['gateway'] ?? '');
        $orderId = sh_int($_REQUEST['order_id'] ?? 0);
        $gw = sh_one('SELECT * FROM payment_gateways WHERE code = ? LIMIT 1', [$gatewayCode]);

        if ($gw === null || (int)$gw['status'] !== 1) {
            sh_log_line('gateway', 'Callback for unknown/disabled gateway: ' . $gatewayCode);
            sh_json(['success' => false, 'error' => 'Unknown payment gateway.'], 400);
        }
        if (!sh_gateway_is_configured($gw)) {
            sh_log_line('gateway', 'Callback for unconfigured gateway: ' . $gatewayCode);
            sh_json(['success' => false, 'error' => 'This gateway has no merchant credentials configured.'], 400);
        }
        $order = $orderId > 0 ? sh_order_get($orderId) : null;
        if ($order === null) { sh_json(['success' => false, 'error' => 'Order not found.'], 404); }

        $reference = trim((string)($_REQUEST['tran_id'] ?? $_REQUEST['reference'] ?? ''));
        if ($reference === '') { sh_json(['success' => false, 'error' => 'Missing transaction reference.'], 400); }

        // A callback is not allowed to choose an order merely by knowing its ID.
        // A real initiator must have persisted the provider's expected reference
        // on the selected gateway payment before redirect/webhook delivery. This
        // codebase deliberately has no initiator yet, so an unbound callback is
        // refused rather than using a valid payment from another order.
        $boundPayment = sh_one('SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$orderId]);
        $boundReference = trim((string)($boundPayment['gateway_reference'] ?? ''));
        if ($boundPayment === null || (string)($boundPayment['kind'] ?? '') !== 'gateway'
            || (int)($boundPayment['gateway_id'] ?? 0) !== (int)$gw['id']
            || $boundReference === '' || !hash_equals($boundReference, $reference)) {
            sh_log_line('gateway', 'Refused callback with no matching pre-bound gateway payment for order ' . $orderId . '.');
            sh_json(['success' => false, 'error' => 'This gateway callback does not match an active payment transaction.'], 400);
        }

        // Every driver MUST verify against the provider's own API before settling.
        $verify = sh_gateway_verify($gw, $order, $_REQUEST, $boundReference);
        if ($verify['status'] === 'unsupported') {
            sh_log_line('gateway', 'No verification driver available for ' . $gw['driver'] . '; refusing to settle.');
            sh_json(['success' => false, 'error' => $verify['error']], 501);
        }
        // A transport/format/reference mismatch is not a provider-authorized
        // rejection. Leave the payment untouched so a forged or incomplete
        // callback cannot turn a live order into payment_rejected.
        if (!in_array((string)($verify['status'] ?? ''), ['verified', 'declined'], true)) {
            sh_log_line('gateway', 'Callback verification remained unconfirmed for order ' . $orderId . '.');
            sh_json(['success' => false, 'verified' => false, 'error' => (string)($verify['error'] ?? 'Gateway verification was not confirmed.')], 400);
        }
        $res = sh_gateway_settle($orderId, (int)$gw['id'], $reference, (array)$_REQUEST, $verify['status'] === 'verified');
        // A duplicate callback reports the already-persisted outcome rather than
        // the potentially contradictory retry payload from the provider.
        $settledVerified = array_key_exists('verified', $res)
            ? (bool)$res['verified']
            : $verify['status'] === 'verified';
        sh_json([
            'success'   => !empty($res['ok']),
            'verified'  => !empty($res['ok']) && $settledVerified,
            'duplicate' => !empty($res['duplicate']),
            'error'     => !empty($res['ok'])
                ? ($settledVerified ? null : ($verify['error'] ?? 'Payment was declined by the gateway.'))
                : (string)($res['error'] ?? 'Gateway settlement could not be recorded safely.'),
        ]);
    }

    sh_json(['success' => false, 'error' => 'Unknown action.'], 400);
} catch (Throwable $e) {
    sh_log_exception($e, 'api-payment');
    sh_json(['success' => false, 'error' => 'The payment request could not be processed.'], 500);
}

/**
 * Server-side verification dispatcher.
 *
 * Honest behaviour: a gateway is only settled as paid when its official
 * validation API confirms the transaction. Drivers whose official API
 * documentation/credentials are not wired up return 'unsupported' rather
 * than pretending the payment succeeded.
 */
function sh_gateway_verify(array $gw, array $order, array $request, string $expectedReference): array
{
    $creds = sh_gateway_credentials($gw);
    $sandbox = $gw['mode'] === 'sandbox';

    switch ($gw['driver']) {
        case 'sslcommerz':
            // Official validation endpoint documented by SSLCommerz.
            $valId = (string)($request['val_id'] ?? '');
            if ($valId === '') { return ['status' => 'unverified', 'error' => 'Missing val_id in callback.']; }
            $base = $sandbox ? 'https://sandbox.sslcommerz.com' : 'https://securepay.sslcommerz.com';
            $url = $base . '/validator/api/validationserverAPI.php?' . http_build_query([
                'val_id'        => $valId,
                'store_id'      => $creds['store_id'] ?? '',
                'store_passwd'  => $creds['store_password'] ?? '',
                'format'        => 'json',
            ]);
            $resp = sh_http_get($url);
            if (!$resp['ok']) { return ['status' => 'unverified', 'error' => 'Validation request failed: ' . $resp['error']]; }
            $j = json_decode($resp['body'], true);
            if (!is_array($j)) { return ['status' => 'unverified', 'error' => 'Invalid validation response.']; }
            $returnedReference = trim((string)($j['tran_id'] ?? ''));
            if ($returnedReference === '' || !hash_equals($expectedReference, $returnedReference)) {
                return ['status' => 'unverified', 'error' => 'Gateway transaction reference did not match the order.'];
            }
            $statusOk = in_array(($j['status'] ?? ''), ['VALID', 'VALIDATED'], true);
            if (!$statusOk) {
                // This is a genuine provider response for the pre-bound
                // transaction, so it may safely move the payment to rejected.
                return ['status' => 'declined', 'error' => 'Gateway reported status: ' . ($j['status'] ?? 'unknown')];
            }
            $currency = strtoupper(trim((string)($j['currency_type'] ?? $j['currency'] ?? '')));
            if ($currency !== '' && $currency !== 'BDT') {
                return ['status' => 'unverified', 'error' => 'Gateway payment currency did not match the order currency.'];
            }
            $amountOk = abs((float)($j['amount'] ?? 0) - (float)$order['total']) < 0.51;
            if (!$amountOk) { return ['status' => 'unverified', 'error' => 'Paid amount did not match the order total.']; }
            return ['status' => 'verified'];

        default:
            return [
                'status' => 'unsupported',
                'error'  => 'No verified server-side driver is implemented for ' . $gw['name']
                    . '. Supply the official API documentation and credentials to enable automatic settlement.',
            ];
    }
}

function sh_http_get(string $url, int $timeout = 20): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) { return ['ok' => false, 'body' => '', 'error' => $err ?: 'Connection failed.']; }
        return ['ok' => $code >= 200 && $code < 300, 'body' => (string)$body, 'error' => $code >= 300 ? 'HTTP ' . $code : ''];
    }
    set_error_handler(static fn(): bool => true);
    try {
        $body = file_get_contents($url, false, stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true]]));
    } finally {
        restore_error_handler();
    }
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $code = (int)$m[1];
    }
    if ($body === false) { return ['ok' => false, 'body' => '', 'error' => 'Connection failed.']; }
    return [
        'ok' => $code >= 200 && $code < 300,
        'body' => (string)$body,
        'error' => $code >= 300 ? 'HTTP ' . $code : ($code === 0 ? 'Validation response status was unavailable.' : ''),
    ];
}
