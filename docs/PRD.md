# PRD: Wein-Wichteln (Arbeitstitel „Wichtelberg“)

| | |
|---|---|
| **Status** | v0.3: schlanke Architektur ohne Datenbank, Länder-Runden DE/AT/CH/IT, optionale Aufdeckung |
| **URL** | https://wichtelberg.steinforth.com |
| **Datum** | 20.11.2026 |
| **Produkttyp** | Web-Anwendung (responsive, mobile-first) |
| **Sprache** | Deutsch |

---

## 1. Zusammenfassung

Wichtelberg ist eine Web-Anwendung zur Organisation eines Wein-Wichtelns per Post. Teilnehmer melden sich bis zu einer festen Frist mit E-Mail-Adresse und einer Lieferadresse an. Es gibt je eine Runde pro Land (Deutschland, Österreich, Schweiz, Italien); innerhalb einer Runde wird nur im Inland verschickt. Nach Ablauf der Frist lost das System jedem Teilnehmer automatisch einen Wichtelpartner zu. Jeder Teilnehmer verschickt eine Flasche Wein an seinen Partner und hinterlegt Versanddienstleister und Sendungsnummer. Der Empfänger erhält daraufhin eine E-Mail mit dem Tracking-Link. Fehlt die Sendungsnummer bis zu einem Stichtag, verschickt das System eine Erinnerung.

**Leitprinzip: schlank.** Die App kommt ohne Datenbank aus. Die Runden werden in einer Konfigurationsdatei definiert; jede Runde hat eine eigene URL (`/de`, `/at`, `/ch`, `/it`) und eine eigene verschlüsselte JSON-Datei (siehe Abschnitt 7).

## 2. Problem & Ziele

### Problem
Ein Wichteln per Post mit Listen, Losen und Gruppen-Chats ist aufwendig: Jemand muss Adressen sammeln, fair und geheim auslosen, Adressen vertraulich weitergeben und nachhalten, wer schon verschickt hat.

### Ziele
1. **Selbstständige Anmeldung** ohne Zutun des Organisators.
2. **Faire, geheime und automatische Auslosung** zum Fristende.
3. **Transparenz beim Versand:** Empfänger wissen, dass ein Paket unterwegs ist, und können es verfolgen.
4. **Wenig Nacharbeit** für den Organisator durch automatische Erinnerungen.
5. **Datensparsamkeit** und DSGVO-Konformität.

### Nicht-Ziele (für v1)
- Kein grenzüberschreitender Versand: Jede Runde ist auf ein Land beschränkt (DE, AT, CH, IT).
- Keine Bezahl- oder Shop-Funktion (kein Weinkauf über die Plattform).
- Keine Integration von Carrier-APIs zum Abruf des Sendungsstatus (nur Deep-Link auf die Tracking-Seite).
- Keine Chat-Funktion zwischen Wichtelpartnern.
- Keine nativen Apps.
- Keine Datenbank (weder SQL noch NoSQL-Server), kein separates Admin-Backend zum Anlegen von Runden.
- Keine Mandantenfähigkeit: ein Organisator, mehrere Länder-Runden pro Instanz.

### Erfolgskennzahlen
| Kennzahl | Ziel |
|---|---|
| Anteil der Teilnehmer mit E-Mail-Bestätigung | ≥ 90 % |
| Anteil Sendungsnummern bis zur Versandfrist | ≥ 85 % |
| Anteil Sendungsnummern nach Erinnerung | ≥ 95 % |
| Manuelle Eingriffe des Organisators je Runde | ≤ 3 |

## 3. Nutzerrollen

| Rolle | Beschreibung |
|---|---|
| **Teilnehmer** | Meldet sich an, verschickt Wein an den zugelosten Partner, empfängt Wein. Gleichzeitig Schenkender und Beschenkter. |
| **Organisator (Admin)** | Legt eine Wichtelrunde an, definiert Fristen, überwacht den Status, greift bei Problemen ein. |
| **System** | Führt Auslosung, E-Mail-Versand und Erinnerungen zeitgesteuert aus. |

