# ShopHaat — E-commerce Marketplace

A complete, self-contained online marketplace built with plain PHP, MySQL/MariaDB, HTML5, CSS3
and vanilla JavaScript. No Composer, no Node, no build step — upload the files and run the installer.

---

## Requirements

| Requirement | Minimum |
|---|---|
| PHP | 8.1 or newer |
| Extensions | `pdo`, `pdo_mysql`, `mbstring`, and `gd` (or `imagick`) |
| Database | MySQL 5.7+ or MariaDB 10.3+ |
| Web server | Apache with `mod_rewrite` (LiteSpeed and Nginx also work) |

Writable directories: `config/`, `logs/`, `uploads/`.

---

## Installation (cPanel / shared hosting)

1. **Upload** the contents of this folder to `public_html` (or a subfolder).
2. **Create a MySQL database** and a user in cPanel, and grant that user all privileges on the database.
3. **Open your site in a browser.** With no configuration present you are sent to the installer automatically.
4. **Fill in the single install form**: database credentials, your store name, and the administrator
   account you want to create. Tick "install demo data" if you want sample categories and products.
5. **Delete the `install/` folder** when the installer tells you setup is complete.

The installer writes `config/database.php` itself — no file needs to be edited by hand.

If the database credentials are wrong you get a plain explanation of the problem, never a blank page
or a stack trace.

### When the installer opens, and when it refuses

The store checks the database on every request and reacts to what it actually finds:

| Situation | What happens |
|---|---|
| Database present and healthy | Store runs. **The installer is closed** and redirects to the homepage, even if someone types `/install/` or `?step=repair` by hand. |
| No `config/database.php` yet | Installer opens for a first-time setup. |
| Database was deleted, renamed, or access was revoked | Installer opens in repair mode and explains why, so you can recreate it. |
| Database exists but the tables are missing | Installer opens in repair mode and recreates the schema. |
| Database *server* itself is unreachable | A clear 503 page with a link to the installer, in case the host or credentials changed. No redirect loop. |

On a repair the host, database name and username are pre-filled from your saved configuration.
The stored password is never written back into the page. The installer opens straight to the setup
form without an explanatory banner. A forced POST to the installer on a healthy
store cannot change anything — it is redirected away before any work is done.

### Upload size limits

Images are limited only by your **server's** PHP settings — the application adds no cap of its own.
Two config files ship with the project and set a 64 MB ceiling:

| File | Used by |
|---|---|
| `.user.ini` | PHP running as FastCGI / PHP-FPM (most cPanel accounts) |
| `.htaccess` | Apache with mod_php |

If your host ignores both, raise the values in cPanel under **Select PHP Version → Options**:
`upload_max_filesize` and `post_max_size` (keep `post_max_size` slightly larger).

The current effective limit is always shown next to every upload field in the admin, and if a file
is too large the error names the exact limit rather than failing vaguely.

### Permissions

```
chmod 755 config logs uploads
chmod 755 uploads/products uploads/logos uploads/payments
```

---

## After installing

Sign in at `/admin/` with the administrator account you created.

| Section | What it does |
|---|---|
| Dashboard | Revenue, orders, payments awaiting review, low stock, 14-day chart |
| Homepage Images | Slider slides and the three feature cards: upload images, edit text, links, order and visibility |
| Products / Categories / Brands | Full catalogue management with image upload |
| Digital Codes | Bulk-load codes for digital products; delivered codes are locked |
| Coupons | Percentage or fixed discounts, minimums, caps, usage limits, date windows |
| Orders | Full order detail, status control, notification history |
| Parcels | Create parcels for physical orders, assign couriers, tracking numbers and a full status timeline |
| Couriers | Courier registry: manual or API drivers, credentials stored server-side |
| Payments | Review and approve or reject each submitted manual payment |
| Payment Methods | bKash, Nagad, Rocket, COD and any other method you add |
| Payment Gateways | Modular automatic gateway registration |
| Telegram / WhatsApp / Messenger / Email | One page per channel, each independent |
| Notifications | Per-event, per-channel switches plus the full delivery log |
| Phone Verification | Master switch, verification rules, SMS/OTP provider config and a test-send button (superadmin only) |
| OTP Logs | Every OTP request/verify with masked phone, purpose, status, attempts and IP |
| Security Logs | Structured audit trail: logins, OTP events, phone changes, blocked orders, rate limits (superadmin only) |
| Settings | General site configuration only |
| Error Logs | Handled errors, with secrets scrubbed |

