import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { buildConfig } from '../src/config.js';
import { FileStore } from '../src/store.js';
import { createMemoryMailer } from '../src/mailer.js';
import { WichtelService } from '../src/service.js';

export const KEYS = {
  SECRET_KEY: '11'.repeat(32),
  DATA_KEY: '22'.repeat(32),
  TASKS_TOKEN: 'cron-secret',
};

export function rawConfig(overrides = {}) {
  return {
    rounds: [{
      slug: 'de',
      country: 'DE',
      name: 'Test-Wichteln',
      description: 'Eine Flasche für ca. 20 €.',
      registration_deadline: '2026-12-01T23:59:00+01:00',
      shipping_deadline: '2026-12-10T23:59:00+01:00',
      reminders: ['2026-12-08T10:00:00+01:00'],
      min_participants: 3,
      data_retention_days: 30,
      ...(overrides.round || {}),
    }, ...(overrides.extraRounds || [])],
    organizer_email: 'orga@example.org',
    base_url: 'http://localhost:3000',
    mail: { transport: 'log', from: 'Wichtelberg <wichteln@example.org>' },
    scheduler_interval_seconds: 0,
    ...Object.fromEntries(Object.entries(overrides).filter(([k]) => !['round', 'extraRounds'].includes(k))),
  };
}

export class Clock {
  constructor(iso) {
    this.t = new Date(iso);
  }
  now = () => new Date(this.t);
  set(iso) {
    this.t = new Date(iso);
  }
}

export async function setup({ overrides, start = '2026-11-20T12:00:00+01:00', baseUrl } = {}) {
  const raw = rawConfig(overrides);
  if (baseUrl) raw.base_url = baseUrl;
  const dataDir = await fs.mkdtemp(path.join(os.tmpdir(), 'wichtelberg-test-'));
  const config = buildConfig({ ...raw, data_dir: dataDir }, { ...KEYS });
  const mailer = createMemoryMailer();
  const clock = new Clock(start);
  const silent = { log() {}, error() {} };
  const services = [];
  for (const round of config.rounds) {
    const store = new FileStore({ dataDir, key: config.dataKey, name: round.slug });
    await store.load();
    services.push(new WichtelService({ config, round, store, mailer, now: clock.now, logger: silent }));
  }
  const service = services[0];
  return { config, store: service.store, mailer, clock, service, services, dataDir, silent };
}

export const sampleForm = (i, extra = {}) => ({
  email: `person${i}@example.org`,
  first_name: `Vorname${i}`,
  last_name: `Nachname${i}`,
  street: 'Hauptstraße',
  house_number: String(i),
  postal_code: '50667',
  city: 'Köln',
  note: i === 1 ? 'Lieber Rotwein' : '',
  adult: 'on',
  consent: 'on',
  ...extra,
});

/** Extrahiert den Token-Parameter t aus dem ersten Link der letzten Mail an `to`. */
export function tokenFromMail(mailer, to, pathPrefix) {
  const msg = [...mailer.outbox].reverse().find((m) => m.to === to && m.text.includes(pathPrefix));
  if (!msg) throw new Error(`Keine Mail an ${to} mit ${pathPrefix}`);
  const m = msg.text.match(new RegExp(`${pathPrefix.replace(/[?/]/g, '\\$&')}\\?t=([^\\s]+)`));
  return decodeURIComponent(m[1]);
}

/** Registriert und bestätigt n Teilnehmer über den Service. */
export async function registerConfirmed(env, n) {
  const ids = [];
  for (let i = 1; i <= n; i++) {
    const res = await env.service.register(sampleForm(i));
    if (!res.ok) throw new Error(JSON.stringify(res.errors));
    const t = tokenFromMail(env.mailer, `person${i}@example.org`, '/bestaetigen');
    ids.push(await env.service.confirm(t));
  }
  return ids;
}
