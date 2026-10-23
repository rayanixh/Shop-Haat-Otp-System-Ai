<?php
/**
 * Reusable SEO field block for the product and category edit forms.
 *
 * Renders the editable SEO fields plus a live preview of how the page would
 * appear in a search result and in a social card. The preview is built in the
 * browser from the values in the form, so it also shows the fallbacks the SEO
 * engine would choose — it never claims to be a Google search result.
 *
 * Expects:
 *   $seoPrefix  string  id/name prefix so two forms on one page cannot clash
 *   $seoRow     array   the row being edited (product or category)
 *   $seoLabel   string  what the entity is called in the hints
 *   $seoBrand   string  brand name used in the generated preview title
 *   $seoBaseUrl string  canonical base for the preview URL
 *   $seoPath    string  the public path pattern, e.g. product.php?slug=…
 *
 * Optional (the entity's own picture — the same one the storefront would use):
 *   $seoImage        string  absolute URL of the saved entity image
 *   $seoImageInput   string  id of the file input that can replace it
 *   $seoImageCurrent string  id of the hidden input holding the saved filename
 */

declare(strict_types=1);

$seoPrefix  = $seoPrefix ?? 'seo';
$seoRow     = is_array($seoRow ?? null) ? $seoRow : [];
$seoLabel   = $seoLabel ?? 'page';
// The picture the page itself would publish. An empty value means the entity
// has no image, so the preview must say so instead of showing nothing.
$seoImage        = trim((string)($seoImage ?? ''));
$seoImageInput   = trim((string)($seoImageInput ?? ''));
$seoImageCurrent = trim((string)($seoImageCurrent ?? ''));
$seoBrand   = trim((string)($seoBrand ?? ''));
if ($seoBrand === '') { $seoBrand = (string)sh_setting('site_name', 'ShopHaat'); }
$seoBaseUrl = rtrim($seoBaseUrl ?? '', '/');
$seoPath    = $seoPath ?? '';

// The same suffix rule the SEO engine applies to a finished title. Kept here so
// the browser preview matches what the page will really print.
$seoSuffixCfg = function_exists('sh_seo_get') ? sh_seo_get('seo_title_suffix') : '';
$seoSuffix    = $seoSuffixCfg !== '' ? $seoSuffixCfg : (' | ' . $seoBrand);

// The live counters and the preview are progressive enhancement: this file is
// loaded by the admin footer only where the block is actually used.
$adminScripts = is_array($adminScripts ?? null) ? $adminScripts : [];
$adminScripts['seo'] = 'assets/js/seo.js';

$sVal = static function (string $k) use ($seoRow): string {
    return trim((string)($seoRow[$k] ?? ''));
};
$seoName    = $sVal('name');
$seoTitle   = $sVal('meta_title');
$seoDesc    = $sVal('meta_description');
$seoFocus   = $sVal('seo_focus_keyword');
$seoPhrases = $sVal('seo_related_phrases');
$seoKw      = $sVal('meta_keywords');
$seoOgImage = $sVal('seo_og_image');
$seoCanon   = $sVal('seo_canonical');
$seoNoindex = (int)($seoRow['seo_noindex'] ?? 0) === 1;

// What the search engine would show if the owner leaves the fields empty.
$autoTitle = $seoName !== '' ? $seoName . $seoSuffix : '';
$autoDesc  = trim((string)($seoRow['short_description'] ?? ($seoRow['description'] ?? '')));
$autoDesc  = $autoDesc !== '' ? sh_seo_plain($autoDesc) : ('Shop ' . $seoName . ' at ' . sh_setting('site_name', 'ShopHaat') . '.');
$autoCanon = ($seoBaseUrl !== '' && $seoPath !== '')
    ? $seoBaseUrl . '/' . str_replace('{slug}', rawurlencode((string)($seoRow['slug'] ?? '')), $seoPath)
    : '';

