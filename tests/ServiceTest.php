<?php
declare(strict_types=1);

namespace Wichtelberg\Tests;

use Wichtelberg\Store;
use Wichtelberg\UserError;

$code = fn (string $c) => fn ($e) => $e instanceof UserError && $e->codeName === $c;

T::test('Anmeldung mit Double-Opt-in', function () {
    $env = new Env(rawConfig());
    T::eq(['ok' => true], $env->service->register(sampleForm(1)));
    $p = $env->service->findByEmail('person1@example.org');
    T::eq(null, $p['email_confirmed_at']);
    T::eq(0, $env->service->confirmedCount());
    T::contains('bestätige', $env->mailer->outbox[0]['subject']);

    $id = $env->service->confirm($env->token('person1@example.org', '/de/bestaetigen'));
    T::eq($p['id'], $id);
    T::ok($env->service->find($id)['email_confirmed_at']);
    T::eq('Du bist dabei: Test-Wichteln', $env->lastMail()['subject']);
    T::contains('Hauptstraße 1', $env->lastMail()['text']);

    $env->reload(); // neue Anfrage
    $env->service->confirm($env->token('person1@example.org', '/de/bestaetigen'));
    T::eq(1, count(array_filter($env->mailsTo('person1@example.org'), fn ($m) => str_starts_with($m['subject'], 'Du bist dabei'))), 'keine zweite Willkommensmail');
    T::throws(fn () => $env->service->confirm('kaputt'));
});

T::test('Doppelte Anmeldung verrät nichts und überschreibt nichts', function () {
    $env = new Env(rawConfig());
    $env->registerConfirmed(1);
    $n = count($env->mailer->outbox);
    T::eq(['ok' => true], $env->service->register(sampleForm(1, ['email' => 'PERSON1@example.org', 'city' => 'Bonn'])));
    T::eq(1, count($env->service->participants()));
    T::eq('Köln', $env->service->participants()[0]['address']['city']);
    T::contains('Anmeldelink', $env->lastMail()['subject']);
    T::eq($n + 1, count($env->mailer->outbox));
});

T::test('Validierungsfehler: nichts gespeichert, keine Mail', function () {
    $env = new Env(rawConfig());
    $r = $env->service->register(sampleForm(1, ['postal_code' => 'ABCDE', 'consent' => '']));
    T::ok(isset($r['errors']['postal_code'], $r['errors']['consent']));
    T::eq(0, count($env->service->participants()));
    T::eq(0, count($env->mailer->outbox));
});

T::test('Nach Anmeldeschluss: Anmeldung, Bestätigung, Änderung gesperrt', function () use ($code) {
    $env = new Env(rawConfig());
    $env->service->register(sampleForm(1));
    $t = $env->token('person1@example.org', '/de/bestaetigen');
    $env->clock->set('2026-12-02T00:00:00+01:00');
    T::throws(fn () => $env->service->register(sampleForm(2)), $code('closed'));
    T::throws(fn () => $env->service->confirm($t));
    T::throws(fn () => $env->service->updateParticipant($env->service->participants()[0]['id'], sampleForm(1)), $code('closed'));
});

T::test('Daten ändern und abmelden vor Anmeldeschluss', function () {
    $env = new Env(rawConfig());
    [$id] = $env->registerConfirmed(1);
    T::eq(['ok' => true], $env->service->updateParticipant($id, sampleForm(1, ['city' => 'Bonn', 'postal_code' => '53111', 'email' => 'egal@example.org'])));
    T::eq('Bonn', $env->service->find($id)['address']['city']);
    T::eq('person1@example.org', $env->service->find($id)['email'], 'E-Mail nicht änderbar');
    T::ok(isset($env->service->updateParticipant($id, sampleForm(1, ['postal_code' => '1']))['errors']['postal_code']));
    $env->service->unregister($id);
    T::eq(null, $env->service->find($id));
});

T::test('Maximale Teilnehmerzahl', function () use ($code) {
    $env = new Env(rawConfig(['max_participants' => 3]));
    $env->registerConfirmed(3);
    T::throws(fn () => $env->service->register(sampleForm(4)), $code('full'));
});

