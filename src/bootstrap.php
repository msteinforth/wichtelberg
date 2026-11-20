<?php
declare(strict_types=1);

namespace Wichtelberg;

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit("Wichtelberg benötigt PHP 8.1 oder neuer (aktuell: " . PHP_VERSION . ").\n");
}
foreach (['openssl', 'mbstring', 'json'] as $ext) {
    if (!extension_loaded($ext)) {
        http_response_code(500);
        exit("PHP-Erweiterung '$ext' fehlt.\n");
    }
}

date_default_timezone_set('Europe/Berlin');

foreach (['Carriers', 'Validation', 'Config', 'Crypto', 'Store', 'Draw', 'Format', 'Mailer', 'Mails', 'RateLimiter', 'Service', 'Views', 'App'] as $f) {
    require_once __DIR__ . "/$f.php";
}

final class Bootstrap
{
    /** Erstellt die App aus der Konfiguration. Uhr und Mailer sind für Tests austauschbar. */
    public static function app(Config $config, ?\Closure $clock = null, ?Mailer $mailer = null, ?\Closure $logger = null): App
    {
        $clock ??= fn () => new \DateTimeImmutable();
        $mailer ??= MailerFactory::create($config);
        if (!is_dir($config->dataDir)) {
            mkdir($config->dataDir, 0700, true);
        }
        $limiter = new RateLimiter($config->dataDir . '/ratelimit.json', $config->secretKey, $clock);
        $services = [];
        foreach ($config->rounds as $round) {
            $store = new Store($config->dataDir, $config->dataKey, $round->slug);
            $services[] = new Service($config, $round, $store, $mailer, $limiter, $clock, $logger);
        }
        return new App($config, $services, $mailer, $limiter, $clock, $logger);
    }

    public static function configPath(): string
    {
        return getenv('WICHTELBERG_CONFIG') ?: dirname(__DIR__) . '/config.php';
    }
}
