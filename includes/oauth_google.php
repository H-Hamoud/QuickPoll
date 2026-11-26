<?php
declare(strict_types=1);
// ============================================================
//  oauth_google.php – "Mit Google fortfahren"
//
//  OpenID Connect, authorization-code flow with PKCE:
//
//    1. auth_google.php sends the browser to Google with three
//       random values kept in the session:
//         state    – proves the answer belongs to OUR request (CSRF)
//         nonce    – is repeated inside the ID token (replay)
//         verifier – PKCE: only who started the flow can finish it
//    2. Google sends the browser back to auth_google_callback.php
//       with a one-time code.
//    3. The SERVER exchanges the code for an ID token (a signed JWT)
//       and checks it itself: signature against Google's public keys,
//       issuer, audience, expiry, nonce.
//
//  No library: the checks are short enough to read – and to test
//  (tests/oauth_test.php feeds this file forged tokens).
//
//  QuickPoll keeps only the provider's user id ("sub") and the
//  e-mail address. No access token, no refresh token, no profile.
// ============================================================

const GOOGLE_ISSUERS       = ['https://accounts.google.com', 'accounts.google.com'];
const GOOGLE_JWKS_TTL      = 21600;   // refresh Google's public keys every 6 hours
const OAUTH_STATE_SECONDS  = 600;     // a started sign-in must finish within 10 minutes
const OAUTH_CLOCK_SKEW     = 60;      // tolerated difference between server clocks

/** The button only exists when the sign-in can really work. */
function google_is_configured(): bool
{
    return GOOGLE_CLIENT_ID !== '' && GOOGLE_CLIENT_SECRET !== '' && function_exists('openssl_verify');
}

/**
 * Google's endpoints. Outside production a local test server can stand
 * in (GOOGLE_TEST_BASE, see tests/mock_oidc.php) – in production that
 * setting is ignored, whatever config.php says.
 *
 * @return array{auth: string, token: string, jwks: string, issuers: list<string>}
 */
function google_endpoints(): array
{
    if (APP_ENV !== 'production' && defined('GOOGLE_TEST_BASE')) {
        $base = rtrim((string) GOOGLE_TEST_BASE, '/');
        return ['auth' => $base . '/authorize', 'token' => $base . '/token', 'jwks' => $base . '/jwks', 'issuers' => [$base]];
    }
    return [
        'auth'    => 'https://accounts.google.com/o/oauth2/v2/auth',
        'token'   => 'https://oauth2.googleapis.com/token',
        'jwks'    => 'https://www.googleapis.com/oauth2/v3/certs',
        'issuers' => GOOGLE_ISSUERS,
    ];
}

function google_redirect_uri(): string
{
    return base_url() . '/auth_google_callback.php';
}

// ---------- Base64url (the alphabet JWTs use) ----------

function base64url_encode(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

/** Returns null for anything that is not clean base64url. */
function base64url_decode(string $text): ?string
{
    if ($text === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $text)) {
        return null;
    }
    $bytes = base64_decode(strtr($text, '-_', '+/'), true);
    return $bytes === false ? null : $bytes;
}

// ---------- Step 1: send the browser to Google ----------

/**
 * Remember state, nonce and PKCE verifier in the session and build the
 * address of Google's sign-in page.
 *
 * @param string   $mode   'login'  sign in / create account
 *                         'link'   attach Google to the logged-in account
 *                         'reauth' prove once more that it is you (before
 *                                  deleting an account that has no password)
 * @param int|null $userId the logged-in user in link and reauth mode
 */
