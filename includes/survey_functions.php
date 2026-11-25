<?php
declare(strict_types=1);
// ============================================================
//  survey_functions.php – everything about surveys, questions,
//  submissions and their statistics.
//
//  All SQL that touches survey data lives here (or in the page
//  that is the only user of a query). Rule of the project:
//  every ownership-sensitive query contains  AND nutzer_id = ?
// ============================================================

const QUESTION_TYPES = [
    'text'     => 'Kurzer Text',
    'textarea' => 'Langer Text',
    'single'   => 'Einfachauswahl',
    'multiple' => 'Mehrfachauswahl',
    'rating'   => 'Bewertung 1–5',
    'yesno'    => 'Ja / Nein',
];

const SURVEY_STATUS_LABELS = [
    'draft'    => 'Entwurf',
    'live'     => 'Veröffentlicht',
    'closed'   => 'Beendet',
    'archived' => 'Archiviert',
];

// Longest plausible time for filling in a form; anything above is a
// tab that was left open and would distort the average.
const MAX_COMPLETION_SECONDS = 3 * 3600;

const MAX_OPTIONS_PER_QUESTION = 20;
const MAX_QUESTIONS_PER_SURVEY = 50;

function is_choice_type(string $type): bool
{
    return $type === 'single' || $type === 'multiple';
}

// ---------- Loading ----------

/**
 * Load a survey ONLY if it belongs to the given user (ownership
 * check, protects against IDOR: changing ?id= in the URL leads nowhere).
 * @return array<string, mixed>|null
 */
function find_owned_survey(int $surveyId, int $userId): ?array
{
    $stmt = db()->prepare('SELECT * FROM fragebogen WHERE id = ? AND nutzer_id = ?');
    $stmt->execute([$surveyId, $userId]);
    return $stmt->fetch() ?: null;
}

/**
 * Public lookup by random token. The sequential id is not used in
 * public links any more – ids can be guessed (1, 2, 3 …), tokens cannot.
 * @return array<string, mixed>|null
 */
function find_survey_by_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM fragebogen WHERE token = ?');
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

/**
 * draft  = not published
 * live   = published and (no end date or end date in the future)
 * closed = published, but the end date has passed
 * @param array<string, mixed> $survey
 */
function survey_status(array $survey): string
{
    if (!empty($survey['archiviert_am'])) {
        return 'archived';
    }
    if (!$survey['veroeffentlicht']) {
        return 'draft';
    }
    if ($survey['endet_am'] !== null && strtotime($survey['endet_am']) <= time()) {
        return 'closed';
    }
    // Response limit reached. Lists pass the number of submissions along
    // as `teilnahmen`; without it the limit cannot be judged here.
    if (!empty($survey['max_teilnahmen']) && isset($survey['teilnahmen'])
        && (int) $survey['teilnahmen'] >= (int) $survey['max_teilnahmen']) {
        return 'closed';
    }
    return 'live';
}

function survey_response_count(int $surveyId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM teilnahme WHERE fragebogen_id = ?');
    $stmt->execute([$surveyId]);
    return (int) $stmt->fetchColumn();
}

// ---------- Access code ----------
// A survey can ask participants for a code (a shared password, e.g.
// written on the invitation). Stored like a password: only its hash.

/**
 * Value of the cookie that remembers "this browser entered the code".
 * It depends on the stored hash: changing the code locks everybody out again.
 *
 * @param array<string, mixed> $survey
 */
function survey_code_cookie(array $survey): string
{
    return hash_hmac('sha256', 'code|' . $survey['id'] . '|' . $survey['zugangscode_hash'], app_secret());
}

/** @param array<string, mixed> $survey */
function survey_code_unlocked(array $survey): bool
{
    if (empty($survey['zugangscode_hash'])) {
        return true;
    }
    $cookie = $_COOKIE['qp_code_' . (int) $survey['id']] ?? '';
    return is_string($cookie) && hash_equals(survey_code_cookie($survey), $cookie);
}

// ---------- Completion time ----------
// The form carries the moment it was opened, signed with the app
// secret. A participant cannot fake a time: without the secret the
// signature does not match and the duration is simply not stored.

function survey_started_token(int $surveyId, ?int $time = null): string
{
    $time ??= time();
    return $time . '.' . hash_hmac('sha256', 'start|' . $surveyId . '|' . $time, app_secret());
}

