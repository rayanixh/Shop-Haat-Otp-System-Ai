<?php
/**
 * Theme Customization — centralised colour system.
 *
 * Every storefront colour is a CSS custom property declared in assets/css/app.css
 * (the defaults). The admin overrides live in the existing `settings` table as
 * `theme_<key>` rows and are emitted as an inline `:root { … }` block right after
 * app.css by sh_theme_style_tag(). Nothing but colours is affected.
 */

/**
 * Registry: key => [css variable, label, default, group].
 * Keys double as the settings row name (theme_<key>).
 */
function sh_theme_registry(): array
{
    static $r = null;
    if ($r !== null) { return $r; }
    $r = [
        // ---- Primary colours ------------------------------------------------
        'primary'          => ['--sh-brand',          'Primary Color',              '#e8501b', 'Primary Colors'],
        'primary_dark'     => ['--sh-brand-dark',     'Primary Hover / Dark',       '#cc4415', 'Primary Colors'],
        'primary_soft'     => ['--sh-brand-soft',     'Primary Tint',               '#fff1ec', 'Primary Colors'],
        'secondary'        => ['--sh-accent',         'Secondary Color',            '#103a6b', 'Primary Colors'],
        'secondary_soft'   => ['--sh-accent-soft',    'Secondary Tint',             '#eef3fa', 'Primary Colors'],
        'accent'           => ['--sh-star',           'Accent Color',               '#f5a623', 'Primary Colors'],
        'bg'               => ['--sh-bg',             'Main Background Color',      '#f2f3f6', 'Primary Colors'],
        'bg_2'             => ['--sh-bg-2',           'Secondary Background Color', '#f7f8fa', 'Primary Colors'],
        'surface'          => ['--sh-surface',        'Surface Color',              '#ffffff', 'Primary Colors'],
        'card'             => ['--sh-card',           'Card Background Color',      '#ffffff', 'Primary Colors'],
        'border'           => ['--sh-line',           'Border Color',               '#e4e7ec', 'Primary Colors'],
        'border_2'         => ['--sh-line-2',         'Divider Color',              '#eef0f4', 'Primary Colors'],

        // ---- Text -----------------------------------------------------------
        'text'             => ['--sh-ink',            'Main Text Color',            '#1f2430', 'Text Colors'],
        'heading'          => ['--sh-heading',        'Heading Color',              '#1f2430', 'Text Colors'],
        'text_2'           => ['--sh-ink-2',          'Secondary Text Color',       '#414958', 'Text Colors'],
        'muted'            => ['--sh-muted',          'Muted Text Color',           '#6b7385', 'Text Colors'],

        // ---- Buttons --------------------------------------------------------
        'btn_bg'           => ['--sh-btn-bg',         'Primary Button Color',       '#e8501b', 'Button Colors'],
        'btn_text'         => ['--sh-btn-text',       'Primary Button Text Color',  '#ffffff', 'Button Colors'],
        'btn_hover'        => ['--sh-btn-hover',      'Button Hover Color',         '#cc4415', 'Button Colors'],
        'btn_border'       => ['--sh-btn-border',     'Button Border Color',        '#e8501b', 'Button Colors'],
        'btn2_bg'          => ['--sh-btn2-bg',        'Secondary Button Color',     '#ffffff', 'Button Colors'],
        'btn2_text'        => ['--sh-btn2-text',      'Secondary Button Text Color','#414958', 'Button Colors'],
        'btn2_border'      => ['--sh-btn2-border',    'Secondary Button Border',    '#e4e7ec', 'Button Colors'],

        // ---- Links ----------------------------------------------------------
        'link'             => ['--sh-link',           'Link Color',                 '#e8501b', 'Link Colors'],
        'link_hover'       => ['--sh-link-hover',     'Link Hover Color',           '#cc4415', 'Link Colors'],

        // ---- Status ---------------------------------------------------------
        'success'          => ['--sh-ok',             'Success Color',              '#17804a', 'Status Colors'],
        'success_soft'     => ['--sh-ok-soft',        'Success Background',         '#e7f5ee', 'Status Colors'],
        'warning'          => ['--sh-warn',           'Warning Color',              '#a35a00', 'Status Colors'],
        'warning_soft'     => ['--sh-warn-soft',      'Warning Background',         '#fdf3e3', 'Status Colors'],
        'danger'           => ['--sh-bad',            'Error / Danger Color',       '#c62828', 'Status Colors'],
        'danger_soft'      => ['--sh-bad-soft',       'Error Background',           '#fdecea', 'Status Colors'],
        'info'             => ['--sh-info',           'Info Color',                 '#17406e', 'Status Colors'],
        'info_soft'        => ['--sh-info-soft',      'Info Background',            '#eef3fa', 'Status Colors'],

        // ---- Forms ----------------------------------------------------------
        'input_bg'         => ['--sh-input-bg',       'Input Background Color',     '#ffffff', 'Form Colors'],
        'input_border'     => ['--sh-input-border',   'Input Border Color',         '#e4e7ec', 'Form Colors'],
        'input_text'       => ['--sh-input-text',     'Input Text Color',           '#1f2430', 'Form Colors'],
        'input_placeholder'=> ['--sh-input-ph',       'Input Placeholder Color',    '#8a93a6', 'Form Colors'],
        'input_focus'      => ['--sh-input-focus',    'Input Focus Border Color',   '#e8501b', 'Form Colors'],

        // ---- Header / navigation -------------------------------------------
        'header_bg'        => ['--sh-header-bg',      'Header Background',          '#ffffff', 'Header / Navigation'],
        'header_text'      => ['--sh-header-text',    'Header Text',                '#1f2430', 'Header / Navigation'],
        'topbar_bg'        => ['--sh-topbar-bg',      'Top Utility Bar Background', '#103a6b', 'Header / Navigation'],
        'topbar_text'      => ['--sh-topbar-text',    'Top Utility Bar Text',       '#dbe6f4', 'Header / Navigation'],
        'nav_active'       => ['--sh-nav-active',     'Navigation Active Color',    '#e8501b', 'Header / Navigation'],
        'nav_hover'        => ['--sh-nav-hover',      'Navigation Hover Background','#f2f3f6', 'Header / Navigation'],

        // ---- Footer ---------------------------------------------------------
        'footer_bg'        => ['--sh-footer-bg',      'Footer Background',          '#151b2b', 'Footer Colors'],
        'footer_text'      => ['--sh-footer-text',    'Footer Text',                '#a7b0c2', 'Footer Colors'],
        'footer_heading'   => ['--sh-footer-heading', 'Footer Heading',             '#ffffff', 'Footer Colors'],
        'footer_link'      => ['--sh-footer-link',    'Footer Link Color',          '#a7b0c2', 'Footer Colors'],
        'footer_link_hover'=> ['--sh-footer-link-hover','Footer Link Hover Color',  '#e8501b', 'Footer Colors'],
    ];
    return $r;
}

