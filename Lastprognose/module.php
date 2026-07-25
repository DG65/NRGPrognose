<?php

// ============================================================
//  LoadForecast — Verbrauchsprognose für IP-Symcon
//  Autor   : DG65
//  Version : 0.1
//  GUID    : {DC5AD508-507F-40EA-8630-0959AED83050}
//
//  Konzept: Ähnliche-Tage-Verfahren (k-NN).
//  Für jeden Prognosetag wird ein Feature-Vektor gebildet
//  (Tagtyp, Tageslänge, Außentemperatur/Heizgrad, Anwesenheit).
//  Aus dem Archiv werden die k ähnlichsten Vergangenheitstage
//  gesucht und ihre Stundenprofile entfernungsgewichtet zu
//  P10/P50/P90 verdichtet. Optional: WP-Verbrauch separat über
//  lineare Temperaturregression (Heizgradtage).
// ============================================================

// Archive Control Modul-GUID (Kernmodul)
define('LFC_ARCHIVE_GUID', '{43192F0B-135B-4CE7-A0A7-1475603F3060}');

// Vertragsversion (Verbund-Konvention, additiv). Major.Minor; Major nur bei
// Bruch, Kompatibilität nur innerhalb derselben Major. Fehlend = '1.0'.
define('LFC_CONTRACT_FORECAST', '1.0'); // GetForecast / GetSnapshot

// OpenWeatherData (demel42) — für den Auto-Modus der Temperaturvorhersage.
// GUID stabil über alle Installationen; Instanz wird zur Laufzeit gesucht.
define('LFC_OWM_GUID',      '{8072158E-53BF-482A-B925-F4FBE522CEF2}');
define('LFC_OWM_IDENT_TIME','HourlyForecastBegin_%02d');
define('LFC_OWM_IDENT_MIN', 'HourlyForecastTemperatureMin_%02d');
define('LFC_OWM_IDENT_MAX', 'HourlyForecastTemperatureMax_%02d');
define('LFC_OWM_MAX_SLOTS', 50);

// Modi der Temperaturvorhersage
define('LFC_FC_AUTO',  0);  // OpenWeatherData automatisch, sonst Klimatologie
define('LFC_FC_DAILY', 1);  // drei Tagesmittel-Variablen
define('LFC_FC_IDENT', 2);  // Slot-Aggregation über frei wählbare Ident-Muster

// Betriebsart temperaturabhängiger Geräte (separate Prognose)
define('LFC_WP_HEAT', 0);   // nur Heizen   → kWh = a + b·Heizgrad
define('LFC_WP_COOL', 1);   // nur Kühlen   → kWh = a + c·Kühlgrad
define('LFC_WP_BOTH', 2);   // Heizen+Kühlen → V-Kurve a + b·Heizgrad + c·Kühlgrad

// Logging-Level
define('LFC_LOG_OFF',     0);
define('LFC_LOG_BASIC',   1);
define('LFC_LOG_VERBOSE', 2);

// Tagtypen
define('LFC_DT_WORK', 0);   // Werktag (Mo–Fr, kein Feiertag)
define('LFC_DT_SAT',  1);   // Samstag
define('LFC_DT_SUN',  2);   // Sonntag / Feiertag

// Zeitliche Auflösung (Minuten je Slot). 60 = stündlich (robust über
// AC_GetAggregatedValues), <60 = aus Rohwerten integriert (AC_GetLoggedValues).

class Lastprognose extends IPSModule
{
    // Request-lokaler Cache der Prognosetemperaturen [0=>heute,1=>morgen,2=>übermorgen]
    private $fcTempCache = null;
    // Request-lokaler Cache der automatisch erkannten Einheiten je Variable
    private $unitCache = [];
    // Request-lokaler Cache des Archiv-Logging-Status je Variable
    private $loggedCache = [];

    // ----------------------------------------------------------------
    //  Modul-Lebenszyklus
    // ----------------------------------------------------------------

    public function Create()
    {
        parent::Create();

        // ── Allgemein ───────────────────────────────────────────────
        $this->RegisterPropertyBoolean('LFC_Active',         false);
        $this->RegisterPropertyInteger('LFC_IntervalHours',  6);
        $this->RegisterPropertyInteger('LFC_Log_Level',      LFC_LOG_BASIC);

        // ── Datenquellen (Archiv) ───────────────────────────────────
        // Hauptverbrauch als LEISTUNG (W) — z.B. EMS_HousePower.
        $this->RegisterPropertyInteger('VAR_Consumption',    0);
        // Einheit der Leistungsvariablen: 0=W, 1=kW, 2=automatisch je Variable.
        $this->RegisterPropertyInteger('LFC_PowerUnit',      2);
        // Optional abzuziehende Verbraucher (WP, Wallbox …) als Liste.
        $this->RegisterPropertyString('ExcludeVars',         '[]');
        // Außentemperatur (Historie, °C).
        $this->RegisterPropertyInteger('VAR_TempHistory',    0);
        // Anwesenheit (bool/0..1), Historie.
        $this->RegisterPropertyInteger('VAR_Presence',       0);
        // Invertierte Logik: Variable meldet ABwesenheit statt Anwesenheit.
        $this->RegisterPropertyBoolean('LFC_PresenceInvert', false);

        // ── Prognose-Eingaben (Zukunft) ─────────────────────────────
        // Quelle der Vorhersagetemperatur. Bewusst modul-agnostisch:
        //   0 = Tagesmittel-Variablen (portabel, keine Abhängigkeit)
        //   1 = Slot-Aggregation über Ident-Muster (z.B. OWM/DWD)
        $this->RegisterPropertyInteger('LFC_TempFcMode',     0);
        // Auto-Modus: konkrete OpenWeatherData-Instanz (0 = erste automatisch).
        $this->RegisterPropertyInteger('LFC_OwmInstance',    0);

        // Modus 0: je eine Tagesmittel-Variable.
        $this->RegisterPropertyInteger('VAR_TempFc_D0',      0);
        $this->RegisterPropertyInteger('VAR_TempFc_D1',      0);
        $this->RegisterPropertyInteger('VAR_TempFc_D2',      0);

        // Modus 1: Eltern-Objekt + Ident-Muster der Slot-Variablen.
        // Platzhalter %d / %02d wird durch den Slot-Index ersetzt.
        $this->RegisterPropertyInteger('LFC_FcParentID',     0);
        $this->RegisterPropertyString('LFC_FcTempIdentLow',  '');
        $this->RegisterPropertyString('LFC_FcTempIdentHigh', '');
        $this->RegisterPropertyString('LFC_FcTimeIdent',     '');
        $this->RegisterPropertyInteger('LFC_FcStartIndex',   0);
        $this->RegisterPropertyInteger('LFC_FcCount',        40);

        // Geplante Anwesenheit (bool/0..1); leer = aktueller Zustand.
        $this->RegisterPropertyInteger('VAR_PresenceFc',     0);

        // ── Modellparameter ─────────────────────────────────────────
        $this->RegisterPropertyInteger('LFC_LookbackDays',   365);
        $this->RegisterPropertyInteger('LFC_K',              12);
        $this->RegisterPropertyFloat(  'LFC_Latitude',       49.0);
        $this->RegisterPropertyFloat(  'LFC_HDD_Base',       15.0);
        // Zeitliche Auflösung in Minuten je Slot (60/30/15).
        $this->RegisterPropertyInteger('LFC_Resolution',     60);
        // Bundesland-Kürzel für regionale Feiertage ('' = nur bundesweite).
        $this->RegisterPropertyString('LFC_State',           '');

        // ── Temperaturabhängige Geräte (optional, separate Prognose) ─
        // Liste je Gerät: { "PowerVar": <id>, "Mode": 0=Heizen|1=Kühlen|2=beides }.
        $this->RegisterPropertyString('WPDevices',           '[]');
        $this->RegisterPropertyFloat('LFC_CDD_Base',         22.0);
        // Legacy-Einzelfeld (vor 0.3) — bleibt als Fallback erhalten.
        $this->RegisterPropertyInteger('VAR_WP_Power',       0);

        // ── Ausgabe-Variablen ───────────────────────────────────────
        $this->ensureNrgPercentProfile();
        $this->RegisterVariableString('LFC_Today',     'Prognose heute (JSON)',     '', 10);
        $this->RegisterVariableString('LFC_Tomorrow',  'Prognose morgen (JSON)',    '', 20);
        $this->RegisterVariableString('LFC_DayAfter',  'Prognose übermorgen (JSON)','', 30);
        $this->RegisterVariableFloat( 'LFC_kWhToday',     'Erwartung heute (kWh)',    '~Electricity', 40);
        $this->RegisterVariableFloat( 'LFC_kWhTomorrow',  'Erwartung morgen (kWh)',   '~Electricity', 50);
        $this->RegisterVariableFloat( 'LFC_kWhDayAfter',  'Erwartung übermorgen (kWh)','~Electricity', 60);
        $this->RegisterVariableFloat( 'LFC_WPkWhToday',   'Erwartung WP/Klima heute (kWh)',     '~Electricity', 70);
        $this->RegisterVariableFloat( 'LFC_WPkWhTomorrow','Erwartung WP/Klima morgen (kWh)',    '~Electricity', 71);
        $this->RegisterVariableFloat( 'LFC_WPkWhDayAfter','Erwartung WP/Klima übermorgen (kWh)','~Electricity', 72);
        $this->RegisterVariableString('LFC_Status',    'Status',                    '', 80);
        $this->RegisterVariableInteger('LFC_LastUpdate','Letzte Berechnung',        '~UnixTimestamp', 90);
        $this->RegisterVariableFloat( 'LFC_ErrorMAPE', 'Prognosefehler |Ø| (%)',    'NRG.Percent', 92);
        $this->RegisterVariableString('LFC_Accuracy',  'Prognosegüte (Soll vs. Ist)','', 94);

        // ── Timer ───────────────────────────────────────────────────
        // Tages-Snapshots der Prognose (für spätere Soll-vs-Ist-Kontrolle je Tag)
        $this->RegisterAttributeString('LFC_Snapshots', '');
        // Empirische Quantile der Prognosefehler (Ist/Soll) für Band/Korrektur.
        $this->RegisterAttributeString('LFC_Residuals', '');
        // 0 = aus (Band aus k-NN-Nachbarn), 1 = Band aus Residuen,
        // 2 = Band + Bias-Korrektur aus Residuen.
        $this->RegisterPropertyInteger('LFC_ResidualMode', 0);

        $this->RegisterTimer('LFC_RebuildTimer', 0, 'LFC_Rebuild($_IPS[\'TARGET\']);');
    }

