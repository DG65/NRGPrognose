🇩🇪 [Deutsche Version](EIGENES_PROGNOSE_MODUL.md)

# Connecting your own forecast module to the EMS

This guide describes what a Symcon module must deliver so that the EMS finds its forecast and uses it as a **load or PV forecast**. The Load forecast (`LFC`) and PV forecast (`PVF`) modules in this repository already follow it and are the reference.

Forecasts are only **read**. A forecast module therefore needs no `failsafeSec` and no write permission.

## How the EMS finds your module

In its slow partner cycle (minutes, never in the control cycle) the EMS looks for modules with the function `<PREFIX>_GetForecast`. It calls it per instance with `($instanceID, $offset)`. If the answer has `contractType` = `forecast` and a known major version, the forecast is taken over. The own prefixes (`LFC`, `PVF`) come through their known path.

## Function

```php
<PREFIX>_GetForecast(int $instanceID, int $offset): array
```

- `$offset`: 0 = today, 1 = tomorrow, up to 4 (five days). If the day is outside your horizon, return an empty array.
- The return value is an array (not a JSON string).

## Fields of the answer

| Field | Type | Required | Meaning |
|---|---|---|---|
| `contractType` | string | yes | always `forecast` |
| `contractVersion` | string | yes | `major.minor`, currently `1.5` (PV) / `1.4` (load). The EMS accepts major `1`. |
| `kind` | string | yes | `pv` (generation) or `load` (consumption) |
| `date` | string | yes | calendar day `Y-m-d` in local time that the forecast is for |
| `resolution` | string | yes | grid: `15min`, `30min` or `60min`. **Authoritative** for the time grid. |
| `slots` | integer | yes | number of values per series, `1440 / grid minutes` (96, 48 or 24) |
| `unit` | string | yes | always `W` |
| `mean` | array of float | yes | mean power per slot in W |
| `kwh` | float | yes | daily total in kWh, consistent with `mean` |
| `generated` | integer | yes | Unix time of the calculation; `0` = placeholder without real data |
| `p10`, `p50`, `p90` | array of float | no | uncertainty band (10 %, 50 %, 90 %), same length and unit as `mean`. If missing, the EMS uses `mean`. |

Further fields are allowed and ignored. Each series has exactly `slots` values.

## Time grid

- **Slot 0 starts at 0:00 local time**, slot `i` covers `[i · grid, (i + 1) · grid)` by wall clock. The number of slots is `1440 / grid` on every day, also on the days of the time change; the caller maps real points in time to the slot via the wall clock.
- Values are **mean power** over the slot in W, neither instantaneous values nor energy.
- **Sign:** PV generation positive, consumption positive. No net quantities.

## Freshness

The EMS treats a forecast with `generated` older than **6 hours** as stale and no longer uses it. `generated = 0` means “no data”. So recalculate at least every few hours, or update `generated` whenever you recalculate.

## Versioning

Contracts are **additive**: new fields only raise the minor version. A break (a field is dropped, a meaning changes) raises the major version, and the EMS then discards answers with an unknown major.

## Example

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

## Optional: additional functions

The modules `LFC` and `PVF` also offer `GetSnapshot($id, 'Y-m-d')` (stored forecast of a past day) and `GetEnergyWindow($id, $from, $to)` (energy in a time window). Both carry the same fields `contractType` and `kind`. They are not needed for the connection.
