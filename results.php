<?php
// ============================================================
//  results.php – survey results (customers only)
//  Shows all answers grouped per question – exactly how the
//  flat data model is meant to be evaluated.
// ============================================================


// ---------- Access guard (see includes/auth.php) ----------
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
/** @var PDO $pdo */

$userId = $_SESSION['user_id'];

// ---------- Load survey + ownership check ----------
$surveyId = (int)($_GET['id'] ?? 0);

// Same protection as in the editor: results are private,
// every customer only ever sees their own surveys.
$stmt = $pdo->prepare(
        'SELECT * FROM fragebogen WHERE id = ? AND nutzer_id = ?'
);
$stmt->execute([$surveyId, $userId]);
$survey = $stmt->fetch();

if (!$survey) {
    header('Location: dashboard.php');
    exit;
}

// ---------- Load questions ----------
$stmt = $pdo->prepare(
        'SELECT * FROM frage WHERE fragebogen_id = ? ORDER BY reihenfolge'
);
$stmt->execute([$surveyId]);
$questions = $stmt->fetchAll();

// ---------- Load answers per question ----------
// Prepare ONCE, execute once per question (same pattern as in
// survey.php). Result: a lookup array  question id => list of answers.
$answerStmt = $pdo->prepare(
        'SELECT * FROM antwort WHERE frage_id = ? ORDER BY abgegeben_am DESC'
);

$answersByQuestion = [];
foreach ($questions as $question) {
    $answerStmt->execute([$question['id']]);
    $answersByQuestion[$question['id']] = $answerStmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>QuickPoll – Ergebnisse</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<header>
    <h1>Quick<span class="brand-accent">Poll</span></h1>
    <nav>
        <a href="subscription.php">Mein Abo</a>
        <a href="logout.php">Logout</a>
        <a href="dashboard.php">&larr; Zurück zum Dashboard</a>
    </nav>
</header>

<main>
    <div class="page">
        <section class="card">
            <h2>Ergebnisse: <?= htmlspecialchars($survey['titel']) ?></h2>

            <?php if ($survey['veroeffentlicht']): ?>
                <p>
                    Öffentlicher Link:
                    <a href="survey.php?id=<?= $surveyId ?>">survey.php?id=<?= $surveyId ?></a>
                </p>
            <?php endif; ?>

            <?php if (empty($questions)): ?>

                <p>Dieser Fragebogen enthält noch keine Fragen.</p>

            <?php else: ?>

                <?php foreach ($questions as $i => $question): ?>
                    <?php $answers = $answersByQuestion[$question['id']]; ?>

                    <h3><?= $i + 1 ?>. <?= htmlspecialchars($question['text']) ?></h3>
                    <p class="text-muted"><?= count($answers) ?> Antwort(en)</p>

                    <?php if (empty($answers)): ?>
                        <p><i>Noch keine Antworten.</i></p>
                    <?php else: ?>
                        <ul class="answer-list">
                            <?php foreach ($answers as $answer): ?>
                                <li>
                                    <span class="answer-text"><?= htmlspecialchars($answer['antwort_text']) ?> </span>
                                    <small class="text-muted"><?= $answer['abgegeben_am'] ?></small>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                <?php endforeach; ?>

            <?php endif; ?>
        </section>
 </div>
</main>
<footer>
    <p>QuickPoll &ndash; Studienprojekt Internetserver-Programmierung</p>
</footer>
</body>
</html>