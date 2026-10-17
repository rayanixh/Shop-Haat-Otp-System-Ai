<?php
/**
 * Server-side validation helpers. The front end is never trusted.
 */

function sh_post(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function sh_get(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function sh_int($v, int $default = 0): int
{
    return is_numeric($v) ? (int)$v : $default;
}

function sh_valid_email(string $email): bool
{
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 190;
}

/** Bangladeshi mobile formats plus generic international. */
function sh_valid_phone(string $phone): bool
{
    $p = preg_replace('/[\s\-()]/', '', $phone);
    return (bool)preg_match('/^(\+?880|0)?1[3-9]\d{8}$/', $p) || (bool)preg_match('/^\+?\d{8,15}$/', $p);
}

/**
 * Password length policy.
 *
 * The minimum is a policy value, not a password format: nothing here requires a
 * brand name, a prefix, a suffix or any particular word. The account minimum can
 * be raised by defining SH_PASSWORD_MIN_LENGTH before this file loads; the
 * administrator minimum (12) is separate because an administrator account can
 * change the whole store, and can be raised with SH_ADMIN_PASSWORD_MIN_LENGTH.
 *
 * @param string $context 'account' for customers, 'admin' for administrators
 */
function sh_password_min_length(string $context = 'account'): int
{
    $admin = $context === 'admin';
    $configured = $admin && defined('SH_ADMIN_PASSWORD_MIN_LENGTH')
        ? (int)constant('SH_ADMIN_PASSWORD_MIN_LENGTH')
        : ($admin ? 12 : (defined('SH_PASSWORD_MIN_LENGTH') ? (int)constant('SH_PASSWORD_MIN_LENGTH') : 8));
    return max($admin ? 12 : 8, min(64, $configured));
}

/**
 * Policy for customer and administrator account passwords.
 *
 * The only hard rule is length (and an upper bound so a request cannot be used
 * to burn CPU on a megabyte-long string). Any characters are accepted and the
 * value is never trimmed, rewritten or normalised: what the person typed is
 * what gets hashed. For customer accounts the pre-existing policy also asks for
 * a letter and a digit, which every script satisfies; administrators only need
 * the length, so an owner may choose any passphrase they like.
 */
function sh_password_policy_problem(string $pw, string $context = 'account'): ?string
{
    $min = sh_password_min_length($context);
    if ($pw === '') { return 'Password is required.'; }
    if (mb_strlen($pw) > 200) { return 'Password must be 200 characters or fewer.'; }
    if (trim($pw) === '') { return 'Password cannot be only spaces.'; }
    if (mb_strlen($pw) < $min) { return 'Password must be at least ' . $min . ' characters long.'; }
    if ($context === 'admin') { return null; }
    // Accept letters and digits from ANY script (Bangla, Arabic, Cyrillic, ...),
    // not just A-Z and 0-9, so non-English speakers are not locked out.
    $hasLetter = preg_match('/\p{L}/u', $pw) === 1;
    $hasNumber = preg_match('/\p{N}/u', $pw) === 1;
    if (!$hasLetter || !$hasNumber) {
        return 'Password must include at least one letter and one number.';
    }
    return null;
}

/** Customer-policy shorthand (kept for the existing call sites). */
function sh_password_problem(string $pw): ?string
{
    return sh_password_policy_problem($pw, 'account');
}

/**
 * Administrator-policy shorthand. Used by the installer, the admin account
 * screens and any password an administrator sets for themselves.
 */
function sh_admin_password_problem(string $pw): ?string
{
    return sh_password_policy_problem($pw, 'admin');
}

class ShValidator
{
    private array $errors = [];
    private array $data;

    public function __construct(array $data) { $this->data = $data; }

    private function value(string $f): string
    {
        $v = $this->data[$f] ?? '';
        return is_string($v) ? trim($v) : '';
    }

    /**
     * The submitted value exactly as sent.
     *
     * Passwords are checked and compared with this: trimming silently changes
     * what the person typed, and a confirmation check against trimmed values
     * would accept two different passwords as "matching".
     */
    private function raw(string $f): string
    {
        $v = $this->data[$f] ?? '';
        return is_string($v) ? $v : '';
    }

    public function required(string $f, string $label): self
    {
        if ($this->value($f) === '') { $this->errors[$f] = "$label is required."; }
        return $this;
    }

    public function email(string $f, string $label): self
    {
        $v = $this->value($f);
        if ($v !== '' && !sh_valid_email($v)) { $this->errors[$f] = "$label must be a valid email address."; }
        return $this;
    }

    public function phone(string $f, string $label): self
    {
        $v = $this->value($f);
        if ($v !== '' && !sh_valid_phone($v)) { $this->errors[$f] = "$label must be a valid phone number."; }
        return $this;
    }

    public function minLen(string $f, int $n, string $label): self
    {
        $v = $this->value($f);
        if ($v !== '' && mb_strlen($v) < $n) { $this->errors[$f] = "$label must be at least $n characters."; }
        return $this;
    }

    public function maxLen(string $f, int $n, string $label): self
    {
        $v = $this->value($f);
        if (mb_strlen($v) > $n) { $this->errors[$f] = "$label must be $n characters or fewer."; }
        return $this;
    }

    /**
     * @param string $context 'account' (customers) or 'admin' (administrators)
     */
    public function password(string $f, string $context = 'account'): self
    {
        $v = $this->raw($f);
        if ($v !== '' && ($p = sh_password_policy_problem($v, $context)) !== null) { $this->errors[$f] = $p; }
        return $this;
    }

    public function matches(string $f, string $other, string $label): self
    {
        if ($this->value($f) !== $this->value($other)) { $this->errors[$f] = "$label does not match."; }
        return $this;
    }

    /**
     * Same as matches(), for password fields: compares the exact bytes so a
     * trailing space cannot make two different passwords look identical.
     */
    public function matchesRaw(string $f, string $other, string $label): self
    {
        if ($this->raw($f) !== $this->raw($other)) { $this->errors[$f] = "$label does not match."; }
        return $this;
    }

    public function numericMin(string $f, float $min, string $label): self
    {
        $v = $this->value($f);
        if ($v === '' || !is_numeric($v) || (float)$v < $min) { $this->errors[$f] = "$label must be a number of at least $min."; }
        return $this;
    }

    public function custom(string $f, bool $ok, string $msg): self
    {
        if (!$ok && !isset($this->errors[$f])) { $this->errors[$f] = $msg; }
        return $this;
    }

    public function fails(): bool { return $this->errors !== []; }
    public function errors(): array { return $this->errors; }
    public function firstError(): string { return $this->errors === [] ? '' : reset($this->errors); }
}
