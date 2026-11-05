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
 * Lampen: Schaltvariablen (Boolean) und Dimmer (Integer/Float), je Lampe optional
 * mit eigenem Namen, früherer Ausschaltzeit am Abend, Teilnahme am Morgenfenster und Dimmwert.
 */
trait DekoLampTrait
{
    /** Namen, die nichts über die Lampe sagen – dann wird der Name des übergeordneten Objekts gezeigt */
    private static array $genericNames = ['status', 'state', 'zustand', 'schalten', 'switch', 'wert', 'value', 'an/aus', 'ein/aus', 'on/off', 'schaltzustand', 'intensität', 'intensity', 'helligkeit', 'brightness'];

    /** @return array<int, array{VariableID: int, Name: string, OffTime: string, Morning: bool, Level: float}> */
    private function LampRows(): array
    {
        $rows = [];
        foreach (json_decode($this->ReadPropertyString('Lights'), true) ?: [] as $row) {
            $id = (int) ($row['VariableID'] ?? 0);
            if ($id <= 0 || isset($rows[$id])) {
                continue;
            }
            $rows[$id] = [
                'VariableID' => $id,
                'Name'       => trim((string) ($row['Name'] ?? '')),
                'OffTime'    => trim((string) ($row['OffTime'] ?? '')),
                'Morning'    => (bool) ($row['Morning'] ?? true),
                'Level'      => (float) ($row['Level'] ?? 100),
            ];
        }
        return $rows;
    }

    /** @return int[] */
    private function LightIDs(): array
    {
        return array_keys($this->LampRows());
    }

    /** Ist die Lampe an? Schalter: true; Dimmer: Wert größer 0 */
    private function LampOn(int $id): bool
    {
        if (!IPS_VariableExists($id)) {
            return false;
        }
        $value = GetValue($id);
        return is_bool($value) ? $value : (float) $value > 0;
    }

    /** Wert, mit dem die Lampe geschaltet wird (Schalter: Bool, Dimmer: Dimmwert bzw. 0) */
    private function LampCommand(int $id, bool $on, float $level): bool|int|float
    {
        return match (IPS_GetVariable($id)['VariableType']) {
            VARIABLETYPE_INTEGER => $on ? (int) round($level) : 0,
            VARIABLETYPE_FLOAT   => $on ? $level : 0.0,
            default              => $on,
        };
    }

    /**
     * Ausschaltzeit einer Lampe an diesem Abend als Zeitstempel (0 = keine eigene Zeit).
     * Eine Zeit vor oder gleich der Einschaltzeit liegt nach Mitternacht.
     */
    private function LampOffTime(array $row, string $eveningDay, int $on): int
    {
        $sec = DekoLogic::ParseClock($row['OffTime']);
        if ($sec === null) {
            return 0;
        }
        [$y, $m, $d] = array_map('intval', explode('-', substr($eveningDay, 0, 10)));
        return mktime(0, 0, 0, $m, $d + ($sec <= $on ? 1 : 0), $y) + $sec;
    }

    /**
     * Schaltet die Lampen auf die gewünschten Zustände (ID => an/aus) – nur die, die nicht schon so stehen.
     * Die Rückmeldung prüft der Timer „Verify“ nach einigen Sekunden; KNX- und Funk-Aktoren melden oft verzögert.
     */
    private function SwitchLamps(array $targets): int
    {
        $rows = $this->LampRows();
        $count = 0;
        $expected = json_decode($this->ReadAttributeString('Expected'), true) ?: [];
        foreach ($targets as $id => $on) {
            $id = (int) $id;
            if (!isset($rows[$id]) || !IPS_VariableExists($id) || !HasAction($id)) {
                $this->SendDebug('Lampe', $id . ' fehlt oder hat keine Aktion', 0);
                continue;
            }
            $expected[$id] = (bool) $on;
            if ($this->LampOn($id) === (bool) $on) {
                continue;
            }
            try {
                RequestAction($id, $this->LampCommand($id, (bool) $on, $rows[$id]['Level']));
                $count++;
            } catch (Throwable $e) {
                $this->SendDebug('Lampe', $id . ': ' . $e->getMessage(), 0);
            }
        }
        $this->WriteAttributeString('Expected', (string) json_encode($expected));
        $this->WriteAttributeString('Unconfirmed', '[]');
        if ($count > 0) {
            $this->SetTimerInterval('Verify', self::VERIFY_MS);
        }
        $this->UpdateLightsValue();
        return $count;
    }

    private function VerifyLights(): void
    {
        $expected = json_decode($this->ReadAttributeString('Expected'), true) ?: [];
        $this->WriteAttributeString('Expected', '{}');
        $missing = [];
        foreach ($expected as $id => $want) {
            $id = (int) $id;
            if (IPS_VariableExists($id) && $this->LampOn($id) !== (bool) $want) {
                $missing[] = $this->LightName($id);
            }
        }
        $this->WriteAttributeString('Unconfirmed', (string) json_encode($missing));
        if ($missing !== []) {
            $text = sprintf($this->Translate('No confirmation from: %s'), implode(', ', $missing));
            $this->LogMessage($text, KL_WARNING);
            if ($this->ReadPropertyBoolean('NotifyUnconfirmed')) {
                $this->Notify($this->Translate('Decoration light'), $text);
            }
        }
        $this->PushTile(null, null);
    }

    private function AnyLightOn(): bool
    {
        foreach ($this->LightIDs() as $id) {
            if ($this->LampOn($id)) {
                return true;
            }
        }
        return false;
    }

    private function UpdateLightsValue(): void
    {
        $this->SetValueIfChanged('Lights', $this->AnyLightOn());
    }

    private function LightName(int $id): string
    {
        $rows = $this->LampRows();
        if (isset($rows[$id]) && $rows[$id]['Name'] !== '') {
            return $rows[$id]['Name'];
        }
        if (!IPS_ObjectExists($id)) {
            return '#' . $id;
        }
        $name = IPS_GetName($id);
        if (in_array(mb_strtolower(trim($name)), self::$genericNames, true)) {
            $parent = IPS_GetParent($id);
            if ($parent > 0) {
                return IPS_GetName($parent);
            }
        }
        return $name;
    }
}
