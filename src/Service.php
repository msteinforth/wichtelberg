<?php
declare(strict_types=1);

namespace Wichtelberg;

use DateTimeImmutable;

final class UserError extends \RuntimeException
{
    public function __construct(public readonly string $codeName, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $codeName);
    }
}

/**
 * Fachlogik einer Wichtelrunde (eine Runde = ein Land = eine Datendatei).
 * Token sind zustandslos signiert und an die Runde gebunden (Zweck enthält den slug).
 */
final class Service
{
    private const MIN = 60;
    private const HOUR = 3600;
    private const DAY = 86400;

    public readonly Mails $mails;

    public function __construct(
        public readonly Config $config,
        public readonly Round $round,
        public readonly Store $store,
        public readonly Mailer $mailer,
        public readonly RateLimiter $limiter,
        private \Closure $clock,
        private ?\Closure $logger = null,
    ) {
        $this->mails = new Mails($round, $this->url(''));
    }

    public function now(): DateTimeImmutable
    {
        return ($this->clock)();
    }

    private function ts(): int
    {
        return $this->now()->getTimestamp();
    }

    private function log(string $msg): void
    {
        if ($this->logger) {
            ($this->logger)($msg);
        } else {
            error_log("[wichtelberg/{$this->round->slug}] $msg");
        }
    }

    // ---------- Lesen ----------

    public function data(): array
    {
        return $this->store->data();
    }

    public function status(): string
    {
        return $this->data()['status'];
    }

    /** @return array[] */
    public function participants(): array
    {
        return $this->data()['participants'];
    }

    public function find(?string $id): ?array
    {
        foreach ($this->participants() as $p) {
            if ($p['id'] === $id) {
                return $p;
            }
        }
        return null;
    }

    public function findByEmail(string $email): ?array
    {
        foreach ($this->participants() as $p) {
            if ($p['email'] === $email) {
                return $p;
            }
        }
        return null;
    }

    /** Wer beschenkt $id? */
    public function santaOf(string $id): ?array
    {
        foreach ($this->participants() as $p) {
            if (($p['giftee_id'] ?? null) === $id) {
                return $p;
            }
        }
        return null;
    }

    public function isRegistrationOpen(): bool
    {
        return $this->status() === 'OPEN' && $this->now() < $this->round->registrationDeadline;
    }

    public function confirmedCount(): int
    {
        return count(array_filter($this->participants(), fn ($p) => $p['email_confirmed_at'] !== null));
    }

    // ---------- Links & Token ----------

    public function base(): string
    {
        return '/' . $this->round->slug;
    }

    public function url(string $path): string
    {
        return $this->config->baseUrl . $this->base() . $path;
    }

    private function purpose(string $kind): string
    {
        return "$kind:{$this->round->slug}";
    }

    /** Persönlicher Link aus E-Mails: meldet an und führt zum Dashboard. */
    public function personalLink(string $id, int $validSeconds = 7 * self::DAY): string
    {
        $t = Crypto::signToken($this->config->secretKey, $this->purpose('login'), $id, $this->ts() + $validSeconds);
        return $this->url('/login/link?t=' . rawurlencode($t));
    }

    public function sessionToken(string $id): string
    {
        return Crypto::signToken($this->config->secretKey, $this->purpose('session'), $id, $this->ts() + 30 * self::DAY);
    }

    /** @return array|null Teilnehmer der Session */
    public function verifySession(mixed $token): ?array
    {
        $id = Crypto::verifyToken($this->config->secretKey, $this->purpose('session'), $token, $this->ts());
        return $id ? $this->find($id) : null;
    }

    public function verifyLoginToken(mixed $token): ?string
    {
        $id = Crypto::verifyToken($this->config->secretKey, $this->purpose('login'), $token, $this->ts());
        return $id && $this->find($id) ? $id : null;
    }

    // ---------- Mailversand ----------

    /** Max. 3 Anmelde-/Login-Mails pro Stunde und Adresse (Schutz vor E-Mail-Bombing). */
    private function throttled(string $email): bool
    {
        return !$this->limiter->hit("mail:$email", 3, self::HOUR);
    }

    private function send(string $to, array $msg): void
    {
        $this->mailer->send($to, $msg[0], $msg[1]);
    }

