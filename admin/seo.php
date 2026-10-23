<?php
/**
 * Admin — SEO Manager.
 *
 * One page that owns every store-wide search-engine setting: whether the SEO
 * system is on, the default title and description, the canonical domain, the
 * social and organisation details used in structured data, the search-engine
 * verification values, and the per-page overrides for pages that have no
 * database row of their own (homepage, About, Terms, Help …).
 *
 * Everything is stored in the existing `settings` table, so changes appear in
 * Settings History automatically and no new table is needed. Access is limited
 * to administrators with settings.edit or the dedicated seo.manage permission.
 */

declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/seo.php';
sh_seo_schema_ensure();

sh_session_start();
sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('seo.manage', 'settings.edit');

$errors = [];
$notice = '';
$canEdit = sh_admin_can('settings.edit', 'seo.manage');

/** Turn a checkbox into a 1/0 string. */
$flag = static fn(string $key): string => !empty($_POST[$key]) ? '1' : '0';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = (string)sh_post('form');

    if (!$canEdit) {
        $errors[] = 'Your role can view the SEO settings but not change them.';
    } elseif ($form === 'settings') {
        // ---- text fields -------------------------------------------------
        // Lengths match the columns/keys these values are stored in. A 0 means
        // "editorial copy": it is stored in the settings TEXT column exactly as
        // written, because a default description has no target length.
        $limits = [
            'seo_title_suffix'      => 60,
            'seo_default_description' => 0,
            'seo_default_keywords'  => 300,
            'seo_lang'              => 10,
            'seo_locale'            => 20,
            'seo_twitter_site'      => 20,
            'seo_org_name'          => 190,
            'seo_org_phone'         => 40,
            'seo_verify_google'     => 200,
            'seo_verify_bing'       => 200,
            'seo_verify_pinterest'  => 200,
            'seo_ga_measurement_id' => 40,
        ];
        foreach ($limits as $key => $max) {
            $value = trim((string)sh_post($key));
            sh_seo_save($key, $max > 0 ? mb_substr($value, 0, $max) : $value);
        }

        // ---- URLs ---------------------------------------------------------
        // Anything that is not an absolute http(s) URL or a site path is
        // refused outright rather than stored and ignored, so the owner gets
        // told instead of wondering why a value does not appear.
        $urlFields = [
            'seo_canonical_base'  => 'Canonical base URL',
            'seo_default_image'   => 'Default SEO image',
            'seo_org_logo'        => 'Organisation logo URL',
            'seo_social_facebook' => 'Facebook profile',
            'seo_social_instagram' => 'Instagram profile',
            'seo_social_youtube'  => 'YouTube channel',
            'seo_social_tiktok'   => 'TikTok profile',
            'seo_social_linkedin' => 'LinkedIn page',
        ];
        foreach ($urlFields as $key => $label) {
            $raw = trim((string)sh_post($key));
            if ($raw === '') { sh_seo_save($key, ''); continue; }
            // Images may also be a path inside this site, e.g. uploads/logos/x.png.
            $cleaned = sh_seo_clean_url($raw);
            if ($cleaned === '') {
                $errors[] = $label . ': enter a full https:// web address, or a path that starts with "/".';
                continue;
            }
            if ($key === 'seo_canonical_base' && !str_starts_with($cleaned, 'https://')) {
                $errors[] = 'Canonical base URL: use https:// — search engines treat http and https as different sites.';
                continue;
            }
            // Profile links should leave this site, so a relative path is wrong.
            if (str_starts_with($key, 'seo_social_') && str_starts_with($cleaned, '/')) {
                $errors[] = $label . ': a social profile is a full https:// address, not a path on this site.';
                continue;
            }
            sh_seo_save($key, mb_substr($cleaned, 0, 255));
        }

        // Verification values become HTML attribute content; keep them to the
        // characters the search engines actually issue.
        foreach (['seo_verify_google', 'seo_verify_bing', 'seo_verify_pinterest'] as $key) {
            $v = trim((string)sh_post($key));
            if ($v !== '' && preg_match('/^[A-Za-z0-9_\-.:]{10,200}$/', $v) !== 1) {
                $errors[] = 'A verification value contains unexpected characters. Paste only the value, not the whole meta tag.';
                sh_seo_save($key, '');
            }
        }

        // The organisation email is the only email field; validate it.
        $orgEmail = trim((string)sh_post('seo_org_email'));
        if ($orgEmail !== '' && !filter_var($orgEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Organisation email is not a valid email address.';
        } else {
            sh_seo_save('seo_org_email', mb_substr($orgEmail, 0, 190));
        }

        // ---- counts and switches -----------------------------------------
        $perFile = max(500, min(20000, sh_int(sh_post('seo_sitemap_products'), 2000)));
        sh_seo_save('seo_sitemap_products', (string)$perFile);
        $cacheMin = max(5, min(1440, sh_int(sh_post('seo_sitemap_cache_min'), 30)));
        sh_seo_save('seo_sitemap_cache_min', (string)$cacheMin);

        foreach (['seo_enabled', 'seo_og_enabled', 'seo_twitter_enabled', 'seo_sitemap_enabled'] as $key) {
            sh_seo_save($key, $flag($key));
        }

        $robots = sh_seo_normalise_robots((string)sh_post('seo_default_robots'));
        sh_seo_save('seo_default_robots', $robots);

        if (!$errors) { $notice = 'SEO settings saved.'; }
        sh_audit('seo_settings_saved', 'setting', null, 'SEO Manager', null, ['sitemap_per_file' => $perFile]);
        sh_seo_cache_clear();
    } elseif ($form === 'page') {
        // ---- per-page override -------------------------------------------
        $key = (string)sh_post('page_key');
        $catalog = sh_seo_pages_catalog();
        if (!isset($catalog[$key])) {
            $errors[] = 'That page cannot be configured here.';
        } else {
            $canonical = trim((string)sh_post('seo_canonical'));
            $cleanCanonical = $canonical === '' ? '' : sh_seo_clean_url($canonical);
            if ($canonical !== '' && $cleanCanonical === '') {
                $errors[] = 'The canonical URL must be a full https:// address or a path starting with "/".';
            }
            $ogImage = trim((string)sh_post('seo_og_image'));
            $cleanImage = $ogImage === '' ? '' : sh_seo_clean_url($ogImage);
            if ($ogImage !== '' && $cleanImage === '') {
                $errors[] = 'The sharing image must be a full https:// address or a path starting with "/".';
            }

            if (!$errors) {
                try {
                    sh_query(
                        'INSERT INTO seo_pages (page_key, meta_title, meta_description, meta_keywords,
                                                seo_og_image, seo_canonical, seo_noindex)
                         VALUES (?,?,?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE
                            meta_title = VALUES(meta_title), meta_description = VALUES(meta_description),
                            meta_keywords = VALUES(meta_keywords), seo_og_image = VALUES(seo_og_image),
                            seo_canonical = VALUES(seo_canonical), seo_noindex = VALUES(seo_noindex)',
                        [
                            $key,
                            trim((string)sh_post('meta_title')) ?: null,
                            trim((string)sh_post('meta_description')) ?: null,
                            mb_substr(trim((string)sh_post('meta_keywords')), 0, 300) ?: null,
                            mb_substr($cleanImage, 0, 190) ?: null,
                            mb_substr($cleanCanonical, 0, 255) ?: null,
                            !empty($_POST['seo_noindex']) ? 1 : 0,
                        ]
                    );
                    $notice = 'Saved the search listing for ' . (string)($catalog[$key]['label'] ?? $key) . '.';
                    sh_audit('seo_page_saved', 'setting', null, (string)($catalog[$key]['label'] ?? $key), null, ['page_key' => $key]);
                    sh_seo_cache_clear();
                } catch (Throwable $e) {
                    sh_log_exception($e, 'seo-page-save');
                    $errors[] = 'The page settings could not be saved.';
                }
            }
        }
    } elseif ($form === 'write_robots') {
        // ---- optional static file ----------------------------------------
        // Some hosts serve a physical file before the rewrite rules run. An
        // owner who needs that can write one from the current settings; it is a
        // snapshot, so it must be rewritten after changing the canonical domain.
        $body = sh_seo_robots_txt();
        $target = SH_ROOT . '/robots.txt';
        if (@file_put_contents($target, $body) === false) {
            $errors[] = 'robots.txt could not be written. The folder may not be writable; the dynamic /robots.txt still works.';
        } else {
            $notice = 'A static robots.txt was written from the current settings. Re-write it after changing the canonical domain.';
        }
    }
}