## 4. Ablauf (Happy Path)

```
[Organisator legt Runde an]
        │
        ▼
Phase 1: ANMELDUNG ─────────── bis Anmeldeschluss (z. B. 01.12., 23:59 Uhr)
  Teilnehmer registriert sich → bestätigt E-Mail (Double-Opt-in)
        │
        ▼  (automatisch zum Anmeldeschluss)
Phase 2: AUSLOSUNG
  System lost Partner zu → jeder erhält E-Mail mit Name + Adresse seines Partners
        │
        ▼
Phase 3: VERSAND ───────────── bis Versandfrist (z. B. 10.12.)
  Teilnehmer verschickt Wein → trägt Versanddienstleister + Sendungsnummer ein
  → Empfänger erhält E-Mail „Dein Wein ist unterwegs“ mit Tracking-Link
  Keine Sendungsnummer bis Erinnerungszeitpunkt → Erinnerungs-E-Mail
        │
        ▼
Phase 4: ABSCHLUSS
  Runde abgeschlossen → personenbezogene Daten werden nach Frist gelöscht
```

## 5. Funktionale Anforderungen

### 5.1 Wichtelrunde konfigurieren (Organisator)

| ID | Anforderung | Priorität |
|---|---|---|
| R-1 | Die Runde wird in einer Konfigurationsdatei (z. B. `config.yaml`) bzw. über Umgebungsvariablen definiert: Name, Beschreibung (z. B. Budget-Empfehlung „ca. 15–25 €“), Anmeldeschluss (Datum + Uhrzeit, Zeitzone Europe/Berlin), Versandfrist, Erinnerungszeitpunkt(e), Mindestteilnehmerzahl, E-Mail-Adresse des Organisators. | Muss |
| R-2 | Beim Start prüft die App die Konfiguration: Versandfrist nach Anmeldeschluss; Erinnerung zwischen Anmeldeschluss und Versandfrist. Ist die Konfiguration ungültig, startet die App nicht und meldet den Fehler. | Muss |
| R-3 | Die Anmeldeseite ist unter der Start-URL der Instanz erreichbar, die der Organisator teilen kann. | Muss |
| R-4 | Optional: Anmeldung nur mit Einladungscode (in der Konfiguration hinterlegt) oder nur für bestimmte E-Mail-Domains. | Kann |
| R-5 | Optional: maximale Teilnehmerzahl. | Kann |
| R-6 | Fristen können vor dem Anmeldeschluss durch Ändern der Konfiguration und Neustart angepasst werden. | Kann |

### 5.2 Anmeldung (Teilnehmer)

| ID | Anforderung | Priorität |
|---|---|---|
| A-1 | Anmeldeformular mit Pflichtfeldern: Vorname, Nachname, E-Mail-Adresse, Straße, Hausnummer, PLZ, Ort. Optional: Adresszusatz (c/o, Etage, Firma), Hinweis für den Wichtel (z. B. „lieber Rotwein“, „kein Riesling“). | Muss |
| A-2 | Das Land ist durch die Runde festgelegt. PLZ-Validierung je Land: DE und IT 5 Ziffern, AT und CH 4 Ziffern. In Italien ist das Provinzkürzel (2 Buchstaben) Pflicht. | Muss |
| A-3 | Packstation/Postfiliale ist zulässig (Felder: Postnummer, Packstation-Nr.), mit Hinweis, dass nicht alle Versanddienstleister an Packstationen liefern. | Soll |
| A-4 | Pflicht-Checkboxen: Bestätigung „Ich bin mindestens 18 Jahre alt“ sowie Zustimmung zur Datenschutzerklärung und zur Weitergabe von Name und Adresse an den zugelosten Partner. | Muss |
| A-5 | **Double-Opt-in:** Nach dem Absenden erhält der Teilnehmer eine E-Mail mit Bestätigungslink. Nur bestätigte Teilnehmer nehmen an der Auslosung teil. Bestätigungslink ist 48 h bzw. bis zum Anmeldeschluss gültig. | Muss |
| A-6 | Eine E-Mail-Adresse kann pro Runde nur einmal angemeldet werden (Vergleich case-insensitive). Bei erneuter Anmeldung wird ein neuer Login-Link gesendet, ohne preiszugeben, ob die Adresse bereits existiert. | Muss |
| A-7 | Nach Anmeldeschluss ist das Formular gesperrt und zeigt „Anmeldung geschlossen“. | Muss |
| A-8 | Die Startseite der Runde zeigt einen Countdown bis zum Anmeldeschluss sowie die Anzahl bestätigter Teilnehmer (ohne Namen). | Soll |
| A-9 | Teilnehmer können bis zum Anmeldeschluss ihre Daten ändern und sich abmelden. | Muss |

