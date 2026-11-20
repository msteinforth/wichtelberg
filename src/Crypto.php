<?php
declare(strict_types=1);

namespace Wichtelberg;

final class Crypto
{
    public static function b64uEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $s): string|false
    {
        if (!preg_match('/^[A-Za-z0-9_-]*$/', $s)) {
            return false;
        }
        return base64_decode(strtr($s, '-_', '+/'), true);
    }

    /**
     * Zustandslos signiertes Token (HMAC-SHA256) für Login-/Bestätigungslinks und Session-Cookies.
     * Format: base64url(JSON) "." base64url(HMAC)
     */
    public static function signToken(string $key, string $purpose, string $subject, int $expiresAt): string
    {
        $payload = self::b64uEncode(json_encode(['p' => $purpose, 's' => $subject, 'e' => $expiresAt], JSON_UNESCAPED_SLASHES));
        return $payload . '.' . self::b64uEncode(hash_hmac('sha256', $payload, $key, true));
    }

    /** Liefert das Subject oder null (falsche Signatur, falscher Zweck, abgelaufen). */
    public static function verifyToken(string $key, string $purpose, mixed $token, int $now): ?string
    {
        if (!is_string($token) || strlen($token) > 1024) {
            return null;
        }
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$payload, $mac] = $parts;
        $given = self::b64uDecode($mac);
        if ($given === false || !hash_equals(hash_hmac('sha256', $payload, $key, true), $given)) {
            return null;
        }
        $json = self::b64uDecode($payload);
        $data = $json === false ? null : json_decode($json, true);
        if (!is_array($data) || ($data['p'] ?? null) !== $purpose || !is_string($data['s'] ?? null) || !is_int($data['e'] ?? null)) {
            return null;
        }
        return $now > $data['e'] ? null : $data['s'];
    }

    /** AES-256-GCM-Verschlüsselung der Datendateien. */
    public static function encrypt(string $key, string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            throw new \RuntimeException('Verschlüsselung fehlgeschlagen');
        }
        return json_encode(['v' => 1, 'alg' => 'aes-256-gcm', 'iv' => self::b64uEncode($iv), 'tag' => self::b64uEncode($tag), 'ct' => self::b64uEncode($ct)]);
    }

    public static function decrypt(string $key, string $envelope): string
    {
        $env = json_decode($envelope, true);
        if (!is_array($env) || ($env['v'] ?? null) !== 1 || ($env['alg'] ?? null) !== 'aes-256-gcm') {
            throw new \RuntimeException('Unbekanntes Format der Datendatei');
        }
        $pt = openssl_decrypt(
            (string) self::b64uDecode($env['ct']),
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            (string) self::b64uDecode($env['iv']),
            (string) self::b64uDecode($env['tag']),
        );
        if ($pt === false) {
            throw new \RuntimeException('Datendatei konnte nicht entschlüsselt werden (falscher data_key oder beschädigte Datei)');
        }
        return $pt;
    }

    public static function randomId(string $prefix = 'p_'): string
    {
        return $prefix . self::b64uEncode(random_bytes(9));
    }

    public static function generateKey(): string
    {
        return bin2hex(random_bytes(32));
    }
}
