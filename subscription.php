<?php
// ============================================================
//  subscription.php – subscription page (Stripe Checkout)
//
//  Flow:
//   1. Customer clicks "Jetzt abonnieren"
//   2. This page creates a Stripe Checkout session
//   3. Customer is redirected to Stripe's hosted payment page
//   4. Stripe redirects back to subscription.php?success=1
//   5. We verify the session with Stripe and activate the account
//
//  Card data NEVER touches our server – that is the whole point
//  of using Stripe's hosted Checkout.
// ============================================================

// ---------- Access guard (see includes/auth.php) ----------
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/config.php';
/** @var PDO $pdo */

// Third-party code: official Stripe PHP library (see lib/README)
require_once __DIR__ . '/lib/stripe-php/init.php';

$userId = $_SESSION['user_id'];

// Load current user
$stmt = $pdo->prepare('SELECT * FROM nutzer WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();

// Tell the Stripe library which account to use
\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

$errors  = [];
$message = '';

// ---------- Step 5: back from Stripe ----------
// Stripe appends the session id to the success URL.
// We ask Stripe whether that session was really paid – we never
// trust the URL alone, because anyone could type ?success=1.
if (isset($_GET['session_id'])) {

    try {
        $checkoutSession = \Stripe\Checkout\Session::retrieve($_GET['session_id']);

        // "paid" is Stripe's confirmation that money was collected
        // (in the sandbox: simulated money).
        if ($checkoutSession->payment_status === 'paid') {

            $stmt = $pdo->prepare(
                'UPDATE nutzer
                 SET abo_status = ?, stripe_customer_id = ?
                 WHERE id = ?'
            );
            $stmt->execute(['aktiv', $checkoutSession->customer, $userId]);

            // Keep the display current without reloading from DB
            $user['abo_status'] = 'aktiv';
            $message = 'Abo erfolgreich abgeschlossen! Du kannst jetzt Fragebögen erstellen.';

        } else {
            $errors[] = 'Die Zahlung wurde nicht abgeschlossen.';
        }

    } catch (\Exception $e) {
        $errors[] = 'Fehler bei der Prüfung der Zahlung: ' . $e->getMessage();
    }
}

// ---------- Steps 2 + 3: start checkout ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'subscribe') {

    try {
        $checkoutSession = \Stripe\Checkout\Session::create([
            'mode'        => 'subscription',          // recurring, not one-off
            'line_items'  => [[
                'price'    => STRIPE_PRICE_ID,        // the 2 EUR/month price
                'quantity' => 1,
            ]],
            'customer_email' => $user['email'],
            // {CHECKOUT_SESSION_ID} is replaced by Stripe automatically
            'success_url' => BASE_URL . '/subscription.php?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'  => BASE_URL . '/subscription.php?cancelled=1',
        ]);

        // Off to Stripe's payment page
        header('Location: ' . $checkoutSession->url);
        exit;

    } catch (\Exception $e) {
        $errors[] = 'Fehler beim Start der Zahlung: ' . $e->getMessage();
    }
}

if (isset($_GET['cancelled'])) {
    $errors[] = 'Die Zahlung wurde abgebrochen.';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>QuickPoll – Mein Abo</title>
</head>
<body>
<h1>QuickPoll</h1>
<p><a href="dashboard.php">&larr; Zurück zum Dashboard</a></p>

<h2>Mein Abo</h2>

<?php if ($message): ?>
    <p style="color: green;"><?= htmlspecialchars($message) ?></p>
<?php endif; ?>

<?php foreach ($errors as $error): ?>
    <p style="color: red;"><?= htmlspecialchars($error) ?></p>
<?php endforeach; ?>

<?php if ($user['abo_status'] === 'aktiv'): ?>

    <p>Status: <b style="color: green;">Aktiv</b></p>
    <p>Du kannst unbegrenzt Fragebögen erstellen.</p>
    <p><a href="survey_create.php">+ Neuen Fragebogen anlegen</a></p>

<?php else: ?>

    <p>Status: <b>Kein Abo</b></p>
    <p>
        Um Fragebögen zu erstellen, brauchst du ein Abo:<br>
        <b>2 € pro Monat</b> – unbegrenzt viele Fragebögen.
    </p>

    <form method="post" action="subscription.php">
        <input type="hidden" name="action" value="subscribe">
        <button type="submit">Jetzt abonnieren (2 €/Monat)</button>
    </form>

    <p style="color: gray; font-size: small;">
        Testmodus (Stripe Sandbox): Es wird kein echtes Geld abgebucht.<br>
        Testkarte: 4242 4242 4242 4242 · beliebiges Datum in der Zukunft · beliebige CVC.
    </p>

<?php endif; ?>
</body>
</html>