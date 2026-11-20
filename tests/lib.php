<?php
declare(strict_types=1);

namespace Wichtelberg\Tests;

use Wichtelberg\App;
use Wichtelberg\Bootstrap;
use Wichtelberg\Config;
use Wichtelberg\MemoryMailer;
use Wichtelberg\Request;
use Wichtelberg\Response;
use Wichtelberg\Service;

require_once dirname(__DIR__) . '/src/bootstrap.php';

/** Minimaler Test-Runner (keine Abhängigkeiten). */
final class T
{
    public static array $tests = [];
    public static int $assertions = 0;

    public static function test(string $name, callable $fn): void
    {
        self::$tests[] = [$name, $fn];
    }

    public static function ok(mixed $cond, string $msg = 'Bedingung nicht erfüllt'): void
    {
        self::$assertions++;
        if (!$cond) {
            throw new \AssertionError($msg);
        }
    }

    public static function eq(mixed $expected, mixed $actual, string $msg = ''): void
    {
        self::$assertions++;
        if ($expected !== $actual) {
            throw new \AssertionError(($msg ? "$msg: " : '') . 'erwartet ' . var_export($expected, true) . ', erhalten ' . var_export($actual, true));
        }
    }

    public static function throws(callable $fn, ?callable $check = null, string $msg = 'Ausnahme erwartet'): void
    {
        self::$assertions++;
        try {
            $fn();
        } catch (\Throwable $e) {
            if ($check && !$check($e)) {
                throw new \AssertionError("$msg – falsche Ausnahme: " . get_class($e) . ': ' . $e->getMessage());
            }
            return;
        }
        throw new \AssertionError($msg);
    }

    public static function contains(string $needle, string $haystack, string $msg = ''): void
    {
        self::ok(str_contains($haystack, $needle), ($msg ? "$msg: " : '') . "\"$needle\" nicht gefunden");
    }

    public static function notContains(string $needle, string $haystack, string $msg = ''): void
    {
        self::ok(!str_contains($haystack, $needle), ($msg ? "$msg: " : '') . "\"$needle\" sollte nicht vorkommen");
    }

    public static function run(): int
    {
        $failed = 0;
        foreach (self::$tests as [$name, $fn]) {
            try {
                $fn();
                echo "ok      $name\n";
            } catch (\Throwable $e) {
                $failed++;
                echo "FEHLER  $name\n        " . get_class($e) . ': ' . $e->getMessage() . "\n        " . $e->getFile() . ':' . $e->getLine() . "\n";
            }
        }
        $n = count(self::$tests);
        echo "\n" . ($n - $failed) . " von $n Tests bestanden, " . self::$assertions . " Prüfungen" . ($failed ? ", $failed FEHLGESCHLAGEN" : '') . "\n";
        return $failed ? 1 : 0;
    }
}

final class Clock
{
    public \DateTimeImmutable $t;

    public function __construct(string $iso)
    {
        $this->t = new \DateTimeImmutable($iso);
    }

    public function set(string $iso): void
    {
        $this->t = new \DateTimeImmutable($iso);
    }

    public function closure(): \Closure
    {
        return fn () => $this->t;
    }
}

const KEYS = ['secret_key' => '1111111111111111111111111111111111111111111111111111111111111111', 'data_key' => '2222222222222222222222222222222222222222222222222222222222222222', 'tasks_token' => 'cron-secret'];

function rawConfig(array $round = [], array $extraRounds = [], array $top = []): array
{
    return $top + [
        'rounds' => array_merge([$round + [
            'slug' => 'de',
            'country' => 'DE',
            'name' => 'Test-Wichteln',
            'description' => 'Eine Flasche für ca. 20 €.',
            'registration_deadline' => '2026-12-01T23:59:00+01:00',
            'shipping_deadline' => '2026-12-10T23:59:00+01:00',
            'reminders' => ['2026-12-08T10:00:00+01:00'],
            'min_participants' => 3,
            'data_retention_days' => 30,
        ]], $extraRounds),
        'organizer_email' => 'orga@example.org',
        'base_url' => 'http://localhost:8080',
        'mail' => ['transport' => 'log', 'from' => 'Wichtelberg <wichteln@example.org>'],
        'secrets' => KEYS,
        'web_cron_seconds' => 0,
    ];
}

