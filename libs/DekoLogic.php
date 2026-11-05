<?php

declare(strict_types=1);

/**
 * Dekosteuerung – IP-Symcon-Modul für die automatische Deko-Beleuchtung
 *
 * @author    Armin Frohwerk
 * @copyright 2026 Armin Frohwerk
 * @license   MIT – siehe Datei LICENSE im Hauptverzeichnis
 *
 * SPDX-License-Identifier: MIT
 */

/**
 * Reine Schaltlogik ohne Symcon-Aufrufe – damit lässt sie sich ohne Symcon testen (tests/run.php).
 *
 * Grundidee: Die Logik bestimmt nur ein Ziel (an, aus oder „nichts tun“).
 * Das Modul schaltet nur, wenn sich dieses Ziel ändert (Flanke). Wer zwischendurch von Hand schaltet,
 * wird also nicht bei der nächsten Prüfung überstimmt.
 */
final class DekoLogic
{
    public const ST_OFF = 0;        // Automatik aus
    public const ST_WAITING = 1;    // wartet auf Dämmerung
    public const ST_ON = 2;         // Automatik hat eingeschaltet
    public const ST_WINDOW = 3;     // außerhalb des Zeitfensters
    public const ST_ABSENT = 4;     // niemand anwesend
    public const ST_VACATION = 5;   // Urlaub
    public const ST_LIMIT = 6;      // heute schon geschaltet, Mehrfach aus
    public const ST_BRIGHT = 7;     // dauerhaft hell
    public const ST_MINRUN = 8;     // Mindestlaufzeit läuft noch
    public const ST_SEASON = 9;     // außerhalb der Saison
    public const ST_PENDING = 10;   // Urlaubssimulation: Schaltung mit Zufallsversatz geplant

    public const TARGET_ON = 'on';
    public const TARGET_OFF = 'off';

    /** Anzahl Messwerte im Verlauf */
    public const HISTORY_SIZE = 5;

    // =====================================================================
    // Zeit
    // =====================================================================

    /** Sekunden seit Mitternacht (Ortszeit) */
    public static function Seconds(int $ts): int
    {
        return (int) date('G', $ts) * 3600 + (int) date('i', $ts) * 60 + (int) date('s', $ts);
    }

    /** „HH:MM“ (oder „H:MM“) in Sekunden seit Mitternacht, null bei ungültiger Angabe */
    public static function ParseClock(string $text): ?int
    {
        if (!preg_match('/^\s*([01]?\d|2[0-3])[:.]([0-5]\d)\s*$/', $text, $m)) {
            return null;
        }
        return (int) $m[1] * 3600 + (int) $m[2] * 60;
    }

