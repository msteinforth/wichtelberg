# Wichtelberg – Wein-Wichteln per Post

Schlanke Web-App für ein Wein-Wichteln: anmelden bis zur Frist, automatische Auslosung, Versand mit
Sendungsnummer, Erinnerungen, optionale Aufdeckung. **Keine Datenbank**: Jede Länder-Runde liegt in
einer verschlüsselten JSON-Datei. Anforderungen: [docs/PRD.md](docs/PRD.md).

- Node.js ≥ 20, zwei Abhängigkeiten (`nodemailer`, `yaml`), serverseitig gerenderte Seiten ohne JavaScript
- Runden pro Land: **DE, AT, CH, IT**, mit passenden PLZ-Regeln und lokalen Versanddienstleistern
- URL je Runde: `https://wichtelberg.steinforth.com/<slug>` (z. B. `/de`), Startseite listet alle Runden

## Schnellstart (lokal)

```bash
npm install
cp config.example.yaml config.yaml   # anpassen; für Tests: mail.transport: log, base_url: http://localhost:3000
npm run keys                         # erzeugt SECRET_KEY, DATA_KEY, TASKS_TOKEN
export SECRET_KEY=… DATA_KEY=… TASKS_TOKEN=…
npm start
npm test
```

## Ablauf

1. **Anmeldung** unter `/<slug>` bis `registration_deadline`, mit Double-Opt-in per E-Mail, Altersbestätigung (18+) und Einwilligung.
2. **Auslosung** automatisch zum Anmeldeschluss: alle bestätigten Teilnehmer in einem einzigen Kreis, d. h. keine Selbstzuordnung und keine gegenseitigen Paare. Unbestätigte Anmeldungen werden gelöscht. Bei weniger als `min_participants` wird die Runde abgesagt; wird die Frist danach verlängert und die App neu gestartet, öffnet die Anmeldung wieder.
3. **Versand**: Der Teilnehmer trägt Dienstleister und Sendungsnummer ein, der Empfänger bekommt eine Mail mit Tracking-Link, ohne Absendernamen.
4. **Erinnerungen** zu den `reminders`-Zeitpunkten an alle ohne Sendungsnummer, je Zeitpunkt höchstens einmal.
5. **Aufdeckung** (optional): Der Schenkende entscheidet im Dashboard. Zu `reveal_at` (Standard: Versandfrist) erfährt der Empfänger den Namen, sofern der Schenkende zugestimmt und verschickt hat.
6. **Löschung** `data_retention_days` nach der Versandfrist: Datendatei und Backups der Runde werden geleert bzw. gelöscht.

Login ohne Passwort per Magic Link. Links in Systemmails sind 7 Tage gültig, angeforderte Login-Links 30 Minuten.
Der Organisator (`organizer_email`) meldet sich unter `/admin/login` an und sieht den Status aller Runden, aber nicht die Zuordnung.

## Versanddienstleister

| Land | Dienstleister |
|---|---|
| DE | DHL, Hermes, DPD, GLS, UPS |
| AT | Österreichische Post, DPD, GLS, DHL, UPS |
| CH | Die Schweizerische Post, Planzer Paket, DPD, DHL, UPS, GLS |
| IT | Poste Italiane, BRT, SDA, GLS, DHL, UPS |

Die Tracking-URL-Vorlagen in `src/carriers.js` **vor dem Go-live einmal mit echten Sendungsnummern prüfen**,
besonders Planzer, BRT und SDA. Bei Italien ist das Provinzkürzel Pflichtfeld.

## Datenhaltung

| Datei | Inhalt |
|---|---|
| `config.yaml` | Runden, Fristen, Organisator, Rechtstexte |
| Umgebungsvariablen | `SECRET_KEY` (Signatur der Links/Cookies), `DATA_KEY` (Verschlüsselung), `TASKS_TOKEN`, `SMTP_URL` |
| `data/<slug>.json.enc` | Teilnehmer, Zuordnung, Sendungen (AES-256-GCM, Rechte 600, atomar geschrieben) |
| `data/backups/<slug>-YYYY-MM-DD.json.enc` | tägliche Backups, die letzten 7 |

**`DATA_KEY` gut aufbewahren**: Ohne ihn sind die Daten nicht lesbar. Die App läuft als **ein** Prozess.

## Zeitgesteuerte Aufgaben

Die App prüft jede Minute selbst, was fällig ist (Auslosung, Mails, Erinnerungen, Aufdeckung, Backup,
Löschung), zusätzlich beim Start. Verpasste Termine werden nachgeholt. Optional per Cron:

```bash
curl -fsS -X POST -H "Authorization: Bearer $TASKS_TOKEN" https://wichtelberg.steinforth.com/tasks/run
```

## Betrieb auf einem Linux-Server (V-Server/Root-Server)

Dateien in `deploy/`: systemd-Unit, nginx-Konfiguration, Vorlage für die Umgebungsvariablen.

```bash
sudo useradd --system --home /opt/wichtelberg wichtelberg
sudo git clone <repo> /opt/wichtelberg && cd /opt/wichtelberg && sudo npm ci --omit=dev
sudo cp config.example.yaml config.yaml          # anpassen
sudo cp deploy/wichtelberg.env.example /etc/wichtelberg.env && sudo chmod 600 /etc/wichtelberg.env  # ausfüllen
sudo mkdir -p data && sudo chown wichtelberg: data && sudo chmod 700 data
sudo cp deploy/wichtelberg.service /etc/systemd/system/ && sudo systemctl enable --now wichtelberg
sudo cp deploy/nginx.conf /etc/nginx/sites-available/wichtelberg && sudo ln -s ../sites-available/wichtelberg /etc/nginx/sites-enabled/
sudo certbot --nginx -d wichtelberg.steinforth.com
```

Für den Mailversand SPF, DKIM und DMARC der Absenderdomain einrichten.
