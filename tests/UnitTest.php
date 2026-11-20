<?php
declare(strict_types=1);

namespace Wichtelberg\Tests;

use Wichtelberg\Carriers;
use Wichtelberg\Config;
use Wichtelberg\ConfigError;
use Wichtelberg\Crypto;
use Wichtelberg\Draw;
use Wichtelberg\Format;
use Wichtelberg\Store;
use Wichtelberg\Validation;
use function Wichtelberg\e;

T::test('Draw: ein Kreis, keine Selbstzuordnung, keine gegenseitigen Paare', function () {
    for ($n = 3; $n <= 30; $n++) {
        $ids = array_map(fn ($i) => "p$i", range(0, $n - 1));
        for ($run = 0; $run < 15; $run++) {
            $m = Draw::cycle($ids);
            T::eq($n, count($m));
            T::eq($n, count(array_unique(array_values($m))), 'jeder wird genau einmal beschenkt');
            foreach ($m as $g => $r) {
                T::ok($g !== $r);
                T::ok($m[$r] !== $g, 'kein gegenseitiges Paar');
            }
            $seen = [];
            $cur = 'p0';
            do {
                $seen[$cur] = true;
                $cur = $m[$cur];
            } while ($cur !== 'p0');
            T::eq($n, count($seen), 'ein einziger Kreis');
        }
    }
    T::throws(fn () => Draw::cycle(['a']));
});

T::test('Token: Signatur, Zweck, Ablauf', function () {
    $key = str_repeat("\1", 32);
    $now = 1_800_000_000;
    $t = Crypto::signToken($key, 'login:de', 'p_1', $now + 10);
    T::eq('p_1', Crypto::verifyToken($key, 'login:de', $t, $now));
    T::eq(null, Crypto::verifyToken($key, 'login:it', $t, $now), 'andere Runde');
    T::eq(null, Crypto::verifyToken($key, 'session:de', $t, $now), 'falscher Zweck');
    T::eq(null, Crypto::verifyToken($key, 'login:de', $t, $now + 11), 'abgelaufen');
    T::eq(null, Crypto::verifyToken(str_repeat("\2", 32), 'login:de', $t, $now), 'falscher Schlüssel');
    [$payload, $mac] = explode('.', $t);
    $forged = Crypto::b64uEncode(json_encode(['p' => 'login:de', 's' => 'p_2', 'e' => $now + 999]));
    T::eq(null, Crypto::verifyToken($key, 'login:de', "$forged.$mac", $now), 'manipuliert');
    T::eq(null, Crypto::verifyToken($key, 'login:de', $payload, $now));
    T::eq(null, Crypto::verifyToken($key, 'login:de', null, $now));
    T::eq(null, Crypto::verifyToken($key, 'login:de', ['x'], $now));
});

T::test('Verschlüsselung: Roundtrip und Manipulation', function () {
    $key = str_repeat("\3", 32);
    $enc = Crypto::encrypt($key, '{"a":1}');
    T::eq('{"a":1}', Crypto::decrypt($key, $enc));
    T::notContains('"a"', $enc);
    $env = json_decode($enc, true);
    $env['ct'] = Crypto::b64uEncode('xxxxxxx');
    T::throws(fn () => Crypto::decrypt($key, json_encode($env)));
    T::throws(fn () => Crypto::decrypt(str_repeat("\4", 32), $enc));
});