T::test('Login-Anfrage und Mail-Drosselung (persistent über Anfragen)', function () {
    $env = new Env(rawConfig());
    $env->registerConfirmed(1);
    $n = count($env->mailer->outbox);
    $env->service->requestLogin('unbekannt@example.org');
    T::eq($n, count($env->mailer->outbox));
    $env->service->requestLogin('Person1@Example.org');
    $t = $env->token('person1@example.org', '/de/login/link');
    T::eq($env->service->participants()[0]['id'], $env->service->verifyLoginToken($t));
    for ($i = 0; $i < 5; $i++) {
        $env->reload();
        $env->service->requestLogin('person1@example.org');
    }
    T::eq($n + 2, count($env->mailer->outbox), 'max. 3 Mails/Stunde inkl. Anmeldemail');
    $env->clock->set('2026-11-20T12:31:00+01:00');
    T::eq(null, $env->service->verifyLoginToken($t), 'Login-Link 30 Minuten gültig');
});

T::test('Auslosung: Unbestätigte verworfen, jeder bekommt Partner, idempotent', function () {
    $env = new Env(rawConfig());
    $ids = $env->registerConfirmed(5);
    $env->service->register(sampleForm(9));
    $env->service->runTasks();
    T::eq('OPEN', $env->service->status());

    $env->clock->set('2026-12-02T00:00:00+01:00');
    $env->service->runTasks();
    T::eq('DRAWN', $env->service->status());
    T::eq(5, count($env->service->participants()));
    T::eq(null, $env->service->findByEmail('person9@example.org'));
    foreach ($ids as $id) {
        $p = $env->service->find($id);
        T::ok($p['giftee_id'] && $p['giftee_id'] !== $id);
        $g = $env->service->find($p['giftee_id']);
        $mail = array_values(array_filter($env->mailsTo($p['email']), fn ($m) => str_contains($m['subject'], 'Lose sind gezogen')));
        T::eq(1, count($mail));
        T::contains("{$g['first_name']} {$g['last_name']}", $mail[0]['text']);
        T::contains("{$g['address']['street']} {$g['address']['house_number']}", $mail[0]['text']);
    }
    $p1 = $env->service->findByEmail('person1@example.org');
    $santa = $env->service->santaOf($p1['id']);
    T::ok((bool) array_filter($env->mailsTo($santa['email']), fn ($m) => str_contains($m['text'], 'Lieber Rotwein')), 'Hinweis weitergegeben');

    $snapshot = json_encode(array_column($env->service->participants(), 'giftee_id', 'id'));
    $n = count($env->mailer->outbox);
    $env->reload();
    $env->service->runTasks();
    T::eq($snapshot, json_encode(array_column($env->service->participants(), 'giftee_id', 'id')));
    T::eq($n, count($env->mailer->outbox));
});

T::test('Zu wenig Teilnehmer: Absage, Wiedereröffnung bei Fristverlängerung', function () {
    $env = new Env(rawConfig());
    $env->registerConfirmed(2);
    $env->clock->set('2026-12-02T00:00:00+01:00');
    $env->service->runTasks();
    T::eq('CANCELLED', $env->service->status());
    T::eq(2, count(array_filter($env->mailer->outbox, fn ($m) => str_contains($m['subject'], 'nicht stattfinden'))));
    T::eq(1, count($env->mailsTo('orga@example.org')));
    $env->service->runTasks();
    T::eq(1, count($env->mailsTo('orga@example.org')), 'keine doppelte Absage');

    $env->reload(rawConfig(['registration_deadline' => '2026-12-05T23:59:00+01:00', 'shipping_deadline' => '2026-12-15T23:59:00+01:00', 'reminders' => []]));
    $env->service->runTasks();
    T::eq('OPEN', $env->service->status());
    T::ok($env->service->isRegistrationOpen());
});

