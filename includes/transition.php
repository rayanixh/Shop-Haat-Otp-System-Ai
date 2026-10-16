<?php
/**
 * ONE global page-transition configuration used by both the customer-facing
 * website and the admin panel. Stored in the existing `settings` table as
 * transition_* rows; media lives in uploads/transitions/ via sh_upload_image().
 */

function sh_transition_prefix(): string
{
    return 'transition_';
}

function sh_transition_hex(string $v, string $default): string
{
    $v = trim($v);
    if ($v !== '' && $v[0] !== '#') { $v = '#' . $v; }
    return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : $default;
}

function sh_transition_directions(): array
{
    return ['down' => 'Top → Bottom', 'right' => 'Left → Right', 'diagonal' => 'Diagonal', 'up' => 'Bottom → Top'];
}

function sh_transition_fades(): array
{
    return ['smooth' => 'Smooth', 'soft' => 'Soft', 'cinematic' => 'Cinematic'];
}

function sh_transition_bg_types(): array
{
    return ['solid' => 'Solid colour', 'gradient' => 'Gradient', 'image' => 'Image', 'gif' => 'GIF'];
}

function sh_transition_file_url(string $file): string
{
    $file = basename($file);
    if ($file === '' || !is_file(SH_UPLOAD_DIR . '/transitions/' . $file)) { return ''; }
    return sh_url('uploads/transitions/' . rawurlencode($file));
}

/** Resolved global configuration; missing files silently fall back. */
function sh_transition_config(): array
{
    $p = sh_transition_prefix();
    $defaults = ['bg_color' => '#111827', 'bg_color2' => '#1f2937', 'bg_type' => 'gradient', 'bg_direction' => 'diagonal'];

    $bgType = (string)sh_setting($p . 'bg_type', $defaults['bg_type']);
    if (!isset(sh_transition_bg_types()[$bgType])) { $bgType = $defaults['bg_type']; }
    $bgMedia = basename((string)sh_setting($p . 'bg_media', ''));
    $bgUrl = sh_transition_file_url($bgMedia);
    if (in_array($bgType, ['image', 'gif'], true) && $bgUrl === '') { $bgType = $defaults['bg_type']; }

    $dir = (string)sh_setting($p . 'bg_direction', $defaults['bg_direction']);
    if (!isset(sh_transition_directions()[$dir])) { $dir = 'diagonal'; }
    $c1 = sh_transition_hex((string)sh_setting($p . 'bg_color', $defaults['bg_color']), $defaults['bg_color']);
    $c2 = sh_transition_hex((string)sh_setting($p . 'bg_color2', $defaults['bg_color2']), $defaults['bg_color2']);

    $media = basename((string)sh_setting($p . 'media', ''));
    $mediaUrl = sh_transition_file_url($media);

    $duration = (int)sh_setting($p . 'duration', '450');
    $duration = max(150, min(5000, $duration));
    $fade = (string)sh_setting($p . 'fade', 'smooth');
    if (!isset(sh_transition_fades()[$fade])) { $fade = 'smooth'; }

    $angles = ['down' => '180deg', 'right' => '90deg', 'diagonal' => '135deg', 'up' => '0deg'];
    switch ($bgType) {
        case 'gradient': $bgCss = 'linear-gradient(' . $angles[$dir] . ', ' . $c1 . ', ' . $c2 . ')'; break;
        case 'image':
        case 'gif':      $bgCss = $c1 . ' url(' . $bgUrl . ') center / cover no-repeat'; break;
        default:         $bgCss = $c1;
    }
    // Light backgrounds get a dark loading mark, dark ones a light mark.
    $rgb = sscanf($c1, '#%02x%02x%02x');
    $lum = $rgb ? (0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2]) : 0;

    return [
        'enabled'      => sh_setting($p . 'enabled', '1') === '1',
        'bg_type'      => $bgType,
        'bg_color'     => $c1,
        'bg_color2'    => $c2,
        'bg_direction' => $dir,
        'bg_media'     => $bgMedia,
        'bg_url'       => $bgUrl,
        'bg_css'       => $bgCss,
        'media'        => $media,
        'media_url'    => $mediaUrl,
        'duration'     => $duration,
        'fade'         => $fade,
        'dark'         => $lum < 140,
    ];
}

/** HTML attributes for <body> that the shared JS reads. */
function sh_transition_body_attrs(array $cfg): string
{
    return ' data-transition="' . ($cfg['enabled'] ? '1' : '0') . '"'
        . ' data-transition-fade="' . e($cfg['fade']) . '"'
        . ' data-transition-duration="' . (int)$cfg['duration'] . '"'
        . ' data-transition-media="' . e($cfg['media_url']) . '"';
}

