import test from 'node:test';
import assert from 'node:assert/strict';
import { FileStore } from '../src/store.js';
import { WichtelService, UserError } from '../src/service.js';
import { setup, sampleForm, tokenFromMail, registerConfirmed } from './helpers.js';

const mailsTo = (mailer, to) => mailer.outbox.filter((m) => m.to === to);

test('Anmeldung mit Double-Opt-in', async () => {
  const env = await setup();
  const res = await env.service.register(sampleForm(1));
  assert.deepEqual(res, { ok: true });
  const p = env.service.findByEmail('person1@example.org');
  assert.equal(p.email_confirmed_at, null);
  assert.equal(env.service.confirmedCount(), 0);
  assert.match(env.mailer.outbox[0].subject, /bestätige/);

  const id = await env.service.confirm(tokenFromMail(env.mailer, 'person1@example.org', '/bestaetigen'));
  assert.equal(id, p.id);
  assert.ok(env.service.find(id).email_confirmed_at);
  assert.equal(env.mailer.outbox.at(-1).subject, 'Du bist dabei: Test-Wichteln');
  assert.ok(env.mailer.outbox.at(-1).text.includes('Hauptstraße 1'));

  // Zweite Bestätigung: keine zweite Willkommensmail
  await env.service.confirm(tokenFromMail(env.mailer, 'person1@example.org', '/bestaetigen'));
  assert.equal(mailsTo(env.mailer, 'person1@example.org').filter((m) => m.subject.startsWith('Du bist dabei')).length, 1);

  await assert.rejects(env.service.confirm('kaputt'), (e) => e instanceof UserError && e.code === 'invalid_link');
});

test('Doppelte Anmeldung verrät nichts und überschreibt nichts', async () => {
  const env = await setup();
  await registerConfirmed(env, 1);
  const before = env.mailer.outbox.length;
  const res = await env.service.register(sampleForm(1, { email: 'PERSON1@example.org', city: 'Bonn' }));
  assert.deepEqual(res, { ok: true });
  assert.equal(env.service.data.participants.length, 1);
  assert.equal(env.service.data.participants[0].address.city, 'Köln');
  assert.match(env.mailer.outbox.at(-1).subject, /Anmeldelink/, 'bestätigte Adresse bekommt Login-Link');
  assert.equal(env.mailer.outbox.length, before + 1);
});

test('Validierungsfehler werden zurückgegeben, nichts gespeichert', async () => {
  const env = await setup();
  const res = await env.service.register(sampleForm(1, { postal_code: 'ABCDE', consent: undefined }));
  assert.ok(res.errors.postal_code && res.errors.consent);
  assert.equal(env.service.data.participants.length, 0);
  assert.equal(env.mailer.outbox.length, 0);
});

test('Nach Anmeldeschluss: Formular, Bestätigung, Änderung und Abmeldung gesperrt', async () => {
  const env = await setup();
  await env.service.register(sampleForm(1));
  const t = tokenFromMail(env.mailer, 'person1@example.org', '/bestaetigen');
  env.clock.set('2026-12-02T00:00:00+01:00');
  await assert.rejects(env.service.register(sampleForm(2)), (e) => e.code === 'closed');
  await assert.rejects(env.service.confirm(t), (e) => e.code === 'invalid_link' || e.code === 'closed');
  await assert.rejects(env.service.updateParticipant(env.service.data.participants[0].id, sampleForm(1)), (e) => e.code === 'closed');
});

test('Daten ändern und abmelden vor Anmeldeschluss', async () => {
  const env = await setup();
  const [id] = await registerConfirmed(env, 1);
  const r = await env.service.updateParticipant(id, sampleForm(1, { city: 'Bonn', postal_code: '53111', email: 'egal@example.org' }));
  assert.deepEqual(r, { ok: true });
  assert.equal(env.service.find(id).address.city, 'Bonn');
  assert.equal(env.service.find(id).email, 'person1@example.org', 'E-Mail ist nicht änderbar');
  const bad = await env.service.updateParticipant(id, sampleForm(1, { postal_code: '1' }));
  assert.ok(bad.errors.postal_code);
  await env.service.unregister(id);
  assert.equal(env.service.find(id), null);
});

