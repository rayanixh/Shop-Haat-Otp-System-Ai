#!/usr/bin/env bash
# Email delivery HTTP integration.
#
# Renders the real authentication pages through a real PHP server and walks the
# whole recovery flow the way a customer does:
#
#   1. the public pages render, with no fatal error, warning or notice;
#   2. forgot-password submits to a capture SMTP server over a real SMTP
#      connection, the six-digit code is read out of the delivered message, and
#      reset-password accepts it, rehashes the password, revokes the code and
#      lets the customer sign in with the new password only;
#   3. with mail unconfigured the page says so and never claims a code was sent,
#      and the answer is identical for an address that exists and one that does
#      not;
#   4. the admin email screens render, and the stored SMTP password and webhook
#      secret are never echoed back into HTML.
#
# Nothing here fakes a delivery: the only success in section 2 comes from the
# capture server actually accepting the message. Run against the disposable CI
# database; every row this script creates is removed again from the EXIT trap.
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PORT="${SHOPHAAT_EMAIL_TEST_PORT:-8098}"
SMTP_PORT="${SHOPHAAT_EMAIL_SMTP_PORT:-2599}"
BASE="http://127.0.0.1:${PORT}"
COOKIE_JAR="$(mktemp)"
GUEST_JAR="$(mktemp)"
SERVER_LOG="$(mktemp)"
TMP_DIR="$(mktemp -d)"
SMTP_DIR="$TMP_DIR/smtp"
SERVER_PID=""
SMTP_PID=""
DESKTOP_UA='Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36'
MOBILE_UA='Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1'

CUSTOMER_EMAIL='http-email-test@example.test'
CUSTOMER_OLD_PASSWORD='Old-Smtp-Test-Pass-1!'
CUSTOMER_NEW_PASSWORD='New-Smtp-Test-Pass-2!'
UNVERIFIED_EMAIL='http-email-unverified@example.test'
ADMIN_EMAIL='http-email-admin@example.test'
ADMIN_PASSWORD='Http-Test-Admin-Pass-1!'
SMTP_PASSWORD='SuperSecret-SMTP-Pass-9x!'
WEBHOOK_SECRET='webhook-secret-value-abcdef123456'

cleanup() {
  [[ -n "$SERVER_PID" ]] && kill "$SERVER_PID" 2>/dev/null || true
  [[ -n "$SMTP_PID" ]] && kill "$SMTP_PID" 2>/dev/null || true
  if [[ -f "$TMP_DIR/fixtures.php" ]]; then
    php "$TMP_DIR/fixtures.php" cleanup >/dev/null 2>&1 || true
  fi
  rm -f "$COOKIE_JAR" "$GUEST_JAR" "$SERVER_LOG"
  rm -rf "$TMP_DIR"
}
trap cleanup EXIT

fail() {
  echo "::error title=Email pages failure::Email HTTP integration failed: $*" >&2
  echo "Email HTTP integration failed: $*" >&2
  exit 1
}

# The application log is already scrubbed of credentials by the application
# itself; the tail is what makes a 500 diagnosable without the full step log.
dump_app_log() {
  local label="$1" file=""
  file="$(ls -1t logs/app-*.log 2>/dev/null | head -n 1 || true)"
  if [[ -n "$file" && -f "$file" ]]; then
    echo "::error title=App log tail (${label})::$(tail -c 2000 "$file" | tr '\n' '|' | sed 's/%/%25/g')"
  else
    echo "::warning title=App log (${label})::no application log was found under logs/"
  fi
}

assert_contains() {
  local haystack="$1" needle="$2" message="$3"
  [[ "$haystack" == *"$needle"* ]] || fail "$message"
}

assert_absent() {
  local haystack="$1" needle="$2" message="$3"
  if [[ "$haystack" == *"$needle"* ]]; then fail "$message"; fi
}

# A page must never leak a PHP diagnostic to the visitor: the store runs with
# display_errors off in production, and the same has to hold here.
assert_no_php_diagnostic() {
  local body="$1" label="$2"
  local needle
  for needle in 'Fatal error' 'Uncaught' 'PHP Warning' 'PHP Notice' 'Warning:' 'Notice:' 'Deprecated:'; do
    if [[ "$body" == *"$needle"* ]]; then
      dump_app_log "$label"
      fail "${label} rendered a PHP diagnostic (${needle})"
    fi
  done
}

