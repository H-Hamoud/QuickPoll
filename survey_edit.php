<?php
// ============================================================
//  survey_edit.php – questionnaire editor
//  Edit title, manage questions, publish, delete.
//  Biggest page of the project: ONE editor, SEVERAL small forms,
//  distinguished by a hidden "action" field.
// ============================================================

// ---------- Access guard (see includes/auth.php) ----------
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
/** @var PDO $pdo */

$userId = $_SESSION['user_id'];

// ---------- Load questionnaire + OWNERSHIP CHECK ----------
// (int) casts the URL parameter to an integer – ids are numbers,
// everything else becomes 0.
$surveyId = (int)($_GET['id'] ?? 0);

// "AND nutzer_id = ?" is the ownership check: if the questionnaire
// exists but belongs to SOMEBODY ELSE, fetch() returns false and we
// redirect. Manipulating the id in the URL therefore leads nowhere.
$stmt = $pdo->prepare(
    'SELECT * FROM fragebogen WHERE id = ? AND nutzer_id = ?'
);
$stmt->execute([$surveyId, $userId]);
$survey = $stmt->fetch();

if (!$survey) {
    header('Location: dashboard.php');
    exit;
}


// Which question is currently in edit mode? 0 = none.
$editId = (int)($_GET['edit'] ?? 0);

$errors = [];

// ---------- Process actions ----------
// Every form on this page sends a hidden field named "action".
// This if/elseif chain decides what to do – like a switch statement.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    if ($action === 'save_title') {

        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($title === '') {
            $errors[] = 'Der Titel darf nicht leer sein.';
        } else {
            $stmt = $pdo->prepare(
                'UPDATE fragebogen SET titel = ?, beschreibung = ?
                 WHERE id = ?'
            );
            $stmt->execute([$title, $description, $surveyId]);
        }

    } elseif ($action === 'add_question') {

        $questionText = trim($_POST['question_text'] ?? '');

        if ($questionText === '') {
            $errors[] = 'Die Frage darf nicht leer sein.';
        } else {
            // Next position = highest existing position + 1.
            // COALESCE turns NULL (no questions yet) into 0.
            $stmt = $pdo->prepare(
                'SELECT COALESCE(MAX(reihenfolge), 0) + 1
                 FROM frage WHERE fragebogen_id = ?'
            );
            $stmt->execute([$surveyId]);
            $position = $stmt->fetchColumn();

            $stmt = $pdo->prepare(
                'INSERT INTO frage (fragebogen_id, text, reihenfolge)
                 VALUES (?, ?, ?)'
            );
            $stmt->execute([$surveyId, $questionText, $position]);
        }

    } elseif ($action === 'delete_question') {

        $questionId = (int)($_POST['question_id'] ?? 0);

        // Same ownership idea one level down: the question must belong
        // to THIS (already verified) questionnaire.
        $stmt = $pdo->prepare(
            'DELETE FROM frage WHERE id = ? AND fragebogen_id = ?'
        );
        $stmt->execute([$questionId, $surveyId]);

    }elseif ($action === 'edit_question') {
        $questionId = (int)($_POST['question_id'] ?? 0);
        $questionText = trim($_POST['question_text'] ?? '');

        if ($questionText === '') {
            $errors[] = 'Der Frage darf nicht leer sein.';
        }else{
            $stmt = $pdo->prepare(
                    'UPDATE frage 
                            SET text = ? 
                            where id = ? and 
                            fragebogen_id = ?'
            );
            $stmt->execute([ $questionText, $questionId, $surveyId]);
        }

    }elseif ($action === 'publish') {

        $stmt = $pdo->prepare(
            'UPDATE fragebogen SET veroeffentlicht = 1 WHERE id = ?'
        );
        $stmt->execute([$surveyId]);

    } elseif ($action === 'unpublish') {

        $stmt = $pdo->prepare(
            'UPDATE fragebogen SET veroeffentlicht = 0 WHERE id = ?'
        );
        $stmt->execute([$surveyId]);

    } elseif ($action === 'delete_survey') {

        // ON DELETE CASCADE in the schema automatically removes all
        // questions and answers of this questionnaire – no extra code.
        $stmt = $pdo->prepare('DELETE FROM fragebogen WHERE id = ?');
        $stmt->execute([$surveyId]);

        header('Location: dashboard.php');
        exit;
    }

    // Success (no errors): reload this page via redirect (PRG pattern).
    // On validation errors we fall through and render the messages.
    if (empty($errors)) {
        header('Location: survey_edit.php?id=' . $surveyId);
        exit;
    }
}