### 5.3 Authentifizierung

| ID | Anforderung | Priorität |
|---|---|---|
| L-1 | Kein Passwort. Zugang über **Magic Link** per E-Mail. Die Links sind **zustandslos signiert** (HMAC mit Server-Secret, enthalten Teilnehmer-ID und Ablaufzeit), sodass keine Token gespeichert werden müssen. Gültigkeit 30 Min.; danach Sitzung per signiertem Cookie (30 Tage). | Muss |
| L-2 | Alle E-Mails des Systems enthalten einen persönlichen Link zum Teilnehmer-Dashboard. | Muss |
| L-3 | Organisator-Zugang per Magic Link an die konfigurierte Organisator-E-Mail-Adresse. | Muss |

### 5.4 Auslosung

| ID | Anforderung | Priorität |
|---|---|---|
| D-1 | Die Auslosung startet automatisch zum Anmeldeschluss. Zeitgesteuerte Aufgaben laufen über einen einfachen In-Process-Scheduler oder einen Cron-Aufruf eines geschützten Endpunkts (z. B. stündlich `POST /tasks/run`), der alle fälligen Aufgaben anhand der Fristen und des gespeicherten Status ausführt. | Muss |
| D-2 | Nur bestätigte Teilnehmer nehmen teil. Unbestätigte Anmeldungen werden verworfen. | Muss |
| D-3 | Niemand wird sich selbst zugelost. | Muss |
| D-4 | Algorithmus: zufällige Permutation der Teilnehmer, die als **ein einziger Kreis** verkettet wird (A → B → C → … → A). Damit funktioniert die Auslosung bei jeder Teilnehmerzahl ≥ 2, es gibt keine Selbstzuordnung und ab 3 Teilnehmern keine gegenseitigen Paare (A beschenkt B und B beschenkt A). Kryptografisch sicherer Zufallsgenerator. | Muss |
| D-5 | Mindestens **3** bestätigte Teilnehmer. Wird das Minimum verfehlt, findet keine Auslosung statt; Teilnehmer und Organisator werden informiert. Der Organisator kann die Frist in der Konfiguration verlängern. | Muss |
| D-6 | Optional: Ausschlussregeln (z. B. Paare/Haushalte, die sich nicht gegenseitig beschenken sollen). Bei Ausschlüssen wird eine gültige Kreiszuordnung per Zufall mit Wiederholung bzw. Backtracking gesucht; ist keine möglich, wird der Organisator informiert. | Kann |
| D-7 | Die Auslosung ist idempotent: Ist in der Datendatei bereits eine Zuordnung vorhanden, wird nicht erneut ausgelost. | Muss |
| D-8 | Nach der Auslosung erhält jeder Teilnehmer eine E-Mail mit Vor- und Nachname, Lieferadresse und ggf. Hinweis seines Partners sowie der Versandfrist. | Muss |
| D-9 | Die Zuordnung ist geheim: Empfänger erfahren nicht, wer sie beschenkt. Auch der Organisator sieht die Zuordnung standardmäßig nicht (nur im Ausnahmefall, siehe 5.7). | Muss |

### 5.5 Versand & Sendungsnummer

