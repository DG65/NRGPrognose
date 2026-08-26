<?php

declare(strict_types=1);

/**
 * EnergyForecastTile
 *
 * Kombinierte HTML-SDK-Kachel: zeigt PV-Erzeugungsprognose (PVForecast) und
 * — falls vorhanden — Verbrauchsprognose (LoadForecast) gemeinsam als zwei
 * P10/P50/P90-Bänder in einem Diagramm. Beide Quellen werden automatisch per
 * Modul-GUID gefunden; die Kachel funktioniert auch PV-only.
 */
class Energiebilanz extends IPSModule
{
    // Request-lokaler Cache der automatisch erkannten Einheiten je Variable
    private $unitCache = [];
    // Request-lokaler Cache der rohen Alt-Konfiguration (Migration Property→
    // Variable, siehe legacyValue()) — als Instanzeigenschaft statt static,
    // damit mehrere Instanzen im selben PHP-Prozess sich nicht die Werte
    // gegenseitig überschreiben.
    private $legacyConfigCache = null;

    private const SOURCE_PV   = '{257DD4E8-9705-462E-89FC-56D0A1038353}'; // PVForecast
    private const SOURCE_LOAD = '{DC5AD508-507F-40EA-8630-0959AED83050}'; // LoadForecast

    // Horizont, deckungsgleich mit PVF_MAX_OFFSET/LFC_MAX_OFFSET (5 Tage).
    private const MAX_OFFSET = 4;
    // Vertragsversion für GetDaysData() (20.08.2026, für NRGDashboard — Trennung
    // Daten/Darstellung, analog HeishaMon/EMS). Additiv, Major nur bei Bruch.
    private const CONTRACT_DAYS = '1.0';

    private const DEF_PV    = 0xE0A020; // Bernstein
    private const DEF_LOAD  = 0x2BB3C0; // Türkis
    private const DEF_BG     = -1;
    private const DEF_SCALE  = 1.0;
    private const DEF_DAYS   = 3;
    private const DEF_LW     = 2.0;
    private const DEF_SMOOTH = true;
    private const DEF_BAND   = true;
    private const DEF_BANDOP = 0.16;
    private const DEF_GRID   = true;
    private const DEF_YMAX   = 0.0; // 0 = automatisch
    private const DEF_HEIGHT = 360;

