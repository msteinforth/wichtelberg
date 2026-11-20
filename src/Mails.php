<?php
declare(strict_types=1);

namespace Wichtelberg;

/**
 * Alle E-Mail-Vorlagen (Nur-Text). Jede Methode liefert [Betreff, Text].
 */
final class Mails
{
    public function __construct(private Round $round, private string $roundUrl)
    {
    }

    private function footer(): string
    {
        return "\n\nFrohes Wichteln!\nDein Wichtelberg-Team\n\n--\n{$this->round->name}\n{$this->roundUrl}";
    }

    /** M-1 */
    public function confirm(array $p, string $url, \DateTimeInterface $expiry): array
    {
        return [
            "Bitte bestätige Deine Anmeldung: {$this->round->name}",
            "Hallo {$p['first_name']},\n\n"
            . "schön, dass Du beim {$this->round->name} mitmachen möchtest!\n\n"
            . "Bitte bestätige Deine E-Mail-Adresse über diesen Link:\n$url\n\n"
            . 'Erst nach der Bestätigung nimmst Du an der Auslosung teil. Der Link ist bis ' . Format::dateTime($expiry) . " gültig.\n\n"
            . 'Falls Du Dich nicht angemeldet hast, kannst Du diese E-Mail einfach ignorieren.'
            . $this->footer(),
        ];
    }

    /** M-2 */
    public function welcome(array $p, string $dashboardUrl): array
    {
        $r = $this->round;
        return [
            "Du bist dabei: {$r->name}",
            "Hallo {$p['first_name']},\n\n"
            . "Deine Anmeldung ist bestätigt. Bitte prüfe noch einmal Deine Lieferadresse:\n\n"
            . Format::address($p, $r->countryName) . "\n\n"
            . "Die wichtigsten Termine:\n"
            . '- Anmeldeschluss und Auslosung: ' . Format::dateTime($r->registrationDeadline) . "\n"
            . '- Versandfrist: ' . Format::dateTime($r->shippingDeadline) . "\n\n"
            . "Bis zum Anmeldeschluss kannst Du Deine Daten hier ändern oder Dich abmelden:\n$dashboardUrl"
            . $this->footer(),
        ];
    }

    /** M-3 */
    public function login(array $p, string $url): array
    {
        return [
            "Dein Anmeldelink: {$this->round->name}",
            "Hallo {$p['first_name']},\n\n"
            . "hier ist Dein persönlicher Anmeldelink (30 Minuten gültig):\n$url\n\n"
            . 'Falls Du keinen Link angefordert hast, kannst Du diese E-Mail ignorieren.'
            . $this->footer(),
        ];
    }

    /** M-5 */
    public function draw(array $p, array $giftee, string $dashboardUrl): array
    {
        $r = $this->round;
        return [
            'Die Lose sind gezogen – Dein Wichtelpartner steht fest 🍷',
            "Hallo {$p['first_name']},\n\n"
            . "die Auslosung ist erfolgt. Du beschenkst:\n\n"
            . Format::address($giftee, $r->countryName) . "\n\n"
            . (!empty($giftee['note']) ? "Hinweis Deines Wichtelpartners: „{$giftee['note']}“\n\n" : '')
            . 'Bitte verschicke Deinen Wein bis ' . Format::dateTime($r->shippingDeadline)
            . " und trage anschließend Versanddienstleister und Sendungsnummer ein:\n$dashboardUrl\n\n"
            . "Dort kannst Du auch festlegen, ob Dein Wichtelpartner nach Abschluss erfahren darf, dass der Wein von Dir kam.\n\n"
            . 'Tipp: Verpacke die Flasche bruchsicher (z. B. im Flaschenkarton) und beachte die Versandbedingungen '
            . 'Deines Versanddienstleisters für Alkohol und Glas. Bis dahin gilt: Wer wen beschenkt, bleibt geheim!'
            . $this->footer(),
        ];
    }

    /** M-6 */
    public function cancelled(array $p, int $count): array
    {
        return [
            "Die Auslosung konnte nicht stattfinden: {$this->round->name}",
            "Hallo {$p['first_name']},\n\n"
            . "leider haben sich bis zum Anmeldeschluss nur $count Teilnehmer bestätigt angemeldet. "
            . "Für eine Auslosung sind mindestens {$this->round->minParticipants} nötig.\n\n"
            . 'Der Organisator kann die Anmeldefrist verlängern – Du wirst dann informiert.'
            . $this->footer(),
        ];
    }

