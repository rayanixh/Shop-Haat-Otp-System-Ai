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

fail() {
  # ::error:: is picked up by the runner and exposed through the annotations
  # API, so a failure is diagnosable without downloading the full step log.
  echo "::error title=Integration failure::Session HTTP integration failed: $*" >&2
  echo "Session HTTP integration failed: $*" >&2
  exit 1
}

# Surface the application log through an annotation: the raw exception is what
# makes a 500 diagnosable, and the log is already scrubbed of credentials by the
# application itself. Newlines are folded so the workflow command stays on one
# line, and % is escaped because the runner treats it as an escape character.
dump_app_log() {
  local label="$1" file=""
  file="$(ls -1t logs/app-*.log 2>/dev/null | head -n 1 || true)"
  if [[ -n "$file" && -f "$file" ]]; then
    echo "::error title=App log tail (${label})::$(tail -c 2000 "$file" | tr '\n' '|' | sed 's/%/%25/g')"
  else
    echo "::warning title=App log (${label})::no application log was found under logs/"
  fi
}

# Parse an XML file. SimpleXML is what CI installs; if a host lacks it, the
# check falls back to a structural assertion and says so, rather than passing
# silently on an unchecked file.
assert_valid_xml() {
  local file="$1" label="$2"
  if ! php -r 'exit(function_exists("simplexml_load_file") ? 0 : 1);'; then
    echo "NOTE: SimpleXML is unavailable, using a structural check for ${label}"
    grep -Eq '</urlset>|</sitemapindex>' "$file" || fail "${label} is not a closed sitemap document"
    return 0
  fi
  php -r 'exit(@simplexml_load_file($argv[1]) === false ? 1 : 0);' "$file" || fail "${label} is not well-formed XML"
}
assert_contains() {
  local haystack="$1" needle="$2" message="$3"
  [[ "$haystack" == *"$needle"* ]] || fail "$message"
}

# Flip a store setting through the application's own writer, so this test
# exercises exactly what the admin settings page writes (settings cache included).
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

fetch_page() {
  local path="$1" ua="${2:-$DESKTOP_UA}"
  # -L: some routes legitimately redirect (checkout with an empty cart), and the
  # page the visitor actually lands on is the one that must carry the overlay.
  curl -sS -L --max-redirs 3 -A "$ua" -c "$COOKIE_JAR" -b "$COOKIE_JAR" "${BASE}${path}" || fail "GET ${path} failed"
}