/** Seconds since the form was opened, or null if the token is missing, forged or implausible. */
function survey_duration(int $surveyId, string $token, ?int $now = null): ?int
{
    if (!preg_match('/^(\d{9,11})\.([a-f0-9]{64})$/', $token, $m)) {
        return null;
    }
    if (!hash_equals(hash_hmac('sha256', 'start|' . $surveyId . '|' . $m[1], app_secret()), $m[2])) {
        return null;
    }
    $seconds = ($now ?? time()) - (int) $m[1];
    return $seconds >= 1 && $seconds <= MAX_COMPLETION_SECONDS ? $seconds : null;
}

/** "2 Min. 5 Sek." – a duration for people. */
function format_duration(?float $seconds): string
{
    if ($seconds === null) {
        return '–';
    }
    $seconds = (int) round($seconds);
    if ($seconds < 60) {
        return t('{s} Sek.', ['s' => $seconds]);
    }
    $minutes = intdiv($seconds, 60);
    $rest    = $seconds % 60;
    return $rest === 0 || $minutes >= 10
        ? t('{m} Min.', ['m' => $minutes + ($minutes >= 10 && $rest >= 30 ? 1 : 0)])
        : t('{m} Min. {s} Sek.', ['m' => $minutes, 's' => $rest]);
}

/**
 * Typical completion time of a survey: the MEDIAN, because one person
 * who went for coffee would pull an average far up.
 *
 * @return array{median: ?float, count: int}
 */
function completion_time(int $surveyId, ?string $since = null): array
{
    $sql    = 'SELECT dauer_sekunden FROM teilnahme WHERE fragebogen_id = ? AND dauer_sekunden IS NOT NULL';
    $params = [$surveyId];
    if ($since !== null) {
        $sql     .= ' AND abgegeben_am >= ?';
        $params[] = $since;
    }
    $stmt = db()->prepare($sql . ' ORDER BY dauer_sekunden LIMIT 20000');
    $stmt->execute($params);
    $values = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    return ['median' => median($values), 'count' => count($values)];
}

/** @param list<int|float> $sorted values in ascending order */
function median(array $sorted): ?float
{
    $n = count($sorted);
    if ($n === 0) {
        return null;
    }
    $middle = intdiv($n, 2);
    return $n % 2 === 1 ? (float) $sorted[$middle] : ($sorted[$middle - 1] + $sorted[$middle]) / 2;
}

/**
 * Requests that should not count as "someone looked at the survey":
 * crawlers, link previews of messengers, browser prefetching.
 * A heuristic on headers the client sends – good enough to keep the
 * view counter honest, not a security measure.
 */
function is_automated_request(): bool
{
    $agent = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($agent === '' || preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|whatsapp|telegram|slack|discord|embedly|curl|wget|python-requests|headless|monitor|uptime/', $agent)) {
        return true;
    }
    $purpose = strtolower(($_SERVER['HTTP_SEC_PURPOSE'] ?? '') . ' ' . ($_SERVER['HTTP_PURPOSE'] ?? '') . ' ' . ($_SERVER['HTTP_X_PURPOSE'] ?? '') . ' ' . ($_SERVER['HTTP_X_MOZ'] ?? ''));
    return str_contains($purpose, 'prefetch') || str_contains($purpose, 'preview');
}

/** @param array<string, mixed> $survey */
function survey_public_url(array $survey): string
{
    return base_url() . '/survey.php?t=' . $survey['token'];
}

/**
 * All questions of a survey, each with its answer options.
 * Two queries in total (questions, then all options at once) –
 * not one options query per question (the classic N+1 problem).
 *
 * @return array<int, array<string, mixed>>
 */
function load_questions(int $surveyId): array
{
    $stmt = db()->prepare('SELECT * FROM frage WHERE fragebogen_id = ? ORDER BY reihenfolge, id');
    $stmt->execute([$surveyId]);

    $questions = [];
    foreach ($stmt->fetchAll() as $row) {
        $row['options'] = [];
        $questions[(int) $row['id']] = $row;
    }

    if ($questions) {
        $stmt = db()->prepare(
            'SELECT o.* FROM antwortoption o
             JOIN frage f ON f.id = o.frage_id
             WHERE f.fragebogen_id = ?
             ORDER BY o.reihenfolge, o.id'
        );
        $stmt->execute([$surveyId]);
        foreach ($stmt->fetchAll() as $option) {
            $questions[(int) $option['frage_id']]['options'][(int) $option['id']] = $option;
        }
    }

    return array_values($questions);
}

// ---------- Creating and changing ----------

/**
 * Turn the "one option per line" textarea into a clean list:
 * trimmed, no empty lines, no duplicates, limited in number and length.
 * @return string[]
 */