set_store_setting() {
  local key="$1" value="$2"
  SH_TEST_SET_KEY="$key" SH_TEST_SET_VALUE="$value" php -r '
    $_SERVER["SCRIPT_NAME"] = "/tests/setting.php";
    $_SERVER["HTTP_HOST"] = "localhost";
    $_SERVER["REMOTE_ADDR"] = "127.0.0.1";
    $_SERVER["REQUEST_METHOD"] = "GET";
    require getcwd() . "/config/config.php";
    sh_setting_save((string)getenv("SH_TEST_SET_KEY"), (string)getenv("SH_TEST_SET_VALUE"));
  ' >/dev/null || fail "could not write the setting ${key}"
}

read_setting() {
  SH_TEST_GET_KEY="$1" php -r '
    $_SERVER["SCRIPT_NAME"] = "/tests/setting.php";
    $_SERVER["HTTP_HOST"] = "localhost";
    $_SERVER["REMOTE_ADDR"] = "127.0.0.1";
    require getcwd() . "/config/config.php";
    echo (string)sh_setting((string)getenv("SH_TEST_GET_KEY"), "");
  ' 2>/dev/null
}

csrf_from_html() {
  sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' | head -n 1
}

# ---------------------------------------------------------------------------
# Fixture rows: one customer, one unverified customer and one operator, created
# through the application's own schema helpers and removed again on exit.
# ---------------------------------------------------------------------------
cat > "$TMP_DIR/fixtures.php" <<'FIXTURES'
<?php
declare(strict_types=1);
$_SERVER['SCRIPT_NAME'] = '/tests/fixtures.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
require getcwd() . '/config/config.php';
require getcwd() . '/includes/mail.php';
sh_email_schema_ensure();

$cmd   = (string)($argv[1] ?? '');
// The addresses are fixed here so that cleanup works without arguments: a
// failure mid-section must never leave a fixture row behind.
$customer   = 'http-email-test@example.test';
$unverified = 'http-email-unverified@example.test';
$adminEmail = 'http-email-admin@example.test';
$adminPass  = 'Http-Test-Admin-Pass-1!';
$email = (string)($argv[2] ?? '') !== '' ? (string)$argv[2] : $customer;
$pass  = (string)($argv[3] ?? '');

