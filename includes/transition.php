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
 * Tiny synchronous <head> snippet: if we arrived through a transition the
 * overlay is shown before first paint so there is no flash between pages.
 * A CSS animation removes the hold automatically if JS never finishes.
 */
function sh_transition_head(array $cfg): string
{
    if (!$cfg['enabled']) { return ''; }
    return '<script>(function(){try{var r=sessionStorage.getItem("sh-pt");if(!r)return;var o=JSON.parse(r);'
        . 'if(o&&Date.now()-o.t<8000){document.documentElement.className+=" sh-pt-hold";}'
        . 'else{sessionStorage.removeItem("sh-pt");}}catch(e){}})();</script>';
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
