import fs from 'node:fs/promises';
import path from 'node:path';
import { encrypt, decrypt } from './crypto.js';

export const DATA_VERSION = 1;

export function emptyData() {
  return { version: DATA_VERSION, status: 'OPEN', drawn_at: null, cancel_notified: false, last_backup: null, purged_at: null, participants: [] };
}

/**
 * Datenhaltung in einer einzigen, verschlüsselten JSON-Datei.
 * - Alle Daten werden beim Start in den Arbeitsspeicher geladen.
 * - Schreibzugriffe sind über eine Promise-Kette serialisiert (ein Prozess).
 * - Geschrieben wird atomar: temporäre Datei + fsync + rename.
 */
export class FileStore {
  constructor({ dataDir, key, name = 'round' }) {
    this.dataDir = dataDir;
    this.key = key;
    this.name = name;
    this.file = path.join(dataDir, `${name}.json.enc`);
    this.backupDir = path.join(dataDir, 'backups');
    this.data = null;
    this.queue = Promise.resolve();
  }

  async load() {
    await fs.mkdir(this.dataDir, { recursive: true, mode: 0o700 });
    let text;
    try {
      text = await fs.readFile(this.file, 'utf8');
    } catch (err) {
      if (err.code !== 'ENOENT') throw err;
      this.data = emptyData();
      await this.#write(this.data);
      return this.data;
    }
    const data = JSON.parse(decrypt(this.key, text));
    validateData(data);
    this.data = data;
    return data;
  }

  /** Aktueller Stand (nur lesen, nicht verändern). */
  get() {
    return this.data;
  }

  /**
   * Führt fn(entwurf) exklusiv aus. Wirft fn, bleibt der alte Stand erhalten.
   * Der Rückgabewert von fn wird durchgereicht.
   */
  update(fn) {
    const run = this.queue.then(async () => {
      const draft = structuredClone(this.data);
      const result = await fn(draft);
      validateData(draft);
      await this.#write(draft);
      this.data = draft;
      return result;
    });
    this.queue = run.catch(() => {});
    return run;
  }

  /** Ersetzt den gesamten Datenbestand (z. B. beim Löschen nach Ablauf der Frist). */
  replace(newData) {
    return this.update((draft) => {
      for (const k of Object.keys(draft)) delete draft[k];
      Object.assign(draft, structuredClone(newData));
    });
  }

  async #write(data) {
    const tmp = `${this.file}.${process.pid}.tmp`;
    const handle = await fs.open(tmp, 'w', 0o600);
    try {
      await handle.writeFile(encrypt(this.key, JSON.stringify(data)), 'utf8');
      await handle.sync();
    } finally {
      await handle.close();
    }
    await fs.rename(tmp, this.file);
  }

  /** Kopiert die verschlüsselte Datei nach backups/<name>-YYYY-MM-DD.json.enc und behält die letzten `keep`. */
  async backup(dateStr, keep = 7) {
    await fs.mkdir(this.backupDir, { recursive: true, mode: 0o700 });
    await fs.copyFile(this.file, path.join(this.backupDir, `${this.name}-${dateStr}.json.enc`));
    const files = (await this.#backupFiles()).sort();
    for (const f of files.slice(0, Math.max(0, files.length - keep))) {
      await fs.rm(path.join(this.backupDir, f), { force: true });
    }
  }

  async #backupFiles() {
    try {
      const re = new RegExp(`^${this.name}-\\d{4}-\\d{2}-\\d{2}\\.json\\.enc$`);
      return (await fs.readdir(this.backupDir)).filter((f) => re.test(f));
    } catch (err) {
      if (err.code === 'ENOENT') return [];
      throw err;
    }
  }

  async listBackups() {
    return this.#backupFiles();
  }

  async deleteBackups() {
    for (const f of await this.#backupFiles()) await fs.rm(path.join(this.backupDir, f), { force: true });
  }
}

const STATUSES = new Set(['OPEN', 'DRAWN', 'CANCELLED', 'CLOSED']);

export function validateData(data) {
  if (!data || data.version !== DATA_VERSION) throw new Error('Datendatei: unbekannte Version');
  if (!STATUSES.has(data.status)) throw new Error(`Datendatei: ungültiger Status ${data.status}`);
  if (!Array.isArray(data.participants)) throw new Error('Datendatei: participants fehlt');
  const ids = new Set();
  const emails = new Set();
  for (const p of data.participants) {
    if (ids.has(p.id)) throw new Error(`Datendatei: doppelte ID ${p.id}`);
    if (emails.has(p.email)) throw new Error(`Datendatei: doppelte E-Mail-Adresse`);
    ids.add(p.id);
    emails.add(p.email);
  }
  if (data.status === 'DRAWN') {
    const receivers = new Set();
    for (const p of data.participants) {
      if (!p.giftee_id || !ids.has(p.giftee_id) || p.giftee_id === p.id) {
        throw new Error('Datendatei: ungültige Zuordnung');
      }
      if (receivers.has(p.giftee_id)) throw new Error('Datendatei: Empfänger doppelt zugeordnet');
      receivers.add(p.giftee_id);
    }
  }
}