function google_start(string $mode, ?int $userId = null): string
{
    $state    = bin2hex(random_bytes(16));
    $nonce    = bin2hex(random_bytes(16));
    $verifier = base64url_encode(random_bytes(32));

    $_SESSION['oauth_google'] = [
        'state'    => $state,
        'nonce'    => $nonce,
        'verifier' => $verifier,
        'mode'     => $mode,
        'user_id'  => $userId,
        'started'  => time(),
    ];

    return google_endpoints()['auth'] . '?' . http_build_query([
        'client_id'             => GOOGLE_CLIENT_ID,
        'redirect_uri'          => google_redirect_uri(),
        'response_type'         => 'code',
        'scope'                 => 'openid email',          // nothing more than we store
        'state'                 => $state,
        'nonce'                 => $nonce,
        'code_challenge'        => base64url_encode(hash('sha256', $verifier, true)),
        'code_challenge_method' => 'S256',
    ] + ($mode === 'reauth'
        // "Sign in again now": Google must ask for the password (or its
        // second factor) and reports the moment in the claim auth_time.
        ? ['prompt' => 'login', 'max_age' => 0]
        : ['prompt' => 'select_account']));
}

/** How long a fresh Google sign-in counts as "just confirmed" (seconds). */
const REAUTH_SECONDS = 600;

/**
 * Did the sign-in in this token really happen just now? Only then
 * does a token prove more than the session cookie already does.
 *
 * @param array<string, mixed> $claims verified claims of the ID token
 */
function google_is_fresh_login(array $claims, int $now): bool
{
    $authTime = $claims['auth_time'] ?? null;
    return is_int($authTime) && $authTime <= $now + OAUTH_CLOCK_SKEW && $now - $authTime <= REAUTH_SECONDS;
}

/** Is a fresh confirmation for this account stored in the session? */
function reauth_is_valid(int $userId): bool
{
    $reauth = $_SESSION['reauth'] ?? null;
    return is_array($reauth) && (int) ($reauth['user_id'] ?? 0) === $userId && (int) ($reauth['until'] ?? 0) > time();
}

/**
 * Take the started flow out of the session (it works once) and check
 * the state that came back. Returns the flow or null.
 *
 * @return array{state: string, nonce: string, verifier: string, mode: string, user_id: int|null, started: int}|null
 */
function google_take_flow(string $returnedState): ?array
{
    $flow = $_SESSION['oauth_google'] ?? null;
    unset($_SESSION['oauth_google']);

    if (!is_array($flow) || !isset($flow['state'], $flow['nonce'], $flow['verifier'], $flow['mode'], $flow['started'])) {
        return null;
    }
    if (time() - (int) $flow['started'] > OAUTH_STATE_SECONDS) {
        return null;
    }
    if ($returnedState === '' || !hash_equals((string) $flow['state'], $returnedState)) {
        return null;
    }
    return $flow;
}

// ---------- Step 3: code → ID token → verified claims ----------

/**
 * HTTP request to Google. TLS certificates are verified (PHP's default,
 * stated explicitly here), redirects are not followed, time is limited.
 *
 * @param array<string, string>|null $post form fields (null = GET)
 * @return array<string, mixed>|null decoded JSON or null on any failure
 */
