<?php
declare(strict_types=1);
// ============================================================
//  account.php – things several pages do with an account:
//  password rules, verification mail, reset mail.
// ============================================================

const VERIFY_VALID_HOURS  = 24;
const RESET_VALID_MINUTES = 60;
// An account whose address was never confirmed is removed after this
// many days: nobody can prove it is theirs, and it blocks the address.
const UNVERIFIED_KEEP_DAYS = 7;

/**
 * One set of password rules for registration, reset and settings.
 * @return list<string> error messages (empty = fine)
 */
function password_problems(string $password, string $repeat): array
{
    if (strlen($password) < 8) {
        return [t('Das Passwort muss mindestens 8 Zeichen lang sein.')];
    }
    if (strlen($password) > 72) {
        // bcrypt only looks at the first 72 bytes – longer passwords
        // would be silently cut off, so we reject them openly.
        return [t('Das Passwort darf höchstens 72 Zeichen lang sein.')];
    }
    if ($password !== $repeat) {
        return [t('Die Passwörter stimmen nicht überein.')];
    }
    return [];
}

/** Read a password field without trimming (spaces may be part of it). */
function post_password(string $key): string
{
    return is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
}

/**
 * Send the "confirm your address" mail with a fresh 24-hour link.
 * The token remembers WHICH address it was sent to.
 *
 * @param array<string, mixed> $user row of `nutzer`
 */
function send_verification_mail(array $user): void
{
    $token = account_token_create((int) $user['id'], 'email_verify', VERIFY_VALID_HOURS * 60, (string) $user['email']);
    send_account_mail(
        $user,
        'verify',
        ['stunden' => VERIFY_VALID_HOURS, 'tage' => UNVERIFIED_KEEP_DAYS],
        base_url() . '/verify_email.php?token=' . $token
    );
}

/**
 * Send a password-reset link (also used to SET a first password for
 * accounts that only sign in with Google). Returns false when a link
 * was sent less than two minutes ago.
 *
 * @param array<string, mixed> $account row of `nutzer`
 */
function send_password_reset_mail(array $account): bool
{
    $pdo  = db();
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM passwort_reset
         WHERE nutzer_id = ? AND laeuft_ab > NOW() + INTERVAL ' . (RESET_VALID_MINUTES - 2) . ' MINUTE'
    );
    $stmt->execute([$account['id']]);
    if ((int) $stmt->fetchColumn() > 0) {
        return false;
    }

    $token = bin2hex(random_bytes(32));   // 64 hex chars, goes into the link
    // Old links of this account become invalid.
    $pdo->prepare('DELETE FROM passwort_reset WHERE nutzer_id = ?')->execute([$account['id']]);
    $pdo->prepare(
        'INSERT INTO passwort_reset (nutzer_id, token_hash, laeuft_ab)
         VALUES (?, ?, NOW() + INTERVAL ' . RESET_VALID_MINUTES . ' MINUTE)'
    )->execute([$account['id'], hash('sha256', $token)]);

    send_account_mail(
        $account,
        'reset',
        ['minuten' => RESET_VALID_MINUTES],
        base_url() . '/reset_password.php?token=' . $token
    );
    return true;
}

/**
 * Ask for the current password again before a sensitive change
 * (new e-mail, new password, linking Google, deleting the account).
 * A stolen session alone is then not enough – and because wrong
 * guesses are counted, the session cannot be used to try passwords.
 *
 * @param array<string, mixed> $user
 */
function password_confirmed(array $user, string $input): bool
{
    if (!has_password($user) || $input === '') {
        return false;
    }
    if (!rate_limit_allow('pw_confirm', (string) $user['id'], 10, 15)) {
        return false;
    }
    return password_verify($input, (string) $user['passwort_hash']);
}

/**
 * Sign-in methods linked to the account, keyed by provider.
 * @return array<string, array<string, mixed>>
 */
function account_identities(int $userId): array
{
    $stmt = db()->prepare('SELECT * FROM nutzer_identitaet WHERE nutzer_id = ?');
    $stmt->execute([$userId]);
    return array_column($stmt->fetchAll(), null, 'anbieter');
}

/**
 * Housekeeping: remove what has expired. Safe to run at any time and
 * as often as you like (database/maintenance.php, and now and then
 * after a login).
 *
 * @return array<string, int> what was removed
 */
function maintenance_purge(): array
{
    $pdo  = db();
    $done = [];
    $run  = static function (string $label, string $sql) use ($pdo, &$done): void {
        $done[$label] = (int) $pdo->exec($sql);
    };

    // Never confirmed, never paid: the account and its drafts go (CASCADE).
    $run('unverified accounts', 'DELETE FROM nutzer
         WHERE email_bestaetigt_am IS NULL AND stripe_customer_id IS NULL
           AND erstellt_am < NOW() - INTERVAL ' . UNVERIFIED_KEEP_DAYS . ' DAY');
    $run('expired account links', 'DELETE FROM konto_token WHERE laeuft_ab < NOW() - INTERVAL 1 DAY');
    $run('expired reset links', 'DELETE FROM passwort_reset WHERE laeuft_ab < NOW() - INTERVAL 1 DAY');
    $run('idle sessions', 'DELETE FROM sitzung WHERE zuletzt_aktiv < NOW() - INTERVAL ' . (int) SESSION_IDLE_SECONDS . ' SECOND');
    $run('old login attempts', 'DELETE FROM login_versuch WHERE zeitpunkt < NOW() - INTERVAL 1 DAY');
    $run('old rate limit entries', 'DELETE FROM rate_limit WHERE zeitpunkt < NOW() - INTERVAL 2 DAY');
    $run('old webhook events', 'DELETE FROM stripe_ereignis WHERE empfangen_am < NOW() - INTERVAL 90 DAY');
    return $done;
}
