<?php
declare(strict_types=1);
// ============================================================
//  security.php – sessions, CSRF, login throttling, headers
// ============================================================

function is_https(): bool
{
    return request_is_https($_SERVER, defined('BASE_URL') ? (string) BASE_URL : '');
}

/**
 * Is this site served over HTTPS?
 *
 * Normally the web server says so ($_SERVER['HTTPS']). Behind a proxy or
 * CDN that ends the TLS connection, PHP itself only sees plain HTTP – then
 * the configured address (BASE_URL = https://…) is the reliable statement.
 * Without this the session cookie would lose its "Secure" flag and HSTS
 * would be missing exactly on the servers that need both.
 * The X-Forwarded-Proto header is deliberately not used: a client can send it.
 */
function request_is_https(array $server, string $baseUrl): bool
{
    if (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off') {
        return true;
    }
    return str_starts_with(strtolower($baseUrl), 'https://');
}

/**
 * HTTP response headers that harden every page.
 * The Content-Security-Policy only allows scripts, styles, fonts and
 * images from our own origin – injected <script> tags or inline event
 * handlers simply do not run. That is why the project has no inline
 * JavaScript and no style="" attributes.
 */
function send_security_headers(): void
{
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
    // A page opened from here (or that opened us) gets no handle on this window.
    header('Cross-Origin-Opener-Policy: same-origin');
    header(
        "Content-Security-Policy: default-src 'self'; img-src 'self' data:; object-src 'none'; "
        . "base-uri 'self'; frame-ancestors 'none'; "
        // Forms that end in a redirect to another origin must name it here:
        // Stripe's hosted pages and (only when configured) Google's sign-in.
        . "form-action 'self' https://checkout.stripe.com https://billing.stripe.com"
        . (GOOGLE_CLIENT_ID !== '' ? ' ' . google_origin() : '')
        . (stripe_test_base() !== null ? ' ' . stripe_test_base() : '')
    );
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// ---------- Secret + pseudonymous hashes ----------

/** Secret key for HMAC hashes; see config.example.php. */
function app_secret(): string
{
    static $secret = null;
    if ($secret !== null) {
        return $secret;
    }
    if (APP_SECRET !== '') {
        return $secret = APP_SECRET;
    }
    // No secret configured: generate one once and keep it in storage/.
    $file = QP_ROOT . '/storage/app_secret.php';
    if (is_file($file)) {
        return $secret = (string) require $file;
    }
    $generated = bin2hex(random_bytes(32));
    if (@file_put_contents($file, "<?php return '" . $generated . "';\n", LOCK_EX) !== false) {
        return $secret = $generated;
    }
    // storage/ not writable: deterministic fallback (weaker, but stable).
    return $secret = hash('sha256', DB_NAME . DB_USER . DB_PASSWORD . __DIR__);
}

function client_ip(): string
{
    static $ip = null;
    return $ip ??= resolve_client_ip(
        (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
        (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''),
        array_filter(array_map('trim', explode(',', (string) TRUSTED_PROXIES)))
    );
}

/**
 * Who is really asking?
 *
 * REMOTE_ADDR is the machine that opened the connection – it cannot be
 * faked. The header X-Forwarded-For can be set by anybody, so it is
 * ignored … unless the connection comes from a proxy WE operate
 * (TRUSTED_PROXIES in the configuration: a reverse proxy, a load
 * balancer, a CDN). Then the proxy's information is used: the list is
 * read from the right, and the first address that is not one of our
 * proxies is the client. Everything further left was supplied by the
 * client and is not believed.
 *
 * @param list<string> $trusted IP addresses or IPv4/IPv6 ranges in CIDR form (10.0.0.0/8)
 */
function resolve_client_ip(string $remote, string $forwardedFor, array $trusted): string
{
    if (!$trusted || $forwardedFor === '' || !ip_in_ranges($remote, $trusted)) {
        return $remote;
    }
    foreach (array_reverse(array_map('trim', explode(',', $forwardedFor))) as $candidate) {
        if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
            return $remote;                 // garbage in the header: fall back to what we know
        }
        if (!ip_in_ranges($candidate, $trusted)) {
            return $candidate;
        }
    }
    return $remote;
}

/** @param list<string> $ranges */
function ip_in_ranges(string $ip, array $ranges): bool
{
    $address = @inet_pton($ip);
    if ($address === false) {
        return false;
    }
    foreach ($ranges as $range) {
        [$base, $bits] = array_pad(explode('/', $range, 2), 2, null);
        $network = @inet_pton((string) $base);
        if ($network === false || strlen($network) !== strlen($address)) {
            continue;                       // not an address, or IPv4 vs IPv6
        }
        $bits  = $bits === null ? strlen($address) * 8 : max(0, min(strlen($address) * 8, (int) $bits));
        $bytes = intdiv($bits, 8);
        $rest  = $bits % 8;
        if (substr($address, 0, $bytes) !== substr($network, 0, $bytes)) {
            continue;
        }
        if ($rest === 0 || (ord($address[$bytes]) >> (8 - $rest)) === (ord($network[$bytes]) >> (8 - $rest))) {
            return true;
        }
    }
    return false;
}

/**
 * The IP address never goes into the database in plain text.
 * HMAC = hash with a secret key: without APP_SECRET nobody can
 * test "which IP produces this hash?".
 */
function ip_hash(): string
{
    return hash_hmac('sha256', client_ip(), app_secret());
}

// ---------- Security log ----------

/**
 * One line per security-relevant event in storage/logs/security.log:
 * failed and throttled logins, changed passwords and addresses, linked
 * sign-ins, deleted accounts, rejected forms and webhooks, rate limits.
 *
 * What is NOT written: passwords, tokens, session ids, plain IP
 * addresses (only the first characters of their HMAC, enough to see
 * "the same sender again") and e-mail addresses of unknown accounts.
 *
 * @param array<string, scalar|null> $context small facts about the event
 */
function security_log(string $event, array $context = []): void
{
    $context = ['ip' => substr(ip_hash(), 0, 12)] + $context;
    if (!isset($context['user']) && isset($_SESSION['user_id'])) {
        $context['user'] = (int) $_SESSION['user_id'];
    }
    // json_encode() keeps every value on one line – nobody can forge a
    // second log entry by putting a line break into a form field.
    $line = date('Y-m-d\TH:i:sP') . ' ' . str_pad($event, 22) . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    @file_put_contents(QP_ROOT . '/storage/logs/security.log', $line, FILE_APPEND | LOCK_EX);
}

/** Short pseudonym of an e-mail address for the log (same address = same value). */
function log_alias(string $email): string
{
    return substr(hash_hmac('sha256', mb_strtolower($email), app_secret()), 0, 12);
}

// ---------- Session ----------

/**
 * Start the session with safe cookie settings. Called by every page
 * that needs a login, flash messages or CSRF protection.
 * Also the central CSRF gate: every POST request is verified here,
 * so no page can forget the check.
 */
function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name('quickpoll_session');
    ini_set('session.use_strict_mode', '1');   // reject session ids the server did not issue
    // PHP may delete session files this many seconds after the last use;
    // without this line the default (24 minutes) would beat our idle timeout.
    ini_set('session.gc_maxlifetime', (string) SESSION_IDLE_SECONDS);
    session_set_cookie_params([
        'lifetime' => 0,                // cookie dies with the browser
        'path'     => '/',
        'httponly' => true,             // JavaScript cannot read the cookie
        'samesite' => 'Lax',            // not sent along with foreign POSTs
        'secure'   => is_https(),       // HTTPS only (when available)
    ]);
    session_start();

    // Idle timeout: a forgotten browser tab does not stay logged in forever.
    $lastSeen = $_SESSION['last_seen'] ?? null;
    if ($lastSeen !== null && time() - $lastSeen > SESSION_IDLE_SECONDS) {
        if (isset($_SESSION['sid'])) {
            db()->prepare('DELETE FROM sitzung WHERE id = ?')->execute([hash('sha256', (string) $_SESSION['sid'])]);
        }
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last_seen'] = time();

    if (is_post()) {
        csrf_verify();
    }
}

// ---------- CSRF ----------
// A random token lives in the session and travels as hidden field in
// every form. A foreign website can make the browser send a POST with
// the session cookie – but it cannot know the token.

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Hidden input for forms: <?= csrf_field() ?> */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    // hash_equals() compares in constant time (no timing side channel).
    if (!is_string($sent) || $sent === '' || !hash_equals(csrf_token(), $sent)) {
        security_log('csrf_rejected', ['page' => basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''))]);
        http_response_code(419);
        render_message_page(
            t('Sitzung abgelaufen'),
            t('text.security.1')
        );
        exit;
    }
}

