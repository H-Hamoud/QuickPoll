<?php
declare(strict_types=1);
// ============================================================
//  responses.php – individual submissions
//  List of all filled-in questionnaires (filter by survey), a
//  detail view of one submission (?view=ID) and deleting one.
// ============================================================

require_once __DIR__ . '/includes/auth.php';

$pdo = db();

/**
 * Load a submission ONLY if its survey belongs to the user.
 * The JOIN to fragebogen carries the ownership check.
 */
$findSubmission = function (int $id) use ($pdo, $userId): ?array {
    $stmt = $pdo->prepare(
        'SELECT t.*, f.titel, f.token, f.id AS survey_id
         FROM teilnahme t
         JOIN fragebogen f ON f.id = t.fragebogen_id
         WHERE t.id = ? AND f.nutzer_id = ?'
    );
    $stmt->execute([$id, $userId]);
    return $stmt->fetch() ?: null;
};

// ---------- Delete one submission ----------
if (is_post() && post_string('action') === 'delete') {
    $submission = $findSubmission(post_int('submission_id'));
    if ($submission) {
        // The answers go with it (ON DELETE CASCADE).
        $pdo->prepare('DELETE FROM teilnahme WHERE id = ?')->execute([$submission['id']]);
        flash('success', t('Antwort gelöscht.'));
        redirect('responses.php?survey=' . (int) $submission['survey_id']);
    }
    redirect('responses.php');
}

// ============================================================
//  Detail view
// ============================================================
$viewId = get_int('view');
if ($viewId > 0) {
    $submission = $findSubmission($viewId);
    if (!$submission) {
        flash('warning', t('Diese Antwort gibt es nicht (mehr).'));
        redirect('responses.php');
    }

    // All questions of the survey, each with this person's answer(s).
    // LEFT JOIN: unanswered questions stay in the list.
    $stmt = $pdo->prepare(
        'SELECT q.id, q.text, q.typ, a.antwort_text
         FROM frage q
         LEFT JOIN antwort a ON a.frage_id = q.id AND a.teilnahme_id = ?
         WHERE q.fragebogen_id = ?
         ORDER BY q.reihenfolge, q.id, a.id'
    );
    $stmt->execute([$viewId, $submission['survey_id']]);

    $items = [];   // question id => ['text' => …, 'typ' => …, 'answers' => […]]
    foreach ($stmt->fetchAll() as $row) {
        $items[$row['id']] ??= ['text' => $row['text'], 'typ' => $row['typ'], 'answers' => []];
        if ($row['antwort_text'] !== null) {
            $items[$row['id']]['answers'][] = $row['antwort_text'];
        }
    }

    $survey = find_owned_survey((int) $submission['survey_id'], $userId);
    render_app_top(t('Antwort ansehen'), 'surveys', $user, $survey, 'responses');
    page_head(
        t('Antwort vom {datum}', ['datum' => format_datetime($submission['abgegeben_am'])]),
        $submission['titel'],
        '<a class="btn btn-ghost" href="responses.php?survey=' . (int) $submission['survey_id'] . '">' . e(t('Zur Liste')) . '</a>'
    );
    ?>
    <section class="card">
        <ol class="response-detail">
            <?php foreach ($items as $item): ?>
                <li>
                    <p class="response-question"><?= bdi($item['text']) ?></p>
                    <?php if (!$item['answers']): ?>
                        <p class="text-muted"><?= e(t('Nicht beantwortet')) ?></p>
                    <?php elseif ($item['typ'] === 'rating'): ?>
                        <p class="response-answer"><?= e(t('{n} von 5', ['n' => $item['answers'][0]])) ?></p>
                    <?php else: ?>
                        <p class="response-answer" dir="auto"><?= nl2br(e($item['typ'] === 'yesno' ? t($item['answers'][0]) : implode(', ', $item['answers']))) ?></p>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </section>

    <form method="post" action="responses.php">
        <?= csrf_field() ?>
        <input type="hidden" name="submission_id" value="<?= $viewId ?>">
        <button type="submit" name="action" value="delete" class="btn btn-danger"
                data-confirm="<?= e(t('Diese Antwort unwiderruflich löschen?')) ?>"><?= icon('trash') ?> <?= e(t('Antwort löschen')) ?></button>
    </form>
    <?php
    render_app_bottom();
    exit;
}

