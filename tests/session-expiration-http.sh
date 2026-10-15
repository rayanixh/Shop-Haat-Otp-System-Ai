#!/usr/bin/env bash
# Exercise the customer session-expiry routes over real HTTP against the
# disposable CI MySQL database seeded by reporting-integration.php.
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PORT="${SHOPHAAT_TEST_PORT:-8099}"
BASE="http://127.0.0.1:${PORT}"
COOKIE_JAR="$(mktemp)"
SERVER_LOG="$(mktemp)"
TMP_DIR="$(mktemp -d)"
SERVER_PID=""
MOBILE_UA='Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1'
DESKTOP_UA='Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36'

cleanup() {
  [[ -n "$SERVER_PID" ]] && kill "$SERVER_PID" 2>/dev/null || true
  rm -f "$COOKIE_JAR" "$SERVER_LOG"
  rm -rf "$TMP_DIR"
}
trap cleanup EXIT

fail() { echo "Session HTTP integration failed: $*" >&2; exit 1; }
assert_contains() {
  local haystack="$1" needle="$2" message="$3"
  [[ "$haystack" == *"$needle"* ]] || fail "$message"
}

php -S "127.0.0.1:${PORT}" router.php >"$SERVER_LOG" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 30); do
  if curl -sS "${BASE}/login.php" >/dev/null 2>&1; then break; fi
  sleep 0.25
done
curl -sS "${BASE}/login.php" >/dev/null 2>&1 || { cat "$SERVER_LOG" >&2; fail 'PHP test server did not start'; }

csrf_from_html() {
  sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' | head -n 1
}

session_id() {
  awk '$6 == "SHSESSID" { sid = $7 } END { print sid }' "$COOKIE_JAR"
}

mutate_session() {
  local mode="$1" sid
  sid="$(session_id)"
  [[ -n "$sid" ]] || fail 'No ShopHaat session cookie was issued'
  SH_TEST_SESSION_ID="$sid" SH_TEST_MODE="$mode" php -r '
    session_name("SHSESSID");
    session_id((string)getenv("SH_TEST_SESSION_ID"));
    session_start();
    if (getenv("SH_TEST_MODE") === "expire") {
      if (empty($_SESSION["user_id"])) { fwrite(STDERR, "No authenticated user in test session\n"); exit(2); }
      $_SESSION["user_login_at"] = time() - 86500;
      session_write_close();
      exit(0);
    }
    if (getenv("SH_TEST_MODE") === "purge") {
      $_SESSION = [];
      session_destroy();
      exit(0);
    }
    fwrite(STDERR, "Unknown test session mutation\n");
    exit(2);
  '
}

login_customer() {
  local page token headers
  page="$(curl -sS -A "$DESKTOP_UA" -c "$COOKIE_JAR" -b "$COOKIE_JAR" "${BASE}/login.php")"
  token="$(printf '%s' "$page" | csrf_from_html)"
  [[ -n "$token" ]] || fail 'Login page did not provide a CSRF token'
  headers="$TMP_DIR/login-headers"
  curl -sS -A "$DESKTOP_UA" -D "$headers" -o /dev/null -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    -X POST --data-urlencode "csrf_token=${token}" \
    --data-urlencode 'email=reporting-customer@example.test' \
    --data-urlencode 'password=not-used-by-test' \
    --data-urlencode 'redirect=account.php' \
    "${BASE}/login.php"
  grep -Eqi '^Location: .*/?account\.php' "$headers" || { cat "$headers" >&2; fail 'Customer login did not redirect to Account'; }
}

request_expect_login_redirect() {
  local path="$1" ua="$2" headers="$TMP_DIR/headers" body="$TMP_DIR/body"
  curl -sS -A "$ua" -D "$headers" -o "$body" -c "$COOKIE_JAR" -b "$COOKIE_JAR" "${BASE}${path}"
  grep -Eq '^HTTP/[0-9.]+ 302' "$headers" || { cat "$headers" >&2; cat "$body" >&2; fail "${path} did not redirect after expiry"; }
  grep -Eqi '^Location: .*/?login\.php\?redirect=' "$headers" || { cat "$headers" >&2; fail "${path} did not target Login"; }
  ! grep -qi '419' "$body" || fail "${path} exposed an Error 419 body"
  awk 'BEGIN { IGNORECASE=1 } /^Location:/ { sub(/\r$/, ""); print $2; exit }' "$headers"
}

open_login_notice() {
  local location="$1" page
  [[ -n "$location" ]] || fail 'Missing Login redirect location'
  if [[ "$location" != http* ]]; then location="${BASE}${location}"; fi
  page="$(curl -sS -A "$MOBILE_UA" -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$location")"
  assert_contains "$page" 'Your session has expired. Please log in again to continue.' 'Login page did not show the session-expired notice'
}

# A fresh logged-in customer can open Account, then add an item that must remain
# available after session recovery and a return to Checkout.
login_customer
account_html="$(curl -sS -A "$MOBILE_UA" -c "$COOKIE_JAR" -b "$COOKIE_JAR" "${BASE}/account.php")"
assert_contains "$account_html" 'Profile Information' 'Authenticated Account page did not render'
csrf="$(printf '%s' "$account_html" | csrf_from_html)"
[[ -n "$csrf" ]] || fail 'Account page did not provide a CSRF token'
cart_json="$(curl -sS -A "$DESKTOP_UA" -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
  -H "X-CSRF-Token: ${csrf}" -F "csrf_token=${csrf}" -F 'action=add' -F 'product_id=1' -F 'quantity=1' \
  "${BASE}/api/cart.php")"
assert_contains "$cart_json" '"success":true' 'Could not create a disposable cart item for session recovery'

