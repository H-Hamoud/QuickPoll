<?php
declare(strict_types=1);
// ============================================================
//  auth_google.php – start "Mit Google fortfahren"
//
//  Only reacts to POST: session_boot() checks the CSRF token, so no
//  foreign page can start a sign-in (or, worse, a "link my account
//  to this Google account") on someone's behalf.
//
//  mode=login  sign in, or create an account on first use
//  mode=link   attach Google to the logged-in account (from settings;
//              asks for the current password first)
//  mode=reauth sign in at Google once more, to confirm a dangerous
//              step for an account without password (delete account)
// ============================================================

require_once __DIR__ . '/includes/bootstrap.php';
session_boot();

if (!is_post() || !google_is_configured()) {
    redirect('login.php');
}

$mode = in_array(post_string('mode', 10), ['link', 'reauth'], true) ? post_string('mode', 10) : 'login';
$user = current_user();

// 20 starts per hour from one address are plenty for humans.
if (!rate_limit_allow('google_start', client_ip(), 20, 60)) {
    flash('error', t('Zu viele Anmeldeversuche. Bitte versuche es später noch einmal.'));
    redirect($mode === 'login' ? 'login.php' : 'settings.php');
}

if ($mode === 'reauth') {
    if ($user === null) {
        redirect('login.php');
    }
    if (!isset(account_identities((int) $user['id'])['google'])) {
        redirect('settings.php');
    }
    redirect(google_start('reauth', (int) $user['id']));
}

if ($mode === 'link') {
    if ($user === null) {
        redirect('login.php');
    }
    // Linking adds a second key to the account. A stolen session alone
    // must not be enough for that – the password is asked again.
    if (has_password($user) && !password_confirmed($user, post_password('current_password'))) {
        flash('error', t('Das Passwort ist falsch. Google wurde nicht verknüpft.'));
        redirect('settings.php#anmeldung');
    }
    if (isset(account_identities((int) $user['id'])['google'])) {
        redirect('settings.php#anmeldung');
    }
    redirect(google_start('link', (int) $user['id']));
}

if ($user !== null) {
    redirect('dashboard.php');
}
redirect(google_start('login'));
