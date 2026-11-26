<?php
declare(strict_types=1);
// ============================================================
//  mail.php – the e-mails QuickPoll sends (account and subscription)
//
//  Every mail exists as plain text AND as HTML (multipart), in the
//  language of the ACCOUNT it goes to – German, English or Arabic
//  (right-to-left).
//
//  Development: XAMPP has no mail server. The mail is written to
//  storage/logs/mail.log, the HTML version to
//  storage/logs/mail-preview.html, and the page shows the link.
//  Production: PHP's mail() function. For reliable delivery on a
//  real server switch to SMTP (e.g. PHPMailer) – only send_mail()
//  has to change.
// ============================================================

/**
 * Texts of one kind of mail in the current language.
 * The t() calls are written out one by one on purpose: the translation
 * tooling finds keys by reading the source code.
 *
 * @return array{subject: string, intro: string, button: string, outro: string}
 */
function mail_texts(string $kind): array
{
    return match ($kind) {
        'verify' => [
            'subject' => t('Bestätige deine E-Mail-Adresse – QuickPoll'),
            'intro'   => t('mail.verify.intro'),
            'button'  => t('E-Mail-Adresse bestätigen'),
            'outro'   => t('mail.verify.outro'),
        ],
        'reset' => [
            'subject' => t('Passwort zurücksetzen – QuickPoll'),
            'intro'   => t('mail.reset.intro'),
            'button'  => t('Neues Passwort vergeben'),
            'outro'   => t('mail.reset.outro'),
        ],
        'email_change' => [
            'subject' => t('Neue E-Mail-Adresse bestätigen – QuickPoll'),
            'intro'   => t('mail.email_change.intro'),
            'button'  => t('Neue Adresse bestätigen'),
            'outro'   => t('mail.email_change.outro'),
        ],
        'email_change_notice' => [
            'subject' => t('Änderung deiner E-Mail-Adresse angefordert – QuickPoll'),
            'intro'   => t('mail.email_change_notice.intro'),
            'button'  => '',
            'outro'   => t('mail.email_change_notice.outro'),
        ],
        'password_changed' => [
            'subject' => t('Dein Passwort wurde geändert – QuickPoll'),
            'intro'   => t('mail.password_changed.intro'),
            'button'  => t('Passwort zurücksetzen'),
            'outro'   => t('mail.password_changed.outro'),
        ],
        'provider_linked' => [
            'subject' => t('Google-Anmeldung verknüpft – QuickPoll'),
            'intro'   => t('mail.provider_linked.intro'),
            'button'  => t('Einstellungen öffnen'),
            'outro'   => t('mail.provider_linked.outro'),
        ],
        'account_exists' => [
            'subject' => t('Du hast bereits ein QuickPoll-Konto'),
            'intro'   => t('mail.account_exists.intro'),
            'button'  => t('Anmelden'),
            'outro'   => t('mail.account_exists.outro'),
        ],
        // ---- subscription (sent by stripe_webhook.php / includes/billing.php) ----
        'payment_received' => [
            'subject' => t('Zahlung erhalten – dein Pro-Tarif ist aktiv – QuickPoll'),
            'intro'   => t('mail.payment_received.intro'),
            'button'  => t('Rechnung ansehen'),
            'outro'   => t('mail.payment_received.outro'),
        ],
        'renewed' => [
            'subject' => t('Dein Pro-Abo wurde verlängert – QuickPoll'),
            'intro'   => t('mail.renewed.intro'),
            'button'  => t('Rechnung ansehen'),
            'outro'   => t('mail.renewed.outro'),
        ],
        'renewal_upcoming' => [
            'subject' => t('Dein Pro-Abo verlängert sich bald – QuickPoll'),
            'intro'   => t('mail.renewal_upcoming.intro'),
            'button'  => t('Abo verwalten'),
            'outro'   => t('mail.renewal_upcoming.outro'),
        ],
        'payment_failed' => [
            'subject' => t('Zahlung fehlgeschlagen – QuickPoll'),
            'intro'   => t('mail.payment_failed.intro'),
            'button'  => t('Zahlungsmethode aktualisieren'),
            'outro'   => t('mail.payment_failed.outro'),
        ],
        'cancel_scheduled' => [
            'subject' => t('Kündigung bestätigt – QuickPoll'),
            'intro'   => t('mail.cancel_scheduled.intro'),
            'button'  => t('Abo ansehen'),
            'outro'   => t('mail.cancel_scheduled.outro'),
        ],
        'subscription_ended' => [
            'subject' => t('Dein Pro-Abo ist beendet – QuickPoll'),
            'intro'   => t('mail.subscription_ended.intro'),
            'button'  => t('Tarife ansehen'),
            'outro'   => t('mail.subscription_ended.outro'),
        ],
        default => throw new InvalidArgumentException('Unknown mail kind: ' . $kind),
    };
}

