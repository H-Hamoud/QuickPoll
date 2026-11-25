<?php
declare(strict_types=1);
// ============================================================
//  analytics.php – evaluations across ALL surveys of the user
//  Three views (tabs): course of the year, comparison of the
//  surveys, and at which times people answer.
// ============================================================

require_once __DIR__ . '/includes/auth.php';

$pdo = db();

// ---------- Filters ----------
$tabs = ['verlauf' => t('Verlauf'), 'umfragen' => t('Umfragen'), 'zeitpunkte' => t('Zeitpunkte')];
$tab  = get_string('tab', 12);
if (!isset($tabs[$tab])) {
    $tab = 'verlauf';
}

// Selectable years: from the first submission until today
$stmt = $pdo->prepare(
    'SELECT MIN(YEAR(t.abgegeben_am))
     FROM teilnahme t JOIN fragebogen f ON f.id = t.fragebogen_id
     WHERE f.nutzer_id = ?'
);
$stmt->execute([$userId]);
$currentYear = (int) date('Y');
$firstYear   = (int) ($stmt->fetchColumn() ?: $currentYear);
$year        = get_int('year', $currentYear);
if ($year < $firstYear || $year > $currentYear) {
    $year = $currentYear;
}

// The user's surveys for the filter. The submitted id is only
// accepted if it is one of them (ownership check).
$stmt = $pdo->prepare('SELECT id, titel FROM fragebogen WHERE nutzer_id = ? ORDER BY titel');
$stmt->execute([$userId]);
$ownSurveys = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);   // id => title
$surveyId   = get_int('survey');
if (!isset($ownSurveys[$surveyId])) {
    $surveyId = 0;   // 0 = all surveys
}

// Shared WHERE clause. A date RANGE instead of YEAR(col) = ? lets
// MySQL use the index on abgegeben_am.
$where  = 'f.nutzer_id = ? AND t.abgegeben_am >= ? AND t.abgegeben_am < ?';
$params = [$userId, $year . '-01-01 00:00:00', ($year + 1) . '-01-01 00:00:00'];
if ($surveyId > 0) {
    $where   .= ' AND f.id = ?';
    $params[] = $surveyId;
}
$from = 'FROM teilnahme t JOIN fragebogen f ON f.id = t.fragebogen_id';

/** Run a "key, COUNT(*)" query and return key => count. */
$countBy = function (string $expression) use ($pdo, $from, $where, $params): array {
    $stmt = $pdo->prepare("SELECT $expression AS k, COUNT(*) AS c $from WHERE $where GROUP BY k");
    $stmt->execute($params);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
};

render_app_top(t('Auswertungen'), 'analytics', $user);
page_head(t('Auswertungen'), t('Wann Antworten eingehen, welche Umfragen gut laufen und zu welchen Zeiten Menschen teilnehmen.'));
?>

<nav class="tabs" aria-label="<?= e(t('Ansicht')) ?>">
    <?php foreach ($tabs as $key => $label): ?>
        <a href="<?= e(query_string(['tab' => $key])) ?>" class="<?= $tab === $key ? 'is-active' : '' ?>"
           <?= $tab === $key ? 'aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>