# The markup a real browser needs for the transition, in the order it must
# arrive in: the hold script in the head, then the overlay, then the content.
assert_transition_markup() {
  local path="$1" body="$2" label="$3"
  local overlay_count head_at body_at overlay_at main_at duration
  overlay_count="$(printf '%s' "$body" | grep -o 'id="sh-pt"' | wc -l | tr -d ' ')"
  [[ "$overlay_count" == "1" ]] || fail "${label} rendered ${overlay_count} transition overlays, expected exactly 1"
  assert_contains "$body" 'data-transition="1"' "${label} does not announce the transition on the body"
  assert_contains "$body" '__shPtFallback' "${label} has no first-paint hold script"
  assert_contains "$body" 'html.sh-pt-hold .sh-pt' "${label} has no first-paint style for the overlay"
  assert_contains "$body" 'assets/js/app.js' "${label} does not load the script that releases the overlay"
  head_at="$(printf '%s' "$body" | grep -bo '__shPtFallback' | head -n 1 | cut -d: -f1)"
  body_at="$(printf '%s' "$body" | grep -bo '<body' | head -n 1 | cut -d: -f1)"
  [[ -n "$head_at" && -n "$body_at" && "$head_at" -lt "$body_at" ]] || fail "${label} emits the hold after <body>, which is too late for the first paint"
  overlay_at="$(printf '%s' "$body" | grep -bo 'id="sh-pt"' | head -n 1 | cut -d: -f1)"
  # Public pages use <main id="sh-main">; the admin layout uses .sh-admin-main
  # and the admin sign-in page its own box. Whichever the page has, the overlay
  # must come first.
  main_at="$(printf '%s' "$body" | grep -bo '<main\|sh-admin-main\|sh-adminlogin__box' | head -n 1 | cut -d: -f1)"
  [[ -n "$overlay_at" && -n "$main_at" && "$overlay_at" -lt "$main_at" ]] || fail "${label} renders the overlay after the page content"
  duration="$(printf '%s' "$body" | sed -n 's/.*--sh-pt-ms:\([0-9]*\)ms.*/\1/p' | head -n 1)"
  [[ -n "$duration" ]] || fail "${label} does not carry the configured transition duration"
  printf '%s' "$duration"
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

# ---------------------------------------------------------------------------
# 7. Public SEO routes, fetched exactly as a crawler would.
#    /sitemap.xml and /robots.txt are generated from the database the reporting
#    test just seeded, so this also proves the endpoints answer over real HTTP
#    with the real schema in place.
# ---------------------------------------------------------------------------
robots_headers="$TMP_DIR/robots-headers"
robots_body="$TMP_DIR/robots.txt"
robots_ct=''
robots_code="$(curl -sS -A "$DESKTOP_UA" -D "$robots_headers" -o "$robots_body" -w '%{http_code}' "${BASE}/robots.txt")"
robots_ct="$(tr -d '\r' < "$robots_headers" | awk 'BEGIN { IGNORECASE=1 } /^content-type:/ { print $2; exit }')"

sitemap_headers="$TMP_DIR/sitemap-headers"
sitemap_body="$TMP_DIR/sitemap.xml"
sitemap_code="$(curl -sS -A "$DESKTOP_UA" -D "$sitemap_headers" -o "$sitemap_body" -w '%{http_code}' "${BASE}/sitemap.xml")"
sitemap_ct="$(tr -d '\r' < "$sitemap_headers" | awk 'BEGIN { IGNORECASE=1 } /^content-type:/ { print $2; exit }')"
sitemap_root=''
grep -q '<sitemapindex' "$sitemap_body" 2>/dev/null && sitemap_root='sitemapindex'
grep -q '<urlset' "$sitemap_body" 2>/dev/null && sitemap_root="${sitemap_root:-urlset}"
sitemap_locs="$(grep -c '<loc>' "$sitemap_body" 2>/dev/null || true)"

chunk_code="$(curl -sS -A "$DESKTOP_UA" -o "$TMP_DIR/sitemap-1.xml" -w '%{http_code}' "${BASE}/sitemap-1.xml")"
missing_code="$(curl -sS -A "$DESKTOP_UA" -o "$TMP_DIR/sitemap-999.xml" -w '%{http_code}' "${BASE}/sitemap-999.xml")"

# The canonical domain the store is configured with, if any.
canonical_base="$(php -r '
$cfg = require "config/database.php";
try {
    $pdo = new PDO("mysql:host=" . $cfg["host"] . ";port=" . $cfg["port"] . ";dbname=" . $cfg["name"] . ";charset=utf8mb4", $cfg["user"], $cfg["pass"]);
    $v = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = \"seo_canonical_base\" LIMIT 1")->fetchColumn();
    echo is_string($v) ? trim($v) : "";
} catch (Throwable $e) { echo ""; }')"

slug="$(php -r '
$cfg = require "config/database.php";
try {
    $pdo = new PDO("mysql:host=" . $cfg["host"] . ";port=" . $cfg["port"] . ";dbname=" . $cfg["name"] . ";charset=utf8mb4", $cfg["user"], $cfg["pass"]);
    $v = $pdo->query("SELECT slug FROM products WHERE status = 1 ORDER BY id ASC LIMIT 1")->fetchColumn();
    echo is_string($v) ? $v : "";
} catch (Throwable $e) { echo ""; }')"

