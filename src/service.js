import { signToken, verifyToken, randomId } from './crypto.js';
import { validateParticipantForm, normalizeEmail, addressFromValues } from './validation.js';
import { carriersFor, normalizeTrackingNumber, isPlausibleTrackingNumber } from './carriers.js';
import { drawCycle } from './draw.js';
import { mails } from './mails.js';
import { emptyData } from './store.js';
import { berlinDay } from './format.js';

const MIN = 60 * 1000;
const HOUR = 60 * MIN;
const DAY = 24 * HOUR;

export class UserError extends Error {
  constructor(code, message) {
    super(message || code);
    this.code = code;
  }
}

/**
 * Fachlogik einer Wichtelrunde (eine Runde = ein Land = eine Datendatei).
 * Zustand liegt ausschließlich im FileStore; Token sind zustandslos signiert
 * und an die Runde gebunden (Zweck enthält den slug).
 */
export class WichtelService {
  constructor({ config, round, store, mailer, now = () => new Date(), logger = console }) {
    this.config = config;
    this.round = round;
    this.store = store;
    this.mailer = mailer;
    this.now = now;
    this.logger = logger;
    this.mailThrottle = new Map(); // E-Mail-Adresse -> Zeitstempel der letzten Systemmails (nur im Speicher)
    this.tasksRunning = null;
  }

  // ---------- Lesen ----------

  get data() {
    return this.store.get();
  }

  find(id) {
    return this.data.participants.find((p) => p.id === id) || null;
  }

  findByEmail(email) {
    return this.data.participants.find((p) => p.email === email) || null;
  }

  isRegistrationOpen() {
    return this.data.status === 'OPEN' && this.now() < this.round.registrationDeadline;
  }

  confirmedCount() {
    return this.data.participants.filter((p) => p.email_confirmed_at).length;
  }

  /** Wer beschenkt `id`? (nur intern, z. B. für die Anzeige „Dein Wein ist unterwegs“) */
  santaOf(id) {
    return this.data.participants.find((p) => p.giftee_id === id) || null;
  }

  // ---------- Links & Token ----------

  /** Basis-Pfad der Runde, z. B. "/de". */
  get base() {
    return `/${this.round.slug}`;
  }

  url(path) {
    return this.config.baseUrl + this.base + path;
  }

  purpose(kind) {
    return `${kind}:${this.round.slug}`;
  }

  /** Persönlicher Link aus E-Mails: meldet an und führt zum Dashboard. */
  personalLink(subject, validMs = 7 * DAY) {
    const t = signToken(this.config.secretKey, this.purpose('login'), subject, new Date(this.now().getTime() + validMs));
    return this.url(`/login/link?t=${encodeURIComponent(t)}`);
  }

  sessionToken(subject) {
    return signToken(this.config.secretKey, this.purpose('session'), subject, new Date(this.now().getTime() + 30 * DAY));
  }

  verifySession(token) {
    const subject = verifyToken(this.config.secretKey, this.purpose('session'), token, this.now());
    if (!subject) return null;
    const p = this.find(subject);
    return p ? { participant: p } : null;
  }

  verifyLoginToken(token) {
    const subject = verifyToken(this.config.secretKey, this.purpose('login'), token, this.now());
    if (!subject) return null;
    return this.find(subject) ? subject : null;
  }

  // ---------- Mailversand ----------

  /** Begrenzung pro Empfänger: max. 3 Anmelde-/Login-Mails pro Stunde. */
  throttled(email) {
    const now = this.now().getTime();
    const list = (this.mailThrottle.get(email) || []).filter((t) => now - t < HOUR);
    if (list.length >= 3) return true;
    list.push(now);
    this.mailThrottle.set(email, list);
    if (this.mailThrottle.size > 10000) this.mailThrottle.clear();
    return false;
  }

  async send(to, msg) {
    await this.mailer.send({ to, ...msg });
  }

  /** Sendet eine Mail und vermerkt sie danach am Teilnehmer (höchstens einmal pro Marke). */
  async sendOnce(participantId, mark, buildMsg) {
    const p = this.find(participantId);
    if (!p || p.mails_sent.includes(mark)) return false;
    const msg = buildMsg(p);
    if (!msg) return false;
    try {
      await this.send(p.email, msg);
    } catch (err) {
      this.logger.error(`Mail ${mark} an ${participantId} fehlgeschlagen: ${err.message}`);
      return false;
    }
    await this.store.update((d) => {
      const x = d.participants.find((q) => q.id === participantId);
      if (x && !x.mails_sent.includes(mark)) x.mails_sent.push(mark);
    });
    return true;
  }

