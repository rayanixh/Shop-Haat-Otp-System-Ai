<?php
/**
 * Customer-facing support channel links for the floating launcher menu.
 *
 * The launcher menu offers Facebook Messenger and WhatsApp next to the existing
 * on-site chat. Both destinations are configured by the administrator in
 * Admin -> Support -> Settings and are validated here, in one place, so the
 * settings form, the save handler and the storefront widget always agree.
 *
 * Rules:
 *  - https only (no http, no javascript:, no data:, no credentials in the URL);
 *  - Facebook links must point at a Facebook / Messenger host;
 *  - WhatsApp accepts a phone number in international format (stored as
 *    https://wa.me/<digits>) or an official WhatsApp URL;
 *  - anything unset or invalid resolves to an empty string, which makes the
 *    widget show the option as unavailable instead of rendering a dead link.
 *
 * Nothing here touches the AI support chat, its session or its endpoints.
 */

if (!function_exists('sh_support_facebook_hosts')) {
    /** Hosts accepted for the Facebook / Messenger support link. */
    function sh_support_facebook_hosts(): array
    {
        return [
            'm.me', 'fb.me',
            'facebook.com', 'www.facebook.com', 'm.facebook.com', 'web.facebook.com',
            'fb.com', 'www.fb.com',
            'messenger.com', 'www.messenger.com',
        ];
    }
}

if (!function_exists('sh_support_whatsapp_hosts')) {
    /** Hosts accepted for a WhatsApp support link. */
    function sh_support_whatsapp_hosts(): array
    {
        return ['wa.me', 'api.whatsapp.com', 'web.whatsapp.com', 'chat.whatsapp.com'];
    }
}

if (!function_exists('sh_support_link_fail')) {
    /** @return array{ok:bool,url:string,error:string} */
    function sh_support_link_fail(string $error): array
    {
        return ['ok' => false, 'url' => '', 'error' => $error];
    }
}

if (!function_exists('sh_support_link_ok')) {
    /** @return array{ok:bool,url:string,error:string} */
    function sh_support_link_ok(string $url): array
    {
        return ['ok' => true, 'url' => $url, 'error' => ''];
    }
}

if (!function_exists('sh_support_link_parts')) {
    /**
     * Parse an https URL and reject the shapes we never want to publish.
     *
     * @param array<int,string> $hosts
     * @return array{ok:bool,url:string,error:string,parts:array<string,mixed>}
     */
    function sh_support_link_parts(string $raw, array $hosts, string $label): array
    {
        $raw = trim($raw);
        if (strlen($raw) > 512) {
            return sh_support_link_fail('That ' . $label . ' link is too long. Use a short, direct link.');
        }
        // Reject anything that could break out of an href or is not valid in a URL.
        if (preg_match('~[\s<>"\'`\\\\]~', $raw) === 1) {
            return sh_support_link_fail('The ' . $label . ' link contains characters that are not allowed. Paste the link without spaces or quotes.');
        }
        if (filter_var($raw, FILTER_VALIDATE_URL) === false) {
            return sh_support_link_fail('Enter a full ' . $label . ' link that starts with https://');
        }
        $parts = parse_url($raw);
        if (!is_array($parts)) {
            return sh_support_link_fail('Enter a full ' . $label . ' link that starts with https://');
        }
        if (strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
            return sh_support_link_fail('Only https:// ' . $label . ' links are accepted. Copy the secure link again.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return sh_support_link_fail('Remove any username or password from the ' . $label . ' link.');
        }
        $host = strtolower((string)($parts['host'] ?? ''));
        if ($host === '' || !in_array($host, $hosts, true)) {
            return sh_support_link_fail('That is not an official ' . $label . ' address. Example: ' . ($label === 'Facebook'
                ? 'https://m.me/yourpage' : 'https://wa.me/8801700000000'));
        }
        $path = (string)($parts['path'] ?? '');
        if ($label === 'Facebook' && (trim($path, '/') === '' || $path === '/')) {
            return sh_support_link_fail('Add the page username after the address, for example https://m.me/yourpage');
        }
        return ['ok' => true, 'url' => $raw, 'error' => '', 'parts' => $parts];
    }
}

if (!function_exists('sh_support_link_check_facebook')) {
    /**
     * Validate an administrator supplied Facebook / Messenger support link.
     *
     * @return array{ok:bool,url:string,error:string}
     */
    function sh_support_link_check_facebook(string $raw): array
    {
        if (trim($raw) === '') { return sh_support_link_ok(''); }
        $result = sh_support_link_parts($raw, sh_support_facebook_hosts(), 'Facebook');
        unset($result['parts']);
        return $result;
    }
}

if (!function_exists('sh_support_link_check_whatsapp')) {
    /**
     * Validate a WhatsApp number or link and normalise it to an https URL.
     *
     * @return array{ok:bool,url:string,error:string}
     */
    function sh_support_link_check_whatsapp(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') { return sh_support_link_ok(''); }

        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $raw) === 1) {
            $result = sh_support_link_parts($raw, sh_support_whatsapp_hosts(), 'WhatsApp');
            if ($result['ok'] !== true) { return $result; }
            $parts = $result['parts'];
            $host = strtolower((string)($parts['host'] ?? ''));
            $path = trim((string)($parts['path'] ?? ''), '/');
            if (in_array($host, ['wa.me', 'web.whatsapp.com', 'api.whatsapp.com'], true)) {
                $phone = '';
                if (isset($parts['query'])) {
                    parse_str((string)$parts['query'], $query);
                    $phone = (string)($query['phone'] ?? '');
                }
                if ($phone === '') { $phone = $path; }
                if (preg_match('~^\+?[0-9]{8,15}$~', $phone) !== 1) {
                    return sh_support_link_fail('That WhatsApp link has no phone number. Use https://wa.me/8801700000000 or type the number.');
                }
            } elseif ($path === '' && !str_contains((string)($parts['query'] ?? ''), 'code=')) {
                return sh_support_link_fail('That WhatsApp group invite link is incomplete. Copy the full https://chat.whatsapp.com/… link.');
            }
            return sh_support_link_ok($raw);
        }

        // A typed number: strip the characters people naturally type.
        $number = preg_replace('~[\s\-().]~', '', $raw) ?? '';
        if (preg_match('~^(\+|00)?([1-9][0-9]{7,14})$~', $number, $m) !== 1) {
            return sh_support_link_fail('Enter a number with country code (for example +8801700000000) or a https://wa.me/… link.');
        }
        return sh_support_link_ok('https://wa.me/' . $m[2]);
    }
}

