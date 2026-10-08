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

function sh_password_problem(string $pw): ?string
{
    if (mb_strlen($pw) < 8) { return 'Password must be at least 8 characters long.'; }
    if (mb_strlen($pw) > 200) { return 'Password is too long.'; }
    // Accept letters and digits from ANY script (Bangla, Arabic, Cyrillic, ...),
    // not just A-Z and 0-9, so non-English speakers are not locked out.
    $hasLetter = preg_match('/\p{L}/u', $pw) === 1;
    $hasNumber = preg_match('/\p{N}/u', $pw) === 1;
    if (!$hasLetter || !$hasNumber) {
        return 'Password must be at least 8 characters and include at least one letter and one number '
             . '(for example: Shop2024pass).';
    }
    return null;
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

    public function password(string $f): self
    {
        $v = $this->value($f);
        if ($v !== '' && ($p = sh_password_problem($v)) !== null) { $this->errors[$f] = $p; }
        return $this;
    }

    public function matches(string $f, string $other, string $label): self
    {
        if ($this->value($f) !== $this->value($other)) { $this->errors[$f] = "$label does not match."; }
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
