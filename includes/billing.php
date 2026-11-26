<?php
declare(strict_types=1);
// ============================================================
//  billing.php – the Pro subscription (Stripe)
//
//  One rule runs through this file: the DATABASE is a copy, STRIPE
//  is the original. Whenever something may have changed – the
//  customer returns from Checkout or the portal, a webhook arrives,
//  the nightly check runs – QuickPoll asks Stripe for the current
//  subscriptions of the customer and copies the result:
//
//      billing_sync_user()  →  Stripe API  →  billing_apply()
//
//  That makes the order of webhooks irrelevant (they may arrive
//  late, twice or shuffled) and nothing the browser says is ever
//  believed.
//
//  Card data never touches this server: payment happens on
//  Stripe's hosted pages (Checkout and the customer portal).
// ============================================================

/**
 * Stripe states that include Pro access.
 *  trialing  trial period
 *  active    paid
 *  past_due  a renewal failed, Stripe is still retrying – access stays
 *            during this grace period; when Stripe gives up the state
 *            becomes "unpaid" or "canceled" and access ends
 * Without access: incomplete (first payment not finished),
 * incomplete_expired, unpaid, canceled, paused.
 */
const BILLING_ACCESS_STATES = ['trialing', 'active', 'past_due'];

// ---------- Pure functions (no Stripe, no database – see tests/billing_test.php) ----------

function billing_grants_access(?string $stripeStatus): bool
{
    return in_array($stripeStatus, BILLING_ACCESS_STATES, true);
}

/**
 * End of the paid period. Older Stripe API versions have it on the
 * subscription, newer ones (2025-03-31 and later) on its items.
 *
 * @param array<string, mixed> $subscription
 */
function billing_period_end(array $subscription): ?int
{
    if (isset($subscription['current_period_end']) && is_numeric($subscription['current_period_end'])) {
        return (int) $subscription['current_period_end'];
    }
    $ends = [];
    foreach ($subscription['items']['data'] ?? [] as $item) {
        if (isset($item['current_period_end']) && is_numeric($item['current_period_end'])) {
            $ends[] = (int) $item['current_period_end'];
        }
    }
    return $ends ? min($ends) : null;
}

/**
 * Translate a Stripe subscription into the columns of `nutzer`.
 * null = the customer has no subscription at all.
 *
 * @param array<string, mixed>|null $subscription
 * @return array{stripe_subscription_id: ?string, stripe_status: ?string, abo_status: string, abo_periode_ende: ?string, abo_endet_am: ?string, abo_preis_cent: ?int, abo_waehrung: ?string, abo_intervall: ?string}
 */
function billing_state(?array $subscription): array
{
    if ($subscription === null || !is_string($subscription['id'] ?? null)) {
        return ['stripe_subscription_id' => null, 'stripe_status' => null, 'abo_status' => 'inaktiv',
                'abo_periode_ende' => null, 'abo_endet_am' => null,
                'abo_preis_cent' => null, 'abo_waehrung' => null, 'abo_intervall' => null];
    }

    // What does it cost? Taken from the subscription itself, so the page
    // shows what Stripe really charges – not what a config file claims.
    $price    = $subscription['items']['data'][0]['price'] ?? $subscription['items']['data'][0]['plan'] ?? $subscription['plan'] ?? [];
    $amount   = $price['unit_amount'] ?? $price['amount'] ?? null;
    $interval = $price['recurring']['interval'] ?? $price['interval'] ?? null;

    $status    = is_string($subscription['status'] ?? null) ? $subscription['status'] : 'incomplete';
    $periodEnd = billing_period_end($subscription);
    $toDate    = static fn(?int $timestamp): ?string => $timestamp ? date('Y-m-d H:i:s', $timestamp) : null;

    // When does it stop? "canceled" has an end in the past (ended_at); a
    // running subscription may have a cancellation scheduled – either as
    // a date (cancel_at) or as "at the end of the period".
    $endsAt = null;
    if (in_array($status, ['canceled', 'incomplete_expired'], true)) {
        $endsAt = (int) ($subscription['ended_at'] ?? $subscription['canceled_at'] ?? 0) ?: null;
    } elseif (!empty($subscription['cancel_at']) && is_numeric($subscription['cancel_at'])) {
        $endsAt = (int) $subscription['cancel_at'];
    } elseif (!empty($subscription['cancel_at_period_end'])) {
        $endsAt = $periodEnd;
    }

    return [
        'stripe_subscription_id' => $subscription['id'],
        'stripe_status'          => $status,
        // A scheduled cancellation does NOT end access: "active" stays
        // "active" until Stripe itself ends the subscription.
        'abo_status'             => billing_grants_access($status) ? 'aktiv' : 'inaktiv',
        'abo_periode_ende'       => $toDate($periodEnd),
        'abo_endet_am'           => $toDate($endsAt),
        'abo_preis_cent'         => is_numeric($amount) ? (int) $amount : null,
        'abo_waehrung'           => is_string($price['currency'] ?? null) ? strtolower(substr($price['currency'], 0, 3)) : null,
        'abo_intervall'          => in_array($interval, ['day', 'week', 'month', 'year'], true) ? $interval : null,
    ];
}

