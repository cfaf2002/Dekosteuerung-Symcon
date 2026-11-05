# Dekosteuerung für IP-Symcon

[![IP-Symcon ab 8.1](https://img.shields.io/badge/IP--Symcon-ab_8.1-0b6fb3.svg)](https://www.symcon.de)
[![Optimiert für Symcon 9.0](https://img.shields.io/badge/optimiert_f%C3%BCr-Symcon_9.0-0b6fb3.svg)](https://www.symcon.de/de/service/dokumentation/installation/migrationen/v81-v90-q1-2026/)
[![Modul-Version 1.2 (Build 3)](https://img.shields.io/badge/Modul--Version-1.2_(Build_3)-informational.svg)](library.json)
[![Tests](https://github.com/cfaf2002/Dekosteuerung-Symcon/actions/workflows/tests.yml/badge.svg)](https://github.com/cfaf2002/Dekosteuerung-Symcon/actions/workflows/tests.yml)
[![PHP 8.3 und 8.5](https://img.shields.io/badge/PHP-8.3_%7C_8.5-777bb4.svg?logo=php&logoColor=white)](https://www.php.net)
[![SDK: IPSModuleStrict](https://img.shields.io/badge/SDK-IPSModuleStrict-success.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/)
[![Variablen: Darstellungen](https://img.shields.io/badge/Variablen-Darstellungen-success.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/darstellungen/)
[![Kachel-Visualisierung: HTML-SDK](https://img.shields.io/badge/Kachel--Visualisierung-HTML--SDK-orange.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/html-sdk/)
[![Farbschema: Symcon-Design, Dunkel, Hell](https://img.shields.io/badge/Farbschema-Symcon--Design_%7C_Dunkel_%7C_Hell-blueviolet.svg)](STYLEGUIDE.md)
![Sprachen: Deutsch | Englisch](https://img.shields.io/badge/Sprachen-Deutsch_%7C_Englisch-blueviolet.svg)
[![Lizenz: MIT](https://img.shields.io/badge/Lizenz-MIT-green.svg)](LICENSE)
![Auslöser](https://img.shields.io/badge/Ausl%C3%B6ser-D%C3%A4mmerung_%7C_Sonnenuntergang-f2b544.svg)
![Ohne Tagesreset-Ereignis](https://img.shields.io/badge/Tagesreset-eingebaut-0f766e.svg)
![Umstieg](https://img.shields.io/badge/Umstieg-Skript_per_Knopfdruck_%C3%BCbernehmen-blue.svg)
![Ohne Cloud](https://img.shields.io/badge/Cloud-nicht_n%C3%B6tig-brightgreen.svg)

IP-Symcon-Modul für die automatische Deko-Beleuchtung: Es schaltet Lichterketten und andere Deko-Lampen bei Dämmerung oder Sonnenuntergang innerhalb eines Zeitfensters ein, solange jemand zu Hause ist, und wieder aus, wenn das Fenster endet, niemand mehr da ist oder es dauerhaft hell bleibt. Es ersetzt das bisherige Skript „Lichtsteuerung Dashboard 2.0“ mit Tagesreset-Skript, Debug-Kachel und elf Ereignissen durch eine Instanz mit eigener Kachel.

Autor: Armin Frohwerk · Lizenz: MIT

## Inhalt

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen und Technik](#2-voraussetzungen-und-technik)
3. [Installation](#3-installation)
4. [So entscheidet das Modul](#4-so-entscheidet-das-modul)
5. [Einstellungen](#5-einstellungen)
6. [Kachel](#6-kachel)
7. [Variablen und Darstellungen](#7-variablen-und-darstellungen)
8. [PHP-Befehle](#8-php-befehle)
9. [Umstieg vom bisherigen Skript](#9-umstieg-vom-bisherigen-skript)
10. [Sicherheit und Geschwindigkeit](#10-sicherheit-und-geschwindigkeit)
11. [Changelog](#11-changelog)
12. [Lizenz](#12-lizenz)

## 1. Funktionsumfang

- **Einschalten** im Zeitfenster (auch über Mitternacht), wenn es dunkler als die Einschaltgrenze ist **oder** der Sonnenuntergang erreicht ist (Versatz einstellbar).
- **Ausschalten** am Ende des Zeitfensters, bei Abwesenheit oder wenn es dauerhaft heller als die Ausschaltgrenze ist (z. B. 3 der letzten 5 Messwerte). Bei Abwesenheit und Helligkeit gilt eine **Mindestlaufzeit**.
- **Urlaub** sperrt das Einschalten, Ausschalten bleibt erlaubt.
- **Anwesenheit erzwingen**, wenn der Präsenzmelder sich irrt.
- **Einmal pro Abend** oder – mit „Mehrfachschaltungen erlauben“ – erneut nach jedem automatischen Ausschalten.
- **Sensorausfall:** Liefert der Helligkeitssensor zu lange keinen Wert, wird nur noch über den Sonnenuntergang eingeschaltet; die Kachel zeigt seit wann.
- **Handbetrieb wird respektiert:** Das Modul schaltet nur, wenn sich sein Ziel ändert. Wer abends von Hand ausschaltet, wird nicht bei der nächsten Prüfung überstimmt (abschaltbar).
- **Rückmeldung:** Fünf Sekunden nach dem Schalten wird geprüft, ob jede Lampe den Zustand erreicht hat; fehlende Rückmeldungen stehen in der Kachel und im Meldungsfenster.
- **Kein Tagesreset-Ereignis mehr:** Das Modul merkt sich den „Schaltabend“ selbst – bei einem Fenster über Mitternacht gehört 0:30 Uhr noch zum Vortag.
- **Übernahme** von Sensoren, Lampen, Zeiten, Grenzen und letztem Schaltzustand aus dem bisherigen Skript per Knopfdruck.

## 2. Voraussetzungen und Technik

- IP-Symcon ab 8.1, optimiert für 9.0 (PHP 8.3 und 8.5)
- Basisklasse `IPSModuleStrict`, alle Befehle typisiert
- Variablen mit Darstellungen (keine eigenen Profile; Zeiten nutzen das Systemprofil `~UnixTimestampTime`)
- Kachel über das HTML-SDK, Farbschema Symcon-Design, Dunkel oder Hell
- Lampen: Schaltvariablen (Boolean) mit Aktion, z. B. KNX-Schaltaktoren oder Homematic-IP-Steckdosen
- Optional: Helligkeitssensor (Lux), Präsenz- und Urlaubsvariable, Location-Instanz für den Sonnenuntergang

## 3. Installation

1. Kern Instanzen → Modules → hinzufügen: `https://github.com/cfaf2002/Dekosteuerung-Symcon`
2. Instanz „Dekosteuerung“ anlegen.
3. Lampen eintragen, Helligkeitssensor und Anwesenheit wählen – oder unter „Aus dem bisherigen Skript übernehmen“ das alte Skript auswählen (siehe [Umstieg](#9-umstieg-vom-bisherigen-skript)).
4. „Änderungen übernehmen“.

## 4. So entscheidet das Modul

Geprüft wird jede Minute und sofort, wenn sich Helligkeit, Anwesenheit, Urlaub, Sonnenuntergang oder eine Einstellung der Instanz ändert. Die erste zutreffende Zeile gilt:

| Lage | Ziel | Status |
|---|---|---|
| Automatik aus | nichts tun | Automatik aus |
| Außerhalb des Zeitfensters | **aus** (auch während der Mindestlaufzeit) | Außerhalb des Zeitfensters |
| Niemand anwesend | **aus** nach der Mindestlaufzeit | Niemand anwesend / Mindestlaufzeit läuft |
| Dauerhaft hell (nur bei funktionierendem Sensor) | **aus** nach der Mindestlaufzeit | Zu hell / Mindestlaufzeit läuft |
| Licht ist schon an | nichts tun | Licht an |
| Urlaub | nichts tun (kein Einschalten) | Urlaub |
| Weder dunkel genug noch Sonnenuntergang | nichts tun | Warte auf Dämmerung |
| Heute schon eingeschaltet, Mehrfach aus | nichts tun | Tageslimit erreicht |
| sonst | **an** | Licht an |

Geschaltet wird nur, wenn sich das Ziel ändert (Flanke). Mit „Ziel bei jeder Prüfung durchsetzen“ verhält sich das Modul wie das alte Skript und stellt von Hand geänderte Lampen bei jeder Prüfung zurück.

Beim ersten Lauf nach dem Anlegen wird ein Ziel „aus“ nur gemerkt, nicht geschaltet – das Modul schaltet also nicht mittags überraschend Lampen aus.

## 5. Einstellungen

| Bereich | Einstellung | Standard |
|---|---|---|
| Lampen | Liste der Schaltvariablen, optional mit eigenem Namen für die Kachel | – |
| Auslöser | Helligkeitssensor (Lux) | – |
| | Sensor gilt als ausgefallen ohne neuen Wert nach | 180 min |
| | Dauerhaft hell: Werte über der Ausschaltgrenze (von den letzten 5, ein Wert alle 5 min) | 3 |
| | Auch bei Sonnenuntergang einschalten | an |
| | Sonnenuntergang-Variable (leer = aus Kern Instanzen → Location), Versatz | leer, 0 min |
| Bedingungen | Anwesenheit (leer = immer anwesend) | – |
| | Urlaub | – |
| | Mindestlaufzeit | 30 min |
| | Ziel bei jeder Prüfung durchsetzen | aus |
| Kachel | Eigene Kachel, Farbschema der Kachel | an, Symcon-Design |

Zeiten, Grenzwerte und die Schalter „Anwesenheit erzwingen“ und „Mehrfachschaltungen erlauben“ sind Variablen der Instanz (siehe unten) und lassen sich direkt in der Visualisierung ändern. Die Ausschaltgrenze wird nie kleiner als die Einschaltgrenze; wer eine Grenze verschiebt, zieht die andere mit.

## 6. Kachel

- Kopfzeile mit Status, Begründung (bei „Licht an“: warum und seit wann) und Schalter für alle Lampen
- Lampen einzeln mit „x von y an“; lange Namen werden gekürzt (voller Name beim Darüberfahren)
- Helligkeit mit Trend (↗ ↘ →) und Balken mit Ein- und Ausschaltgrenze, Sonnenuntergang mit Countdown, Zeitfenster, Restzeit der Mindestlaufzeit
- Hinweise nur, wenn sie zutreffen: niemand anwesend, Anwesenheit erzwungen, Urlaub, Tageslimit, dauerhaft hell
- Schalter für Automatik, Anwesenheit erzwingen und Mehrfach
- Kleine Kacheln zeigen nur Status, Werte und Schalter
- Hinweise bei Sensorausfall und fehlender Rückmeldung einer Lampe

Die Kachel bekommt nur Daten (JSON) und setzt alle Texte per `textContent`. Der Countdown läuft nur, solange die Kachel sichtbar ist.

## 7. Variablen und Darstellungen

| Ident | Name | Typ | Darstellung | bedienbar |
|---|---|---|---|---|
| `Automatic` | Automatik | Boolean | Schalter | ja |
| `Lights` | Deko-Licht | Boolean | Schalter (schaltet alle Lampen) | ja |
| `Status` | Status | Integer | Aufzählung | – |
| `Reason` | Letzte Entscheidung | String | Wertanzeige | – |
| `TimeOn` | Einschaltzeitpunkt | Integer | `~UnixTimestampTime` | ja |
| `TimeOff` | Ausschaltzeitpunkt | Integer | `~UnixTimestampTime` | ja |
| `LuxOn` | Dämmerung Einschaltgrenze | Integer | Schieberegler (lx) | ja |
| `LuxOff` | Dämmerung Ausschaltgrenze | Integer | Schieberegler (lx) | ja |
| `ForcePresence` | Anwesenheit erzwingen | Boolean | Schalter | ja |
| `AllowMultiple` | Mehrfachschaltungen erlauben | Boolean | Schalter | ja |

Status-Werte: 0 Automatik aus · 1 Warte auf Dämmerung · 2 Licht an · 3 Außerhalb des Zeitfensters · 4 Niemand anwesend · 5 Urlaub · 6 Tageslimit erreicht · 7 Zu hell · 8 Mindestlaufzeit läuft

## 8. PHP-Befehle

```php
DEKO_Evaluate(int $InstanceID): bool                 // sofort prüfen
DEKO_SetLights(int $InstanceID, bool $On): bool      // alle Lampen von Hand schalten
DEKO_SetAutomatic(int $InstanceID, bool $Active): void
```

## 9. Umstieg vom bisherigen Skript

1. Instanz anlegen, unter „Aus dem bisherigen Skript übernehmen“ das Skript „Lichtsteuerung Dashboard 2.0“ wählen und „Einstellungen übernehmen“ klicken.
   - Ins Formular: Helligkeitssensor (`$ID_DAEMMERUNG`), Anwesenheit (`$ID_ANWESEND`), Urlaub (`$ID_URLAUB`), Sonnenuntergang (`$ID_SONNENUNTERGANG_HEUTE`), Lampen (`$LICHT_EIN_IDS` und `$LICHT_AUS_IDS`).
   - Sofort in die Variablen der Instanz: Ein- und Ausschaltzeit, beide Grenzen, Anwesenheit erzwingen, Mehrfachschaltungen.
   - Letzter Schaltzustand (`Licht EIN`/`Licht AUS`), Einschaltzeitpunkt und „heute geschaltet“ – die Automatik läuft nahtlos weiter.
2. „Änderungen übernehmen“.
3. Altes Skript, das Tagesreset-Skript und alle zugehörigen Ereignisse deaktivieren (nach ein paar Tagen löschen): die Auslöser auf Anwesenheit, Urlaub, Lux, Anwesenheit erzwingen, Ein-/Ausschaltzeitpunkt, beide Grenzen, Mehrfachschaltungen und Tagesrücksetzer sowie das zyklische Ereignis alle 5 Minuten. Das Modul überwacht diese Werte selbst.
4. Die Hilfsvariablen des Skripts (Kachel-HTML, Trendhistorie, Sensorfehler seit, letzte Aktualisierung, letzter Tagesreset …) werden nicht mehr gebraucht.

## 10. Sicherheit und Geschwindigkeit

- Das bisherige Skript wird bei der Übernahme nur gelesen, nie ausgeführt; Kommentare werden vorher entfernt.
- Keine Netzwerkzugriffe, keine Zugangsdaten.
- Variablen werden nur bei Änderung geschrieben, die Kachel nur bei geänderten Daten aktualisiert (Helligkeit auf 10 lx gerundet).
- Kein `IPS_Sleep`: die Rückmeldung der Lampen prüft ein Timer nach fünf Sekunden, die Bewertung blockiert nicht.
- Kacheldaten werden mit `JSON_HEX_*` eingebettet, Texte in der Kachel nur per `textContent` gesetzt.

## 11. Changelog

| Version | Build | Datum | Beschreibung |
|---|---|---|---|
| 1.2 | 3 | 05.11.2026 | Kachel kompakter: Lichtschalter in der Kopfzeile, „x von y an“, Werte in einer flachen Reihe, nur zutreffende Hinweise, kurze Schalter; bei „Licht an“ Grund und Uhrzeit; passt sich kleinen und schmalen Kacheln an |
| 1.1 | 2 | 05.11.2026 | Kachel: Platz für den Instanznamen von Symcon, Begründung nicht doppelt, außerhalb des Zeitfensters „Einschalten frühestens ab …“; Lampenliste mit eigenen Namen für die Kachel |
| 1.0 | 1 | 05.11.2026 | Erste Version: Ablösung des Skripts „Lichtsteuerung Dashboard 2.0“ samt Tagesreset und Ereignissen; Flankensteuerung, eingebauter Tagesreset, Rückmeldeprüfung, Kachel, Übernahme aus dem Skript |

## 12. Lizenz

MIT – siehe [LICENSE](LICENSE). Copyright (c) 2026 Armin Frohwerk