// ---------- Load current data for display ----------
$stmt = $pdo->prepare(
    'SELECT * FROM frage WHERE fragebogen_id = ? ORDER BY reihenfolge'
);
$stmt->execute([$surveyId]);
$questions = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>QuickPoll – Fragebogen bearbeiten</title>
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

<h2>Fragebogen bearbeiten</h2>

<?php foreach ($errors as $error): ?>
    <p class="message message-error"><?= htmlspecialchars($error) ?></p>
<?php endforeach; ?>

<!-- ---------- Status + publish / hide ---------- -->
<?php if ($survey['veroeffentlicht']): ?>

    <p>Status: <b>Veröffentlicht</b></p>
    <div class="row-right">
        Fragebogen teilen:
        <a href="survey.php?id=<?= $surveyId ?>">survey.php?id=<?= $surveyId ?></a>
    </div>
    <form method="post" action="survey_edit.php?id=<?= $surveyId ?>">
        <input type="hidden" name="action" value="unpublish">
        <button type="submit" class="btn btn-secondary">Auf Entwurf zurücksetzen</button>
    </form>

<?php else: ?>

    <p>Status: <b>Entwurf</b> (für Endnutzer noch nicht sichtbar)</p>
    <form method="post" action="survey_edit.php?id=<?= $surveyId ?>">
        <input type="hidden" name="action" value="publish">
        <button type="submit" class="btn">Veröffentlichen</button>
    </form>

<?php endif; ?>

<hr>

<!-- ---------- Title & description ---------- -->
<h3>Titel &amp; Beschreibung</h3>
<form method="post" action="survey_edit.php?id=<?= $surveyId ?>">
    <input type="hidden" name="action" value="save_title">
    <label>Titel:<br>
        <input type="text" name="title" size="50"
               value="<?= htmlspecialchars($survey['titel']) ?>">
    </label><br><br>
    <label>Beschreibung:<br>
        <textarea name="description" rows="3"><?= htmlspecialchars($survey['beschreibung'] ?? '') ?></textarea>
    </label><br><br>
    <button type="submit" class="btn btn-success">Speichern</button>
</form>

<hr>

<!-- ---------- Questions ---------- -->
<h3>Fragen</h3>

<?php if (empty($questions)): ?>
    <p>Noch keine Fragen vorhanden.</p>
<?php else: ?>
    <ol>
        <?php foreach ($questions as $question): ?>
            <li>
                <?php if ((int)$question['id'] === $editId): ?>

                    <form method="post"
                          action="survey_edit.php?id=<?= $surveyId ?>&amp;edit=<?= $question['id'] ?>"
                          class="question-row">
                        <input type="hidden" name="action" value="edit_question">
                        <input type="hidden" name="question_id" value="<?= $question['id'] ?>">
                        <input type="text" name="question_text" class="question-text"
                               value="<?= htmlspecialchars($question['text']) ?>">
                        <button type="submit" class="btn btn-sm btn-success">Speichern</button>
                        <a href="survey_edit.php?id=<?= $surveyId ?>"
                           class="btn btn-sm btn-secondary">Abbrechen</a>
                    </form>

                <?php else: ?>

                    <div class="question-row">
                        <span class="question-text"><?= htmlspecialchars($question['text']) ?></span>

                        <a href="survey_edit.php?id=<?= $surveyId ?>&amp;edit=<?= $question['id'] ?>"
                           class="btn btn-sm">Ändern</a>

                        <form method="post" action="survey_edit.php?id=<?= $surveyId ?>">
                            <input type="hidden" name="action" value="delete_question">
                            <input type="hidden" name="question_id" value="<?= $question['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Löschen</button>
                        </form>
                    </div>

                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>

<form method="post" action="survey_edit.php?id=<?= $surveyId ?>">
    <input type="hidden" name="action" value="add_question">
    <label>Neue Frage:<br>
        <input type="text" name="question_text" size="50">
    </label>
    <button type="submit" class="btn">+ Hinzufügen</button>
</form>

<hr>

<!-- ---------- Delete questionnaire ---------- -->
<!-- confirm() is one line of JavaScript that asks before submitting -->
<form method="post" action="survey_edit.php?id=<?= $surveyId ?>"
      onsubmit="return confirm('Diesen Fragebogen wirklich löschen? Alle Fragen und Antworten gehen verloren.');">
    <input type="hidden" name="action" value="delete_survey">
    <button type="submit" class="btn btn-danger">Fragebogen löschen</button>
</form>
</div>
</main>
<footer>
    <p>QuickPoll &ndash; Studienprojekt Internetserver-Programmierung</p>
</footer>

</body>
</html>