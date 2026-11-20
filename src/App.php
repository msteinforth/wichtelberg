<?php
declare(strict_types=1);

namespace Wichtelberg;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $form = [],
        public readonly array $cookies = [],
        /** Header-Namen in Kleinbuchstaben */
        public readonly array $headers = [],
        public readonly string $ip = 'unknown',
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (!isset($headers['authorization']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $form = array_filter($_POST, 'is_string'); // keine Arrays (name[]) zulassen
        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path === '' ? '/' : $path,
            array_filter($_GET, 'is_string'),
            $form,
            array_filter($_COOKIE, 'is_string'),
            $headers,
            (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
        );
    }
}

final class Response
{
    /** @param string[] $cookies Set-Cookie-Werte */
    public function __construct(
        public int $status = 200,
        public string $body = '',
        public array $headers = ['Content-Type' => 'text/html; charset=utf-8'],
        public array $cookies = [],
    ) {
    }

    public static function redirect(string $location): self
    {
        return new self(303, '', ['Location' => $location]);
    }

    public function emit(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header("$k: $v");
        }
        foreach ($this->cookies as $c) {
            header("Set-Cookie: $c", false);
        }
        echo $this->body;
    }
}

/**
 * Routing für alle Runden:
 *   /                       Übersicht der Runden
 *   /<slug>/…               eine Länder-Runde (Anmeldung, Login, Dashboard)
 *   /admin/…                Organisator (alle Runden)
 *   /datenschutz, /impressum, /healthz, POST /tasks/run
 */
final class App
{
    private const ADMIN = 'admin';
    private const DAY = 86400;

    /** @var array<string, Service> */
    private array $bySlug = [];

    /**
     * @param Service[] $services
     */
    public function __construct(
        private Config $config,
        private array $services,
        private Mailer $mailer,
        private RateLimiter $limiter,
        private \Closure $clock,
        private ?\Closure $logger = null,
    ) {
        foreach ($services as $s) {
            $this->bySlug[$s->round->slug] = $s;
        }
    }

    /** @return Service[] */
    public function services(): array
    {
        return $this->services;
    }

    private function ts(): int
    {
        return ($this->clock)()->getTimestamp();
    }

    private function log(string $m): void
    {
        $this->logger ? ($this->logger)($m) : error_log("[wichtelberg] $m");
    }

    private function cookie(string $name, string $value, string $path, int $maxAge): string
    {
        return "$name=" . rawurlencode($value) . "; Path=$path; HttpOnly; SameSite=Lax; Max-Age=$maxAge" . ($this->config->secureCookies ? '; Secure' : '');
    }

    private function clientIp(Request $req): string
    {
        if ($this->config->trustProxy && isset($req->headers['x-forwarded-for'])) {
            return trim(explode(',', $req->headers['x-forwarded-for'])[0]);
        }
        return $req->ip;
    }

    /**
     * CSRF-Schutz zusätzlich zu SameSite=Lax: Origin (bzw. Referer) muss zur eigenen Domain passen.
     * Verglichen wird der Host – mit base_url und mit dem Host der Anfrage selbst –, damit z. B.
     * http/https oder ein Proxy davor nicht stören. "Origin: null" (Sandbox-iframes) wird abgelehnt.
     */
    private function sameOrigin(Request $req): bool
    {
        $hostOf = function (string $url): ?string {
            $u = parse_url($url);
            if (!$u || empty($u['host'])) {
                return null;
            }
            return strtolower($u['host']) . (isset($u['port']) ? ':' . $u['port'] : '');
        };
        $allowed = array_filter([$hostOf($this->config->baseUrl), isset($req->headers['host']) ? strtolower($req->headers['host']) : null]);
        $source = $req->headers['origin'] ?? $req->headers['referer'] ?? null;
        if ($source === null) {
            return true; // manche Clients senden keines von beiden; Cookie ist SameSite=Lax
        }
        $h = $hostOf($source);
        return $h !== null && in_array($h, $allowed, true);
    }

