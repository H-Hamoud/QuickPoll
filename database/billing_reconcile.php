<?php
declare(strict_types=1);
// ============================================================
//  billing_reconcile.php – compare every subscription with Stripe
//
//      C:\xampp\php\php.exe database\billing_reconcile.php
//      php database/billing_reconcile.php --all
//
//  Webhooks keep the accounts up to date within seconds. This script
//  is the safety net for the day one was missed (server down longer
//  than Stripe retries, wrong webhook secret after a key rotation):
//  it asks Stripe for the current state and corrects the copy in the
//  database. Meant for a daily cron job on a real server.
//
//  Without --all only accounts are checked whose paid period is over
//  or whose last check is more than a day old.
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this script on the command line.');
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!stripe_is_configured()) {
    fwrite(STDERR, 'Stripe is not configured (keys or lib/stripe-php missing).' . PHP_EOL);
    exit(1);
}

$all  = in_array('--all', $argv, true);
$stmt = db()->query(
    'SELECT * FROM nutzer WHERE stripe_customer_id IS NOT NULL'
    . ($all ? '' : ' AND (abo_abgeglichen_am IS NULL OR abo_abgeglichen_am < NOW() - INTERVAL 1 DAY
                          OR (abo_periode_ende IS NOT NULL AND abo_periode_ende < NOW()))')
    . ' ORDER BY id'
);

$checked = $changed = $failed = 0;
foreach ($stmt->fetchAll() as $user) {
    $checked++;
    try {
        $result = billing_sync_user($user);
        if ($result === null) {
            continue;
        }
        $was = $user['abo_status'] . '/' . ($user['stripe_status'] ?? '-') . '/' . ($user['abo_endet_am'] ?? '-');
        $now = $result['after']['abo_status'] . '/' . ($result['after']['stripe_status'] ?? '-') . '/' . ($result['after']['abo_endet_am'] ?? '-');
        if ($was !== $now) {
            $changed++;
            echo '  account ' . $user['id'] . ': ' . $was . ' -> ' . $now . PHP_EOL;
            billing_notify_changes($user, $result['changes'], $result['after']);
        }
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, '  account ' . $user['id'] . ': ' . $e->getMessage() . PHP_EOL);
    }
}

echo "Checked $checked account(s), corrected $changed, failed $failed." . PHP_EOL;
exit($failed > 0 ? 1 : 0);