/** Group order for the admin UI. */
function sh_theme_groups(): array
{
    $g = [];
    foreach (sh_theme_registry() as $key => [$var, $label, $def, $group]) { $g[$group][] = $key; }
    return $g;
}

function sh_theme_defaults(): array
{
    $d = [];
    foreach (sh_theme_registry() as $k => $row) { $d[$k] = $row[2]; }
    return $d;
}

/** Normalise any user-supplied colour to #rrggbb; null if invalid. */
function sh_theme_hex(?string $v): ?string
{
    $v = strtolower(trim((string)$v));
    if ($v === '') { return null; }
    if ($v[0] !== '#') { $v = '#' . $v; }
    if (preg_match('/^#([0-9a-f]{3})$/', $v, $m)) {
        return '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
    }
    return preg_match('/^#[0-9a-f]{6}$/', $v) ? $v : null;
}

/** Effective colours: saved value when valid, otherwise the default. */
function sh_theme_colors(): array
{
    static $c = null;
    if ($c !== null) { return $c; }
    $c = sh_theme_defaults();
    try {
        $s = sh_settings();
        foreach ($c as $k => $def) {
            $hex = sh_theme_hex($s['theme_' . $k] ?? null);
            if ($hex !== null) { $c[$k] = $hex; }
        }
    } catch (Throwable $e) { sh_log_exception($e, 'theme'); }
    return $c;
}

/** True when at least one colour differs from the shipped default. */
function sh_theme_is_custom(): bool
{
    return sh_theme_colors() !== sh_theme_defaults();
}

