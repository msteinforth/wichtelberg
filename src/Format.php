<?php
declare(strict_types=1);

namespace Wichtelberg;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class Format
{
    private static function berlin(DateTimeInterface $d): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($d)->setTimezone(new DateTimeZone('Europe/Berlin'));
    }

    /** "01.12.2026, 23:59 Uhr" */
    public static function dateTime(DateTimeInterface $d): string
    {
        return self::berlin($d)->format('d.m.Y, H:i') . ' Uhr';
    }

    /** Kalendertag in Europe/Berlin als YYYY-MM-DD (für Backups). */
    public static function berlinDay(DateTimeInterface $d): string
    {
        return self::berlin($d)->format('Y-m-d');
    }

    /** "noch 3 Tage", "noch 5 Stunden", "noch 12 Minuten" */
    public static function timeLeft(DateTimeInterface $until, DateTimeInterface $now): string
    {
        $sec = $until->getTimestamp() - $now->getTimestamp();
        if ($sec <= 0) {
            return 'abgelaufen';
        }
        $min = intdiv($sec, 60);
        if ($min < 60) {
            return 'noch ' . $min . ($min === 1 ? ' Minute' : ' Minuten');
        }
        $h = intdiv($min, 60);
        if ($h < 48) {
            return 'noch ' . $h . ($h === 1 ? ' Stunde' : ' Stunden');
        }
        return 'noch ' . intdiv($h, 24) . ' Tage';
    }

    public static function address(array $p, string $countryName): string
    {
        $a = $p['address'];
        $cityLine = "{$a['postal_code']} {$a['city']}" . (!empty($a['province']) ? " ({$a['province']})" : '');
        return implode("\n", array_filter([
            "{$p['first_name']} {$p['last_name']}",
            $a['extra'] ?? null,
            "{$a['street']} {$a['house_number']}",
            $cityLine,
            $countryName,
        ]));
    }
}