| ID | Anforderung | Priorität |
|---|---|---|
| V-1 | Im Dashboard sieht der Teilnehmer Adresse und Hinweis seines Partners sowie ein Formular „Versand melden“. | Muss |
| V-2 | Pflichtfelder: Versanddienstleister und Sendungsnummer. Auswahl je Land: **DE** DHL, Hermes, DPD, GLS, UPS · **AT** Österreichische Post, DPD, GLS, DHL, UPS · **CH** Die Schweizerische Post, Planzer Paket, DPD, DHL, UPS, GLS · **IT** Poste Italiane, BRT, SDA, GLS, DHL, UPS. | Muss |
| V-3 | Formatprüfung der Sendungsnummer je Dienstleister (Leerzeichen entfernen, Großbuchstaben). Eine nicht passende Nummer erzeugt einen Warnhinweis und kann bestätigt werden, statt hart abgelehnt zu werden, da Formate sich ändern. Richtwerte: siehe Anhang A. | Soll |
| V-4 | Das System erzeugt aus Dienstleister und Nummer einen Tracking-Link (siehe Anhang A). | Muss |
| V-5 | Nach dem Speichern erhält der **Empfänger** eine E-Mail „Dein Wichtel-Wein ist unterwegs 🍷“ mit Versanddienstleister, Sendungsnummer und Tracking-Link, **ohne** Namen des Absenders. | Muss |
| V-6 | Der Absender kann Dienstleister und Nummer korrigieren. Bei Korrektur erhält der Empfänger eine aktualisierte E-Mail. | Soll |
| V-7 | Die Sendungsnummer kann auch nach Ablauf der Versandfrist noch eingetragen werden. | Muss |
| V-8 | Hinweis im Dashboard zum sicheren Weinversand (bruchsicher verpacken, Flaschenkarton, ggf. „Zerbrechlich“). Hinweis, dass als Absender auf dem Paket auch eine neutrale Angabe möglich ist, sofern der Dienstleister das zulässt. | Kann |

### 5.6 Erinnerungen & Benachrichtigungen

| ID | Anforderung | Priorität |
|---|---|---|
| E-1 | Hat ein Teilnehmer zum Erinnerungszeitpunkt (Standard: 2 Tage vor Versandfrist) keine Sendungsnummer hinterlegt, erhält er eine Erinnerungs-E-Mail mit direktem Link zum Formular. | Muss |
| E-2 | Optional zweite Erinnerung am Tag nach Ablauf der Versandfrist. | Soll |
| E-3 | Erinnerungen werden pro Teilnehmer und Typ höchstens einmal versendet. Dafür wird am Teilnehmer in der Datendatei vermerkt, welche Erinnerungen bereits verschickt wurden. | Muss |
| E-4 | Nach der letzten Erinnerung ohne Sendungsnummer erhält der Organisator eine Übersicht der säumigen Teilnehmer. | Soll |
| E-5 | Optional: Empfänger kann im Dashboard „Wein angekommen“ bestätigen; der Absender erhält daraufhin eine anonyme Dankes-E-Mail. | Kann |
| E-6 | **Aufdeckung:** Der Schenkende entscheidet im Dashboard (bis zum Aufdeckungszeitpunkt änderbar), ob der Empfänger erfahren darf, wer ihn beschenkt hat. Zum Aufdeckungszeitpunkt (`reveal_at`, Standard: Versandfrist) erhält der Empfänger eine E-Mail mit dem Namen, sofern zugestimmt und Versand gemeldet wurde. Standard: geheim. | Muss |

Übersicht aller E-Mails:

