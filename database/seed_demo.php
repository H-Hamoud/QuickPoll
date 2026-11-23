<?php
declare(strict_types=1);
// ============================================================
//  seed_demo.php – fills the database with a demo account
//
//      C:\xampp\php\php.exe database\seed_demo.php
//
//  Creates the user  demo@quickpoll.test / demo1234  (Pro plan)
//  with four surveys and a few hundred invented submissions spread
//  over the last months – so dashboard and charts have something
//  to show (screenshots for the README, demo for recruiters).
//
//  Running it again deletes and rebuilds the demo account only.
//  With a language (de, en, ar) the demo content is created in it:
//      php database/seed_demo.php ar
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this script on the command line.');
}

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/templates.php';

const DEMO_EMAIL    = 'demo@quickpoll.test';
const DEMO_PASSWORD = 'demo1234';

// Optional: language of the demo content.
//     php database/seed_demo.php en      (de | en | ar, default de)
// Templates and sample answers go through t(), like everything else.
set_locale($argv[1] ?? 'de');

$pdo = db();
mt_srand(2026);   // fixed seed: the "random" data is the same on every run

// ---------- Demo user ----------
$pdo->prepare('DELETE FROM nutzer WHERE email = ?')->execute([DEMO_EMAIL]);   // CASCADE removes old demo data
// The demo address does not exist, so nobody could confirm it: the account starts verified.
$pdo->prepare("INSERT INTO nutzer (name, email, passwort_hash, abo_status, sprache, erstellt_am, email_bestaetigt_am) VALUES (?, ?, ?, 'aktiv', ?, ?, NOW())")
    ->execute([t('Demo Nutzerin'), DEMO_EMAIL, password_hash(DEMO_PASSWORD, PASSWORD_DEFAULT), locale(), date('Y-m-d H:i:s', strtotime('-11 months'))]);
$userId = (int) $pdo->lastInsertId();

/** Pick an index by weight: weighted_pick([1, 3]) returns 1 three times as often as 0. */
function weighted_pick(array $weights): int
{
    $roll = mt_rand(1, array_sum($weights));
    foreach ($weights as $index => $weight) {
        $roll -= $weight;
        if ($roll <= 0) {
            return $index;
        }
    }
    return 0;
}

$freeTexts = [
    t('Alles bestens, weiter so.'),
    t('Die Kommunikation könnte etwas schneller sein.'),
    t('Sehr freundlich und kompetent.'),
    t('Mehr Praxisbeispiele wären hilfreich.'),
    t('Die Pausen waren zu kurz.'),
    t('Gute Struktur, klare Erklärungen.'),
    t('Preis-Leistung stimmt für mich.'),
    t('Ich hätte mir mehr Zeit für Fragen gewünscht.'),
    t('Die Unterlagen waren sehr gut vorbereitet.'),
    t('Der Raum war leider etwas zu klein.'),
    t('Tolle Atmosphäre, gerne wieder.'),
    t('Terminabstimmung war unkompliziert.'),
];

// title key => [submissions, views, created (months ago), published, extra columns]
$plan = [
    'customer' => [210, 318, 10, true, ['max_teilnahmen' => 500]],                                // open, with a response limit
    'course'   => [146, 190, 7, true, []],
    'event'    => [88, 131, 3, true, ['endet_am' => date('Y-m-d H:i:s', strtotime('-3 weeks'))]],   // the event is over: closed
    'team'     => [0, 0, 0, false, []],                                                             // a draft without answers
];

$insertSubmission = $pdo->prepare('INSERT INTO teilnahme (fragebogen_id, abgegeben_am, teilnehmer_hash, dauer_sekunden) VALUES (?, ?, NULL, ?)');
$insertAnswer     = $pdo->prepare('INSERT INTO antwort (frage_id, teilnahme_id, option_id, antwort_text, abgegeben_am) VALUES (?, ?, ?, ?, ?)');

$templates = survey_templates();
$total     = 0;

