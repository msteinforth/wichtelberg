<?php
/**
 * Cronjob: führt alle fälligen Aufgaben aller Runden aus
 * (Auslosung, Mails, Erinnerungen, Aufdeckung, Backup, Löschung).
 * Strato-Cronjob z. B. alle 15 Minuten auf diese Datei einrichten.
 */
declare(strict_types=1);

namespace Wichtelberg;

// Aufruf per Browser verhindern, falls die Datei doch erreichbar sein sollte
if (isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(403);
    exit;
}

require __DIR__ . '/src/bootstrap.php';

$config = Config::load(Bootstrap::configPath());
$app = Bootstrap::app($config, null, null, fn ($m) => fwrite(STDERR, $m . "\n"));
foreach ($app->runAllTasks() as $slug => $status) {
    echo date('c') . " $slug: $status\n";
}
