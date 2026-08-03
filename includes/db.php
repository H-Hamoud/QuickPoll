<?php
// ============================================================
//  db.php – central database connection (PDO)
//  Included by every page that needs database access:
//      require_once 'db.php';
// ============================================================

// Credentials for the local XAMPP database.
// XAMPP default: user 'root', no password.
$host     = 'localhost';
$dbname   = 'quickpoll';
$user     = 'root';
$password = '';


// DSN = "Data Source Name": describes WHERE and HOW to connect.
// charset=utf8mb4 makes sure umlauts (ä, ö, ü, ß) are stored correctly.
$dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";

// Connection options:
$options = [
    // Throw an exception on errors (instead of failing silently)
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    // Return results as associative arrays (column name => value)
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    // Use real prepared statements (safer against SQL injection)
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// Open the connection. If it fails, show a short message and stop –
// this way you can immediately see when something is wrong.
try {
    $pdo = new PDO($dsn, $user, $password, $options);
} catch (PDOException $e) {
    die('Datenbank-Verbindung fehlgeschlagen: ' . $e->getMessage());
}