import { formatDateTime, formatAddress } from './format.js';
import { carrierLabel, trackingUrl } from './carriers.js';

const footer = (ctx) => `\n\nFrohes Wichteln!\nDein Wichtelberg-Team\n\n--\n${ctx.round.name}\n${ctx.roundUrl}`;

/**
 * Alle E-Mail-Vorlagen. ctx = { config, round, roundUrl }.
 * Jede Funktion liefert { subject, text }.
 */
export const mails = {
  // M-1
  confirm(ctx, p, confirmUrl, expiry) {
    return {
      subject: `Bitte bestätige Deine Anmeldung: ${ctx.round.name}`,
      text:
        `Hallo ${p.first_name},\n\n` +
        `schön, dass Du beim ${ctx.round.name} mitmachen möchtest!\n\n` +
        `Bitte bestätige Deine E-Mail-Adresse über diesen Link:\n${confirmUrl}\n\n` +
        `Erst nach der Bestätigung nimmst Du an der Auslosung teil. ` +
        `Der Link ist bis ${formatDateTime(expiry)} gültig.\n\n` +
        `Falls Du Dich nicht angemeldet hast, kannst Du diese E-Mail einfach ignorieren.` +
        footer(ctx),
    };
  },

  // M-2
  welcome(ctx, p, dashboardUrl) {
    const r = ctx.round;
    return {
      subject: `Du bist dabei: ${r.name}`,
      text:
        `Hallo ${p.first_name},\n\n` +
        `Deine Anmeldung ist bestätigt. Bitte prüfe noch einmal Deine Lieferadresse:\n\n` +
        `${formatAddress(p, r.countryName)}\n\n` +
        `Die wichtigsten Termine:\n` +
        `- Anmeldeschluss und Auslosung: ${formatDateTime(r.registrationDeadline)}\n` +
        `- Versandfrist: ${formatDateTime(r.shippingDeadline)}\n\n` +
        `Bis zum Anmeldeschluss kannst Du Deine Daten hier ändern oder Dich abmelden:\n${dashboardUrl}` +
        footer(ctx),
    };
  },

  // M-3
  login(ctx, p, loginUrl) {
    return {
      subject: `Dein Anmeldelink: ${ctx.round.name}`,
      text:
        `Hallo ${p.first_name},\n\n` +
        `hier ist Dein persönlicher Anmeldelink (30 Minuten gültig):\n${loginUrl}\n\n` +
        `Falls Du keinen Link angefordert hast, kannst Du diese E-Mail ignorieren.` +
        footer(ctx),
    };
  },

  // M-5
  draw(ctx, p, giftee, dashboardUrl) {
    const r = ctx.round;
    return {
      subject: `Die Lose sind gezogen – Dein Wichtelpartner steht fest 🍷`,
      text:
        `Hallo ${p.first_name},\n\n` +
        `die Auslosung ist erfolgt. Du beschenkst:\n\n` +
        `${formatAddress(giftee, r.countryName)}\n\n` +
        (giftee.note ? `Hinweis Deines Wichtelpartners: „${giftee.note}“\n\n` : '') +
        `Bitte verschicke Deinen Wein bis ${formatDateTime(r.shippingDeadline)} ` +
        `und trage anschließend Versanddienstleister und Sendungsnummer ein:\n${dashboardUrl}\n\n` +
        `Dort kannst Du auch festlegen, ob Dein Wichtelpartner nach Abschluss erfahren darf, dass der Wein von Dir kam.\n\n` +
        `Tipp: Verpacke die Flasche bruchsicher (z. B. im Flaschenkarton) und beachte die Versandbedingungen ` +
        `Deines Versanddienstleisters für Alkohol und Glas. Bis dahin gilt: Wer wen beschenkt, bleibt geheim!` +
        footer(ctx),
    };
  },

  // M-6
  cancelled(ctx, p, count) {
    return {
      subject: `Die Auslosung konnte nicht stattfinden: ${ctx.round.name}`,
      text:
        `Hallo ${p.first_name},\n\n` +
        `leider haben sich bis zum Anmeldeschluss nur ${count} Teilnehmer bestätigt angemeldet. ` +
        `Für eine Auslosung sind mindestens ${ctx.round.minParticipants} nötig.\n\n` +
        `Der Organisator kann die Anmeldefrist verlängern – Du wirst dann informiert.` +
        footer(ctx),
    };
  },

  // M-6 (an Organisator)
  cancelledOrganizer(ctx, count) {
    return {
      subject: `[Organisator] Auslosung nicht möglich: ${ctx.round.name}`,
      text:
        `Bis zum Anmeldeschluss haben sich nur ${count} Teilnehmer bestätigt angemeldet ` +
        `(Minimum: ${ctx.round.minParticipants}).\n\n` +
        `Um die Anmeldung wieder zu öffnen, setze registration_deadline (und ggf. die weiteren Fristen) ` +
        `der Runde "${ctx.round.slug}" in config.yaml auf einen späteren Zeitpunkt und starte die App neu.` +
        footer(ctx),
    };
  },

  // M-7
  shipped(ctx, receiver, shipment, dashboardUrl, isUpdate) {
    const country = ctx.round.country;
    return {
      subject: isUpdate ? `Aktualisierte Sendungsdaten für Deinen Wichtel-Wein 🍷` : `Dein Wichtel-Wein ist unterwegs 🍷`,
      text:
        `Hallo ${receiver.first_name},\n\n` +
        (isUpdate
          ? `Dein Wichtel hat die Sendungsdaten für Dein Paket korrigiert.\n\n`
          : `gute Nachrichten: Dein Wichtel hat Dir eine Flasche Wein geschickt!\n\n`) +
        `Versanddienstleister: ${carrierLabel(country, shipment.carrier)}\n` +
        `Sendungsnummer: ${shipment.tracking_number}\n` +
        `Sendung verfolgen: ${trackingUrl(country, shipment.carrier, shipment.tracking_number)}\n\n` +
        `Wer Dich beschenkt, bleibt vorerst geheim.\n\n` +
        `Zu Deiner Übersicht:\n${dashboardUrl}` +
        footer(ctx),
    };
  },

  // M-8
  reminder(ctx, p, giftee, dashboardUrl, isLate) {
    const r = ctx.round;
    return {
      subject: isLate ? `Dein Wichtelpartner wartet noch auf seinen Wein` : `Erinnerung: Bitte trage Deine Sendungsnummer ein`,
      text:
        `Hallo ${p.first_name},\n\n` +
        `für Dein Paket an ${giftee.first_name} ${giftee.last_name} ist noch keine Sendungsnummer hinterlegt. ` +
        (isLate
          ? `Die Versandfrist ist am ${formatDateTime(r.shippingDeadline)} abgelaufen.\n\n`
          : `Die Versandfrist endet am ${formatDateTime(r.shippingDeadline)}.\n\n`) +
        `Falls Du schon verschickt hast: Trage Versanddienstleister und Sendungsnummer bitte hier ein, ` +
        `damit Dein Wichtelpartner Bescheid bekommt:\n${dashboardUrl}\n\n` +
        `Die Adresse findest Du ebenfalls dort.` +
        footer(ctx),
    };
  },

  // M-11: Aufdeckung (nur wenn der Schenkende zugestimmt hat)
  reveal(ctx, receiver, santa, dashboardUrl) {
    return {
      subject: `Das Geheimnis ist gelüftet: Von wem kam Dein Wein? 🍷`,
      text:
        `Hallo ${receiver.first_name},\n\n` +
        `das Wichteln ist vorbei – und Dein Wichtel möchte sich zu erkennen geben:\n\n` +
        `Dein Wein kam von ${santa.first_name} ${santa.last_name}.\n\n` +
        `Vielleicht magst Du Dich ja bedanken? 😊\n\n` +
        `Zu Deiner Übersicht:\n${dashboardUrl}` +
        footer(ctx),
    };
  },
};
