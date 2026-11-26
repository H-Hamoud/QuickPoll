<?php
declare(strict_types=1);
// ============================================================
//  verify_email.php – confirm an e-mail address
//
//  Two kinds of links end here:
//    email_verify  "this address belongs to my new account"
//    email_change  "move my account to this new address"
//
//  Opening the link (GET) only SHOWS a confirmation button. The
//  change happens on POST: mail scanners and link previews open
//  links automatically, and they must not be able to confirm an
//  address nobody looked at.
//
//  The same file re-sends the verification mail (POST action=resend).
// ============================================================

require_once __DIR__ . '/includes/bootstrap.php';
session_boot();

$user = current_user();

// ---------- Send the verification mail again ----------
if (is_post() && post_string('action', 20) === 'resend') {
    if ($user === null) {
        redirect('login.php');
    }
    if (is_verified($user)) {
        redirect('dashboard.php');
    }
    // 3 mails per hour: enough for "it did not arrive", useless for spamming.
    if (rate_limit_allow('verify_mail', (string) $user['id'], 3, 60)) {
        send_verification_mail($user);
        flash('success', t('Wir haben dir einen neuen Link an {email} geschickt.', ['email' => $user['email']]));
    } else {
        flash('error', t('Du hast gerade mehrere Links angefordert. Bitte warte eine Stunde – und schau auch im Spam-Ordner nach.'));
    }
    redirect('dashboard.php');
}

// ---------- Confirm ----------
$token = is_post() ? post_string('token', 64) : get_string('token', 64);
$row   = account_token_find($token);

$account = null;
if ($row !== null) {
    $stmt = db()->prepare('SELECT * FROM nutzer WHERE id = ?');
    $stmt->execute([$row['nutzer_id']]);
    $account = $stmt->fetch() ?: null;

    // The token must still fit the account: a verification link is for
    // the address it was sent to, a change link for the address that is
    // still waiting (the request may have been cancelled or replaced).
    $fits = $account !== null && match ($row['zweck']) {
        'email_verify' => mb_strtolower((string) $account['email']) === mb_strtolower((string) $row['daten']),
        'email_change' => $account['neue_email'] !== null && $account['neue_email'] === $row['daten'],
        default        => false,
    };
    if (!$fits) {
        $row = null;
    }
}

if ($row !== null && is_post() && post_string('action', 20) === 'confirm') {
    $pdo    = db();
    $target = $user !== null && (int) $user['id'] === (int) $account['id'] ? 'dashboard.php' : 'login.php';

    if (!account_token_use((int) $row['id'])) {
        $row = null;   // a parallel request used it first
    } elseif ($row['zweck'] === 'email_verify') {
        $pdo->prepare('UPDATE nutzer SET email_bestaetigt_am = COALESCE(email_bestaetigt_am, NOW()) WHERE id = ?')
            ->execute([$account['id']]);
        flash('success', t('Deine E-Mail-Adresse ist bestätigt. Du kannst jetzt Umfragen veröffentlichen.'));
        redirect($target);
    } else {
        try {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE nutzer SET email = ?, neue_email = NULL, email_bestaetigt_am = NOW() WHERE id = ?')
                ->execute([$row['daten'], $account['id']]);
            // Reset links were sent to the OLD address – they must not work any more.
            $pdo->prepare('DELETE FROM passwort_reset WHERE nutzer_id = ?')->execute([$account['id']]);
            $pdo->commit();
            security_log('email_changed', ['user' => (int) $account['id']]);
            // Receipts from Stripe should follow to the new address. Best
            // effort: if Stripe is not reachable the change itself stands.
            if (!empty($account['stripe_customer_id']) && stripe_is_configured()) {
                try {
                    stripe_boot();
                    \Stripe\Customer::update($account['stripe_customer_id'], ['email' => $row['daten']]);
                } catch (Throwable $e) {
                    error_log('Stripe: customer e-mail not updated: ' . $e->getMessage());
                }
            }
            flash('success', t('Deine neue E-Mail-Adresse ist bestätigt. Ab jetzt meldest du dich mit {email} an.', ['email' => $row['daten']]));
        } catch (PDOException $e) {
            $pdo->rollBack();
            if ($e->getCode() !== '23000') {   // 23000 = UNIQUE constraint on email
                throw $e;
            }
            // Someone registered that address in the meantime.
            $pdo->prepare('UPDATE nutzer SET neue_email = NULL WHERE id = ?')->execute([$account['id']]);
            flash('error', t('Diese E-Mail-Adresse wird inzwischen von einem anderen Konto verwendet. Deine bisherige Adresse bleibt gültig.'));
        }
        redirect($target === 'dashboard.php' ? 'settings.php' : 'login.php');
    }
}

render_public_top(t('E-Mail-Adresse bestätigen'), 'auth');
?>
<h1><?= e(t('E-Mail-Adresse bestätigen')) ?></h1>

<?php if ($row === null): ?>
    <p class="message message-error" role="alert">
        <?= e(t('Dieser Link ist ungültig, abgelaufen oder wurde schon benutzt.')) ?>
    </p>
    <?php if ($user !== null && !is_verified($user)): ?>
        <form method="post" action="verify_email.php" class="form-stack">
            <?= csrf_field() ?>
            <button type="submit" name="action" value="resend" class="btn btn-block"><?= e(t('Neuen Link senden')) ?></button>
        </form>
    <?php else: ?>
        <p class="auth-links"><a href="<?= $user !== null ? 'dashboard.php' : 'login.php' ?>"><?= e($user !== null ? t('Zum Dashboard') : t('Zurück zur Anmeldung')) ?></a></p>
    <?php endif; ?>
<?php else: ?>
    <p class="auth-sub">
        <?= $row['zweck'] === 'email_change'
            ? e(t('Dein QuickPoll-Konto wird auf diese Adresse umgestellt:'))
            : e(t('Bestätige, dass diese Adresse zu deinem QuickPoll-Konto gehört:')) ?>
    </p>
    <p class="verify-address"><?= bdi($row['daten']) ?></p>
    <form method="post" action="verify_email.php" class="form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <button type="submit" name="action" value="confirm" class="btn btn-block"><?= e(t('Adresse bestätigen')) ?></button>
    </form>
<?php endif; ?>
<?php render_public_bottom('auth'); ?>
