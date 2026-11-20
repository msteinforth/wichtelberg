import http from 'node:http';
import crypto from 'node:crypto';
import { UserError } from './service.js';
import { signToken, verifyToken } from './crypto.js';
import * as views from './views.js';

const MAX_BODY = 16 * 1024;

/** Einfaches In-Memory-Rate-Limit (feste Fenster). */
export class RateLimiter {
  constructor({ limit, windowMs, now = () => Date.now() }) {
    Object.assign(this, { limit, windowMs, now, hits: new Map() });
  }
  hit(key) {
    const t = this.now();
    let e = this.hits.get(key);
    if (!e || t - e.start >= this.windowMs) {
      e = { start: t, count: 0 };
      this.hits.set(key, e);
    }
    e.count++;
    if (this.hits.size > 50000) this.hits.clear();
    return e.count <= this.limit;
  }
}

function parseCookies(header = '') {
  const out = {};
  for (const part of header.split(';')) {
    const i = part.indexOf('=');
    if (i > 0) out[part.slice(0, i).trim()] = decodeURIComponent(part.slice(i + 1).trim());
  }
  return out;
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    let size = 0;
    const chunks = [];
    req.on('data', (c) => {
      size += c.length;
      if (size > MAX_BODY) {
        reject(new UserError('too_large'));
        req.destroy();
      } else chunks.push(c);
    });
    req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
    req.on('error', reject);
  });
}

async function parseForm(req) {
  const type = req.headers['content-type'] || '';
  if (!type.startsWith('application/x-www-form-urlencoded')) return {};
  return Object.fromEntries(new URLSearchParams(await readBody(req)));
}

const ADMIN_PURPOSE = 'admin';
const DAY = 24 * 3600 * 1000;

/**
 * HTTP-Server für alle Runden. Routen:
 *   /                       Übersicht der Runden
 *   /<slug>/…               eine Länder-Runde (Anmeldung, Login, Dashboard)
 *   /admin/…                Organisator (alle Runden)
 *   /datenschutz, /impressum, /style.css, /healthz, POST /tasks/run
 */