    /** Sendet eine Mail und vermerkt sie danach am Teilnehmer (höchstens einmal pro Marke). */
    private function sendOnce(string $participantId, string $mark, callable $build): bool
    {
        $p = $this->find($participantId);
        if (!$p || in_array($mark, $p['mails_sent'], true)) {
            return false;
        }
        $msg = $build($p);
        if (!$msg) {
            return false;
        }
        try {
            $this->send($p['email'], $msg);
        } catch (\Throwable $e) {
            $this->log("Mail $mark an $participantId fehlgeschlagen: {$e->getMessage()}");
            return false;
        }
        $this->store->update(function (array &$d) use ($participantId, $mark) {
            foreach ($d['participants'] as &$x) {
                if ($x['id'] === $participantId && !in_array($mark, $x['mails_sent'], true)) {
                    $x['mails_sent'][] = $mark;
                }
            }
        });
        return true;
    }

    private function sendConfirmMail(array $p): void
    {
        $expiry = min($this->ts() + 48 * self::HOUR, $this->round->registrationDeadline->getTimestamp());
        $t = Crypto::signToken($this->config->secretKey, $this->purpose('confirm'), $p['id'], $expiry);
        $this->send($p['email'], $this->mails->confirm($p, $this->url('/bestaetigen?t=' . rawurlencode($t)), (new DateTimeImmutable())->setTimestamp($expiry)));
    }

    private function sendLoginMail(array $p): void
    {
        $this->send($p['email'], $this->mails->login($p, $this->personalLink($p['id'], 30 * self::MIN)));
    }

    private function updateParticipantRecord(array &$d, string $id, callable $fn): void
    {
        foreach ($d['participants'] as &$x) {
            if ($x['id'] === $id) {
                $fn($x);
                return;
            }
        }
        throw new UserError('not_found', 'Teilnehmer nicht gefunden.');
    }

    // ---------- Anmeldung ----------

    /**
     * Neuanmeldung. Liefert bei Erfolg immer dasselbe Ergebnis – unabhängig davon,
     * ob die Adresse schon existiert (keine Enumeration).
     * @return array{ok?: bool, errors?: array, values?: array}
     */
    public function register(array $form): array
    {
        if (!$this->isRegistrationOpen()) {
            throw new UserError('closed', 'Die Anmeldung ist geschlossen.');
        }
        [$values, $errors] = Validation::participantForm($form, $this->round->country);
        if ($errors) {
            return ['errors' => $errors, 'values' => $values];
        }

        $existing = $this->findByEmail($values['email']);
        if ($existing) {
            if (!$this->throttled($values['email'])) {
                try {
                    $existing['email_confirmed_at'] ? $this->sendLoginMail($existing) : $this->sendConfirmMail($existing);
                } catch (\Throwable $e) {
                    $this->log("Mail an bestehende Anmeldung fehlgeschlagen: {$e->getMessage()}");
                }
            }
            return ['ok' => true];
        }

        $max = $this->round->maxParticipants;
        if ($max !== null && $this->confirmedCount() >= $max) {
            throw new UserError('full', 'Die maximale Teilnehmerzahl ist erreicht.');
        }

        $now = $this->now()->format(DATE_ATOM);
        $participant = $this->store->update(function (array &$d) use ($values, $now) {
            foreach ($d['participants'] as $p) {
                if ($p['email'] === $values['email']) {
                    return null; // parallel angelegt
                }
            }
            $p = [
                'id' => Crypto::randomId(),
                'email' => $values['email'],
                'first_name' => $values['first_name'],
                'last_name' => $values['last_name'],
                'address' => Validation::address($values),
                'note' => $values['note'] !== '' ? $values['note'] : null,
                'adult_confirmed' => true,
                'consent_at' => $now,
                'created_at' => $now,
                'email_confirmed_at' => null,
                'giftee_id' => null,
                'shipment' => null,
                'reveal' => false,
                'mails_sent' => [],
            ];
            $d['participants'][] = $p;
            return $p;
        });
        if ($participant && !$this->throttled($participant['email'])) {
            try {
                $this->sendConfirmMail($participant);
            } catch (\Throwable $e) {
                $this->log("Bestätigungsmail fehlgeschlagen: {$e->getMessage()}");
                throw new UserError('mail', 'Die Bestätigungs-E-Mail konnte nicht versendet werden. Bitte versuche es später erneut.');
            }
        }
        return ['ok' => true];
    }