try {
    if ($cmd === 'setup') {
        sh_query('DELETE FROM users WHERE email IN (?, ?)', [$customer, $unverified]);
        sh_query('DELETE FROM admins WHERE email = ?', [$adminEmail]);
        sh_insert('users', [
            'name' => 'HTTP Email Test', 'email' => $customer, 'phone' => '8801700011111',
            'password_hash' => password_hash($pass, PASSWORD_DEFAULT), 'status' => 'active',
            'email_verified' => 1, 'auth_provider' => 'email',
        ]);
        sh_insert('users', [
            'name' => 'HTTP Unverified', 'email' => $unverified, 'phone' => '8801700022222',
            'password_hash' => password_hash($pass, PASSWORD_DEFAULT), 'status' => 'active',
            'email_verified' => 0, 'auth_provider' => 'email',
        ]);
        sh_insert('admins', [
            'name' => 'HTTP Email Admin', 'email' => $adminEmail,
            'password_hash' => password_hash($adminPass, PASSWORD_DEFAULT),
            'role' => 'superadmin', 'status' => 'active',
        ]);
        echo "created\n";
        exit;
    }

    if ($cmd === 'save-secrets') {
        // Distinctive values, so an echo of either one into HTML is detectable.
        sh_setting_save('smtp_password', $argv[2] ?? '');
        sh_setting_save('email_webhook_secret', $argv[3] ?? '');
        echo "saved\n";
        exit;
    }

    if ($cmd === 'state') {
        $row = sh_one('SELECT status, code_hash, attempts FROM email_challenges
                       WHERE email = ? ORDER BY id DESC LIMIT 1', [$email]);
        $code = (string)($argv[2] ?? '');
        $plain = $code === '' ? 0 : (int)sh_val('SELECT COUNT(*) FROM email_challenges WHERE code_hash = ?', [$code], 0);
        $notified = sh_one("SELECT status, category FROM email_log WHERE purpose = 'password_changed' ORDER BY id DESC LIMIT 1");
        $resetRows = (int)sh_val("SELECT COUNT(*) FROM email_challenges WHERE email = ? AND purpose = 'password_reset' AND status = 'used'", [$email], 0);
        $unverifiedFlag = (int)sh_val('SELECT email_verified FROM users WHERE email = ?', [$unverified], 0);
        $unverifiedCodes = (int)sh_val('SELECT COUNT(*) FROM email_challenges WHERE email = ?', [$unverified], 0);
        echo 'challenge_status=' . (string)($row['status'] ?? 'none') . "\n";
        echo 'challenge_hash_prefix=' . substr((string)($row['code_hash'] ?? ''), 0, 4) . "\n";
        echo 'plaintext_code_rows=' . $plain . "\n";
        echo 'used_reset_rows=' . $resetRows . "\n";
        echo 'notification_status=' . (string)($notified['status'] ?? 'none') . "\n";
        echo 'unverified_flag=' . $unverifiedFlag . "\n";
        echo 'unverified_code_rows=' . $unverifiedCodes . "\n";
        exit;
    }

    if ($cmd === 'cleanup') {
        $emails = [$customer, $unverified];
        sh_query('DELETE FROM email_challenges WHERE email IN (?, ?)', $emails);
        sh_query('DELETE FROM email_log WHERE recipient IN (?, ?)', $emails);
        sh_query('DELETE FROM password_resets WHERE user_id IN (SELECT id FROM users WHERE email IN (?, ?))', $emails);
        sh_query('DELETE FROM users WHERE email IN (?, ?)', $emails);
        sh_query('DELETE FROM admins WHERE email = ?', [$adminEmail]);
        // The two values this run deliberately set to a recognisable string are
        // cleared again: this database is disposable, and a real store must not
        // be left with a test secret in its settings table.
        sh_setting_save('smtp_password', '');
        sh_setting_save('email_webhook_secret', '');
        echo "removed\n";
        exit;
    }

    echo "unknown command\n";
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'fixture error: ' . $e->getMessage() . "\n");
    exit(1);
}
FIXTURES

fixtures() {
  local prepend=()
  if [[ -f "$TMP_DIR/ca-bootstrap.php" ]]; then
    prepend=(-d "auto_prepend_file=$TMP_DIR/ca-bootstrap.php")
  fi
  php "${prepend[@]}" "$TMP_DIR/fixtures.php" "$@" 2>/dev/null || fail "fixture command '${1}' failed"
}

# The pages under test require the email + password authentication mode; the
# original value is restored by the trap below.
original_auth_mode="$(read_setting authentication_mode)"
original_smtp_enabled="$(read_setting smtp_enabled)"
original_from_email="$(read_setting smtp_from_email)"
set_store_setting authentication_mode email_password
set_store_setting email_forgot_enabled 1
set_store_setting email_verify_enabled 1

# The recovery flow must authenticate over a verified TLS channel, exactly like
# production. Generate a throwaway CA and server certificate for the capture
# SMTP server, and make the web server trust only that CA.
SMTP_CA="$TMP_DIR/smtp-ca.pem"
SMTP_CERT="$TMP_DIR/smtp-server.pem"
SMTP_KEY="$TMP_DIR/smtp-server.key"
openssl req -x509 -newkey rsa:2048 -sha256 -nodes -days 2 -keyout "$TMP_DIR/smtp-ca.key" -out "$SMTP_CA" \
  -subj "/CN=ShopHaat HTTP Test CA" -addext "basicConstraints=critical,CA:TRUE" \
  -addext "keyUsage=critical,keyCertSign,cRLSign" >/dev/null 2>&1 \
  || fail 'could not generate the test CA'
openssl req -newkey rsa:2048 -sha256 -nodes -keyout "$SMTP_KEY" -out "$TMP_DIR/smtp.csr" \
  -subj "/CN=127.0.0.1" >/dev/null 2>&1 || fail 'could not generate the test server key'