    public function Destroy()
    {
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $active = $this->ReadPropertyBoolean('LFC_Active');
        $hours  = max(1, $this->ReadPropertyInteger('LFC_IntervalHours'));

        if ($active) {
            $this->SetTimerInterval('LFC_RebuildTimer', $hours * 3600 * 1000);
            $this->SetStatus(102);
            $this->log(LFC_LOG_BASIC, 'Aktiv, Neuberechnung alle ' . $hours . ' h');
        } else {
            $this->SetTimerInterval('LFC_RebuildTimer', 0);
            $this->SetStatus(104);
            $this->log(LFC_LOG_BASIC, 'Deaktiviert');
        }
    }

    // ----------------------------------------------------------------
    //  Öffentliche Funktionen
    // ----------------------------------------------------------------

    /**
     * Vollständige Neuberechnung aller Prognose-Horizonte.
     */
    public function Rebuild()
    {
        if ($this->ReadPropertyInteger('VAR_Consumption') <= 0) {
            $this->SetValue('LFC_Status', 'Keine Verbrauchsvariable konfiguriert');
            return;
        }

        try {
            $idents = ['LFC_Today', 'LFC_Tomorrow', 'LFC_DayAfter'];
            $kwhIds = ['LFC_kWhToday', 'LFC_kWhTomorrow', 'LFC_kWhDayAfter'];

            $fcs = [];
            for ($offset = 0; $offset <= 2; $offset++) {
                $fc = $this->GetForecast($offset);
                $fcs[$offset] = $fc;
                $this->SetValue($idents[$offset], json_encode($fc));
                $this->SetValue($kwhIds[$offset], round($fc['kwh'], 2));
            }
            $this->saveSnapshot($fcs);
            $this->evaluateAccuracy();

            // Optional: separate WP-/Klima-Prognose für heute/morgen/übermorgen
            $wpIds = ['LFC_WPkWhToday', 'LFC_WPkWhTomorrow', 'LFC_WPkWhDayAfter'];
            for ($offset = 0; $offset <= 2; $offset++) {
                $wp = $this->wpForecast($offset);
                if ($wp !== null) {
                    $this->SetValue($wpIds[$offset], round($wp, 2));
                }
            }

            $this->SetValue('LFC_LastUpdate', time());
            $status = sprintf(
                'OK | heute %.1f / morgen %.1f / übermorgen %.1f kWh',
                $this->GetValue('LFC_kWhToday'),
                $this->GetValue('LFC_kWhTomorrow'),
                $this->GetValue('LFC_kWhDayAfter')
            );
            // Nicht archivierte Variablen melden (werden ignoriert / nicht abgezogen).
            $missing = $this->unloggedVars();
            if (count($missing) > 0) {
                $status .= ' | ⚠ nicht archiviert (ignoriert): ' . implode(', ', $missing);
                $this->log(LFC_LOG_BASIC, 'Nicht archivierte Variablen: ' . implode(', ', $missing));
            }
            $this->SetValue('LFC_Status', $status);
            $this->SetStatus(102);
            $this->log(LFC_LOG_BASIC, 'Neuberechnung abgeschlossen');

        } catch (Exception $e) {
            $this->SetValue('LFC_Status', 'Fehler: ' . $e->getMessage());
            $this->log(LFC_LOG_BASIC, 'Fehler: ' . $e->getMessage());
        }
    }

    /**
     * Liefert die Prognose für einen Tag als Array.
     * $offset: 0 = heute, 1 = morgen, 2 = übermorgen.
     * Rückgabe: ['date','slots','p10','p50','p90','kwh','neighbors']
     * Für das EMS per LFC_GetForecast($id, $offset) abrufbar.
     */
    public function GetForecast(int $offset)
    {
        $targetTs = strtotime('today +' . $offset . ' days');
        $tf       = $this->dayFeatures($targetTs, true);

        $lookback = $this->ReadPropertyInteger('LFC_LookbackDays');
        $k        = max(1, $this->ReadPropertyInteger('LFC_K'));

        // Kandidaten: alle Tage von gestern rückwärts.
        $cands = [];
        for ($d = 1; $d <= $lookback; $d++) {
            $ts      = strtotime('today -' . $d . ' days');
            $profile = $this->getDayProfile($ts);
            if ($profile === null) {
                continue; // kein/zu wenig Datum an diesem Tag
            }
            $cf   = $this->dayFeatures($ts, false);
            $dist = $this->distance($tf, $cf);
            $cands[] = ['dist' => $dist, 'profile' => $profile];
        }

        if (count($cands) === 0) {
            return $this->emptyForecast($targetTs);
        }

        // k nächste Nachbarn auswählen.
        usort($cands, function ($a, $b) {
            return $a['dist'] <=> $b['dist'];
        });
        $neighbors = array_slice($cands, 0, $k);

        // Entfernungsgewichtung (Gauß-Kernel über mittlere Distanz).
        $distSum = 0.0;
        foreach ($neighbors as $n) { $distSum += $n['dist']; }
        $sigma   = max(0.0001, $distSum / max(1, count($neighbors)));
        foreach ($neighbors as $i => $n) {
            $neighbors[$i]['w'] = exp(-0.5 * ($n['dist'] * $n['dist']) / ($sigma * $sigma));
        }

        // Pro Slot P10/P50/P90 und gewichteten Mittelwert bilden.
        $slots = $this->slots();
        $p10 = []; $p50 = []; $p90 = []; $mean = [];
        for ($s = 0; $s < $slots; $s++) {
            $pairs = [];
            $wsum  = 0.0; $vsum = 0.0;
            foreach ($neighbors as $n) {
                $v = $n['profile'][$s];
                $pairs[] = ['v' => $v, 'w' => $n['w']];
                $wsum += $n['w'];
                $vsum += $n['w'] * $v;
            }
            $mean[$s] = ($wsum > 0) ? $vsum / $wsum : 0.0;
            $p10[$s]  = $this->weightedPercentile($pairs, 0.10);
            $p50[$s]  = $this->weightedPercentile($pairs, 0.50);
            $p90[$s]  = $this->weightedPercentile($pairs, 0.90);
        }

        // Band (und optional Pegel) aus den gemessenen Prognosefehlern ableiten.
        list($p10, $p50, $p90, $mean) = $this->applyResiduals($p10, $p50, $p90, $mean);

        // Ø-Leistung (W) je Slot → Energie mit Slot-Dauer in Stunden.
        $kwh = array_sum($mean) * $this->slotHours() / 1000.0;

        return [
            'contractVersion' => LFC_CONTRACT_FORECAST,
            'date'      => date('Y-m-d', $targetTs),
            'slots'     => $slots,
            'resolution'=> $this->slotMinutes() . 'min',
            'unit'      => 'W',
            'p10'       => array_map(function ($x) { return round($x, 1); }, $p10),
            'p50'       => array_map(function ($x) { return round($x, 1); }, $p50),
            'p90'       => array_map(function ($x) { return round($x, 1); }, $p90),
            'mean'      => array_map(function ($x) { return round($x, 1); }, $mean),
            'kwh'       => round($kwh, 2),
            'neighbors' => count($neighbors),
        ];
    }

