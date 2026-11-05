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

/** Donnerstag, 05.11.2026 */
function at(string $time, string $day = '2026-11-05'): int
{
    return (int) strtotime($day . ' ' . $time);
}

function h(int $hour, int $minute = 0): int
{
    return $hour * 3600 + $minute * 60;
}

/** Ausgangslage: Abendfenster 16:00–23:00, anwesend, Sensor in Ordnung, hell, noch nicht geschaltet */
function ctx(array $over = []): array
{
    $now = $over['now'] ?? at('17:00');
    $on = $over['on'] ?? h(16);
    $off = $over['off'] ?? h(23);
    $evening = DekoLogic::Evening($now, $on, $off, $off, []);
    return array_merge([
        'automatic'    => true,
        'now'          => $now,
        'inSeason'     => true,
        'seasonStart'  => '',
        'windowActive' => $evening['active'],
        'nextOn'       => DekoLogic::Clock($on),
        'morning'      => false,
        'present'      => true,
        'vacation'     => false,
        'sensorOk'     => true,
        'lux'          => 3000.0,
        'luxOn'        => 800,
        'luxOff'       => 2500,
        'bright'       => false,
        'sunDark'      => false,
        'lastTarget'   => 'off',
        'onToday'      => false,
        'multiple'     => false,
        'onSince'      => 0,
        'minRun'       => 1800,
    ], $over);
}

function decide(array $over = []): array
{
    return DekoLogic::Decide(ctx($over));
}

echo 'Schaltlogik' . PHP_EOL;

// Uhrzeiten
check(DekoLogic::ParseClock('23:00') === h(23), 'ParseClock 23:00');
check(DekoLogic::ParseClock('0.30') === h(0, 30), 'ParseClock mit Punkt');
check(DekoLogic::ParseClock('24:00') === null && DekoLogic::ParseClock('') === null, 'ParseClock ungültig');
check(DekoLogic::Clock(h(6, 5)) === '06:05', 'Clock');

// Zeitfenster
check(DekoLogic::Window(h(17), h(16), h(23))['active'], 'Fenster 16–23 um 17 Uhr aktiv');
check(!DekoLogic::Window(h(23), h(16), h(23))['active'], 'Fenster endet genau um 23 Uhr');
check(DekoLogic::Window(h(0, 30), h(16), h(1))['active'], 'Fenster über Mitternacht um 0:30 aktiv');
check(!DekoLogic::Window(h(12), h(12), h(12))['active'], 'Gleiche Zeiten: nie aktiv');

// Abendfenster mit Schaltabend
$e = DekoLogic::Evening(at('00:30'), h(16), h(1), h(1), []);
check($e['active'] && $e['day'] === '2026-11-04', '0:30 gehört bei Fenster bis 1 Uhr zum Vortag');
$e = DekoLogic::Evening(at('17:00'), h(16), h(1), h(1), []);
check($e['active'] && $e['day'] === '2026-11-05', '17 Uhr gehört zum heutigen Abend');
check(!DekoLogic::Evening(at('12:00'), h(16), h(1), h(1), [])['active'], 'Mittags kein Abendfenster');

// Wochenende: Freitag- und Samstagabend bis 0:30, sonst bis 23 Uhr
$we = [5, 6];
check(!DekoLogic::Evening(at('23:30'), h(16), h(23), h(0, 30), $we)['active'], 'Donnerstag 23:30: aus');
$e = DekoLogic::Evening(at('23:30', '2026-11-06'), h(16), h(23), h(0, 30), $we);
check($e['active'] && $e['weekend'], 'Freitag 23:30: Wochenendfenster aktiv');
$e = DekoLogic::Evening(at('00:15', '2026-11-07'), h(16), h(23), h(0, 30), $we);
check($e['active'] && $e['day'] === '2026-11-06', 'Samstag 0:15 gehört zum Freitagabend');
check(!DekoLogic::Evening(at('00:15', '2026-11-06'), h(16), h(23), h(0, 30), $we)['active'], 'Freitag 0:15 (Donnerstagabend endete um 23 Uhr): aus');
check(!DekoLogic::Evening(at('00:45', '2026-11-08'), h(16), h(23), h(0, 30), $we)['active'], 'Sonntag 0:45: Samstagabend vorbei');
check(DekoLogic::Evening(at('00:15', '2026-11-08'), h(16), h(23), h(0, 30), $we)['active'], 'Sonntag 0:15: Samstagabend läuft noch');

// Morgenfenster
$sunrise = at('07:40');
check(DekoLogic::Morning(at('06:30'), h(6), h(8), $sunrise)['active'], 'Morgens 6:30 aktiv');
check(!DekoLogic::Morning(at('07:45'), h(6), h(8), $sunrise)['active'], 'Nach Sonnenaufgang vorbei');
check(DekoLogic::Morning(at('07:45'), h(6), h(8), 0)['active'], 'Ohne Sonnenaufgang bis 8 Uhr');
check(DekoLogic::Morning(at('06:30'), h(6), h(8), $sunrise)['day'] === '2026-11-05-m', 'Morgen hat eigenen Schalttag');

