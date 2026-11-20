import { formatDateTime, timeLeft, formatAddress } from './format.js';
import { COUNTRIES, carriersFor, carrierLabel, trackingUrl } from './carriers.js';

// ---------- HTML-Hilfen mit automatischem Escaping ----------

class Safe {
  constructor(s) {
    this.s = s;
  }
  toString() {
    return this.s;
  }
}
export const raw = (s) => new Safe(String(s));

export function esc(v) {
  if (v instanceof Safe) return v.s;
  if (Array.isArray(v)) return v.map(esc).join('');
  if (v === null || v === undefined || v === false) return '';
  return String(v).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
}

export function html(strings, ...values) {
  let out = strings[0];
  values.forEach((v, i) => {
    out += esc(v) + strings[i + 1];
  });
  return new Safe(out);
}

const nl2br = (s) => raw(esc(s).replace(/\n/g, '<br>'));

// ---------- Layout ----------

export function layout(ctx, title, body) {
  const { service, session } = ctx;
  const round = service?.round;
  const base = service?.base;
  const brand = round ? html`<a class="brand" href="${base}">🍷 ${round.name}</a>` : html`<a class="brand" href="/">🍷 Wichtelberg</a>`;
  let navItems = '';
  if (session?.admin) {
    navItems = html`<a href="/admin">Übersicht</a><form method="post" action="/admin/logout" class="inline"><button class="link">Abmelden</button></form>`;
  } else if (round) {
    navItems = session?.participant
      ? html`<a href="${base}/dashboard">Mein Bereich</a><form method="post" action="${base}/logout" class="inline"><button class="link">Abmelden</button></form>`
      : html`<a href="${base}/login">Anmelden</a>`;
  }
  return html`<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${title} · ${round ? round.name : 'Wichtelberg'}</title>
<link rel="stylesheet" href="/style.css">
</head>
<body>
<header class="top"><div class="wrap">
  ${brand}
  <nav>${navItems}</nav>
</div></header>
<main class="wrap">
${body}
</main>
<footer class="wrap foot"><a href="/">Alle Runden</a> · <a href="/datenschutz">Datenschutz</a> · <a href="/impressum">Impressum</a></footer>
</body>
</html>`.toString();
}

const err = (errors, name) => (errors?.[name] ? html`<span class="err">${errors[name]}</span>` : '');

function field(label, name, values, errors, { type = 'text', required = true, autocomplete, maxlength, inputmode, pattern, hint } = {}) {
  return html`<label class="${errors?.[name] ? 'invalid' : ''}">${label}${required ? '' : html` <small>(optional)</small>`}
  <input type="${type}" name="${name}" value="${values?.[name] ?? ''}"${raw(required ? ' required' : '')}${raw(autocomplete ? ` autocomplete="${autocomplete}"` : '')}${raw(maxlength ? ` maxlength="${maxlength}"` : '')}${raw(inputmode ? ` inputmode="${inputmode}"` : '')}${raw(pattern ? ` pattern="${pattern}"` : '')}>
  ${hint ? html`<small>${hint}</small>` : ''}${err(errors, name)}</label>`;
}

