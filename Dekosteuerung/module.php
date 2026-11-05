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

require_once __DIR__ . '/../libs/DekoLogic.php';
require_once __DIR__ . '/../libs/DekoImportTrait.php';

class Dekosteuerung extends IPSModuleStrict
{
    use DekoImportTrait;

    private const LOCATION_GUID = '{45E97A63-F870-408A-B259-2933F7EABF74}';

    /** Mindestabstand zwischen zwei Messwerten im Helligkeitsverlauf (Sekunden) */
    private const SAMPLE_SECONDS = 300;

    /** Wartezeit, bis die Rückmeldung der Lampen geprüft wird (Millisekunden) */
    private const VERIFY_MS = 5000;

    /** Namen, die nichts über die Lampe sagen – dann wird der Name des übergeordneten Objekts gezeigt */
    private const GENERIC_NAMES = ['status', 'state', 'zustand', 'schalten', 'switch', 'wert', 'value', 'an/aus', 'ein/aus', 'on/off', 'schaltzustand'];

    public function Create(): void
    {
        // Never delete this line!
        parent::Create();

        $this->RegisterPropertyBoolean('Active', true);

        // Lampen
        $this->RegisterPropertyString('Lights', '[]');

        // Auslöser
        $this->RegisterPropertyInteger('BrightnessVariableID', 0);
        $this->RegisterPropertyInteger('SensorMaxAge', 180);
        $this->RegisterPropertyInteger('BrightCount', 3);
        $this->RegisterPropertyBoolean('UseSunset', true);
        $this->RegisterPropertyInteger('SunsetVariableID', 0);
        $this->RegisterPropertyInteger('SunsetOffset', 0);

        // Bedingungen
        $this->RegisterPropertyInteger('PresenceVariableID', 0);
        $this->RegisterPropertyInteger('VacationVariableID', 0);
        $this->RegisterPropertyInteger('MinOnMinutes', 30);
        $this->RegisterPropertyBoolean('Enforce', false);

        // Kachel
        $this->RegisterPropertyBoolean('UseTile', true);
        $this->RegisterPropertyInteger('TileTheme', 0);

        // Übernahme aus dem bisherigen Skript (nur Auswahl im Formular)
        $this->RegisterPropertyInteger('ImportScriptID', 0);

        // Laufzeitdaten
        $this->RegisterAttributeString('LastTarget', '');
        $this->RegisterAttributeInteger('OnSince', 0);
        $this->RegisterAttributeString('OnDay', '');
        $this->RegisterAttributeInteger('LastSwitch', 0);
        $this->RegisterAttributeString('LastSwitchTarget', '');
        $this->RegisterAttributeString('LastSwitchReason', '');
        $this->RegisterAttributeString('Expected', '');
        $this->RegisterAttributeString('Unconfirmed', '[]');
        $this->RegisterAttributeString('LuxHistory', '[]');
        $this->RegisterAttributeInteger('LuxSampleTime', 0);
        $this->RegisterAttributeInteger('SensorFaultSince', 0);
        $this->RegisterAttributeString('TileData', '{}');
        $this->RegisterAttributeString('Watched', '[]');
        $this->RegisterAttributeBoolean('Initialized', false);

        $this->RegisterTimer('Tick', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'Tick\', 0);');
        $this->RegisterTimer('Verify', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'Verify\', 0);');
    }

