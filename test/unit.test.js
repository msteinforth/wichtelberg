import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import os from 'node:os';
import { drawCycle } from '../src/draw.js';
import { signToken, verifyToken, encrypt, decrypt } from '../src/crypto.js';
import { buildConfig, ConfigError } from '../src/config.js';
import { FileStore } from '../src/store.js';
import { validateParticipantForm } from '../src/validation.js';
import { trackingUrl, normalizeTrackingNumber, COUNTRIES, carriersFor } from '../src/carriers.js';
import { esc, html } from '../src/views.js';
import { formatDateTime } from '../src/format.js';
import { KEYS, rawConfig, sampleForm } from './helpers.js';

test('drawCycle: ein einziger Kreis, niemand beschenkt sich selbst, keine gegenseitigen Paare', () => {
  for (let n = 3; n <= 40; n++) {
    const ids = Array.from({ length: n }, (_, i) => `p${i}`);
    for (let run = 0; run < 20; run++) {
      const m = drawCycle(ids);
      assert.equal(m.size, n);
      assert.equal(new Set(m.values()).size, n, 'jeder wird genau einmal beschenkt');
      for (const [g, r] of m) {
        assert.notEqual(g, r);
        assert.notEqual(m.get(r), g, 'kein gegenseitiges Paar');
      }
      // Kreis: von p0 aus erreicht man alle
      let cur = ids[0];
      const seen = new Set();
      do {
        seen.add(cur);
        cur = m.get(cur);
      } while (cur !== ids[0]);
      assert.equal(seen.size, n);
    }
  }
  assert.throws(() => drawCycle(['a']));
});

test('Token: Signatur, Zweck und Ablauf werden geprüft', () => {
  const key = Buffer.alloc(32, 1);
  const now = new Date('2026-11-20T12:00:00Z');
  const t = signToken(key, 'login', 'p_1', new Date(now.getTime() + 1000));
  assert.equal(verifyToken(key, 'login', t, now), 'p_1');
  assert.equal(verifyToken(key, 'session', t, now), null, 'falscher Zweck');
  assert.equal(verifyToken(key, 'login', t, new Date(now.getTime() + 2000)), null, 'abgelaufen');
  assert.equal(verifyToken(Buffer.alloc(32, 2), 'login', t, now), null, 'falscher Schlüssel');
  const [payload, mac] = t.split('.');
  const forged = Buffer.from(JSON.stringify({ p: 'login', s: '__admin__', e: now.getTime() + 1e9 })).toString('base64url');
  assert.equal(verifyToken(key, 'login', `${forged}.${mac}`, now), null, 'manipulierte Nutzlast');
  assert.equal(verifyToken(key, 'login', payload, now), null);
  assert.equal(verifyToken(key, 'login', undefined, now), null);
});

test('Verschlüsselung: Roundtrip und Manipulationserkennung', () => {
  const key = Buffer.alloc(32, 3);
  const enc = encrypt(key, '{"a":1}');
  assert.equal(decrypt(key, enc), '{"a":1}');
  assert.ok(!enc.includes('"a"'));
  const env = JSON.parse(enc);
  env.ct = Buffer.from('xxxxxxx').toString('base64url');
  assert.throws(() => decrypt(key, JSON.stringify(env)));
  assert.throws(() => decrypt(Buffer.alloc(32, 4), enc));
});

test('Konfiguration: gültige Werte und Fehlermeldungen', () => {
  const c = buildConfig(rawConfig(), KEYS);
  assert.equal(c.rounds[0].minParticipants, 3);
  assert.equal(c.rounds[0].purgeAt.toISOString(), new Date('2027-01-09T22:59:00Z').toISOString());
  assert.equal(c.rounds[0].revealAt.toISOString(), c.rounds[0].shippingDeadline.toISOString(), 'Aufdeckung standardmäßig zur Versandfrist');
  assert.throws(() => buildConfig(rawConfig({ extraRounds: [{ ...rawConfig().rounds[0] }] }), KEYS), /doppelt/);
  assert.throws(() => buildConfig(rawConfig({ round: { country: 'FR' } }), KEYS), /country/);
  assert.throws(() => buildConfig(rawConfig({ round: { slug: 'admin' } }), KEYS), /slug/);
  assert.throws(() => buildConfig({ ...rawConfig(), base_url: 'https://x.de/sub' }, KEYS), /base_url/);

  const bad = rawConfig({ round: { shipping_deadline: '2026-11-01T00:00:00+01:00', reminders: ['2026-11-01T00:00:00+01:00'], min_participants: 2 } });
  bad.organizer_email = 'kaputt';
  bad.mail = { transport: 'smtp' };
  let msg = '';
  try {
    buildConfig(bad, {});
  } catch (err) {
    assert.ok(err instanceof ConfigError);
    msg = err.message;
  }
  for (const part of ['shipping_deadline muss nach', 'rounds[0].reminders[0]', 'min_participants', 'organizer_email', 'mail.from', 'SMTP_URL', 'SECRET_KEY', 'DATA_KEY']) {
    assert.ok(msg.includes(part), `Fehlermeldung enthält ${part}`);
  }
  assert.throws(() => buildConfig(rawConfig({ round: { registration_deadline: '2026-12-01T23:59:00' } }), KEYS), /Zeitzone/);
});

test('Validierung des Anmeldeformulars', () => {
  assert.deepEqual(validateParticipantForm(sampleForm(1)).errors, {});
  const { errors, values } = validateParticipantForm({ ...sampleForm(1), email: ' Foo@Example.ORG ', postal_code: '1234', adult: undefined, consent: undefined, city: '' });
  assert.equal(values.email, 'foo@example.org');
  assert.ok(errors.postal_code && errors.adult && errors.consent && errors.city);
  assert.ok(!errors.email);
  assert.ok(validateParticipantForm({ ...sampleForm(1), email: 'kein-at' }).errors.email);
  assert.ok(validateParticipantForm({ ...sampleForm(1), first_name: 'x'.repeat(81) }).errors.first_name);
});