| # | Auslöser | Empfänger | Inhalt |
|---|---|---|---|
| M-1 | Anmeldung abgeschickt | Teilnehmer | Bestätigungslink (Double-Opt-in) |
| M-2 | Anmeldung bestätigt | Teilnehmer | Bestätigung, Termine, Dashboard-Link |
| M-3 | Login angefordert | Teilnehmer/Admin | Magic Link |
| M-5 | Auslosung erfolgt | Teilnehmer | Partner (Name, Adresse, Hinweis), Versandfrist |
| M-6 | Auslosung nicht möglich | Teilnehmer, Admin | Zu wenig Teilnehmer, weiteres Vorgehen |
| M-7 | Sendungsnummer eingetragen/geändert | Empfänger | Dienstleister, Nummer, Tracking-Link |
| M-8 | Erinnerungszeitpunkt ohne Sendungsnummer | Absender | Erinnerung mit Link zum Formular |
| M-9 | Letzte Erinnerung erfolglos | Admin | Liste säumiger Teilnehmer |
| M-10 | Teilnehmer fällt aus (siehe 5.7) | Betroffene | Neue Zuordnung |
| M-11 | Aufdeckungszeitpunkt, Schenkender hat zugestimmt | Empfänger | Name des Schenkenden |

### 5.7 Organisator-Ansicht

Bewusst minimal gehalten: eine einzige, geschützte Statusseite.

| ID | Anforderung | Priorität |
|---|---|---|
| O-1 | Statusübersicht: Anzahl angemeldet / bestätigt / Versand gemeldet / angekommen. | Muss |
| O-2 | Teilnehmerliste mit Status (bestätigt, Versand gemeldet ja/nein, Erinnerungen versendet), **ohne** Anzeige der Zuordnung. | Muss |
| O-3 | Manuelle Erinnerung an einzelne Teilnehmer auslösen. | Kann |
| O-4 | **Ausfall eines Teilnehmers nach der Auslosung:** Organisator entfernt Teilnehmer X. Im Kreis … → W → X → Y → … wird W direkt Y zugeordnet (W erhält M-10 mit neuer Adresse). Hat X bereits verschickt oder W bereits an X verschickt, wird der Organisator darauf hingewiesen. | Soll |
| O-5 | Die Zuordnung ist nicht über die Oberfläche einsehbar. Im Notfall kann der Betreiber sie der Datendatei entnehmen. | Muss |

## 6. Nicht-funktionale Anforderungen

### 6.1 Datenschutz & Recht
- **DSGVO:** Datenschutzerklärung und Impressum. Rechtsgrundlage: Einwilligung (Art. 6 Abs. 1 lit. a) für die Weitergabe der Adresse an genau einen anderen Teilnehmer.
- **Datensparsamkeit:** Es werden nur Daten erhoben, die für den Versand nötig sind. Kein Tracking, keine Analyse-Cookies; nur technisch notwendige Cookies.
- **Löschkonzept:** Adressen, Sendungsnummern und Zuordnungen werden automatisch **30 Tage nach Versandfrist** gelöscht; der Wert ist konfigurierbar (`data_retention_days`). Teilnehmer können vor der Auslosung jederzeit selbst löschen.
- **Hosting & E-Mail-Versand** in der EU, mit Auftragsverarbeitungsvertrag.
- **Löschung technisch einfach:** Nach Ablauf der Löschfrist wird die Datendatei inklusive Backups gelöscht.
- **Jugendschutz:** Altersbestätigung 18+ bei der Anmeldung. Hinweis, dass Alkohol nur an Erwachsene verschickt werden darf.
- **Versandbedingungen:** Hinweis, dass die AGB des jeweiligen Versanddienstleisters für den Versand von Flüssigkeiten und Glas zu beachten sind.

### 6.2 Sicherheit
- HTTPS-only, HSTS.
- Magic-Link-Token: zufällig, einmal verwendbar, gehasht gespeichert, kurze Gültigkeit.
- Rate Limiting für Anmeldung und Login-Anfragen (Schutz vor Spam und E-Mail-Bombing; im Arbeitsspeicher gehalten), dazu Honeypot-Feld.
- Zugriffsschutz: Ein Teilnehmer sieht ausschließlich die Adresse seines eigenen Partners.
- Die Datendatei liegt außerhalb des Web-Roots, ist nur für den App-Prozess lesbar (Dateirechte 600) und wird verschlüsselt abgelegt (z. B. AES-GCM mit Schlüssel aus Umgebungsvariable) oder auf einem verschlüsselten Volume.
- Server-Secret für Link-Signaturen und Datenschlüssel nur über Umgebungsvariablen, nie im Repository.
- Keine Enumeration von E-Mail-Adressen (siehe A-6).

