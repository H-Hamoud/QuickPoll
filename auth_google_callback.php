<?php
declare(strict_types=1);
// ============================================================
//  auth_google_callback.php – Google sends the browser back here
//
//  Everything that arrives in the address bar (code, state) is
//  untrusted. The identity is only believed after the server has
//  fetched the ID token itself and verified it (oauth_google.php).
//
//  Which account does a Google identity belong to?
//    1. The Google user id ("sub") is already linked → that account.
//    2. Link mode (started from settings while logged in) → attach
//       it to the logged-in account.
//    3. Nobody has that e-mail address → create a new account.
//    4. An account with that e-mail exists but is NOT linked →
//       refuse. Matching by e-mail alone would hand the account to
//       whoever controls a Google account with that address; the
//       owner signs in with the password and links Google in the
//       settings instead.
// ============================================================

require_once __DIR__ . '/includes/bootstrap.php';
session_boot();

if (!google_is_configured()) {
    redirect('login.php');
}

// The flow is taken out of the session first: whatever happens below,
// this state cannot be used a second time.
$flow = google_take_flow(get_string('state', 64));
$back = in_array($flow['mode'] ?? 'login', ['link', 'reauth'], true) ? 'settings.php' : 'login.php';

/** One message for every failure – details go to the log, not to the screen. */
$fail = static function (string $reason, ?string $message = null) use ($back): never {
    error_log('Google sign-in failed: ' . $reason);
    security_log('google_failed', ['reason' => $reason]);
    flash('error', $message ?? t('Die Anmeldung mit Google hat nicht geklappt. Bitte versuche es noch einmal.'));
    redirect($back);
};

if ($flow === null) {
    $fail('missing, expired or wrong state');
}
if (get_string('error', 60) !== '') {
    // The user pressed "Cancel" at Google – not an error worth a red box.
    flash('warning', t('Die Anmeldung mit Google wurde abgebrochen.'));
    redirect($back);
}
$code = get_string('code', 2048);
if ($code === '') {
    $fail('no code');
}

$claims = google_fetch_identity($code, $flow['verifier'], $flow['nonce']);
if ($claims === null) {
    $fail('token exchange or ID token verification failed');
}
if (!google_email_verified($claims)) {
    $fail('e-mail not verified at Google', t('Deine E-Mail-Adresse ist bei Google nicht bestätigt. Bitte registriere dich mit E-Mail und Passwort.'));
}

$sub   = (string) $claims['sub'];
$email = mb_strtolower((string) $claims['email']);
$pdo   = db();

$stmt = $pdo->prepare("SELECT nutzer_id FROM nutzer_identitaet WHERE anbieter = 'google' AND anbieter_id = ?");
$stmt->execute([$sub]);
$linkedUserId = $stmt->fetchColumn();
$linkedUserId = $linkedUserId === false ? null : (int) $linkedUserId;

// ---------- Reauth mode: "it is really me, right now" ----------
if ($flow['mode'] === 'reauth') {
    $user = current_user();
    if ($user === null || (int) $user['id'] !== (int) $flow['user_id']) {
        $fail('reauth: session changed during the flow');
    }
    // It must be the Google account that is linked to THIS QuickPoll
    // account, and the sign-in must have happened just now.
    if ($linkedUserId !== (int) $user['id']) {
        $fail('reauth: different Google account', t('Das war ein anderes Google-Konto als das verknüpfte.'));
    }
    if (!google_is_fresh_login($claims, time())) {
        $fail('reauth: sign-in is not fresh (auth_time)');
    }
    $_SESSION['reauth'] = ['user_id' => (int) $user['id'], 'until' => time() + REAUTH_SECONDS];
    security_log('reauth_ok', ['via' => 'google']);
    flash('success', t('Bestätigt. Du kannst das Konto jetzt innerhalb von 10 Minuten löschen.'));
    redirect('settings.php#loeschen');
}

// ---------- Link mode: attach Google to the logged-in account ----------
if ($flow['mode'] === 'link') {
    $user = current_user();
    // The account that started the flow must still be the one logged in.
    if ($user === null || (int) $user['id'] !== (int) $flow['user_id']) {
        $fail('link: session changed during the flow');
    }
    if ($linkedUserId !== null && $linkedUserId !== (int) $user['id']) {
        $fail('link: Google account belongs to another user', t('Dieses Google-Konto ist bereits mit einem anderen QuickPoll-Konto verknüpft.'));
    }
    if ($linkedUserId === null) {
        try {
            $pdo->prepare("INSERT INTO nutzer_identitaet (nutzer_id, anbieter, anbieter_id, email) VALUES (?, 'google', ?, ?)")
                ->execute([$user['id'], $sub, $email]);
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            // UNIQUE (nutzer_id, anbieter): the account already has a Google link.
            $fail('link: account already has a Google identity', t('Mit diesem Konto ist bereits ein Google-Konto verknüpft.'));
        }
        security_log('provider_linked', ['provider' => 'google']);
        send_account_mail($user, 'provider_linked', ['email' => $email], base_url() . '/settings.php');
        unset($_SESSION['dev_mail']);
    }
    flash('success', t('Google ist jetzt mit deinem Konto verknüpft.'));
    redirect('settings.php#anmeldung');
}

// ---------- Login mode ----------
if ($linkedUserId !== null) {
    login_user($linkedUserId);
    security_log('login_ok', ['user' => $linkedUserId, 'via' => 'google']);
    redirect('dashboard.php');
}

$stmt = $pdo->prepare('SELECT id FROM nutzer WHERE email = ?');
$stmt->execute([$email]);
if ($stmt->fetchColumn() !== false) {
    // Case 4 above: same e-mail, but no link. Never merge silently.
    $fail('login: e-mail belongs to an unlinked account', t('text.auth_google.1'));
}

// First visit: create the account. Google has confirmed the address,
// so it starts verified; there is no password (passwort_hash NULL).
if (!rate_limit_allow('register', client_ip(), 5, 60)) {
    $fail('register rate limit', t('Zu viele Registrierungen von diesem Anschluss. Bitte versuche es in einer Stunde noch einmal.'));
}
$name = is_string($claims['name'] ?? null) && trim($claims['name']) !== ''
    ? mb_substr(trim($claims['name']), 0, 100)
    : mb_substr(strstr($email, '@', true) ?: $email, 0, 100);

try {
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO nutzer (name, email, passwort_hash, email_bestaetigt_am, sprache) VALUES (?, ?, NULL, NOW(), ?)')
        ->execute([$name, $email, locale()]);
    $newId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO nutzer_identitaet (nutzer_id, anbieter, anbieter_id, email) VALUES (?, 'google', ?, ?)")
        ->execute([$newId, $sub, $email]);
    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    if ($e->getCode() !== '23000') {
        throw $e;
    }
    // Two requests at the same moment: the UNIQUE constraints decide.
    $fail('login: account or identity created concurrently');
}

login_user($newId);
flash('success', t('Willkommen bei QuickPoll! Dein Konto ist eingerichtet.'));
redirect('dashboard.php');
