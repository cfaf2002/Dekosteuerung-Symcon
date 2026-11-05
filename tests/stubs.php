<?php

declare(strict_types=1);

/**
 * Ladetest mit den offiziellen Symcon-Stubs (https://github.com/symcon/SymconStubs).
 *
 * Lädt die Bibliothek wie Symcon über den Modul-Loader, legt die Instanz an,
 * verbindet Lampen und Sensoren, spielt Einschalten, Handbetrieb, Abwesenheit
 * und die Übernahme aus dem bisherigen Skript durch und prüft Kachel und Formular.
 *
 * Aufruf: php tests/stubs.php <Pfad zu SymconStubs>
 *
 * SPDX-License-Identifier: MIT
 */

$stubs = $argv[1] ?? __DIR__ . '/../../SymconStubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, 'SymconStubs nicht gefunden: ' . $stubs . PHP_EOL);
    exit(2);
}

// Veraltete Stub-Aufrufe und Ident-Hinweise der Stubs ausblenden
set_error_handler(static function (int $no, string $str): bool {
    return $no === E_DEPRECATED || $no === E_USER_DEPRECATED || str_contains($str, 'could not be found');
});

// Die Stubs verlangen für Timer eine Testuhr (getTime). In eine Kopie die normale Uhrzeit eintragen.
$copy = sys_get_temp_dir() . '/deko-stubs-' . getmypid();
@mkdir($copy);
foreach (glob($stubs . '/*.php') as $file) {
    $code = (string) file_get_contents($file);
    if (basename($file) === 'ModuleStrictStubs.php') {
        $code = str_replace(
            "throw new Exception('getTime needs to be implemented by module under test');\n    }\n}",
            "return time();\n    }\n}",
            $code
        );
    }
    file_put_contents($copy . '/' . basename($file), $code);
}
register_shutdown_function(static function () use ($copy): void {
    array_map('unlink', glob($copy . '/*.php'));
    @rmdir($copy);
});

require $copy . '/autoload.php';

\IPS\Kernel::reset();
if (!IPS_VariableProfileExists('~UnixTimestampTime')) {
    IPS_CreateVariableProfile('UnixTimestampTime', 1); // nur ältere Stubs ohne Systemprofile
}
\IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');

$failed = 0;
function ok(bool $condition, string $message): void
{
    global $failed;
    echo ($condition ? '  ✓ ' : '  ✗ ') . $message . PHP_EOL;
    if (!$condition) {
        $failed++;
    }
}

function variable(int $type, mixed $value, int $action = 0, string $name = ''): int
{
    $id = IPS_CreateVariable($type);
    SetValue($id, $value);
    if ($action > 0) {
        IPS_SetVariableCustomAction($id, $action);
    }
    if ($name !== '') {
        IPS_SetName($id, $name);
    }
    return $id;
}

function own(int $instance, string $ident): int
{
    return (int) IPS_GetObjectIDByIdent($ident, $instance);
}

