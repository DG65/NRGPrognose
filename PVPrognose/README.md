# PV-Prognose

**Physikbasierte PV-Erzeugungsprognose** für 1–3 Tage, je Generator (Dachfläche/MPP-Tracker), über
eine Wetter-/Solar-API. Liefert JSON-Profile zur direkten Nutzung durch ein EMS. Prefix: `PVF`.

## Funktionsweise

Für jeden Generator wird aus **Neigung**, **Azimut** und **kWp** mit den Einstrahlungsdaten einer
API die erwartete Leistung berechnet; alle Generatoren werden zur Gesamt-PV summiert.

**Azimut-Konvention:** 0° = Süd, −90° = Ost, +90° = West, ±180° = Nord.
**Neigung:** 0° = flach, 90° = senkrecht (typisches Satteldach 30–45°).

## Datenquellen

| Quelle | Eigenschaften |
|---|---|
| **Open-Meteo** (Standard) | Kostenlos, kein API-Schlüssel. Leistung = kWp × Einstrahlung/1000 × Performance-Ratio mit Temperatur-Abminderung. |
| **Forecast.Solar** | Liefert Leistung direkt; Gratis-Tarif ratenbegrenzt (nicht zu häufig abrufen). |
| **Solcast** | API-Schlüssel + Resource-ID je Generator; liefert als einzige Quelle ein echtes **P10/P90-Band**. Der Schlüssel wird nach dem Speichern sicher abgelegt (nicht im Formular sichtbar, nicht in Exporten). |

Bei Open-Meteo/Forecast.Solar ist P10 = P50 = P90 (eine Linie ohne Band).

## Voraussetzungen

- IP-Symcon ab **7.0**
- Pro Generator: Neigung, Azimut, kWp
- Für die Selbstkalibrierung und die Prognosegüte: eine **archivierte Leistungsvariable** je
  Generator (`PowerVar`, Einheit W/kW automatisch erkannt)

## Einrichtung

