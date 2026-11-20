# Wichtelberg – Wein-Wichteln per Post

Schlanke PHP-App für ein Wein-Wichteln: anmelden bis zur Frist, automatische Auslosung, Versand mit
Sendungsnummer, Erinnerungen, optionale Aufdeckung. **Keine Datenbank, keine Abhängigkeiten**: Jede
Länder-Runde liegt in einer verschlüsselten JSON-Datei. Läuft auf normalem Webhosting (Strato).

Anforderungen: [docs/PRD.md](docs/PRD.md) · Live: https://wichtelberg.steinforth.com

- PHP ≥ 8.1 mit `openssl`, `mbstring`, `json` (Standard bei Strato), Apache mit `.htaccess`
- Runden pro Land: **DE, AT, CH, IT**, mit passenden PLZ-Regeln und lokalen Versanddienstleistern
- URL je Runde: `/de`, `/at`, `/ch`, `/it`; die Startseite listet alle Runden, `/admin` ist für den Organisator

## Verzeichnisse

```
public/          Web-Root: index.php (Front-Controller), style.css, .htaccess
src/             Anwendung (nicht per Web erreichbar)
data/            verschlüsselte Datendateien, Backups, Rate-Limit (wird angelegt; per .htaccess gesperrt)
config.php       Konfiguration inkl. Schlüssel (aus config.example.php; nicht im Repository)
cron.php         Cronjob: fällige Aufgaben ausführen
cli.php          php cli.php keys | check | tasks | status (lokal oder mit Shell-Zugang)
deploy.sh        config.php erzeugen und per SFTP auf den Strato-Webspace hochladen
tests/           php tests/run.php
```

## Installation auf Strato

Der Strato-Zugang erlaubt **nur SFTP**, keine Shell. Darum wird alles auf dem eigenen Rechner vorbereitet
und per SFTP hochgeladen. Konfigurationsfehler zeigt die Website direkt an.

1. **Konfiguration erzeugen** (einmalig, im Projektordner auf Deinem Rechner):
   ```bash
   ./deploy.sh config
   ```
   Das legt `config.php` mit neuen Schlüsseln an (per `openssl`, das macOS mitbringt). Danach in `config.php`
   Organisator, Absender (`mail.from`), Rechtstexte und Fristen anpassen.
   **`config.php` sicher aufbewahren**: Sie ist nicht im Git-Repository, und ohne `data_key` sind die Daten verloren.
2. **Hochladen** (fragt das Strato-Passwort ab):
   ```bash
   ./deploy.sh
   ```
   Lädt `public/`, `src/`, die Skripte und `config.php` nach `wichtelberg/`. `data/` auf dem Server bleibt
   unangetastet. Alternativ geht das auch mit einem SFTP-Programm (Cyberduck, FileZilla).
3. **Domain**: Im Strato-Kundenbereich die (Sub-)Domain `wichtelberg.steinforth.com` auf das Verzeichnis
   `/wichtelberg/public` zeigen lassen. Falls sie auf `/wichtelberg` zeigt, funktioniert es dank `.htaccess`
   ebenfalls, und `src/`, `data/` und `config.php` bleiben gesperrt. SSL-Zertifikat für die Subdomain aktivieren.
4. **PHP-Version**: Im Kundenbereich PHP 8.3 (mindestens 8.1) für die Domain wählen.
5. **Prüfen**: https://wichtelberg.steinforth.com aufrufen. Ist die Konfiguration fehlerhaft, stehen die Fehler
   dort (ohne Schlüsselwerte).
6. **Cronjob** (falls im Paket enthalten): `wichtelberg/cron.php` alle 15 Minuten ausführen. Ohne Cronjob
   übernimmt der eingebaute Web-Cron (`web_cron_seconds`): Fällige Aufgaben laufen dann beim nächsten
   Seitenaufruf. Alternativ ein externer Cron-Dienst:
   ```bash
   curl -fsS -X POST -H "Authorization: Bearer <tasks_token>" https://wichtelberg.steinforth.com/tasks/run
   ```
7. **E-Mail**: Am zuverlässigsten mit SMTP über ein Strato-Postfach der Domain (`mail.transport = 'smtp'`,
   `smtp.strato.de`, Port 465). `mail()` funktioniert ebenfalls, wenn `mail.from` eine Adresse der Domain ist.
8. **Testen**: Zunächst `mail.transport = 'log'` setzen. Mails landen dann in `data/mail.log`, das sich per SFTP
   herunterladen lässt.

Updates: Repository aktualisieren und erneut `./deploy.sh` ausführen.

## Ablauf

1. **Anmeldung** unter `/<slug>` bis `registration_deadline`, mit Double-Opt-in, Altersbestätigung (18+) und Einwilligung.
2. **Auslosung** zum Anmeldeschluss: alle bestätigten Teilnehmer in einem einzigen Kreis, also keine Selbstzuordnung und keine gegenseitigen Paare. Unbestätigte Anmeldungen werden gelöscht. Bei weniger als `min_participants` wird abgesagt. Wird die Frist in `config.php` verlängert, öffnet die Anmeldung wieder.
3. **Versand**: Der Teilnehmer trägt Dienstleister und Sendungsnummer ein, der Empfänger bekommt eine Mail mit Tracking-Link, ohne Absendernamen.
4. **Erinnerungen** zu den `reminders`-Zeitpunkten an alle ohne Sendungsnummer, je Zeitpunkt höchstens einmal.
5. **Aufdeckung** (optional): Der Schenkende entscheidet. Zu `reveal_at` (Standard: Versandfrist) erfährt der Empfänger den Namen, sofern der Schenkende zugestimmt und verschickt hat.
6. **Löschung** `data_retention_days` nach der Versandfrist: Daten und Backups der Runde werden gelöscht.

Login ohne Passwort per Magic Link. Links in Systemmails sind 7 Tage gültig, angeforderte Login-Links 30 Minuten.
Links aus Mails lösen erst nach einem Klick auf einen Button etwas aus (Schutz vor Link-Scannern der Mailprogramme).

## Versanddienstleister

| Land | Dienstleister |
|---|---|
| DE | DHL, Hermes, DPD, GLS, UPS |
| AT | Österreichische Post, DPD, GLS, DHL, UPS |
| CH | Die Schweizerische Post, Planzer Paket, DPD, DHL, UPS, GLS |
| IT | Poste Italiane, BRT, SDA, GLS, DHL, UPS |

Die Tracking-URL-Vorlagen in `src/Carriers.php` **vor dem Go-live mit echten Sendungsnummern prüfen**,
besonders Planzer, BRT und SDA.

## Technik

- **Datenhaltung**: `data/<slug>.json.enc`, AES-256-GCM, Dateirechte 600. Schreibzugriffe sind über `flock()` serialisiert und atomar (tmp + rename). Tägliche Backups in `data/backups/`, die letzten 7 bleiben.
- **Zeitgesteuerte Aufgaben** sind zustandsbasiert und idempotent: Jeder Lauf prüft, was laut Fristen und Daten fällig ist. Verpasste Termine werden nachgeholt, doppelte Läufe schaden nicht.
- **Sicherheit**: signierte Links und Cookies (HMAC, rundengebunden), HttpOnly/SameSite-Cookies, Origin-Prüfung gegen CSRF, CSP ohne JavaScript, Rate-Limits pro IP und pro E-Mail-Adresse, Honeypot, kein Auflisten registrierter Adressen.

## Entwicklung

```bash
php tests/run.php
```
```bash
cp config.example.php config.php    # base_url: http://localhost:8080, mail.transport: log, Schlüssel per php cli.php keys
php -S localhost:8080 -t public public/index.php
```