    /** Double-Opt-in. Liefert die Teilnehmer-ID. */
    public function confirm(mixed $token): string
    {
        $id = Crypto::verifyToken($this->config->secretKey, $this->purpose('confirm'), $token, $this->ts());
        $p = $id ? $this->find($id) : null;
        if (!$p) {
            throw new UserError('invalid_link', 'Der Bestätigungslink ist ungültig oder abgelaufen.');
        }
        if (!$p['email_confirmed_at']) {
            if (!$this->isRegistrationOpen()) {
                throw new UserError('closed', 'Die Anmeldung ist bereits geschlossen.');
            }
            $now = $this->now()->format(DATE_ATOM);
            $this->store->update(fn (array &$d) => $this->updateParticipantRecord($d, $id, function (array &$x) use ($now) {
                $x['email_confirmed_at'] = $now;
            }));
            $this->sendOnce($id, 'WELCOME', fn ($x) => $this->mails->welcome($x, $this->personalLink($x['id'])));
        }
        return $id;
    }

    /** Magic Link anfordern. Antwort ist für den Aufrufer immer gleich. */
    public function requestLogin(mixed $rawEmail): void
    {
        $email = Validation::normalizeEmail($rawEmail);
        $p = $email !== '' ? $this->findByEmail($email) : null;
        if (!$p || $this->throttled($email)) {
            return;
        }
        try {
            if ($p['email_confirmed_at']) {
                $this->sendLoginMail($p);
            } elseif ($this->isRegistrationOpen()) {
                $this->sendConfirmMail($p);
            }
        } catch (\Throwable $e) {
            $this->log("Login-Mail fehlgeschlagen: {$e->getMessage()}");
        }
    }

    /** Eigene Daten ändern – nur bis zum Anmeldeschluss. */
    public function updateParticipant(string $id, array $form): array
    {
        if (!$this->isRegistrationOpen()) {
            throw new UserError('closed', 'Nach dem Anmeldeschluss können die Daten nicht mehr geändert werden.');
        }
        [$values, $errors] = Validation::participantForm($form, $this->round->country, false, false);
        if ($errors) {
            return ['errors' => $errors, 'values' => $values];
        }
        $this->store->update(fn (array &$d) => $this->updateParticipantRecord($d, $id, function (array &$x) use ($values) {
            $x['first_name'] = $values['first_name'];
            $x['last_name'] = $values['last_name'];
            $x['address'] = Validation::address($values);
            $x['note'] = $values['note'] !== '' ? $values['note'] : null;
        }));
        return ['ok' => true];
    }

    /** Abmelden: Daten werden sofort gelöscht. */
    public function unregister(string $id): void
    {
        if (!$this->isRegistrationOpen()) {
            throw new UserError('closed', 'Nach dem Anmeldeschluss ist eine Abmeldung nur über den Organisator möglich.');
        }
        $this->store->update(function (array &$d) use ($id) {
            $d['participants'] = array_values(array_filter($d['participants'], fn ($p) => $p['id'] !== $id));
        });
    }

    // ---------- Versand ----------