| Bereich | Bedeutung |
|---|---|
| **Generatoren** | Je Dachfläche/MPP-Tracker eine Zeile (Name, Neigung, Azimut, kWp, optional PowerVar/Faktor/SolcastID). |
| **Quelle** | Open-Meteo / Forecast.Solar / Solcast. |
| **Selbstkalibrierung** (Open-Meteo) | Vergleicht gemessene mit modellierter Erzeugung und lernt einen Korrekturfaktor – fängt Verschattung, Verschmutzung und reale Modulleistung. **Je Generator ab-/anschaltbar** (Spalte „Kalibrieren"): Für abgeregelte Generatoren (DC-MPPT mit Strom-/Spannungslimit, Batterie-voll-Abregelung) ausschalten, damit die Prognose das **Potenzial** statt der künstlich gedrosselten Messung zeigt. |
| **Auflösung** | 60 / 30 / 15 Minuten – idealerweise deckungsgleich zur Lastprognose. |

## Statusvariablen

| Ident | Beschreibung |
|---|---|
| `PVF_Today` / `PVF_Tomorrow` / `PVF_DayAfter` | Prognoseprofil als JSON (`p10/p50/p90/mean` in W je Slot, `kwh`, `slots`, `resolution`) |
| `PVF_kWhToday` / `…Tomorrow` / `…DayAfter` | Tageserzeugung der Prognose (kWh) |
| `PVF_ErrorMAPE` | Mittlerer Betragsfehler der letzten Tage (%) |
| `PVF_Accuracy` | Prognosegüte als Text: Tagesanzahl, Bias, \|Ø-Fehler\| |
| `PVF_Status` / `PVF_LastUpdate` | Status und Zeitpunkt der letzten Berechnung |

## Unsicherheitsband aus echten Prognosefehlern (optional)

**Open-Meteo und Forecast.Solar liefern nur eine Linie** (`p10 = p50 = p90`) — also gar kein
Unsicherheitsband. Aus den **gemessenen Abweichungen** der letzten Tage (gespeicherter Snapshot gegen
Ist) lässt sich erstmals ein echtes Band bilden; P90 bedeutet dann „in 90 % der Fälle lag der reale
Wert darunter".

| Modus | Wirkung |
|---|---|
| **Band der Datenquelle** (Standard) | wie bisher (bei Open-Meteo/Forecast.Solar: kein Band) |
| **Nur Band** | P10/P90 aus realen Fehlern; Prognosewert (P50) und kWh bleiben unverändert |
| **Band + Pegelkorrektur** | zusätzlich wird ein systematischer Fehler (Bias) nachgezogen |

Nacht- und Dämmerungsslots werden dabei ausgeblendet (dort ist die Prognose ≈ 0 und das Verhältnis
Ist/Soll bedeutungslos). Greift erst ab **3 auswertbaren Tagen** und benötigt die `PowerVar` je
Generator; vorher bleibt automatisch der Standard aktiv. Bei **Solcast** gibt es bereits ein natives
Band. Die wirksamen Faktoren stehen in der Variable *Prognosegüte*.

## Prognosegüte (Soll vs. Ist)

Vergleicht die frühere Day-Ahead-Prognose vergangener Tage (Snapshots) mit der gemessenen Erzeugung
(Summe der `PowerVar`). **Bias** + bedeutet überschätzt (z. B. Verschattung → Kalibrierung
aktivieren), **\|Ø-Fehler\|** ist der mittlere Betrag.

**Sondereffekte werden ausgeschlossen** (NRG-Stack, sobald ein EMS mit `EMS_GetSpecialEvents`
installiert ist): Tage mit externem Regeleingriff (§14a-Dimmung, Tibber-Regelenergie,
Direktvermarktung, EMS-Schutzabschaltung) fließen weder in Bias/\|Ø-Fehler\| noch in die
Residuen-Quantile ein. Ohne EMS (oder ohne diese Funktion) bleibt das Verhalten unverändert.

## Öffentliche Funktionen

```php
// Prognose eines Tages holen: $offset 0=heute, 1=morgen, 2=übermorgen
$fc = PVF_GetForecast(int $InstanzID, int $offset);

// Gespeicherte Prognose (Soll) eines vergangenen Tages ('Y-m-d')
$snap = PVF_GetSnapshot(int $InstanzID, string $date); // [] wenn kein Snapshot

// Sofortige Neuberechnung / Neuladen der Vorhersage
PVF_Rebuild(int $InstanzID);

// Gesamte Modulfläche (m²) über alle Generatoren = Σ Anzahl × Fläche je Modul
// (z.B. für das Modul InverterHub). Auch als Statusvariable PVF_ModuleArea.
$m2 = PVF_GetModuleArea(int $InstanzID);

// Modulfläche je Generator: Liste [{name, modules, areaPerModule, area}]
$perGen = PVF_GetModuleAreas(int $InstanzID);

// Erwartete PV-Erzeugung (kWh) in einem beliebigen Zeitfenster — symmetrisch zu
// LFC_GetEnergyWindow, eigenständig (keine Kopplung zu LFC). Deckt bis zu 3 Tage ab;
// 'coverage' (0..1) zeigt an, welcher Anteil tatsächlich mit einem echten Modell
// abgedeckt ist (0 bei API-/Netzwerkfehler oder fehlenden Generatoren).
$w = PVF_GetEnergyWindow(int $InstanzID, int $fromTs, int $toTs);
// => ['contractVersion'=>'1.0', 'from'=>.., 'to'=>.., 'kwh'=>.., 'coverage'=>..]
```

## Modul-Metadaten (für InverterHub)

Je Generator lassen sich **Modulanzahl** sowie **Modullänge** und **Modulbreite (mm)** hinterlegen.
Die Fläche je Modul wird daraus berechnet (Länge × Breite). Diese Angaben fließen **nicht** in die
Ertragsprognose ein, sondern werden zur **Gesamtfläche** aufsummiert (Anzahl × Länge × Breite) und als
Statusvariable `PVF_ModuleArea` sowie über `PVF_GetModuleArea()` bereitgestellt. Die Fläche **je
Generator** liefert `PVF_GetModuleAreas()` als Liste (`name`, `modules`, `lengthMM`, `widthMM`,
`areaPerModule`, `area`) – gedacht zur Übernahme durch das Modul **InverterHub**.

Teil der **[EnergiePrognose-Suite](https://github.com/DG65/NRGPrognose)** – zusammen mit *Lastprognose*
und der *Energiebilanz*-Kachel.

> **Teil des NRG-Stack** — dem Energie-Modulverbund von DG65 (Messen · Wissen · Entscheiden · Steuern · Zeigen). Welche Modulstände zusammen getestet sind, listet das [Manifest](https://github.com/DG65/NRGEMS/blob/main/SUITE.md).