function parse_option_lines(string $raw): array
{
    $options = [];
    foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
        $line = mb_substr(trim($line), 0, 255);
        if ($line !== '' && !in_array($line, $options, true)) {
            $options[] = $line;
        }
    }
    return array_slice($options, 0, MAX_OPTIONS_PER_QUESTION);
}

/**
 * Insert a question (and its options) at the end of a survey.
 * @param string[] $options
 */
function add_question(int $surveyId, string $text, string $type, bool $required, array $options = []): int
{
    $pdo = db();

    // Next position = highest existing position + 1.
    // COALESCE turns NULL (no questions yet) into 0.
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(reihenfolge), 0) + 1 FROM frage WHERE fragebogen_id = ?');
    $stmt->execute([$surveyId]);
    $position = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        'INSERT INTO frage (fragebogen_id, text, typ, pflicht, reihenfolge) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$surveyId, $text, $type, (int) $required, $position]);
    $questionId = (int) $pdo->lastInsertId();

    if (is_choice_type($type)) {
        add_options($questionId, $options);
    }

    return $questionId;
}

/** @param string[] $options */
function add_options(int $questionId, array $options): void
{
    $pdo = db();

    $stmt = $pdo->prepare('SELECT COALESCE(MAX(reihenfolge), 0), COUNT(*) FROM antwortoption WHERE frage_id = ?');
    $stmt->execute([$questionId]);
    [$position, $existing] = array_map('intval', $stmt->fetch(PDO::FETCH_NUM));

    // Prepare ONCE, execute several times inside the loop.
    $insert = $pdo->prepare('INSERT INTO antwortoption (frage_id, text, reihenfolge) VALUES (?, ?, ?)');
    foreach ($options as $text) {
        if ($existing >= MAX_OPTIONS_PER_QUESTION) {
            break;
        }
        $insert->execute([$questionId, $text, ++$position]);
        $existing++;
    }
}

/**
 * Create a survey, optionally with a set of questions (templates,
 * duplicates). Runs in ONE transaction: either the survey exists
 * completely or not at all – never half a template.
 *
 * @param array<int, array{text: string, type: string, required?: bool, options?: string[]}> $questions
 */
function create_survey(int $userId, string $title, string $description, array $questions = []): int
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        // nutzer_id comes from the SESSION, never from the form –
        // so nobody can create surveys for somebody else.
        $stmt = $pdo->prepare(
            'INSERT INTO fragebogen (nutzer_id, titel, beschreibung, token) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $title, $description, bin2hex(random_bytes(16))]);
        $surveyId = (int) $pdo->lastInsertId();

        foreach ($questions as $q) {
            add_question($surveyId, $q['text'], $q['type'], $q['required'] ?? false, $q['options'] ?? []);
        }

        $pdo->commit();
        return $surveyId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Copy a survey with all questions and options (without answers).
 * The copy always starts as a draft.
 * @param array<string, mixed> $survey
 */
function duplicate_survey(array $survey, int $userId): int
{
    $questions = [];
    foreach (load_questions((int) $survey['id']) as $q) {
        $questions[] = [
            'text'     => $q['text'],
            'type'     => $q['typ'],
            'required' => (bool) $q['pflicht'],
            'options'  => array_column($q['options'], 'text'),
        ];
    }

    $title = mb_substr($survey['titel'], 0, 240) . ' ' . t('(Kopie)');
    return create_survey($userId, $title, (string) $survey['beschreibung'], $questions);
}

/**
 * What still prevents this survey from being published?
 * @param array<int, array<string, mixed>> $questions
 * @return string[] problems (empty = ready)
 */
function publish_problems(array $questions): array
{
    $problems = [];
    if (!$questions) {
        $problems[] = t('Füge mindestens eine Frage hinzu, bevor du veröffentlichst.');
    }
    foreach ($questions as $i => $q) {
        if (is_choice_type($q['typ']) && count($q['options']) < 2) {
            $problems[] = t('Frage {n} braucht mindestens zwei Antwortoptionen.', ['n' => $i + 1]);
        }
    }
    return $problems;
}

// ---------- Submissions (public form) ----------

/**
 * Pseudonymous fingerprint of a participant for ONE survey:
 * HMAC(survey id + IP + browser). Different per survey, so answers
 * cannot be linked across surveys; not reversible without APP_SECRET.
 */
function participant_hash(int $surveyId): string
{
    $agent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300);
    return hash_hmac('sha256', $surveyId . '|' . client_ip() . '|' . $agent, app_secret());
}

