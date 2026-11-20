import { COUNTRIES } from './carriers.js';

export const EMAIL_RE = /^[^\s@<>"]+@[^\s@<>"]+\.[^\s@<>"]+$/;

const FIELDS = {
  first_name: { label: 'Vorname', required: true, max: 80 },
  last_name: { label: 'Nachname', required: true, max: 80 },
  street: { label: 'Straße', required: true, max: 120 },
  house_number: { label: 'Hausnummer', required: true, max: 20 },
  extra: { label: 'Adresszusatz', required: false, max: 120 },
  postal_code: { label: 'PLZ', required: true, max: 5 },
  city: { label: 'Ort', required: true, max: 80 },
  note: { label: 'Hinweis für Deinen Wichtel', required: false, max: 300 },
};

const clean = (v) => String(v ?? '').replace(/[\u0000-\u001f\u007f]/g, ' ').replace(/\s+/g, ' ').trim();

export function normalizeEmail(v) {
  return clean(v).toLowerCase();
}

/**
 * Prüft Name/Adresse (und optional E-Mail + Einwilligungen bei Neuanmeldung)
 * nach den Regeln des Landes der Runde.
 * @returns {{ values: object, errors: object }}
 */
export function validateParticipantForm(form, { country = 'DE', requireEmail = true, requireConsents = true } = {}) {
  const c = COUNTRIES[country];
  const values = {};
  const errors = {};
  for (const [name, def] of Object.entries(FIELDS)) {
    const v = clean(form[name]);
    values[name] = v;
    if (def.required && !v) errors[name] = `${name === 'postal_code' ? c.postalCode.label : def.label} fehlt.`;
    else if (v.length > def.max) errors[name] = `${def.label} ist zu lang (max. ${def.max} Zeichen).`;
  }
  if (values.postal_code && !c.postalCode.re.test(values.postal_code)) {
    errors.postal_code = `Bitte gib eine gültige Postleitzahl für ${c.name} an (${c.postalCode.hint}).`;
  }
  if (c.province) {
    values.province = clean(form.province).toUpperCase();
    if (!/^[A-Z]{2}$/.test(values.province)) errors.province = 'Bitte gib das Provinzkürzel mit 2 Buchstaben an (z. B. MI, RM, BZ).';
  }
  if (requireEmail) {
    values.email = normalizeEmail(form.email);
    if (!values.email) errors.email = 'E-Mail-Adresse fehlt.';
    else if (values.email.length > 254 || !EMAIL_RE.test(values.email)) errors.email = 'Bitte gib eine gültige E-Mail-Adresse an.';
  }
  if (requireConsents) {
    if (form.adult !== 'on') errors.adult = 'Die Teilnahme ist erst ab 18 Jahren möglich.';
    if (form.consent !== 'on') errors.consent = 'Bitte stimme der Datenschutzerklärung und der Weitergabe Deiner Adresse zu.';
  }
  return { values, errors };
}

/** Adressobjekt aus validierten Formularwerten. */
export function addressFromValues(values) {
  return {
    street: values.street,
    house_number: values.house_number,
    extra: values.extra || null,
    postal_code: values.postal_code,
    city: values.city,
    province: values.province || null,
  };
}