/**
 * A customer can have several subscriptions at Stripe (an abandoned
 * first attempt next to the paid one). Which one describes the account?
 * The one with access wins; among equals the newest.
 *
 * @param list<array<string, mixed>> $subscriptions
 * @return array<string, mixed>|null
 */
function billing_pick_subscription(array $subscriptions): ?array
{
    $rank = ['active' => 0, 'trialing' => 0, 'past_due' => 1, 'unpaid' => 2, 'paused' => 2,
             'incomplete' => 3, 'canceled' => 4, 'incomplete_expired' => 5];
    $best = null;
    foreach ($subscriptions as $subscription) {
        if (!is_array($subscription) || !isset($subscription['id'])) {
            continue;
        }
        if ($best === null) {
            $best = $subscription;
            continue;
        }
        $a = $rank[$subscription['status'] ?? ''] ?? 9;
        $b = $rank[$best['status'] ?? ''] ?? 9;
        if ($a < $b || ($a === $b && (int) ($subscription['created'] ?? 0) > (int) ($best['created'] ?? 0))) {
            $best = $subscription;
        }
    }
    return $best;
}

/**
 * What changed between two states – decides which mail goes out.
 * Returns a list of: activated, cancel_scheduled, resumed, ended, payment_problem.
 *
 * @param array<string, mixed> $before row of `nutzer` (or billing_state())
 * @param array<string, mixed> $after  billing_state()
 * @return list<string>
 */
function billing_transitions(array $before, array $after): array
{
    $had     = ($before['abo_status'] ?? 'inaktiv') === 'aktiv';
    $has     = $after['abo_status'] === 'aktiv';
    $changes = [];

    if (!$had && $has) {
        $changes[] = 'activated';
    }
    if ($had && !$has) {
        $changes[] = 'ended';
    }
    if ($has && empty($before['abo_endet_am']) && !empty($after['abo_endet_am'])) {
        $changes[] = 'cancel_scheduled';
    }
    if ($had && $has && !empty($before['abo_endet_am']) && empty($after['abo_endet_am'])) {
        $changes[] = 'resumed';
    }
    if ($has && ($before['stripe_status'] ?? null) !== 'past_due' && $after['stripe_status'] === 'past_due') {
        $changes[] = 'payment_problem';
    }
    return $changes;
}

/**
 * How the subscription is described to the user: one key, used by
 * subscription.php for stamp, sentence and buttons.
 *   free | trial | active | cancelling | past_due | unpaid | incomplete | ended
 *
 * @param array<string, mixed> $user row of `nutzer`
 */
function billing_view(array $user): string
{
    $status = $user['stripe_status'] ?? null;
    if ($status === null) {
        return $user['abo_status'] === 'aktiv' ? 'active' : 'free';   // 'active' without Stripe = development switch
    }
    if (billing_grants_access($status) && !empty($user['abo_endet_am'])) {
        return 'cancelling';
    }
    return match ($status) {
        'trialing'                     => 'trial',
        'active'                       => 'active',
        'past_due'                     => 'past_due',
        'unpaid', 'paused'             => 'unpaid',
        'incomplete'                   => 'incomplete',
        default                        => 'ended',          // canceled, incomplete_expired
    };
}

