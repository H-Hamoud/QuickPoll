<?php
// ============================================================
//  config.example.php – TEMPLATE for config.php
//  This file is committed to Git, config.php is NOT.
//
//  Setup: copy this file to config.php and insert your own
//  Stripe TEST keys (Stripe dashboard > sandbox > API keys).
// ============================================================

// Publishable key (public, safe to appear in the browser)
define('STRIPE_PUBLISHABLE_KEY', 'pk_test_XXXXXXXXXXXXXXXX');

// Secret key (NEVER share, never commit to Git!)
define('STRIPE_SECRET_KEY', 'sk_test_XXXXXXXXXXXXXXXX');

// Price ID of the subscription product (Stripe dashboard > product catalogue)
define('STRIPE_PRICE_ID', 'price_XXXXXXXXXXXXXXXX');

// Base URL of the local installation – Stripe redirects back here
define('BASE_URL', 'http://localhost/quickpoll');