    private function securityHeaders(Response $res): Response
    {
        $res->headers += [
            'Content-Security-Policy' => "default-src 'none'; style-src 'self'; font-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin', // "no-referrer" würde Browser zu "Origin: null" bei Formularen zwingen
            'X-Frame-Options' => 'DENY',
            'Cache-Control' => 'no-store',
        ];
        if ($this->config->secureCookies) {
            $res->headers['Strict-Transport-Security'] = 'max-age=31536000';
        }
        return $res;
    }

    public function handle(Request $req): Response
    {
        try {
            return $this->securityHeaders($this->route($req));
        } catch (\Throwable $e) {
            $this->log('Fehler: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $ctx = ['config' => $this->config, 'service' => null, 'session' => null];
            return $this->securityHeaders(new Response(500, Views::message($ctx, 'Fehler', 'Es ist ein Fehler aufgetreten. Bitte versuche es später erneut.')));
        }
    }

    private function route(Request $req): Response
    {
        $path = rtrim($req->path, '/') ?: '/';
        $segments = array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));
        $service = isset($segments[0]) ? ($this->bySlug[$segments[0]] ?? null) : null;
        $sub = $service ? '/' . implode('/', array_slice($segments, 1)) : $path;
        $method = $req->method;

        $session = null;
        if ($service) {
            $p = $service->verifySession($req->cookies['wb_' . $service->round->slug] ?? null);
            $session = $p ? ['participant' => $p] : null;
        } elseif (($segments[0] ?? '') === 'admin' && $this->isAdmin($req)) {
            $session = ['admin' => true];
        }
        $ctx = ['config' => $this->config, 'service' => $service, 'session' => $session];
        $page = fn (int $status, string $html) => new Response($status, $html);
        $msg = fn (int $status, string $title, string $text, ?array $link = null) => new Response($status, Views::message($ctx, $title, $text, $link));

        if ($method === 'POST' && $path !== '/tasks/run') {
            if (!$this->sameOrigin($req)) {
                return $msg(403, 'Nicht erlaubt', 'Die Anfrage kam nicht von dieser Seite.');
            }
            if (!$this->limiter->hit('ip:' . $this->clientIp($req) . ':' . $path, 20, 900)) {
                return $msg(429, 'Zu viele Anfragen', 'Bitte warte einige Minuten und versuche es dann erneut.');
            }
        }

        if (!$service) {
            return $this->routeGlobal($req, $path, $ctx, $page, $msg);
        }

        // ---- Runde /<slug>/… ----
        $base = $service->base();
        $cookie = 'wb_' . $service->round->slug;
        $form = $req->form;

        if ($method === 'GET' && $sub === '/') {
            return $page(200, Views::round($ctx, [], null, ($req->query['m'] ?? '') === 'bye' ? 'Du wurdest abgemeldet und Deine Daten wurden gelöscht.' : null));
        }

        if ($method === 'POST' && $sub === '/anmelden') {
            if (($form['website'] ?? '') !== '') { // Honeypot
                return $msg(200, 'Fast geschafft!', 'Bitte prüfe Dein Postfach und bestätige Deine E-Mail-Adresse.');
            }
            try {
                $r = $service->register($form);
                if (isset($r['errors'])) {
                    return $page(422, Views::round($ctx, $r['values'] + ['adult' => $form['adult'] ?? '', 'consent' => $form['consent'] ?? ''], $r['errors']));
                }
                return $msg(200, 'Fast geschafft!', 'Wir haben Dir eine E-Mail geschickt. Bitte bestätige darin Deine E-Mail-Adresse – erst dann nimmst Du an der Auslosung teil. Schau auch im Spam-Ordner nach.');
            } catch (UserError $e) {
                return $msg($e->codeName === 'mail' ? 503 : 409, 'Anmeldung nicht möglich', $e->getMessage(), [$base, 'Zurück']);
            }
        }

        if ($method === 'GET' && $sub === '/bestaetigen') {
            return $page(200, Views::confirm($ctx, $req->query['t'] ?? '', "$base/bestaetigen", 'E-Mail-Adresse bestätigen', 'Klicke auf den Button, um Deine Anmeldung abzuschließen.', 'Anmeldung bestätigen'));
        }
        if ($method === 'POST' && $sub === '/bestaetigen') {
            try {
                $id = $service->confirm($form['t'] ?? null);
                $res = Response::redirect("$base/dashboard?m=confirmed");
                $res->cookies[] = $this->cookie($cookie, $service->sessionToken($id), $base, 30 * self::DAY);
                return $res;
            } catch (UserError $e) {
                return $msg(400, 'Bestätigung fehlgeschlagen', $e->getMessage(), ["$base/login", 'Neuen Link anfordern']);
            }
        }