function has_participated(int $surveyId, string $hash): bool
{
    $stmt = db()->prepare('SELECT 1 FROM teilnahme WHERE fragebogen_id = ? AND teilnehmer_hash = ? LIMIT 1');
    $stmt->execute([$surveyId, $hash]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Check the submitted form against the survey's REAL questions from
 * the database. The client's array is only used as a lookup – nobody
 * can smuggle in answers for foreign questions or invented options.
 *
 * @param array<int, array<string, mixed>> $questions
 * @param array<mixed>                     $input     $_POST['answers']
 * @return array{0: array<int, array{question_id: int, option_id: ?int, text: string}>, 1: array<int, string>}
 *         [answers ready to insert, error message per question id]
 */
function validate_submission(array $questions, array $input): array
{
    $answers = [];
    $errors  = [];

    foreach ($questions as $q) {
        $qid = (int) $q['id'];
        $raw = $input[$qid] ?? null;
        $found = [];   // answers of THIS question

        switch ($q['typ']) {
            case 'single':
                // The form sends the option id; it must exist for this question.
                if (is_string($raw) && isset($q['options'][(int) $raw])) {
                    $option  = $q['options'][(int) $raw];
                    $found[] = ['question_id' => $qid, 'option_id' => (int) $option['id'], 'text' => $option['text']];
                }
                break;

            case 'multiple':
                foreach (is_array($raw) ? array_unique($raw) : [] as $value) {
                    if (is_string($value) && isset($q['options'][(int) $value])) {
                        $option  = $q['options'][(int) $value];
                        $found[] = ['question_id' => $qid, 'option_id' => (int) $option['id'], 'text' => $option['text']];
                    }
                }
                break;

            case 'rating':
                if (is_string($raw) && in_array($raw, ['1', '2', '3', '4', '5'], true)) {
                    $found[] = ['question_id' => $qid, 'option_id' => null, 'text' => $raw];
                }
                break;

            case 'yesno':
                if ($raw === 'ja' || $raw === 'nein') {
                    $found[] = ['question_id' => $qid, 'option_id' => null, 'text' => $raw === 'ja' ? 'Ja' : 'Nein'];
                }
                break;

            default:   // text, textarea
                $limit = $q['typ'] === 'textarea' ? 5000 : 500;
                $text  = is_string($raw) ? mb_substr(trim($raw), 0, $limit) : '';
                if ($text !== '') {
                    $found[] = ['question_id' => $qid, 'option_id' => null, 'text' => $text];
                }
        }

        if (!$found && $q['pflicht']) {
            $errors[$qid] = t('Diese Frage ist ein Pflichtfeld.');
        }
        array_push($answers, ...$found);
    }

    return [$answers, $errors];
}

/**
 * Store one submission with all its answers – in a transaction, so a
 * failure in the middle never leaves half a submission behind.
 * @param array<int, array{question_id: int, option_id: ?int, text: string}> $answers
 */
function save_submission(int $surveyId, array $answers, ?string $participantHash, ?int $durationSeconds = null): int
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare('INSERT INTO teilnahme (fragebogen_id, teilnehmer_hash, dauer_sekunden) VALUES (?, ?, ?)');
        $stmt->execute([$surveyId, $participantHash, $durationSeconds]);
        $submissionId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO antwort (frage_id, teilnahme_id, option_id, antwort_text) VALUES (?, ?, ?, ?)'
        );
        foreach ($answers as $a) {
            $stmt->execute([$a['question_id'], $submissionId, $a['option_id'], $a['text']]);
        }

        $pdo->commit();
        return $submissionId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ---------- Statistics ----------

/**
 * Submissions per day for one user (optionally one survey), with
 * zero-filled gaps – a chart needs every day, also the empty ones.
 *
 * @return array<string, int> 'Y-m-d' => count, ascending
 */
function responses_per_day(int $userId, int $days, ?int $surveyId = null): array
{
    $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));

    $sql = 'SELECT DATE(t.abgegeben_am) AS tag, COUNT(*) AS anzahl
            FROM teilnahme t
            JOIN fragebogen f ON f.id = t.fragebogen_id
            WHERE f.nutzer_id = ? AND t.abgegeben_am >= ?';
    $params = [$userId, $from . ' 00:00:00'];
    if ($surveyId !== null) {
        $sql .= ' AND f.id = ?';
        $params[] = $surveyId;
    }
    $stmt = db()->prepare($sql . ' GROUP BY tag');
    $stmt->execute($params);
    $counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $series = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime("-$i days"));
        $series[$day] = (int) ($counts[$day] ?? 0);
    }
    return $series;
}