  ctx() {
    return { config: this.config, round: this.round, roundUrl: this.url('') };
  }

  async sendConfirmMail(p) {
    const expiry = new Date(Math.min(this.now().getTime() + 48 * HOUR, this.round.registrationDeadline.getTime()));
    const t = signToken(this.config.secretKey, this.purpose('confirm'), p.id, expiry);
    await this.send(p.email, mails.confirm(this.ctx(), p, this.url(`/bestaetigen?t=${encodeURIComponent(t)}`), expiry));
  }

  async sendLoginMail(p) {
    await this.send(p.email, mails.login(this.ctx(), p, this.personalLink(p.id, 30 * MIN)));
  }

  // ---------- Anmeldung (A-1 … A-9) ----------

  /**
   * Neuanmeldung. Liefert bei Erfolg immer dasselbe Ergebnis – unabhängig davon,
   * ob die Adresse schon existiert (keine Enumeration, A-6).
   */
  async register(form) {
    if (!this.isRegistrationOpen()) throw new UserError('closed', 'Die Anmeldung ist geschlossen.');
    const { values, errors } = validateParticipantForm(form, { country: this.round.country });
    if (Object.keys(errors).length) return { errors, values };

    const existing = this.findByEmail(values.email);
    if (existing) {
      if (!this.throttled(values.email)) {
        if (existing.email_confirmed_at) await this.sendLoginMail(existing);
        else await this.sendConfirmMail(existing);
      }
      return { ok: true };
    }

    const max = this.round.maxParticipants;
    if (max && this.data.participants.filter((p) => p.email_confirmed_at).length >= max) {
      throw new UserError('full', 'Die maximale Teilnehmerzahl ist erreicht.');
    }

    const now = this.now().toISOString();
    const participant = await this.store.update((d) => {
      if (d.participants.some((p) => p.email === values.email)) return null; // Wettlauf: parallel angelegt
      const p = {
        id: randomId(),
        email: values.email,
        first_name: values.first_name,
        last_name: values.last_name,
        address: addressFromValues(values),
        note: values.note || null,
        adult_confirmed: true,
        consent_at: now,
        created_at: now,
        email_confirmed_at: null,
        giftee_id: null,
        shipment: null,
        reveal: false,
        mails_sent: [],
      };
      d.participants.push(p);
      return p;
    });
    if (participant && !this.throttled(participant.email)) {
      try {
        await this.sendConfirmMail(participant);
      } catch (err) {
        this.logger.error(`Bestätigungsmail fehlgeschlagen: ${err.message}`);
        throw new UserError('mail', 'Die Bestätigungs-E-Mail konnte nicht versendet werden. Bitte versuche es später erneut.');
      }
    }
    return { ok: true };
  }

  /** Double-Opt-in (A-5). Liefert die Teilnehmer-ID. */
  async confirm(token) {
    const id = verifyToken(this.config.secretKey, this.purpose('confirm'), token, this.now());
    if (!id || !this.find(id)) throw new UserError('invalid_link', 'Der Bestätigungslink ist ungültig oder abgelaufen.');
    const p = this.find(id);
    if (!p.email_confirmed_at) {
      if (!this.isRegistrationOpen()) throw new UserError('closed', 'Die Anmeldung ist bereits geschlossen.');
      await this.store.update((d) => {
        const x = d.participants.find((q) => q.id === id);
        if (x) x.email_confirmed_at = this.now().toISOString();
      });
      await this.sendOnce(id, 'WELCOME', (x) => mails.welcome(this.ctx(), x, this.personalLink(x.id)));
    }
    return id;
  }

  /** Magic Link anfordern (L-1). Antwort ist für den Aufrufer immer gleich. */
  async requestLogin(rawEmail) {
    const email = normalizeEmail(rawEmail);
    const p = email && this.findByEmail(email);
    if (!p || this.throttled(email)) return;
    try {
      if (p.email_confirmed_at) await this.sendLoginMail(p);
      else if (this.isRegistrationOpen()) await this.sendConfirmMail(p);
    } catch (err) {
      this.logger.error(`Login-Mail fehlgeschlagen: ${err.message}`);
    }
  }