    public function GetStatusText()
    {
        return (string)$this->GetValue('LFC_Status');
    }

    /**
     * Gespeicherte Prognose (Soll) eines vergangenen Tages ('Y-m-d').
     * Rückgabe [] wenn kein Snapshot vorhanden.
     */
    public function GetSnapshot(string $date)
    {
        $snaps = json_decode($this->ReadAttributeString('LFC_Snapshots'), true);
        if (!is_array($snaps) || !isset($snaps[$date])) { return []; }
        return array_merge(['contractVersion' => LFC_CONTRACT_FORECAST], $snaps[$date]);
    }

    /**
     * Prognosegüte: vergleicht je vergangenem Tag (bis 7 zurück) den
     * Day-Ahead-Snapshot (Soll-kWh) mit dem gemessenen Ist aus dem Archiv
     * (identisch zum Trainings-Ziel: Hauptverbrauch minus Abzugsliste).
     * Bias = mittlere vorzeichenbehaftete Abweichung, |Ø| = mittlerer Betrag.
     */
    private function evaluateAccuracy()
    {
        $snaps = json_decode($this->ReadAttributeString('LFC_Snapshots'), true);
        if (!is_array($snaps)) { $snaps = []; }

        $slots  = $this->slots();
        $errs   = [];   // Tages-kWh-Fehler (%) → Bias/MAPE
        $ratios = [];   // Slot-Verhältnisse Ist/Soll → Residuen-Quantile
        $rDays  = 0;
        $excluded = 0;  // Tage mit Sondereffekt (EMS_GetSpecialEvents) ausgeschlossen

        $specialEvents = $this->fetchSpecialEvents(14);

        for ($d = 1; $d <= 14; $d++) {
            $ts   = strtotime('today -' . $d . ' days');
            $date = date('Y-m-d', $ts);
            if (!isset($snaps[$date])) { continue; }
            if ($this->dayHasSpecialEvent($specialEvents, $ts, $ts + 86400 - 1)) { $excluded++; continue; }
            $soll = (float)($snaps[$date]['kwh'] ?? 0);
            if ($soll <= 0) { continue; }
            $prof = $this->getDayProfile($ts);
            if ($prof === null) { continue; }
            $ist = array_sum($prof) * $this->slotHours() / 1000.0;
            if ($ist < 0.5) { continue; }
            $errs[] = ($soll - $ist) / $ist * 100.0;

            // Slot-Residuen nur bei gleicher Auflösung (Snapshot vs. heute).
            $sp = $snaps[$date]['p50'] ?? null;
            if (!is_array($sp) || count($sp) !== $slots || count($prof) !== $slots) { continue; }
            $maxS = max($sp);
            if ($maxS <= 0) { continue; }
            $floor = max(10.0, 0.02 * $maxS); // Mini-Nenner ausschließen (Nacht/0-Werte)
            $used  = 0;
            for ($i = 0; $i < $slots; $i++) {
                $s = (float)$sp[$i];
                if ($s < $floor) { continue; }
                $ratios[] = ((float)$prof[$i]) / $s;
                $used++;
            }
            if ($used > 0) { $rDays++; }
        }

        $this->storeResiduals($ratios, $rDays);

        if (count($errs) === 0) {
            $this->SetValue('LFC_Accuracy', 'Noch keine auswertbaren Tage (Snapshots sammeln sich seit v0.14)');
            return;
        }
        $bias = array_sum($errs) / count($errs);
        $mape = array_sum(array_map('abs', $errs)) / count($errs);
        $this->SetValue('LFC_ErrorMAPE', round($mape, 1));
        $txt = sprintf('%d Tage: Bias %+.1f %% · |Ø-Fehler| %.1f %%', count($errs), $bias, $mape);
        $res = json_decode($this->ReadAttributeString('LFC_Residuals'), true);
        if (is_array($res) && isset($res['q10'])) {
            $txt .= sprintf(' | Residuen ×%.2f…×%.2f (Median ×%.2f, %d Tage)',
                $res['q10'], $res['q90'], $res['q50'], $res['days']);
        }
        if ($excluded > 0) {
            $txt .= sprintf(' | %d Tag(e) mit Sondereffekt ausgeschlossen', $excluded);
        }
        $this->SetValue('LFC_Accuracy', $txt);
        $this->log(LFC_LOG_BASIC, sprintf('Prognosegüte (%d Tage): Bias %+.1f %%, MAPE %.1f %%', count($errs), $bias, $mape));
    }

    /**
     * Empirische Quantile der Prognosefehler (Ist/Soll je Slot) ablegen.
     * Braucht eine Mindestbasis, sonst wird nichts gespeichert (→ k-NN-Band bleibt).
     */
    private function storeResiduals(array $ratios, int $days)
    {
        if ($days < 3 || count($ratios) < 50) {
            $this->WriteAttributeString('LFC_Residuals', '');
            return;
        }
        sort($ratios);
        $q10 = $this->clampFactor($this->percentileOf($ratios, 0.10));
        $q50 = $this->clampFactor($this->percentileOf($ratios, 0.50));
        $q90 = $this->clampFactor($this->percentileOf($ratios, 0.90));
        $this->WriteAttributeString('LFC_Residuals', json_encode([
            'q10' => round($q10, 3), 'q50' => round($q50, 3), 'q90' => round($q90, 3),
            'days' => $days, 'samples' => count($ratios), 'updated' => time(),
        ]));
    }

    /** Perzentil aus einer aufsteigend sortierten Liste. */
    private function percentileOf(array $sorted, float $p): float
    {
        $n = count($sorted);
        if ($n === 0) { return 1.0; }
        $idx = (int)floor($p * ($n - 1));
        return (float)$sorted[max(0, min($n - 1, $idx))];
    }

    /** Korrekturfaktoren in einen plausiblen Bereich zwingen. */
    private function clampFactor(float $f): float
    {
        return max(0.3, min(3.0, $f));
    }