/**
 * Aggregated results per question – a handful of GROUP BY queries for
 * the whole survey instead of one query per question.
 *
 * @param array<int, array<string, mixed>> $questions from load_questions()
 * @param string|null $since only submissions after this datetime
 * @return array<int, array<string, mixed>> keyed by question id
 */
function question_stats(int $surveyId, array $questions, ?string $since = null, int $textLimit = 5): array
{
    $pdo = db();

    $where  = 't.fragebogen_id = ?';
    $params = [$surveyId];
    if ($since !== null) {
        $where   .= ' AND t.abgegeben_am >= ?';
        $params[] = $since;
    }

    // 1. How many PEOPLE answered each question (base for percentages;
    //    for multiple choice this differs from the number of ticks).
    $stmt = $pdo->prepare(
        "SELECT a.frage_id, COUNT(DISTINCT a.teilnahme_id)
         FROM antwort a JOIN teilnahme t ON t.id = a.teilnahme_id
         WHERE $where GROUP BY a.frage_id"
    );
    $stmt->execute($params);
    $respondents = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // 2. Choice questions: ticks per option
    $stmt = $pdo->prepare(
        "SELECT a.frage_id, a.option_id, COUNT(*) AS anzahl
         FROM antwort a
         JOIN teilnahme t ON t.id = a.teilnahme_id
         JOIN frage f ON f.id = a.frage_id
         WHERE $where AND f.typ IN ('single', 'multiple')
         GROUP BY a.frage_id, a.option_id"
    );
    $stmt->execute($params);
    $optionCounts = [];
    foreach ($stmt->fetchAll() as $row) {
        $optionCounts[(int) $row['frage_id']][(int) ($row['option_id'] ?? 0)] = (int) $row['anzahl'];
    }

    // 3. Rating and yes/no: count per value
    $stmt = $pdo->prepare(
        "SELECT a.frage_id, a.antwort_text, COUNT(*) AS anzahl
         FROM antwort a
         JOIN teilnahme t ON t.id = a.teilnahme_id
         JOIN frage f ON f.id = a.frage_id
         WHERE $where AND f.typ IN ('rating', 'yesno')
         GROUP BY a.frage_id, a.antwort_text"
    );
    $stmt->execute($params);
    $valueCounts = [];
    foreach ($stmt->fetchAll() as $row) {
        $valueCounts[(int) $row['frage_id']][$row['antwort_text']] = (int) $row['anzahl'];
    }

    // 4. Free text: the newest answers (prepared once, run per text question)
    $textStmt = $pdo->prepare(
        "SELECT a.antwort_text, t.abgegeben_am
         FROM antwort a JOIN teilnahme t ON t.id = a.teilnahme_id
         WHERE $where AND a.frage_id = ?
         ORDER BY t.abgegeben_am DESC, a.id DESC
         LIMIT " . max(1, $textLimit)
    );

    $stats = [];
    foreach ($questions as $q) {
        $qid  = (int) $q['id'];
        $stat = ['respondents' => (int) ($respondents[$qid] ?? 0)];

        if (is_choice_type($q['typ'])) {
            $stat['options'] = [];
            foreach ($q['options'] as $optionId => $option) {
                $stat['options'][] = [
                    'label' => $option['text'],
                    'count' => $optionCounts[$qid][$optionId] ?? 0,
                ];
            }
            // option_id NULL (key 0) = the option was deleted later
            $stat['removed'] = $optionCounts[$qid][0] ?? 0;

        } elseif ($q['typ'] === 'rating') {
            $distribution = [];
            $sum = 0;
            $n   = 0;
            for ($value = 1; $value <= 5; $value++) {
                $count = $valueCounts[$qid][(string) $value] ?? 0;
                $distribution[$value] = $count;
                $sum += $value * $count;
                $n   += $count;
            }
            $stat['distribution'] = $distribution;
            $stat['average']      = $n > 0 ? $sum / $n : null;

        } elseif ($q['typ'] === 'yesno') {
            $stat['yes'] = $valueCounts[$qid]['Ja'] ?? 0;
            $stat['no']  = $valueCounts[$qid]['Nein'] ?? 0;

        } else {
            $textStmt->execute([...$params, $qid]);
            $stat['texts'] = $textStmt->fetchAll();
        }

        $stats[$qid] = $stat;
    }

    return $stats;
}
