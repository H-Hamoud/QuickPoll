<?php
// ============================================================
//  login.php – customer sign-in
//  Checks email + password and starts a session on success.
// ============================================================

// Start the session – MUST be at the very top,
// before any HTML output is sent.
// The session remembers across page loads WHO is logged in.
session_start();

require_once __DIR__ . '/includes/db.php';
/** @var PDO $pdo */


$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $errors[] = 'Bitte E-Mail und Passwort eingeben.';
    } else {
        // Look up the user by email
        $stmt = $pdo->prepare('SELECT * FROM nutzer WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();   // returns the row or false

        // password_verify() compares the entered password
        // with the stored hash from the database.
        if ($user && password_verify($password, $user['passwort_hash'])) {

            // Login successful: store the user ID in the session.
            // From now on, every page knows who is logged in.
            $_SESSION['user_id'] = $user['id'];

            // Continue to the dashboard
            header('Location: dashboard.php');
            exit;   // important: always stop after a redirect

        } else {
            // Deliberately ONE shared message for both cases
            // (unknown email OR wrong password) – this way the page
            // does not reveal to attackers which emails exist.
            $errors[] = 'E-Mail oder Passwort ist falsch.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>QuickPoll – Login</title>
</head>
<body>
<h1>QuickPoll</h1>
<h2>Anmelden</h2>

<?php foreach ($errors as $error): ?>
    <p style="color: red;"><?= htmlspecialchars($error) ?></p>
<?php endforeach; ?>

<form method="post" action="login.php">
    <label>E-Mail:<br>
        <input type="email" name="email"
               value="<?= htmlspecialchars($email ?? '') ?>">
    </label><br><br>

    <label>Passwort:<br>
        <input type="password" name="password">
    </label><br><br>

    <button type="submit">Einloggen</button>
</form>

<p>Noch kein Konto? <a href="register.php">Registrieren</a></p>
</body>
</html>