---

## How payments work

**Manual methods (bKash, Nagad, Rocket, …)**
The customer sends money to your merchant number, then submits the transaction ID and the number they
sent from. The order is marked *payment submitted* and waits. **Nothing is auto-verified.** You compare
the transaction ID with your merchant statement in *Admin → Payments* and approve or reject it.
Approving moves the order to processing and, for digital products, releases the codes.

**Cash on delivery**
Available for physical products only; hidden automatically for digital-only carts.

**Automatic gateways**
Gateways are modular. Credentials are stored server-side and are never sent to the browser.
A callback is only trusted after it has been verified against the provider's own API, and repeat
callbacks for the same reference are ignored so an order can never be settled twice.

> Only **SSLCommerz** ships with a real server-side validation call. Other drivers (aamarPay, shurjoPay,
> custom) are registered so you can plug them in, but they deliberately refuse to settle an order until
> their official API verification is implemented with real credentials. The site never fakes a payment.

Register this callback URL with your provider:

```
https://yourdomain.com/api/payment.php?action=callback&gateway=CODE
```

---

## Courier & parcel delivery

Physical orders can be dispatched from the admin panel (Admin → Parcels). A parcel is a snapshot of
the recipient taken from the order, linked to a courier, and tracked through a status timeline:

`Draft → Booked → Picked up → In transit → Out for delivery → Delivered` (plus *Failed attempt*,
*Returned* and *Cancelled*).

- **One source of truth:** marking a parcel *Delivered* moves the order to *Completed*; *Returned*
  moves it to *Cancelled*; any other movement into pickup/transit sets the order to *Processing*.
  Order `payment_status` is never changed by parcel activity — COD collection is still approved under
  Admin → Payments.
- **Courier registry:** ships pre-seeded with common Bangladesh couriers (Steadfast, Pathao, RedX,
  eCourier, Paperfly, Sundarban, SA Paribahan), all **disabled by default**. Enable and edit them under
  Admin → Couriers. A tracking URL template can include a `{tracking}` placeholder.
- **API drivers:** credentials are stored server-side and never sent to the browser. Only **Steadfast**
  currently ships with a real API client (book a parcel and fetch its status). The other drivers are
  registered so you can store their credentials, but they deliberately refuse to send requests until
  their official API is wired in — the site never fakes a booking or a tracking result. Every courier
  also works fully manually: type the consignment number and update the status by hand.

Existing installs pick up the new tables automatically the first time the Couriers or Parcels page is
opened; fresh installs create them during setup.

## Phone verification & anti-fake orders

An optional phone-OTP layer protects account creation, sign-in, checkout and order placement from
fake or duplicated orders. It is governed by a **master switch** and per-rule toggles under
*Admin → Phone Verification*, so turning the system off restores the original flow exactly.

- **Rules:** require OTP before account creation, before sign-in (unverified phones), before checkout,
  before order creation, and require a verified phone for COD and online-payment orders. A separate
  **“Require OTP for every order”** toggle (off by default) re-challenges even verified users.
- **Providers are swappable.** The system ships with an `offline` test driver (no real SMS, code shown
  only to the admin on the test button) and a `generic_http` driver configured entirely from the admin
  panel (URL, method, body/headers with `{phone}`, `{message}`, `{sender}`, `{api_key}`, `{api_secret}`
  placeholders, plus an optional success-field check so a delivery is never faked). More drivers can be
  registered with `sh_otp_register_driver()`.
