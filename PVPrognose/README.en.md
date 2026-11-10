🇩🇪 [Deutsche Fassung](README.md)

# PV forecast (PVPrognose)

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Module Version](https://img.shields.io/badge/Module_Version-0.24-blue)
![Symcon Version](https://img.shields.io/badge/Symcon_Version-9.0%2B-blue)
![License](https://img.shields.io/badge/License-PolyForm_Noncommercial_1.0.0-lightgrey)
[![Check Style](https://github.com/DG65/NRGPrognose/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/NRGPrognose/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Me-blue?logo=paypal)](https://paypal.me/DietmarGureth)

**Physics-based PV generation forecast** for 1–5 days, per generator (roof area / MPP tracker), using a
weather/solar API. Delivers JSON profiles for direct use by an EMS. Prefix: `PVF`.

## How it works

For each generator, the expected power is calculated from **tilt**, **azimuth** and **kWp** using the
irradiance data of an API; all generators are summed to the total PV output.

**Azimuth convention:** 0° = south, −90° = east, +90° = west, ±180° = north.
**Tilt:** 0° = flat, 90° = vertical (a typical pitched roof is 30–45°).

## Data sources

| Source | Properties |
|---|---|
| **Open-Meteo** (default) | Free, no API key. Power = kWp × irradiance/1000 × performance ratio with temperature derating. |
| **Forecast.Solar** | Returns the power directly; the free tier is rate-limited (do not call too often). |
| **Solcast** | API key + resource ID per generator; the only source that delivers a real **P10/P90 band**. The key is stored safely after saving (not visible in the form, not in exports). |

With Open-Meteo/Forecast.Solar, P10 = P50 = P90 (a single line without a band).

## Requirements

- Symcon **9.0** or later
- Per generator: tilt, azimuth, kWp
- For self-calibration and the forecast accuracy: an **archived power variable** per generator
  (`PowerVar`, unit W/kW detected automatically)

## Setup

| Area | Meaning |
|---|---|
| **Generators** | One row per roof area / MPP tracker (name, tilt, azimuth, kWp, optionally PowerVar/factor/Solcast ID). If your inverter reports the generation as **negative** (often with a small positive standby draw at night), switch on “Invert sign” in that column: the power is then read as yield, and a positive value counts as 0. The status line “Sign” under “Self-calibration” checks the values of the last 7 days. |
| **Source** | Open-Meteo / Forecast.Solar / Solcast. |
| **Self-calibration** (Open-Meteo) | Compares measured with modelled generation and learns a correction factor – this accounts for shading, soiling and the real module output. **Can be switched on or off per generator** (“Calibrate” column): switch it off for curtailed generators (DC MPPT with a current/voltage limit, curtailment when the battery is full) so that the forecast shows the **potential** instead of the artificially throttled measurement. |
| **Resolution** | 60 / 30 / 15 minutes – ideally the same as the load forecast. |

## Status variables

| Ident | Description |
|---|---|
| `PVF_Today` / `PVF_Tomorrow` / `PVF_DayAfter` | Forecast profile as JSON (`p10/p50/p90/mean` in W per slot, `kwh`, `slots`, `resolution`) |
| `PVF_kWhToday` / `…Tomorrow` / `…DayAfter` | Daily generation of the forecast (kWh) |
| `PVF_ErrorMAPE` | Mean absolute error of the last days (%) |
| `PVF_Accuracy` | Forecast accuracy as text: number of days, bias, \|Ø error\| |
| `PVF_Status` / `PVF_LastUpdate` | Status and time of the last calculation |

## Uncertainty band from real forecast errors (optional)

**Open-Meteo and Forecast.Solar deliver only a single line** (`p10 = p50 = p90`) — so no uncertainty band
at all. The **measured deviations** of the last days (stored snapshot against actual) make it possible to
form a real band for the first time; P90 then means “in 90 % of cases the real value was below this”.

| Mode | Effect |
|---|---|
| **Band of the data source** (default) | as before (with Open-Meteo/Forecast.Solar: no band) |
| **Band only** | P10/P90 from real errors; forecast value (P50) and kWh stay unchanged |
| **Band + level correction** | additionally corrects a systematic error (bias) |

Night and twilight slots are hidden here (the forecast there is ≈ 0 and the ratio of actual to planned is
meaningless). It only takes effect from **3 evaluable days** and needs the `PowerVar` of each generator;
until then the default stays active. **Solcast** already has a native band. The effective factors are shown
in the variable *Forecast accuracy*.

## Forecast accuracy (planned vs. actual)

Compares the earlier day-ahead forecast of past days (snapshots) with the measured generation (sum of the
`PowerVar`). **Bias** + means overestimated (e.g. shading → enable calibration), **\|Ø error\|** is the mean
absolute value.

**Special effects are excluded** (NRG-Stack, as soon as an EMS with `EMS_GetSpecialEvents` is installed):
days with an external control intervention (§14a dimming, Tibber balancing energy, direct marketing, EMS
protective shutdown, negative exchange price) enter neither bias/\|Ø error\| nor the residual quantiles nor
the self-calibration — otherwise a curtailed measurement would be learned as “model too high”. What counts is
what an event distorts (field `affects` in EMS contract 1.1): it counts if it affects the PV generation; events
that only affect the load (Grid Rewards, boost) are left out. Without an EMS (or without this function) the
behaviour stays unchanged.

## Snow on the modules (FrigateHub)

If the module **FrigateHub** (observation area of the type “PV modules”, observation `snow_pv`) reports the
modules as covered, the forecast for **today** can take that into account. The switch “Take snow on the modules
into account” (general settings) is **off by default**: no forecast value ever changes without your consent.
When switched on, with “covered” and a certainty of at least 70 %, all slots **from now on** are set to 0 until
FrigateHub reports the modules free again; past slots stay. “Partly covered” has no fixed factor and changes
nothing. **Later days stay unchanged** — no source knows when snow slides off. Observations whose last check is
more than 2 hours old (or whose `validUntil` has passed) count as unknown. Without FrigateHub everything stays as
before.

The correction is applied when **reading**: the stored state (`PVF_Today`, snapshots) remains the pure model
forecast, so forecast accuracy, residuals and self-calibration never learn from a snow correction. Anyone reading
`PVF_Today` directly therefore does not see the correction; `PVF_GetForecast($id, 0)` and
`PVF_GetEnergyWindow()` include it. Snow days in the **training** are excluded by the EMS through its special
effects (`EMS_GetSpecialEvents`, `affects` ∋ `pv`); PVPrognose builds nothing for that.

## Public functions

```php
// Get the forecast of one day: $offset 0=today, 1=tomorrow, 2=day after tomorrow, 3/4=day 4/5
$fc = PVF_GetForecast(int $InstanceID, int $offset);

// Stored forecast (planned) of a past day ('Y-m-d')
$snap = PVF_GetSnapshot(int $InstanceID, string $date); // [] if there is no snapshot

// Immediate recalculation / reload of the forecast
PVF_Rebuild(int $InstanceID);

// Total module area (m²) over all generators = Σ count × area per module
// (e.g. for the InverterHub module). Also available as status variable PVF_ModuleArea.
$m2 = PVF_GetModuleArea(int $InstanceID);

// Module area per generator: list [{name, modules, areaPerModule, area}]
$perGen = PVF_GetModuleAreas(int $InstanceID);

// Expected PV generation (kWh) in any time window — symmetric to LFC_GetEnergyWindow,
// self-contained (no coupling to LFC). Covers up to 5 days; 'coverage' (0..1) shows
// which share is actually covered by a real model (0 on an API/network error or
// missing generators).
$w = PVF_GetEnergyWindow(int $InstanceID, int $fromTs, int $toTs);
// => ['contractVersion'=>'1.0', 'from'=>.., 'to'=>.., 'kwh'=>.., 'coverage'=>..]
```

## Module metadata (for InverterHub)

For each generator you can enter the **number of modules** as well as the **module length** and **module
width (mm)**. The area per module is calculated from them (length × width). This information does **not**
enter the yield forecast; it is summed to the **total area** (count × length × width) and provided as status
variable `PVF_ModuleArea` and through `PVF_GetModuleArea()`. The area **per generator** is returned by
`PVF_GetModuleAreas()` as a list (`name`, `modules`, `lengthMM`, `widthMM`, `areaPerModule`, `area`) – meant
to be taken over by the **InverterHub** module.

## Languages

The module is bilingual (German/English): form, status messages, notices and the names of newly created
variables follow the Symcon language. The source texts are English, the German version is in `locale.json`.
Variables that already exist keep their names.

Part of the **[EnergiePrognose suite](https://github.com/DG65/NRGPrognose)** – together with *Lastprognose*
(load forecast) and the forecast tile in the NRG dashboard.

> **Part of the NRG-Stack** — the energy module family by DG65 (measure · know · decide · control · show). The [manifest](https://github.com/DG65/NRGEMS/blob/main/SUITE.md) lists which module versions have been tested together.