  /** Eigene Daten ändern (A-9) – nur bis zum Anmeldeschluss. */
  async updateParticipant(id, form) {
    if (!this.isRegistrationOpen()) throw new UserError('closed', 'Nach dem Anmeldeschluss können die Daten nicht mehr geändert werden.');
    const { values, errors } = validateParticipantForm(form, { country: this.round.country, requireEmail: false, requireConsents: false });
    if (Object.keys(errors).length) return { errors, values };
    await this.store.update((d) => {
      const p = d.participants.find((q) => q.id === id);
      if (!p) throw new UserError('not_found');
      p.first_name = values.first_name;
      p.last_name = values.last_name;
      p.address = addressFromValues(values);
      p.note = values.note || null;
    });
    return { ok: true };
  }

  /** Abmelden (A-9): Daten werden sofort gelöscht. */
  async unregister(id) {
    if (!this.isRegistrationOpen()) throw new UserError('closed', 'Nach dem Anmeldeschluss ist eine Abmeldung nur über den Organisator möglich.');
    await this.store.update((d) => {
      d.participants = d.participants.filter((p) => p.id !== id);
    });
  }

  // ---------- Versand (V-1 … V-7) ----------

  async submitShipment(id, form) {
    if (this.data.status !== 'DRAWN') throw new UserError('not_drawn', 'Die Auslosung hat noch nicht stattgefunden.');
    const carrier = String(form.carrier || '');
    const trackingNumber = normalizeTrackingNumber(form.tracking_number);
    const errors = {};
    if (!carriersFor(this.round.country)[carrier]) errors.carrier = 'Bitte wähle einen Versanddienstleister.';
    if (!trackingNumber) errors.tracking_number = 'Bitte gib die Sendungsnummer ein.';
    else if (!isPlausibleTrackingNumber(trackingNumber)) errors.tracking_number = 'Die Sendungsnummer darf nur Buchstaben und Ziffern enthalten (6–40 Zeichen).';
    if (Object.keys(errors).length) return { errors, values: { carrier, tracking_number: form.tracking_number || '' } };

    const p = this.find(id);
    if (p?.shipment && p.shipment.carrier === carrier && p.shipment.tracking_number === trackingNumber) return { ok: true };

    const now = this.now().toISOString();
    await this.store.update((d) => {
      const x = d.participants.find((q) => q.id === id);
      if (!x) throw new UserError('not_found');
      const isUpdate = Boolean(x.shipment);
      x.shipment = {
        carrier,
        tracking_number: trackingNumber,
        submitted_at: x.shipment?.submitted_at || now,
        updated_at: now,
        is_update: isUpdate,
        notified_at: null,
      };
    });
    await this.notifyShipment(id);
    return { ok: true };
  }

  /** Darf der Empfänger nach Abschluss erfahren, wer ihn beschenkt hat? Entscheidet der Schenkende. */
  canChangeReveal() {
    return this.data.status === 'DRAWN' && this.now() < this.round.revealAt;
  }

  isRevealTime() {
    return this.data.status === 'DRAWN' && this.now() >= this.round.revealAt;
  }

  async setReveal(id, reveal) {
    if (!this.canChangeReveal()) throw new UserError('reveal_closed', 'Die Aufdeckung kann nicht mehr geändert werden.');
    await this.store.update((d) => {
      const x = d.participants.find((q) => q.id === id);
      if (!x) throw new UserError('not_found');
      x.reveal = Boolean(reveal);
    });
  }

  /** Name des Schenkenden, falls aufgedeckt (nach revealAt, Zustimmung, Versand gemeldet). */
  revealedSanta(receiverId) {
    if (!this.isRevealTime()) return null;
    const santa = this.santaOf(receiverId);
    return santa && santa.reveal && santa.shipment ? santa : null;
  }

  /** Benachrichtigt den Empfänger (M-7). Bei Fehlschlag holt runTasks() das nach. */
  async notifyShipment(giverId) {
    const giver = this.find(giverId);
    if (!giver?.shipment || giver.shipment.notified_at) return;
    const receiver = this.find(giver.giftee_id);
    if (!receiver) return;
    const s = giver.shipment;
    try {
      await this.send(receiver.email, mails.shipped(this.ctx(), receiver, s, this.personalLink(receiver.id), s.is_update));
    } catch (err) {
      this.logger.error(`Versandbenachrichtigung an ${receiver.id} fehlgeschlagen: ${err.message}`);
      return;
    }
    await this.store.update((d) => {
      const x = d.participants.find((q) => q.id === giverId);
      if (x?.shipment && x.shipment.updated_at === s.updated_at) x.shipment.notified_at = this.now().toISOString();
    });
  }

  // ---------- Zeitgesteuerte Aufgaben (zustandsbasiert) ----------