        if ($method === 'GET' && $sub === '/login') {
            return $page(200, Views::login($ctx, "$base/login"));
        }
        if ($method === 'POST' && $sub === '/login') {
            $service->requestLogin($form['email'] ?? '');
            return $page(200, Views::login($ctx, "$base/login", true));
        }
        if ($method === 'GET' && $sub === '/login/link') {
            return $page(200, Views::confirm($ctx, $req->query['t'] ?? '', "$base/login/link", 'Anmelden', 'Klicke auf den Button, um Dich anzumelden.', 'Jetzt anmelden'));
        }
        if ($method === 'POST' && $sub === '/login/link') {
            $id = $service->verifyLoginToken($form['t'] ?? null);
            if (!$id) {
                return $msg(400, 'Link ungültig', 'Der Anmeldelink ist ungültig oder abgelaufen.', ["$base/login", 'Neuen Link anfordern']);
            }
            $res = Response::redirect("$base/dashboard");
            $res->cookies[] = $this->cookie($cookie, $service->sessionToken($id), $base, 30 * self::DAY);
            return $res;
        }
        if ($method === 'POST' && $sub === '/logout') {
            $res = Response::redirect($base);
            $res->cookies[] = $this->cookie($cookie, '', $base, 0);
            return $res;
        }

        if (str_starts_with($sub, '/dashboard')) {
            if (!$session) {
                return Response::redirect("$base/login");
            }
            $p = $session['participant'];
            $messages = [
                'confirmed' => 'Danke! Deine E-Mail-Adresse ist bestätigt.',
                'saved' => 'Deine Daten wurden gespeichert.',
                'shipped' => 'Danke! Dein Wichtelpartner wurde benachrichtigt.',
                'reveal' => 'Deine Entscheidung zur Aufdeckung wurde gespeichert.',
            ];
            if ($method === 'GET' && $sub === '/dashboard') {
                return $page(200, Views::dashboard($ctx, $p, null, null, $messages[$req->query['m'] ?? ''] ?? null));
            }
            try {
                if ($method === 'POST' && $sub === '/dashboard/daten') {
                    $r = $service->updateParticipant($p['id'], $form);
                    return isset($r['errors']) ? $page(422, Views::dashboard($ctx, $p, $r['values'], $r['errors'])) : Response::redirect("$base/dashboard?m=saved");
                }
                if ($method === 'POST' && $sub === '/dashboard/abmelden') {
                    if (($form['sure'] ?? '') !== 'on') {
                        return Response::redirect("$base/dashboard");
                    }
                    $service->unregister($p['id']);
                    $res = Response::redirect("$base?m=bye");
                    $res->cookies[] = $this->cookie($cookie, '', $base, 0);
                    return $res;
                }
                if ($method === 'POST' && $sub === '/dashboard/versand') {
                    $r = $service->submitShipment($p['id'], $form);
                    return isset($r['errors']) ? $page(422, Views::dashboard($ctx, $p, $r['values'], $r['errors'])) : Response::redirect("$base/dashboard?m=shipped");
                }
                if ($method === 'POST' && $sub === '/dashboard/aufdecken') {
                    $service->setReveal($p['id'], ($form['reveal'] ?? '') === 'yes');
                    return Response::redirect("$base/dashboard?m=reveal");
                }
            } catch (UserError $e) {
                return $msg(409, 'Nicht möglich', $e->getMessage(), ["$base/dashboard", 'Zurück']);
            }
        }

