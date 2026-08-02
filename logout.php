<?php
// ============================================================
//  logout.php – sign out
//  Ends the session and redirects back to the login page.
// ============================================================

session_start();          // load the existing session
session_destroy();        // delete all session data

header('Location: login.php');   // back to login
exit;