/** Relative luminance (WCAG) 0..1 */
function sh_theme_luminance(string $hex): float
{
    $hex = sh_theme_hex($hex) ?? '#000000';
    $ch = [];
    foreach ([1, 3, 5] as $i) {
        $v = hexdec(substr($hex, $i, 2)) / 255;
        $ch[] = $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    }
    return 0.2126 * $ch[0] + 0.7152 * $ch[1] + 0.0722 * $ch[2];
}

function sh_theme_contrast(string $a, string $b): float
{
    $la = sh_theme_luminance($a); $lb = sh_theme_luminance($b);
    return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
}

/** Best readable text (black/white) for a given background — used for auto "on-colour" text. */
function sh_theme_on(string $bg): string
{
    return sh_theme_luminance($bg) > 0.45 ? '#1f2430' : '#ffffff';
}

/**
 * Pairs that must stay readable. Returns [[label, fg, bg, ratio], …] for those
 * below 4.5:1 so the admin sees a warning instead of an unreadable store.
 */
function sh_theme_contrast_issues(array $c): array
{
    $pairs = [
        ['Main text on main background',       'text',        'bg'],
        ['Main text on cards',                 'text',        'card'],
        ['Heading on cards',                   'heading',     'card'],
        ['Secondary text on cards',            'text_2',      'card'],
        ['Primary button text on button',      'btn_text',    'btn_bg'],
        ['Secondary button text on button',    'btn2_text',   'btn2_bg'],
        ['Input text on input background',     'input_text',  'input_bg'],
        ['Header text on header',              'header_text', 'header_bg'],
        ['Top bar text on top bar',            'topbar_text', 'topbar_bg'],
        ['Footer text on footer',              'footer_text', 'footer_bg'],
        ['Footer links on footer',             'footer_link', 'footer_bg'],
        ['Links on cards',                     'link',        'card'],
    ];
    $out = [];
    foreach ($pairs as [$label, $fg, $bg]) {
        $r = sh_theme_contrast($c[$fg], $c[$bg]);
        if ($r < 4.5) { $out[] = [$label, $c[$fg], $c[$bg], round($r, 2)]; }
    }
    return $out;
}

/** Builds the `:root{…}` declarations for a colour set (no <style> wrapper). */
function sh_theme_css(array $colors): string
{
    $reg = sh_theme_registry();
    $lines = [];
    foreach ($colors as $k => $hex) {
        if (!isset($reg[$k])) { continue; }
        $lines[] = $reg[$k][0] . ':' . $hex;
    }
    // Derived tokens: text that sits on top of the primary colour, and the focus ring.
    $lines[] = '--sh-on-brand:' . sh_theme_on($colors['primary']);
    $lines[] = '--sh-on-accent:' . sh_theme_on($colors['secondary']);
    [$r, $g, $b] = sscanf($colors['input_focus'], '#%02x%02x%02x');
    $lines[] = '--sh-focus-ring:rgba(' . $r . ',' . $g . ',' . $b . ',.14)';
    return ':root{' . implode(';', $lines) . '}';
}

/**
 * Inline override block for the storefront <head>. Emitted only when the admin
 * has customised something, so an untouched store ships exactly the CSS defaults.
 * The derived tokens are always emitted so contrast helpers work out of the box.
 */
function sh_theme_style_tag(): string
{
    return '<style id="sh-theme">' . sh_theme_css(sh_theme_colors()) . '</style>';
}

/** Persist a validated colour set (only theme_* rows are touched). */
function sh_theme_save(array $input): array
{
    $errors = [];
    $reg = sh_theme_registry();
    foreach ($reg as $k => [$var, $label]) {
        $hex = sh_theme_hex($input[$k] ?? null);
        if ($hex === null) { $errors[$k] = $label . ' must be a valid HEX colour like #1a73e8.'; continue; }
        sh_setting_save('theme_' . $k, $hex);
    }
    return $errors;
}

/** Remove every theme_* override so the CSS defaults apply again. */
function sh_theme_reset(): void
{
    foreach (array_keys(sh_theme_registry()) as $k) {
        sh_query('DELETE FROM settings WHERE setting_key = ?', ['theme_' . $k]);
    }
    sh_settings(true);
}
