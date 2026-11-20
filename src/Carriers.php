<?php
declare(strict_types=1);

namespace Wichtelberg;

/**
 * Länder und ihre wichtigsten Versanddienstleister.
 *
 * Tracking-URL-Vorlagen: {nr} wird durch die Sendungsnummer ersetzt.
 * WICHTIG: Die URLs vor dem Go-live prüfen – Anbieter ändern sie gelegentlich.
 * Die Reihenfolge entspricht der Anzeige im Auswahlfeld.
 */
final class Carriers
{
    public const COUNTRIES = [
        'DE' => [
            'name' => 'Deutschland',
            'postal' => ['re' => '/^\d{5}$/', 'label' => 'PLZ', 'hint' => '5 Ziffern', 'length' => 5],
            'province' => false,
            'carriers' => [
                'DHL' => ['label' => 'DHL', 'url' => 'https://www.dhl.de/de/privatkunden/pakete-empfangen/verfolgen.html?piececode={nr}'],
                'HERMES' => ['label' => 'Hermes', 'url' => 'https://www.myhermes.de/empfangen/sendungsverfolgung/sendungsinformation#{nr}'],
                'DPD' => ['label' => 'DPD', 'url' => 'https://tracking.dpd.de/status/de_DE/parcel/{nr}'],
                'GLS' => ['label' => 'GLS', 'url' => 'https://gls-group.com/DE/de/paketverfolgung?match={nr}'],
                'UPS' => ['label' => 'UPS', 'url' => 'https://www.ups.com/track?loc=de_DE&tracknum={nr}'],
            ],
        ],
        'AT' => [
            'name' => 'Österreich',
            'postal' => ['re' => '/^\d{4}$/', 'label' => 'PLZ', 'hint' => '4 Ziffern', 'length' => 4],
            'province' => false,
            'carriers' => [
                'POST_AT' => ['label' => 'Österreichische Post', 'url' => 'https://www.post.at/s/sendungsdetails?snr={nr}'],
                'DPD' => ['label' => 'DPD', 'url' => 'https://tracking.dpd.de/status/de_AT/parcel/{nr}'],
                'GLS' => ['label' => 'GLS', 'url' => 'https://gls-group.com/AT/de/paketverfolgung?match={nr}'],
                'DHL' => ['label' => 'DHL', 'url' => 'https://www.dhl.com/at-de/home/tracking.html?tracking-id={nr}'],
                'UPS' => ['label' => 'UPS', 'url' => 'https://www.ups.com/track?loc=de_AT&tracknum={nr}'],
            ],
        ],
        'CH' => [
            'name' => 'Schweiz',
            'postal' => ['re' => '/^\d{4}$/', 'label' => 'PLZ', 'hint' => '4 Ziffern', 'length' => 4],
            'province' => false,
            'carriers' => [
                'POST_CH' => ['label' => 'Die Schweizerische Post', 'url' => 'https://service.post.ch/ekp-web/ui/entry/search/{nr}'],
                'PLANZER' => ['label' => 'Planzer Paket', 'url' => 'https://www.planzer-paket.ch/de/sendungsverfolgung?parcelNumber={nr}'],
                'DPD' => ['label' => 'DPD', 'url' => 'https://tracking.dpd.de/status/de_CH/parcel/{nr}'],
                'DHL' => ['label' => 'DHL', 'url' => 'https://www.dhl.com/ch-de/home/tracking.html?tracking-id={nr}'],
                'UPS' => ['label' => 'UPS', 'url' => 'https://www.ups.com/track?loc=de_CH&tracknum={nr}'],
                'GLS' => ['label' => 'GLS', 'url' => 'https://gls-group.com/CH/de/paketverfolgung?match={nr}'],
            ],
        ],
        'IT' => [
            'name' => 'Italien',
            'postal' => ['re' => '/^\d{5}$/', 'label' => 'CAP (PLZ)', 'hint' => '5 Ziffern', 'length' => 5],
            'province' => true, // Provinzkürzel (z. B. MI, RM, BZ) gehört zur italienischen Anschrift
            'carriers' => [
                'POSTE_IT' => ['label' => 'Poste Italiane', 'url' => 'https://www.poste.it/cerca/index.html#/risultati-spedizioni/{nr}'],
                'BRT' => ['label' => 'BRT (Bartolini)', 'url' => 'https://vas.brt.it/vas/sped_det_show.hsm?referer=sped_numspe_par.htm&Nspediz={nr}'],
                'SDA' => ['label' => 'SDA', 'url' => 'https://www.sda.it/wps/portal/Servizi_online/dettaglio-spedizione?locale=it&tracing.letteraVettura={nr}'],
                'GLS' => ['label' => 'GLS', 'url' => 'https://gls-group.com/IT/it/servizi-online/ricerca-spedizioni?match={nr}'],
                'DHL' => ['label' => 'DHL', 'url' => 'https://www.dhl.com/it-it/home/tracking.html?tracking-id={nr}'],
                'UPS' => ['label' => 'UPS', 'url' => 'https://www.ups.com/track?loc=it_IT&tracknum={nr}'],
            ],
        ],
    ];

    public static function country(string $code): array
    {
        return self::COUNTRIES[$code];
    }

    /** @return array<string, array{label: string, url: string}> */
    public static function carriersFor(string $country): array
    {
        return self::COUNTRIES[$country]['carriers'] ?? [];
    }

    public static function normalizeTrackingNumber(?string $value): string
    {
        return strtoupper((string) preg_replace('/[\s\-]+/', '', (string) $value));
    }

    /** Grobe Prüfung: 6–40 Zeichen, nur Buchstaben und Ziffern. */
    public static function isPlausibleTrackingNumber(string $nr): bool
    {
        return (bool) preg_match('/^[A-Z0-9]{6,40}$/', $nr);
    }

    public static function trackingUrl(string $country, string $carrier, string $nr): ?string
    {
        $c = self::COUNTRIES[$country]['carriers'][$carrier] ?? null;
        return $c ? str_replace('{nr}', rawurlencode($nr), $c['url']) : null;
    }

    public static function label(string $country, string $carrier): string
    {
        return self::COUNTRIES[$country]['carriers'][$carrier]['label'] ?? $carrier;
    }
}