    public function submitShipment(string $id, array $form): array
    {
        if ($this->status() !== 'DRAWN') {
            throw new UserError('not_drawn', 'Die Auslosung hat noch nicht stattgefunden.');
        }
        $carrier = is_string($form['carrier'] ?? null) ? $form['carrier'] : '';
        $nr = Carriers::normalizeTrackingNumber(is_string($form['tracking_number'] ?? null) ? $form['tracking_number'] : '');
        $errors = [];
        if (!isset(Carriers::carriersFor($this->round->country)[$carrier])) {
            $errors['carrier'] = 'Bitte wähle einen Versanddienstleister.';
        }
        if ($nr === '') {
            $errors['tracking_number'] = 'Bitte gib die Sendungsnummer ein.';
        } elseif (!Carriers::isPlausibleTrackingNumber($nr)) {
            $errors['tracking_number'] = 'Die Sendungsnummer darf nur Buchstaben und Ziffern enthalten (6–40 Zeichen).';
        }
        if ($errors) {
            return ['errors' => $errors, 'values' => ['carrier' => $carrier, 'tracking_number' => Validation::clean($form['tracking_number'] ?? '')]];
        }
        $p = $this->find($id);
        if ($p && $p['shipment'] && $p['shipment']['carrier'] === $carrier && $p['shipment']['tracking_number'] === $nr) {
            return ['ok' => true];
        }
        $now = $this->now()->format(DATE_ATOM);
        $this->store->update(fn (array &$d) => $this->updateParticipantRecord($d, $id, function (array &$x) use ($carrier, $nr, $now) {
            $x['shipment'] = [
                'carrier' => $carrier,
                'tracking_number' => $nr,
                'submitted_at' => $x['shipment']['submitted_at'] ?? $now,
                'updated_at' => $now,
                'is_update' => $x['shipment'] !== null,
                'notified_at' => null,
            ];
        }));
        $this->notifyShipment($id);
        return ['ok' => true];
    }

    /** Benachrichtigt den Empfänger (M-7). Bei Fehlschlag holt runTasks() das nach. */
    public function notifyShipment(string $giverId): void
    {
        $giver = $this->find($giverId);
        if (!$giver || !$giver['shipment'] || $giver['shipment']['notified_at']) {
            return;
        }
        $receiver = $this->find($giver['giftee_id']);
        if (!$receiver) {
            return;
        }
        $s = $giver['shipment'];
        try {
            $this->send($receiver['email'], $this->mails->shipped($receiver, $s, $this->personalLink($receiver['id']), (bool) $s['is_update']));
        } catch (\Throwable $e) {
            $this->log("Versandbenachrichtigung an {$receiver['id']} fehlgeschlagen: {$e->getMessage()}");
            return;
        }
        $now = $this->now()->format(DATE_ATOM);
        $this->store->update(fn (array &$d) => $this->updateParticipantRecord($d, $giverId, function (array &$x) use ($s, $now) {
            if ($x['shipment'] && $x['shipment']['updated_at'] === $s['updated_at']) {
                $x['shipment']['notified_at'] = $now;
            }
        }));
    }

    // ---------- Aufdeckung ----------

    public function canChangeReveal(): bool
    {
        return $this->status() === 'DRAWN' && $this->now() < $this->round->revealAt;
    }

    public function isRevealTime(): bool
    {
        return $this->status() === 'DRAWN' && $this->now() >= $this->round->revealAt;
    }

    public function setReveal(string $id, bool $reveal): void
    {
        if (!$this->canChangeReveal()) {
            throw new UserError('reveal_closed', 'Die Aufdeckung kann nicht mehr geändert werden.');
        }
        $this->store->update(fn (array &$d) => $this->updateParticipantRecord($d, $id, function (array &$x) use ($reveal) {
            $x['reveal'] = $reveal;
        }));
    }

    /** Schenkender, falls aufgedeckt (nach revealAt, mit Zustimmung und gemeldetem Versand). */
    public function revealedSanta(string $receiverId): ?array
    {
        if (!$this->isRevealTime()) {
            return null;
        }
        $santa = $this->santaOf($receiverId);
        return $santa && $santa['reveal'] && $santa['shipment'] ? $santa : null;
    }

    // ---------- Zeitgesteuerte Aufgaben (zustandsbasiert, idempotent) ----------

