<?php
declare(strict_types=1);
// ============================================================
//  surveys.php – "Meine Umfragen": list with search, status
//  filter, sorting and pagination. Also handles the list actions
//  "duplicate" and "delete".
// ============================================================

require_once __DIR__ . '/includes/auth.php';

$pdo = db();

// ---------- Actions ----------
if (is_post()) {
    $action = post_string('action', 30);
    // The ownership check: a foreign or invented id yields null.
    $survey = find_owned_survey(post_int('survey_id'), $userId);

    if ($survey && $action === 'duplicate') {
        if (!can_create_survey($user)) {
            flash('warning', t('Im Free-Tarif sind {n} Umfragen enthalten. Mit Pro erstellst du beliebig viele.', ['n' => (int) FREE_SURVEY_LIMIT]));
            redirect('subscription.php');
        }
        $newId = duplicate_survey($survey, $userId);
        flash('success', t('Umfrage dupliziert. Die Kopie ist ein Entwurf.'));
        redirect('survey_edit.php?id=' . $newId);
    }

    if ($survey && $action === 'archive') {
        $pdo->prepare('UPDATE fragebogen SET archiviert_am = NOW() WHERE id = ? AND nutzer_id = ?')->execute([$survey['id'], $userId]);
        flash('success', t('Umfrage „{titel}“ archiviert.', ['titel' => $survey['titel']]));
    }

    if ($survey && $action === 'unarchive') {
        $pdo->prepare('UPDATE fragebogen SET archiviert_am = NULL WHERE id = ? AND nutzer_id = ?')->execute([$survey['id'], $userId]);
        flash('success', t('Umfrage „{titel}“ aus dem Archiv geholt.', ['titel' => $survey['titel']]));
        redirect('surveys.php');
    }

    if ($survey && $action === 'delete') {
        // ON DELETE CASCADE removes questions, options, submissions and answers.
        $pdo->prepare('DELETE FROM fragebogen WHERE id = ? AND nutzer_id = ?')->execute([$survey['id'], $userId]);
        flash('success', t('Umfrage „{titel}“ gelöscht.', ['titel' => $survey['titel']]));
    }

    redirect('surveys.php');   // PRG pattern
}

// ---------- Filters ----------
$search = get_string('q', 100);
$status = get_string('status', 10);
$sort   = get_string('sort', 12);
$page   = max(1, get_int('page', 1));
$perPage = 10;

$where  = ['f.nutzer_id = ?'];
$params = [$userId];

if ($search !== '') {
    // % and _ are wildcards in LIKE – escape them so they are searched literally.
    $where[]  = 'f.titel LIKE ?';
    $params[] = '%' . addcslashes($search, '%_\\') . '%';
}

// The status is derived from several columns (see survey_status()).
// "Full" = the response limit is reached; that closes a survey too.
$full      = '(f.max_teilnahmen IS NOT NULL AND (SELECT COUNT(*) FROM teilnahme t WHERE t.fragebogen_id = f.id) >= f.max_teilnahmen)';
$statusSql = [
    'draft'    => 'f.archiviert_am IS NULL AND f.veroeffentlicht = 0',
    'live'     => "f.archiviert_am IS NULL AND f.veroeffentlicht = 1 AND (f.endet_am IS NULL OR f.endet_am > NOW()) AND NOT $full",
    'closed'   => "f.archiviert_am IS NULL AND f.veroeffentlicht = 1 AND (f.endet_am <= NOW() OR $full)",
    'archived' => 'f.archiviert_am IS NOT NULL',
];
if (isset($statusSql[$status])) {
    $where[] = '(' . $statusSql[$status] . ')';
} else {
    // Without a status filter the archive stays out of the way.
    $where[] = 'f.archiviert_am IS NULL';
}

$stmt = $pdo->prepare('SELECT COUNT(*) FROM fragebogen WHERE nutzer_id = ? AND archiviert_am IS NOT NULL');
$stmt->execute([$userId]);
$archivedCount = (int) $stmt->fetchColumn();

// ORDER BY cannot be a bound parameter – so the user input only
// SELECTS one of these fixed strings (whitelist), it never becomes SQL.
$sortSql = [
    'new'       => 'f.erstellt_am DESC, f.id DESC',
    'responses' => 'teilnahmen DESC, f.erstellt_am DESC',
    'title'     => 'f.titel ASC',
];
$orderBy = $sortSql[$sort] ?? $sortSql['new'];