// ---------- Login state ----------

/**
 * The logged-in user's database row, or null. Loaded once per request.
 *
 * Being logged in needs TWO things: the user id in the PHP session and
 * a matching row in the table `sitzung`. Deleting that row ends the
 * session from anywhere ("log out my other devices", password reset).
 *
 * @return array<string, mixed>|null
 */
function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = null;
        if (isset($_SESSION['user_id'])) {
            $stmt = db()->prepare('SELECT * FROM nutzer WHERE id = ?');
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch() ?: null;

            if ($user !== null && !session_is_registered((int) $user['id'])) {
                $user = null;   // revoked, or a session from before sessions were tracked
            }
            if ($user === null) {
                // Account deleted or session revoked: drop the login, keep
                // the (now anonymous) session for flash messages.
                unset($_SESSION['user_id'], $_SESSION['sid'], $_SESSION['sid_seen']);
            }
        }
    }
    return $user;
}

/**
 * Access guard for protected pages (used via includes/auth.php).
 * @return array<string, mixed> the logged-in user
 */
function require_login(): array
{
    session_boot();
    $user = current_user();
    if ($user === null) {
        redirect('login.php');
    }
    // A language or theme switch on this device is saved to the account.
    preferences_sync($user);
    return $user;
}

/** Mark the session as logged in (after password check, registration or Google sign-in). */
function login_user(int $userId): void
{
    // New session id on every privilege change – an id an attacker
    // planted before the login becomes worthless (session fixation).
    session_regenerate_id(true);
    if (isset($_SESSION['sid'])) {
        // Logging in again in the same browser replaces the old session row.
        db()->prepare('DELETE FROM sitzung WHERE id = ?')->execute([hash('sha256', (string) $_SESSION['sid'])]);
    }
    $_SESSION['user_id'] = $userId;
    session_register($userId);
    db()->prepare('UPDATE nutzer SET letzter_login = NOW() WHERE id = ?')->execute([$userId]);

    if (random_int(1, 50) === 1) {
        maintenance_purge();   // housekeeping now and then (see includes/account.php)
    }

    // The account's saved language and theme follow the user to this device.
    $stmt = db()->prepare('SELECT id, sprache, design FROM nutzer WHERE id = ?');
    $stmt->execute([$userId]);
    if ($row = $stmt->fetch()) {
        preferences_sync($row, true);
    }
}

