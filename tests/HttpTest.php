<?php
declare(strict_types=1);

namespace Wichtelberg\Tests;

T::test('HTTP: Anmeldung → Bestätigung → Auslosung → Versand → Aufdeckung → Admin', function () {
    $env = new Env(rawConfig([], [[
        'slug' => 'ch', 'country' => 'CH', 'name' => 'Wichteln Schweiz',
        'registration_deadline' => '2026-12-01T23:59:00+01:00', 'shipping_deadline' => '2026-12-10T23:59:00+01:00',
    ]]));

    $index = $env->req('GET', '/');
    T::eq(200, $index->status);
    T::contains('href="/de"', $index->body);
    T::contains('href="/ch"', $index->body);
    T::contains("default-src 'none'", $index->headers['Content-Security-Policy']);
    T::contains('class="banner"', $index->body);
    T::contains('keine Aktion von Lobenbergs Gute Weine', $index->body);
    T::contains('<meta property="og:image" content="http://localhost:8080/og-image.png">', $index->body);
    T::contains('<link rel="icon" href="/favicon.svg"', $index->body);
    T::contains('<meta property="og:title" content="Wichteln Schweiz">', $env->req('GET', '/ch')->body);

    $ch = $env->req('GET', '/ch');
    T::contains('Schweiz', $ch->body);
    T::contains('maxlength="4"', $ch->body);

    $bad = $env->req('POST', '/de/anmelden', sampleForm(1, ['postal_code' => 'x']));
    T::eq(422, $bad->status);
    T::contains('5 Ziffern', $bad->body);
    T::contains('value="Vorname1"', $bad->body, 'Eingaben bleiben erhalten');

    $xss = $env->req('POST', '/de/anmelden', sampleForm(1, ['first_name' => '<script>alert(1)</script>', 'postal_code' => 'x']));
    T::notContains('<script>alert(1)</script>', $xss->body);
    T::contains('&lt;script&gt;', $xss->body);

    $env->req('POST', '/de/anmelden', sampleForm(99, ['website' => 'spam']));
    T::eq(null, $env->service->findByEmail('person99@example.org'), 'Honeypot');

    T::eq(403, $env->req('POST', '/de/anmelden', sampleForm(1), [], ['origin' => 'https://evil.example'])->status, 'CSRF');
    T::eq(403, $env->req('POST', '/de/anmelden', sampleForm(1), [], ['origin' => 'null'])->status, 'Origin null abgelehnt');
    T::eq(200, $env->req('POST', '/de/login', ['email' => 'x@example.org'], [], ['origin' => 'https://localhost:8080'])->status, 'http/https egal');
    T::eq(200, $env->req('POST', '/de/login', ['email' => 'x@example.org'], [], ['origin' => 'https://proxy.example', 'host' => 'proxy.example'])->status, 'eigener Host');
    T::eq(403, $env->req('POST', '/de/login', ['email' => 'x@example.org'], [], ['origin' => 'https://evil.example', 'referer' => 'http://localhost:8080/de'])->status, 'Origin hat Vorrang vor Referer');
    T::eq('same-origin', $env->req('GET', '/de')->headers['Referrer-Policy']);

    $cookies = [];
    for ($i = 1; $i <= 3; $i++) {
        T::eq(200, $env->req('POST', '/de/anmelden', sampleForm($i))->status);
        $tok = $env->token("person$i@example.org", '/de/bestaetigen');
        $page = $env->req('GET', '/de/bestaetigen?t=' . rawurlencode($tok));
        T::contains('Anmeldung bestätigen', $page->body);
        T::eq(null, $env->service->findByEmail("person$i@example.org")['email_confirmed_at'], 'GET bestätigt nicht (Link-Scanner)');
        $c = $env->req('POST', '/de/bestaetigen', ['t' => $tok]);
        T::eq(303, $c->status);
        T::eq('/de/dashboard?m=confirmed', $c->headers['Location']);
        T::contains('Path=/de', $c->cookies[0]);
        T::contains('HttpOnly', $c->cookies[0]);
        $cookies[] = cookieOf($c);
        $env->reload();
    }
    T::contains('Du bist angemeldet', $env->req('GET', '/de/dashboard', [], $cookies[0])->body);
    T::eq(303, $env->req('GET', '/ch/dashboard', [], $cookies[0])->status, 'Session nur in eigener Runde');
    T::eq(303, $env->req('GET', '/de/dashboard')->status);

    T::eq(303, $env->req('POST', '/de/dashboard/daten', sampleForm(1, ['city' => 'Bonn', 'postal_code' => '53111']), $cookies[0])->status);
    $env->reload();
    T::eq('Bonn', $env->service->findByEmail('person1@example.org')['address']['city']);

    // Auslosung per Cron-Endpunkt
    $env->clock->set('2026-12-02T00:00:00+01:00');
    T::eq(401, $env->req('POST', '/tasks/run', [], [], ['authorization' => 'Bearer falsch'])->status);
    $run = $env->req('POST', '/tasks/run', [], [], ['authorization' => 'Bearer cron-secret']);
    T::eq(['ok' => true, 'rounds' => ['de' => 'DRAWN', 'ch' => 'CANCELLED']], json_decode($run->body, true));
    $env->reload();

    $p1 = $env->service->findByEmail('person1@example.org');
    $giftee = $env->service->find($p1['giftee_id']);
    $d2 = $env->req('GET', '/de/dashboard', [], $cookies[0])->body;
    T::contains("{$giftee['first_name']} {$giftee['last_name']}", $d2);
    foreach (['DHL', 'Hermes', 'DPD', 'GLS', 'UPS'] as $label) {
        T::contains(">$label</option>", $d2);
    }
    T::contains('Aufdecken?', $d2);

    T::eq(303, $env->req('POST', '/de/dashboard/versand', ['carrier' => 'HERMES', 'tracking_number' => '1234 5678 9012 34'], $cookies[0])->status);
    T::eq($giftee['email'], $env->lastMail()['to']);
    T::contains('myhermes.de', $env->lastMail()['text']);
    T::eq(303, $env->req('POST', '/de/dashboard/aufdecken', ['reveal' => 'yes'], $cookies[0])->status);
    $env->reload();
    T::eq(true, $env->service->find($p1['id'])['reveal']);

    $gIdx = (int) preg_replace('/\D/', '', $giftee['email']) - 1;
    $d3 = $env->req('GET', '/de/dashboard', [], $cookies[$gIdx])->body;
    T::contains('Sendung 12345678901234 verfolgen', $d3);
    T::notContains($p1['last_name'], $d3);

    $env->clock->set('2026-12-11T00:00:00+01:00');
    T::contains("{$p1['first_name']} {$p1['last_name']}", $env->req('GET', '/de/dashboard', [], $cookies[$gIdx])->body);

    // Organisator
    T::eq('/admin/login', $env->req('GET', '/admin')->headers['Location']);
    $env->req('POST', '/admin/login', ['email' => 'ORGA@example.org']);
    $login = $env->req('POST', '/admin/link', ['t' => $env->token('orga@example.org', '/admin/link')]);
    T::eq('/admin', $login->headers['Location']);
    $admin = $env->req('GET', '/admin', [], cookieOf($login));
    T::eq(200, $admin->status);
    T::contains('Test-Wichteln', $admin->body);
    T::contains('Wichteln Schweiz', $admin->body);
    T::contains('person2@example.org', $admin->body);
    T::contains('1 / 3', $admin->body);
    T::eq(303, $env->req('GET', '/admin', [], ['wb_admin' => $cookies[0]['wb_de']])->status, 'Teilnehmer-Session ist kein Admin');

    T::eq(404, $env->req('GET', '/gibtsnicht')->status);
    T::eq(200, $env->req('GET', '/datenschutz')->status);
    T::eq(200, $env->req('GET', '/impressum')->status);
});

T::test('HTTP: Rate-Limit und geschlossene Anmeldung', function () {
    $env = new Env(rawConfig());
    $last = null;
    for ($i = 0; $i < 21; $i++) {
        $last = $env->req('POST', '/de/login', ['email' => 'x@example.org']);
    }
    T::eq(429, $last->status);
    $env->clock->set('2026-12-02T00:00:00+01:00');
    T::notContains('action="/de/anmelden"', $env->req('GET', '/de')->body);
});
