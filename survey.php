<?php
// ============================================================
//  survey.php – public survey page (end users)
//  Deliberately NO login and NO session: end users answer
//  anonymously – that is the second user group of QuickPoll.
// ============================================================

require_once __DIR__ . '/includes/db.php';
/** @var PDO $pdo */

$surveyId = (int)($_GET['id'] ?? 0);
$saved    = isset($_GET['saved']);   // confirmation flag (set after saving)

// Only PUBLISHED surveys are publicly visible.
// "AND veroeffentlicht = 1" is the public counterpart of the
// ownership check: drafts stay invisible, whatever id is tried.
$stmt = $pdo->prepare(
    'SELECT * FROM fragebogen WHERE id = ? AND veroeffentlicht = 1'
);
$stmt->execute([$surveyId]);
$survey = $stmt->fetch();

if (!$survey) {
    // Public page: show a short message (no login redirect here).
    http_response_code(404);
    exit('<h1>QuickPoll</h1><p>Diese Umfrage existiert nicht oder ist nicht öffentlich.</p>');
}

// Load the questions of this survey
$stmt = $pdo->prepare(
    'SELECT * FROM frage WHERE fragebogen_id = ? ORDER BY reihenfolge'
);
$stmt->execute([$surveyId]);
$questions = $stmt->fetchAll();

$errors = [];

// ---------- Save answers ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // $_POST['answers'] is an array: question id => answer text.
    // The [] syntax in the form's name attribute builds it automatically.
    $answers = $_POST['answers'] ?? [];

    // Prepare ONCE, execute several times inside the loop –
    // exactly what prepared statements are made for.
    $stmt = $pdo->prepare(
        'INSERT INTO antwort (frage_id, antwort_text) VALUES (?, ?)'
    );

    // Loop over the survey's REAL questions from the database,
    // never over what the client sent. The submitted array is only
    // used as a lookup – so nobody can smuggle in answers for
    // questions that belong to a different survey.
    $savedCount = 0;
    foreach ($questions as $question) {
        $text = trim($answers[$question['id']] ?? '');
        if ($text !== '') {
            $stmt->execute([$question['id'], $text]);
            $savedCount++;
        }
    }

    if ($savedCount === 0) {
        $errors[] = 'Bitte beantworte mindestens eine Frage.';
    } else {
        // PRG pattern with a flag: reload via redirect so that F5
        // cannot submit the same answers a second time.
        header('Location: survey.php?id=' . $surveyId . '&saved=1');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>QuickPoll – <?= htmlspecialchars($survey['titel']) ?></title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<header>
    <h1>Quick<span class="brand-accent">Poll</span></h1>
    <nav>
        <a href="dashboard.php">&larr; Zurück zum Dashboard</a>
    </nav>
</header>
<main>
    <div class="page">
<h2><?= htmlspecialchars($survey['titel']) ?></h2>

<?php if (!empty($survey['beschreibung'])): ?>
    <p><?= htmlspecialchars($survey['beschreibung']) ?></p>
<?php endif; ?>

<?php if ($saved): ?>

    <p class="message message-success">
        Vielen Dank! Deine Antworten wurden gespeichert.
    </p>

<?php elseif (empty($questions)): ?>

    <p class="message message-warning">Diese Umfrage enthält noch keine Fragen.</p>

<?php else: ?>

    <?php foreach ($errors as $error): ?>
        <p class="message message-error"><?= htmlspecialchars($error) ?></p>
    <?php endforeach; ?>

    <form method="post" action="survey.php?id=<?= $surveyId ?>">
        <?php foreach ($questions as $i => $question): ?>
            <p>
                <label>
                    <?= $i + 1 ?>. <?= htmlspecialchars($question['text']) ?><br>
                    <input type="text" name="answers[<?= $question['id'] ?>]" size="60">
                </label>
            </p>
        <?php endforeach; ?>

        <button class="btn btn-block" type="submit" >Antworten absenden</button>
    </form>

<?php endif; ?>

</div>

</main>
<footer>
    <p>QuickPoll &ndash; Studienprojekt Internetserver-Programmierung</p>
</footer>
</body>
</html>