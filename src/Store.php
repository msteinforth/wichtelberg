<?php
declare(strict_types=1);

namespace Wichtelberg;

/**
 * Datenhaltung in einer verschlüsselten JSON-Datei pro Runde (keine Datenbank).
 *
 * PHP arbeitet pro Anfrage in einem eigenen Prozess. Darum:
 * - Schreibzugriffe laufen exklusiv über flock() auf eine Sperrdatei,
 *   lesen den aktuellen Stand frisch ein, ändern ihn und schreiben atomar (tmp + rename).
 * - Lesezugriffe brauchen keine Sperre, weil rename() atomar ist.
 */
final class Store
{
    public const VERSION = 1;

    private string $file;
    private string $lockFile;
    private string $backupDir;
    private ?array $cache = null;

    public function __construct(private string $dataDir, private string $key, private string $name)
    {
        $this->file = "$dataDir/$name.json.enc";
        $this->lockFile = "$dataDir/$name.lock";
        $this->backupDir = "$dataDir/backups";
    }

    public static function emptyData(): array
    {
        return ['version' => self::VERSION, 'status' => 'OPEN', 'drawn_at' => null, 'cancel_notified' => false, 'last_backup' => null, 'purged_at' => null, 'participants' => []];
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dataDir) && !mkdir($this->dataDir, 0700, true) && !is_dir($this->dataDir)) {
            throw new \RuntimeException("Datenverzeichnis {$this->dataDir} kann nicht angelegt werden");
        }
        $ht = "{$this->dataDir}/.htaccess";
        if (!is_file($ht)) {
            @file_put_contents($ht, "Require all denied\nDeny from all\n");
        }
    }

    /** Aktueller Stand (innerhalb einer Anfrage zwischengespeichert). */
    public function data(): array
    {
        if ($this->cache === null) {
            $this->cache = $this->readFile();
        }
        return $this->cache;
    }

    private function readFile(): array
    {
        clearstatcache(true, $this->file);
        if (!is_file($this->file)) {
            return self::emptyData();
        }
        $text = file_get_contents($this->file);
        if ($text === false) {
            throw new \RuntimeException("Datendatei {$this->file} nicht lesbar");
        }
        $data = json_decode(Crypto::decrypt($this->key, $text), true, 512, JSON_THROW_ON_ERROR);
        self::validate($data);
        return $data;
    }

    /**
     * Führt $fn(&$data) exklusiv aus und speichert das Ergebnis. Wirft $fn, bleibt der alte Stand erhalten.
     * Der Rückgabewert von $fn wird durchgereicht.
     */
    public function update(callable $fn): mixed
    {
        $this->ensureDir();
        $lock = fopen($this->lockFile, 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Sperre der Datendatei fehlgeschlagen');
        }
        try {
            $data = $this->readFile();
            $result = $fn($data);
            self::validate($data);
            $this->write($data);
            $this->cache = $data;
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function replace(array $newData): void
    {
        $this->update(function (array &$d) use ($newData) {
            $d = $newData;
        });
    }

    private function write(array $data): void
    {
        $tmp = $this->file . '.' . getmypid() . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $old = umask(0077);
        try {
            if (file_put_contents($tmp, Crypto::encrypt($this->key, $json), LOCK_EX) === false) {
                throw new \RuntimeException('Schreiben der Datendatei fehlgeschlagen');
            }
        } finally {
            umask($old);
        }
        @chmod($tmp, 0600);
        if (!rename($tmp, $this->file)) {
            @unlink($tmp);
            throw new \RuntimeException('Umbenennen der Datendatei fehlgeschlagen');
        }
    }

    /** Kopiert die verschlüsselte Datei nach backups/<name>-YYYY-MM-DD.json.enc und behält die letzten $keep. */
    public function backup(string $day, int $keep = 7): void
    {
        if (!is_file($this->file)) {
            return;
        }
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0700, true);
        }
        copy($this->file, "{$this->backupDir}/{$this->name}-$day.json.enc");
        @chmod("{$this->backupDir}/{$this->name}-$day.json.enc", 0600);
        $files = $this->listBackups();
        sort($files);
        foreach (array_slice($files, 0, max(0, count($files) - $keep)) as $f) {
            @unlink("{$this->backupDir}/$f");
        }
    }

    /** @return string[] */
    public function listBackups(): array
    {
        if (!is_dir($this->backupDir)) {
            return [];
        }
        $re = '/^' . preg_quote($this->name, '/') . '-\d{4}-\d{2}-\d{2}\.json\.enc$/';
        return array_values(array_filter(scandir($this->backupDir) ?: [], fn ($f) => (bool) preg_match($re, $f)));
    }

    public function deleteBackups(): void
    {
        foreach ($this->listBackups() as $f) {
            @unlink("{$this->backupDir}/$f");
        }
    }

    public function file(): string
    {
        return $this->file;
    }

    public static function validate(mixed $data): void
    {
        if (!is_array($data) || ($data['version'] ?? null) !== self::VERSION) {
            throw new \RuntimeException('Datendatei: unbekannte Version');
        }
        if (!in_array($data['status'] ?? null, ['OPEN', 'DRAWN', 'CANCELLED', 'CLOSED'], true)) {
            throw new \RuntimeException('Datendatei: ungültiger Status');
        }
        if (!is_array($data['participants'] ?? null)) {
            throw new \RuntimeException('Datendatei: participants fehlt');
        }
        $ids = [];
        $emails = [];
        foreach ($data['participants'] as $p) {
            if (isset($ids[$p['id']])) {
                throw new \RuntimeException("Datendatei: doppelte ID {$p['id']}");
            }
            if (isset($emails[$p['email']])) {
                throw new \RuntimeException('Datendatei: doppelte E-Mail-Adresse');
            }
            $ids[$p['id']] = true;
            $emails[$p['email']] = true;
        }
        if ($data['status'] === 'DRAWN') {
            $receivers = [];
            foreach ($data['participants'] as $p) {
                $g = $p['giftee_id'] ?? null;
                if (!$g || !isset($ids[$g]) || $g === $p['id']) {
                    throw new \RuntimeException('Datendatei: ungültige Zuordnung');
                }
                if (isset($receivers[$g])) {
                    throw new \RuntimeException('Datendatei: Empfänger doppelt zugeordnet');
                }
                $receivers[$g] = true;
            }
        }
    }
}
