<?php

declare(strict_types=1);

/**
 * Tests der Schaltlogik (ohne Symcon).
 * Aufruf: php tests/run.php
 *
 * SPDX-License-Identifier: MIT
 */

require __DIR__ . '/../libs/DekoLogic.php';

date_default_timezone_set('Europe/Berlin');

$failed = 0;
$count = 0;
function check(bool $condition, string $message): void
{
    global $failed, $count;
    $count++;
    if (!$condition) {
        $failed++;
        echo '  ✗ ' . $message . PHP_EOL;
    }
}

function at(string $time): int
{
    return (int) strtotime('2026-11-05 ' . $time);
}

function h(int $hour, int $minute = 0): int
{
    return $hour * 3600 + $minute * 60;
}

/** Ausgangslage: 16:00–23:00, anwesend, Sensor in Ordnung, hell, noch nicht geschaltet */
function ctx(array $over = []): array
{
    $now = $over['now'] ?? at('17:00');
    return array_merge([
        'automatic'     => true,
        'now'           => $now,
        'sec'           => DekoLogic::Seconds($now),
        'on'            => h(16),
        'off'           => h(23),
        'present'       => true,
        'vacation'      => false,
        'sensorOk'      => true,
        'lux'           => 3000.0,
        'luxOn'         => 800,
        'luxOff'        => 2500,
        'bright'        => false,
        'sunsetReached' => false,
        'lastTarget'    => 'off',
        'onToday'       => false,
        'multiple'      => false,
        'onSince'       => 0,
        'minRun'        => 1800,
    ], $over);
}

function decide(array $over = []): array
{
    return DekoLogic::Decide(ctx($over));
}

echo 'Schaltlogik' . PHP_EOL;

// Zeitfenster
check(DekoLogic::Window(h(17), h(16), h(23))['active'], 'Fenster 16–23 um 17 Uhr aktiv');
check(!DekoLogic::Window(h(23), h(16), h(23))['active'], 'Fenster endet genau um 23 Uhr');
check(!DekoLogic::Window(h(12), h(16), h(23))['active'], 'Fenster 16–23 mittags nicht aktiv');
check(DekoLogic::Window(h(0, 30), h(16), h(1))['active'], 'Fenster über Mitternacht um 0:30 aktiv');
check(!DekoLogic::Window(h(12), h(16), h(1))['active'], 'Fenster über Mitternacht mittags nicht aktiv');
check(DekoLogic::Window(h(12), h(16), h(1))['overMidnight'], 'Über Mitternacht erkannt');
check(!DekoLogic::Window(h(12), h(12), h(12))['active'], 'Gleiche Zeiten: nie aktiv');

// Schaltabend
check(DekoLogic::DayKey(at('00:30'), h(0, 30), h(16), h(1)) === '2026-11-04', '0:30 gehört bei Fenster bis 1 Uhr zum Vortag');
check(DekoLogic::DayKey(at('17:00'), h(17), h(16), h(1)) === '2026-11-05', '17 Uhr gehört zum heutigen Abend');
check(DekoLogic::DayKey(at('00:30'), h(0, 30), h(16), h(23)) === '2026-11-05', 'Ohne Mitternacht zählt das Datum');

// Verlauf
check(DekoLogic::Bright([3000, 3000, 3000], 2500, 3), 'Drei helle Werte: dauerhaft hell');
check(!DekoLogic::Bright([3000, 3000], 2500, 3), 'Zwei Werte reichen nicht');
check(!DekoLogic::Bright([3000, 100, 3000, 100, 100], 2500, 3), 'Zwei von fünf hell: nicht dauerhaft');
check(DekoLogic::Trend([100, 200]) === 1, 'Trend heller');
check(DekoLogic::Trend([500, 100]) === -1, 'Trend dunkler');
check(DekoLogic::Trend([500, 520]) === 0, 'Trend stabil (Toleranz)');

