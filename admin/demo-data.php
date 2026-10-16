<?php
/**
 * Demo data review.
 *
 * Answers one question for the owner: "is any sample content from the installer
 * sitting on this store, and which rows exactly?". Read-only until the owner
 * types the confirmation phrase; customers, orders, payments, gateways,
 * administrators and settings are never candidates at any point.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/admin-perms.php';
// The manifest that defines what "sample content" means lives with the seeder, so
// the review screen and the installer can never disagree about it.
require_once SH_ROOT . '/install/demo-data.php';

sh_session_start();
$admin = sh_require_admin();
sh_require_perm('catalog.manage');
require_once SH_ROOT . '/includes/admin-tools.php';
require_once SH_ROOT . '/includes/seo.php';

$pdo = sh_db();
$confirmPhrase = 'DELETE SAMPLE DATA';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');

    if ($form === 'cleanup') {
        $typed = trim((string)sh_post('confirm_phrase'));
        $acked = !empty($_POST['acknowledge']);
        $inv   = sh_demo_inventory($pdo);
        $counts = [
            'products'   => count($inv['products']),
            'categories' => count($inv['categories']),
            'brands'     => count($inv['brands']),
            'coupons'    => count($inv['coupons']),
            'slides'     => count($inv['slides']),
        ];
        $total = array_sum($counts);

        // Least privilege per group that is actually present: removing a slide is
        // storefront work, removing a product is catalogue work.
        $missing = [];
        if ($counts['products'] > 0 && !sh_admin_can('products.delete')) { $missing[] = 'products.delete'; }
        if ($counts['slides'] > 0 && !sh_admin_can('storefront.manage')) { $missing[] = 'storefront.manage'; }

        if ($total === 0) {
            sh_flash('info', 'There is no sample content left to remove on this store.');
        } elseif ($missing) {
            sh_flash('error', 'Your role is missing the permission this removal needs: ' . implode(', ', $missing) . '. Nothing was changed.');
        } elseif ($typed !== $confirmPhrase || !$acked) {
            sh_flash('error', 'Nothing was removed. Type ' . $confirmPhrase . ' exactly and tick the acknowledgement box.');
        } else {
            try {
                $result = sh_demo_cleanup($pdo);
                sh_audit('settings_changed', 'settings', 1, 'demo_data_cleanup', null, $result);
                sh_log_line('admin', 'Sample content removed by ' . $admin['email'] . ': ' . json_encode($result));
                $msg = 'Removed ' . $result['products_deleted'] . ' sample product(s), '
                    . $result['categories_deleted'] . ' categor(ies), ' . $result['brands_deleted'] . ' brand(s), '
                    . $result['coupons_deleted'] . ' coupon(s), ' . $result['slides_deleted'] . ' homepage slide(s) and '
                    . $result['reviews_deleted'] . ' sample review(s).';
                if ($result['products_deactivated'] > 0) {
                    $msg .= ' ' . $result['products_deactivated'] . ' sample product(s) appear in order history, so they were'
                        . ' deactivated instead of deleted — the orders themselves are untouched.';
                }
                if ($result['coupons_disabled'] > 0) {
                    $msg .= ' ' . $result['coupons_disabled'] . ' sample coupon(s) were used on an order and were disabled instead of deleted.';
                }
                if ($result['kept'] > 0) {
                    $msg .= ' ' . $result['kept'] . ' categor(ies)/brand(s) still hold other products and were kept.';
                }
                sh_flash('success', $msg);
                sh_seo_cache_invalidate();
            } catch (Throwable $e) {
                sh_log_exception($e, 'demo-cleanup');
                sh_flash('error', 'The removal did not complete and was rolled back. Nothing was changed. See the error log for the details.');
            }
        }
        sh_redirect('admin/demo-data.php');
    }
}

$inv   = sh_demo_inventory($pdo);
$state = (string)$inv['state'];
$total = count($inv['products']) + count($inv['categories']) + count($inv['brands'])
       + count($inv['coupons']) + count($inv['slides']);

$stateCopy = [
    'installed' => 'The installer recorded that it installed the starter catalogue on this store.',
    'none'      => 'The installer recorded that this store was set up without the starter catalogue.',
    'cleaned'   => 'The starter catalogue was removed from this store by this screen.',
    ''          => 'No record was kept by the installer that set this store up, so the rows below were matched against the'
                   . ' bundled starter catalogue by their own slug, code or title. Review the list before removing anything.',
][$state] ?? '';

// Payment methods still carrying a demo wallet number (the review screen also
// covers the one piece of sample data that lives outside the manifest tables).
$demoAccounts = sh_demo_manifest()['demo_accounts'];
$demoMethodCount = 0;
foreach ($demoAccounts as $code => $number) {
    $demoMethodCount += (int)sh_val('SELECT COUNT(*) FROM payment_methods WHERE code = ? AND account_number = ?', [$code, $number], 0);
}

$groups = [
    ['Sample products', $inv['products'], 'products', 'products.delete'],
    ['Sample categories', $inv['categories'], 'categories', ''],
    ['Sample brands', $inv['brands'], 'brands', ''],
    ['Sample coupons', $inv['coupons'], 'coupons', ''],
    ['Homepage slides & feature cards', $inv['slides'], 'slides', 'storefront.manage'],
];
$groups = array_values(array_filter($groups, static fn(array $g): bool => (bool)$g[1]));

$adminPage  = 'demo_data';
$adminTitle = 'Demo Data Review';
require __DIR__ . '/_layout.php';
?>

<?= sh_admin_header(
    'Demo data review',
    'Sample content created by the installer, and a safe way to remove it.',
    '',
    'box'
) ?>

<?php if ($demoMethodCount > 0): ?>
  <div class="sh-panel" style="margin-bottom:14px">
    <div class="sh-panel__body">
      <div class="sh-alert sh-alert--warning"><?= sh_icon('alert', 17) ?>
        <div>
          <strong><?= (int)$demoMethodCount ?> payment method(s) still use a demo wallet number.</strong>
          These came from the starter catalogue and are not real merchant accounts. Replace them in
          <a href="<?= e(sh_url('admin/payment-methods.php')) ?>">Payment Methods</a> before you take live orders —
          a manual method with a blank number is hidden from checkout automatically.
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="sh-panel" style="margin-bottom:14px">
  <div class="sh-panel__body">
    <p class="sh-panel__note" style="margin-top:0">
      <strong>Where this store stands:</strong> <?= e($stateCopy) ?>
    </p>
    <?php if ($total === 0): ?>
      <div class="sh-alert sh-alert--success"><?= sh_icon('check-circle', 17) ?>
        <div>
          <strong>No sample content found.</strong>
          Nothing on this store matches the bundled starter catalogue, so there is nothing to review.
          Products, categories, orders and reviews were left exactly as they are.
        </div>
      </div>
    <?php else: ?>
      <p style="margin:0;font-size:13.5px">
        <strong><?= (int)$total ?></strong> record group entries match the starter catalogue.
        Review them below before removing anything.
      </p>
    <?php endif; ?>
  </div>
</div>

<?php foreach ($groups as [$title, $rows, $key, $perm]): ?>
  <div class="sh-panel" style="margin-bottom:14px">
    <div class="sh-panel__head">
      <h2 class="sh-panel__title"><?= e($title) ?></h2>
      <span class="sh-panel__note"><?= count($rows) ?> matched</span>
    </div>
    <div class="sh-panel__body">
      <?php if ($perm !== '' && !sh_admin_can($perm)): ?>
        <div class="sh-alert sh-alert--warning"><?= sh_icon('lock', 17) ?>
          <div>Your role cannot remove these records (missing <code><?= e($perm) ?></code>). They will be left in place.</div>
        </div>
      <?php endif; ?>
      <div class="sh-tablewrap sh-tablewrap--cards">
        <table class="sh-table">
          <thead><tr><th>Name</th><th>What will happen</th></tr></thead>
          <tbody>
          <?php foreach (array_slice($rows, 0, 60) as $row): ?>
            <tr>
              <td data-label="Name">
                <?= e((string)$row['label']) ?>
                <span class="sh-badge sh-badge--muted">#<?= (int)$row['id'] ?></span>
              </td>
              <td data-label="What will happen"><?= e((string)$row['detail']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if (count($rows) > 60): ?>
        <p class="sh-panel__note">Showing the first 60 of <?= count($rows) ?> records.</p>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>

<?php if ($inv['reviews'] > 0): ?>
  <div class="sh-panel" style="margin-bottom:14px">
    <div class="sh-panel__body">
      <p class="sh-panel__note" style="margin:0">
        <strong><?= (int)$inv['reviews'] ?> sample review(s)</strong> were written by the installer for the sample products
        above. They are removed together with those products. A review left by a real customer — on any product, sample or
        not — does not match the bundled review text and is never removed.
      </p>
    </div>
  </div>
<?php endif; ?>

<div class="sh-panel">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title">Remove sample content</h2>
  </div>
  <div class="sh-panel__body">
    <p style="margin:0 0 10px;font-size:13.5px">
      This is the only action on this screen that changes anything, and it cannot be undone. Tick the box and type the
      phrase below to run it. The removal runs inside a single database transaction: if any step fails, nothing is changed.
    </p>
    <ul style="margin:0 0 14px;padding-left:20px;font-size:13px;color:#3a4152">
      <li>Sample products with order history are <strong>deactivated, not deleted</strong> — their order lines keep the saved name, price and quantity.</li>
      <li>Sample categories and brands that still hold other products are <strong>kept</strong>.</li>
      <li>Sample coupons used on a real order are <strong>disabled, not deleted</strong>.</li>
      <li>Customers, orders, payments, gateways, administrators, settings and reports are <strong>never touched</strong>.</li>
      <li>Homepage slider slides and feature cards come from the same starter catalogue and are removed with it.</li>
    </ul>
    <?php if ($total === 0): ?>
      <p class="sh-panel__note" style="margin:0">There is nothing to remove on this store.</p>
    <?php else: ?>
      <form method="post" novalidate action="<?= e(sh_url('admin/demo-data.php')) ?>">
        <?= sh_csrf_field() ?>
        <input type="hidden" name="form" value="cleanup">
        <label class="sh-toggle" style="margin-bottom:12px">
          <input type="checkbox" name="acknowledge" value="1">
          <span class="sh-toggle__track"></span>
          <span>I have reviewed the records above and I want to remove the sample content from this store.</span>
        </label>
        <div class="sh-field" style="max-width:340px">
          <label class="sh-field__label" for="confirm_phrase">Type <code><?= e($confirmPhrase) ?></code> to confirm</label>
          <input class="sh-input" id="confirm_phrase" name="confirm_phrase" autocomplete="off" spellcheck="false" placeholder="<?= e($confirmPhrase) ?>">
        </div>
        <div class="sh-actions">
          <button class="sh-btn sh-btn--bad" type="submit"><?= sh_icon('trash', 14) ?> Remove sample content</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