echo 'Dekosteuerung' . PHP_EOL;
try {
    $id = IPS_CreateInstance('{83B0BC87-906B-44E2-AED2-15BEA4F8A383}');
    ok($id > 0, 'Instanz angelegt');
    $form = json_decode(IPS_GetConfigurationForm($id), true);
    ok(is_array($form) && isset($form['elements']), 'Formular ist gültiges JSON');
    ok(IPS_GetInstance($id)['InstanceStatus'] === 200, 'Ohne Lampen Status 200');
    ok(str_contains(DEKO_GetVisualizationTile($id), 'window.handleMessage'), 'Kachel-HTML auch ohne Einrichtung');
    foreach (['Automatic', 'Lights', 'Status', 'Reason', 'TimeOn', 'TimeOff', 'LuxOn', 'LuxOff', 'ForcePresence', 'AllowMultiple'] as $ident) {
        ok(@IPS_GetObjectIDByIdent($ident, $id) !== false, 'Variable ' . $ident);
    }
    ok(GetValue(own($id, 'LuxOn')) === 800 && GetValue(own($id, 'LuxOff')) === 2500, 'Startwerte der Grenzen');

    // Aktionsskript wie bei einem KNX-Aktor: setzt einfach den Wert
    $script = IPS_CreateScript(0);
    IPS_SetScriptContent($script, '<?php SetValue($_IPS["VARIABLE"], $_IPS["VALUE"]);');
    $lamp1 = variable(0, false, $script, 'Lichterkette');
    $lamp2 = variable(0, false, $script, 'Status');
    $lux = variable(2, 300.0);
    $presence = variable(0, true);
    $vacation = variable(0, false);

    IPS_SetProperty($id, 'Lights', json_encode([['VariableID' => $lamp1, 'Name' => ''], ['VariableID' => $lamp2, 'Name' => 'Fensterstern']]));
    IPS_SetProperty($id, 'BrightnessVariableID', $lux);
    IPS_SetProperty($id, 'PresenceVariableID', $presence);
    IPS_SetProperty($id, 'VacationVariableID', $vacation);
    IPS_SetProperty($id, 'UseSunset', false);
    IPS_ApplyChanges($id);
    ok(IPS_GetInstance($id)['InstanceStatus'] === 102, 'Eingerichtet: Status 102');

    // Zeitfenster um die aktuelle Uhrzeit legen: dunkel und anwesend → an
    RequestAction(own($id, 'TimeOn'), time() - 3600);
    RequestAction(own($id, 'TimeOff'), time() + 3600);
    ok(GetValue($lamp1) === true && GetValue($lamp2) === true, 'Dunkel im Zeitfenster: Lampen eingeschaltet');
    ok(GetValue(own($id, 'Lights')) === true, 'Variable Deko-Licht zeigt an');
    ok(GetValue(own($id, 'Status')) === 2, 'Status: Licht an');

    // Von Hand aus: die Automatik schaltet nicht sofort wieder ein (Flanke)
    SetValue($lamp1, false);
    DEKO_Evaluate($id);
    ok(GetValue($lamp1) === false, 'Handbetrieb wird nicht überstimmt');

    // Abwesend: Mindestlaufzeit läuft, danach aus
    SetValue($presence, false);
    DEKO_Evaluate($id);
    ok(GetValue($lamp2) === true && GetValue(own($id, 'Status')) === 8, 'Abwesend während Mindestlaufzeit: bleibt an');
    IPS_SetProperty($id, 'MinOnMinutes', 0);
    IPS_ApplyChanges($id);
    ok(GetValue($lamp2) === false && GetValue(own($id, 'Status')) === 4, 'Abwesend ohne Mindestlaufzeit: aus');

    // Wieder da: heute schon geschaltet → Tageslimit, mit Mehrfach → an
    SetValue($presence, true);
    DEKO_Evaluate($id);
    ok(GetValue($lamp2) === false && GetValue(own($id, 'Status')) === 6, 'Wieder anwesend: Tageslimit');
    RequestAction(own($id, 'AllowMultiple'), true);
    ok(GetValue($lamp2) === true, 'Mehrfach erlaubt: wieder an');

    // Grenzwerte bleiben in sinnvoller Reihenfolge
    RequestAction(own($id, 'LuxOn'), 3000);
    ok(GetValue(own($id, 'LuxOff')) === 3000, 'Ausschaltgrenze wird nachgezogen');

    // Handschalter und Befehle
    ok(DEKO_SetLights($id, false) === true && GetValue($lamp1) === false && GetValue($lamp2) === false, 'DEKO_SetLights aus');
    RequestAction(own($id, 'Lights'), true);
    ok(GetValue($lamp1) === true, 'Variable Deko-Licht schaltet alle Lampen');
    DEKO_SetAutomatic($id, false);
    ok(GetValue(own($id, 'Status')) === 0, 'DEKO_SetAutomatic aus');
    DEKO_SetAutomatic($id, true);

    // Version 2.0: Dimmer und eigene Ausschaltzeit je Lampe
    $dimmer = variable(1, 0, $script, 'Sterne');
    DEKO_SetLights($id, false);
    IPS_SetProperty($id, 'Lights', json_encode([
        ['VariableID' => $lamp1, 'Name' => '', 'OffTime' => date('H:i', time() - 60), 'Morning' => true, 'Level' => 100],
        ['VariableID' => $lamp2, 'Name' => 'Fensterstern', 'OffTime' => '', 'Morning' => true, 'Level' => 100],
        ['VariableID' => $dimmer, 'Name' => '', 'OffTime' => '', 'Morning' => false, 'Level' => 80],
    ]));
    IPS_SetProperty($id, 'MinOnMinutes', 0);
    IPS_ApplyChanges($id);
    RequestAction(own($id, 'TimeOn'), time() - 7200);
    // Automatik neu starten: alle Lampen aus gilt als letztes Ziel, dann wird es dunkel
    DEKO_SetAutomatic($id, false);
    DEKO_SetAutomatic($id, true);
    SetValue($lux, 300.0);
    DEKO_Evaluate($id);
    ok(GetValue($dimmer) === 80, 'Dimmer mit Dimmwert eingeschaltet');
    ok(GetValue($lamp2) === true, 'Lampe ohne eigene Zeit an');
    ok(GetValue($lamp1) === false, 'Lampe mit abgelaufener eigener Ausschaltzeit bleibt aus');

    // Wochenende: eigene Variable für die Ausschaltzeit
    IPS_SetProperty($id, 'WeekendMode', 1);
    IPS_ApplyChanges($id);
    ok(@IPS_GetObjectIDByIdent('TimeOffWeekend', $id) !== false, 'Variable Ausschaltzeitpunkt Wochenende');
    ok(date('H:i', GetValue(own($id, 'TimeOffWeekend'))) === '00:30', 'Wochenende startet mit 00:30');

    // Morgenfenster: ungültige Zeiten melden
    IPS_SetProperty($id, 'MorningEnabled', true);
    IPS_SetProperty($id, 'MorningFrom', '{"hour":8,"minute":0,"second":0}');
    IPS_SetProperty($id, 'MorningTo', '{"hour":6,"minute":0,"second":0}');
    IPS_ApplyChanges($id);
    ok(IPS_GetInstance($id)['InstanceStatus'] === 205, 'Morgenfenster mit Ende vor Beginn: Status 205');
    IPS_SetProperty($id, 'MorningEnabled', false);
    IPS_ApplyChanges($id);

    // Saison: heute außerhalb → aus
    $md = (int) date('n') * 100 + (int) date('j');
    $other = $md <= 615 ? [7, 1, 8, 1] : [1, 2, 2, 2];
    IPS_SetProperty($id, 'SeasonMode', 1);
    IPS_SetProperty($id, 'SeasonStartMonth', $other[0]);
    IPS_SetProperty($id, 'SeasonStartDay', $other[1]);
    IPS_SetProperty($id, 'SeasonEndMonth', $other[2]);
    IPS_SetProperty($id, 'SeasonEndDay', $other[3]);
    IPS_ApplyChanges($id);
    ok(GetValue(own($id, 'Status')) === 9, 'Außerhalb der Saison: Status 9');
    ok(GetValue($lamp2) === false && GetValue($dimmer) === 0, 'Außerhalb der Saison: Lampen aus');
    IPS_SetProperty($id, 'SeasonMode', 0);
    IPS_ApplyChanges($id);

    // Urlaubssimulation: abwesend, aber Urlaub → schaltet mit Zufallsversatz ein
    SetValue($presence, false);
    SetValue($vacation, true);
    IPS_SetProperty($id, 'VacationMode', 1);
    IPS_SetProperty($id, 'RandomMinutes', 120);
    IPS_ApplyChanges($id);
    $st = GetValue(own($id, 'Status'));
    ok($st === 10 || GetValue($lamp2) === true, 'Urlaubssimulation: Einschalten geplant oder erfolgt');
    IPS_SetProperty($id, 'RandomMinutes', 0);
    IPS_ApplyChanges($id);
    ok(GetValue($lamp2) === true, 'Urlaubssimulation ohne Versatz: sofort an');
    SetValue($vacation, false);
    SetValue($presence, true);
    IPS_SetProperty($id, 'VacationMode', 0);
    IPS_ApplyChanges($id);

    // Version 2.2: „Heute länger“ öffnet einen gerade beendeten Abend wieder
    ok(@IPS_GetObjectIDByIdent('Extend', $id) !== false, 'Variable Heute länger');
    RequestAction(own($id, 'AllowMultiple'), false);
    RequestAction(own($id, 'TimeOff'), time() - 600);
    ok(GetValue($lamp2) === false, 'Abend vorbei: Lampen aus');
    RequestAction(own($id, 'Extend'), 60);
    ok(GetValue(own($id, 'Extend')) === 60 && GetValue($lamp2) === true, '+1 h: Abend wieder offen, Lampen an');
    RequestAction(own($id, 'Extend'), 0);
    ok(GetValue($lamp2) === false, 'Zurück auf normal: Lampen aus');
    ok(DEKO_ExtendTonight($id, 120) === true && GetValue($lamp2) === true, 'DEKO_ExtendTonight');
    DEKO_ExtendTonight($id, 0);
    RequestAction(own($id, 'TimeOff'), time() + 3600);

    // Sondertage: ungültige Zeile melden
    IPS_SetProperty($id, 'SpecialDays', json_encode([['Name' => 'Test', 'Day' => 31, 'Month' => 2, 'OffTime' => '01:00']]));
    IPS_ApplyChanges($id);
    ok(IPS_GetInstance($id)['InstanceStatus'] === 207, 'Sondertag mit 31.02.: Status 207');
    IPS_SetProperty($id, 'SpecialDays', json_encode([['Name' => 'Heute', 'Day' => (int) date('j'), 'Month' => (int) date('n'), 'OffTime' => '23:59']]));
    IPS_ApplyChanges($id);
    ok(IPS_GetInstance($id)['InstanceStatus'] === 102, 'Gültiger Sondertag: Status 102');
    ok(str_contains(DEKO_GetVisualizationTile($id), '"specialName":"Heute"') || (int) date('G') < 1, 'Sondertag in der Kachel');

    // Benachrichtigung ohne Visualisierung: kein Absturz
    ob_start();
    IPS_RequestAction($id, 'TestNotify', 0);
    ok(ob_get_clean() !== '', 'Testnachricht ohne Visualisierung meldet sich');

    // Kachel
    $tile = DEKO_GetVisualizationTile($id);
    ok(!str_contains($tile, '/*INITIAL_DATA*/'), 'Kachel mit Startdaten');
    ok(str_contains($tile, 'Lichterkette'), 'Kachel kennt die Lampennamen');
    ok(str_contains($tile, 'Fensterstern'), 'Eigener Name aus der Lampenliste');

    // Übernahme aus dem bisherigen Skript
    $old = IPS_CreateScript(0);
    $limitOn = variable(1, 900);
    $limitOff = variable(1, 2600);
    $last = variable(3, 'Licht EIN');
    IPS_SetScriptContent($old, "<?php\n// \$ID_DAEMMERUNG = 1;\n\$ID_DAEMMERUNG = $lux;\n\$ID_ANWESEND = $presence;\n\$ID_URLAUB = $vacation;\n"
        . "\$ID_DAEMMERUNG_EIN_GRENZE = $limitOn;\n\$ID_DAEMMERUNG_AUS_GRENZE = $limitOff;\n\$ID_LETZTE_AKTION = $last;\n"
        . "\$LICHT_AUS_IDS = [\n    $lamp1,\n    $lamp2\n];\n\$LICHT_EIN_IDS = [$lamp1, $lamp2];\n");
    ob_start();
    IPS_RequestAction($id, 'ImportScript', $old);
    $out = (string) ob_get_clean();
    ok(str_contains($out, 'Taken over') || str_contains($out, 'Übernommen'), 'Übernahme aus dem Skript');
    ok(str_contains($out, '2 '), 'Zwei Lampen erkannt');
    ok(GetValue(own($id, 'LuxOn')) === 900 && GetValue(own($id, 'LuxOff')) === 2600, 'Grenzwerte übernommen');

    // Nicht aktiv
    IPS_SetProperty($id, 'Active', false);
    IPS_ApplyChanges($id);
    ok(IPS_GetInstance($id)['InstanceStatus'] === 104, 'Nicht aktiv: Status 104');

    // Befehle haben Typen (IPSModuleStrict verlangt das)
    $class = new ReflectionClass('Dekosteuerung');
    $untyped = [];
    foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
        if ($m->getDeclaringClass()->getName() !== 'Dekosteuerung') {
            continue;
        }
        if (!$m->hasReturnType()) {
            $untyped[] = $m->getName();
        }
        foreach ($m->getParameters() as $p) {
            if (!$p->hasType()) {
                $untyped[] = $m->getName() . '($' . $p->getName() . ')';
            }
        }
    }
    ok($untyped === [], 'Öffentliche Funktionen typisiert' . ($untyped ? ': ' . implode(', ', $untyped) : ''));
} catch (Throwable $e) {
    ok(false, get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}

echo PHP_EOL . ($failed === 0 ? 'Ladetest bestanden.' : $failed . ' Prüfung(en) fehlgeschlagen.') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
