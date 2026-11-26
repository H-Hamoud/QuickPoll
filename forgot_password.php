<?php
declare(strict_types=1);
// ============================================================
//  forgot_password.php – request a password-reset link
//
//  Flow: e-mail address → random token → link by mail →
//  reset_password.php checks the token and sets the new password.
//  The database only stores the SHA-256 HASH of the token.
// ============================================================

require_once __DIR__ . '/includes/bootstrap.php';
session_boot();

$sent  = false;
$email = '';

if (is_post()) {
    $email = mb_strtolower(post_string('email'));

    // At most 10 requests per hour from one address – nobody needs more,
    // and it keeps the form from being used to flood mailboxes.
    if (rate_limit_allow('forgot', client_ip(), 10, 60)) {
        $stmt = db()->prepare('SELECT id, email, name, sprache FROM nutzer WHERE email = ?');
        $stmt->execute([$email]);
        if ($account = $stmt->fetch()) {
            send_password_reset_mail($account);   // at most one mail every two minutes per account
        }
    }

    // Same answer whether the account exists or not – otherwise this
    // form would be a tool to find out who is registered.
    $sent = true;
}

render_public_top(t('Passwort vergessen'), 'auth');
?>
<h1><?= e(t('Passwort vergessen')) ?></h1>

<?php if ($sent): ?>
    <p class="message message-success" role="status">
        <?= e(t('text.forgot_password.1', ['n' => RESET_VALID_MINUTES])) ?>
    </p>
    <?php render_dev_mail(); ?>
<?php else: ?>
    <p class="auth-sub"><?= e(t('Gib deine E-Mail-Adresse ein. Wir schicken dir einen Link, über den du ein neues Passwort vergibst.')) ?></p>
    <form method="post" action="forgot_password.php" class="form-stack">
        <?= csrf_field() ?>
        <label class="field">
            <span class="field-label"><?= e(t('E-Mail')) ?></span>
            <input type="email" name="email" value="<?= e($email) ?>" autocomplete="username" required autofocus>
        </label>
        <button type="submit" class="btn btn-block"><?= e(t('Link anfordern')) ?></button>
    </form>
<?php endif; ?>

<p class="auth-links"><a href="login.php"><?= e(t('Zurück zur Anmeldung')) ?></a></p>
<?php render_public_bottom('auth'); ?>