$s = static fn(string $k, string $fb = ''): string => sh_seo_get($k, $fb);
$catalog = sh_seo_pages_catalog();
$editKey = (string)sh_get('page');
if (!isset($catalog[$editKey])) {
    $editKey = array_key_first($catalog);
}
$pageRows = [];
try {
    foreach (sh_all('SELECT * FROM seo_pages') as $r) { $pageRows[(string)$r['page_key']] = $r; }
} catch (Throwable $e) {
    sh_log_exception($e, 'seo-pages-load');
}
$editRow = $pageRows[$editKey] ?? [];

// A preview built from the values that are actually saved right now.
$previewMeta = sh_seo_meta([
    'key' => $editKey,
    'fallback_title' => '',
    'fallback_desc' => '',
    'canonical' => '/',
]);

// What the crawler will really be served, read from the cache the sitemap
// generator writes: the chunk count and the time of the last successful build.
$sitemapChunks = 0;
$cachedCount = sh_seo_cache_read('seo-sitemap-count', 86400 * 30);
if ($cachedCount !== null) { $sitemapChunks = max(0, (int)$cachedCount); }
$sitemapBuiltAt = sh_seo_cache_time('seo-sitemap-count');
$robotsStatic = is_file(SH_ROOT . '/robots.txt');
$staticRobotsAge = $robotsStatic ? (int)filemtime(SH_ROOT . '/robots.txt') : 0;