function tmpDir(): string
{
    $d = sys_get_temp_dir() . '/wichtelberg-test-' . bin2hex(random_bytes(6));
    mkdir($d, 0700, true);
    return $d;
}

final class Env
{
    public Config $config;
    public MemoryMailer $mailer;
    public Clock $clock;
    public App $app;
    /** @var Service[] */
    public array $services;
    public Service $service;
    public string $dataDir;
    public array $log = [];

    public function __construct(array $raw, string $start = '2026-11-20T12:00:00+01:00')
    {
        $this->dataDir = tmpDir();
        $this->config = Config::build(['data_dir' => $this->dataDir] + $raw, $this->dataDir);
        $this->mailer = new MemoryMailer();
        $this->clock = new Clock($start);
        $this->app = Bootstrap::app($this->config, $this->clock->closure(), $this->mailer, function ($m) {
            $this->log[] = $m;
        });
        $this->services = $this->app->services();
        $this->service = $this->services[0];
    }

    /** Frische Service-Instanzen (wie eine neue PHP-Anfrage). */
    public function reload(?array $raw = null): void
    {
        if ($raw) {
            $this->config = Config::build(['data_dir' => $this->dataDir] + $raw, $this->dataDir);
        }
        $this->app = Bootstrap::app($this->config, $this->clock->closure(), $this->mailer, function ($m) {
            $this->log[] = $m;
        });
        $this->services = $this->app->services();
        $this->service = $this->services[0];
    }

    public function mailsTo(string $to): array
    {
        return array_values(array_filter($this->mailer->outbox, fn ($m) => $m['to'] === $to));
    }

    public function lastMail(): array
    {
        return $this->mailer->outbox[count($this->mailer->outbox) - 1];
    }

    /** Token t aus der letzten Mail an $to, die $pathPrefix enthält. */
    public function token(string $to, string $pathPrefix): string
    {
        foreach (array_reverse($this->mailer->outbox) as $m) {
            if ($m['to'] === $to && preg_match('#' . preg_quote($pathPrefix, '#') . '\?t=(\S+)#', $m['text'], $mm)) {
                return rawurldecode($mm[1]);
            }
        }
        throw new \AssertionError("Keine Mail an $to mit $pathPrefix");
    }

    /** @return string[] IDs */
    public function registerConfirmed(int $n, ?Service $s = null, array $extra = []): array
    {
        $s ??= $this->service;
        $ids = [];
        for ($i = 1; $i <= $n; $i++) {
            $r = $s->register(sampleForm($i, $extra));
            T::ok(isset($r['ok']), 'Anmeldung fehlgeschlagen: ' . json_encode($r));
            $ids[] = $s->confirm($this->token("person$i@example.org", $s->base() . '/bestaetigen'));
        }
        return $ids;
    }

    /** HTTP-artige Anfrage direkt an die App. */
    public function req(string $method, string $uri, array $form = [], array $cookies = [], array $headers = []): Response
    {
        $parts = parse_url($uri);
        parse_str($parts['query'] ?? '', $query);
        if ($method === 'POST' && !isset($headers['origin'])) {
            $headers['origin'] = $this->config->baseUrl;
        }
        return $this->app->handle(new Request($method, $parts['path'], $query, $form, $cookies, $headers, '127.0.0.1'));
    }
}

function sampleForm(int $i, array $extra = []): array
{
    return $extra + [
        'email' => "person$i@example.org",
        'first_name' => "Vorname$i",
        'last_name' => "Nachname$i",
        'street' => 'Hauptstraße',
        'house_number' => (string) $i,
        'postal_code' => '50667',
        'city' => 'Köln',
        'note' => $i === 1 ? 'Lieber Rotwein' : '',
        'adult' => 'on',
        'consent' => 'on',
    ];
}

/** Cookie name=value aus Response. */
function cookieOf(Response $r): ?array
{
    if (!$r->cookies) {
        return null;
    }
    [$pair] = explode(';', $r->cookies[0]);
    [$k, $v] = explode('=', $pair, 2);
    return [$k => rawurldecode($v)];
}