function addressFields(country, values, errors) {
  const c = COUNTRIES[country];
  return html`
  <div class="row">${field('Vorname', 'first_name', values, errors, { autocomplete: 'given-name', maxlength: 80 })}
  ${field('Nachname', 'last_name', values, errors, { autocomplete: 'family-name', maxlength: 80 })}</div>
  <div class="row"><div class="grow">${field('Straße', 'street', values, errors, { autocomplete: 'address-line1', maxlength: 120 })}</div>
  <div class="small">${field('Hausnr.', 'house_number', values, errors, { maxlength: 20 })}</div></div>
  ${field('Adresszusatz', 'extra', values, errors, { required: false, autocomplete: 'address-line2', maxlength: 120, hint: 'z. B. c/o, Firma, Etage' })}
  <div class="row"><div class="small">${field(c.postalCode.label, 'postal_code', values, errors, { autocomplete: 'postal-code', maxlength: c.postalCode.length, inputmode: 'numeric', pattern: `[0-9]{${c.postalCode.length}}` })}</div>
  <div class="grow">${field('Ort', 'city', values, errors, { autocomplete: 'address-level2', maxlength: 80 })}</div>
  ${c.province ? html`<div class="small">${field('Provinz', 'province', values, errors, { maxlength: 2, hint: 'z. B. MI' })}</div>` : ''}</div>
  <label>Land<input value="${c.name}" disabled></label>
  <label class="${errors?.note ? 'invalid' : ''}">Hinweis für Deinen Wichtel <small>(optional)</small>
    <textarea name="note" maxlength="300" rows="2" placeholder="z. B. „lieber Rotwein“, „kein Riesling“">${values?.note ?? ''}</textarea>${err(errors, 'note')}</label>`;
}

function participantValues(p) {
  return { first_name: p.first_name, last_name: p.last_name, ...p.address, province: p.address.province || '', note: p.note || '' };
}

const flash = (msg) => (msg ? html`<p class="notice">${msg}</p>` : '');

// ---------- Seiten ----------

export function roundPage(ctx, { values = {}, errors = null, message = null } = {}) {
  const { service } = ctx;
  const r = service.round;
  const base = service.base;
  const now = service.now();
  const status = service.data.status;
  let content;
  if (service.isRegistrationOpen()) {
    const full = r.maxParticipants && service.confirmedCount() >= r.maxParticipants;
    content = full
      ? html`<p class="notice">Die maximale Teilnehmerzahl ist erreicht.</p>`
      : html`
      <h2>Jetzt anmelden</h2>
      ${errors ? html`<p class="notice error">Bitte prüfe Deine Angaben.</p>` : ''}
      <form method="post" action="${base}/anmelden" class="card" novalidate>
        ${field('E-Mail-Adresse', 'email', values, errors, { type: 'email', autocomplete: 'email', maxlength: 254 })}
        ${addressFields(r.country, values, errors)}
        <label class="hp" aria-hidden="true">Website<input name="website" tabindex="-1" autocomplete="off"></label>
        <label class="check ${errors?.adult ? 'invalid' : ''}"><input type="checkbox" name="adult"${raw(values.adult === 'on' ? ' checked' : '')}> Ich bin mindestens 18 Jahre alt.${err(errors, 'adult')}</label>
        <label class="check ${errors?.consent ? 'invalid' : ''}"><input type="checkbox" name="consent"${raw(values.consent === 'on' ? ' checked' : '')}> Ich habe die <a href="/datenschutz">Datenschutzerklärung</a> gelesen und bin einverstanden, dass mein Name, meine Adresse und mein Hinweis nach der Auslosung an meinen Wichtelpartner weitergegeben werden.${err(errors, 'consent')}</label>
        <button>Anmelden</button>
        <p><small>Du erhältst anschließend eine E-Mail mit einem Bestätigungslink. Bitte schau auch im Spam-Ordner nach.</small></p>
      </form>`;
  } else if (status === 'DRAWN') {
    content = html`<p class="notice">Die Anmeldung ist geschlossen und die Lose sind gezogen. Teilnehmer finden ihren Wichtelpartner in <a href="${base}/dashboard">ihrem Bereich</a>.</p>`;
  } else if (status === 'CLOSED') {
    content = html`<p class="notice">Diese Wichtelrunde ist abgeschlossen. Danke fürs Mitmachen!</p>`;
  } else if (status === 'CANCELLED') {
    content = html`<p class="notice">Die Auslosung konnte mangels Teilnehmern nicht stattfinden.</p>`;
  } else {
    content = html`<p class="notice">Die Anmeldung ist geschlossen. Die Auslosung erfolgt in Kürze.</p>`;
  }
  return layout(
    ctx,
    'Wein-Wichteln',
    html`
    <section class="hero">
      <h1>${r.name}</h1>
      <p class="meta">Für Teilnehmer mit Lieferadresse in ${r.countryName}</p>
      ${r.description ? html`<p>${nl2br(r.description)}</p>` : ''}
      <ol class="steps">
        <li>Bis <strong>${formatDateTime(r.registrationDeadline)}</strong> anmelden.</li>
        <li>Danach wird ausgelost – Du erfährst per E-Mail, wen Du beschenkst.</li>
        <li>Wein bis <strong>${formatDateTime(r.shippingDeadline)}</strong> verschicken und Sendungsnummer eintragen.</li>
      </ol>
      ${status === 'OPEN' ? html`<p class="meta">Anmeldeschluss: ${timeLeft(r.registrationDeadline, now)} · ${service.confirmedCount()} bestätigte Teilnehmer</p>` : ''}
    </section>
    ${flash(message)}
    ${content}`,
  );
}