### 6.3 Zuverlässigkeit
- Zeitgesteuerte Aufgaben sind zustandsbasiert statt ereignisbasiert: Bei jedem Lauf wird geprüft, was laut Fristen und Datendatei fällig ist (z. B. „Anmeldeschluss vorbei und noch keine Zuordnung → auslosen“). Verpasste Läufe werden so automatisch nachgeholt, ein eigener Job-Store ist nicht nötig.
- Schreibzugriffe auf die Datendatei erfolgen **atomar** (in temporäre Datei schreiben, dann umbenennen) und serialisiert über eine Sperre (Mutex/Dateisperre), damit gleichzeitige Anmeldungen keine Daten überschreiben.
- Tägliches Backup der Datendatei (verschlüsselt), das mit der Löschfrist ebenfalls entfernt wird.
- E-Mail-Versand über einen SMTP- bzw. API-Dienst mit einfachem Retry. Der Status „versendet“ wird erst nach erfolgreicher Übergabe gesetzt.
- E-Mails mit SPF, DKIM und DMARC, um Spam-Einstufung zu vermeiden. Bei der Anmeldung Hinweis, den Spam-Ordner zu prüfen.

### 6.4 Usability & Barrierefreiheit
- Mobile-first, responsive.
- WCAG 2.1 AA.
- Alle Zeiten in Europe/Berlin und deutschem Format (z. B. „01.12.2026, 23:59 Uhr“).
- Die Anmeldung muss in unter 2 Minuten möglich sein.

### 6.5 Performance & Skalierung
- Ausgelegt für Runden mit 3 bis ca. 500 Teilnehmern; die gesamte Datendatei wird beim Start in den Arbeitsspeicher geladen (bei 500 Teilnehmern deutlich unter 1 MB).
- Betrieb als **ein einzelner Prozess** (keine horizontale Skalierung, da die Datei der einzige Speicher ist), z. B. als Container mit gemountetem Volume.
- Seitenaufbau < 2 s auf mobilem 4G.

## 7. Datenhaltung ohne Datenbank

Es gibt genau zwei Dateien:

| Datei | Inhalt | Wer schreibt |
|---|---|---|
| `config.yaml` (+ Umgebungsvariablen für Secrets) | Rundendefinition, Fristen, Organisator-E-Mail, SMTP-Zugang | Organisator/Betreiber |
| `data/<slug>.json.enc` (verschlüsselt, je Runde) | Teilnehmer, Zuordnung, Sendungen, Versandvermerke | nur die App |

Nicht gespeichert werden: Login-Token (zustandslos signiert, L-1), Sitzungen (signiertes Cookie), Rate-Limit-Zähler (Arbeitsspeicher), E-Mail-Log (nur Vermerke am Teilnehmer).

### Beispiel `config.yaml`

```yaml
round:
  name: "Wein-Wichteln 2026"
  description: "Eine Flasche im Wert von ca. 15–25 €."
  registration_deadline: "2026-12-01T23:59:00+01:00"
  shipping_deadline: "2026-12-10T23:59:00+01:00"
  reminders: ["2026-12-08T10:00:00+01:00", "2026-12-11T10:00:00+01:00"]
  min_participants: 3
  max_participants: null
  invite_code: null
  data_retention_days: 30
organizer_email: "orga@example.org"
base_url: "https://wichteln.example.org"
# SMTP-Zugang, SECRET_KEY, DATA_KEY über Umgebungsvariablen
```

### Struktur `round.json`

```json
{
  "version": 1,
  "status": "OPEN",
  "drawn_at": null,
  "participants": [
    {
      "id": "p_8f3k2a",
      "email": "anna@example.org",
      "first_name": "Anna",
      "last_name": "Muster",
      "address": {
        "street": "Hauptstraße", "house_number": "12",
        "extra": null, "postal_code": "50667", "city": "Köln"
      },
      "note": "Gerne Rotwein",
      "adult_confirmed": true,
      "consent_at": "2026-11-20T18:02:11+01:00",
      "email_confirmed_at": "2026-11-20T18:05:40+01:00",
      "removed_at": null,
      "giftee_id": "p_x7m1qd",
      "reveal": false,
      "shipment": {
        "carrier": "DHL",
        "tracking_number": "00340434161234567890",
        "submitted_at": "2026-12-05T09:12:00+01:00"
      },
      "received_at": null,
      "mails_sent": ["CONFIRM", "WELCOME", "DRAW"]
    }
  ]
}
```