$adminPage = 'seo';
$adminTitle = 'SEO Manager';
$adminScripts = ['seo' => 'assets/js/seo.js'];
require __DIR__ . '/_layout.php';
?>
<?php if ($errors): ?>
  <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 17) ?>
    <div><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>
<?php if ($notice !== ''): ?>
  <div class="sh-alert sh-alert--success"><?= sh_icon('check-circle', 17) ?><span><?= e($notice) ?></span></div>
<?php endif; ?>

<div class="sh-panel">
  <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('search', 17) ?> How this works</h2></div>
  <div class="sh-panel__body">
    <p class="sh-panel__note" style="margin-bottom:8px">
      ShopHaat now writes one set of search-engine tags per page: title, description, canonical URL, robots
      directive, social sharing cards and structured data. Product and category pages pull their own values from
      the fields on their edit forms; the pages listed further down (homepage, About, Terms …) have no database row
      of their own, so their text is edited here.
    </p>
    <p class="sh-panel__note">
      <strong>No setting here guarantees a ranking.</strong> Search engines decide what to index and where it
      appears. What these settings control is whether the site is technically readable, crawlable and honest
      about itself — which is the part that can be configured correctly.
    </p>
  </div>
</div>

<?php
// ---- Health check --------------------------------------------------------
// Real counts read from the catalogue, plus honest notes about what is missing.
// Levels are colour-coded but nothing here blocks saving: an SEO warning is
// never a reason to stop an owner from editing their own store.
$audit = sh_seo_audit(!empty($_GET['audit']));
$levelClass = ['error' => 'sh-alert--error', 'warning' => 'sh-alert--warning', 'info' => 'sh-alert--info'];
$counts = $audit['counts'];
?>
<div class="sh-panel">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title"><?= sh_icon('trending-up', 17) ?> Health check</h2>
    <a class="sh-btn sh-btn--ghost sh-btn--sm" href="<?= e(sh_url('admin/seo.php?audit=deep')) ?>">
      <?= sh_icon('refresh', 14) ?> Deep recheck
    </a>
  </div>
  <div class="sh-panel__body">
    <div class="sh-stats sh-stats--4">
      <div class="sh-stat"><span class="sh-stat__icon sh-stat__icon--green"><?= sh_icon('box', 18) ?></span>
        <div><p class="sh-stat__value"><?= (int)$counts['products_indexable'] ?></p><p class="sh-stat__label">Indexable products</p></div></div>
      <div class="sh-stat"><span class="sh-stat__icon sh-stat__icon--amber"><?= sh_icon('eye', 18) ?></span>
        <div><p class="sh-stat__value"><?= (int)$counts['products_hidden'] ?></p><p class="sh-stat__label">Hidden products</p></div></div>
      <div class="sh-stat"><span class="sh-stat__icon sh-stat__icon--blue"><?= sh_icon('grid', 18) ?></span>
        <div><p class="sh-stat__value"><?= (int)$counts['categories_indexable'] ?></p><p class="sh-stat__label">Indexable categories</p></div></div>
      <div class="sh-stat"><span class="sh-stat__icon sh-stat__icon--amber"><?= sh_icon('file-text', 18) ?></span>
        <div><p class="sh-stat__value"><?= (int)$counts['products_missing_desc'] ?></p><p class="sh-stat__label">Products with no description</p></div></div>
    </div>
    <p class="sh-panel__note" style="margin:10px 0 12px">
      <?php if (!empty($_GET['audit'])): ?>
        Deep recheck ran just now: it also validates saved canonical URLs and looks for products with no image.
      <?php else: ?>
        These are counts only. Use <strong>Deep recheck</strong> to also validate saved canonical URLs and find products with no image.
      <?php endif; ?>
      Products excluded from indexing: <?= (int)$counts['products_noindex'] ?> ·
      categories excluded: <?= (int)$counts['categories_noindex'] ?> ·
      pages excluded here: <?= (int)$counts['pages_noindex'] ?>
      <?php if (isset($counts['posts_indexable']) && (int)$counts['posts_indexable'] > 0): ?>
        · published blog posts: <?= (int)$counts['posts_indexable'] ?>
      <?php endif; ?>
    </p>
    <?php if (!$audit['issues']): ?>
      <div class="sh-alert sh-alert--success"><?= sh_icon('check-circle', 16) ?>
        <span>No problems found in the checks that ran.</span></div>
    <?php else: ?>
      <?php foreach ($audit['issues'] as $issue): ?>
        <div class="sh-alert <?= e($levelClass[(string)$issue['level']] ?? 'sh-alert--info') ?>" style="margin-bottom:8px">
          <?= sh_icon((string)$issue['level'] === 'error' ? 'x-circle' : 'alert', 16) ?>
          <span><?= e((string)$issue['message']) ?></span>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
    <?php if ($audit['duplicates']['titles'] || $audit['duplicates']['descriptions']): ?>
      <p class="sh-panel__note" style="margin-top:10px">
        <strong>Repeated text will not be listed twice:</strong>
        <?= count($audit['duplicates']['titles']) ?> product title(s) and
        <?= count($audit['duplicates']['descriptions']) ?> product description(s) are shared by more than one
        product. Search engines may treat those pages as near-duplicates and show only one.
      </p>
    <?php endif; ?>
    <div class="sh-panel__note" style="margin-top:10px;border-top:1px solid var(--sh-line);padding-top:10px">
      <strong>Setting up Google Search Console (free, and the only way to see what Google actually indexed):</strong>
      <ol style="margin:8px 0 0 18px;line-height:1.7">
        <li>Go to <span>search.google.com/search-console</span> and sign in with the store's Google account.</li>
        <li>Choose <em>URL prefix</em> and enter <code><?= e(sh_seo_base()) ?>/</code> exactly, including <code>https://</code>.</li>
        <li>Pick the <em>HTML tag</em> verification method and copy only the value inside <code>content="…"</code>.</li>
        <li>Paste it into <strong>Google Search Console</strong> above, save, then press Verify in Google.</li>
        <li>When it verifies, use <em>Sitemaps</em> and submit <code>sitemap.xml</code>.</li>
        <li>Use <em>URL Inspection</em> on a product page to confirm Google sees what you expect.</li>
      </ol>
      <p style="margin:8px 0 0">
        Indexing is not instant. A new store can take days or weeks to appear, and a search engine may still
        choose to ignore any page. Nothing on this screen can force it to.
      </p>
    </div>
  </div>
