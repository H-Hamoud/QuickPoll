<?php
declare(strict_types=1);
// ============================================================
//  helpers.php – small general-purpose functions
// ============================================================

/**
 * Escape a value for HTML output (XSS protection).
 * Rule of the project: validate on input, ESCAPE ON OUTPUT –
 * every user-supplied value goes through e() in the templates.
 */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Redirect and stop the script (always exit after a Location header). */
function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/**
 * Read a trimmed string from $_POST, cut to a maximum length.
 * Arrays (name[]=…) are rejected – they would cause type errors.
 */
function post_string(string $key, int $maxLength = 255): string
{
    $value = $_POST[$key] ?? '';
    if (!is_string($value)) {
        return '';
    }
    return mb_substr(trim($value), 0, $maxLength);
}

/** Same as post_string() for $_GET. */
function get_string(string $key, int $maxLength = 255): string
{
    $value = $_GET[$key] ?? '';
    if (!is_string($value)) {
        return '';
    }
    return mb_substr(trim($value), 0, $maxLength);
}

function get_int(string $key, int $default = 0): int
{
    $value = $_GET[$key] ?? null;
    return is_string($value) && ctype_digit($value) ? (int) $value : $default;
}

function post_int(string $key, int $default = 0): int
{
    $value = $_POST[$key] ?? null;
    return is_string($value) && ctype_digit($value) ? (int) $value : $default;
}

/**
 * Base URL without trailing slash. Taken from config.php; if it is
 * missing there, derived from the current request.
 */
function base_url(): string
{
    if (defined('BASE_URL') && BASE_URL !== '') {
        return rtrim(BASE_URL, '/');
    }
    $scheme = is_https() ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme . '://' . $host . $dir;
}

// ---------- Flash messages ----------
// A message that survives exactly ONE redirect (PRG pattern):
// set before redirect(), shown and removed on the next page.

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** @return array<int, array{type: string, message: string}> */
function flash_take(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

// Formatting of numbers, dates and plurals depends on the language:
// see includes/i18n.php.

/** Shorten a text for tables and lists. */
function excerpt(?string $text, int $length = 90): string
{
    $text = trim((string) $text);
    return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1) . '…' : $text;
}

/**
 * Build a query string from the current filters, with overrides.
 * query_string(['page' => 2]) keeps q/status/sort and changes the page.
 *
 * @param array<string, scalar|null> $overrides
 */
function query_string(array $overrides): string
{
    $params = array_merge($_GET, $overrides);
    $params = array_filter($params, fn($v) => $v !== null && $v !== '' && !is_array($v));
    return $params ? '?' . http_build_query($params) : '';
}