    /**
     * Band (und optional Bias) aus den gemessenen Prognosefehlern statt aus der
     * Streuung der k-NN-Nachbarn. Modus 1 lässt p50/kWh unverändert und legt nur
     * das Band (auf den Median normiert) darum; Modus 2 korrigiert zusätzlich
     * den Pegel. Ohne ausreichende Datenbasis bleibt alles unverändert.
     */
    private function applyResiduals(array $p10, array $p50, array $p90, array $mean): array
    {
        $mode = $this->ReadPropertyInteger('LFC_ResidualMode');
        if ($mode === 0) { return [$p10, $p50, $p90, $mean]; }

        $r = json_decode($this->ReadAttributeString('LFC_Residuals'), true);
        if (!is_array($r) || !isset($r['q10'], $r['q50'], $r['q90'])) {
            return [$p10, $p50, $p90, $mean];
        }
        $q10 = (float)$r['q10']; $q50 = (float)$r['q50']; $q90 = (float)$r['q90'];
        if ($q50 <= 0) { return [$p10, $p50, $p90, $mean]; }

        $nP10 = []; $nP50 = []; $nP90 = []; $nMean = $mean;
        if ($mode === 1) {
            // Nur Streuung: Band auf den Median normiert → p50/kWh bleiben.
            $lo = $q10 / $q50; $hi = $q90 / $q50;
            foreach ($p50 as $i => $v) { $nP10[$i] = $v * $lo; $nP90[$i] = $v * $hi; }
            return [$nP10, $p50, $nP90, $mean];
        }
        // Modus 2: vollständige Kalibrierung (Pegel + Band).
        foreach ($p50 as $i => $v) {
            $nP10[$i] = $v * $q10;
            $nP50[$i] = $v * $q50;
            $nP90[$i] = $v * $q90;
        }
        foreach ($mean as $i => $v) { $nMean[$i] = $v * $q50; }
        return [$nP10, $nP50, $nP90, $nMean];
    }

    /**
     * Speichert je Tag genau einen Prognose-Snapshot (Soll): heute + morgen,
     * jeweils nur wenn für das Datum noch keiner existiert → jeder Tag behält
     * den frühesten (Day-Ahead-)Stand. Auf die letzten 14 Tage begrenzt.
     */
    private function saveSnapshot(array $fcs)
    {
        $snaps = json_decode($this->ReadAttributeString('LFC_Snapshots'), true);
        if (!is_array($snaps)) { $snaps = []; }

        foreach ([0, 1] as $offset) {
            if (!isset($fcs[$offset])) { continue; }
            $fc   = $fcs[$offset];
            $date = date('Y-m-d', strtotime('today +' . $offset . ' days'));
            if (isset($snaps[$date])) { continue; }
            $sum = array_sum($fc['p50'] ?? []);
            if ($sum <= 0) { continue; } // nichts Sinnvolles (z.B. keine Nachbarn)
            $snaps[$date] = [
                'slots'      => $fc['slots'],
                'resolution' => $fc['resolution'],
                'p50'        => $fc['p50'],
                'kwh'        => $fc['kwh'],
            ];
        }

        krsort($snaps);
        $snaps = array_slice($snaps, 0, 14, true);
        $this->WriteAttributeString('LFC_Snapshots', json_encode($snaps));
    }

    // ----------------------------------------------------------------
    //  Feature-Engineering
    // ----------------------------------------------------------------

    /**
     * Bildet den Feature-Vektor eines Tages.
     * $future = true → Prognose-Eingaben (Wettervorhersage, geplante
     * Anwesenheit). $future = false → historische Archivwerte.
     */
    private function dayFeatures(int $ts, bool $future)
    {
        $dt   = $this->dayType($ts);
        $dl   = $this->dayLength($ts);

        if ($future) {
            $temp = $this->forecastTemp($ts);
            $pres = $this->forecastPresence();
        } else {
            $temp = $this->dailyMean($this->ReadPropertyInteger('VAR_TempHistory'), $ts);
            if ($temp === null) { $temp = $this->ReadPropertyFloat('LFC_HDD_Base'); }
            $pres = $this->dailyMean($this->ReadPropertyInteger('VAR_Presence'), $ts);
            if ($pres === null) { $pres = $this->ReadPropertyBoolean('LFC_PresenceInvert') ? 0.0 : 1.0; }
            $pres = $this->applyPresence($pres);
        }

        $base = $this->ReadPropertyFloat('LFC_HDD_Base');
        $hdd  = max(0.0, $base - $temp); // Heizgrad

        return ['dt' => $dt, 'dl' => $dl, 'hdd' => $hdd, 'pres' => $pres];
    }

    /**
     * Gewichtete euklidische Distanz im Feature-Raum.
     * Tagtyp kategorial (harter Aufschlag bei Abweichung), übrige
     * Features auf vergleichbare Skalen normiert.
     */
    private function distance(array $a, array $b)
    {
        // Gewichte (siehe README): Tagtyp dominiert die Form.
        $wDT = 4.0; $wDL = 1.0; $wHDD = 2.0; $wPres = 3.0;
        $sDL = 4.0;  // h
        $sHDD = 8.0; // K

        $d2  = $wDT  * (($a['dt'] !== $b['dt']) ? 1.0 : 0.0);
        $d2 += $wDL  * pow(($a['dl']  - $b['dl'])  / $sDL,  2);
        $d2 += $wHDD * pow(($a['hdd'] - $b['hdd']) / $sHDD, 2);
        $d2 += $wPres * pow(($a['pres'] - $b['pres']), 2);

        return sqrt($d2);
    }

    /**
     * Tagtyp: Sonntag/Feiertag = 2, Samstag = 1, sonst Werktag = 0.
     */
    private function dayType(int $ts)
    {
        if ($this->isHoliday($ts)) { return LFC_DT_SUN; }
        $wd = (int)date('N', $ts); // 1=Mo … 7=So
        if ($wd === 7) { return LFC_DT_SUN; }
        if ($wd === 6) { return LFC_DT_SAT; }
        return LFC_DT_WORK;
    }

    /**
     * Tageslänge (Sonnenscheindauer in Stunden) als Saison-Proxy.
     * CBM-Modell nach Forsythe et al., abhängig von Breitengrad
     * und Tag des Jahres.
     */
    private function dayLength(int $ts)
    {
        $lat = deg2rad($this->ReadPropertyFloat('LFC_Latitude'));
        $n   = (int)date('z', $ts) + 1; // Tag des Jahres 1..366
        $p   = asin(0.39795 * cos(0.2163108 + 2 * atan(0.9671396 * tan(0.00860 * ($n - 186)))));
        $arg = (sin(deg2rad(0.8333)) + sin($lat) * sin($p)) / (cos($lat) * cos($p));
        $arg = max(-1.0, min(1.0, $arg));
        return 24.0 - (24.0 / M_PI) * acos($arg);
    }

    /**
     * Deutsche Feiertage. Bundesweite immer, regionale je nach
     * konfiguriertem Bundesland (LFC_State, '' = nur bundesweite).
     */
    private function isHoliday(int $ts)
    {
        $y     = (int)date('Y', $ts);
        $md    = date('m-d', $ts);
        $ymd   = date('Y-m-d', $ts);
        $state = strtoupper(trim($this->ReadPropertyString('LFC_State')));

        // Bundesweite feste Feiertage
        $fixed = ['01-01', '05-01', '10-03', '12-25', '12-26'];

        // Regionale feste Feiertage je Bundesland
        $heiligeDreiKoenige = ['BW', 'BY', 'ST'];
        $allerheiligen      = ['BW', 'BY', 'NW', 'RP', 'SL'];
        $reformationstag    = ['BB', 'MV', 'SN', 'ST', 'TH', 'HB', 'HH', 'NI', 'SH'];
        if (in_array($state, $heiligeDreiKoenige, true)) { $fixed[] = '01-06'; }
        if (in_array($state, $allerheiligen, true))      { $fixed[] = '11-01'; }
        if (in_array($state, $reformationstag, true))    { $fixed[] = '10-31'; }
        if ($state === 'SL')                              { $fixed[] = '08-15'; } // Mariä Himmelfahrt
        if ($state === 'BE')                              { $fixed[] = '03-08'; } // Frauentag
        if ($state === 'MV')                              { $fixed[] = '03-08'; }
        if ($state === 'TH')                              { $fixed[] = '09-20'; } // Weltkindertag

        if (in_array($md, $fixed, true)) { return true; }

        // Bundesweite osterbasierte Feiertage
        $easter  = easter_date($y); // Ostersonntag (Mittag)
        $movable = [
            strtotime('-2 days', $easter),  // Karfreitag
            strtotime('+1 day',  $easter),  // Ostermontag
            strtotime('+39 days', $easter), // Christi Himmelfahrt
            strtotime('+50 days', $easter), // Pfingstmontag
        ];
        // Fronleichnam (Ostern +60) — regional
        $fronleichnam = ['BW', 'BY', 'HE', 'NW', 'RP', 'SL'];
        if (in_array($state, $fronleichnam, true)) {
            $movable[] = strtotime('+60 days', $easter);
        }
        foreach ($movable as $m) {
            if (date('Y-m-d', $m) === $ymd) { return true; }
        }

        // Buß- und Bettag (Mittwoch vor dem 23.11.) — nur Sachsen
        if ($state === 'SN' && $ymd === $this->bussUndBettag($y)) { return true; }

        return false;
    }