test('Maximale Teilnehmerzahl', async () => {
  const env = await setup({ overrides: { round: { max_participants: 3 } } });
  await registerConfirmed(env, 3);
  await assert.rejects(env.service.register(sampleForm(4)), (e) => e.code === 'full');
});

test('Login-Anfrage: Teilnehmer bekommt Link, Unbekannte nicht', async () => {
  const env = await setup();
  await registerConfirmed(env, 1);
  const n = env.mailer.outbox.length;
  await env.service.requestLogin('unbekannt@example.org');
  assert.equal(env.mailer.outbox.length, n);
  await env.service.requestLogin('Person1@Example.org');
  const pt = tokenFromMail(env.mailer, 'person1@example.org', '/login/link');
  assert.equal(env.service.verifyLoginToken(pt), env.service.data.participants[0].id);
  // Login-Link aus Login-Mail ist 30 Minuten gültig
  env.clock.set('2026-11-20T12:31:00+01:00');
  assert.equal(env.service.verifyLoginToken(pt), null);
});

test('Mail-Drosselung: max. 3 Login-Mails pro Stunde und Adresse', async () => {
  const env = await setup();
  await registerConfirmed(env, 1);
  const n = env.mailer.outbox.length;
  for (let i = 0; i < 6; i++) await env.service.requestLogin('person1@example.org');
  assert.equal(env.mailer.outbox.length - n, 2, 'eine Mail wurde schon bei der Anmeldung gezählt');
});

test('Auslosung zum Anmeldeschluss: Unbestätigte werden verworfen, alle bekommen ihren Partner', async () => {
  const env = await setup();
  const ids = await registerConfirmed(env, 5);
  await env.service.register(sampleForm(9)); // nicht bestätigt
  await env.service.runTasks();
  assert.equal(env.service.data.status, 'OPEN', 'vor Fristende passiert nichts');

  env.clock.set('2026-12-02T00:00:00+01:00');
  await env.service.runTasks();
  const d = env.service.data;
  assert.equal(d.status, 'DRAWN');
  assert.equal(d.participants.length, 5);
  assert.equal(env.service.findByEmail('person9@example.org'), null);
  for (const id of ids) {
    const p = env.service.find(id);
    assert.ok(p.giftee_id && p.giftee_id !== id);
    const drawMail = mailsTo(env.mailer, p.email).find((m) => m.subject.includes('Lose sind gezogen'));
    const giftee = env.service.find(p.giftee_id);
    assert.ok(drawMail.text.includes(`${giftee.first_name} ${giftee.last_name}`));
    assert.ok(drawMail.text.includes(`${giftee.address.street} ${giftee.address.house_number}`));
  }
  const giftee1 = env.service.data.participants.find((p) => p.email === 'person1@example.org');
  const santa = env.service.santaOf(giftee1.id);
  assert.ok(mailsTo(env.mailer, santa.email).some((m) => m.text.includes('Lieber Rotwein')), 'Hinweis wird weitergegeben');

  // idempotent
  const snapshot = JSON.stringify(env.service.data.participants.map((p) => [p.id, p.giftee_id]));
  const count = env.mailer.outbox.length;
  await env.service.runTasks();
  assert.equal(JSON.stringify(env.service.data.participants.map((p) => [p.id, p.giftee_id])), snapshot);
  assert.equal(env.mailer.outbox.length, count);
});

