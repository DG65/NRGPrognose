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
// Minor-Bump 1.0→1.1 (20.08.2026, additiv, mit EMS abgestimmt): gültiger
// Offset-Bereich für GetForecast()/GetEnergyWindow() erweitert 0..2 → 0..4
// (Horizont 3→5 Tage). Rückgaben für Offset 0-2 unverändert, kein Major-Bruch.
define('LFC_CONTRACT_FORECAST', '1.2'); // GetForecast / GetSnapshot (1.2: +generated)
define('LFC_CONTRACT_ENERGYWINDOW', '1.1'); // GetEnergyWindow

// Horizont: gültige Offsets für GetForecast() sind 0..LFC_MAX_OFFSET (0=heute).
// Deckungsgleich mit PVF_MAX_OFFSET. Grenze kommt vom Auto-Temperaturvorhersage-
// Modus: OpenWeatherData (kostenlose OWM-Anbindung) liefert maximal 5 Tage.
define('LFC_MAX_OFFSET', 4);

// EMS — für EMS_GetSpecialEvents (Sondereffekt-Ausschluss). GUID stabil über
// alle Installationen; Instanz wird zur Laufzeit gesucht (optional, kein Fehler
// wenn kein EMS installiert ist).
define('LFC_EMS_GUID', '{31C61A7B-28C4-4F97-9651-1A64B3469E3C}');

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
    /** Erkannte Wallboxen (Opt-in), request-lokal — Erkennung läuft höchstens einmal je Ausführung. */
    private $wallboxCache = null;
    // Request-lokaler Cache der automatisch erkannten Einheiten je Variable
    private $unitCache = [];
    // Request-lokaler Cache des Archiv-Logging-Status je Variable
    private $loggedCache = [];
    // Major der EMS_GetSpecialEvents-Vertragsversion, gegen die wir die Felder
    // from/to/deviceId/source/reason deuten; bei Abweichung Kopplung deaktivieren.
    private const EMS_EVENTS_MAJOR = 1;
    // Wird gesetzt, wenn ein Aufruf einen unbekannten Vertrags-Major lieferte
    // (Update-Meldepflicht) — für die Statuszeile in evaluateAccuracy().
    private $specialEventsVersionMismatch = null;
    // Vertragsversion, mit der EMS_GetSpecialEvents zuletzt geantwortet hat (null = keine Antwort) — für die Formular-Statuszeile.
    private $specialEventsContract = null;
    // Ereignisse für das k-NN-Lernmaterial (Lookback-Tiefe), request-lokal — computeForecast() läuft je Rebuild fünfmal.
    // Im Formular gewählte, noch nicht gespeicherte Werte (Name => Wert), nur während PreviewSelection().
    private $formOverride = [];
    // Automatisch erkannter Hausverbrauch (MeterHub, Funktion 'house'), request-lokal — getDayProfile() läuft hunderte Male je Rebuild.
    private $consumptionCache = null;
    // Major des MHUB_GetFunctions-/MHUBV_GetFunctions-Vertrags, den wir lesen; abweichende Major → Zähler nicht verwenden.
    private const METERHUB_MAJOR = 1;
    private $poolEvents = null;
    // Anzahl der im letzten computeForecast() wegen Sondereffekt zurückgestellten Kandidatentage — für die Status-Zeile.
    private $poolExcluded = 0;

    // Bibliotheks-GUID (aus library.json "id", NICHT die Modul-GUID) — für
    // VersionLabel() im Doku-Panel (SUITE.md „Einheitliche Formular-Optik").
    private const LIBRARY_GUID = '{2D15AFF6-7CD5-4147-B438-B4288BD598AE}';
    private const FORUM_THREAD_URL = 'https://community.symcon.de/t/beta-tester-gesucht-energieprognose-last-pv-prognose-fuers-ems/144125';
    private const ATTR_REVIEW_HINT_GONE = 'ReviewHintDismissed';
    private const LICENSE_URL = 'https://github.com/DG65/NRGPrognose/blob/beta/LICENSE';
    private const PAYPAL_URL = 'https://paypal.me/DietmarGureth';

    // „Was ist neu"-Banner — Vergleich läuft gegen den STRING NEWS_VERSION,
    // jede Erhöhung zeigt den Banner erneut, bis bestätigt. Nur bei
    // nutzerrelevanten Änderungsrunden hochziehen, nicht bei jedem Patch.
    // PFLICHT-CHECK-Rückstand behoben (13.09.2026): der Sondereffekt-
    // Ausschluss (Punkt 3) war seit der Einführung fehlerhaft (schloss
    // ALLE Tage aus statt nur die betroffenen) und wurde erst mit dem
    // EMS-Events-Wrapper-Fix zuverlässig — Wortlaut entsprechend geschärft.
    private const NEWS_VERSION = '0.20 (Build 126)';
    private const NEWS_ITEMS = [
        '🔗 Hausverbrauch automatisch: Bleibt das Feld „Hausverbrauch“ leer und gibt es genau einen MeterHub-Zähler mit der Funktion „Hausverbrauch“ (gemessene Leistung, archiviert, aktuell), übernimmt die Lastprognose dessen Leistung selbst — das Formular zeigt Zähler und Variable. Bei mehreren Zählern wird nichts geraten, eine eigene Variable hat immer Vorrang. Eine bestehende Einstellung bleibt unverändert.',
        '🔗 Formular: Was automatisch erkannt wird, steht nicht mehr als leeres Eingabefeld da. Die OpenWeatherData-Instanz ist bei genau einer Instanz und leerem Feld ausgeblendet und als „🔗 automatisch übernommen“ mit den erhaltenen Tagesmitteln zu sehen. Die Einheit (W/kW) zeigt je Variable, woher sie stammt; das Feld zum Überschreiben liegt eingeklappt unter „Einheit selbst festlegen“ und klappt nur auf, wo die Automatik unsicher ist. Eigene Angaben (✏️) haben Vorrang, die Zeilen folgen der Auswahl sofort. Der Hausverbrauch wird nicht automatisch ermittelt und bleibt immer ein Eingabefeld.',
        '🧹 Sondertage bleiben aus dem Lernmaterial: Mit einem NRG-Stack-EMS werden Tage, an denen ein externer Eingriff den Verbrauch verfälscht hat (z. B. Grid-Rewards- oder Boost-Ladung, §14a-Lastbegrenzung), nicht mehr für die Ähnliche-Tage-Suche verwendet, statt nur aus der Prognosegüte herausgerechnet zu werden. Ereignisse, die nur die PV-Erzeugung betreffen (Negativpreis), zählen hier nicht. Reicht die saubere Historie nicht für k Nachbarn, werden Sondertage aufgefüllt. Ohne EMS ändert sich nichts.',
        '🔎 Neu im Formular: Statuszeilen zeigen live, was automatisch erkannt wurde und welche Werte übernommen werden — Archiv, Einheit je Leistungsvariable (mit Quelle: Profil-Suffix oder Größenordnung), OpenWeatherData-Instanz samt erhaltenen Tagesmitteln, erkannte Wallboxen und die EMS-Kopplung. Steht dort ⚠️ oder ⛔, sagt die Zeile, was zu tun ist.',
        '🔌 Neu, optional: Wallboxen der NRG-Stack-Hubs (ChargerHub/OCPPHub) können jetzt automatisch von der Hauslast abgezogen werden — Schalter unter „Datenquellen (Archiv)", Standard aus. Jede Wallbox wird nur einmal abgezogen, auch wenn sie über beide Hubs erreichbar ist; was erkannt wurde, steht in der Status-Zeile. Bereits von Hand eingetragene Wallboxen dann aus der Liste entfernen.',
        'Die Warnung „ohne neuen Messwert" zählt jetzt ab der letzten Aktualisierung statt ab der letzten Wertänderung — eine regelmäßig gemeldete, aber dauerhaft konstante Leistung (z. B. ungenutzte Wallbox mit 0 W) löst sie nicht mehr fälschlich aus.',
        'Zeitumstellung (25.10. / 28.03.): Historische Umstellungstage fließen bei 15/30 Minuten Auflösung jetzt nach Wanduhr in die Prognose ein statt um bis zu eine halbe Stunde verschoben; die Prognose selbst hat wie immer 96 Wanduhr-Slots.',
        'Am Tageswechsel werden die gespeicherten Tage um Mitternacht weitergeschoben, sodass auch direkte Leser der Prognose-Variablen nicht mehr die Kurve von gestern als „heute" sehen.',
    ];

    // ----------------------------------------------------------------
    //  Modul-Lebenszyklus
    // ----------------------------------------------------------------

    public function Create()
    {
        parent::Create();
        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, false);
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);

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
        // Opt-in: Wallboxen der NRG-Stack-Hubs (ChargerHub/OCPPHub) über deren Vertrag erkennen und
        // ihre Ladeleistung von der Hauslast abziehen (zusätzlich zur manuellen Liste). Standard AUS:
        // Ob die Verbrauchsvariable die Ladeleistung enthält, weiß nur der Nutzer.
        $this->RegisterPropertyBoolean('LFC_AutoWallboxes',  false);
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
        // Horizont-Erweiterung 3→5 Tage (20.08.2026).
        $this->RegisterPropertyInteger('VAR_TempFc_D3',      0);
        $this->RegisterPropertyInteger('VAR_TempFc_D4',      0);

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
        // Horizont-Erweiterung 3→5 Tage (20.08.2026): neue Idents für Tag 3/4,
        // bestehende Today/Tomorrow/DayAfter bewusst unverändert (Archivhistorie).
        $this->RegisterVariableString('LFC_Day3',      'Prognose in 3 Tagen (JSON)', '', 65);
        $this->RegisterVariableString('LFC_Day4',      'Prognose in 4 Tagen (JSON)', '', 68);
        $this->RegisterVariableFloat( 'LFC_kWhDay3',      'Erwartung in 3 Tagen (kWh)', '~Electricity', 63);
        $this->RegisterVariableFloat( 'LFC_kWhDay4',      'Erwartung in 4 Tagen (kWh)', '~Electricity', 66);
        $this->RegisterVariableFloat( 'LFC_WPkWhToday',   'Erwartung WP/Klima heute (kWh)',     '~Electricity', 70);
        $this->RegisterVariableFloat( 'LFC_WPkWhTomorrow','Erwartung WP/Klima morgen (kWh)',    '~Electricity', 71);
        $this->RegisterVariableFloat( 'LFC_WPkWhDayAfter','Erwartung WP/Klima übermorgen (kWh)','~Electricity', 72);
        $this->RegisterVariableFloat( 'LFC_WPkWhDay3',    'Erwartung WP/Klima in 3 Tagen (kWh)','~Electricity', 73);
        $this->RegisterVariableFloat( 'LFC_WPkWhDay4',    'Erwartung WP/Klima in 4 Tagen (kWh)','~Electricity', 74);
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
        // Tageswechsel: gespeicherte Tage um Mitternacht verschieben (siehe RollDay()).
        $this->RegisterTimer('LFC_DayRollTimer', 0, 'LFC_RollDay($_IPS[\'TARGET\']);');
    }

    public function Destroy()
    {
        parent::Destroy();
    }

    /**
     * Baut das Formular aus form.json und ergänzt die drei dynamischen,
     * verbundweit einheitlichen Elemente (SUITE.md „Einheitliche Formular-
     * Optik"): Versionszeile im Doku-Panel, dismissibler Forum-Hinweis,
     * versionsscharf dismissibler „Was ist neu"-Banner ganz oben.
     */
    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        foreach ($form['elements'] as &$el) {
            if (($el['type'] ?? '') === 'ExpansionPanel' && ($el['caption'] ?? '') === '📖 Dokumentation & Hilfe') {
                array_unshift($el['items'], $this->VersionLabel());
                break;
            }
        }
        unset($el);

        // Verbund-Verbindungen live sichtbar machen (SUITE.md „Formular-Konvention",
        // Statuszeilen): je automatischer Erkennung eine live berechnete Zeile.
        $lines = $this->connectionStatusLines();
        foreach ($lines as $name => $caption) {
            $this->patchElementByName($form['elements'], $name, ['caption' => $caption, 'color' => $this->lineColor($caption)]);
        }
        // Wert kommt automatisch (🔗) und das Feld ist leer: Eingabefeld ausblenden statt leer stehen
        // lassen (SUITE.md „Eingabefeld ersetzen"); eigene Angabe (✏️) bleibt sichtbar und hat Vorrang.
        // Der automatische Wert wird nie ins Feld geschrieben.
        if (strpos($lines['ConnStatusOwm'], '🔗') === 0 && $this->ReadPropertyInteger('LFC_OwmInstance') === 0) {
            $this->patchElementByName($form['elements'], 'LFC_OwmInstance', ['visible' => false]);
        }
        // Hausverbrauch: kommt er automatisch (🔗), liegt das Auswahlfeld eingeklappt unter „Eigene Variable
        // stattdessen verwenden" (bewusstes Überschreiben ist gewollt — der Zähler kann eine andere Größe
        // erfassen als der Nutzer meint); in allen anderen Zuständen (✏️/⚠️/⛔) ist es aufgeklappt.
        $this->patchElementByName($form['elements'], 'ConsumptionOverridePanel', ['expanded' => strpos($lines['ConnStatusConsumption'], '🔗') !== 0]);
        // Einheit: die Erkennung über Größenordnung kann bei großen kW-Anlagen irren, deshalb bleibt ein
        // bewusstes Überschreiben möglich — das Feld liegt in einem eingeklappten Panel „Einheit selbst
        // festlegen"; nur wo die Automatik nichts Sicheres liefert (⚠️) oder eine eigene Angabe gilt (✏️),
        // ist es aufgeklappt.
        $unitOpen = (strpos($lines['ConnStatusUnit'], '⚠️') === 0 || strpos($lines['ConnStatusUnit'], '✏️') === 0);
        $this->patchElementByName($form['elements'], 'UnitOverridePanel', ['expanded' => $unitOpen]);

        if (self::FORUM_THREAD_URL !== '' && !$this->ReadAttributeBoolean(self::ATTR_REVIEW_HINT_GONE)) {
            $form['elements'][] = [
                'type' => 'RowLayout',
                'name' => 'ForumHint',
                'items' => [
                    ['type' => 'Label', 'caption' => '💬 Rückmeldungen und Testberichte sind im Symcon-Forum-Thread willkommen:'],
                    ['type' => 'Label', 'link' => true, 'caption' => self::FORUM_THREAD_URL],
                    ['type' => 'Button', 'caption' => 'Nicht mehr anzeigen', 'onClick' => 'LFC_DismissForumHint($id);'],
                ],
            ];
        }

        $form['elements'][] = $this->LicenseHint();

        $banner = $this->newsBanner();
        if ($banner !== null) {
            array_unshift($form['elements'], $banner);
        }

        $purpose = $this->PurposeIntro();
        if ($purpose !== null) {
            array_unshift($form['elements'], $purpose);
        }

        return json_encode($form);
    }

    /**
     * Setzt Eigenschaften (caption, visible, expanded …) eines benannten Elements an beliebiger
     * Tiefe im Formular (ExpansionPanel/RowLayout/… verschachteln). Nur die oberste Ebene
     * abzusuchen war der Fehler im Szenariorechner — deshalb rekursiv.
     * Liefert false, wenn das Element nicht gefunden wurde.
     */
    private function patchElementByName(array &$elements, string $name, array $patch): bool
    {
        foreach ($elements as &$el) {
            if (!is_array($el)) { continue; }
            if (($el['name'] ?? '') === $name) {
                $el = array_merge($el, $patch);
                return true;
            }
            foreach (['items', 'elements'] as $child) {
                if (isset($el[$child]) && is_array($el[$child]) && $this->patchElementByName($el[$child], $name, $patch)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Wert eines Auswahlfelds: der gerade im Formular gewählte (onChange, noch nicht gespeichert)
     * oder — beim Öffnen — der gespeicherte. So folgt die Statuszeile der Auswahl, nicht dem Speicherstand.
     */
    private function selectedInt(string $prop): int
    {
        return array_key_exists($prop, $this->formOverride) ? (int)$this->formOverride[$prop] : $this->ReadPropertyInteger($prop);
    }

    /**
     * Farbe einer Statuszeile nach ihrem Symbol (SUITE.md „Wert kommt automatisch“): 🔗 automatisch übernommen
     * grün, ⛔ Pflichtangabe fehlt rot, alles andere Standardfarbe (-1).
     */
    private function lineColor(string $line): int
    {
        if (strpos($line, '🔗') === 0) { return 0x2E8B3D; }
        if (strpos($line, '⛔') === 0) { return 0xFF0000; }
        return -1;
    }

    /** onChange von Einheit und OpenWeatherData-Auswahl: Statuszeile live nachziehen (SUITE.md „Zeile folgt der Auswahl"). */
    public function PreviewSelection(string $prop, int $value): void
    {
        $lineFor = [
            'LFC_PowerUnit'   => ['ConnStatusUnit'],
            'LFC_OwmInstance' => ['ConnStatusOwm'],
            // Der Hausverbrauch bestimmt auch, was Archiv-Zeile und Einheiten-Zeile prüfen.
            'VAR_Consumption' => ['ConnStatusConsumption', 'ConnStatusArchive', 'ConnStatusUnit'],
        ];
        if (!isset($lineFor[$prop])) { return; }
        $this->formOverride[$prop] = $value;
        $lines = $this->connectionStatusLines();
        foreach ($lineFor[$prop] as $name) {
            $this->UpdateFormField($name, 'caption', $lines[$name]);
            $this->UpdateFormField($name, 'color', $this->lineColor($lines[$name]));
        }
    }

    /**
     * Live berechnete Verbindungs-Statuszeilen (name des Labels => Text).
     * Jede Zeile zeigt, was das Modul tatsächlich erkannt hat und welche Werte
     * es daraus übernimmt — oder warum nichts Brauchbares dabei herauskam.
     * Keine Zeile darf das Formular verhindern: jede Ermittlung ist einzeln
     * abgesichert und meldet im Fehlerfall ⚠️ statt zu werfen.
     */
    private function connectionStatusLines(): array
    {
        $builders = [
            'ConnStatusArchive'  => 'archiveStatusLine',
            'ConnStatusConsumption' => 'consumptionStatusLine',
            'ConnStatusUnit'     => 'unitStatusLine',
            'ConnStatusOwm'      => 'owmStatusLine',
            'ConnStatusWallbox'  => 'wallboxStatusLine',
            'ConnStatusEms'      => 'emsStatusLine',
        ];
        $out = [];
        foreach ($builders as $name => $method) {
            try {
                $out[$name] = $this->$method();
            } catch (\Throwable $e) {
                $out[$name] = '⚠️ Status konnte nicht ermittelt werden: ' . $e->getMessage();
            }
        }
        return $out;
    }

    /**
     * Hausverbrauch-Variable, die das Modul tatsächlich verwendet: die eigene Angabe (Property, Vorrang) oder —
     * nur bei leerem Feld — der Leistungszähler eines MeterHub mit Funktion „Hausverbrauch“ (siehe
     * detectedConsumption()). 0 = keine. $selected = im Formular gewählter, noch nicht gespeicherter Wert.
     */
    private function consumptionVar(?int $selected = null): int
    {
        $p = $selected ?? $this->ReadPropertyInteger('VAR_Consumption');
        if ($p > 0) { return $p; }
        return $this->detectedConsumption()['varID'];
    }

    /**
     * Zuordnungen mit Funktion 'house' aus den MeterHub-Verträgen (MHUB_GetFunctions, MHUBV_GetFunctions; hinter
     * function_exists — ohne MeterHub bleibt alles wie bisher). Der Vertrag liefert einen JSON-String
     * {contractVersion, ready?, assignments[]}; ein Zähler im Neuladen meldet ready=false und wird übersprungen.
     * protected = Testnaht des Prüfstands.
     */
    protected function hubHouseMeters(): array
    {
        $out = [];
        foreach (['MHUB' => 'MeterHub', 'MHUBV' => 'MeterHub (virtuell)'] as $prefix => $hub) {
            $fn = $prefix . '_GetFunctions';
            if (!function_exists($fn)) { continue; }
            foreach (IPS_GetInstanceList() as $iid) {
                $m = IPS_GetModule(IPS_GetInstance($iid)['ModuleInfo']['ModuleID']);
                if (($m['Prefix'] ?? '') !== $prefix) { continue; }
                $raw = @$fn($iid);
                $c = is_string($raw) ? json_decode($raw, true) : $raw;
                if (!is_array($c) || ($c['ready'] ?? true) === false) { continue; }
                foreach ((array)($c['assignments'] ?? []) as $a) {
                    if (is_array($a) && ($a['function'] ?? '') === 'house') {
                        $a['_hub'] = $hub; $a['_instance'] = $iid; $a['_contract'] = (string)($c['contractVersion'] ?? '1.0');
                        $out[] = $a;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Automatisch erkannter Hausverbrauch: NUR ein gemessener Hauslast-Zähler eines MeterHub (Funktion 'house'),
     * dessen Leistung (W) archiviert und aktuell ist. Bewusst nichts anderes: das abgeleitete EMS_HousePower
     * (PV + Batterie − Netz − Wallbox) ist keine Messung und erst kurz archiviert; „Last gesamt“ des InverterHub
     * ist je Hersteller nicht die Hauslast. Mehrere brauchbare Zähler werden nicht geraten (state 'ambiguous').
     *
     * @return array{varID:int,state:string,usable:array,issues:array} state: auto | ambiguous | unusable | none
     */
    private function detectedConsumption(): array
    {
        if ($this->consumptionCache !== null) { return $this->consumptionCache; }
        $out = ['varID' => 0, 'state' => 'none', 'usable' => [], 'issues' => []];
        $aid = (int)$this->getArchiveID();
        $seen = [];
        foreach ($this->hubHouseMeters() as $a) {
            $pid   = (int)($a['powerID'] ?? 0);
            $label = trim((string)($a['label'] ?? '')) !== '' ? trim((string)$a['label']) : IPS_GetName((int)$a['_instance']);
            $e = ['label' => $label, 'hub' => $a['_hub'], 'instance' => (int)$a['_instance'], 'powerID' => $pid, 'contract' => $a['_contract']];
            $slot = (string)($a['slot'] ?? '');
            $major = (int)explode('.', (string)$a['_contract'])[0];
            if ($major !== self::METERHUB_MAJOR) {
                $reason = 'Vertrag ' . $a['_contract'] . ' wird nicht unterstützt (Major ' . self::METERHUB_MAJOR . ' erwartet) – Modul-Update prüfen';
            } elseif (!in_array($slot, ['total', 'main'], true)) {
                $reason = 'nur Teilwert einer Phase (' . $slot . '), nicht der gesamte Hausverbrauch';
            } elseif ($pid <= 0 || !IPS_VariableExists($pid)) {
                $reason = 'Leistungsvariable fehlt';
            } elseif (array_key_exists('measured', $a) && $a['measured'] === false) {
                $reason = 'Leistung nicht gemessen';
            } elseif (array_key_exists('energyMeasured', $a) && $a['energyMeasured'] === false) {
                // Nur der echte MeterHub liefert es (Zählerstand aus der Leistung hochgerechnet, z. B. blue'Log): laut
                // Vertragsbesitzer nicht als Hausverbrauch-Lernmaterial nehmen. Fehlt das Feld, gilt „gemessen“.
                $reason = 'Zählerstand hochgerechnet, nicht gemessen';
            } elseif ($aid <= 0 || !$this->isLogged($aid, $pid)) {
                $reason = 'Leistung nicht archiviert';
            } else {
                $v = IPS_GetVariable($pid);
                $age = time() - max((int)$v['VariableUpdated'], (int)$v['VariableChanged']);
                $reason = ($age > 48 * 3600) ? sprintf('seit %s Tagen ohne neuen Messwert', number_format($age / 86400, 1, ',', '')) : '';
                if ($reason === '') { $reason = $this->consumptionSignReason($aid, $pid); }
            }
            if ($reason !== '') { $e['reason'] = $reason; $out['issues'][] = $e; continue; }
            if (isset($seen[$pid])) { continue; }    // dieselbe Variable über zwei Wege gemeldet
            $seen[$pid] = true;
            $out['usable'][] = $e;
        }
        if (count($out['usable']) === 1) {
            $out['varID'] = $out['usable'][0]['powerID'];
            $out['state'] = 'auto';
        } elseif (count($out['usable']) > 1) {
            $out['state'] = 'ambiguous';
        } elseif (count($out['issues']) > 0) {
            $out['state'] = 'unusable';
        }
        return $this->consumptionCache = $out;
    }

    /**
     * Sicherheitsnetz gegen ein falsch geführtes Vorzeichen: Der Vertrag legt für function='house' nicht fest,
     * ob Verbrauch positiv oder negativ ist. Ein verkehrtes Vorzeichen würde das Lernmaterial (365 Tage) still
     * verkehren. Deshalb wird ein Zähler nur automatisch genommen, wenn seine Stundenwerte der letzten 7 Tage wie
     * eine Hauslast aussehen: Median deutlich über 0 und höchstens ein Fünftel der Werte negativ. Zu wenige
     * Archivdaten (< 12 Stundenwerte) gelten als „nicht prüfbar“ — dann nicht raten. '' = plausibel.
     */
    private function consumptionSignReason(int $aid, int $pid): string
    {
        $rows = @AC_GetAggregatedValues($aid, $pid, 0, strtotime('-7 days'), $this->clampEnd(time()), 0);
        $vals = [];
        if (is_array($rows)) {
            foreach ($rows as $r) { if (isset($r['Avg'])) { $vals[] = (float)$r['Avg']; } }
        }
        $n = count($vals);
        if ($n < 12) {
            return 'Vorzeichen nicht prüfbar: weniger als 12 Stundenwerte der letzten 7 Tage im Archiv';
        }
        sort($vals);
        $median = ($n % 2) ? $vals[intdiv($n, 2)] : ($vals[$n / 2 - 1] + $vals[$n / 2]) / 2;
        $negShare = count(array_filter($vals, function ($x) { return $x < 0; })) / $n;
        if ($median < 0) {
            // Vertrag (MeterHub-Sitzung, SUITE.md „Vorzeichen-Konvention MHUB/MHUBV“): für function='house' gilt + = Verbrauch.
            // Dauerhaft negativ ist eine falsch gestellte Richtung am Zähler, kein Messwert — nicht still umdrehen, melden.
            return 'Hausverbrauch dauerhaft negativ (Median der letzten 7 Tage ' . number_format($median, 1, ',', '') . '), Richtung am Zähler prüfen';
        }
        if ($median == 0) {
            return 'Vorzeichen unklar: Median der letzten 7 Tage ist 0 (eine Hauslast müsste im Mittel positiv sein)';
        }
        if ($negShare > 0.2) {
            return 'Vorzeichen unklar: ' . round($negShare * 100) . ' % der Stundenwerte sind negativ';
        }
        return '';
    }

    /** Hausverbrauch: eigene Variable (✏️), automatisch von einem MeterHub-Zähler (🔗) oder nichts (⚠️/⛔). */
    private function consumptionStatusLine(): string
    {
        $sel = $this->selectedInt('VAR_Consumption');
        $det = $this->detectedConsumption();
        $meter = function (array $e) { return 'MeterHub “' . $e['label'] . '” (#' . $e['instance'] . ')'; };

        if ($sel > 0) {
            if (!IPS_VariableExists($sel)) {
                return '⚠️ Hausverbrauch: die gewählte Variable #' . $sel . ' existiert nicht.';
            }
            $line = '✏️ Hausverbrauch: eigene Variable „' . IPS_GetName($sel) . '“ (#' . $sel . '), hat Vorrang vor der Automatik.';
            if ($det['state'] === 'auto' && $det['varID'] !== $sel) {
                $line .= ' Zum Vergleich: ' . $meter($det['usable'][0]) . ' würde den Hausverbrauch automatisch liefern (Leistung #' . $det['varID'] . ').';
            }
            return $line;
        }
        switch ($det['state']) {
            case 'auto':
                $e = $det['usable'][0];
                return '🔗 Hausverbrauch: automatisch übernommen von ' . $meter($e) . ', Funktion „Hausverbrauch“, Vertrag MHUB ' . $e['contract']
                    . ' – Leistung „' . IPS_GetName($e['powerID']) . '“ (#' . $e['powerID'] . '), archiviert. Erfasst dieser Zähler nicht das, was du als Hausverbrauch meinst (z. B. mit oder ohne Wärmepumpe), unten eine eigene Variable wählen.';
            case 'ambiguous':
                return '⚠️ Hausverbrauch: ' . count($det['usable']) . ' MeterHub-Zähler mit Funktion „Hausverbrauch“ gefunden ('
                    . implode(', ', array_map(function ($e) { return '“' . $e['label'] . '” #' . $e['instance']; }, $det['usable']))
                    . ') – es wird keiner geraten, bitte unten die Variable selbst wählen.';
            case 'unusable':
                $parts = array_map(function ($e) { return '“' . $e['label'] . '” (#' . $e['instance'] . '): ' . $e['reason']; }, $det['issues']);
                return '⚠️ Hausverbrauch: MeterHub-Zähler mit Funktion „Hausverbrauch“ gefunden, aber nicht verwendbar – ' . implode('; ', $parts) . '. Bitte unten die Variable selbst wählen.';
            default:
                return '⛔ Hausverbrauch: Pflichtangabe fehlt. Es gibt keinen MeterHub-Zähler mit Funktion „Hausverbrauch“ – bitte unten die Leistungsvariable selbst wählen (z. B. der Hausverbrauch deines Energiemanagements).';
        }
    }

    /** Archiv-Control und Archivierung des Hauptverbrauchs. */
    private function archiveStatusLine(): string
    {
        $aid = (int)$this->getArchiveID();
        if ($aid <= 0) {
            return '⛔ Archiv: keine Archiv-Control-Instanz gefunden – ohne Archiv gibt es keine Prognose.';
        }
        $head = 'Archiv: verbunden mit “' . IPS_GetName($aid) . '” (#' . $aid . ', automatisch erkannt)';
        $vid = $this->consumptionVar($this->selectedInt('VAR_Consumption'));
        if ($vid <= 0) {
            return 'ℹ️ ' . $head . '. Der Hausverbrauch ist noch nicht festgelegt (siehe unten) – ohne ihn gibt es keine Prognose.';
        }
        if (!IPS_VariableExists($vid)) {
            return '⛔ ' . $head . '. Die gewählte Hausverbrauch-Variable #' . $vid . ' existiert nicht.';
        }
        if (!$this->isLogged($aid, $vid)) {
            return '⛔ ' . $head . ', aber der Hausverbrauch „' . IPS_GetName($vid) . '“ (#' . $vid . ') ist nicht archiviert – bitte im Archiv aktivieren.';
        }
        return '✅ ' . $head . '. Übernommen: Verlauf des Hausverbrauchs „' . IPS_GetName($vid) . '“ (#' . $vid . ') für das Lernen der Tagesprofile.';
    }

    /** Einheit (W/kW) je Leistungsvariable, mit Quelle der Erkennung. */
    private function unitStatusLine(): string
    {
        $mode = $this->selectedInt('LFC_PowerUnit');
        if ($mode === 0 || $mode === 1) {
            return '✏️ Einheit: fest auf ' . ($mode === 1 ? 'kW' : 'W') . ' eingestellt (eigene Angabe, überschreibt die Automatik) – gilt für Hausverbrauch, Abzugsliste und Geräte.';
        }

        $entries = [];
        $cons = $this->consumptionVar($this->selectedInt('VAR_Consumption'));
        if ($cons > 0) { $entries[$cons] = 'Hausverbrauch'; }
        foreach ($this->excludeVarIds() as $vid) { if (!isset($entries[$vid])) { $entries[$vid] = 'Abzug'; } }
        foreach ($this->wpDevices() as $dev) { if (!isset($entries[$dev['var']])) { $entries[$dev['var']] = 'Gerät'; } }
        if (count($entries) === 0) {
            return 'ℹ️ Einheit: noch keine Leistungsvariable gewählt – die Einheit (W/kW) wird je Variable automatisch erkannt, sobald eine gewählt ist.';
        }

        $lines = [];
        $unsure = false;
        foreach ($entries as $vid => $role) {
            $name = $role . ' „' . (IPS_VariableExists($vid) ? IPS_GetName($vid) : '?') . '“ (#' . $vid . ')';
            if (!IPS_VariableExists($vid)) {
                $lines[] = '• ' . $name . ': Variable existiert nicht';
                $unsure = true;
                continue;
            }
            $u = $this->autoPowerUnit($vid);
            $unit = ($u['factor'] == 1000.0) ? 'kW' : (($u['factor'] == 1000000.0) ? 'MW' : 'W');
            if ($u['source'] === 'profile') {
                $lines[] = '• ' . $name . ': ' . $unit . ' – aus dem Profil-Suffix „' . $u['detail'] . '“';
            } elseif ($u['source'] === 'magnitude') {
                $lines[] = '• ' . $name . ': ' . $unit . ' – aus der Größenordnung (Tagesmaximum der letzten 7 Tage ' . $u['detail'] . ')';
            } else {
                $lines[] = '• ' . $name . ': W angenommen – weder Profil-Suffix noch auswertbare Archivdaten; bei einer kW-Variable bitte oben manuell festlegen';
                $unsure = true;
            }
        }
        return ($unsure ? '⚠️' : '🔗') . " Einheit (automatisch übernommen):\n" . implode("\n", $lines);
    }

    /** Temperaturvorhersage: welche OpenWeatherData-Instanz, welche Werte kommen an. */
    private function owmStatusLine(): string
    {
        $mode = $this->ReadPropertyInteger('LFC_TempFcMode');
        if ($mode === LFC_FC_DAILY) {
            $n = 0;
            foreach (['VAR_TempFc_D0', 'VAR_TempFc_D1', 'VAR_TempFc_D2', 'VAR_TempFc_D3', 'VAR_TempFc_D4'] as $prop) {
                $vid = $this->ReadPropertyInteger($prop);
                if ($vid > 0 && IPS_VariableExists($vid)) { $n++; }
            }
            return ($n > 0)
                ? '✏️ Temperaturvorhersage: Modus „Tagesmittel-Variablen“ – ' . $n . ' von 5 Tagen eigene Variablen, OpenWeatherData wird nicht verwendet.'
                : '⚠️ Temperaturvorhersage: Modus „Tagesmittel-Variablen“, aber keine Variable gewählt – es gilt das saisonale Normal aus dem Temperatur-Archiv.';
        }
        if ($mode === LFC_FC_IDENT) {
            $parent = $this->ReadPropertyInteger('LFC_FcParentID');
            return ($parent > 0 && IPS_ObjectExists($parent))
                ? '✏️ Temperaturvorhersage: Modus „Ident-Muster“ mit Eltern-Objekt „' . IPS_GetName($parent) . '“ (#' . $parent . '), OpenWeatherData wird nicht verwendet.'
                : '⚠️ Temperaturvorhersage: Modus „Ident-Muster“, aber kein Eltern-Objekt gewählt – es gilt das saisonale Normal aus dem Temperatur-Archiv.';
        }

        $list = IPS_GetInstanceListByModuleID(LFC_OWM_GUID);
        $list = is_array($list) ? array_values($list) : [];
        $tempHist = $this->ReadPropertyInteger('VAR_TempHistory');
        $fallback = ($tempHist > 0 && IPS_VariableExists($tempHist))
            ? 'es gilt das saisonale Normal aus dem Temperatur-Archiv'
            : 'es gilt die Heizgrenztemperatur, weil auch keine Außentemperatur-Historie gewählt ist';

        if (count($list) === 0) {
            return 'ℹ️ Temperaturvorhersage: keine OpenWeatherData-Instanz gefunden – ' . $fallback . '.';
        }
        $sel = $this->selectedInt('LFC_OwmInstance');
        $owm = (int)$this->owmInstance($sel);
        $chosen = ($sel > 0 && $owm === $sel);
        $ignored = ($sel > 0 && !$chosen);
        $src = 'OpenWeatherData “' . IPS_GetName($owm) . '” (#' . $owm . ')';
        $head = $chosen
            ? 'Temperaturvorhersage: eigene Auswahl ' . $src . ', hat Vorrang vor der Automatik'
            : 'Temperaturvorhersage: automatisch übernommen von ' . $src;

        $res = $this->aggregateForecastSlots($owm, LFC_OWM_IDENT_TIME, LFC_OWM_IDENT_MIN, LFC_OWM_IDENT_MAX, 0, LFC_OWM_MAX_SLOTS);
        $days = array_filter($res, function ($v) { return $v !== null; });
        $warn = '';
        if ($ignored) {
            $warn .= ' Die gewählte Instanz #' . $sel . ' ist keine OpenWeatherData-Instanz und wird ignoriert – bitte neu wählen oder leeren.';
        } elseif (count($list) > 1 && !$chosen) {
            $warn .= ' Es gibt ' . count($list) . ' OpenWeatherData-Instanzen und keine ist gewählt, deshalb wird die erste genommen – bitte die passende festlegen.';
        }
        if (count($days) === 0) {
            return '⚠️ ' . $head . ', aber ohne Stundenvorhersage – dort „hourly_forecast_count“ auf mindestens 40 setzen (voller 5-Tage-Horizont); bis dahin: ' . $fallback . '.' . $warn;
        }
        $sample = [];
        foreach ($days as $off => $t) { $sample[] = ($off === 0 ? 'heute' : '+' . $off . ' T') . ' ' . number_format((float)$t, 1, ',', '') . ' °C'; }
        $line = $head . '. Werte: Tagesmittel für ' . count($days) . ' von 5 Tagen (' . implode(', ', array_slice($sample, 0, 3)) . (count($sample) > 3 ? ' …' : '') . ').' . $warn;
        return ($warn !== '' ? '⚠️ ' : ($chosen ? '✏️ ' : '🔗 ')) . $line;
    }

    /** Wallboxen der Hubs: Schalter, was erkannt und abgezogen wird. */
    private function wallboxStatusLine(): string
    {
        if (!$this->ReadPropertyBoolean('LFC_AutoWallboxes')) {
            $n = count($this->hubChargers());
            return ($n > 0)
                ? 'ℹ️ Wallboxen: automatische Erkennung aus (Standard). Die Hubs melden ' . $n . ' Wallbox-Eintrag/-Einträge – einschalten, wenn der Hausverbrauch die Ladeleistung enthält.'
                : 'ℹ️ Wallboxen: automatische Erkennung aus (Standard); keine Hubs (ChargerHub/OCPPHub) mit Wallboxen gefunden – Wallboxen bei Bedarf über die Abzugsliste eintragen.';
        }
        $w = $this->detectedWallboxes();
        $parts = [];
        foreach ($w['use'] as $x) {
            $age = ($x['ageDays'] === null) ? '' : (($x['ageDays'] < 1) ? ', Wert aktuell' : ', zuletzt aktualisiert vor ' . number_format($x['ageDays'], 1, ',', '') . ' Tagen');
            $parts[] = '• ' . $x['label'] . ' (' . $x['hub'] . ', Ladeleistung #' . $x['powerID'] . $age . ')';
        }
        $warn = false;
        foreach ($w['use'] as $x) { if (($x['ageDays'] ?? 0) > 7) { $warn = true; } }
        foreach ($w['skipped'] as $x) { $parts[] = '• ' . $x['label'] . ': ignoriert – ' . $x['reason']; $warn = true; }
        $manual = array_filter((array)json_decode((string)$this->ReadPropertyString('ExcludeVars'), true), function ($r) { return (int)($r['VariableID'] ?? 0) > 0; });
        if (count($w['use']) === 0 && count($w['skipped']) === 0) {
            return 'ℹ️ Wallboxen: automatische Erkennung an, aber keine Wallbox mit archivierter Ladeleistung gefunden.';
        }
        if (count($w['use']) > 0 && count($manual) > 0) {
            $parts[] = 'Hinweis: die manuelle Abzugsliste ist zusätzlich aktiv – enthält sie dieselben Wallboxen, werden sie doppelt abgezogen.';
            $warn = true;
        }
        return ($warn ? '⚠️' : '✅') . ' Wallboxen: ' . count($w['use']) . " automatisch erkannt und abgezogen:\n" . implode("\n", $parts);
    }

    /** EMS-Kopplung für Sondereffekte: Prognosegüte, Fehler-Korrektur und k-NN-Lernmaterial. */
    private function emsStatusLine(): string
    {
        $ems = function_exists('EMS_GetSpecialEvents') ? $this->emsInstance() : 0;
        if ($ems <= 0) {
            return 'ℹ️ EMS: nicht gefunden – Tage mit Sondereffekten (Grid Rewards, Boost, §14a-Lastbegrenzung …) werden weder aus dem Lernmaterial noch aus der Prognosegüte herausgerechnet, alle Tage zählen.';
        }
        $lookback = $this->ReadPropertyInteger('LFC_LookbackDays');
        $events = $this->poolSpecialEvents($lookback);
        $head = 'EMS: verbunden mit “' . IPS_GetName($ems) . '” (#' . $ems . ', automatisch erkannt)';
        if ($this->specialEventsVersionMismatch !== null) {
            return '⚠️ ' . $head . ', aber der Vertrag EMS_GetSpecialEvents hat Version ' . $this->specialEventsVersionMismatch . ' (unterstützt: ' . self::EMS_EVENTS_MAJOR . '.x) – Kopplung bis zum Modul-Update aus.';
        }
        if ($this->specialEventsContract === null) {
            return '⚠️ ' . $head . ', aber EMS_GetSpecialEvents liefert keine Ereignisliste – Sondereffekte werden nicht berücksichtigt.';
        }
        $days = 0;
        for ($d = 1; $d <= $lookback; $d++) {
            $ts = strtotime('today -' . $d . ' days');
            if ($this->dayHasSpecialEvent($events, $ts, $this->dayEndExclusive($ts) - 1, 'load')) { $days++; }
        }
        $line = '✅ ' . $head . ', Vertrag EMS_GetSpecialEvents ' . $this->specialEventsContract . '. Übernommen: ' . count($events)
            . ' Sonderereignis(se), davon betreffen ' . $days . ' Tag(e) der letzten ' . $lookback . ' die Last – diese Tage bleiben aus dem Lernmaterial (Ähnliche-Tage-Suche), der Prognosegüte und der Fehler-Korrektur (Band) heraus.'
            . ' Ereignisse, die nur die PV-Erzeugung betreffen (z. B. Negativpreis), zählen hier nicht.';
        if (version_compare($this->specialEventsContract, '1.1', '<')) {
            $line .= ' Hinweis: Vertrag ' . $this->specialEventsContract . ' liefert kein Feld „affects“ – jedes Ereignis gilt dann für Last und PV.';
        }
        return $line;
    }

    /**
     * „Wozu dieses Modul?" — ganz oben, vor dem News-Panel, einmalig
     * dismissible (nicht pro Version, s. SUITE.md „Einheitliche
     * Formular-Optik" Punkt 0). Auslöser: ein Praxistester wusste am
     * Anfang nicht, was er mit dem Modul machen kann/soll.
     */
    private function PurposeIntro(): ?array
    {
        if ($this->ReadAttributeBoolean('PurposeIntroGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true,
            'caption' => '👋  Wozu dieses Modul?',
            'items' => [
                ['type' => 'Label', 'caption' => 'Lastprognose sagt für die kommenden Tage deinen Stromverbrauch voraus — aus deiner Verbrauchshistorie per Ähnliche-Tage-Verfahren (k-NN), berücksichtigt Wochentag, Jahreszeit, Außentemperatur und Anwesenheit.'],
                ['type' => 'Label', 'caption' => 'Der Nutzen: eine verlässliche Planungsgrundlage für Lastmanagement oder ein Energiemanagement-System (EMS), ganz ohne Wetterstation oder manuelles Schätzen.'],
                ['type' => 'Label', 'caption' => 'Für die Erzeugungsseite gehört PVPrognose dazu, für eine gemeinsame Übersicht beider Prognosen die Kachel Energiebilanz.'],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'LFC_AckPurposeIntro($id);'],
            ],
        ];
    }

    public function AckPurposeIntro(): void
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
    }

    /** Versionszeile im Doku-Panel — dauerhaft sichtbar, anders als der dismissible „Neu"-Banner. */
    private function VersionLabel(): array
    {
        $lib = @IPS_GetLibrary(self::LIBRARY_GUID);
        $txt = (is_array($lib) && isset($lib['Version']))
            ? 'ℹ️ Lastprognose — Teil der NRG-Stack Prognose-Suite, Version ' . $lib['Version'] . ' (Build ' . ($lib['Build'] ?? '?') . ')'
            : 'ℹ️ Lastprognose';
        return ['type' => 'Label', 'caption' => $txt];
    }

    /** „Was ist neu"-Banner: erscheint nach einem Update, bis „Verstanden" geklickt wird. */
    private function newsBanner()
    {
        if ($this->ReadAttributeString('SeenNews') === self::NEWS_VERSION) {
            return null;
        }
        $items = [['type' => 'Label', 'caption' => '🆕 Neu — bitte kurz ansehen und ggf. die Einstellungen prüfen:']];
        foreach (self::NEWS_ITEMS as $line) {
            $items[] = ['type' => 'Label', 'caption' => '• ' . $line];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'LFC_AckNews($id);'];
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'caption' => '🆕 Neu in Version ' . self::NEWS_VERSION, 'expanded' => true, 'items' => $items];
    }

    public function AckNews(): void
    {
        $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    public function DismissForumHint(): void
    {
        $this->WriteAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, true);
        $this->UpdateFormField('ForumHint', 'visible', false);
    }

    /**
     * "Über dieses Modul" — ganz unten, nach dem Forum-Hinweis, bewusst
     * NICHT dismissible (Lizenz ist kein einmaliger Hinweis). Wortlaut
     * verbundweit identisch (SUITE.md „Einheitliche Formular-Optik" Punkt 5).
     */
    private function LicenseHint(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🧡  Über dieses Modul',
            'items' => [
                ['type' => 'Label', 'caption' => 'Entstanden aus echter Begeisterung für die eigene Anlage — und ein paar durchgetippten Abenden. Trotzdem: Software-Hobby hin oder her, das hier ist geistiges Eigentum und echte Arbeit steckt drin.'],
                ['type' => 'Label', 'caption' => 'Lizenz: PolyForm Noncommercial 1.0.0 — privat und nicht-kommerziell frei nutzbar, für den gewerblichen Einsatz braucht es eine gesonderte Lizenz vom Rechteinhaber.'],
                ['type' => 'Button', 'caption' => 'Lizenztext ansehen', 'onClick' => "echo '" . self::LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Gewerbliche Nutzung oder Fragen zur Lizenz? Einfach melden: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'Gefällt dir das Modul und du möchtest trotzdem etwas dalassen? Über eine kleine Spende freue ich mich — völlig freiwillig, keine Gegenleistung nötig.'],
                ['type' => 'Button', 'caption' => '☕  Spenden via PayPal', 'onClick' => "echo '" . self::PAYPAL_URL . "';", 'link' => true],
            ],
        ];
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $active = $this->ReadPropertyBoolean('LFC_Active');
        $hours  = max(1, $this->ReadPropertyInteger('LFC_IntervalHours'));

        if ($active) {
            $this->SetTimerInterval('LFC_RebuildTimer', $hours * 3600 * 1000);
            $this->SetTimerInterval('LFC_DayRollTimer', $this->msToNextMidnight());
            $this->SetStatus(102);
            $this->log(LFC_LOG_BASIC, 'Aktiv, Neuberechnung alle ' . $hours . ' h');
        } else {
            $this->SetTimerInterval('LFC_RebuildTimer', 0);
            $this->SetTimerInterval('LFC_DayRollTimer', 0);
            $this->SetStatus(104);
            $this->log(LFC_LOG_BASIC, 'Deaktiviert');
        }
    }

    /** Millisekunden bis 10 s nach der nächsten lokalen Mitternacht. */
    private function msToNextMidnight(): int
    {
        return max(1000, (strtotime('tomorrow') - time() + 10) * 1000);
    }

    /**
     * Tageswechsel: schiebt die gespeicherten Tage weiter (gestern-"morgen" wird
     * "heute" usw.), ohne bis zum nächsten Rebuild zu warten. Sonst zeigt jeder,
     * der LFC_Today direkt liest (Energiebilanz u. a.), bis dahin die Kurve von
     * gestern als "heute" (Fund bei PVPrognose, EMS 19.09.2026 — gleiche
     * Cache-Struktur). Läuft als Timer kurz nach Mitternacht, stellt sich selbst neu.
     */
    public function RollDay()
    {
        try {
            $this->rotateForecastCache();
        } finally {
            $this->SetTimerInterval('LFC_DayRollTimer', $this->ReadPropertyBoolean('LFC_Active') ? $this->msToNextMidnight() : 0);
        }
    }

    /**
     * Ordnet die fünf gespeicherten Tagesprognosen nach ihrem 'date' wieder den
     * Offsets zu. Ein Offset ohne passende Quelle bekommt eine ehrliche
     * Leer-Prognose (generated=0) statt einer falschen Kurve mit altem Datum.
     */
    private function rotateForecastCache()
    {
        $idents = ['LFC_Today', 'LFC_Tomorrow', 'LFC_DayAfter', 'LFC_Day3', 'LFC_Day4'];
        $kwhIds = ['LFC_kWhToday', 'LFC_kWhTomorrow', 'LFC_kWhDayAfter', 'LFC_kWhDay3', 'LFC_kWhDay4'];
        $byDate = [];
        foreach ($idents as $ident) {
            $c = json_decode((string)$this->GetValue($ident), true);
            if (is_array($c) && isset($c['date']) && ($c['generated'] ?? 1) !== 0) { $byDate[$c['date']] = $c; }
        }
        foreach ($idents as $o => $ident) {
            $want = date('Y-m-d', strtotime('today +' . $o . ' days'));
            $cur  = json_decode((string)$this->GetValue($ident), true);
            if (is_array($cur) && ($cur['date'] ?? null) === $want) { continue; }
            $fc = $byDate[$want] ?? $this->emptyForecast(strtotime('today +' . $o . ' days'));
            $this->SetValue($ident, json_encode($fc));
            $this->SetValue($kwhIds[$o], round((float)($fc['kwh'] ?? 0), 2));
        }
    }

    // ----------------------------------------------------------------
    //  Öffentliche Funktionen
    // ----------------------------------------------------------------

    /**
     * Vollständige Neuberechnung aller Prognose-Horizonte.
     */
    /**
     * Neuberechnung anstoßen. Gibt einen menschenlesbaren Ergebnistext zurück
     * (✅/⚠️/⛔-Präfix) — der Formular-Button ruft das über
     * "echo LFC_Rebuild($id);" auf, damit man ohne Formular-Neuöffnen sofort
     * sieht, dass etwas passiert ist (Verbund-Konvention "Sichtbare
     * Rückmeldung bei jeder Aktion", SUITE.md 20.08.2026). Der Intervall-
     * Timer ruft dieselbe Methode auf und ignoriert den Rückgabewert.
     */
    public function Rebuild(): string
    {
        if ($this->consumptionVar() <= 0) {
            $msg = '⛔ Keine Verbrauchsvariable konfiguriert und kein eindeutiger MeterHub-Hausverbrauchszähler gefunden.';
            $this->SetValue('LFC_Status', $msg);
            return $msg;
        }

        try {
            $idents = ['LFC_Today', 'LFC_Tomorrow', 'LFC_DayAfter', 'LFC_Day3', 'LFC_Day4'];
            $kwhIds = ['LFC_kWhToday', 'LFC_kWhTomorrow', 'LFC_kWhDayAfter', 'LFC_kWhDay3', 'LFC_kWhDay4'];

            $fcs = [];
            for ($offset = 0; $offset <= LFC_MAX_OFFSET; $offset++) {
                $fc = $this->computeForecast($offset);
                $fcs[$offset] = $fc;
                $this->SetValue($idents[$offset], json_encode($fc));
                $this->SetValue($kwhIds[$offset], round($fc['kwh'], 2));
            }
            $this->saveSnapshot($fcs);
            $this->evaluateAccuracy();

            // Optional: separate WP-/Klima-Prognose über den vollen Horizont
            $wpIds = ['LFC_WPkWhToday', 'LFC_WPkWhTomorrow', 'LFC_WPkWhDayAfter', 'LFC_WPkWhDay3', 'LFC_WPkWhDay4'];
            for ($offset = 0; $offset <= LFC_MAX_OFFSET; $offset++) {
                $wp = $this->wpForecast($offset);
                if ($wp !== null) {
                    $this->SetValue($wpIds[$offset], round($wp, 2));
                }
            }

            $this->SetValue('LFC_LastUpdate', time());
            $status = sprintf(
                '✅ heute %.1f / morgen %.1f / übermorgen %.1f / Tag 4 %.1f / Tag 5 %.1f kWh',
                $this->GetValue('LFC_kWhToday'),
                $this->GetValue('LFC_kWhTomorrow'),
                $this->GetValue('LFC_kWhDayAfter'),
                $this->GetValue('LFC_kWhDay3'),
                $this->GetValue('LFC_kWhDay4')
            );
            // Nicht archivierte Variablen melden (werden ignoriert / nicht abgezogen).
            $missing = $this->unloggedVars();
            if (count($missing) > 0) {
                $status .= ' | ⚠ nicht archiviert (ignoriert): ' . implode(', ', $missing);
                $this->log(LFC_LOG_BASIC, 'Nicht archivierte Variablen: ' . implode(', ', $missing));
            }
            $stale = $this->checkDataPlausibility();
            if (count($stale) > 0) {
                $status .= ' | ⚠️ ' . implode(' · ', $stale);
            }
            $status .= $this->wallboxNotices();
            if ($this->ReadPropertyInteger('VAR_Consumption') <= 0 && $this->detectedConsumption()['state'] === 'auto') {
                $e = $this->detectedConsumption()['usable'][0];
                $status .= sprintf(' | 🔗 Hausverbrauch automatisch von MeterHub „%s“ (Leistung #%d)', $e['label'], $e['powerID']);
            }
            if ($this->poolExcluded > 0) {
                $status .= sprintf(' | ℹ️ Lernmaterial ohne %d Tag(e) mit Sondereffekt (EMS)', $this->poolExcluded);
            }
            $this->SetValue('LFC_Status', $status);
            $this->SetStatus(102);
            $this->log(LFC_LOG_BASIC, 'Neuberechnung abgeschlossen');
            return $status;

        } catch (Exception $e) {
            $msg = '⛔ Fehler: ' . $e->getMessage();
            $this->SetValue('LFC_Status', $msg);
            $this->log(LFC_LOG_BASIC, 'Fehler: ' . $e->getMessage());
            return $msg;
        }
    }

    /**
     * Liefert die Prognose für einen Tag als Array. $offset: 0 = heute,
     * 1 = morgen, 2 = übermorgen. Für das EMS per LFC_GetForecast($id, $offset)
     * abrufbar.
     *
     * Performance (Fund aus PVMonitor/Dashboard-Sitzung, 20.08.2026): liest
     * bevorzugt den von Rebuild() bereits berechneten und in LFC_Today/
     * Tomorrow/DayAfter zwischengespeicherten Stand, statt bei JEDEM externen
     * Aufruf die komplette k-NN-Suche (bis zu LFC_LookbackDays=365 Kandidaten-
     * tage, je ein Archivzugriff) neu zu rechnen — das war bei jedem Konsumenten
     * (PVMonitor, EMS, …) unnötig teuer, weil Rebuild() denselben Wert für
     * denselben Kalendertag ohnehin schon periodisch vorhält. Der Cache ist nur
     * gültig, solange sein 'date'-Feld noch zum angefragten Offset passt (fällt
     * nach Mitternacht automatisch auf eine frische Berechnung zurück). Rebuild()
     * selbst nutzt computeForecast() direkt, damit es nie den eigenen alten
     * Stand zurückbekommt statt neu zu rechnen.
     */
    public function GetForecast(int $offset)
    {
        $idents   = ['LFC_Today', 'LFC_Tomorrow', 'LFC_DayAfter', 'LFC_Day3', 'LFC_Day4'];
        $wantDate = date('Y-m-d', strtotime('today +' . $offset . ' days'));
        // Nach DATUM suchen, nicht nur im Ident des Offsets: Nach Mitternacht ist
        // der gestrige "morgen"-Stand heute der "heute"-Stand (steht in LFC_Tomorrow).
        // Sonst wird bis zum nächsten Rebuild jeder Aufruf teuer neu berechnet
        // (Fund bei PVPrognose, EMS 19.09.2026 — gleiche Cache-Struktur hier).
        foreach ($idents as $i => $ident) {
            $cached = json_decode((string)$this->GetValue($ident), true);
            if (!is_array($cached) || ($cached['date'] ?? null) !== $wantDate) { continue; }
            if (($cached['generated'] ?? 1) === 0) { continue; } // Leer-Platzhalter aus rotateForecastCache()
            // Treffer in anderem Speicher als dem des Offsets = Tageswechsel noch
            // nicht verschoben → nachholen, damit auch direkte Leser stimmen.
            if (isset($idents[$offset]) && $i !== $offset) { $this->rotateForecastCache(); }
            return $cached;
        }
        return $this->computeForecast($offset);
    }

    /** Die eigentliche, teure k-NN-Berechnung — siehe GetForecast() für den Cache davor. */
    private function computeForecast(int $offset)
    {
        $targetTs = strtotime('today +' . $offset . ' days');
        $tf       = $this->dayFeatures($targetTs, true);

        $lookback = $this->ReadPropertyInteger('LFC_LookbackDays');
        $k        = max(1, $this->ReadPropertyInteger('LFC_K'));

        // Kandidaten: alle Tage von gestern rückwärts. Tage mit einem Sondereffekt, der die LAST
        // verfälscht (Grid Rewards, Boost, §14a-Lastbegrenzung — EMS_GetSpecialEvents, `affects`
        // ∋ 'load'), kommen nicht ins Lernmaterial: sonst steckt z. B. die Ladeleistung einer
        // Regelenergie-Ladung im Ähnliche-Tage-Mittel. Zurückgestellt statt verworfen: reicht
        // die saubere Historie nicht für k Nachbarn (junge Installation), werden die nächsten
        // zurückgestellten Tage aufgefüllt — eine Prognose mit Sondertagen ist besser als keine.
        $events = $this->poolSpecialEvents($lookback);
        $cands = [];
        $held  = [];
        for ($d = 1; $d <= $lookback; $d++) {
            $ts      = strtotime('today -' . $d . ' days');
            $profile = $this->getDayProfile($ts);
            if ($profile === null) {
                continue; // kein/zu wenig Datum an diesem Tag
            }
            $cf   = $this->dayFeatures($ts, false);
            $dist = $this->distance($tf, $cf);
            $cand = ['dist' => $dist, 'profile' => $profile];
            if ($this->dayHasSpecialEvent($events, $ts, $this->dayEndExclusive($ts) - 1, 'load')) {
                $held[] = $cand;
            } else {
                $cands[] = $cand;
            }
        }
        $this->poolExcluded = count($held);
        if (count($held) > 0 && count($cands) < $k) {
            usort($held, function ($a, $b) { return $a['dist'] <=> $b['dist']; });
            $fill = array_slice($held, 0, $k - count($cands));
            $this->log(LFC_LOG_BASIC, sprintf('Nur %d Tage ohne Sondereffekt, k=%d: %d Tag(e) mit Sondereffekt zum Auffüllen verwendet',
                count($cands), $k, count($fill)));
            $cands = array_merge($cands, $fill);
        }

        if (count($cands) === 0) {
            return $this->emptyForecast($targetTs, time()); // echtes Ergebnis "kein Nachbar", kein Platzhalter
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
            'generated' => time(),
        ];
    }

    public function GetStatusText()
    {
        return (string)$this->GetValue('LFC_Status');
    }

    /**
     * Erwarteter Hausverbrauch (kWh) in einem beliebigen Zeitfenster
     * [$fromTs, $toTs) — z.B. "von jetzt bis morgen früh, wenn die PV
     * wieder produziert". Bewusst ohne PV-Bezug: DIESES Modul beantwortet
     * nur "wie viel Verbrauch im Fenster", die Wahl des Fensters (z.B. der
     * PV-Startzeitpunkt) obliegt dem Aufrufer — keine Abhängigkeit zu PVF.
     * Deckt bis zu LFC_MAX_OFFSET+1 Tage ab (unser Horizont); darüber hinaus
     * fehlende Anteile werden nicht ergänzt. 'coverage' zeigt
     * an, welcher Anteil des Fensters tatsächlich mit einer ECHTEN Prognose
     * abgedeckt ist — Tage ohne Nachbarn (z.B. unkonfigurierte Instanz, siehe
     * emptyForecast()) zählen NICHT als abgedeckt, auch wenn ihr Nullprofil
     * strukturell gültig ist. Sonst würde eine kaputte Konfiguration einem
     * unbeaufsichtigten Aufrufer "kwh=0, coverage=1.0" vortäuschen statt
     * ehrlich "keine Daten" zu melden (Verbund-Ziel „Zuverlässigkeit ohne
     * KI-Krücke" — niemand schaut hier live nach, ob's stimmt).
     * Für das EMS per LFC_GetEnergyWindow($id, $fromTs, $toTs) abrufbar.
     */
    public function GetEnergyWindow(int $fromTs, int $toTs): array
    {
        $result = [
            'contractVersion' => LFC_CONTRACT_ENERGYWINDOW,
            'from' => $fromTs,
            'to'   => $toTs,
            'kwh'  => 0.0,
            'coverage' => 0.0,
        ];
        if ($toTs <= $fromTs) { return $result; }

        list($kwh, $coveredSec) = $this->integrateWindow(
            $fromTs, $toTs, $this->slotMinutes(), strtotime('today'),
            function (int $offset) { return $this->GetForecast($offset); }
        );

        $result['kwh'] = round($kwh, 3);
        $result['coverage'] = round($coveredSec / ($toTs - $fromTs), 3);
        return $result;
    }

    /**
     * Energie (kWh) und abgedeckte Sekunden eines Zeitfensters aus den
     * Tagesprofilen. Läuft über die REALE Zeit und ordnet jeden Zeitpunkt seinem
     * WANDUHR-Slot zu (Slot i = i-te Viertelstunde/Halbstunde/Stunde nach
     * Wanduhr, wie in GetForecast). Damit stimmen Zeitumstellungstage: die
     * doppelte Stunde im Oktober (25-h-Tag) zählt zweimal mit dem Slot-Wert,
     * die fehlende Stunde im März (23-h-Tag) gar nicht. Früher wurde
     * "Mitternacht + i * Slotsekunden" gerechnet, das ab 02:00 Wanduhr an diesen
     * zwei Tagen um 1 h versetzt lag (Fund: EMS-Anfrage zur Zeitumstellung,
     * 20.09.2026).
     *
     * $forecastFor(int $offset): array|null liefert die Prognose je Tag-Offset,
     * $todayTs ist die lokale Mitternacht von Offset 0 (Parameter statt time(),
     * damit der Prüfstand beliebige Tage simulieren kann).
     * Rückgabe [kWh, abgedeckteSekunden]. "Abgedeckt" nur bei echten Nachbarn —
     * ein emptyForecast()-Nullprofil zählt nicht.
     */
    private function integrateWindow(int $fromTs, int $toTs, int $slotMin, int $todayTs, callable $forecastFor): array
    {
        $kwh = 0.0;
        $coveredSec = 0;
        $cache = [];
        $t = $fromTs;
        while ($t < $toTs) {
            $dayStart = strtotime('today', $t);
            $offset   = (int)round(($dayStart - $todayTs) / 86400);
            $minutes  = (int)date('G', $t) * 60 + (int)date('i', $t);
            $slot     = intdiv($minutes, $slotMin);
            // Nächste Slotgrenze in realer Zeit (Zeitumstellungen sind volle
            // Stunden, Slotgrenzen liegen also weiter auf ganzen Minuten).
            $intoSlot = ($minutes % $slotMin) * 60 + (int)date('s', $t);
            $segEnd   = min($toTs, $t - $intoSlot + $slotMin * 60);
            if ($segEnd <= $t) { $segEnd = min($toTs, $t + 1); }
            if ($offset >= 0 && $offset <= LFC_MAX_OFFSET) {
                if (!array_key_exists($offset, $cache)) { $cache[$offset] = $forecastFor($offset); }
                $fc   = $cache[$offset];
                $mean = is_array($fc) ? ($fc['mean'] ?? null) : null;
                if (is_array($mean) && isset($mean[$slot])) {
                    $sec = $segEnd - $t;
                    $kwh += ((float)$mean[$slot]) * ($sec / 3600.0) / 1000.0; // W * h / 1000 = kWh
                    if (($fc['neighbors'] ?? 0) > 0) { $coveredSec += $sec; }
                }
            }
            $t = $segEnd;
        }
        return [$kwh, $coveredSec];
    }

    /**
     * Gespeicherte Prognose (Soll) eines vergangenen Tages ('Y-m-d').
     * Rückgabe [] wenn kein Snapshot vorhanden.
     */
    public function GetSnapshot(string $date)
    {
        $snaps = json_decode((string)$this->ReadAttributeString('LFC_Snapshots'), true);
        if (!is_array($snaps) || !isset($snaps[$date])) { return []; }
        return array_merge(['contractVersion' => LFC_CONTRACT_FORECAST], $snaps[$date]);
    }

    /**
     * Prognosegüte: vergleicht je vergangenem Tag (bis 14 zurück) den
     * Day-Ahead-Snapshot (Soll-kWh) mit dem gemessenen Ist aus dem Archiv
     * (identisch zum Trainings-Ziel: Hauptverbrauch minus Abzugsliste).
     * Bias = mittlere vorzeichenbehaftete Abweichung, |Ø| = mittlerer Betrag.
     */
    private function evaluateAccuracy()
    {
        $snaps = json_decode((string)$this->ReadAttributeString('LFC_Snapshots'), true);
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
            if ($this->dayHasSpecialEvent($specialEvents, $ts, $this->dayEndExclusive($ts) - 1, 'load')) { $excluded++; continue; }
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
            // Ausschluss-Zähler auch im Leer-Fall anzeigen — sonst ist nicht
            // erkennbar, ob Snapshots fehlen oder alle Tage ausgeschlossen
            // wurden (genau das verschleierte den Wrapper-Bug, s. fetchSpecialEvents()).
            $txt = 'Noch keine auswertbaren Tage (Snapshots sammeln sich seit v0.14)';
            if ($excluded > 0) {
                $txt .= sprintf(' | %d Tag(e) mit Sondereffekt ausgeschlossen', $excluded);
            }
            $this->SetValue('LFC_Accuracy', $txt);
            return;
        }
        $bias = array_sum($errs) / count($errs);
        $mape = array_sum(array_map('abs', $errs)) / count($errs);
        $this->SetValue('LFC_ErrorMAPE', round($mape, 1));
        $txt = sprintf('%d Tage: Bias %+.1f %% · |Ø-Fehler| %.1f %%', count($errs), $bias, $mape);
        $res = json_decode((string)$this->ReadAttributeString('LFC_Residuals'), true);
        if (is_array($res) && isset($res['q10'])) {
            $txt .= sprintf(' | Residuen ×%.2f…×%.2f (Median ×%.2f, %d Tage)',
                $res['q10'], $res['q90'], $res['q50'], $res['days']);
        }
        if ($excluded > 0) {
            $txt .= sprintf(' | %d Tag(e) mit Sondereffekt ausgeschlossen', $excluded);
        }
        if ($this->specialEventsVersionMismatch !== null) {
            $txt .= sprintf(' | ⚠️ EMS-Vertrag %s nicht unterstützt (Major %d erwartet) — Sondereffekt-Ausschluss inaktiv, Modul-Update prüfen',
                $this->specialEventsVersionMismatch, self::EMS_EVENTS_MAJOR);
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

        $r = json_decode((string)$this->ReadAttributeString('LFC_Residuals'), true);
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
        $snaps = json_decode((string)$this->ReadAttributeString('LFC_Snapshots'), true);
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
        $state = strtoupper(trim((string)$this->ReadPropertyString('LFC_State')));

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
        $main = $this->dayProfile($this->consumptionVar(), $ts);
        if ($main === null) { return null; }

        foreach ($this->excludeVarIds() as $vid) {
            $sub = $this->dayProfile($vid, $ts);
            if ($sub === null) { continue; }
            for ($s = 0; $s < $slots; $s++) {
                $main[$s] = max(0.0, $main[$s] - $sub[$s]);
            }
        }
        return $main;
    }

    /**
     * Alle abzuziehenden Leistungsvariablen: die manuelle Liste (unverändert, Reihenfolge und
     * Mehrfacheinträge wie eingetragen) plus — nur mit dem Opt-in-Schalter — die automatisch erkannten
     * Wallboxen der NRG-Stack-Hubs, sofern ihre Variable nicht schon in der manuellen Liste steht.
     */
    private function excludeVarIds(): array
    {
        $ids = [];
        foreach ((array)json_decode((string)$this->ReadPropertyString('ExcludeVars'), true) as $row) {
            $vid = (int)($row['VariableID'] ?? 0);
            if ($vid > 0) { $ids[] = $vid; }
        }
        foreach ($this->detectedWallboxes()['use'] as $wb) {
            if (!in_array($wb['powerID'], $ids, true)) { $ids[] = $wb['powerID']; }
        }
        return $ids;
    }

    /**
     * Wallboxen aus den Verträgen der NRG-Stack-Hubs (CHUB_GetFunctions, OHUB_GetFunctions; hinter
     * function_exists — ohne die Hubs bleibt alles wie bisher). Nur mit Opt-in-Schalter.
     * Dieselbe physische Wallbox erscheint bei Nutzung beider Hubs zweimal (gleiche deviceSerial,
     * duplicateOf ist verbundweit bewusst null): je deviceSerial wird GENAU EINE Variable gewählt, sonst
     * würde doppelt abgezogen. Wahl unter den gemessenen, archivierten Kandidaten: erst der aktive Weg
     * (active=false zuletzt), dann die zuletzt aktualisierte Variable. Ohne deviceSerial gilt jede
     * Hub-Instanz/Bezeichnung als eigene Box.
     * Rückgabe: use = [{label, hub, powerID, ageDays}], skipped = [{label, reason}].
     */
    private function detectedWallboxes(): array
    {
        if ($this->wallboxCache !== null) { return $this->wallboxCache; }
        $out = ['use' => [], 'skipped' => []];
        if (!$this->ReadPropertyBoolean('LFC_AutoWallboxes')) { return $this->wallboxCache = $out; }

        $aid = $this->getArchiveID();
        $groups = [];
        foreach ($this->hubChargers() as $f) {
            $pid    = (int)($f['powerID'] ?? 0);
            $serial = trim((string)($f['deviceSerial'] ?? ''));
            $label  = trim((string)($f['label'] ?? '')) !== '' ? trim((string)$f['label']) : ($serial !== '' ? 'Wallbox ' . $serial : 'Wallbox');
            $key    = ($serial !== '') ? 'sn:' . $serial : 'hub:' . $f['_hub'] . ':' . ($f['_instance'] ?? 0) . ':' . $label;
            $exists = ($pid > 0 && IPS_VariableExists($pid));
            $c = [
                'label' => $label, 'hub' => $f['_hub'], 'powerID' => $pid, 'exists' => $exists,
                'measured' => !empty($f['measured']), 'logged' => ($exists && $aid > 0 && $this->isLogged($aid, $pid)),
                'active' => array_key_exists('active', $f) ? ($f['active'] === false ? 0 : 2) : 1, // false=0 < unbekannt=1 < true=2
                'ageDays' => null,
            ];
            if ($exists) {
                $v = IPS_GetVariable($pid);
                $c['ageDays'] = (time() - max((int)$v['VariableUpdated'], (int)$v['VariableChanged'])) / 86400;
            }
            $groups[$key][] = $c;
        }
        foreach ($groups as $cands) {
            $ok = array_values(array_filter($cands, function ($c) { return $c['exists'] && $c['measured'] && $c['logged']; }));
            if (count($ok) === 0) {
                $c = $cands[0];
                $reason = !$c['exists'] ? 'Ladeleistung fehlt' : (!$c['measured'] ? 'Leistung nicht gemessen' : 'Ladeleistung nicht archiviert');
                $out['skipped'][] = ['label' => $c['label'], 'reason' => $reason];
                continue;
            }
            usort($ok, function ($a, $b) {
                if ($a['active'] !== $b['active']) { return $b['active'] <=> $a['active']; }
                return ($a['ageDays'] ?? 1e9) <=> ($b['ageDays'] ?? 1e9);
            });
            $out['use'][] = ['label' => $ok[0]['label'], 'hub' => $ok[0]['hub'], 'powerID' => $ok[0]['powerID'], 'ageDays' => $ok[0]['ageDays']];
        }
        return $this->wallboxCache = $out;
    }

    /**
     * Alle Wallboxen der Hub-Verträge, ein Eintrag je Gerät mit '_hub' und '_instance' ergänzt.
     * protected = Testnaht des Prüfstands. Ohne Hubs (Funktion fehlt) leer, kein Fehler.
     */
    protected function hubChargers(): array
    {
        $out = [];
        foreach (['CHUB' => 'ChargerHub', 'OHUB' => 'OCPPHub'] as $prefix => $hub) {
            $fn = $prefix . '_GetFunctions';
            if (!function_exists($fn)) { continue; }
            foreach (IPS_GetInstanceList() as $iid) {
                $m = IPS_GetModule(IPS_GetInstance($iid)['ModuleInfo']['ModuleID']);
                if (($m['Prefix'] ?? '') !== $prefix) { continue; }
                $funcs = @$fn($iid);
                if (!is_array($funcs)) { continue; }
                foreach ($funcs as $f) {
                    if (is_array($f) && ($f['function'] ?? '') === 'charger') { $f['_hub'] = $hub; $f['_instance'] = $iid; $out[] = $f; }
                }
            }
        }
        return $out;
    }

    /** Status-Zeile zur automatischen Wallbox-Erkennung (leer ohne Opt-in). */
    private function wallboxNotices(): string
    {
        if (!$this->ReadPropertyBoolean('LFC_AutoWallboxes')) { return ''; }
        $w = $this->detectedWallboxes();
        $out = '';
        if (count($w['use']) > 0) {
            $out .= ' | 🔌 Wallboxen automatisch abgezogen: ' . implode(', ', array_map(function ($x) { return $x['label'] . ' (' . $x['hub'] . ')'; }, $w['use']));
            $stale = array_filter($w['use'], function ($x) { return ($x['ageDays'] ?? 0) > 7; });
            foreach ($stale as $x) { $out .= sprintf(' | ⚠️ %s: seit %.1f Tagen ohne Aktualisierung', $x['label'], $x['ageDays']); }
            $manual = array_filter((array)json_decode((string)$this->ReadPropertyString('ExcludeVars'), true), function ($r) { return (int)($r['VariableID'] ?? 0) > 0; });
            if (count($manual) > 0) { $out .= ' | ℹ️ Manuelle Abzugsliste zusätzlich aktiv — enthält sie dieselben Wallboxen, werden sie doppelt abgezogen'; }
        } else {
            $out .= ' | ℹ️ Automatische Wallbox-Erkennung: keine Wallbox mit archivierter Ladeleistung gefunden';
        }
        foreach ($w['skipped'] as $x) { $out .= sprintf(' | ⚠️ %s (%s) — ignoriert', $x['label'], $x['reason']); }
        return $out;
    }

    /**
     * Ø-Leistung (W) je Slot einer Variablen für einen Tag.
     * 60 min: schnell über AC_GetAggregatedValues (Stundenaggregat).
     * <60 min: zeitgewichtet aus den Rohwerten (AC_GetLoggedValues).
     * Erwartet eine LEISTUNGS-Variable (W). Rückgabe null bei
     * unzureichender Datenlage (< halber Tag belegt).
     */
    /**
     * Exklusives Tagesende (nächste lokale Mitternacht) von $start —
     * DST-sicher statt fixer 86400s-Arithmetik: liefert an Umstellungstagen
     * korrekt 23h/25h statt immer 24h. `strtotime('tomorrow', …)` rechnet in
     * Kalendertagen, nicht in Sekunden, und respektiert damit automatisch
     * Zeitumstellungen (PHP-Verhalten, kein manuelles DST-Handling nötig).
     * Fund: Verbundweite DST-Prüfung (Dashboard, 26.08.2026) — die alte
     * `$start + 86400 - 1`-Grenze überlappte am 23h-Tag (März) 1h in den
     * Folgetag hinein bzw. schnitt am 25h-Tag (Oktober) die letzte reale
     * Stunde ab. Betrifft hier direkt die k-NN-Trainingsprofile
     * (dayProfile()/integratedProfile()) — ein Umstellungstag unter den
     * nächsten Nachbarn wäre sonst systematisch leicht verfälscht.
     */
    private function dayEndExclusive(int $start): int
    {
        return strtotime('tomorrow', $start);
    }

    private function dayProfile(int $varID, int $ts)
    {
        if ($varID <= 0 || !IPS_VariableExists($varID)) { return null; }
        $aid = $this->getArchiveID();
        if ($aid === 0 || !$this->isLogged($aid, $varID)) { return null; }

        $slots = $this->slots();
        $start = strtotime('today', $ts);
        $end   = $this->clampEnd($this->dayEndExclusive($start) - 1);

        if ($this->slotMinutes() === 60) {
            $profile = array_fill(0, $slots, null);
            $hits = array_fill(0, $slots, 0);
            $rows = AC_GetAggregatedValues($aid, $varID, 0 /* stündlich */, $start, $end, 0);
            if (!is_array($rows) || count($rows) === 0) { return null; }
            foreach ($rows as $r) {
                $h = (int)date('G', $r['TimeStamp']);
                if ($h < 0 || $h >= $slots) { continue; }
                $v = (float)$r['Avg'];
                // Zeitumstellung Oktober: Wanduhr-Stunde 2 kommt real ZWEIMAL
                // vor — beide Archivzeilen liefern denselben date('G')-Wert.
                // Bisher schrieb die zweite Zeile die erste stillschweigend
                // über (eine reale Stunde ging verloren); jetzt gemittelt.
                if ($hits[$h] > 0) { $profile[$h] = ($profile[$h] * $hits[$h] + $v) / ($hits[$h] + 1); }
                else { $profile[$h] = $v; }
                $hits[$h]++;
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
        // Slots sind WANDUHR-Slots (Slot i = i-te Viertelstunde/Halbstunde nach Wanduhr), unabhängig
        // von der realen Taglänge. Bis Build 119 wurde die reale Taglänge (25 h/23 h an Umstellungstagen)
        // durch $slots geteilt: ein historischer Umstellungstag stand dann als Nachbar in einem
        // "verstrichene Zeit"-Raster (Slot 38 = 09:25 statt 09:30, Abend um bis zu 35 min verschoben) und
        // verfälschte die Prognose um bis zu ca. 6 % je Slot, am 28.03.2027 die Abendspitze um einen Slot
        // (Fund: Prüfstand für EMS-Anfrage, 20.09.2026). Jetzt: jeder Zeitpunkt wird über seine Wanduhrzeit
        // dem Slot zugeordnet. Die doppelte Stunde im Oktober fließt gewichtet in dieselben Slots (Mittel),
        // die fehlende im März bleibt leer und wird von finishProfile() vorwärts gefüllt — wie im 60-min-Pfad.
        $slotSec = 86400 / $slots;

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
                $wall    = (int)date('G', $t0) * 3600 + (int)date('i', $t0) * 60 + (int)date('s', $t0); // Sekunden seit Wanduhr-Mitternacht
                $slot    = (int)floor($wall / $slotSec);
                if ($slot < 0 || $slot >= $slots) { break; }
                // Slotgrenzen liegen an vollen Minuten; Umstellungen an vollen Stunden, also nie innerhalb eines Slots.
                $segEnd  = min($t1, $t0 + (int)ceil(($slot + 1) * $slotSec - $wall));
                $dur     = $segEnd - $t0;
                if ($dur <= 0) { break; }
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
        $end   = $this->clampEnd($this->dayEndExclusive($start) - 1);

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
     * Ereignisse für das Lernmaterial über die ganze Lookback-Tiefe, je Request einmal. Die Tiefe
     * hängt am Bestand des EMS (höchstens 500 Einträge, erst ab dem Zeitpunkt, an dem das EMS sie
     * führt) — ältere Tage sind dann schlicht nicht markiert. Ohne EMS leer, nichts ändert sich.
     */
    private function poolSpecialEvents(int $lookbackDays): array
    {
        if ($this->poolEvents === null) {
            try {
                $this->poolEvents = $this->fetchSpecialEvents($lookbackDays);
            } catch (\Throwable $e) {
                $this->log(LFC_LOG_BASIC, 'EMS_GetSpecialEvents für das Lernmaterial nicht lesbar: ' . $e->getMessage());
                $this->poolEvents = [];
            }
        }
        return $this->poolEvents;
    }

    /**
     * Sondereffekte (§14a, Tibber-Regelenergie, Vermarktung, EMS-Schutz) der
     * letzten $lookbackDays über EMS_GetSpecialEvents (Verbund-Vertrag 1.0)
     * abfragen. Standalone-fähig: ohne EMS bleibt die Liste leer, wirkt sich
     * nirgends aus (kein Fehler, keine Warnung).
     */
    private function fetchSpecialEvents(int $lookbackDays): array
    {
        $this->specialEventsVersionMismatch = null;
        $this->specialEventsContract = null;
        if (!function_exists('EMS_GetSpecialEvents')) { return []; }
        $emsId = $this->emsInstance();
        if ($emsId <= 0) { return []; }
        $from = strtotime('today -' . $lookbackDays . ' days');
        $to   = time();
        $events = @EMS_GetSpecialEvents($emsId, $from, $to);
        if (!is_array($events)) { return []; }

        // EMS liefert einen WRAPPER {contractVersion, events: [...]}, keine
        // flache Event-Liste. Live gefunden (27.08.2026, Dietmars Frage warum
        // die Prognosegüte leer blieb): Der bisherige Code iterierte über den
        // Wrapper selbst — der String "1.0" (contractVersion) wurde dabei in
        // dayHasSpecialEvent() zu einem Pseudo-Event mit from=0/to=0, und
        // to=0 heißt „noch andauernd" → das Pseudo-Event überlappte JEDEN
        // Tag, alle 14 Auswertungstage wurden still als Sondereffekt
        // ausgeschlossen, LFC_Accuracy blieb dauerhaft bei „Noch keine
        // auswertbaren Tage". Version wird jetzt auf Wrapper-Ebene geprüft,
        // zurückgegeben wird die eigentliche Event-Liste; eine flache Liste
        // (falls eine künftige EMS-Version das Format wechselt) bleibt als
        // Rückfall lesbar.
        $verStr = (string)($events['contractVersion'] ?? '1.0');
        $this->specialEventsContract = $verStr;
        $major  = (int)explode('.', $verStr)[0];
        if ($major !== self::EMS_EVENTS_MAJOR) {
            // Update-Meldepflicht (Verbund-Konvention, SUITE.md): volle
            // Kompatibilität nur innerhalb derselben Major — Kopplung
            // deaktivieren statt Felder blind zu deuten, und sichtbar melden.
            $this->specialEventsVersionMismatch = $verStr;
            $this->log(LFC_LOG_BASIC, sprintf(
                'EMS_GetSpecialEvents liefert Vertrag %s, unterstützt wird nur Major %d — Sondereffekt-Ausschluss deaktiviert bis zum Modul-Update.',
                $verStr, self::EMS_EVENTS_MAJOR
            ));
            return [];
        }
        $list = $events['events'] ?? $events;
        return is_array($list) ? $list : [];
    }

    /** Überlappt irgendein Sondereffekt-Fenster den Tag [$dayStart, $dayEnd]? */
    private function dayHasSpecialEvent(array $events, int $dayStart, int $dayEnd, string $affects = ''): bool
    {
        foreach ($events as $e) {
            // Nur echte Event-Objekte mit realem Startzeitpunkt werten —
            // from=0 wäre „seit Anbeginn der Zeit" und würde (mit to=0 =
            // „noch andauernd") jeden Tag treffen; genau so entstand der
            // Alle-Tage-ausgeschlossen-Bug (siehe fetchSpecialEvents()).
            if (!is_array($e)) { continue; }
            $from = (int)($e['from'] ?? 0);
            if ($from <= 0) { continue; }
            // Vertrag 1.1: `affects` nennt, WAS das Ereignis verfälscht ('pv' = Erzeugung abgeregelt,
            // 'load' = Last verfälscht). Ohne Feld (Vertrag 1.0, ältere Einträge) gilt beides.
            if ($affects !== '' && !in_array($affects, $this->eventAffects($e), true)) { continue; }
            $to   = (int)($e['to'] ?? 0);
            $effectiveTo = ($to > 0) ? $to : time(); // 0 = noch andauernd → bis jetzt
            if ($from <= $dayEnd && $effectiveTo >= $dayStart) { return true; }
        }
        return false;
    }

    /** Wirkung eines EMS-Ereignisses: ['pv'], ['load'] oder beides; fehlt/ungültig das Feld → beides. */
    private function eventAffects(array $e): array
    {
        $a = $e['affects'] ?? null;
        if (!is_array($a)) { return ['pv', 'load']; }
        $a = array_values(array_filter($a, 'is_string'));
        return count($a) > 0 ? $a : ['pv', 'load'];
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

    /**
     * Endzeit nie in die Zukunft (verhindert "Aggregation aus der Zukunft").
     * 2 Sekunden Sicherheitsabstand statt exakt time(): bekannte, seltene
     * Symcon-Archiv-Falle bei minimaler Systemuhr-Korrektur (NTP-Sprung
     * knapp nach einem Sekundenwechsel) — ohne Puffer reicht schon 1
     * Sekunde Jitter, um "Aggregation von Datensatz aus der Zukunft
     * fehlgeschlagen" auszulösen (Fund: Beta-Tester somm, 16.09.2026,
     * bestätigtes, ungelöstes Symcon-Core-Verhalten, keine offizielle
     * Lösung dokumentiert — wir können es nur seltener machen, nicht
     * ausschließen).
     */
    private function clampEnd(int $end): int
    {
        return min($end, time() - 2);
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

        $check($this->consumptionVar());
        $check($this->ReadPropertyInteger('VAR_TempHistory'));
        $check($this->ReadPropertyInteger('VAR_Presence'));
        foreach ((array)json_decode((string)$this->ReadPropertyString('ExcludeVars'), true) as $row) {
            $check((int)($row['VariableID'] ?? 0));
        }
        foreach ((array)json_decode((string)$this->ReadPropertyString('WPDevices'), true) as $row) {
            $check((int)($row['PowerVar'] ?? 0));
        }
        $check($this->ReadPropertyInteger('VAR_WP_Power'));

        return array_values(array_unique($missing));
    }

    /**
     * Laufende Plausibilitätskontrolle: läuft bei JEDEM Rebuild() automatisch mit
     * (kein separater Zeitplan nötig — nutzt den ohnehin vorhandenen Intervall-
     * Timer des Moduls). Prüft, ob die für die Prognose genutzten Messwerte
     * überhaupt noch aktuell hereinkommen — anders als unloggedVars() (prüft nur
     * die Konfiguration: "ist Archivierung eingeschaltet") erkennt das auch eine
     * zur Laufzeit ausgefallene Quelle (z. B. abgebrochene Modbus-Verbindung),
     * die weiter als archiviert gilt, aber keine frischen Werte mehr liefert.
     *
     * Zwei Schwellen, bewusst unterschiedlich (Lektion 09.08.2026: eine erste
     * Fassung meldete den Wallbox-Ladewert einer kaum genutzten Wallbox
     * fälschlich als "kaputt", obwohl "seit 3 Wochen konstant 0 kW" bei einem
     * optionalen Verbraucher schlicht "niemand hat geladen" bedeuten kann):
     * Hausverbrauch MUSS in jedem 48h-Fenster echt schwanken (ein Haus hat
     * nie über zwei volle Tage exakt konstante Last) — kurze, strenge Schwelle.
     * Abzugsliste/WP-Geräte sind OPTIONALE Lasten (Wallbox, Wärmepumpe/Klima),
     * die legitim wochenlang inaktiv sein können (kein Ladevorgang, Saison
     * ohne Heizen/Kühlen) — deutlich längere, lockere Schwelle, damit nur eine
     * wirklich verdächtig lange Stille auffällt, nicht der Normalfall.
     */
    private function checkDataPlausibility(): array
    {
        $warnings = [];
        $check = function (int $vid, string $label, int $staleSec) use (&$warnings) {
            if ($vid <= 0 || !IPS_VariableExists($vid)) { return; }
            // Letztes Lebenszeichen = letzte AKTUALISIERUNG, nicht letzte Wertänderung: eine Ladeleistung, die
            // wochenlang regulär gemeldet 0 W ist (Wallbox ungenutzt), ändert ihren Wert nie und wäre sonst
            // fälschlich "seit Monaten ohne Messwert" (Fund 20.09.2026: frische Hub-Variable mit
            // VariableChanged = 01.01.). Umgekehrt zeigte die alte Angabe bei einer wirklich toten Variable
            // die letzte Wertänderung (64 Tage) statt der letzten Aktualisierung (50 Tage).
            $v   = IPS_GetVariable($vid);
            $age = time() - max((int)$v['VariableUpdated'], (int)$v['VariableChanged']);
            if ($age > $staleSec) {
                $warnings[] = sprintf('%s: seit %.1f Tagen ohne neuen Messwert', $label, $age / 86400);
            }
        };

        $check($this->consumptionVar(), 'Hausverbrauch', 48 * 3600);
        foreach ((array)json_decode((string)$this->ReadPropertyString('ExcludeVars'), true) as $row) {
            $vid = (int)($row['VariableID'] ?? 0);
            $check($vid, $vid > 0 && IPS_VariableExists($vid) ? IPS_GetName($vid) : 'Abzugsliste', 30 * 86400);
        }
        foreach ((array)json_decode((string)$this->ReadPropertyString('WPDevices'), true) as $row) {
            $vid = (int)($row['PowerVar'] ?? 0);
            $check($vid, $vid > 0 && IPS_VariableExists($vid) ? IPS_GetName($vid) : 'WP-Gerät', 30 * 86400);
        }

        return $warnings;
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
        // Auf lokale Mitternacht normiert VOR der Division — sonst würde ein
        // $ts mitten am Tag den Tagesoffset über eine Zeitumstellung hinweg
        // (23h/25h-Tag dazwischen) im Grenzfall falsch runden.
        $offset = (int)round((strtotime('today', $ts) - strtotime('today')) / 86400);
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
                    if (array_filter($res, function ($v) { return $v !== null; }) !== []) {
                        return $res;
                    }
                    $this->log(LFC_LOG_VERBOSE, 'OWM gefunden, aber keine Stundenvorhersage (aktiviert?)');
                }
                // Sonst leer lassen → Klimatologie übernimmt in forecastTemp.
                return $this->emptyTempForecast();
        }
    }

    /** [0=>null, 1=>null, ...] über den vollen Horizont — Fallback-Gerüst. */
    private function emptyTempForecast(): array
    {
        $out = [];
        for ($o = 0; $o <= LFC_MAX_OFFSET; $o++) { $out[$o] = null; }
        return $out;
    }

    /** Modus DAILY: direkte Tagesmittel-Variablen über den vollen Horizont. */
    private function forecastFromDailyVars()
    {
        $out = $this->emptyTempForecast();
        $map = [
            0 => 'VAR_TempFc_D0', 1 => 'VAR_TempFc_D1', 2 => 'VAR_TempFc_D2',
            3 => 'VAR_TempFc_D3', 4 => 'VAR_TempFc_D4',
        ];
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
        $patLow  = trim((string)$this->ReadPropertyString('LFC_FcTempIdentLow'));
        $patHigh = trim((string)$this->ReadPropertyString('LFC_FcTempIdentHigh'));
        $patTime = trim((string)$this->ReadPropertyString('LFC_FcTimeIdent'));
        $start   = $this->ReadPropertyInteger('LFC_FcStartIndex');
        $count   = $this->ReadPropertyInteger('LFC_FcCount');

        if ($parent <= 0 || !IPS_ObjectExists($parent) || $patLow === '' || $patTime === '') {
            $this->log(LFC_LOG_VERBOSE, 'Temp-Vorhersage Ident-Modus unvollständig konfiguriert');
            return $this->emptyTempForecast();
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
        $sum = []; $cnt = [];
        for ($o = 0; $o <= LFC_MAX_OFFSET; $o++) { $sum[$o] = 0.0; $cnt[$o] = 0; }

        for ($i = $start; $i < $start + $count; $i++) {
            $tId = $this->identToVar($parent, $patTime, $i);
            $lId = $this->identToVar($parent, $patLow,  $i);
            if ($tId === 0 || $lId === 0) { continue; }

            $slotTs = (int)GetValue($tId);
            if ($slotTs <= 0) { continue; }
            // Auf lokale Mitternacht normiert VOR der Division (DST-sicher,
            // wie forecastTemp()) — sonst könnte der Tagesoffset über eine
            // Zeitumstellung hinweg im Grenzfall falsch abgerundet werden.
            $off = (int)round((strtotime('today', $slotTs) - $today) / 86400);
            if ($off < 0 || $off > LFC_MAX_OFFSET) { continue; } // nur heute..Horizontende

            $val = (float)GetValue($lId);
            if ($patHigh !== '') {
                $hId = $this->identToVar($parent, $patHigh, $i);
                if ($hId !== 0) { $val = ($val + (float)GetValue($hId)) / 2.0; }
            }
            $sum[$off] += $val;
            $cnt[$off]++;
        }

        $out = [];
        for ($o = 0; $o <= LFC_MAX_OFFSET; $o++) {
            $out[$o] = ($cnt[$o] > 0) ? $sum[$o] / $cnt[$o] : null;
        }
        return $out;
    }

    /**
     * OpenWeatherData-Instanz für die Auto-Vorhersage. Wenn explizit gewählt
     * (LFC_OwmInstance) und gültig, diese; sonst die erste gefundene.
     * 0 = keine vorhanden.
     */
    private function owmInstance(?int $selected = null)
    {
        $sel = $selected ?? $this->ReadPropertyInteger('LFC_OwmInstance');
        if ($sel > 0 && IPS_InstanceExists($sel)
            && (IPS_GetInstance($sel)['ModuleInfo']['ModuleID'] ?? '') === LFC_OWM_GUID) {
            return $sel;
        }
        $ids = IPS_GetInstanceListByModuleID(LFC_OWM_GUID);
        return (is_array($ids) && count($ids) > 0) ? $ids[0] : 0;
    }

    /** EMS-Instanz für EMS_GetSpecialEvents. 0 = keine vorhanden. */
    private function emsInstance(): int
    {
        $ids = @IPS_GetInstanceListByModuleID(LFC_EMS_GUID);
        return (is_array($ids) && count($ids) > 0) ? (int)$ids[0] : 0;
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
        return $this->autoPowerUnit($vid)['factor'];
    }

    /**
     * Wie autoPowerFactor(), liefert zusätzlich WOHER die Einheit stammt
     * (für die Formular-Statuszeile): source = profile | magnitude | default,
     * bei „profile" das Suffix, bei „magnitude" das Tagesmaximum (W bzw. kW).
     *
     * @return array{factor:float, source:string, detail:string}
     */
    private function autoPowerUnit(int $vid): array
    {
        $v    = IPS_GetVariable($vid);
        $prof = ($v['VariableCustomProfile'] !== '') ? $v['VariableCustomProfile'] : $v['VariableProfile'];
        if ($prof !== '' && IPS_VariableProfileExists($prof)) {
            $rawSuffix = trim(IPS_GetVariableProfile($prof)['Suffix']);
            $suffix = strtolower($rawSuffix);
            if ($suffix === 'kw') { return ['factor' => 1000.0, 'source' => 'profile', 'detail' => $rawSuffix]; }
            if ($suffix === 'w')  { return ['factor' => 1.0, 'source' => 'profile', 'detail' => $rawSuffix]; }
            if ($suffix === 'mw') { return ['factor' => 1000000.0, 'source' => 'profile', 'detail' => $rawSuffix]; }
        }

        $aid = $this->getArchiveID();
        if ($this->isLogged($aid, $vid)) {
            $rows = @AC_GetAggregatedValues($aid, $vid, 1 /* täglich */, strtotime('-7 days'), $this->clampEnd(time()), 0);
            if (is_array($rows) && count($rows) > 0) {
                $max = 0.0;
                foreach ($rows as $r) { $max = max($max, (float)$r['Max']); }
                if ($max > 0 && $max < 100) { return ['factor' => 1000.0, 'source' => 'magnitude', 'detail' => (string)round($max, 1)]; }
                if ($max >= 100) { return ['factor' => 1.0, 'source' => 'magnitude', 'detail' => (string)round($max)]; }
            }
        }
        return ['factor' => 1.0, 'source' => 'default', 'detail' => ''];
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
        $list = json_decode((string)$this->ReadPropertyString('WPDevices'), true);
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

    /** $generated=0 = Platzhalter ohne echte Daten; ein echt berechnetes Ergebnis "kein Nachbar" bekommt time(). */
    private function emptyForecast(int $ts, int $generated = 0)
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
            'generated' => $generated,
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
