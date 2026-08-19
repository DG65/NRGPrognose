# Lastprognose

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Modul Version](https://img.shields.io/badge/Modul_Version-0.20--beta-blue)
![Symcon Version](https://img.shields.io/badge/Symcon_Version-7.0%2B-blue)
![License](https://img.shields.io/badge/License-PolyForm_Noncommercial_1.0.0-lightgrey)
[![PayPal](https://img.shields.io/badge/PayPal-Me-blue?logo=paypal)](https://paypal.me/DietmarGureth)

Erstellt aus deinen IP-Symcon-Archivdaten eine **1–3-Tage-Verbrauchsprognose** und liefert sie als
JSON-Profil (P10/P50/P90 je Zeitslot) zur direkten Nutzung durch ein EMS. Prefix der Funktionen: `LFC`.

## Funktionsweise

Statt den Verbrauch in starre Gruppen (Tageszeit × Jahreszeit × Wochentag …) zu zwängen, sucht das
Modul über ein **Ähnliche-Tage-Verfahren (k-NN)** die *k* ähnlichsten Vergangenheitstage und
verdichtet deren gemessene Tagesprofile zu einer Prognose mit Unsicherheitsband:

- **P50** – wahrscheinlichster Verlauf (Median)
- **P10 / P90** – Unter-/Obergrenze (10 % bzw. 90 % der ähnlichen Tage lagen darunter)

Die Ähnlichkeit eines Tages wird aus **Tagtyp** (Werktag/Sa/So+Feiertag), **Tageslänge**,
**Heizgrad** (aus der Außentemperatur) und **Anwesenheit** bestimmt.

## Voraussetzungen

- IP-Symcon ab **7.0**
- Eine **archivierte Leistungsvariable** des Hausverbrauchs (Watt oder Kilowatt – wird automatisch
  erkannt). Kein kWh-Zähler.
- Optional, aber empfohlen: Außentemperatur (archiviert) und ein Anwesenheitssignal.

## Einrichtung

| Bereich | Bedeutung |
|---|---|
| **Hausverbrauch** | Pflicht: archivierte Leistungsvariable. Einheit W/kW automatisch oder manuell. |
| **Abzugsliste** | Steuerbare Lasten (Wallbox, Batterie-Ladung, WP), die **vom Hausverbrauch abgezogen** werden – so lernt das Modul die planbare **Grundlast**. Preisgesteuertes Laden plant das EMS selbst. **Diese Variablen müssen archiviert sein**, sonst können sie nicht abgezogen werden (der Status meldet nicht archivierte Variablen). |
| **Wärmepumpe / Klima** | Optionale eigene Prognose je Gerät über Temperaturregression: Heizen, Kühlen oder beides (V-Kurve, z. B. Luft-Luft-WP). |
| **Anwesenheit** | Bool/0..1 (z. B. Geofencing). Bei einer *Abwesenheits*-Variable die Logik invertieren. |
| **Temperaturvorhersage** | Auto (OpenWeatherData-Instanz), eigene Tagesmittel-Variablen, Ident-Muster oder – ohne Quelle – saisonales Normal aus dem Archiv. Bei **mehreren** OpenWeatherData-Instanzen lässt sich die relevante explizit wählen (leer = erste automatisch). |
| **Auflösung** | 60 / 30 / 15 Minuten. 60 min ist robust; feinere Raster brauchen entsprechend feine Archivierung. |
| **Bundesland** | Für regionale Feiertage. |

## Statusvariablen

| Ident | Beschreibung |
|---|---|
| `LFC_Today` / `LFC_Tomorrow` / `LFC_DayAfter` | Prognoseprofil als JSON (`p10/p50/p90/mean` in W je Slot, `kwh`, `slots`, `resolution`) |
| `LFC_kWhToday` / `…Tomorrow` / `…DayAfter` | Tagesenergie der Prognose (kWh) |
| `LFC_WPkWhToday` / `…Tomorrow` / `…DayAfter` | Separater WP-/Klima-Anteil (kWh), falls konfiguriert |
| `LFC_ErrorMAPE` | Mittlerer Betragsfehler der letzten Tage (%) |
| `LFC_Accuracy` | Prognosegüte als Text: Tagesanzahl, Bias, \|Ø-Fehler\| |
| `LFC_Status` / `LFC_LastUpdate` | Status und Zeitpunkt der letzten Berechnung |

## Unsicherheitsband aus echten Prognosefehlern (optional)

Standardmäßig kommt das P10/P90-Band aus der **Streuung der ähnlichen Tage** — dadurch ist es oft
deutlich breiter als der tatsächliche Prognosefehler. Alternativ lässt sich das Band aus den
**gemessenen Abweichungen** der letzten Tage bilden (gespeicherter Snapshot gegen Ist); dann bedeutet
P90 wirklich „in 90 % der Fälle lag der reale Wert darunter".

| Modus | Wirkung |
|---|---|
| **Streuung ähnlicher Tage** (Standard) | wie bisher |
| **Nur Band** | P10/P90 aus den realen Fehlern; Prognosewert (P50) und kWh bleiben unverändert |
| **Band + Pegelkorrektur** | zusätzlich wird ein systematischer Fehler (Bias) nachgezogen |

Greift erst ab **3 auswertbaren Tagen** (und genügend Messpunkten) — vorher bleibt automatisch der
Standard aktiv. Die wirksamen Faktoren stehen in der Variable *Prognosegüte*.

## Prognosegüte (Soll vs. Ist)

Bei jeder Neuberechnung wird die frühere Day-Ahead-Prognose vergangener Tage (gespeicherte
Snapshots) mit dem gemessenen Ist verglichen. **Bias** zeigt systematische Über-/Unterschätzung
(+ = überschätzt), **\|Ø-Fehler\|** den mittleren Betrag. Die Werte füllen sich nach einigen Tagen
Laufzeit.

**Sondereffekte werden ausgeschlossen** (NRG-Stack, sobald ein EMS mit `EMS_GetSpecialEvents`
installiert ist): Tage mit externem Regeleingriff (§14a-Dimmung, Tibber-Regelenergie,
Direktvermarktung, EMS-Schutzabschaltung) fließen weder in Bias/\|Ø-Fehler\| noch in die
Residuen-Quantile ein — sie würden sonst eine gute Prognose fälschlich als Fehler werten. Ohne EMS
(oder ohne diese Funktion) bleibt das Verhalten unverändert.

## Öffentliche Funktionen

```php
// Prognose eines Tages holen: $offset 0=heute, 1=morgen, 2=übermorgen
$fc = LFC_GetForecast(int $InstanzID, int $offset); // array mit p10/p50/p90/mean/kwh …

// Gespeicherte Prognose (Soll) eines vergangenen Tages ('Y-m-d')
$snap = LFC_GetSnapshot(int $InstanzID, string $date); // [] wenn kein Snapshot

// Sofortige Neuberechnung auslösen
LFC_Rebuild(int $InstanzID);

// Erwarteter Verbrauch (kWh) in einem beliebigen Zeitfenster, z.B. "von jetzt
// bis morgen früh, wenn die PV wieder produziert" — für ein dynamisches
// Batterie-Ziel-SoC statt eines festen Prozentwerts. Deckt bis zu 3 Tage ab
// (unser Horizont); 'coverage' (0..1) zeigt an, welcher Anteil des Fensters
// tatsächlich mit Prognosedaten abgedeckt ist (Fenster ohne Bezug zur PV —
// den PV-Startzeitpunkt bestimmt der Aufrufer selbst, z.B. aus PVF).
$w = LFC_GetEnergyWindow(int $InstanzID, int $fromTs, int $toTs);
// => ['contractVersion'=>'1.0', 'from'=>.., 'to'=>.., 'kwh'=>.., 'coverage'=>..]
```

Teil der **[EnergiePrognose-Suite](https://github.com/DG65/NRGPrognose)** – zusammen mit *PV-Prognose*
und der *Energiebilanz*-Kachel.

> **Teil des NRG-Stack** — dem Energie-Modulverbund von DG65 (Messen · Wissen · Entscheiden · Steuern · Zeigen). Welche Modulstände zusammen getestet sind, listet das [Manifest](https://github.com/DG65/NRGEMS/blob/main/SUITE.md).