T::test('Konfiguration: Normalisierung und Fehlermeldungen', function () {
    $c = Config::build(rawConfig());
    T::eq(3, $c->rounds[0]->minParticipants);
    T::eq('2027-01-09T23:59:00+01:00', $c->rounds[0]->purgeAt->format(DATE_ATOM));
    T::eq($c->rounds[0]->shippingDeadline, $c->rounds[0]->revealAt, 'Aufdeckung standardmäßig zur Versandfrist');
    T::eq('Deutschland', $c->rounds[0]->countryName);

    T::throws(fn () => Config::build(rawConfig([], [rawConfig()['rounds'][0]])), fn ($e) => str_contains($e->getMessage(), 'doppelt'));
    T::throws(fn () => Config::build(rawConfig(['country' => 'FR'])), fn ($e) => str_contains($e->getMessage(), 'country'));
    T::throws(fn () => Config::build(rawConfig(['slug' => 'admin'])), fn ($e) => str_contains($e->getMessage(), 'slug'));
    T::throws(fn () => Config::build(rawConfig([], [], ['base_url' => 'https://x.de/sub'])), fn ($e) => str_contains($e->getMessage(), 'base_url'));
    T::throws(fn () => Config::build(rawConfig(['registration_deadline' => '2026-12-01T23:59:00'])), fn ($e) => str_contains($e->getMessage(), 'Zeitzone'));

    $msg = '';
    try {
        Config::build(rawConfig(
            ['shipping_deadline' => '2026-11-01T00:00:00+01:00', 'reminders' => ['2026-11-01T00:00:00+01:00'], 'min_participants' => 2],
            [],
            ['organizer_email' => 'kaputt', 'mail' => ['transport' => 'smtp'], 'secrets' => []],
        ));
    } catch (ConfigError $e) {
        $msg = $e->getMessage();
    }
    foreach (['shipping_deadline muss nach', 'rounds[0].reminders[0]', 'min_participants', 'organizer_email', 'mail.from', 'mail.smtp.host', 'secret_key', 'data_key'] as $part) {
        T::contains($part, $msg);
    }
});

T::test('Validierung des Anmeldeformulars', function () {
    [, $errors] = Validation::participantForm(sampleForm(1), 'DE');
    T::eq([], $errors);
    [$values, $errors] = Validation::participantForm(sampleForm(1, ['email' => ' Foo@Example.ORG ', 'postal_code' => '1234', 'adult' => '', 'consent' => '', 'city' => '']), 'DE');
    T::eq('foo@example.org', $values['email']);
    T::ok(isset($errors['postal_code'], $errors['adult'], $errors['consent'], $errors['city']));
    T::ok(!isset($errors['email']));
    T::ok(isset(Validation::participantForm(sampleForm(1, ['email' => 'kein-at']), 'DE')[1]['email']));
    T::ok(isset(Validation::participantForm(sampleForm(1, ['first_name' => str_repeat('ä', 81)]), 'DE')[1]['first_name']));
    T::eq('A B', Validation::clean("A\x00\n  B "));
    T::eq('', Validation::clean(['array']));
    T::eq('', Validation::clean("\xff\xfe"), 'ungültiges UTF-8');
});

T::test('Länderspezifische Adressprüfung', function () {
    T::ok(isset(Validation::participantForm(sampleForm(1, ['postal_code' => '1010']), 'DE')[1]['postal_code']));
    T::eq([], Validation::participantForm(sampleForm(1, ['postal_code' => '1010']), 'AT')[1]);
    T::eq([], Validation::participantForm(sampleForm(1, ['postal_code' => '8001']), 'CH')[1]);
    T::ok(isset(Validation::participantForm(sampleForm(1, ['postal_code' => '80331']), 'CH')[1]['postal_code']));
    T::ok(isset(Validation::participantForm(sampleForm(1, ['postal_code' => '20121']), 'IT')[1]['province']), 'Provinz in Italien Pflicht');
    [$v, $err] = Validation::participantForm(sampleForm(1, ['postal_code' => '20121', 'province' => 'mi']), 'IT');
    T::eq([], $err);
    T::eq('MI', $v['province']);
});

T::test('Versanddienstleister und Tracking-Links je Land', function () {
    T::eq('1Z999AA1', Carriers::normalizeTrackingNumber(' 1z 999-aa1 '));
    T::eq('https://tracking.dpd.de/status/de_DE/parcel/01234567890123', Carriers::trackingUrl('DE', 'DPD', '01234567890123'));
    $de = array_keys(Carriers::carriersFor('DE'));
    sort($de);
    T::eq(['DHL', 'DPD', 'GLS', 'HERMES', 'UPS'], $de);
    T::ok(isset(Carriers::carriersFor('AT')['POST_AT'], Carriers::carriersFor('CH')['POST_CH'], Carriers::carriersFor('IT')['POSTE_IT'], Carriers::carriersFor('IT')['BRT']));
    foreach (Carriers::COUNTRIES as $code => $c) {
        foreach ($c['carriers'] as $k => $carrier) {
            T::ok(str_starts_with($carrier['url'], 'https://') && str_contains($carrier['url'], '{nr}'), "$code/$k");
            T::contains('ABC123', Carriers::trackingUrl($code, $k, 'ABC123'));
        }
    }
    T::eq(null, Carriers::trackingUrl('DE', 'POST_CH', '1'), 'fremde Dienstleister nicht wählbar');
});