    /** Buß- und Bettag: Mittwoch vor dem 23. November. */
    private function bussUndBettag(int $y): string
    {
        $ref = strtotime($y . '-11-23');
        $dow = (int)date('w', $ref);           // 0=So … 3=Mi
        $back = ($dow >= 3) ? ($dow - 3) : ($dow + 4);
        return date('Y-m-d', strtotime("-{$back} days", $ref));
    }

    // ----------------------------------------------------------------
    //  Archivzugriff
    // ----------------------------------------------------------------

    /**
     * Tagesprofil (slots() Werte, Ø-Leistung in W) eines Tages.
     * Zieht optional konfigurierte Verbraucher (WP, Wallbox) ab,
     * sodass die planbare Grundlast übrig bleibt.
     * Rückgabe null, wenn der Tag kaum Daten hat.
     */
    private function getDayProfile(int $ts)
    {
        $slots = $this->slots();
        $main = $this->dayProfile($this->ReadPropertyInteger('VAR_Consumption'), $ts);
        if ($main === null) { return null; }

        $excludes = json_decode($this->ReadPropertyString('ExcludeVars'), true);
        if (is_array($excludes)) {
            foreach ($excludes as $row) {
                $vid = isset($row['VariableID']) ? (int)$row['VariableID'] : 0;
                if ($vid <= 0) { continue; }
                $sub = $this->dayProfile($vid, $ts);
                if ($sub === null) { continue; }
                for ($s = 0; $s < $slots; $s++) {
                    $main[$s] = max(0.0, $main[$s] - $sub[$s]);
                }
            }
        }
        return $main;
    }

    /**
     * Ø-Leistung (W) je Slot einer Variablen für einen Tag.
     * 60 min: schnell über AC_GetAggregatedValues (Stundenaggregat).
     * <60 min: zeitgewichtet aus den Rohwerten (AC_GetLoggedValues).
     * Erwartet eine LEISTUNGS-Variable (W). Rückgabe null bei
     * unzureichender Datenlage (< halber Tag belegt).
     */
    private function dayProfile(int $varID, int $ts)
    {
        if ($varID <= 0 || !IPS_VariableExists($varID)) { return null; }
        $aid = $this->getArchiveID();
        if ($aid === 0 || !$this->isLogged($aid, $varID)) { return null; }

        $slots = $this->slots();
        $start = strtotime('today', $ts);
        $end   = $this->clampEnd($start + 86400 - 1);

        if ($this->slotMinutes() === 60) {
            $profile = array_fill(0, $slots, null);
            $rows = AC_GetAggregatedValues($aid, $varID, 0 /* stündlich */, $start, $end, 0);
            if (!is_array($rows) || count($rows) === 0) { return null; }
            foreach ($rows as $r) {
                $h = (int)date('G', $r['TimeStamp']);
                if ($h >= 0 && $h < $slots) { $profile[$h] = (float)$r['Avg']; }
            }
            return $this->scaleProfile($this->finishProfile($profile, $slots), $varID);
        }

        return $this->scaleProfile($this->integratedProfile($aid, $varID, $start, $end, $slots), $varID);
    }

    /** Profil auf W normieren (Einheit je Variable: manuell oder automatisch). */
    private function scaleProfile($profile, int $varID)
    {
        if (!is_array($profile)) { return $profile; }
        $f = $this->varPowerFactor($varID);
        if ($f === 1.0) { return $profile; }
        foreach ($profile as $i => $v) { if ($v !== null) { $profile[$i] = $v * $f; } }
        return $profile;
    }

    /**
     * Zeitgewichtetes Slot-Profil aus den Rohwerten. Jeder geloggte Wert
     * gilt bis zum nächsten Wechsel; die Leistung wird über die Slots
     * integriert (Ø-Leistung = Σ v·Δt / Σ Δt je Slot).
     */
    private function integratedProfile(int $aid, int $varID, int $start, int $end, int $slots)
    {
        $slotSec = 86400.0 / $slots;

        // Wert, der zu Tagesbeginn aktiv ist (letzter Wechsel davor).
        $carry = null;
        $pre = AC_GetLoggedValues($aid, $varID, 0, $start - 1, 1);
        if (is_array($pre) && count($pre) > 0) { $carry = (float)$pre[0]['Value']; }

        $rows = AC_GetLoggedValues($aid, $varID, $start, $end, 0);
        if (!is_array($rows)) { $rows = []; }
        usort($rows, function ($a, $b) { return $a['TimeStamp'] <=> $b['TimeStamp']; });

        // Zeitachse aufbauen: (Zeit, Wert) ab Tagesbeginn.
        $points = [];
        $first  = ($carry !== null) ? $carry : (count($rows) > 0 ? (float)$rows[0]['Value'] : null);
        $points[] = ['t' => $start, 'v' => $first];
        foreach ($rows as $r) {
            $t = (int)$r['TimeStamp'];
            if ($t > $start && $t <= $end) { $points[] = ['t' => $t, 'v' => (float)$r['Value']]; }
        }
        if ($first === null && count($points) <= 1) { return null; }

        $sumW   = array_fill(0, $slots, 0.0);
        $sumSec = array_fill(0, $slots, 0.0);
        $cnt    = count($points);
        for ($p = 0; $p < $cnt; $p++) {
            $v  = $points[$p]['v'];
            if ($v === null) { continue; }
            $t0 = $points[$p]['t'];
            $t1 = ($p + 1 < $cnt) ? $points[$p + 1]['t'] : ($end + 1);
            while ($t0 < $t1) {
                $slot    = (int)(($t0 - $start) / $slotSec);
                if ($slot < 0 || $slot >= $slots) { break; }
                $slotEnd = $start + ($slot + 1) * $slotSec;
                $segEnd  = min($t1, $slotEnd);
                $dur     = $segEnd - $t0;
                $sumW[$slot]   += $v * $dur;
                $sumSec[$slot] += $dur;
                $t0 = $segEnd;
            }
        }

        $profile = array_fill(0, $slots, null);
        for ($s = 0; $s < $slots; $s++) {
            if ($sumSec[$s] > 0) { $profile[$s] = $sumW[$s] / $sumSec[$s]; }
        }
        return $this->finishProfile($profile, $slots);
    }

    /** Mindestabdeckung prüfen und Lücken per Nachbarwert füllen. */
    private function finishProfile(array $profile, int $slots)
    {
        $have = 0;
        foreach ($profile as $v) { if ($v !== null) { $have++; } }
        if ($have < $slots / 2) { return null; }

        $last = 0.0;
        for ($s = 0; $s < $slots; $s++) {
            if ($profile[$s] === null) { $profile[$s] = $last; }
            else { $last = $profile[$s]; }
        }
        return $profile;
    }

    /**
     * Tagesmittel einer Variablen aus dem Archiv (z.B. Temperatur,
     * Anwesenheitsanteil). Rückgabe null bei fehlenden Daten.
     */
    private function dailyMean(int $varID, int $ts)
    {
        if ($varID <= 0 || !IPS_VariableExists($varID)) { return null; }
        $aid = $this->getArchiveID();
        if ($aid === 0 || !$this->isLogged($aid, $varID)) { return null; }

        $start = strtotime('today', $ts);
        $end   = $this->clampEnd($start + 86400 - 1);

        $rows = AC_GetAggregatedValues($aid, $varID, 1 /* täglich */, $start, $end, 0);
        if (!is_array($rows) || count($rows) === 0) { return null; }
        return (float)$rows[0]['Avg'];
    }

    private function getArchiveID()
    {
        $ids = IPS_GetInstanceListByModuleID(LFC_ARCHIVE_GUID);
        return (count($ids) > 0) ? $ids[0] : 0;
    }

