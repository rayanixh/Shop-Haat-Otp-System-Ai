<?php
/**
 * ShopHaat Admin — Firebase Integration & Setup Guide.
 *
 * Dedicated documentation page explaining Firebase console configuration, Web API Key,
 * Email/Password sign-in method, authorized domains, user synchronization, and
 * the architectural distinction between link-based verification and custom 6-digit OTP codes.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/admin-perms.php';
require_once SH_ROOT . '/includes/admin-tools.php';
require_once SH_ROOT . '/includes/firebase.php';

sh_session_start();
sh_require_admin();
sh_require_perm('integrations.manage');

$adminPage = 'email';
$adminTitle = 'Firebase Setup Guide';
require dirname(__DIR__) . '/_layout.php';

$siteHost = parse_url(sh_site_url(), PHP_URL_HOST) ?: 'yourdomain.com';
$continueUrl = sh_fb_continue_url();
$projectId = (string)sh_setting('firebase_project_id', '');
?>

<div class="sh-pagehead sh-pagehead--bar" style="margin-bottom:18px">
  <div style="display:flex;align-items:center;gap:12px">
    <a href="<?= e(sh_url('admin/email.php#firebase')) ?>" class="sh-btn sh-btn--sm sh-btn--ghost">
      <?= sh_icon('arrow-left', 14) ?> Back to Email &amp; Verification Settings
    </a>
    <span class="sh-badge sh-badge--info">Documentation &amp; Identity Sync</span>
  </div>
</div>

<div style="max-width:980px;margin:0 auto">

  <div class="sh-panel" style="margin-bottom:20px">
    <div class="sh-panel__head">
      <h2 class="sh-panel__title"><?= sh_icon('settings', 18) ?> Complete Firebase Integration Setup Guide</h2>
    </div>
    <div class="sh-panel__body" style="font-size:14px;line-height:1.75;color:var(--sh-ink)">
      <p style="font-size:15px;margin-bottom:14px">
        This step-by-step guide walks you through integrating Google Firebase Authentication with your ShopHaat store.
        Firebase provides identity synchronization, Google Sign-In, and hosted link-based email verification.
      </p>

      <div class="sh-alert sh-alert--warning" style="margin:14px 0">
        <?= sh_icon('alert', 17) ?>
        <div>
          <strong>Important Architecture Rule:</strong>
          Firebase's standard email verification is <strong>link-based</strong> (it sends an email with an action link, not a 6-digit code).
          ShopHaat's <strong>6-digit numeric verification code</strong> system is generated and authenticated securely on your server
          and delivered through <strong>Custom SMTP</strong>.
        </div>
      </div>
    </div>
  </div>

  <!-- Step 1 & 2 -->
  <div class="sh-panel" style="margin-bottom:20px">
    <div class="sh-panel__head">
      <h3 class="sh-panel__title">1. Opening the Firebase Console &amp; Selecting Your Project</h3>
    </div>
    <div class="sh-panel__body" style="font-size:13.8px;line-height:1.75">
      <ol style="margin-left:18px;margin-bottom:10px">
        <li>Visit the <a href="https://console.firebase.google.com/" target="_blank" rel="noopener">Firebase Console</a> and log in with your Google account.</li>
        <li>Select your existing ShopHaat Firebase project (e.g. <code><?= e($projectId !== '' ? $projectId : 'your-project-id') ?></code>), or create a new project if you have not set one up yet.</li>
      </ol>
    </div>
  </div>

  <!-- Step 3 & 4 -->
  <div class="sh-panel" style="margin-bottom:20px">
    <div class="sh-panel__head">
      <h3 class="sh-panel__title">2. Registering a Web App &amp; Finding Your Web API Key</h3>
    </div>
    <div class="sh-panel__body" style="font-size:13.8px;line-height:1.75">
      <ol style="margin-left:18px;margin-bottom:14px">
        <li>Inside your Firebase project, click the <strong>Project Settings</strong> gear icon &rarr; <strong>General</strong>.</li>
        <li>Scroll down to <em>Your apps</em>. If no Web app exists, click the <strong>&lt;/&gt; (Web)</strong> icon to register one (e.g. named <code>ShopHaat Web</code>).</li>
        <li>
          Under <em>Project settings &rarr; General</em>, locate your <strong>Web API Key</strong>. It starts with <code>AIzaSy...</code>
        </li>
      </ol>
      <div class="sh-alert sh-alert--info">
        <?= sh_icon('info', 16) ?>
        <div>
          <strong>Security Distiction:</strong>
          ShopHaat uses the client-compatible <strong>Web API Key</strong> over server-to-server HTTPS REST calls.
          Your Web API Key is stored encrypted in the MySQL database and is never exposed in browser HTML or public JavaScript files.
          <em>Never</em> upload a service-account private key (<code>.json</code> file) to public web folders!
        </div>
      </div>
    </div>
  </div>

  <!-- Step 5 & 6 -->
  <div class="sh-panel" style="margin-bottom:20px">
    <div class="sh-panel__head">
      <h3 class="sh-panel__title">3. Enabling Email/Password Sign-in &amp; Authorized Domains</h3>
    </div>
    <div class="sh-panel__body" style="font-size:13.8px;line-height:1.75">
      <h4 style="margin:0 0 8px;color:var(--sh-heading)">A. Enable Email/Password Provider</h4>
      <p style="margin-bottom:12px">
        Navigate to <strong>Build &rarr; Authentication &rarr; Sign-in method</strong>.
        Click <strong>Email/Password</strong>, switch the primary toggle to <strong>Enable</strong>, and click <em>Save</em>.
        (Leave "Email link (passwordless sign-in)" disabled unless required).
      </p>

      <h4 style="margin:14px 0 8px;color:var(--sh-heading)">B. Add Authorized Domain</h4>
      <p style="margin-bottom:8px">
        In the Authentication section, click the <strong>Settings</strong> tab &rarr; <strong>Authorized domains</strong>.
        Add your store's production domain to the list:
      </p>
      <div style="background:#0f172a;color:#e2e8f0;padding:10px 14px;border-radius:6px;font-family:monospace;font-size:13px;margin-bottom:12px">
        <?= e($siteHost) ?>
      </div>
      <p style="font-size:12.5px;color:var(--sh-muted);margin:0">
        This allows Firebase to accept authentication handshakes and verification redirect requests from your website.
      </p>
    </div>
  </div>

  <!-- Step 7, 8, 9 -->
  <div class="sh-panel" style="margin-bottom:20px">
    <div class="sh-panel__head">
      <h3 class="sh-panel__title">4. Verification Email Template &amp; Continue URL</h3>
    </div>
    <div class="sh-panel__body" style="font-size:13.8px;line-height:1.75">
      <p>
        If you are using Firebase's hosted link verification:
      </p>
      <ol style="margin-left:18px;margin-bottom:14px">
        <li>Go to <strong>Authentication &rarr; Templates &rarr; Email address verification</strong>.</li>
        <li>Click the edit pencil icon to customize the sender name and subject.</li>
        <li>
          ShopHaat provides a dedicated landing page for verified customers returning from Firebase:
          <div style="background:#0f172a;color:#e2e8f0;padding:8px 12px;border-radius:6px;font-family:monospace;font-size:12.5px;margin:8px 0">
            <?= e($continueUrl) ?>
          </div>
        </li>
      </ol>
    </div>
  </div>

  <!-- Step 10 & 11 -->
  <div class="sh-panel" style="margin-bottom:20px">
    <div class="sh-panel__head">
      <h3 class="sh-panel__title">5. MySQL User Synchronization &amp; Testing</h3>
    </div>
    <div class="sh-panel__body" style="font-size:13.8px;line-height:1.75">
      <p>
        ShopHaat synchronizes your MySQL customer accounts with Firebase safely without altering your primary database structure:
      </p>
      <ul>
        <li>When a customer registers or logs in with an email, a linked shadow account is established in Firebase.</li>
        <li>The unique Firebase user identifier is saved in <code>users.firebase_uid</code>.</li>
        <li>Google Sign-In accounts have their emails marked verified automatically because Google has already validated the email.</li>
        <li>Local account status is securely verified through server-side token exchange rather than client-side assertions.</li>
      </ul>

      <h4 style="margin:16px 0 8px;color:var(--sh-heading)">Testing the Connection</h4>
      <p style="margin-bottom:10px">
        On the <a href="<?= e(sh_url('admin/email.php#firebase')) ?>">Email &amp; Verification Settings</a> screen,
        click <strong>Test Firebase Connection</strong>. ShopHaat performs an encrypted handshake with Google Identity Toolkit
        and reports whether Email/Password sign-in is operational.
      </p>
    </div>
  </div>

  <!-- Step 12 -->
  <div class="sh-panel" style="margin-bottom:20px">
    <div class="sh-panel__head">
      <h3 class="sh-panel__title">6. Common Firebase Errors &amp; Solutions</h3>
    </div>
    <div class="sh-panel__body" style="font-size:13.8px;line-height:1.75">
      <table class="sh-table">
        <thead>
          <tr>
            <th>Error Code</th>
            <th>Cause</th>
            <th>Solution</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td><code>OPERATION_NOT_ALLOWED</code></td>
            <td>Email/Password sign-in is disabled in your Firebase project.</td>
            <td>Go to Firebase Console &rarr; Authentication &rarr; Sign-in method &rarr; Enable Email/Password.</td>
          </tr>
          <tr>
            <td><code>API_KEY_INVALID</code></td>
            <td>Web API Key has a typo or is restricted.</td>
            <td>Copy the exact Web API Key from Firebase Project Settings &rarr; General. Ensure Google Cloud API restrictions allow Identity Toolkit API.</td>
          </tr>
          <tr>
            <td><code>UNAUTHORIZED_DOMAIN</code></td>
            <td>Website domain is missing from authorized domains.</td>
            <td>Add <code><?= e($siteHost) ?></code> to Firebase Console &rarr; Authentication &rarr; Settings &rarr; Authorized domains.</td>
          </tr>
          <tr>
            <td><code>EXPIRED_OOB_CODE</code></td>
            <td>The verification link in the email expired before being clicked.</td>
            <td>Ask the customer to request a new verification email from their profile page.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <div style="text-align:center;margin:30px 0 20px">
    <a href="<?= e(sh_url('admin/email.php#firebase')) ?>" class="sh-btn">
      <?= sh_icon('settings', 15) ?> Configure Firebase Settings
    </a>
  </div>

</div>

<?php require dirname(__DIR__) . '/_footer.php'; ?>
