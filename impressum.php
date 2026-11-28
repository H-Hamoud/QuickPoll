<?php
declare(strict_types=1);
// ============================================================
//  impressum.php – legal notice (required in Germany, § 5 DDG)
//
//  TODO before going live: replace every [placeholder] with your
//  real data. This text is a structural template, not legal advice.
// ============================================================

require_once __DIR__ . '/includes/bootstrap.php';
session_boot_if_cookie();

render_public_top(t('Impressum'));
?>
<article class="prose">
    <h1><?= e(t('Impressum')) ?></h1>

    <p class="message message-warning"><?= e(t('Platzhalter – vor der Veröffentlichung mit den echten Angaben ersetzen.')) ?></p>

    <h2><?= e(t('Angaben gemäß § 5 DDG')) ?></h2>
    <p><?= e(t('[Vorname Nachname]')) ?><br><?= e(t('[Straße und Hausnummer]')) ?><br><?= e(t('[PLZ Ort]')) ?></p>

    <h2><?= e(t('Kontakt')) ?></h2>
    <p><?= e(t('E-Mail: [adresse@example.com]')) ?></p>

    <h2><?= e(t('Hinweis')) ?></h2>
    <p><?= e(t('text.impressum.1')) ?></p>
</article>
<?php render_public_bottom(); ?>