printf 'subjectAltName=DNS:localhost,IP:127.0.0.1\nextendedKeyUsage=serverAuth\n' >"$TMP_DIR/smtp.ext"
openssl x509 -req -in "$TMP_DIR/smtp.csr" -CA "$SMTP_CA" -CAkey "$TMP_DIR/smtp-ca.key" \
  -CAcreateserial -out "$SMTP_CERT" -days 2 -sha256 -extfile "$TMP_DIR/smtp.ext" >/dev/null 2>&1 \
  || fail 'could not sign the test server certificate'
printf '<?php define("SH_MAIL_CA_BUNDLE", %s);\n' "$(php -r 'echo var_export($argv[1], true);' "$SMTP_CA")" >"$TMP_DIR/ca-bootstrap.php"

php -d "auto_prepend_file=$TMP_DIR/ca-bootstrap.php" -S "127.0.0.1:${PORT}" router.php >"$SERVER_LOG" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 40); do
  if curl -sS "${BASE}/login.php" >/dev/null 2>&1; then break; fi
  sleep 0.25
done
curl -sS "${BASE}/login.php" >/dev/null 2>&1 || { cat "$SERVER_LOG" >&2; fail 'PHP test server did not start'; }

fixtures setup "$CUSTOMER_EMAIL" "$CUSTOMER_OLD_PASSWORD" >/dev/null

# ---------------------------------------------------------------------------
# 1. The public email pages render for a signed-out visitor
# ---------------------------------------------------------------------------
echo 'Email pages render'
for pair in "/verify-email.php:Verify your email" "/forgot-password.php:Forgot Password" "/reset-password.php:Reset Password"; do
  path="${pair%%:*}"; marker="${pair#*:}"
  body="$(curl -sS -A "$DESKTOP_UA" -c "$COOKIE_JAR" -b "$COOKIE_JAR" "${BASE}${path}")" || fail "GET ${path} failed"
  assert_no_php_diagnostic "$body" "GET ${path}"
  assert_contains "$body" "$marker" "GET ${path} did not render its heading"
done
# With nothing pending, the two follow-up pages must say so instead of offering a
# form that cannot work, and the entry page must still carry a real form.
verify_guest="$(curl -sS -A "$MOBILE_UA" "${BASE}/verify-email.php")"
assert_contains "$verify_guest" 'There is no verification in progress for this browser' \
  'verify-email.php does not say honestly that nothing is pending'
assert_absent "$verify_guest" 'A 6-digit code was sent' 'verify-email.php claims a code was sent with no pending verification'
reset_guest="$(curl -sS -A "$MOBILE_UA" "${BASE}/reset-password.php")"
assert_contains "$reset_guest" 'There is no password reset in progress for this browser' \
  'reset-password.php does not say honestly that nothing is pending'
forgot_guest="$(curl -sS -A "$MOBILE_UA" "${BASE}/forgot-password.php")"
assert_contains "$forgot_guest" 'name="csrf_token"' 'the recovery entry page has no CSRF token'
assert_contains "$forgot_guest" 'name="email"' 'the recovery entry page has no email field'

# ---------------------------------------------------------------------------
# 2. Recovery over real HTTP, with the code read out of a real SMTP submission
# ---------------------------------------------------------------------------
echo 'Recovery over real SMTP'
mkdir -p "$SMTP_DIR"
php tests/smtp-tls-server.php "$SMTP_PORT" "$SMTP_CERT" "$SMTP_KEY" normal "$SMTP_DIR" >"$TMP_DIR/smtp.log" 2>&1 &
SMTP_PID=$!
for _ in $(seq 1 40); do
  [[ -f "$SMTP_DIR/ready" ]] && break
  sleep 0.25
done
[[ -f "$SMTP_DIR/ready" ]] || { cat "$TMP_DIR/smtp.log" >&2; fail 'the capture SMTP server did not start'; }

set_store_setting smtp_enabled 1
set_store_setting smtp_host 127.0.0.1
set_store_setting smtp_port "$SMTP_PORT"
set_store_setting smtp_encryption tls
set_store_setting smtp_username 'apiuser'
set_store_setting smtp_password "$SMTP_PASSWORD"
set_store_setting smtp_from_email 'noreply@shophaat.test'
set_store_setting smtp_from_name 'ShopHaat Test'