// Saison
check(date('Y-m-d', DekoLogic::FirstAdvent(2026)) === '2026-11-29', '1. Advent 2026 = 29.11.');
check(date('Y-m-d', DekoLogic::FirstAdvent(2027)) === '2027-11-28', '1. Advent 2027 = 28.11.');
check(date('Y-m-d', DekoLogic::FirstAdvent(2022)) === '2022-11-27', '1. Advent 2022 = 27.11. (Weihnachten Sonntag)');
check(DekoLogic::InSeason('2026-11-05', 0, 0, 0), 'Ohne Saison immer');
check(!DekoLogic::InSeason('2026-11-05', 2, 0, 106), 'Vor dem 1. Advent nicht in der Saison');
check(DekoLogic::InSeason('2026-11-29', 2, 0, 106), 'Am 1. Advent in der Saison');
check(DekoLogic::InSeason('2027-01-06', 2, 0, 106), '6. Januar noch in der Saison');
check(!DekoLogic::InSeason('2027-01-07', 2, 0, 106), '7. Januar nicht mehr');
check(DekoLogic::InSeason('2026-11-15', 1, 1115, 106) && !DekoLogic::InSeason('2026-11-14', 1, 1115, 106), 'Datumsbereich über den Jahreswechsel');
check(DekoLogic::InSeason('2026-07-01', 1, 601, 831), 'Datumsbereich im Sommer');
check(date('Y-m-d', DekoLogic::SeasonStart(at('12:00'), 2, 0)) === '2026-11-29', 'Nächster Saisonbeginn');

// Verlauf
check(DekoLogic::Bright([3000, 3000, 3000], 2500, 3), 'Drei helle Werte: dauerhaft hell');
check(!DekoLogic::Bright([3000, 100, 3000, 100, 100], 2500, 3), 'Zwei von fünf hell: nicht dauerhaft');
check(DekoLogic::Trend([100, 200]) === 1 && DekoLogic::Trend([500, 100]) === -1 && DekoLogic::Trend([500, 520]) === 0, 'Trend');

// Einschalten
$r = decide(['lux' => 500.0]);
check($r['target'] === 'on' && $r['status'] === DekoLogic::ST_ON && $r['args'] === [800], 'Dunkel im Fenster: an');
$r = decide(['sunDark' => true]);
check($r['target'] === 'on' && $r['reason'] === 'Sunset reached', 'Sonnenuntergang: an');
$r = decide(['sunDark' => true, 'morning' => true]);
check($r['target'] === 'on' && $r['reason'] === 'Before sunrise', 'Morgens vor Sonnenaufgang: an');
$r = decide(['lux' => 1500.0]);
check($r['target'] === null && $r['status'] === DekoLogic::ST_WAITING, 'Zwischen den Grenzen: warten');
$r = decide(['sensorOk' => false, 'lux' => 10.0]);
check($r['target'] === null && $r['reason'] === 'Sensor fault – waiting for sunset', 'Sensorfehler: dunkler Wert zählt nicht');
$r = decide(['lux' => 500.0, 'now' => at('12:00')]);
check($r['target'] === 'off' && $r['status'] === DekoLogic::ST_WINDOW && $r['args'] === ['16:00'], 'Außerhalb des Fensters: aus, Grund nennt Einschaltzeit');
$r = decide(['lux' => 500.0, 'inSeason' => false, 'seasonStart' => '29.11.']);
check($r['target'] === 'off' && $r['status'] === DekoLogic::ST_SEASON && $r['args'] === ['29.11.'], 'Außerhalb der Saison: aus');
$r = decide(['lux' => 500.0, 'vacation' => true]);
check($r['target'] === null && $r['status'] === DekoLogic::ST_VACATION, 'Urlaub sperrt das Einschalten');
$r = decide(['lux' => 500.0, 'present' => false]);
check($r['target'] === 'off' && $r['status'] === DekoLogic::ST_ABSENT, 'Abwesend: Ziel aus');
$r = decide(['lux' => 500.0, 'automatic' => false]);
check($r['target'] === null && $r['status'] === DekoLogic::ST_OFF, 'Automatik aus: nichts tun');

// Tageslimit und Mehrfach
$r = decide(['lux' => 500.0, 'onToday' => true]);
check($r['target'] === null && $r['status'] === DekoLogic::ST_LIMIT, 'Heute schon geschaltet: Tageslimit');
$r = decide(['lux' => 500.0, 'onToday' => true, 'multiple' => true]);
check($r['target'] === 'on', 'Mehrfach erlaubt: erneut an');

// Bereits an
$on = ['lastTarget' => 'on', 'onSince' => at('16:50'), 'now' => at('17:00'), 'lux' => 500.0];
$r = decide($on);
check($r['target'] === null && $r['status'] === DekoLogic::ST_ON, 'Schon an: nichts tun');
$r = decide($on + ['vacation' => true]);
check($r['target'] === null, 'Urlaub schaltet nicht aus');

// Ausschalten
$r = decide(['present' => false] + $on);
check($r['target'] === null && $r['status'] === DekoLogic::ST_MINRUN && $r['args'] === [20], 'Abwesend, Mindestlaufzeit läuft (20 min)');
$r = decide(['present' => false, 'now' => at('17:30')] + $on);
check($r['target'] === 'off' && $r['status'] === DekoLogic::ST_ABSENT, 'Abwesend nach Mindestlaufzeit: aus');
$r = decide(['bright' => true, 'now' => at('17:30')] + $on);
check($r['target'] === 'off' && $r['status'] === DekoLogic::ST_BRIGHT, 'Dauerhaft hell nach Mindestlaufzeit: aus');
$r = decide(['bright' => true, 'sensorOk' => false, 'now' => at('17:30')] + $on);
check($r['target'] === null, 'Sensorfehler: Helligkeit schaltet nicht aus');
$r = decide(['now' => at('23:00'), 'onSince' => at('22:50')] + $on);
check($r['target'] === 'off' && $r['status'] === DekoLogic::ST_WINDOW, 'Fensterende schaltet aus, auch während der Mindestlaufzeit');

echo ($failed === 0 ? '  ✓ ' : '  ✗ ') . ($count - $failed) . ' von ' . $count . ' Prüfungen bestanden' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