- Die Zuordnung ist als `giftee_id` am Schenkenden gespeichert; der Kreis ergibt sich durch Verfolgen der IDs. Der Tracking-Link wird bei Bedarf aus Carrier und Nummer erzeugt (Anhang A) und nicht gespeichert.
- Die Eindeutigkeit der E-Mail-Adresse (A-6) und die Konsistenz der Zuordnung prüft die App beim Schreiben.
- `version` erlaubt spätere Formatänderungen mit einfacher Migration beim Laden.

## 8. Statusmodell der Runde

| Status | Bedeutung | Übergang |
|---|---|---|
| OPEN | Anmeldung möglich (Ausgangszustand bei leerer Datendatei) | Anmeldeschluss erreicht → Auslosung |
| DRAWN / SHIPPING | Ausgelost, Versandphase | Versandfrist + Nachlauf → CLOSED |
| CLOSED | Abgeschlossen, Löschfrist läuft | Löschfrist abgelaufen → Datendatei und Backups gelöscht |
| CANCELLED | Abgebrochen (z. B. zu wenig Teilnehmer) | Organisator verlängert Frist in der Konfiguration → OPEN |

## 9. Seitenübersicht

| Seite | Zugriff | Inhalt |
|---|---|---|
| `/` | öffentlich | Beschreibung, Countdown, Anzahl Teilnehmer, Anmeldeformular |
| `/bestaetigen` | öffentlich | Bestätigung der E-Mail-Adresse |
| `/login` | öffentlich | Magic Link anfordern |
| `/dashboard` | Teilnehmer | Phase-abhängig: eigene Daten bearbeiten / Partneradresse + „Versand melden“ / Status eingehender Sendung |
| `/admin` | Organisator | Status, Teilnehmerliste, Ausfall melden |
| `POST /tasks/run` | geschützt (Secret) | Fällige Aufgaben ausführen (für Cron) |
| `/datenschutz`, `/impressum` | öffentlich | Rechtstexte |

Die Oberfläche wird serverseitig gerendert (HTML-Formulare, wenig bzw. kein JavaScript), um ohne Frontend-Build auszukommen.

## 10. Release-Planung

**MVP (v1.0):** R-1 bis R-3, Datenhaltung gemäß Abschnitt 7, A-1, A-2, A-4 bis A-7, A-9, L-1 bis L-3, D-1 bis D-5, D-7 bis D-9, V-1, V-2, V-4, V-5, V-7, E-1, E-3, O-1, O-2 sowie die nicht-funktionalen Anforderungen.

**v1.1:** Formatprüfung der Sendungsnummern (V-3), Korrektur der Sendung (V-6), zweite Erinnerung und Admin-Eskalation (E-2, E-4), Ausfallbehandlung (O-4), Packstation (A-3).

**v1.2+:** Ausschlussregeln (D-6), Empfangsbestätigung (E-5), Einladungscode/Domain-Beschränkung (R-4), manuelle Erinnerung (O-3).

## 11. Risiken

