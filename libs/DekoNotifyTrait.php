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
 * Push-Nachrichten über die Kachel-Visualisierung (ersatzweise WebFront).
 */
trait DekoNotifyTrait
{
    protected function Notify(string $title, string $text): bool
    {
        $target = $this->NotifyTarget();
        if ($target === 0) {
            $this->SendDebug('Benachrichtigung', 'Keine Visualisierung gefunden.', 0);
            return false;
        }
        $title = mb_substr($title, 0, 32);
        $text = mb_substr($text, 0, 256);
        $prefix = $this->ModulePrefix($target);
        $ok = false;

        try {
            if ($prefix === 'VISU' && function_exists('VISU_PostNotification')) {
                // Antippen öffnet die Instanz – geht nur, wenn sie in der Visualisierung liegt, sonst ohne Ziel
                $ok = @VISU_PostNotification($target, $title, $text, 'Warning', $this->InstanceID) !== false;
                if (!$ok) {
                    $ok = @VISU_PostNotification($target, $title, $text, 'Warning', 0) !== false;
                }
            } elseif ($prefix === 'WFC' && function_exists('WFC_PushNotification')) {
                $ok = @WFC_PushNotification($target, $title, $text, '', $this->InstanceID) !== false;
                if (!$ok) {
                    $ok = @WFC_PushNotification($target, $title, $text, '', 0) !== false;
                }
            }
        } catch (Throwable $e) {
            $this->SendDebug('Benachrichtigung', $e->getMessage(), 0);
        }
        $this->SendDebug('Benachrichtigung', ($ok ? 'gesendet: ' : 'fehlgeschlagen: ') . $title . ' – ' . $text, 0);
        return $ok;
    }

    private function NotifyTarget(): int
    {
        $target = $this->ReadPropertyInteger('NotifyTarget');
        if ($target > 0 && IPS_InstanceExists($target)) {
            return $target;
        }
        $fallback = 0;
        foreach (IPS_GetInstanceList() as $id) {
            $prefix = $this->ModulePrefix($id);
            if ($prefix === 'VISU') {
                return $id;
            }
            if ($prefix === 'WFC' && $fallback === 0) {
                $fallback = $id;
            }
        }
        return $fallback;
    }

    private function ModulePrefix(int $instanceID): string
    {
        try {
            $module = IPS_GetModule(IPS_GetInstance($instanceID)['ModuleInfo']['ModuleID']);
            return (string) ($module['Prefix'] ?? '');
        } catch (Throwable $e) {
            return '';
        }
    }
}