</div>

<form method="post" novalidate>
  <?= sh_csrf_field() ?>
  <input type="hidden" name="form" value="settings">

  <div class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('sliders', 17) ?> System</h2></div>
    <div class="sh-panel__body">
      <label class="sh-check">
        <input type="checkbox" name="seo_enabled" value="1" <?= $s('seo_enabled', '1') === '1' ? 'checked' : '' ?>>
        <span>SEO system enabled
          <span class="sh-field__hint" style="display:block">
            When off, pages keep their previous titles and no social or structured-data tags are added.
          </span></span>
      </label>
      <label class="sh-check">
        <input type="checkbox" name="seo_og_enabled" value="1" <?= $s('seo_og_enabled', '1') === '1' ? 'checked' : '' ?>>
        <span>Open Graph tags (Facebook, WhatsApp, Messenger, LinkedIn)</span>
      </label>
      <label class="sh-check">
        <input type="checkbox" name="seo_twitter_enabled" value="1" <?= $s('seo_twitter_enabled', '1') === '1' ? 'checked' : '' ?>>
        <span>Twitter / X card tags</span>
      </label>
    </div>
  </div>

  <div class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('type', 17) ?> Defaults</h2></div>
    <div class="sh-panel__body">
      <p class="sh-panel__note" style="margin-bottom:12px">
        Used when a page supplies nothing of its own. Product and category pages build their values from real
        product data before ever falling back to these.
      </p>
      <div class="sh-grid2">
        <div class="sh-field">
          <label class="sh-field__label" for="seo-title-suffix">Title suffix</label>
          <input class="sh-input" id="seo-title-suffix" name="seo_title_suffix" maxlength="60"
                 value="<?= e($s('seo_title_suffix')) ?>" placeholder=" | <?= e((string)sh_setting('site_name', 'ShopHaat')) ?>">
          <span class="sh-field__hint">Appended to a page title that does not already name the store. Empty uses “ | <?= e((string)sh_setting('site_name', 'ShopHaat')) ?>”.</span>
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="seo-default-robots">Default robots directive</label>
          <select class="sh-select" id="seo-default-robots" name="seo_default_robots">
            <?php foreach (['index,follow' => 'index,follow — normal (recommended)', 'index,nofollow' => 'index,nofollow — list the page, do not follow its links', 'noindex,follow' => 'noindex,follow — keep out of the index, follow its links'] as $val => $label): ?>
              <option value="<?= e($val) ?>" <?= $s('seo_default_robots', 'index,follow') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="sh-field__hint">Applies to normal public pages. Private pages set their own value.</span>
        </div>
      </div>
      <div class="sh-field">
        <label class="sh-field__label" for="seo-default-desc">Default meta description</label>
        <textarea class="sh-textarea" id="seo-default-desc" name="seo_default_description" rows="3"
                  placeholder="<?= e((string)sh_setting('site_description', 'Leave empty to use the store description from General Settings')) ?>"><?= e($s('seo_default_description')) ?></textarea>
        <span class="sh-field__hint">No character limit. Search engines may shorten the snippet they show.</span>
      </div>
      <div class="sh-field">
        <label class="sh-field__label" for="seo-default-kw">Default keywords</label>
        <input class="sh-input" id="seo-default-kw" name="seo_default_keywords" maxlength="300" value="<?= e($s('seo_default_keywords')) ?>">
        <span class="sh-field__hint">
          <strong>Google does not use the meta keywords tag for ranking.</strong> This is kept for internal
          organisation and for older engines; it will not bring in traffic on its own.
        </span>
      </div>
      <div class="sh-grid2">
        <div class="sh-field">
          <label class="sh-field__label" for="seo-lang">Default language</label>
          <input class="sh-input" id="seo-lang" name="seo_lang" maxlength="10" value="<?= e($s('seo_lang', 'en')) ?>" placeholder="en">
          <span class="sh-field__hint">Written as the <code>lang</code> attribute of the page.</span>
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="seo-locale">Open Graph locale</label>
          <input class="sh-input" id="seo-locale" name="seo_locale" maxlength="20" value="<?= e($s('seo_locale', 'en_US')) ?>" placeholder="en_US">
        </div>
      </div>
    </div>
  </div>

  <div class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('external', 17) ?> Canonical domain and images</h2></div>
    <div class="sh-panel__body">
      <div class="sh-field">
        <label class="sh-field__label" for="seo-base">Canonical base URL</label>
        <input class="sh-input" id="seo-base" name="seo_canonical_base" maxlength="255"
               value="<?= e($s('seo_canonical_base')) ?>" placeholder="https://yourdomain.com">
        <span class="sh-field__hint">
          The single preferred address of the store. Every canonical link, sitemap URL and social tag is built
          from it. Empty falls back to the site URL in General Settings.
          <?php if ($s('seo_canonical_base') === ''): ?>
            <br><strong>Currently in use:</strong> <code><?= e(sh_seo_base()) ?></code>
          <?php endif; ?>
        </span>
      </div>
      <div class="sh-grid2">
        <div class="sh-field">
          <label class="sh-field__label" for="seo-img">Default SEO / sharing image</label>
          <input class="sh-input" id="seo-img" name="seo_default_image" maxlength="255"
                 value="<?= e($s('seo_default_image')) ?>" placeholder="/uploads/logos/your-image.png">
          <span class="sh-field__hint">Used when a page has no image of its own. About 1200×630 px works everywhere.</span>
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="seo-twitter">X (Twitter) handle</label>
          <input class="sh-input" id="seo-twitter" name="seo_twitter_site" maxlength="20"
                 value="<?= e($s('seo_twitter_site')) ?>" placeholder="@yourstore">
        </div>
      </div>
    </div>
  </div>

  <div class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('box', 17) ?> Organisation details</h2></div>
    <div class="sh-panel__body">
      <p class="sh-panel__note" style="margin-bottom:12px">
        These feed the structured data that describes the business. Leave a field empty and it is simply omitted —
        nothing is guessed on your behalf.
      </p>
      <div class="sh-grid2">
        <div class="sh-field">
          <label class="sh-field__label" for="seo-org-name">Organisation name</label>
          <input class="sh-input" id="seo-org-name" name="seo_org_name" maxlength="190"
                 value="<?= e($s('seo_org_name')) ?>" placeholder="<?= e((string)sh_setting('site_name', 'ShopHaat')) ?>">
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="seo-org-logo">Logo URL</label>
          <input class="sh-input" id="seo-org-logo" name="seo_org_logo" maxlength="255"
                 value="<?= e($s('seo_org_logo')) ?>" placeholder="https://yourdomain.com/uploads/logos/logo.png">
          <span class="sh-field__hint">Empty falls back to the store logo in General Settings.</span>
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="seo-org-email">Contact email</label>
          <input class="sh-input" id="seo-org-email" type="email" name="seo_org_email" maxlength="190"
                 value="<?= e($s('seo_org_email')) ?>" placeholder="support@yourdomain.com">
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="seo-org-phone">Contact phone</label>
          <input class="sh-input" id="seo-org-phone" name="seo_org_phone" maxlength="40"
                 value="<?= e($s('seo_org_phone')) ?>" placeholder="+8801XXXXXXXXX">
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="seo-fb">Facebook page</label>
          <input class="sh-input" id="seo-fb" name="seo_social_facebook" maxlength="255" value="<?= e($s('seo_social_facebook')) ?>" placeholder="https://facebook.com/yourpage">
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="seo-ig">Instagram profile</label>
          <input class="sh-input" id="seo-ig" name="seo_social_instagram" maxlength="255" value="<?= e($s('seo_social_instagram')) ?>" placeholder="https://instagram.com/yourstore">
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="seo-yt">YouTube channel</label>
          <input class="sh-input" id="seo-yt" name="seo_social_youtube" maxlength="255" value="<?= e($s('seo_social_youtube')) ?>" placeholder="https://youtube.com/@yourstore">
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="seo-tt">TikTok profile</label>
          <input class="sh-input" id="seo-tt" name="seo_social_tiktok" maxlength="255" value="<?= e($s('seo_social_tiktok')) ?>" placeholder="https://tiktok.com/@yourstore">
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="seo-li">LinkedIn page</label>
          <input class="sh-input" id="seo-li" name="seo_social_linkedin" maxlength="255" value="<?= e($s('seo_social_linkedin')) ?>" placeholder="https://linkedin.com/company/yourstore">
        </div>
      </div>
    </div>
  </div>

  <div class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('check-circle', 17) ?> Search engine verification</h2></div>
    <div class="sh-panel__body">
      <p class="sh-panel__note" style="margin-bottom:12px">
        Paste only the <em>value</em> from the verification tag each service gives you — not the whole
        <code>&lt;meta&gt;</code> element. These tags appear in the page head only when a value is saved here.
      </p>
      <div class="sh-grid2">
        <div class="sh-field">
          <label class="sh-field__label" for="seo-vg">Google Search Console</label>
          <input class="sh-input" id="seo-vg" name="seo_verify_google" maxlength="200"
                 value="<?= e($s('seo_verify_google')) ?>" placeholder="e.g. AbC123…xyz">
          <span class="sh-field__hint">Google Search Console → Settings → Ownership verification → HTML tag.</span>
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="seo-vb">Bing Webmaster Tools</label>
          <input class="sh-input" id="seo-vb" name="seo_verify_bing" maxlength="200"
                 value="<?= e($s('seo_verify_bing')) ?>" placeholder="msvalidate.01 value">
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="seo-vp">Pinterest</label>
          <input class="sh-input" id="seo-vp" name="seo_verify_pinterest" maxlength="200"
                 value="<?= e($s('seo_verify_pinterest')) ?>" placeholder="p:domain_verify value">
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="seo-ga">Google Analytics measurement ID (optional)</label>
          <input class="sh-input" id="seo-ga" name="seo_ga_measurement_id" maxlength="40"
                 value="<?= e($s('seo_ga_measurement_id')) ?>" placeholder="G-XXXXXXXXXX">
          <span class="sh-field__hint">Stored for reference. Adding a tracking script is a separate step, so nothing is injected automatically.</span>
        </div>
      </div>
    </div>
  </div>

  <div class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('file-text', 17) ?> Sitemap and robots.txt</h2></div>
    <div class="sh-panel__body">
      <label class="sh-check" style="margin-bottom:12px">
        <input type="checkbox" name="seo_sitemap_enabled" value="1" <?= $s('seo_sitemap_enabled', '1') === '1' ? 'checked' : '' ?>>
        <span>Publish <code>/sitemap.xml</code></span>
      </label>
      <div class="sh-grid2">
        <div class="sh-field">
          <label class="sh-field__label" for="seo-per-file">URLs per sitemap file</label>
          <input class="sh-input" id="seo-per-file" type="number" name="seo_sitemap_products" min="500" max="20000"
                 value="<?= e($s('seo_sitemap_products', '2000')) ?>">
          <span class="sh-field__hint">
            When the catalogue needs more than one file, an index is served at <code>/sitemap.xml</code> that points
            at <code>/sitemap-1.xml</code>, <code>/sitemap-2.xml</code> … Search engines allow 50,000 URLs per file.
          </span>
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="seo-cache-min">Rebuild the cache after (minutes)</label>
          <input class="sh-input" id="seo-cache-min" type="number" name="seo_sitemap_cache_min" min="5" max="1440"
                 value="<?= e($s('seo_sitemap_cache_min', '30')) ?>">
          <span class="sh-field__hint">
            The sitemap is cached so a crawler never triggers a heavy query. Saving a product or category clears
            the cache immediately, so this is only an upper bound.
          </span>
        </div>
      </div>
      <p class="sh-panel__note" style="margin:10px 0 0">
        <strong>Sitemap:</strong> <a href="<?= e(sh_url('sitemap.xml')) ?>" target="_blank" rel="noopener"><?= e(sh_seo_base()) ?>/sitemap.xml</a><br>
        <strong>robots.txt:</strong> <a href="<?= e(sh_url('robots.txt')) ?>" target="_blank" rel="noopener"><?= e(sh_seo_base()) ?>/robots.txt</a>
        <?php if ($sitemapBuiltAt !== null): ?>
          <br><strong>Last built:</strong> <?= e(date('d M Y H:i', $sitemapBuiltAt)) ?> —
          <?= $sitemapChunks === 1
                ? 'one file of URLs'
                : ($sitemapChunks > 1
                    ? (int)$sitemapChunks . ' files, listed through an index at /sitemap.xml'
                    : 'nothing indexable was published, so the sitemap is currently empty') ?>
        <?php else: ?>
          <br><strong>Not built yet.</strong> Open the sitemap link above once to generate it, then reload this page.
        <?php endif; ?>
        <?php if ($robotsStatic): ?>
          <br><span style="color:#1a7f4b">A static robots.txt file exists (written <?= e(date('d M Y H:i', $staticRobotsAge)) ?>).
          It is served directly and takes priority over the generated one.</span>
        <?php endif; ?>
      </p>
    </div>
  </div>

  <?php if ($canEdit): ?>
    <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Save SEO settings</button>
  <?php else: ?>
    <p class="sh-panel__note">Your role can view these settings but not change them.</p>
  <?php endif; ?>