/** Replace {placeholders}; with $html the values are escaped and kept left-to-right. */
function mail_fill(string $text, array $vars, bool $html): string
{
    $out = $html ? e($text) : $text;
    foreach ($vars as $name => $value) {
        // E-mail addresses and numbers are Latin script: inside Arabic
        // text they need their own direction, or the "@" parts swap places.
        $out = str_replace('{' . $name . '}', $html ? '<bdi dir="ltr">' . e($value) . '</bdi>' : (string) $value, $out);
    }
    return $out;
}

/**
 * Build one mail (subject, text part, HTML part) in the current language.
 *
 * @param array<string, scalar> $vars placeholders used by the texts
 * @return array{subject: string, text: string, html: string}
 */
function mail_build(string $kind, string $name, array $vars = [], string $url = ''): array
{
    $texts    = mail_texts($kind);
    $greeting = $name !== '' ? t('Hallo {name},', ['name' => $name]) : t('Hallo,');
    $footer   = t('mail.footer');
    $rtl      = is_rtl();

    // ---------- plain text ----------
    $text = $greeting . "\n\n" . mail_fill($texts['intro'], $vars, false) . "\n\n";
    if ($url !== '' && $texts['button'] !== '') {
        $text .= $texts['button'] . ":\n" . $url . "\n\n";
    }
    $text .= mail_fill($texts['outro'], $vars, false) . "\n\n-- \nQuickPoll\n" . $footer . "\n";

    // ---------- HTML ----------
    // Mail programs ignore stylesheets and modern CSS, so the layout is a
    // table with inline styles – the one place in the project where that
    // is correct. Colours are the brand tokens from css/01-tokens.css.
    $align  = $rtl ? 'right' : 'left';
    $serif  = $rtl ? "'Noto Naskh Arabic','Traditional Arabic',Tahoma,serif" : "Georgia,'Times New Roman',serif";
    $sans   = $rtl ? "'Noto Sans Arabic',Tahoma,Arial,sans-serif" : "'Segoe UI',Helvetica,Arial,sans-serif";
    $stroke = '<td width="3" height="18" bgcolor="#ffffff" style="font-size:0;line-height:0">&nbsp;</td><td width="3" style="font-size:0;line-height:0">&nbsp;</td>';

    $button = '';
    if ($url !== '' && $texts['button'] !== '') {
        $button = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:26px 0 22px"><tr>'
                . '<td bgcolor="#2d5991" style="border-radius:6px;border-bottom:3px solid #083e7a">'
                . '<a href="' . e($url) . '" style="display:inline-block;padding:12px 22px;font-family:' . $sans . ';font-size:16px;font-weight:600;color:#ffffff;text-decoration:none">'
                . e($texts['button']) . '</a></td></tr></table>'
                . '<p style="margin:0 0 6px;font-size:13px;line-height:1.5;color:#5f6e80">' . e(t('Falls die Schaltfläche nicht funktioniert, kopiere diesen Link in deinen Browser:')) . '</p>'
                . '<p dir="ltr" style="margin:0 0 22px;font-size:13px;line-height:1.5;word-break:break-all;text-align:' . $align . '"><a href="' . e($url) . '" style="color:#12686d">' . e($url) . '</a></p>';
    }

    $html = '<!DOCTYPE html><html lang="' . e(locale()) . '" dir="' . ($rtl ? 'rtl' : 'ltr') . '"><head><meta charset="UTF-8">'
          . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>' . e($texts['subject']) . '</title></head>'
          . '<body style="margin:0;padding:0;background:#f3f5f8">'
          . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="#f3f5f8"><tr><td align="center" style="padding:28px 12px">'
          . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" dir="' . ($rtl ? 'rtl' : 'ltr') . '" style="max-width:560px;background:#ffffff;border:1px solid #dfe5ec;border-top:4px solid #083e7a">'
          // Brand row: the tally of five – four strokes and the orange fifth.
          . '<tr><td style="padding:22px 28px 0;text-align:' . $align . '">'
          . '<table role="presentation" cellpadding="0" cellspacing="0" style="display:inline-table;vertical-align:middle"><tr>'
          . '<td bgcolor="#083e7a" style="padding:8px 7px 8px 10px;border-radius:7px"><table role="presentation" cellpadding="0" cellspacing="0"><tr>'
          . str_repeat($stroke, 4)
          . '<td width="3" height="18" bgcolor="#f5a623" style="font-size:0;line-height:0">&nbsp;</td></tr></table></td>'
          . '<td style="padding:0 10px;font-family:' . $serif . ';font-size:21px;font-weight:600;color:#083e7a">QuickPoll</td>'
          . '</tr></table></td></tr>'
          . '<tr><td style="padding:22px 28px 26px;font-family:' . $sans . ';font-size:16px;line-height:1.6;color:#14263d;text-align:' . $align . '">'
          . '<p style="margin:0 0 14px;font-family:' . $serif . ';font-size:20px;color:#083e7a">' . e($greeting) . '</p>'
          . '<p style="margin:0">' . mail_fill($texts['intro'], $vars, true) . '</p>'
          . ($button !== '' ? $button : '<p style="margin:0 0 16px">&nbsp;</p>')
          . '<p style="margin:0;color:#4a5a6d">' . mail_fill($texts['outro'], $vars, true) . '</p>'
          . '</td></tr>'
          . '<tr><td style="padding:16px 28px;border-top:1px dashed #c4cfdb;font-family:' . $sans . ';font-size:12px;line-height:1.5;color:#5f6e80;text-align:' . $align . '">'
          . e($footer) . '</td></tr>'
          . '</table></td></tr></table></body></html>';

    return ['subject' => $texts['subject'], 'text' => $text, 'html' => $html];
}