test('Zu wenig Teilnehmer: Absage an alle + Organisator, Wiedereröffnung bei Fristverlängerung', async () => {
  const env = await setup();
  await registerConfirmed(env, 2);
  env.clock.set('2026-12-02T00:00:00+01:00');
  await env.service.runTasks();
  assert.equal(env.service.data.status, 'CANCELLED');
  assert.equal(env.mailer.outbox.filter((m) => m.subject.includes('nicht stattfinden')).length, 2);
  assert.equal(mailsTo(env.mailer, 'orga@example.org').length, 1);
  await env.service.runTasks();
  assert.equal(mailsTo(env.mailer, 'orga@example.org').length, 1, 'keine doppelte Absage');

  // Organisator verlängert die Frist (neue Config, Neustart)
  const raw = (await import('./helpers.js')).rawConfig({ round: { registration_deadline: '2026-12-05T23:59:00+01:00', shipping_deadline: '2026-12-15T23:59:00+01:00', reminders: [] } });
  const { buildConfig } = await import('../src/config.js');
  const { KEYS } = await import('./helpers.js');
  const config2 = buildConfig({ ...raw, data_dir: env.dataDir }, KEYS);
  const store2 = new FileStore({ dataDir: env.dataDir, key: config2.dataKey, name: 'de' });
  await store2.load();
  const service2 = new WichtelService({ config: config2, round: config2.rounds[0], store: store2, mailer: env.mailer, now: env.clock.now, logger: env.silent });
  await service2.runTasks();
  assert.equal(service2.data.status, 'OPEN');
  assert.ok(service2.isRegistrationOpen());
});

test('Versand melden: Empfänger wird benachrichtigt, ohne Absendernamen; Korrektur möglich', async () => {
  const env = await setup();
  const ids = await registerConfirmed(env, 3);
  await assert.rejects(env.service.submitShipment(ids[0], { carrier: 'DHL', tracking_number: '123456789012' }), (e) => e.code === 'not_drawn');
  env.clock.set('2026-12-02T00:00:00+01:00');
  await env.service.runTasks();

  const giver = env.service.find(ids[0]);
  const receiver = env.service.find(giver.giftee_id);
  const bad = await env.service.submitShipment(giver.id, { carrier: 'POST', tracking_number: '!!' });
  assert.ok(bad.errors.carrier && bad.errors.tracking_number);

  const res = await env.service.submitShipment(giver.id, { carrier: 'DHL', tracking_number: '0034 0434 1612 3456 7890' });
  assert.deepEqual(res, { ok: true });
  const mail = mailsTo(env.mailer, receiver.email).at(-1);
  assert.match(mail.subject, /unterwegs/);
  assert.ok(mail.text.includes('00340434161234567890'));
  assert.ok(mail.text.includes('https://www.dhl.de/'));
  assert.ok(!mail.text.includes(giver.last_name), 'Absender bleibt geheim');
  assert.ok(env.service.find(giver.id).shipment.notified_at);

  // gleiche Daten erneut: keine weitere Mail
  const n = env.mailer.outbox.length;
  await env.service.submitShipment(giver.id, { carrier: 'DHL', tracking_number: '00340434161234567890' });
  assert.equal(env.mailer.outbox.length, n);

  await env.service.submitShipment(giver.id, { carrier: 'DPD', tracking_number: '01234567890123' });
  assert.match(mailsTo(env.mailer, receiver.email).at(-1).subject, /Aktualisierte/);

  // nach Versandfrist weiterhin möglich (V-7)
  env.clock.set('2026-12-12T12:00:00+01:00');
  const other = env.service.find(ids[1]);
  assert.deepEqual(await env.service.submitShipment(other.id, { carrier: 'UPS', tracking_number: '1Z999AA10123456784' }), { ok: true });
});

test('Fehlgeschlagene Versandbenachrichtigung wird von runTasks nachgeholt', async () => {
  const env = await setup();
  const ids = await registerConfirmed(env, 3);
  env.clock.set('2026-12-02T00:00:00+01:00');
  await env.service.runTasks();
  const giver = env.service.find(ids[0]);
  const receiver = env.service.find(giver.giftee_id);
  env.mailer.failFor.add(receiver.email);
  await env.service.submitShipment(giver.id, { carrier: 'GLS', tracking_number: '12345678901' });
  assert.equal(env.service.find(giver.id).shipment.notified_at, null);
  env.mailer.failFor.clear();
  await env.service.runTasks();
  assert.ok(env.service.find(giver.id).shipment.notified_at);
  assert.match(mailsTo(env.mailer, receiver.email).at(-1).subject, /unterwegs/);
});

