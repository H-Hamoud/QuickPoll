<?php
// ============================================================
//  register.php – customer registration
//  This single file does BOTH:
//  - shows the form (normal GET request)
//  - processes the input (form submitted via POST)
// ============================================================

require_once __DIR__ . '/includes/db.php';   // provides the $pdo connection
/** @var PDO $pdo */

$errors  = [];      // collects error messages for display
$success = false;   // becomes true once registration succeeded

// Only process input when the form was submitted (POST).
// On the first visit (GET) we only show the form below.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Read the form input.
    // trim() removes whitespace at the start/end.
    // ?? '' means: if the field is missing, use an empty string.
    $email     = trim($_POST['email'] ?? '');
    $password  = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';

    // ---------- Validation ----------
    if ($email === '') {
        $errors[] = 'Bitte eine E-Mail-Adresse eingeben.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Die E-Mail-Adresse ist ungültig.';
    }

    if (strlen($password) < 8) {
        $errors[] = 'Das Passwort muss mindestens 8 Zeichen lang sein.';
    } elseif ($password !== $password2) {
        $errors[] = 'Die Passwörter stimmen nicht überein.';
    }

    // Check whether the email is already registered
    if (empty($errors)) {
        $stmt = $pdo->prepare('SELECT id FROM nutzer WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors[] = 'Diese E-Mail-Adresse ist bereits registriert.';
        }
    }

    // ---------- Save ----------
    if (empty($errors)) {
        // password_hash() creates a secure hash (bcrypt).
        // The plain-text password is NEVER stored.
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare(
                'INSERT INTO nutzer (email, passwort_hash) VALUES (?, ?)'
        );
        $stmt->execute([$email, $hash]);

        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>QuickPoll – Registrierung</title>
</head>
<body>
<h1>QuickPoll</h1>
<h2>Registrieren</h2>

<?php if ($success): ?>

    <p style="color: green;">
        Registrierung erfolgreich! Du kannst dich jetzt
        <a href="login.php">einloggen</a>.
    </p>

<?php else: ?>

    <?php foreach ($errors as $error): ?>
        <p style="color: red;"><?= htmlspecialchars($error) ?></p>
    <?php endforeach; ?>

    <form method="post" action="register.php">
        <label>E-Mail:<br>
            <input type="email" name="email"
                   value="<?= htmlspecialchars($email ?? '') ?>">
        </label><br><br>

        <label>Passwort (mind. 8 Zeichen):<br>
            <input type="password" name="password">
        </label><br><br>

        <label>Passwort wiederholen:<br>
            <input type="password" name="password2">
        </label><br><br>

        <button type="submit">Registrieren</button>
    </form>

    <p>Schon ein Konto? <a href="login.php">Zum Login</a></p>

<?php endif; ?>
</body>
</html>