function logout_user(): void
{
    if (isset($_SESSION['sid'])) {
        db()->prepare('DELETE FROM sitzung WHERE id = ?')->execute([hash('sha256', (string) $_SESSION['sid'])]);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?: 'Lax',
        ]);
    }
    session_destroy();
}

// ---------- Visible, revocable sessions ----------
// PHP's own session storage cannot answer "where am I logged in?" and
// cannot end a session on another device. So every login also gets a
// row in `sitzung`. The row id is the SHA-256 of a random token that
// only lives inside the PHP session – a database leak does not hand
// out usable session ids.

/** Create the `sitzung` row for the session that was just logged in. */
function session_register(int $userId): void
{
    $token = bin2hex(random_bytes(32));
    $_SESSION['sid']      = $token;
    $_SESSION['sid_seen'] = time();

    db()->prepare('INSERT INTO sitzung (id, nutzer_id, ip_hash, user_agent) VALUES (?, ?, ?, ?)')
        ->execute([hash('sha256', $token), $userId, ip_hash(), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);

    // Housekeeping: sessions that idled out can never come back.
    db()->exec('DELETE FROM sitzung WHERE zuletzt_aktiv < NOW() - INTERVAL ' . (int) SESSION_IDLE_SECONDS . ' SECOND');
}

/** Is this PHP session still backed by a `sitzung` row of that user? */
function session_is_registered(int $userId): bool
{
    $token = $_SESSION['sid'] ?? null;
    if (!is_string($token) || $token === '') {
        return false;
    }
    $id   = hash('sha256', $token);
    $stmt = db()->prepare('SELECT 1 FROM sitzung WHERE id = ? AND nutzer_id = ?');
    $stmt->execute([$id, $userId]);
    if (!$stmt->fetchColumn()) {
        return false;
    }
    // "Last active" is written at most once a minute, not on every request.
    if (time() - (int) ($_SESSION['sid_seen'] ?? 0) >= 60) {
        db()->prepare('UPDATE sitzung SET zuletzt_aktiv = NOW() WHERE id = ?')->execute([$id]);
        $_SESSION['sid_seen'] = time();
    }
    return true;
}

/** The current session's row id (hash) – to mark "this device" in the list. */
function session_current_id(): string
{
    return isset($_SESSION['sid']) ? hash('sha256', (string) $_SESSION['sid']) : '';
}

/** End every session of the user except the one making this request. */
function sessions_end_others(int $userId): int
{
    $stmt = db()->prepare('DELETE FROM sitzung WHERE nutzer_id = ? AND id <> ?');
    $stmt->execute([$userId, session_current_id()]);
    return $stmt->rowCount();
}

/** End every session of the user (password reset: whoever is logged in must leave). */
function sessions_end_all(int $userId): void
{
    db()->prepare('DELETE FROM sitzung WHERE nutzer_id = ?')->execute([$userId]);
}

/**
 * Readable name for a session from its User-Agent header, e.g.
 * "Firefox auf Windows". Only a display hint – the header is sent by
 * the client and can say anything, so it is escaped on output and
 * never used for decisions.
 */
function device_label(string $userAgent): string
{
    $browser = match (true) {
        str_contains($userAgent, 'Edg/')     => 'Edge',
        str_contains($userAgent, 'OPR/')     => 'Opera',
        str_contains($userAgent, 'Firefox/') => 'Firefox',
        str_contains($userAgent, 'Chrome/')  => 'Chrome',
        str_contains($userAgent, 'Safari/')  => 'Safari',
        default                              => '',
    };
    $system = match (true) {
        str_contains($userAgent, 'Android')   => 'Android',
        str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad') => 'iOS',
        str_contains($userAgent, 'Windows')   => 'Windows',
        str_contains($userAgent, 'Mac OS X')  => 'macOS',
        str_contains($userAgent, 'Linux')     => 'Linux',
        default                               => '',
    };
    if ($browser !== '' && $system !== '') {
        return t('{browser} auf {system}', ['browser' => $browser, 'system' => $system]);
    }
    return $browser . $system !== '' ? $browser . $system : t('Unbekanntes Gerät');
}

// ---------- E-mail verification ----------

/** @param array<string, mixed> $user */
function is_verified(array $user): bool
{
    return $user['email_bestaetigt_am'] !== null;
}

/** @param array<string, mixed> $user */
function has_password(array $user): bool
{
    return $user['passwort_hash'] !== null && $user['passwort_hash'] !== '';
}

// ---------- One-time tokens sent by e-mail ----------
// Same idea as the password reset: the link carries a random token,
// the database only its SHA-256 hash. Purposes: 'email_verify',
// 'email_change' (the new address travels in `daten`).

/** Create a token; older unused tokens for the same purpose stop working. */
function account_token_create(int $userId, string $purpose, int $validMinutes, ?string $data = null): string
{
    $token = bin2hex(random_bytes(32));
    $pdo   = db();
    $pdo->prepare('DELETE FROM konto_token WHERE nutzer_id = ? AND zweck = ?')->execute([$userId, $purpose]);
    $pdo->prepare(
        'INSERT INTO konto_token (nutzer_id, zweck, token_hash, daten, laeuft_ab)
         VALUES (?, ?, ?, ?, NOW() + INTERVAL ' . $validMinutes . ' MINUTE)'
    )->execute([$userId, $purpose, hash('sha256', $token), $data]);
    return $token;
}

/**
 * Look a token up. Valid = known, not expired, not used.
 * @return array<string, mixed>|null the konto_token row
 */
function account_token_find(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM konto_token WHERE token_hash = ? AND laeuft_ab > NOW() AND benutzt_am IS NULL');
    $stmt->execute([hash('sha256', $token)]);
    return $stmt->fetch() ?: null;
}

/**
 * Mark a token as used. Returns false when another request was faster –
 * the WHERE clause makes "use once" hold even for two parallel clicks.
 */
function account_token_use(int $tokenId): bool
{
    $stmt = db()->prepare('UPDATE konto_token SET benutzt_am = NOW() WHERE id = ? AND benutzt_am IS NULL');
    $stmt->execute([$tokenId]);
    return $stmt->rowCount() === 1;
}

// ---------- Rate limits ----------
// One small table for everything that must not be repeated endlessly:
// registrations, mails we send, sign-in starts. (The login itself keeps
// its own table `login_versuch`, because it counts FAILURES per e-mail.)

/**
 * Count one attempt and say whether it is still within the limit.
 * $key is what the limit is counted per – an IP address or a user id.
 * It is stored as HMAC, never in plain text.
 */
function rate_limit_allow(string $action, string $key, int $max, int $windowMinutes): bool
{
    $pdo  = db();
    $hash = hash_hmac('sha256', $action . '|' . $key, app_secret());

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM rate_limit
         WHERE aktion = ? AND schluessel = ? AND zeitpunkt > NOW() - INTERVAL ' . $windowMinutes . ' MINUTE'
    );
    $stmt->execute([$action, $hash]);
    if ((int) $stmt->fetchColumn() >= $max) {
        security_log('rate_limited', ['action' => $action]);
        return false;
    }

    $pdo->prepare('INSERT INTO rate_limit (aktion, schluessel) VALUES (?, ?)')->execute([$action, $hash]);
    if (random_int(1, 50) === 1) {   // housekeeping now and then
        $pdo->exec('DELETE FROM rate_limit WHERE zeitpunkt < NOW() - INTERVAL 1 DAY');
    }
    return true;
}