    /**
     * Legt das verbund-weite, geteilte Profil NRG.Percent an, falls es noch
     * nicht existiert (kein Eigentümer-Modul — wer zuerst startet, erzeugt
     * es). Nur bei der Erstanlage der Variable wirksam (RegisterVariableFloat
     * setzt das Profil nur beim ersten Anlegen, siehe IPS-Verhalten).
     */
    private function ensureNrgPercentProfile()
    {
        if (IPS_VariableProfileExists('NRG.Percent')) { return; }
        IPS_CreateVariableProfile('NRG.Percent', VARIABLETYPE_FLOAT);
        IPS_SetVariableProfileValues('NRG.Percent', 0, 100, 0);
        IPS_SetVariableProfileDigits('NRG.Percent', 1);
        IPS_SetVariableProfileText('NRG.Percent', '', ' %');
    }

    /**
     * Sondereffekte (§14a, Tibber-Regelenergie, Vermarktung, EMS-Schutz) der
     * letzten $lookbackDays über EMS_GetSpecialEvents (Verbund-Vertrag 1.0)
     * abfragen. Standalone-fähig: ohne EMS bleibt die Liste leer, wirkt sich
     * nirgends aus (kein Fehler, keine Warnung).
     */
    private function fetchSpecialEvents(int $lookbackDays): array
    {
        if (!function_exists('EMS_GetSpecialEvents')) { return []; }
        $from = strtotime('today -' . $lookbackDays . ' days');
        $to   = time();
        $events = @EMS_GetSpecialEvents(0, $from, $to);
        return is_array($events) ? $events : [];
    }

    /** Überlappt irgendein Sondereffekt-Fenster den Tag [$dayStart, $dayEnd]? */
    private function dayHasSpecialEvent(array $events, int $dayStart, int $dayEnd): bool
    {
        foreach ($events as $e) {
            $from = (int)($e['from'] ?? 0);
            $to   = (int)($e['to'] ?? 0);
            $effectiveTo = ($to > 0) ? $to : time(); // 0 = noch andauernd → bis jetzt
            if ($from <= $dayEnd && $effectiveTo >= $dayStart) { return true; }
        }
        return false;
    }

    /**
     * Ist die Variable im Archiv geloggt? Verhindert Archiv-Warnungen
     * ("Logging nicht verfügbar") bei nicht archivierten Variablen. Cache
     * je Request. Ohne Logging liefern die Leser sauber null.
     */
    private function isLogged(int $aid, int $vid): bool
    {
        if ($aid <= 0 || $vid <= 0 || !IPS_VariableExists($vid)) { return false; }
        if (!isset($this->loggedCache[$vid])) {
            $this->loggedCache[$vid] = (bool)@AC_GetLoggingStatus($aid, $vid);
        }
        return $this->loggedCache[$vid];
    }

    /** Endzeit nie in die Zukunft (verhindert "Aggregation aus der Zukunft"). */
    private function clampEnd(int $end): int
    {
        return min($end, time());
    }

    /**
     * Namen aller konfigurierten, aber NICHT archivierten Variablen — für eine
     * klare Statusmeldung. Nicht archivierte Abzugs-Variablen können nicht
     * abgezogen werden, Historien-Variablen fließen nicht in die Prognose ein.
     */
    private function unloggedVars(): array
    {
        $aid = $this->getArchiveID();
        if ($aid === 0) { return ['(kein Archive Control gefunden)']; }

        $missing = [];
        $check = function (int $vid) use ($aid, &$missing) {
            if ($vid > 0 && IPS_VariableExists($vid) && !$this->isLogged($aid, $vid)) {
                $missing[] = IPS_GetName($vid);
            }
        };

        $check($this->ReadPropertyInteger('VAR_Consumption'));
        $check($this->ReadPropertyInteger('VAR_TempHistory'));
        $check($this->ReadPropertyInteger('VAR_Presence'));
        foreach ((array)json_decode($this->ReadPropertyString('ExcludeVars'), true) as $row) {
            $check((int)($row['VariableID'] ?? 0));
        }
        foreach ((array)json_decode($this->ReadPropertyString('WPDevices'), true) as $row) {
            $check((int)($row['PowerVar'] ?? 0));
        }
        $check($this->ReadPropertyInteger('VAR_WP_Power'));

        return array_values(array_unique($missing));
    }

    /** Minuten je Slot (60, 30 oder 15). */
    private function slotMinutes(): int
    {
        $m = $this->ReadPropertyInteger('LFC_Resolution');
        return in_array($m, [15, 30, 60], true) ? $m : 60;
    }

    /** Anzahl Slots pro Tag (24, 48 oder 96). */
    private function slots(): int
    {
        return (int)(1440 / $this->slotMinutes());
    }

    /** Dauer eines Slots in Stunden (für die Energie-/kWh-Rechnung). */
    private function slotHours(): float
    {
        return $this->slotMinutes() / 60.0;
    }

    // ----------------------------------------------------------------
    //  Prognose-Eingaben
    // ----------------------------------------------------------------

    private function forecastTemp(int $ts)
    {
        if ($this->fcTempCache === null) {
            $this->fcTempCache = $this->buildForecastTemps();
        }
        $offset = (int)round(($ts - strtotime('today')) / 86400);
        if (isset($this->fcTempCache[$offset]) && $this->fcTempCache[$offset] !== null) {
            return $this->fcTempCache[$offset];
        }
        // Fallback-Kaskade: saisonales Normal (Klimatologie) → gestern → Basis.
        $clim = $this->climatologyTemp($ts);
        if ($clim !== null) { return $clim; }
        $t = $this->dailyMean($this->ReadPropertyInteger('VAR_TempHistory'), strtotime('yesterday'));
        return ($t !== null) ? $t : $this->ReadPropertyFloat('LFC_HDD_Base');
    }

    /**
     * Ermittelt die Vorhersage-Tagesmittel [0=>heute,1=>morgen,2=>übermorgen].
     * Modul-agnostisch: keine Instanz-ID fest verdrahtet. Nicht ermittelbare
     * Tage bleiben null (Klimatologie-Fallback greift in forecastTemp).
     */
    private function buildForecastTemps()
    {
        $mode = $this->ReadPropertyInteger('LFC_TempFcMode');

        switch ($mode) {
            case LFC_FC_DAILY:
                return $this->forecastFromDailyVars();

            case LFC_FC_IDENT:
                return $this->forecastFromIdentPattern();

            case LFC_FC_AUTO:
            default:
                // OpenWeatherData automatisch finden und auswerten.
                $owm = $this->owmInstance();
                if ($owm > 0) {
                    $res = $this->aggregateForecastSlots(
                        $owm, LFC_OWM_IDENT_TIME, LFC_OWM_IDENT_MIN, LFC_OWM_IDENT_MAX,
                        0, LFC_OWM_MAX_SLOTS
                    );
                    if ($res[0] !== null || $res[1] !== null || $res[2] !== null) {
                        return $res;
                    }
                    $this->log(LFC_LOG_VERBOSE, 'OWM gefunden, aber keine Stundenvorhersage (aktiviert?)');
                }
                // Sonst leer lassen → Klimatologie übernimmt in forecastTemp.
                return [0 => null, 1 => null, 2 => null];
        }
    }

    /** Modus DAILY: drei direkte Tagesmittel-Variablen. */
    private function forecastFromDailyVars()
    {
        $out = [0 => null, 1 => null, 2 => null];
        $map = [0 => 'VAR_TempFc_D0', 1 => 'VAR_TempFc_D1', 2 => 'VAR_TempFc_D2'];
        foreach ($map as $off => $prop) {
            $vid = $this->ReadPropertyInteger($prop);
            if ($vid > 0 && IPS_VariableExists($vid)) {
                $out[$off] = (float)GetValue($vid);
            }
        }
        return $out;
    }