T::test('HTML-Escaping und Datumsformat', function () {
    T::eq('&lt;script&gt;&quot;x&quot; &amp; &#039;y&#039;&lt;/script&gt;', e('<script>"x" & \'y\'</script>'));
    T::eq('01.12.2026, 23:59 Uhr', Format::dateTime(new \DateTimeImmutable('2026-12-01T22:59:00Z')));
    T::eq('noch 3 Tage', Format::timeLeft(new \DateTimeImmutable('2026-11-23T13:00:00Z'), new \DateTimeImmutable('2026-11-20T12:00:00Z')));
});

T::test('Store: verschlüsselt, atomar, Rollback, Backups je Runde', function () {
    $dir = tmpDir();
    $key = str_repeat("\5", 32);
    $store = new Store($dir, $key, 'de');
    T::eq('OPEN', $store->data()['status']);
    for ($i = 0; $i < 10; $i++) {
        $store->update(function (array &$d) use ($i) {
            $d['participants'][] = ['id' => "p$i", 'email' => "p$i@x.de", 'mails_sent' => []];
        });
    }
    T::eq(10, count($store->data()['participants']));

    T::throws(fn () => $store->update(function (array &$d) {
        $d['participants'] = [];
        throw new \RuntimeException('nope');
    }));
    T::eq(10, count((new Store($dir, $key, 'de'))->data()['participants']), 'Rollback');
    T::throws(fn () => $store->update(function (array &$d) {
        $d['participants'][] = ['id' => 'p0', 'email' => 'neu@x.de', 'mails_sent' => []];
    }), fn ($e) => str_contains($e->getMessage(), 'doppelte ID'));

    $text = file_get_contents("$dir/de.json.enc");
    T::notContains('p0@x.de', $text, 'Datei ist verschlüsselt');
    T::eq(0600, fileperms("$dir/de.json.enc") & 0777);
    T::eq([], glob("$dir/*.tmp"));
    T::ok(is_file("$dir/.htaccess"), 'Datenverzeichnis per .htaccess gesperrt');

    $store->backup('2026-11-20');
    $store->backup('2026-11-21');
    $other = new Store($dir, $key, 'at');
    $other->update(fn (array &$d) => null);
    $other->backup('2026-11-20');
    $b = $store->listBackups();
    sort($b);
    T::eq(['de-2026-11-20.json.enc', 'de-2026-11-21.json.enc'], $b);
    $store->deleteBackups();
    T::eq([], $store->listBackups());
    T::eq(['at-2026-11-20.json.enc'], $other->listBackups(), 'Backups anderer Runden bleiben');

    T::throws(fn () => (new Store($dir, str_repeat("\9", 32), 'de'))->data(), null, 'falscher Schlüssel');
});

T::test('Store: parallele Prozesse verlieren keine Schreibzugriffe', function () {
    if (!function_exists('pcntl_fork') && !function_exists('proc_open')) {
        return;
    }
    $dir = tmpDir();
    $keyHex = str_repeat('55', 32);
    $script = tempnam(sys_get_temp_dir(), 'wbw') . '.php';
    file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__) . '/src/bootstrap.php', true) . ';
        $s = new Wichtelberg\Store($argv[1], hex2bin($argv[2]), "de");
        for ($i = 0; $i < 10; $i++) { $s->update(function (array &$d) use ($argv, $i) { $d["participants"][] = ["id" => "w{$argv[3]}_$i", "email" => "w{$argv[3]}_$i@x.de", "mails_sent" => []]; }); }');
    $procs = [];
    for ($w = 0; $w < 4; $w++) {
        $procs[] = proc_open([PHP_BINARY, $script, $dir, $keyHex, (string) $w], [], $pipes);
    }
    foreach ($procs as $p) {
        proc_close($p);
    }
    T::eq(40, count((new Store($dir, hex2bin($keyHex), 'de'))->data()['participants']));
    unlink($script);
});