/**
 * Origin of Google's sign-in page for the Content-Security-Policy.
 * Outside production a test server can stand in for Google
 * (see tests/mock_oidc.php) – never in production.
 */
function google_origin(): string
{
    if (APP_ENV !== 'production' && defined('GOOGLE_TEST_BASE')) {
        return (string) GOOGLE_TEST_BASE;
    }
    return 'https://accounts.google.com';
}

// ---------- Login throttling (brute-force protection) ----------
// Failed attempts are stored in `login_versuch`. Limits within the
// time window:  5 per e-mail address  |  20 per IP address.

const LOGIN_MAX_PER_EMAIL = 5;
const LOGIN_MAX_PER_IP    = 20;
const LOGIN_WINDOW_MINUTES = 15;

function login_is_throttled(string $email): bool
{
    $stmt = db()->prepare(
        'SELECT
            COALESCE(SUM(email = ?), 0)   AS by_email,
            COALESCE(SUM(ip_hash = ?), 0) AS by_ip
         FROM login_versuch
         WHERE zeitpunkt > NOW() - INTERVAL ' . LOGIN_WINDOW_MINUTES . ' MINUTE
           AND (email = ? OR ip_hash = ?)'
    );
    $stmt->execute([$email, ip_hash(), $email, ip_hash()]);
    $row = $stmt->fetch();

    return (int) $row['by_email'] >= LOGIN_MAX_PER_EMAIL
        || (int) $row['by_ip'] >= LOGIN_MAX_PER_IP;
}

