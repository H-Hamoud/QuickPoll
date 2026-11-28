<?php
declare(strict_types=1);
// ============================================================
//  datenschutz.php – privacy policy
//
//  The sections describe what THIS application technically does
//  (cookies, hashes, Stripe). TODO before going live: add your
//  contact data and your hosting provider, and have the text
//  checked – this is a technical description, not legal advice.
// ============================================================

require_once __DIR__ . '/includes/bootstrap.php';
session_boot_if_cookie();

render_public_top(t('Datenschutz'));
?>
<article class="prose">
    <h1><?= e(t('Datenschutzerklärung')) ?></h1>

    <p class="message message-warning"><?= e(t('Vorlage – Verantwortliche Person und Hosting-Anbieter vor der Veröffentlichung ergänzen.')) ?></p>

    <h2><?= e(t('Verantwortlich')) ?></h2>
    <p><?= e(t('[Vorname Nachname], [Anschrift], [E-Mail-Adresse]')) ?></p>

    <h2><?= e(t('Hosting und Server-Logs')) ?></h2>
    <p><?= e(t('text.datenschutz.1')) ?></p>

    <h2><?= e(t('Konto')) ?></h2>
    <p><?= e(t('text.datenschutz.2')) ?></p>

    <h2><?= e(t('Anmeldung und angemeldete Geräte')) ?></h2>
    <p><?= e(t('text.datenschutz.7')) ?></p>

    <h2><?= e(t('Teilnahme an Umfragen')) ?></h2>
    <p><?= e(t('text.datenschutz.3')) ?></p>

    <h2><?= e(t('Cookies')) ?></h2>
    <p><?= e(t('text.datenschutz.4')) ?></p>

    <h2><?= e(t('Zahlung')) ?></h2>
    <p><?= e(t('text.datenschutz.5')) ?></p>

    <h2><?= e(t('Deine Rechte')) ?></h2>
    <p><?= e(t('text.datenschutz.6')) ?></p>
</article>
<?php render_public_bottom(); ?>
