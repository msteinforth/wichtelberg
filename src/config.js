import fs from 'node:fs';
import { parse as parseYaml } from 'yaml';
import { COUNTRIES } from './carriers.js';

export class ConfigError extends Error {}

const DAY = 24 * 60 * 60 * 1000;

function parseDate(value, name, errors) {
  if (typeof value !== 'string' && !(value instanceof Date)) {
    errors.push(`${name} fehlt oder ist kein Datum (ISO-8601 mit Zeitzone, z. B. "2026-12-01T23:59:00+01:00").`);
    return null;
  }
  if (typeof value === 'string' && !/([+-]\d{2}:?\d{2}|Z)$/.test(value.trim())) {
    errors.push(`${name} muss eine Zeitzone enthalten (z. B. "+01:00").`);
    return null;
  }
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) {
    errors.push(`${name} ist kein gültiges Datum: ${value}`);
    return null;
  }
  return d;
}

function decodeKey(value, name, errors) {
  if (!value) {
    errors.push(`Umgebungsvariable ${name} fehlt (32 Byte, hex oder base64). Erzeugen mit: npm run keys`);
    return null;
  }
  let buf = /^[0-9a-fA-F]{64}$/.test(value) ? Buffer.from(value, 'hex') : Buffer.from(value, 'base64');
  if (buf.length !== 32) {
    errors.push(`Umgebungsvariable ${name} muss genau 32 Byte lang sein (64 Hex-Zeichen oder base64).`);
    return null;
  }
  return buf;
}

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

function buildRound(r, idx, errors) {
  const at = `rounds[${idx}]`;
  r = r || {};
  const slug = String(r.slug || '');
  if (!/^[a-z0-9][a-z0-9-]{0,31}$/.test(slug) || ['admin', 'style.css', 'healthz', 'tasks', 'datenschutz', 'impressum'].includes(slug)) {
    errors.push(`${at}.slug fehlt oder ist ungültig (Kleinbuchstaben, Ziffern, Bindestrich; z. B. "de").`);
  }
  const country = String(r.country || '').toUpperCase();
  if (!COUNTRIES[country]) errors.push(`${at}.country muss eines von ${Object.keys(COUNTRIES).join(', ')} sein.`);
  if (!r.name || typeof r.name !== 'string') errors.push(`${at}.name fehlt.`);

  const registrationDeadline = parseDate(r.registration_deadline, `${at}.registration_deadline`, errors);
  const shippingDeadline = parseDate(r.shipping_deadline, `${at}.shipping_deadline`, errors);
  if (registrationDeadline && shippingDeadline && shippingDeadline <= registrationDeadline) {
    errors.push(`${at}.shipping_deadline muss nach registration_deadline liegen.`);
  }

  const reminders = [];
  if (r.reminders !== undefined && !Array.isArray(r.reminders)) {
    errors.push(`${at}.reminders muss eine Liste von Zeitpunkten sein.`);
  } else {
    (r.reminders || []).forEach((value, i) => {
      const d = parseDate(value, `${at}.reminders[${i}]`, errors);
      if (d && registrationDeadline && d <= registrationDeadline) {
        errors.push(`${at}.reminders[${i}] muss nach dem Anmeldeschluss liegen.`);
      }
      if (d) reminders.push(d);
    });
    reminders.sort((a, b) => a - b);
  }

  let revealAt = shippingDeadline;
  if (r.reveal_at !== undefined && r.reveal_at !== null) {
    revealAt = parseDate(r.reveal_at, `${at}.reveal_at`, errors);
    if (revealAt && registrationDeadline && revealAt <= registrationDeadline) errors.push(`${at}.reveal_at muss nach dem Anmeldeschluss liegen.`);
  }

  const minParticipants = r.min_participants ?? 3;
  if (!Number.isInteger(minParticipants) || minParticipants < 3) {
    errors.push(`${at}.min_participants muss eine ganze Zahl ≥ 3 sein.`);
  }
  const maxParticipants = r.max_participants ?? null;
  if (maxParticipants !== null && (!Number.isInteger(maxParticipants) || maxParticipants < minParticipants)) {
    errors.push(`${at}.max_participants muss leer oder eine ganze Zahl ≥ min_participants sein.`);
  }
  const retentionDays = r.data_retention_days ?? 30;
  if (!Number.isInteger(retentionDays) || retentionDays < 0) {
    errors.push(`${at}.data_retention_days muss eine ganze Zahl ≥ 0 sein.`);
  }
  const purgeAt = shippingDeadline ? new Date(shippingDeadline.getTime() + retentionDays * DAY) : null;
  if (purgeAt && revealAt && revealAt >= purgeAt) errors.push(`${at}.reveal_at muss vor der Datenlöschung (${retentionDays} Tage nach Versandfrist) liegen.`);

  return Object.freeze({
    slug,
    country,
    countryName: COUNTRIES[country]?.name,
    name: r.name,
    description: r.description ? String(r.description) : '',
    registrationDeadline,
    shippingDeadline,
    reminders: Object.freeze(reminders),
    revealAt,
    minParticipants,
    maxParticipants,
    retentionDays,
    purgeAt,
  });
}

