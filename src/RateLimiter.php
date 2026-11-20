<?php
declare(strict_types=1);

namespace Wichtelberg;

/**
 * Rate-Limit mit festen Zeitfenstern, gespeichert in einer kleinen Datei
 * (PHP hält zwischen Anfragen keinen Arbeitsspeicher). Schlüssel werden per HMAC
 * gehasht, damit dort weder IP- noch E-Mail-Adressen im Klartext liegen.
 */
final class RateLimiter
{
    public function __construct(private string $file, private string $secret, private \Closure $now)
    {
    }

    /** Zählt einen Treffer; true = erlaubt, false = Limit überschritten. */
    public function hit(string $key, int $limit, int $windowSeconds): bool
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $fh = fopen($this->file, 'c+');
        if ($fh === false || !flock($fh, LOCK_EX)) {
            return true; // im Zweifel nicht blockieren
        }
        try {
            $raw = stream_get_contents($fh);
            $data = $raw ? (json_decode($raw, true) ?: []) : [];
            $t = ($this->now)()->getTimestamp();
            // aufräumen
            foreach ($data as $k => $e) {
                if ($t - $e[0] >= $e[2]) {
                    unset($data[$k]);
                }
            }
            $h = substr(hash_hmac('sha256', $key, $this->secret), 0, 32);
            if (!isset($data[$h])) {
                $data[$h] = [$t, 0, $windowSeconds];
            }
            $data[$h][1]++;
            $allowed = $data[$h][1] <= $limit;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($data));
            fflush($fh);
            return $allowed;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}