export function messagePage(ctx, title, text, { link } = {}) {
  return layout(ctx, title, html`<h1>${title}</h1><p>${text}</p>${link ? html`<p><a href="${link.href}">${link.label}</a></p>` : ''}`);
}

export function confirmPage(ctx, token, action, title, text, button) {
  return layout(
    ctx,
    title,
    html`<h1>${title}</h1><p>${text}</p>
    <form method="post" action="${action}"><input type="hidden" name="t" value="${token}"><button>${button}</button></form>`,
  );
}

export function loginPage(ctx, { sent = false, action } = {}) {
  return layout(
    ctx,
    'Anmelden',
    html`<h1>Anmelden</h1>
    ${sent
      ? html`<p class="notice">Falls die Adresse bei uns registriert ist, haben wir Dir einen Anmeldelink geschickt. Er ist 30 Minuten gültig.</p>`
      : html`<p>Gib Deine E-Mail-Adresse ein. Wir schicken Dir einen Link, mit dem Du Dich ohne Passwort anmeldest.</p>`}
    <form method="post" action="${action}" class="card">
      <label>E-Mail-Adresse<input type="email" name="email" required autocomplete="email" maxlength="254"></label>
      <button>Link anfordern</button>
    </form>`,
  );
}

export function dashboardPage(ctx, p, { values = null, errors = null, message = null } = {}) {
  const { service } = ctx;
  const r = service.round;
  const base = service.base;
  const country = r.country;
  const status = service.data.status;
  let body;

  if (status === 'OPEN') {
    const open = service.isRegistrationOpen();
    body = html`
    <h1>Hallo ${p.first_name}!</h1>
    ${flash(message)}
    ${p.email_confirmed_at
      ? html`<p class="notice ok">Du bist angemeldet. Die Auslosung findet am <strong>${formatDateTime(r.registrationDeadline)}</strong> statt.</p>`
      : html`<p class="notice">Bitte bestätige noch Deine E-Mail-Adresse über den Link in unserer E-Mail.</p>`}
    ${open
      ? html`
      <h2>Meine Daten</h2>
      <form method="post" action="${base}/dashboard/daten" class="card" novalidate>
        <label>E-Mail-Adresse<input value="${p.email}" disabled></label>
        ${addressFields(country, values || participantValues(p), errors)}
        <button>Speichern</button>
      </form>
      <h2>Abmelden</h2>
      <form method="post" action="${base}/dashboard/abmelden" class="card">
        <p>Du kannst Deine Teilnahme bis zum Anmeldeschluss zurückziehen. Deine Daten werden dabei sofort gelöscht.</p>
        <label class="check"><input type="checkbox" name="sure" required> Ja, ich möchte nicht mehr teilnehmen.</label>
        <button class="danger">Teilnahme zurückziehen</button>
      </form>`
      : html`<p>Die Anmeldung ist geschlossen, die Auslosung läuft in Kürze.</p>`}`;
  } else if (status === 'DRAWN') {
    const giftee = service.find(p.giftee_id);
    const santa = service.santaOf(p.id);
    const s = p.shipment;
    const incoming = santa?.shipment;
    const revealed = service.revealedSanta(p.id);
    const v = values || (s ? { carrier: s.carrier, tracking_number: s.tracking_number } : {});
    body = html`
    <h1>Hallo ${p.first_name}!</h1>
    ${flash(message)}
    <section class="card">
      <h2>Du beschenkst</h2>
      <address>${nl2br(formatAddress(giftee, r.countryName))}</address>
      ${giftee.note ? html`<p class="quote">„${giftee.note}“</p>` : ''}
      <p class="meta">Versandfrist: ${formatDateTime(r.shippingDeadline)}</p>
      <details><summary>Tipps zum Weinversand</summary>
        <ul><li>Flasche bruchsicher verpacken, am besten in einem Flaschenkarton.</li>
        <li>Paket als „Zerbrechlich“ kennzeichnen und die Versandbedingungen des Dienstleisters für Glas und Flüssigkeiten beachten.</li>
        <li>Alkohol darf nur an Erwachsene verschickt werden.</li></ul>
      </details>
    </section>
    <section class="card">
      <h2>${s ? 'Versand gemeldet ✓' : 'Versand melden'}</h2>
      ${s
        ? html`<p>${carrierLabel(country, s.carrier)} · <a href="${trackingUrl(country, s.carrier, s.tracking_number)}" rel="noopener noreferrer" target="_blank">${s.tracking_number}</a><br><small>${s.notified_at ? 'Dein Wichtelpartner wurde benachrichtigt.' : 'Die Benachrichtigung Deines Wichtelpartners wird versendet.'}</small></p><p>Korrigieren:</p>`
        : html`<p>Sobald Du Deinen Wein verschickt hast, trage hier Versanddienstleister und Sendungsnummer ein. Dein Wichtelpartner bekommt dann eine E-Mail mit dem Tracking-Link – ohne Deinen Namen.</p>`}
      <form method="post" action="${base}/dashboard/versand" novalidate>
        <label class="${errors?.carrier ? 'invalid' : ''}">Versanddienstleister
          <select name="carrier" required>
            <option value="">Bitte wählen …</option>
            ${Object.entries(carriersFor(country)).map(([k, c]) => html`<option value="${k}"${raw(v.carrier === k ? ' selected' : '')}>${c.label}</option>`)}
          </select>${err(errors, 'carrier')}</label>
        ${field('Sendungsnummer', 'tracking_number', v, errors, { maxlength: 60, autocomplete: 'off' })}
        <button>${s ? 'Sendungsdaten aktualisieren' : 'Versand melden'}</button>
      </form>
    </section>
    <section class="card">
      <h2>Aufdecken?</h2>
      ${service.canChangeReveal()
        ? html`<form method="post" action="${base}/dashboard/aufdecken">
          <p>Soll Dein Wichtelpartner am <strong>${formatDateTime(r.revealAt)}</strong> erfahren, dass der Wein von Dir kam? Du kannst das bis dahin jederzeit ändern.</p>
          <label class="check"><input type="radio" name="reveal" value="yes"${raw(p.reveal ? ' checked' : '')}> Ja, mein Name darf verraten werden.</label>
          <label class="check"><input type="radio" name="reveal" value="no"${raw(!p.reveal ? ' checked' : '')}> Nein, ich bleibe geheim.</label>
          <button>Speichern</button></form>`
        : html`<p>${p.reveal ? 'Du hast Dich zu erkennen gegeben.' : 'Du bleibst geheim.'}</p>`}
    </section>
    <section class="card">
      <h2>Dein Wein</h2>
      ${incoming
        ? html`<p>Dein Wichtel hat Dir etwas geschickt! 🍷<br>${carrierLabel(country, incoming.carrier)} · <a href="${trackingUrl(country, incoming.carrier, incoming.tracking_number)}" rel="noopener noreferrer" target="_blank">Sendung ${incoming.tracking_number} verfolgen</a></p>`
        : html`<p>Noch keine Sendung gemeldet. Wir schicken Dir eine E-Mail, sobald Dein Wein unterwegs ist.</p>`}
      ${revealed ? html`<p class="notice ok">Das Geheimnis ist gelüftet: Dein Wein kam von <strong>${revealed.first_name} ${revealed.last_name}</strong>.</p>` : ''}
    </section>`;
  } else if (status === 'CANCELLED') {
    body = html`<h1>Hallo ${p.first_name}!</h1><p class="notice">Die Auslosung konnte mangels Teilnehmern leider nicht stattfinden.</p>`;
  } else {
    body = html`<h1>Diese Runde ist abgeschlossen.</h1>`;
  }
  return layout(ctx, 'Mein Bereich', body);
}

