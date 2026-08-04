<?php
// ============================================================
//  auth.php – access guard for protected pages
//
//  Include this as the FIRST line of every page that requires
//  a logged-in customer:
//      require_once __DIR__ . '/includes/auth.php';
//
//  Central place for the login check (DRY): if the rule ever
//  changes, it only changes here – not in five files.
// ============================================================

// Start the session – must run before any HTML output,
// because the session cookie travels in the HTTP header.
session_start();

// No user_id in the session means: not logged in.
// Redirect to the login page and stop the script immediately,
// so no protected content is ever sent to the browser.
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}