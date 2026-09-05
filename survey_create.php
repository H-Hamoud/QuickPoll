<?php
// ============================================================
//  survey_create.php – create a new questionnaire
//  Only for logged-in customers WITH an active subscription.
// ============================================================

// ---------- Access guard (see includes/auth.php) ----------
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
/** @var PDO $pdo */

$userId = $_SESSION['user_id'];

// ---------- Subscription guard ----------
// The dashboard hides the link for non-subscribers, but anyone
// could still type this URL directly into the browser.
// Real security checks always happen here on the server.
$stmt = $pdo->prepare('SELECT abo_status FROM nutzer WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();

if ($user['abo_status'] !== 'aktiv') {
    header('Location: dashboard.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($title === '') {
        $errors[] = 'Bitte einen Titel eingeben.';
    }

    if (empty($errors)) {
        // nutzer_id comes from the SESSION, never from the form –
        // so nobody can create questionnaires for somebody else.
        $stmt = $pdo->prepare(
                'INSERT INTO fragebogen (nutzer_id, titel, beschreibung)
             VALUES (?, ?, ?)'
        );
        $stmt->execute([$userId, $title, $description]);

        // lastInsertId() returns the id that MySQL just generated
        // (AUTO_INCREMENT) for the new row.
        $newId = $pdo->lastInsertId();

        // Straight to the editor, where questions are added.
        // Redirect after successful POST = "Post/Redirect/Get" pattern,
        // prevents double submission when the user presses F5.
        header('Location: survey_edit.php?id=' . $newId);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>QuickPoll – Neuer Fragebogen</title>
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
        <section class="card">
            <h2>Neuen Fragebogen anlegen</h2>

            <?php foreach ($errors as $error): ?>
                <p class="message message-error"><?= htmlspecialchars($error) ?></p>
            <?php endforeach; ?>

            <form method="post" action="survey_create.php">
                <label>Titel:<br>
                    <input type="text" name="title" size="50"
                           value="<?= htmlspecialchars($title ?? '') ?>">
                </label><br><br>

                <label>Beschreibung (optional):<br>
                    <textarea name="description" rows="4"
                              cols="50"><?= htmlspecialchars($description ?? '') ?></textarea>
                </label><br><br>

                <button type="submit" class="btn">Anlegen</button>

            </form>
        </section>
    </div>
</main>
<footer>
    <p>QuickPoll &ndash; Studienprojekt Internetserver-Programmierung</p>
</footer>
</body>
</html>