    public function ApplyChanges(): void
    {
        // Never delete this line!
        parent::ApplyChanges();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->SetVisualizationType($this->ReadPropertyBoolean('UseTile') ? 1 : 0);
        $this->MaintainVariables();
        $this->WatchVariables();

        $status = $this->ReadPropertyBoolean('Active') ? $this->CheckConfiguration() : 104;
        $this->SetStatus($status);
        $this->SetTimerInterval('Tick', $status === 102 ? 60000 : 0);

        $this->UpdateLightsValue();
        if ($status === 102) {
            $this->Run('apply');
        } else {
            $this->PushTile(null, null);
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }
        if ($Message !== VM_UPDATE) {
            return;
        }
        if (in_array($SenderID, $this->LightIDs(), true)) {
            // Lampe geschaltet (von uns oder von Hand): nur Anzeige nachziehen
            $this->UpdateLightsValue();
            $this->PushTile(null, null);
            return;
        }
        if ($this->GetStatus() === 102) {
            $this->Run('sensor ' . $SenderID);
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'Tick':
                if ($this->GetStatus() === 102) {
                    $this->Run('timer');
                }
                return;

            case 'Verify':
                $this->SetTimerInterval('Verify', 0);
                $this->VerifyLights();
                return;

            case 'Automatic':
                $this->SetAutomatic((bool) $Value);
                return;

            case 'Lights':
                $this->SetLights((bool) $Value);
                return;

            case 'ForcePresence':
            case 'AllowMultiple':
                $this->SetValue($Ident, (bool) $Value);
                $this->Reevaluate();
                return;

            case 'TimeOn':
            case 'TimeOff':
                $this->SetValue($Ident, (int) $Value);
                $this->Reevaluate();
                return;

            case 'LuxOn':
            case 'LuxOff':
                $this->SetThreshold($Ident, (int) $Value);
                $this->Reevaluate();
                return;

            case 'ImportScript':
                echo $this->ImportFromScript((int) $Value);
                return;

            default:
                throw new InvalidArgumentException('Unbekannter Ident: ' . $Ident);
        }
    }

    // =====================================================================
    // Öffentliche Befehle
    // =====================================================================

    /** Sofort neu bewerten (DEKO_Evaluate) */
    public function Evaluate(): bool
    {
        if ($this->GetStatus() !== 102) {
            return false;
        }
        $this->Run('manual');
        return true;
    }

    /** Alle Lampen von Hand schalten (DEKO_SetLights). Die Automatik schaltet erst bei der nächsten Zieländerung wieder. */
    public function SetLights(bool $On): bool
    {
        $this->WriteAttributeString('Expected', $On ? DekoLogic::TARGET_ON : DekoLogic::TARGET_OFF);
        $ok = $this->SwitchAll($On) >= 0;
        $this->UpdateLightsValue();
        $this->PushTile(null, null);
        return $ok;
    }

    /** Automatik ein- oder ausschalten (DEKO_SetAutomatic) */
    public function SetAutomatic(bool $Active): void
    {
        $this->SetValue('Automatic', $Active);
        if ($Active) {
            // Neu beginnen: der aktuelle Lampenzustand gilt als letztes Ziel
            $this->WriteAttributeString('LastTarget', $this->AnyLightOn() ? DekoLogic::TARGET_ON : DekoLogic::TARGET_OFF);
        }
        $this->Reevaluate();
    }

    // =====================================================================
    // Bewertung
    // =====================================================================

    private function Reevaluate(): void
    {
        if ($this->GetStatus() === 102) {
            $this->Run('setting');
        } else {
            $this->PushTile(null, null);
        }
    }