</form>

<!-- Preview of the settings as saved right now ----------------------------- -->
<div class="sh-panel">
  <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('eye', 17) ?> Preview: <?= e((string)($catalog[$editKey]['label'] ?? $editKey)) ?></h2></div>
  <div class="sh-panel__body">
    <div style="border:1px solid var(--sh-line);border-radius:9px;padding:12px 14px;background:#fff;max-width:620px">
      <div style="font-size:12px;color:#202124"><?= e($previewMeta['canonical'] !== '' ? $previewMeta['canonical'] : sh_seo_base() . '/') ?></div>
      <div style="font-size:17px;line-height:1.3;color:#1a0dab;margin:3px 0 4px"><?= e((string)$previewMeta['title']) ?></div>
      <div style="font-size:13px;line-height:1.5;color:#4d5156"><?= e((string)$previewMeta['description']) ?></div>
    </div>
    <p class="sh-panel__note" style="margin-top:8px">
      Robots <code><?= e((string)$previewMeta['robots']) ?></code>
      <?php if ($previewMeta['image'] !== ''): ?> · sharing image <code><?= e((string)$previewMeta['image']) ?></code><?php endif; ?>
    </p>
  </div>
</div>

<!-- Per-page overrides ----------------------------------------------------- -->
<form method="post" novalidate>
  <?= sh_csrf_field() ?>
  <input type="hidden" name="form" value="page">
  <div class="sh-panel">
    <div class="sh-panel__head">
      <h2 class="sh-panel__title"><?= sh_icon('layout', 17) ?> Page search listings</h2>
    </div>
    <div class="sh-panel__body">
      <p class="sh-panel__note" style="margin-bottom:12px">
        These pages are built by the application rather than stored in the database, so their titles and
        descriptions are edited here. Product and category pages are edited on their own forms instead — they
        already carry these fields.
      </p>
      <div class="sh-field" style="max-width:420px">
        <label class="sh-field__label" for="seo-page-key">Page</label>
        <select class="sh-select" id="seo-page-key" name="page_key" data-seo-page-picker>
          <?php foreach ($catalog as $k => $info): ?>
            <option value="<?= e($k) ?>" <?= $k === $editKey ? 'selected' : '' ?>>
              <?= e((string)$info['label']) ?><?= empty($info['indexable']) ? ' — not in the sitemap' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
        <span class="sh-field__hint">Switching pages reloads the form with that page's saved values.</span>
      </div>

      <div class="sh-field">
        <label class="sh-field__label" for="seo-page-title">SEO title</label>
        <input class="sh-input" id="seo-page-title" name="meta_title"
               value="<?= e((string)($editRow['meta_title'] ?? '')) ?>"
               placeholder="Leave empty to generate from the page">
      </div>
      <div class="sh-field">
        <label class="sh-field__label" for="seo-page-desc">Meta description</label>
        <textarea class="sh-textarea" id="seo-page-desc" name="meta_description" rows="3"
                  placeholder="Leave empty to generate from the page"><?= e((string)($editRow['meta_description'] ?? '')) ?></textarea>
      </div>
      <div class="sh-grid2">
        <div class="sh-field">
          <label class="sh-field__label" for="seo-page-kw">Keywords</label>
          <input class="sh-input" id="seo-page-kw" name="meta_keywords" maxlength="300"
                 value="<?= e((string)($editRow['meta_keywords'] ?? '')) ?>">
          <span class="sh-field__hint">Organisation only — not a ranking factor.</span>
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="seo-page-img">Sharing image</label>
          <input class="sh-input" id="seo-page-img" name="seo_og_image" maxlength="190"
                 value="<?= e((string)($editRow['seo_og_image'] ?? '')) ?>" placeholder="/uploads/… or https://…">
        </div>
      </div>
      <div class="sh-field">
        <label class="sh-field__label" for="seo-page-canon">Canonical URL (optional)</label>
        <input class="sh-input" id="seo-page-canon" name="seo_canonical" maxlength="255"
               value="<?= e((string)($editRow['seo_canonical'] ?? '')) ?>" placeholder="Leave empty to use the page's own URL">
      </div>
      <label class="sh-check" style="margin:6px 0 12px">
        <input type="checkbox" name="seo_noindex" value="1" <?= (int)($editRow['seo_noindex'] ?? 0) === 1 ? 'checked' : '' ?>>
        <span>Hide this page from search engines
          <span class="sh-field__hint" style="display:block">Adds <code>noindex,follow</code> and removes it from the sitemap.</span>
        </span>
      </label>
      <?php if ($canEdit): ?>
        <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Save this page</button>
      <?php endif; ?>
    </div>
  </div>
</form>

<form method="post" novalidate>
  <?= sh_csrf_field() ?>
  <input type="hidden" name="form" value="write_robots">
  <div class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('lock', 17) ?> robots.txt file</h2></div>
    <div class="sh-panel__body">
      <p class="sh-panel__note" style="margin-bottom:10px">
        <code>/robots.txt</code> is generated from the settings above, so it always matches the current
        canonical domain. Some hosting setups serve a physical file before the rewrite rules run; if
        <a href="<?= e(sh_url('robots.txt')) ?>" target="_blank" rel="noopener">/robots.txt</a> does not show this
        store's sitemap, write a static copy below.
      </p>
      <?php if ($canEdit): ?>
        <button class="sh-btn sh-btn--ghost" type="submit"
                data-confirm="Write a static robots.txt file into the website root?">
          <?= sh_icon('download', 15) ?> Write static robots.txt
        </button>
      <?php endif; ?>
    </div>
  </div>
</form>
<?php require __DIR__ . '/_footer.php'; ?>
