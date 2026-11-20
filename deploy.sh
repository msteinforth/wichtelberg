#!/usr/bin/env bash
# Lädt Wichtelberg per SSH auf den Strato-Webspace hoch (von Deinem Rechner aus ausführen).
# Das Passwort fragt ssh/rsync interaktiv ab. config.php und data/ auf dem Server bleiben unangetastet.
#
#   ./deploy.sh                       # Standardziel
#   ./deploy.sh user@host zielordner  # anderes Ziel
set -euo pipefail

TARGET="${1:-stu111925250@58819075.ssh.w1.strato.hosting}"
DIR="${2:-wichtelberg}"
cd "$(dirname "$0")"

FILES=(public src cron.php cli.php .htaccess config.example.php)

if command -v rsync >/dev/null && ssh -o BatchMode=no "$TARGET" "command -v rsync" >/dev/null 2>&1; then
  echo "→ Übertrage per rsync nach $TARGET:$DIR/"
  rsync -rlptvz --delete-after --exclude 'data/' --exclude 'config.php' \
    "${FILES[@]}" "$TARGET:$DIR/"
else
  echo "→ rsync nicht verfügbar, übertrage per tar über ssh nach $TARGET:$DIR/"
  tar czf - "${FILES[@]}" | ssh "$TARGET" "mkdir -p '$DIR' && cd '$DIR' && rm -rf public src && tar xzf -"
fi

echo
echo "Fertig. Beim ersten Mal auf dem Server noch:"
echo "  ssh $TARGET"
echo "  cd $DIR && cp config.example.php config.php && php cli.php keys   # Schlüssel in config.php eintragen"
echo "  php cli.php check"