function login_record_failure(string $email): void
{
    db()->prepare('INSERT INTO login_versuch (email, ip_hash) VALUES (?, ?)')
        ->execute([mb_substr($email, 0, 255), ip_hash()]);
    // Housekeeping: rows older than a day are useless, remove them on the way.
    db()->exec('DELETE FROM login_versuch WHERE zeitpunkt < NOW() - INTERVAL 1 DAY');
}

function login_clear_failures(string $email): void
{
    db()->prepare('DELETE FROM login_versuch WHERE email = ?')->execute([$email]);
}

// ---------- Plans ----------

/**
 * Does the account have Pro access? `abo_status` is the copy of what
 * Stripe said last (see includes/billing.php).
 *
 * Safety net: a cancellation that was scheduled for a moment in the
 * past ends access even if Stripe's final webhook never arrived
 * (one day of tolerance for clocks and late events).
 *
 * @param array<string, mixed> $user
 */
function is_pro(array $user): bool
{
    if (($user['abo_status'] ?? '') !== 'aktiv') {
        return false;
    }
    $endsAt = $user['abo_endet_am'] ?? null;
    return $endsAt === null || strtotime((string) $endsAt) > time() - 86400;
}

function count_user_surveys(int $userId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM fragebogen WHERE nutzer_id = ?');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Free accounts may create FREE_SURVEY_LIMIT surveys, Pro accounts
 * unlimited. This check runs on the SERVER before every insert –
 * hiding a button in the UI is never a security measure.
 *
 * @param array<string, mixed> $user
 */
function can_create_survey(array $user): bool
{
    return is_pro($user) || count_user_surveys((int) $user['id']) < (int) FREE_SURVEY_LIMIT;
}

/** True when real Stripe keys are configured and the SDK is present. */
function stripe_is_configured(): bool
{
    return STRIPE_SECRET_KEY !== ''
        && !str_contains(STRIPE_SECRET_KEY, 'XXXX')
        && STRIPE_PRICE_ID !== ''
        && !str_contains(STRIPE_PRICE_ID, 'XXXX')
        && is_file(QP_ROOT . '/lib/stripe-php/init.php');
}

/**
 * Start the session only if the browser already has our session
 * cookie. Public pages use this: anonymous visitors get NO cookie,
 * logged-in owners are still recognised (e.g. for the survey preview).
 */
function session_boot_if_cookie(): void
{
    if (isset($_COOKIE['quickpoll_session'])) {
        session_boot();
    }
}