// The preview shows the image this page would really publish: an explicit
// sharing image first, then the product's own picture. Both are absolute public
// URLs — never a server path and never a data: URI.
$seoPreviewImage = $seoOgImage !== '' ? sh_seo_image_url($seoOgImage) : '';
if ($seoPreviewImage === '') { $seoPreviewImage = $seoImage; }
$seoPreviewImage = sh_seo_image_url($seoPreviewImage);
// Last resort is the site-wide default, exactly like sh_seo_meta(). The preview
// is honest about which one it is showing.
$seoDefaultImage = sh_seo_image_url(sh_seo_get('seo_default_image'));
if ($seoDefaultImage === '') { $seoDefaultImage = sh_seo_image_url((string)sh_setting('site_logo', '')); }
if ($seoPreviewImage === '') { $seoPreviewImage = $seoDefaultImage; }
?>
<div class="sh-panel" id="<?= e($seoPrefix) ?>-panel" data-seo-block="1"
     data-seo-brand="<?= e($seoBrand) ?>" data-seo-suffix="<?= e($seoSuffix) ?>"
     data-seo-image="<?= e($seoImage) ?>"
     data-seo-image-input="<?= e($seoImageInput) ?>"
     data-seo-image-current="<?= e($seoImageCurrent) ?>"
     data-seo-default-image="<?= e($seoDefaultImage) ?>">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title"><?= sh_icon('search', 17) ?> Search engine listing</h2>
  </div>
  <div class="sh-panel__body">
    <p class="sh-panel__note">
      These fields control how this <?= e($seoLabel) ?> appears in search results and in shared links.
      Leaving a field empty is fine — ShopHaat then builds a sensible value from the
      <?= e($seoLabel) ?>'s own name and description, shown as the placeholder below.
    </p>

    <div class="sh-field">
      <label class="sh-field__label" for="<?= e($seoPrefix) ?>-title">SEO title</label>
      <input class="sh-input" id="<?= e($seoPrefix) ?>-title" name="meta_title"
             value="<?= e($seoTitle) ?>" data-seo-field="title"
             placeholder="<?= e(sh_seo_clip($autoTitle, 70, '')) ?>">
      <span class="sh-field__hint">
        The site name is added automatically unless you write it yourself.
        Search engines may shorten a long title, so write it for a person.
      </span>
    </div>

    <div class="sh-field">
      <label class="sh-field__label" for="<?= e($seoPrefix) ?>-desc">Meta description</label>
      <textarea class="sh-textarea" id="<?= e($seoPrefix) ?>-desc" name="meta_description" rows="3"
                data-seo-field="description"
                placeholder="<?= e(sh_seo_clip($autoDesc, 158)) ?>"><?= e($seoDesc) ?></textarea>
      <span class="sh-field__hint">
        No character limit. Write it for a person deciding whether to click; Google may rewrite it.
      </span>
    </div>

    <div class="sh-grid2">
      <div class="sh-field">
        <label class="sh-field__label" for="<?= e($seoPrefix) ?>-focus">Focus keyword</label>
        <input class="sh-input" id="<?= e($seoPrefix) ?>-focus" name="seo_focus_keyword" maxlength="120"
               value="<?= e($seoFocus) ?>" data-seo-field="focus"
               placeholder="e.g. wireless earbuds price in bangladesh">
        <span class="sh-field__hint">
          Editorial guidance only — nothing in the page is forced to match it and no ranking depends on it.
          <span data-seo-check="focus"></span>
        </span>
      </div>
      <div class="sh-field">
        <label class="sh-field__label" for="<?= e($seoPrefix) ?>-phrases">Related search phrases</label>
        <input class="sh-input" id="<?= e($seoPrefix) ?>-phrases" name="seo_related_phrases" maxlength="500"
               value="<?= e($seoPhrases) ?>" placeholder="cheap earbuds, bluetooth earphone bd">
        <span class="sh-field__hint">Comma separated. Kept for your own organisation and for legacy engines.</span>
      </div>
    </div>

    <div class="sh-grid2">
      <div class="sh-field">
        <label class="sh-field__label" for="<?= e($seoPrefix) ?>-kw">Keywords</label>
        <input class="sh-input" id="<?= e($seoPrefix) ?>-kw" name="meta_keywords" maxlength="300"
               value="<?= e($seoKw) ?>" placeholder="earbuds, wireless, bluetooth">
        <span class="sh-field__hint">
          Output as the meta keywords tag. <strong>Google has not used meta keywords for ranking since 2009</strong>
          — keep this for internal organisation and older engines, not for ranking.
        </span>
      </div>
      <div class="sh-field">
        <label class="sh-field__label" for="<?= e($seoPrefix) ?>-canon">Canonical URL (optional)</label>
        <input class="sh-input" id="<?= e($seoPrefix) ?>-canon" name="seo_canonical" maxlength="255"
               value="<?= e($seoCanon) ?>" placeholder="Leave empty to use the page's own URL"
               data-seo-field="canonical" data-seo-auto="<?= e($autoCanon) ?>">
        <span class="sh-field__hint">
          Only set this to point at a different address for the same content. Leave it empty normally.
        </span>
      </div>
    </div>

    <div class="sh-field">
      <label class="sh-field__label" for="<?= e($seoPrefix) ?>-og">Social sharing image (optional)</label>
      <input class="sh-input" id="<?= e($seoPrefix) ?>-og" name="seo_og_image" maxlength="190"
             value="<?= e($seoOgImage) ?>" data-seo-field="image"
             placeholder="uploads/… or https://… — empty uses the site default">
      <span class="sh-field__hint">
        Used when this page is shared on Facebook, WhatsApp, Messenger or X. Around 1200×630 looks best.
        Leave empty to use this <?= e($seoLabel) ?>'s own image<?= $seoLabel === 'product' ? ' (the product image above)' : '' ?>,
        then the site's default image.
      </span>
    </div>

    <label class="sh-check" style="margin-top:6px">
      <input type="checkbox" name="seo_noindex" value="1" <?= $seoNoindex ? 'checked' : '' ?> data-seo-field="noindex">
      <span>Hide this <?= e($seoLabel) ?> from search engines
        <span class="sh-field__hint" style="display:block">
          Adds <code>noindex,follow</code> and removes it from the sitemap. It stays visible to shoppers who
          already have the link. Use this for a seasonal or discontinued item, not for something you want found.
        </span>
      </span>
    </label>

    <!-- Preview ------------------------------------------------------------ -->
    <div style="margin-top:18px;border-top:1px solid var(--sh-line);padding-top:14px">
      <p class="sh-field__label">Preview</p>
      <p class="sh-alert sh-alert--warning" data-seo-preview="noindex-note" hidden style="margin-bottom:10px">
        This <?= e($seoLabel) ?> is set to stay out of search results. It will not appear in the sitemap.
      </p>
      <p class="sh-panel__note" style="margin-bottom:10px">
        An approximation of the listing — search engines decide the final layout, and Google may
        rewrite a title or description it considers unhelpful.
      </p>

      <div style="border:1px solid var(--sh-line);border-radius:9px;padding:12px 14px;background:#fff;max-width:620px">
        <div style="font-size:12px;color:#202124" data-seo-preview="url">
          <?= e($autoCanon !== '' ? $autoCanon : sh_seo_base() . '/') ?>
        </div>
        <div style="font-size:17px;line-height:1.3;color:#1a0dab;margin:3px 0 4px" data-seo-preview="title">
          <?= e($seoTitle !== '' ? $seoTitle : $autoTitle) ?>
        </div>
        <div style="font-size:13px;line-height:1.5;color:#4d5156" data-seo-preview="description">
          <?= e($seoDesc !== '' ? $seoDesc : sh_seo_clip($autoDesc, 158)) ?>
        </div>
      </div>

      <div style="display:flex;gap:12px;align-items:flex-start;margin-top:12px;border:1px solid var(--sh-line);border-radius:9px;padding:12px;background:#fff;max-width:620px">
        <div style="width:96px;height:96px;flex:none;border-radius:8px;background:var(--sh-brand-soft);display:grid;place-items:center;overflow:hidden">
          <img data-seo-preview="image" alt=""<?= $seoPreviewImage !== '' ? ' src="' . e($seoPreviewImage) . '"' : '' ?>
               style="width:100%;height:100%;object-fit:cover;display:<?= $seoPreviewImage !== '' ? 'block' : 'none' ?>">
          <span data-seo-preview="image-fallback" style="font-size:11px;color:var(--sh-muted);text-align:center;<?= $seoPreviewImage !== '' ? 'display:none' : '' ?>">No image</span>
        </div>
        <div style="min-width:0">
          <div style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--sh-muted)" data-seo-preview="host">
            <?= e(preg_replace('~^https?://~', '', $seoBaseUrl !== '' ? $seoBaseUrl : sh_seo_base())) ?>
          </div>
          <div style="font-weight:700;margin:3px 0 4px" data-seo-preview="og-title">
            <?= e($seoTitle !== '' ? $seoTitle : $autoTitle) ?>
          </div>
          <div style="font-size:12.5px;color:var(--sh-ink-2)" data-seo-preview="og-description">
            <?= e($seoDesc !== '' ? $seoDesc : sh_seo_clip($autoDesc, 158)) ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