forgot_page="$(curl -sS -A "$DESKTOP_UA" -c "$COOKIE_JAR" -b "$COOKIE_JAR" "${BASE}/forgot-password.php")"
forgot_token="$(printf '%s' "$forgot_page" | csrf_from_html)"
[[ -n "$forgot_token" ]] || fail 'the forgot-password page did not provide a CSRF token'
forgot_headers="$TMP_DIR/forgot-headers"
curl -sS -A "$DESKTOP_UA" -D "$forgot_headers" -o /dev/null -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
  -X POST --data-urlencode "csrf_token=${forgot_token}" \
  --data-urlencode "email=${CUSTOMER_EMAIL}" \
  "${BASE}/forgot-password.php"
if ! grep -Eqi '^Location: .*/?reset-password\.php' "$forgot_headers"; then
  cat "$forgot_headers" >&2
  echo '--- body ---' >&2
  curl -sS -A "$DESKTOP_UA" -c "$COOKIE_JAR" -b "$COOKIE_JAR" "${BASE}/forgot-password.php" \
    | grep -o 'sh-alert[^<]*<[^>]*>[^<]*' | head -n 5 >&2 || true
  dump_app_log 'forgot-password'
  fail 'forgot-password.php did not hand off to the reset page after a real submission'
fi
# The code must have gone out over an authenticated SMTP session that started
# only after the TLS handshake completed.
smtp_conversation=''
for _ in $(seq 1 30); do
  smtp_conversation="$(cat "$SMTP_DIR"/conversation-*.txt 2>/dev/null || true)"
  if [[ "$smtp_conversation" == *'[tls] C: AUTH LOGIN'* ]]; then break; fi
  sleep 0.1
done
assert_contains "$smtp_conversation" '[tls] C: AUTH LOGIN' \
  'the recovery code was not submitted over authenticated, encrypted SMTP'
assert_absent "$smtp_conversation" 'AUTH before TLS' \
  'authentication was attempted before the TLS handshake'

# The code only exists in the delivered message: read it from the captured SMTP
# submission and prove the database itself never holds it in the clear.
code="$(SH_SMTP_DIR="$SMTP_DIR" php -r '
    $file = getenv("SH_SMTP_DIR") . "/message.txt";
    $raw = is_file($file) ? (string)file_get_contents($file) : "";
    $blocks = preg_split("~^--.*$~m", $raw) ?: [];
    foreach ($blocks as $block) {
        if (stripos($block, "Content-Type: text/plain") === false) { continue; }
        $chunks = explode("\n\n", $block, 2);
        $decoded = base64_decode((string)preg_replace("~\s+~", "", $chunks[1] ?? ""), true);
        if (is_string($decoded) && preg_match("~\bCode:\s*([0-9]{6})\b~", $decoded, $m) === 1) { echo $m[1]; return; }
    }
    echo "";
  ' 2>/dev/null || true)"
[[ "$code" =~ ^[0-9]{6}$ ]] || { cat "$TMP_DIR/smtp.log" >&2; dump_app_log 'smtp capture'; fail "no six-digit code arrived by SMTP (captured: ${code:-none})"; }

state="$(fixtures state "$CUSTOMER_EMAIL" "$code")"
assert_contains "$state" 'plaintext_code_rows=0' 'the verification code was stored in the clear'
assert_contains "$state" 'challenge_hash_prefix=$2y' 'the stored code is not a password_hash() value'

reset_page="$(curl -sS -A "$DESKTOP_UA" -c "$COOKIE_JAR" -b "$COOKIE_JAR" "${BASE}/reset-password.php")"
assert_no_php_diagnostic "$reset_page" 'GET /reset-password.php (pending)'
assert_contains "$reset_page" 'Reset code' 'the reset page did not ask for the emailed code'
reset_token="$(printf '%s' "$reset_page" | csrf_from_html)"
[[ -n "$reset_token" ]] || fail 'the reset page did not provide a CSRF token'

