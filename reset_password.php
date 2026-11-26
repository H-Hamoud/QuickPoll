<?php
declare(strict_types=1);
// ============================================================
//  reset_password.php – set a new password with a reset token
// ============================================================

require_once __DIR__ . '/includes/bootstrap.php';
session_boot();

$token = is_post() ? post_string('token', 64) : get_string('token', 64);

// The link carries the token, the database its hash. Valid means:
// known, not expired, not used yet.
$reset = null;
if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $stmt = db()->prepare(
        'SELECT * FROM passwort_reset
         WHERE token_hash = ? AND laeuft_ab > NOW() AND benutzt_am IS NULL'
    );
    $stmt->execute([hash('sha256', $token)]);
    $reset = $stmt->fetch() ?: null;
}

$errors = [];

if ($reset && is_post()) {
    $errors = password_problems(post_password('password'), post_password('password2'));

    if (!$errors) {
        $pdo    = db();
        $userId = (int) $reset['nutzer_id'];

        $pdo->beginTransaction();
        try {
            // A token works exactly once. Claim it FIRST and atomically: if two
            // requests arrive with the same link, the second UPDATE waits for the
            // row lock, then finds benutzt_am already set and changes 0 rows.
            $claim = $pdo->prepare(
                'UPDATE passwort_reset SET benutzt_am = NOW()
                 WHERE id = ? AND benutzt_am IS NULL AND laeuft_ab > NOW()'
            );
            $claim->execute([$reset['id']]);
            $claimed = $claim->rowCount() === 1;

            if ($claimed) {
                // Whoever opens this link has proven access to the mailbox – so the
                // address counts as verified from now on (if it was not already).
                $pdo->prepare(
                    'UPDATE nutzer SET passwort_hash = ?, email_bestaetigt_am = COALESCE(email_bestaetigt_am, NOW()) WHERE id = ?'
                )->execute([password_hash(post_password('password'), PASSWORD_DEFAULT), $userId]);
                $pdo->commit();
            } else {
                $pdo->rollBack();
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        if (!$claimed) {
            // Used or expired in the meantime (e.g. sent twice from two tabs):
            // show "link invalid or expired" below instead of changing anything.
            $reset = null;
        } else {
            // A reset is what you do when someone else may know the password:
            // every device that is still logged in has to sign in again.
            sessions_end_all($userId);
            security_log('password_reset', ['user' => $userId]);

            $stmt = $pdo->prepare('SELECT id, name, email, sprache FROM nutzer WHERE id = ?');
            $stmt->execute([$userId]);
            if ($account = $stmt->fetch()) {
                login_clear_failures((string) $account['email']);
                send_account_mail($account, 'password_changed', [], base_url() . '/forgot_password.php');
                unset($_SESSION['dev_mail']);   // a notice, not a link the user has to open
            }

            flash('success', t('Dein Passwort wurde geändert. Du kannst dich jetzt anmelden.'));
            redirect('login.php');
        }
    }
}

render_public_top(t('Neues Passwort'), 'auth');
?>
<h1><?= e(t('Neues Passwort vergeben')) ?></h1>

<?php if (!$reset): ?>
    <p class="message message-error" role="alert">
        <?= e(t('Dieser Link ist ungültig oder abgelaufen. Fordere bitte einen neuen an.')) ?>
    </p>
    <p class="auth-links"><a href="forgot_password.php"><?= e(t('Neuen Link anfordern')) ?></a></p>
<?php else: ?>
    <?php render_errors($errors); ?>
    <form method="post" action="reset_password.php" class="form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <label class="field">
            <span class="field-label"><?= e(t('Neues Passwort')) ?> <span class="field-hint"><?= e(t('mindestens 8 Zeichen')) ?></span></span>
            <input type="password" name="password" autocomplete="new-password" minlength="8" maxlength="72" required autofocus>
        </label>
        <label class="field">
            <span class="field-label"><?= e(t('Passwort wiederholen')) ?></span>
            <input type="password" name="password2" autocomplete="new-password" minlength="8" maxlength="72" required>
        </label>
        <button type="submit" class="btn btn-block"><?= e(t('Passwort speichern')) ?></button>
    </form>
<?php endif; ?>
<?php render_public_bottom('auth'); ?>