/**
 * The few rules the overlay needs before the stylesheet is in the picture.
 *
 * app.css owns the whole overlay, but this keeps the first paint correct even if
 * the stylesheet is unavailable: the overlay covers the viewport while the page
 * is held and is invisible (and click-through) as soon as the hold is released.
 */
function sh_transition_critical_css(): string
{
    return '.sh-pt{position:fixed;inset:0;z-index:4000;display:grid;place-items:center;padding:24px;'
        . 'overflow:hidden;opacity:0;visibility:hidden;pointer-events:none;background:var(--sh-pt-bg,#111827)}'
        . 'html.sh-pt-hold .sh-pt,.sh-pt.is-on{opacity:1;visibility:visible;pointer-events:auto}';
}

/**
 * Synchronous <head> snippet — the reason the transition now runs on the very
 * first page load instead of only after an internal link.
 *
 * It runs before the browser can paint anything, so it decides what the visitor
 * sees first:
 *
 *   1. arriving from an internal link (single-use marker in sessionStorage):
 *      hold the overlay that app.js will fade out — the established behaviour.
 *   2. first visit, refresh, bookmark, direct URL, new tab: hold the overlay
 *      too, so the configured transition is what the visitor sees, with no
 *      unanimated page appearing first.
 *   3. prefers-reduced-motion: no hold at all.
 *
 * Two independent release paths exist because a stuck overlay is worse than no
 * transition: app.js releases it as soon as the page is ready, and this snippet
 * arms a timer that releases it if app.js never runs. The stylesheet carries a
 * third, CSS-only release for the case where neither script can finish.
 */
function sh_transition_head(array $cfg): string
{
    if (!$cfg['enabled']) { return ''; }
    $dur = max(150, min(5000, (int)$cfg['duration']));
    // Matches --sh-pt-out in app.css: the fade the overlay is released with.
    $out = max(240, (int)round($dur * 0.5));
    $grace = 3500;

    $js = '(function(){var d=document.documentElement,t;'
        . 'function take(k){try{var v=sessionStorage.getItem(k);if(v){sessionStorage.removeItem(k);return v;}}catch(e){}return null;}'
        // A page can still be loading its very first stylesheet: never let the
        // decision itself throw and leave the page held.
        . 'try{var reduced=!!(window.matchMedia&&window.matchMedia("(prefers-reduced-motion: reduce)").matches);'
        . 'var raw=take("sh-pt"),arrived=null;'
        . 'if(raw){try{var o=JSON.parse(raw);if(o&&Date.now()-o.t<8000){arrived=o;}}catch(e){}}'
        . 'if(!reduced){'
        . 'd.classList.add("sh-pt-hold");'
        . 'if(arrived){window.__shPtArrived=arrived;}else{window.__shPtCold={t:Date.now(),d:' . $dur . '};}'
        // Fallback release: the same class changes app.js makes, without it.
        . 'window.__shPtFallbackRelease=function(){'
        . 'if(!d.classList.contains("sh-pt-hold")){return;}'
        . 'd.classList.remove("sh-pt-hold");d.classList.add("sh-pt-enter");'
        . 'setTimeout(function(){d.classList.remove("sh-pt-enter");},' . $out . ');};'
        . 't=setTimeout(function(){try{window.__shPtFallbackRelease();}catch(e){}},'
        . ($dur + $grace) . ');window.__shPtFallback=t;'
        . '}'
        . '}catch(e){try{d.classList.remove("sh-pt-hold");}catch(e2){}}'
        . '})();';

    return '<style>' . sh_transition_critical_css() . '</style>'
        . '<script' . sh_csp_nonce_attr() . '>' . $js . '</script>';
}

/** Full-screen overlay markup (only when enabled). */
function sh_transition_overlay(array $cfg): string
{
    if (!$cfg['enabled']) { return ''; }
    $cls = 'sh-pt sh-pt--' . e($cfg['fade']) . ($cfg['dark'] ? ' sh-pt--dark' : ' sh-pt--light');
    $html = '<div class="' . $cls . '" id="sh-pt" aria-hidden="true" style="--sh-pt-ms:' . (int)$cfg['duration'] . 'ms;--sh-pt-bg:' . e($cfg['bg_css']) . '">';
    $html .= '<div class="sh-pt__bg"></div>';
    if ($cfg['media_url'] !== '') {
        $html .= '<div class="sh-pt__media"><img src="' . e($cfg['media_url']) . '" alt="" decoding="async"></div>';
    } else {
        $html .= '<div class="sh-pt__mark"><span></span><span></span><span></span></div>';
    }
    return $html . '</div>';
}

/** Duration presets for the settings form (ms => label). */
function sh_transition_presets(): array
{
    return [300 => '300 ms', 500 => '500 ms', 750 => '750 ms', 1000 => '1 second', 1500 => '1.5 seconds', 2000 => '2 seconds'];
}