    /** M-6 (an Organisator) */
    public function cancelledOrganizer(int $count): array
    {
        return [
            "[Organisator] Auslosung nicht möglich: {$this->round->name}",
            "Bis zum Anmeldeschluss haben sich nur $count Teilnehmer bestätigt angemeldet (Minimum: {$this->round->minParticipants}).\n\n"
            . "Um die Anmeldung wieder zu öffnen, setze registration_deadline (und ggf. die weiteren Fristen) der Runde \"{$this->round->slug}\" "
            . 'in config.php auf einen späteren Zeitpunkt.'
            . $this->footer(),
        ];
    }

    /** M-7 */
    public function shipped(array $receiver, array $shipment, string $dashboardUrl, bool $isUpdate): array
    {
        $c = $this->round->country;
        return [
            $isUpdate ? 'Aktualisierte Sendungsdaten für Deinen Wichtel-Wein 🍷' : 'Dein Wichtel-Wein ist unterwegs 🍷',
            "Hallo {$receiver['first_name']},\n\n"
            . ($isUpdate ? "Dein Wichtel hat die Sendungsdaten für Dein Paket korrigiert.\n\n" : "gute Nachrichten: Dein Wichtel hat Dir eine Flasche Wein geschickt!\n\n")
            . 'Versanddienstleister: ' . Carriers::label($c, $shipment['carrier']) . "\n"
            . "Sendungsnummer: {$shipment['tracking_number']}\n"
            . 'Sendung verfolgen: ' . Carriers::trackingUrl($c, $shipment['carrier'], $shipment['tracking_number']) . "\n\n"
            . "Wer Dich beschenkt, bleibt vorerst geheim.\n\n"
            . "Zu Deiner Übersicht:\n$dashboardUrl"
            . $this->footer(),
        ];
    }

    /** M-8 */
    public function reminder(array $p, array $giftee, string $dashboardUrl, bool $isLate): array
    {
        $deadline = Format::dateTime($this->round->shippingDeadline);
        return [
            $isLate ? 'Dein Wichtelpartner wartet noch auf seinen Wein' : 'Erinnerung: Bitte trage Deine Sendungsnummer ein',
            "Hallo {$p['first_name']},\n\n"
            . "für Dein Paket an {$giftee['first_name']} {$giftee['last_name']} ist noch keine Sendungsnummer hinterlegt. "
            . ($isLate ? "Die Versandfrist ist am $deadline abgelaufen.\n\n" : "Die Versandfrist endet am $deadline.\n\n")
            . 'Falls Du schon verschickt hast: Trage Versanddienstleister und Sendungsnummer bitte hier ein, '
            . "damit Dein Wichtelpartner Bescheid bekommt:\n$dashboardUrl\n\n"
            . 'Die Adresse findest Du ebenfalls dort.'
            . $this->footer(),
        ];
    }

    /** M-11: Aufdeckung (nur wenn der Schenkende zugestimmt hat) */
    public function reveal(array $receiver, array $santa, string $dashboardUrl): array
    {
        return [
            'Das Geheimnis ist gelüftet: Von wem kam Dein Wein? 🍷',
            "Hallo {$receiver['first_name']},\n\n"
            . "das Wichteln ist vorbei – und Dein Wichtel möchte sich zu erkennen geben:\n\n"
            . "Dein Wein kam von {$santa['first_name']} {$santa['last_name']}.\n\n"
            . "Vielleicht magst Du Dich ja bedanken? 😊\n\n"
            . "Zu Deiner Übersicht:\n$dashboardUrl"
            . $this->footer(),
        ];
    }

    public static function adminLogin(string $url): array
    {
        return [
            '[Organisator] Dein Anmeldelink für Wichtelberg',
            "Hier ist Dein Anmeldelink für die Organisator-Übersicht (30 Minuten gültig):\n$url\n\n"
            . 'Falls Du keinen Link angefordert hast, ignoriere diese E-Mail.',
        ];
    }
}
