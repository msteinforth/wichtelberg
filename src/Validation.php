<?php
declare(strict_types=1);

namespace Wichtelberg;

final class Validation
{
    private const FIELDS = [
        'first_name' => ['label' => 'Vorname', 'required' => true, 'max' => 80],
        'last_name' => ['label' => 'Nachname', 'required' => true, 'max' => 80],
        'street' => ['label' => 'Straße', 'required' => true, 'max' => 120],
        'house_number' => ['label' => 'Hausnummer', 'required' => true, 'max' => 20],
        'extra' => ['label' => 'Adresszusatz', 'required' => false, 'max' => 120],
        'postal_code' => ['label' => 'PLZ', 'required' => true, 'max' => 5],
        'city' => ['label' => 'Ort', 'required' => true, 'max' => 80],
        'note' => ['label' => 'Hinweis für Deinen Wichtel', 'required' => false, 'max' => 300],
    ];

    public static function clean(mixed $v): string
    {
        $s = is_string($v) ? $v : '';
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = '';
        }
        $s = (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $s);
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    public static function normalizeEmail(mixed $v): string
    {
        return mb_strtolower(self::clean($v));
    }

    public static function isEmail(string $email): bool
    {
        return strlen($email) <= 254 && (bool) preg_match('/^[^\s@<>"]+@[^\s@<>"]+\.[^\s@<>"]+$/', $email);
    }

    /**
     * Prüft Name/Adresse (bei Neuanmeldung auch E-Mail und Einwilligungen) nach den Regeln des Landes.
     * @return array{0: array, 1: array} [values, errors]
     */
    public static function participantForm(array $form, string $country, bool $requireEmail = true, bool $requireConsents = true): array
    {
        $c = Carriers::country($country);
        $values = [];
        $errors = [];
        foreach (self::FIELDS as $name => $def) {
            $v = self::clean($form[$name] ?? '');
            $values[$name] = $v;
            $label = $name === 'postal_code' ? $c['postal']['label'] : $def['label'];
            if ($def['required'] && $v === '') {
                $errors[$name] = "$label fehlt.";
            } elseif (mb_strlen($v) > $def['max']) {
                $errors[$name] = "{$def['label']} ist zu lang (max. {$def['max']} Zeichen).";
            }
        }
        if ($values['postal_code'] !== '' && !preg_match($c['postal']['re'], $values['postal_code'])) {
            $errors['postal_code'] = "Bitte gib eine gültige Postleitzahl für {$c['name']} an ({$c['postal']['hint']}).";
        }
        if ($c['province']) {
            $values['province'] = strtoupper(self::clean($form['province'] ?? ''));
            if (!preg_match('/^[A-Z]{2}$/', $values['province'])) {
                $errors['province'] = 'Bitte gib das Provinzkürzel mit 2 Buchstaben an (z. B. MI, RM, BZ).';
            }
        }
        if ($requireEmail) {
            $values['email'] = self::normalizeEmail($form['email'] ?? '');
            if ($values['email'] === '') {
                $errors['email'] = 'E-Mail-Adresse fehlt.';
            } elseif (!self::isEmail($values['email'])) {
                $errors['email'] = 'Bitte gib eine gültige E-Mail-Adresse an.';
            }
        }
        if ($requireConsents) {
            if (($form['adult'] ?? '') !== 'on') {
                $errors['adult'] = 'Die Teilnahme ist erst ab 18 Jahren möglich.';
            }
            if (($form['consent'] ?? '') !== 'on') {
                $errors['consent'] = 'Bitte stimme der Datenschutzerklärung und der Weitergabe Deiner Adresse zu.';
            }
        }
        return [$values, $errors];
    }

    public static function address(array $values): array
    {
        return [
            'street' => $values['street'],
            'house_number' => $values['house_number'],
            'extra' => $values['extra'] !== '' ? $values['extra'] : null,
            'postal_code' => $values['postal_code'],
            'city' => $values['city'],
            'province' => ($values['province'] ?? '') !== '' ? $values['province'] : null,
        ];
    }
}