product_code='(no published product)'
title_count='-'
canonical_count='-'
description_count='-'
og_count='-'
ld_count='-'
if [[ -n "$slug" ]]; then
  product_body="$TMP_DIR/product.html"
  product_code="$(curl -sS -A "$DESKTOP_UA" -o "$product_body" -w '%{http_code}' --get --data-urlencode "slug=${slug}" "${BASE}/product.php")"
  if [[ -s "$product_body" ]]; then
    title_count="$(grep -c '<title>' "$product_body" || true)"
    canonical_count="$(grep -c 'rel="canonical"' "$product_body" || true)"
    description_count="$(grep -c 'name="description"' "$product_body" || true)"
    og_count="$(grep -c 'property="og:title"' "$product_body" || true)"
    ld_count="$(grep -c 'application/ld+json' "$product_body" || true)"
  fi
fi

# Observed values, reported before the assertions so a failure is diagnosable
# from the annotations alone.
echo "::notice title=SEO routes observed::robots ${robots_code} ${robots_ct} | sitemap ${sitemap_code} ${sitemap_ct} root=${sitemap_root} locs=${sitemap_locs} | chunk ${chunk_code} | out-of-range ${missing_code} | product ${product_code} slug=${slug} titles=${title_count} canonicals=${canonical_count} descriptions=${description_count} og=${og_count} jsonld=${ld_count} | canonical_base=${canonical_base}"

[[ "$robots_code" == '200' ]] || { cat "$robots_body" >&2; fail "GET /robots.txt returned HTTP ${robots_code}, expected 200"; }
case "$robots_ct" in text/plain*) ;; *) cat "$robots_headers" >&2; fail "robots.txt Content-Type is '${robots_ct}', expected text/plain";; esac
grep -q '^User-agent: \*' "$robots_body" || { cat "$robots_body" >&2; fail 'robots.txt has no user-agent group'; }
grep -q '^Allow: /' "$robots_body" || { cat "$robots_body" >&2; fail 'robots.txt does not allow crawling'; }
grep -Eq '^Sitemap: .*/?sitemap\.xml[[:space:]]*$' "$robots_body" || { cat "$robots_body" >&2; fail 'robots.txt does not advertise the sitemap URL'; }
if grep -Eq '^Disallow: /[[:space:]]*$' "$robots_body"; then cat "$robots_body" >&2; fail 'robots.txt disallows the whole site'; fi
if grep -q 'Disallow: /assets/' "$robots_body"; then cat "$robots_body" >&2; fail 'robots.txt blocks the assets a crawler needs to render pages'; fi

[[ "$sitemap_code" == '200' ]] || { cat "$sitemap_body" >&2; fail "GET /sitemap.xml returned HTTP ${sitemap_code}, expected 200 from an installed store"; }
case "$sitemap_ct" in application/xml*) ;; *) cat "$sitemap_headers" >&2; fail "sitemap.xml Content-Type is '${sitemap_ct}', expected application/xml";; esac
if grep -qi '<!doctype html' "$sitemap_body"; then cat "$sitemap_body" >&2; fail 'sitemap.xml returned an HTML page instead of XML'; fi
assert_valid_xml "$sitemap_body" 'sitemap.xml'
[[ "$sitemap_root" == 'urlset' || "$sitemap_root" == 'sitemapindex' ]] || { cat "$sitemap_body" >&2; fail 'sitemap.xml has neither <urlset> nor <sitemapindex>'; }
grep -q '<loc>' "$sitemap_body" || { cat "$sitemap_body" >&2; fail 'the sitemap publishes no <loc> entry at all'; }
if grep -q '<loc>http' "$sitemap_body"; then
  while IFS= read -r loc; do
    case "$loc" in
      *"<loc>http://"*|*"<loc>https://"*) ;;
      *) cat "$sitemap_body" >&2; fail "the sitemap mixes absolute and relative URLs: ${loc}";;
    esac
  done < <(grep -o '<loc>[^<]*</loc>' "$sitemap_body")
