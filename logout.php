<?php
// ============================================================
//  logout.php – sign out
//  Ends the session and redirects back to the login page.
// ============================================================

session_start();          // load the existing session

// 1. Clear the data in the current request
$_SESSION = [];

// 2. Delete the session cookie in the browser.
//    session_destroy() only removes the file on the SERVER –
//    the client would keep sending the old PHPSESSID otherwise.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// 3. Delete the session file on the server
session_destroy();

header('Location: login.php');   // back to login
exit;