/** Amount in the smallest currency unit (cents) as readable text. */
function billing_format_amount(int $amount, string $currency): string
{
    $currency = strtolower($currency);
    // Currencies without cents (Stripe calls them zero-decimal).
    $zeroDecimal = ['bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf'];
    $value       = in_array($currency, $zeroDecimal, true) ? $amount : $amount / 100;
    $decimals    = in_array($currency, $zeroDecimal, true) ? 0 : 2;
    if ($currency === 'eur') {
        return format_money($value, $decimals);
    }
    return format_number($value, $decimals) . ' ' . strtoupper($currency);
}

/** "2 €" instead of "2,00 €" for whole amounts – for price tags. */
function billing_format_price(int $amount, string $currency): string
{
    if (strtolower($currency) === 'eur' && $amount % 100 === 0) {
        return format_money(intdiv($amount, 100));
    }
    return billing_format_amount($amount, $currency);
}

/** "2 € pro Monat" / "20 € pro Jahr". */
function billing_price_text(int $amount, string $currency, ?string $interval): string
{
    $price = billing_format_price($amount, $currency);
    return $interval === 'year' ? t('{preis} pro Jahr', ['preis' => $price]) : t('{preis} pro Monat', ['preis' => $price]);
}

// What is shown while Stripe cannot be asked (not configured, no network).
const BILLING_FALLBACK_PLAN = ['id' => '', 'amount' => 200, 'currency' => 'eur', 'interval' => 'month', 'assumed' => true];

// ---------- Stripe access ----------

/** A local stand-in for Stripe's API (tests/mock_stripe.php) – never in production. */
function stripe_test_base(): ?string
{
    return APP_ENV !== 'production' && defined('STRIPE_TEST_BASE') ? rtrim((string) STRIPE_TEST_BASE, '/') : null;
}

/** Load the official Stripe library and hand it the secret key. */
function stripe_boot(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }
    // Third-party code: official Stripe PHP library (see lib/README.md)
    require_once QP_ROOT . '/lib/stripe-php/init.php';
    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);          // server side only – never printed, never sent to the browser
    \Stripe\Stripe::setAppInfo('QuickPoll', '3.3');
    \Stripe\Stripe::setMaxNetworkRetries(2);               // the library repeats safely (idempotency keys)
    if (($base = stripe_test_base()) !== null) {
        \Stripe\Stripe::$apiBase = $base;
    }
    $booted = true;
}

/** Is the webhook endpoint usable (keys + signing secret)? */
function stripe_webhook_is_configured(): bool
{
    return stripe_is_configured() && STRIPE_WEBHOOK_SECRET !== '' && !str_contains(STRIPE_WEBHOOK_SECRET, 'XXXX');
}

/** Language of Stripe's hosted pages; Stripe has no Arabic, so it decides itself there. */
function stripe_locale(): string
{
    return in_array(locale(), ['de', 'en'], true) ? locale() : 'auto';
}

/**
 * The plans on offer, with the prices as they are stored AT STRIPE:
 * STRIPE_PRICE_ID (monthly) and, optionally, STRIPE_PRICE_ID_YEARLY.
 * Asked once an hour and kept in storage/cache/. If Stripe cannot be
 * asked, the page shows the fallback and says nothing it cannot know.
 *
 * @return list<array{id: string, amount: int, currency: string, interval: string, assumed?: bool}>
 */
