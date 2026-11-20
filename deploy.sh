#!/usr/bin/env bash
# Deployment auf Strato-Webhosting per SFTP (Strato erlaubt dort keinen Shell-Zugang).
# Von Deinem Rechner aus im Projektordner ausführen. Das Passwort fragt sftp ab.
#
#   ./deploy.sh config   # einmalig: config.php aus der Vorlage erzeugen, inkl. neuer Schlüssel
#   ./deploy.sh          # Dateien + config.php hochladen (data/ auf dem Server bleibt unangetastet)
#
# Anderes Ziel: TARGET=user@host DIR=ordner ./deploy.sh
set -euo pipefail

TARGET="${TARGET:-stu111925250@58819075.ssh.w1.strato.hosting}"
DIR="${DIR:-wichtelberg}"
cd "$(dirname "$0")"

if [[ "${1:-}" == "config" ]]; then
  if [[ -f config.php ]]; then
    echo "config.php existiert bereits – nichts geändert."
    exit 1
  fi
  sk=$(openssl rand -hex 32); dk=$(openssl rand -hex 32); tt=$(openssl rand -hex 32)
  sed -e "s/'secret_key' => ''/'secret_key' => '$sk'/" \
      -e "s/'data_key' => ''/'data_key' => '$dk'/" \
      -e "s/'tasks_token' => ''/'tasks_token' => '$tt'/" \
      config.example.php > config.php
  chmod 600 config.php
  echo "config.php mit neuen Schlüsseln erzeugt."
  echo "Jetzt anpassen: organizer_email, mail.from, legal, rounds (Fristen)."
  echo "WICHTIG: config.php sicher aufbewahren (data_key!) – sie ist nicht im Git-Repository."
  exit 0
fi

if [[ ! -f config.php ]]; then
  echo "config.php fehlt. Zuerst: ./deploy.sh config  (und dann anpassen)"
  exit 1
fi
if grep -q "'secret_key' => ''" config.php; then
  echo "config.php enthält noch keine Schlüssel. Bitte mit ./deploy.sh config neu erzeugen."
  exit 1
fi

# SFTP-Befehle. Ein vorangestelltes "-" ignoriert Fehler (z. B. „Ordner existiert schon“).
cmds=$(mktemp)
trap 'rm -f "$cmds"' EXIT
{
  echo "-mkdir $DIR"
  echo "-mkdir $DIR/data"
  for d in public src; do
    find "$d" -type d | while read -r sub; do echo "-mkdir $DIR/$sub"; done
    find "$d" -type f | while read -r f; do echo "put $f $DIR/$f"; done
  done
  for f in .htaccess cron.php cli.php config.example.php config.php; do echo "put $f $DIR/$f"; done
  echo "chmod 600 $DIR/config.php"
  echo "chmod 700 $DIR/data"
  echo "bye"
} > "$cmds"

echo "→ Lade nach $TARGET:$DIR/ hoch (Passwort wird abgefragt) …"
# Ohne -b, damit sftp das Passwort interaktiv abfragen darf; die Befehle kommen über stdin.
sftp -q "$TARGET" < "$cmds"

echo
echo "Fertig. Jetzt https://wichtelberg.steinforth.com aufrufen."
echo "Konfigurationsfehler werden dort angezeigt."