foreach ($plan as $key => [$count, $views, $monthsAgo, $published, $extra]) {
    $template = $templates[$key];
    $surveyId = create_survey($userId, $template['title'], $template['description'], $template['questions']);
    $created  = strtotime("-$monthsAgo months");

    $pdo->prepare('UPDATE fragebogen SET veroeffentlicht = ?, aufrufe = ?, erstellt_am = ? WHERE id = ?')
        ->execute([(int) $published, $views, date('Y-m-d H:i:s', $created), $surveyId]);

    foreach ($extra as $column => $value) {     // column names come from the list above, not from input
        $pdo->prepare("UPDATE fragebogen SET $column = ? WHERE id = ?")->execute([$value, $surveyId]);
    }

    $questions = load_questions($surveyId);
    $latest    = isset($extra['endet_am']) ? strtotime($extra['endet_am']) : time();   // no answers after the end
    $span      = max(1, $latest - $created);

    $pdo->beginTransaction();
    for ($i = 0; $i < $count; $i++) {
        // Somewhat more submissions towards the present (random ** 1.3),
        // on weekdays rather than weekends, in the afternoon and evening.
        do {
            $time = $latest - (int) ($span * (mt_rand(0, 10000) / 10000) ** 1.3);
            $weekend = (int) date('N', $time) >= 6;
        } while ($weekend && mt_rand(1, 100) <= 60);
        $hour = [8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22][weighted_pick([1, 2, 3, 3, 4, 3, 3, 4, 5, 6, 7, 8, 7, 4, 2])];
        $when = min($latest, mktime($hour, mt_rand(0, 59), mt_rand(0, 59), (int) date('n', $time), (int) date('j', $time), (int) date('Y', $time)));
        $stamp = date('Y-m-d H:i:s', $when);

        // Completion time: roughly 9–20 seconds per question; some submissions
        // have none (e.g. the form sat open for hours and was not measured).
        $duration = mt_rand(1, 100) <= 88 ? (int) round(count($questions) * mt_rand(90, 200) / 10 + mt_rand(0, 40)) : null;
        $insertSubmission->execute([$surveyId, $stamp, $duration]);
        $submissionId = (int) $pdo->lastInsertId();

        foreach ($questions as $q) {
            $options = array_values($q['options']);
            // Optional questions are skipped now and then.
            if (!$q['pflicht'] && mt_rand(1, 100) <= ($q['typ'] === 'textarea' ? 55 : 12)) {
                continue;
            }
            switch ($q['typ']) {
                case 'rating':
                    $value = weighted_pick([2, 4, 12, 34, 28]) + 1;
                    $insertAnswer->execute([$q['id'], $submissionId, null, (string) $value, $stamp]);
                    break;
                case 'yesno':
                    $insertAnswer->execute([$q['id'], $submissionId, null, mt_rand(1, 100) <= 78 ? 'Ja' : 'Nein', $stamp]);
                    break;
                case 'single':
                    // Earlier options are a bit more popular.
                    $weights = array_map(fn($n) => max(1, 6 - $n), array_keys($options));
                    $option  = $options[weighted_pick($weights)];
                    $insertAnswer->execute([$q['id'], $submissionId, $option['id'], $option['text'], $stamp]);
                    break;
                case 'multiple':
                    $picked = 0;
                    foreach ($options as $n => $option) {
                        if (mt_rand(1, 100) <= 55 - $n * 7) {
                            $insertAnswer->execute([$q['id'], $submissionId, $option['id'], $option['text'], $stamp]);
                            $picked++;
                        }
                    }
                    if ($picked === 0) {
                        $insertAnswer->execute([$q['id'], $submissionId, $options[0]['id'], $options[0]['text'], $stamp]);
                    }
                    break;
                default:
                    $insertAnswer->execute([$q['id'], $submissionId, null, $freeTexts[mt_rand(0, count($freeTexts) - 1)], $stamp]);
            }
        }
    }
    $pdo->commit();
    $total += $count;
    echo sprintf("  - %-28s %3d submissions\n", $template['title'], $count);
}

echo "Demo account ready: " . DEMO_EMAIL . " / " . DEMO_PASSWORD . " ($total submissions)\n";
