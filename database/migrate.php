<?php
declare(strict_types=1);
// ============================================================
//  migrate.php – brings the database to schema version 2
//
//  Run on the command line (XAMPP shell / PowerShell):
//      C:\xampp\php\php.exe database\migrate.php
//
//  - Empty database      → imports schema.sql
//  - Old 4-table schema  → adds the new columns and tables and
//                          converts the existing answers. No data
//                          is deleted.
//
//  The script is IDEMPOTENT: every step first checks whether it is
//  still necessary, so running it twice is harmless.
//  Still: make a backup first (mysqldump --result-file=backup.sql quickpoll).
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this script on the command line.');
}

require_once __DIR__ . '/../includes/bootstrap.php';

$pdo = db();

// Read and write times exactly as the server shows them. db() sets a
// fixed offset (e.g. +01:00), which would shift old TIMESTAMP values
// from summer time by one hour while they are copied.
$pdo->exec("SET time_zone = 'SYSTEM'");

// ---------- Small helpers that ask information_schema ----------

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

/** Exact type of a column, e.g. "int(11)" or "int(10) unsigned". */
function column_type(PDO $pdo, string $table, string $column): string
{
    $stmt = $pdo->prepare(
        'SELECT column_type FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->execute([$table, $column]);
    return (string) $stmt->fetchColumn();
}

function index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
    );
    $stmt->execute([$table, $index]);
    return (int) $stmt->fetchColumn() > 0;
}

function foreign_key_exists(PDO $pdo, string $table, string $constraint): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.table_constraints
         WHERE table_schema = DATABASE() AND table_name = ?
           AND constraint_name = ? AND constraint_type = 'FOREIGN KEY'"
    );
    $stmt->execute([$table, $constraint]);
    return (int) $stmt->fetchColumn() > 0;
}

function step(string $message): void
{
    echo '  - ' . $message . PHP_EOL;
}

function add_column(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!column_exists($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        step("$table.$column added");
    }
}

// ============================================================
//  Case 1: empty database → import schema.sql
// ============================================================
if (!table_exists($pdo, 'nutzer')) {
    echo 'Empty database – importing schema.sql' . PHP_EOL;

    $sql = file_get_contents(__DIR__ . '/schema.sql');
    // Remove comment lines, then split into single statements.
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $sql = preg_replace('/\s--\s.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        $pdo->exec($statement);
    }

    echo 'Done. Schema version 2 installed.' . PHP_EOL;
    exit(0);
}

// ============================================================
//  Case 2: upgrade the existing schema
// ============================================================
echo 'Existing database found – upgrading to schema version 2' . PHP_EOL;

// New foreign-key columns must have EXACTLY the type of the key they
// point to (INT vs. INT UNSIGNED matters), so we read the real types.
$userIdType     = column_type($pdo, 'nutzer', 'id');
$surveyIdType   = column_type($pdo, 'fragebogen', 'id');
$questionIdType = column_type($pdo, 'frage', 'id');

