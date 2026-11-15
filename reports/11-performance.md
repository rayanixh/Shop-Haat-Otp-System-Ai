# 11 — Performance

**Status: NOT EXECUTED — no load generator, no MySQL server, no web server in this sandbox.
Nothing in this report is a measured number.**

## Why there is no benchmark here

Performance numbers require all three of: a real database with realistic row counts, an HTTP
server serving the app, and a load generator driving it. This environment has none of them
(`php-wasm` CLI only). Any "page loads in 180 ms" figure I produced here would be measuring a
WASM interpreter with an empty SQLite file — worse than useless, because it invites a decision.

## What was reviewed instead (static, and honest about being static)

| Area | Finding |
| --- | --- |
| Query shape on hot pages | Product listing, cart and admin lists use `LIMIT`/`OFFSET` with bound parameters. `products.php` joins categories, and stock/price filters are covered by indexes in `install/schema.php`. |
| N+1 on order screens | `order-details.php` loads items in one query (`sh_order_items()`); the admin order list paginates. No per-row query was found in the storefront listing path. |
| Index coverage for the new work | `payments.transaction_id` is now **unique-indexed** (report 01), so the duplicate check is an index lookup rather than a scan. `notification_logs` is keyed on the idempotency key. `otp_rate_limits` is indexed on `(phone_key, created_at)` and `(ip_key, created_at)` from the phase-2 migration. |
| Backup cost | `sh_backup_create()` streams per table in 800 KB / 500-row batches and writes through `gzopen`, so memory stays flat regardless of store size. Retention is a directory walk with `glob()`. |
| Caching | `checkout_totals`/cart summary are computed per request (correct — prices change); `system-health.php` caches its probe results for 600 s; support settings are cached per request. |
| Fixed cost on every request | `config/config.php` loads ~10 include files, none of which perform queries at load time (verified: `tests/*` include them with no database). No `SHOW TABLES`-style probe runs before a page's own queries — `sh_db_state()` checks the `installed.lock` file and one table. |
| Log growth | `logs/` and `logs/limits/` grow without a rotation step. The rate-limit counters are one small file per bucket+key and are reusable; the app logs are the ones that grow. **Recommendation:** a monthly `cron/logrotate` entry, documented in the deployment checklist as a manual step. |

## The measurement plan (for staging, in this order)

```bash
# 1. seed a realistic database: 5,000 products, 20,000 orders, 60,000 order_items
#    (use the installer demo data generator, then multiply)

# 2. enable the slow query log
mysql -e "SET GLOBAL slow_query_log = 1; SET GLOBAL long_query_time = 0.5;"

# 3. baseline with a fixed concurrency, not a "best of" run
ab -n 2000 -c 20 https://staging/                     # home
ab -n 2000 -c 20 https://staging/products.php         # listing
ab -n 1000 -c 20 https://staging/product.php?slug=…   # product page
ab -n 500  -c 10 https://staging/admin/orders.php     # admin (session cookie)

# 4. read the slow log, fix indexes, re-run
mysql -e "SELECT * FROM mysql.slow_log ORDER BY query_time DESC LIMIT 20" > slow-before.txt

# 5. the two paths that matter most for correctness-under-load
#    - 20 concurrent manual payment submissions (one TrxID) → exactly one succeeds
#    - 50 concurrent OTP requests for one phone → rate limit holds, no 500s
```

## Acceptance thresholds worth agreeing before measuring

| Metric | Suggested ceiling |
| --- | --- |
| Storefront page, p95, 20 concurrent | 800 ms |
| Admin list page, p95, 10 concurrent | 1.5 s |
| Checkout POST, p95 | 1.2 s |
| Slow queries (> 0.5 s) per 1000 storefront requests | 0 |
| Backup of a 500 MB database | under 120 s (the script sets `set_time_limit(300)`) |

Until step 5 runs against MySQL, the two concurrency guarantees in this project (one TrxID per
store, OTP limits under contention) rest on the database's own locking — designed for, but not
observed here.