test('Erinnerung nur an Teilnehmer ohne Sendungsnummer, genau einmal', async () => {
  const env = await setup();
  const ids = await registerConfirmed(env, 4);
  env.clock.set('2026-12-02T00:00:00+01:00');
  await env.service.runTasks();
  await env.service.submitShipment(ids[0], { carrier: 'HERMES', tracking_number: '12345678901234' });

  env.clock.set('2026-12-08T09:59:00+01:00');
  await env.service.runTasks();
  assert.equal(env.mailer.outbox.filter((m) => m.subject.startsWith('Erinnerung')).length, 0);

  env.clock.set('2026-12-08T10:00:00+01:00');
  await env.service.runTasks();
  await env.service.runTasks();
  const reminders = env.mailer.outbox.filter((m) => m.subject.startsWith('Erinnerung'));
  assert.equal(reminders.length, 3);
  assert.ok(!reminders.some((m) => m.to === 'person1@example.org'));
  const p2 = env.service.find(ids[1]);
  const giftee = env.service.find(p2.giftee_id);
  assert.ok(reminders.find((m) => m.to === p2.email).text.includes(giftee.first_name));
});

test('Keine Erinnerung für Zeitpunkte vor der (verspäteten) Auslosung', async () => {
  const env = await setup();
  await registerConfirmed(env, 3);
  env.clock.set('2026-12-09T00:00:00+01:00'); // App war bis nach dem Erinnerungstermin offline
  await env.service.runTasks();
  assert.equal(env.service.data.status, 'DRAWN');
  assert.equal(env.mailer.outbox.filter((m) => m.subject.startsWith('Erinnerung')).length, 0);
});

test('Löschfrist: alle Daten und Backups werden entfernt', async () => {
  const env = await setup();
  await registerConfirmed(env, 3);
  env.clock.set('2026-12-02T00:00:00+01:00');
  await env.service.runTasks();
  assert.ok((await env.store.listBackups()).length >= 1);

  env.clock.set('2027-01-10T00:00:00+01:00');
  await env.service.runTasks();
  assert.equal(env.service.data.status, 'CLOSED');
  assert.equal(env.service.data.participants.length, 0);
  assert.deepEqual(await env.store.listBackups(), []);

  const reloaded = new FileStore({ dataDir: env.dataDir, key: env.config.dataKey, name: 'de' });
  await reloaded.load();
  assert.equal(reloaded.get().participants.length, 0);
});

test('Session-Token: Teilnehmer, gelöschter Teilnehmer', async () => {
  const env = await setup();
  const [id] = await registerConfirmed(env, 1);
  assert.equal(env.service.verifySession(env.service.sessionToken(id)).participant.id, id);
  await env.service.unregister(id);
  assert.equal(env.service.verifySession(env.service.sessionToken(id)), null);
});

