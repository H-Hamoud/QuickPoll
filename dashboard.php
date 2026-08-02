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
    <title>QuickPoll – Dashboard</title>
</head>
<body>
<h1>QuickPoll</h1>

<p>
    Eingeloggt als <?= htmlspecialchars($user['email']) ?> ·
    <a href="subscription.php">Mein Abo</a> ·
    <a href="logout.php">Logout</a>
</p>

<h2>Meine Fragebögen</h2>

<?php if ($user['abo_status'] === 'aktiv'): ?>
    <p><a href="survey_create.php">+ Neuen Fragebogen anlegen</a></p>
<?php else: ?>
    <p style="color: darkorange;">
        Um Fragebögen zu erstellen, brauchst du ein aktives Abo.
        <a href="subscription.php">Jetzt abschließen (2 €/Monat)</a>
    </p>
<?php endif; ?>

<?php if (empty($surveys)): ?>

    <p>Du hast noch keine Fragebögen.</p>

<?php else: ?>

    <table border="1" cellpadding="8">
        <tr>
            <th>Titel</th>
            <th>Status</th>
            <th>Erstellt am</th>
            <th>Aktionen</th>
        </tr>
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
    </table>

<?php endif; ?>
</body>
</html>