    /** Modus IDENT: frei konfigurierte Slot-Idents aggregieren. */
    private function forecastFromIdentPattern()
    {
        $parent  = $this->ReadPropertyInteger('LFC_FcParentID');
        $patLow  = trim($this->ReadPropertyString('LFC_FcTempIdentLow'));
        $patHigh = trim($this->ReadPropertyString('LFC_FcTempIdentHigh'));
        $patTime = trim($this->ReadPropertyString('LFC_FcTimeIdent'));
        $start   = $this->ReadPropertyInteger('LFC_FcStartIndex');
        $count   = $this->ReadPropertyInteger('LFC_FcCount');

        if ($parent <= 0 || !IPS_ObjectExists($parent) || $patLow === '' || $patTime === '') {
            $this->log(LFC_LOG_VERBOSE, 'Temp-Vorhersage Ident-Modus unvollständig konfiguriert');
            return [0 => null, 1 => null, 2 => null];
        }
        return $this->aggregateForecastSlots($parent, $patTime, $patLow, $patHigh, $start, $count);
    }

    /**
     * Aggregiert nummerierte Slot-Variablen zu Tagesmitteln je Kalendertag.
     * Bucketing über den Unix-Zeitstempel jedes Slots; (Min+Max)/2, falls
     * ein Max-Muster angegeben ist. Wird von Auto- und Ident-Modus genutzt.
     */
    private function aggregateForecastSlots(int $parent, string $patTime, string $patLow, string $patHigh, int $start, int $count)
    {
        $today = strtotime('today');
        $sum   = [0 => 0.0, 1 => 0.0, 2 => 0.0];
        $cnt   = [0 => 0,   1 => 0,   2 => 0];

        for ($i = $start; $i < $start + $count; $i++) {
            $tId = $this->identToVar($parent, $patTime, $i);
            $lId = $this->identToVar($parent, $patLow,  $i);
            if ($tId === 0 || $lId === 0) { continue; }

            $slotTs = (int)GetValue($tId);
            if ($slotTs <= 0) { continue; }
            $off = (int)floor(($slotTs - $today) / 86400);
            if ($off < 0 || $off > 2) { continue; } // nur heute..übermorgen

            $val = (float)GetValue($lId);
            if ($patHigh !== '') {
                $hId = $this->identToVar($parent, $patHigh, $i);
                if ($hId !== 0) { $val = ($val + (float)GetValue($hId)) / 2.0; }
            }
            $sum[$off] += $val;
            $cnt[$off]++;
        }

        $out = [0 => null, 1 => null, 2 => null];
        foreach ([0, 1, 2] as $off) {
            if ($cnt[$off] > 0) { $out[$off] = $sum[$off] / $cnt[$off]; }
        }
        return $out;
    }

    /**
     * OpenWeatherData-Instanz für die Auto-Vorhersage. Wenn explizit gewählt
     * (LFC_OwmInstance) und gültig, diese; sonst die erste gefundene.
     * 0 = keine vorhanden.
     */
    private function owmInstance()
    {
        $sel = $this->ReadPropertyInteger('LFC_OwmInstance');
        if ($sel > 0 && IPS_InstanceExists($sel)
            && (IPS_GetInstance($sel)['ModuleInfo']['ModuleID'] ?? '') === LFC_OWM_GUID) {
            return $sel;
        }
        $ids = IPS_GetInstanceListByModuleID(LFC_OWM_GUID);
        return (is_array($ids) && count($ids) > 0) ? $ids[0] : 0;
    }

    /**
     * Saisonales Normal (Klimatologie) als Fallback ohne Vorhersage:
     * Mittel desselben Kalendertags ±7 Tage über alle verfügbaren
     * Vorjahre aus dem Temperatur-Archiv. Nutzt „die Daten vom letzten
     * Jahr", aber geglättet statt eines einzelnen verrauschten Tages.
     * Rückgabe null, wenn keine Temperatur-Historie konfiguriert ist.
     */
    private function climatologyTemp(int $ts)
    {
        $vid = $this->ReadPropertyInteger('VAR_TempHistory');
        if ($vid <= 0 || !IPS_VariableExists($vid)) { return null; }

        $yearsBack = max(1, (int)ceil($this->ReadPropertyInteger('LFC_LookbackDays') / 365));
        $yearsBack = min(5, $yearsBack);

        $sum = 0.0; $n = 0;
        for ($y = 1; $y <= $yearsBack; $y++) {
            for ($d = -7; $d <= 7; $d++) {
                $day = strtotime("-{$y} year {$d} day", $ts);
                $m   = $this->dailyMean($vid, $day);
                if ($m !== null) { $sum += $m; $n++; }
            }
        }
        return ($n > 0) ? $sum / $n : null;
    }

    /**
     * Löst eine Slot-Variable über ein Ident-Muster relativ zum
     * Eltern-Objekt auf. Muster mit %d/%02d wird per Index ersetzt.
     * Rückgabe 0, wenn nicht vorhanden.
     */
    private function identToVar(int $parent, string $pattern, int $index)
    {
        $ident = (strpos($pattern, '%') !== false) ? sprintf($pattern, $index) : $pattern;
        $id    = @IPS_GetObjectIDByIdent($ident, $parent);
        return ($id !== false && $id > 0 && IPS_VariableExists($id)) ? $id : 0;
    }

    private function forecastPresence()
    {
        $vid = $this->ReadPropertyInteger('VAR_PresenceFc');
        if ($vid <= 0) { $vid = $this->ReadPropertyInteger('VAR_Presence'); }
        if ($vid > 0 && IPS_VariableExists($vid)) {
            return $this->applyPresence((float)GetValue($vid));
        }
        return 1.0;
    }

    /** Invertiert das Anwesenheitssignal, falls die Variable Abwesenheit meldet. */
    private function applyPresence(float $pres): float
    {
        $pres = max(0.0, min(1.0, $pres));
        return $this->ReadPropertyBoolean('LFC_PresenceInvert') ? (1.0 - $pres) : $pres;
    }

    /**
     * Faktor zur Umrechnung einer Leistungsvariablen nach W.
     * 0=W, 1=kW, 2=automatisch je Variable (Profil-Suffix, sonst Größenordnung).
     */
    private function varPowerFactor(int $vid): float
    {
        $mode = $this->ReadPropertyInteger('LFC_PowerUnit');
        if ($mode === 0) { return 1.0; }
        if ($mode === 1) { return 1000.0; }

        if (isset($this->unitCache[$vid])) { return $this->unitCache[$vid]; }
        $f = $this->autoPowerFactor($vid);
        $this->unitCache[$vid] = $f;
        $this->log(LFC_LOG_VERBOSE, 'Einheit Variable ' . $vid . ': ' . ($f == 1000.0 ? 'kW' : 'W') . ' (automatisch)');
        return $f;
    }

    /**
     * Automatische Einheiten-Erkennung: 1) Suffix des Variablenprofils
     * („W"/„kW"), 2) Größenordnung der Tagesmaxima der letzten 7 Tage
     * (< 100 nur als kW plausibel), 3) Default W.
     */
    private function autoPowerFactor(int $vid): float
    {
        $v    = IPS_GetVariable($vid);
        $prof = ($v['VariableCustomProfile'] !== '') ? $v['VariableCustomProfile'] : $v['VariableProfile'];
        if ($prof !== '' && IPS_VariableProfileExists($prof)) {
            $suffix = strtolower(trim(IPS_GetVariableProfile($prof)['Suffix']));
            if ($suffix === 'kw') { return 1000.0; }
            if ($suffix === 'w')  { return 1.0; }
            if ($suffix === 'mw') { return 1000000.0; }
        }

        $aid = $this->getArchiveID();
        if ($this->isLogged($aid, $vid)) {
            $rows = @AC_GetAggregatedValues($aid, $vid, 1 /* täglich */, strtotime('-7 days'), $this->clampEnd(time()), 0);
            if (is_array($rows) && count($rows) > 0) {
                $max = 0.0;
                foreach ($rows as $r) { $max = max($max, (float)$r['Max']); }
                if ($max > 0 && $max < 100) { return 1000.0; }
            }
        }
        return 1.0;
    }

    // ----------------------------------------------------------------
    //  Temperaturabhängige Geräte — separate Regression (optional)
    // ----------------------------------------------------------------

