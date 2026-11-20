const dateTimeFmt = new Intl.DateTimeFormat('de-DE', {
  timeZone: 'Europe/Berlin',
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
});

const dateFmt = new Intl.DateTimeFormat('de-DE', { timeZone: 'Europe/Berlin', day: '2-digit', month: '2-digit', year: 'numeric' });

/** "01.12.2026, 23:59 Uhr" */
export function formatDateTime(d) {
  return `${dateTimeFmt.format(d)} Uhr`;
}

export function formatDate(d) {
  return dateFmt.format(d);
}

/** Kalendertag in Europe/Berlin als YYYY-MM-DD (für Backups). */
export function berlinDay(d) {
  const parts = Object.fromEntries(
    new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Berlin', year: 'numeric', month: '2-digit', day: '2-digit' })
      .formatToParts(d)
      .map((p) => [p.type, p.value]),
  );
  return `${parts.year}-${parts.month}-${parts.day}`;
}

/** "noch 3 Tage", "noch 5 Stunden", "noch 12 Minuten" */
export function timeLeft(until, now) {
  const ms = until - now;
  if (ms <= 0) return 'abgelaufen';
  const min = Math.floor(ms / 60000);
  if (min < 60) return `noch ${min} ${min === 1 ? 'Minute' : 'Minuten'}`;
  const h = Math.floor(min / 60);
  if (h < 48) return `noch ${h} ${h === 1 ? 'Stunde' : 'Stunden'}`;
  const d = Math.floor(h / 24);
  return `noch ${d} Tage`;
}

export function formatAddress(p, countryName) {
  const a = p.address;
  const cityLine = `${a.postal_code} ${a.city}${a.province ? ` (${a.province})` : ''}`;
  return [`${p.first_name} ${p.last_name}`, a.extra, `${a.street} ${a.house_number}`, cityLine, countryName].filter(Boolean).join('\n');
}