const STATUS_LABEL = { OPEN: 'Anmeldung offen', DRAWN: 'Ausgelost – Versandphase', CANCELLED: 'Abgesagt (zu wenig Teilnehmer)', CLOSED: 'Abgeschlossen, Daten gelöscht' };

function adminRound(service) {
  const d = service.data;
  const r = service.round;
  const ps = [...d.participants].sort((a, b) => a.last_name.localeCompare(b.last_name, 'de'));
  const confirmed = ps.filter((p) => p.email_confirmed_at).length;
  const shipped = ps.filter((p) => p.shipment).length;
  return html`<section class="card">
    <h2>${r.name} <small class="meta">(${r.countryName}, <a href="${service.base}">${service.base}</a>)</small></h2>
    <dl class="stats">
      <div><dt>Status</dt><dd>${STATUS_LABEL[d.status]}</dd></div>
      <div><dt>Angemeldet</dt><dd>${ps.length}</dd></div>
      <div><dt>Bestätigt</dt><dd>${confirmed}</dd></div>
      <div><dt>Versand gemeldet</dt><dd>${d.status === 'DRAWN' ? `${shipped} / ${ps.length}` : '–'}</dd></div>
    </dl>
    <p class="meta">Anmeldeschluss: ${formatDateTime(r.registrationDeadline)} · Versandfrist: ${formatDateTime(r.shippingDeadline)} · Aufdeckung: ${formatDateTime(r.revealAt)} · Datenlöschung: ${formatDateTime(r.purgeAt)}</p>
    ${ps.length
      ? html`<div class="scroll"><table>
      <thead><tr><th>Name</th><th>E-Mail</th><th>Bestätigt</th><th>Versand</th><th>Erinnerungen</th></tr></thead>
      <tbody>${ps.map(
        (p) => html`<tr>
        <td>${p.first_name} ${p.last_name}</td><td>${p.email}</td>
        <td>${p.email_confirmed_at ? '✓' : '–'}</td>
        <td>${p.shipment ? html`✓ ${carrierLabel(r.country, p.shipment.carrier)}` : '–'}</td>
        <td>${p.mails_sent.filter((m) => m.startsWith('REMINDER_')).length || '–'}</td></tr>`,
      )}</tbody>
    </table></div>`
      : ''}
  </section>`;
}