    private function Run(string $trigger): void
    {
        $now = $this->Now();
        $ctx = $this->Context($now);
        $result = DekoLogic::Decide($ctx);
        $target = $result['target'];
        $last = $ctx['lastTarget'];
        $reason = vsprintf($this->Translate($result['reason']), $result['args']);

        $act = false;
        if ($target !== null && $target !== $last) {
            // Erster Lauf nach dem Anlegen: Aus nur merken, nicht schalten (keine Überraschung am Mittag)
            $act = !($last === '' && $target === DekoLogic::TARGET_OFF);
            $this->WriteAttributeString('LastTarget', $target);
            if ($target === DekoLogic::TARGET_ON) {
                $this->WriteAttributeInteger('OnSince', $now);
                $this->WriteAttributeString('OnDay', $ctx['dayKey']);
            } else {
                $this->WriteAttributeInteger('OnSince', 0);
            }
        } elseif ($target !== null && $this->ReadPropertyBoolean('Enforce')) {
            $act = true;
        }

        $this->SendDebug('Bewertung', sprintf('%s → Ziel %s, %s', $trigger, $target ?? '–', $reason), 0);

        if ($act) {
            $this->WriteAttributeString('Expected', (string) $target);
            $changed = $this->SwitchAll($target === DekoLogic::TARGET_ON);
            $this->UpdateLightsValue();
            if ($changed > 0 || $target !== $last) {
                $this->WriteAttributeInteger('LastSwitch', $now);
                $this->WriteAttributeString('LastSwitchTarget', (string) $target);
                $this->WriteAttributeString('LastSwitchReason', $reason);
            }
        }

        $this->SetValueIfChanged('Status', $result['status']);
        $this->SetValueIfChanged('Reason', $reason);
        $this->PushTile($ctx, $result);
    }

    /** Sammelt alle Werte für die Entscheidung */
    private function Context(int $now): array
    {
        $sec = DekoLogic::Seconds($now);
        $on = DekoLogic::Seconds((int) $this->GetValue('TimeOn'));
        $off = DekoLogic::Seconds((int) $this->GetValue('TimeOff'));

        // Anwesenheit (ohne Sensor gilt das Haus als bewohnt)
        $pid = $this->ReadPropertyInteger('PresenceVariableID');
        $presenceSensor = $pid > 0 ? (IPS_VariableExists($pid) && (bool) GetValue($pid)) : null;
        $force = (bool) $this->GetValue('ForcePresence');

        $vid = $this->ReadPropertyInteger('VacationVariableID');
        $vacation = $vid > 0 && IPS_VariableExists($vid) && (bool) GetValue($vid);

        // Helligkeit
        $lux = null;
        $age = null;
        $sensorOk = false;
        $bid = $this->ReadPropertyInteger('BrightnessVariableID');
        $hasSensor = $bid > 0 && IPS_VariableExists($bid);
        if ($hasSensor) {
            $lux = (float) GetValue($bid);
            $updated = (int) IPS_GetVariable($bid)['VariableUpdated'];
            $age = $updated > 0 ? max(0, $now - $updated) : null;
            $sensorOk = $age !== null && $age <= max(1, $this->ReadPropertyInteger('SensorMaxAge')) * 60;
        }
        $faultSince = $this->ReadAttributeInteger('SensorFaultSince');
        if ($hasSensor && !$sensorOk && $faultSince === 0) {
            $faultSince = $now;
            $this->WriteAttributeInteger('SensorFaultSince', $now);
        } elseif ((!$hasSensor || $sensorOk) && $faultSince !== 0) {
            $faultSince = 0;
            $this->WriteAttributeInteger('SensorFaultSince', 0);
        }

        // Verlauf: höchstens alle 5 Minuten ein Wert, die letzten fünf zählen
        $history = json_decode($this->ReadAttributeString('LuxHistory'), true) ?: [];
        if ($sensorOk && $now - $this->ReadAttributeInteger('LuxSampleTime') >= self::SAMPLE_SECONDS) {
            $history[] = round((float) $lux, 1);
            $history = array_slice($history, -DekoLogic::HISTORY_SIZE);
            $this->WriteAttributeString('LuxHistory', json_encode($history));
            $this->WriteAttributeInteger('LuxSampleTime', $now);
        }

        $luxOn = (int) $this->GetValue('LuxOn');
        $luxOff = (int) $this->GetValue('LuxOff');
        $need = max(1, min(DekoLogic::HISTORY_SIZE, $this->ReadPropertyInteger('BrightCount')));

        // Sonnenuntergang (heute, mit Versatz)
        $sunset = $this->SunsetTime($now);
        $useSunset = $this->ReadPropertyBoolean('UseSunset');

        $lastTarget = $this->ReadAttributeString('LastTarget');
        $dayKey = DekoLogic::DayKey($now, $sec, $on, $off);

        return [
            'automatic'      => (bool) $this->GetValue('Automatic'),
            'now'            => $now,
            'sec'            => $sec,
            'on'             => $on,
            'off'            => $off,
            'presenceSensor' => $presenceSensor,
            'force'          => $force,
            'present'        => $force || ($presenceSensor ?? true),
            'vacation'       => $vacation,
            'hasSensor'      => $hasSensor,
            'sensorOk'       => $sensorOk,
            'sensorAge'      => $age,
            'faultSince'     => $faultSince,
            'lux'            => $hasSensor ? $lux : null,
            'luxOn'          => $luxOn,
            'luxOff'         => $luxOff,
            'history'        => $history,
            'bright'         => $sensorOk && DekoLogic::Bright($history, (float) $luxOff, $need),
            'sunset'         => $sunset,
            'sunsetReached'  => $useSunset && $sunset > 0 && $now >= $sunset,
            'lastTarget'     => $lastTarget,
            'dayKey'         => $dayKey,
            'onToday'        => $this->ReadAttributeString('OnDay') === $dayKey,
            'multiple'       => (bool) $this->GetValue('AllowMultiple'),
            'onSince'        => $this->ReadAttributeInteger('OnSince'),
            'minRun'         => max(0, $this->ReadPropertyInteger('MinOnMinutes')) * 60,
        ];
    }

