<?php
/**
 * Hilfsbefehle:
 *   php cli.php keys     Schlüssel für config.php erzeugen
 *   php cli.php check    Konfiguration prüfen
 *   php cli.php tasks    fällige Aufgaben ausführen (wie cron.php)
 *   php cli.php status   Status aller Runden anzeigen
 */
declare(strict_types=1);

namespace Wichtelberg;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require __DIR__ . '/src/bootstrap.php';

$cmd = $argv[1] ?? 'help';
try {
    switch ($cmd) {
        case 'keys':
            echo "'secret_key'  => '" . Crypto::generateKey() . "',\n";
            echo "'data_key'    => '" . Crypto::generateKey() . "',\n";
            echo "'tasks_token' => '" . Crypto::generateKey() . "',\n";
            break;
        case 'check':
            $c = Config::load(Bootstrap::configPath());
            echo "Konfiguration OK: " . count($c->rounds) . " Runde(n), Datenverzeichnis {$c->dataDir}, Mailversand: {$c->mailTransport}\n";
            foreach ($c->rounds as $r) {
                echo "  /{$r->slug}: {$r->name} ({$r->countryName}), Anmeldeschluss " . Format::dateTime($r->registrationDeadline) . "\n";
            }
            break;
        case 'tasks':
        case 'status':
            $app = Bootstrap::app(Config::load(Bootstrap::configPath()), null, null, fn ($m) => fwrite(STDERR, $m . "\n"));
            if ($cmd === 'tasks') {
                $app->runAllTasks();
            }
            foreach ($app->services() as $s) {
                echo "/{$s->round->slug}: {$s->status()}, " . count($s->participants()) . " Teilnehmer ({$s->confirmedCount()} bestätigt)\n";
            }
            break;
        default:
            echo "Befehle: keys | check | tasks | status\n";
    }
} catch (ConfigError $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