export function adminPage(ctx, services) {
  return layout(
    ctx,
    'Übersicht',
    html`<h1>Übersicht</h1>
    ${services.map(adminRound)}
    <p><small>Die Zuordnung der Wichtelpartner wird hier bewusst nicht angezeigt.</small></p>`,
  );
}

export function indexPage(ctx, services) {
  const open = services.filter((s) => s.data.status !== 'CLOSED');
  return layout(
    ctx,
    'Wein-Wichteln',
    html`<section class="hero"><h1>Wein-Wichteln</h1>
    <p>Melde Dich an, bekomme einen Wichtelpartner zugelost, verschicke eine Flasche Wein – und lass Dich selbst überraschen.</p></section>
    <h2>Wähle Deine Runde</h2>
    ${open.length
      ? html`<ul class="rounds">${open.map(
          (s) => html`<li><a class="card round" href="${s.base}"><strong>${s.round.name}</strong><span class="meta">Lieferadresse in ${s.round.countryName} · ${s.isRegistrationOpen() ? `Anmeldung bis ${formatDateTime(s.round.registrationDeadline)}` : STATUS_LABEL[s.data.status]}</span></a></li>`,
        )}</ul>`
      : html`<p class="notice">Aktuell gibt es keine Wichtelrunde.</p>`}
    <p><small>Organisator? <a href="/admin/login">Hier anmelden</a>.</small></p>`,
  );
}