<form method="get" action="analytics.php" class="filters">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <label class="field">
        <span class="field-label"><?= e(t('Jahr')) ?></span>
        <select name="year" data-autosubmit>
            <?php for ($y = $currentYear; $y >= $firstYear; $y--): ?>
                <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
    </label>
    <?php if ($tab !== 'umfragen'): ?>
        <label class="field">
            <span class="field-label"><?= e(t('Umfrage')) ?></span>
            <select name="survey" data-autosubmit>
                <option value="0"><?= e(t('Alle Umfragen')) ?></option>
                <?php foreach ($ownSurveys as $id => $title): ?>
                    <option value="<?= (int) $id ?>" <?= (int) $id === $surveyId ? 'selected' : '' ?>><?= e(excerpt($title, 50)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    <?php endif; ?>
    <button type="submit" class="btn btn-ghost js-hide"><?= e(t('Anwenden')) ?></button>
</form>

<?php if ($tab === 'verlauf'): ?>

    <?php
    $perMonth = $countBy('MONTH(t.abgegeben_am)');
    $total    = array_sum($perMonth);
    $active   = count($perMonth);                       // months with at least one answer
    $best     = $perMonth ? array_search(max($perMonth), $perMonth, true) : null;

    $series = [];
    foreach (month_names(true) as $m => $name) {
        $count    = $perMonth[$m] ?? 0;
        $series[] = [
            'label' => $name,
            'value' => $count,
            'tip'   => month_names()[$m] . ' ' . $year . ': ' . plural($count, 'Antwort', 'Antworten'),
        ];
    }
    ?>
    <section class="stat-grid stat-grid-3" aria-label="<?= e(t('Kennzahlen')) ?>">
        <div class="stat">
            <p class="stat-label"><?= e(t('Antworten {jahr}', ['jahr' => $year])) ?></p>
            <p class="stat-value"><?= format_number($total) ?></p>
            <p class="stat-note"><?= e($surveyId > 0 ? excerpt($ownSurveys[$surveyId], 40) : t('alle Umfragen')) ?></p>
        </div>
        <div class="stat">
            <p class="stat-label"><?= e(t('Durchschnitt pro Monat')) ?></p>
            <p class="stat-value"><?= $active > 0 ? format_number($total / $active, 1) : '–' ?></p>
            <p class="stat-note"><?= e(t('{monate} mit Antworten im Jahr', ['monate' => plural($active, 'Monat', 'Monate')])) ?></p>
        </div>
        <div class="stat">
            <p class="stat-label"><?= e(t('Bester Monat')) ?></p>
            <p class="stat-value stat-value-text"><?= $best ? e(month_names()[$best]) : '–' ?></p>
            <p class="stat-note"><?= e($best ? plural($perMonth[$best], 'Antwort', 'Antworten') : t('noch keine Antworten')) ?></p>
        </div>
    </section>

    <section class="card">
        <header class="card-head"><h2><?= e(t('Antworten je Monat')) ?></h2></header>
        <?= chart_columns($series, [
            'title'  => t('Antworten je Monat im Jahr {jahr}', ['jahr' => $year]),
            'column' => t('Monat'),
            'empty'  => t('Im Jahr {jahr} sind für diese Auswahl keine Antworten eingegangen.', ['jahr' => $year]),
        ]) ?>
    </section>

<?php elseif ($tab === 'umfragen'): ?>

    <?php
    // LEFT JOIN with the date condition INSIDE the ON clause: surveys
    // without answers in this year stay in the list with count 0.
    $stmt = $pdo->prepare(
        'SELECT f.id, f.titel, f.veroeffentlicht, f.endet_am, f.archiviert_am, f.max_teilnahmen, f.aufrufe,
                COUNT(t.id) AS anzahl,
                (SELECT COUNT(*) FROM teilnahme g WHERE g.fragebogen_id = f.id) AS teilnahmen
         FROM fragebogen f
         LEFT JOIN teilnahme t ON t.fragebogen_id = f.id
              AND t.abgegeben_am >= ? AND t.abgegeben_am < ?
         WHERE f.nutzer_id = ?
         GROUP BY f.id, f.titel, f.veroeffentlicht, f.endet_am, f.archiviert_am, f.max_teilnahmen, f.aufrufe
         ORDER BY anzahl DESC, f.titel'
    );
    $stmt->execute([$year . '-01-01 00:00:00', ($year + 1) . '-01-01 00:00:00', $userId]);
    $rows  = $stmt->fetchAll();
    $total = array_sum(array_column($rows, 'anzahl'));

    $bars = [];
    foreach (array_slice($rows, 0, 8) as $row) {
        $bars[] = [
            'label' => $row['titel'],
            'value' => (int) $row['anzahl'],
            'note'  => $total > 0 ? format_percent($row['anzahl'] / $total) : '',
            'href'  => 'results.php?id=' . (int) $row['id'],
        ];
    }
    ?>
    <?php if (!$rows): ?>
        <section class="card empty-state">
            <h2><?= e(t('Noch keine Umfragen')) ?></h2>
            <p><a href="survey_create.php" class="btn"><?= e(t('Umfrage erstellen')) ?></a></p>
        </section>
    <?php else: ?>
        <section class="card">
            <header class="card-head">
                <h2><?= e(t('Antworten nach Umfrage')) ?></h2>
                <span class="text-muted"><?= e(t('{antworten} im Jahr {jahr}', ['antworten' => plural((int) $total, 'Antwort', 'Antworten'), 'jahr' => $year])) ?></span>
            </header>
            <?= chart_hbars($bars) ?>
        </section>

        <section class="card card-flush">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th><?= e(t('Umfrage')) ?></th>
                        <th><?= e(t('Status')) ?></th>
                        <th class="num"><?= e(t('Antworten {jahr}', ['jahr' => $year])) ?></th>
                        <th class="num"><?= e(t('Anteil')) ?></th>
                        <th class="num"><?= e(t('Abschlussquote')) ?></th>
                        <th class="num"><?= e(t('Bearbeitungszeit')) ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td class="cell-title"><a href="results.php?id=<?= (int) $row['id'] ?>"><?= bdi($row['titel']) ?></a></td>
                            <td><?= status_badge($row) ?></td>
                            <td class="num"><?= format_number((int) $row['anzahl']) ?></td>
                            <td class="num"><?= $total > 0 ? format_percent($row['anzahl'] / $total) : '–' ?></td>
                            <?php /* Lifetime figures: views are not stored per year. */ ?>
                            <td class="num" title="<?= e(t('{antworten} Antworten bei {aufrufe} Aufrufen, gesamte Laufzeit', ['antworten' => format_number((int) $row['teilnahmen']), 'aufrufe' => format_number((int) $row['aufrufe'])])) ?>">
                                <?= $row['aufrufe'] > 0 ? format_percent(min(1, $row['teilnahmen'] / $row['aufrufe'])) : '–' ?></td>
                            <td class="num"><?= e(format_duration(completion_time((int) $row['id'])['median'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

<?php else: ?>

    <?php
    // WEEKDAY() returns 0 = Monday … 6 = Sunday
    $perWeekday = $countBy('WEEKDAY(t.abgegeben_am)');
    $perHour    = $countBy('HOUR(t.abgegeben_am)');
    $total      = array_sum($perWeekday);

    $weekdayLong = weekday_names();

    $weekdaySeries = [];
    foreach (weekday_names(true) as $d => $name) {
        $count = $perWeekday[$d] ?? 0;
        $weekdaySeries[] = ['label' => $name, 'value' => $count,
            'tip' => $weekdayLong[$d] . ': ' . plural($count, 'Antwort', 'Antworten')];
    }

    $hourSeries = [];
    for ($h = 0; $h < 24; $h++) {
        $count = $perHour[$h] ?? 0;
        $hourSeries[] = ['label' => t('{h} Uhr', ['h' => $h]), 'value' => $count,
            'tip' => sprintf('%02d:00–%02d:59: ', $h, $h) . plural($count, 'Antwort', 'Antworten')];
    }

    $bestDay  = $perWeekday ? array_search(max($perWeekday), $perWeekday, true) : null;
    $bestHour = $perHour ? array_search(max($perHour), $perHour, true) : null;
    ?>
    <section class="stat-grid stat-grid-3" aria-label="<?= e(t('Kennzahlen')) ?>">
        <div class="stat">
            <p class="stat-label"><?= e(t('Antworten {jahr}', ['jahr' => $year])) ?></p>
            <p class="stat-value"><?= format_number($total) ?></p>
            <p class="stat-note"><?= e($surveyId > 0 ? excerpt($ownSurveys[$surveyId], 40) : t('alle Umfragen')) ?></p>
        </div>
        <div class="stat">
            <p class="stat-label"><?= e(t('Stärkster Wochentag')) ?></p>
            <p class="stat-value stat-value-text"><?= $bestDay !== null ? e($weekdayLong[$bestDay]) : '–' ?></p>
            <p class="stat-note"><?= e($bestDay !== null ? t('{anteil} aller Antworten', ['anteil' => format_percent($perWeekday[$bestDay] / $total)]) : t('noch keine Antworten')) ?></p>
        </div>
        <div class="stat">
            <p class="stat-label"><?= e(t('Stärkste Uhrzeit')) ?></p>
            <p class="stat-value stat-value-text"><?= $bestHour !== null ? e(t('{von}–{bis} Uhr', ['von' => $bestHour, 'bis' => $bestHour + 1])) : '–' ?></p>
            <p class="stat-note"><?= e($bestHour !== null ? t('{antworten} in dieser Stunde', ['antworten' => plural($perHour[$bestHour], 'Antwort', 'Antworten')]) : t('noch keine Antworten')) ?></p>
        </div>
    </section>

    <div class="grid-2">
        <section class="card">
            <header class="card-head"><h2><?= e(t('Nach Wochentag')) ?></h2></header>
            <?= chart_columns($weekdaySeries, [
                'title'  => t('Antworten nach Wochentag im Jahr {jahr}', ['jahr' => $year]),
                'column' => t('Wochentag'),
                'empty'  => t('Im Jahr {jahr} sind für diese Auswahl keine Antworten eingegangen.', ['jahr' => $year]),
            ]) ?>
        </section>
        <section class="card">
            <header class="card-head"><h2><?= e(t('Nach Uhrzeit')) ?></h2></header>
            <?= chart_columns($hourSeries, [
                'title'       => t('Antworten nach Uhrzeit im Jahr {jahr}', ['jahr' => $year]),
                'label_every' => 6,
                'column'      => t('Stunde'),
                'empty'       => t('Im Jahr {jahr} sind für diese Auswahl keine Antworten eingegangen.', ['jahr' => $year]),
            ]) ?>
        </section>
    </div>

<?php endif; ?>

<?php render_app_bottom(); ?>