        return $msg(404, 'Nicht gefunden', 'Diese Seite gibt es nicht.', [$base, 'Zur Startseite der Runde']);
    }

    private function isAdmin(Request $req): bool
    {
        return Crypto::verifyToken($this->config->secretKey, self::ADMIN . ':session', $req->cookies['wb_admin'] ?? null, $this->ts()) === 'organizer';
    }

    /** Aufgaben aller Runden ausführen (Cronjob, Web-Cron, CLI). */
    public function runAllTasks(): array
    {
        $status = [];
        foreach ($this->services as $s) {
            try {
                $s->runTasks();
            } catch (\Throwable $e) {
                $this->log("Aufgaben ({$s->round->slug}) fehlgeschlagen: {$e->getMessage()}");
            }
            $status[$s->round->slug] = $s->status();
        }
        return $status;
    }

    private function routeGlobal(Request $req, string $path, array $ctx, \Closure $page, \Closure $msg): Response
    {
        $method = $req->method;
        $form = $req->form;

        if ($method === 'GET' && $path === '/') {
            return $page(200, Views::index($ctx, $this->services));
        }
        if ($method === 'GET' && $path === '/healthz') {
            return new Response(200, 'ok', ['Content-Type' => 'text/plain']);
        }
        if ($method === 'GET' && $path === '/datenschutz') {
            return $page(200, Views::privacy($ctx, $this->services));
        }
        if ($method === 'GET' && $path === '/impressum') {
            return $page(200, Views::imprint($ctx));
        }

        if ($method === 'POST' && $path === '/tasks/run') {
            $auth = $req->headers['authorization'] ?? '';
            $token = $this->config->tasksToken;
            if (!$token || !hash_equals("Bearer $token", $auth)) {
                return new Response(401, 'unauthorized', ['Content-Type' => 'text/plain']);
            }
            return new Response(200, json_encode(['ok' => true, 'rounds' => $this->runAllTasks()]), ['Content-Type' => 'application/json']);
        }

        // ---- Organisator ----
        if ($method === 'GET' && $path === '/admin') {
            return $ctx['session'] ? $page(200, Views::admin($ctx, $this->services)) : Response::redirect('/admin/login');
        }
        if ($method === 'GET' && $path === '/admin/login') {
            return $page(200, Views::adminLogin($ctx));
        }
        if ($method === 'POST' && $path === '/admin/login') {
            $email = Validation::normalizeEmail($form['email'] ?? '');
            if ($email === $this->config->organizerEmail && $this->limiter->hit('admin-mail', 3, 3600)) {
                $t = Crypto::signToken($this->config->secretKey, self::ADMIN . ':login', 'organizer', $this->ts() + 1800);
                try {
                    [$subject, $text] = Mails::adminLogin($this->config->baseUrl . '/admin/link?t=' . rawurlencode($t));
                    $this->mailer->send($email, $subject, $text);
                } catch (\Throwable $e) {
                    $this->log("Admin-Login-Mail fehlgeschlagen: {$e->getMessage()}");
                }
            }
            return $page(200, Views::adminLogin($ctx, true));
        }
        if ($method === 'GET' && $path === '/admin/link') {
            return $page(200, Views::confirm($ctx, $req->query['t'] ?? '', '/admin/link', 'Organisator-Anmeldung', 'Klicke auf den Button, um Dich anzumelden.', 'Jetzt anmelden'));
        }
        if ($method === 'POST' && $path === '/admin/link') {
            if (Crypto::verifyToken($this->config->secretKey, self::ADMIN . ':login', $form['t'] ?? null, $this->ts()) !== 'organizer') {
                return $msg(400, 'Link ungültig', 'Der Anmeldelink ist ungültig oder abgelaufen.', ['/admin/login', 'Neuen Link anfordern']);
            }
            $res = Response::redirect('/admin');
            $res->cookies[] = $this->cookie('wb_admin', Crypto::signToken($this->config->secretKey, self::ADMIN . ':session', 'organizer', $this->ts() + 7 * self::DAY), '/admin', 7 * self::DAY);
            return $res;
        }
        if ($method === 'POST' && $path === '/admin/logout') {
            $res = Response::redirect('/');
            $res->cookies[] = $this->cookie('wb_admin', '', '/admin', 0);
            return $res;
        }

        return $msg(404, 'Nicht gefunden', 'Diese Seite gibt es nicht.', ['/', 'Zur Startseite']);
    }
}