test('Tracking-Links und Normalisierung', () => {
  assert.equal(normalizeTrackingNumber(' 1z 999-aa1 '), '1Z999AA1');
  assert.equal(trackingUrl('DE', 'DPD', '01234567890123'), 'https://tracking.dpd.de/status/de_DE/parcel/01234567890123');
  assert.match(trackingUrl('DE', 'DHL', '00340434161234567890'), /piececode=00340434161234567890$/);
  assert.deepEqual(Object.keys(carriersFor('DE')).sort(), ['DHL', 'DPD', 'GLS', 'HERMES', 'UPS']);
  assert.ok(carriersFor('AT').POST_AT && carriersFor('CH').POST_CH && carriersFor('IT').POSTE_IT && carriersFor('IT').BRT);
  for (const [country, c] of Object.entries(COUNTRIES)) {
    for (const [key, carrier] of Object.entries(c.carriers)) {
      assert.ok(carrier.url.startsWith('https://') && carrier.url.includes('{nr}'), `${country}/${key}`);
      assert.ok(trackingUrl(country, key, 'ABC123').includes('ABC123'));
    }
  }
  assert.equal(trackingUrl('DE', 'POST_CH', '1'), null, 'Dienstleister anderer Länder sind nicht wählbar');
});

test('Länderspezifische Adressprüfung', () => {
  assert.ok(validateParticipantForm(sampleForm(1, { postal_code: '1010' }), { country: 'DE' }).errors.postal_code);
  assert.deepEqual(validateParticipantForm(sampleForm(1, { postal_code: '1010' }), { country: 'AT' }).errors, {});
  assert.deepEqual(validateParticipantForm(sampleForm(1, { postal_code: '8001' }), { country: 'CH' }).errors, {});
  assert.ok(validateParticipantForm(sampleForm(1, { postal_code: '80331' }), { country: 'CH' }).errors.postal_code);
  const it = validateParticipantForm(sampleForm(1, { postal_code: '20121' }), { country: 'IT' });
  assert.ok(it.errors.province, 'Provinz ist in Italien Pflicht');
  const it2 = validateParticipantForm(sampleForm(1, { postal_code: '20121', province: 'mi' }), { country: 'IT' });
  assert.deepEqual(it2.errors, {});
  assert.equal(it2.values.province, 'MI');
});

test('HTML-Escaping', () => {
  assert.equal(esc('<script>"x" & \'y\'</script>'), '&lt;script&gt;&quot;x&quot; &amp; &#39;y&#39;&lt;/script&gt;');
  assert.equal(html`<p>${'<b>'}</p>${html`<i>ok</i>`}`.toString(), '<p>&lt;b&gt;</p><i>ok</i>');
});

test('Datumsformat in deutscher Zeit', () => {
  assert.equal(formatDateTime(new Date('2026-12-01T22:59:00Z')), '01.12.2026, 23:59 Uhr');
});

test('FileStore: verschlüsselt, atomar, Rollback bei Fehler, serialisiert', async () => {
  const dir = await fs.mkdtemp(path.join(os.tmpdir(), 'wb-store-'));
  const key = Buffer.alloc(32, 5);
  const store = new FileStore({ dataDir: dir, key });
  await store.load();
  assert.equal(store.get().status, 'OPEN');

  await Promise.all(
    Array.from({ length: 25 }, (_, i) =>
      store.update(async (d) => {
        await new Promise((r) => setTimeout(r, Math.random() * 3));
        d.participants.push({ id: `p${i}`, email: `p${i}@x.de`, mails_sent: [] });
      }),
    ),
  );
  assert.equal(store.get().participants.length, 25, 'keine verlorenen Schreibzugriffe');

  await assert.rejects(store.update((d) => {
    d.participants = [];
    throw new Error('nope');
  }));
  assert.equal(store.get().participants.length, 25, 'Rollback');

  await assert.rejects(store.update((d) => {
    d.participants.push({ id: 'p0', email: 'neu@x.de', mails_sent: [] });
  }), /doppelte ID/);

  const fileText = await fs.readFile(path.join(dir, 'round.json.enc'), 'utf8');
  assert.ok(!fileText.includes('p0@x.de'), 'Datei ist verschlüsselt');
  const stat = await fs.stat(path.join(dir, 'round.json.enc'));
  assert.equal(stat.mode & 0o777, 0o600);
  assert.deepEqual((await fs.readdir(dir)).filter((f) => f.endsWith('.tmp')), []);

  const reloaded = new FileStore({ dataDir: dir, key });
  await reloaded.load();
  assert.equal(reloaded.get().participants.length, 25);

  await store.backup('2026-11-20');
  await store.backup('2026-11-21');
  assert.equal((await fs.readdir(path.join(dir, 'backups'))).length, 2);
  const other = new FileStore({ dataDir: dir, key, name: 'at' });
  await other.load();
  await other.backup('2026-11-20');
  assert.deepEqual((await store.listBackups()).sort(), ['round-2026-11-20.json.enc', 'round-2026-11-21.json.enc']);
  await store.deleteBackups();
  assert.deepEqual(await store.listBackups(), []);
  assert.deepEqual(await other.listBackups(), ['at-2026-11-20.json.enc'], 'Backups anderer Runden bleiben');

  await assert.rejects(new FileStore({ dataDir: dir, key: Buffer.alloc(32, 9) }).load());
});
