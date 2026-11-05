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

    public const TARGET_ON = 'on';
    public const TARGET_OFF = 'off';

    /** Anzahl Messwerte im Verlauf und wie viele davon über der Ausschaltgrenze liegen müssen */
    public const HISTORY_SIZE = 5;

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
     * Schlüssel des „Schaltabends“. Liegt das Fenster über Mitternacht, gehört 00:00 bis zur
     * Ausschaltzeit noch zum Vortag. Damit braucht es kein Tagesreset-Ereignis mehr.
     */
    public static function DayKey(int $now, int $sec, int $on, int $off): string
    {
        if ($on > $off && $sec < $off) {
            $now -= 86400;
        }
        return date('Y-m-d', $now);
    }

    /** Sekunden seit Mitternacht (Ortszeit) */
    public static function Seconds(int $ts): int
    {
        return (int) date('G', $ts) * 3600 + (int) date('i', $ts) * 60 + (int) date('s', $ts);
    }

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

    /**
     * Entscheidung. Erwartet:
     *  automatic, now, sec, on, off (Sekunden seit Mitternacht), present, vacation,
     *  sensorOk, lux (?float), luxOn, luxOff, bright (dauerhaft hell), sunsetReached,
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

        $window = self::Window($c['sec'], $c['on'], $c['off']);
        $isOn = $c['lastTarget'] === self::TARGET_ON;
        $minRunLeft = ($isOn && $c['onSince'] > 0) ? max(0, $c['onSince'] + $c['minRun'] - $c['now']) : 0;

        // Ende des Zeitfensters: immer aus, auch während der Mindestlaufzeit
        if (!$window['active']) {
            return $out(self::TARGET_OFF, self::ST_WINDOW, 'Outside the time window');
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
        if (!$byLux && !$c['sunsetReached']) {
            return $out(null, self::ST_WAITING, $c['sensorOk'] ? 'Waiting for dusk' : 'Sensor fault – waiting for sunset');
        }

        if ($c['onToday'] && !$c['multiple']) {
            return $out(null, self::ST_LIMIT, 'Already switched on today');
        }

        return $byLux
            ? $out(self::TARGET_ON, self::ST_ON, 'Darker than %d lx', [(int) $c['luxOn']])
            : $out(self::TARGET_ON, self::ST_ON, 'Sunset reached');
    }
}