# 1. Mobile Account route after automatic logout -> normal Login + message.
mutate_session expire
location="$(request_expect_login_redirect '/account.php' "$MOBILE_UA")"
open_login_notice "$location"

# 4/5. Login again and resume Checkout with the persisted cart item.
login_customer
checkout_headers="$TMP_DIR/checkout-headers"
checkout_body="$TMP_DIR/checkout-body"
curl -sS -A "$DESKTOP_UA" -D "$checkout_headers" -o "$checkout_body" -c "$COOKIE_JAR" -b "$COOKIE_JAR" "${BASE}/checkout.php"
grep -Eq '^HTTP/[0-9.]+ 200' "$checkout_headers" || { cat "$checkout_headers" >&2; cat "$checkout_body" >&2; fail 'Checkout did not resume after login'; }
assert_contains "$(cat "$checkout_body")" 'Checkout' 'Resumed Checkout page did not render'
checkout_csrf="$(cat "$checkout_body" | csrf_from_html)"
[[ -n "$checkout_csrf" ]] || fail 'Checkout did not provide a CSRF token'

# 3. A state-changing AJAX checkout request after expiry receives structured
# authentication JSON rather than an HTML/session error page.
mutate_session expire
api_body="$TMP_DIR/api-expired.json"
api_code="$(curl -sS -A "$DESKTOP_UA" -o "$api_body" -w '%{http_code}' -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
  -H "X-Requested-With: XMLHttpRequest" -H "X-CSRF-Token: ${checkout_csrf}" \
  -F "csrf_token=${checkout_csrf}" -F 'delivery_zone=inside' "${BASE}/api/checkout.php")"
[[ "$api_code" == '401' ]] || { cat "$api_body" >&2; fail "Expired checkout AJAX returned HTTP ${api_code}, expected 401"; }
api_json="$(cat "$api_body")"
assert_contains "$api_json" '"auth_required":true' 'Expired checkout AJAX omitted auth_required'
assert_contains "$api_json" '"session_expired":true' 'Expired checkout AJAX omitted session_expired'
assert_contains "$api_json" '"login_url"' 'Expired checkout AJAX omitted safe login_url'
! grep -qi '<html\|419' "$api_body" || fail 'Expired checkout AJAX returned an HTML/error page'

# 2. My Orders after an automatic logout follows the same Login recovery flow.
login_customer
mutate_session expire
location="$(request_expect_login_redirect '/orders.php' "$DESKTOP_UA")"
open_login_notice "$location"

# Customer order detail is no longer shown as an unauthorised/404 page when the
# known owner session just expired.
login_customer
mutate_session expire
location="$(request_expect_login_redirect '/order-details.php?id=1' "$MOBILE_UA")"
open_login_notice "$location"

# Payment submission is protected in the same order: auth is checked before
# CSRF, so an expired token/session produces the same JSON recovery contract.
login_customer
payment_page="$(curl -sS -A "$DESKTOP_UA" -c "$COOKIE_JAR" -b "$COOKIE_JAR" "${BASE}/account.php")"
payment_csrf="$(printf '%s' "$payment_page" | csrf_from_html)"
mutate_session expire
payment_body="$TMP_DIR/payment-expired.json"
payment_code="$(curl -sS -A "$DESKTOP_UA" -o "$payment_body" -w '%{http_code}' -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
  -H "X-Requested-With: XMLHttpRequest" -H "X-CSRF-Token: ${payment_csrf}" \
  -F "csrf_token=${payment_csrf}" -F 'action=submit' -F 'order_id=1' -F 'transaction_id=SESSIONTEST' -F 'sender_phone=01700000001' \
  "${BASE}/api/payment.php")"
[[ "$payment_code" == '401' ]] || { cat "$payment_body" >&2; fail "Expired payment AJAX returned HTTP ${payment_code}, expected 401"; }
assert_contains "$(cat "$payment_body")" '"session_expired":true' 'Expired payment AJAX omitted session-expired signal'

# 6. Simulate the hosting session store removing the record while an Account
# form is open. The old CSRF token must never execute the POST or render a raw
# error page; it returns cleanly to Login with the expiry notice.
login_customer
stale_account="$(curl -sS -A "$MOBILE_UA" -c "$COOKIE_JAR" -b "$COOKIE_JAR" "${BASE}/account.php")"
stale_csrf="$(printf '%s' "$stale_account" | csrf_from_html)"
[[ -n "$stale_csrf" ]] || fail 'Open Account form did not contain a CSRF token'
mutate_session purge
stale_headers="$TMP_DIR/stale-headers"
stale_body="$TMP_DIR/stale-body"
curl -sS -A "$MOBILE_UA" -D "$stale_headers" -o "$stale_body" -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
  -X POST --data-urlencode "csrf_token=${stale_csrf}" --data-urlencode 'name=Reporting Customer' \
  --data-urlencode 'email=reporting-customer@example.test' --data-urlencode 'phone=01700000001' \
  "${BASE}/account.php"
grep -Eq '^HTTP/[0-9.]+ 302' "$stale_headers" || { cat "$stale_headers" >&2; cat "$stale_body" >&2; fail 'Stale Account POST did not redirect to Login'; }
grep -Eqi '^Location: .*/?login\.php\?redirect=' "$stale_headers" || { cat "$stale_headers" >&2; fail 'Stale Account POST did not point to Login'; }
! grep -qi '419' "$stale_body" || fail 'Stale Account POST exposed Error 419'
stale_location="$(awk 'BEGIN { IGNORECASE=1 } /^Location:/ { sub(/\r$/, ""); print $2; exit }' "$stale_headers")"
open_login_notice "$stale_location"

echo 'Session expiration HTTP integration passed'