    public function Create()
    {
        parent::Create();

        // Reine Verdrahtung (Quell-/Variablenauswahl) bleibt Property: nur die
        // Konsole bietet SelectInstance/SelectVariable-Formularelemente, eine
        // Doppelpfeil-Variable kann das nicht abbilden (Dashboard-Muster).
        $this->RegisterPropertyInteger('PVSource',   0);
        $this->RegisterPropertyInteger('LoadSource', 0);
        $this->RegisterPropertyInteger('ActualPV',   0);
        $this->RegisterPropertyInteger('ActualLoad', 0);
        $this->RegisterAttributeString('MeasuredCache', '');

        // Alle übrigen Einstellungen (Dietmar, 26.08.2026: "Bau alles um" —
        // Auftrag, die kompletten bisher konsolenpflichtigen Einstell-
        // möglichkeiten hinter den Doppelpfeil der Kachel zu legen) als echte
        // Instanz-Variablen statt Formular-Properties. Muster von Dashboard
        // (NRGDashboardHeatSchema), Befund ursprünglich aus der CometWiFi-
        // Sitzung (SUITE.md Punkt 10): eine aufgezogene Kachel zeigt NIE das
        // eigene Kachel-HTML, sondern immer die Standardansicht der Instanz-
        // Kinder — eigene Variablen mit EnableAction() erscheinen dort
        // automatisch als Schalter/Dropdown/Zahlenfeld.
        //
        // Migration bestehender Installationen: Diese Einstellungen waren bis
        // Build 76 Properties. Beim erstmaligen Anlegen der jeweiligen
        // Variable wird der zuvor gespeicherte Property-Wert aus der rohen
        // Konfiguration übernommen (legacyValue()) statt des Modul-Defaults —
        // sonst würden bereits angepasste Einstellungen (z. B. Dietmars
        // Days=5) beim Umbau stillschweigend zurückgesetzt. Create() läuft
        // bei jedem Symcon-Neustart erneut (nicht nur bei echter Neuanlage,
        // CometWiFi-Fund) — Default/Migration werden deshalb nur beim
        // tatsächlichen Neuanlegen der Variable angewendet, nie bei einem
        // gewöhnlichen Neustart.
        $this->ensureProfiles();

        $bool = [
            'ShowPV'             => ['PV-Erzeugung anzeigen', 10, true],
            'ShowLoad'           => ['Verbrauch anzeigen', 11, true],
            'ShowActualPV'       => ['Gemessenen PV-Tagesverlauf (heute) als Linie zeigen', 31, false],
            'ShowActualLoad'     => ['Gemessenen Verbrauchs-Tagesverlauf (heute) als Linie zeigen', 32, false],
            'ShowYesterday'      => ['Gestern mit anzeigen (Soll aus gespeicherter Prognose + Ist aus Archiv)', 33, false],
            'Smooth'             => ['Kurven glätten (gegen kantige Linien)', 49, self::DEF_SMOOTH],
            'ShowBand'           => ['Unsicherheitsband (P10–P90) anzeigen', 50, self::DEF_BAND],
            'ShowGrid'           => ['Gitter & Achsenbeschriftung anzeigen', 52, self::DEF_GRID],
            'ColorBackgroundAuto' => ['Hintergrund automatisch (IPS-Theme/transparent)', 43, true],
        ];
        foreach ($bool as $ident => $spec) {
            [$caption, $pos, $default] = $spec;
            $isNew = @IPS_GetObjectIDByIdent($ident, $this->InstanceID) === false;
            $this->RegisterVariableBoolean($ident, $caption, '', $pos);
            $this->EnableAction($ident);
            if ($isNew) {
                $legacy = ($ident === 'ColorBackgroundAuto')
                    ? ((int) $this->legacyValue('ColorBackground', self::DEF_BG) < 0)
                    : (bool) $this->legacyValue($ident, $default);
                $this->SetValue($ident, $legacy);
            }
        }

        $int = [
            'Days'             => ['Anzuzeigende Tage', 'EFTILE.Days', 20, self::DEF_DAYS],
            'PowerUnit'        => ['Einheit der Ist-Leistungsvariablen', 'EFTILE.PowerUnit', 30, 2],
            'MeasuredCacheSec' => ['Ist-Verlauf neu berechnen alle … s (Archiv-Cache)', 'EFTILE.CacheSec', 34, 120],
            'ChartEngine'      => ['Diagramm-Engine', 'EFTILE.Engine', 40, 0],
            'ColorPV'          => ['Farbe PV-Erzeugung', '~HexColor', 41, self::DEF_PV],
            'ColorLoad'        => ['Farbe Verbrauch', '~HexColor', 42, self::DEF_LOAD],
            'ColorBackground'  => ['Hintergrundfarbe (falls nicht automatisch)', '~HexColor', 44, 0xFFFFFF],
            'FontFamily'       => ['Schriftart', 'EFTILE.Font', 45, 0],
            'ChartHeight'      => ['Diagrammhöhe', 'EFTILE.Height', 47, self::DEF_HEIGHT],
        ];
        foreach ($int as $ident => $spec) {
            [$caption, $profile, $pos, $default] = $spec;
            $isNew = @IPS_GetObjectIDByIdent($ident, $this->InstanceID) === false;
            $this->RegisterVariableInteger($ident, $caption, $profile, $pos);
            $this->EnableAction($ident);
            if ($isNew) {
                $this->SetValue($ident, $this->legacyIntValue($ident, $default));
            }
        }

        $float = [
            'FontScale'   => ['Schriftgröße (Faktor, wirkt auf alle Beschriftungen)', 'EFTILE.Scale', 46, self::DEF_SCALE],
            'LineWidth'   => ['Linienstärke', 'EFTILE.LineWidth', 48, self::DEF_LW],
            'BandOpacity' => ['Band-Transparenz (0 = unsichtbar … 0.6)', 'EFTILE.BandOpacity', 51, self::DEF_BANDOP],
            'YMaxManual'  => ['Y-Achse max. fest (kW, 0 = automatisch)', 'EFTILE.YMax', 53, self::DEF_YMAX],
        ];
        foreach ($float as $ident => $spec) {
            [$caption, $profile, $pos, $default] = $spec;
            $isNew = @IPS_GetObjectIDByIdent($ident, $this->InstanceID) === false;
            $this->RegisterVariableFloat($ident, $caption, $profile, $pos);
            $this->EnableAction($ident);
            if ($isNew) {
                $this->SetValue($ident, (float) $this->legacyValue($ident, $default));
            }
        }

        $this->SetVisualizationType(1);
    }

