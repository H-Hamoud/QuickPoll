<?php
declare(strict_types=1);
// ============================================================
//  stripe_webhook.php – Stripe tells US what happened
//
//  Renewals, failed payments and cancellations happen at Stripe
//  while nobody is looking at QuickPoll. Stripe then calls this
//  address (server to server, no browser, no session, no cookie).
//
//  What makes this endpoint safe and repeatable:
//
//   1. Signature   Anybody can POST to a public address. The header
//                  "Stripe-Signature" proves the request comes from
//                  Stripe and is at most 5 minutes old.
//   2. Once only   Stripe delivers every event AT LEAST once. The
//                  event id is stored in `stripe_ereignis`; a second
//                  delivery is answered with 200 and changes nothing.
//   3. Any order   Events can arrive late or shuffled. The handler
//                  does not copy the event's content – it asks Stripe
//                  for the CURRENT subscriptions of the customer.
//   4. Transaction Event id and new state are written together or
//                  not at all. On an error Stripe gets a 500 and
//                  simply tries again later.
//
//  Setup: docs/PAYMENTS.md
//  Events: checkout.session.completed, customer.subscription.created,
//          customer.subscription.updated, customer.subscription.deleted,
//          invoice.paid, invoice.payment_failed, invoice.upcoming,
//          customer.deleted
// ============================================================

require_once __DIR__ . '/includes/bootstrap.php';

/** Short plain-text answer; Stripe only looks at the status code. */
$answer = static function (int $status, string $text): never {
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $text;
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $answer(405, 'POST only');
}
if (!stripe_webhook_is_configured()) {
    $answer(503, 'Webhook not configured');
}
stripe_boot();

