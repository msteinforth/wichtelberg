import nodemailer from 'nodemailer';

/**
 * Einheitliche Schnittstelle: send({ to, subject, text }) -> Promise.
 * Wirft bei Fehlern; Aufrufer vermerken den Versand erst nach Erfolg.
 */
export function createMailer(config, logger = console) {
  if (config.mail.transport === 'log') {
    return {
      async send(msg) {
        logger.log(`\n=== E-Mail an ${msg.to} ===\nBetreff: ${msg.subject}\n\n${msg.text}\n=== Ende ===\n`);
      },
    };
  }
  const transport = nodemailer.createTransport(config.mail.smtpUrl);
  return {
    async send(msg) {
      let lastErr;
      for (let attempt = 1; attempt <= 3; attempt++) {
        try {
          await transport.sendMail({ from: config.mail.from, to: msg.to, subject: msg.subject, text: msg.text });
          return;
        } catch (err) {
          lastErr = err;
          await new Promise((r) => setTimeout(r, 500 * attempt));
        }
      }
      throw lastErr;
    },
  };
}

/** Test-Mailer: sammelt Nachrichten im Speicher. */
export function createMemoryMailer() {
  const outbox = [];
  return {
    outbox,
    failFor: new Set(),
    async send(msg) {
      if (this.failFor.has(msg.to)) throw new Error(`Zustellung an ${msg.to} fehlgeschlagen`);
      outbox.push(msg);
    },
  };
}