    /** Führt alle fälligen Aufgaben aus. Läuft bereits ein anderer Prozess, wird übersprungen. */
    public function runTasks(): void
    {
        $dir = $this->config->dataDir;
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $lock = fopen("$dir/{$this->round->slug}.tasks.lock", 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return;
        }
        try {
            $this->doRunTasks();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function doRunTasks(): void
    {
        $now = $this->now();
        $r = $this->round;

        // Löschfrist erreicht: alle personenbezogenen Daten und Backups entfernen.
        if ($now >= $r->purgeAt) {
            if ($this->status() !== 'CLOSED') {
                $this->store->replace(['status' => 'CLOSED', 'purged_at' => $now->format(DATE_ATOM)] + Store::emptyData());
                $this->store->deleteBackups();
                $this->log('Löschfrist erreicht: Daten gelöscht.');
            }
            return;
        }

        // Frist nach Absage verlängert -> Anmeldung wieder öffnen.
        if ($this->status() === 'CANCELLED' && $now < $r->registrationDeadline) {
            $this->store->update(function (array &$d) {
                $d['status'] = 'OPEN';
                $d['cancel_notified'] = false;
                foreach ($d['participants'] as &$p) {
                    $p['mails_sent'] = array_values(array_filter($p['mails_sent'], fn ($m) => $m !== 'CANCELLED'));
                }
            });
        }

        // Anmeldeschluss erreicht -> auslosen.
        if ($this->status() === 'OPEN' && $now >= $r->registrationDeadline) {
            $this->store->update(function (array &$d) use ($r, $now) {
                if ($d['status'] !== 'OPEN') {
                    return;
                }
                $d['participants'] = array_values(array_filter($d['participants'], fn ($p) => $p['email_confirmed_at'] !== null));
                if (count($d['participants']) < $r->minParticipants) {
                    $d['status'] = 'CANCELLED';
                    return;
                }
                $pairs = Draw::cycle(array_column($d['participants'], 'id'));
                foreach ($d['participants'] as &$p) {
                    $p['giftee_id'] = $pairs[$p['id']];
                }
                $d['status'] = 'DRAWN';
                $d['drawn_at'] = $now->format(DATE_ATOM);
            });
            $this->log("Auslosung: Status {$this->status()}, " . count($this->participants()) . ' Teilnehmer.');
        }

        if ($this->status() === 'CANCELLED') {
            $count = count($this->participants());
            foreach ($this->participants() as $p) {
                $this->sendOnce($p['id'], 'CANCELLED', fn ($x) => $this->mails->cancelled($x, $count));
            }
            if (!$this->data()['cancel_notified']) {
                try {
                    $this->send($this->config->organizerEmail, $this->mails->cancelledOrganizer($count));
                    $this->store->update(function (array &$d) {
                        $d['cancel_notified'] = true;
                    });
                } catch (\Throwable $e) {
                    $this->log("Mail an Organisator fehlgeschlagen: {$e->getMessage()}");
                }
            }
        }

        if ($this->status() === 'DRAWN') {
            // Partner-Mails
            foreach ($this->participants() as $p) {
                $this->sendOnce($p['id'], 'DRAW', fn ($x) => $this->mails->draw($x, $this->find($x['giftee_id']), $this->personalLink($x['id'])));
            }
            // ausstehende Versandbenachrichtigungen nachholen
            foreach ($this->participants() as $p) {
                if ($p['shipment'] && !$p['shipment']['notified_at']) {
                    $this->notifyShipment($p['id']);
                }
            }
            // Erinnerungen – nur für Zeitpunkte nach der Auslosung
            $drawnAt = new DateTimeImmutable($this->data()['drawn_at']);
            foreach ($r->reminders as $i => $at) {
                if ($at > $now || $at <= $drawnAt) {
                    continue;
                }
                $isLate = $at > $r->shippingDeadline;
                foreach ($this->participants() as $p) {
                    if ($p['shipment']) {
                        continue;
                    }
                    $this->sendOnce($p['id'], "REMINDER_$i", fn ($x) => $x['shipment'] ? null
                        : $this->mails->reminder($x, $this->find($x['giftee_id']), $this->personalLink($x['id']), $isLate));
                }
            }
            // Aufdeckung
            if ($this->isRevealTime()) {
                foreach ($this->participants() as $santa) {
                    if (!$santa['reveal'] || !$santa['shipment']) {
                        continue;
                    }
                    $this->sendOnce($santa['giftee_id'], 'REVEAL', fn ($x) => $this->mails->reveal($x, $santa, $this->personalLink($x['id'])));
                }
            }
        }

        // tägliches Backup (verschlüsselt)
        $today = Format::berlinDay($now);
        if ($this->data()['last_backup'] !== $today) {
            $this->store->update(function (array &$d) use ($today) {
                $d['last_backup'] = $today;
            });
            $this->store->backup($today);
        }
    }
}