if (!function_exists('sh_support_facebook_url')) {
    /** Stored Facebook / Messenger support link, or '' when unset or invalid. */
    function sh_support_facebook_url(): string
    {
        if (!function_exists('sh_setting')) { return ''; }
        $stored = (string)sh_setting('support_chat_facebook_url', '');
        if ($stored === '') { return ''; }
        $check = sh_support_link_check_facebook($stored);
        return $check['ok'] ? $check['url'] : '';
    }
}

if (!function_exists('sh_support_whatsapp_url')) {
    /** Stored WhatsApp support URL without the pre-filled message. */
    function sh_support_whatsapp_url(): string
    {
        if (!function_exists('sh_setting')) { return ''; }
        $stored = (string)sh_setting('support_chat_whatsapp', '');
        if ($stored === '') { return ''; }
        $check = sh_support_link_check_whatsapp($stored);
        return $check['ok'] ? $check['url'] : '';
    }
}

if (!function_exists('sh_support_whatsapp_message')) {
    /** Optional pre-filled WhatsApp message (single line, max 300 chars). */
    function sh_support_whatsapp_message(): string
    {
        if (!function_exists('sh_setting')) { return ''; }
        $message = (string)sh_setting('support_chat_whatsapp_message', '');
        $message = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $message) ?? '';
        $message = trim(preg_replace('/\s+/u', ' ', $message) ?? '');
        return function_exists('mb_substr') ? mb_substr($message, 0, 300, 'UTF-8') : substr($message, 0, 300);
    }
}

if (!function_exists('sh_support_whatsapp_href')) {
    /** WhatsApp link with the configured pre-filled message appended. */
    function sh_support_whatsapp_href(): string
    {
        $url = sh_support_whatsapp_url();
        if ($url === '') { return ''; }
        $message = sh_support_whatsapp_message();
        if ($message === '') { return $url; }
        return $url . (str_contains($url, '?') ? '&' : '?') . 'text=' . rawurlencode($message);
    }
}

if (!function_exists('sh_support_chat_on')) {
    /** Overall customer chat master switch (same value the widget already checks). */
    function sh_support_chat_on(): bool
    {
        return sh_setting('support_chat_enabled', sh_setting('support_enabled', '1')) === '1';
    }
}

if (!function_exists('sh_support_channels')) {
    /** Which customer-facing support channels are actually available.
     *
     * ai     – AI Assistant replies enabled in Admin → Support → Settings
     * messenger – Facebook / Messenger link configured and valid
     * whatsapp  – WhatsApp link/number configured and valid
     *
     * @return array{ai:bool,messenger:bool,whatsapp:bool}
     */
    function sh_support_channels(): array
    {
        return [
            'ai'        => sh_setting('support_chat_ai_enabled', '0') === '1',
            'messenger' => sh_support_facebook_url() !== '',
            'whatsapp'  => sh_support_whatsapp_url() !== '',
        ];
    }
}

if (!function_exists('sh_support_any_channel')) {
    /** True when at least one support channel is available for customers. */
    function sh_support_any_channel(): bool
    {
        $ch = sh_support_channels();
        return $ch['ai'] || $ch['messenger'] || $ch['whatsapp'];
    }
}

if (!function_exists('sh_support_page_allowed')) {
    /** Server-side decision: should the floating support launcher appear on this page?
     *
     * Support is kept off checkout / order / account / authentication pages so
     * those flows stay focused. It remains on browsing pages (home, category,
     * product, help, static pages, etc.) where a customer may need assistance.
     *
     * @param string $currentPage basename of the current PHP script
     * @return bool
     */
    function sh_support_page_allowed(string $currentPage): bool
    {
        $excluded = [
            // Checkout / order flow — must stay focused on purchase
            'cart.php', 'checkout.php', 'payment.php', 'order-success.php',
            // Order management — no support widget inside order views
            'orders.php', 'order-details.php',
            // Account / profile pages
            'account.php', 'addresses.php', 'password.php',
            // Authentication — must stay clean
            'login.php', 'register.php', 'forgot-password.php',
            'reset-password.php', 'otp.php', 'verify-email.php',
        ];
        return !in_array($currentPage, $excluded, true);
    }
}
