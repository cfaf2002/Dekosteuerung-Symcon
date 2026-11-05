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
 * Übernahme aus dem bisherigen Skript „Lichtsteuerung Dashboard 2.0“.
 *
 * Das Skript wird nur gelesen und mit regulären Ausdrücken ausgewertet – nie ausgeführt.
 * Variablen-IDs und Lampen landen im Formular (gespeichert wird mit „Änderungen übernehmen“).
 * Zeiten, Grenzwerte und Schalter werden sofort in die Variablen der Instanz geschrieben,
 * dazu der letzte Schaltzustand, damit die Automatik nahtlos weiterläuft.
 */
trait DekoImportTrait
{
    /** Formularfeld => Name im Skript */
    private const IMPORT_FIELDS = [
        'BrightnessVariableID' => 'ID_DAEMMERUNG',
        'PresenceVariableID'   => 'ID_ANWESEND',
        'VacationVariableID'   => 'ID_URLAUB',
        'SunsetVariableID'     => 'ID_SONNENUNTERGANG_HEUTE',
    ];

    /** Variable der Instanz => [Name im Skript, Rückfall-Konstante] */
    private const IMPORT_VALUES = [
        'TimeOn'        => ['ID_EINSCHALTZEIT', null],
        'TimeOff'       => ['ID_AUSSCHALTZEIT', null],
        'LuxOn'         => ['ID_DAEMMERUNG_EIN_GRENZE', 'DAEMMERUNG_EIN_FALLBACK'],
        'LuxOff'        => ['ID_DAEMMERUNG_AUS_GRENZE', 'DAEMMERUNG_AUS_FALLBACK'],
        'ForcePresence' => ['ID_ANWESENHEIT_OVERRIDE', null],
        'AllowMultiple' => ['ID_MEHRFACHSCHALTUNGEN', null],
    ];

    private const IMPORT_MAX_BYTES = 262144;

    private function ImportFromScript(int $scriptID): string
    {
        if ($scriptID <= 0 || !IPS_ScriptExists($scriptID)) {
            return $this->Translate('Please select the previous script first.');
        }
        $code = (string) IPS_GetScriptContent($scriptID);
        if ($code === '' || strlen($code) > self::IMPORT_MAX_BYTES) {
            return $this->Translate('The script is empty or too large.');
        }

        $p = self::ParseScript($code);
        $done = [];
        $notes = [];

        // Variablen-IDs ins Formular
        foreach (self::IMPORT_FIELDS as $field => $name) {
            $id = $p['ints'][$name] ?? 0;
            if ($id <= 0) {
                continue;
            }
            if (!IPS_VariableExists($id)) {
                $notes[] = sprintf($this->Translate('Variable #%d (%s) does not exist and was skipped.'), $id, $name);
                continue;
            }
            $this->UpdateFormField($field, 'value', $id);
            $done[] = $field . ' = #' . $id;
        }

        // Lampen: Einschalt- und Ausschaltliste zusammen, ohne Doppelte
        $lights = array_values(array_unique(array_merge($p['lists']['LICHT_EIN_IDS'] ?? [], $p['lists']['LICHT_AUS_IDS'] ?? [])));
        $rows = [];
        foreach ($lights as $id) {
            if (!IPS_VariableExists($id)) {
                $notes[] = sprintf($this->Translate('Light #%d does not exist and was skipped.'), $id);
                continue;
            }
            if (!HasAction($id)) {
                $notes[] = sprintf($this->Translate('Light #%d has no action – please check it.'), $id);
            }
            $rows[] = ['VariableID' => $id, 'Name' => '', 'OffTime' => '', 'Morning' => true, 'Level' => 100];
        }
        if ($rows !== []) {
            $this->UpdateFormField('Lights', 'values', (string) json_encode($rows));
            $done[] = sprintf($this->Translate('%d lights'), count($rows));
        }

        // Einstellungen direkt in die Variablen der Instanz
        foreach (self::IMPORT_VALUES as $ident => [$name, $fallback]) {
            $id = $p['ints'][$name] ?? 0;
            if ($id > 0 && IPS_VariableExists($id)) {
                $value = GetValue($id);
            } elseif ($fallback !== null && isset($p['ints'][$fallback])) {
                $value = $p['ints'][$fallback];
            } else {
                continue;
            }
            if ($this->VariableID($ident) === 0) {
                continue;
            }
            $value = match ($ident) {
                'ForcePresence', 'AllowMultiple' => (bool) $value,
                default                          => (int) $value,
            };
            $this->SetValue($ident, $value);
            $done[] = $ident;
        }

        // Letzter Schaltzustand: die Automatik soll nicht von vorn anfangen
        $actionID = $p['ints']['ID_LETZTE_AKTION'] ?? 0;
        if ($actionID > 0 && IPS_VariableExists($actionID)) {
            $isOn = (string) GetValue($actionID) === 'Licht EIN';
            $this->WriteAttributeString('LastTarget', $isOn ? DekoLogic::TARGET_ON : DekoLogic::TARGET_OFF);
            $sinceID = $p['ints']['ID_EINSCHALTZEITPUNKT'] ?? 0;
            $since = ($sinceID > 0 && IPS_VariableExists($sinceID)) ? (int) GetValue($sinceID) : 0;
            $this->WriteAttributeInteger('OnSince', $isOn ? $since : 0);
            $doneID = $p['ints']['ID_BEREITS_GESCHALTET'] ?? 0;
            if ($doneID > 0 && IPS_VariableExists($doneID) && (bool) GetValue($doneID)) {
                $now = $this->Now();
                $this->WriteAttributeString('OnDay', DekoLogic::DayKey(
                    $now,
                    DekoLogic::Seconds($now),
                    DekoLogic::Seconds((int) $this->GetValue('TimeOn')),
                    DekoLogic::Seconds((int) $this->GetValue('TimeOff'))
                ));
            }
            $done[] = $this->Translate('last switching state');
        }

        if ($done === []) {
            return $this->Translate('Nothing found. Is this the previous decoration lighting script?');
        }
        $text = $this->Translate('Taken over:') . "\n• " . implode("\n• ", $done);
        if ($notes !== []) {
            $text .= "\n\n" . $this->Translate('Notes:') . "\n• " . implode("\n• ", $notes);
        }
        return $text . "\n\n" . $this->Translate('Click "Apply changes" to save the sensors and lights. Then deactivate the old script and its events.');
    }

    /**
     * Liest Zuweisungen der Form $NAME = 123; und Listen $NAME = [1, 2, 3];
     * @return array{ints: array<string,int>, lists: array<string,int[]>}
     */
    private static function ParseScript(string $code): array
    {
        // Kommentare entfernen, damit auskommentierte Zeilen nicht zählen
        $code = (string) preg_replace(['~/\*.*?\*/~s', '~(?<!:)//[^\n]*~', '~#[^\n]*~'], '', $code);
        $ints = [];
        if (preg_match_all('/\$([A-Z][A-Z0-9_]*)\s*=\s*(\d+)\s*;/', $code, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $ints[$hit[1]] ??= (int) $hit[2];
            }
        }
        $lists = [];
        if (preg_match_all('/\$([A-Z][A-Z0-9_]*)\s*=\s*(?:\[|array\s*\()([\d\s,]*)(?:\]|\))\s*;/', $code, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                preg_match_all('/\d+/', $hit[2], $n);
                $lists[$hit[1]] = array_map('intval', $n[0]);
            }
        }
        return ['ints' => $ints, 'lists' => $lists];
    }
}
