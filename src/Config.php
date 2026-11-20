<?php
declare(strict_types=1);

namespace Wichtelberg;

use DateTimeImmutable;

final class ConfigError extends \RuntimeException
{
}

/** Eine Länder-Runde (aus config.php). */
final class Round
{
    public function __construct(
        public readonly string $slug,
        public readonly string $country,
        public readonly string $countryName,
        public readonly string $name,
        public readonly string $description,
        public readonly DateTimeImmutable $registrationDeadline,
        public readonly DateTimeImmutable $shippingDeadline,
        /** @var DateTimeImmutable[] */
        public readonly array $reminders,
        public readonly DateTimeImmutable $revealAt,
        public readonly int $minParticipants,
        public readonly ?int $maxParticipants,
        public readonly int $retentionDays,
        public readonly DateTimeImmutable $purgeAt,
    ) {
    }
}

final class Config
{
    /** Hinweisbanner über allen Seiten; per 'banner' => '' in config.php abschaltbar. */
    public const DEFAULT_BANNER = 'Privates Wein-Wichteln der WhatsApp-Gruppe – keine Aktion von Lobenbergs Gute Weine.';

    public const RESERVED_SLUGS = ['admin', 'style.css', 'healthz', 'tasks', 'datenschutz', 'impressum', 'index.php', 'fonts', 'favicon.ico', 'favicon.svg', 'favicon-32.png', 'apple-touch-icon.png', 'og-image.png', 'img'];

    public function __construct(
        /** @var Round[] */
        public readonly array $rounds,
        public readonly string $organizerEmail,
        public readonly string $baseUrl,
        public readonly bool $secureCookies,
        public readonly bool $trustProxy,
        public readonly string $dataDir,
        public readonly int $webCronSeconds,
        public readonly ?string $tasksToken,
        public readonly string $mailTransport,
        public readonly string $mailFrom,
        public readonly string $mailFromAddress,
        public readonly ?array $smtp,
        public readonly string $legalOperator,
        public readonly string $legalImprint,
        public readonly string $secretKey,
        public readonly string $dataKey,
        public readonly string $banner = '',
    ) {
    }

    public static function load(string $path): self
    {
        if (!is_file($path)) {
            throw new ConfigError("Konfigurationsdatei " . basename($path) . " nicht gefunden. Vorlage: config.example.php (./deploy.sh config)");
        }
        $raw = require $path;
        if (!is_array($raw)) {
            throw new ConfigError("$path muss ein Array zurückgeben (return [...];).");
        }
        return self::build($raw, dirname($path));
    }

