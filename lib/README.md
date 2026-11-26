# lib/ – third-party code

Everything in this folder is NOT written by the project author.

- `stripe-php/` – official Stripe PHP library (MIT licence, see
  `stripe-php/LICENSE`), https://github.com/stripe/stripe-php
  Version: see `stripe-php/VERSION` (23.0.0 when this was packaged).
  Loaded in exactly one place: `stripe_boot()` in `includes/billing.php`.

Updating: download a release from GitHub and replace the folder
(`init.php`, `lib/`, `data/`, `LICENSE`, `VERSION` are enough). Then run
`php tests/run.php` – `billing_test.php` checks the webhook signature
with the library – and go through the checklist in `docs/PAYMENTS.md`,
because a new library version also means a newer Stripe API version.

The application also runs without this folder: the subscription page
then shows that online payment is not set up.