function billing_plans(): array
{
    static $plans = null;
    if ($plans !== null) {
        return $plans;
    }
    if (!stripe_is_configured()) {
        return $plans = [BILLING_FALLBACK_PLAN];
    }

    $ids   = array_values(array_filter([(string) STRIPE_PRICE_ID, (string) STRIPE_PRICE_ID_YEARLY], static fn(string $id): bool => $id !== '' && !str_contains($id, 'XXXX')));
    $file  = QP_ROOT . '/storage/cache/stripe_prices.json';
    // The cache belongs to this key and these prices; changing either starts fresh.
    $stamp = hash('sha256', STRIPE_SECRET_KEY . '|' . implode('|', $ids) . '|' . (stripe_test_base() ?? ''));

    $cached = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
    if (is_array($cached) && ($cached['stamp'] ?? '') === $stamp && time() - (int) ($cached['fetched'] ?? 0) < 3600) {
        return $plans = $cached['plans'];
    }

    try {
        stripe_boot();
        $plans = [];
        foreach ($ids as $id) {
            $price = \Stripe\Price::retrieve($id);
            if (!$price->active || $price->type !== 'recurring') {
                error_log('Stripe: price ' . $id . ' is not an active recurring price');
                continue;
            }
            $plans[] = ['id' => $id, 'amount' => (int) $price->unit_amount, 'currency' => (string) $price->currency,
                        'interval' => (string) $price->recurring->interval];
        }
        if (!$plans) {
            throw new RuntimeException('no usable price configured');
        }
        @file_put_contents($file, json_encode(['stamp' => $stamp, 'fetched' => time(), 'plans' => $plans]), LOCK_EX);
        return $plans;
    } catch (Throwable $e) {
        error_log('Stripe: prices could not be loaded: ' . $e->getMessage());
        // An older answer is better than a guess.
        if (is_array($cached) && ($cached['stamp'] ?? '') === $stamp) {
            return $plans = $cached['plans'];
        }
        return $plans = [['id' => (string) STRIPE_PRICE_ID] + BILLING_FALLBACK_PLAN];
    }
}

/**
 * The Stripe customer of this account; created on first use.
 * The customer exists BEFORE the first checkout, so every later event
 * can be matched to the account through its customer id.
 *
 * @param array<string, mixed> $user
 */
function billing_customer_id(array $user): string
{
    if (!empty($user['stripe_customer_id'])) {
        return (string) $user['stripe_customer_id'];
    }
    stripe_boot();
    $customer = \Stripe\Customer::create([
        'email'    => $user['email'],
        'name'     => $user['name'] ?: null,
        'metadata' => ['nutzer_id' => (string) $user['id']],
    ]);
    $pdo = db();
    $pdo->prepare('UPDATE nutzer SET stripe_customer_id = ? WHERE id = ? AND stripe_customer_id IS NULL')
        ->execute([$customer->id, $user['id']]);

    // Two clicks at the same moment: the first one to write wins, both
    // continue with THAT customer (the other one stays unused at Stripe).
    $stmt = $pdo->prepare('SELECT stripe_customer_id FROM nutzer WHERE id = ?');
    $stmt->execute([$user['id']]);
    return (string) $stmt->fetchColumn();
}

/**
 * All subscriptions of a customer, straight from Stripe.
 * @return list<array<string, mixed>>
 */
function billing_fetch_subscriptions(string $customerId): array
{
    stripe_boot();
    $list = \Stripe\Subscription::all(['customer' => $customerId, 'status' => 'all', 'limit' => 20]);
    $subscriptions = [];
    foreach ($list->data as $subscription) {
        $subscriptions[] = $subscription->toArray();
    }
    return $subscriptions;
}

/**
 * Write the state into `nutzer`. The row is locked while it changes,
 * so a webhook and the customer's own page load cannot interleave.
 * The caller owns the transaction.
 *
 * @param list<array<string, mixed>> $subscriptions from billing_fetch_subscriptions()
 * @return array{before: array<string, mixed>, after: array<string, mixed>, changes: list<string>}|null
 *         null when the account no longer exists
 */