    private static function parseDate(mixed $value, string $name, array &$errors): ?DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            $errors[] = "$name fehlt oder ist kein Datum (ISO-8601 mit Zeitzone, z. B. \"2026-12-01T23:59:00+01:00\").";
            return null;
        }
        if (!preg_match('/([+-]\d{2}:?\d{2}|Z)$/', trim($value))) {
            $errors[] = "$name muss eine Zeitzone enthalten (z. B. \"+01:00\").";
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            $errors[] = "$name ist kein gültiges Datum: $value";
            return null;
        }
    }

    private static function decodeKey(mixed $value, string $name, array &$errors): ?string
    {
        if (!is_string($value) || $value === '') {
            $errors[] = "secrets.$name fehlt (64 Hex-Zeichen). Erzeugen mit: ./deploy.sh config";
            return null;
        }
        if (!preg_match('/^[0-9a-fA-F]{64}$/', $value)) {
            $errors[] = "secrets.$name muss aus genau 64 Hex-Zeichen bestehen.";
            return null;
        }
        return hex2bin($value);
    }

    private static function buildRound(mixed $r, int $idx, array &$errors): ?Round
    {
        $at = "rounds[$idx]";
        $r = is_array($r) ? $r : [];
        $slug = (string) ($r['slug'] ?? '');
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $slug) || in_array($slug, self::RESERVED_SLUGS, true)) {
            $errors[] = "$at.slug fehlt oder ist ungültig (Kleinbuchstaben, Ziffern, Bindestrich; z. B. \"de\").";
        }
        $country = strtoupper((string) ($r['country'] ?? ''));
        if (!isset(Carriers::COUNTRIES[$country])) {
            $errors[] = "$at.country muss eines von " . implode(', ', array_keys(Carriers::COUNTRIES)) . ' sein.';
        }
        $name = $r['name'] ?? null;
        if (!is_string($name) || $name === '') {
            $errors[] = "$at.name fehlt.";
        }

        $reg = self::parseDate($r['registration_deadline'] ?? null, "$at.registration_deadline", $errors);
        $ship = self::parseDate($r['shipping_deadline'] ?? null, "$at.shipping_deadline", $errors);
        if ($reg && $ship && $ship <= $reg) {
            $errors[] = "$at.shipping_deadline muss nach registration_deadline liegen.";
        }

        $reminders = [];
        $rawReminders = $r['reminders'] ?? [];
        if (!is_array($rawReminders)) {
            $errors[] = "$at.reminders muss eine Liste von Zeitpunkten sein.";
        } else {
            foreach (array_values($rawReminders) as $i => $value) {
                $d = self::parseDate($value, "$at.reminders[$i]", $errors);
                if ($d && $reg && $d <= $reg) {
                    $errors[] = "$at.reminders[$i] muss nach dem Anmeldeschluss liegen.";
                }
                if ($d) {
                    $reminders[] = $d;
                }
            }
            usort($reminders, fn ($a, $b) => $a <=> $b);
        }

        $reveal = $ship;
        if (isset($r['reveal_at'])) {
            $reveal = self::parseDate($r['reveal_at'], "$at.reveal_at", $errors);
            if ($reveal && $reg && $reveal <= $reg) {
                $errors[] = "$at.reveal_at muss nach dem Anmeldeschluss liegen.";
            }
        }

        $min = $r['min_participants'] ?? 3;
        if (!is_int($min) || $min < 3) {
            $errors[] = "$at.min_participants muss eine ganze Zahl ≥ 3 sein.";
            $min = 3;
        }
        $max = $r['max_participants'] ?? null;
        if ($max !== null && (!is_int($max) || $max < $min)) {
            $errors[] = "$at.max_participants muss leer oder eine ganze Zahl ≥ min_participants sein.";
        }
        $retention = $r['data_retention_days'] ?? 30;
        if (!is_int($retention) || $retention < 0) {
            $errors[] = "$at.data_retention_days muss eine ganze Zahl ≥ 0 sein.";
            $retention = 30;
        }
        $purge = $ship ? $ship->modify("+$retention days") : null;
        if ($purge && $reveal && $reveal >= $purge) {
            $errors[] = "$at.reveal_at muss vor der Datenlöschung ($retention Tage nach Versandfrist) liegen.";
        }

        if (!$reg || !$ship || !$reveal || !isset(Carriers::COUNTRIES[$country]) || !is_string($name)) {
            return null;
        }
        return new Round(
            $slug,
            $country,
            Carriers::COUNTRIES[$country]['name'],
            $name,
            (string) ($r['description'] ?? ''),
            $reg,
            $ship,
            $reminders,
            $reveal,
            $min,
            $max,
            $retention,
            $purge,
        );
    }

    /** Validiert die rohe Konfiguration und liefert ein Config-Objekt oder wirft ConfigError mit allen Fehlern. */
    public static function build(array $raw, string $baseDir = '.'): self
    {
        $errors = [];

        $rounds = [];
        if (!isset($raw['rounds']) || !is_array($raw['rounds']) || $raw['rounds'] === []) {
            $errors[] = 'rounds muss mindestens eine Runde enthalten.';
        } else {
            foreach (array_values($raw['rounds']) as $i => $r) {
                $round = self::buildRound($r, $i, $errors);
                if ($round) {
                    $rounds[] = $round;
                }
            }
        }
        $slugs = [];
        foreach ($rounds as $r) {
            if (isset($slugs[$r->slug])) {
                $errors[] = "Der slug \"{$r->slug}\" ist doppelt vergeben.";
            }
            $slugs[$r->slug] = true;
        }

        $organizer = strtolower(trim((string) ($raw['organizer_email'] ?? '')));
        if (!Validation::isEmail($organizer)) {
            $errors[] = 'organizer_email fehlt oder ist ungültig.';
        }

        $baseUrl = rtrim(trim((string) ($raw['base_url'] ?? '')), '/');
        $parts = parse_url($baseUrl);
        if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || (($parts['path'] ?? '') !== '')) {
            $errors[] = 'base_url fehlt oder ist keine gültige http(s)-URL ohne Pfad (z. B. https://wichtelberg.steinforth.com).';
        }

        $mail = is_array($raw['mail'] ?? null) ? $raw['mail'] : [];
        $transport = (string) ($mail['transport'] ?? 'mail');
        if (!in_array($transport, ['mail', 'smtp', 'log'], true)) {
            $errors[] = 'mail.transport muss "mail", "smtp" oder "log" sein.';
        }
        $from = (string) ($mail['from'] ?? '');
        $fromAddress = preg_match('/<([^>]+)>/', $from, $m) ? $m[1] : trim($from);
        if (!Validation::isEmail(strtolower($fromAddress))) {
            $errors[] = 'mail.from fehlt oder ist ungültig (z. B. "Wichtelberg <wichteln@steinforth.com>").';
        }
        $smtp = null;
        if ($transport === 'smtp') {
            $smtp = is_array($mail['smtp'] ?? null) ? $mail['smtp'] : [];
            foreach (['host', 'port', 'username', 'password'] as $k) {
                if (empty($smtp[$k])) {
                    $errors[] = "mail.smtp.$k fehlt.";
                }
            }
            $smtp['encryption'] = $smtp['encryption'] ?? 'ssl';
            if (!in_array($smtp['encryption'], ['ssl', 'tls'], true)) {
                $errors[] = 'mail.smtp.encryption muss "ssl" (Port 465) oder "tls" (STARTTLS, Port 587) sein.';
            }
        }

        $secrets = is_array($raw['secrets'] ?? null) ? $raw['secrets'] : [];
        $secretKey = self::decodeKey($secrets['secret_key'] ?? null, 'secret_key', $errors);
        $dataKey = self::decodeKey($secrets['data_key'] ?? null, 'data_key', $errors);
        $tasksToken = isset($secrets['tasks_token']) && $secrets['tasks_token'] !== '' ? (string) $secrets['tasks_token'] : null;

        $dataDir = (string) ($raw['data_dir'] ?? 'data');
        if ($dataDir !== '' && $dataDir[0] !== '/') {
            $dataDir = $baseDir . '/' . $dataDir;
        }

        $legal = is_array($raw['legal'] ?? null) ? $raw['legal'] : [];

        if ($errors) {
            throw new ConfigError("Ungültige Konfiguration:\n  - " . implode("\n  - ", $errors));
        }

        return new self(
            $rounds,
            $organizer,
            $baseUrl,
            str_starts_with($baseUrl, 'https://'),
            ($raw['trust_proxy'] ?? false) === true,
            $dataDir,
            (int) ($raw['web_cron_seconds'] ?? 60),
            $tasksToken,
            $transport,
            $from,
            $fromAddress,
            $smtp,
            (string) ($legal['operator'] ?? ''),
            (string) ($legal['imprint'] ?? ''),
            $secretKey,
            $dataKey,
            array_key_exists('banner', $raw) ? trim((string) $raw['banner']) : self::DEFAULT_BANNER,
        );
    }

    public function round(string $slug): ?Round
    {
        foreach ($this->rounds as $r) {
            if ($r->slug === $slug) {
                return $r;
            }
        }
        return null;
    }
}
