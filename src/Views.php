<?php
declare(strict_types=1);

namespace Wichtelberg;

/** HTML-Escaping. */
function e(mixed $v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function nl2brE(string $v): string
{
    return nl2br(e($v), false);
}

/**
 * Serverseitig gerenderte Seiten (kein JavaScript).
 * $ctx: ['config' => Config, 'service' => ?Service, 'session' => ?array{participant?: array, admin?: bool}]
 */
final class Views
{
    private const STATUS_LABEL = [
        'OPEN' => 'Anmeldung offen',
        'DRAWN' => 'Ausgelost – Versandphase',
        'CANCELLED' => 'Abgesagt (zu wenig Teilnehmer)',
        'CLOSED' => 'Abgeschlossen, Daten gelöscht',
    ];

    public static function layout(array $ctx, string $title, string $body, string $hero = ''): string
    {
        $service = $ctx['service'] ?? null;
        $session = $ctx['session'] ?? null;
        $round = $service?->round;
        $base = $service?->base();
        $brand = '<a class="brand" href="' . e($round ? $base : '/') . '"><span class="brand-name">Wichtelberg</span><span class="brand-sub">'
            . e($round ? $round->countryName : 'Wein-Wichteln') . '</span></a>';
        $nav = '';
        if (!empty($session['admin'])) {
            $nav = '<a href="/admin">Übersicht</a><form method="post" action="/admin/logout" class="inline"><button class="link">Abmelden</button></form>';
        } elseif ($round) {
            $nav = !empty($session['participant'])
                ? '<a href="' . e("$base/dashboard") . '">Mein Bereich</a><form method="post" action="' . e("$base/logout") . '" class="inline"><button class="link">Abmelden</button></form>'
                : '<a href="' . e("$base/login") . '">Anmelden</a>';
        }
        $site = $round ? $round->name : 'Wichtelberg';
        return '<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>' . e($title) . ' · ' . e($site) . '</title>
<link rel="stylesheet" href="/style.css">
</head>
<body>
<header class="top"><div class="wrap">' . $brand . '<nav>' . $nav . '</nav></div></header>
' . $hero . '
<main class="wrap">
' . $body . '
</main>
<footer class="foot"><div class="wrap"><a href="/">Alle Runden</a><a href="/datenschutz">Datenschutz</a><a href="/impressum">Impressum</a></div></footer>
</body>
</html>';
    }

    private static function err(?array $errors, string $name): string
    {
        return isset($errors[$name]) ? '<span class="err">' . e($errors[$name]) . '</span>' : '';
    }

    private static function field(string $label, string $name, array $values, ?array $errors, array $o = []): string
    {
        $required = $o['required'] ?? true;
        $attrs = ' type="' . e($o['type'] ?? 'text') . '" name="' . e($name) . '" value="' . e($values[$name] ?? '') . '"';
        $attrs .= $required ? ' required' : '';
        foreach (['autocomplete', 'maxlength', 'inputmode', 'pattern'] as $a) {
            if (isset($o[$a])) {
                $attrs .= " $a=\"" . e($o[$a]) . '"';
            }
        }
        return '<label class="' . (isset($errors[$name]) ? 'invalid' : '') . '">' . e($label) . ($required ? '' : ' <small>(optional)</small>')
            . "<input$attrs>" . (isset($o['hint']) ? '<small>' . e($o['hint']) . '</small>' : '') . self::err($errors, $name) . '</label>';
    }

    private static function addressFields(string $country, array $v, ?array $errors): string
    {
        $c = Carriers::country($country);
        $len = $c['postal']['length'];
        return '
  <div class="row">' . self::field('Vorname', 'first_name', $v, $errors, ['autocomplete' => 'given-name', 'maxlength' => 80])
            . self::field('Nachname', 'last_name', $v, $errors, ['autocomplete' => 'family-name', 'maxlength' => 80]) . '</div>
  <div class="row"><div class="grow">' . self::field('Straße', 'street', $v, $errors, ['autocomplete' => 'address-line1', 'maxlength' => 120]) . '</div>
  <div class="small">' . self::field('Hausnr.', 'house_number', $v, $errors, ['maxlength' => 20]) . '</div></div>
  ' . self::field('Adresszusatz', 'extra', $v, $errors, ['required' => false, 'autocomplete' => 'address-line2', 'maxlength' => 120, 'hint' => 'z. B. c/o, Firma, Etage']) . '
  <div class="row"><div class="small">' . self::field($c['postal']['label'], 'postal_code', $v, $errors, ['autocomplete' => 'postal-code', 'maxlength' => $len, 'inputmode' => 'numeric', 'pattern' => "[0-9]{{$len}}"]) . '</div>
  <div class="grow">' . self::field('Ort', 'city', $v, $errors, ['autocomplete' => 'address-level2', 'maxlength' => 80]) . '</div>'
            . ($c['province'] ? '<div class="small">' . self::field('Provinz', 'province', $v, $errors, ['maxlength' => 2, 'hint' => 'z. B. MI']) . '</div>' : '') . '</div>
  <label>Land<input value="' . e($c['name']) . '" disabled></label>
  <label class="' . (isset($errors['note']) ? 'invalid' : '') . '">Hinweis für Deinen Wichtel <small>(optional)</small>
    <textarea name="note" maxlength="300" rows="2" placeholder="z. B. „lieber Rotwein“, „kein Riesling“">' . e($v['note'] ?? '') . '</textarea>' . self::err($errors, 'note') . '</label>';
    }

    private static function participantValues(array $p): array
    {
        return ['first_name' => $p['first_name'], 'last_name' => $p['last_name'], 'note' => $p['note'] ?? ''] + array_map(fn ($x) => $x ?? '', $p['address']);
    }

    private static function flash(?string $msg): string
    {
        return $msg ? '<p class="notice">' . e($msg) . '</p>' : '';
    }

    private static function checked(bool $c): string
    {
        return $c ? ' checked' : '';
    }

    // ---------- Seiten ----------

    public static function index(array $ctx, array $services): string
    {
        $items = '';
        foreach ($services as $s) {
            if ($s->status() === 'CLOSED') {
                continue;
            }
            $state = $s->isRegistrationOpen() ? 'Anmeldung bis ' . Format::dateTime($s->round->registrationDeadline) : self::STATUS_LABEL[$s->status()];
            $items .= '<li><a class="round" href="' . e($s->base()) . '"><span><span class="kicker">' . e($s->round->name) . '</span><strong>'
                . e($s->round->countryName) . '</strong></span><span><span class="meta">' . e($state) . '</span><br><span class="go">'
                . ($s->isRegistrationOpen() ? 'Jetzt mitmachen' : 'Zur Runde') . '</span></span></a></li>';
        }
        $hero = '<section class="hero"><div class="wrap">
      <span class="kicker">Wein-Wichteln per Post</span>
      <h1>Eine Flasche.<br>Ein Los.<br>Eine Überraschung.</h1>
      <p>Melde Dich an, bekomme einen Wichtelpartner zugelost und verschicke eine Flasche Wein, die Du selbst gern trinken würdest. Und dann: Lass Dich überraschen.</p>
    </div></section>
    <div class="tagline"><span class="kicker">Deutschland · Österreich · Schweiz · Italien</span></div>';
        $body = '<h2>Wähle Deine Runde</h2>'
            . ($items ? "<ul class=\"rounds\">$items</ul>" : '<p class="notice">Aktuell gibt es keine Wichtelrunde.</p>')
            . '<p class="meta">Organisator? <a href="/admin/login">Hier anmelden</a>.</p>';
        return self::layout($ctx, 'Wein-Wichteln', $body, $hero);
    }

    public static function round(array $ctx, array $values = [], ?array $errors = null, ?string $message = null): string
    {
        /** @var Service $service */
        $service = $ctx['service'];
        $r = $service->round;
        $base = $service->base();
        $status = $service->status();
        if ($service->isRegistrationOpen()) {
            if ($r->maxParticipants !== null && $service->confirmedCount() >= $r->maxParticipants) {
                $content = '<p class="notice">Die maximale Teilnehmerzahl ist erreicht.</p>';
            } else {
                $content = '<h2>Jetzt anmelden</h2>'
                    . ($errors ? '<p class="notice error">Bitte prüfe Deine Angaben.</p>' : '')
                    . '<form method="post" action="' . e("$base/anmelden") . '" class="card" novalidate>'
                    . self::field('E-Mail-Adresse', 'email', $values, $errors, ['type' => 'email', 'autocomplete' => 'email', 'maxlength' => 254])
                    . self::addressFields($r->country, $values, $errors)
                    . '<label class="hp" aria-hidden="true">Website<input name="website" tabindex="-1" autocomplete="off"></label>'
                    . '<label class="check ' . (isset($errors['adult']) ? 'invalid' : '') . '"><input type="checkbox" name="adult"' . self::checked(($values['adult'] ?? '') === 'on') . '><span>Ich bin mindestens 18 Jahre alt.</span>' . self::err($errors, 'adult') . '</label>'
                    . '<label class="check ' . (isset($errors['consent']) ? 'invalid' : '') . '"><input type="checkbox" name="consent"' . self::checked(($values['consent'] ?? '') === 'on') . '><span>Ich habe die <a href="/datenschutz">Datenschutzerklärung</a> gelesen und bin einverstanden, dass mein Name, meine Adresse und mein Hinweis nach der Auslosung an meinen Wichtelpartner weitergegeben werden.</span>' . self::err($errors, 'consent') . '</label>'
                    . '<button>Anmelden</button>'
                    . '<p><small>Du erhältst anschließend eine E-Mail mit einem Bestätigungslink. Bitte schau auch im Spam-Ordner nach.</small></p>'
                    . '</form>';
            }
        } elseif ($status === 'DRAWN') {
            $content = '<p class="notice">Die Anmeldung ist geschlossen und die Lose sind gezogen. Teilnehmer finden ihren Wichtelpartner in <a href="' . e("$base/dashboard") . '">ihrem Bereich</a>.</p>';
        } elseif ($status === 'CLOSED') {
            $content = '<p class="notice">Diese Wichtelrunde ist abgeschlossen. Danke fürs Mitmachen!</p>';
        } elseif ($status === 'CANCELLED') {
            $content = '<p class="notice">Die Auslosung konnte mangels Teilnehmern nicht stattfinden.</p>';
        } else {
            $content = '<p class="notice">Die Anmeldung ist geschlossen. Die Auslosung erfolgt in Kürze.</p>';
        }
        $meta = $status === 'OPEN'
            ? '<p class="meta">Anmeldeschluss: ' . e(Format::timeLeft($r->registrationDeadline, $service->now())) . ' · ' . $service->confirmedCount() . ' bestätigte Teilnehmer</p>'
            : '';
        $hero = '<section class="hero"><div class="wrap">
      <span class="kicker">Wein-Wichteln · Lieferadresse in ' . e($r->countryName) . '</span>
      <h1>' . e($r->name) . '</h1>
      ' . ($r->description !== '' ? '<p>' . nl2brE($r->description) . '</p>' : '') . '
    </div></section>';
        $body = '<ol class="steps">
        <li><strong>Anmelden</strong><br>bis ' . e(Format::dateTime($r->registrationDeadline)) . '</li>
        <li><strong>Auslosung</strong><br>Du erfährst per E-Mail, wen Du beschenkst.</li>
        <li><strong>Verschicken</strong><br>bis ' . e(Format::dateTime($r->shippingDeadline)) . ', dann Sendungsnummer eintragen.</li>
      </ol>' . $meta . self::flash($message) . $content;
        return self::layout($ctx, 'Wein-Wichteln', $body, $hero);
    }

    public static function message(array $ctx, string $title, string $text, ?array $link = null): string
    {
        return self::layout($ctx, $title, '<h1>' . e($title) . '</h1><p>' . e($text) . '</p>'
            . ($link ? '<p><a href="' . e($link[0]) . '">' . e($link[1]) . '</a></p>' : ''));
    }

    /** Zwischenseite mit Button – Links aus E-Mails lösen erst per POST etwas aus (Schutz vor Link-Scannern). */
    public static function confirm(array $ctx, string $token, string $action, string $title, string $text, string $button): string
    {
        return self::layout($ctx, $title, '<h1>' . e($title) . '</h1><p>' . e($text) . '</p>
    <form method="post" action="' . e($action) . '"><input type="hidden" name="t" value="' . e($token) . '"><button>' . e($button) . '</button></form>');
    }

    public static function login(array $ctx, string $action, bool $sent = false, string $title = 'Anmelden'): string
    {
        return self::layout($ctx, $title, '<h1>' . e($title) . '</h1>'
            . ($sent
                ? '<p class="notice">Falls die Adresse bei uns registriert ist, haben wir Dir einen Anmeldelink geschickt. Er ist 30 Minuten gültig.</p>'
                : '<p>Gib Deine E-Mail-Adresse ein. Wir schicken Dir einen Link, mit dem Du Dich ohne Passwort anmeldest.</p>')
            . '<form method="post" action="' . e($action) . '" class="card">
      <label>E-Mail-Adresse<input type="email" name="email" required autocomplete="email" maxlength="254"></label>
      <button>Link anfordern</button>
    </form>');
    }

    public static function dashboard(array $ctx, array $p, ?array $values = null, ?array $errors = null, ?string $message = null): string
    {
        /** @var Service $service */
        $service = $ctx['service'];
        $r = $service->round;
        $base = $service->base();
        $country = $r->country;
        $status = $service->status();
        $hello = '<h1>Hallo ' . e($p['first_name']) . '!</h1>' . self::flash($message);

        if ($status === 'OPEN') {
            $body = $hello . ($p['email_confirmed_at']
                ? '<p class="notice ok">Du bist angemeldet. Die Auslosung findet am <strong>' . e(Format::dateTime($r->registrationDeadline)) . '</strong> statt.</p>'
                : '<p class="notice">Bitte bestätige noch Deine E-Mail-Adresse über den Link in unserer E-Mail.</p>');
            if ($service->isRegistrationOpen()) {
                $body .= '<h2>Meine Daten</h2>
      <form method="post" action="' . e("$base/dashboard/daten") . '" class="card" novalidate>
        <label>E-Mail-Adresse<input value="' . e($p['email']) . '" disabled></label>'
                    . self::addressFields($country, $values ?? self::participantValues($p), $errors) . '
        <button>Speichern</button>
      </form>
      <h2>Abmelden</h2>
      <form method="post" action="' . e("$base/dashboard/abmelden") . '" class="card">
        <p>Du kannst Deine Teilnahme bis zum Anmeldeschluss zurückziehen. Deine Daten werden dabei sofort gelöscht.</p>
        <label class="check"><input type="checkbox" name="sure" required><span>Ja, ich möchte nicht mehr teilnehmen.</span></label>
        <button class="danger">Teilnahme zurückziehen</button>
      </form>';
            } else {
                $body .= '<p>Die Anmeldung ist geschlossen, die Auslosung läuft in Kürze.</p>';
            }
        } elseif ($status === 'DRAWN') {
            $giftee = $service->find($p['giftee_id']);
            $santa = $service->santaOf($p['id']);
            $s = $p['shipment'];
            $incoming = $santa['shipment'] ?? null;
            $revealed = $service->revealedSanta($p['id']);
            $v = $values ?? ($s ? ['carrier' => $s['carrier'], 'tracking_number' => $s['tracking_number']] : []);

            $options = '<option value="">Bitte wählen …</option>';
            foreach (Carriers::carriersFor($country) as $k => $c) {
                $options .= '<option value="' . e($k) . '"' . (($v['carrier'] ?? '') === $k ? ' selected' : '') . '>' . e($c['label']) . '</option>';
            }
            $link = fn (array $sh, string $text) => '<a href="' . e(Carriers::trackingUrl($country, $sh['carrier'], $sh['tracking_number'])) . '" rel="noopener noreferrer" target="_blank">' . e($text) . '</a>';

            $body = $hello . '
    <section class="card">
      <h2>Du beschenkst</h2>
      <address>' . nl2brE(Format::address($giftee, $r->countryName)) . '</address>
      ' . (!empty($giftee['note']) ? '<p class="quote">„' . e($giftee['note']) . '“</p>' : '') . '
      <p class="meta">Versandfrist: ' . e(Format::dateTime($r->shippingDeadline)) . '</p>
      <details><summary>Tipps zum Weinversand</summary>
        <ul><li>Flasche bruchsicher verpacken, am besten in einem Flaschenkarton.</li>
        <li>Paket als „Zerbrechlich“ kennzeichnen und die Versandbedingungen des Dienstleisters für Glas und Alkohol beachten.</li>
        <li>Alkohol darf nur an Erwachsene verschickt werden.</li></ul>
      </details>
    </section>
    <section class="card">
      <h2>' . ($s ? 'Versand gemeldet ✓' : 'Versand melden') . '</h2>'
                . ($s
                    ? '<p>' . e(Carriers::label($country, $s['carrier'])) . ' · ' . $link($s, $s['tracking_number']) . '<br><small>'
                        . ($s['notified_at'] ? 'Dein Wichtelpartner wurde benachrichtigt.' : 'Die Benachrichtigung Deines Wichtelpartners wird versendet.') . '</small></p><p>Korrigieren:</p>'
                    : '<p>Sobald Du Deinen Wein verschickt hast, trage hier Versanddienstleister und Sendungsnummer ein. Dein Wichtelpartner bekommt dann eine E-Mail mit dem Tracking-Link – ohne Deinen Namen.</p>')
                . '<form method="post" action="' . e("$base/dashboard/versand") . '" novalidate>
        <label class="' . (isset($errors['carrier']) ? 'invalid' : '') . '">Versanddienstleister<select name="carrier" required>' . $options . '</select>' . self::err($errors, 'carrier') . '</label>'
                . self::field('Sendungsnummer', 'tracking_number', $v, $errors, ['maxlength' => 60, 'autocomplete' => 'off']) . '
        <button>' . ($s ? 'Sendungsdaten aktualisieren' : 'Versand melden') . '</button>
      </form>
    </section>
    <section class="card">
      <h2>Aufdecken?</h2>'
                . ($service->canChangeReveal()
                    ? '<form method="post" action="' . e("$base/dashboard/aufdecken") . '">
          <p>Soll Dein Wichtelpartner am <strong>' . e(Format::dateTime($r->revealAt)) . '</strong> erfahren, dass der Wein von Dir kam? Du kannst das bis dahin jederzeit ändern.</p>
          <label class="check"><input type="radio" name="reveal" value="yes"' . self::checked((bool) $p['reveal']) . '><span>Ja, mein Name darf verraten werden.</span></label>
          <label class="check"><input type="radio" name="reveal" value="no"' . self::checked(!$p['reveal']) . '><span>Nein, ich bleibe geheim.</span></label>
          <button>Speichern</button></form>'
                    : '<p>' . ($p['reveal'] ? 'Du hast Dich zu erkennen gegeben.' : 'Du bleibst geheim.') . '</p>') . '
    </section>
    <section class="card">
      <h2>Dein Wein</h2>'
                . ($incoming
                    ? '<p>Dein Wichtel hat Dir etwas geschickt! 🍷<br>' . e(Carriers::label($country, $incoming['carrier'])) . ' · ' . $link($incoming, "Sendung {$incoming['tracking_number']} verfolgen") . '</p>'
                    : '<p>Noch keine Sendung gemeldet. Wir schicken Dir eine E-Mail, sobald Dein Wein unterwegs ist.</p>')
                . ($revealed ? '<p class="notice ok">Das Geheimnis ist gelüftet: Dein Wein kam von <strong>' . e("{$revealed['first_name']} {$revealed['last_name']}") . '</strong>.</p>' : '') . '
    </section>';
        } elseif ($status === 'CANCELLED') {
            $body = $hello . '<p class="notice">Die Auslosung konnte mangels Teilnehmern leider nicht stattfinden.</p>';
        } else {
            $body = '<h1>Diese Runde ist abgeschlossen.</h1>';
        }
        return self::layout($ctx, 'Mein Bereich', $body);
    }

    private static function adminRound(Service $s): string
    {
        $r = $s->round;
        $ps = $s->participants();
        usort($ps, fn ($a, $b) => strcoll($a['last_name'], $b['last_name']));
        $confirmed = count(array_filter($ps, fn ($p) => $p['email_confirmed_at']));
        $shipped = count(array_filter($ps, fn ($p) => $p['shipment']));
        $rows = '';
        foreach ($ps as $p) {
            $rem = count(array_filter($p['mails_sent'], fn ($m) => str_starts_with($m, 'REMINDER_')));
            $rows .= '<tr><td>' . e("{$p['first_name']} {$p['last_name']}") . '</td><td>' . e($p['email']) . '</td><td>' . ($p['email_confirmed_at'] ? '✓' : '–')
                . '</td><td>' . ($p['shipment'] ? '✓ ' . e(Carriers::label($r->country, $p['shipment']['carrier'])) : '–') . '</td><td>' . ($rem ?: '–') . '</td></tr>';
        }
        return '<section class="card">
    <h2>' . e($r->name) . ' <small class="meta">(' . e($r->countryName) . ', <a href="' . e($s->base()) . '">' . e($s->base()) . '</a>)</small></h2>
    <dl class="stats">
      <div><dt>Status</dt><dd>' . e(self::STATUS_LABEL[$s->status()]) . '</dd></div>
      <div><dt>Angemeldet</dt><dd>' . count($ps) . '</dd></div>
      <div><dt>Bestätigt</dt><dd>' . $confirmed . '</dd></div>
      <div><dt>Versand gemeldet</dt><dd>' . ($s->status() === 'DRAWN' ? $shipped . ' / ' . count($ps) : '–') . '</dd></div>
    </dl>
    <p class="meta">Anmeldeschluss: ' . e(Format::dateTime($r->registrationDeadline)) . ' · Versandfrist: ' . e(Format::dateTime($r->shippingDeadline))
            . ' · Aufdeckung: ' . e(Format::dateTime($r->revealAt)) . ' · Datenlöschung: ' . e(Format::dateTime($r->purgeAt)) . '</p>'
            . ($rows ? '<div class="scroll"><table><thead><tr><th>Name</th><th>E-Mail</th><th>Bestätigt</th><th>Versand</th><th>Erinnerungen</th></tr></thead><tbody>' . $rows . '</tbody></table></div>' : '')
            . '</section>';
    }

    public static function admin(array $ctx, array $services): string
    {
        return self::layout($ctx, 'Übersicht', '<h1>Übersicht</h1>' . implode('', array_map([self::class, 'adminRound'], $services))
            . '<p><small>Die Zuordnung der Wichtelpartner wird hier bewusst nicht angezeigt.</small></p>');
    }

    public static function adminLogin(array $ctx, bool $sent = false): string
    {
        return self::layout($ctx, 'Organisator', '<h1>Organisator-Anmeldung</h1>'
            . ($sent ? '<p class="notice">Falls die Adresse als Organisator hinterlegt ist, haben wir einen Anmeldelink geschickt.</p>' : '')
            . '<form method="post" action="/admin/login" class="card">
      <label>E-Mail-Adresse<input type="email" name="email" required autocomplete="email" maxlength="254"></label>
      <button>Link anfordern</button>
    </form>');
    }

    public static function privacy(array $ctx, array $services): string
    {
        /** @var Config $config */
        $config = $ctx['config'];
        $purge = '';
        foreach ($services as $s) {
            $purge .= '<li>' . e($s->round->name) . ': am ' . e(Format::dateTime($s->round->purgeAt)) . '</li>';
        }
        return self::layout($ctx, 'Datenschutz', '<h1>Datenschutzerklärung</h1>
    <h2>Verantwortlich</h2><p>' . ($config->legalOperator !== '' ? nl2brE($config->legalOperator) : 'Siehe Impressum.') . '</p>
    <h2>Welche Daten wir verarbeiten</h2>
    <p>Für die Teilnahme speichern wir Deine E-Mail-Adresse, Deinen Namen, Deine Lieferadresse und Deinen optionalen Hinweis für den Wichtel. Nach der Auslosung speichern wir außerdem, wen Du beschenkst, sowie Versanddienstleister und Sendungsnummer Deines Pakets.</p>
    <h2>Zweck und Rechtsgrundlage</h2>
    <p>Die Daten dienen ausschließlich der Durchführung des Wichtelns. Rechtsgrundlage ist Deine Einwilligung (Art. 6 Abs. 1 lit. a DSGVO). Name, Adresse und Hinweis werden nach der Auslosung an genau einen anderen Teilnehmer – Deinen Wichtelpartner – weitergegeben. Deine Sendungsnummer erhält Dein Wichtelpartner, Deinen Namen nur, wenn Du der Aufdeckung ausdrücklich zustimmst.</p>
    <h2>Speicherdauer</h2>
    <p>Alle personenbezogenen Daten einer Runde werden automatisch gelöscht, einschließlich Backups:</p>
    <ul>' . $purge . '</ul>
    <p>Nicht bestätigte Anmeldungen werden zum Anmeldeschluss gelöscht. Bis zum Anmeldeschluss kannst Du Deine Teilnahme jederzeit selbst zurückziehen; Deine Daten werden dann sofort gelöscht.</p>
    <h2>Cookies</h2>
    <p>Wir verwenden ausschließlich ein technisch notwendiges Cookie, das Deine Anmeldung speichert. Es gibt kein Tracking und keine Analyse-Werkzeuge.</p>
    <h2>Hosting und E-Mail</h2>
    <p>Die Website wird bei der STRATO GmbH in Deutschland gehostet; E-Mails werden über deren Server versendet.</p>
    <h2>Deine Rechte</h2>
    <p>Du hast das Recht auf Auskunft, Berichtigung, Löschung, Einschränkung der Verarbeitung, Datenübertragbarkeit sowie auf Widerruf Deiner Einwilligung mit Wirkung für die Zukunft. Wende Dich dazu an den Verantwortlichen. Außerdem kannst Du Dich bei einer Datenschutz-Aufsichtsbehörde beschweren.</p>');
    }

    public static function imprint(array $ctx): string
    {
        /** @var Config $config */
        $config = $ctx['config'];
        return self::layout($ctx, 'Impressum', '<h1>Impressum</h1><p>'
            . ($config->legalImprint !== '' ? nl2brE($config->legalImprint) : 'Bitte in config.php unter legal.imprint hinterlegen.') . '</p>');
    }
}
