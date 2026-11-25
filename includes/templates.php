<?php
declare(strict_types=1);
// ============================================================
//  templates.php – ready-made surveys to start from
//
//  Plain PHP data, no database table: templates change with the
//  code, not at runtime. create_survey() turns one into real rows.
//  The texts go through t(): a survey created from a template is
//  written in the language the user is working in.
// ============================================================

/**
 * @return array<string, array{title: string, description: string, hint: string,
 *         questions: array<int, array{text: string, type: string, required?: bool, options?: string[]}>}>
 */
function survey_templates(): array
{
    return [
        'customer' => [
            'title'       => t('Kundenzufriedenheit'),
            'hint'        => t('Wie zufrieden sind Kundinnen und Kunden nach einem Kauf oder Auftrag?'),
            'description' => t('Deine Rückmeldung hilft uns, besser zu werden. Die Umfrage dauert etwa zwei Minuten.'),
            'questions'   => [
                ['text' => t('Wie zufrieden bist du insgesamt mit uns?'), 'type' => 'rating', 'required' => true],
                ['text' => t('Wie bist du auf uns aufmerksam geworden?'), 'type' => 'single',
                 'options' => [t('Empfehlung'), t('Suchmaschine'), t('Soziale Medien'), t('Werbung'), t('Sonstiges')]],
                ['text' => t('Was hat dir besonders gut gefallen?'), 'type' => 'multiple',
                 'options' => [t('Qualität'), t('Preis'), t('Beratung'), t('Schnelligkeit'), t('Erreichbarkeit')]],
                ['text' => t('Würdest du uns weiterempfehlen?'), 'type' => 'yesno', 'required' => true],
                ['text' => t('Was können wir besser machen?'), 'type' => 'textarea'],
            ],
        ],
        'event' => [
            'title'       => t('Event-Feedback'),
            'hint'        => t('Rückmeldungen nach einem Workshop, Meetup oder einer Feier einsammeln.'),
            'description' => t('Danke, dass du dabei warst! Sag uns kurz, wie es dir gefallen hat.'),
            'questions'   => [
                ['text' => t('Wie hat dir die Veranstaltung insgesamt gefallen?'), 'type' => 'rating', 'required' => true],
                ['text' => t('Wie bewertest du die Organisation?'), 'type' => 'rating'],
                ['text' => t('Welche Programmpunkte fandest du am besten?'), 'type' => 'multiple',
                 'options' => [t('Vorträge'), t('Workshops'), t('Austausch in den Pausen'), t('Verpflegung')]],
                ['text' => t('Würdest du beim nächsten Mal wieder teilnehmen?'), 'type' => 'yesno'],
                ['text' => t('Was sollten wir beim nächsten Mal anders machen?'), 'type' => 'textarea'],
            ],
        ],
        'course' => [
            'title'       => t('Lehrveranstaltung bewerten'),
            'hint'        => t('Evaluation für Kurse, Seminare und Vorlesungen.'),
            'description' => t('Die Teilnahme ist anonym. Deine Antworten helfen, die Veranstaltung zu verbessern.'),
            'questions'   => [
                ['text' => t('Wie bewertest du die Veranstaltung insgesamt?'), 'type' => 'rating', 'required' => true],
                ['text' => t('Wie verständlich wurden die Inhalte erklärt?'), 'type' => 'rating'],
                ['text' => t('Wie empfandest du das Tempo?'), 'type' => 'single',
                 'options' => [t('Zu langsam'), t('Genau richtig'), t('Zu schnell')]],
                ['text' => t('Welche Materialien hast du genutzt?'), 'type' => 'multiple',
                 'options' => [t('Skript'), t('Folien'), t('Übungsaufgaben'), t('Aufzeichnungen'), t('Eigene Notizen')]],
                ['text' => t('Was nimmst du aus der Veranstaltung mit?'), 'type' => 'textarea'],
            ],
        ],
        'team' => [
            'title'       => t('Team-Stimmung'),
            'hint'        => t('Kurzer, regelmäßiger Check: Wie geht es dem Team?'),
            'description' => t('Fünf kurze Fragen, anonym ausgewertet.'),
            'questions'   => [
                ['text' => t('Wie zufrieden bist du aktuell mit deiner Arbeit?'), 'type' => 'rating', 'required' => true],
                ['text' => t('Wie hoch ist deine Arbeitsbelastung?'), 'type' => 'single',
                 'options' => [t('Zu niedrig'), t('Angemessen'), t('Hoch'), t('Zu hoch')]],
                ['text' => t('Fühlst du dich im Team gut unterstützt?'), 'type' => 'yesno'],
                ['text' => t('Was lief in den letzten Wochen gut?'), 'type' => 'text'],
                ['text' => t('Was sollte sich ändern?'), 'type' => 'textarea'],
            ],
        ],
    ];
}
