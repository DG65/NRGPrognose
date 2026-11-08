🇩🇪 [Deutsche Fassung](README.md)

# EnergiePrognose — load & PV forecast for Symcon

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Module Version](https://img.shields.io/badge/Module_Version-0.23-blue)
![Symcon Version](https://img.shields.io/badge/Symcon_Version-9.0%2B-blue)
![License](https://img.shields.io/badge/License-PolyForm_Noncommercial_1.0.0-lightgrey)
[![Check Style](https://github.com/DG65/NRGPrognose/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/NRGPrognose/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Me-blue?logo=paypal)](https://paypal.me/DietmarGureth)

Forecast suite with two building blocks that give the EMS both sides of the energy balance 1–5 days ahead:

- **Lastprognose** (load forecast, prefix `LFC`) — consumption forecast using a similar-days method (k-NN).
- **PVPrognose** (PV forecast, prefix `PVF`) — physics-based PV generation forecast per generator using a
  weather/solar API ([details below](#pvprognose--pv-generation-forecast)).

Both modules are bilingual (German/English): form, status messages and notices follow the Symcon language.

> The former tile **Energiebilanz** (prefix `EFTILE`) has been removed since build 162. Its successor is the
> forecast tile in the **NRG dashboard** (module `NRGDashboardForecast`, library “NRG-Stack Dashboard” in the Symcon Store).

## Lastprognose — consumption forecast

Creates a **1–5-day consumption forecast** from your archive data and delivers it as a JSON profile
(60/30/15-minute resolution, P10/P50/P90) for direct use by the EMS.

## Concept

Instead of forcing the consumption into rigid groups (time of day × season × weekday × holiday …) — which
explodes combinatorially and leaves hardly any data per group — the module uses a
**similar-days method (k-NN)**:

1. Every forecast day gets a **feature vector**:
   - **Day type** — working day / Saturday / Sunday·public holiday (the strongest driver of the shape)
   - **Day length** — a clean season proxy instead of “month” (from latitude + day of the year)
   - **Heating degree** — from the outdoor temperature (weather forecast); captures heating/lighting
   - **Presence** — the really tricky factor (holiday ≠ weekday!)
2. The **k most similar days** are searched in the history (weighted distance).
3. Their hourly profiles are condensed into **P10/P50/P90**, weighted by distance.

So a “winter Saturday on holiday” never needs a bucket of its own — the method simply finds the matching
neighbouring days.

**Temperature-dependent devices** (heat pump, air conditioning — the largest weather-dependent consumers) are
optionally forecast separately through a temperature regression and can be subtracted from the main
consumption, so that only the plannable base load remains. The operating mode can be chosen per device:

- **Heating**: `kWh = a + b · heating degree`
- **Cooling**: `kWh = a + c · cooling degree`
- **Heating + cooling** (air-to-air heat pump/air conditioning): V curve `kWh = a + b · heating degree + c · cooling degree`

Heating degree = `max(0, heating limit − T)`, cooling degree = `max(0, T − cooling limit)`. Several devices are
fitted individually and summed.

## Setup

> Install **only** through the Symcon module control (module repository URL), not by copying files manually.

1. Create a “Lastprognose” module instance.
2. Connect the **data sources**:
   - *House consumption (W)* — e.g. the EMS variable `House consumption (W)`. Must be archived!
   - *Loads to subtract* — optionally heat pump / wallbox (power in W) to isolate the base load.
   - *Outdoor temperature (°C)* — archived.
   - *Presence* — bool/0..1, e.g. from geofencing. **Important:** without this signal, the forecast is systematically wrong during holidays/home office.
3. Connect the **temperature forecast** (see below) and, if needed, the planned presence.
4. Adjust the **model parameters** (the defaults are usable): history depth `365 d`, `k = 12`, latitude, heating limit `15 °C`.
5. Press “Recalculate forecast now”.

## Temperature forecast (module-agnostic)

The module does **not** hard-wire a particular weather source — it runs on any Symcon system. Important:
**daily means** are expected (consistent with the history, which also uses the daily mean), not the current
temperature. Three modes plus an automatic climatology fallback:

**Auto (default, recommended).**
The module finds an OpenWeatherData instance (demel42) itself through the module GUID — **no** instance ID is
hard-wired, so it is portable across all systems. Today/tomorrow/day after tomorrow are aggregated from the
3-hour slots (min/max → daily mean). Prerequisite: enable the hourly forecast in the OWM instance
(`hourly_forecast_count` > 0, e.g. 40). If there is no OWM instance or the hourly forecast is off → automatic
climatology (see below).

**Daily-mean variables.**
Point to three variables with the daily mean for today/tomorrow/day after tomorrow. For weather modules with
ready-made daily values — no dependency.

**Slot aggregation via ident pattern.**
For other modules with hourly/3-hour slots (DWD, Meteoblue …). You say *where* the slots are and *what* they
are called — the module aggregates them to the daily mean:

- *Parent object*: instance/category under which the slot variables are located.
- *Ident pattern*: with `%d` or `%02d` as a placeholder for the slot index.
- *Timestamp pattern*: Unix time per slot → the module sorts by calendar day.
- *Min + optionally max*: with both, `(min+max)/2` is used as the slot value.

**Climatology fallback (always active).**
If the chosen source delivers nothing for a day, the module uses the *seasonal normal*: the mean of the same
calendar day ±7 days over all available previous years from the temperature archive. So the forecast also
works completely without a weather module — an archived outdoor temperature is all that is needed.

> Even without any temperature source the module keeps running: the heating degree is then neutral and the
> forecast relies on day type, day length and presence (base load).

## Outputs

| Variable | Content |
|---|---|
| `Forecast today/tomorrow/day after tomorrow (JSON)` | full profile: `p10[]`, `p50[]`, `p90[]`, `mean[]`, `kwh` |
| `Expected today/tomorrow/day after tomorrow (kWh)` | daily total |
| `Expected heat pump/AC tomorrow (kWh)` | separate heat pump forecast (if configured) |
| `Status`, `Last calculation` | diagnosis |

JSON structure (`slots`/`resolution` depending on the chosen resolution, values in **W** average power per slot):

```json
{ "date":"2026-06-18","slots":24,"resolution":"60min","unit":"W",
  "p10":[...],"p50":[...],"p90":[...],"mean":[...],"kwh":14.3,"neighbors":12 }
```

Variables that already exist keep their (German) names; the idents (`LFC_Today`, …) are the same in both languages.

## EMS integration

Call the forecast directly in the EMS script:

```php
$lfc = 12345; // instance ID of Lastprognose
$fc  = LFC_GetForecast($lfc, 1);   // 1 = tomorrow

$expectedTomorrow = $fc['kwh'];      // kWh
$profileMedian    = $fc['p50'];      // 24 hourly values (W)
$profileP90       = $fc['p90'];      // plan conservatively

// Example: charge the battery more conservatively when the forecast is uncertain
$spread = array_sum($fc['p90']) - array_sum($fc['p10']);
```

The **uncertainty band** (P10/P90) allows risk-aware decisions: buffer more conservatively with a large
spread, run more optimistically with a narrow spread.

> For a graphical display of the load forecast, use the forecast tile in the **NRG dashboard** (module
> `NRGDashboardForecast`) with the switch “Show PV generation” = off.

## Extension stages

- **Resolution** 60/30/15 min selectable (model parameters). 60 min uses the hourly aggregate, finer steps
  integrate from the raw values — this needs correspondingly fine archiving.
- **Regional public holidays** through the federal-state selection (model parameters).
- Adjust the **feature weights** in `distance()` (`wDT/wDL/wHDD/wPres`).

---

## PVPrognose — PV generation forecast

PV is mostly **deterministic physics** — so no similar-days method, but system geometry × irradiance forecast:

1. **Per PV generator** (roof area / MPP tracker): tilt, azimuth (0=south, −90=east, +90=west), kWp. All
   generators are summed to the total PV output.
2. **Selectable forecast source**:
   - **Open-Meteo** — free, no API key; delivers tilted irradiance (GTI). Power =
     `kWp × GTI/1000 × performance ratio` with temperature derating. Universal default.
   - **Forecast.Solar** — returns the PV power directly; the free tier is rate-limited.
   - **Solcast** — API key required, in return includes P10/P90.
3. **Self-calibration** (Open-Meteo, optional): compares the measured generation (archived power variable per
   generator) with the generation modelled from *past* irradiance and learns a correction factor — this
   accounts for shading, soiling and the real output. In addition there is a manual correction factor per
   generator.

Output: profile (P10/P50/P90 — a single line with Open-Meteo/Forecast.Solar, a real band with Solcast) + kWh
for 1–5 days. **Resolution 60/30/15 min** selectable (the same as the load forecast; the weather sources
deliver hourly values, finer steps are interpolated). EMS access: `PVF_GetForecast($id, $offset)`.

**Forecast accuracy & time-of-day correction.** Both forecast modules store a day-ahead snapshot every day and
compare it with the measured generation/consumption — special effects (external control interventions such as
§14a, Grid Rewards, negative price, with EMS coupling — depending on whether they distort load or generation)
and detected archive faults (held measurement values after a data gap) are excluded automatically, also from
the learning material (similar-days search, self-calibration). With PVF, the residual correction derived from
this is determined separately for each part of the day (sunrise to sunset divided into 8 sections, instead of
one factor for the whole day) — a forecast is often off differently in the morning and evening than at noon.
`PVF_GetAccuracy($id)` returns the forecast accuracy in structured form (bias/error rate/number of days, per
part of the day) for external evaluation; `PVF_GetIntradaySnapshot($id, 'Y-m-d', '06:00'|'10:00')` returns
additional intraday forecast states.

## Related projects

**[DG65/NRGInverterHub](https://github.com/DG65/NRGInverterHub)** — Modbus TCP connection for inverters. Its
`InverterHubMonitor` calculates expected values from an irradiance sensor and the generator parameters stored
here and compares them with the measured yield (soiling/defect detection).

For this it uses the public API of the PV forecast:

| Function | Returns |
|---|---|
| `PVF_GetGenerators($id)` | Performance ratio, total kWp and per generator `name`, `kwp`, `tilt`, `azimuth`, `factor`, `area` |
| `PVF_GetModuleArea($id)` | Total module area (m²), also as status variable `PVF_ModuleArea` |

These `PVF_Get*` functions count as a **stable contract** between both repos: changes to signature or return
structure are agreed beforehand. Details in [CLAUDE.md](CLAUDE.md).

> **Part of the NRG-Stack** — the energy module family by DG65 (measure · know · decide · control · show).