| Risiko | Auswirkung | Gegenmaßnahme |
|---|---|---|
| Teilnehmer verschickt nicht | Ein Empfänger geht leer aus | Erinnerungen, Admin-Eskalation, Ausfallbehandlung (O-4) |
| E-Mails landen im Spam | Bestätigung/Auslosung kommt nicht an | SPF/DKIM/DMARC, seriöser Versanddienst, Hinweis bei Anmeldung |
| Falsche oder unvollständige Adresse | Paket kommt nicht an | Pflichtfelder, PLZ-Validierung, Adresse in M-2 zur Kontrolle anzeigen |
| Bruch beim Transport | Enttäuschung | Verpackungshinweise im Dashboard |
| Anonymität wird über Tracking oder Absenderangabe aufgehoben | Überraschung verdorben | Hinweis zur neutralen Absenderangabe; bewusst in Kauf genommen |
| Fake- oder Spam-Anmeldungen | Verfälschte Auslosung | Double-Opt-in, Rate Limiting, optional Einladungscode |
| Datendatei beschädigt oder verloren | Anmeldungen/Zuordnung weg | Atomares Schreiben, Sperre, tägliches Backup, Validierung beim Laden |
| Gleichzeitige Schreibzugriffe | Verlorene Anmeldungen | Ein Prozess, serialisierte Schreibzugriffe |

## 12. Offene Fragen

1. ~~Hosting~~ → Strato, unter https://wichtelberg.steinforth.com. Offen: Ist es ein V-Server/Root-Server (Node.js möglich) oder Webhosting (kein dauerhaft laufender Prozess)?
2. ~~Anonymität~~ → Optional, der Schenkende entscheidet (E-6).
3. **Budget:** Nur als Empfehlung im Beschreibungstext oder als festes Feld?
4. **Erinnerungen:** Reicht eine Erinnerung, oder sind standardmäßig zwei gewünscht? Sollen auch Teilnehmer erinnert werden, die ihre E-Mail-Adresse noch nicht bestätigt haben?
5. **Weitere Versanddienstleister** (z. B. Deutsche Post Warenpost, „Sonstige“ mit Freitext)?
6. ~~Mehrere Runden~~ → Ja, je eine Runde pro Land (DE, AT, CH, IT), jeweils mit eigener Datendatei. Offen: Sollen die Seiten für IT/CH auch auf Italienisch/Französisch erscheinen?
7. **Ausfälle:** Wie wird verfahren, wenn ein Teilnehmer erst nach dem eigenen Empfang ausfällt (Wein erhalten, aber nicht verschickt)?
8. **Branding/Domain** und Absender-E-Mail-Adresse.

---

## Anhang A: Versanddienstleister

Die Formate sind Richtwerte und sollen nur warnen, nicht blockieren (V-3). Tracking-URLs müssen vor dem Go-live geprüft und zentral konfigurierbar gehalten werden, da Anbieter sie ändern.

| Dienstleister | Typisches Format | Tracking-URL (Vorlage) |
|---|---|---|
| DHL | 12, 14 oder 20 Ziffern; international auch `JJD…` bzw. 2 Buchstaben + 9 Ziffern + `DE` | `https://www.dhl.de/de/privatkunden/pakete-empfangen/verfolgen.html?piececode={nr}` |
| UPS | `1Z` + 16 alphanumerische Zeichen | `https://www.ups.com/track?loc=de_DE&tracknum={nr}` |
| Hermes | 14 bis 20 Ziffern (häufig 14 oder 16) | `https://www.myhermes.de/empfangen/sendungsverfolgung/sendungsinformation#{nr}` |
| GLS | 11 bis 12 Ziffern bzw. 8 alphanumerische Zeichen (Track-ID) | `https://gls-group.com/DE/de/paketverfolgung?match={nr}` |
| DPD | 14 Ziffern | `https://tracking.dpd.de/status/de_DE/parcel/{nr}` |

## Anhang B: Beispiel-E-Mail M-7 (Wein unterwegs)

> **Betreff:** Dein Wichtel-Wein ist unterwegs 🍷
>
> Hallo {Vorname},
>
> gute Nachrichten: Dein Wichtel hat Dir eine Flasche Wein geschickt!
>
> **Versanddienstleister:** {Carrier}
> **Sendungsnummer:** {Nummer}
> **[Sendung verfolgen]({Tracking-URL})**
>
> Wer Dich beschenkt, bleibt natürlich geheim.
>
> Prost & frohes Wichteln!
> Dein Wichtelberg-Team
>
> [Zu Deinem Dashboard]({Dashboard-Link})