T::test('Versand: Empfänger benachrichtigt ohne Absendernamen, Korrektur, nach Frist möglich', function () use ($code) {
    $env = new Env(rawConfig());
    $ids = $env->registerConfirmed(3);
    T::throws(fn () => $env->service->submitShipment($ids[0], ['carrier' => 'DHL', 'tracking_number' => '123456789012']), $code('not_drawn'));
    $env->clock->set('2026-12-02T00:00:00+01:00');
    $env->service->runTasks();

    $giver = $env->service->find($ids[0]);
    $receiver = $env->service->find($giver['giftee_id']);
    $bad = $env->service->submitShipment($giver['id'], ['carrier' => 'POST_CH', 'tracking_number' => '!!']);
    T::ok(isset($bad['errors']['carrier'], $bad['errors']['tracking_number']));

    T::eq(['ok' => true], $env->service->submitShipment($giver['id'], ['carrier' => 'DHL', 'tracking_number' => '0034 0434 1612 3456 7890']));
    $mail = $env->lastMail();
    T::eq($receiver['email'], $mail['to']);
    T::contains('unterwegs', $mail['subject']);
    T::contains('00340434161234567890', $mail['text']);
    T::contains('https://www.dhl.de/', $mail['text']);
    T::notContains($giver['last_name'], $mail['text'], 'Absender geheim');
    T::ok($env->service->find($giver['id'])['shipment']['notified_at']);

    $n = count($env->mailer->outbox);
    $env->service->submitShipment($giver['id'], ['carrier' => 'DHL', 'tracking_number' => '00340434161234567890']);
    T::eq($n, count($env->mailer->outbox), 'gleiche Daten: keine Mail');
    $env->service->submitShipment($giver['id'], ['carrier' => 'DPD', 'tracking_number' => '01234567890123']);
    T::contains('Aktualisierte', $env->lastMail()['subject']);

    $env->clock->set('2026-12-12T12:00:00+01:00');
    T::eq(['ok' => true], $env->service->submitShipment($ids[1], ['carrier' => 'UPS', 'tracking_number' => '1Z999AA10123456784']));
});

T::test('Fehlgeschlagene Versandbenachrichtigung wird nachgeholt', function () {
    $env = new Env(rawConfig());
    $ids = $env->registerConfirmed(3);
    $env->clock->set('2026-12-02T00:00:00+01:00');
    $env->service->runTasks();
    $giver = $env->service->find($ids[0]);
    $receiver = $env->service->find($giver['giftee_id']);
    $env->mailer->failFor[$receiver['email']] = true;
    $env->service->submitShipment($giver['id'], ['carrier' => 'GLS', 'tracking_number' => '12345678901']);
    T::eq(null, $env->service->find($giver['id'])['shipment']['notified_at']);
    $env->mailer->failFor = [];
    $env->service->runTasks();
    T::ok($env->service->find($giver['id'])['shipment']['notified_at']);
    T::contains('unterwegs', $env->mailsTo($receiver['email'])[count($env->mailsTo($receiver['email'])) - 1]['subject']);
});

T::test('Erinnerung nur ohne Sendungsnummer, genau einmal, nicht vor verspäteter Auslosung', function () {
    $env = new Env(rawConfig());
    $ids = $env->registerConfirmed(4);
    $env->clock->set('2026-12-02T00:00:00+01:00');
    $env->service->runTasks();
    $env->service->submitShipment($ids[0], ['carrier' => 'HERMES', 'tracking_number' => '12345678901234']);
    $reminders = fn () => array_values(array_filter($env->mailer->outbox, fn ($m) => str_starts_with($m['subject'], 'Erinnerung')));

    $env->clock->set('2026-12-08T09:59:00+01:00');
    $env->service->runTasks();
    T::eq(0, count($reminders()));
    $env->clock->set('2026-12-08T10:00:00+01:00');
    $env->service->runTasks();
    $env->reload();
    $env->service->runTasks();
    T::eq(3, count($reminders()));
    T::ok(!in_array('person1@example.org', array_column($reminders(), 'to'), true));

    $late = new Env(rawConfig());
    $late->registerConfirmed(3);
    $late->clock->set('2026-12-09T00:00:00+01:00');
    $late->service->runTasks();
    T::eq('DRAWN', $late->service->status());
    T::eq(0, count(array_filter($late->mailer->outbox, fn ($m) => str_starts_with($m['subject'], 'Erinnerung'))));
});

