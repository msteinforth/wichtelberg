<?php
declare(strict_types=1);

namespace Wichtelberg;

require dirname(__DIR__) . '/src/bootstrap.php';

// Statische Datei für den PHP-Entwicklungsserver (php -S … public/index.php)
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) && !str_ends_with($_SERVER['REQUEST_URI'], '.php')) {
    return false;
}

try {
    $config = Config::load(Bootstrap::configPath());
} catch (ConfigError $e) {
    // Ohne Shell-Zugang (Strato-Webhosting) ist das die einzige Stelle, an der man Konfigurationsfehler sieht.
    // Die Meldungen nennen nur Feldnamen und Regeln, niemals Werte von Schlüsseln.
    error_log($e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Wichtelberg ist noch nicht korrekt konfiguriert (config.php).\n\n" . $e->getMessage() . "\n");
}

$app = Bootstrap::app($config);
$app->handle(Request::fromGlobals())->emit();

// Web-Cron: Fällige Aufgaben höchstens alle web_cron_seconds Sekunden nach einer Anfrage ausführen.
// Ergänzt den Strato-Cronjob (cron.php) – oder ersetzt ihn, falls das Paket keine Cronjobs bietet.
if ($config->webCronSeconds > 0) {
    $stamp = $config->dataDir . '/webcron.stamp';
    $last = @filemtime($stamp) ?: 0;
    if (time() - $last >= $config->webCronSeconds) {
        @touch($stamp);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request(); // Antwort ist raus, Aufgaben laufen im Hintergrund weiter
        }
        ignore_user_abort(true);
        $app->runAllTasks();
    }
}