// ---------- 1. Signature ----------
$payload   = (string) file_get_contents('php://input');   // raw body, exactly as signed
$signature = (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');

try {
    $event = \Stripe\Webhook::constructEvent($payload, $signature, STRIPE_WEBHOOK_SECRET);
} catch (Throwable $e) {
    // Wrong or missing signature, too old, or not JSON: not from Stripe.
    error_log('Stripe webhook rejected: ' . $e->getMessage());
    security_log('webhook_rejected', ['reason' => substr($e->getMessage(), 0, 80)]);
    $answer(400, 'Invalid signature');
}

$eventId = (string) $event->id;
$type    = (string) $event->type;
$object  = $event->data->object->toArray();
$pdo     = db();

/** Remember the event (inside the caller's transaction or on its own). */
$remember = static function (string $result) use ($pdo, $eventId, $type, $event): void {
    $pdo->prepare('INSERT INTO stripe_ereignis (id, typ, erstellt_am, ergebnis) VALUES (?, ?, ?, ?)')
        ->execute([$eventId, mb_substr($type, 0, 80), date('Y-m-d H:i:s', (int) $event->created), $result]);
    if (random_int(1, 100) === 1) {
        // Stripe retries for at most three days; 90 days is plenty of history.
        $pdo->exec('DELETE FROM stripe_ereignis WHERE empfangen_am < NOW() - INTERVAL 90 DAY');
    }
};
/** Record an event that needs no work. A parallel duplicate is fine. */
$ignore = static function (string $why) use ($remember, $answer): never {
    try {
        $remember('ignoriert');
    } catch (PDOException $e) {
        if ($e->getCode() !== '23000') {
            throw $e;
        }
    }
    $answer(200, 'ignored: ' . $why);
};

// ---------- 2. Seen before? ----------
$stmt = $pdo->prepare('SELECT 1 FROM stripe_ereignis WHERE id = ?');
$stmt->execute([$eventId]);
if ($stmt->fetchColumn()) {
    $answer(200, 'duplicate');
}

$handled = [
    'checkout.session.completed', 'customer.subscription.created', 'customer.subscription.updated',
    'customer.subscription.deleted', 'customer.subscription.paused', 'customer.subscription.resumed',
    'invoice.paid', 'invoice.payment_failed', 'invoice.upcoming', 'customer.deleted',
];
if (!in_array($type, $handled, true)) {
    $ignore('event type not used');
}

// ---------- Which account? ----------
// Every handled object names its customer (the customer object itself for customer.deleted).
$customer   = $type === 'customer.deleted' ? ($object['id'] ?? null) : ($object['customer'] ?? null);
$customerId = is_array($customer) ? ($customer['id'] ?? null) : $customer;
if (!is_string($customerId) || $customerId === '') {
    $ignore('no customer');
}

$stmt = $pdo->prepare('SELECT * FROM nutzer WHERE stripe_customer_id = ?');
$stmt->execute([$customerId]);
$user = $stmt->fetch() ?: null;

// Accounts from before the customer was created up front: the checkout
// carries the account id we set ourselves (client_reference_id).
if ($user === null && $type === 'checkout.session.completed' && ctype_digit((string) ($object['client_reference_id'] ?? ''))) {
    $pdo->prepare('UPDATE nutzer SET stripe_customer_id = ? WHERE id = ? AND stripe_customer_id IS NULL')
        ->execute([$customerId, (int) $object['client_reference_id']]);
    $stmt->execute([$customerId]);
    $user = $stmt->fetch() ?: null;
}
if ($user === null) {
    // A customer of this Stripe account that is not ours (or a deleted account).
    $ignore('unknown customer');
}
$userId = (int) $user['id'];

// ---------- 3. Current state from Stripe (not from the event) ----------
// Network before the transaction: no database lock is held while waiting.
try {
    $subscriptions = $type === 'customer.deleted' ? [] : billing_fetch_subscriptions($customerId);
} catch (Throwable $e) {
    // Stripe not reachable: nothing was stored, so the event is not
    // "done". The 500 makes Stripe deliver it again later.
    error_log('Stripe webhook: state could not be fetched for event ' . $eventId . ': ' . $e->getMessage());
    $answer(500, 'Could not fetch the current state, please retry');
}

// ---------- 4. Event id + new state in one transaction ----------
$pdo->beginTransaction();
try {
    $remember('angewendet');                       // PRIMARY KEY: a parallel duplicate fails here
    $result = billing_apply($pdo, $userId, $subscriptions);
    if ($type === 'customer.deleted') {
        $pdo->prepare('UPDATE nutzer SET stripe_customer_id = NULL WHERE id = ?')->execute([$userId]);
    }
    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    if ($e->getCode() === '23000') {
        $answer(200, 'duplicate');
    }
    throw $e;
}
if ($result === null) {
    $answer(200, 'account gone');
}

// ---------- Mails (after the commit; a failing mail changes nothing) ----------
$after = $result['after'];
try {
    billing_notify_changes($user, $result['changes'], $after);

    $page       = base_url() . '/subscription.php';
    $invoiceUrl = (string) ($object['hosted_invoice_url'] ?? '');
    $invoiceUrl = preg_match('#^https://#', $invoiceUrl) ? $invoiceUrl : $page;
    $currency   = (string) ($object['currency'] ?? 'eur');
    $reason     = (string) ($object['billing_reason'] ?? '');
    $nextDate   = $after['abo_periode_ende'] !== null ? mail_date($user, $after['abo_periode_ende']) : '';

    if ($type === 'invoice.paid' && (int) ($object['amount_paid'] ?? 0) > 0 && $nextDate !== '') {
        $kind = $reason === 'subscription_create' ? 'payment_received' : ($reason === 'subscription_cycle' ? 'renewed' : null);
        if ($kind !== null) {
            send_account_mail($user, $kind, ['betrag' => mail_amount($user, (int) $object['amount_paid'], $currency), 'datum' => $nextDate], $invoiceUrl);
        }
    }
    // A failed FIRST payment is shown by Stripe's checkout page itself;
    // the mail is for renewals, when the customer is not watching.
    if ($type === 'invoice.payment_failed' && $reason !== 'subscription_create' && $after['abo_status'] === 'aktiv') {
        send_account_mail($user, 'payment_failed', ['betrag' => mail_amount($user, (int) ($object['amount_due'] ?? 0), $currency)], $page);
    }
    if ($type === 'invoice.upcoming' && $after['abo_status'] === 'aktiv' && $after['abo_endet_am'] === null && $nextDate !== '') {
        send_account_mail($user, 'renewal_upcoming', ['betrag' => mail_amount($user, (int) ($object['amount_due'] ?? 0), $currency), 'datum' => $nextDate], $page);
    }
} catch (Throwable $e) {
    error_log('Stripe webhook: mail failed for event ' . $eventId . ': ' . $e->getMessage());
}

// Stripe only needs a 2xx status; anything else makes it retry the event.
$answer(200, 'ok');