/**
 * Send one of the account mails.
 *
 * @param array<string, mixed>  $account row of `nutzer` (needs email, name, sprache)
 * @param array<string, scalar> $vars    placeholders for the texts
 * @param string                $url     target of the button ('' = no button)
 * @param string|null           $to      other recipient than the account's address
 *                                       (confirming a NEW address)
 */
function send_account_mail(array $account, string $kind, array $vars = [], string $url = '', ?string $to = null): bool
{
    // The mail is written in the ACCOUNT's language, not in the language
    // of whoever triggered it (e.g. the person filling in "forgot password").
    $pageLocale = locale();
    set_locale((string) ($account['sprache'] ?? $pageLocale));
    $mail = mail_build($kind, (string) ($account['name'] ?? ''), $vars, $url);
    set_locale($pageLocale);

    $to ??= (string) $account['email'];
    $ok = send_mail($to, $mail['subject'], $mail['text'], $mail['html']);

    // Development on the own machine: remember the link so the next page
    // can show it (there is no mailbox to look into).
    if ($url !== '' && is_local_dev() && session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['dev_mail'] = ['to' => $to, 'subject' => $mail['subject'], 'url' => $url];
    }
    return $ok;
}

/**
 * True only for development AND a request from this very computer.
 * Guards everything that shows mail links on screen: even if someone
 * forgets to set APP_ENV to "production" on a real server, visitors
 * from the internet never see such a link.
 */
function is_local_dev(): bool
{
    return APP_ENV !== 'production' && in_array(client_ip(), ['127.0.0.1', '::1'], true);
}

/** Development only: show (once) the link of the mail that was "sent". */
function render_dev_mail(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['dev_mail'])) {
        return;
    }
    $mail = $_SESSION['dev_mail'];
    unset($_SESSION['dev_mail']);
    if (!is_local_dev()) {
        return;
    }
    echo '<p class="message message-warning">'
       . e(t('Entwicklungsmodus – es wurde keine echte E-Mail versendet (siehe storage/logs/mail.log):')) . '<br>'
       . '<a href="' . e($mail['url']) . '">' . e($mail['subject']) . '</a> <span class="text-muted">→ ' . bdi($mail['to']) . '</span></p>';
}

/**
 * Low-level sending. $html is optional; with it the mail becomes
 * multipart/alternative (the mail program picks the version it can show).
 */
function send_mail(string $to, string $subject, string $text, ?string $html = null): bool
{
    // Header injection guard: an address or subject must never contain line breaks.
    if (preg_match('/[\r\n]/', $to . $subject) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    if (APP_ENV !== 'production') {
        $entry = '[' . date('Y-m-d H:i:s') . "] To: $to\nSubject: $subject\n\n$text\n" . str_repeat('-', 60) . "\n";
        if ($html !== null) {
            @file_put_contents(QP_ROOT . '/storage/logs/mail-preview.html', $html, LOCK_EX);
        }
        return @file_put_contents(QP_ROOT . '/storage/logs/mail.log', $entry, FILE_APPEND | LOCK_EX) !== false;
    }

    $headers = ['From: QuickPoll <' . MAIL_FROM . '>', 'MIME-Version: 1.0'];
    if ($html === null) {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';
        $body = chunk_split(base64_encode($text));
    } else {
        $boundary  = 'qp-' . bin2hex(random_bytes(12));
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        // Base64 keeps every line short and survives any mail server,
        // whatever the language of the text.
        $body = '--' . $boundary . "\r\n"
              . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($text))
              . '--' . $boundary . "\r\n"
              . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($html))
              . '--' . $boundary . "--\r\n";
    }
    return mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));
}
