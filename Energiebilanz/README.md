# Energiebilanz (Kachel)

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Modul Version](https://img.shields.io/badge/Modul_Version-0.20--beta-blue)
![Symcon Version](https://img.shields.io/badge/Symcon_Version-7.0%2B-blue)
![License](https://img.shields.io/badge/License-PolyForm_Noncommercial_1.0.0-lightgrey)
[![Check Style](https://github.com/DG65/NRGPrognose/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/NRGPrognose/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Me-blue?logo=paypal)](https://paypal.me/DietmarGureth)

Kombinierte **Visualisierungskachel**, die PV-Erzeugung und Verbrauch gemeinsam (oder einzeln) über
bis zu 5 Tage darstellt – der EMS-Blick: Erzeugung gegen Verbrauch, die Lücke ist
Netzbezug/Einspeisung. Prefix: `EFTILE`.

## Funktionsweise

Die Kachel liest die Prognosevariablen von **[Lastprognose](../Lastprognose)** und
**[PV-Prognose](../PVPrognose)** und zeichnet:

- **Prognose (Soll):** P50-Linien mit P10–P90-Unsicherheitsband
- **Ist-Werte** (optional): Momentanwert in der Legende, Punkt auf der Jetzt-Linie, gemessener
  Tagesverlauf als gestrichelte Linie und Ist-kWh unter den Soll-Werten
- **Gestern** (optional): gemessene Kurve des Vortags plus – sobald ein Prognose-Snapshot vom
  Vortag existiert – das damalige Soll (Treffer-Kontrolle)
- **Tagesenergie** je Tag (kWh) unter dem Diagramm

Beim Überfahren/Antippen werden Werte und Saldo zur Uhrzeit angezeigt.

## Voraussetzungen

- IP-Symcon ab **7.0** (HTML-Tile-Visualisierung)
- Mindestens eine der beiden Prognose-Instanzen (Lastprognose und/oder PV-Prognose)
- Für Ist-Werte: je eine archivierte Leistungsvariable (Einheit W/kW automatisch erkannt)
- Internet auf dem Anzeigegerät (die Diagramm-Bibliothek wird per CDN geladen)

## Einrichtung

| Bereich | Bedeutung |
|---|---|
| **Quellen** | Werden automatisch erkannt (je eine PV- und Lastprognose-Instanz); nur bei mehreren Instanzen manuell wählen. Jede Reihe per Schalter oder Legenden-Klick ausblendbar. |
| **Ist-Werte** | Momentane Leistungsvariablen für PV und Verbrauch; optional der gemessene Tagesverlauf als Linie. |
| **Gestern** | Vortag mit Ist-Kurve und (falls vorhanden) Snapshot-Soll. |
| **Diagramm-Engine** | **ECharts** (quelloffen, auch kommerziell kostenlos) oder **Highcharts** (nur privat/nicht-kommerziell kostenlos). |
| **Darstellung** | Diagrammhöhe, Linienstärke, Glättung, Band-Transparenz, Gitter, Y-Achse, Farben, Schriftart/-größe. |

## Als eigenständige Webseite (IPSView / Popup)

IPSView rendert HTML-SDK-Kacheln nicht direkt. Das Modul stellt die Kachel daher zusätzlich als
eigenständige Seite über einen **WebHook** bereit:

```
http://<IPS-IP>:3777/hook/energiebilanz<InstanzID>
```

Auto-Aktualisierung alle 30 s (`?json=1` liefert nur die Daten). In IPSView ein **WebView-Element**
auf eine Popup-Seite legen und diese URL eintragen. Die konkrete URL steht auch in der Instanz-Doku.

> **Hinweis:** WebHooks sind im lokalen Netz ohne Anmeldung erreichbar.

**Empfohlene Popup-Größe:** ca. **1000 × 470 px** bei 3 Tagen (Höhe = eingestellte Diagrammhöhe
+ ~100 px für Legende und Tagesstreifen).

## Hinweis: Hintergrundfarbe

Wird eine Hintergrundfarbe gesetzt, gilt sie für die gesamte Kachel, und die Textfarben richten sich
nach deren Helligkeit. Ohne gesetzte Farbe (transparent) folgt die Kachel dem Hell-/Dunkelmodus des
Anzeigegeräts bzw. dem IPS-Theme.

## Öffentliche Funktionen

```php
// Style-freier Datenzugriff für eine eigene Visualisierung (z. B. NRGDashboard) — liefert
// dasselbe days[]-Format wie die eigene Kachel intern nutzt, aber IMMER vollständig (voller
// 5-Tage-Horizont, "Gestern", Ist-Überlagerung), unabhängig von den Anzeige-Einstellungen
// dieser Instanz. Der Konsument entscheidet selbst, was er zeigt.
$data = EFTILE_GetDaysData(int $InstanzID);
// => ['contractVersion'=>'1.0', 'hasData'=>.., 'message'=>.., 'actualPV'=>.., 'actualLoad'=>..,
//     'days'=>[{label, pv:{p10,p50,p90,kwh}|null, load:{...}|null, pvMeas, loMeas, pvKwhIst, loKwhIst}, ...]]
```

Teil der **[EnergiePrognose-Suite](https://github.com/DG65/NRGPrognose)**.

> **Teil des NRG-Stack** — dem Energie-Modulverbund von DG65 (Messen · Wissen · Entscheiden · Steuern · Zeigen). Welche Modulstände zusammen getestet sind, listet das [Manifest](https://github.com/DG65/NRGEMS/blob/main/SUITE.md).