- **OTP security:** codes are generated server-side (default 6 digits, 5-minute expiry), stored only as
  `password_hash()` hashes, and never logged or echoed to the customer. Attempts, resends and cooldowns
  are enforced server-side, with per-phone, per-IP and daily rate limits.
- **Phone normalisation:** Bangladeshi numbers (`017XXXXXXXX`, `88017XXXXXXXX`, `+88017XXXXXXXX`) are
  normalised to one canonical form, so the same number can never verify twice on two accounts.
- **Anti-fake order gate:** `sh_create_order()` re-verifies phone verification **inside the order
  transaction** before anything is written, always recomputes prices/stock server-side, and a per-session
  idempotency token makes double-clicks impossible. Guest carts survive login/registration and checkout
  verification via the existing cart token + merge, and return URLs are validated server-side.
- **Account flows:** a verified phone change keeps the old number active until the new one is verified;
  the account page shows Verified/Unverified with a Verify-now link; admins can manually verify or reset
  a customer's phone (logged).

Existing installs create the new columns/tables automatically on the first request (stamped in
`settings`), so no manual migration is needed. Fresh installs create them during setup.

## Digital product delivery

Codes are stored in `product_codes` and issued inside a locked database transaction
(`SELECT … FOR UPDATE`). A code moves from `available` to `used` exactly once and is bound to one
order item, so the same code can never reach two customers. Delivery only happens after a payment has
been verified. Delivered codes cannot be deleted from the admin panel.

---

## Notifications

Four independent channels: Telegram, WhatsApp Cloud API, Facebook Messenger and Email/SMTP.
Each has its own settings page and its own enable switch, and *Admin → Notifications* controls
which of the eight events go to which channel.

Key guarantees:

- A channel that is disabled or unconfigured is recorded as **skipped**, never as sent.
- A channel that errors is recorded as **failed** with the reason.
- A notification failure never fails, blocks or rolls back an order.
- Tokens and passwords are scrubbed before anything is written to the log.

---

## Security

- All queries use PDO prepared statements.
- Every state-changing form and AJAX call is CSRF protected.
- Output is escaped through `e()`; uploads are validated by real MIME type, size and dimensions,
  and stored under an `.htaccess` that disables script execution.
- Passwords are hashed with `password_hash()` and re-hashed on sign-in when the algorithm improves.
- OTP codes are likewise only ever stored as hashes, with server-side attempt/resend/rate limits.
- Sign-in throttling on both the customer and admin login forms.
- Orders, addresses and digital codes are ownership-checked on every read (IDOR protection).
- Phone verification settings, SMS credentials, logs and manual verification are restricted to the
  superadmin role.
- Errors are logged server-side; visitors only ever see a friendly message. No `@` suppression is
  used anywhere in the codebase.

---

## Layout

```
index.php products.php product.php category.php cart.php checkout.php
payment.php order-success.php login.php register.php logout.php
account.php orders.php order-details.php addresses.php wishlist.php
digital.php password.php track.php support.php help.php page.php
otp.php     phone-verification entry page (register/login/account/checkout)

admin/      dashboard, products, categories, brands, digital-codes, coupons,
            orders, payments, payment-methods, payment-gateways, customers,
            telegram, whatsapp, messenger, email, notifications, settings, logs,
            security (phone verification), otp-logs, security-logs
api/        cart, search, checkout, orders, payment, notifications, otp
config/     config.php, database.php (generated), mail.php
includes/   header, footer, auth, admin-auth, functions, csrf, validation,
            db, errors, cart, catalog, payment, notifications, partials
assets/     css/app.css   ← the only stylesheet
            js/app.js     ← the only script
install/    the web installer
```

---

## Verifying the installation

The suites under `/home/user/tests` (not part of the deployable site) cover syntax, include
dependencies, every storefront page, every admin page, and a full purchase → manual payment →
admin approval → digital-code delivery cycle including IDOR and CSRF checks.

```
bash tests/run-all.sh
```
