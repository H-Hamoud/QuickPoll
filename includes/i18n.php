<?php
declare(strict_types=1);
// ============================================================
//  i18n.php – languages (German, English, Arabic) and themes
//
//  How translation works here (same idea as gettext or Laravel's
//  "translation strings as keys"):
//
//      t('Meine Umfragen')            →  "My surveys" / "استبياناتي"
//      t('Hallo {name}', ['name' => $n])
//      plural($n, 'Antwort', 'Antworten')   →  "3 responses"
//
//  The GERMAN text is the key. The other languages live in
//  lang/en.php and lang/ar.php. lang/de.php only holds what cannot
//  be a readable key: plural forms, month names, long legal texts.
//  A string that is missing in a language falls back to German –
//  tests/i18n_test.php fails when that happens, so nothing ships
//  half translated.
//
//  The language and the theme are PREFERENCES: stored in a cookie
//  (works before login and for anonymous survey participants) and
//  in the user's row (follows the account to another device).
//  docs/I18N.md explains the details.
// ============================================================

const SUPPORTED_LOCALES = [
    'de' => ['name' => 'Deutsch', 'dir' => 'ltr'],
    'en' => ['name' => 'English', 'dir' => 'ltr'],
    'ar' => ['name' => 'العربية', 'dir' => 'rtl'],
];
const DEFAULT_LOCALE = 'de';

const SUPPORTED_THEMES = ['system', 'light', 'dark'];

const PREFERENCE_COOKIE_DAYS = 365;

/** Current language code: 'de', 'en' or 'ar'. */
function locale(): string
{
    return $GLOBALS['qp_locale'] ?? DEFAULT_LOCALE;
}

/** Text direction of the current language: 'ltr' or 'rtl'. */
function text_direction(): string
{
    return SUPPORTED_LOCALES[locale()]['dir'];
}

function is_rtl(): bool
{
    return text_direction() === 'rtl';
}

/** Current theme preference: 'system', 'light' or 'dark'. */
function theme(): string
{
    return $GLOBALS['qp_theme'] ?? 'system';
}

/** Switch the language for the rest of this request (e-mails, CLI, tests). */
function set_locale(string $locale): void
{
    if (isset(SUPPORTED_LOCALES[$locale])) {
        $GLOBALS['qp_locale'] = $locale;
    }
}

/**
 * Best supported language from the browser's Accept-Language header,
 * e.g. "ar-SY,ar;q=0.9,en;q=0.8" → 'ar'. Only a first suggestion –
 * an explicit choice always wins.
 */
function locale_from_browser(string $header): ?string
{
    $candidates = [];
    foreach (explode(',', $header) as $position => $part) {
        if (!preg_match('/^\s*([a-zA-Z]{2,3})(?:-[\w-]+)?\s*(?:;\s*q\s*=\s*([\d.]+))?\s*$/', $part, $m)) {
            continue;
        }
        $quality = isset($m[2]) ? (float) $m[2] : 1.0;
        // Sort by quality, then by position in the header.
        $candidates[] = [-$quality, $position, strtolower($m[1])];
    }
    sort($candidates);
    foreach ($candidates as [, , $code]) {
        if (isset(SUPPORTED_LOCALES[$code])) {
            return $code;
        }
    }
    return null;
}

function set_preference_cookie(string $name, string $value): void
{
    setcookie($name, $value, [
        'expires'  => time() + PREFERENCE_COOKIE_DAYS * 86400,
        'path'     => '/',
        'samesite' => 'Lax',
        'secure'   => is_https(),
        'httponly' => true,     // no script needs it: the SERVER renders language and theme
    ]);
    $_COOKIE[$name] = $value;
}

/**
 * Called once per request by bootstrap.php.
 *
 * 1. ?lang=xx or ?theme=xx in the URL = the visitor used a switcher:
 *    store the choice in a cookie and redirect to the same address
 *    without the parameter (clean, shareable URLs).
 *    The value only SELECTS an entry of a fixed list (whitelist).
 * 2. Otherwise: cookie → browser language → German.
 */
