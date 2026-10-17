<?php
/**
 * ShopHaat Admin — Custom SMTP Server Setup & Deliverability Guide.
 *
 * Dedicated documentation page explaining SMTP configuration, credential retrieval,
 * encryption/port compatibility, testing, troubleshooting, and DNS authentication
 * (SPF, DKIM, DMARC).
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/admin-perms.php';
require_once SH_ROOT . '/includes/admin-tools.php';
require_once SH_ROOT . '/includes/mail.php';

sh_session_start();
sh_require_admin();
sh_require_perm('integrations.manage');

$adminPage = 'email';
$adminTitle = 'SMTP Setup Guide';
require dirname(__DIR__) . '/_layout.php';

$siteDomain = parse_url(sh_site_url(), PHP_URL_HOST) ?: 'yourdomain.com';
$fromEmail = (string)sh_setting('smtp_from_email', 'noreply@' . $siteDomain);
?>

<div class="sh-pagehead sh-pagehead--bar" style="margin-bottom:18px">
  <div style="display:flex;align-items:center;gap:12px">
    <a href="<?= e(sh_url('admin/email.php')) ?>" class="sh-btn sh-btn--sm sh-btn--ghost">
      <?= sh_icon('arrow-left', 14) ?> Back to Email &amp; Verification Settings
    </a>
    <span class="sh-badge sh-badge--info">Documentation &amp; Deliverability</span>
  </div>
</div>

<div style="max-width:980px;margin:0 auto">

  <div class="sh-panel" style="margin-bottom:20px">
    <div class="sh-panel__head">
      <h2 class="sh-panel__title"><?= sh_icon('mail', 18) ?> Complete Custom SMTP Configuration Guide</h2>
    </div>
    <div class="sh-panel__body" style="font-size:14px;line-height:1.75;color:var(--sh-ink)">
      <p style="font-size:15px;margin-bottom:14px">
        This comprehensive guide walks you through setting up, testing, and securing custom SMTP email delivery on your ShopHaat store.
        Properly configured authenticated SMTP ensures that 6-digit verification codes, order confirmations, and password recovery emails
        are reliably delivered directly to customer inboxes instead of getting rejected or dropped into spam folders.
      </p>
      <div class="sh-alert sh-alert--info" style="margin:14px 0">
        <?= sh_icon('info', 17) ?>
        <div>
          <strong>Architecture Rule:</strong> All 6-digit customer verification codes are cryptographically generated and validated
          by ShopHaat's backend and sent through your configured Custom SMTP server.
        </div>
      </div>
    </div>
  </div>

  <!-- Step 1 -->
  <div class="sh-panel" style="margin-bottom:20px">
    <div class="sh-panel__head">
      <h3 class="sh-panel__title">1. What is SMTP &amp; Why It Is Required</h3>
    </div>
    <div class="sh-panel__body" style="font-size:13.8px;line-height:1.75">
      <p>
        <strong>SMTP (Simple Mail Transfer Protocol)</strong> is the industry-standard internet protocol for sending outgoing emails.
        While PHP includes a basic built-in <code>mail()</code> function, modern mailbox providers (such as Gmail, Outlook, Yahoo, and iCloud)
        routinely block or flag unauthenticated emails sent via <code>mail()</code> because:
      </p>
      <ul>
        <li>Shared hosting servers share IP addresses among thousands of websites, often causing low IP reputation.</li>
        <li>Built-in <code>mail()</code> lacks proper cryptographic TLS encryption and sender identity handshakes.</li>
        <li>Without authenticated SMTP, messages fail modern DMARC, SPF, and DKIM alignment policies.</li>
      </ul>
      <p>
        ShopHaat uses <strong>PHPMailer</strong> over secure, authenticated SMTP to ensure every message is signed, encrypted, and
        honestly reported.
      </p>
    </div>
  </div>

  <!-- Step 2 -->
  <div class="sh-panel" style="margin-bottom:20px">
    <div class="sh-panel__head">
      <h3 class="sh-panel__title">2. Obtaining SMTP Credentials From Your Provider</h3>
    </div>
    <div class="sh-panel__body" style="font-size:13.8px;line-height:1.75">
      <p>
        You can use any standard SMTP provider or your web hosting mail service. Common options include:
      </p>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px;margin:14px 0">
        <div style="border:1px solid var(--sh-line);border-radius:8px;padding:14px;background:var(--sh-bg-2)">
          <h4 style="margin:0 0 6px;color:var(--sh-heading)">cPanel / Web Hosting Email</h4>
          <p style="margin:0;font-size:12.5px;color:var(--sh-muted)">
            Log in to cPanel &rarr; <em>Email Accounts</em> &rarr; Click <em>Connect Devices</em> next to your mailbox.
            Look for <strong>Outgoing Server (SMTP)</strong>, port <code>465</code> (SSL) or <code>587</code> (TLS).
          </p>
        </div>
        <div style="border:1px solid var(--sh-line);border-radius:8px;padding:14px;background:var(--sh-bg-2)">
          <h4 style="margin:0 0 6px;color:var(--sh-heading)">Google Workspace / Gmail</h4>
          <p style="margin:0;font-size:12.5px;color:var(--sh-muted)">
            Enable 2-Step Verification in Google Account &rarr; Generate a 16-character <strong>App Password</strong>
            under <em>Security &rarr; App Passwords</em>. Use <code>smtp.gmail.com</code> with port <code>587</code> (STARTTLS).
          </p>
        </div>
        <div style="border:1px solid var(--sh-line);border-radius:8px;padding:14px;background:var(--sh-bg-2)">
          <h4 style="margin:0 0 6px;color:var(--sh-heading)">Transactional Cloud Providers</h4>
          <p style="margin:0;font-size:12.5px;color:var(--sh-muted)">
            Services such as <strong>Brevo (Sendinblue)</strong>, <strong>SendGrid</strong>, <strong>Amazon SES</strong>,
            or <strong>Mailgun</strong> provide dedicated SMTP endpoints with high deliverability and API keys.
          </p>
        </div>
      </div>
      <div class="sh-alert sh-alert--warning" style="margin-top:10px">
        <?= sh_icon('alert', 16) ?>
        <div>
          <strong>Security Notice:</strong> The credentials above are examples only. Always obtain actual credentials from your provider's
          official control panel. Never share your SMTP password with third parties.
        </div>
      </div>
    </div>
  </div>

  <!-- Step 3 & 4 -->
  <div class="sh-panel" style="margin-bottom:20px">
    <div class="sh-panel__head">
      <h3 class="sh-panel__title">3. Entering SMTP Settings &amp; Port/Encryption Compatibility</h3>
    </div>
    <div class="sh-panel__body" style="font-size:13.8px;line-height:1.75">
      <table class="sh-table" style="margin-bottom:16px">
        <thead>
          <tr>
            <th>Field</th>
            <th>Required?</th>
            <th>Description &amp; Best Practices</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td><strong>SMTP Host</strong></td>
            <td>Yes</td>
            <td>The server address (e.g., <code>smtp.yourhost.com</code> or <code>smtp-relay.brevo.com</code>). Do not include <code>http://</code> or <code>ssl://</code>.</td>
          </tr>
          <tr>
            <td><strong>SMTP Port</strong></td>
            <td>Yes</td>
            <td>Standard port: <code>587</code> for STARTTLS, <code>465</code> for SSL/TLS, or <code>25</code> / <code>2525</code> for internal relays.</td>
          </tr>
          <tr>
            <td><strong>Encryption</strong></td>
            <td>Yes</td>
            <td>
              <strong>STARTTLS (Port 587):</strong> Recommended for modern providers.<br>
              <strong>SSL/TLS (Port 465):</strong> Encrypted from the first byte (traditional SMTPS).<br>
              <strong>None (Port 25):</strong> Unencrypted; only for local localhost relays.
            </td>
          </tr>
          <tr>
            <td><strong>SMTP Username</strong></td>
            <td>Yes</td>
            <td>Your mailbox address (e.g., <code>orders@<?= e($siteDomain) ?></code>) or API login username.</td>
          </tr>
          <tr>
            <td><strong>SMTP Password</strong></td>
            <td>Yes</td>
            <td>Your mailbox password or app-specific password. Saved securely and masked on screen.</td>
          </tr>
          <tr>
            <td><strong>Sender Email</strong></td>
            <td>Yes</td>
            <td>Must be a verified address matching your domain (e.g. <code>noreply@<?= e($siteDomain) ?></code>).</td>
          </tr>
          <tr>
            <td><strong>Sender Name</strong></td>
            <td>No</td>
            <td>The friendly name displayed in inboxes (e.g., <code>ShopHaat Store</code>).</td>
          </tr>
          <tr>
            <td><strong>Reply-To Email</strong></td>
            <td>Optional</td>
            <td>Customer support address where customer replies should be directed (e.g., <code>support@<?= e($siteDomain) ?></code>).</td>
          </tr>
          <tr>
            <td><strong>Timeout</strong></td>
            <td>Optional</td>
            <td>Connection timeout in seconds (default: <code>20</code>). Shared hosting may require <code>30</code> seconds.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Step 5, 6, 7 -->
  <div class="sh-panel" style="margin-bottom:20px">
    <div class="sh-panel__head">
      <h3 class="sh-panel__title">4. Testing Connection &amp; Sending Real Test Emails</h3>
    </div>
    <div class="sh-panel__body" style="font-size:13.8px;line-height:1.75">
      <ol style="margin-left:18px;margin-bottom:14px">
        <li>
          <strong>Save Settings:</strong> Fill in the fields on the <a href="<?= e(sh_url('admin/email.php')) ?>">Email &amp; Verification Settings</a>
          page and click <em>Save email settings</em>.
        </li>
        <li>
          <strong>Test SMTP Connection:</strong> Click <em>Test SMTP Connection</em> to verify network reachability, TLS handshake,
          and authentication credentials without sending an email.
        </li>
        <li>
          <strong>Send Test Email:</strong> Enter an accessible destination inbox and click <em>Send test email</em>.
          ShopHaat transmits a live test message and prints the exact server transcript.
        </li>
      </ol>
      <div class="sh-alert sh-alert--info">
        <?= sh_icon('info', 16) ?>
        <div>
          <strong>Notice on Acceptance vs. Delivery:</strong> When an SMTP server responds with <code>250 OK</code>, it means the server
          has accepted the message for delivery. Inbox arrival depends on domain reputation and DNS records (SPF, DKIM, DMARC).
        </div>
      </div>
    </div>
  </div>

  <!-- Step 8, 9, 10 -->
  <div class="sh-panel" style="margin-bottom:20px">
    <div class="sh-panel__head">
      <h3 class="sh-panel__title">5. DNS Deliverability Setup: SPF, DKIM &amp; DMARC</h3>
    </div>
    <div class="sh-panel__body" style="font-size:13.8px;line-height:1.75">
      <p>
        Publishing DNS authentication records for your domain is mandatory for ensuring emails avoid spam folders:
      </p>

      <h4 style="margin:12px 0 6px;color:var(--sh-heading)">A. SPF (Sender Policy Framework)</h4>
      <p style="margin-bottom:8px">
        SPF authorizes which mail servers can send email on behalf of your domain.
        <strong>Important:</strong> A domain must have only <em>one</em> SPF TXT record. Check your existing DNS records before adding one!
      </p>
      <div style="background:#0f172a;color:#e2e8f0;padding:10px 14px;border-radius:6px;font-family:monospace;font-size:13px;margin-bottom:12px">
        TXT @ "v=spf1 include:_spf.yourprovider.com ~all"
      </div>

      <h4 style="margin:12px 0 6px;color:var(--sh-heading)">B. DKIM (DomainKeys Identified Mail)</h4>
      <p style="margin-bottom:8px">
        DKIM adds a cryptographic digital signature to outgoing email headers. Your provider issues a public DNS key
        (often a CNAME or TXT record like <code>selector._domainkey.yourdomain.com</code>).
        If using private key signing, enter the selector and absolute path in the <em>DKIM Signing</em> panel in ShopHaat.
      </p>

      <h4 style="margin:12px 0 6px;color:var(--sh-heading)">C. DMARC (Domain-based Message Authentication, Reporting &amp; Conformance)</h4>
      <p style="margin-bottom:8px">
        DMARC aligns SPF and DKIM. Begin with a reporting-only policy (<code>p=none</code>) so you can monitor delivery reports
        before enforcing strict quarantine:
      </p>
      <div style="background:#0f172a;color:#e2e8f0;padding:10px 14px;border-radius:6px;font-family:monospace;font-size:13px;margin-bottom:12px">
        TXT _dmarc "v=DMARC1; p=none; rua=mailto:dmarc-reports@<?= e($siteDomain) ?>"
      </div>
    </div>
  </div>

  <!-- Step 11, 12 -->
  <div class="sh-panel" style="margin-bottom:20px">
    <div class="sh-panel__head">
      <h3 class="sh-panel__title">6. Troubleshooting Common SMTP Errors</h3>
    </div>
    <div class="sh-panel__body" style="font-size:13.8px;line-height:1.75">
      <table class="sh-table">
        <thead>
          <tr>
            <th>Error Message</th>
            <th>Probable Cause</th>
            <th>Recommended Solution</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td><code>535 Authentication failed</code></td>
            <td>Incorrect username or password. 2FA enabled on Gmail without App Password.</td>
            <td>Verify credentials. For Gmail/Google Workspace, generate an App Password in your Google Account security settings.</td>
          </tr>
          <tr>
            <td><code>Connection timed out / Network unreachable</code></td>
            <td>Shared hosting firewall blocks outbound port 25 or 587.</td>
            <td>Try port <code>465</code> with SSL/TLS. If still blocked, ask hosting support to open outbound SMTP connections.</td>
          </tr>
          <tr>
            <td><code>550 / 553 Sender address rejected</code></td>
            <td>Sender email does not match authenticated username or verified sender domain.</td>
            <td>Ensure <em>From Address</em> in settings matches the mailbox or is verified in your provider console.</td>
          </tr>
          <tr>
            <td><code>STARTTLS negotiation failed</code></td>
            <td>Host certificate mismatch or port/encryption incompatibility.</td>
            <td>Toggle <em>Accept self-signed certificate</em> if using internal server, or verify hostname matches certificate CN.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <div style="text-align:center;margin:30px 0 20px">
    <a href="<?= e(sh_url('admin/email.php')) ?>" class="sh-btn">
      <?= sh_icon('settings', 15) ?> Configure Email &amp; Verification Settings
    </a>
  </div>

</div>

<?php require dirname(__DIR__) . '/_footer.php'; ?>