export function createServer({ config, services, logger = console, now = () => new Date() }) {
  const bySlug = new Map(services.map((s) => [s.round.slug, s]));
  const limiter = new RateLimiter({ limit: 20, windowMs: 15 * 60 * 1000 });
  const baseOrigin = new URL(config.baseUrl).origin;
  const adminThrottle = new RateLimiter({ limit: 3, windowMs: 3600 * 1000 });

  function securityHeaders(res) {
    res.setHeader('Content-Security-Policy', "default-src 'none'; style-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    res.setHeader('X-Content-Type-Options', 'nosniff');
    res.setHeader('Referrer-Policy', 'no-referrer');
    res.setHeader('X-Frame-Options', 'DENY');
    if (config.secureCookies) res.setHeader('Strict-Transport-Security', 'max-age=31536000');
  }

  function send(res, status, body, type = 'text/html; charset=utf-8') {
    res.statusCode = status;
    res.setHeader('Content-Type', type);
    res.setHeader('Cache-Control', 'no-store');
    res.end(body);
  }

  function redirect(res, location) {
    res.statusCode = 303;
    res.setHeader('Location', location);
    res.end();
  }

  function cookieAttrs(path, maxAge) {
    const attrs = [`Path=${path}`, 'HttpOnly', 'SameSite=Lax', `Max-Age=${maxAge}`];
    if (config.secureCookies) attrs.push('Secure');
    return attrs.join('; ');
  }
  const setCookie = (res, name, value, path, maxAge = 30 * 24 * 3600) => res.setHeader('Set-Cookie', `${name}=${encodeURIComponent(value)}; ${cookieAttrs(path, maxAge)}`);
  const clearCookie = (res, name, path) => res.setHeader('Set-Cookie', `${name}=; ${cookieAttrs(path, 0)}`);

  function clientIp(req) {
    if (config.trustProxy && req.headers['x-forwarded-for']) return String(req.headers['x-forwarded-for']).split(',')[0].trim();
    return req.socket.remoteAddress || 'unknown';
  }

  /** CSRF-Schutz zusätzlich zu SameSite=Lax: Origin/Referer muss zur eigenen Domain passen. */
  function sameOrigin(req) {
    const origin = req.headers.origin;
    if (origin) return origin === baseOrigin;
    const referer = req.headers.referer;
    if (referer) {
      try {
        return new URL(referer).origin === baseOrigin;
      } catch {
        return false;
      }
    }
    return true; // Manche Clients senden keines von beiden; Cookie ist SameSite=Lax.
  }

  function isAdmin(cookies) {
    return verifyToken(config.secretKey, ADMIN_PURPOSE + ':session', cookies.wb_admin, now()) === 'organizer';
  }

  async function handle(req, res) {
    securityHeaders(res);
    const url = new URL(req.url, 'http://localhost');
    const path = url.pathname.replace(/\/+$/, '') || '/';
    const method = req.method;
    const cookies = parseCookies(req.headers.cookie);
    const segments = path.split('/').filter(Boolean);
    const service = bySlug.get(segments[0]) || null;
    const sub = service ? '/' + segments.slice(1).join('/') : path;
    const session = service
      ? service.verifySession(cookies[`wb_${service.round.slug}`])
      : segments[0] === 'admin' && isAdmin(cookies)
        ? { admin: true }
        : null;
    const ctx = { config, service, session };
    const page = (status, htmlStr) => send(res, status, htmlStr);
    const msg = (status, title, text, opts) => page(status, views.messagePage(ctx, title, text, opts));

    if (method === 'POST' && path !== '/tasks/run') {
      if (!sameOrigin(req)) return msg(403, 'Nicht erlaubt', 'Die Anfrage kam nicht von dieser Seite.');
      if (!limiter.hit(`${clientIp(req)}:${path}`)) return msg(429, 'Zu viele Anfragen', 'Bitte warte einige Minuten und versuche es dann erneut.');
    }

    // ---- global ----
    if (!service) {
      if (method === 'GET' && path === '/') return page(200, views.indexPage(ctx, services));
      if (method === 'GET' && path === '/style.css') {
        res.statusCode = 200;
        res.setHeader('Cache-Control', 'public, max-age=3600');
        res.setHeader('Content-Type', 'text/css; charset=utf-8');
        return res.end(views.STYLE);
      }
      if (method === 'GET' && path === '/healthz') return send(res, 200, 'ok', 'text/plain');
      if (method === 'GET' && path === '/datenschutz') return page(200, views.privacyPage(ctx, services));
      if (method === 'GET' && path === '/impressum') return page(200, views.imprintPage(ctx));

      if (method === 'POST' && path === '/tasks/run') {
        const auth = String(req.headers.authorization || '');
        const expected = config.tasksToken ? `Bearer ${config.tasksToken}` : null;
        const ok = expected && auth.length === expected.length && crypto.timingSafeEqual(Buffer.from(auth), Buffer.from(expected));
        if (!ok) return send(res, 401, 'unauthorized', 'text/plain');
        for (const s of services) await s.runTasks();
        return send(res, 200, JSON.stringify({ ok: true, rounds: Object.fromEntries(services.map((s) => [s.round.slug, s.data.status])) }), 'application/json');
      }

      // ---- Organisator ----
      if (method === 'GET' && path === '/admin') {
        if (!session?.admin) return redirect(res, '/admin/login');
        return page(200, views.adminPage(ctx, services));
      }
      if (method === 'GET' && path === '/admin/login') return page(200, views.adminLoginPage(ctx));
      if (method === 'POST' && path === '/admin/login') {
        const form = await parseForm(req);
        const email = String(form.email || '').trim().toLowerCase();
        if (email === config.organizerEmail && adminThrottle.hit(email)) {
          const t = signToken(config.secretKey, ADMIN_PURPOSE + ':login', 'organizer', new Date(now().getTime() + 30 * 60 * 1000));
          try {
            await services[0].mailer.send({
              to: email,
              subject: '[Organisator] Dein Anmeldelink für Wichtelberg',
              text: `Hier ist Dein Anmeldelink für die Organisator-Übersicht (30 Minuten gültig):\n${config.baseUrl}/admin/link?t=${encodeURIComponent(t)}\n\nFalls Du keinen Link angefordert hast, ignoriere diese E-Mail.`,
            });
          } catch (err) {
            logger.error(`Admin-Login-Mail fehlgeschlagen: ${err.message}`);
          }
        }
        return page(200, views.adminLoginPage(ctx, { sent: true }));
      }
      if (method === 'GET' && path === '/admin/link') {
        return page(200, views.confirmPage(ctx, url.searchParams.get('t') || '', '/admin/link', 'Organisator-Anmeldung', 'Klicke auf den Button, um Dich anzumelden.', 'Jetzt anmelden'));
      }
      if (method === 'POST' && path === '/admin/link') {
        const form = await parseForm(req);
        if (verifyToken(config.secretKey, ADMIN_PURPOSE + ':login', form.t, now()) !== 'organizer') {
          return msg(400, 'Link ungültig', 'Der Anmeldelink ist ungültig oder abgelaufen.', { link: { href: '/admin/login', label: 'Neuen Link anfordern' } });
        }
        setCookie(res, 'wb_admin', signToken(config.secretKey, ADMIN_PURPOSE + ':session', 'organizer', new Date(now().getTime() + 7 * DAY)), '/admin', 7 * 24 * 3600);
        return redirect(res, '/admin');
      }
      if (method === 'POST' && path === '/admin/logout') {
        clearCookie(res, 'wb_admin', '/admin');
        return redirect(res, '/');
      }

      return msg(404, 'Nicht gefunden', 'Diese Seite gibt es nicht.', { link: { href: '/', label: 'Zur Startseite' } });
    }

    // ---- Runde /<slug>/… ----
    const base = service.base;
    const cookieName = `wb_${service.round.slug}`;

    if (method === 'GET' && sub === '/') return page(200, views.roundPage(ctx, { message: url.searchParams.get('m') === 'bye' ? 'Du wurdest abgemeldet und Deine Daten wurden gelöscht.' : null }));

    if (method === 'POST' && sub === '/anmelden') {
      const form = await parseForm(req);
      if (form.website) return msg(200, 'Fast geschafft!', 'Bitte prüfe Dein Postfach und bestätige Deine E-Mail-Adresse.'); // Honeypot
      try {
        const result = await service.register(form);
        if (result.errors) return page(422, views.roundPage(ctx, { values: { ...result.values, adult: form.adult, consent: form.consent }, errors: result.errors }));
        return msg(200, 'Fast geschafft!', 'Wir haben Dir eine E-Mail geschickt. Bitte bestätige darin Deine E-Mail-Adresse – erst dann nimmst Du an der Auslosung teil. Schau auch im Spam-Ordner nach.');
      } catch (err) {
        if (err instanceof UserError) return msg(err.code === 'mail' ? 503 : 409, 'Anmeldung nicht möglich', err.message, { link: { href: base, label: 'Zurück' } });
        throw err;
      }
    }

    if (method === 'GET' && sub === '/bestaetigen') {
      return page(200, views.confirmPage(ctx, url.searchParams.get('t') || '', `${base}/bestaetigen`, 'E-Mail-Adresse bestätigen', 'Klicke auf den Button, um Deine Anmeldung abzuschließen.', 'Anmeldung bestätigen'));
    }
    if (method === 'POST' && sub === '/bestaetigen') {
      const form = await parseForm(req);
      try {
        const id = await service.confirm(form.t);
        setCookie(res, cookieName, service.sessionToken(id), base);
        return redirect(res, `${base}/dashboard?m=confirmed`);
      } catch (err) {
        if (err instanceof UserError) return msg(400, 'Bestätigung fehlgeschlagen', err.message, { link: { href: `${base}/login`, label: 'Neuen Link anfordern' } });
        throw err;
      }
    }

    if (method === 'GET' && sub === '/login') return page(200, views.loginPage(ctx, { action: `${base}/login` }));
    if (method === 'POST' && sub === '/login') {
      const form = await parseForm(req);
      await service.requestLogin(form.email);
      return page(200, views.loginPage(ctx, { sent: true, action: `${base}/login` }));
    }
    if (method === 'GET' && sub === '/login/link') {
      return page(200, views.confirmPage(ctx, url.searchParams.get('t') || '', `${base}/login/link`, 'Anmelden', 'Klicke auf den Button, um Dich anzumelden.', 'Jetzt anmelden'));
    }
    if (method === 'POST' && sub === '/login/link') {
      const form = await parseForm(req);
      const id = service.verifyLoginToken(form.t);
      if (!id) return msg(400, 'Link ungültig', 'Der Anmeldelink ist ungültig oder abgelaufen.', { link: { href: `${base}/login`, label: 'Neuen Link anfordern' } });
      setCookie(res, cookieName, service.sessionToken(id), base);
      return redirect(res, `${base}/dashboard`);
    }
    if (method === 'POST' && sub === '/logout') {
      clearCookie(res, cookieName, base);
      return redirect(res, base);
    }

    if (sub.startsWith('/dashboard')) {
      if (!session?.participant) return redirect(res, `${base}/login`);
      const p = session.participant;
      const messages = {
        confirmed: 'Danke! Deine E-Mail-Adresse ist bestätigt.',
        saved: 'Deine Daten wurden gespeichert.',
        shipped: 'Danke! Dein Wichtelpartner wurde benachrichtigt.',
        reveal: 'Deine Entscheidung zur Aufdeckung wurde gespeichert.',
      };
      if (method === 'GET' && sub === '/dashboard') return page(200, views.dashboardPage(ctx, p, { message: messages[url.searchParams.get('m')] || null }));
      const form = method === 'POST' ? await parseForm(req) : null;
      try {
        if (method === 'POST' && sub === '/dashboard/daten') {
          const r = await service.updateParticipant(p.id, form);
          if (r.errors) return page(422, views.dashboardPage(ctx, p, { values: r.values, errors: r.errors }));
          return redirect(res, `${base}/dashboard?m=saved`);
        }
        if (method === 'POST' && sub === '/dashboard/abmelden') {
          if (form.sure !== 'on') return redirect(res, `${base}/dashboard`);
          await service.unregister(p.id);
          clearCookie(res, cookieName, base);
          return redirect(res, `${base}?m=bye`);
        }
        if (method === 'POST' && sub === '/dashboard/versand') {
          const r = await service.submitShipment(p.id, form);
          if (r.errors) return page(422, views.dashboardPage(ctx, p, { values: r.values, errors: r.errors }));
          return redirect(res, `${base}/dashboard?m=shipped`);
        }
        if (method === 'POST' && sub === '/dashboard/aufdecken') {
          await service.setReveal(p.id, form.reveal === 'yes');
          return redirect(res, `${base}/dashboard?m=reveal`);
        }
      } catch (err) {
        if (err instanceof UserError) return msg(409, 'Nicht möglich', err.message, { link: { href: `${base}/dashboard`, label: 'Zurück' } });
        throw err;
      }
    }

    return msg(404, 'Nicht gefunden', 'Diese Seite gibt es nicht.', { link: { href: base, label: 'Zur Startseite der Runde' } });
  }

  return http.createServer((req, res) => {
    handle(req, res).catch((err) => {
      if (err instanceof UserError && err.code === 'too_large') {
        if (!res.headersSent) send(res, 413, 'Payload too large', 'text/plain');
        return;
      }
      logger.error(err);
      if (!res.headersSent) send(res, 500, views.messagePage({ config, service: null, session: null }, 'Fehler', 'Es ist ein Fehler aufgetreten. Bitte versuche es später erneut.'));
      else res.end();
    });
  });
}
