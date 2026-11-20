import test from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from '../src/server.js';
import { setup, sampleForm, tokenFromMail } from './helpers.js';

async function start(env) {
  const server = createServer({ config: env.config, services: env.services, logger: env.silent, now: env.clock.now });
  await new Promise((r) => server.listen(0, '127.0.0.1', r));
  const base = `http://127.0.0.1:${server.address().port}`;
  const origin = env.config.baseUrl;
  async function req(method, path, { form, cookie, headers = {} } = {}) {
    const res = await fetch(base + path, {
      method,
      redirect: 'manual',
      headers: {
        ...(form ? { 'content-type': 'application/x-www-form-urlencoded', origin } : {}),
        ...(cookie ? { cookie } : {}),
        ...headers,
      },
      body: form ? new URLSearchParams(form).toString() : undefined,
    });
    const text = await res.text();
    const setCookie = res.headers.get('set-cookie');
    return { status: res.status, text, location: res.headers.get('location'), cookie: setCookie ? setCookie.split(';')[0] : null, headers: res.headers };
  }
  return { server, req };
}

test('HTTP: kompletter Ablauf Anmeldung → Bestätigung → Auslosung → Versand → Admin', async (t) => {
  const env = await setup({
    overrides: { extraRounds: [{ slug: 'ch', country: 'CH', name: 'Wichteln Schweiz', registration_deadline: '2026-12-01T23:59:00+01:00', shipping_deadline: '2026-12-10T23:59:00+01:00' }] },
  });
  const { server, req } = await start(env);
  t.after(() => server.close());

  // Startseite listet Runden
  const index = await req('GET', '/');
  assert.equal(index.status, 200);
  assert.ok(index.text.includes('href="/de"') && index.text.includes('href="/ch"'));
  assert.match(index.headers.get('content-security-policy'), /default-src 'none'/);

  // Runde Schweiz: PLZ und Versanddienstleister
  const ch = await req('GET', '/ch');
  assert.ok(ch.text.includes('Schweiz') && ch.text.includes('maxlength="4"'));

  // Validierungsfehler
  const bad = await req('POST', '/de/anmelden', { form: sampleForm(1, { postal_code: 'x' }) });
  assert.equal(bad.status, 422);
  assert.ok(bad.text.includes('5 Ziffern'));
  assert.ok(bad.text.includes('value="Vorname1"'), 'Eingaben bleiben erhalten');

  // XSS wird escaped
  const xss = await req('POST', '/de/anmelden', { form: sampleForm(1, { first_name: '<script>alert(1)</script>', postal_code: 'x' }) });
  assert.ok(!xss.text.includes('<script>alert(1)</script>'));
  assert.ok(xss.text.includes('&lt;script&gt;'));

  // Honeypot
  await req('POST', '/de/anmelden', { form: sampleForm(99, { website: 'spam' }) });
  assert.equal(env.service.findByEmail('person99@example.org'), null);

  // CSRF: fremder Origin
  const csrf = await req('POST', '/de/anmelden', { form: sampleForm(1), headers: { origin: 'https://evil.example' } });
  assert.equal(csrf.status, 403);

  // Drei Teilnehmer anmelden + bestätigen
  const cookies = [];
  for (let i = 1; i <= 3; i++) {
    const r = await req('POST', '/de/anmelden', { form: sampleForm(i) });
    assert.equal(r.status, 200);
    const tok = tokenFromMail(env.mailer, `person${i}@example.org`, '/de/bestaetigen');
    const page = await req('GET', `/de/bestaetigen?t=${encodeURIComponent(tok)}`);
    assert.ok(page.text.includes('Anmeldung bestätigen'), 'GET bestätigt nicht automatisch (Schutz vor Link-Scannern)');
    assert.equal(env.service.findByEmail(`person${i}@example.org`).email_confirmed_at, null);
    const c = await req('POST', '/de/bestaetigen', { form: { t: tok } });
    assert.equal(c.status, 303);
    assert.equal(c.location, '/de/dashboard?m=confirmed');
    assert.match(c.cookie, /^wb_de=/);
    cookies.push(c.cookie);
  }
  const dash = await req('GET', '/de/dashboard', { cookie: cookies[0] });
  assert.ok(dash.text.includes('Du bist angemeldet'));
  assert.equal((await req('GET', '/ch/dashboard', { cookie: cookies[0] })).status, 303, 'Session gilt nur in der eigenen Runde');

  // Daten ändern
  const upd = await req('POST', '/de/dashboard/daten', { cookie: cookies[0], form: sampleForm(1, { city: 'Bonn', postal_code: '53111' }) });
  assert.equal(upd.status, 303);
  assert.equal(env.service.findByEmail('person1@example.org').address.city, 'Bonn');

  // Auslosung per Cron-Endpunkt
  env.clock.set('2026-12-02T00:00:00+01:00');
  assert.equal((await req('POST', '/tasks/run', { headers: { authorization: 'Bearer falsch' } })).status, 401);
  const run = await req('POST', '/tasks/run', { headers: { authorization: 'Bearer cron-secret' } });
  assert.deepEqual(JSON.parse(run.text), { ok: true, rounds: { de: 'DRAWN', ch: 'CANCELLED' } });

  // Dashboard zeigt Partneradresse und Versandformular
  const p1 = env.service.findByEmail('person1@example.org');
  const giftee = env.service.find(p1.giftee_id);
  const d2 = await req('GET', '/de/dashboard', { cookie: cookies[0] });
  assert.ok(d2.text.includes(`${giftee.first_name} ${giftee.last_name}`));
  for (const label of ['DHL', 'Hermes', 'DPD', 'GLS', 'UPS']) assert.ok(d2.text.includes(`>${label}</option>`));
  assert.ok(d2.text.includes('Aufdecken?'));

  // Versand melden
  const ship = await req('POST', '/de/dashboard/versand', { cookie: cookies[0], form: { carrier: 'HERMES', tracking_number: '1234 5678 9012 34' } });
  assert.equal(ship.status, 303);
  const mail = env.mailer.outbox.at(-1);
  assert.equal(mail.to, giftee.email);
  assert.ok(mail.text.includes('myhermes.de'));
  const reveal = await req('POST', '/de/dashboard/aufdecken', { cookie: cookies[0], form: { reveal: 'yes' } });
  assert.equal(reveal.status, 303);
  assert.equal(env.service.find(p1.id).reveal, true);

  // Empfänger sieht eingehende Sendung, aber nicht den Absender
  const giftIdx = Number(giftee.email.match(/person(\d)/)[1]) - 1;
  const d3 = await req('GET', '/de/dashboard', { cookie: cookies[giftIdx] });
  assert.ok(d3.text.includes('Sendung 12345678901234 verfolgen'));
  assert.ok(!d3.text.includes(p1.last_name));

  // Nach Aufdeckungszeitpunkt: Name sichtbar
  env.clock.set('2026-12-11T00:00:00+01:00');
  const d4 = await req('GET', '/de/dashboard', { cookie: cookies[giftIdx] });
  assert.ok(d4.text.includes(`${p1.first_name} ${p1.last_name}`));

  // Organisator
  assert.equal((await req('GET', '/admin')).location, '/admin/login');
  await req('POST', '/admin/login', { form: { email: 'ORGA@example.org' } });
  const adminTok = tokenFromMail(env.mailer, 'orga@example.org', '/admin/link');
  const login = await req('POST', '/admin/link', { form: { t: adminTok } });
  assert.equal(login.location, '/admin');
  const admin = await req('GET', '/admin', { cookie: login.cookie });
  assert.equal(admin.status, 200);
  assert.ok(admin.text.includes('Test-Wichteln') && admin.text.includes('Wichteln Schweiz'));
  assert.ok(admin.text.includes('person2@example.org'));
  assert.ok(admin.text.includes('1 / 3'));

  // Teilnehmer-Session ist kein Admin
  assert.equal((await req('GET', '/admin', { cookie: cookies[0].replace('wb_de', 'wb_admin') })).status, 303);

  assert.equal((await req('GET', '/gibtsnicht')).status, 404);
  assert.equal((await req('GET', '/style.css')).status, 200);
});

test('HTTP: Anmeldung nach Fristende gesperrt, Rate-Limit greift', async (t) => {
  const env = await setup();
  const { server, req } = await start(env);
  t.after(() => server.close());
  let last;
  for (let i = 0; i < 21; i++) last = await req('POST', '/de/login', { form: { email: 'x@example.org' } });
  assert.equal(last.status, 429);

  env.clock.set('2026-12-02T00:00:00+01:00');
  const page = await req('GET', '/de');
  assert.ok(!page.text.includes('action="/de/anmelden"'));
});