export function adminLoginPage(ctx, { sent = false } = {}) {
  return layout(
    ctx,
    'Organisator',
    html`<h1>Organisator-Anmeldung</h1>
    ${sent ? html`<p class="notice">Falls die Adresse als Organisator hinterlegt ist, haben wir einen Anmeldelink geschickt.</p>` : ''}
    <form method="post" action="/admin/login" class="card">
      <label>E-Mail-Adresse<input type="email" name="email" required autocomplete="email" maxlength="254"></label>
      <button>Link anfordern</button>
    </form>`,
  );
}

export function privacyPage(ctx, services) {
  const { config } = ctx;
  return layout(
    ctx,
    'Datenschutz',
    html`<h1>Datenschutzerklärung</h1>
    <h2>Verantwortlich</h2><p>${config.legal.operator ? nl2br(config.legal.operator) : 'Siehe Impressum.'}</p>
    <h2>Welche Daten wir verarbeiten</h2>
    <p>Für die Teilnahme speichern wir Deine E-Mail-Adresse, Deinen Namen, Deine Lieferadresse und Deinen optionalen Hinweis für den Wichtel. Nach der Auslosung speichern wir außerdem, wen Du beschenkst, sowie Versanddienstleister und Sendungsnummer Deines Pakets.</p>
    <h2>Zweck und Rechtsgrundlage</h2>
    <p>Die Daten dienen ausschließlich der Durchführung des Wichtelns. Rechtsgrundlage ist Deine Einwilligung (Art. 6 Abs. 1 lit. a DSGVO). Name, Adresse und Hinweis werden nach der Auslosung an genau einen anderen Teilnehmer – Deinen Wichtelpartner – weitergegeben. Deine Sendungsnummer erhält Dein Wichtelpartner, Deinen Namen nur, wenn Du der Aufdeckung ausdrücklich zustimmst.</p>
    <h2>Speicherdauer</h2>
    <p>Alle personenbezogenen Daten einer Runde werden automatisch gelöscht, einschließlich Backups:</p>
    <ul>${services.map((s) => html`<li>${s.round.name}: am ${formatDateTime(s.round.purgeAt)}</li>`)}</ul>
    <p> Nicht bestätigte Anmeldungen werden zum Anmeldeschluss gelöscht. Bis zum Anmeldeschluss kannst Du Deine Teilnahme jederzeit selbst zurückziehen; Deine Daten werden dann sofort gelöscht.</p>
    <h2>Cookies</h2>
    <p>Wir verwenden ausschließlich ein technisch notwendiges Cookie, das Deine Anmeldung speichert. Es gibt kein Tracking und keine Analyse-Werkzeuge.</p>
    <h2>Deine Rechte</h2>
    <p>Du hast das Recht auf Auskunft, Berichtigung, Löschung, Einschränkung der Verarbeitung, Datenübertragbarkeit sowie auf Widerruf Deiner Einwilligung mit Wirkung für die Zukunft. Wende Dich dazu an den Verantwortlichen. Außerdem kannst Du Dich bei einer Datenschutz-Aufsichtsbehörde beschweren.</p>`,
  );
}

export function imprintPage(ctx) {
  const { config } = ctx;
  return layout(ctx, 'Impressum', html`<h1>Impressum</h1><p>${config.legal.imprint ? nl2br(config.legal.imprint) : 'Bitte in config.yaml unter legal.imprint hinterlegen.'}</p>`);
}