/**
 * Validiert eine rohe Konfiguration (aus YAML) plus Umgebungsvariablen
 * und liefert ein normalisiertes, eingefrorenes Konfigurationsobjekt.
 */
export function buildConfig(raw, env = process.env) {
  const errors = [];
  raw = raw || {};

  if (!Array.isArray(raw.rounds) || raw.rounds.length === 0) errors.push('rounds muss mindestens eine Runde enthalten.');
  const rounds = (Array.isArray(raw.rounds) ? raw.rounds : []).map((r, i) => buildRound(r, i, errors));
  const slugs = new Set();
  for (const r of rounds) {
    if (slugs.has(r.slug)) errors.push(`Der slug "${r.slug}" ist doppelt vergeben.`);
    slugs.add(r.slug);
  }

  const organizerEmail = String(raw.organizer_email || '').trim().toLowerCase();
  if (!EMAIL_RE.test(organizerEmail)) errors.push('organizer_email fehlt oder ist ungültig.');

  let baseUrl = String(raw.base_url || '').trim().replace(/\/+$/, '');
  try {
    const u = new URL(baseUrl);
    if (!['http:', 'https:'].includes(u.protocol) || u.pathname !== '/') throw new Error();
  } catch {
    errors.push('base_url fehlt oder ist keine gültige http(s)-URL ohne Pfad (z. B. https://wichtelberg.steinforth.com).');
  }

  const mail = raw.mail || {};
  const mailTransport = mail.transport || 'smtp';
  if (!['smtp', 'log'].includes(mailTransport)) errors.push('mail.transport muss "smtp" oder "log" sein.');
  if (!mail.from) errors.push('mail.from fehlt (Absenderadresse, z. B. "Wichtelberg <wichteln@example.org>").');
  const smtpUrl = env.SMTP_URL || null;
  if (mailTransport === 'smtp' && !smtpUrl) errors.push('Umgebungsvariable SMTP_URL fehlt (z. B. smtps://user:pass@smtp.example.org:465).');

  const secretKey = decodeKey(env.SECRET_KEY, 'SECRET_KEY', errors);
  const dataKey = decodeKey(env.DATA_KEY, 'DATA_KEY', errors);

  const legal = raw.legal || {};

  if (errors.length) {
    throw new ConfigError('Ungültige Konfiguration:\n  - ' + errors.join('\n  - '));
  }

  return Object.freeze({
    rounds: Object.freeze(rounds),
    organizerEmail,
    baseUrl,
    secureCookies: baseUrl.startsWith('https://'),
    trustProxy: raw.trust_proxy === true,
    host: env.HOST || raw.host || '127.0.0.1',
    port: Number(env.PORT || raw.port || 3000),
    dataDir: env.DATA_DIR || raw.data_dir || './data',
    schedulerIntervalSeconds: Number(raw.scheduler_interval_seconds ?? 60),
    tasksToken: env.TASKS_TOKEN || null,
    mail: Object.freeze({ transport: mailTransport, from: mail.from, smtpUrl }),
    legal: Object.freeze({
      operator: legal.operator ? String(legal.operator) : '',
      imprint: legal.imprint ? String(legal.imprint) : '',
    }),
    secretKey,
    dataKey,
  });
}

export function loadConfig(path = process.env.CONFIG_PATH || './config.yaml', env = process.env) {
  let text;
  try {
    text = fs.readFileSync(path, 'utf8');
  } catch (err) {
    throw new ConfigError(`Konfigurationsdatei ${path} nicht lesbar: ${err.message}`);
  }
  return buildConfig(parseYaml(text), env);
}