  /** Führt alle fälligen Aufgaben aus. Mehrfacher Aufruf ist unschädlich (idempotent). */
  runTasks() {
    if (!this.tasksRunning) {
      this.tasksRunning = this.#runTasks().finally(() => {
        this.tasksRunning = null;
      });
    }
    return this.tasksRunning;
  }

  async #runTasks() {
    const now = this.now();
    const r = this.round;

    // Löschfrist erreicht: alle personenbezogenen Daten und Backups entfernen.
    if (now >= r.purgeAt) {
      if (this.data.status !== 'CLOSED') {
        await this.store.replace({ ...emptyData(), status: 'CLOSED', purged_at: now.toISOString() });
        await this.store.deleteBackups();
        this.logger.log('Löschfrist erreicht: Daten gelöscht.');
      }
      return;
    }

    // Frist wurde nach einer abgesagten Auslosung verlängert -> Anmeldung wieder öffnen.
    if (this.data.status === 'CANCELLED' && now < r.registrationDeadline) {
      await this.store.update((d) => {
        d.status = 'OPEN';
        d.cancel_notified = false;
        for (const p of d.participants) p.mails_sent = p.mails_sent.filter((m) => m !== 'CANCELLED');
      });
    }

    // Anmeldeschluss erreicht -> auslosen (D-1 … D-5, D-7).
    if (this.data.status === 'OPEN' && now >= r.registrationDeadline) {
      await this.store.update((d) => {
        if (d.status !== 'OPEN') return;
        d.participants = d.participants.filter((p) => p.email_confirmed_at); // D-2
        if (d.participants.length < r.minParticipants) {
          d.status = 'CANCELLED';
          return;
        }
        const pairs = drawCycle(d.participants.map((p) => p.id));
        for (const p of d.participants) p.giftee_id = pairs.get(p.id);
        d.status = 'DRAWN';
        d.drawn_at = now.toISOString();
      });
      this.logger.log(`Auslosung: Status ${this.data.status}, ${this.data.participants.length} Teilnehmer.`);
    }

    if (this.data.status === 'CANCELLED') {
      const count = this.data.participants.length;
      for (const p of this.data.participants) {
        await this.sendOnce(p.id, 'CANCELLED', (x) => mails.cancelled(this.ctx(), x, count));
      }
      if (!this.data.cancel_notified) {
        try {
          await this.send(this.config.organizerEmail, mails.cancelledOrganizer(this.ctx(), count));
          await this.store.update((d) => {
            d.cancel_notified = true;
          });
        } catch (err) {
          this.logger.error(`Mail an Organisator fehlgeschlagen: ${err.message}`);
        }
      }
    }

    if (this.data.status === 'DRAWN') {
      // Partner-Mails (M-5, D-8)
      for (const p of this.data.participants) {
        await this.sendOnce(p.id, 'DRAW', (x) => mails.draw(this.ctx(), x, this.find(x.giftee_id), this.personalLink(x.id)));
      }
      // Ausstehende Versandbenachrichtigungen nachholen (M-7)
      for (const p of this.data.participants) {
        if (p.shipment && !p.shipment.notified_at) await this.notifyShipment(p.id);
      }
      // Erinnerungen (E-1, E-3) – nur für Zeitpunkte nach der Auslosung
      const drawnAt = new Date(this.data.drawn_at);
      for (let i = 0; i < r.reminders.length; i++) {
        const at = r.reminders[i];
        if (at > now || at <= drawnAt) continue;
        const isLate = at > r.shippingDeadline;
        for (const p of this.data.participants) {
          if (p.shipment) continue;
          await this.sendOnce(p.id, `REMINDER_${i}`, (x) =>
            x.shipment ? null : mails.reminder(this.ctx(), x, this.find(x.giftee_id), this.personalLink(x.id), isLate),
          );
        }
      }
      // Aufdeckung (M-11): nur wenn der Schenkende zugestimmt und verschickt hat
      if (this.isRevealTime()) {
        for (const santa of this.data.participants) {
          if (!santa.reveal || !santa.shipment) continue;
          const receiver = this.find(santa.giftee_id);
          await this.sendOnce(receiver.id, 'REVEAL', (x) => mails.reveal(this.ctx(), x, santa, this.personalLink(x.id)));
        }
      }
    }

    // Tägliches Backup (verschlüsselt)
    const today = berlinDay(now);
    if (this.data.last_backup !== today) {
      await this.store.update((d) => {
        d.last_backup = today;
      });
      await this.store.backup(today);
    }
  }
}