    /** Heutiger Sonnenuntergang inkl. Versatz, 0 wenn unbekannt oder nicht von heute */
    private function SunsetTime(int $now): int
    {
        $id = $this->SunsetVariable();
        if ($id === 0) {
            return 0;
        }
        $ts = (int) GetValue($id);
        if ($ts <= 0 || date('Y-m-d', $ts) !== date('Y-m-d', $now)) {
            return 0;
        }
        return $ts + $this->ReadPropertyInteger('SunsetOffset') * 60;
    }

    /** Variable mit dem Sonnenuntergang: eigene Auswahl, sonst „Sunset“ aus Kern Instanzen → Location */
    private function SunsetVariable(): int
    {
        $id = $this->ReadPropertyInteger('SunsetVariableID');
        if ($id > 0) {
            return IPS_VariableExists($id) ? $id : 0;
        }
        foreach (IPS_GetInstanceListByModuleID(self::LOCATION_GUID) as $instance) {
            $vid = @IPS_GetObjectIDByIdent('Sunset', $instance);
            if (is_int($vid) && $vid > 0 && IPS_VariableExists($vid)) {
                return $vid;
            }
        }
        return 0;
    }

    // =====================================================================
    // Lampen
    // =====================================================================

    /** @return int[] */
    private function LightIDs(): array
    {
        $ids = [];
        foreach (json_decode($this->ReadPropertyString('Lights'), true) ?: [] as $row) {
            $id = (int) ($row['VariableID'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Schaltet alle Lampen, die nicht schon so stehen. Liefert die Zahl der Schaltbefehle.
     * Die Rückmeldung wird nach einigen Sekunden geprüft (Timer „Verify“), nicht sofort –
     * KNX- und Funk-Aktoren melden oft erst nach einer Weile zurück.
     */
    private function SwitchAll(bool $on): int
    {
        $count = 0;
        foreach ($this->LightIDs() as $id) {
            if (!IPS_VariableExists($id) || !HasAction($id)) {
                $this->SendDebug('Lampe', $id . ' fehlt oder hat keine Aktion', 0);
                continue;
            }
            if ((bool) GetValue($id) === $on) {
                continue;
            }
            try {
                RequestAction($id, $on);
                $count++;
            } catch (Throwable $e) {
                $this->SendDebug('Lampe', $id . ': ' . $e->getMessage(), 0);
            }
        }
        $this->WriteAttributeString('Unconfirmed', '[]');
        $this->SetTimerInterval('Verify', $count > 0 ? self::VERIFY_MS : 0);
        return $count;
    }

    private function VerifyLights(): void
    {
        $expected = $this->ReadAttributeString('Expected');
        if ($expected === '') {
            return;
        }
        $want = $expected === DekoLogic::TARGET_ON;
        $missing = [];
        foreach ($this->LightIDs() as $id) {
            if (IPS_VariableExists($id) && (bool) GetValue($id) !== $want) {
                $missing[] = $this->LightName($id);
            }
        }
        $this->WriteAttributeString('Unconfirmed', json_encode($missing));
        if ($missing !== []) {
            $this->LogMessage(sprintf($this->Translate('No confirmation from: %s'), implode(', ', $missing)), KL_WARNING);
        }
        $this->PushTile(null, null);
    }

    private function AnyLightOn(): bool
    {
        foreach ($this->LightIDs() as $id) {
            if (IPS_VariableExists($id) && (bool) GetValue($id)) {
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
        foreach (json_decode($this->ReadPropertyString('Lights'), true) ?: [] as $row) {
            if ((int) ($row['VariableID'] ?? 0) === $id && trim((string) ($row['Name'] ?? '')) !== '') {
                return trim((string) $row['Name']);
            }
        }
        $name = IPS_GetName($id);
        if (in_array(mb_strtolower(trim($name)), self::GENERIC_NAMES, true)) {
            $parent = IPS_GetParent($id);
            if ($parent > 0) {
                return IPS_GetName($parent);
            }
        }
        return $name;
    }

    // =====================================================================
    // Variablen
    // =====================================================================

    private function MaintainVariables(): void
    {
        $switch = static fn (string $icon): array => [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON_TRUE'    => $icon,
            'USAGE_TYPE'   => 0,
        ];

        $this->MaintainVariable('Automatic', $this->Translate('Automatic'), VARIABLETYPE_BOOLEAN, $switch('wand-magic-sparkles'), 10, true);
        $this->EnableAction('Automatic');

        $this->MaintainVariable('Lights', $this->Translate('Decoration light'), VARIABLETYPE_BOOLEAN, [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON_TRUE'    => 'lightbulb-on',
            'ICON_FALSE'   => 'lightbulb',
            'USAGE_TYPE'   => 1,
        ], 20, true);
        $this->EnableAction('Lights');

        $this->MaintainVariable('Status', $this->Translate('Status'), VARIABLETYPE_INTEGER, [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'ICON'         => 'circle-info',
            'DISPLAY'      => 2,
            'OPTIONS'      => json_encode($this->StatusOptions()),
        ], 30, true);

        $this->MaintainVariable('Reason', $this->Translate('Last decision'), VARIABLETYPE_STRING, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'list-check',
        ], 40, true);

        $this->MaintainVariable('TimeOn', $this->Translate('Switch-on time'), VARIABLETYPE_INTEGER, '~UnixTimestampTime', 50, true);
        $this->EnableAction('TimeOn');
        $this->MaintainVariable('TimeOff', $this->Translate('Switch-off time'), VARIABLETYPE_INTEGER, '~UnixTimestampTime', 51, true);
        $this->EnableAction('TimeOff');

        $slider = static fn (string $icon): array => [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'ICON'         => $icon,
            'MIN'          => 0,
            'MAX'          => 10000,
            'STEP_SIZE'    => 50,
            'DIGITS'       => 0,
            'SUFFIX'       => ' lx',
            'USAGE_TYPE'   => 0,
        ];
        $this->MaintainVariable('LuxOn', $this->Translate('Dusk switch-on limit'), VARIABLETYPE_INTEGER, $slider('moon'), 60, true);
        $this->EnableAction('LuxOn');
        $this->MaintainVariable('LuxOff', $this->Translate('Dusk switch-off limit'), VARIABLETYPE_INTEGER, $slider('sun'), 61, true);
        $this->EnableAction('LuxOff');

        $this->MaintainVariable('ForcePresence', $this->Translate('Force presence'), VARIABLETYPE_BOOLEAN, $switch('house-person-return'), 70, true);
        $this->EnableAction('ForcePresence');
        $this->MaintainVariable('AllowMultiple', $this->Translate('Allow multiple switch-ons'), VARIABLETYPE_BOOLEAN, $switch('arrows-rotate'), 71, true);
        $this->EnableAction('AllowMultiple');

        // Startwerte beim ersten Anlegen (wie im bisherigen Skript)
        if (!$this->ReadAttributeBoolean('Initialized')) {
            $this->WriteAttributeBoolean('Initialized', true);
            $this->SetValue('Automatic', true);
            $this->SetValue('TimeOn', mktime(16, 0, 0));
            $this->SetValue('TimeOff', mktime(23, 0, 0));
            $this->SetValue('LuxOn', 800);
            $this->SetValue('LuxOff', 2500);
        }
    }

    private function StatusOptions(): array
    {
        $o = static fn (int $v, string $caption, string $icon, int $color): array
            => ['Value' => $v, 'Caption' => $caption, 'IconActive' => true, 'IconValue' => $icon, 'Color' => $color];
        return [
            $o(DekoLogic::ST_OFF, $this->Translate('Automatic off'), 'pause', 0x7C3AED),
            $o(DekoLogic::ST_WAITING, $this->Translate('Waiting for dusk'), 'cloud-sun', -1),
            $o(DekoLogic::ST_ON, $this->Translate('Light on'), 'lightbulb-on', 0x16A34A),
            $o(DekoLogic::ST_WINDOW, $this->Translate('Outside the time window'), 'clock', 0x64748B),
            $o(DekoLogic::ST_ABSENT, $this->Translate('Nobody present'), 'house-person-leave', 0x0F766E),
            $o(DekoLogic::ST_VACATION, $this->Translate('Vacation'), 'plane', 0x0F766E),
            $o(DekoLogic::ST_LIMIT, $this->Translate('Daily limit reached'), 'calendar-check', 0x6366F1),
            $o(DekoLogic::ST_BRIGHT, $this->Translate('Too bright'), 'sun', 0xF59E0B),
            $o(DekoLogic::ST_MINRUN, $this->Translate('Minimum on-time running'), 'hourglass-half', 0x1677FF),
        ];
    }

    /** Grenzwerte: Ausschaltgrenze nie unter der Einschaltgrenze, sonst flackert die Automatik */
    private function SetThreshold(string $ident, int $value): void
    {
        $value = max(0, min(100000, $value));
        $this->SetValue($ident, $value);
        if ($ident === 'LuxOn' && (int) $this->GetValue('LuxOff') < $value) {
            $this->SetValue('LuxOff', $value);
        } elseif ($ident === 'LuxOff' && (int) $this->GetValue('LuxOn') > $value) {
            $this->SetValue('LuxOn', $value);
        }
    }

    /** Meldet Sensoren und Lampen zur Überwachung an und trägt sie als Referenz ein */
    private function WatchVariables(): void
    {
        foreach (json_decode($this->ReadAttributeString('Watched'), true) ?: [] as $id) {
            $this->UnregisterMessage((int) $id, VM_UPDATE);
            $this->UnregisterReference((int) $id);
        }
        $ids = $this->LightIDs();
        foreach (['BrightnessVariableID', 'PresenceVariableID', 'VacationVariableID'] as $prop) {
            $ids[] = $this->ReadPropertyInteger($prop);
        }
        if ($this->ReadPropertyBoolean('UseSunset')) {
            $ids[] = $this->SunsetVariable();
        }
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0 && IPS_VariableExists($id))));
        foreach ($ids as $id) {
            $this->RegisterMessage($id, VM_UPDATE);
            $this->RegisterReference($id);
        }
        $this->WriteAttributeString('Watched', json_encode($ids));
    }

    private function CheckConfiguration(): int
    {
        $lights = $this->LightIDs();
        if ($lights === []) {
            return 200;
        }
        foreach ($lights as $id) {
            if (!IPS_VariableExists($id) || !HasAction($id)) {
                $this->SendDebug('Konfiguration', 'Lampe ' . $id . ' fehlt oder hat keine Aktion', 0);
                return 201;
            }
        }
        foreach (['BrightnessVariableID', 'PresenceVariableID', 'VacationVariableID', 'SunsetVariableID'] as $prop) {
            $id = $this->ReadPropertyInteger($prop);
            if ($id > 0 && !IPS_VariableExists($id)) {
                $this->SendDebug('Konfiguration', $prop . ' (' . $id . ') existiert nicht', 0);
                return 202;
            }
        }
        // Ohne Helligkeitssensor und ohne Sonnenuntergang gibt es keinen Auslöser zum Einschalten
        $sunset = $this->ReadPropertyBoolean('UseSunset') && $this->SunsetVariable() > 0;
        if ($this->ReadPropertyInteger('BrightnessVariableID') <= 0 && !$sunset) {
            return 203;
        }
        return 102;
    }

    // =====================================================================
    // Formular
    // =====================================================================

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        $id = $this->SunsetVariable();
        if ($id > 0) {
            $ts = (int) GetValue($id);
            $info = sprintf($this->Translate('Sunset from variable #%d: today %s'), $id, $ts > 0 ? date('H:i', $ts) : '–');
        } else {
            $info = $this->Translate('No sunset variable found (Core Instances → Location).');
        }
        $form['elements'] = $this->WalkForm($form['elements'], static function (array $el) use ($info): array {
            if (($el['name'] ?? '') === 'SunsetInfo') {
                $el['caption'] = $info;
            }
            return $el;
        });
        return (string) json_encode($form);
    }

    private function WalkForm(array $elements, callable $fn): array
    {
        foreach ($elements as $i => $el) {
            if (!is_array($el)) {
                continue;
            }
            $el = $fn($el);
            if (isset($el['items']) && is_array($el['items'])) {
                $el['items'] = $this->WalkForm($el['items'], $fn);
            }
            $elements[$i] = $el;
        }
        return $elements;
    }

    // =====================================================================
    // Kachel
    // =====================================================================

    public function GetVisualizationTile(): string
    {
        $html = (string) file_get_contents(__DIR__ . '/tile.html');
        $initial = json_decode($this->ReadAttributeString('TileData'), true) ?: [];
        $initial['now'] = $this->Now();
        $json = (string) json_encode($initial, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return str_replace('/*INITIAL_DATA*/null', $json, $html);
    }

    /** Baut die Kacheldaten; ohne Bewertung (null) werden nur Lampen und Schalter aktualisiert */
    private function PushTile(?array $ctx, ?array $result): void
    {
        $data = json_decode($this->ReadAttributeString('TileData'), true) ?: [];
        $status = $this->GetStatus();

        $data['theme'] = $this->ReadPropertyInteger('TileTheme');
        $data['instanceStatus'] = $status;
        $data['error'] = $this->ConfigError($status);
        $data['automatic'] = (bool) $this->GetValue('Automatic');
        $data['force'] = (bool) $this->GetValue('ForcePresence');
        $data['multiple'] = (bool) $this->GetValue('AllowMultiple');
        $data['lightsOn'] = (bool) $this->GetValue('Lights');
        $data['lamps'] = array_map(fn (int $id): array => [
            'name' => $this->LightName($id),
            'on'   => IPS_VariableExists($id) && (bool) GetValue($id),
        ], array_values(array_filter($this->LightIDs(), 'IPS_VariableExists')));
        $data['unconfirmed'] = json_decode($this->ReadAttributeString('Unconfirmed'), true) ?: [];
        $data['lastSwitch'] = $this->ReadAttributeInteger('LastSwitch');
        $data['lastTarget'] = $this->ReadAttributeString('LastSwitchTarget');
        $data['lastReason'] = $this->ReadAttributeString('LastSwitchReason');
        $data['onSince'] = $this->ReadAttributeString('LastTarget') === DekoLogic::TARGET_ON ? $this->ReadAttributeInteger('OnSince') : 0;
        $data['minRun'] = max(0, $this->ReadPropertyInteger('MinOnMinutes')) * 60;
        $data['timeOn'] = date('H:i', (int) $this->GetValue('TimeOn'));
        $data['timeOff'] = date('H:i', (int) $this->GetValue('TimeOff'));
        $data['luxOn'] = (int) $this->GetValue('LuxOn');
        $data['luxOff'] = (int) $this->GetValue('LuxOff');

        if ($ctx !== null && $result !== null) {
            $window = DekoLogic::Window($ctx['sec'], $ctx['on'], $ctx['off']);
            $data['status'] = $result['status'];
            $data['statusText'] = $this->StatusText($result['status']);
            $data['reason'] = vsprintf($this->Translate($result['reason']), $result['args']);
            $data['window'] = $window['active'];
            $data['overMidnight'] = $window['overMidnight'];
            $data['present'] = $ctx['present'];
            $data['presenceSensor'] = $ctx['presenceSensor'];
            $data['vacation'] = $ctx['vacation'];
            $data['hasSensor'] = $ctx['hasSensor'];
            $data['sensorOk'] = $ctx['sensorOk'];
            // Wert gerundet: weniger Kachel-Updates bei unruhigem Sensor
            $data['lux'] = $ctx['lux'] === null ? null : ($ctx['lux'] >= 100 ? round($ctx['lux'], -1) : round($ctx['lux']));
            $data['faultSince'] = $ctx['faultSince'];
            $data['trend'] = DekoLogic::Trend($ctx['history']);
            $data['bright'] = $ctx['bright'];
            $data['sunset'] = $ctx['sunset'];
            $data['onToday'] = $ctx['onToday'];
        } elseif (isset($data['status'])) {
            $data['statusText'] = $this->StatusText((int) $data['status']);
        }

        $json = (string) json_encode($data);
        if ($json === $this->ReadAttributeString('TileData')) {
            return;
        }
        $this->WriteAttributeString('TileData', $json);
        if ($this->ReadPropertyBoolean('UseTile')) {
            $data['now'] = $this->Now();
            $this->UpdateVisualizationValue((string) json_encode($data));
        }
    }

    private function StatusText(int $status): string
    {
        foreach ($this->StatusOptions() as $option) {
            if ($option['Value'] === $status) {
                return $option['Caption'];
            }
        }
        return '';
    }

    private function ConfigError(int $status): string
    {
        return match ($status) {
            102     => '',
            104     => $this->Translate('Instance is inactive'),
            200     => $this->Translate('Please add the lights'),
            201     => $this->Translate('A light is missing or has no action'),
            202     => $this->Translate('A sensor variable does not exist'),
            203     => $this->Translate('No trigger: select a brightness sensor or use the sunset'),
            default => $this->Translate('Not configured'),
        };
    }

    // =====================================================================
    // Hilfsfunktionen
    // =====================================================================

    protected function Now(): int
    {
        return time();
    }

    private function SetValueIfChanged(string $ident, mixed $value): void
    {
        if ($this->VariableID($ident) === 0) {
            return;
        }
        if ($this->GetValue($ident) !== $value) {
            $this->SetValue($ident, $value);
        }
    }

    /** ID der eigenen Variable, 0 wenn es sie nicht gibt (GetIDForIdent kennt bei IPSModuleStrict kein false) */
    private function VariableID(string $ident): int
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        return is_int($id) ? $id : 0;
    }
}