fi
if [[ -n "$canonical_base" ]]; then
  while IFS= read -r loc; do
    case "$loc" in
      *"<loc>${canonical_base}/"*) ;;
      *) cat "$sitemap_body" >&2; fail "a sitemap URL ignores the configured canonical domain ${canonical_base}: ${loc}";;
    esac
  done < <(grep -o '<loc>[^<]*</loc>' "$sitemap_body")
fi
for forbidden in '/cart.php' '/checkout.php' '/payment.php' '/account.php' '/order-success.php' '/admin/' '/install/' '/logs/' '?q='; do
  if grep -qF "$forbidden" "$sitemap_body"; then cat "$sitemap_body" >&2; fail "the sitemap publishes a private route: ${forbidden}"; fi
done
if grep -q 'changefreq\|priority' "$sitemap_body"; then cat "$sitemap_body" >&2; fail 'the sitemap carries an invented changefreq or priority value'; fi

[[ "$chunk_code" == '200' ]] || fail "GET /sitemap-1.xml returned HTTP ${chunk_code}, expected 200"
assert_valid_xml "$TMP_DIR/sitemap-1.xml" 'sitemap-1.xml'
[[ "$missing_code" == '404' ]] || fail "GET /sitemap-999.xml returned HTTP ${missing_code}, expected 404 for a file that was never published"
assert_valid_xml "$TMP_DIR/sitemap-999.xml" 'the out-of-range sitemap chunk'

# A product page must carry exactly one of each metadata tag.
if [[ -n "$slug" ]]; then
  if [[ "$product_code" != '200' ]]; then
    dump_app_log "product page HTTP ${product_code}"
    echo "::notice title=Product page body::$(head -c 400 "$TMP_DIR/product.html" | tr '\n' ' ' | sed 's/%/%25/g')"
    fail "GET /product.php?slug=${slug} returned HTTP ${product_code}"
  fi
  [[ "$title_count" == '1' ]] || fail "the product page has ${title_count} <title> tags, expected 1"
  [[ "$canonical_count" == '1' ]] || fail "the product page has ${canonical_count} canonical links, expected 1"
  [[ "$description_count" == '1' ]] || fail "the product page has ${description_count} meta descriptions, expected 1"
  [[ "$og_count" == '1' ]] || fail "the product page has ${og_count} og:title tags, expected 1"
  [[ "$ld_count" == '1' ]] || fail "the product page has ${ld_count} JSON-LD blocks, expected 1"
  grep -q "product.php?slug=${slug}" "$TMP_DIR/product.html" || fail 'the product page does not reference its own canonical slug URL'
  echo "SEO product page metadata passed (slug: ${slug})"
else
  echo '::notice title=SEO routes observed::no published product in the test database, product page metadata not checked'
fi

