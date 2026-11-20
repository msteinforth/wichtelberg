<?php
/**
 * Wichtelberg – Konfiguration
 * Kopieren nach config.php (liegt außerhalb von public/ und ist nicht per Web erreichbar) und anpassen.
 * Schlüssel erzeugen: php cli.php keys
 */
return [
    'base_url' => 'https://wichtelberg.steinforth.com',
    'organizer_email' => 'orga@example.org',  // erhält Absage-Mails und meldet sich unter /admin an

    'secrets' => [
        'secret_key' => '',    // signiert Links und Cookies (64 Hex-Zeichen)
        'data_key' => '',      // verschlüsselt die Datendateien – GUT AUFBEWAHREN, ohne ihn sind die Daten verloren
        'tasks_token' => '',   // optional: für POST /tasks/run von einem externen Cron-Dienst
    ],

    'data_dir' => 'data',      // relativ zu dieser Datei; nicht innerhalb von public/
    'web_cron_seconds' => 60,  // fällige Aufgaben nach Seitenaufrufen ausführen (0 = nur per Cronjob)

    'mail' => [
        'transport' => 'mail', // "mail" = PHP mail() (Strato), "smtp", oder "log" (nur in data/mail.log schreiben)
        'from' => 'Wichtelberg <wichteln@steinforth.com>',  // Adresse muss zur Domain/zum Postfach passen
        // 'smtp' => ['host' => 'smtp.strato.de', 'port' => 465, 'encryption' => 'ssl', 'username' => 'wichteln@steinforth.com', 'password' => ''],
    ],

    'legal' => [
        'operator' => "Vorname Nachname\nStraße 1, 12345 Ort\nE-Mail: orga@example.org",
        'imprint' => "Angaben gemäß § 5 DDG:\nVorname Nachname\nStraße 1, 12345 Ort\nE-Mail: orga@example.org",
    ],

    // Eine Runde pro Land. URL: base_url/<slug>, z. B. https://wichtelberg.steinforth.com/de
    // country: DE | AT | CH | IT (bestimmt PLZ-Format und Versanddienstleister)
    'rounds' => [
        [
            'slug' => 'de',
            'country' => 'DE',
            'name' => 'Wein-Wichteln Deutschland 2026',
            'description' => 'Eine Flasche im Wert von ca. 15–25 €.',
            'registration_deadline' => '2026-12-01T23:59:00+01:00',
            'shipping_deadline' => '2026-12-10T23:59:00+01:00',
            'reminders' => ['2026-12-08T10:00:00+01:00'],
            'reveal_at' => '2026-12-24T12:00:00+01:00', // optional, Standard: Versandfrist
            'min_participants' => 3,
            'max_participants' => null,
            'data_retention_days' => 30,                // Löschung X Tage nach Versandfrist
        ],
        [
            'slug' => 'at',
            'country' => 'AT',
            'name' => 'Wein-Wichteln Österreich 2026',
            'description' => 'Eine Flasche im Wert von ca. 15–25 €.',
            'registration_deadline' => '2026-12-01T23:59:00+01:00',
            'shipping_deadline' => '2026-12-10T23:59:00+01:00',
            'reminders' => ['2026-12-08T10:00:00+01:00'],
        ],
        [
            'slug' => 'ch',
            'country' => 'CH',
            'name' => 'Wein-Wichteln Schweiz 2026',
            'description' => 'Eine Flasche im Wert von ca. 20–30 CHF.',
            'registration_deadline' => '2026-12-01T23:59:00+01:00',
            'shipping_deadline' => '2026-12-10T23:59:00+01:00',
            'reminders' => ['2026-12-08T10:00:00+01:00'],
        ],
        [
            'slug' => 'it',
            'country' => 'IT',
            'name' => 'Wein-Wichteln Italien 2026',
            'description' => 'Eine Flasche im Wert von ca. 15–25 €.',
            'registration_deadline' => '2026-12-01T23:59:00+01:00',
            'shipping_deadline' => '2026-12-10T23:59:00+01:00',
            'reminders' => ['2026-12-08T10:00:00+01:00'],
        ],
    ],
];
