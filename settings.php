<?php
declare(strict_types=1);
// ============================================================
//  settings.php – account management
//
//  Language and appearance · profile · e-mail address (changed only
//  after the NEW address was confirmed) · password · sign-in methods
//  · active sessions · data export (GDPR Art. 20) · delete account
//  (GDPR Art. 17).
//
//  Rule of thumb on this page: whatever could lock the owner out or
//  let someone else in asks for the current password again.
// ============================================================

require_once __DIR__ . '/includes/auth.php';

$pdo    = db();
$errors = [];
$name   = (string) $user['name'];

$hasPassword = has_password($user);
$identities  = account_identities($userId);

if (is_post()) {
    switch (post_string('action', 30)) {

        // ---------- Profile ----------
        case 'save_profile':
            $name = post_string('name', 100);
            if (mb_strlen($name) < 2) {
                $errors[] = t('Bitte gib deinen Namen ein (mindestens 2 Zeichen).');
                break;
            }
            $pdo->prepare('UPDATE nutzer SET name = ? WHERE id = ?')->execute([$name, $userId]);
            flash('success', t('Profil gespeichert.'));
            redirect('settings.php');

        // ---------- E-mail address ----------
        // The address is the login name and the way back into the account
        // (password reset). So it only changes after the NEW mailbox has
        // confirmed the link; the old one is told about the request.
        case 'change_email':
            $newEmail = mb_strtolower(post_string('new_email'));

            if (!$hasPassword) {
                $errors[] = t('Lege zuerst ein Passwort fest – danach kannst du die E-Mail-Adresse ändern.');
            } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                $errors[] = t('Die E-Mail-Adresse ist ungültig.');
            } elseif ($newEmail === mb_strtolower((string) $user['email'])) {
                $errors[] = t('Das ist bereits deine E-Mail-Adresse.');
            } elseif (!password_confirmed($user, post_password('current_password'))) {
                $errors[] = t('Das aktuelle Passwort ist falsch.');
            } elseif (!rate_limit_allow('email_change', (string) $userId, 3, 60)) {
                $errors[] = t('Du hast die Adresse gerade mehrmals geändert. Bitte warte eine Stunde.');
            }
            if ($errors) {
                break;
            }

            $pdo->prepare('UPDATE nutzer SET neue_email = ? WHERE id = ?')->execute([$newEmail, $userId]);
            $token = account_token_create($userId, 'email_change', VERIFY_VALID_HOURS * 60, $newEmail);
            security_log('email_change_requested');

            // If the address already belongs to another account, no mail is
            // sent – but the answer on screen stays the same. Otherwise this
            // form could be used to test which addresses are registered.
            $stmt = $pdo->prepare('SELECT 1 FROM nutzer WHERE email = ?');
            $stmt->execute([$newEmail]);
            if (!$stmt->fetchColumn()) {
                send_account_mail(
                    $user,
                    'email_change',
                    ['email' => $newEmail, 'stunden' => VERIFY_VALID_HOURS],
                    base_url() . '/verify_email.php?token=' . $token,
                    $newEmail
                );
                // The OLD address learns about the request – if it was not the
                // owner, this is the moment they find out.
                send_account_mail($user, 'email_change_notice', ['email' => $newEmail]);
            }
            flash('success', t('Wir haben einen Bestätigungslink an {email} geschickt. Bis du ihn öffnest, bleibt deine bisherige Adresse gültig.', ['email' => $newEmail]));
            redirect('settings.php#email');

        case 'cancel_email_change':
            $pdo->prepare('UPDATE nutzer SET neue_email = NULL WHERE id = ?')->execute([$userId]);
            $pdo->prepare("DELETE FROM konto_token WHERE nutzer_id = ? AND zweck = 'email_change'")->execute([$userId]);
            flash('success', t('Die Änderung der E-Mail-Adresse wurde abgebrochen.'));
            redirect('settings.php#email');

        // ---------- Password ----------
        case 'change_password':
            if (!password_confirmed($user, post_password('current_password'))) {
                $errors[] = t('Das aktuelle Passwort ist falsch.');
                break;
            }
            $errors = password_problems(post_password('new_password'), post_password('new_password2'));
            if ($errors) {
                break;
            }
            $pdo->prepare('UPDATE nutzer SET passwort_hash = ? WHERE id = ?')
                ->execute([password_hash(post_password('new_password'), PASSWORD_DEFAULT), $userId]);

            // Fresh session id for this device, every other device is signed out:
            // whoever knew the old password does not stay logged in.
            session_regenerate_id(true);
            $ended = sessions_end_others($userId);
            security_log('password_changed', ['other_sessions_ended' => $ended]);
            send_account_mail($user, 'password_changed', [], base_url() . '/forgot_password.php');
            unset($_SESSION['dev_mail']);

            flash('success', $ended > 0
                ? t('Passwort geändert. Alle anderen Geräte wurden abgemeldet.')
                : t('Passwort geändert.'));
            redirect('settings.php');

        // Accounts created with Google have no password. Setting one works
        // like "forgot password": the link goes to the account's mailbox.
        case 'send_password_link':
            if ($hasPassword) {
                break;
            }
            if (send_password_reset_mail($user)) {
                flash('success', t('Wir haben dir einen Link geschickt, über den du ein Passwort festlegst.'));
            } else {
                flash('warning', t('Wir haben dir gerade erst einen Link geschickt. Schau in dein Postfach.'));
            }
            redirect('settings.php');

        // ---------- Sign-in methods ----------
        case 'unlink_google':
            if (!isset($identities['google'])) {
                break;
            }
            // Without a password, Google is the only way in – it stays.
            if (!$hasPassword) {
                $errors[] = t('Lege zuerst ein Passwort fest – sonst könntest du dich nicht mehr anmelden.');
            } elseif (!password_confirmed($user, post_password('current_password'))) {
                $errors[] = t('Das aktuelle Passwort ist falsch.');
            } else {
                $pdo->prepare("DELETE FROM nutzer_identitaet WHERE nutzer_id = ? AND anbieter = 'google'")->execute([$userId]);
                security_log('provider_unlinked', ['provider' => 'google']);
                flash('success', t('Die Verknüpfung mit Google wurde entfernt.'));
                redirect('settings.php#anmeldung');
            }
            break;

        // ---------- Sessions ----------
        case 'end_session':
            $sessionId = post_string('session', 64);
            // "AND nutzer_id = ?": only the own sessions (IDOR). The current
            // one ends with "Abmelden", not here.
            if (preg_match('/^[a-f0-9]{64}$/', $sessionId) && !hash_equals(session_current_id(), $sessionId)) {
                $pdo->prepare('DELETE FROM sitzung WHERE id = ? AND nutzer_id = ?')->execute([$sessionId, $userId]);
                flash('success', t('Das Gerät wurde abgemeldet.'));
            }
            redirect('settings.php#sitzungen');

        case 'end_other_sessions':
            $ended = sessions_end_others($userId);
            security_log('sessions_ended', ['count' => $ended]);
            flash('success', $ended > 0
                ? t('Alle anderen Geräte wurden abgemeldet.')
                : t('Es war kein anderes Gerät angemeldet.'));
            redirect('settings.php#sitzungen');

        // ---------- Export ----------
        case 'export_data':
            // Everything the account owns as one JSON document.
            $stmt = $pdo->prepare('SELECT * FROM fragebogen WHERE nutzer_id = ? ORDER BY id');
            $stmt->execute([$userId]);

            $answerStmt = $pdo->prepare(
                'SELECT t.id AS teilnahme, t.abgegeben_am, a.frage_id, a.antwort_text
                 FROM teilnahme t LEFT JOIN antwort a ON a.teilnahme_id = t.id
                 WHERE t.fragebogen_id = ? ORDER BY t.id, a.id'
            );

            $surveys = [];
            foreach ($stmt->fetchAll() as $s) {
                $questions = [];
                foreach (load_questions((int) $s['id']) as $q) {
                    $questions[] = [
                        'id' => (int) $q['id'], 'text' => $q['text'], 'typ' => $q['typ'],
                        'pflicht' => (bool) $q['pflicht'], 'optionen' => array_column($q['options'], 'text'),
                    ];
                }
                $submissions = [];
                $answerStmt->execute([$s['id']]);
                foreach ($answerStmt->fetchAll() as $row) {
                    $submissions[$row['teilnahme']]['abgegeben_am'] = $row['abgegeben_am'];
                    if ($row['frage_id'] !== null) {
                        $submissions[$row['teilnahme']]['antworten'][] = ['frage_id' => (int) $row['frage_id'], 'antwort' => $row['antwort_text']];
                    }
                }
                $surveys[] = [
                    'titel' => $s['titel'], 'beschreibung' => $s['beschreibung'],
                    'veroeffentlicht' => (bool) $s['veroeffentlicht'], 'erstellt_am' => $s['erstellt_am'],
                    'fragen' => $questions, 'teilnahmen' => array_values($submissions),
                ];
            }

            $methods = [];
            foreach ($identities as $identity) {
                $methods[] = ['anbieter' => $identity['anbieter'], 'email' => $identity['email'], 'verknuepft_am' => $identity['verknuepft_am']];
            }

            header('Content-Type: application/json; charset=UTF-8');
            header('Content-Disposition: attachment; filename="quickpoll-daten-' . date('Y-m-d') . '.json"');
            header('Cache-Control: no-store');
            echo json_encode([
                'konto' => [
                    'name' => $user['name'], 'email' => $user['email'],
                    'email_bestaetigt_am' => $user['email_bestaetigt_am'],
                    'sprache' => $user['sprache'], 'design' => $user['design'],
                    'tarif' => is_pro($user) ? 'Pro' : 'Free', 'registriert_am' => $user['erstellt_am'],
                    'letzte_anmeldung' => $user['letzter_login'],
                    'anmeldung_mit_passwort' => $hasPassword,
                    'verknuepfte_anmeldungen' => $methods,
                ],
                'umfragen' => $surveys,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;

        // ---------- Delete account ----------
        case 'delete_account':
            // With a password: enter it. Without (Google only): sign in at
            // Google once more right before (see auth_google.php, mode
            // "reauth"). Either way a stolen session alone is not enough.
            $confirmed = $hasPassword
                ? password_confirmed($user, post_password('current_password'))
                : reauth_is_valid($userId);
            if (!$confirmed) {
                $errors[] = $hasPassword
                    ? t('Das Passwort ist falsch. Das Konto wurde nicht gelöscht.')
                    : t('Bitte bestätige zuerst mit Google, dass du es bist. Das Konto wurde nicht gelöscht.');
                break;
            }

            // A subscription that still exists at Stripe must end there too –
            // otherwise the customer would keep paying for a deleted account.
            // (Also for "past due" or "unpaid": those can still charge later.)
            $stillRuns = !empty($user['stripe_subscription_id'])
                && !in_array($user['stripe_status'], ['canceled', 'incomplete_expired'], true);
            if ($stillRuns) {
                try {
                    if (!stripe_is_configured()) {
                        throw new RuntimeException('Stripe is not configured');
                    }
                    stripe_boot();
                    \Stripe\Subscription::retrieve($user['stripe_subscription_id'])->cancel();
                } catch (Throwable $e) {
                    error_log('Stripe cancel failed: ' . $e->getMessage());
                    $errors[] = t('Dein Abo konnte nicht automatisch gekündigt werden. Kündige es bitte zuerst unter „Abo & Tarif“.');
                    break;
                }
            }

            // ON DELETE CASCADE removes surveys, questions, submissions, answers,
            // tokens, linked sign-ins and every session of this account.
            $pdo->prepare('DELETE FROM nutzer WHERE id = ?')->execute([$userId]);
            security_log('account_deleted', ['user' => $userId]);
            // Keep an (anonymous) session for the goodbye message, but
            // with a new id and without the login.
            unset($_SESSION['user_id'], $_SESSION['sid'], $_SESSION['sid_seen'], $_SESSION['reauth']);
            session_regenerate_id(true);
            flash('success', t('Dein Konto und alle zugehörigen Daten wurden gelöscht.'));
            redirect('login.php');
    }
}

// Sessions that can still be alive (the others idled out).
$stmt = $pdo->prepare(
    'SELECT * FROM sitzung
     WHERE nutzer_id = ? AND zuletzt_aktiv > NOW() - INTERVAL ' . (int) SESSION_IDLE_SECONDS . ' SECOND
     ORDER BY zuletzt_aktiv DESC'
);
$stmt->execute([$userId]);
$sessions  = $stmt->fetchAll();
$currentId = session_current_id();

$google     = $identities['google'] ?? null;
$showGoogle = $google !== null || google_is_configured();

render_app_top(t('Einstellungen'), 'settings', $user);
page_head(t('Einstellungen'), t('Sprache, Profil, Anmeldung, Sitzungen und deine Daten.'));

render_errors($errors);
?>

<div class="settings-grid">
    <section class="card settings-wide">
        <h2><?= e(t('Sprache und Darstellung')) ?></h2>
        <div class="prefs-row">
            <div class="field">
                <span class="field-label"><?= e(t('Sprache')) ?></span>
                <?= language_menu() ?>
            </div>
            <div class="field">
                <span class="field-label"><?= e(t('Darstellung')) ?></span>
                <?= theme_switch() ?>
            </div>
        </div>
        <p class="text-muted"><?= e(t('Die Auswahl gilt auf diesem Gerät und wird in deinem Konto gespeichert.')) ?></p>
    </section>

    <section class="card">
        <h2><?= e(t('Profil')) ?></h2>
        <form method="post" action="settings.php" class="form-stack">
            <?= csrf_field() ?>
            <label class="field">
                <span class="field-label"><?= e(t('Name')) ?></span>
                <input type="text" name="name" value="<?= e($name) ?>" maxlength="100" autocomplete="name" required>
            </label>
            <div><button type="submit" name="action" value="save_profile" class="btn"><?= e(t('Profil speichern')) ?></button></div>
        </form>
        <p class="text-muted"><?= e(t('Registriert seit {datum}, letzte Anmeldung {zeit}.', ['datum' => format_date($user['erstellt_am']), 'zeit' => format_datetime($user['letzter_login'])])) ?></p>
    </section>

    <section class="card" id="email">
        <h2><?= e(t('E-Mail-Adresse')) ?></h2>
        <p class="account-line">
            <span class="account-value"><?= bdi($user['email']) ?></span>
            <?php if (is_verified($user)): ?>
                <span class="badge badge-live"><?= e(t('bestätigt')) ?></span>
            <?php else: ?>
                <span class="badge badge-draft"><?= e(t('noch nicht bestätigt')) ?></span>
            <?php endif; ?>
        </p>

        <?php if ($user['neue_email'] !== null): ?>
            <div class="pending-box">
                <p><?= t_html('Wartet auf Bestätigung: {email}', ['email' => '<strong>' . bdi($user['neue_email']) . '</strong>']) ?></p>
                <p class="text-muted"><?= e(t('Öffne den Link in der Mail an die neue Adresse. Bis dahin meldest du dich weiter mit der bisherigen an.')) ?></p>
                <form method="post" action="settings.php">
                    <?= csrf_field() ?>
                    <button type="submit" name="action" value="cancel_email_change" class="btn btn-ghost btn-sm"><?= e(t('Änderung abbrechen')) ?></button>
                </form>
            </div>
        <?php elseif ($hasPassword): ?>
            <form method="post" action="settings.php" class="form-stack">
                <?= csrf_field() ?>
                <label class="field">
                    <span class="field-label"><?= e(t('Neue E-Mail-Adresse')) ?></span>
                    <input type="email" name="new_email" autocomplete="email" required>
                </label>
                <label class="field">
                    <span class="field-label"><?= e(t('Aktuelles Passwort')) ?></span>
                    <input type="password" name="current_password" autocomplete="current-password" required>
                </label>
                <div><button type="submit" name="action" value="change_email" class="btn"><?= e(t('Bestätigungslink senden')) ?></button></div>
            </form>
        <?php else: ?>
            <p class="text-muted"><?= e(t('Lege zuerst ein Passwort fest – danach kannst du die E-Mail-Adresse ändern.')) ?></p>
        <?php endif; ?>
    </section>

    <section class="card">
        <?php if ($hasPassword): ?>
            <h2><?= e(t('Passwort ändern')) ?></h2>
            <form method="post" action="settings.php" class="form-stack">
                <?= csrf_field() ?>
                <label class="field">
                    <span class="field-label"><?= e(t('Aktuelles Passwort')) ?></span>
                    <input type="password" name="current_password" autocomplete="current-password" required>
                </label>
                <label class="field">
                    <span class="field-label"><?= e(t('Neues Passwort')) ?> <span class="field-hint"><?= e(t('mindestens 8 Zeichen')) ?></span></span>
                    <input type="password" name="new_password" autocomplete="new-password" minlength="8" maxlength="72" required>
                </label>
                <label class="field">
                    <span class="field-label"><?= e(t('Neues Passwort wiederholen')) ?></span>
                    <input type="password" name="new_password2" autocomplete="new-password" minlength="8" maxlength="72" required>
                </label>
                <div><button type="submit" name="action" value="change_password" class="btn"><?= e(t('Passwort ändern')) ?></button></div>
            </form>
            <p class="text-muted"><?= e(t('Danach werden alle anderen Geräte abgemeldet.')) ?></p>
        <?php else: ?>
            <h2><?= e(t('Passwort festlegen')) ?></h2>
            <p><?= e(t('Dieses Konto meldet sich bisher nur über Google an. Mit einem Passwort kommst du auch ohne Google hinein.')) ?></p>
            <form method="post" action="settings.php">
                <?= csrf_field() ?>
                <button type="submit" name="action" value="send_password_link" class="btn"><?= e(t('Link per E-Mail senden')) ?></button>
            </form>
        <?php endif; ?>
    </section>

    <?php if ($showGoogle): ?>
    <section class="card" id="anmeldung">
        <h2><?= e(t('Anmeldemethoden')) ?></h2>
        <ul class="method-list">
            <li>
                <div>
                    <strong><?= e(t('E-Mail und Passwort')) ?></strong>
                    <span class="text-muted"><?= bdi($user['email']) ?></span>
                </div>
                <span class="badge <?= $hasPassword ? 'badge-live' : 'badge-draft' ?>"><?= e($hasPassword ? t('aktiv') : t('kein Passwort')) ?></span>
            </li>
            <li>
                <div>
                    <strong>Google</strong>
                    <span class="text-muted"><?= $google !== null
                        ? t_html('{email}, verknüpft am {datum}', ['email' => bdi($google['email']), 'datum' => e(format_date($google['verknuepft_am']))])
                        : e(t('nicht verknüpft')) ?></span>
                </div>
                <span class="badge <?= $google !== null ? 'badge-live' : 'badge-draft' ?>"><?= e($google !== null ? t('aktiv') : t('aus')) ?></span>
            </li>
        </ul>

        <?php if ($google === null): ?>
            <form method="post" action="auth_google.php" class="form-stack">
                <?= csrf_field() ?>
                <input type="hidden" name="mode" value="link">
                <label class="field">
                    <span class="field-label"><?= e(t('Aktuelles Passwort')) ?></span>
                    <input type="password" name="current_password" autocomplete="current-password" required>
                </label>
                <div><button type="submit" class="btn btn-ghost"><?= e(t('Google verknüpfen')) ?></button></div>
            </form>
        <?php elseif ($hasPassword): ?>
            <form method="post" action="settings.php" class="form-stack">
                <?= csrf_field() ?>
                <label class="field">
                    <span class="field-label"><?= e(t('Aktuelles Passwort')) ?></span>
                    <input type="password" name="current_password" autocomplete="current-password" required>
                </label>
                <div><button type="submit" name="action" value="unlink_google" class="btn btn-ghost"><?= e(t('Verknüpfung entfernen')) ?></button></div>
            </form>
        <?php else: ?>
            <p class="text-muted"><?= e(t('Google ist der einzige Weg in dieses Konto. Lege ein Passwort fest, bevor du die Verknüpfung entfernst.')) ?></p>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="card settings-wide" id="sitzungen">
        <div class="card-head">
            <h2><?= e(t('Angemeldete Geräte')) ?></h2>
            <?php if (count($sessions) > 1): ?>
                <form method="post" action="settings.php">
                    <?= csrf_field() ?>
                    <button type="submit" name="action" value="end_other_sessions" class="btn btn-ghost btn-sm"
                            data-confirm="<?= e(t('Alle anderen Geräte abmelden?')) ?>"><?= e(t('Alle anderen abmelden')) ?></button>
                </form>
            <?php endif; ?>
        </div>
        <ul class="session-list">
            <?php foreach ($sessions as $session): ?>
                <?php $isCurrent = hash_equals($currentId, (string) $session['id']); ?>
                <li class="<?= $isCurrent ? 'is-current' : '' ?>">
                    <div class="session-device">
                        <strong><?= e(device_label((string) $session['user_agent'])) ?></strong>
                        <?php if ($isCurrent): ?><span class="stamp stamp-live"><?= e(t('Dieses Gerät')) ?></span><?php endif; ?>
                    </div>
                    <div class="session-meta">
                        <span><?= e(t('Angemeldet am {zeit}', ['zeit' => format_datetime($session['erstellt_am'])])) ?></span>
                        <span><?= e($isCurrent ? t('gerade aktiv') : t('zuletzt aktiv {zeit}', ['zeit' => time_ago($session['zuletzt_aktiv'])])) ?></span>
                    </div>
                    <?php if (!$isCurrent): ?>
                        <form method="post" action="settings.php">
                            <?= csrf_field() ?>
                            <input type="hidden" name="session" value="<?= e($session['id']) ?>">
                            <button type="submit" name="action" value="end_session" class="btn btn-ghost btn-sm"><?= e(t('Abmelden')) ?></button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="text-muted"><?= e(t('Ein Gerät wird nach {stunden} ohne Aktivität automatisch abgemeldet. Erkennst du eines nicht? Melde es ab und ändere dein Passwort.', ['stunden' => plural((int) (SESSION_IDLE_SECONDS / 3600), 'Stunde', 'Stunden')])) ?></p>
    </section>

    <section class="card">
        <h2><?= e(t('Meine Daten')) ?></h2>
        <p><?= e(t('Lade alle Daten deines Kontos als JSON-Datei herunter: Profil, Umfragen, Fragen und sämtliche Antworten.')) ?></p>
        <form method="post" action="settings.php">
            <?= csrf_field() ?>
            <button type="submit" name="action" value="export_data" class="btn btn-ghost"><?= icon('download') ?> <?= e(t('Daten exportieren')) ?></button>
        </form>
    </section>

    <section class="card card-danger" id="loeschen">
        <h2><?= e(t('Konto löschen')) ?></h2>
        <p><?= e(t('Löscht dein Konto mit allen Umfragen und Antworten. Das lässt sich nicht rückgängig machen.')) ?></p>
        <?php if ($hasPassword || reauth_is_valid($userId)): ?>
            <form method="post" action="settings.php" class="form-stack">
                <?= csrf_field() ?>
                <?php if ($hasPassword): ?>
                    <label class="field">
                        <span class="field-label"><?= e(t('Passwort zur Bestätigung')) ?></span>
                        <input type="password" name="current_password" autocomplete="current-password" required>
                    </label>
                <?php else: ?>
                    <p class="text-muted"><?= e(t('Mit Google bestätigt. Die Bestätigung gilt 10 Minuten.')) ?></p>
                <?php endif; ?>
                <div>
                    <button type="submit" name="action" value="delete_account" class="btn btn-danger"
                            data-confirm="<?= e(t('Konto wirklich endgültig löschen? Alle Umfragen und Antworten gehen verloren.')) ?>"><?= e(t('Konto endgültig löschen')) ?></button>
                </div>
            </form>
        <?php elseif ($google !== null && google_is_configured()): ?>
            <?php /* No password to ask for: Google confirms that it is really the owner. */ ?>
            <p><?= e(t('Dieses Konto hat kein Passwort. Bestätige zuerst mit einer erneuten Anmeldung bei Google, dass du es bist.')) ?></p>
            <form method="post" action="auth_google.php">
                <?= csrf_field() ?>
                <input type="hidden" name="mode" value="reauth">
                <button type="submit" class="btn btn-ghost"><?= e(t('Mit Google bestätigen')) ?></button>
            </form>
        <?php else: ?>
            <p class="text-muted"><?= e(t('Lege zuerst ein Passwort fest (oben unter „Passwort festlegen“) – es wird zur Bestätigung gebraucht.')) ?></p>
        <?php endif; ?>
    </section>
</div>

<?php render_app_bottom(); ?>
