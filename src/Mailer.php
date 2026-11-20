<?php
declare(strict_types=1);

namespace Wichtelberg;

interface Mailer
{
    /** Wirft bei Fehlern; Aufrufer vermerken den Versand erst nach Erfolg. */
    public function send(string $to, string $subject, string $text): void;
}

final class MailMessage
{
    public static function encodeHeader(string $s): string
    {
        return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    }

    /** "Name <adresse>" mit kodiertem Namen. */
    public static function encodeAddress(string $from): string
    {
        if (preg_match('/^\s*(.*?)\s*<([^>]+)>\s*$/', $from, $m) && $m[1] !== '') {
            return self::encodeHeader(trim($m[1], '"')) . " <{$m[2]}>";
        }
        return trim($from);
    }

    public static function body(string $text): string
    {
        return quoted_printable_encode(str_replace(["\r\n", "\r"], "\n", $text));
    }

    /** Header ohne To/Subject (die setzt mail() selbst bzw. der SMTP-Mailer). */
    public static function headers(string $from, string $fromAddress): array
    {
        $domain = substr(strrchr($fromAddress, '@') ?: '@localhost', 1);
        return [
            'From' => self::encodeAddress($from),
            'Date' => date(DATE_RFC2822),
            'Message-ID' => '<' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => 'quoted-printable',
            'Auto-Submitted' => 'auto-generated',
        ];
    }
}

/** Versand über PHPs mail() – auf Strato-Webhosting ohne weitere Einrichtung nutzbar. */
final class PhpMailMailer implements Mailer
{
    public function __construct(private string $from, private string $fromAddress)
    {
    }

    public function send(string $to, string $subject, string $text): void
    {
        $headers = MailMessage::headers($this->from, $this->fromAddress);
        $ok = mail($to, MailMessage::encodeHeader($subject), MailMessage::body($text), $headers, '-f' . $this->fromAddress);
        if (!$ok) {
            throw new \RuntimeException("mail() an $to fehlgeschlagen");
        }
    }
}

/** Minimaler SMTP-Client (AUTH LOGIN, SSL auf Port 465 oder STARTTLS auf Port 587). */
final class SmtpMailer implements Mailer
{
    public function __construct(private array $smtp, private string $from, private string $fromAddress)
    {
    }

    public function send(string $to, string $subject, string $text): void
    {
        $host = $this->smtp['host'];
        $port = (int) $this->smtp['port'];
        $remote = ($this->smtp['encryption'] === 'ssl' ? 'ssl://' : 'tcp://') . "$host:$port";
        $ctx = stream_context_create(['ssl' => ['peer_name' => $host, 'verify_peer' => true, 'verify_peer_name' => true]]);
        $s = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
        if (!$s) {
            throw new \RuntimeException("SMTP-Verbindung zu $host:$port fehlgeschlagen: $errstr");
        }
        stream_set_timeout($s, 20);
        try {
            $this->expect($s, 220);
            $ehlo = 'EHLO ' . (gethostname() ?: 'localhost');
            $this->cmd($s, $ehlo, 250);
            if ($this->smtp['encryption'] === 'tls') {
                $this->cmd($s, 'STARTTLS', 220);
                if (!stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('STARTTLS fehlgeschlagen');
                }
                $this->cmd($s, $ehlo, 250);
            }
            $this->cmd($s, 'AUTH LOGIN', 334);
            $this->cmd($s, base64_encode($this->smtp['username']), 334);
            $this->cmd($s, base64_encode($this->smtp['password']), 235);
            $this->cmd($s, "MAIL FROM:<{$this->fromAddress}>", 250);
            $this->cmd($s, "RCPT TO:<$to>", [250, 251]);
            $this->cmd($s, 'DATA', 354);
            $headers = ['To' => $to, 'Subject' => MailMessage::encodeHeader($subject)] + MailMessage::headers($this->from, $this->fromAddress);
            $msg = '';
            foreach ($headers as $k => $v) {
                $msg .= "$k: $v\r\n";
            }
            $body = str_replace("\n", "\r\n", MailMessage::body($text));
            $body = preg_replace('/^\./m', '..', $body);
            $this->cmd($s, $msg . "\r\n" . $body . "\r\n.", 250);
            $this->cmd($s, 'QUIT', 221);
        } finally {
            fclose($s);
        }
    }

    private function cmd($s, string $line, int|array $expect): void
    {
        fwrite($s, $line . "\r\n");
        $this->expect($s, $expect);
    }

    private function expect($s, int|array $codes): void
    {
        $codes = (array) $codes;
        $resp = '';
        while (($line = fgets($s, 1024)) !== false) {
            $resp .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        if (!in_array((int) substr($resp, 0, 3), $codes, true)) {
            throw new \RuntimeException('SMTP-Fehler: ' . trim($resp));
        }
    }
}

/** Schreibt Mails nur in eine Logdatei (zum Testen). */
final class LogMailer implements Mailer
{
    public function __construct(private string $file)
    {
    }

    public function send(string $to, string $subject, string $text): void
    {
        file_put_contents($this->file, "=== " . date('c') . " an $to ===\nBetreff: $subject\n\n$text\n=== Ende ===\n\n", FILE_APPEND | LOCK_EX);
    }
}

/** Test-Mailer: sammelt Nachrichten im Speicher. */
final class MemoryMailer implements Mailer
{
    /** @var array<int, array{to: string, subject: string, text: string}> */
    public array $outbox = [];
    /** @var array<string, bool> */
    public array $failFor = [];

    public function send(string $to, string $subject, string $text): void
    {
        if (isset($this->failFor[$to])) {
            throw new \RuntimeException("Zustellung an $to fehlgeschlagen");
        }
        $this->outbox[] = ['to' => $to, 'subject' => $subject, 'text' => $text];
    }
}

final class MailerFactory
{
    public static function create(Config $config): Mailer
    {
        return match ($config->mailTransport) {
            'smtp' => new SmtpMailer($config->smtp, $config->mailFrom, $config->mailFromAddress),
            'log' => new LogMailer($config->dataDir . '/mail.log'),
            default => new PhpMailMailer($config->mailFrom, $config->mailFromAddress),
        };
    }
}
