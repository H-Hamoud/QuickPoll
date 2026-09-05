<?php
// ============================================================
//  dashboard.php – "Meine Fragebögen" overview (customers only)
//  First PROTECTED page: only accessible when logged in.
// ============================================================



// ---------- Access guard (see includes/auth.php) ----------
// No user_id in the session means: not logged in.
// Redirect to the login page and stop the script.
// Every protected page starts with this block.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
/** @var PDO $pdo */

$userId = $_SESSION['user_id'];

// Load the logged-in user (needed for email + subscription status)
$stmt = $pdo->prepare('SELECT * FROM nutzer WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();

// Load ONLY this user's questionnaires – this WHERE clause is the
// access control: nobody ever sees another user's data.
$stmt = $pdo->prepare(
        'SELECT * FROM fragebogen WHERE nutzer_id = ? ORDER BY erstellt_am DESC'
);
$stmt->execute([$userId]);
$surveys = $stmt->fetchAll();   // array of rows (may be empty)
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>QuickPoll – Dashboard</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<header>
    <h1>Quick<span class="brand-accent">Poll</span></h1>
    <nav>
        <span><?= htmlspecialchars($user['email']) ?></span>
        <a href="subscription.php">Mein Abo</a>
        <a href="logout.php">Logout</a>
    </nav>
</header>
<main>
    <div class="page">

<h2>Meine Fragebögen</h2>

<?php if ($user['abo_status'] === 'aktiv'): ?>
    <p class="row-right"><a href="survey_create.php" class="btn">+ Neuen Fragebogen anlegen</a></p>
<?php else: ?>
    <p class="message message-warning">
        Um Fragebögen zu erstellen, brauchst du ein aktives Abo.
        <a href="subscription.php">Jetzt abschließen (2 €/Monat)</a>
    </p>
<?php endif; ?>
        <section class="card">

<?php if (empty($surveys)): ?>

    <p>Du hast noch keine Fragebögen.</p>

<?php else: ?>

    <table>
        <thead>
        <tr>
            <th>Titel</th>
            <th>Status</th>
            <th>Erstellt am</th>
            <th>Aktionen</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($surveys as $survey): ?>
            <tr>
                <td><?= htmlspecialchars($survey['titel']) ?></td>
                <td><?= $survey['veroeffentlicht'] ? 'Veröffentlicht' : 'Entwurf' ?></td>
                <td><?= $survey['erstellt_am'] ?></td>
                <td>
                    <a href="survey_edit.php?id=<?= $survey['id'] ?>">Bearbeiten</a> ·
                    <a href="results.php?id=<?= $survey['id'] ?>">Ergebnisse</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

<?php endif; ?>
        </section>
    </div>
</main>
<footer>
    <p>QuickPoll &ndash; Studienprojekt Internetserver-Programmierung</p>
</footer>
</body>
</html>