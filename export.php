<?php
declare(strict_types=1);
// ============================================================
//  export.php – download all answers of a survey as CSV
//  One row per submission, one column per question. Pro feature.
// ============================================================

require_once __DIR__ . '/includes/auth.php';

$survey = find_owned_survey(get_int('id'), $userId);
if (!$survey) {
    flash('warning', t('Diese Umfrage gibt es nicht (mehr).'));
    redirect('surveys.php');
}

// Feature gate on the server – the results page only hides the link.
if (!is_pro($user)) {
    flash('warning', t('Der CSV-Export gehört zum Pro-Tarif.'));
    redirect('subscription.php');
}

/**
 * CSV injection guard: Excel executes cells that start with = + - @
 * as formulas. A leading apostrophe turns them into plain text.
 */
function csv_safe(string $value): string
{
    return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
}

$surveyId  = (int) $survey['id'];
$questions = load_questions($surveyId);

// File name from the title: only harmless characters.
$slug     = trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($survey['titel'])), '-') ?: 'umfrage';
$filename = 'quickpoll-' . $slug . '-' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM, so Excel shows umlauts correctly

// Semicolon as separator: that is what a German Excel expects.
$writeRow = fn(array $row) => fputcsv($out, array_map('csv_safe', $row), ';', '"', '');

$writeRow(array_merge([t('Nr.'), t('Abgegeben am'), t('Dauer (Sekunden)')], array_column($questions, 'text')));

// One query for everything, ordered by submission. The loop collects
// the answers of one submission and writes the row when the id changes
// (classic "control break"), so memory use stays flat for big exports.
$stmt = db()->prepare(
    'SELECT t.id, t.abgegeben_am, t.dauer_sekunden, a.frage_id, a.antwort_text
     FROM teilnahme t
     LEFT JOIN antwort a ON a.teilnahme_id = t.id
     WHERE t.fragebogen_id = ?
     ORDER BY t.abgegeben_am, t.id, a.id'
);
$stmt->execute([$surveyId]);

$flush = function (?array $current) use ($questions, $writeRow): void {
    if ($current === null) {
        return;
    }
    $row = [(string) $current['number'], date('d.m.Y H:i', strtotime($current['date'])), (string) ($current['duration'] ?? '')];
    foreach ($questions as $q) {
        // Multiple choice: several answers in one cell
        $row[] = implode(', ', $current['answers'][(int) $q['id']] ?? []);
    }
    $writeRow($row);
};

$current = null;
$number  = 0;
while ($record = $stmt->fetch()) {
    if ($current === null || $current['id'] !== $record['id']) {
        $flush($current);
        $current = ['id' => $record['id'], 'number' => ++$number, 'date' => $record['abgegeben_am'], 'duration' => $record['dauer_sekunden'], 'answers' => []];
    }
    if ($record['frage_id'] !== null) {
        $current['answers'][(int) $record['frage_id']][] = $record['antwort_text'];
    }
}
$flush($current);

fclose($out);