// ============================================================
//  List view
// ============================================================
$stmt = $pdo->prepare('SELECT id, titel FROM fragebogen WHERE nutzer_id = ? ORDER BY titel');
$stmt->execute([$userId]);
$ownSurveys = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$surveyId = get_int('survey');
if (!isset($ownSurveys[$surveyId])) {
    $surveyId = 0;
}

$where  = 'f.nutzer_id = ?';
$params = [$userId];
if ($surveyId > 0) {
    $where   .= ' AND f.id = ?';
    $params[] = $surveyId;
}

$perPage = 20;
$stmt = $pdo->prepare("SELECT COUNT(*) FROM teilnahme t JOIN fragebogen f ON f.id = t.fragebogen_id WHERE $where");
$stmt->execute($params);
$totalRows  = (int) $stmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page       = min(max(1, get_int('page', 1)), $totalPages);
$offset     = ($page - 1) * $perPage;

$stmt = $pdo->prepare(
    "SELECT t.id, t.abgegeben_am, f.id AS survey_id, f.titel,
            (SELECT COUNT(DISTINCT a.frage_id) FROM antwort a WHERE a.teilnahme_id = t.id) AS beantwortet,
            (SELECT COUNT(*) FROM frage q WHERE q.fragebogen_id = f.id) AS fragen
     FROM teilnahme t
     JOIN fragebogen f ON f.id = t.fragebogen_id
     WHERE $where
     ORDER BY t.abgegeben_am DESC, t.id DESC
     LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$submissions = $stmt->fetchAll();

$survey = $surveyId > 0 ? find_owned_survey($surveyId, $userId) : null;

render_app_top(t('Antworten'), $survey ? 'surveys' : 'responses', $user, $survey, 'responses');
page_head(t('Antworten'), plural($totalRows, 'ausgefüllter Fragebogen', 'ausgefüllte Fragebögen'));
?>

<form method="get" action="responses.php" class="filters">
    <label class="field">
        <span class="field-label"><?= e(t('Umfrage')) ?></span>
        <select name="survey" data-autosubmit>
            <option value="0"><?= e(t('Alle Umfragen')) ?></option>
            <?php foreach ($ownSurveys as $id => $title): ?>
                <option value="<?= (int) $id ?>" <?= (int) $id === $surveyId ? 'selected' : '' ?>><?= e(excerpt($title, 50)) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button type="submit" class="btn btn-ghost js-hide"><?= e(t('Anwenden')) ?></button>
</form>

<?php if (!$submissions): ?>
    <section class="card empty-state">
        <div>
            <h2><?= e(t('Noch keine Antworten')) ?></h2>
            <p><?= e(t('Sobald jemand einen veröffentlichten Fragebogen ausfüllt, erscheint die Antwort hier.')) ?></p>
            <p><a href="surveys.php" class="btn btn-ghost"><?= e(t('Zu meinen Umfragen')) ?></a></p>
        </div>
        <?= illustration_ballot_box() ?>
    </section>
<?php else: ?>
    <section class="card card-flush">
        <div class="table-wrap">
            <table class="table table-stack">
                <thead>
                <tr>
                    <th><?= e(t('Eingegangen')) ?></th>
                    <th><?= e(t('Umfrage')) ?></th>
                    <th class="num"><?= e(t('Beantwortet')) ?></th>
                    <th><span class="sr-only"><?= e(t('Aktionen')) ?></span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($submissions as $row): ?>
                    <tr>
                        <td data-label="<?= e(t('Eingegangen')) ?>"><?= e(format_datetime($row['abgegeben_am'])) ?></td>
                        <td class="cell-title"><?= bdi($row['titel']) ?></td>
                        <td class="num" data-label="<?= e(t('Beantwortet')) ?>"><?= e(t('{n} von {gesamt} Fragen', ['n' => (int) $row['beantwortet'], 'gesamt' => (int) $row['fragen']])) ?></td>
                        <td class="cell-actions">
                            <a class="btn btn-ghost btn-sm" href="responses.php?view=<?= (int) $row['id'] ?>"><?= e(t('Ansehen')) ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php render_pagination($page, $totalPages); ?>
<?php endif; ?>

<?php render_app_bottom(); ?>