T::test('Aufdeckung: Zustimmung + Versand + Zeitpunkt', function () use ($code) {
    $env = new Env(rawConfig());
    $ids = $env->registerConfirmed(3);
    T::throws(fn () => $env->service->setReveal($ids[0], true), $code('reveal_closed'));
    $env->clock->set('2026-12-02T00:00:00+01:00');
    $env->service->runTasks();
    [$a, $b, $c] = array_map(fn ($id) => $env->service->find($id), $ids);
    $env->service->setReveal($a['id'], true);
    $env->service->submitShipment($a['id'], ['carrier' => 'DHL', 'tracking_number' => '123456789012']);
    $env->service->setReveal($b['id'], true); // ohne Versand
    $env->service->submitShipment($c['id'], ['carrier' => 'DPD', 'tracking_number' => '01234567890123']); // geheim
    T::eq(null, $env->service->revealedSanta($a['giftee_id']));

    $env->clock->set('2026-12-11T00:00:00+01:00');
    T::throws(fn () => $env->service->setReveal($c['id'], true), $code('reveal_closed'));
    $env->service->runTasks();
    $env->service->runTasks();
    $reveal = array_values(array_filter($env->mailer->outbox, fn ($m) => str_contains($m['subject'], 'Geheimnis')));
    T::eq(1, count($reveal));
    $recvA = $env->service->find($a['giftee_id']);
    T::eq($recvA['email'], $reveal[0]['to']);
    T::contains("{$a['first_name']} {$a['last_name']}", $reveal[0]['text']);
    T::eq($a['id'], $env->service->revealedSanta($recvA['id'])['id']);
    T::eq(null, $env->service->revealedSanta($b['giftee_id']));
    T::eq(null, $env->service->revealedSanta($c['giftee_id']));
});

T::test('Löschfrist: Daten und Backups entfernt', function () {
    $env = new Env(rawConfig());
    $env->registerConfirmed(3);
    $env->clock->set('2026-12-02T00:00:00+01:00');
    $env->service->runTasks();
    T::ok(count($env->service->store->listBackups()) >= 1);
    $env->clock->set('2027-01-10T00:00:00+01:00');
    $env->service->runTasks();
    T::eq('CLOSED', $env->service->status());
    T::eq(0, count($env->service->participants()));
    T::eq([], $env->service->store->listBackups());
    T::eq(0, count((new Store($env->dataDir, $env->config->dataKey, 'de'))->data()['participants']));
});

T::test('Mehrere Länder-Runden getrennt (Daten, Token, Dienstleister)', function () {
    $env = new Env(rawConfig([], [[
        'slug' => 'it', 'country' => 'IT', 'name' => 'Wichteln Italien',
        'registration_deadline' => '2026-12-01T23:59:00+01:00', 'shipping_deadline' => '2026-12-10T23:59:00+01:00',
    ]]));
    [$de, $it] = $env->services;
    $de->register(sampleForm(1));
    T::eq(['ok' => true], $it->register(sampleForm(1, ['postal_code' => '20121', 'city' => 'Milano', 'province' => 'MI'])));
    T::ok(isset($it->register(sampleForm(2, ['postal_code' => '20121']))['errors']['province']));

    $deTok = $env->token('person1@example.org', '/de/bestaetigen');
    $itTok = $env->token('person1@example.org', '/it/bestaetigen');
    T::throws(fn () => $it->confirm($deTok));
    $itId = $it->confirm($itTok);
    T::eq(null, $de->find($itId));
    T::eq('MI', $it->find($itId)['address']['province']);
    T::eq(null, $de->verifySession($it->sessionToken($itId)));

    for ($i = 2; $i <= 3; $i++) {
        $it->register(sampleForm($i, ['postal_code' => '00184', 'city' => 'Roma', 'province' => 'RM']));
        $it->confirm($env->token("person$i@example.org", '/it/bestaetigen'));
    }
    $env->clock->set('2026-12-02T00:00:00+01:00');
    $env->app->runAllTasks();
    T::eq('DRAWN', $it->status());
    T::eq('CANCELLED', $de->status());
    T::ok((bool) array_filter($env->mailer->outbox, fn ($m) => str_contains($m['subject'], 'Lose') && str_contains($m['text'], 'Italien') && str_contains($m['text'], '(RM)')));

    T::ok(isset($it->submitShipment($itId, ['carrier' => 'HERMES', 'tracking_number' => '12345678901234'])['errors']['carrier']), 'Hermes nicht in IT');
    T::eq(['ok' => true], $it->submitShipment($itId, ['carrier' => 'BRT', 'tracking_number' => '12345678901234']));
    T::contains('BRT (Bartolini)', $env->lastMail()['text']);
    T::contains('brt.it', $env->lastMail()['text']);
});
