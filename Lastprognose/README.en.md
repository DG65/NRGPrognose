🇩🇪 [Deutsche Fassung](README.md)

# Load forecast (Lastprognose)

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Module Version](https://img.shields.io/badge/Module_Version-0.24-blue)
![Symcon Version](https://img.shields.io/badge/Symcon_Version-9.0%2B-blue)
![License](https://img.shields.io/badge/License-PolyForm_Noncommercial_1.0.0-lightgrey)
[![Check Style](https://github.com/DG65/NRGPrognose/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/NRGPrognose/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Me-blue?logo=paypal)](https://paypal.me/DietmarGureth)

Creates a **1–5-day consumption forecast** from your Symcon archive data and delivers it as a JSON profile
(P10/P50/P90 per time slot) for direct use by an EMS. Function prefix: `LFC`.

## How it works

Instead of forcing the consumption into rigid groups (time of day × season × weekday …), the module uses a
**similar-days method (k-NN)** to find the *k* most similar past days and condenses their measured daily
profiles into a forecast with an uncertainty band:

- **P50** – most likely course (median)
- **P10 / P90** – lower/upper limit (10 % or 90 % of the similar days were below it)

The similarity of a day is determined from the **day type** (working day / Saturday / Sunday + public
holiday), the **day length**, the **heating degree** (from the outdoor temperature) and **presence**.

## Requirements

- Symcon **9.0** or later
- An **archived power variable** of the house consumption (watts or kilowatts – detected automatically).
  Not a kWh counter.
- Optional, but recommended: outdoor temperature (archived) and a presence signal.

## Setup

| Area | Meaning |
|---|---|
| **House consumption** | Required: archived power variable. W/kW unit automatically or manually. If the field stays empty and there is exactly **one MeterHub meter with the function “house consumption”** (power in W, archived, current), its power is taken over automatically — the form shows the meter, the variable and the contract (“🔗 taken over automatically”). Several such meters are not guessed (⚠️, choose the variable yourself); a meter whose values do not look like a positive house load (sign unclear) is not taken automatically; your own variable always takes precedence. Other sources (derived EMS value, the inverter's “total load”) are deliberately not taken automatically. |
| **Deduction list** | Controllable loads (wallbox, battery charging, heat pump) that are **subtracted from the house consumption** – this way the module learns the plannable **base load**. Price-controlled charging is planned by the EMS itself. **These variables must be archived**, otherwise they cannot be subtracted (the status reports variables that are not archived). |
| **Subtract wallboxes automatically** *(optional, off by default)* | Detects wallboxes of the NRG-Stack hubs (ChargerHub/OCPPHub) through their interface and subtracts their **archived** charging power in addition to the deduction list. Each wallbox only once (matched by serial number, even if it can be reached through both hubs). Only switch it on if the house consumption variable **includes** the charging power; remove wallboxes entered by hand from the list then, otherwise they are subtracted twice. The status line names detected and ignored wallboxes. |
| **Heat pump / air conditioning** | Optional, per device through temperature regression: heating, cooling or both (V curve, e.g. air-to-air heat pump). From build 152 the device share enters the forecast (total = base load + devices; switch “include in the forecast”, on by default): daily kWh from the regression, daily profile from the 7 days with the most similar temperature, uncertainty band from the spread of the regression. The devices are subtracted automatically when learning the base load. The temperature forecast comes from OpenWeatherData or, if there is none, from PVPrognose (Open-Meteo). |
| **Presence** | Bool/0..1 (e.g. geofencing). Invert the logic for an *absence* variable. |
| **Temperature forecast** | Auto (OpenWeatherData instance), your own daily-mean variables, ident pattern or – without a source – the seasonal normal from the archive. With **several** OpenWeatherData instances, the relevant one can be chosen explicitly (empty = first one automatically). |
| **Resolution** | 60 / 30 / 15 minutes. 60 min is robust; finer grids need correspondingly fine archiving. |
| **Federal state** | For regional public holidays. |

## Status variables

| Ident | Description |
|---|---|
| `LFC_Today` / `LFC_Tomorrow` / `LFC_DayAfter` | Forecast profile as JSON (`p10/p50/p90/mean` in W per slot, `kwh`, `slots`, `resolution`) |
| `LFC_kWhToday` / `…Tomorrow` / `…DayAfter` | Daily energy of the forecast (kWh) |
| `LFC_WPkWhToday` / `…Tomorrow` / `…DayAfter` | Separate heat pump/air conditioning share (kWh), if configured |
| `LFC_ErrorMAPE` | Mean absolute error of the last days (%) |
| `LFC_Accuracy` | Forecast accuracy as text: number of days, bias, \|Ø error\| |
| `LFC_Status` / `LFC_LastUpdate` | Status and time of the last calculation |

## Uncertainty band from real forecast errors (optional)

By default, the P10/P90 band comes from the **spread of the similar days** — so it is often considerably
wider than the actual forecast error. Alternatively, the band can be formed from the **measured deviations**
of the last days (stored snapshot against actual); P90 then really means “in 90 % of cases the real value was
below this”.

| Mode | Effect |
|---|---|
| **Spread of the similar days** (default) | as before |
| **Band only** | P10/P90 from the real errors; forecast value (P50) and kWh stay unchanged |
| **Band + level correction** | additionally corrects a systematic error (bias) |

It only takes effect from **3 evaluable days** (and enough measuring points) — until then the default stays
active automatically. The effective factors are shown in the variable *Forecast accuracy*.

## Forecast accuracy (planned vs. actual)

With every recalculation, the earlier day-ahead forecast of past days (stored snapshots) is compared with the
measured actual. **Bias** shows a systematic over-/underestimate (+ = overestimated), **\|Ø error\|** the mean
absolute value. The values fill up after a few days of running.

**Special effects are excluded** (NRG-Stack, as soon as an EMS with `EMS_GetSpecialEvents` is installed): days
with an external control intervention (§14a dimming, Tibber balancing energy, direct marketing, EMS protective
shutdown, Grid Rewards or boost charging) enter neither bias/\|Ø error\| nor the residual quantiles nor the
similar-days search (learning material) — otherwise they would wrongly count a good forecast as an error or
learn the charging power as normal consumption. What counts is what an event distorts (field `affects` in EMS
contract 1.1): it counts if it affects the load; events that only affect the PV generation (e.g. negative
price) are left out. Without an EMS (or without this function) the behaviour stays unchanged. If the clean
history is not enough for k neighbours, the nearest special days are used to fill up.

## Public functions

```php
// Get the forecast of one day: $offset 0=today, 1=tomorrow, 2=day after tomorrow, 3/4=day 4/5
$fc = LFC_GetForecast(int $InstanceID, int $offset); // array with p10/p50/p90/mean/kwh …

// Stored forecast (planned) of a past day ('Y-m-d')
$snap = LFC_GetSnapshot(int $InstanceID, string $date); // [] if there is no snapshot

// Trigger an immediate recalculation
LFC_Rebuild(int $InstanceID);

// Expected consumption (kWh) in any time window, e.g. "from now until tomorrow
// morning, when the PV produces again" — for a dynamic battery target SoC instead
// of a fixed percentage. Covers up to 5 days (our horizon); 'coverage' (0..1)
// shows which share of the window is actually covered by forecast data (window
// without reference to the PV — the caller determines the PV start time itself,
// e.g. from PVF).
$w = LFC_GetEnergyWindow(int $InstanceID, int $fromTs, int $toTs);
// => ['contractVersion'=>'1.0', 'from'=>.., 'to'=>.., 'kwh'=>.., 'coverage'=>..]
```

## Languages

The module is bilingual (German/English): form, status messages, notices and the names of newly created
variables follow the Symcon language. The source texts are English, the German version is in `locale.json`.
Variables that already exist keep their names.

Part of the **[EnergiePrognose suite](https://github.com/DG65/NRGPrognose)** – together with *PVPrognose* (PV
forecast) and the forecast tile in the NRG dashboard.

> **Part of the NRG-Stack** — the energy module family by DG65 (measure · know · decide · control · show). The [manifest](https://github.com/DG65/NRGEMS/blob/main/SUITE.md) lists which module versions have been tested together.