function billing_apply(PDO $pdo, int $userId, array $subscriptions): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM nutzer WHERE id = ? FOR UPDATE');
    $stmt->execute([$userId]);
    $before = $stmt->fetch();
    if (!$before) {
        return null;
    }

    $after = billing_state(billing_pick_subscription($subscriptions));
    $pdo->prepare(
        'UPDATE nutzer
         SET abo_status = ?, stripe_subscription_id = ?, stripe_status = ?,
             abo_periode_ende = ?, abo_endet_am = ?, abo_preis_cent = ?, abo_waehrung = ?, abo_intervall = ?,
             abo_abgeglichen_am = NOW()
         WHERE id = ?'
    )->execute([
        $after['abo_status'], $after['stripe_subscription_id'], $after['stripe_status'],
        $after['abo_periode_ende'], $after['abo_endet_am'],
        $after['abo_preis_cent'], $after['abo_waehrung'], $after['abo_intervall'], $userId,
    ]);

    return ['before' => $before, 'after' => $after, 'changes' => billing_transitions($before, $after)];
}

/**
 * Ask Stripe and update the account. Used when the customer comes
 * back from Checkout or the portal and by the reconcile script.
 * Throws when Stripe cannot be reached – the stored state then stays.
 *
 * @param array<string, mixed> $user
 * @return array{before: array<string, mixed>, after: array<string, mixed>, changes: list<string>}|null
 */
function billing_sync_user(array $user): ?array
{
    if (empty($user['stripe_customer_id'])) {
        return null;
    }
    $subscriptions = billing_fetch_subscriptions((string) $user['stripe_customer_id']);   // network first …

    $pdo = db();
    $pdo->beginTransaction();                                                             // … then a short transaction
    try {
        $result = billing_apply($pdo, (int) $user['id'], $subscriptions);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $result;
}

/**
 * Invoices of the customer for the billing page (newest first).
 * Links lead to Stripe's hosted invoice pages.
 *
 * @return list<array{date: int, number: string, amount: int, currency: string, status: string, url: string}>
 */
function billing_invoices(string $customerId, int $limit = 12): array
{
    stripe_boot();
    $rows = [];
    foreach (\Stripe\Invoice::all(['customer' => $customerId, 'limit' => $limit])->data as $invoice) {
        if ($invoice->status === 'draft') {
            continue;   // not issued yet
        }
        $url = (string) ($invoice->hosted_invoice_url ?? '');
        $rows[] = [
            'date'     => (int) $invoice->created,
            'number'   => (string) ($invoice->number ?? $invoice->id),
            'amount'   => (int) ($invoice->status === 'paid' ? $invoice->amount_paid : $invoice->amount_due),
            'currency' => (string) $invoice->currency,
            'status'   => (string) $invoice->status,
            // Only real web addresses become links.
            'url'      => preg_match('#^https?://#', $url) ? $url : '',
        ];
    }
    return $rows;
}

/**
 * Tell the customer by mail what happened to the subscription.
 * Called AFTER the database transaction – a mail that fails must not
 * undo a state change.
 *
 * @param array<string, mixed> $user    row of `nutzer`
 * @param list<string>         $changes from billing_transitions()
 * @param array<string, mixed> $after   billing_state()
 */
function billing_notify_changes(array $user, array $changes, array $after): void
{
    $page = base_url() . '/subscription.php';
    if (in_array('cancel_scheduled', $changes, true) && $after['abo_endet_am'] !== null) {
        send_account_mail($user, 'cancel_scheduled', ['datum' => mail_date($user, $after['abo_endet_am'])], $page);
    }
    if (in_array('ended', $changes, true)) {
        send_account_mail($user, 'subscription_ended', ['n' => (int) FREE_SURVEY_LIMIT], $page);
    }
}

/** A date written the way the ACCOUNT's language writes it (mails). */
function mail_date(array $user, string $datetime): string
{
    $pageLocale = locale();
    set_locale((string) ($user['sprache'] ?? $pageLocale));
    $text = format_date($datetime);
    set_locale($pageLocale);
    return $text;
}

/** Same for an amount of money. */
function mail_amount(array $user, int $amount, string $currency): string
{
    $pageLocale = locale();
    set_locale((string) ($user['sprache'] ?? $pageLocale));
    $text = billing_format_amount($amount, $currency);
    set_locale($pageLocale);
    return $text;
}
