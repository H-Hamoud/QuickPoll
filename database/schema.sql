-- ============================================================
--  QuickPoll – database schema (version 2), fresh installation
--
--  Import into an EMPTY database `quickpoll` (utf8mb4), either via
--  phpMyAdmin or with:   php database/migrate.php
--
--  Upgrading the old 4-table database? Do NOT import this file –
--  run  php database/migrate.php  instead, it keeps your data.
--
--  Table and column names are German (as in the planning document),
--  the PHP code is English.
-- ============================================================

CREATE TABLE nutzer (
    id                     INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name                   VARCHAR(100) NULL,
    email                  VARCHAR(255) NOT NULL,
    passwort_hash          VARCHAR(255) NULL,                 -- NULL = account without password (Google sign-in only)
    email_bestaetigt_am    DATETIME     NULL,                 -- NULL = e-mail address not verified yet
    neue_email             VARCHAR(255) NULL,                 -- requested new address, waiting for confirmation
    abo_status             VARCHAR(20)  NOT NULL DEFAULT 'inaktiv',   -- 'aktiv' | 'inaktiv': does the account have Pro access?
    stripe_customer_id     VARCHAR(255) NULL,
    stripe_subscription_id VARCHAR(255) NULL,
    stripe_status          VARCHAR(30)  NULL,                 -- Stripe's own state: trialing, active, past_due, unpaid, canceled, incomplete, incomplete_expired, paused
    abo_periode_ende       DATETIME     NULL,                 -- end of the paid period (next renewal)
    abo_endet_am           DATETIME     NULL,                 -- cancellation is scheduled for this moment; NULL = renews
    abo_abgeglichen_am     DATETIME     NULL,                 -- last time the state was fetched from Stripe
    abo_preis_cent         INT UNSIGNED NULL,                 -- what this subscription costs per period (from Stripe)
    abo_waehrung           CHAR(3)      NULL,
    abo_intervall          VARCHAR(10)  NULL,                 -- month | year
    sprache                VARCHAR(5)   NULL,                 -- preferred language: de | en | ar
    design                 VARCHAR(10)  NULL,                 -- theme: system | light | dark
    erstellt_am            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    letzter_login          DATETIME     NULL,
    UNIQUE KEY uq_nutzer_email (email),
    UNIQUE KEY uq_nutzer_stripe_customer (stripe_customer_id)   -- one Stripe customer, one account
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fragebogen (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nutzer_id        INT UNSIGNED NOT NULL,
    titel            VARCHAR(255) NOT NULL,
    beschreibung     TEXT         NULL,
    veroeffentlicht  TINYINT(1)   NOT NULL DEFAULT 0,
    token            CHAR(32)     NULL,                 -- random id for the public link
    endet_am         DATETIME     NULL,                 -- survey closes automatically
    mehrfach_erlaubt TINYINT(1)   NOT NULL DEFAULT 0,   -- allow several submissions per person
    danke_text       VARCHAR(500) NULL,                 -- custom thank-you message
    aufrufe          INT UNSIGNED NOT NULL DEFAULT 0,   -- page views of the public form (without bots and previews)
    max_teilnahmen   INT UNSIGNED NULL,                 -- closes automatically after this many submissions
    zugangscode_hash VARCHAR(255) NULL,                 -- optional access code for participants (password_hash)
    archiviert_am    DATETIME     NULL,                 -- archived: hidden from the list, not answerable
    erstellt_am      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fragebogen_token (token),
    KEY idx_fragebogen_nutzer (nutzer_id),
    CONSTRAINT fk_fragebogen_nutzer FOREIGN KEY (nutzer_id)
        REFERENCES nutzer (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE frage (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    fragebogen_id INT UNSIGNED NOT NULL,
    text          VARCHAR(500) NOT NULL,
    typ           VARCHAR(20)  NOT NULL DEFAULT 'text',  -- text | textarea | single | multiple | rating | yesno
    pflicht       TINYINT(1)   NOT NULL DEFAULT 0,
    reihenfolge   INT          NOT NULL DEFAULT 0,
    KEY idx_frage_fragebogen (fragebogen_id, reihenfolge),
    CONSTRAINT fk_frage_fragebogen FOREIGN KEY (fragebogen_id)
        REFERENCES fragebogen (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Answer options of single/multiple choice questions
CREATE TABLE antwortoption (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    frage_id    INT UNSIGNED NOT NULL,
    text        VARCHAR(255) NOT NULL,
    reihenfolge INT          NOT NULL DEFAULT 0,
    KEY idx_antwortoption_frage (frage_id, reihenfolge),
    CONSTRAINT fk_antwortoption_frage FOREIGN KEY (frage_id)
        REFERENCES frage (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per submitted form ("one person filled in the survey once").
-- Groups the individual answers and is the basis of all statistics.
CREATE TABLE teilnahme (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    fragebogen_id   INT UNSIGNED NOT NULL,
    abgegeben_am    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    teilnehmer_hash CHAR(64)     NULL,   -- HMAC of IP + browser, duplicate protection
    dauer_sekunden  INT UNSIGNED NULL,   -- time between opening the form and sending it
    KEY idx_teilnahme_zeit (fragebogen_id, abgegeben_am),
    KEY idx_teilnahme_hash (fragebogen_id, teilnehmer_hash),
    CONSTRAINT fk_teilnahme_fragebogen FOREIGN KEY (fragebogen_id)
        REFERENCES fragebogen (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE antwort (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    frage_id     INT UNSIGNED NOT NULL,
    teilnahme_id INT UNSIGNED NOT NULL,
    option_id    INT UNSIGNED NULL,       -- set for choice questions
    antwort_text TEXT         NOT NULL,   -- free text, rating value or option label
    abgegeben_am DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_antwort_frage (frage_id),
    KEY idx_antwort_teilnahme (teilnahme_id),
    KEY idx_antwort_option (option_id),
    CONSTRAINT fk_antwort_frage FOREIGN KEY (frage_id)
        REFERENCES frage (id) ON DELETE CASCADE,
    CONSTRAINT fk_antwort_teilnahme FOREIGN KEY (teilnahme_id)
        REFERENCES teilnahme (id) ON DELETE CASCADE,
    -- Deleting an option keeps the answers (their label stays in antwort_text)
    CONSTRAINT fk_antwort_option FOREIGN KEY (option_id)
        REFERENCES antwortoption (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Failed logins, basis of the brute-force throttle
CREATE TABLE login_versuch (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email     VARCHAR(255) NOT NULL,
    ip_hash   CHAR(64)     NOT NULL,
    zeitpunkt DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_login_versuch_email (email, zeitpunkt),
    KEY idx_login_versuch_ip (ip_hash, zeitpunkt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Password-reset tokens. Only the SHA-256 hash of the token is stored:
-- a leaked database does not hand out working reset links.
CREATE TABLE passwort_reset (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nutzer_id  INT UNSIGNED NOT NULL,
    token_hash CHAR(64)     NOT NULL,
    laeuft_ab  DATETIME     NOT NULL,
    benutzt_am DATETIME     NULL,
    UNIQUE KEY uq_passwort_reset_token (token_hash),
    CONSTRAINT fk_passwort_reset_nutzer FOREIGN KEY (nutzer_id)
        REFERENCES nutzer (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One-time tokens sent by e-mail: verify the address, confirm a new
-- address. Like password resets, only the SHA-256 hash is stored.
CREATE TABLE konto_token (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nutzer_id  INT UNSIGNED NOT NULL,
    zweck      VARCHAR(20)  NOT NULL,                 -- email_verify | email_change
    token_hash CHAR(64)     NOT NULL,
    daten      VARCHAR(255) NULL,                     -- e.g. the new e-mail address
    laeuft_ab  DATETIME     NOT NULL,
    benutzt_am DATETIME     NULL,
    UNIQUE KEY uq_konto_token (token_hash),
    KEY idx_konto_token_nutzer (nutzer_id, zweck),
    CONSTRAINT fk_konto_token_nutzer FOREIGN KEY (nutzer_id)
        REFERENCES nutzer (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- External sign-in providers linked to an account (Google).
-- Stored: who (provider + its stable user id "sub") – no access tokens.
CREATE TABLE nutzer_identitaet (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nutzer_id     INT UNSIGNED NOT NULL,
    anbieter      VARCHAR(20)  NOT NULL,              -- google
    anbieter_id   VARCHAR(255) NOT NULL,              -- the provider's user id (claim "sub")
    email         VARCHAR(255) NULL,                  -- address at the provider when linked (display only)
    verknuepft_am DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_identitaet_anbieter (anbieter, anbieter_id),
    UNIQUE KEY uq_identitaet_nutzer (nutzer_id, anbieter),
    CONSTRAINT fk_identitaet_nutzer FOREIGN KEY (nutzer_id)
        REFERENCES nutzer (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Logged-in sessions. PHP keeps the session data; this table makes
-- sessions VISIBLE and REVOCABLE ("log out my other devices").
-- id = SHA-256 of a random token that only lives in the PHP session.
CREATE TABLE sitzung (
    id            CHAR(64)     NOT NULL PRIMARY KEY,
    nutzer_id     INT UNSIGNED NOT NULL,
    erstellt_am   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    zuletzt_aktiv DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_hash       CHAR(64)     NOT NULL,
    user_agent    VARCHAR(255) NOT NULL DEFAULT '',
    KEY idx_sitzung_nutzer (nutzer_id, zuletzt_aktiv),
    CONSTRAINT fk_sitzung_nutzer FOREIGN KEY (nutzer_id)
        REFERENCES nutzer (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Generic counter for rate limits (registrations, mails, sign-in starts).
-- schluessel = HMAC of action + IP or user id, never the plain value.
CREATE TABLE rate_limit (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    aktion     VARCHAR(30)  NOT NULL,
    schluessel CHAR(64)     NOT NULL,
    zeitpunkt  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_rate_limit (aktion, schluessel, zeitpunkt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Webhook events that were already processed. Stripe delivers every
-- event AT LEAST once; the primary key makes sure it is applied once.
CREATE TABLE stripe_ereignis (
    id           VARCHAR(255) NOT NULL PRIMARY KEY,   -- evt_…
    typ          VARCHAR(80)  NOT NULL,
    erstellt_am  DATETIME     NOT NULL,               -- when Stripe created the event
    empfangen_am DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ergebnis     VARCHAR(20)  NOT NULL,               -- angewendet | ignoriert
    KEY idx_stripe_ereignis_zeit (empfangen_am)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