    /** Sekunden seit Mitternacht als „HH:MM“ */
    public static function Clock(int $sec): string
    {
        $sec = (($sec % 86400) + 86400) % 86400;
        return sprintf('%02d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60));
    }

    /**
     * Zeitfenster: aktiv und über Mitternacht?
     * Bei gleicher Ein- und Ausschaltzeit ist das Fenster nie aktiv.
     */
    public static function Window(int $sec, int $on, int $off): array
    {
        $over = $on > $off;
        if ($on === $off) {
            $active = false;
        } elseif ($over) {
            $active = $sec >= $on || $sec < $off;
        } else {
            $active = $sec >= $on && $sec < $off;
        }
        return ['active' => $active, 'overMidnight' => $over];
    }

    /**
     * Abendfenster. Jeder Abend gehört zu dem Tag, an dem er beginnt („Schaltabend“);
     * liegt das Ende nach Mitternacht, gehört 0:00 bis zum Ende noch zum Vortag.
     *
     * Ausschaltzeit eines Abends, in dieser Reihenfolge:
     *  - Sondertag ($special: MMTT => Sekunden, z. B. 1231 => 7200 für Silvester bis 02:00)
     *  - Wochenende ($weekendDays: ISO-Wochentag des Abends, 1 = Montag … 7 = Sonntag)
     *  - normale Ausschaltzeit
     * Dazu kommt eine einmalige Verlängerung ($extra: 'JJJJ-MM-TT' => Sekunden), höchstens bis kurz vor der Einschaltzeit.
     *
     * @return array{active: bool, on: int, off: int, overMidnight: bool, day: string, weekend: bool, special: int, extended: int}
     */
    public static function Evening(int $now, int $on, int $off, int $offWeekend, array $weekendDays, array $special = [], array $extra = []): array
    {
        $sec = self::Seconds($now);
        $today = date('Y-m-d', $now);
        $yesterday = date('Y-m-d', mktime(12, 0, 0, (int) date('n', $now), (int) date('j', $now) - 1, (int) date('Y', $now)));

        $offFor = static function (string $day) use ($on, $off, $offWeekend, $weekendDays, $special, $extra): array {
            $ts = (int) strtotime($day . ' 12:00');
            $md = self::MonthDay($ts);
            $weekend = in_array((int) date('N', $ts), $weekendDays, true);
            $isSpecial = array_key_exists($md, $special);
            $end = $isSpecial ? (int) $special[$md] : ($weekend ? $offWeekend : $off);
            $add = max(0, (int) ($extra[$day] ?? 0));
            if ($add > 0) {
                $length = (($end - $on) % 86400 + 86400) % 86400;
                $length = min($length + $add, 86400 - 60);
                $end = ($on + $length) % 86400;
            }
            return ['off' => $end, 'weekend' => $weekend, 'special' => $isSpecial ? $md : 0, 'extended' => $add];
        };

        // Abend von gestern, der über Mitternacht hinaus noch läuft
        $y = $offFor($yesterday);
        if ($on > $y['off'] && $sec < $y['off']) {
            return ['active' => true, 'on' => $on, 'off' => $y['off'], 'overMidnight' => true, 'day' => $yesterday,
                'weekend' => $y['weekend'], 'special' => $y['special'], 'extended' => $y['extended']];
        }

        $t = $offFor($today);
        $over = $on > $t['off'];
        $active = $on !== $t['off'] && $sec >= $on && ($over || $sec < $t['off']);
        return ['active' => $active, 'on' => $on, 'off' => $t['off'], 'overMidnight' => $over, 'day' => $today,
            'weekend' => $t['weekend'], 'special' => $t['special'], 'extended' => $t['extended']];
    }

    /**
     * Morgenfenster von $from bis $to, auf Wunsch schon mit Sonnenaufgang zu Ende ($sunrise, 0 = ohne).
     *
     * @return array{active: bool, on: int, off: int, overMidnight: bool, day: string, weekend: bool, special: int, extended: int}
     */
    public static function Morning(int $now, int $from, int $to, int $sunrise): array
    {
        $sec = self::Seconds($now);
        $end = $to;
        if ($sunrise > 0 && date('Y-m-d', $sunrise) === date('Y-m-d', $now)) {
            $end = min($end, self::Seconds($sunrise));
        }
        $active = $from < $end && $sec >= $from && $sec < $end;
        return ['active' => $active, 'on' => $from, 'off' => $end, 'overMidnight' => false, 'day' => date('Y-m-d', $now) . '-m', 'weekend' => false, 'special' => 0, 'extended' => 0];
    }

    /** Erster Advent (Sonntag, vier Wochen vor dem letzten Sonntag vor Weihnachten) als Zeitstempel 0:00 Uhr */
    public static function FirstAdvent(int $year): int
    {
        $dow = (int) date('N', mktime(12, 0, 0, 12, 25, $year)); // 1 = Montag … 7 = Sonntag
        $back = $dow === 7 ? 7 : $dow;                             // 4. Advent: Sonntag vor dem 25.12.
        return mktime(0, 0, 0, 12, 25 - $back - 21, $year);
    }

    /**
     * Liegt der Tag in der Saison?
     * $mode: 0 = immer, 1 = von $startMd bis $endMd, 2 = vom 1. Advent bis $endMd.
     * Monat/Tag als MMTT-Zahl, z. B. 1115 für den 15. November; über den Jahreswechsel möglich.
     */
    public static function InSeason(string $day, int $mode, int $startMd, int $endMd): bool
    {
        if ($mode <= 0) {
            return true;
        }
        $ts = (int) strtotime(substr($day, 0, 10) . ' 12:00');
        $md = (int) date('n', $ts) * 100 + (int) date('j', $ts);
        $start = $mode === 2 ? self::MonthDay(self::FirstAdvent((int) date('Y', $ts))) : $startMd;
        if ($start <= $endMd) {
            return $md >= $start && $md <= $endMd;
        }
        return $md >= $start || $md <= $endMd;
    }

    /** Beginn der nächsten Saison als Zeitstempel (für die Anzeige „ab …“) */
    public static function SeasonStart(int $now, int $mode, int $startMd): int
    {
        $year = (int) date('Y', $now);
        for ($y = $year; $y <= $year + 1; $y++) {
            $ts = $mode === 2 ? self::FirstAdvent($y) : mktime(0, 0, 0, intdiv($startMd, 100), $startMd % 100, $y);
            if ($ts >= mktime(0, 0, 0, (int) date('n', $now), (int) date('j', $now), $year)) {
                return $ts;
            }
        }
        return 0;
    }

    public static function MonthDay(int $ts): int
    {
        return (int) date('n', $ts) * 100 + (int) date('j', $ts);
    }

    // =====================================================================
    // Helligkeit
    // =====================================================================

    /** Dauerhaft hell: mindestens $need der letzten Werte über der Ausschaltgrenze */
    public static function Bright(array $values, float $luxOff, int $need): bool
    {
        if (count($values) < $need) {
            return false;
        }
        $n = 0;
        foreach ($values as $v) {
            if ((float) $v > $luxOff) {
                $n++;
            }
        }
        return $n >= $need;
    }

    /** Trend über den Verlauf: 1 heller, -1 dunkler, 0 stabil */
    public static function Trend(array $values, float $tolerance = 50.0): int
    {
        if (count($values) < 2) {
            return 0;
        }
        $diff = (float) end($values) - (float) reset($values);
        return $diff > $tolerance ? 1 : ($diff < -$tolerance ? -1 : 0);
    }

    // =====================================================================
    // Entscheidung
    // =====================================================================

    /**
     * Erwartet:
     *  automatic, now, inSeason, seasonStart (Text), windowActive, nextOn (Text), morning (aktives Fenster ist das Morgenfenster),
     *  present, vacation (sperrt das Einschalten), sensorOk, lux (?float), luxOn, luxOff, bright (dauerhaft hell),
     *  sunDark (abends: Sonnenuntergang erreicht, morgens: vor Sonnenaufgang),
     *  lastTarget ('on'|'off'|''), onToday, multiple, onSince, minRun (Sekunden)
     *
     * Liefert target ('on'|'off'|null = nichts tun), status, reason (englischer Text mit %-Platzhaltern), args.
     */
    public static function Decide(array $c): array
    {
        $out = static fn (?string $target, int $status, string $reason, array $args = []): array
            => ['target' => $target, 'status' => $status, 'reason' => $reason, 'args' => $args];

        if (!$c['automatic']) {
            return $out(null, self::ST_OFF, 'Automatic is off');
        }

        if (!($c['inSeason'] ?? true)) {
            return $out(self::TARGET_OFF, self::ST_SEASON, 'Outside the season – from %s', [(string) ($c['seasonStart'] ?? '')]);
        }

        $isOn = $c['lastTarget'] === self::TARGET_ON;
        $minRunLeft = ($isOn && $c['onSince'] > 0) ? max(0, $c['onSince'] + $c['minRun'] - $c['now']) : 0;

        // Ende des Zeitfensters: immer aus, auch während der Mindestlaufzeit
        if (!$c['windowActive']) {
            return $out(self::TARGET_OFF, self::ST_WINDOW, 'Switching on from %s at the earliest', [(string) $c['nextOn']]);
        }

        if (!$c['present']) {
            if ($minRunLeft > 0) {
                return $out(null, self::ST_MINRUN, 'Nobody present – minimum on-time still %d min', [(int) ceil($minRunLeft / 60)]);
            }
            return $out(self::TARGET_OFF, self::ST_ABSENT, 'Nobody present');
        }

        if ($c['sensorOk'] && $c['bright']) {
            if ($minRunLeft > 0) {
                return $out(null, self::ST_MINRUN, 'Bright – minimum on-time still %d min', [(int) ceil($minRunLeft / 60)]);
            }
            return $out(self::TARGET_OFF, self::ST_BRIGHT, 'Permanently brighter than %d lx', [(int) $c['luxOff']]);
        }

        if ($isOn) {
            return $out(null, self::ST_ON, 'Light is on');
        }

        if ($c['vacation']) {
            return $out(null, self::ST_VACATION, 'Vacation – switching on is blocked');
        }

        $byLux = $c['sensorOk'] && $c['lux'] !== null && $c['lux'] < $c['luxOn'];
        if (!$byLux && !$c['sunDark']) {
            if (!$c['sensorOk']) {
                return $out(null, self::ST_WAITING, $c['morning'] ? 'Sensor fault – sunrise has passed' : 'Sensor fault – waiting for sunset');
            }
            return $out(null, self::ST_WAITING, $c['morning'] ? 'Bright enough in the morning' : 'Waiting for dusk');
        }

        if ($c['onToday'] && !$c['multiple']) {
            return $out(null, self::ST_LIMIT, 'Already switched on today');
        }

        if ($byLux) {
            return $out(self::TARGET_ON, self::ST_ON, 'Darker than %d lx', [(int) $c['luxOn']]);
        }
        return $out(self::TARGET_ON, self::ST_ON, $c['morning'] ? 'Before sunrise' : 'Sunset reached');
    }
}
