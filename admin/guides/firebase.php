<?php
/**
 * ShopHaat Admin — Firebase Integration & Authentication Guide.
 *
 * Comprehensive step-by-step documentation for integrating Google Firebase,
 * understanding public Web App configuration vs backend credentials, configuring
 * authorized domains, backend account synchronization, and link-based vs
 * 6-digit numeric OTP architecture.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/admin-perms.php';
require_once SH_ROOT . '/includes/firebase.php';

sh_session_start();
sh_require_admin();
sh_require_perm('integrations.manage');

$adminPage = 'email';
$adminTitle = 'Firebase Setup Guide';
require dirname(__DIR__) . '/_layout.php';

$siteDomain = (string)(parse_url(sh_site_url(), PHP_URL_HOST) ?: 'yourdomain.com');
$continueUrl = (string)sh_fb_continue_url();
?>

<div class="sh-guide-wrap">

  <!-- Guide Header / Hero -->
  <header class="sh-guide-hero">
    <div class="sh-guide-hero__nav">
      <a href="<?= e(sh_url('admin/email.php?tab=firebase')) ?>" class="sh-btn sh-btn--sm sh-btn--ghost">
        <?= sh_icon('arrow-left', 14) ?> Back to Email &amp; Verification Settings
      </a>
      <span class="sh-badge sh-badge--info">ShopHaat Integration Guide</span>
    </div>
    <h1 class="sh-guide-hero__title">Firebase Integration Setup Guide</h1>
    <p class="sh-guide-hero__lead">
      Connect Google Firebase Authentication to your ShopHaat store. Learn how to configure your Firebase project, set up authorized domains, manage Web API keys safely, and understand how Firebase authentication coordinates with ShopHaat's customer accounts.
    </p>
  </header>

  <!-- Step 1: Project Setup -->
  <section class="sh-guide-card">
    <div class="sh-guide-card__header">
      <span class="sh-guide-card__num">1</span>
      <h2 class="sh-guide-card__title">Create or Select a Firebase Project</h2>
    </div>
    <div class="sh-guide-card__body">
      <p>
        Firebase provides managed identity services backed by Google Cloud infrastructure. To begin:
      </p>
      <ol style="margin:8px 0 14px 20px;padding:0">
        <li style="margin-bottom:6px">Visit the <a href="https://console.firebase.google.com" target="_blank" rel="noopener" style="color:var(--sh-brand);text-decoration:underline">Firebase Console (console.firebase.google.com)</a> and sign in with your Google Account.</li>
        <li style="margin-bottom:6px">Click <strong>Add Project</strong> (or select your existing project).</li>
        <li style="margin-bottom:6px">Enter your store name (e.g., <code>ShopHaat Store</code>) and accept the terms. Google Analytics is optional.</li>
        <li style="margin-bottom:6px">Click <strong>Create Project</strong> and wait for the initialization to complete.</li>
      </ol>
    </div>
  </section>

  <!-- Step 2: Configure Authentication Provider -->
  <section class="sh-guide-card">
    <div class="sh-guide-card__header">
      <span class="sh-guide-card__num">2</span>
      <h2 class="sh-guide-card__title">Enable Email/Password Sign-In Provider</h2>
    </div>
    <div class="sh-guide-card__body">
      <p>
        Before ShopHaat can communicate with Firebase Authentication, the Email/Password provider must be activated:
      </p>
      <ol style="margin:8px 0 14px 20px;padding:0">
        <li style="margin-bottom:6px">In the left sidebar of the Firebase Console, navigate to <strong>Build &rarr; Authentication</strong>.</li>
        <li style="margin-bottom:6px">Click <strong>Get Started</strong> if Authentication has not been initialized yet.</li>
        <li style="margin-bottom:6px">Click the <strong>Sign-in method</strong> tab.</li>
        <li style="margin-bottom:6px">Select <strong>Email/Password</strong> from the provider list.</li>
        <li style="margin-bottom:6px">Toggle the first switch to <strong>Enable</strong>. Leave <em>Email link (passwordless sign-in)</em> disabled unless specifically required.</li>
        <li style="margin-bottom:6px">Click <strong>Save</strong>.</li>
      </ol>
      <div class="sh-guide-callout sh-guide-callout--warn">
        <?= sh_icon('alert', 16) ?>
        <div>
          <strong>Security Note:</strong> Do not enable <em>Anonymous sign-in</em> or unauthenticated public sign-in methods unless you have explicit business logic requiring guest accounts.
        </div>
      </div>
    </div>
  </section>

  <!-- Step 3: Register Web App & Obtain Public Config -->
  <section class="sh-guide-card">
    <div class="sh-guide-card__header">
      <span class="sh-guide-card__num">3</span>
      <h2 class="sh-guide-card__title">Register Web App &amp; Obtain Public Configuration</h2>
    </div>
    <div class="sh-guide-card__body">
      <p>
        Next, register a Web App within your Firebase project to generate your store's client configuration:
      </p>
      <ol style="margin:8px 0 14px 20px;padding:0">
        <li style="margin-bottom:6px">Click the gear icon &rarr; <strong>Project settings</strong> in the top-left corner.</li>
        <li style="margin-bottom:6px">Under the <strong>General</strong> tab, scroll down to <em>Your apps</em> and click the <strong>Web icon (&lt;/&gt;)</strong>.</li>
        <li style="margin-bottom:6px">Enter an app nickname (e.g. <code>ShopHaat Web</code>) and click <strong>Register app</strong>.</li>
        <li style="margin-bottom:6px">Firebase will display a JavaScript configuration snippet containing your public configuration keys.</li>
      </ol>

      <div class="sh-guide-callout sh-guide-callout--info">
        <?= sh_icon('info', 16) ?>
        <div>
          <strong>Public vs. Private Credentials:</strong> The <strong>Web API Key</strong> (starts with <code>AIzaSy...</code>) is a public client identifier used to route requests to your Firebase project. It is stored server-side encrypted in ShopHaat. <em>Never</em> upload your Google Cloud Service Account private key JSON file to public directories or web pages.
        </div>
      </div>

      <div class="sh-tablewrap">
        <table class="sh-guide-table">
          <thead><tr><th>Field in ShopHaat</th><th>Firebase Configuration Property</th><th>Example Format</th></tr></thead>
          <tbody>
            <tr><td><strong>Firebase Web API Key</strong></td><td><code>apiKey</code></td><td><code>AIzaSyD-1234567890abcdef...</code></td></tr>
            <tr><td><strong>Firebase Project ID</strong></td><td><code>projectId</code></td><td><code>shop-haat-12345</code></td></tr>
            <tr><td><strong>Firebase App ID</strong> (Optional)</td><td><code>appId</code></td><td><code>1:1234567890:web:abcdef...</code></td></tr>
            <tr><td><strong>Auth Domain</strong> (Optional)</td><td><code>authDomain</code></td><td><code>shop-haat-12345.firebaseapp.com</code></td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <!-- Step 4: Configure Authorized Domains -->
  <section class="sh-guide-card">
    <div class="sh-guide-card__header">
      <span class="sh-guide-card__num">4</span>
      <h2 class="sh-guide-card__title">Configure Authorized Domains in Firebase</h2>
    </div>
    <div class="sh-guide-card__body">
      <p>
        Firebase blocks authentication requests, OAuth redirects, and verification callbacks originating from unauthorized domain names. You must add your store's live domain:
      </p>
      <ol style="margin:8px 0 14px 20px;padding:0">
        <li style="margin-bottom:6px">In the Firebase Console, go to <strong>Authentication &rarr; Settings &rarr; Authorized domains</strong>.</li>
        <li style="margin-bottom:6px">Click <strong>Add domain</strong>.</li>
        <li style="margin-bottom:6px">
          Enter your website's domain: <span class="sh-copy-chip"><?= e($siteDomain) ?></span> (do not include <code>https://</code> or trailing slashes).
        </li>
        <li style="margin-bottom:6px">If using a staging or test environment, add the staging hostname as well.</li>
      </ol>
      <div class="sh-guide-callout sh-guide-callout--success">
        <?= sh_icon('check-circle', 16) ?>
        <div>
          <strong>Continue URL:</strong> Customers completing Firebase link verification return to: <br>
          <code style="word-break:break-all"><?= e($continueUrl) ?></code>
        </div>
      </div>
    </div>
  </section>

  <!-- Step 5: Enter Configuration in ShopHaat -->
  <section class="sh-guide-card">
    <div class="sh-guide-card__header">
      <span class="sh-guide-card__num">5</span>
      <h2 class="sh-guide-card__title">Enter Firebase Configuration in ShopHaat</h2>
    </div>
    <div class="sh-guide-card__body">
      <ol style="margin:8px 0 14px 20px;padding:0">
        <li style="margin-bottom:6px">Navigate to <strong>Admin &rarr; Email &amp; Verification Settings</strong>.</li>
        <li style="margin-bottom:6px">Under <strong>Setup Method Selection</strong>, click <strong>Firebase Integration</strong>.</li>
        <li style="margin-bottom:6px">Paste your <strong>Firebase Web API Key</strong> into the field. It is encrypted in the database using AES-256-GCM.</li>
        <li style="margin-bottom:6px">Enter your <strong>Firebase Project ID</strong>.</li>
        <li style="margin-bottom:6px">Check <strong>Enable Firebase Integration</strong> and click <strong>Save Firebase Settings</strong>.</li>
      </ol>
    </div>
  </section>

  <!-- Step 6: Test Integration -->
  <section class="sh-guide-card">
    <div class="sh-guide-card__header">
      <span class="sh-guide-card__num">6</span>
      <h2 class="sh-guide-card__title">Testing the Firebase Connection</h2>
    </div>
    <div class="sh-guide-card__body">
      <p>
        Click the <strong>Test Firebase Connection</strong> button in the Firebase settings panel. ShopHaat initiates a secure server-side handshake against Google's Identity Toolkit REST API (<code>identitytoolkit.googleapis.com</code>).
      </p>
      <ul>
        <li style="margin-bottom:6px"><strong>Success:</strong> Confirms the Web API Key is valid and the Email/Password authentication provider is active on the project.</li>
        <li style="margin-bottom:6px"><strong>Failure:</strong> Reports the exact Google API error code (e.g. <code>API_KEY_INVALID</code> or <code>OPERATION_NOT_ALLOWED</code>) so you can fix the project settings immediately.</li>
      </ul>
    </div>
  </section>

  <!-- Step 7: Architecture Rule: Link-based vs 6-Digit OTP -->
  <section class="sh-guide-card">
    <div class="sh-guide-card__header">
      <span class="sh-guide-card__num">7</span>
      <h2 class="sh-guide-card__title">Architecture Rule: Firebase Link Verification vs. 6-Digit OTP</h2>
    </div>
    <div class="sh-guide-card__body">
      <div class="sh-guide-callout sh-guide-callout--warn">
        <?= sh_icon('shield', 16) ?>
        <div>
          <strong>Important Architecture Distinction:</strong><br>
          Standard Google Firebase email verification is <strong>link-based</strong>. Firebase emails a unique action link containing an out-of-band verification token (<code>oobCode</code>) that the user clicks to confirm their email address. Firebase <em>does not</em> generate or deliver a custom 6-digit numeric OTP.
        </div>
      </div>
      <p>
        In ShopHaat:
      </p>
      <ul>
        <li style="margin-bottom:8px">
          <strong>If you require 6-digit numeric verification codes:</strong> Use <strong>Option A (Custom SMTP Server)</strong>. ShopHaat's backend generates the random 6-digit OTP, securely hashes it in the MySQL database, sends it through your SMTP server, and validates it when entered by the customer.
        </li>
        <li style="margin-bottom:8px">
          <strong>If Firebase is enabled:</strong> Customers who sign in through Firebase or Google OAuth have their identity verified by Firebase. ShopHaat validates the Firebase user on the server side and synchronizes their account record in MySQL without trusting client-side claims.
        </li>
      </ul>
    </div>
  </section>

  <!-- Step 8: Troubleshooting -->
  <section class="sh-guide-card">
    <div class="sh-guide-card__header">
      <span class="sh-guide-card__num">8</span>
      <h2 class="sh-guide-card__title">Common Errors and Troubleshooting</h2>
    </div>
    <div class="sh-guide-card__body">
      <div class="sh-tablewrap">
        <table class="sh-guide-table">
          <thead><tr><th>Error Code</th><th>Root Cause</th><th>Resolution</th></tr></thead>
          <tbody>
            <tr>
              <td><code>auth/unauthorized-domain</code></td>
              <td>Your website domain is not listed in Authorized Domains.</td>
              <td>Open Firebase Console &rarr; Authentication &rarr; Settings &rarr; Authorized domains, and add <code><?= e($siteDomain) ?></code>.</td>
            </tr>
            <tr>
              <td><code>auth/invalid-api-key</code></td>
              <td>The Web API Key was mistyped or deactivated.</td>
              <td>Verify the API Key in Firebase Console &rarr; Project settings &rarr; General &rarr; Web API Key.</td>
            </tr>
            <tr>
              <td><code>OPERATION_NOT_ALLOWED</code></td>
              <td>Email/Password sign-in method is disabled.</td>
              <td>Open Authentication &rarr; Sign-in method, select Email/Password, and toggle Enable to ON.</td>
            </tr>
            <tr>
              <td><code>Network / cURL timeout</code></td>
              <td>Web server firewall cannot reach Google APIs.</td>
              <td>Ensure outbound HTTPS traffic to <code>identitytoolkit.googleapis.com</code> (port 443) is permitted by your web hosting firewall.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div style="margin-top:20px;text-align:center">
        <a href="<?= e(sh_url('admin/email.php?tab=firebase')) ?>" class="sh-btn">
          <?= sh_icon('settings', 15) ?> Open Firebase Configuration in Admin &rarr;
        </a>
      </div>
    </div>
  </section>

</div>

<?php require dirname(__DIR__) . '/_footer.php'; ?>