function google_http(string $url, ?array $post = null): ?array
{
    $isTest = APP_ENV !== 'production' && defined('GOOGLE_TEST_BASE');
    if (!$isTest && !str_starts_with($url, 'https://')) {
        return null;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($ch);
        curl_close($ch);
        if ($body === false || $status !== 200) {
            error_log('Google sign-in: HTTP request failed (' . $status . ') ' . $error);
            return null;
        }
    } else {
        // Fallback without the curl extension.
        $context = stream_context_create([
            'http' => [
                'method'          => $post !== null ? 'POST' : 'GET',
                'header'          => "Accept: application/json\r\n" . ($post !== null ? "Content-Type: application/x-www-form-urlencoded\r\n" : ''),
                'content'         => $post !== null ? http_build_query($post) : '',
                'timeout'         => 10,
                'follow_location' => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            error_log('Google sign-in: HTTP request failed');
            return null;
        }
    }

    $data = json_decode((string) $body, true);
    return is_array($data) ? $data : null;
}

/** Exchange the one-time code for the ID token (server to server). */
function google_exchange_code(string $code, string $verifier): ?string
{
    $response = google_http(google_endpoints()['token'], [
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,   // never leaves the server
        'redirect_uri'  => google_redirect_uri(),
        'code_verifier' => $verifier,
    ]);
    $token = $response['id_token'] ?? null;
    return is_string($token) ? $token : null;
}

/**
 * Google's public signing keys (JWKS), cached in storage/cache/.
 * @return array<string, mixed>|null ['keys' => [...]]
 */
function google_jwks(bool $forceRefresh = false): ?array
{
    $file = QP_ROOT . '/storage/cache/google_jwks.json';
    $url  = google_endpoints()['jwks'];

    if (!$forceRefresh && is_file($file)) {
        $cached = json_decode((string) @file_get_contents($file), true);
        if (is_array($cached) && ($cached['url'] ?? '') === $url && time() - (int) ($cached['fetched'] ?? 0) < GOOGLE_JWKS_TTL) {
            return $cached['jwks'];
        }
    }

    $jwks = google_http($url);
    if (!isset($jwks['keys']) || !is_array($jwks['keys'])) {
        return null;
    }
    @file_put_contents($file, json_encode(['url' => $url, 'fetched' => time(), 'jwks' => $jwks]), LOCK_EX);
    return $jwks;
}

/**
 * Turn an RSA key in JWK form (modulus n, exponent e) into the PEM
 * text OpenSSL understands. A PEM public key is a small DER structure:
 *
 *   SEQUENCE { SEQUENCE { OID rsaEncryption, NULL },
 *              BIT STRING { SEQUENCE { INTEGER n, INTEGER e } } }
 *
 * @param array<string, mixed> $jwk
 */
function jwk_to_pem(array $jwk): ?string
{
    if (($jwk['kty'] ?? '') !== 'RSA' || !is_string($jwk['n'] ?? null) || !is_string($jwk['e'] ?? null)) {
        return null;
    }
    $n = base64url_decode($jwk['n']);
    $e = base64url_decode($jwk['e']);
    // Anything below 2048 bits is not a key Google would use.
    if ($n === null || $e === null || strlen(ltrim($n, "\0")) < 256) {
        return null;
    }

    $length = static function (int $len): string {
        if ($len < 128) {
            return chr($len);
        }
        $bytes = ltrim(pack('N', $len), "\0");
        return chr(0x80 | strlen($bytes)) . $bytes;
    };
    $integer = static function (string $bytes) use ($length): string {
        $bytes = ltrim($bytes, "\0");
        if ($bytes === '' || ord($bytes[0]) > 0x7f) {
            $bytes = "\0" . $bytes;   // keep the number positive
        }
        return "\x02" . $length(strlen($bytes)) . $bytes;
    };
    $sequence = static fn(string $content): string => "\x30" . $length(strlen($content)) . $content;

    $rsaKey    = $sequence($integer($n) . $integer($e));
    $algorithm = hex2bin('300d06092a864886f70d0101010500');          // rsaEncryption + NULL
    $bitString = "\x03" . $length(strlen($rsaKey) + 1) . "\x00" . $rsaKey;
    $der       = $sequence($algorithm . $bitString);

    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/**
 * Verify an ID token. Returns the claims or null – every single check
 * has to pass, there is no "mostly valid".
 *
 * Pure function (no network, no clock, no session): all inputs are
 * parameters, which makes it testable with forged tokens.
 *
 * @param array<string, mixed> $jwks    ['keys' => [...]]
 * @param list<string>         $issuers accepted values of "iss"
 * @return array<string, mixed>|null
 */
function google_verify_id_token(string $jwt, array $jwks, string $clientId, string $nonce, int $now, array $issuers = GOOGLE_ISSUERS): ?array
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return null;
    }
    [$headerB64, $payloadB64, $signatureB64] = $parts;

    $header    = json_decode((string) base64url_decode($headerB64), true);
    $claims    = json_decode((string) base64url_decode($payloadB64), true);
    $signature = base64url_decode($signatureB64);
    if (!is_array($header) || !is_array($claims) || $signature === null) {
        return null;
    }

    // --- 1. Algorithm: exactly RS256. The token says which algorithm it
    // uses – if we believed it, "none" (no signature) or "HS256" (our
    // public key as shared secret) would let anyone forge tokens.
    if (($header['alg'] ?? null) !== 'RS256') {
        return null;
    }

    // --- 2. Signature with the key the token names (kid).
    $kid = $header['kid'] ?? null;
    $pem = null;
    foreach ($jwks['keys'] ?? [] as $key) {
        if (is_array($key) && is_string($kid) && ($key['kid'] ?? null) === $kid
            && ($key['use'] ?? 'sig') === 'sig' && in_array($key['alg'] ?? 'RS256', ['RS256'], true)) {
            $pem = jwk_to_pem($key);
            break;
        }
    }
    if ($pem === null || openssl_verify($headerB64 . '.' . $payloadB64, $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) {
        return null;
    }

    // --- 3. Issuer: the token comes from Google.
    if (!is_string($claims['iss'] ?? null) || !in_array($claims['iss'], $issuers, true)) {
        return null;
    }

    // --- 4. Audience: the token was issued for THIS app. Without the
    // check, a token a user gave to any other website could be replayed here.
    $aud = $claims['aud'] ?? null;
    if (is_array($aud)) {
        if (!in_array($clientId, $aud, true) || ($claims['azp'] ?? null) !== $clientId) {
            return null;
        }
    } elseif ($aud !== $clientId) {
        return null;
    }
    if (isset($claims['azp']) && $claims['azp'] !== $clientId) {
        return null;
    }

    // --- 5. Time: not expired, not issued in the future.
    if (!is_int($claims['exp'] ?? null) || $claims['exp'] <= $now - OAUTH_CLOCK_SKEW) {
        return null;
    }
    if (!is_int($claims['iat'] ?? null) || $claims['iat'] > $now + OAUTH_CLOCK_SKEW) {
        return null;
    }

    // --- 6. Nonce: the token answers the sign-in WE started.
    if ($nonce === '' || !is_string($claims['nonce'] ?? null) || !hash_equals($nonce, $claims['nonce'])) {
        return null;
    }

    // --- 7. The identity itself.
    if (!is_string($claims['sub'] ?? null) || $claims['sub'] === '' || strlen($claims['sub']) > 255) {
        return null;
    }
    return $claims;
}

/**
 * Steps 2 + 3 together: code → verified claims.
 * @return array<string, mixed>|null
 */
function google_fetch_identity(string $code, string $verifier, string $nonce): ?array
{
    $idToken = google_exchange_code($code, $verifier);
    if ($idToken === null) {
        return null;
    }
    $issuers = google_endpoints()['issuers'];

    $jwks   = google_jwks();
    $claims = $jwks !== null ? google_verify_id_token($idToken, $jwks, GOOGLE_CLIENT_ID, $nonce, time(), $issuers) : null;
    if ($claims === null) {
        // Google rotates its keys: one retry with a fresh key set.
        $jwks   = google_jwks(true);
        $claims = $jwks !== null ? google_verify_id_token($idToken, $jwks, GOOGLE_CLIENT_ID, $nonce, time(), $issuers) : null;
    }
    return $claims;
}

/** Is the e-mail address in the token confirmed by Google? */
function google_email_verified(array $claims): bool
{
    $email = $claims['email'] ?? null;
    return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
        && in_array($claims['email_verified'] ?? false, [true, 'true'], true);
}

// ---------- The button ----------

/**
 * "Mit Google fortfahren" on the login and registration page.
 * Prints nothing when the sign-in is not configured – a button that
 * cannot work is worse than no button.
 */
function render_google_button(string $mode): void
{
    if (!google_is_configured()) {
        return;
    }
    ?>
<form method="post" action="auth_google.php" class="provider-form">
    <?= csrf_field() ?>
    <input type="hidden" name="mode" value="<?= e($mode) ?>">
    <button type="submit" class="btn btn-provider btn-block">
        <span class="provider-initial" aria-hidden="true">G</span> <?= e(t('Mit Google fortfahren')) ?>
    </button>
</form>
<p class="auth-divider"><span><?= e(t('oder mit E-Mail')) ?></span></p>
    <?php
}