    /**
     * Erwarteter Tagesverbrauch (kWh) ALLER temperaturabhängigen Geräte
     * (WP, Klima) für $offset Tage — Summe der Einzelprognosen.
     * Rückgabe null, wenn nichts konfiguriert ist oder kein Gerät genug
     * Daten für eine Regression hat.
     */
    private function wpForecast(int $offset)
    {
        $devices = $this->wpDevices();
        if (count($devices) === 0) { return null; }

        $total = 0.0; $any = false;
        foreach ($devices as $dev) {
            $kwh = $this->wpDeviceForecast($dev['var'], $dev['mode'], $offset);
            if ($kwh !== null) { $total += $kwh; $any = true; }
        }
        return $any ? $total : null;
    }

    /**
     * Geräteliste aus der Konfiguration. Fällt auf das Legacy-Einzelfeld
     * (vor 0.3) als Heiz-Gerät zurück, solange die Liste leer ist.
     */
    private function wpDevices(): array
    {
        $out  = [];
        $list = json_decode($this->ReadPropertyString('WPDevices'), true);
        if (is_array($list)) {
            foreach ($list as $row) {
                $vid = isset($row['PowerVar']) ? (int)$row['PowerVar'] : 0;
                if ($vid > 0 && IPS_VariableExists($vid)) {
                    $out[] = ['var' => $vid, 'mode' => (int)($row['Mode'] ?? LFC_WP_HEAT)];
                }
            }
        }
        if (count($out) === 0) {
            $legacy = $this->ReadPropertyInteger('VAR_WP_Power');
            if ($legacy > 0 && IPS_VariableExists($legacy)) {
                $out[] = ['var' => $legacy, 'mode' => LFC_WP_HEAT];
            }
        }
        return $out;
    }

    /**
     * Tagesverbrauch (kWh) eines einzelnen Geräts für $offset Tage.
     * Modell je nach Betriebsart:
     *   Heizen  : kWh = a + b·Heizgrad
     *   Kühlen  : kWh = a + c·Kühlgrad
     *   beides  : kWh = a + b·Heizgrad + c·Kühlgrad  (V-Kurve)
     * Heizgrad = max(0, Heizgrenze − T), Kühlgrad = max(0, T − Kühlgrenze).
     * Rückgabe null bei zu wenig Daten oder singulärer Regression.
     */
    private function wpDeviceForecast(int $vid, int $mode, int $offset)
    {
        $lookback = $this->ReadPropertyInteger('LFC_LookbackDays');
        $hBase    = $this->ReadPropertyFloat('LFC_HDD_Base');
        $cBase    = $this->ReadPropertyFloat('LFC_CDD_Base');

        $X = []; $y = [];
        for ($d = 1; $d <= $lookback; $d++) {
            $ts   = strtotime('today -' . $d . ' days');
            $prof = $this->dayProfile($vid, $ts);
            $temp = $this->dailyMean($this->ReadPropertyInteger('VAR_TempHistory'), $ts);
            if ($prof === null || $temp === null) { continue; }

            $hdd = max(0.0, $hBase - $temp);
            $cdd = max(0.0, $temp - $cBase);
            $X[] = $this->wpFeatureRow($mode, $hdd, $cdd);
            $y[] = array_sum($prof) * $this->slotHours() / 1000.0;
        }

        $need = ($mode === LFC_WP_BOTH) ? 20 : 10;
        if (count($X) < $need) { return null; }

        $coef = $this->fitLeastSquares($X, $y);
        if ($coef === null) { return null; }

        $targetTs = strtotime('today +' . $offset . ' days');
        $temp     = $this->forecastTemp($targetTs);
        $hdd      = max(0.0, $hBase - $temp);
        $cdd      = max(0.0, $temp - $cBase);
        $row      = $this->wpFeatureRow($mode, $hdd, $cdd);

        $pred = 0.0;
        foreach ($coef as $i => $b) { $pred += $b * $row[$i]; }
        return max(0.0, $pred);
    }

    /** Feature-Zeile (mit Achsenabschnitt 1.0) je nach Betriebsart. */
    private function wpFeatureRow(int $mode, float $hdd, float $cdd): array
    {
        switch ($mode) {
            case LFC_WP_COOL: return [1.0, $cdd];
            case LFC_WP_BOTH: return [1.0, $hdd, $cdd];
            case LFC_WP_HEAT:
            default:          return [1.0, $hdd];
        }
    }

    /**
     * Lineare Kleinste-Quadrate-Regression über die Normalgleichungen
     * (XᵀX)·b = Xᵀy, gelöst per Gauß-Elimination mit Teilpivotisierung.
     * $X = Zeilen aus Features (inkl. führender 1.0). Rückgabe null bei
     * singulärer Matrix (z.B. kein Kühlbedarf in der Historie).
     */
    private function fitLeastSquares(array $X, array $y)
    {
        $n = count($X);
        if ($n === 0) { return null; }
        $k = count($X[0]);

        // Normalgleichungen aufbauen: A = XᵀX (k×k), g = Xᵀy (k).
        $A = array_fill(0, $k, array_fill(0, $k, 0.0));
        $g = array_fill(0, $k, 0.0);
        for ($r = 0; $r < $n; $r++) {
            for ($i = 0; $i < $k; $i++) {
                $g[$i] += $X[$r][$i] * $y[$r];
                for ($j = 0; $j < $k; $j++) {
                    $A[$i][$j] += $X[$r][$i] * $X[$r][$j];
                }
            }
        }

        // Gauß-Elimination mit Teilpivotisierung.
        for ($col = 0; $col < $k; $col++) {
            $piv = $col;
            for ($r = $col + 1; $r < $k; $r++) {
                if (abs($A[$r][$col]) > abs($A[$piv][$col])) { $piv = $r; }
            }
            if (abs($A[$piv][$col]) < 1e-9) { return null; } // singulär
            if ($piv !== $col) {
                $tmp = $A[$piv]; $A[$piv] = $A[$col]; $A[$col] = $tmp;
                $tg = $g[$piv]; $g[$piv] = $g[$col]; $g[$col] = $tg;
            }
            for ($r = 0; $r < $k; $r++) {
                if ($r === $col) { continue; }
                $f = $A[$r][$col] / $A[$col][$col];
                for ($j = $col; $j < $k; $j++) { $A[$r][$j] -= $f * $A[$col][$j]; }
                $g[$r] -= $f * $g[$col];
            }
        }

        $b = array_fill(0, $k, 0.0);
        for ($i = 0; $i < $k; $i++) { $b[$i] = $g[$i] / $A[$i][$i]; }
        return $b;
    }

    // ----------------------------------------------------------------
    //  Hilfsfunktionen
    // ----------------------------------------------------------------

    /**
     * Gewichtetes Perzentil aus [['v'=>wert,'w'=>gewicht], …].
     */
    private function weightedPercentile(array $pairs, float $p)
    {
        if (count($pairs) === 0) { return 0.0; }
        usort($pairs, function ($x, $y) { return $x['v'] <=> $y['v']; });

        $total = 0.0;
        foreach ($pairs as $pp) { $total += $pp['w']; }
        if ($total <= 0) { return $pairs[0]['v']; }

        $cum = 0.0;
        $target = $p * $total;
        foreach ($pairs as $pp) {
            $cum += $pp['w'];
            if ($cum >= $target) { return $pp['v']; }
        }
        return end($pairs)['v'];
    }

    private function emptyForecast(int $ts)
    {
        $slots = $this->slots();
        $zeros = array_fill(0, $slots, 0.0);
        return [
            'contractVersion' => LFC_CONTRACT_FORECAST,
            'date'      => date('Y-m-d', $ts),
            'slots'     => $slots,
            'resolution'=> $this->slotMinutes() . 'min',
            'unit'      => 'W',
            'p10' => $zeros, 'p50' => $zeros, 'p90' => $zeros, 'mean' => $zeros,
            'kwh' => 0.0, 'neighbors' => 0,
        ];
    }

    private function log($level, $message)
    {
        $configLevel = $this->ReadPropertyInteger('LFC_Log_Level');
        if ($level > $configLevel) { return; }
        $prefix = ($level === LFC_LOG_VERBOSE) ? 'VERBOSE' : 'INFO';
        $this->SendDebug($prefix, $message, 0);
        if ($level <= LFC_LOG_BASIC) {
            IPS_LogMessage('Lastprognose', $message);
        }
    }
}