// ---------- nutzer ----------
add_column($pdo, 'nutzer', 'name', 'VARCHAR(100) NULL AFTER id');
add_column($pdo, 'nutzer', 'stripe_customer_id', 'VARCHAR(255) NULL');
add_column($pdo, 'nutzer', 'stripe_subscription_id', 'VARCHAR(255) NULL');
add_column($pdo, 'nutzer', 'erstellt_am', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
add_column($pdo, 'nutzer', 'letzter_login', 'DATETIME NULL');
add_column($pdo, 'nutzer', 'sprache', 'VARCHAR(5) NULL');     // preferred language
add_column($pdo, 'nutzer', 'design', 'VARCHAR(10) NULL');     // theme

// ---------- Accounts: verification, sign-in without password ----------
// Existing accounts were created before verification existed: they count
// as verified, otherwise every current user would be locked out of
// publishing. Only NEW registrations start unverified.
if (!column_exists($pdo, 'nutzer', 'email_bestaetigt_am')) {
    $pdo->exec('ALTER TABLE nutzer ADD COLUMN email_bestaetigt_am DATETIME NULL');
    $pdo->exec('UPDATE nutzer SET email_bestaetigt_am = COALESCE(erstellt_am, NOW())');
    step('nutzer.email_bestaetigt_am added (existing accounts marked as verified)');
}
add_column($pdo, 'nutzer', 'neue_email', 'VARCHAR(255) NULL');
// Accounts created through Google have no password.
$pdo->exec('ALTER TABLE nutzer MODIFY passwort_hash VARCHAR(255) NULL');

// abo_status becomes a plain VARCHAR with the two values aktiv/inaktiv
// (an ENUM would keep its old values when converted – nothing is lost).
if (column_type($pdo, 'nutzer', 'abo_status') !== 'varchar(20)') {
    $pdo->exec("ALTER TABLE nutzer MODIFY abo_status VARCHAR(20) NULL");
    $pdo->exec("UPDATE nutzer SET abo_status = 'inaktiv' WHERE abo_status IS NULL OR abo_status <> 'aktiv'");
    $pdo->exec("ALTER TABLE nutzer MODIFY abo_status VARCHAR(20) NOT NULL DEFAULT 'inaktiv'");
    step('nutzer.abo_status normalised (aktiv / inaktiv)');
}

// ---------- fragebogen ----------
add_column($pdo, 'fragebogen', 'token', 'CHAR(32) NULL');
add_column($pdo, 'fragebogen', 'endet_am', 'DATETIME NULL');
add_column($pdo, 'fragebogen', 'mehrfach_erlaubt', 'TINYINT(1) NOT NULL DEFAULT 0');
add_column($pdo, 'fragebogen', 'danke_text', 'VARCHAR(500) NULL');
add_column($pdo, 'fragebogen', 'aufrufe', 'INT UNSIGNED NOT NULL DEFAULT 0');
add_column($pdo, 'fragebogen', 'erstellt_am', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');

// Every existing survey gets a random public token.
$missing = $pdo->query('SELECT id FROM fragebogen WHERE token IS NULL')->fetchAll(PDO::FETCH_COLUMN);
$update  = $pdo->prepare('UPDATE fragebogen SET token = ? WHERE id = ?');
foreach ($missing as $id) {
    $update->execute([bin2hex(random_bytes(16)), $id]);
}
if ($missing) {
    step(count($missing) . ' survey token(s) generated');
}
if (!index_exists($pdo, 'fragebogen', 'uq_fragebogen_token')) {
    $pdo->exec('ALTER TABLE fragebogen ADD UNIQUE KEY uq_fragebogen_token (token)');
    step('unique index on fragebogen.token');
}

// ---------- frage ----------
add_column($pdo, 'frage', 'typ', "VARCHAR(20) NOT NULL DEFAULT 'text'");
add_column($pdo, 'frage', 'pflicht', 'TINYINT(1) NOT NULL DEFAULT 0');

// ---------- antwortoption ----------
if (!table_exists($pdo, 'antwortoption')) {
    $pdo->exec(
        "CREATE TABLE antwortoption (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            frage_id    $questionIdType NOT NULL,
            text        VARCHAR(255) NOT NULL,
            reihenfolge INT NOT NULL DEFAULT 0,
            KEY idx_antwortoption_frage (frage_id, reihenfolge),
            CONSTRAINT fk_antwortoption_frage FOREIGN KEY (frage_id)
                REFERENCES frage (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    step('table antwortoption created');
}

// ---------- teilnahme ----------
if (!table_exists($pdo, 'teilnahme')) {
    $pdo->exec(
        "CREATE TABLE teilnahme (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            fragebogen_id   $surveyIdType NOT NULL,
            abgegeben_am    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            teilnehmer_hash CHAR(64) NULL,
            KEY idx_teilnahme_zeit (fragebogen_id, abgegeben_am),
            KEY idx_teilnahme_hash (fragebogen_id, teilnehmer_hash),
            CONSTRAINT fk_teilnahme_fragebogen FOREIGN KEY (fragebogen_id)
                REFERENCES fragebogen (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    step('table teilnahme created');
}

// ---------- antwort ----------
add_column($pdo, 'antwort', 'abgegeben_am', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
add_column($pdo, 'antwort', 'teilnahme_id', 'INT UNSIGNED NULL');
add_column($pdo, 'antwort', 'option_id', 'INT UNSIGNED NULL');

// ---------- Remove orphans + add missing foreign keys ----------
// If the old database had no foreign keys, deleting a question or survey
// left its children behind ("orphans"): answers of deleted questions,
// questions of deleted surveys. Nobody can see them in the app any more,
// and they block the new constraints. They are removed here, then the
// constraints are added so it cannot happen again.

/** True if the column already has ANY foreign key (whatever its name). */
function column_has_foreign_key(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.key_column_usage
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
           AND referenced_table_name IS NOT NULL'
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

$orphanRules = [
    // child table, column, parent table, label for the output
    ['fragebogen', 'nutzer_id', 'nutzer', 'survey(s) of deleted users'],
    ['frage', 'fragebogen_id', 'fragebogen', 'question(s) of deleted surveys'],
    ['antwort', 'frage_id', 'frage', 'answer(s) of deleted questions'],
];
foreach ($orphanRules as [$child, $column, $parent, $label]) {
    if (column_has_foreign_key($pdo, $child, $column)) {
        continue;   // a constraint exists, orphans are impossible
    }
    $removed = $pdo->exec(
        "DELETE c FROM `$child` c LEFT JOIN `$parent` p ON p.id = c.`$column` WHERE p.id IS NULL"
    );
    if ($removed > 0) {
        step("$removed orphaned $label removed");
    }
    // The column must have exactly the parent's id type for a foreign key.
    $type = column_type($pdo, $parent, 'id');
    $pdo->exec("ALTER TABLE `$child` MODIFY `$column` $type NOT NULL");
    $pdo->exec(
        "ALTER TABLE `$child` ADD CONSTRAINT fk_{$child}_{$parent}
         FOREIGN KEY (`$column`) REFERENCES `$parent` (id) ON DELETE CASCADE"
    );
    step("foreign key $child → $parent");
}

// ---------- Convert old answers into submissions ----------
// The old schema had no "submission" entity: one person's answers were
// just several rows in `antwort`. The old survey.php inserted them in a
// loop over the questions (ordered by position), inside one request.
// So one submission = a run of consecutive answer ids of the same survey
// whose question positions keep increasing. A new submission starts when
//   - the survey changes,
//   - the question position does not increase (same/earlier question
//     again → the next person), or
//   - both timestamps are known and more than 5 seconds apart.
// This works even when abgegeben_am is empty (NULL) in old data.
//
// "Broken" = no submission yet: NULL from the old schema, or 0 / a
// dangling id left behind by an earlier, interrupted migration run.
$broken = $pdo->query(
    'SELECT a.id, a.abgegeben_am, fr.id AS frage_id, fr.reihenfolge,
            fr.fragebogen_id, f.erstellt_am AS survey_created
     FROM antwort a
     JOIN frage fr       ON fr.id = a.frage_id
     JOIN fragebogen f   ON f.id = fr.fragebogen_id
     LEFT JOIN teilnahme t ON t.id = a.teilnahme_id
     WHERE t.id IS NULL
     ORDER BY a.id'
)->fetchAll();

if ($broken) {
    /** A usable datetime string, or null for NULL / 0000-00-00 values. */
    $validTime = fn(?string $v): ?string => ($v !== null && !str_starts_with($v, '0000')) ? $v : null;

    $pdo->beginTransaction();

    // An interrupted earlier run may have left empty submissions behind
    // (no answers, no participant hash). They would count as responses.
    $removed = $pdo->exec(
        'DELETE t FROM teilnahme t
         WHERE t.teilnehmer_hash IS NULL
           AND NOT EXISTS (SELECT 1 FROM antwort a WHERE a.teilnahme_id = t.id)'
    );

    $insert = $pdo->prepare('INSERT INTO teilnahme (fragebogen_id, abgegeben_am) VALUES (?, ?)');
    $assign = $pdo->prepare('UPDATE antwort SET teilnahme_id = ? WHERE id = ?');

    $prev   = null;   // previous answer: survey, position, time
    $groups = 0;
    $current = 0;     // id of the submission being filled

    foreach ($broken as $row) {
        $survey   = (int) $row['fragebogen_id'];
        $position = [(int) $row['reihenfolge'], (int) $row['frage_id']];
        $time     = $validTime($row['abgegeben_am']);

        $newSubmission = $prev === null
            || $prev['survey'] !== $survey
            || $position[0] < $prev['position'][0]
            || ($position[0] === $prev['position'][0] && $position[1] <= $prev['position'][1])
            || ($time !== null && $prev['time'] !== null
                && abs(strtotime($time) - strtotime($prev['time'])) > 5);

        if ($newSubmission) {
            // Unknown time: fall back to the survey's creation date, then to now.
            $when = $time ?? $validTime($row['survey_created']) ?? date('Y-m-d H:i:s');
            $insert->execute([$survey, $when]);
            $current = (int) $pdo->lastInsertId();
            $groups++;
        }

        $assign->execute([$current, $row['id']]);
        $prev = ['survey' => $survey, 'position' => $position, 'time' => $time];
    }

    $pdo->commit();

    if ($removed > 0) {
        step("$removed empty submission(s) from an interrupted run removed");
    }
    step(count($broken) . " old answer(s) grouped into $groups submission(s)");
}

if (!foreign_key_exists($pdo, 'antwort', 'fk_antwort_teilnahme')) {
    // Safety net: never tighten the column while answers are unassigned.
    $left = (int) $pdo->query(
        'SELECT COUNT(*) FROM antwort a LEFT JOIN teilnahme t ON t.id = a.teilnahme_id WHERE t.id IS NULL'
    )->fetchColumn();
    if ($left > 0) {
        throw new RuntimeException("$left answer(s) still without a submission – migration stopped, nothing was lost.");
    }
    $pdo->exec('ALTER TABLE antwort MODIFY teilnahme_id INT UNSIGNED NOT NULL');
    $pdo->exec(
        'ALTER TABLE antwort ADD CONSTRAINT fk_antwort_teilnahme
         FOREIGN KEY (teilnahme_id) REFERENCES teilnahme (id) ON DELETE CASCADE'
    );
    step('foreign key antwort → teilnahme');
}
if (!foreign_key_exists($pdo, 'antwort', 'fk_antwort_option')) {
    $pdo->exec(
        'ALTER TABLE antwort ADD CONSTRAINT fk_antwort_option
         FOREIGN KEY (option_id) REFERENCES antwortoption (id) ON DELETE SET NULL'
    );
    step('foreign key antwort → antwortoption');
}

// ---------- login_versuch ----------
if (!table_exists($pdo, 'login_versuch')) {
    $pdo->exec(
        'CREATE TABLE login_versuch (
            id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            email     VARCHAR(255) NOT NULL,
            ip_hash   CHAR(64) NOT NULL,
            zeitpunkt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_login_versuch_email (email, zeitpunkt),
            KEY idx_login_versuch_ip (ip_hash, zeitpunkt)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    step('table login_versuch created');
}

// ---------- passwort_reset ----------
if (!table_exists($pdo, 'passwort_reset')) {
    $pdo->exec(
        "CREATE TABLE passwort_reset (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            nutzer_id  $userIdType NOT NULL,
            token_hash CHAR(64) NOT NULL,
            laeuft_ab  DATETIME NOT NULL,
            benutzt_am DATETIME NULL,
            UNIQUE KEY uq_passwort_reset_token (token_hash),
            CONSTRAINT fk_passwort_reset_nutzer FOREIGN KEY (nutzer_id)
                REFERENCES nutzer (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    step('table passwort_reset created');
}

// ---------- Subscription details from Stripe ----------
add_column($pdo, 'nutzer', 'stripe_status', 'VARCHAR(30) NULL');
add_column($pdo, 'nutzer', 'abo_periode_ende', 'DATETIME NULL');
add_column($pdo, 'nutzer', 'abo_endet_am', 'DATETIME NULL');
add_column($pdo, 'nutzer', 'abo_abgeglichen_am', 'DATETIME NULL');
add_column($pdo, 'nutzer', 'abo_preis_cent', 'INT UNSIGNED NULL');
add_column($pdo, 'nutzer', 'abo_waehrung', 'CHAR(3) NULL');
add_column($pdo, 'nutzer', 'abo_intervall', 'VARCHAR(10) NULL');

// ---------- Survey settings: response limit, access code, archive; completion time ----------
add_column($pdo, 'fragebogen', 'max_teilnahmen', 'INT UNSIGNED NULL');
add_column($pdo, 'fragebogen', 'zugangscode_hash', 'VARCHAR(255) NULL');
add_column($pdo, 'fragebogen', 'archiviert_am', 'DATETIME NULL');
add_column($pdo, 'teilnahme', 'dauer_sekunden', 'INT UNSIGNED NULL');

// Every Stripe customer belongs to exactly one account.
if (!index_exists($pdo, 'nutzer', 'uq_nutzer_stripe_customer')) {
    $shared = (int) $pdo->query(
        'SELECT COUNT(*) FROM (SELECT stripe_customer_id FROM nutzer WHERE stripe_customer_id IS NOT NULL
         GROUP BY stripe_customer_id HAVING COUNT(*) > 1) AS doppelt'
    )->fetchColumn();
    if ($shared === 0) {
        $pdo->exec('ALTER TABLE nutzer ADD UNIQUE KEY uq_nutzer_stripe_customer (stripe_customer_id)');
        step('unique index on nutzer.stripe_customer_id added');
    } else {
        step("WARNING: $shared Stripe customer id(s) are used by more than one account – unique index NOT added, please clean up");
    }
}

// ---------- konto_token, nutzer_identitaet, sitzung, rate_limit, stripe_ereignis ----------
$tables = [
    'stripe_ereignis' => "CREATE TABLE stripe_ereignis (
        id           VARCHAR(255) NOT NULL PRIMARY KEY,
        typ          VARCHAR(80) NOT NULL,
        erstellt_am  DATETIME NOT NULL,
        empfangen_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ergebnis     VARCHAR(20) NOT NULL,
        KEY idx_stripe_ereignis_zeit (empfangen_am)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'konto_token' => "CREATE TABLE konto_token (
        id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        nutzer_id  $userIdType NOT NULL,
        zweck      VARCHAR(20) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        daten      VARCHAR(255) NULL,
        laeuft_ab  DATETIME NOT NULL,
        benutzt_am DATETIME NULL,
        UNIQUE KEY uq_konto_token (token_hash),
        KEY idx_konto_token_nutzer (nutzer_id, zweck),
        CONSTRAINT fk_konto_token_nutzer FOREIGN KEY (nutzer_id) REFERENCES nutzer (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'nutzer_identitaet' => "CREATE TABLE nutzer_identitaet (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        nutzer_id     $userIdType NOT NULL,
        anbieter      VARCHAR(20) NOT NULL,
        anbieter_id   VARCHAR(255) NOT NULL,
        email         VARCHAR(255) NULL,
        verknuepft_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_identitaet_anbieter (anbieter, anbieter_id),
        UNIQUE KEY uq_identitaet_nutzer (nutzer_id, anbieter),
        CONSTRAINT fk_identitaet_nutzer FOREIGN KEY (nutzer_id) REFERENCES nutzer (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'sitzung' => "CREATE TABLE sitzung (
        id            CHAR(64) NOT NULL PRIMARY KEY,
        nutzer_id     $userIdType NOT NULL,
        erstellt_am   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        zuletzt_aktiv DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ip_hash       CHAR(64) NOT NULL,
        user_agent    VARCHAR(255) NOT NULL DEFAULT '',
        KEY idx_sitzung_nutzer (nutzer_id, zuletzt_aktiv),
        CONSTRAINT fk_sitzung_nutzer FOREIGN KEY (nutzer_id) REFERENCES nutzer (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'rate_limit' => "CREATE TABLE rate_limit (
        id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        aktion     VARCHAR(30) NOT NULL,
        schluessel CHAR(64) NOT NULL,
        zeitpunkt  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_rate_limit (aktion, schluessel, zeitpunkt)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
foreach ($tables as $name => $sql) {
    if (!table_exists($pdo, $name)) {
        $pdo->exec($sql);
        step("table $name created");
    }
}

echo 'Done. Database is at schema version 2.' . PHP_EOL;