function preferences_boot(): void
{
    $changed = false;

    $lang = $_GET['lang'] ?? null;
    if (is_string($lang) && isset(SUPPORTED_LOCALES[$lang])) {
        set_preference_cookie('qp_lang', $lang);
        $changed = true;
    }
    $theme = $_GET['theme'] ?? null;
    if (is_string($theme) && in_array($theme, SUPPORTED_THEMES, true)) {
        set_preference_cookie('qp_theme', $theme);
        $changed = true;
    }

    if ($changed && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !headers_sent()) {
        $params = $_GET;
        unset($params['lang'], $params['theme']);
        // Back to the same page. The path is the one the SERVER resolved
        // (SCRIPT_NAME), not the raw request line: a request for
        // "//evil.example/%2e%2e/index.php?lang=en" also ends up here, and
        // echoing that back as "Location: //evil.example/…" would send the
        // browser to a foreign site (open redirect).
        $path = '/' . ltrim(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        header('Location: ' . $path . ($params ? '?' . http_build_query($params) : ''));
        exit;
    }

    $cookieLang = $_COOKIE['qp_lang'] ?? null;
    if (is_string($cookieLang) && isset(SUPPORTED_LOCALES[$cookieLang])) {
        $GLOBALS['qp_locale'] = $cookieLang;
    } else {
        $GLOBALS['qp_locale'] = locale_from_browser($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '') ?? DEFAULT_LOCALE;
    }

    $cookieTheme = $_COOKIE['qp_theme'] ?? null;
    $GLOBALS['qp_theme'] = is_string($cookieTheme) && in_array($cookieTheme, SUPPORTED_THEMES, true)
        ? $cookieTheme
        : 'system';
}

/**
 * Keep cookie and account in step (called for logged-in users).
 *
 * $justLoggedIn = true:  the ACCOUNT wins – a preference saved earlier
 *                        follows the user to this device.
 * otherwise:             the COOKIE wins – it only differs from the
 *                        account when the user has just used a switcher.
 *
 * @param array<string, mixed> $user row of `nutzer`
 */
function preferences_sync(array $user, bool $justLoggedIn = false): void
{
    $stored = [
        'sprache' => isset(SUPPORTED_LOCALES[$user['sprache'] ?? '']) ? $user['sprache'] : null,
        'design'  => in_array($user['design'] ?? '', SUPPORTED_THEMES, true) ? $user['design'] : null,
    ];

    if ($justLoggedIn) {
        if ($stored['sprache'] !== null && $stored['sprache'] !== locale()) {
            set_preference_cookie('qp_lang', $stored['sprache']);
            $GLOBALS['qp_locale'] = $stored['sprache'];
        }
        if ($stored['design'] !== null && $stored['design'] !== theme()) {
            set_preference_cookie('qp_theme', $stored['design']);
            $GLOBALS['qp_theme'] = $stored['design'];
        }
    }

    if ($stored['sprache'] !== locale() || $stored['design'] !== theme()) {
        db()->prepare('UPDATE nutzer SET sprache = ?, design = ? WHERE id = ?')
            ->execute([locale(), theme(), $user['id']]);
    }
}

// ------------------------------------------------------------
//  Translation
// ------------------------------------------------------------

/**
 * Catalogue of one language (loaded once per request).
 * @return array<string, mixed>
 */
function translations(string $locale): array
{
    static $loaded = [];
    if (!isset($loaded[$locale])) {
        $file = QP_ROOT . '/lang/' . $locale . '.php';
        $loaded[$locale] = is_file($file) ? require $file : [];
    }
    return $loaded[$locale];
}

/**
 * Translate a text. The German text is the key; {placeholders} are
 * replaced AFTER the lookup, so translations can move them around.
 * The result is NOT escaped – templates print it with e(t(…)).
 *
 * @param array<string, scalar> $params
 */
function t(string $text, array $params = []): string
{
    $locale = locale();
    $result = translations($locale)[$text] ?? null;
    if (!is_string($result)) {
        // German is both the fallback language and (usually) the key itself.
        $result = translations(DEFAULT_LOCALE)[$text] ?? $text;
        if (!is_string($result)) {
            $result = $text;
        }
    }

    if ($params) {
        $replace = [];
        foreach ($params as $name => $value) {
            $replace['{' . $name . '}'] = (string) $value;
        }
        $result = strtr($result, $replace);
    }
    return $result;
}

/**
 * Like t(), for sentences that contain a piece of HTML (an address in
 * <bdi>, a link). The translated sentence is escaped; the parameters
 * are inserted as they are and must already be safe HTML.
 *
 * @param array<string, string> $htmlParams
 */
function t_html(string $text, array $htmlParams): string
{
    $out = e(t($text));
    foreach ($htmlParams as $name => $html) {
        $out = str_replace('{' . $name . '}', $html, $out);
    }
    return $out;
}

/**
 * Plural category of a number in the current language (CLDR rules).
 * German and English know "one" and "other"; Arabic has SIX forms:
 * 0, 1, 2, 3–10, 11–99 and the rest.
 */
function plural_category(int $n, ?string $locale = null): string
{
    if (($locale ?? locale()) === 'ar') {
        $mod100 = $n % 100;
        return match (true) {
            $n === 0                        => 'zero',
            $n === 1                        => 'one',
            $n === 2                        => 'two',
            $mod100 >= 3 && $mod100 <= 10   => 'few',
            $mod100 >= 11                   => 'many',
            default                         => 'other',
        };
    }
    return $n === 1 ? 'one' : 'other';
}

/**
 * Number + noun in the right plural form:
 *     plural(1, 'Antwort', 'Antworten') → "1 Antwort" / "1 response" / "إجابة واحدة"
 * The pair "Antwort|Antworten" is the key in the 'plurals' section of
 * the language files; {n} marks where the number goes.
 */
function plural(int $count, string $singular, string $plural): string
{
    $forms = translations(locale())['plurals'][$singular . '|' . $plural] ?? null;
    if (is_array($forms)) {
        $form = $forms[plural_category($count)] ?? $forms['other'] ?? null;
        if (is_string($form)) {
            return str_replace('{n}', format_number($count), $form);
        }
    }
    return format_number($count) . ' ' . ($count === 1 ? $singular : $plural);
}

/**
 * Keep a technical value (a signed number like "+12 %", a version, a
 * key) left to right inside right-to-left text. Uses the invisible
 * Unicode characters "left-to-right isolate" … "pop isolate"; without
 * them Arabic text would show "+12%" as "12%+".
 */
function ltr(string $value): string
{
    return "\u{2066}" . $value . "\u{2069}";
}

/**
 * Wrap user-written text (a survey title, an e-mail address) in <bdi>:
 * the browser then decides its direction on its own, so English or
 * German content inside an Arabic sentence does not scramble the line.
 * Returns ESCAPED HTML.
 */
function bdi(?string $text): string
{
    return '<bdi>' . e($text) . '</bdi>';
}

// ------------------------------------------------------------
//  Numbers, dates, currency
//
//  Deliberately without PHP's intl extension: XAMPP ships it switched
//  off. The few patterns we need are small enough to write down.
//  Arabic uses Western digits (0–9), as is common on the web in the
//  Levant and the Maghreb – one decision instead of mixed digit sets.
// ------------------------------------------------------------

/** 1.234,5 (de) · 1,234.5 (en, ar) */
function format_number(int|float $number, int $decimals = 0): string
{
    return locale() === 'de'
        ? number_format((float) $number, $decimals, ',', '.')
        : number_format((float) $number, $decimals, '.', ',');
}

/** 46 % (de) · 46% (en, ar) */
function format_percent(float $ratio): string
{
    $value = format_number($ratio * 100);
    return locale() === 'de' ? $value . ' %' : $value . '%';
}

/** 2 € (de, ar) · €2 (en) */
function format_money(int|float $amount, int $decimals = 0): string
{
    $value = format_number($amount, $decimals);
    return locale() === 'en' ? '€' . $value : $value . ' €';
}

/** @return array<int, string> 1 => 'Januar' … 12 */
function month_names(bool $short = false): array
{
    return translations(locale())[$short ? 'months_short' : 'months']
        ?? translations(DEFAULT_LOCALE)[$short ? 'months_short' : 'months'];
}

/** @return array<int, string> 0 => Monday … 6 => Sunday */
function weekday_names(bool $short = false): array
{
    return translations(locale())[$short ? 'weekdays_short' : 'weekdays']
        ?? translations(DEFAULT_LOCALE)[$short ? 'weekdays_short' : 'weekdays'];
}

/** 12.11.2026 (de) · 12 Nov 2026 (en) · 12 نوفمبر 2026 (ar) */
function format_date(?string $datetime): string
{
    if (!$datetime) {
        return '–';
    }
    $time = strtotime($datetime);
    if (locale() === 'de') {
        return date('d.m.Y', $time);
    }
    $month = month_names(locale() === 'en')[(int) date('n', $time)];
    return date('j', $time) . ' ' . $month . ' ' . date('Y', $time);
}

/** 12.11.2026, 14:58 Uhr (de) · 12 Nov 2026, 14:58 (en) */
function format_datetime(?string $datetime): string
{
    if (!$datetime) {
        return '–';
    }
    $clock = date('H:i', strtotime($datetime));
    return format_date($datetime) . ', ' . (locale() === 'de' ? $clock . ' Uhr' : $clock);
}

/** Short day for chart axes: 12.11. (de) · 12/11 (en, ar) */
function format_day_short(string $date): string
{
    $time = strtotime($date);
    return locale() === 'de' ? date('d.m.', $time) : date('j/n', $time);
}

/** "Donnerstag, 12. November" (de) · "Thursday, 12 November" (en) */
function format_today(): string
{
    $weekday = weekday_names()[(int) date('N') - 1];
    $month   = month_names()[(int) date('n')];
    $comma   = locale() === 'ar' ? '، ' : ', ';
    return $weekday . $comma . date('j') . (locale() === 'de' ? '. ' : ' ') . $month;
}

/** "vor 5 Minuten", "gestern", … – falls back to the date after a week. */
function time_ago(?string $datetime): string
{
    if (!$datetime) {
        return '–';
    }
    $seconds = time() - strtotime($datetime);
    if ($seconds < 60) {
        return t('gerade eben');
    }
    if ($seconds < 3600) {
        return t('vor {dauer}', ['dauer' => plural(intdiv($seconds, 60), 'Minute', 'Minuten')]);
    }
    if ($seconds < 86400) {
        return t('vor {dauer}', ['dauer' => plural(intdiv($seconds, 3600), 'Stunde', 'Stunden')]);
    }
    if ($seconds < 172800) {
        return t('gestern');
    }
    if ($seconds < 604800) {
        return t('vor {dauer}', ['dauer' => plural(intdiv($seconds, 86400), 'Tag', 'Tagen')]);
    }
    return format_date($datetime);
}