# A wrong code must be refused before the new-password form is reachable.
wrong_code="$(printf '%06d' "$(( (10#$code + 1) % 1000000 ))")"
curl -sS -A "$DESKTOP_UA" -o "$TMP_DIR/wrong-body" -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
  -X POST --data-urlencode "csrf_token=${reset_token}" \
  --data-urlencode 'action=code' --data-urlencode "code=${wrong_code}" \
  "${BASE}/reset-password.php"
assert_contains "$(cat "$TMP_DIR/wrong-body")" 'not correct' 'an incorrect reset code produced no honest error'
if grep -q 'name="password2"' "$TMP_DIR/wrong-body"; then fail 'the new-password form was offered before the code was verified'; fi

curl -sS -A "$DESKTOP_UA" -D "$TMP_DIR/code-headers" -o /dev/null -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
  -X POST --data-urlencode "csrf_token=${reset_token}" \
  --data-urlencode 'action=code' --data-urlencode "code=${code}" \
  "${BASE}/reset-password.php"
grep -Eqi '^Location: .*/?reset-password\.php' "$TMP_DIR/code-headers" || fail 'the correct code was not accepted'

password_page="$(curl -sS -A "$DESKTOP_UA" -c "$COOKIE_JAR" -b "$COOKIE_JAR" "${BASE}/reset-password.php")"
assert_contains "$password_page" 'name="password2"' 'the new-password form did not appear after the code was verified'
password_token="$(printf '%s' "$password_page" | csrf_from_html)"

curl -sS -A "$DESKTOP_UA" -D "$TMP_DIR/password-headers" -o /dev/null -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
  -X POST --data-urlencode "csrf_token=${password_token}" \
  --data-urlencode 'action=password' \
  --data-urlencode "password=${CUSTOMER_NEW_PASSWORD}" \
  --data-urlencode "password2=${CUSTOMER_NEW_PASSWORD}" \
  "${BASE}/reset-password.php"
grep -Eqi '^Location: .*/?login\.php' "$TMP_DIR/password-headers" \
  || { cat "$TMP_DIR/password-headers" >&2; fail 'a completed reset did not redirect to the sign-in page'; }

# The notification after the change went through the same real transport.
state="$(fixtures state "$CUSTOMER_EMAIL")"
assert_contains "$state" 'challenge_status=used' 'the used reset code was not stamped as used'
assert_contains "$state" 'used_reset_rows=1' 'the reset challenge was not marked used'
assert_contains "$state" 'notification_status=accepted' 'the password-change notification was not accepted by SMTP'

# Only the new password works; the old one is refused, and the reset code is dead.
login_after_reset() {
  local password="$1" jar="$2" label="$3"
  local body token headers
  body="$(curl -sS -A "$DESKTOP_UA" -c "$jar" -b "$jar" "${BASE}/login.php")"
  token="$(printf '%s' "$body" | csrf_from_html)"
  [[ -n "$token" ]] || fail "the sign-in page gave no CSRF token for ${label}"
  headers="$TMP_DIR/login-${label}-headers"
  curl -sS -A "$DESKTOP_UA" -D "$headers" -o "$TMP_DIR/login-${label}-body" -c "$jar" -b "$jar" \
    -X POST --data-urlencode "csrf_token=${token}" \
    --data-urlencode "email=${CUSTOMER_EMAIL}" \
    --data-urlencode "password=${password}" \
    --data-urlencode 'redirect=' \
    "${BASE}/login.php"
  if grep -Eqi '^Location: .*/?account\.php' "$headers"; then echo 'signed-in'; else echo 'refused'; fi
}

login_new_jar="$(mktemp)"
login_old_jar="$(mktemp)"
[[ "$(login_after_reset "$CUSTOMER_NEW_PASSWORD" "$login_new_jar" 'new')" == 'signed-in' ]] \
  || fail 'the customer could not sign in with the password set through the recovery flow'
[[ "$(login_after_reset "$CUSTOMER_OLD_PASSWORD" "$login_old_jar" 'old')" == 'refused' ]] \
  || fail 'the old password still signs the customer in after a reset'
rm -f "$login_new_jar" "$login_old_jar"

# The code is single use: submitting it again must not open the password form.
reuse_jar="$(mktemp)"
curl -sS -A "$DESKTOP_UA" -c "$reuse_jar" -b "$reuse_jar" "${BASE}/forgot-password.php" >"$TMP_DIR/reuse-forgot"
reuse_token="$(cat "$TMP_DIR/reuse-forgot" | csrf_from_html)"
curl -sS -A "$DESKTOP_UA" -D "$TMP_DIR/reuse-headers" -o /dev/null -c "$reuse_jar" -b "$reuse_jar" \
  -X POST --data-urlencode "csrf_token=${reuse_token}" \
  --data-urlencode "email=${CUSTOMER_EMAIL}" \
  "${BASE}/forgot-password.php"
reuse_page="$(curl -sS -A "$DESKTOP_UA" -L --max-redirs 2 -c "$reuse_jar" -b "$reuse_jar" "${BASE}/reset-password.php")"
reuse_page_token="$(printf '%s' "$reuse_page" | csrf_from_html)"
curl -sS -A "$DESKTOP_UA" -o "$TMP_DIR/reuse-body" -c "$reuse_jar" -b "$reuse_jar" \
  -X POST --data-urlencode "csrf_token=${reuse_page_token}" \
  --data-urlencode 'action=code' --data-urlencode "code=${code}" \
  "${BASE}/reset-password.php"
if grep -q 'name="password2"' "$TMP_DIR/reuse-body"; then fail 'a used reset code was accepted a second time'; fi
echo "::notice title=Recovery flow::code=emailed,stored-as-hash | wrong-code=refused | reuse=refused | new password only | notification=accepted"

# ---------------------------------------------------------------------------
# 3. No mail configured: honest wording, and no way to tell who has an account
# ---------------------------------------------------------------------------
echo 'Unconfigured mail is reported honestly'
set_store_setting smtp_enabled 0
set_store_setting smtp_from_email ''

unknown_jar="$(mktemp)"
unknown_page="$(curl -sS -A "$DESKTOP_UA" -c "$unknown_jar" -b "$unknown_jar" "${BASE}/forgot-password.php")"
unknown_token="$(printf '%s' "$unknown_page" | csrf_from_html)"
curl -sS -A "$DESKTOP_UA" -o "$TMP_DIR/unknown-body" -c "$unknown_jar" -b "$unknown_jar" \
  -X POST --data-urlencode "csrf_token=${unknown_token}" \
  --data-urlencode 'email=definitely-not-a-customer@example.test' \
  "${BASE}/forgot-password.php"

known_jar="$(mktemp)"
known_page="$(curl -sS -A "$DESKTOP_UA" -c "$known_jar" -b "$known_jar" "${BASE}/forgot-password.php")"
known_token="$(printf '%s' "$known_page" | csrf_from_html)"
curl -sS -A "$DESKTOP_UA" -o "$TMP_DIR/known-body" -c "$known_jar" -b "$known_jar" \
  -X POST --data-urlencode "csrf_token=${known_token}" \
  --data-urlencode "email=${CUSTOMER_EMAIL}" \
  "${BASE}/forgot-password.php"

unknown_text="$(tr -d '\n' < "$TMP_DIR/unknown-body")"
known_text="$(tr -d '\n' < "$TMP_DIR/known-body")"
assert_contains "$known_text" 'Password recovery by email is not available yet' 'an unconfigured store did not explain that it cannot send mail'
assert_absent "$known_text" 'a reset code has been sent' 'an unconfigured store claimed a reset code was sent'
assert_absent "$known_text" 'No code is pending' 'the unconfigured reply leaks internal detail'

# The notice is the only part of the page that could betray whether the address
# has an account, so it is compared word for word. The surrounding markup is
# session-specific (CSRF token, one-shot transition marker, the address the
# visitor typed) and is not part of the answer.
notice_of() {
  grep -o 'Password recovery by email is not available yet[^<]*' "$1" | head -n 1 | sed 's/[[:space:]]\+/ /g'
}
known_notice="$(notice_of "$TMP_DIR/known-body")"
unknown_notice="$(notice_of "$TMP_DIR/unknown-body")"
[[ -n "$known_notice" && -n "$unknown_notice" ]] || fail 'the unconfigured store did not answer the recovery form at all'
if [[ "$known_notice" != "$unknown_notice" ]]; then
  echo "known:   $known_notice" >&2
  echo "unknown: $unknown_notice" >&2
  fail 'the reply differs for an address that exists and one that does not'
fi
for pair in "known:$known_text" "unknown:$unknown_text"; do
  label="${pair%%:*}"; text="${pair#*:}"
  assert_absent "$text" 'If an account exists for that email' "the ${label} address produced the copy used when a code really was sent"
done
# The unverified fixture was never touched by any of this.
state="$(fixtures state "$CUSTOMER_EMAIL")"
assert_contains "$state" 'unverified_flag=0' 'an unverified account was marked verified without a code'
assert_contains "$state" 'unverified_code_rows=0' 'a code was issued for an address nobody requested'
echo "::notice title=Unconfigured mail::known=identical to unknown | claim=never | code=pending none"

# ---------------------------------------------------------------------------
# 4. Admin email screens: they render, and they never echo a stored secret
# ---------------------------------------------------------------------------
echo 'Admin email screens'
fixtures save-secrets "$SMTP_PASSWORD" "$WEBHOOK_SECRET" >/dev/null

admin_jar="$(mktemp)"
admin_login_page="$(curl -sS -A "$DESKTOP_UA" -c "$admin_jar" -b "$admin_jar" "${BASE}/admin/login.php")"
admin_token="$(printf '%s' "$admin_login_page" | csrf_from_html)"
[[ -n "$admin_token" ]] || fail 'the admin sign-in page did not provide a CSRF token'
curl -sS -A "$DESKTOP_UA" -D "$TMP_DIR/admin-headers" -o /dev/null -c "$admin_jar" -b "$admin_jar" \
  -X POST --data-urlencode "csrf_token=${admin_token}" \
  --data-urlencode "email=${ADMIN_EMAIL}" \
  --data-urlencode "password=${ADMIN_PASSWORD}" \
  --data-urlencode 'redirect=' \
  "${BASE}/admin/login.php"
grep -Eqi '^Location: .*/?admin/dashboard\.php' "$TMP_DIR/admin-headers" \
  || { cat "$TMP_DIR/admin-headers" >&2; fail 'the admin sign-in for the email screens did not succeed'; }

for page in '/admin/email.php' '/admin/email-log.php'; do
  body="$(curl -sS -A "$DESKTOP_UA" -c "$admin_jar" -b "$admin_jar" "${BASE}${page}")"
  assert_no_php_diagnostic "$body" "GET ${page}"
  assert_contains "$body" 'sh-admin-sidebar' "GET ${page} did not render the admin navigation"
  assert_absent "$body" "$SMTP_PASSWORD" "GET ${page} echoed the stored SMTP password"
done
email_page="$(curl -sS -A "$DESKTOP_UA" -c "$admin_jar" -b "$admin_jar" "${BASE}/admin/email.php")"
assert_contains "$email_page" 'type="password"' 'the SMTP password field is not a masked input'
assert_contains "$email_page" 'password stored' 'the screen does not say whether a password is stored'
log_page="$(curl -sS -A "$DESKTOP_UA" -c "$admin_jar" -b "$admin_jar" "${BASE}/admin/email-log.php")"
assert_contains "$log_page" 'email-events.php' 'the delivery log does not show the provider webhook URL'
assert_contains "$log_page" 'DMARC' 'the delivery log does not show the DNS records to publish'

# Signed out, the same screen must not expose any of it.
guest_log="$(curl -sS -A "$DESKTOP_UA" -L --max-redirs 2 -c "$GUEST_JAR" -b "$GUEST_JAR" "${BASE}/admin/email-log.php")"
assert_absent "$guest_log" 'DMARC' 'the delivery log rendered for a signed-out visitor'
assert_absent "$guest_log" "$WEBHOOK_SECRET" 'the delivery log leaked the webhook secret to a signed-out visitor'
echo "::notice title=Admin email screens::rendered=email.php,email-log.php | secrets echoed=none | signed-out=blocked"

# Restore the settings this test changed.
set_store_setting smtp_enabled "${original_smtp_enabled:-0}"
set_store_setting smtp_from_email "${original_from_email:-}"
if [[ -n "$original_auth_mode" ]]; then set_store_setting authentication_mode "$original_auth_mode"; fi
fixtures cleanup >/dev/null

echo 'Email delivery HTTP integration passed'