# ---------------------------------------------------------------------------
# Phase 11 — the Digital Codes feature is removed.
#
# A row that still carries product_type = 'digital' is deliberately left in
# place for the owner to decide about, but the store must not sell it (there is
# no fulfilment path any more) and the page must say so honestly instead of
# offering a buy button or promising a code. A disposable probe row is created,
# rendered, asserted and removed.
# ---------------------------------------------------------------------------
digital_slug='legacy-digital-removal-probe'
digital_id="$(php -r '
$cfg = require "config/database.php";
$slug = "legacy-digital-removal-probe";
try {
    $pdo = new PDO("mysql:host=" . $cfg["host"] . ";port=" . $cfg["port"] . ";dbname=" . $cfg["name"] . ";charset=utf8mb4", $cfg["user"], $cfg["pass"]);
    $pdo->prepare("DELETE FROM products WHERE slug = ?")->execute([$slug]);
    $pdo->prepare("INSERT INTO products (name, slug, sku, price, stock, product_type, image, status, rating, review_count, sold_count, view_count, created_at)
                   VALUES (?,?,?,?,?,?,?,?,0,0,0,0,NOW())")
        ->execute(["Legacy Digital Removal Probe", $slug, "PROBE-DIGITAL", 300, 25, "digital", null, 1]);
    echo (string)(int)$pdo->lastInsertId();
} catch (Throwable $e) { echo ""; }')"

digital_code='(probe row not created)'
if [[ -n "$digital_id" ]]; then
  digital_body="$TMP_DIR/legacy-digital.html"
  digital_code="$(curl -sS -A "$DESKTOP_UA" -o "$digital_body" -w '%{http_code}' --get --data-urlencode "slug=${digital_slug}" "${BASE}/product.php")"
  digital_orderable='unknown'
  grep -q "data-add-cart=\"${digital_id}\"" "$digital_body" 2>/dev/null && digital_orderable='button present'
  grep -q "data-add-cart=\"${digital_id}\" data-qty-source=\"sh-pdp-qty\" disabled" "$digital_body" 2>/dev/null && digital_orderable='disabled'
  echo "::notice title=Phase 11 probe::legacy digital product page ${digital_code} id=${digital_id} buy_state=${digital_orderable}"

  [[ "$digital_code" == '200' ]] || {
    head -c 400 "$digital_body" >&2
    fail "GET /product.php?slug=${digital_slug} returned HTTP ${digital_code}"
  }
  grep -q 'can no longer be ordered online' "$digital_body" || fail 'a legacy digital product page does not explain that it can no longer be ordered'
  grep -q 'Not orderable online' "$digital_body" || fail 'a legacy digital product page does not show its availability honestly'
  [[ "$digital_orderable" == 'disabled' ]] || fail "a legacy digital product page offers a live buy button (state: ${digital_orderable})"
  if grep -qE 'Digital Delivery|Your Digital Codes|digital codes will appear' "$digital_body"; then
    fail 'a legacy digital product page still advertises code delivery'
  fi

  php -r '
$cfg = require "config/database.php";
try {
    $pdo = new PDO("mysql:host=" . $cfg["host"] . ";port=" . $cfg["port"] . ";dbname=" . $cfg["name"] . ";charset=utf8mb4", $cfg["user"], $cfg["pass"]);
    $pdo->prepare("DELETE FROM products WHERE slug = ?")->execute(["legacy-digital-removal-probe"]);
} catch (Throwable $e) {}'
  echo 'Legacy digital product probe passed'
else
  echo '::notice title=Phase 11 probe::probe row could not be created, legacy digital product page not checked'
fi

echo 'SEO route integration passed'

# ---------------------------------------------------------------------------
# 8. Page transition: every supported page load, website and admin panel.
#    The overlay must be in the first paint (before the content), on a fresh
#    load, a refresh and a direct URL, on the public site and in the admin; the
#    one global setting must drive both; and switching it off must render no
#    overlay at all rather than a hidden one.
# ---------------------------------------------------------------------------
echo 'Page transition HTTP integration'

home_body="$(fetch_page '/')"
home_duration="$(assert_transition_markup '/' "$home_body" 'GET / (fresh load)')"
# A refresh and a direct URL are the same request; the assertion is that the
# hold is emitted by the HTML itself rather than from a leftover session marker.
assert_transition_markup '/login.php' "$(fetch_page '/login.php')" 'GET /login.php (direct URL)' >/dev/null
assert_transition_markup '/products.php' "$(fetch_page '/products.php')" 'GET /products.php (direct URL)' >/dev/null

login_customer
assert_transition_markup '/account.php' "$(fetch_page '/account.php' "$MOBILE_UA")" 'GET /account.php (signed in)' >/dev/null
assert_transition_markup '/orders.php' "$(fetch_page '/orders.php')" 'GET /orders.php (signed in)' >/dev/null
assert_transition_markup '/checkout.php' "$(fetch_page '/checkout.php')" 'GET /checkout.php (signed in)' >/dev/null

order_probe_id="$(php -r '
$cfg = require "config/database.php";
try {
    $pdo = new PDO("mysql:host=" . $cfg["host"] . ";port=" . $cfg["port"] . ";dbname=" . $cfg["name"] . ";charset=utf8mb4", $cfg["user"], $cfg["pass"]);
    $id = $pdo->query("SELECT o.id FROM orders o JOIN users u ON u.id = o.user_id WHERE u.email = \"reporting-customer@example.test\" ORDER BY o.id ASC LIMIT 1")->fetchColumn();
    echo $id === false ? "" : (string)$id;
} catch (Throwable $e) { echo ""; }')"
if [[ -n "$order_probe_id" ]]; then
  assert_transition_markup "/order-details.php?id=${order_probe_id}" "$(fetch_page "/order-details.php?id=${order_probe_id}" "$MOBILE_UA")" "GET /order-details.php?id=${order_probe_id} (direct URL)" >/dev/null
  echo "::notice title=Page transition order page::checked /order-details.php?id=${order_probe_id}"
else
  echo '::notice title=Page transition order page::no order for the reporting customer, /order-details.php not checked'
fi

# The admin sign-in page is the one admin screen a signed-out visitor sees, and
# it must use the very same global duration as the storefront.
admin_duration="$(assert_transition_markup '/admin/login.php' "$(fetch_page '/admin/login.php')" 'GET /admin/login.php (admin panel)')"
[[ "$admin_duration" == "$home_duration" ]] || fail "the admin panel uses a different transition duration (${admin_duration}ms) than the website (${home_duration}ms)"

# A refresh of a page fetched after a click marker exists must not double-hold:
# the marker is single-use, and a later load without it is a normal cold load.
marker_page="$(fetch_page '/cart.php')"
assert_transition_markup '/cart.php' "$marker_page" 'GET /cart.php (refresh)' >/dev/null

# Change the shared duration: both panels must follow it, with no second setting.
read_setting() {
  SH_TEST_GET_KEY="$1" php -r '
    $_SERVER["SCRIPT_NAME"] = "/tests/setting.php";
    $_SERVER["HTTP_HOST"] = "localhost";
    $_SERVER["REMOTE_ADDR"] = "127.0.0.1";
    require getcwd() . "/config/config.php";
    echo (string)sh_setting((string)getenv("SH_TEST_GET_KEY"), "");
  ' 2>/dev/null
}
original_duration="$(read_setting transition_duration)"
[[ -n "$original_duration" ]] || original_duration=450
original_enabled="$(read_setting transition_enabled)"
[[ -n "$original_enabled" ]] || original_enabled=1

set_store_setting transition_duration 750
home_new="$(fetch_page '/')"
admin_new="$(fetch_page '/admin/login.php')"
assert_contains "$home_new" '--sh-pt-ms:750ms' 'the website did not pick up the changed transition duration'
assert_contains "$admin_new" '--sh-pt-ms:750ms' 'the admin panel did not pick up the changed transition duration'
set_store_setting transition_duration "$original_duration"

# Disabled: no overlay, no hold script — and the page content still renders.
set_store_setting transition_enabled 0
disabled_home="$(fetch_page '/')"
disabled_admin="$(fetch_page '/admin/login.php')"
disabled_account="$(fetch_page '/account.php')"
for pair in "GET /:${disabled_home}" "GET /admin/login.php:${disabled_admin}" "GET /account.php:${disabled_account}"; do
  label="${pair%%:*}"; body="${pair#*:}"
  if printf '%s' "$body" | grep -q 'id="sh-pt"'; then fail "${label} rendered the transition overlay while the setting is off"; fi
  if printf '%s' "$body" | grep -q '__shPtFallback'; then fail "${label} emitted the first-paint hold while the setting is off"; fi
done
assert_contains "$disabled_home" 'id="sh-main"' 'the homepage lost its content while the transition is disabled'
assert_contains "$disabled_admin" 'sh-adminlogin__box' 'the admin sign-in form disappeared while the transition is disabled'
set_store_setting transition_enabled "$original_enabled"
assert_transition_markup '/' "$(fetch_page '/')" 'GET / after re-enabling the transition' >/dev/null
echo "::notice title=Page transition observed::home=${home_duration}ms admin=${admin_duration}ms disabled=no-overlay duration-change=750ms both panels"

echo 'Session expiration HTTP integration passed'
