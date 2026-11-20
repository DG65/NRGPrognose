🇬🇧 [English version](EIGENES_PROGNOSE_MODUL.en.md)

# Eigenes Prognosemodul an das EMS anbinden

Diese Anleitung beschreibt, was ein Symcon-Modul liefern muss, damit das EMS seine Prognose als **Last- oder PV-Prognose** findet und nutzt. Die Module Lastprognose (`LFC`) und PV-Prognose (`PVF`) aus diesem Repository halten sich bereits daran und sind die Referenz.

Prognosen werden nur **gelesen**. Ein Prognosemodul braucht deshalb kein `failsafeSec` und keine Schreibfreigabe.

## So findet das EMS dein Modul

Das EMS sucht im langsamen Partner-Zyklus (Minuten, nie im Regelzyklus) nach Modulen mit der Funktion `<PRÄFIX>_GetForecast`. Es ruft sie je Instanz mit `($instanzID, $offset)` auf. Antwortet sie mit `contractType` = `forecast` und einer bekannten Major-Version, wird die Prognose übernommen. Eigene Präfixe (`LFC`, `PVF`) kommen über ihren bekannten Weg.

## Funktion

```php
<PRÄFIX>_GetForecast(int $instanzID, int $offset): array
```

- `$offset`: 0 = heute, 1 = morgen, bis 4 (fünf Tage). Liegt der Tag außerhalb deines Horizonts, gib ein leeres Array zurück.
- Rückgabe ein Array (kein JSON-String).

## Felder der Antwort

| Feld | Typ | Pflicht | Bedeutung |
|---|---|---|---|
| `contractType` | String | ja | immer `forecast` |
| `contractVersion` | String | ja | `Major.Minor`, derzeit `1.5` (PV) / `1.4` (Last). Das EMS akzeptiert die Major `1`. |
| `kind` | String | ja | `pv` (Erzeugung) oder `load` (Verbrauch) |
| `date` | String | ja | Kalendertag `Y-m-d` in Ortszeit, für den die Prognose gilt |
| `resolution` | String | ja | Raster: `15min`, `30min` oder `60min`. **Maßgeblich** für den Zeitraster. |
| `slots` | Integer | ja | Anzahl der Werte je Reihe, `1440 / Rastermin` (96, 48 oder 24) |
| `unit` | String | ja | immer `W` |
| `mean` | Array Float | ja | mittlere Leistung je Slot in W |
| `kwh` | Float | ja | Tagessumme in kWh, passend zu `mean` |
| `generated` | Integer | ja | Unix-Zeit der Berechnung; `0` = Platzhalter ohne echte Daten |
| `p10`, `p50`, `p90` | Array Float | nein | Unsicherheitsband (10 %, 50 %, 90 %), gleiche Länge und Einheit wie `mean`. Fehlt es, nutzt das EMS `mean`. |

Weitere Felder sind erlaubt und werden ignoriert. Die Reihen haben genau `slots` Werte.

## Zeitraster

- **Slot 0 beginnt um 0:00 Uhr Ortszeit**, Slot `i` deckt `[i · Raster, (i + 1) · Raster)` nach Wanduhr ab. Die Slotzahl ist an jedem Tag `1440 / Raster`, auch an Tagen der Zeitumstellung; der Aufrufer ordnet reale Zeitpunkte über die Wanduhr dem Slot zu.
- Werte sind **Mittelwerte der Leistung** über den Slot in W, keine Momentanwerte und keine Energie.
- **Vorzeichen:** PV-Erzeugung positiv, Verbrauch positiv. Keine Netto-Größen.

## Frische

Das EMS behandelt eine Prognose mit `generated` älter als **6 Stunden** als veraltet und nutzt sie nicht mehr. `generated = 0` heißt „keine Daten“. Berechne daher mindestens alle paar Stunden neu oder aktualisiere `generated` beim Neuberechnen.

## Versionierung

Verträge sind **additiv**: neue Felder erhöhen nur die Minor-Version. Ein Bruch (Feld entfällt, Bedeutung ändert sich) erhöht die Major-Version, das EMS verwirft dann Antworten mit unbekannter Major.

## Beispiel

```json
{
  "contractType": "forecast",
  "contractVersion": "1.5",
  "kind": "pv",
  "date": "2026-11-21",
  "resolution": "60min",
  "slots": 24,
  "unit": "W",
  "p10": [0, 0, 0, 0, 0, 0, 0, 0, 120, 410, 780, 960, 1010, 880, 520, 210, 20, 0, 0, 0, 0, 0, 0, 0],
  "p50": [0, 0, 0, 0, 0, 0, 0, 0, 220, 640, 1130, 1380, 1450, 1260, 790, 330, 40, 0, 0, 0, 0, 0, 0, 0],
  "p90": [0, 0, 0, 0, 0, 0, 0, 0, 330, 890, 1490, 1810, 1870, 1650, 1110, 470, 70, 0, 0, 0, 0, 0, 0, 0],
  "mean": [0, 0, 0, 0, 0, 0, 0, 0, 220, 640, 1130, 1380, 1450, 1260, 790, 330, 40, 0, 0, 0, 0, 0, 0, 0],
  "kwh": 7.24,
  "generated": 1794900000
}
```

## Optional: Zusatzfunktionen

Die Module `LFC` und `PVF` bieten zusätzlich `GetSnapshot($id, 'Y-m-d')` (gespeicherte Prognose eines vergangenen Tages) und `GetEnergyWindow($id, $von, $bis)` (Energie in einem Zeitfenster). Beide tragen dieselben Felder `contractType` und `kind`. Für die Anbindung sind sie nicht nötig.