export const STYLE = `
:root{--bg:#faf7f5;--fg:#2b2024;--muted:#6f6266;--card:#fff;--line:#e6dcdc;--accent:#7b1e3a;--accent-fg:#fff;--ok:#1f6b45;--err:#b3261e;--notice:#f4ebe4}
@media (prefers-color-scheme:dark){:root{--bg:#1c1618;--fg:#f1e9ea;--muted:#b3a5a8;--card:#271f22;--line:#3e3236;--accent:#d36b8a;--accent-fg:#1c1618;--ok:#6fcf97;--err:#ff8a80;--notice:#30262a}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--fg);font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
.wrap{max-width:46rem;margin:0 auto;padding:0 16px}
.top{border-bottom:1px solid var(--line);background:var(--card)}
.top .wrap{display:flex;justify-content:space-between;align-items:center;gap:1rem;min-height:3.5rem;flex-wrap:wrap}
.brand{font-weight:700;color:var(--fg);text-decoration:none}
nav{display:flex;gap:1rem;align-items:center}
a{color:var(--accent)}
h1{font-size:1.75rem;line-height:1.2;margin:1.5rem 0 .75rem}
h2{font-size:1.2rem;margin:1.5rem 0 .5rem}
.card h2{margin-top:0}
.hero{padding:.5rem 0}
.steps{padding-left:1.25rem}
.meta{color:var(--muted);font-size:.9rem}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:1.25rem;margin:1rem 0}
label{display:block;margin:.75rem 0;font-weight:600}
label small{font-weight:400;color:var(--muted)}
input,select,textarea{display:block;width:100%;margin-top:.25rem;padding:.6rem .7rem;font:inherit;color:var(--fg);background:var(--bg);border:1px solid var(--line);border-radius:8px}
input:disabled{opacity:.7}
input:focus,select:focus,textarea:focus{outline:2px solid var(--accent);outline-offset:1px}
.check{display:flex;gap:.6rem;align-items:flex-start;font-weight:400}
.check input{width:auto;margin-top:.3rem;flex:none}
.check .err{display:block}
.row{display:flex;gap:.75rem}
.row>*{flex:1}.row .grow{flex:3}.row .small{flex:1;min-width:6rem}
@media (max-width:30rem){.row{flex-direction:column;gap:0}}
.invalid input,.invalid select,.invalid textarea{border-color:var(--err)}
.err{display:block;color:var(--err);font-weight:400;font-size:.9rem;margin-top:.2rem}
button{font:inherit;font-weight:600;padding:.65rem 1.2rem;border:0;border-radius:8px;background:var(--accent);color:var(--accent-fg);cursor:pointer;margin-top:.5rem}
button.danger{background:var(--err)}
button.link{background:none;color:var(--accent);padding:0;margin:0;font-weight:400;text-decoration:underline}
.inline{display:inline}
.notice{background:var(--notice);border-radius:8px;padding:.75rem 1rem}
.notice.ok{border-left:4px solid var(--ok)}.notice.error{border-left:4px solid var(--err)}
address{font-style:normal;font-size:1.1rem;line-height:1.6}
.quote{font-style:italic;color:var(--muted)}
.hp{position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(9rem,1fr));gap:.75rem;margin:1rem 0}
.stats div{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:.75rem 1rem}
.stats dt{color:var(--muted);font-size:.85rem}.stats dd{margin:0;font-size:1.3rem;font-weight:700}
.scroll{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:.95rem}
th,td{text-align:left;padding:.5rem;border-bottom:1px solid var(--line);white-space:nowrap}
.rounds{list-style:none;padding:0}.round{display:flex;flex-direction:column;gap:.25rem;text-decoration:none;color:var(--fg)}.round:hover{border-color:var(--accent)}
.foot{color:var(--muted);font-size:.9rem;padding-top:2rem;padding-bottom:2rem}
`;