    /** Legt/aktualisiert alle EFTILE.*-Variablenprofile (idempotent). */
    private function ensureProfiles(): void
    {
        if (!IPS_VariableProfileExists('EFTILE.Days')) { IPS_CreateVariableProfile('EFTILE.Days', VARIABLETYPE_INTEGER); }
        IPS_SetVariableProfileAssociation('EFTILE.Days', 1, 'Heute', '', -1);
        IPS_SetVariableProfileAssociation('EFTILE.Days', 2, 'Heute + morgen', '', -1);
        IPS_SetVariableProfileAssociation('EFTILE.Days', 3, 'Heute + 2 Tage', '', -1);
        IPS_SetVariableProfileAssociation('EFTILE.Days', 4, 'Heute + 3 Tage', '', -1);
        IPS_SetVariableProfileAssociation('EFTILE.Days', 5, 'Heute + 4 Tage (voller Horizont)', '', -1);

        if (!IPS_VariableProfileExists('EFTILE.PowerUnit')) { IPS_CreateVariableProfile('EFTILE.PowerUnit', VARIABLETYPE_INTEGER); }
        IPS_SetVariableProfileAssociation('EFTILE.PowerUnit', 0, 'W (Watt)', '', -1);
        IPS_SetVariableProfileAssociation('EFTILE.PowerUnit', 1, 'kW (Kilowatt)', '', -1);
        IPS_SetVariableProfileAssociation('EFTILE.PowerUnit', 2, 'Automatisch erkennen (Profil/Größenordnung)', '', -1);

        if (!IPS_VariableProfileExists('EFTILE.Engine')) { IPS_CreateVariableProfile('EFTILE.Engine', VARIABLETYPE_INTEGER); }
        IPS_SetVariableProfileAssociation('EFTILE.Engine', 0, 'ECharts (quelloffen, auch kommerziell)', '', -1);
        IPS_SetVariableProfileAssociation('EFTILE.Engine', 1, 'Highcharts (nur privat/nicht-kommerziell)', '', -1);

        if (!IPS_VariableProfileExists('EFTILE.Font')) { IPS_CreateVariableProfile('EFTILE.Font', VARIABLETYPE_INTEGER); }
        IPS_SetVariableProfileAssociation('EFTILE.Font', 0, 'System', '', -1);
        IPS_SetVariableProfileAssociation('EFTILE.Font', 1, 'Arial', '', -1);
        IPS_SetVariableProfileAssociation('EFTILE.Font', 2, 'Verdana', '', -1);
        IPS_SetVariableProfileAssociation('EFTILE.Font', 3, 'Tahoma', '', -1);
        IPS_SetVariableProfileAssociation('EFTILE.Font', 4, 'Trebuchet MS', '', -1);
        IPS_SetVariableProfileAssociation('EFTILE.Font', 5, 'Georgia', '', -1);
        IPS_SetVariableProfileAssociation('EFTILE.Font', 6, 'Courier New', '', -1);

        if (!IPS_VariableProfileExists('EFTILE.CacheSec')) { IPS_CreateVariableProfile('EFTILE.CacheSec', VARIABLETYPE_INTEGER); }
        IPS_SetVariableProfileValues('EFTILE.CacheSec', 15, 900, 5);
        IPS_SetVariableProfileText('EFTILE.CacheSec', '', ' s');

        if (!IPS_VariableProfileExists('EFTILE.Height')) { IPS_CreateVariableProfile('EFTILE.Height', VARIABLETYPE_INTEGER); }
        IPS_SetVariableProfileValues('EFTILE.Height', 180, 800, 10);
        IPS_SetVariableProfileText('EFTILE.Height', '', ' px');

        if (!IPS_VariableProfileExists('EFTILE.Scale')) { IPS_CreateVariableProfile('EFTILE.Scale', VARIABLETYPE_FLOAT); }
        IPS_SetVariableProfileValues('EFTILE.Scale', 0.5, 2.5, 0.1);
        IPS_SetVariableProfileDigits('EFTILE.Scale', 2);
        IPS_SetVariableProfileText('EFTILE.Scale', '', ' ×');

        if (!IPS_VariableProfileExists('EFTILE.LineWidth')) { IPS_CreateVariableProfile('EFTILE.LineWidth', VARIABLETYPE_FLOAT); }
        IPS_SetVariableProfileValues('EFTILE.LineWidth', 0.5, 6, 0.5);
        IPS_SetVariableProfileDigits('EFTILE.LineWidth', 1);
        IPS_SetVariableProfileText('EFTILE.LineWidth', '', ' px');

        if (!IPS_VariableProfileExists('EFTILE.BandOpacity')) { IPS_CreateVariableProfile('EFTILE.BandOpacity', VARIABLETYPE_FLOAT); }
        IPS_SetVariableProfileValues('EFTILE.BandOpacity', 0, 0.6, 0.02);
        IPS_SetVariableProfileDigits('EFTILE.BandOpacity', 2);

        if (!IPS_VariableProfileExists('EFTILE.YMax')) { IPS_CreateVariableProfile('EFTILE.YMax', VARIABLETYPE_FLOAT); }
        IPS_SetVariableProfileValues('EFTILE.YMax', 0, 100, 0.5);
        IPS_SetVariableProfileDigits('EFTILE.YMax', 1);
        IPS_SetVariableProfileText('EFTILE.YMax', '', ' kW');
    }

    /**
     * Rohen Alt-Property-Wert lesen (Migration Property→Variable, Build 76) —
     * über IPS_GetConfiguration() statt ReadPropertyX(), weil die Property zu
     * diesem Zeitpunkt in Create() nicht mehr registriert ist. Liefert
     * $default, wenn der Schlüssel fehlt (Neuinstallation ohne Alt-Property).
     */
    private function legacyValue(string $name, $default)
    {
        if ($this->legacyConfigCache === null) {
            $cfg = json_decode(IPS_GetConfiguration($this->InstanceID), true);
            $this->legacyConfigCache = is_array($cfg) ? $cfg : [];
        }
        return array_key_exists($name, $this->legacyConfigCache) ? $this->legacyConfigCache[$name] : $default;
    }

    /** Wie legacyValue(), aber mit Alt-String→neue-Int-Zuordnung für ChartEngine/FontFamily. */
    private function legacyIntValue(string $ident, int $default): int
    {
        $raw = $this->legacyValue($ident, null);
        if ($raw === null) { return $default; }
        if ($ident === 'ChartEngine') { return ($raw === 'highcharts') ? 1 : 0; }
        if ($ident === 'FontFamily') {
            $map = ['system' => 0, 'arial' => 1, 'verdana' => 2, 'tahoma' => 3, 'trebuchet' => 4, 'georgia' => 5, 'courier' => 6];
            return $map[$raw] ?? 0;
        }
        if ($ident === 'ColorBackground') {
            return ((int) $raw >= 0) ? (int) $raw : $default;
        }
        return (int) $raw;
    }