// Einschalten
$r = decide(['lux' => 500.0]);
check($r['target'] === 'on' && $r['status'] === DekoLogic::ST_ON, 'Dunkel im Fenster: an');
check($r['args'] === [800], 'Grund nennt die Einschaltgrenze');
$r = decide(['sunsetReached' => true]);
check($r['target'] === 'on' && $r['reason'] === 'Sunset reached', 'Sonnenuntergang: an');
$r = decide(['lux' => 1500.0]);
check($r['target'] === null && $r['status'] === DekoLogic::ST_WAITING, 'Zwischen den Grenzen: warten');
$r = decide(['sensorOk' => false, 'lux' => 10.0]);
check($r['target'] === null && $r['status'] === DekoLogic::ST_WAITING, 'Sensorfehler: dunkler Wert zählt nicht');
check($r['reason'] === 'Sensor fault – waiting for sunset', 'Sensorfehler im Grund genannt');
$r = decide(['sensorOk' => false, 'lux' => null, 'sunsetReached' => true]);
check($r['target'] === 'on', 'Sensorfehler, Sonnenuntergang erreicht: an');
$r = decide(['lux' => 500.0, 'now' => at('12:00')]);
check($r['target'] === 'off' && $r['status'] === DekoLogic::ST_WINDOW, 'Dunkel außerhalb des Fensters: Ziel aus');
check($r['args'] === ['16:00'], 'Grund nennt die Einschaltzeit');
$r = decide(['lux' => 500.0, 'vacation' => true]);
check($r['target'] === null && $r['status'] === DekoLogic::ST_VACATION, 'Urlaub sperrt das Einschalten');
$r = decide(['lux' => 500.0, 'present' => false]);
check($r['target'] === 'off' && $r['status'] === DekoLogic::ST_ABSENT, 'Abwesend: kein Einschalten, Ziel aus');
$r = decide(['lux' => 500.0, 'automatic' => false]);
check($r['target'] === null && $r['status'] === DekoLogic::ST_OFF, 'Automatik aus: nichts tun');

// Tageslimit und Mehrfach
$r = decide(['lux' => 500.0, 'onToday' => true]);
check($r['target'] === null && $r['status'] === DekoLogic::ST_LIMIT, 'Heute schon geschaltet: Tageslimit');
$r = decide(['lux' => 500.0, 'onToday' => true, 'multiple' => true]);
check($r['target'] === 'on', 'Mehrfach erlaubt: erneut an');

// Bereits an: keine neue Einschaltung (Grundlage für die Flanke)
$on = ['lastTarget' => 'on', 'onSince' => at('16:50'), 'now' => at('17:00'), 'lux' => 500.0];
$r = decide($on);
check($r['target'] === null && $r['status'] === DekoLogic::ST_ON, 'Schon an: nichts tun');
$r = decide($on + ['vacation' => true]);
check($r['target'] === null, 'Urlaub schaltet nicht aus');

// Ausschalten
$r = decide(['present' => false, 'lux' => 500.0] + $on);
check($r['target'] === null && $r['status'] === DekoLogic::ST_MINRUN, 'Abwesend, Mindestlaufzeit läuft: warten');
check($r['args'] === [20], 'Restzeit in Minuten (20)');
$r = decide(['present' => false, 'now' => at('17:30')] + $on);
check($r['target'] === 'off' && $r['status'] === DekoLogic::ST_ABSENT, 'Abwesend nach Mindestlaufzeit: aus');
$r = decide(['bright' => true] + $on);
check($r['target'] === null && $r['status'] === DekoLogic::ST_MINRUN, 'Hell, Mindestlaufzeit läuft: warten');
$r = decide(['bright' => true, 'now' => at('17:30')] + $on);
check($r['target'] === 'off' && $r['status'] === DekoLogic::ST_BRIGHT, 'Dauerhaft hell nach Mindestlaufzeit: aus');
$r = decide(['bright' => true, 'sensorOk' => false, 'now' => at('17:30')] + $on);
check($r['target'] === null, 'Sensorfehler: Helligkeit schaltet nicht aus');
$r = decide(['now' => at('23:00'), 'onSince' => at('22:50')] + $on);
check($r['target'] === 'off' && $r['status'] === DekoLogic::ST_WINDOW, 'Fensterende schaltet aus, auch während der Mindestlaufzeit');

// Fenster über Mitternacht
$r = decide(['on' => h(16), 'off' => h(1), 'now' => at('00:30'), 'lux' => 10.0]);
check($r['target'] === 'on', 'Über Mitternacht: um 0:30 dunkel → an');
$r = decide(['on' => h(16), 'off' => h(1), 'now' => at('01:00')] + $on);
check($r['target'] === 'off', 'Über Mitternacht: um 1 Uhr aus');

echo ($failed === 0 ? '  ✓ ' : '  ✗ ') . ($count - $failed) . ' von ' . $count . ' Prüfungen bestanden' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