test('Aufdeckung: nur mit Zustimmung des Schenkenden, nach Versand, zum Aufdeckungszeitpunkt', async () => {
  const env = await setup();
  const ids = await registerConfirmed(env, 3);
  await assert.rejects(env.service.setReveal(ids[0], true), (e) => e.code === 'reveal_closed', 'vor der Auslosung nicht möglich');
  env.clock.set('2026-12-02T00:00:00+01:00');
  await env.service.runTasks();

  const [a, b, c] = ids.map((id) => env.service.find(id));
  await env.service.setReveal(a.id, true); // a stimmt zu und verschickt
  await env.service.submitShipment(a.id, { carrier: 'DHL', tracking_number: '123456789012' });
  await env.service.setReveal(b.id, true); // b stimmt zu, verschickt aber nicht
  await env.service.submitShipment(c.id, { carrier: 'DPD', tracking_number: '01234567890123' }); // c bleibt geheim

  assert.equal(env.service.revealedSanta(a.giftee_id), null, 'vor dem Aufdeckungszeitpunkt geheim');

  env.clock.set('2026-12-11T00:00:00+01:00'); // nach Versandfrist = Standard-Aufdeckung
  await assert.rejects(env.service.setReveal(c.id, true), (e) => e.code === 'reveal_closed');
  await env.service.runTasks();
  await env.service.runTasks();

  const revealMails = env.mailer.outbox.filter((m) => m.subject.includes('Geheimnis'));
  assert.equal(revealMails.length, 1);
  const receiverOfA = env.service.find(a.giftee_id);
  assert.equal(revealMails[0].to, receiverOfA.email);
  assert.ok(revealMails[0].text.includes(`${a.first_name} ${a.last_name}`));
  assert.equal(env.service.revealedSanta(receiverOfA.id).id, a.id);
  assert.equal(env.service.revealedSanta(b.giftee_id), null, 'ohne Versand keine Aufdeckung');
  assert.equal(env.service.revealedSanta(c.giftee_id), null, 'ohne Zustimmung keine Aufdeckung');
});

test('Mehrere Länder-Runden sind voneinander getrennt (Daten, Token, Versanddienstleister)', async () => {
  const env = await setup({
    overrides: {
      extraRounds: [
        { slug: 'it', country: 'IT', name: 'Wichteln Italien', registration_deadline: '2026-12-01T23:59:00+01:00', shipping_deadline: '2026-12-10T23:59:00+01:00' },
      ],
    },
  });
  const [de, it] = env.services;
  await de.register(sampleForm(1));
  const res = await it.register(sampleForm(1, { postal_code: '20121', city: 'Milano', province: 'MI' }));
  assert.deepEqual(res, { ok: true });
  assert.ok(it.register(sampleForm(2, { postal_code: '20121' })).then((r) => assert.ok(r.errors.province)));

  const deToken = tokenFromMail(env.mailer, 'person1@example.org', '/de/bestaetigen');
  const itToken = tokenFromMail(env.mailer, 'person1@example.org', '/it/bestaetigen');
  await assert.rejects(it.confirm(deToken), (e) => e.code === 'invalid_link', 'Token der DE-Runde gilt nicht in IT');
  const itId = await it.confirm(itToken);
  assert.equal(de.find(itId), null);
  assert.equal(it.find(itId).address.province, 'MI');
  assert.equal(de.verifySession(it.sessionToken(itId)), null);

  for (let i = 2; i <= 3; i++) {
    await it.register(sampleForm(i, { postal_code: '00184', city: 'Roma', province: 'RM' }));
    await it.confirm(tokenFromMail(env.mailer, `person${i}@example.org`, '/it/bestaetigen'));
  }
  env.clock.set('2026-12-02T00:00:00+01:00');
  await it.runTasks();
  await de.runTasks();
  assert.equal(it.data.status, 'DRAWN');
  assert.equal(de.data.status, 'CANCELLED');
  const drawMail = env.mailer.outbox.find((m) => m.subject.includes('Lose') && m.text.includes('Italien'));
  assert.ok(drawMail, 'Adresse enthält das Land der Runde');

  const bad = await it.submitShipment(itId, { carrier: 'HERMES', tracking_number: '12345678901234' });
  assert.ok(bad.errors.carrier, 'Hermes ist in Italien nicht wählbar');
  assert.deepEqual(await it.submitShipment(itId, { carrier: 'BRT', tracking_number: '12345678901234' }), { ok: true });
  const shipped = env.mailer.outbox.at(-1);
  assert.ok(shipped.text.includes('BRT (Bartolini)') && shipped.text.includes('brt.it'));
});