    public function Destroy()
    {
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetVisualizationType(1);
        $this->WriteAttributeString('MeasuredCache', ''); // Cache bei Konfig-Änderung verwerfen

        // Standalone-Webseite für IPSView/Browser (WebView/Popup)
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->RegisterHook('/hook/energiebilanz' . $this->InstanceID);
        } else {
            $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        }

        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $msg) {
                if ($msg === VM_UPDATE) { $this->UnregisterMessage($senderID, VM_UPDATE); }
            }
        }

        $found = false;
        $pv = $this->ResolveSource(self::SOURCE_PV, 'PVSource');
        if ($pv > 0) {
            foreach (['PVF_Today', 'PVF_Tomorrow', 'PVF_DayAfter', 'PVF_Day3', 'PVF_Day4'] as $ident) {
                $vid = @IPS_GetObjectIDByIdent($ident, $pv);
                if ($vid !== false && $vid > 0) { $this->RegisterReference($vid); $this->RegisterMessage($vid, VM_UPDATE); $found = true; }
            }
        }
        $load = $this->ResolveSource(self::SOURCE_LOAD, 'LoadSource');
        if ($load > 0) {
            foreach (['LFC_Today', 'LFC_Tomorrow', 'LFC_DayAfter', 'LFC_Day3', 'LFC_Day4'] as $ident) {
                $vid = @IPS_GetObjectIDByIdent($ident, $load);
                if ($vid !== false && $vid > 0) { $this->RegisterReference($vid); $this->RegisterMessage($vid, VM_UPDATE); $found = true; }
            }
        }
        // Ist-Wert-Variablen live abonnieren (momentane Leistung).
        foreach (['ActualPV', 'ActualLoad'] as $prop) {
            $vid = $this->ReadPropertyInteger($prop);
            if ($vid > 0 && IPS_VariableExists($vid)) {
                $this->RegisterReference($vid);
                $this->RegisterMessage($vid, VM_UPDATE);
                $found = true;
            }
        }
        $this->SetStatus($found ? 102 : 104);

        $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELMESSAGE && isset($Data[0]) && $Data[0] === KR_READY) {
            $this->ApplyChanges();
            return;
        }
        if ($Message === VM_UPDATE) {
            $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
        }
    }

    /**
     * WebFront-Bedienung der Doppelpfeil-Variablen (siehe Create()) — Muster
     * NRGDashboardHeatSchema::RequestAction(): Wert setzen, Kachel neu
     * rendern. Kein Fall braucht wie dort Folgeaktionen (z. B. abhängige
     * Sichtbarkeiten), da unsere Einstellungen unabhängig voneinander sind.
     */
    public function RequestAction($Ident, $Value)
    {
        $boolIdents = ['ShowPV', 'ShowLoad', 'ShowActualPV', 'ShowActualLoad', 'ShowYesterday',
                       'Smooth', 'ShowBand', 'ShowGrid', 'ColorBackgroundAuto'];
        $intIdents  = ['Days', 'PowerUnit', 'MeasuredCacheSec', 'ChartEngine',
                       'ColorPV', 'ColorLoad', 'ColorBackground', 'FontFamily', 'ChartHeight'];
        $floatIdents = ['FontScale', 'LineWidth', 'BandOpacity', 'YMaxManual'];

        if (in_array($Ident, $boolIdents, true)) {
            $this->SetValue($Ident, (bool) $Value);
            $this->Render();
            return;
        }
        if (in_array($Ident, $intIdents, true)) {
            $this->SetValue($Ident, (int) $Value);
            $this->Render();
            return;
        }
        if (in_array($Ident, $floatIdents, true)) {
            $this->SetValue($Ident, (float) $Value);
            $this->Render();
        }
    }

    private function Render(): void
    {
        $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
    }

    /**
     * Liefert die Kachel als eigenständige Webseite (für IPSView-WebView/
     * Popup oder jeden Browser). Aufruf: /hook/energiebilanz<InstanzID>.
     * Mit ?json=1 werden nur die Daten geliefert (für die Auto-Aktualisierung).
     */
    public function ProcessHookData()
    {
        if (isset($_GET['json'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo $this->GetFullUpdateMessage();
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        $html = file_get_contents(__DIR__ . '/module.html');
        $html .= '<script>handleMessage(' . json_encode($this->GetFullUpdateMessage()) . ');'
               . 'setInterval(function(){fetch(window.location.pathname+"?json=1")'
               . '.then(function(r){return r.text();}).then(function(t){handleMessage(t);})'
               . '.catch(function(){});},30000);</script>';
        echo $html;
    }

    /** WebHook beim WebHook-Control registrieren (Standard-Muster). */
    private function RegisterHook(string $WebHook)
    {
        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        if (count($ids) === 0) { return; }
        $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true);
        if (!is_array($hooks)) { $hooks = []; }
        foreach ($hooks as $index => $hook) {
            if ($hook['Hook'] === $WebHook) {
                if ((int) $hook['TargetID'] === $this->InstanceID) { return; }
                $hooks[$index]['TargetID'] = $this->InstanceID;
                IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
                IPS_ApplyChanges($ids[0]);
                return;
            }
        }
        $hooks[] = ['Hook' => $WebHook, 'TargetID' => $this->InstanceID];
        IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($ids[0]);
    }

    public function GetConfigurationForm()
    {
        $form = file_get_contents(__DIR__ . '/form.json');
        $form = str_replace('%%HOOK%%', '/hook/energiebilanz' . $this->InstanceID, $form);
        return json_encode(json_decode($form, true));
    }

    /**
     * Alle Doppelpfeil-Einstellungen auf den Modul-Default zurücksetzen —
     * seit Build 76 echte Variablen statt Formularfelder, deshalb SetValue()
     * statt UpdateFormField() (per Konsolen-Button weiterhin erreichbar).
     */
    public function ResetStyle(): void
    {
        $this->SetValue('ShowPV', true);
        $this->SetValue('ShowLoad', true);
        $this->SetValue('Days', self::DEF_DAYS);
        $this->SetValue('PowerUnit', 2);
        $this->SetValue('ShowActualPV', false);
        $this->SetValue('ShowActualLoad', false);
        $this->SetValue('ShowYesterday', false);
        $this->SetValue('MeasuredCacheSec', 120);
        $this->SetValue('ChartEngine', 0);
        $this->SetValue('ColorPV', self::DEF_PV);
        $this->SetValue('ColorLoad', self::DEF_LOAD);
        $this->SetValue('ColorBackgroundAuto', true);
        $this->SetValue('ColorBackground', 0xFFFFFF);
        $this->SetValue('FontFamily', 0);
        $this->SetValue('FontScale', self::DEF_SCALE);
        $this->SetValue('ChartHeight', self::DEF_HEIGHT);
        $this->SetValue('LineWidth', self::DEF_LW);
        $this->SetValue('Smooth', self::DEF_SMOOTH);
        $this->SetValue('ShowBand', self::DEF_BAND);
        $this->SetValue('BandOpacity', self::DEF_BANDOP);
        $this->SetValue('ShowGrid', self::DEF_GRID);
        $this->SetValue('YMaxManual', self::DEF_YMAX);
        $this->Render();
    }

    public function GetVisualizationTile()
    {
        $module = file_get_contents(__DIR__ . '/module.html');
        $module .= '<script>handleMessage(' . json_encode($this->GetFullUpdateMessage()) . ');</script>';
        return $module;
    }

    // ---------------------------------------------------------------------

    private function GetFullUpdateMessage(): string
    {
        $style = [
            'pvColor'   => sprintf('#%06x', (int) $this->GetValue('ColorPV')),
            'loadColor' => sprintf('#%06x', (int) $this->GetValue('ColorLoad')),
            'bg'        => $this->GetValue('ColorBackgroundAuto') ? '' : sprintf('#%06x', (int) $this->GetValue('ColorBackground')),
            'scale'     => $this->FontScaleValue(),
            'lineWidth' => max(0.5, min(6.0, (float) $this->GetValue('LineWidth'))),
            'smooth'    => (bool) $this->GetValue('Smooth'),
            'showBand'  => (bool) $this->GetValue('ShowBand'),
            'bandOp'    => max(0.0, min(0.6, (float) $this->GetValue('BandOpacity'))),
            'showGrid'  => (bool) $this->GetValue('ShowGrid'),
            'yMaxManual'=> max(0.0, (float) $this->GetValue('YMaxManual')),
            'font'      => $this->FontStack((int) $this->GetValue('FontFamily')),
            'height'    => max(180, (int) $this->GetValue('ChartHeight')),
            'engine'    => ((int) $this->GetValue('ChartEngine') === 1) ? 'highcharts' : 'echarts',
        ];

        return json_encode(array_merge($style, $this->buildDaysData(false)));
    }

    /**
     * Öffentlicher, style-freier Datenzugriff auf dasselbe days[]-Format, das
     * die eigene Kachel intern nutzt — für externe Konsumenten (NRGDashboard),
     * die eine eigene Visualisierung bauen, statt unsere PHP-Datenlogik zu
     * duplizieren (Verbund-Muster wie HeishaMon/EMS: Datenberechnung bleibt
     * hier, Darstellung entsteht als eigenes Modul dort). Anders als die
     * eigene Kachel IGNORIERT dieser Aufruf die Anzeige-Einstellungen dieser
     * Instanz (Days/ShowYesterday/ShowActualPV/ShowActualLoad) und liefert
     * immer den vollen Umfang — der Konsument entscheidet selbst, was er
     * zeigt. Für das Dashboard per EFTILE_GetDaysData($id) abrufbar.
     */
    public function GetDaysData(): array
    {
        return array_merge(['contractVersion' => self::CONTRACT_DAYS], $this->buildDaysData(true));
    }

    /**
     * Baut days[]/actualPV/actualLoad/hasData — den reinen Datenanteil, ohne
     * Stil. $full=true (GetDaysData): immer voller Horizont + Gestern +
     * Ist-Überlagerung, unabhängig von den Anzeige-Properties dieser Instanz.
     * $full=false (eigene Kachel): respektiert Days/ShowYesterday/
     * ShowActualPV/ShowActualLoad wie bisher.
     */
    private function buildDaysData(bool $full): array
    {
        $limit = $full
            ? self::MAX_OFFSET + 1
            : max(1, min(self::MAX_OFFSET + 1, (int) $this->GetValue('Days')));
        $labels = ['heute', 'morgen', 'übermorgen', 'Tag 4', 'Tag 5'];

        $showPV   = (bool) $this->GetValue('ShowPV');
        $showLoad = (bool) $this->GetValue('ShowLoad');

        $pvSrc   = $showPV   ? $this->ResolveSource(self::SOURCE_PV, 'PVSource')   : 0;
        $loadSrc = $showLoad ? $this->ResolveSource(self::SOURCE_LOAD, 'LoadSource') : 0;

        $pvIdents   = ['PVF_Today', 'PVF_Tomorrow', 'PVF_DayAfter', 'PVF_Day3', 'PVF_Day4'];
        $loadIdents = ['LFC_Today', 'LFC_Tomorrow', 'LFC_DayAfter', 'LFC_Day3', 'LFC_Day4'];
        $pvDays   = $this->ReadSeries($pvSrc,   $pvIdents,   $limit);
        $loadDays = $this->ReadSeries($loadSrc, $loadIdents, $limit);

        // Momentane Ist-Leistung (W) für „jetzt"-Punkt/Legende.
        $actualPV   = $showPV   ? $this->readActual('ActualPV')   : null;
        $actualLoad = $showLoad ? $this->readActual('ActualLoad') : null;

        $pvVar = $this->ReadPropertyInteger('ActualPV');
        $loVar = $this->ReadPropertyInteger('ActualLoad');
        $showMeasPV   = $full || (bool) $this->GetValue('ShowActualPV');
        $showMeasLoad = $full || (bool) $this->GetValue('ShowActualLoad');
        $wantYesterday = $full || (bool) $this->GetValue('ShowYesterday');
        $today = strtotime('today');

        $days = [];

        // ── Gestern (optional): Soll aus Snapshot, Ist (voller Tag) aus Archiv
        if ($wantYesterday) {
            $yDate  = date('Y-m-d', strtotime('yesterday'));
            $yStart = strtotime('yesterday');
            $gpv = $showPV   ? $this->snapshotToDay($pvSrc,   'PVF_GetSnapshot', $yDate) : null;
            $glo = $showLoad ? $this->snapshotToDay($loadSrc, 'LFC_GetSnapshot', $yDate) : null;
            $g = ['label' => 'gestern', 'pv' => $gpv, 'load' => $glo,
                  'pvMeas' => null, 'loMeas' => null, 'pvKwhIst' => null, 'loKwhIst' => null];
            $refPV = ($pvDays[0] !== null) ? count($pvDays[0]['p50']) : ($gpv ? count($gpv['p50']) : 0);
            if ($showPV && $pvVar > 0 && $refPV > 0) {
                $m = $this->measuredCached('pv', $pvVar, $refPV, $yStart);
                if (is_array($m)) { $g['pvMeas'] = $m; $g['pvKwhIst'] = $this->sumKwh($m, $refPV); }
            }
            $refLo = ($loadDays[0] !== null) ? count($loadDays[0]['p50']) : ($glo ? count($glo['p50']) : 0);
            if ($showLoad && $loVar > 0 && $refLo > 0) {
                $m = $this->measuredCached('load', $loVar, $refLo, $yStart);
                if (is_array($m)) { $g['loMeas'] = $m; $g['loKwhIst'] = $this->sumKwh($m, $refLo); }
            }
            if ($g['pv'] !== null || $g['load'] !== null || $g['pvMeas'] !== null || $g['loMeas'] !== null) {
                $days[] = $g;
            }
        }

        // ── Heute / Morgen / Übermorgen (heute zusätzlich mit Ist bis jetzt)
        $hasData = false;
        for ($i = 0; $i < $limit; $i++) {
            $pv   = $pvDays[$i]   ?? null;
            $load = $loadDays[$i] ?? null;
            if ($pv !== null || $load !== null) { $hasData = true; }
            $d = ['label' => $labels[$i], 'pv' => $pv, 'load' => $load,
                  'pvMeas' => null, 'loMeas' => null, 'pvKwhIst' => null, 'loKwhIst' => null];
            if ($i === 0) {
                if ($showPV && $pvVar > 0 && $pv !== null) {
                    $n = count($pv['p50']);
                    $m = $this->measuredCached('pv', $pvVar, $n, $today);
                    if (is_array($m)) { $d['pvKwhIst'] = $this->sumKwh($m, $n); if ($showMeasPV) { $d['pvMeas'] = $m; } }
                }
                if ($showLoad && $loVar > 0 && $load !== null) {
                    $n = count($load['p50']);
                    $m = $this->measuredCached('load', $loVar, $n, $today);
                    if (is_array($m)) { $d['loKwhIst'] = $this->sumKwh($m, $n); if ($showMeasLoad) { $d['loMeas'] = $m; } }
                }
            }
            $days[] = $d;
        }

        return [
            'hasData'    => $hasData,
            'message'    => $hasData ? '' : 'Keine Prognosedaten',
            'days'       => $days,
            'actualPV'   => $actualPV,
            'actualLoad' => $actualLoad,
        ];
    }

    /** Liest die JSON-Prognosevariablen einer Quelle in [Tag => {p10,p50,p90,kwh}|null]. */
    private function ReadSeries(int $src, array $idents, int $limit): array
    {
        $out = [];
        for ($i = 0; $i < $limit; $i++) {
            $out[$i] = null;
            if ($src <= 0) { continue; }
            $raw = $this->ReadSourceValue($src, $idents[$i], '');
            $fc  = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($fc) || !isset($fc['p50']) || !is_array($fc['p50'])) { continue; }
            $out[$i] = [
                'p10' => array_map('floatval', $fc['p10'] ?? []),
                'p50' => array_map('floatval', $fc['p50']),
                'p90' => array_map('floatval', $fc['p90'] ?? []),
                'kwh' => round((float) ($fc['kwh'] ?? 0), 2),
            ];
        }
        return $out;
    }

    /**
     * Gespeicherten Prognose-Snapshot (Soll) eines Tages als Tag-Struktur.
     * p10=p50=p90 (Snapshot hat nur den Median → Linie ohne Band).
     */
    private function snapshotToDay(int $src, string $fn, string $date)
    {
        if ($src <= 0 || !function_exists($fn)) { return null; }
        $snap = @$fn($src, $date);
        if (!is_array($snap) || empty($snap['p50']) || !is_array($snap['p50'])) { return null; }
        $p50 = array_map('floatval', $snap['p50']);
        return ['p10' => $p50, 'p50' => $p50, 'p90' => $p50, 'kwh' => round((float) ($snap['kwh'] ?? 0), 2)];
    }

    /** Ist-Tagessumme (kWh bis jetzt) aus einem Slot-Profil (Ø-W je Slot). */
    private function sumKwh($arr, int $n)
    {
        if (!is_array($arr) || $n <= 0) { return null; }
        $hoursPerSlot = 24.0 / $n;
        $sum = 0.0; $any = false;
        foreach ($arr as $v) { if ($v !== null) { $sum += (float) $v; $any = true; } }
        return $any ? $sum * $hoursPerSlot / 1000.0 : null;
    }

    /** Momentane Leistung (W) einer Ist-Wert-Variablen; null wenn unkonfiguriert. */
    private function readActual(string $prop)
    {
        $vid = $this->ReadPropertyInteger($prop);
        if ($vid <= 0 || !IPS_VariableExists($vid)) { return null; }
        return (float) GetValue($vid) * $this->varPowerFactor($vid);
    }

    /** Faktor zur Umrechnung nach W: 0=W, 1=kW, 2=automatisch je Variable. */
    private function varPowerFactor(int $vid): float
    {
        $mode = (int) $this->GetValue('PowerUnit');
        if ($mode === 0) { return 1.0; }
        if ($mode === 1) { return 1000.0; }
        if (isset($this->unitCache[$vid])) { return $this->unitCache[$vid]; }
        $f = $this->autoPowerFactor($vid);
        $this->unitCache[$vid] = $f;
        return $f;
    }

    /**
     * Automatische Einheiten-Erkennung: 1) Profil-Suffix („W"/„kW"),
     * 2) Größenordnung der Tagesmaxima (letzte 7 Tage, < 100 → kW), 3) W.
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
        if ($aid > 0) {
            $rows = @AC_GetAggregatedValues($aid, $vid, 1, strtotime('-7 days'), time(), 0);
            if (is_array($rows) && count($rows) > 0) {
                $max = 0.0;
                foreach ($rows as $r) { $max = max($max, (float)$r['Max']); }
                if ($max > 0 && $max < 100) { return 1000.0; }
            }
        }
        return 1.0;
    }

    /**
     * Gemessener Tagesverlauf (heute) einer Leistungsvariablen, auf $slots
     * Slots gebracht (stündliches Archivaggregat → auf Raster expandiert).
     * Nicht belegte/zukünftige Slots = null. Rückgabe null ohne Archiv/Daten.
     */
    /**
     * Wie readMeasured(), aber mit Cache: integriert den Ist-Verlauf nur alle
     * MeasuredCacheSec Sekunden neu (Archiv-Zugriff), dazwischen aus dem
     * Attribut. Der „jetzt"-Punkt/Legendenwert bleibt davon unberührt (live).
     */
    private function measuredCached(string $key, int $vid, int $slots, int $start)
    {
        $today   = strtotime('today');
        $dateStr = date('Y-m-d', $start);
        $cKey    = $key . '_' . $dateStr;
        // Abgeschlossene Tage ändern sich nicht mehr → länger cachen.
        $ttl     = ($start < $today) ? 21600 : max(15, (int) $this->GetValue('MeasuredCacheSec'));

        $cache = json_decode($this->ReadAttributeString('MeasuredCache'), true);
        if (!is_array($cache)) { $cache = []; }

        $e = $cache[$cKey] ?? null;
        if (is_array($e)
            && (int) ($e['vid'] ?? 0) === $vid
            && (int) ($e['slots'] ?? 0) === $slots
            && (time() - (int) ($e['ts'] ?? 0)) < $ttl) {
            return $e['data'];
        }

        $data = $this->readMeasured($vid, $slots, $start);
        $cache[$cKey] = ['ts' => time(), 'vid' => $vid, 'slots' => $slots, 'data' => $data];
        $this->WriteAttributeString('MeasuredCache', json_encode($cache));
        return $data;
    }

    private function readMeasured(int $vid, int $slots, int $start)
    {
        if ($vid <= 0 || !IPS_VariableExists($vid)) { return null; }
        $aid = $this->getArchiveID();
        if ($aid === 0) { return null; }

        $f = $this->varPowerFactor($vid);

        // 60 min: stündliches Aggregat (exakt, leichtgewichtig).
        if ($slots <= 24) {
            $rows = AC_GetAggregatedValues($aid, $vid, 0, $start, $start + 86400 - 1, 0);
            if (!is_array($rows) || count($rows) === 0) { return null; }
            $out = array_fill(0, $slots, null);
            foreach ($rows as $r) {
                $h = (int) date('G', $r['TimeStamp']);
                if ($h >= 0 && $h < $slots) { $out[$h] = (float) $r['Avg'] * $f; }
            }
            return $out;
        }

        // 30/15 min: zeitgewichtet aus den Rohwerten (keine Treppenstufen).
        $fine = $this->measuredFine($aid, $vid, $start, $slots);
        if (is_array($fine) && $f !== 1.0) {
            foreach ($fine as $i => $v) { if ($v !== null) { $fine[$i] = $v * $f; } }
        }
        return $fine;
    }

    /**
     * Gemessenes Slot-Profil (heute bis „jetzt") zeitgewichtet aus den
     * Rohwerten: jeder geloggte Wert gilt bis zum nächsten Wechsel,
     * Ø-Leistung je Slot = Σ v·Δt / Σ Δt. Zukünftige Slots = null.
     */
    private function measuredFine(int $aid, int $vid, int $start, int $slots)
    {
        $until   = min($start + 86400, time());
        $slotSec = 86400.0 / $slots;

        $carry = null;
        $pre = AC_GetLoggedValues($aid, $vid, 0, $start - 1, 1);
        if (is_array($pre) && count($pre) > 0) { $carry = (float) $pre[0]['Value']; }

        $rows = AC_GetLoggedValues($aid, $vid, $start, $until, 0);
        if (!is_array($rows)) { $rows = []; }
        usort($rows, function ($a, $b) { return $a['TimeStamp'] <=> $b['TimeStamp']; });

        $points = [];
        $first  = ($carry !== null) ? $carry : (count($rows) > 0 ? (float) $rows[0]['Value'] : null);
        $points[] = ['t' => $start, 'v' => $first];
        foreach ($rows as $r) {
            $t = (int) $r['TimeStamp'];
            if ($t > $start && $t <= $until) { $points[] = ['t' => $t, 'v' => (float) $r['Value']]; }
        }
        if ($first === null && count($points) <= 1) { return null; }

        $sumW = array_fill(0, $slots, 0.0);
        $sumS = array_fill(0, $slots, 0.0);
        $cnt  = count($points);
        for ($p = 0; $p < $cnt; $p++) {
            $v = $points[$p]['v'];
            if ($v === null) { continue; }
            $t0 = $points[$p]['t'];
            $t1 = ($p + 1 < $cnt) ? $points[$p + 1]['t'] : $until;
            while ($t0 < $t1) {
                $slot = (int) (($t0 - $start) / $slotSec);
                if ($slot < 0 || $slot >= $slots) { break; }
                $slotEnd = $start + ($slot + 1) * $slotSec;
                $segEnd  = min($t1, $slotEnd);
                $dur     = $segEnd - $t0;
                $sumW[$slot] += $v * $dur;
                $sumS[$slot] += $dur;
                $t0 = $segEnd;
            }
        }
        $out = array_fill(0, $slots, null);
        for ($s = 0; $s < $slots; $s++) {
            if ($sumS[$s] > 0) { $out[$s] = $sumW[$s] / $sumS[$s]; }
        }
        return $out;
    }

    private function getArchiveID(): int
    {
        $ids = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        return (count($ids) > 0) ? (int) $ids[0] : 0;
    }

    private function ResolveSource(string $guid, string $prop): int
    {
        $configured = $this->ReadPropertyInteger($prop);
        if ($configured > 0 && IPS_InstanceExists($configured)) { return $configured; }
        $list = IPS_GetInstanceListByModuleID($guid);
        return (count($list) === 1) ? (int) $list[0] : 0;
    }

    private function ReadSourceValue(int $instanceID, string $ident, $default)
    {
        $vid = @IPS_GetObjectIDByIdent($ident, $instanceID);
        if ($vid === false || $vid <= 0) { return $default; }
        return GetValue($vid);
    }

    private function FontScaleValue(): float
    {
        $s = (float) $this->GetValue('FontScale');
        return max(0.5, min(2.5, $s));
    }

    /** Schlüssel wie im Alt-Property (0=system, siehe EFTILE.Font-Profil in ensureProfiles()). */
    private function FontStack(int $key): string
    {
        switch ($key) {
            case 1: return 'Arial, Helvetica, sans-serif';
            case 2: return 'Verdana, Geneva, sans-serif';
            case 3: return 'Tahoma, Geneva, sans-serif';
            case 4: return '"Trebuchet MS", Helvetica, sans-serif';
            case 5: return 'Georgia, "Times New Roman", serif';
            case 6: return '"Courier New", Courier, monospace';
            case 0:
            default: return "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif";
        }
    }
}