$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM fragebogen f WHERE $whereSql");
$stmt->execute($params);
$totalRows  = (int) $stmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// LIMIT/OFFSET are integers we calculated ourselves, safe to insert.
$stmt = $pdo->prepare(
    "SELECT f.*,
            (SELECT COUNT(*) FROM frage q WHERE q.fragebogen_id = f.id)     AS fragen,
            (SELECT COUNT(*) FROM teilnahme t WHERE t.fragebogen_id = f.id) AS teilnahmen
     FROM fragebogen f
     WHERE $whereSql
     ORDER BY $orderBy
     LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$surveys = $stmt->fetchAll();

$hasAny     = count_user_surveys($userId) > 0;
$isFiltered = $search !== '' || isset($statusSql[$status]);

render_app_top(t('Meine Umfragen'), 'surveys', $user);

page_head(
    t('Meine Umfragen'),
    $hasAny ? ($isFiltered ? plural($totalRows, 'Umfrage gefunden', 'Umfragen gefunden') : plural($totalRows, 'Umfrage', 'Umfragen')) : '',
    can_create_survey($user)
        ? '<a href="survey_create.php" class="btn">' . icon('plus') . ' ' . e(t('Neue Umfrage')) . '</a>'
        : '<a href="subscription.php" class="btn">' . e(t('Auf Pro wechseln')) . '</a>'
);
?>

<?php if (!$hasAny): ?>

    <section class="card empty-state">
        <div>
            <h2><?= e(t('Noch keine Umfragen')) ?></h2>
            <p><?= e(t('Lege einen Fragebogen an, veröffentliche ihn und teile den Link – die Antworten landen hier.')) ?></p>
            <p><a href="survey_create.php" class="btn"><?= icon('plus') ?> <?= e(t('Umfrage erstellen')) ?></a></p>
        </div>
        <?= illustration_ballot_box() ?>
    </section>

<?php else: ?>

    <?php if (!can_create_survey($user)): ?>
        <p class="message message-warning">
            <?= e(t('Du nutzt alle {n} Umfragen des Free-Tarifs.', ['n' => (int) FREE_SURVEY_LIMIT])) ?>
            <a href="subscription.php"><?= e(t('Mit Pro erstellst du beliebig viele.')) ?></a>
        </p>
    <?php endif; ?>

    <form method="get" action="surveys.php" class="filters">
        <label class="field field-grow">
            <span class="field-label"><?= e(t('Suche')) ?></span>
            <input type="search" name="q" value="<?= e($search) ?>" placeholder="<?= e(t('Titel enthält …')) ?>">
        </label>
        <label class="field">
            <span class="field-label"><?= e(t('Status')) ?></span>
            <select name="status">
                <option value=""><?= e(t('Alle')) ?></option>
                <?php foreach (SURVEY_STATUS_LABELS as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= e(t($label)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field">
            <span class="field-label"><?= e(t('Sortierung')) ?></span>
            <select name="sort">
                <option value="new" <?= $sort === 'new' || $sort === '' ? 'selected' : '' ?>><?= e(t('Neueste zuerst')) ?></option>
                <option value="responses" <?= $sort === 'responses' ? 'selected' : '' ?>><?= e(t('Meiste Antworten')) ?></option>
                <option value="title" <?= $sort === 'title' ? 'selected' : '' ?>><?= e(t('Titel A–Z')) ?></option>
            </select>
        </label>
        <button type="submit" class="btn btn-ghost"><?= e(t('Anwenden')) ?></button>
    </form>

    <?php if ($archivedCount > 0 && $status !== 'archived'): ?>
        <p class="list-note"><a href="surveys.php?status=archived"><?= e(plural($archivedCount, 'archivierte Umfrage', 'archivierte Umfragen')) ?></a></p>
    <?php endif; ?>

    <section class="card card-flush">
        <?php if (!$surveys): ?>
            <p class="card-pad text-muted"><?= e(t('Keine Umfrage passt zu diesem Filter.')) ?> <a href="surveys.php"><?= e(t('Filter zurücksetzen')) ?></a></p>
        <?php else: ?>
            <div class="table-wrap">
                <?php $mostResponses = max(1, max(array_column($surveys, 'teilnahmen'))); ?>
                <!-- .table-stack: on phones every row becomes a small block,
                     the data-label attributes replace the table header -->
                <table class="table table-stack">
                    <thead>
                    <tr>
                        <th><?= e(t('Titel')) ?></th>
                        <th><?= e(t('Status')) ?></th>
                        <th class="num"><?= e(t('Fragen')) ?></th>
                        <th class="num"><?= e(t('Aufrufe')) ?></th>
                        <th class="num"><?= e(t('Antworten')) ?></th>
                        <th><?= e(t('Erstellt')) ?></th>
                        <th><span class="sr-only"><?= e(t('Aktionen')) ?></span></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($surveys as $survey): ?>
                        <?php $sid = (int) $survey['id']; ?>
                        <tr>
                            <td class="cell-title">
                                <a href="survey_edit.php?id=<?= $sid ?>"><?= bdi($survey['titel']) ?></a>
                                <?php if (!empty($survey['beschreibung'])): ?>
                                    <small><?= bdi(excerpt($survey['beschreibung'], 70)) ?></small>
                                <?php endif; ?>
                            </td>
                            <td data-label="<?= e(t('Status')) ?>"><?= status_badge($survey) ?></td>
                            <td class="num" data-label="<?= e(t('Fragen')) ?>"><?= format_number((int) $survey['fragen']) ?></td>
                            <td class="num" data-label="<?= e(t('Aufrufe')) ?>"><?= format_number((int) $survey['aufrufe']) ?></td>
                            <td class="num cell-meter" data-label="<?= e(t('Antworten')) ?>">
                                <?= format_number((int) $survey['teilnahmen']) ?>
                                <!-- thin bar: this survey compared with the busiest one on the page -->
                                <svg width="64" height="4" aria-hidden="true">
                                    <rect class="hbar-bg" width="64" height="4" rx="1"/>
                                    <rect class="hbar-fill" width="<?= round($survey['teilnahmen'] / $mostResponses * 64, 1) ?>" height="4" rx="1"/>
                                </svg>
                            </td>
                            <td data-label="<?= e(t('Erstellt')) ?>"><?= e(format_date($survey['erstellt_am'])) ?></td>
                            <td class="cell-actions">
                                <a class="btn btn-ghost btn-sm" href="results.php?id=<?= $sid ?>"><?= e(t('Ergebnisse')) ?></a>
                                <form method="post" action="surveys.php">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="survey_id" value="<?= $sid ?>">
                                    <?php if ($survey['archiviert_am'] === null): ?>
                                        <button type="submit" name="action" value="archive" class="btn-icon"
                                                title="<?= e(t('Archivieren')) ?>" aria-label="<?= e(t('„{titel}“ archivieren', ['titel' => $survey['titel']])) ?>"><?= icon('archive') ?></button>
                                    <?php else: ?>
                                        <button type="submit" name="action" value="unarchive" class="btn-icon"
                                                title="<?= e(t('Aus dem Archiv holen')) ?>" aria-label="<?= e(t('„{titel}“ aus dem Archiv holen', ['titel' => $survey['titel']])) ?>"><?= icon('unarchive') ?></button>
                                    <?php endif; ?>
                                    <button type="submit" name="action" value="duplicate" class="btn-icon"
                                            title="<?= e(t('Duplizieren')) ?>" aria-label="<?= e(t('„{titel}“ duplizieren', ['titel' => $survey['titel']])) ?>"><?= icon('copy') ?></button>
                                    <button type="submit" name="action" value="delete" class="btn-icon btn-icon-danger"
                                            title="<?= e(t('Löschen')) ?>" aria-label="<?= e(t('„{titel}“ löschen', ['titel' => $survey['titel']])) ?>"
                                            data-confirm="<?= e(t('Die Umfrage „{titel}“ mit allen Fragen und Antworten löschen?', ['titel' => $survey['titel']])) ?>"><?= icon('trash') ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <?php render_pagination($page, $totalPages); ?>

<?php endif; ?>

<?php render_app_bottom(); ?>
