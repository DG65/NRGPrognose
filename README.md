# EnergiePrognose — Last- & PV-Prognose für IP-Symcon

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Modul Version](https://img.shields.io/badge/Modul_Version-0.20--beta-blue)
![Symcon Version](https://img.shields.io/badge/Symcon_Version-7.0%2B-blue)
![License](https://img.shields.io/badge/License-PolyForm_Noncommercial_1.0.0-lightgrey)
[![Check Style](https://github.com/DG65/NRGPrognose/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/NRGPrognose/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Me-blue?logo=paypal)](https://paypal.me/DietmarGureth)

Energieprognose-Suite mit drei Bausteinen, die dem EMS beide Seiten der Energiebilanz
1–5 Tage voraus liefern:

- **Lastprognose** (Prefix `LFC`) — Verbrauchsprognose über ein Ähnliche-Tage-Verfahren (k-NN).
- **PV-Prognose** (Prefix `PVF`) — physikbasierte PV-Erzeugungsprognose je Generator über eine
  Wetter-/Solar-API ([Details unten](#pv-prognose--pv-erzeugungsprognose)).
- **Energiebilanz** (Prefix `EFTILE`) — kombinierte Kachel, die Erzeugung und Verbrauch
  gemeinsam (oder per Schalter einzeln) zeigt.

## Lastprognose — Verbrauchsprognose

Erstellt aus deinen Archivdaten eine **1–3-Tage-Verbrauchsprognose** und liefert sie
als JSON-Profil (60/30/15-min-Auflösung, P10/P50/P90) zur direkten Nutzung durch das EMS.

## Konzept

Statt den Verbrauch in starre Gruppen (Tageszeit × Jahreszeit × Wochentag × Urlaub …)
zu zwängen — was kombinatorisch explodiert und pro Gruppe kaum Daten lässt — nutzt das
Modul ein **Ähnliche-Tage-Verfahren (k-NN)**:

1. Jeder Prognosetag bekommt einen **Feature-Vektor**:
   - **Tagtyp** — Werktag / Samstag / Sonntag·Feiertag (stärkster Form-Treiber)
   - **Tageslänge** — sauberer Saison-Proxy statt „Monat" (aus Breitengrad + Tag des Jahres)
   - **Heizgrad** — aus Außentemperatur (Wettervorhersage); fängt Heizen/Beleuchtung
   - **Anwesenheit** — der eigentlich knifflige Faktor (Urlaub ≠ Wochentag!)
2. Aus der Historie werden die **k ähnlichsten Tage** gesucht (gewichtete Distanz).
3. Deren Stundenprofile werden entfernungsgewichtet zu **P10/P50/P90** verdichtet.

So braucht „Winter-Samstag-im-Urlaub" nie einen eigenen Bucket — das Verfahren findet
einfach die passenden Nachbartage.

**Temperaturabhängige Geräte** (WP, Klima — die größten wetterabhängigen Verbraucher)
werden optional separat über eine Temperaturregression prognostiziert und können beim
Hauptverbrauch abgezogen werden, sodass nur die planbare Grundlast übrig bleibt. Je
Gerät ist die Betriebsart wählbar:

- **Heizen**: `kWh = a + b · Heizgrad`
- **Kühlen**: `kWh = a + c · Kühlgrad`
- **Heizen + Kühlen** (Luft-Luft-WP/Klima): V-Kurve `kWh = a + b · Heizgrad + c · Kühlgrad`

Heizgrad = `max(0, Heizgrenze − T)`, Kühlgrad = `max(0, T − Kühlgrenze)`. Mehrere Geräte
werden einzeln gefittet und summiert.

## Einrichtung

> Installation **nur** über die IP-Symcon Modulverwaltung (Modul-Repository-URL),
> nicht durch manuelles Kopieren.

1. Modul-Instanz „Lastprognose" anlegen.
2. **Datenquellen** verbinden:
   - *Hausverbrauch (W)* — z.B. die EMS-Variable `Hausverbrauch (W)`. Muss archiviert sein!
   - *Abzuziehende Verbraucher* — optional WP / Wallbox (Leistung in W), um die Grundlast zu isolieren.
   - *Außentemperatur (°C)* — archiviert.
   - *Anwesenheit* — bool/0..1, z.B. aus Geofencing. **Wichtig:** ohne dieses Signal ist die Prognose bei Urlaub/Homeoffice systematisch falsch.
3. **Temperaturvorhersage** anbinden (siehe unten) und ggf. geplante Anwesenheit.
4. **Modellparameter** anpassen (Defaults sind brauchbar): Historie-Tiefe `365 d`, `k = 12`, Breitengrad, Heizgrenze `15 °C`.
5. „Prognose jetzt neu berechnen" drücken.

## Temperaturvorhersage (modul-agnostisch)

Das Modul bindet **keine** bestimmte Wetterquelle fest ein — es läuft auf jedem
Symcon-System. Wichtig: erwartet werden **Tagesmittel** (konsistent zur Historie,
die ebenfalls das Tagesmittel nutzt), nicht die aktuelle Temperatur. Drei Modi
plus automatischer Klimatologie-Fallback:

**Auto (Default, empfohlen).**
Das Modul sucht selbst eine OpenWeatherData-Instanz (demel42) per Modul-GUID — es
wird **keine** Instanz-ID fest verdrahtet, daher portabel über alle Systeme. Aus
den 3h-Slots (Min/Max → Tagesmittel) werden heute/morgen/übermorgen aggregiert.
Voraussetzung: in der OWM-Instanz die Stundenvorhersage aktivieren
(`hourly_forecast_count` > 0, z.B. 40). Ist keine OWM-Instanz da oder die
Stundenvorhersage aus → automatisch Klimatologie (siehe unten).

**Tagesmittel-Variablen.**
Zeige auf drei Variablen mit dem Tagesmittel für heute/morgen/übermorgen. Für
Wettermodule mit fertigen Tageswerten — keine Abhängigkeit.

**Slot-Aggregation über Ident-Muster.**
Für andere Module mit stündlichen/3h-Slots (DWD, Meteoblue …). Du gibst an, *wo*
die Slots liegen und *wie* sie heißen — das Modul aggregiert zum Tagesmittel:

- *Eltern-Objekt*: Instanz/Kategorie, unter der die Slot-Variablen liegen.
- *Ident-Muster*: mit `%d` oder `%02d` als Platzhalter für den Slot-Index.
- *Zeitstempel-Muster*: Unix-Zeit je Slot → das Modul sortiert nach Kalendertag.
- *Min + optional Max*: bei beiden wird `(Min+Max)/2` als Slot-Wert genutzt.

**Klimatologie-Fallback (immer aktiv).**
Liefert die gewählte Quelle für einen Tag nichts, nutzt das Modul das *saisonale
Normal*: das Mittel desselben Kalendertags ±7 Tage über alle verfügbaren Vorjahre
aus dem Temperatur-Archiv. Damit funktioniert die Prognose auch ganz ohne
Wettermodul — nur eine archivierte Außentemperatur genügt.

> Selbst ohne jede Temperaturquelle bleibt das Modul lauffähig: Der Heizgrad fällt
> dann neutral aus und die Prognose stützt sich auf Tagtyp, Tageslänge und
> Anwesenheit (Grundlast).

## Ausgaben

| Variable | Inhalt |
|---|---|
| `Prognose heute/morgen/übermorgen (JSON)` | volles Profil: `p10[]`, `p50[]`, `p90[]`, `mean[]`, `kwh` |
| `Erwartung heute/morgen/übermorgen (kWh)` | Tagessumme |
| `Erwartung WP morgen (kWh)` | separate WP-Prognose (falls konfiguriert) |
| `Status`, `Letzte Berechnung` | Diagnose |

JSON-Struktur (`slots`/`resolution` je nach gewählter Auflösung, Werte in **W**
Ø-Leistung je Slot):

```json
{ "date":"2026-06-18","slots":24,"resolution":"60min","unit":"W",
  "p10":[...],"p50":[...],"p90":[...],"mean":[...],"kwh":14.3,"neighbors":12 }
```

## EMS-Integration

Im EMS-Script die Prognose direkt abrufen:

```php
$lfc = 12345; // Instanz-ID Lastprognose
$fc  = LFC_GetForecast($lfc, 1);   // 1 = morgen

$erwartungMorgen = $fc['kwh'];      // kWh
$profilMedian    = $fc['p50'];      // 24 Stundenwerte (W)
$profilP90       = $fc['p90'];      // konservativ planen

// Beispiel: Batterie konservativer laden, wenn Prognose unsicher ist
$spread = array_sum($fc['p90']) - array_sum($fc['p10']);
```

Das **Unsicherheitsband** (P10/P90) erlaubt risikobewusste Entscheidungen:
bei großer Spreizung konservativer puffern, bei enger Spreizung optimistischer fahren.

> Für die grafische Darstellung der Lastprognose dient die Kachel **Energiebilanz**
> (siehe unten) mit dem Schalter „PV-Erzeugung anzeigen" = aus.

## Ausbaustufen

- **Auflösung** 60/30/15 min wählbar (Modellparameter). 60 min nutzt das Stundenaggregat,
  feinere Stufen integrieren aus den Rohwerten — benötigt entsprechend feine Archivierung.
- **Regionale Feiertage** über die Bundesland-Auswahl (Modellparameter).
- **Feature-Gewichte** in `distance()` justieren (`wDT/wDL/wHDD/wPres`).

---

## PV-Prognose — PV-Erzeugungsprognose

PV ist überwiegend **deterministische Physik** — daher kein Ähnliche-Tage-Verfahren,
sondern Anlagengeometrie × Einstrahlungsvorhersage:

1. **Pro PV-Generator** (Dachfläche/MPP-Tracker): Neigung, Azimut (0=Süd, −90=Ost, +90=West),
   kWp. Alle Generatoren werden zur Gesamt-PV summiert.
2. **Wählbare Vorhersagequelle**:
   - **Open-Meteo** — kostenlos, ohne API-Schlüssel; liefert geneigte Einstrahlung (GTI). Leistung =
     `kWp × GTI/1000 × Performance-Ratio` mit Temperatur-Abminderung. Universeller Default.
   - **Forecast.Solar** — liefert PV-Leistung direkt; Gratis-Tarif ratenbegrenzt.
   - **Solcast** — API-Schlüssel nötig, dafür inkl. P10/P90.
3. **Selbstkalibrierung** (Open-Meteo, optional): vergleicht die gemessene Erzeugung (archivierte
   Leistungsvariable je Generator) mit der aus *vergangener* Einstrahlung modellierten und lernt
   einen Korrekturfaktor — fängt Verschattung, Verschmutzung und reale Leistung. Zusätzlich ein
   manueller Korrekturfaktor je Generator.

Ausgabe: Profil (P10/P50/P90 — bei Open-Meteo/Forecast.Solar als Linie, bei Solcast echtes Band) +
kWh für heute/morgen/übermorgen. **Auflösung 60/30/15 min** wählbar (deckungsgleich zur Lastprognose;
die Wetterquellen liefern stündlich, feinere Stufen werden interpoliert). EMS-Zugriff:
`PVF_GetForecast($id, $offset)`.

## Energiebilanz — kombinierte Kachel

Zeigt **PV-Erzeugung und Verbrauch** als zwei P10/P50/P90-Bänder in einem Diagramm — der
EMS-Blick: Erzeugung gegen Verbrauch, die Lücke ist Netzbezug/Einspeisung. Findet die PV- und die
Last-Instanz automatisch per Modul-GUID. Per Schalter **„PV-Erzeugung anzeigen"** / **„Verbrauch
anzeigen"** lässt sich dieselbe Kachel als kombinierte, reine PV- oder reine Verbrauchs-Ansicht
nutzen. Hover/Touch zeigt PV-Wert, Verbrauch und **Saldo** zur jeweiligen Uhrzeit.

Optional lassen sich **Ist-Werte** anbinden (momentane PV-Leistung und Hauslast in W): sie erscheinen
live in der Legende und als Punkt auf der „jetzt"-Linie. Zusätzlich kann der **gemessene Tagesverlauf
(heute)** als gestrichelte Linie über die Prognose gelegt werden — Prognose gegen Realität über den
ganzen Tag. Im WebFront blendet ein **Klick auf einen Legendeneintrag** die jeweilige Reihe live
aus/ein.

**Diagramm-Engine wählbar:** **ECharts** (Apache-2.0, Default — auch kommerziell kostenlos) oder
**Highcharts** (nur privat/nicht-kommerziell kostenlos). Beide bieten denselben Funktionsumfang und
ein nahezu identisches Aussehen; geladen wird nur die gewählte Library per CDN.

Konfigurierbar: kWh je Tag (2 Nachkommastellen), Diagrammhöhe, Linienstärke, Kurvenglättung,
Unsicherheitsband (ein/aus + Transparenz), Gitter/Achsen, Y-Achse manuell, Farben, Schriftart und
Schriftgröße; Standard ist theme-konform.

> **Charting-Library:** Die Kachel rendert mit **Highcharts**, das per CDN (`code.highcharts.com`)
> geladen wird — es ist **nicht** Teil dieses Repos. Highcharts ist für **private,
> nicht-kommerzielle** Nutzung kostenlos; für kommerzielle Nutzung ist eine Highcharts-Lizenz nötig
> (siehe [highcharts.com/license](https://www.highcharts.com/license)). Im WebFront muss der Browser
> das CDN erreichen können.

## Verwandte Projekte

**[DG65/NRGInverterHub](https://github.com/DG65/NRGInverterHub)** — Modbus-TCP-Anbindung für Wechselrichter.
Der dortige `InverterHubMonitor` berechnet aus einem Einstrahlungssensor und den hier hinterlegten
Generatorparametern Erwartungswerte und stellt sie dem gemessenen Ertrag gegenüber
(Verschmutzungs-/Defekterkennung).

Dafür nutzt er die öffentliche API der PV-Prognose:

| Funktion | Liefert |
|---|---|
| `PVF_GetGenerators($id)` | Performance-Ratio, Gesamt-kWp und je Generator `name`, `kwp`, `tilt`, `azimuth`, `factor`, `area` |
| `PVF_GetModuleArea($id)` | Gesamte Modulfläche (m²), auch als Statusvariable `PVF_ModuleArea` |

Diese `PVF_Get*`-Funktionen gelten als **stabiler Vertrag** zwischen beiden Repos: Änderungen an
Signatur oder Rückgabestruktur werden vorher abgestimmt. Details siehe [CLAUDE.md](CLAUDE.md).

> **Teil des NRG-Stack** — dem Energie-Modulverbund von DG65 (Messen · Wissen · Entscheiden · Steuern · Zeigen).
