<?php
declare(strict_types=1);
// ============================================================
//  maintenance.php – remove what has expired
//
//      C:\xampp\php\php.exe database\maintenance.php
//
//  Deletes accounts whose e-mail address was never confirmed (after
//  7 days), expired links, idle sessions and old counters. The
//  application also does this now and then by itself; on a real
//  server a daily cron job makes it predictable.
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this script on the command line.');
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';

foreach (maintenance_purge() as $what => $count) {
    echo str_pad($what, 26) . $count . PHP_EOL;
}
