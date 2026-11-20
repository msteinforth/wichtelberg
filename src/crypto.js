import crypto from 'node:crypto';

const b64u = (buf) => Buffer.from(buf).toString('base64url');

/**
 * Zustandslos signierte Token (HMAC-SHA256): Login-Links, Bestätigungslinks, Session-Cookies.
 * Format: base64url(JSON-Payload) "." base64url(HMAC)
 */
export function signToken(key, purpose, subject, expiresAt) {
  const payload = b64u(JSON.stringify({ p: purpose, s: subject, e: expiresAt.getTime() }));
  const mac = b64u(crypto.createHmac('sha256', key).update(payload).digest());
  return `${payload}.${mac}`;
}

/** Liefert das Subject des Tokens oder null (falsche Signatur, falscher Zweck, abgelaufen). */
export function verifyToken(key, purpose, token, now = new Date()) {
  if (typeof token !== 'string' || token.length > 1024) return null;
  const parts = token.split('.');
  if (parts.length !== 2) return null;
  const [payload, mac] = parts;
  const expected = crypto.createHmac('sha256', key).update(payload).digest();
  let given;
  try {
    given = Buffer.from(mac, 'base64url');
  } catch {
    return null;
  }
  if (given.length !== expected.length || !crypto.timingSafeEqual(given, expected)) return null;
  let data;
  try {
    data = JSON.parse(Buffer.from(payload, 'base64url').toString('utf8'));
  } catch {
    return null;
  }
  if (data.p !== purpose || typeof data.s !== 'string' || typeof data.e !== 'number') return null;
  if (now.getTime() > data.e) return null;
  return data.s;
}

/** AES-256-GCM-Verschlüsselung der Datendatei. */
export function encrypt(key, plaintext) {
  const iv = crypto.randomBytes(12);
  const cipher = crypto.createCipheriv('aes-256-gcm', key, iv);
  const ct = Buffer.concat([cipher.update(plaintext, 'utf8'), cipher.final()]);
  return JSON.stringify({ v: 1, alg: 'aes-256-gcm', iv: b64u(iv), tag: b64u(cipher.getAuthTag()), ct: b64u(ct) });
}

export function decrypt(key, envelopeText) {
  const env = JSON.parse(envelopeText);
  if (env.v !== 1 || env.alg !== 'aes-256-gcm') throw new Error('Unbekanntes Format der Datendatei');
  const decipher = crypto.createDecipheriv('aes-256-gcm', key, Buffer.from(env.iv, 'base64url'));
  decipher.setAuthTag(Buffer.from(env.tag, 'base64url'));
  return Buffer.concat([decipher.update(Buffer.from(env.ct, 'base64url')), decipher.final()]).toString('utf8');
}

export function randomId(prefix = 'p_') {
  return prefix + crypto.randomBytes(9).toString('base64url');
}

export function generateKey() {
  return crypto.randomBytes(32).toString('hex');
}
