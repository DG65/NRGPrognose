<?php

// ============================================================
//  PVForecast — PV-Erzeugungsprognose für IP-Symcon
//  Autor   : DG65
//  Version : 0.5 (Teil der LastPrognose-Bibliothek)
//  GUID    : {257DD4E8-9705-462E-89FC-56D0A1038353}
//
//  Ansatz: deterministische Physik statt Mustersuche.
//  Pro PV-Generator (Neigung, Azimut, kWp) wird die erwartete
//  Leistung aus einer externen Einstrahlungs-/PV-Vorhersage
//  berechnet. Wählbare Quelle:
//    - Open-Meteo  : geneigte Einstrahlung (GTI) → kWp·GTI/1000·PR
//    - Forecast.Solar: liefert PV-Leistung direkt
//    - Solcast     : liefert PV-Leistung inkl. P10/P90
//  Die Archivdaten dienen der Selbstkalibrierung (gemessen vs.
//  vorhergesagt → Korrekturfaktor je Generator).
// ============================================================

define('PVF_LOG_OFF',     0);
define('PVF_LOG_BASIC',   1);
define('PVF_LOG_VERBOSE', 2);

define('PVF_ARCHIVE_GUID', '{43192F0B-135B-4CE7-A0A7-1475603F3060}');

// Vorhersagequellen
define('PVF_SRC_OPENMETEO',     0);
define('PVF_SRC_FORECASTSOLAR', 1);
define('PVF_SRC_SOLCAST',       2);

// Vertragsversionen (Verbund-Konvention, additiv). Major.Minor; Major nur bei
// Bruch. Getrennt je Vertrags-Familie, damit ein Bruch der einen die Konsumenten
// der anderen nicht fälschlich zur Deaktivierung zwingt.
// Minor-Bump 1.0→1.1 (20.08.2026, additiv, mit EMS abgestimmt): gültiger
// Offset-Bereich für GetForecast()/GetEnergyWindow() erweitert 0..2 → 0..4
// (Horizont 3→5 Tage). Rückgaben für Offset 0-2 unverändert, kein Major-Bruch.
// Minor-Bump 1.1→1.2 (12.09.2026, additiv): neue Funktion GetIntradaySnapshot()
// ergänzt GetSnapshot() um untertägige Prognosestände. GetForecast()/
// GetSnapshot() selbst unverändert, kein Major-Bruch.
define('PVF_CONTRACT_FORECAST',   '1.4'); // GetForecast / GetSnapshot / GetIntradaySnapshot (1.3: +generated; 1.4: +moduleCover, nur GetForecast Offset 0)
define('PVF_CONTRACT_GENERATORS', '1.0'); // GetGenerators / GetModuleAreas
define('PVF_CONTRACT_ENERGYWINDOW', '1.1'); // GetEnergyWindow
define('PVF_CONTRACT_TEMPERATURE', '1.0'); // GetTemperatureForecast (Außentemperatur-Tagesmittel, z. B. für die Lastprognose)
// Neu 13.09.2026 (EMS' netzdienlicher Baustein B1, Mittagsspitze): strukturierte
// Prognosegüte-Kennzahlen statt der bisherigen reinen Textausgabe in PVF_Accuracy.
define('PVF_CONTRACT_ACCURACY', '1.2'); // GetAccuracy (1.1: +curveShape/slotLevelDays/slotLevelLegacyDays; 1.2: +factorBasis/levelCorrectionApplied)

// Horizont: gültige Offsets für GetForecast() sind 0..PVF_MAX_OFFSET (0=heute).
// Von der kostenlosen Open-Meteo-/Forecast.Solar-/Solcast-Anbindung her wären
// deutlich mehr Tage möglich; 4 (= 5 Tage) ist mit EMS/Dashboard abgestimmt.
define('PVF_MAX_OFFSET', 4);

// EMS — für EMS_GetSpecialEvents (Sondereffekt-Ausschluss). GUID stabil über
// alle Installationen; Instanz wird zur Laufzeit gesucht (optional, kein Fehler
// wenn kein EMS installiert ist).
define('PVF_EMS_GUID', '{31C61A7B-28C4-4F97-9651-1A64B3469E3C}');

// FrigateHub-Server — für FRIGS_GetObservations (Beobachtung `snow_pv`: Schnee auf den Modulen).
// Optional wie das EMS: ohne FrigateHub bleibt alles unverändert. Vertrag `observation` 1.x.
define('PVF_FRIGS_GUID', '{114A19E4-4933-4DA4-97DE-AFD2D4AE8D53}');
define('PVF_COVER_MAX_AGE', 7200);        // Beobachtungen, deren letzte Prüfung länger zurückliegt, gelten als unbekannt
define('PVF_COVER_MIN_CONFIDENCE', 0.7);  // darunter wird die Bedeckung gemeldet, aber nicht eingerechnet

class PVPrognose extends IPSModule
{
    // Request-lokales Modell: [offset => 24×{p10,p50,p90} in W]
    private $modelCache = null;
    /** Rohe (unkorrigierte) p50 je Offset aus computeForecast() — nur für saveSnapshot() im selben Rebuild. */
    private $lastRawP50 = [];
    /** Ersatzwerte, die im aktuellen Rebuild benutzt wurden (Kalibrierfaktor aus dem Cache) — für die Status-Zeile. */
    private $calibNotes = [];
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
    // Ereignisse für die Selbstkalibrierung (Kalibrier-Zeitraum), request-lokal — calibrate() läuft je Generator.
    // Im Formular gewählte, noch nicht gespeicherte Werte (Name => Wert), nur während PreviewSelection().
    private $formOverride = [];
    private $calibEvents = null;
    // Ursachen fehlgeschlagener/unvollständiger Solcast-Abrufe im laufenden Rebuild (kind, gen, rid, http) — damit
    // die Status-Zeile statt "API/Netzwerk?" die tatsächliche Ursache nennt (Forum hbraun, 12.10.2026).
    private $solcastIssues = [];

    // Residuen-Korrektur ("Immer genauer werden"): Tagesgang-Profil statt
    // einem einzigen globalen Faktor (Fund EMS-Sitzung 12.09.2026: Morgens
    // wird überschätzt, abends unterschätzt, bei stimmender Mittagsspitze —
    // ein globaler Faktor mittelt das gegenseitig weg). Buckets liegen auf
    // der TAGESANTEIL-Achse (0=Sonnenaufgang, 1=Sonnenuntergang je Tag),
    // nicht auf der Uhrzeit — eine fixe Horizont-/Bebauungsverschattung
    // hängt am Sonnenstand, der sich mit der Jahreszeit auf der Uhrzeit-
    // Achse verschiebt, auf der Tagesanteil-Achse aber stabil bleibt.
    private const PVF_RESIDUAL_BUCKETS = 8;
    private const PVF_RESIDUAL_MIN_PER_BUCKET = 20;
    // Kurvenform der stündlich→feiner hochgerechneten Prognose: 1 = Stundenwert auf den
    // Stundenbeginn gelegt (bis Build 114), 2 = Intervallmittel auf die Stundenmitte (ab Build 115,
    // nur Open-Meteo). Snapshots und Residuen tragen die Kennung, damit Lernwerte aus einer
    // anderen Kurvenform nicht auf die heutige angewendet werden.
    private const PVF_CURVE_SHAPE_MEANS = 2;
    // Pegel-Korrektur (q50) je Bucket begrenzen; Band-Ränder (q10/q90) dürfen weiter (clampFactor).
    // Plausibilitätsdeckel je Slot: installierte Modulleistung x 1000 W x 1,1 (kalter, klarer Himmel mit Reflexion
    // kann die DC-Leistung kurz über die STC-Nennleistung heben, mit exakt 1,0 würde die reale Spitze abgeschnitten).
    private const PVF_PEAK_CAP_FACTOR = 1.1;
    private const PVF_LEVEL_MIN = 0.5;
    private const PVF_LEVEL_MAX = 2.0;
    // Wechselwetter: Interquartilsverhältnis (p75/p25) der Slot-Verhältnisse EINES Tages darüber →
    // Tag zählt nicht für den Pegel (q50), nur fürs Band. Live-Daten Dietmar 20.09.2026: normale
    // Tage 1,2-2,4, der eine extreme (3,9 kWh, sehr dunkel) 4,2.
    private const PVF_RESIDUAL_MAX_DAY_SPREAD = 3.0;

    // Untertägige Zusatz-Snapshots (Day-Ahead-Snapshot in PVF_Snapshots
    // bleibt unberührt) — feste Tageszeiten, zu denen je Tag EINMAL der
    // dann aktuelle Prognosestand für "heute" gesichert wird, sobald ein
    // Rebuild nach diesem Zeitpunkt läuft. Grundlage für eine spätere
    // Day-Ahead-vs-Intraday-Auswertung, s. saveIntradaySnapshot().
    private const PVF_INTRADAY_CHECKPOINTS = ['06:00', '10:00'];

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
    private const NEWS_VERSION = '0.24 (Build 167)';
    private const NEWS_ITEMS = [
        '❄️ New: snow on the modules (needs the module FrigateHub). If FrigateHub reports the modules as covered, today’s forecast can now take it into account: switch “Take snow on the modules into account” in the general settings, off by default. The rest of today is then set to 0 until the modules are reported free again; later days stay unchanged. The state is always passed on as the new field “moduleCover” of PVF_GetForecast (contract 1.4); the stored forecast and the learning stay untouched.',
        '🌍 New: the module is now bilingual (German/English): form, status lines, messages and notices follow the Symcon language. Variables that already exist keep their names; newly created variables are named in the current language.',
        '🪟 New: choose the form layout yourself: in the new “Form: layout” panel (at the bottom, above the forum notice) you set for each section whether it is expanded, collapsed or hidden when the form opens, and at which position it appears. Symcon does not remember expanding and collapsing by itself, so your choice applies every time the form opens. Hidden sections keep working, only their display is missing; “Reset to default” puts everything back.',
        '➖ Generation reported as negative? (question from the forum, thanks!): some inverters and meters count the generation as negative (e.g. −3000 W at 3 kW) and report a small positive standby draw at night. For this, the generator list has the new column “Invert sign”: when switched on, the power is read as yield and a positive value counts as 0. The line “Sign” under “Self-calibration” tells you whether your values of the last 7 days match. Without inverting, the self-calibration would learn a negative generation as a negative yield.',
        '🕒 Solcast line shows when it continues: in addition to today’s calls, the form line now shows the time of the last call and the earliest possible next call (question from the forum, thanks!).',
        '🧮 Solcast works out by itself how often it may be called: the daily budget and the number of different resource IDs give the number of possible call rounds per day, which the module spreads evenly over the daylight. If your set interval is shorter than the account allows, it is stretched automatically - the budget is then no longer used up by the morning. The form line shows the rounds and the actual spacing.',
        '☀️ Solcast spares the daily quota (suggestion from the forum, thanks!): calls now only run in daylight (sunrise to sunset from your coordinates, adjustable offset) and at most as often per day as the daily budget allows (default 10, free account). At night and once the budget is reached, the last valid response applies. A line in the form shows today’s calls and whether the interval and the number of resource IDs fit the budget. Open-Meteo and Forecast.Solar are not affected.',
        '❓ Solcast ID explained: next to the key field there is the button “Where do I find the Solcast ID?” (question from the forum, thanks!) - it explains that the resource ID of the rooftop site is meant (not the API key) and where to find it at Solcast.',
        '🔎 Solcast: clear cause of error instead of “API/network?” - the status line now says whether the key is missing or was rejected, whether the resource ID (column “Solcast ID”) is missing or unknown for a generator, whether the daily quota is used up or there is no connection. If the resource ID is missing, this also shows in the form above the key field.',
        '🔑 Solcast key visible: after saving, the input field stays empty for security reasons, which looked as if nothing had been saved (note from the forum, thanks!). A line above now shows whether a key is stored (with the number of characters, without the key itself).',
        '🩹 More robust against faulty archive data: a single broken hourly aggregate in the archive (seen live on three variables) made the self-calibration show a PHP warning in the system log for the affected day, although the day was skipped cleanly anyway. The warning is now suppressed, the result is unchanged.',
        '🔗 Form: what is detected automatically is no longer shown as an empty input field. The unit (W/kW) of the measured power shows for each generator where it comes from (“🔗 taken over automatically”); the field for overriding it sits collapsed under “Set unit yourself” and only opens where the automatic detection is unsure. Your own entry (✏️) takes precedence, and the line follows the selection immediately.',
        '🧹 Curtailed days stay out of the self-calibration: with an NRG-Stack EMS, days on which the generation was curtailed (grid operator dimming, negative exchange price) are no longer used for the calibration factor — otherwise the throttled measurement would be learned as “model too high” and the forecast would come out too low. Events that only affect the load (Grid Rewards, boost) do not count here. Without an EMS nothing changes.',
        '🔎 New in the form: status lines show live what was detected automatically — the archive and archiving of the measured power, the unit per power variable (with source: profile suffix or order of magnitude) and the EMS link. If it says ⚠️ or ⛔ there, the line tells you what to do.',
        '📈 Forecast correction fixed: the “getting more accurate” correction (level) used to compensate a constant error only by about half — it learned against the already corrected forecast instead of the raw one. Now the error is compensated in full. Depending on your previous error, the PV forecast may therefore come out noticeably higher or lower (with a previously too low forecast, on average around a tenth higher or more). The correction learns anew for a few days.',
        '🛟 More robust during the transition and with network problems: while the correction learns anew, the uncertainty band (P10/P90) is kept, and if the calibration query fails, the last good calibration factor applies (up to 3 days) instead of silently 1.0.',
        'Plausibility limit: no forecast value (P10/P50/P90) lies above the installed module power any more (with a 10 % reserve for short peaks) — otherwise the uncertainty band could lie far above it at noon.',
        'Also new: the correction factor per part of the day is limited to 0.5 to 2.0, and distinctly changeable-weather days (strongly fluctuating ratio of actual to forecast) do not count for the level.',
        '☀️ PV curve corrected in time (source Open-Meteo): the 15/30-minute curve used to be about 30 minutes too early because the hourly mean was placed at the start of the hour instead of the middle of the hour. The values therefore shift by about 30 minutes later (morning later, evening later), the daily energy stays the same.',
        'Bias and error rate of the forecast accuracy carry on unchanged; the time-of-day correction switches back on step by step with the new days.',
        'Daylight saving time change (25 Oct / 28 Mar): weather times are now read unambiguously, energy windows for the EMS count by wall clock. More robust during network outages and at the change of day: if a weather call partly fails, the last valid forecast is kept, and the stored days are shifted on at midnight.',
    ];

    // ----------------------------------------------------------------
    //  Lebenszyklus
    // ----------------------------------------------------------------

    /** Gemerkte Übersetzungen: Symcons Translate() liest bei jedem Aufruf die locale.json (SUITE.md Stolperstein 24). */
    private static $textMerkliste = [];

    /**
     * Text in der Sprache der Konsole. Die Quelltexte im Code sind Englisch, die deutsche Fassung steht in
     * locale.json. Jeder Text fragt Symcon höchstens einmal je Prozess; mit Argumenten wie sprintf (%s, %d, %%).
     * Formulartexte (caption/label) übersetzt die Konsole selbst (Store-Review, SUITE.md Punkt 25) — t() ist für
     * Texte mit eingesetzten Werten, Meldungen, Variablennamen und Statuszeilen.
     */
    private function t(string $text, ...$args): string
    {
        // Merkliste je Sprache (Schlüssel: Option Locale), damit nie ein Text in der Sprache eines früheren Aufrufs hängen bleibt.
        // Hinweis: Translate() folgt einem Sprachwechsel im laufenden Betrieb vermutlich nicht (Live-Test 08.11.2026), er wirkt erst nach Neustart.
        $lang = function_exists('IPS_GetOption') ? (string)@IPS_GetOption('Locale') : '';
        $key = $lang . "\0" . $text;
        if (!isset(self::$textMerkliste[$key])) {
            try {
                self::$textMerkliste[$key] = (string)$this->Translate($text);
            } catch (\Throwable $e) {
                return $args === [] ? $text : (string)@vsprintf($text, $args);
            }
        }
        $out = self::$textMerkliste[$key];
        if ($args === []) { return $out; }
        $args = array_map(function ($a) { return (is_scalar($a) || $a === null) ? $a : (string)json_encode($a); }, $args);
        $r = @vsprintf($out, $args);
        return $r === false ? $out : $r;
    }

    public function Create()
    {
        parent::Create();
        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, false);
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);

        $this->RegisterPropertyBoolean('PVF_Active',        false);
        $this->RegisterPropertyInteger('PVF_IntervalHours', 6);
        $this->RegisterPropertyInteger('PVF_Log_Level',     PVF_LOG_BASIC);

        // Vorhersagequelle
        $this->RegisterPropertyInteger('PVF_Source',        PVF_SRC_OPENMETEO);
        $this->RegisterPropertyFloat(  'PVF_Latitude',      49.0);
        $this->RegisterPropertyFloat(  'PVF_Longitude',     9.0);
        $this->RegisterPropertyFloat(  'PVF_PR',            0.85);   // Performance-Ratio (Open-Meteo)
        $this->RegisterPropertyFloat(  'PVF_TempCoeff',     -0.40);  // %/K (Open-Meteo, 0 = aus)
        $this->RegisterPropertyString( 'PVF_SolcastKey',    '');
        // Solcast-Kontingent (Forum lungo, 13.10.2026): Abrufe nur bei Tageslicht (Sonnenaufgang/-untergang aus
        // Breiten-/Längengrad, mit Versatz) und höchstens N echte Abrufe pro Tag. Nachts und bei erreichtem
        // Tagesbudget gilt die letzte gültige Antwort aus dem Zwischenspeicher.
        $this->RegisterPropertyBoolean('PVF_SolcastDaylightOnly', true);
        $this->RegisterPropertyInteger('PVF_SolcastOffsetMin',    30);
        $this->RegisterPropertyInteger('PVF_SolcastDailyBudget',  10);

        // PV-Generatoren: je Dachfläche/MPP-Tracker.
        // { Name, Tilt, Azimuth(-180..180,0=Süd), kWp, PowerVar, SolcastId, Factor }
        $this->RegisterPropertyString('PVGenerators',       '[]');

        // Selbstkalibrierung (Open-Meteo): gemessen vs. vorhergesagt.
        $this->RegisterPropertyBoolean('PVF_Calibrate',     false);
        // Schneebedeckung (FrigateHub `snow_pv`) in die heutige Prognose einrechnen. Standard AUS: ohne
        // Zustimmung ändert sich nie ein Prognosewert; das Feld `moduleCover` wird trotzdem gemeldet.
        $this->RegisterPropertyBoolean('PVF_SnowAdjust',    false);
        $this->RegisterPropertyInteger('PVF_CalibDays',     21);
        // Einheit der gemessenen Generator-Leistung: 0=W, 1=kW, 2=automatisch.
        $this->RegisterPropertyInteger('PVF_PowerUnit',     2);

        // Zeitliche Auflösung (60/30/15 min). Quellen liefern stündlich;
        // feinere Stufen werden interpoliert (zur Deckung mit der Lastprognose).
        $this->RegisterPropertyInteger('PVF_Resolution',    60);

        // Ausgabe
        $this->ensureNrgPercentProfile();
        $this->RegisterVariableString('PVF_Today',     $this->t('PV forecast today (JSON)'),      '', 10);
        $this->RegisterVariableString('PVF_Tomorrow',  $this->t('PV forecast tomorrow (JSON)'),     '', 20);
        $this->RegisterVariableString('PVF_DayAfter',  $this->t('PV forecast day after tomorrow (JSON)'), '', 30);
        $this->RegisterVariableFloat( 'PVF_kWhToday',    $this->t('Expected PV today (kWh)'),     '~Electricity', 40);
        $this->RegisterVariableFloat( 'PVF_kWhTomorrow', $this->t('Expected PV tomorrow (kWh)'),    '~Electricity', 50);
        $this->RegisterVariableFloat( 'PVF_kWhDayAfter', $this->t('Expected PV day after tomorrow (kWh)'),'~Electricity', 60);
        // Horizont-Erweiterung 3→5 Tage (20.08.2026): neue Idents für Tag 3/4,
        // bestehende Today/Tomorrow/DayAfter bewusst unverändert (Archivhistorie).
        $this->RegisterVariableString('PVF_Day3',      $this->t('PV forecast in 3 days (JSON)'), '', 65);
        $this->RegisterVariableString('PVF_Day4',      $this->t('PV forecast in 4 days (JSON)'), '', 68);
        $this->RegisterVariableFloat( 'PVF_kWhDay3',     $this->t('Expected PV in 3 days (kWh)'), '~Electricity', 70);
        $this->RegisterVariableFloat( 'PVF_kWhDay4',     $this->t('Expected PV in 4 days (kWh)'), '~Electricity', 75);
        $this->RegisterVariableString('PVF_Status',    'Status',                    '', 70);
        $this->RegisterVariableInteger('PVF_LastUpdate',$this->t('Last calculation'),        '~UnixTimestamp', 80);
        $this->RegisterVariableFloat( 'PVF_ErrorMAPE', $this->t('Forecast error |Ø| (%)'),    'NRG.Percent', 82);
        $this->RegisterVariableString('PVF_Accuracy',  $this->t('Forecast accuracy (planned vs. actual)'),'', 84);
        // Gesamte Modulfläche (m²) aus der Generatorliste — z.B. für das Modul InverterHub.
        $this->RegisterVariableFloat( 'PVF_ModuleArea', $this->t('Total module area (m²)'),   '', 86);

        // Tages-Snapshots der Prognose (für spätere Soll-vs-Ist-Kontrolle je Tag)
        $this->RegisterAttributeString('PVF_Snapshots', '');
        // Zusätzliche untertägige Prognose-Stände (Day-Ahead-Snapshot bleibt
        // in PVF_Snapshots unberührt) — Grundlage für eine spätere
        // Day-Ahead-vs-Intraday-Auswertung (Fund EMS-Sitzung, 12.09.2026,
        // mit Dietmar priorisiert), s. saveIntradaySnapshot().
        $this->RegisterAttributeString('PVF_IntradaySnapshots', '');
        // Empirische Quantile der Prognosefehler (Ist/Soll) für Band/Korrektur.
        $this->RegisterAttributeString('PVF_Residuals', '');
        // Strukturierte Prognosegüte-Kennzahlen für externe Abfrage (EMS'
        // netzdienlicher Baustein B1, 13.09.2026) — GetAccuracy() liefert
        // daraus zusammen mit PVF_Residuals den Vertrag PVF_CONTRACT_ACCURACY.
        $this->RegisterAttributeString('PVF_AccuracyDetail', '');
        // 0 = aus (Band der Quelle), 1 = Band aus Residuen,
        // 2 = Band + Pegelkorrektur aus Residuen.
        $this->RegisterPropertyInteger('PVF_ResidualMode', 0);

        $this->RegisterTimer('PVF_RebuildTimer', 0, 'PVF_Rebuild($_IPS[\'TARGET\']);');
        // Tageswechsel: gespeicherte Tage um Mitternacht verschieben (siehe RollDay()).
        $this->RegisterTimer('PVF_DayRollTimer', 0, 'PVF_RollDay($_IPS[\'TARGET\']);');

        // Solcast-API-Schlüssel: das Formularfeld (Property, PasswordTextBox)
        // dient nur der Eingabe. Der wirksame Wert liegt in einem Attribut
        // (nicht im Formular sichtbar, nicht in Exporten). Ein kurzer
        // Einmal-Timer räumt das Formularfeld unmittelbar nach dem Speichern
        // leer — asynchron, damit kein rekursiver ApplyChanges()-Aufruf
        // innerhalb des laufenden ApplyChanges() nötig ist.
        $this->RegisterPropertyString('FormLayout', '[]');
        $this->RegisterAttributeString('PVF_SolcastSecret', '');
        $this->RegisterTimer('PVF_ClearSolcastKeyTimer', 0, 'PVF_ClearSolcastKey($_IPS[\'TARGET\']);');

        // Solcast-Antwort-Cache je Resource-ID + Abkühlphase nach HTTP 429
        // (Forum-Meldung cbeham, 30.08.2026): Das Solcast-Gratiskonto erlaubt
        // nur ~10 API-Abrufe pro TAG, und jede Neuberechnung kostete bisher
        // einen Live-Abruf JE GENERATOR — beim Einrichten/Testen (mehrfach
        // „Prognose jetzt neu berechnen") war das Kontingent binnen Minuten
        // erschöpft, danach kam dauerhaft 429 und wir lieferten eine
        // Null-Prognose statt der letzten gültigen. Cache-Format:
        // { rid: {ts, byDate} }, Abkühlphase als Unix-Zeitstempel.
        $this->RegisterAttributeString('PVF_SolcastCache', '');
        $this->RegisterAttributeInteger('PVF_SolcastCooldownUntil', 0);
        // Eigene Abrufe des laufenden Tages: {date: 'Y-m-d', n: Anzahl} (Tagesbudget)
        $this->RegisterAttributeString('PVF_SolcastCalls', '');

        // Abruf-Pause nach fehlgeschlagenem Modellaufbau (alle Quellen): Bis dahin
        // löst GetForecast() keinen weiteren Live-Abruf aus (siehe computeForecast()).
        $this->RegisterAttributeInteger('PVF_ModelFailUntil', 0);

        // Übergangsband: relatives Unsicherheitsband (P10/P90 zu P50, ohne Pegel) aus den Tagen
        // VOR der Kurvenform-/Lernumstellung (Build 115/116), solange die Residuen neu lernen.
        $this->RegisterAttributeString('PVF_TransitionBand', '');
        // Letzter guter Kalibrierfaktor je Generator (PowerVar-ID → {f, ts}), Rückfall bei Abruf-Ausfall.
        $this->RegisterAttributeString('PVF_CalibCache', '');
        // Außentemperatur-Vorhersage (Open-Meteo) für GetTemperatureForecast: {ts, lat, lon, days:{Y-m-d:[mean,min,max]}}.
        $this->RegisterAttributeString('PVF_TempFc', '');
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
            if (($el['type'] ?? '') === 'ExpansionPanel' && ($el['name'] ?? '') === 'PanelDokumentation') {
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
        // Einheit kommt automatisch (🔗): Eingabefeld nicht leer stehen lassen (SUITE.md „Eingabefeld
        // ersetzen"). Die Erkennung über Größenordnung kann bei großen kW-Anlagen irren, deshalb bleibt
        // ein bewusstes Überschreiben möglich — das Feld liegt in einem eingeklappten Panel „Einheit selbst
        // festlegen"; nur wo die Automatik nichts Sicheres liefert (⚠️) oder eine eigene Angabe gilt (✏️),
        // ist es aufgeklappt. Der automatische Wert wird nie ins Feld geschrieben.
        $unitOpen = (strpos($lines['ConnStatusUnit'], '⚠️') === 0 || strpos($lines['ConnStatusUnit'], '✏️') === 0);
        $this->patchElementByName($form['elements'], 'UnitOverridePanel', ['expanded' => $unitOpen]);

        // Fachpanels nach der Einstellung „Formular: Aufbau“ ordnen, auf-/zuklappen oder ausblenden
        // (SUITE.md 3a). Oben stehen Wozu, Neu und Dokumentation fest, unten Aufbau, Forum und Lizenz.
        $free   = $this->takeFachPanels($form['elements']);
        $layout = $this->formLayout(array_keys($free));
        foreach ($layout as $key => $l) {
            $panel = $free[$key];
            if ($l['open'] === 'open') {
                $panel['expanded'] = true;
            } elseif ($l['open'] === 'closed') {
                $panel['expanded'] = false;
            } elseif ($l['open'] === 'hidden') {
                // Nicht weglassen: die Felder bleiben im Formular, gespeicherte Werte bleiben beim „Übernehmen“ erhalten
                $panel['visible'] = false;
            }
            $form['elements'][] = $panel;
        }
        $form['elements'][] = $this->LayoutPanel($layout, $free);

        if (self::FORUM_THREAD_URL !== '' && !$this->ReadAttributeBoolean(self::ATTR_REVIEW_HINT_GONE)) {
            $form['elements'][] = [
                'type' => 'RowLayout',
                'name' => 'ForumHint',
                'items' => [
                    ['type' => 'Label', 'caption' => '💬 Feedback and test reports are welcome in the Symcon forum thread:'],
                    ['type' => 'Label', 'link' => true, 'caption' => self::FORUM_THREAD_URL],
                    ['type' => 'Button', 'caption' => 'Do not show again', 'onClick' => 'PVF_DismissForumHint($id);'],
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

    /** Fachpanels des Formulars: Schlüssel => Name des ExpansionPanels in form.json, in Standardreihenfolge. */
    private const FACH_PANELS = ['allgemein' => 'PanelAllgemein', 'quelle' => 'PanelQuelle', 'generatoren' => 'PanelGeneratoren', 'kalibrierung' => 'PanelKalibrierung'];

    /**
     * Reihenfolge und Aufklapp-Zustand der Fachpanels aus der Einstellung „FormLayout“ (SUITE.md
     * „Formular: Aufbau“). Unbekannte oder fehlende Einträge fallen auf die Standardreihenfolge
     * und „Standard“ zurück.
     *
     * @param string[] $keys Schlüssel der Fachpanels in Standardreihenfolge
     * @return array key => ['open' => 'std'|'open'|'closed'|'hidden', 'pos' => int, 'idx' => int], in anzuwendender Reihenfolge
     */
    private function formLayout(array $keys): array
    {
        $saved = json_decode((string)$this->ReadPropertyString('FormLayout'), true);
        $by = [];
        foreach (is_array($saved) ? $saved : [] as $row) {
            if (is_array($row) && isset($row['Key'])) {
                $by[(string)$row['Key']] = $row;
            }
        }
        $out = [];
        foreach ($keys as $i => $k) {
            $r = $by[$k] ?? [];
            // Kontrollkästchen; Vorrang bei Widerspruch: ausgeblendet vor zugeklappt vor aufgeklappt, keines = Standard
            $open = !empty($r['Hidden']) ? 'hidden' : (!empty($r['Closed']) ? 'closed' : (!empty($r['Opened']) ? 'open' : 'std'));
            $out[$k] = [
                'open' => $open,
                'pos'  => isset($r['Pos']) ? max(1, min(99, (int)$r['Pos'])) : $i + 1,
                'idx'  => $i,
            ];
        }
        uasort($out, function ($a, $b) { return [$a['pos'], $a['idx']] <=> [$b['pos'], $b['idx']]; });
        return $out;
    }

    /** Nimmt die Fachpanels aus den Formularelementen heraus: key => Panel; übrige Elemente bleiben stehen. */
    private function takeFachPanels(array &$elements): array
    {
        $free = [];
        $rest = [];
        $byName = array_flip(self::FACH_PANELS);
        foreach ($elements as $el) {
            $n = (string)($el['name'] ?? '');
            if (($el['type'] ?? '') === 'ExpansionPanel' && isset($byName[$n])) {
                $free[$byName[$n]] = $el;
            } else {
                $rest[] = $el;
            }
        }
        $elements = $rest;
        // Standardreihenfolge der Schlüssel herstellen
        $sorted = [];
        foreach (array_keys(self::FACH_PANELS) as $k) {
            if (isset($free[$k])) { $sorted[$k] = $free[$k]; }
        }
        return $sorted;
    }

    /** Wählbarer Aufbau des Formulars: je Fachpanel Aufklapp-Zustand beim Öffnen und Position. */
    private function LayoutPanel(array $layout, array $free): array
    {
        $rows   = [];
        $hidden = [];
        foreach ($layout as $key => $l) {
            $name = trim((string)preg_replace('/\s*\(.*$/u', '', $this->t((string)($free[$key]['caption'] ?? $key))));
            $rows[] = ['Key' => $key, 'Panel' => $name, 'Opened' => $l['open'] === 'open', 'Closed' => $l['open'] === 'closed',
                'Hidden' => $l['open'] === 'hidden', 'Pos' => $l['pos']];
            if ($l['open'] === 'hidden') {
                $hidden[] = trim((string)preg_replace('/^[^\p{L}\p{N}]+/u', '', $name));
            }
        }
        $hiddenLine = $hidden
            ? [['type' => 'Label', 'caption' => $this->t('🙈 Hidden: %s — to show again, remove the tick under “Hidden”. Hidden sections keep working, only the display is missing.', implode(', ', $hidden))]]
            : [];
        return [
            'type' => 'ExpansionPanel', 'name' => 'LayoutPanel', 'expanded' => false,
            'caption' => $this->t('🪟 Form: layout%s', $hidden ? $this->t(' (%d hidden)', count($hidden)) : ''),
            'items' => array_merge($hiddenLine, [
                ['type' => 'Label', 'caption' => 'Here you set which sections are expanded when the form opens and in which order they appear. Symcon does not remember expanding and collapsing by itself, so the setting here applies every time the form opens. It takes effect after “Apply” the next time the form is opened.'],
                ['type' => 'Label', 'caption' => 'No tick means “Default”: as the module intends it (e.g. “General” expanded). If you choose “Collapsed”, you will not see anything new there right away either. The position is a number; with equal numbers the default order is kept. “Hidden” removes a section from the display without losing anything: its settings are kept and keep working (meant for sections you rarely need). To show it again, remove the tick under “Hidden” or click “Reset to default”. If several ticks are set, “Hidden” beats “Collapsed” beats “Expanded”. The sections at the top (purpose, news, documentation) and at the bottom (feedback, about this module) stay where they are and cannot be hidden.'],
                [
                    'type' => 'List', 'name' => 'FormLayout', 'caption' => 'Sections', 'rowCount' => count($rows), 'add' => false, 'delete' => false,
                    'loadValuesFromConfiguration' => false,
                    'values' => $rows,
                    'columns' => [
                        ['caption' => 'Section', 'name' => 'Panel', 'width' => 'auto', 'add' => ''],
                        ['caption' => 'Key', 'name' => 'Key', 'width' => '0px', 'visible' => false, 'add' => '', 'save' => true],
                        ['caption' => 'Expanded', 'name' => 'Opened', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Collapsed', 'name' => 'Closed', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => '🙈 Hidden', 'name' => 'Hidden', 'width' => '130px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Position', 'name' => 'Pos', 'width' => '100px', 'add' => 1, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 99]],
                    ],
                ],
                ['type' => 'Button', 'caption' => '↩️ Reset to default', 'onClick' => 'echo PVF_ResetFormLayout($id);'],
            ]),
        ];
    }

    /** Setzt Aufklapp-Zustand und Reihenfolge der Fachpanels im offenen Formular auf den Standard (gilt nach „Übernehmen“). */
    public function ResetFormLayout(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $free = $this->takeFachPanels($form['elements']);
        $rows = [];
        $i = 0;
        foreach (array_keys(self::FACH_PANELS) as $k) {
            $name = trim((string)preg_replace('/\s*\(.*$/u', '', $this->t((string)($free[$k]['caption'] ?? $k))));
            $rows[] = ['Key' => $k, 'Panel' => $name, 'Opened' => false, 'Closed' => false, 'Hidden' => false, 'Pos' => ++$i];
        }
        $this->UpdateFormField('FormLayout', 'values', json_encode($rows, JSON_UNESCAPED_UNICODE));
        return $this->t('ℹ️ Reset to default. Click “Apply” below to save.');
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

    /** onChange der Einheit: Statuszeile live nachziehen (SUITE.md „Zeile folgt der Auswahl"). */
    public function PreviewSelection(string $prop, int $value): void
    {
        if ($prop !== 'PVF_PowerUnit') { return; }
        $this->formOverride[$prop] = $value;
        $lines = $this->connectionStatusLines();
        $this->UpdateFormField('ConnStatusUnit', 'caption', $lines['ConnStatusUnit']);
        $this->UpdateFormField('ConnStatusUnit', 'color', $this->lineColor($lines['ConnStatusUnit']));
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
            'ConnStatusArchive' => 'archiveStatusLine',
            'ConnStatusUnit'    => 'unitStatusLine',
            'ConnStatusSign'    => 'signStatusLine',
            'ConnStatusSolcast' => 'solcastStatusLine',
            'ConnStatusSolcastBudget' => 'solcastBudgetStatusLine',
            'ConnStatusEms'     => 'emsStatusLine',
            'ConnStatusSnow'    => 'snowStatusLine',
        ];
        $out = [];
        foreach ($builders as $name => $method) {
            try {
                $out[$name] = $this->$method();
            } catch (\Throwable $e) {
                $out[$name] = $this->t('⚠️ Could not determine status: %s', $e->getMessage());
            }
        }
        return $out;
    }

    /** Gemessene Leistungsvariablen (PowerVar) der Generatoren: [ID => Generatorname]. */
    private function powerVarsByGenerator(): array
    {
        $out = [];
        foreach ($this->pvGenerators() as $g) {
            if ($g['powervar'] > 0 && !isset($out[$g['powervar']])) {
                $out[$g['powervar']] = ($g['name'] !== '') ? $g['name'] : $this->t('Generator');
            }
        }
        return $out;
    }

    /** Archiv-Control und Archivierung der gemessenen Leistung (für Kalibrierung und Prognosegüte). */
    private function archiveStatusLine(): string
    {
        $vars = $this->powerVarsByGenerator();
        $aid = $this->archiveID();
        if (count($vars) === 0) {
            return $this->t('ℹ️ Archive: no measured power (PowerVar) entered for the generators – self-calibration and forecast accuracy are off, the forecast itself runs without them.');
        }
        if ($aid <= 0) {
            return $this->t('⚠️ Archive: no Archive Control instance found – self-calibration and forecast accuracy cannot read the measured power.');
        }
        $head = $this->t('Archive: connected to “%s” (#%d, detected automatically)', IPS_GetName($aid), $aid);
        $missing = [];
        foreach ($vars as $vid => $name) {
            if (!IPS_VariableExists($vid)) { $missing[] = $this->t('%s (#%d does not exist)', $name, $vid); }
            elseif (!$this->isLogged($aid, $vid)) { $missing[] = $this->t('%s (#%d not archived)', $name, $vid); }
        }
        if (count($missing) > 0) {
            return '⚠️ ' . $this->t('%s, but without usable measured values for: %s – enable archiving there, otherwise this generator is not calibrated.', $head, implode(', ', $missing));
        }
        return '✅ ' . $this->t('%s. Taken over: measured power of %d generator(s) for self-calibration and forecast accuracy.', $head, count($vars));
    }

    /** Einheit (W/kW) je gemessener Leistungsvariable, mit Quelle der Erkennung. */
    private function unitStatusLine(): string
    {
        $mode = $this->selectedInt('PVF_PowerUnit');
        if ($mode === 0 || $mode === 1) {
            return $this->t('✏️ Unit: fixed to %s (own setting, overrides the automatic detection) – applies to all measured power variables.', $mode === 1 ? 'kW' : 'W');
        }
        $vars = $this->powerVarsByGenerator();
        if (count($vars) === 0) {
            return $this->t('ℹ️ Unit: no measured power (PowerVar) selected yet – the unit (W/kW) is detected automatically per variable as soon as one is selected.');
        }
        $lines = [];
        $unsure = false;
        foreach ($vars as $vid => $gen) {
            $name = $this->t('%s – “%s” (#%d)', $gen, IPS_VariableExists($vid) ? IPS_GetName($vid) : '?', $vid);
            if (!IPS_VariableExists($vid)) {
                $lines[] = $this->t('• %s: variable does not exist', $name);
                $unsure = true;
                continue;
            }
            $u = $this->autoPowerUnit($vid);
            $unit = ($u['factor'] == 1000.0) ? 'kW' : (($u['factor'] == 1000000.0) ? 'MW' : 'W');
            if ($u['source'] === 'profile') {
                $lines[] = $this->t('• %s: %s – from the profile suffix “%s”', $name, $unit, $u['detail']);
            } elseif ($u['source'] === 'magnitude') {
                $lines[] = $this->t('• %s: %s – from the order of magnitude (daily maximum of the last 7 days %s)', $name, $unit, $u['detail']);
            } else {
                $lines[] = $this->t('• %s: W assumed – neither a profile suffix nor evaluable archive data; for a kW variable please set it manually above', $name);
                $unsure = true;
            }
        }
        return ($unsure ? '⚠️' : '🔗') . ' ' . $this->t('Unit (taken over automatically):') . "\n" . implode("\n", $lines);
    }

    /**
     * Vorzeichen der gemessenen Leistung je Generator (Spalte „Vorzeichen umkehren"). Prüft an den Tageswerten der
     * letzten 7 Tage, ob eine nicht umgekehrte Variable die Erzeugung negativ liefert (dann ⚠️ mit Hinweis) oder
     * eine umgekehrte positiv (Umkehr würde alles auf 0 setzen). Nur Hinweis, es wird nichts selbst umgestellt.
     */
    private function signStatusLine(): string
    {
        $gens = array_values(array_filter($this->pvGenerators(), function ($g) { return $g['powervar'] > 0; }));
        if (count($gens) === 0) {
            return $this->t('ℹ️ Sign: no measured power (PowerVar) selected yet. If your inverter counts the generation as negative, switch on “Invert sign” in the generator list.');
        }
        $aid = $this->archiveID();
        $warn = [];
        $inverted = [];
        $checked = 0;
        foreach ($gens as $g) {
            $vid = $g['powervar'];
            $name = ($g['name'] !== '') ? $g['name'] : ('#' . $vid);
            if ($g['invert']) { $inverted[] = $name; }
            if ($aid <= 0 || !IPS_VariableExists($vid) || !$this->isLogged($aid, $vid)) { continue; }
            $rows = $this->aggregatedValuesQuiet($aid, $vid, 1, strtotime('-7 days'), $this->clampEnd(time()));
            if (!is_array($rows) || count($rows) === 0) { continue; }
            $maxPos = 0.0; $maxNeg = 0.0;
            foreach ($rows as $r) {
                $maxPos = max($maxPos, (float)$r['Max']);
                $maxNeg = max($maxNeg, -(float)($r['Min'] ?? 0));
            }
            $checked++;
            if (!$g['invert'] && $maxNeg > 0 && $maxNeg > 2 * $maxPos) {
                $warn[] = $this->t('%s: the values are predominantly negative (strongest value −%s, highest positive %s) – switch “Invert sign” on', $name, round($maxNeg, 1), round($maxPos, 1));
            } elseif ($g['invert'] && $maxPos > 0 && $maxPos >= 2 * $maxNeg) {
                $warn[] = $this->t('%s: the values are predominantly positive (highest %s, strongest negative −%s) – switch “Invert sign” off, otherwise the generation counts as 0', $name, round($maxPos, 1), round($maxNeg, 1));
            }
        }
        if (count($warn) > 0) {
            return '⚠️ ' . $this->t('Sign:') . "\n• " . implode("\n• ", $warn);
        }
        if (count($inverted) > 0) {
            return $this->t('✏️ Sign: inverted for %s (own setting) – negative generation is read as yield, a positive value (standby draw) as 0.', implode(', ', $inverted));
        }
        return $checked > 0
            ? $this->t('✅ Sign: generation is measured as positive, nothing to invert.')
            : $this->t('ℹ️ Sign: no evaluable archive data yet – generation is expected as a positive value; switch on “Invert sign” if it is counted negatively.');
    }

    /**
     * Solcast-API-Schlüssel: Das Eingabefeld ist nach dem Speichern bewusst leer (der Schlüssel liegt im
     * Attribut, nicht in der Property — siehe ApplyChanges()). Ein leeres Feld sah aber aus, als sei nichts
     * gespeichert (Forum hbraun, 12.10.2026) — deshalb sagt diese Zeile, ob ein Schlüssel hinterlegt ist.
     * Der Schlüssel selbst wird nie angezeigt, nur die Länge (reicht, um "gespeichert" von "abgeschnitten"
     * zu unterscheiden, verrät aber nichts).
     */
    private function solcastStatusLine(): string
    {
        $secret = trim((string)$this->ReadAttributeString('PVF_SolcastSecret'));
        $isSolcast = ($this->ReadPropertyInteger('PVF_Source') === PVF_SRC_SOLCAST);
        if ($secret !== '' && $isSolcast) {
            // Resource-ID je Generator (Spalte "Solcast-ID"): ohne sie gibt es für diesen Generator keinen Abruf.
            $noRid = [];
            $gens = $this->pvGenerators();
            foreach ($gens as $g) { if (trim($g['solcast']) === '') { $noRid[] = ($g['name'] !== '') ? $this->t('“%s”', $g['name']) : $this->t('a generator'); } }
            if (count($noRid) > 0) {
                return count($noRid) === count($gens)
                    ? $this->t('⛔ Solcast key stored, but no resource ID is entered for %s in the “Solcast ID” column of the generator list – there is no call for it (help: button “Where do I find the Solcast ID?”), the forecast cannot be loaded this way.', implode(', ', $noRid))
                    : $this->t('⛔ Solcast key stored, but no resource ID is entered for %s in the “Solcast ID” column of the generator list – there is no call for it (help: button “Where do I find the Solcast ID?”), it is missing from the total.', implode(', ', $noRid));
            }
        }
        if ($secret !== '') {
            return $this->t('✏️ Solcast key: stored (%d characters, not shown for security reasons; the field below therefore stays empty).', mb_strlen($secret))
                . ' ' . ($isSolcast ? $this->t('It is used for the call.') : $this->t('It is only used when the source is set to Solcast.'))
                . ' ' . $this->t('To change it, just enter a new key and apply.');
        }
        return $isSolcast
            ? $this->t('⛔ Solcast key: the Solcast source is selected, but no key is stored yet – without a key there is no call. Please enter it below and apply.')
            : $this->t('ℹ️ Solcast key: not stored (only needed when the source is set to Solcast).');
    }

    /**
     * Solcast-Kontingent: heutige Abrufe gegen das Tagesbudget, Tageslicht-Fenster und eine Abschätzung, ob
     * Intervall und Zahl der Resource-IDs ins Budget passen (Forum lungo, 13.10.2026: 2 Dächer, 4-h-Intervall).
     * Je Rebuild und verschiedener Resource-ID gibt es höchstens einen Abruf; Generatoren mit derselben ID teilen sich ihn.
     */
    private function solcastBudgetStatusLine(): string
    {
        if ($this->ReadPropertyInteger('PVF_Source') !== PVF_SRC_SOLCAST) {
            return $this->t('ℹ️ Solcast calls: not relevant, the source is not set to Solcast.');
        }
        $budget = max(0, $this->ReadPropertyInteger('PVF_SolcastDailyBudget'));
        $n = $this->solcastCallsToday();
        $daylight = $this->ReadPropertyBoolean('PVF_SolcastDaylightOnly');
        $win = $daylight ? $this->solcastDaylightWindow() : null;
        $plan = $this->solcastBudgetPlan();
        $interval = max(1, $this->ReadPropertyInteger('PVF_IntervalHours'));
        $gap = max($interval * 3600, $plan['gap']);
        $fmt = function (int $sec) { $h = intdiv($sec, 3600); $m = (int)round(($sec % 3600) / 60); if ($m === 60) { $h++; $m = 0; } return $h . ' h' . ($m > 0 ? ' ' . $m . ' min' : ''); };
        $perDay = ($plan['ids'] > 0) ? $plan['ids'] * ((int)floor($plan['window'] / $gap) + 1) : 0;

        $line = ($budget > 0 ? $this->t('Solcast calls today: %d of %d', $n, $budget) : $this->t('Solcast calls today: %d (no daily budget)', $n)) . ', '
            . (($win !== null)
                ? $this->t('calls only in daylight (%s–%s)', date('H:i', $win[0]), date('H:i', $win[1]))
                : ($daylight ? $this->t('daylight window cannot be determined (check coordinates), calls around the clock') : $this->t('calls around the clock')));
        if ($budget > 0 && $plan['ids'] > 0) {
            $ids = ($plan['ids'] === 1) ? $this->t('1 resource ID') : $this->t('%d different resource IDs', $plan['ids']);
            $line .= '. ' . (($plan['gap'] > $interval * 3600)
                ? $this->t('With %s and budget %d, %d call rounds per day are possible (%d × %d = %d calls) - therefore a call is made at most every %s (longer than your interval of %d h, stretched automatically).',
                    $ids, $budget, $plan['rounds'], $plan['rounds'], $plan['ids'], $plan['rounds'] * $plan['ids'], $fmt($gap), $interval)
                : $this->t('With %s and budget %d, %d call rounds per day are possible (%d × %d = %d calls); your interval of %d h fits, that is about %d calls per day.',
                    $ids, $budget, $plan['rounds'], $plan['rounds'], $plan['ids'], $plan['rounds'] * $plan['ids'], $interval, $perDay));
        } elseif ($budget <= 0) {
            $line .= '. ' . ($plan['ids'] > 0
                ? $this->t('Interval %d h, about %d calls per day.', $interval, $perDay)
                : $this->t('Interval %d h.', $interval));
        } else {
            $line .= '.';
        }
        // Wann war der letzte Abruf, wann ist der nächste frühestens möglich (Forum lungo #61: "wo sieht man die geplanten Slots")
        $t = $this->solcastCallTimes();
        $dayOf = function (int $ts) { return (date('Y-m-d', $ts) === date('Y-m-d', $this->solcastNow())) ? '' : date('d.m. ', $ts); };
        if ($plan['ids'] > 0) {
            $line .= ' ' . $this->t('Last call: %s; earliest next: %s.',
                $t['last'] !== null ? $dayOf($t['last']) . date('H:i', $t['last']) : $this->t('none yet'),
                ($t['next'] !== null && $t['next'] > $this->solcastNow() + 60) ? $dayOf($t['next']) . date('H:i', $t['next']) : $this->t('at the next calculation run'));
        }
        if ($budget > 0 && $n >= $budget) {
            return '⚠️ ' . $line . ' ' . $this->t('The daily budget is reached, until tomorrow the last valid response applies.');
        }
        if ($budget > 0 && $plan['ids'] > $budget) {
            return '⚠️ ' . $line . ' ' . $this->t('There are more different resource IDs than the budget allows calls - give generators of the same site the same ID.');
        }
        return 'ℹ️ ' . $line;
    }

    /** EMS-Kopplung für Sondereffekte: Prognosegüte, Fehler-Korrektur und Selbstkalibrierung. */
    private function emsStatusLine(): string
    {
        $ems = function_exists('EMS_GetSpecialEvents') ? $this->emsInstance() : 0;
        if ($ems <= 0) {
            return $this->t('ℹ️ EMS: not found – days with curtailed generation (grid operator dimming, negative price …) are not removed from the self-calibration or the forecast accuracy, every day counts.');
        }
        $days = max(7, $this->ReadPropertyInteger('PVF_CalibDays'));
        $events = $this->calibrationEvents($days);
        $head = $this->t('EMS: connected to “%s” (#%d, detected automatically)', IPS_GetName($ems), $ems);
        if ($this->specialEventsVersionMismatch !== null) {
            return '⚠️ ' . $this->t('%s, but the contract EMS_GetSpecialEvents has version %s (supported: %d.x) – link switched off until the module is updated.', $head, $this->specialEventsVersionMismatch, self::EMS_EVENTS_MAJOR);
        }
        if ($this->specialEventsContract === null) {
            return '⚠️ ' . $this->t('%s, but EMS_GetSpecialEvents returns no event list – special effects are not taken into account.', $head);
        }
        $pvDays = 0;
        for ($d = 1; $d <= $days; $d++) {
            $ts = strtotime('today -' . $d . ' days');
            if ($this->dayHasSpecialEvent($events, $ts, $this->dayEndExclusive($ts) - 1, 'pv')) { $pvDays++; }
        }
        $line = '✅ ' . $this->t('%s, contract EMS_GetSpecialEvents %s. Taken over: %d special event(s), of which %d day(s) of the last %d affect the PV generation – these days stay out of the self-calibration, the forecast accuracy and the error correction (band, level).',
                $head, $this->specialEventsContract, count($events), $pvDays, $days)
            . ' ' . $this->t('Events that only affect the load (e.g. Grid Rewards) do not count here.');
        if (version_compare($this->specialEventsContract, '1.1', '<')) {
            $line .= ' ' . $this->t('Note: contract %s provides no field “affects” – every event then applies to load and PV.', $this->specialEventsContract);
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
            'caption' => '👋  What is this module for?',
            'items' => [
                ['type' => 'Label', 'caption' => 'PVPrognose calculates a physics-based generation forecast with an uncertainty band (P10/P50/P90) for the coming days from weather data (Open-Meteo, Forecast.Solar or Solcast) and the data of your PV system — and keeps learning from the comparison with the actual generation.'],
                ['type' => 'Label', 'caption' => 'The benefit: a reliable planning basis for load management, battery control or an energy management system (EMS), without having to look at the weather yourself.'],
                ['type' => 'Label', 'caption' => 'For the consumption side, Lastprognose (load forecast) is the counterpart; for a combined overview of both forecasts there is the forecast tile in the NRG dashboard.'],
                ['type' => 'Button', 'caption' => 'Understood – do not show again', 'onClick' => 'PVF_AckPurposeIntro($id);'],
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
            ? $this->t('ℹ️ PV forecast — part of the NRG-Stack forecast suite, version %s (build %s)', $lib['Version'], $lib['Build'] ?? '?')
            : $this->t('ℹ️ PV forecast');
        return ['type' => 'Label', 'caption' => $txt];
    }

    /** „Was ist neu"-Banner: erscheint nach einem Update, bis „Verstanden" geklickt wird. */
    private function newsBanner()
    {
        if ($this->ReadAttributeString('SeenNews') === self::NEWS_VERSION) {
            return null;
        }
        $items = [['type' => 'Label', 'caption' => '🆕 New — please take a quick look and check your settings if necessary:']];
        foreach (self::NEWS_ITEMS as $line) {
            $items[] = ['type' => 'Label', 'caption' => '• ' . $this->t($line)];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Understood – do not show again', 'onClick' => 'PVF_AckNews($id);'];
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'caption' => $this->t('🆕 New in version %s', self::NEWS_VERSION), 'expanded' => true, 'items' => $items];
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
            'caption' => '🧡  About this module',
            'items' => [
                ['type' => 'Label', 'caption' => 'Born out of genuine enthusiasm for my own installation — and a few evenings of typing. Still: hobby software or not, this is intellectual property and real work went into it.'],
                ['type' => 'Label', 'caption' => 'License: PolyForm Noncommercial 1.0.0 — free for private and non-commercial use; commercial use needs a separate license from the rights holder.'],
                ['type' => 'Button', 'caption' => 'View license text', 'onClick' => "echo '" . self::LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Commercial use or questions about the license? Just get in touch: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'Do you like the module and still want to leave something? I would be happy about a small donation — completely voluntary, nothing owed in return.'],
                ['type' => 'Button', 'caption' => '☕  Donate via PayPal', 'onClick' => "echo '" . self::PAYPAL_URL . "';", 'link' => true],
            ],
        ];
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Gesamte Modulfläche aus der Generatorliste (konfig-abgeleitet).
        $this->SetValue('PVF_ModuleArea', $this->totalModuleArea());

        // Neu eingegebenen Solcast-Schlüssel ins Attribut übernehmen und das
        // Formularfeld per Kurz-Timer (nicht rekursiv) wieder leeren.
        $enteredKey = trim((string)$this->ReadPropertyString('PVF_SolcastKey'));
        if ($enteredKey !== '') {
            $this->WriteAttributeString('PVF_SolcastSecret', $enteredKey);
            $this->SetTimerInterval('PVF_ClearSolcastKeyTimer', 1);
        }

        $active = $this->ReadPropertyBoolean('PVF_Active');
        $hours  = max(1, $this->ReadPropertyInteger('PVF_IntervalHours'));

        if ($active) {
            $this->SetTimerInterval('PVF_RebuildTimer', $hours * 3600 * 1000);
            $this->SetTimerInterval('PVF_DayRollTimer', $this->msToNextMidnight());
            $this->SetStatus(102);
        } else {
            $this->SetTimerInterval('PVF_RebuildTimer', 0);
            $this->SetTimerInterval('PVF_DayRollTimer', 0);
            $this->SetStatus(104);
        }
    }

    /** Millisekunden bis 10 s nach der nächsten lokalen Mitternacht. */
    private function msToNextMidnight(): int
    {
        return max(1000, (strtotime('tomorrow') - time() + 10) * 1000);
    }

    /**
     * Tageswechsel: schiebt die gespeicherten Tage weiter (Prognose von gestern-
     * "morgen" wird "heute" usw.), OHNE die Wetter-API zu brauchen. Fund (EMS,
     * 19.09.2026): Bei Netzausfall um Mitternacht blieb in PVF_Today die Kurve von
     * gestern stehen — jeder, der die Variablen direkt liest (Energiebilanz, andere
     * Module), zeigte sie als "heute", obwohl die richtige Prognose in
     * PVF_Tomorrow lag. Läuft als Timer kurz nach Mitternacht und stellt sich
     * selbst für den nächsten Tag neu.
     */
    public function RollDay()
    {
        try {
            $this->rotateForecastCache();
        } finally {
            $this->SetTimerInterval('PVF_DayRollTimer', $this->ReadPropertyBoolean('PVF_Active') ? $this->msToNextMidnight() : 0);
        }
    }

    /**
     * Ordnet die fünf gespeicherten Tagesprognosen nach ihrem 'date' wieder den
     * Offsets zu. Ein Offset ohne passende Quelle bekommt eine ehrliche
     * Leer-Prognose (generated=0) statt einer falschen Kurve mit altem Datum.
     */
    private function rotateForecastCache()
    {
        $idents = ['PVF_Today', 'PVF_Tomorrow', 'PVF_DayAfter', 'PVF_Day3', 'PVF_Day4'];
        $kwhIds = ['PVF_kWhToday', 'PVF_kWhTomorrow', 'PVF_kWhDayAfter', 'PVF_kWhDay3', 'PVF_kWhDay4'];
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

    /**
     * Leert das Solcast-Schlüssel-Formularfeld (Property). Läuft als
     * eigenständiger Timer-Aufruf NACH ApplyChanges(), nicht rekursiv
     * innerhalb davon — sicherer Weg für IPS_SetProperty+IPS_ApplyChanges
     * auf die eigene Instanz.
     */
    public function ClearSolcastKey()
    {
        $this->SetTimerInterval('PVF_ClearSolcastKeyTimer', 0);
        if (trim((string)$this->ReadPropertyString('PVF_SolcastKey')) === '') { return; }
        @IPS_SetProperty($this->InstanceID, 'PVF_SolcastKey', '');
        @IPS_ApplyChanges($this->InstanceID);
    }

    // ----------------------------------------------------------------
    //  Öffentlich
    // ----------------------------------------------------------------

    /**
     * Neuberechnung anstoßen. Gibt einen menschenlesbaren Ergebnistext zurück
     * (✅/⚠️/⛔-Präfix) — der Formular-Button ruft das über
     * "echo PVF_Rebuild($id);" auf, damit man ohne Formular-Neuöffnen sofort
     * sieht, dass etwas passiert ist (Verbund-Konvention "Sichtbare
     * Rückmeldung bei jeder Aktion", SUITE.md 20.08.2026). Der Intervall-
     * Timer ruft dieselbe Methode auf und ignoriert den Rückgabewert.
     */
    public function Rebuild(): string
    {
        if (count($this->pvGenerators()) === 0) {
            $msg = $this->t('⛔ No PV generators configured.');
            $this->SetValue('PVF_Status', $msg);
            return $msg;
        }

        try {
            $this->modelCache = null;
            $this->calibNotes = [];
            $model = $this->buildModel();
            $this->WriteAttributeInteger('PVF_ModelFailUntil', $model === null ? time() + 120 : 0);
            // Bisher blieb modelCache hier null, computeForecast() lud danach beim
            // ersten Offset alles ein ZWEITES Mal von der Wetter-API (doppelte Abrufe,
            // bei Forecast.Solar ratenbegrenzt) — jetzt wird der Aufbau wiederverwendet.
            $this->modelCache = $model;
            if ($model === null) {
                $why = $this->solcastIssueText();
                $msg = ($why !== '')
                    ? $this->t('⚠️ Forecast could not be loaded: %s — the last valid forecast is kept.', $why)
                    : $this->t('⚠️ Forecast could not be loaded (API/network?) — the last valid forecast is kept.');
                $this->rotateForecastCache(); // Tageswechsel trotzdem nachziehen (siehe RollDay())
                $this->SetValue('PVF_Status', $msg);
                $this->SetStatus(104);
                return $msg;
            }

            // Prognosegüte/Residuen VOR den Prognosen lernen: evaluateAccuracy() liest nur abgeschlossene
            // Vortage (d >= 1), hängt also nicht von den heutigen Prognosen ab. Bisher lief es danach, ein
            // frisch berechnetes Übergangsband (bzw. die neuen Residuen) wirkte dadurch erst ab dem ZWEITEN
            // Rebuild nach dem Update, also ca. 6 h später.
            try { $this->evaluateAccuracy(); }
            catch (\Throwable $e) { $this->log(PVF_LOG_BASIC, $this->t('Forecast accuracy evaluation failed: %s', $e->getMessage())); }

            $idents = ['PVF_Today', 'PVF_Tomorrow', 'PVF_DayAfter', 'PVF_Day3', 'PVF_Day4'];
            $kwhIds = ['PVF_kWhToday', 'PVF_kWhTomorrow', 'PVF_kWhDayAfter', 'PVF_kWhDay3', 'PVF_kWhDay4'];
            $fcs = [];
            for ($offset = 0; $offset <= PVF_MAX_OFFSET; $offset++) {
                $fc = $this->computeForecast($offset);
                $fcs[$offset] = $fc;
                $this->SetValue($idents[$offset], json_encode($fc));
                $this->SetValue($kwhIds[$offset], round($fc['kwh'], 2));
            }
            $this->saveSnapshot($fcs);
            $this->saveIntradaySnapshot($fcs);

            $this->SetValue('PVF_LastUpdate', time());
            $status = $this->t(
                '✅ today %.1f / tomorrow %.1f / day after tomorrow %.1f / day 4 %.1f / day 5 %.1f kWh',
                $this->GetValue('PVF_kWhToday'),
                $this->GetValue('PVF_kWhTomorrow'),
                $this->GetValue('PVF_kWhDayAfter'),
                $this->GetValue('PVF_kWhDay3'),
                $this->GetValue('PVF_kWhDay4')
            );
            $stale = $this->checkDataPlausibility();
            if (count($stale) > 0) {
                $status .= ' | ⚠️ ' . implode(' · ', $stale);
            }
            $status .= $this->statusNotices();
            $this->SetValue('PVF_Status', $status);
            $this->SetStatus(102);
            $this->log(PVF_LOG_BASIC, $this->t('Recalculation completed'));
            return $status;

        } catch (Exception $e) {
            $msg = $this->t('⛔ Error: %s', $e->getMessage());
            $this->SetValue('PVF_Status', $msg);
            $this->SetStatus(104);
            $this->log(PVF_LOG_BASIC, $this->t('Error: %s', $e->getMessage()));
            return $msg;
        }
    }

    /**
     * Laufende Plausibilitätskontrolle: läuft bei JEDEM Rebuild() automatisch mit
     * (kein separater Zeitplan nötig — nutzt den ohnehin vorhandenen Intervall-
     * Timer des Moduls). Prüft, ob die für Selbstkalibrierung/Prognosegüte
     * verwendeten PowerVar-Messwerte überhaupt noch aktuell hereinkommen. Genau
     * diese Fehlerklasse (zwei Wochen unbemerkte Daten-Stille durch eine
     * geschlossene Modbus-Verbindung, 09.08.2026) fiel bisher niemandem auf,
     * bis der Vergleich Soll/Ist von Hand angestoßen wurde. 48h Schwelle, weil
     * jeder normale Tag/Nacht-Zyklus mindestens einmal einen echten Wertewechsel
     * zeigen muss (auch bei bewölktem Himmel) — kürzer würde jede Nacht falsch
     * anschlagen, länger verschleppt eine echte Störung unnötig.
     */
    private function checkDataPlausibility(): array
    {
        $warnings = [];
        $staleSec = 48 * 3600;
        foreach ($this->pvGenerators() as $g) {
            if ($g['powervar'] <= 0 || !IPS_VariableExists($g['powervar'])) { continue; }
            // Letzte AKTUALISIERUNG statt letzter Wertänderung (siehe Lastprognose): eine regulär gemeldete
            // Leistung, die 48 h konstant 0 W ist (z. B. Schnee auf den Modulen), ist nicht "ohne Messwert".
            $v   = IPS_GetVariable($g['powervar']);
            $age = time() - max((int)$v['VariableUpdated'], (int)$v['VariableChanged']);
            if ($age > $staleSec) {
                $warnings[] = $this->t('%s: PowerVar without a new measured value for %.1f days', $g['name'], $age / 86400);
            }
        }
        return $warnings;
    }

    /**
     * PV-Prognose für einen Tag als Array. $offset 0/1/2. Für das EMS per
     * PVF_GetForecast($id, $offset) abrufbar.
     *
     * Performance (Fund aus PVMonitor/Dashboard-Sitzung, 20.08.2026, analog
     * zum LFC-Fund): $modelCache ist eine reine Request-lokale Instanzvariable
     * und hilft NUR innerhalb EINER Ausführung (z. B. den 3 Offsets in
     * Rebuild()) — ein externer Aufruf von außen (PVMonitor, EMS, …) ist immer
     * eine frische Skriptausführung und löste bisher jedes Mal buildModel()
     * neu aus (Live-API-Aufrufe an Open-Meteo/Forecast.Solar/Solcast, bei
     * Forecast.Solar sogar ratenbegrenzt). Jetzt: bevorzugt der von Rebuild()
     * bereits berechnete und in PVF_Today/Tomorrow/DayAfter zwischen-
     * gespeicherte Stand, solange das 'date'-Feld noch zum Offset passt.
     * Rebuild() selbst nutzt computeForecast() direkt.
     */
    private function forecastBase(int $offset)
    {
        $idents   = ['PVF_Today', 'PVF_Tomorrow', 'PVF_DayAfter', 'PVF_Day3', 'PVF_Day4'];
        $wantDate = date('Y-m-d', strtotime('today +' . $offset . ' days'));
        // Nach DATUM suchen, nicht nur im Ident des Offsets: Nach Mitternacht (und
        // nach jedem fehlgeschlagenen Rebuild) ist die Prognose von gestern-"morgen"
        // heute der "heute"-Stand — sie steht dann in PVF_Tomorrow, nicht in
        // PVF_Today. Fund (EMS, 19.09.2026): Der Offset-Treffer scheiterte am
        // Datum, jeder Aufruf löste einen Live-Abruf aus (bei Netzausfall im
        // Sekundentakt Timeouts) und lieferte Null- oder Teilwerte, obwohl die
        // richtige Prognose längst gespeichert war.
        foreach ($idents as $i => $ident) {
            $cached = json_decode((string)$this->GetValue($ident), true);
            if (!is_array($cached) || ($cached['date'] ?? null) !== $wantDate) { continue; }
            if (($cached['generated'] ?? 1) === 0) { continue; } // Leer-Platzhalter, keine echten Daten
            // Treffer in einem anderen Speicher als dem des Offsets = Tageswechsel
            // noch nicht verschoben → jetzt nachholen, damit auch direkte Leser stimmen.
            if (isset($idents[$offset]) && $i !== $offset) { $this->rotateForecastCache(); }
            return $cached;
        }
        return $this->computeForecast($offset);
    }

    /**
     * Öffentlicher Vertrag PVF_GetForecast($id, $offset) (siehe Kommentar oben bei forecastBase()).
     *
     * Vertrag 1.4 (additiv): Für HEUTE (Offset 0) steht `moduleCover` im Ergebnis, wenn FrigateHub (Beobachtung
     * `snow_pv`) eine gültige Bedeckung der Module meldet. Das Feld wird beim LESEN aufgelegt, nicht im Cache und
     * nicht im Snapshot: Cache und gespeicherte Day-Ahead-Prognose bleiben die reine Modellprognose, so lernen
     * Prognosegüte und Residuen nie aus einer Schneekorrektur, und „Module wieder frei“ wirkt sofort statt erst
     * beim nächsten Rebuild. Folgetage unverändert (wann Schnee abgeht, weiß keine Quelle).
     */
    public function GetForecast(int $offset)
    {
        $fc = $this->forecastBase($offset);
        if ($offset !== 0 || !is_array($fc)) { return $fc; }
        if (($fc['date'] ?? '') !== date('Y-m-d') || ($fc['generated'] ?? 0) === 0) { return $fc; }
        try {
            return $this->withModuleCover($fc);
        } catch (\Throwable $e) {
            $this->log(PVF_LOG_BASIC, $this->t('Module cover could not be applied: %s', $e->getMessage()));
            return $fc;
        }
    }

    /**
     * Legt `moduleCover` auf die Tagesprognose von heute und rechnet bei eingeschaltetem Schalter eine
     * vollständige Bedeckung ein: die Slots ab jetzt (inkl. des laufenden) werden 0, bereits vergangene bleiben.
     * Eingerechnet wird nur bei value 2 (bedeckt) und ausreichender Sicherheit; „teilweise“ hat keinen festen Faktor.
     */
    private function withModuleCover(array $fc): array
    {
        $obs = $this->snowObservation();
        if ($obs === null) { return $fc; }
        $cover = [
            'level'      => $obs['level'],
            'since'      => $obs['since'],
            'confidence' => $obs['confidence'],
            'source'     => $obs['source'],
            'calibrated' => $obs['calibrated'],
            'applied'    => false,
        ];
        if (isset($obs['clearedAt'])) { $cover['clearedAt'] = $obs['clearedAt']; }

        $slots = (int)($fc['slots'] ?? 0);
        $adjust = $this->ReadPropertyBoolean('PVF_SnowAdjust')
            && $obs['level'] === 2
            && $obs['confidence'] >= PVF_COVER_MIN_CONFIDENCE
            && $slots > 0 && count($fc['p50'] ?? []) === $slots;
        if ($adjust) {
            $slotMin = intdiv(1440, $slots);
            $from = intdiv((int)date('G') * 60 + (int)date('i'), $slotMin);   // Wanduhr-Slot wie in integrateWindow()
            foreach (['p10', 'p50', 'p90', 'mean'] as $k) {
                if (!is_array($fc[$k] ?? null)) { continue; }
                for ($i = $from; $i < $slots; $i++) { $fc[$k][$i] = 0.0; }
            }
            $cover['kwhWithoutCover'] = $fc['kwh'];
            $fc['kwh'] = round(array_sum($fc['p50']) * ($slotMin / 60.0) / 1000.0, 2);
            $cover['applied'] = true;
        }
        $fc['moduleCover'] = $cover;
        return $fc;
    }

    /**
     * Gültige Beobachtung `snow_pv` aus FrigateHub (FRIGS_GetObservations, Vertrag `observation` 1.x) oder null.
     * null = unbekannt (kein FrigateHub, keine Beobachtung, veraltet, fremder Major): dann ändert sich nichts.
     * Veraltet heißt: validUntil in der Vergangenheit oder letzte Prüfung älter als PVF_COVER_MAX_AGE.
     * Je Aufruf einmal gelesen.
     */
    private $snowObsLoaded = false;
    private $snowObs = null;
    private function snowObservation(): ?array
    {
        if ($this->snowObsLoaded) { return $this->snowObs; }
        $this->snowObsLoaded = true;
        $this->snowObs = null;
        if (!function_exists('FRIGS_GetObservations')) { return null; }
        $ids = @IPS_GetInstanceListByModuleID(PVF_FRIGS_GUID);
        if (!is_array($ids)) { return null; }
        $now = time();
        foreach ($ids as $id) {
            $r = @FRIGS_GetObservations((int)$id);
            if (!is_array($r)) { continue; }
            if ((int)explode('.', (string)($r['contractVersion'] ?? '1.0'))[0] !== 1) { continue; }
            foreach (($r['observations'] ?? []) as $o) {
                if (!is_array($o) || ($o['code'] ?? '') !== 'snow_pv') { continue; }
                $level = $o['value'] ?? null;
                if (is_bool($level) || !is_numeric($level) || (int)$level < 0 || (int)$level > 2) { continue; }
                $until = (int)($o['validUntil'] ?? 0);
                $checked = (int)($o['checkedAt'] ?? 0);
                if ($until > 0 && $until < $now) { continue; }
                if ($checked > 0 && $now - $checked > PVF_COVER_MAX_AGE) { continue; }
                $obs = [
                    'level'      => (int)$level,
                    'since'      => (int)($o['since'] ?? 0),
                    'confidence' => round((float)($o['confidence'] ?? 0), 2),
                    'source'     => (string)($o['source'] ?? ''),
                    'calibrated' => (bool)($o['calibrated'] ?? false),
                ];
                if (isset($o['clearedAt']) && (int)$o['clearedAt'] > 0) { $obs['clearedAt'] = (int)$o['clearedAt']; }
                // Mehrere FrigateHub-Server: die ungünstigste (höchste) Bedeckung zählt.
                if ($this->snowObs === null || $obs['level'] > $this->snowObs['level']) { $this->snowObs = $obs; }
            }
        }
        return $this->snowObs;
    }

    /** FrigateHub-Kopplung für die Schneebedeckung der Module (Statuszeile im Formular). */
    private function snowStatusLine(): string
    {
        $adjust = $this->ReadPropertyBoolean('PVF_SnowAdjust');
        if (!function_exists('FRIGS_GetObservations')) {
            return $this->t('ℹ️ FrigateHub: not found – snow on the modules is not taken into account.');
        }
        $ids = @IPS_GetInstanceListByModuleID(PVF_FRIGS_GUID);
        if (!is_array($ids) || count($ids) === 0) {
            return $this->t('ℹ️ FrigateHub: not found – snow on the modules is not taken into account.');
        }
        $id = (int)$ids[0];
        $head = $this->t('FrigateHub: connected to “%s” (#%d, detected automatically)', IPS_GetName($id), $id);
        $obs = $this->snowObservation();
        if ($obs === null) {
            return '⚠️ ' . $this->t('%s, but there is currently no valid observation “snow on the modules” (no area assigned, camera down or value too old) – the forecast stays unchanged.', $head);
        }
        $levels = [0 => $this->t('free'), 1 => $this->t('partly covered'), 2 => $this->t('covered')];
        $state = $levels[$obs['level']];
        if (!$adjust) {
            return '✅ ' . $this->t('%s. Modules: %s (certainty %d %%). The switch below is off – the value is only passed on as “moduleCover”, the forecast stays unchanged.', $head, $state, (int)round($obs['confidence'] * 100));
        }
        if ($obs['level'] === 2 && $obs['confidence'] < PVF_COVER_MIN_CONFIDENCE) {
            return '⚠️ ' . $this->t('%s. Modules: %s, but the certainty (%d %%) is below the minimum of %d %% – the forecast stays unchanged.', $head, $state, (int)round($obs['confidence'] * 100), (int)round(PVF_COVER_MIN_CONFIDENCE * 100));
        }
        if ($obs['level'] === 2) {
            return '✅ ' . $this->t('%s. Modules: %s (certainty %d %%) – the rest of today is set to 0 until FrigateHub reports them free again.', $head, $state, (int)round($obs['confidence'] * 100));
        }
        return '✅ ' . $this->t('%s. Modules: %s (certainty %d %%) – nothing to correct.', $head, $state, (int)round($obs['confidence'] * 100));
    }

    /** Die eigentliche, teure Modellberechnung — siehe forecastBase() für den Cache davor. */
    private function computeForecast(int $offset)
    {
        if ($this->modelCache === null && time() >= $this->ReadAttributeInteger('PVF_ModelFailUntil')) {
            $this->modelCache = $this->buildModel();
            // Bei Fehlschlag 2 Minuten Pause: sonst lädt jeder externe Aufruf
            // (Dashboard, EMS, …) erneut und blockiert bei Netzausfall je 10 s.
            $this->WriteAttributeInteger('PVF_ModelFailUntil', $this->modelCache === null ? time() + 120 : 0);
        }
        $targetTs = strtotime('today +' . $offset . ' days');
        if ($this->modelCache === null || !isset($this->modelCache[$offset])) {
            return $this->emptyForecast($targetTs);
        }

        $day = $this->modelCache[$offset];
        $h10 = []; $h50 = []; $h90 = [];
        for ($h = 0; $h < 24; $h++) {
            $h10[$h] = $day[$h]['p10'];
            $h50[$h] = $day[$h]['p50'];
            $h90[$h] = $day[$h]['p90'];
        }

        // Stündliches Modell auf die gewählte Auflösung bringen (Interpolation).
        $slots = $this->slots();
        $means = $this->sourceIsIntervalMean();
        $p10 = $this->resample($h10, $slots, $means);
        $p50 = $this->resample($h50, $slots, $means);
        $p90 = $this->resample($h90, $slots, $means);

        // Rohe p50 fürs Lernen merken (nicht im Rückgabewert — Vertrag unverändert): Die Residuen
        // müssen gegen die ROHE Modellprognose gelernt werden, sonst konvergiert der Pegel nur gegen
        // die Wurzel des Fehlers (Snapshot = korrigierte Prognose, wird wieder auf Roh multipliziert).
        $this->lastRawP50[$offset] = $p50;

        // Band (und optional Pegel) aus den gemessenen Prognosefehlern ableiten.
        list($p10, $p50, $p90) = $this->applyResiduals($p10, $p50, $p90);
        list($p10, $p50, $p90) = $this->applyPeakCap($p10, $p50, $p90);

        $kwh = array_sum($p50) * $this->slotHours() / 1000.0;

        return [
            'contractVersion' => PVF_CONTRACT_FORECAST,
            'date'       => date('Y-m-d', $targetTs),
            'slots'      => $slots,
            'resolution' => $this->slotMinutes() . 'min',
            'unit'       => 'W',
            'p10'        => array_map(function ($x) { return round($x, 1); }, $p10),
            'p50'        => array_map(function ($x) { return round($x, 1); }, $p50),
            'p90'        => array_map(function ($x) { return round($x, 1); }, $p90),
            'mean'       => array_map(function ($x) { return round($x, 1); }, $p50),
            'kwh'        => round($kwh, 2),
            'neighbors'  => 0,
            'generated'  => time(),
        ];
    }

    private function slotMinutes(): int
    {
        $m = $this->ReadPropertyInteger('PVF_Resolution');
        return in_array($m, [15, 30, 60], true) ? $m : 60;
    }

    private function slots(): int
    {
        return (int)(1440 / $this->slotMinutes());
    }

    private function slotHours(): float
    {
        return $this->slotMinutes() / 60.0;
    }

    /**
     * Ob die stündlichen Modellwerte MITTELWERTE über das Intervall [h, h+1) sind
     * (Open-Meteo: Einstrahlung = Mittel der Stunde, siehe omSlot) oder
     * PUNKTWERTE auf der Stundenmarke (Forecast.Solar: Momentanleistung hh:00;
     * Solcast: zwei Halbstunden-Mittel gruppiert um hh:00, also ebenfalls auf der
     * Marke zentriert).
     */
    private function sourceIsIntervalMean(): bool
    {
        $src = $this->ReadPropertyInteger('PVF_Source');
        return $src !== PVF_SRC_FORECASTSOLAR && $src !== PVF_SRC_SOLCAST;
    }

    /** Kurvenform-Kennung der aktuell erzeugten Prognose (siehe PVF_CURVE_SHAPE_MEANS). */
    private function curveShape(): int
    {
        return $this->sourceIsIntervalMean() ? self::PVF_CURVE_SHAPE_MEANS : 1;
    }

    /**
     * Stündliche Werte (24) auf $slots Slots hochrechnen. Bei 60 min unverändert.
     *
     * $intervalMeans=false (Punktwerte auf der Stundenmarke h:00): lineare
     * Interpolation zwischen den Marken, wie immer.
     *
     * $intervalMeans=true (Mittelwert über [h, h+1)): der Wert gehört zur
     * Stundenmitte h+0,5 — bis Build 114 wurde er auf h:00 gelegt, die Kurve
     * lief der Wahrheit dadurch ca. 30 min voraus (bis ca. 11 % Abweichung in
     * Vormittagsfenstern, Fund EMS/Dietmar 20.09.2026). Jetzt: Interpolation
     * zwischen den Stundenmitten und anschließend je Stunde auf den Stundenmittel-
     * wert normiert, damit die Energie JEDER Stunde exakt erhalten bleibt und
     * eine Stunde mit Mittel 0 (vor Sonnenaufgang/nach Sonnenuntergang) auch in
     * allen ihren Slots 0 bleibt statt Leistung aus der Nachbarstunde zu erben.
     */
    private function resample(array $hourly, int $slots, bool $intervalMeans = false): array
    {
        if ($slots === 24) { return array_values($hourly); }
        $out = [];
        $step = 24.0 / $slots; // Stunden je Slot

        if (!$intervalMeans) {
            for ($s = 0; $s < $slots; $s++) {
                $hf = $s * $step;          // Position in Stunden
                $h0 = (int)floor($hf);
                $h1 = min(23, $h0 + 1);
                $f  = $hf - $h0;
                $out[$s] = $hourly[$h0] * (1.0 - $f) + $hourly[$h1] * $f;
            }
            return $out;
        }

        $k = (int)round($slots / 24); // Slots je Stunde
        for ($s = 0; $s < $slots; $s++) {
            $p  = ($s + 0.5) * $step - 0.5;   // Slotmitte, gemessen ab der Mitte der Stunde 0
            $h0 = (int)floor($p);
            $f  = $p - $h0;
            $a  = $hourly[max(0, min(23, $h0))];
            $b  = $hourly[max(0, min(23, $h0 + 1))];
            $out[$s] = $a * (1.0 - $f) + $b * $f;
        }
        for ($h = 0; $h < 24; $h++) {
            $sum = 0.0;
            for ($j = 0; $j < $k; $j++) { $sum += $out[$h * $k + $j]; }
            $target = (float)$hourly[$h] * $k;   // Summe der Slotwerte = k × Stundenmittel
            for ($j = 0; $j < $k; $j++) {
                $i = $h * $k + $j;
                if ($target <= 0.0)  { $out[$i] = 0.0; }
                elseif ($sum > 0.0)  { $out[$i] *= $target / $sum; }
                else                 { $out[$i] = (float)$hourly[$h]; }
            }
        }
        return $out;
    }

    public function GetStatusText()
    {
        return (string) $this->GetValue('PVF_Status');
    }

    /**
     * Erwartete PV-Erzeugung (kWh) in einem beliebigen Zeitfenster [$fromTs,
     * $toTs) — symmetrisch zu LFC_GetEnergyWindow, eigenständig (keine
     * Kopplung zu LFC; die Netto-Bilanz aus Verbrauch minus Erzeugung bildet
     * der Aufrufer selbst aus beiden Fenster-Funktionen). Deckt bis zu
     * PVF_MAX_OFFSET+1 Tage ab (unser Horizont); 'coverage' (0..1) zeigt an,
     * welcher Anteil des
     * Fensters tatsächlich mit einem ECHTEN Modell abgedeckt ist — anders als
     * bei LFC trägt 'neighbors' bei PVF (physikbasiert, kein k-NN) auch im
     * Erfolgsfall immer 0 und taugt nicht als Signal. Stattdessen wird geprüft,
     * ob buildModel() (API/Netzwerk) tatsächlich ein Modell geliefert hat —
     * bei Fehlschlag bzw. fehlenden Generatoren zählt nichts als abgedeckt,
     * statt "kwh=0, coverage=1.0" vorzutäuschen.
     * Für das EMS per PVF_GetEnergyWindow($id, $fromTs, $toTs) abrufbar.
     */
    public function GetEnergyWindow(int $fromTs, int $toTs): array
    {
        $result = [
            'contractVersion' => PVF_CONTRACT_ENERGYWINDOW,
            'from' => $fromTs,
            'to'   => $toTs,
            'kwh'  => 0.0,
            'coverage' => 0.0,
        ];
        if ($toTs <= $fromTs) { return $result; }
        if (count($this->pvGenerators()) === 0) { return $result; }

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
     * Rückgabe [kWh, abgedeckteSekunden].
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
            if ($offset >= 0 && $offset <= PVF_MAX_OFFSET) {
                if (!array_key_exists($offset, $cache)) { $cache[$offset] = $forecastFor($offset); }
                $fc = $cache[$offset];
                $mean = is_array($fc) ? ($fc['mean'] ?? null) : null;
                if (is_array($mean) && isset($mean[$slot])) {
                    $sec = $segEnd - $t;
                    $kwh += ((float)$mean[$slot]) * ($sec / 3600.0) / 1000.0; // W * h / 1000 = kWh
                    if (($fc['kwh'] ?? 0) > 0) { $coveredSec += $sec; }
                }
            }
            $t = $segEnd;
        }
        return [$kwh, $coveredSec];
    }

    /**
     * Gesamte Modulfläche (m²) über alle Generatoren = Σ Anzahl × Fläche je Modul.
     * Übergabepunkt für andere Module (z.B. InverterHub): PVF_GetModuleArea($id).
     */
    public function GetModuleArea(): float
    {
        return $this->totalModuleArea();
    }

    /**
     * Modulfläche je Generator als Liste [{name, modules, areaPerModule, area}].
     * Übergabepunkt für InverterHub: PVF_GetModuleAreas($id).
     */
    public function GetModuleAreas(): array
    {
        $out = [];
        foreach ($this->pvGenerators() as $g) {
            $out[] = [
                'name'          => $g['name'],
                'modules'       => $g['modules'],
                'lengthMM'      => round($g['modulelength'], 1),
                'widthMM'       => round($g['modulewidth'], 1),
                'areaPerModule' => round($g['modulearea'], 3),
                'area'          => round($g['modules'] * $g['modulearea'], 2),
            ];
        }
        return $out;
    }

    private function totalModuleArea(): float
    {
        $sum = 0.0;
        foreach ($this->pvGenerators() as $g) {
            $sum += $g['modules'] * $g['modulearea'];
        }
        return round($sum, 2);
    }

    /**
     * Außentemperatur-Vorhersage (Tagesmittel, Min, Max in °C) für $offset Tage (0 = heute, bis PVF_MAX_OFFSET).
     * Für Verbraucher, die eine Temperaturprognose brauchen, ohne eine eigene Wetterquelle zu haben (Lastprognose:
     * Wärmepumpe). Quelle immer Open-Meteo (kostenlos, ohne Schlüssel) an den Koordinaten dieser Instanz, unabhängig
     * von der gewählten PV-Quelle; ein einziger kleiner Abruf, 3 Stunden zwischengespeichert, bei Ausfall bis 12 Stunden
     * alter Stand. `mean` = null, wenn keine Vorhersage vorliegt (Aufrufer fällt auf seine eigene Quelle zurück).
     * Vertrag PVF_CONTRACT_TEMPERATURE 1.0: {contractVersion, date, mean, min, max, source, generated}.
     */
    public function GetTemperatureForecast(int $offset): array
    {
        $date = date('Y-m-d', strtotime('today +' . max(0, $offset) . ' days'));
        $out = ['contractVersion' => PVF_CONTRACT_TEMPERATURE, 'date' => $date, 'mean' => null, 'min' => null, 'max' => null,
                'source' => 'open-meteo', 'generated' => 0];
        if ($offset < 0 || $offset > PVF_MAX_OFFSET) { return $out; }
        $cache = $this->temperatureCache();
        if ($cache !== null && isset($cache['days'][$date])) {
            list($mean, $min, $max) = $cache['days'][$date];
            $out['mean'] = $mean; $out['min'] = $min; $out['max'] = $max; $out['generated'] = (int)$cache['ts'];
        }
        return $out;
    }

    /** Zwischengespeicherte Temperaturvorhersage; holt sie bei Bedarf neu (3 h gültig, bei Ausfall bis 12 h alter Stand). */
    private function temperatureCache()
    {
        $lat = $this->ReadPropertyFloat('PVF_Latitude');
        $lon = $this->ReadPropertyFloat('PVF_Longitude');
        $c = json_decode((string)$this->ReadAttributeString('PVF_TempFc'), true);
        $sameSite = is_array($c) && isset($c['ts'], $c['days'])
            && abs((float)($c['lat'] ?? 0) - $lat) < 0.0001 && abs((float)($c['lon'] ?? 0) - $lon) < 0.0001;
        $age = $sameSite ? (time() - (int)$c['ts']) : PHP_INT_MAX;
        if ($sameSite && $age < 3 * 3600) { return $c; }

        $url = sprintf(
            'https://api.open-meteo.com/v1/forecast?latitude=%s&longitude=%s&hourly=temperature_2m'
            . '&forecast_days=%d&timezone=auto&timeformat=unixtime',
            rawurlencode((string)$lat), rawurlencode((string)$lon), PVF_MAX_OFFSET + 1
        );
        $j = $this->httpGetJson($url);
        $times = (is_array($j) && isset($j['hourly']['time'])) ? $j['hourly']['time'] : null;
        $temps = (is_array($j) && isset($j['hourly']['temperature_2m'])) ? $j['hourly']['temperature_2m'] : null;
        if (is_array($times) && is_array($temps)) {
            $byDay = [];
            foreach ($times as $i => $t) {
                if (!isset($temps[$i]) || $temps[$i] === null) { continue; }
                $byDay[date('Y-m-d', (int)$t)][] = (float)$temps[$i];
            }
            $days = [];
            foreach ($byDay as $d => $v) {
                if (count($v) < 20) { continue; } // unvollständiger Tag (Abruf mitten im Tag liefert trotzdem 24 Werte; weniger = Lücke)
                $days[$d] = [round(array_sum($v) / count($v), 2), round(min($v), 1), round(max($v), 1)];
            }
            if (count($days) > 0) {
                $c = ['ts' => time(), 'lat' => $lat, 'lon' => $lon, 'days' => $days];
                $this->WriteAttributeString('PVF_TempFc', json_encode($c));
                return $c;
            }
        }
        // Abruf fehlgeschlagen: alter Stand bis 12 h, sonst nichts.
        return ($sameSite && $age < 12 * 3600) ? $c : null;
    }

    /**
     * Stabile Schnittstelle für andere Module (z.B. InverterHub-Monitor):
     * Performance-Ratio und je Generator die Parameter, mit denen sich aus einer
     * gemessenen Einstrahlung (W/m²) die erwartete Leistung berechnen lässt
     * (P = kWp × E/1000 × PR × Faktor). Aufruf: PVF_GetGenerators($id).
     * Rückgabe: ['pr' => float, 'totalKwp' => float, 'generators' => [
     *   ['name','kwp','tilt','azimuth','factor','area'], … ]].
     */
    public function GetGenerators(): array
    {
        $gens = [];
        $totalKwp = 0.0;
        foreach ($this->pvGenerators() as $g) {
            $gens[] = [
                'name'    => $g['name'],
                'kwp'     => round($g['kwp'], 3),
                'tilt'    => round($g['tilt'], 1),
                'azimuth' => round($g['az'], 1),
                'factor'  => round($g['factor'] > 0 ? $g['factor'] : 1.0, 4),
                'area'    => round($g['modules'] * $g['modulearea'], 2),
            ];
            $totalKwp += $g['kwp'];
        }
        return [
            'contractVersion' => PVF_CONTRACT_GENERATORS,
            'pr'        => round($this->ReadPropertyFloat('PVF_PR'), 4),
            'totalKwp'  => round($totalKwp, 3),
            'generators'=> $gens,
        ];
    }

    /**
     * Gespeicherte PV-Prognose (Soll) eines vergangenen Tages ('Y-m-d').
     * Rückgabe [] wenn kein Snapshot vorhanden.
     */
    public function GetSnapshot(string $date)
    {
        $snaps = json_decode((string)$this->ReadAttributeString('PVF_Snapshots'), true);
        if (!is_array($snaps) || !isset($snaps[$date])) { return []; }
        // shape/p50raw sind interne Lernfelder (Prognosegüte) und nicht Teil des Vertrags.
        $snap = $snaps[$date];
        unset($snap['shape'], $snap['p50raw']);
        return array_merge(['contractVersion' => PVF_CONTRACT_FORECAST], $snap);
    }

    /**
     * Untertägiger Prognose-Stand eines Tages zu einem festen Checkpoint
     * (aktuell '06:00'/'10:00', s. PVF_INTRADAY_CHECKPOINTS) — der
     * Day-Ahead-Stand bleibt GetSnapshot() vorbehalten. Rückgabe [], wenn
     * kein Stand vorhanden (Checkpoint noch nicht erreicht, oder Sammlung
     * lief zu diesem Zeitpunkt noch nicht seit Build 94).
     */
    public function GetIntradaySnapshot(string $date, string $checkpoint)
    {
        $store = json_decode((string)$this->ReadAttributeString('PVF_IntradaySnapshots'), true);
        if (!is_array($store) || !isset($store[$date][$checkpoint])) { return []; }
        $snap = $store[$date][$checkpoint];
        unset($snap['shape']); // interne Kennung, nicht Teil des Vertrags
        return array_merge(['contractVersion' => PVF_CONTRACT_FORECAST], $snap);
    }

    /**
     * Strukturierte Prognosegüte (Vertrag PVF_CONTRACT_ACCURACY) — Grundlage
     * für EMS' netzdienlichen Baustein B1 (Mittagsspitze, 13.09.2026): ein
     * lesender Abruf, löst KEINEN Wetter-Abruf aus, liefert den Stand der
     * letzten `evaluateAccuracy()`-Auswertung (läuft bei jedem Rebuild).
     *
     * ACHTUNG VORZEICHEN — `bias` und `byDaylightFraction[].factor` zählen
     * in ENTGEGENGESETZTE Richtungen (EMS' ausdrücklicher Wunsch, das hier
     * nebeneinander festzuhalten, damit sich kein Konsument vertut):
     *   - `bias` (%, aus (Soll-Ist)/Ist): POSITIV = Prognose zu HOCH.
     *   - `factor` (Ist/Soll-Median je Bucket): GRÖSSER ALS 1 = Prognose zu
     *     NIEDRIG (Ist übertrifft Soll).
     * Ein optimistischer Bias und ein Bucket-Faktor > 1 sagen also NICHT
     * dasselbe — sie sagen sich sogar tendenziell wörtlich das Gegenteil.
     *
     * `days`/`bias`/`mape` sind `null`, solange noch keine auswertbaren
     * Tage vorliegen (dann auch `days: 0`) — das ist der Fall, den EMS als
     * "zu wenig Daten, beim festen Faktor bleiben" behandeln will.
     * `byDaylightFraction[].factor` ist einzeln `null`, wenn dieser
     * Tagesanteil-Abschnitt (0=Sonnenaufgang…1=Sonnenuntergang) noch keine
     * ausreichende Datenbasis hat (< 20 Werte) — auch dann unabhängig von
     * den anderen Buckets.
     */
    public function GetAccuracy()
    {
        $detail = json_decode((string)$this->ReadAttributeString('PVF_AccuracyDetail'), true);
        if (!is_array($detail)) {
            $detail = [
                'days' => 0, 'excludedSpecialEvent' => 0, 'excludedArchiveFault' => 0,
                'bias' => null, 'mape' => null, 'updated' => 0,
            ];
        }

        $res = json_decode((string)$this->ReadAttributeString('PVF_Residuals'), true);
        // Faktoren aus einer anderen Kurvenform (Übergang Build 115) nicht melden.
        if (is_array($res) && ((int)($res['shape'] ?? 1) !== $this->curveShape() || empty($res['raw']))) { $res = null; }
        $buckets = self::PVF_RESIDUAL_BUCKETS;
        $byDaylightFraction = [];
        for ($b = 0; $b < $buckets; $b++) {
            $factor = null; $n = 0;
            if (is_array($res) && isset($res['q50'][$b])) {
                $factor = $res['q50'][$b] !== null ? (float)$res['q50'][$b] : null;
                $n = (int)($res['n'][$b] ?? 0);
            }
            $byDaylightFraction[] = [
                'from'   => round($b / $buckets, 3),
                'to'     => round(($b + 1) / $buckets, 3),
                'factor' => $factor,
                'n'      => $n,
            ];
        }

        return [
            'contractVersion'      => PVF_CONTRACT_ACCURACY,
            'days'                 => $detail['days'] ?? 0,
            'excludedSpecialEvent' => $detail['excludedSpecialEvent'] ?? 0,
            'excludedArchiveFault' => $detail['excludedArchiveFault'] ?? 0,
            'bias'                 => $detail['bias'] ?? null,
            'mape'                 => $detail['mape'] ?? null,
            'updated'              => $detail['updated'] ?? 0,
            'byDaylightFraction'   => $byDaylightFraction,
            // Übergangs-Marker (additiv, Vertrag 1.1): Kurvenform der aktuellen Prognose (2 = seit
            // Build 115 Stundenmittel auf Stundenmitte; 1 = Stundenbeginn / Punktwert-Quellen), Tage,
            // aus denen byDaylightFraction gelernt wurde, und Tage mit älterer Kurvenform, die dafür
            // (nicht aber für bias/mape) ausgeschlossen sind. Solange slotLevelLegacyDays > 0 oder
            // slotLevelDays < 14, ist byDaylightFraction noch im Einschwingen.
            'curveShape'           => $this->curveShape(),
            'slotLevelDays'        => $detail['slotLevelDays'] ?? 0,
            'slotLevelLegacyDays'  => $detail['slotLevelLegacyDays'] ?? 0,
            // Vertrag 1.2: `factor` ist Ist / ROHE Modellprognose (= die Korrektur, die das Modul anwendet),
            // NICHT Ist / ausgelieferte Prognose. levelCorrectionApplied=true: die ausgelieferte p50
            // enthält diese Faktoren schon — nicht ein zweites Mal anwenden; die Restabweichung der
            // ausgelieferten Prognose steht in bias/mape.
            'factorBasis'          => 'rawModel',
            'levelCorrectionApplied' => ($this->ReadPropertyInteger('PVF_ResidualMode') === 2),
        ];
    }

    /**
     * Prognosegüte: vergleicht je vergangenem Tag (bis 14 zurück) den
     * Day-Ahead-Snapshot (Soll-kWh) mit der gemessenen PV-Erzeugung
     * (Summe der Generator-Leistungsvariablen aus dem Archiv).
     */
    private function evaluateAccuracy()
    {
        $snaps = json_decode((string)$this->ReadAttributeString('PVF_Snapshots'), true);
        if (!is_array($snaps)) { $snaps = []; }

        $gens = [];
        foreach ($this->pvGenerators() as $g) {
            if ($g['powervar'] > 0 && IPS_VariableExists($g['powervar'])) { $gens[] = $g['powervar']; }
        }
        if (count($gens) === 0) {
            $this->SetValue('PVF_Accuracy', $this->t('No measured power (PowerVar per generator) configured'));
            return;
        }

        $slots  = $this->slots();
        $errs   = [];   // Tages-kWh-Fehler (%) → Bias/MAPE
        $bucketRatios = array_fill(0, self::PVF_RESIDUAL_BUCKETS, []); // je Tagesanteil-Bucket, alle Tage (Band)
        $levelRatios  = array_fill(0, self::PVF_RESIDUAL_BUCKETS, []); // nur ruhige Tage (Pegel q50)
        $rDays  = 0;
        $noisyDays = 0; // Wechselwetter-Tage: zählen fürs Band, nicht für den Pegel
        $legacyBucket = array_fill(0, self::PVF_RESIDUAL_BUCKETS, []); // Tage vor der Umstellung → Übergangsband
        $legacyUsed = 0;
        $excluded  = 0; // Tage mit Sondereffekt (EMS_GetSpecialEvents) ausgeschlossen
        $corrupted = 0; // Tage mit Archivstörung (gehaltener Messwert) ausgeschlossen
        $legacyDays = 0; // Tage mit älterer Kurvenform: zählen für Bias/MAPE, nicht für Slot-Residuen
        $snapDays = 0;   // Snapshot-Tage der letzten 14 Tage (für die Erklärung im Leer-Fall)
        $noMeas   = 0;   // davon ohne Messwerte im Archiv (PowerVar nicht archiviert / leer)
        $lowMeas  = 0;   // davon mit gemessener Erzeugung unter 0,5 kWh

        $specialEvents = $this->fetchSpecialEvents(14);

        for ($d = 1; $d <= 14; $d++) {
            $ts   = strtotime('today -' . $d . ' days');
            $date = date('Y-m-d', $ts);
            if (!isset($snaps[$date])) { continue; }
            $snapDays++;
            if ($this->dayHasSpecialEvent($specialEvents, $ts, $this->dayEndExclusive($ts) - 1, 'pv')) { $excluded++; continue; }
            $soll = (float)($snaps[$date]['kwh'] ?? 0);
            if ($soll <= 0) { continue; }

            // Slot-Profil vorab holen (auch für den Archivstörungs-Check
            // gebraucht) — nur bei gleicher Auflösung (Snapshot vs. heute).
            $sp   = $snaps[$date]['p50'] ?? null;
            $prof = (is_array($sp) && count($sp) === $slots) ? $this->measuredProfile($ts, $slots) : null;
            if ($prof !== null && $this->hasNightArtifact($prof, $sp)) { $corrupted++; continue; }

            $ist = 0.0; $any = false;
            foreach ($gens as $vid) {
                $k = $this->measuredKwh($vid, $ts);
                if ($k !== null) { $ist += $k; $any = true; }
            }
            if (!$any) { $noMeas++; continue; }
            if ($ist < 0.5) { $lowMeas++; continue; }
            $errs[] = ($soll - $ist) / $ist * 100.0;

            if ($prof === null) { continue; }
            // Slot-Ebene (Residuen/byDaylightFraction) nur aus Snapshots mit der HEUTIGEN Kurvenform
            // UND mit roher Modellkurve (p50raw): ältere Snapshots (Stundenwert auf Stundenbeginn bzw.
            // nur die korrigierte Prognose) würden die Faktoren verfälschen und zählen nur noch für
            // Bias/Fehlerquote (Tagesenergie). Zähler = Übergangs-Marker in GetAccuracy.
            if (!$this->slotLevelEligible($snaps[$date], $slots)) {
                $legacyDays++;
                // Nur fürs Übergangsband: Ist / ausgelieferte Prognose (alte Form), nur das relative Band zählt.
                $ld = $this->daySlotRatios($sp, $prof);
                if ($ld !== null) {
                    foreach ($ld['ratios'] as $b => $vals) { foreach ($vals as $v) { $legacyBucket[$b][] = $v; } }
                    $legacyUsed++;
                }
                continue;
            }
            $day = $this->daySlotRatios($snaps[$date]['p50raw'], $prof);
            if ($day === null) { continue; }
            foreach ($day['ratios'] as $b => $vals) {
                foreach ($vals as $v) {
                    $bucketRatios[$b][] = $v;
                    if ($day['calm']) { $levelRatios[$b][] = $v; }
                }
            }
            if (!$day['calm']) { $noisyDays++; }
            $rDays++;
        }

        $this->storeResiduals($bucketRatios, $levelRatios, $rDays, $noisyDays);
        $this->updateTransitionBand($legacyBucket, $legacyUsed);

        if (count($errs) === 0) {
            // Ausschluss-Zähler auch im Leer-Fall anzeigen — sonst ist nicht
            // erkennbar, ob Snapshots fehlen oder alle Tage ausgeschlossen
            // wurden (genau das verschleierte den Wrapper-Bug, s. fetchSpecialEvents()).
            // Grund nennen (Forum cbeham, 20.10.2026: nach Wochen immer noch "Snapshots sammeln sich", obwohl sie sich sammeln,
            // nur die Messwerte fehlen): ohne Snapshots = es dauert bis morgen; mit Snapshots fehlt die Gegenseite.
            if ($snapDays === 0) {
                $txt = $this->t('No evaluable days yet: there are no snapshots yet (they are created with every recalculation for today and tomorrow and compared with the measurement on the following day)');
            } else {
                $why = [];
                if ($noMeas > 0)  { $why[] = $this->t('%d without measured values in the archive (power variable per generator archived and with values?)', $noMeas); }
                if ($lowMeas > 0) { $why[] = $this->t('%d with measured generation below 0.5 kWh', $lowMeas); }
                $txt = (count($why) > 0)
                    ? $this->t('No evaluable days yet: %d snapshot day(s) present, of which %s', $snapDays, implode(', ', $why))
                    : $this->t('No evaluable days yet: %d snapshot day(s) present', $snapDays);
            }
            if ($excluded > 0) {
                $txt .= ' | ' . $this->t('%d day(s) excluded due to a special effect', $excluded);
            }
            if ($corrupted > 0) {
                $txt .= ' | ' . $this->t('%d day(s) excluded due to an archive fault', $corrupted);
            }
            $this->SetValue('PVF_Accuracy', $txt);
            $this->WriteAttributeString('PVF_AccuracyDetail', json_encode([
                'days' => 0, 'excludedSpecialEvent' => $excluded, 'excludedArchiveFault' => $corrupted,
                'bias' => null, 'mape' => null, 'updated' => time(),
                'slotLevelDays' => $rDays, 'slotLevelLegacyDays' => $legacyDays, 'slotLevelNoisyDays' => $noisyDays,
            ]));
            return;
        }
        $bias = array_sum($errs) / count($errs);
        $mape = array_sum(array_map('abs', $errs)) / count($errs);
        $this->SetValue('PVF_ErrorMAPE', round($mape, 1));
        $this->WriteAttributeString('PVF_AccuracyDetail', json_encode([
            'days' => count($errs), 'excludedSpecialEvent' => $excluded, 'excludedArchiveFault' => $corrupted,
            'bias' => round($bias, 2), 'mape' => round($mape, 2), 'updated' => time(),
            'slotLevelDays' => $rDays, 'slotLevelLegacyDays' => $legacyDays, 'slotLevelNoisyDays' => $noisyDays,
        ]));
        $txt = $this->t('%d days: bias %+.1f %% · |Ø error| %.1f %%', count($errs), $bias, $mape);
        $res = json_decode((string)$this->ReadAttributeString('PVF_Residuals'), true);
        if (is_array($res) && isset($res['q50']) && is_array($res['q50'])) {
            $q50vals = array_filter($res['q50'], function ($v) { return $v !== null; });
            if (count($q50vals) > 0) {
                $txt .= ' | ' . $this->t('time-of-day residuals ×%.2f…×%.2f (%d days)',
                    min($q50vals), max($q50vals), $res['days']);
            }
        }
        if ($excluded > 0) {
            $txt .= ' | ' . $this->t('%d day(s) excluded due to a special effect', $excluded);
        }
        if ($corrupted > 0) {
            $txt .= ' | ' . $this->t('%d day(s) excluded due to an archive fault', $corrupted);
        }
        if ($noisyDays > 0) {
            $txt .= ' | ' . $this->t('%d changeable-weather day(s) do not count for the level', $noisyDays);
        }
        if ($this->specialEventsVersionMismatch !== null) {
            $txt .= ' | ' . $this->t('⚠️ EMS contract %s not supported (major %d expected) — special-effect exclusion inactive, check for a module update',
                $this->specialEventsVersionMismatch, self::EMS_EVENTS_MAJOR);
        }
        $hint = $this->learningHint($bias, count($errs));
        if ($hint !== '') { $txt .= ' | ' . $hint; }
        $this->SetValue('PVF_Accuracy', $txt);
        $this->log(PVF_LOG_BASIC, $this->t('Forecast accuracy (%d days): bias %+.1f %%, MAPE %.1f %%', count($errs), $bias, $mape));
    }

    /**
     * Erster/letzter Slot-Index mit Soll ≥ Schwelle ("Tageslicht" laut
     * Modell). Null, wenn kein Slot die Schwelle erreicht (z.B. bei einem
     * Datenfehler, der $maxS<=0 nicht schon vorher abgefangen hat).
     */
    private function daylightBounds(array $sp, float $floor): ?array
    {
        $n = count($sp);
        $start = null; $end = null;
        for ($i = 0; $i < $n; $i++) {
            if ((float)$sp[$i] >= $floor) {
                if ($start === null) { $start = $i; }
                $end = $i;
            }
        }
        return ($start === null) ? null : [$start, $end];
    }

    /**
     * Position eines Slots innerhalb der Tageslicht-Spanne, 0 (Sonnenaufgang)
     * … 1 (Sonnenuntergang). Bewusst auf dieser Achse statt der Uhrzeit,
     * s. PVF_RESIDUAL_BUCKETS.
     */
    private function daylightFraction(int $i, int $start, int $end): float
    {
        if ($end <= $start) { return 0.0; }
        return max(0.0, min(1.0, ($i - $start) / ($end - $start)));
    }

    /** Tagesanteil (0..1) auf einen Bucket-Index abbilden. */
    private function residualBucket(float $frac): int
    {
        $b = (int)floor($frac * self::PVF_RESIDUAL_BUCKETS);
        return max(0, min(self::PVF_RESIDUAL_BUCKETS - 1, $b));
    }

    /**
     * Darf dieser Snapshot in die Slot-Ebene (Residuen, byDaylightFraction) einfließen?
     * Nur mit heutiger Kurvenform UND roher Modellkurve (p50raw) in passender Auflösung.
     */
    private function slotLevelEligible(array $snap, int $slots): bool
    {
        return (int)($snap['shape'] ?? 1) === $this->curveShape()
            && isset($snap['p50raw']) && is_array($snap['p50raw']) && count($snap['p50raw']) === $slots;
    }

    /**
     * Slot-Verhältnisse Ist/ROH-Prognose eines Tages je Tagesanteil-Bucket. Gelernt wird bewusst
     * gegen die rohe Modellprognose (nicht gegen die ausgelieferte, bereits korrigierte): nur so
     * konvergiert der Pegel gegen den vollen Ausgleich statt gegen die Wurzel des Fehlers.
     * 'calm' = false bei Wechselwetter (Interquartilsverhältnis p75/p25 der Slot-Verhältnisse über
     * PVF_RESIDUAL_MAX_DAY_SPREAD): solche Tage sagen nichts über einen systematischen Pegelfehler.
     * Rückgabe null, wenn nichts auswertbar ist.
     */
    private function daySlotRatios(array $rawSp, array $prof): ?array
    {
        $maxS = (count($rawSp) > 0) ? max($rawSp) : 0.0;
        if ($maxS <= 0) { return null; }
        // Schwelle blendet Nacht/Dämmerung aus — dort ist Soll≈0 und das
        // Verhältnis Ist/Soll wäre bedeutungslos bzw. explodiert.
        $floor  = max(10.0, 0.02 * $maxS);
        $bounds = $this->daylightBounds($rawSp, $floor);
        if ($bounds === null) { return null; }
        [$dStart, $dEnd] = $bounds;
        $ratios = array_fill(0, self::PVF_RESIDUAL_BUCKETS, []);
        $all = [];
        for ($i = $dStart; $i <= $dEnd; $i++) {
            $s = (float)$rawSp[$i];
            if ($s < $floor) { continue; }
            $r = ((float)$prof[$i]) / $s;
            $ratios[$this->residualBucket($this->daylightFraction($i, $dStart, $dEnd))][] = $r;
            $all[] = $r;
        }
        if (count($all) === 0) { return null; }
        $calm = true;
        if (count($all) >= 8) {
            sort($all);
            $q25 = $this->percentileOf($all, 0.25); $q75 = $this->percentileOf($all, 0.75);
            $calm = ($q25 <= 0.0) ? false : (($q75 / $q25) <= self::PVF_RESIDUAL_MAX_DAY_SPREAD);
        }
        return ['ratios' => $ratios, 'calm' => $calm];
    }

    /**
     * Empirische Quantile der Prognosefehler (Ist/ROH-Soll je Slot) ablegen —
     * je Tagesanteil-Bucket getrennt (Tagesgang-Profil, s. PVF_RESIDUAL_BUCKETS),
     * damit sich morgens/abends unterschiedliche Fehler nicht gegenseitig
     * weg mitteln. Ein Bucket ohne ausreichende Datenbasis bleibt null
     * (Passthrough, keine Korrektur) statt aus zu wenigen Werten zu raten.
     * q10/q90 (Band) aus allen Tagen, q50 (Pegel) aus den ruhigen Tagen, sofern
     * dort genug Werte vorliegen, sonst aus allen; q50 zusätzlich auf
     * PVF_LEVEL_MIN…MAX begrenzt.
     */
    private function storeResiduals(array $bucketRatios, array $levelRatios, int $days, int $noisyDays = 0)
    {
        $total = 0;
        foreach ($bucketRatios as $arr) { $total += count($arr); }
        if ($days < 3 || $total < 50) {
            $this->WriteAttributeString('PVF_Residuals', '');
            return;
        }
        $q10 = []; $q50 = []; $q90 = []; $n = [];
        for ($b = 0; $b < self::PVF_RESIDUAL_BUCKETS; $b++) {
            $arr = $bucketRatios[$b] ?? [];
            $n[$b] = count($arr);
            if ($n[$b] < self::PVF_RESIDUAL_MIN_PER_BUCKET) {
                $q10[$b] = null; $q50[$b] = null; $q90[$b] = null;
                continue;
            }
            sort($arr);
            $lvl = $levelRatios[$b] ?? [];
            if (count($lvl) >= self::PVF_RESIDUAL_MIN_PER_BUCKET) { sort($lvl); } else { $lvl = $arr; }
            $q10[$b] = round($this->clampFactor($this->percentileOf($arr, 0.10)), 3);
            $q50[$b] = round(max(self::PVF_LEVEL_MIN, min(self::PVF_LEVEL_MAX, $this->percentileOf($lvl, 0.50))), 3);
            $q90[$b] = round($this->clampFactor($this->percentileOf($arr, 0.90)), 3);
        }
        $this->WriteAttributeString('PVF_Residuals', json_encode([
            'buckets' => self::PVF_RESIDUAL_BUCKETS, 'q10' => $q10, 'q50' => $q50, 'q90' => $q90, 'n' => $n,
            'days' => $days, 'noisyDays' => $noisyDays, 'samples' => $total, 'updated' => time(),
            'shape' => $this->curveShape(), 'raw' => true, // 'raw' = gegen die ROHE Prognose gelernt (ab Build 116)
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
     * Band (und optional Pegel) aus den gemessenen Prognosefehlern — je
     * Tagesanteil-Bucket ein eigener Faktor (Tagesgang-Profil), auf die
     * Tageslicht-Spanne DIESER Prognose abgebildet (eigener Sonnenaufgang/
     * -untergang je Tag/Jahreszeit). Besonders relevant bei Open-Meteo/
     * Forecast.Solar, die p10=p50=p90 liefern — dort entsteht so überhaupt
     * erst ein Unsicherheitsband. Slots ohne Bucket-Datenbasis bleiben
     * unverändert (Passthrough).
     */
    private function applyResiduals(array $p10, array $p50, array $p90): array
    {
        $mode = $this->ReadPropertyInteger('PVF_ResidualMode');
        if ($mode === 0) { return [$p10, $p50, $p90]; }

        $r = json_decode((string)$this->ReadAttributeString('PVF_Residuals'), true);
        // Residuen aus einer anderen Kurvenform (vor Build 115: Stundenwert auf Stundenbeginn) oder ohne
        // 'raw' (gegen die bereits KORRIGIERTE Prognose gelernt, Fehler bis Build 115) passen nicht zur
        // heutigen Rohkurve — nicht anwenden. Statt komplett ohne Korrektur zu liefern (p10 = p50 = p90),
        // bleibt in dieser Übergangsphase das relative Band aus den Tagen davor erhalten, mit Pegel 1,0.
        if (!$this->residualsUsable($r)) {
            return $this->applyTransitionBand($p10, $p50, $p90);
        }
        $q10arr = $r['q10']; $q50arr = $r['q50']; $q90arr = $r['q90'];

        $maxS = (count($p50) > 0) ? max($p50) : 0.0;
        if ($maxS <= 0) { return [$p10, $p50, $p90]; }
        $floor  = max(10.0, 0.02 * $maxS);
        $bounds = $this->daylightBounds($p50, $floor);
        if ($bounds === null) { return [$p10, $p50, $p90]; }
        [$dStart, $dEnd] = $bounds;

        $nP10 = $p10; $nP50 = $p50; $nP90 = $p90;
        foreach ($p50 as $i => $v) {
            if ($i < $dStart || $i > $dEnd) { continue; }
            $b = $this->residualBucket($this->daylightFraction($i, $dStart, $dEnd));
            $q10 = $q10arr[$b] ?? null; $q50 = $q50arr[$b] ?? null; $q90 = $q90arr[$b] ?? null;
            if ($q10 === null || $q50 === null || $q90 === null || (float)$q50 <= 0) { continue; }
            $q10 = (float)$q10; $q50 = (float)$q50; $q90 = (float)$q90;

            if ($mode === 1) {
                $lo = min(1.0, $q10 / $q50); $hi = max(1.0, $q90 / $q50);
                $nP10[$i] = $v * $lo; $nP90[$i] = $v * $hi;
                continue;
            }
            $nP50[$i] = $v * $q50;
            // q50 ist auf 0,5..2,0 begrenzt, q10/q90 auf 0,3..3,0 — ohne Nachziehen könnte P10 über p50
            // (oder P90 darunter) liegen; Reihenfolge P10 <= p50 <= P90 ist Vertragsannahme der Konsumenten.
            $nP10[$i] = min($v * $q10, $nP50[$i]);
            $nP90[$i] = max($v * $q90, $nP50[$i]);
        }
        return [$nP10, $nP50, $nP90];
    }

    /**
     * Plausibilitätsdeckel: kein Slot (p10/p50/p90) über installierter Modulleistung x 1000 W x
     * PVF_PEAK_CAP_FACTOR. Fund 20.09.2026: Das relative Band (p90 = ca. 3 x p50) ergab mittags 16,6 kW bei
     * 9,18 kWp — physikalisch unmöglich. Gilt für alle Quellen (auch Solcast mit eigenem Band); Cap je Wert
     * (min) erhält die Reihenfolge P10 <= p50 <= P90. Ohne konfigurierte kWp kein Deckel.
     */
    private function applyPeakCap(array $p10, array $p50, array $p90): array
    {
        $kwp = 0.0;
        foreach ($this->pvGenerators() as $g) { $kwp += max(0.0, (float)$g['kwp']); }
        if ($kwp <= 0.0) { return [$p10, $p50, $p90]; }
        $cap = $kwp * 1000.0 * self::PVF_PEAK_CAP_FACTOR;
        $c = function (array $a) use ($cap) { foreach ($a as $i => $v) { if ($v > $cap) { $a[$i] = $cap; } } return $a; };
        return [$c($p10), $c($p50), $c($p90)];
    }

    /** Sind die gespeicherten Residuen zur heutigen Rohkurve passend (Struktur, Kurvenform, 'raw')? */
    private function residualsUsable($r): bool
    {
        return is_array($r) && isset($r['q10'], $r['q50'], $r['q90']) && is_array($r['q10'])
            && (int)($r['shape'] ?? 1) === $this->curveShape() && !empty($r['raw']);
    }

    /**
     * Übergangsphase (Residuen lernen neu): nur das RELATIVE Band anwenden (P10 = p50·lo, P90 = p50·hi,
     * lo ≤ 1 ≤ hi), p50 bleibt die Rohkurve. Fund (EMS, 20.09.2026): ohne das lieferte PVF ca. 5-7 Tage
     * p10 = p50 = p90, für die konservative Planung (B1) also kein Band.
     */
    private function applyTransitionBand(array $p10, array $p50, array $p90): array
    {
        $band = json_decode((string)$this->ReadAttributeString('PVF_TransitionBand'), true);
        if (!is_array($band) || !isset($band['lo'], $band['hi']) || !is_array($band['lo'])) {
            return [$p10, $p50, $p90];
        }
        $maxS = (count($p50) > 0) ? max($p50) : 0.0;
        if ($maxS <= 0) { return [$p10, $p50, $p90]; }
        $bounds = $this->daylightBounds($p50, max(10.0, 0.02 * $maxS));
        if ($bounds === null) { return [$p10, $p50, $p90]; }
        [$dStart, $dEnd] = $bounds;
        $nP10 = $p10; $nP90 = $p90;
        foreach ($p50 as $i => $v) {
            if ($i < $dStart || $i > $dEnd) { continue; }
            $b  = $this->residualBucket($this->daylightFraction($i, $dStart, $dEnd));
            $lo = $band['lo'][$b] ?? null; $hi = $band['hi'][$b] ?? null;
            if ($lo === null || $hi === null) { continue; }
            // Quellen mit eigenem Band (Solcast: echtes P10/P90) behalten es.
            if ($p10[$i] != $v || $p90[$i] != $v) { continue; }
            $nP10[$i] = $v * min(1.0, (float)$lo);   // Reihenfolge P10 <= p50 <= P90 garantiert
            $nP90[$i] = $v * max(1.0, (float)$hi);
        }
        return [$nP10, $p50, $nP90];
    }

    /**
     * Relatives Band je Bucket aus Slot-Verhältnissen (Ist / ausgelieferte Prognose der Tage vor der
     * Umstellung). Nur das Verhältnis der Quantile zum Median zählt (lo = q10/q50 ≤ 1, hi = q90/q50 ≥ 1),
     * der ungeeignete alte Pegel fällt heraus. Bucket ohne genug Werte bleibt null; null ohne Datenbasis.
     */
    private function transitionBand(array $bucketRatios, int $days): ?array
    {
        $total = 0;
        foreach ($bucketRatios as $arr) { $total += count($arr); }
        if ($days < 3 || $total < 50) { return null; }
        $lo = []; $hi = []; $any = false;
        for ($b = 0; $b < self::PVF_RESIDUAL_BUCKETS; $b++) {
            $arr = $bucketRatios[$b] ?? [];
            if (count($arr) < self::PVF_RESIDUAL_MIN_PER_BUCKET) { $lo[$b] = null; $hi[$b] = null; continue; }
            sort($arr);
            $q50 = $this->percentileOf($arr, 0.50);
            if ($q50 <= 0.0) { $lo[$b] = null; $hi[$b] = null; continue; }
            $lo[$b] = round(min(1.0, $this->clampFactor($this->percentileOf($arr, 0.10) / $q50)), 3);
            $hi[$b] = round(max(1.0, $this->clampFactor($this->percentileOf($arr, 0.90) / $q50)), 3);
            $any = true;
        }
        return $any ? ['lo' => $lo, 'hi' => $hi, 'days' => $days, 'samples' => $total, 'updated' => time()] : null;
    }

    /**
     * Übergangsband pflegen: sobald brauchbare (neu gelernte) Residuen vorliegen, wird es geleert; solange
     * nicht, wird es aus den Vor-Umstellungs-Tagen berechnet und aufgehoben (bleibt bei zu wenig Daten
     * stehen statt gelöscht zu werden).
     */
    private function updateTransitionBand(array $legacyBucketRatios, int $legacyDays)
    {
        $res = json_decode((string)$this->ReadAttributeString('PVF_Residuals'), true);
        if ($this->residualsUsable($res)) {
            $this->WriteAttributeString('PVF_TransitionBand', '');
            return;
        }
        $band = $this->transitionBand($legacyBucketRatios, $legacyDays);
        if ($band !== null) {
            $this->WriteAttributeString('PVF_TransitionBand', json_encode($band));
        }
    }

    /**
     * Speichert je Tag genau einen Prognose-Snapshot (Soll): heute + morgen,
     * jeweils nur wenn für das Datum noch keiner existiert → jeder Tag behält
     * den frühesten (Day-Ahead-)Stand. Auf die letzten 14 Tage begrenzt.
     */
    private function saveSnapshot(array $fcs)
    {
        $snaps = json_decode((string)$this->ReadAttributeString('PVF_Snapshots'), true);
        if (!is_array($snaps)) { $snaps = []; }

        foreach ([0, 1] as $offset) {
            if (!isset($fcs[$offset])) { continue; }
            $fc   = $fcs[$offset];
            $date = date('Y-m-d', strtotime('today +' . $offset . ' days'));
            if (isset($snaps[$date])) { continue; }
            if (array_sum($fc['p50'] ?? []) <= 0) { continue; }
            $snaps[$date] = [
                'slots'      => $fc['slots'],
                'resolution' => $fc['resolution'],
                'p50'        => $fc['p50'],
                'kwh'        => $fc['kwh'],
                'shape'      => $this->curveShape(),
            ];
            if (isset($this->lastRawP50[$offset]) && count($this->lastRawP50[$offset]) === count($fc['p50'])) {
                $snaps[$date]['p50raw'] = array_map(function ($x) { return round($x, 1); }, $this->lastRawP50[$offset]);
            }
        }

        krsort($snaps);
        $snaps = array_slice($snaps, 0, 14, true);
        $this->WriteAttributeString('PVF_Snapshots', json_encode($snaps));
    }

    /**
     * Speichert für JEDEN Checkpoint aus PVF_INTRADAY_CHECKPOINTS, der
     * heute bereits erreicht und noch nicht erfasst ist, den aktuellen
     * "heute"-Prognosestand (offset 0) — unabhängig vom Day-Ahead-Snapshot
     * in PVF_Snapshots, der unberührt bleibt. Läuft der Rebuild-Intervall
     * gröber als der Checkpoint-Abstand, können mehrere Checkpoints in
     * einem Aufruf mit demselben (dann bereits etwas späteren) Stand
     * gefüllt werden — informativer Bestwert statt gar keiner Erfassung,
     * kein Fehler. Auf die letzten 14 Tage begrenzt, analog saveSnapshot().
     */
    private function saveIntradaySnapshot(array $fcs)
    {
        if (!isset($fcs[0])) { return; }
        $fc = $fcs[0];
        if (array_sum($fc['p50'] ?? []) <= 0) { return; }

        $date = date('Y-m-d');
        $now  = date('H:i');

        $store = json_decode((string)$this->ReadAttributeString('PVF_IntradaySnapshots'), true);
        if (!is_array($store)) { $store = []; }
        if (!isset($store[$date])) { $store[$date] = []; }

        $changed = false;
        foreach (self::PVF_INTRADAY_CHECKPOINTS as $cp) {
            if ($now < $cp || isset($store[$date][$cp])) { continue; }
            $store[$date][$cp] = [
                'slots'      => $fc['slots'],
                'resolution' => $fc['resolution'],
                'p50'        => $fc['p50'],
                'kwh'        => $fc['kwh'],
                'shape'      => $this->curveShape(),
            ];
            $changed = true;
        }
        if (!$changed) { return; }

        krsort($store);
        $store = array_slice($store, 0, 14, true);
        $this->WriteAttributeString('PVF_IntradaySnapshots', json_encode($store));
    }

    // ----------------------------------------------------------------
    //  Modellaufbau (Summe der Generatoren)
    // ----------------------------------------------------------------

    /**
     * Baut das Tagesmodell für heute bis Tag PVF_MAX_OFFSET:
     * [offset => [hour => ['p10','p50','p90']]] in W, Summe aller Generatoren.
     * Rückgabe null, wenn keine Quelle Daten liefert.
     */
    private function buildModel()
    {
        $gens = $this->pvGenerators();
        if (count($gens) === 0) { return null; }
        $src = $this->ReadPropertyInteger('PVF_Source');

        $model = [];
        for ($o = 0; $o <= PVF_MAX_OFFSET; $o++) {
            $model[$o] = [];
            for ($h = 0; $h < 24; $h++) { $model[$o][$h] = ['p10' => 0.0, 'p50' => 0.0, 'p90' => 0.0]; }
        }

        $gotAny = false;
        $failed = 0;
        $this->solcastIssues = [];
        foreach ($gens as $g) {
            switch ($src) {
                case PVF_SRC_FORECASTSOLAR: $perDay = $this->fetchForecastSolar($g); break;
                case PVF_SRC_SOLCAST:       $perDay = $this->fetchSolcast($g);       break;
                case PVF_SRC_OPENMETEO:
                default:                    $perDay = $this->fetchOpenMeteo($g);     break;
            }
            if ($perDay === null) {
                // Solcast ohne API-Schlüssel/Resource-ID = nicht konfiguriert, kein Ausfall.
                $unconfigured = ($src === PVF_SRC_SOLCAST && (trim($g['solcast']) === '' || $this->solcastKey() === ''));
                if (!$unconfigured) { $failed++; }
                continue;
            }

            $factor = $this->generatorFactor($g, $src);
            for ($o = 0; $o <= PVF_MAX_OFFSET; $o++) {
                if (!isset($perDay[$o])) { continue; }
                for ($h = 0; $h < 24; $h++) {
                    $cell = $perDay[$o][$h];
                    $model[$o][$h]['p10'] += $cell['p10'] * $factor;
                    $model[$o][$h]['p50'] += $cell['p50'] * $factor;
                    $model[$o][$h]['p90'] += $cell['p90'] * $factor;
                }
            }
            $gotAny = true;
        }

        // Alles oder nichts: Klappt der Abruf nur für einen Teil der Generatoren
        // (typisch bei wackligem Netz — jeder Generator ist ein eigener Abruf),
        // wäre die Summe stillschweigend zu niedrig, bei 3 Generatoren z. B. nur
        // ein Fünftel der Anlage (Fund: EMS, 19.09.2026, Spitze 1,2 statt 6,6 kW
        // bei klarem Himmel). Lieber verwerfen — Rebuild()/GetForecast() greifen
        // dann auf die zuletzt gültige, gespeicherte Prognose zurück.
        if ($failed > 0) {
            $this->log(PVF_LOG_BASIC, $this->t(
                'Forecast discarded: call failed for %d of %d generators — a partial sum would be silently too low',
                $failed, count($gens)
            ));
            return null;
        }

        return $gotAny ? $model : null;
    }

    private function pvGenerators(): array
    {
        $out  = [];
        $list = json_decode((string)$this->ReadPropertyString('PVGenerators'), true);
        if (is_array($list)) {
            foreach ($list as $row) {
                $out[] = [
                    'name'     => (string)($row['Name'] ?? ''),
                    'tilt'     => (float)($row['Tilt'] ?? 30),
                    'az'       => (float)($row['Azimuth'] ?? 0),
                    'kwp'      => (float)($row['kWp'] ?? 0),
                    'powervar' => (int)($row['PowerVar'] ?? 0),
                    'solcast'  => (string)($row['SolcastId'] ?? ''),
                    'factor'   => (float)($row['Factor'] ?? 1.0),
                    // Selbstkalibrierung je Generator (fehlt = an → rückwärtskompatibel).
                    'calibrate'=> (bool)($row['Calibrate'] ?? true),
                    // Vorzeichen der gemessenen Leistung umkehren (fehlt = aus → rückwärtskompatibel): manche
                    // Wechselrichter/Zähler zählen die Erzeugung negativ (Forum power1625, 19.10.2026).
                    'invert'   => (bool)($row['Invert'] ?? false),
                    // Modul-Metadaten (nur für externe Nutzung, z.B. InverterHub).
                    // Fläche je Modul aus Länge × Breite (mm → m²); Fallback: früher
                    // direkt eingetragene Fläche (ModuleArea, ältere Beta-Konfig).
                    'modules'    => (int)($row['Modules'] ?? 0),
                    'modulelength'=> (float)($row['ModuleLength'] ?? 0),
                    'modulewidth' => (float)($row['ModuleWidth'] ?? 0),
                    'modulearea'  => $this->moduleAreaM2($row),
                ];
            }
        }
        return $out;
    }

    /** Fläche eines Moduls in m² aus Länge × Breite (mm); Fallback ModuleArea (m²). */
    private function moduleAreaM2(array $row): float
    {
        $len = (float)($row['ModuleLength'] ?? 0);
        $wid = (float)($row['ModuleWidth'] ?? 0);
        if ($len > 0 && $wid > 0) {
            return ($len * $wid) / 1000000.0;
        }
        return (float)($row['ModuleArea'] ?? 0); // ältere Beta-Konfig
    }

    /** Wirksamer Korrekturfaktor: manuell × (optional) Selbstkalibrierung. */
    private function generatorFactor(array $g, int $src): float
    {
        $f = ($g['factor'] > 0) ? $g['factor'] : 1.0;
        // Kalibrierung nur wenn Master-Schalter AN und für diesen Generator aktiviert.
        // Abgeregelte Generatoren (z.B. DC-MPPT mit Strom-/Spannungslimit) hier abschalten
        // → sie liefern das reine Wetter-Potenzial statt der gedrosselten Messung.
        if ($src === PVF_SRC_OPENMETEO && $this->ReadPropertyBoolean('PVF_Calibrate')
            && $g['calibrate'] && $g['powervar'] > 0) {
            $cal = $this->calibrationOrCached($g, $this->calibrate($g));
            if ($cal !== null) { $f *= $cal; }
        }
        return $f;
    }

    /**
     * Hinweise für die Status-Zeile: Ersatzwerte im aktuellen Rebuild (Kalibrierfaktor aus dem Cache) und
     * die Lernphase (Übergangsband aktiv). Leer, wenn alles normal ist.
     */
    /**
     * Hinweis auf die Lernkorrektur, wenn die Prognose deutlich daneben liegt und nichts davon gelernt wird
     * (Forum lungo, 08.11.2026: Bias +73 % bei ausgeschalteter Selbstkalibrierung und Pegelkorrektur — beides ist
     * standardmäßig aus). Leer, wenn zu wenige Tage vorliegen, die Abweichung klein ist oder die Korrektur schon läuft.
     * Die Selbstkalibrierung wirkt nur mit Open-Meteo; bei anderen Quellen bleibt allein die Pegelkorrektur.
     */
    private function learningHint(float $bias, int $days): string
    {
        if ($days < 7 || abs($bias) < 30.0) { return ''; }
        $calibActive = ($this->ReadPropertyInteger('PVF_Source') === PVF_SRC_OPENMETEO) && $this->ReadPropertyBoolean('PVF_Calibrate');
        $levelActive = ($this->ReadPropertyInteger('PVF_ResidualMode') === 2);
        $needCalib = !$calibActive && $this->ReadPropertyInteger('PVF_Source') === PVF_SRC_OPENMETEO;
        $needLevel = !$levelActive;
        if (!$needCalib && !$needLevel) { return ''; }
        $pct = (int)round(abs($bias));
        $dir = $bias > 0 ? $this->t('higher') : $this->t('lower');
        if ($needCalib && $needLevel) {
            return $this->t('💡 The forecast is on average %d %% %s than measured. It does not learn from this yet: in the form, switch on the self-calibration and set the uncertainty band to “band + level correction”.', $pct, $dir);
        }
        return $needCalib
            ? $this->t('💡 The forecast is on average %d %% %s than measured. It does not learn from this yet: in the form, switch on the self-calibration.', $pct, $dir)
            : $this->t('💡 The forecast is on average %d %% %s than measured. It does not learn from this yet: in the form, set the uncertainty band to “band + level correction”.', $pct, $dir);
    }

    /**
     * Ursachen der Solcast-Abrufprobleme dieses Rebuilds als lesbarer Text (leer ohne Probleme). Ersetzt für die
     * Quelle Solcast die pauschale Meldung "API/Netzwerk?": Schlüssel fehlt/abgelehnt, Resource-ID fehlt/unbekannt,
     * Tageskontingent, sonstiger HTTP-Fehler, keine Verbindung. Je Ursache und Generator höchstens einmal.
     */
    private function solcastIssueText(): string
    {
        $parts = []; $seen = [];
        foreach ($this->solcastIssues as $i) {
            $gen = ($i['gen'] !== '') ? $this->t('“%s”', $i['gen']) : $this->t('a generator');
            switch ($i['kind']) {
                case 'nokey':
                    $t = $this->t('⛔ Solcast: no API key stored'); $k = 'nokey'; break;
                case 'norid':
                    $t = $this->t('⛔ Solcast: no resource ID is entered for %s (“Solcast ID” column of the generator list; help: button “Where do I find the Solcast ID?”)', $gen); $k = 'norid' . $i['gen']; break;
                case 'auth':
                    $t = $this->t('⛔ Solcast: key rejected (HTTP %s) – please check the API key', $i['http']); $k = 'auth'; break;
                case 'notfound':
                    $t = $this->t('⛔ Solcast: resource ID “%s” for %s not found (HTTP 404) – please check the Solcast ID', $i['rid'], $gen); $k = 'nf' . $i['rid']; break;
                case 'quota':
                    $t = $this->t('⚠️ Solcast: daily quota used up (HTTP 429), next attempt in 2 hours'); $k = 'quota'; break;
                case 'network':
                    $t = $this->t('⚠️ Solcast: no connection (network, DNS or timeout)'); $k = 'net'; break;
                case 'budget':
                    $t = $this->t('ℹ️ Solcast: daily budget of calls reached, no further call until tomorrow'); $k = 'budget'; break;
                default:
                    $t = $this->t('⚠️ Solcast: call for %s failed (HTTP %s)', $gen, $i['http']); $k = 'http' . $i['http'] . $i['gen'];
            }
            if (isset($seen[$k])) { continue; }
            $seen[$k] = true;
            if (!empty($i['stale'])) { $t .= ' ' . $this->t('(the last valid response continues to be used)'); }
            $parts[] = $t;
        }
        return implode('; ', $parts);
    }

    private function statusNotices(): string
    {
        $out = '';
        $solcast = $this->solcastIssueText();
        if ($solcast !== '') { $out .= ' | ' . $solcast; }
        if (count($this->calibNotes) > 0) {
            $names = array_map(function ($n) { return $n['name']; }, $this->calibNotes);
            $oldest = min(array_map(function ($n) { return $n['ts']; }, $this->calibNotes));
            $out .= ' | ' . $this->t('⚠️ Calibration not available — substitute value (last factor from %s) for %s',
                date('d.m. H:i', $oldest), implode(', ', $names));
        }
        if (!$this->residualsUsable(json_decode((string)$this->ReadAttributeString('PVF_Residuals'), true))
            && is_array(json_decode((string)$this->ReadAttributeString('PVF_TransitionBand'), true))) {
            $out .= ' | ' . $this->t('ℹ️ Correction is learning anew — uncertainty band from the days before');
        }
        $d = json_decode((string)$this->ReadAttributeString('PVF_AccuracyDetail'), true);
        if (is_array($d) && isset($d['bias'], $d['days'])) {
            $hint = $this->learningHint((float)$d['bias'], (int)$d['days']);
            if ($hint !== '') { $out .= ' | ' . $hint; }
        }
        return $out;
    }

    /**
     * Fällt die Kalibrier-Abfrage aus (Timeout gegen die Wetter-API, zu wenig Daten), wird bisher still
     * Faktor 1,0 genommen — das Rohmodell sprang dadurch je nach Netz zwischen kalibriert und
     * unkalibriert (Fund 20.09.2026: ein Timeout bei der 21-Tage-Reihe im Rebuild). Jetzt: letzter guter
     * Faktor je Generator (PowerVar) bis 3 Tage alt; gelingt die Kalibrierung, wird er aufgefrischt.
     * $fresh = Ergebnis von calibrate() (null bei Ausfall). Rückgabe null = gar keine Kalibrierung.
     */
    private function calibrationOrCached(array $g, ?float $fresh): ?float
    {
        $cache = json_decode((string)$this->ReadAttributeString('PVF_CalibCache'), true);
        if (!is_array($cache)) { $cache = []; }
        $key = (string)$g['powervar'];
        if ($fresh !== null) {
            $cache[$key] = ['f' => round($fresh, 4), 'ts' => time()];
            $this->WriteAttributeString('PVF_CalibCache', json_encode($cache));
            return $fresh;
        }
        if (isset($cache[$key]['f'], $cache[$key]['ts']) && (time() - (int)$cache[$key]['ts']) <= 3 * 86400) {
            $name = $g['name'] !== '' ? $g['name'] : ('#' . $g['powervar']);
            $this->log(PVF_LOG_BASIC, $this->t('Calibration for %s not available — last factor %.3f from %s continues to be used',
                $name, (float)$cache[$key]['f'], date('d.m. H:i', (int)$cache[$key]['ts'])));
            $this->calibNotes[] = ['name' => $name, 'ts' => (int)$cache[$key]['ts']];
            return (float)$cache[$key]['f'];
        }
        return null;
    }

    // ----------------------------------------------------------------
    //  Quelle: Open-Meteo (geneigte Einstrahlung → Leistung)
    // ----------------------------------------------------------------

    // protected = Testnaht des Prüfstands (Abruf-Ausfälle simulieren), sonst unverändert
    protected function fetchOpenMeteo(array $g, int $pastDays = 0)
    {
        $lat = $this->ReadPropertyFloat('PVF_Latitude');
        $lon = $this->ReadPropertyFloat('PVF_Longitude');
        $url = sprintf(
            'https://api.open-meteo.com/v1/forecast?latitude=%s&longitude=%s'
            . '&hourly=global_tilted_irradiance,temperature_2m&tilt=%s&azimuth=%s'
            . '&forecast_days=%d&past_days=%d&timezone=auto&timeformat=unixtime',
            rawurlencode((string)$lat), rawurlencode((string)$lon),
            rawurlencode((string)$g['tilt']), rawurlencode((string)$g['az']),
            PVF_MAX_OFFSET + 1, $pastDays
        );
        $j = $this->httpGetJson($url);
        if ($j === null || !isset($j['hourly']['time'])) { return null; }

        $time = $j['hourly']['time'];
        $gti  = $j['hourly']['global_tilted_irradiance'] ?? [];
        $temp = $j['hourly']['temperature_2m'] ?? [];
        $pr   = $this->ReadPropertyFloat('PVF_PR');
        $tc   = $this->ReadPropertyFloat('PVF_TempCoeff');
        $kwpW = $g['kwp'] * 1000.0;

        // Leistung je Datum/Stunde berechnen (W).
        $byDate = [];
        $n = count($time);
        for ($i = 0; $i < $n; $i++) {
            // Open-Meteo: Strahlung = Mittel der VORANGEHENDEN Stunde →
            // dem Stundenbeginn zuordnen (deckt sich mit dem IPS-Stundenaggregat).
            list($date, $hour) = $this->omSlot((int)$time[$i]);
            $irr  = (float)($gti[$i] ?? 0);
            $ta   = (float)($temp[$i] ?? 20);
            $derate = 1.0;
            if ($tc != 0.0 && $irr > 0) {
                $tcell  = $ta + $irr / 800.0 * 20.0;       // NOCT-Näherung
                $derate = 1.0 + ($tc / 100.0) * ($tcell - 25.0);
            }
            $w = $kwpW * ($irr / 1000.0) * $pr * max(0.0, $derate);
            if (!isset($byDate[$date])) { $byDate[$date] = array_fill(0, 24, 0.0); }
            $byDate[$date][$hour] = $w;
        }
        return $this->mapOffsets($byDate);
    }

    /**
     * Open-Meteo-Zeitstempel (Unix-Sekunden, Ende des Mittelungsintervalls) auf
     * [Datum, Wanduhr-Stunde] des Intervall-BEGINNS abbilden (eine Stunde
     * zurück), damit Prognose und gemessenes Stundenaggregat zeitlich
     * deckungsgleich sind.
     *
     * Bewusst Unix-Zeit statt der Standard-Beschriftung ("Y-m-d\TH:i"): Open-Meteo
     * beschriftet ALLE Stunden einer Antwort mit EINEM festen UTC-Offset (dem
     * zum Abrufzeitpunkt). Reicht das 5-Tage-Fenster über eine Zeitumstellung,
     * wäre jeder Tag danach um 1 h versetzt gelesen worden (Oktober zu spät,
     * März zu früh) — an der Archiv-API belegt: der Sonnenaufgang läuft über
     * den Wechsel glatt weiter statt um 1 h zu springen. Die Umrechnung in
     * Ortszeit übernimmt hier PHP (Zeitzonen-Datenbank, DST-korrekt).
     * Fund: Sitzung Prognose für EMS-Anfrage zur Zeitumstellung, 20.09.2026.
     */
    private function omSlot(int $ts): array
    {
        $start = $ts - 3600;
        return [date('Y-m-d', $start), (int)date('G', $start)];
    }

    /**
     * Selbstkalibrierung: gemessene vs. vorhergesagte Tages-kWh über die
     * letzten Tage (aus echter, vergangener Einstrahlung). Liefert das
     * mittlere Verhältnis gemessen/modelliert (geklammert), sonst null.
     */
    // protected = Testnaht des Prüfstands (Kalibrier-Ausfall simulieren), sonst unverändert
    protected function calibrate(array $g)
    {
        $days = max(7, $this->ReadPropertyInteger('PVF_CalibDays'));
        // Open-Meteo mit past_days liefert auch vergangene Einstrahlung.
        $perDayPast = $this->fetchOpenMeteoPast($g, $days);
        if ($perDayPast === null) { return null; }

        // Tage, an denen die Erzeugung abgeregelt war (EMS_GetSpecialEvents, `affects` ∋ 'pv':
        // Netzbetreiber-Dimmung, negativer Börsenpreis, Direktvermarktung), zählen nicht: die
        // gedrosselte Messung würde sonst als „Modell zu hoch" gelernt und den Faktor drücken.
        // Ereignisse, die nur die Last betreffen (Grid Rewards, Boost), berühren die Erzeugung nicht.
        $events = $this->calibrationEvents($days);
        $skipped = 0;
        $ratios = [];
        foreach ($perDayPast as $date => $hours) {
            $pred = array_sum($hours) / 1000.0;          // modellierte kWh
            if ($pred < 0.2) { continue; }               // Nachts/triviale Tage überspringen
            $dayStart = strtotime($date);
            if ($this->dayHasSpecialEvent($events, $dayStart, $this->dayEndExclusive($dayStart) - 1, 'pv')) { $skipped++; continue; }
            $meas = $this->measuredKwh($g['powervar'], $dayStart);
            if ($meas === null) { continue; }
            $ratios[] = $meas / $pred;
        }
        if ($skipped > 0) {
            $this->log(PVF_LOG_VERBOSE, $this->t('Calibration %s: %d day(s) with curtailed generation (special effect) skipped, %d days used',
                $g['name'] !== '' ? $g['name'] : ('#' . $g['powervar']), $skipped, count($ratios)));
        }
        if (count($ratios) < 5) { return null; }

        sort($ratios);
        $median = $ratios[(int)floor(count($ratios) / 2)];
        return max(0.4, min(1.6, $median));
    }

    /** Open-Meteo nur für vergangene Tage (Kalibrierung), nach Datum. protected = Testnaht des Prüfstands. */
    protected function fetchOpenMeteoPast(array $g, int $pastDays)
    {
        $lat = $this->ReadPropertyFloat('PVF_Latitude');
        $lon = $this->ReadPropertyFloat('PVF_Longitude');
        $url = sprintf(
            'https://api.open-meteo.com/v1/forecast?latitude=%s&longitude=%s'
            . '&hourly=global_tilted_irradiance,temperature_2m&tilt=%s&azimuth=%s'
            . '&forecast_days=0&past_days=%d&timezone=auto&timeformat=unixtime',
            rawurlencode((string)$lat), rawurlencode((string)$lon),
            rawurlencode((string)$g['tilt']), rawurlencode((string)$g['az']), $pastDays
        );
        $j = $this->httpGetJson($url);
        if ($j === null || !isset($j['hourly']['time'])) { return null; }

        $time = $j['hourly']['time'];
        $gti  = $j['hourly']['global_tilted_irradiance'] ?? [];
        $temp = $j['hourly']['temperature_2m'] ?? [];
        $pr   = $this->ReadPropertyFloat('PVF_PR');
        $tc   = $this->ReadPropertyFloat('PVF_TempCoeff');
        $kwpW = $g['kwp'] * 1000.0;
        $today = date('Y-m-d');

        $byDate = [];
        $n = count($time);
        for ($i = 0; $i < $n; $i++) {
            list($date, $hour) = $this->omSlot((int)$time[$i]); // Stundenbeginn (siehe fetchOpenMeteo)
            if ($date >= $today) { continue; }             // nur abgeschlossene Tage
            $irr = (float)($gti[$i] ?? 0);
            $ta  = (float)($temp[$i] ?? 20);
            // Dieselbe Temperatur-Abminderung wie im eigentlichen Forecast (fetchOpenMeteo).
            // Sonst faengt der Selbstkalibrierungs-Faktor (gemessen/hier-modelliert) den
            // Temperatureffekt zusaetzlich ein und der spaeter im Forecast schon ange-
            // wendete Temperaturabzug wird doppelt gerechnet -> Prognose zu niedrig.
            $derate = 1.0;
            if ($tc != 0.0 && $irr > 0) {
                $tcell  = $ta + $irr / 800.0 * 20.0;
                $derate = 1.0 + ($tc / 100.0) * ($tcell - 25.0);
            }
            $w = $kwpW * ($irr / 1000.0) * $pr * max(0.0, $derate);
            if (!isset($byDate[$date])) { $byDate[$date] = array_fill(0, 24, 0.0); }
            $byDate[$date][$hour] = $w;
        }
        return $byDate;
    }

    // ----------------------------------------------------------------
    //  Quelle: Forecast.Solar (liefert Leistung direkt)
    // ----------------------------------------------------------------

    private function fetchForecastSolar(array $g)
    {
        $lat = $this->ReadPropertyFloat('PVF_Latitude');
        $lon = $this->ReadPropertyFloat('PVF_Longitude');
        $url = sprintf(
            'https://api.forecast.solar/estimate/%s/%s/%s/%s/%s?limit=%d&time=utc',
            rawurlencode((string)$lat), rawurlencode((string)$lon),
            rawurlencode((string)$g['tilt']), rawurlencode((string)$g['az']),
            rawurlencode((string)$g['kwp']), PVF_MAX_OFFSET + 1
        );
        $j = $this->httpGetJson($url);
        if ($j === null || !isset($j['result']['watts'])) {
            $this->log(PVF_LOG_VERBOSE, $this->t('Forecast.Solar returned no result (limit reached?)'));
            return null;
        }

        $byDate = [];
        foreach ($j['result']['watts'] as $ts => $w) {
            // time=utc: eindeutige ISO-Zeit; die Standard-Schlüssel sind lokale
            // Zeitstrings ohne Offset (bei Zeitumstellung mehrdeutig/lückenhaft).
            $t = strtotime((string)$ts);
            if ($t === false) { continue; }
            $date = date('Y-m-d', $t);
            $hour = (int)date('G', $t);
            if (!isset($byDate[$date])) { $byDate[$date] = array_fill(0, 24, 0.0); }
            $byDate[$date][$hour] = (float)$w;            // Stundenwert (volle Stunde gewinnt)
        }
        return $this->mapOffsets($byDate);
    }

    // ----------------------------------------------------------------
    //  Quelle: Solcast (Leistung inkl. P10/P90)
    // ----------------------------------------------------------------

    /**
     * Wirksamer Solcast-API-Schlüssel: aus dem Attribut (sicherer
     * Speicherort); Fallback auf die Property für den kurzen Moment vor
     * dem asynchronen Leeren des Formularfelds nach dem Speichern.
     */
    private function solcastKey(): string
    {
        $secret = trim((string)$this->ReadAttributeString('PVF_SolcastSecret'));
        if ($secret !== '') { return $secret; }
        return trim((string)$this->ReadPropertyString('PVF_SolcastKey'));
    }

    /** Aktuelle Zeit — Testnaht des Prüfstands (Tageslicht-Fenster, Tagesbudget). */
    protected function solcastNow(): int { return time(); }

    /**
     * Tageslicht-Fenster für Solcast-Abrufe: Sonnenaufgang minus Versatz bis Sonnenuntergang plus Versatz
     * (Breiten-/Längengrad des Moduls, PHP date_sun_info). null = nicht bestimmbar (Polartag/-nacht oder
     * ungültige Koordinaten) — dann gilt kein Fenster, es wird wie bisher abgerufen.
     */
    private function solcastDaylightWindow(): ?array
    {
        $now = $this->solcastNow();
        $info = @date_sun_info(strtotime('today 12:00', $now), $this->ReadPropertyFloat('PVF_Latitude'), $this->ReadPropertyFloat('PVF_Longitude'));
        if (!is_array($info) || !is_int($info['sunrise'] ?? null) || !is_int($info['sunset'] ?? null)) { return null; }
        $off = max(0, $this->ReadPropertyInteger('PVF_SolcastOffsetMin')) * 60;
        return [$info['sunrise'] - $off, $info['sunset'] + $off];
    }

    /**
     * Zeitpunkt des letzten Solcast-Abrufs und frühester nächster Abruf (Unix-Zeit, null = unbekannt/jetzt).
     * Der nächste ergibt sich aus dem ältesten Zwischenspeicher-Eintrag plus Mindestalter (Intervall bzw. Abstand
     * aus dem Budget), bei aktivem Tageslicht-Fenster zusätzlich in dessen Grenzen verschoben. "Frühestens", weil
     * der Abruf erst beim nächsten Berechnungslauf stattfindet.
     *
     * @return array{last:?int,next:?int}
     */
    private function solcastCallTimes(): array
    {
        $cache = json_decode((string)$this->ReadAttributeString('PVF_SolcastCache'), true);
        $rids = [];
        foreach ($this->pvGenerators() as $g) { $r = trim($g['solcast']); if ($r !== '') { $rids[$r] = true; } }
        if (!is_array($cache) || count($rids) === 0) { return ['last' => null, 'next' => null]; }
        $last = null; $oldest = null;
        foreach (array_keys($rids) as $rid) {
            $ts = (int)($cache[$rid]['ts'] ?? 0);
            if ($ts <= 0) { return ['last' => $last, 'next' => null]; }   // eine ID noch nie abgerufen: sofort beim nächsten Lauf
            $last = ($last === null) ? $ts : max($last, $ts);
            $oldest = ($oldest === null) ? $ts : min($oldest, $ts);
        }
        $plan = $this->solcastBudgetPlan();
        $minAge = max(max(1, $this->ReadPropertyInteger('PVF_IntervalHours')) * 3600, $plan['gap']);
        $next = $oldest + $minAge;
        $now = $this->solcastNow();
        if ($next < $now) { $next = $now; }
        if ($this->ReadPropertyBoolean('PVF_SolcastDaylightOnly')) {
            $win = $this->solcastDaylightWindow();
            if ($win !== null) {
                if ($next < $win[0]) { $next = $win[0]; }
                elseif ($next > $win[1]) {
                    $off = max(0, $this->ReadPropertyInteger('PVF_SolcastOffsetMin')) * 60;
                    $tm = @date_sun_info(strtotime('tomorrow 12:00', $now), $this->ReadPropertyFloat('PVF_Latitude'), $this->ReadPropertyFloat('PVF_Longitude'));
                    $next = (is_array($tm) && is_int($tm['sunrise'] ?? null)) ? $tm['sunrise'] - $off : strtotime('tomorrow 06:00', $now);
                }
            }
        }
        return ['last' => $last, 'next' => $next];
    }

    /** Eigene Solcast-Abrufe des heutigen Tages (Tagesbudget). */
    private function solcastCallsToday(): int
    {
        $c = json_decode((string)$this->ReadAttributeString('PVF_SolcastCalls'), true);
        return (is_array($c) && ($c['date'] ?? '') === date('Y-m-d', $this->solcastNow())) ? (int)($c['n'] ?? 0) : 0;
    }

    /**
     * Mindestabstand zwischen zwei Solcast-Abrufen derselben Resource-ID aus dem Tagesbudget: Das Budget (Standard 10,
     * Gratiskonto) ist die Zahl der Abrufe, jede VERSCHIEDENE Resource-ID kostet einen je Runde. Daraus folgen
     * Runden = floor(Budget / IDs) und, gleichmäßig über das Tageslicht-Fenster (bzw. 24 h) verteilt, der kürzeste
     * zulässige Abstand = Fenster / Runden. So wird das Budget nicht schon vormittags verbraucht und das Intervall
     * ist nie kürzer, als es das Konto hergibt (Forum lungo 13.10.2026, Dietmar: "es müsste errechnet werden, wie
     * oft man abrufen kann").
     *
     * @return array{gap:int,rounds:int,ids:int,window:int} gap in Sekunden (0 = kein Budget gesetzt)
     */
    private function solcastBudgetPlan(): array
    {
        $ids = [];
        foreach ($this->pvGenerators() as $g) { $r = trim($g['solcast']); if ($r !== '') { $ids[$r] = true; } }
        $n = max(1, count($ids));
        $budget = max(0, $this->ReadPropertyInteger('PVF_SolcastDailyBudget'));
        $win = $this->ReadPropertyBoolean('PVF_SolcastDaylightOnly') ? $this->solcastDaylightWindow() : null;
        $window = ($win !== null) ? max(3600, $win[1] - $win[0]) : 86400;
        if ($budget <= 0) { return ['gap' => 0, 'rounds' => 0, 'ids' => count($ids), 'window' => $window]; }
        $rounds = max(1, intdiv($budget, $n));
        // Abrufe zum Beginn UND Ende des Fensters mitgezählt: n Runden = n-1 Abstände, n=1 = ein Abruf je Fenster.
        $gap = ($rounds > 1) ? (int)ceil($window / ($rounds - 1)) : $window;
        return ['gap' => $gap, 'rounds' => $rounds, 'ids' => count($ids), 'window' => $window];
    }

    private function solcastCountCall(): void
    {
        $this->WriteAttributeString('PVF_SolcastCalls', json_encode(['date' => date('Y-m-d', $this->solcastNow()), 'n' => $this->solcastCallsToday() + 1]));
    }

    /**
     * Solcast mit Antwort-Cache + 429-Schutz (Forum-Meldung cbeham,
     * 30.08.2026 — Details siehe Create()):
     * 1. Frischer Cache (jünger als das Rebuild-Intervall) → KEIN API-Abruf.
     *    Mehrfaches „Prognose jetzt neu berechnen" beim Einrichten kostet
     *    damit keine Abrufe mehr vom knappen Tageskontingent (~10/Tag im
     *    Gratiskonto, je Generator einer pro Neuberechnung).
     * 2. Abkühlphase aktiv (nach 429) → ebenfalls kein Abruf; stattdessen
     *    letzte gecachte Antwort, auch wenn älter (besser eine leicht
     *    veraltete Prognose als eine Null-Prognose).
     * 3. Bei neuem 429 → 2 h Abkühlphase setzen, klare Log-Meldung,
     *    letzte gecachte Antwort weiterverwenden.
     */
    private function fetchSolcast(array $g)
    {
        $key = $this->solcastKey();
        $rid = trim($g['solcast']);
        if ($key === '' || $rid === '') {
            $this->log(PVF_LOG_VERBOSE, $this->t('Solcast: API key or resource ID missing'));
            $this->solcastIssues[] = ['kind' => ($key === '') ? 'nokey' : 'norid', 'gen' => (string)$g['name'], 'rid' => $rid, 'http' => 0];
            return null;
        }

        $cache = json_decode((string)$this->ReadAttributeString('PVF_SolcastCache'), true);
        if (!is_array($cache)) { $cache = []; }
        $entry = $cache[$rid] ?? null;
        $plan = $this->solcastBudgetPlan();
        $minAge = max(max(1, $this->ReadPropertyInteger('PVF_IntervalHours')) * 3600, $plan['gap']);
        $cacheFresh = is_array($entry)
            && ($this->solcastNow() - (int)($entry['ts'] ?? 0)) < $minAge - 60;

        if ($cacheFresh) {
            return $this->mapOffsets($entry['byDate'], true);
        }
        $cooldown = $this->ReadAttributeInteger('PVF_SolcastCooldownUntil');
        if ($cooldown > time()) {
            if (is_array($entry)) {
                $this->log(PVF_LOG_VERBOSE, $this->t('Solcast: cool-down after the daily limit until %s — the last valid response continues to be used.', date('H:i', $cooldown)));
                $this->solcastIssues[] = ['kind' => 'quota', 'gen' => (string)$g['name'], 'rid' => $rid, 'http' => 429, 'stale' => true];
                return $this->mapOffsets($entry['byDate'], true);
            }
            $this->solcastIssues[] = ['kind' => 'quota', 'gen' => (string)$g['name'], 'rid' => $rid, 'http' => 429, 'stale' => false];
            return null;
        }

        // Kontingent schonen: nachts (außerhalb Sonnenaufgang/-untergang mit Versatz) und nach Erreichen des
        // Tagesbudgets KEIN Live-Abruf — die letzte gültige Antwort (Zwischenspeicher, mehrere Tage) reicht dann.
        // Ohne Zwischenspeicher wird beim ersten Mal trotzdem abgerufen (Tageslicht-Fenster), das Budget bleibt hart.
        $budget = max(0, $this->ReadPropertyInteger('PVF_SolcastDailyBudget'));
        if ($budget > 0 && $this->solcastCallsToday() >= $budget) {
            $this->solcastIssues[] = ['kind' => 'budget', 'gen' => (string)$g['name'], 'rid' => $rid, 'http' => 0, 'stale' => is_array($entry)];
            $this->log(PVF_LOG_VERBOSE, $this->t('Solcast: daily budget (%d calls) reached — the last valid response continues to be used.', $budget));
            return is_array($entry) ? $this->mapOffsets($entry['byDate'], true) : null;
        }
        if ($this->ReadPropertyBoolean('PVF_SolcastDaylightOnly') && is_array($entry)) {
            $win = $this->solcastDaylightWindow();
            $now = $this->solcastNow();
            if ($win !== null && ($now < $win[0] || $now > $win[1])) {
                $this->log(PVF_LOG_VERBOSE, $this->t('Solcast: outside the daylight window (%s–%s) — no call, the last valid response continues to be used.', date('H:i', $win[0]), date('H:i', $win[1])));
                return $this->mapOffsets($entry['byDate'], true);
            }
        }

        $url = sprintf('https://api.solcast.com.au/rooftop_sites/%s/forecasts?format=json&hours=%d',
            rawurlencode($rid), (PVF_MAX_OFFSET + 1) * 24);
        $this->solcastCountCall();
        $httpCode = 0;
        $j = $this->httpGetJson($url, ['Authorization: Bearer ' . $key], $httpCode);
        if ($j === null || !isset($j['forecasts'])) {
            if ($httpCode === 429) {
                // Tageskontingent erschöpft — 2 h Pause statt weiter dagegen
                // anzurennen (jeder Versuch würde nur wieder 429 liefern).
                $this->WriteAttributeInteger('PVF_SolcastCooldownUntil', time() + 2 * 3600);
                $this->log(PVF_LOG_BASIC, $this->t('Solcast: daily quota used up (HTTP 429). The free account only allows ~10 calls/day — one per generator per recalculation, other scripts using the same key count as well. Next attempt in 2 hours; until then the last valid response continues to be used.'));
            }
            $kind = ($httpCode === 429) ? 'quota'
                : (($httpCode === 401 || $httpCode === 403) ? 'auth'
                : (($httpCode === 404) ? 'notfound'
                : (($httpCode === 0) ? 'network' : 'http')));
            $this->solcastIssues[] = ['kind' => $kind, 'gen' => (string)$g['name'], 'rid' => $rid, 'http' => (int)$httpCode, 'stale' => is_array($entry)];
            // Letzte gecachte Antwort als Rückfall (stale-if-error) — besser
            // leicht veraltet als Null-Prognose.
            return is_array($entry) ? $this->mapOffsets($entry['byDate'], true) : null;
        }

        // 30-Min-Schätzungen (kW) zu Stunden mitteln; P10/P50/P90.
        $acc = [];
        foreach ($j['forecasts'] as $f) {
            $end = strtotime($f['period_end'] ?? '');
            if ($end <= 0) { continue; }
            $date = date('Y-m-d', $end);
            $hour = (int)date('G', $end);
            $key2 = $date . ' ' . $hour;
            if (!isset($acc[$key2])) { $acc[$key2] = ['p10' => [], 'p50' => [], 'p90' => []]; }
            $acc[$key2]['p50'][] = (float)($f['pv_estimate'] ?? 0) * 1000.0;
            $acc[$key2]['p10'][] = (float)($f['pv_estimate10'] ?? $f['pv_estimate'] ?? 0) * 1000.0;
            $acc[$key2]['p90'][] = (float)($f['pv_estimate90'] ?? $f['pv_estimate'] ?? 0) * 1000.0;
        }
        $byDate = [];
        foreach ($acc as $key2 => $vals) {
            list($date, $hour) = explode(' ', $key2);
            if (!isset($byDate[$date])) {
                $byDate[$date] = [];
                for ($h = 0; $h < 24; $h++) { $byDate[$date][$h] = ['p10' => 0.0, 'p50' => 0.0, 'p90' => 0.0]; }
            }
            $byDate[$date][(int)$hour] = [
                'p10' => array_sum($vals['p10']) / max(1, count($vals['p10'])),
                'p50' => array_sum($vals['p50']) / max(1, count($vals['p50'])),
                'p90' => array_sum($vals['p90']) / max(1, count($vals['p90'])),
            ];
        }

        // Erfolgreiche Antwort cachen (kompakt: aggregiertes Tagesraster statt
        // Roh-Forecasts, nach Datum indiziert — bleibt über Mitternacht gültig,
        // mapOffsets() löst „heute + o" erst beim Lesen auf) und eine evtl.
        // Abkühlphase beenden.
        $cache[$rid] = ['ts' => $this->solcastNow(), 'byDate' => $byDate];
        $this->WriteAttributeString('PVF_SolcastCache', json_encode($cache));
        if ($cooldown > 0) { $this->WriteAttributeInteger('PVF_SolcastCooldownUntil', 0); }

        return $this->mapOffsets($byDate, true);
    }

    // ----------------------------------------------------------------
    //  Hilfen
    // ----------------------------------------------------------------

    /**
     * Ordnet ein nach Datum indiziertes Tagesraster den Offsets 0/1/2 zu.
     * $hasBands=false: skalare W-Werte → p10=p50=p90; true: bereits {p10,p50,p90}.
     */
    private function mapOffsets(array $byDate, bool $hasBands = false): array
    {
        $out = [];
        for ($o = 0; $o <= PVF_MAX_OFFSET; $o++) {
            $date = date('Y-m-d', strtotime('today +' . $o . ' days'));
            $out[$o] = [];
            for ($h = 0; $h < 24; $h++) {
                if (!isset($byDate[$date])) {
                    $out[$o][$h] = ['p10' => 0.0, 'p50' => 0.0, 'p90' => 0.0];
                } elseif ($hasBands) {
                    $out[$o][$h] = $byDate[$date][$h];
                } else {
                    $w = $byDate[$date][$h];
                    $out[$o][$h] = ['p10' => $w, 'p50' => $w, 'p90' => $w];
                }
            }
        }
        return $out;
    }

    /**
     * Exklusives Tagesende (nächste lokale Mitternacht) von $start —
     * DST-sicher statt fixer 86400s-Arithmetik: liefert an Umstellungstagen
     * korrekt 23h/25h statt immer 24h. `strtotime('tomorrow', …)` rechnet in
     * Kalendertagen, nicht in Sekunden, und respektiert damit automatisch
     * Zeitumstellungen (PHP-Verhalten, kein manuelles DST-Handling nötig).
     * Fund: Verbundweite DST-Prüfung (Dashboard, 26.08.2026) — die alte
     * `$start + 86400 - 1`-Grenze überlappte am 23h-Tag (März) 1h in den
     * Folgetag hinein bzw. schnitt am 25h-Tag (Oktober) die letzte reale
     * Stunde ab.
     */
    private function dayEndExclusive(int $start): int
    {
        return strtotime('tomorrow', $start);
    }

    /** Gemessene Tages-kWh einer Leistungsvariablen (W) aus dem Archiv. */
    private function measuredKwh(int $varID, int $ts)
    {
        if ($varID <= 0 || !IPS_VariableExists($varID)) { return null; }
        $aid = $this->archiveID();
        if (!$this->isLogged($aid, $varID)) { return null; }

        $start = strtotime('today', $ts);
        $end   = $this->clampEnd($this->dayEndExclusive($start) - 1);
        $rows  = $this->aggregatedValuesQuiet($aid, $varID, 0, $start, $end); // stündlich
        if (!is_array($rows) || count($rows) === 0) { return null; }
        $f  = $this->varPowerFactor($varID); // Einheit → W
        $inv = $this->powerVarInverted($varID);
        $wh = 0.0;
        foreach ($rows as $r) { $wh += $this->signedWatts((float)$r['Avg'] * $f, $inv); } // Ø-W × 1 h = Wh
        return $wh / 1000.0;
    }

    /** Ist die gemessene Leistung dieser Variable bei einem Generator als „Vorzeichen umkehren" markiert? */
    private function powerVarInverted(int $vid): bool
    {
        foreach ($this->pvGenerators() as $g) {
            if ($g['powervar'] === $vid && $g['invert']) { return true; }
        }
        return false;
    }

    /**
     * Gemessene Leistung (W) im Erzeugungs-Vorzeichen: bei umgekehrter Variable wird negative Erzeugung positiv,
     * ein positiver Wert (Standby-Bezug nachts) wird zu 0 — kein Ertrag.
     */
    private function signedWatts(float $w, bool $invert): float
    {
        return $invert ? max(0.0, -$w) : $w;
    }

    /**
     * AC_GetAggregatedValues, robust gegen ein defektes Stunden-Aggregat im Archiv (Fund 24.09.2026, live
     * bei Dietmar — drei GoodWe-MPPT-Variablen, Zeitstempel einer Stunde lag nicht auf der vollen Stunde).
     * In diesem Fall liefert die native Funktion `false` statt eines Arrays UND feuert eine PHP-Warnung
     * ("Ungültige Aggregation hour"), die ohne Gegenmaßnahme im TimerPool als Fehler auftaucht. Ein
     * simples `@` reicht dafür NICHT zuverlässig: Ist ein eigener Error-Handler registriert (wie bei
     * IP-Symcon selbst), wird der seit PHP 8 trotz `@` aufgerufen — geprüft im Prüfstand
     * `archivstoerung.php`, ein `@` allein ließ die Warnung dort durchrutschen. Deshalb wird der Handler
     * für die Dauer des Aufrufs explizit durch einen No-Op ersetzt und danach wiederhergestellt.
     * Rückgabe: das native Ergebnis unverändert (Array oder `false`) — die Aufrufer prüfen bereits
     * `is_array()` und überspringen den betroffenen Tag/die betroffene Variable sauber, kein Absturz,
     * kein falscher Wert.
     */
    private function aggregatedValuesQuiet(int $aid, int $vid, int $level, int $start, int $end)
    {
        // Kein einschränkender Error-Level: ob die native Warnung als E_WARNING oder E_USER_WARNING
        // ankommt, ist von außen nicht verlässlich bekannt — ein zu enger Filter würde sie NICHT fangen
        // (PHP ruft dann weder diesen noch den vorherigen Handler, sondern den eingebauten Standard-
        // Handler auf; im Prüfstand `archivstoerung.php` live nachvollzogen). E_ALL (Standard ohne
        // zweiten Parameter) deckt das sicher ab, und das Zeitfenster ist auf diesen einen Aufruf begrenzt.
        set_error_handler(function () { return true; });
        $rows = AC_GetAggregatedValues($aid, $vid, $level, $start, $end, 0);
        restore_error_handler();
        return $rows;
    }

    /** Archive-Control-Instanz (0 = keine vorhanden). */
    private function archiveID(): int
    {
        $ids = IPS_GetInstanceListByModuleID(PVF_ARCHIVE_GUID);
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
        // ausgeschlossen, PVF_Accuracy blieb dauerhaft bei „Noch keine
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
            $this->log(PVF_LOG_BASIC, $this->t(
                'EMS_GetSpecialEvents returns contract %s, only major %d is supported — special-effect exclusion disabled until the module is updated.',
                $verStr, self::EMS_EVENTS_MAJOR
            ));
            return [];
        }
        $list = $events['events'] ?? $events;
        return is_array($list) ? $list : [];
    }

    /** EMS-Instanz für EMS_GetSpecialEvents. 0 = keine vorhanden. */
    private function emsInstance(): int
    {
        $ids = @IPS_GetInstanceListByModuleID(PVF_EMS_GUID);
        return (is_array($ids) && count($ids) > 0) ? (int)$ids[0] : 0;
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
     * Ereignisse für die Selbstkalibrierung, je Request einmal (calibrate() läuft je Generator).
     * Ohne EMS leer, nichts ändert sich. Fehler beim Lesen dürfen die Kalibrierung nicht kippen.
     */
    private function calibrationEvents(int $days): array
    {
        if ($this->calibEvents === null) {
            try {
                $this->calibEvents = $this->fetchSpecialEvents($days + 1);
            } catch (\Throwable $e) {
                $this->log(PVF_LOG_BASIC, $this->t('EMS_GetSpecialEvents not readable for the calibration: %s', $e->getMessage()));
                $this->calibEvents = [];
            }
        }
        return $this->calibEvents;
    }

    /**
     * Ist die Variable im Archiv geloggt? Verhindert Archiv-Warnungen
     * ("Logging nicht verfügbar") bei nicht archivierten Variablen.
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
     * fehlgeschlagen" auszulösen (Fund: Beta-Tester somm, 16.09.2026 bei
     * Lastprognose, analog übernommen — bestätigtes, ungelöstes
     * Symcon-Core-Verhalten, keine offizielle Lösung dokumentiert — wir
     * können es nur seltener machen, nicht ausschließen).
     */
    private function clampEnd(int $end): int
    {
        return min($end, time() - 2);
    }

    /**
     * Gemessenes PV-Slot-Profil (W je Slot) eines Tages: Summe aller
     * Generatoren aus dem Stundenaggregat, auf $slots abgebildet.
     * Rückgabe null ohne Archiv/Daten.
     */
    private function measuredProfile(int $ts, int $slots)
    {
        $aid = $this->archiveID();
        if ($aid === 0) { return null; }

        $start  = strtotime('today', $ts);
        $end    = $this->clampEnd($this->dayEndExclusive($start) - 1);
        $hourly = array_fill(0, 24, 0.0);
        $any    = false;

        foreach ($this->pvGenerators() as $g) {
            $vid = $g['powervar'];
            if (!$this->isLogged($aid, $vid)) { continue; }
            $rows = $this->aggregatedValuesQuiet($aid, $vid, 0 /* stündlich */, $start, $end);
            if (!is_array($rows) || count($rows) === 0) { continue; }
            $f = $this->varPowerFactor($vid);
            $inv = $this->powerVarInverted($vid);
            // Je Generator erst sauber auf 0..23 Stunden bringen (Kollision
            // bei Zeitumstellung Oktober behandeln: Wanduhr-Stunde 2 kommt
            // real ZWEIMAL vor, date('G') liefert für beide "2" — gemittelt
            // statt aufaddiert, sonst würde diese eine Stunde für den
            // Generator praktisch verdoppelt), erst DANACH über die
            // Generatoren aufsummieren.
            $genHourly = array_fill(0, 24, null);
            $hits = array_fill(0, 24, 0);
            foreach ($rows as $r) {
                $h = (int)date('G', $r['TimeStamp']);
                if ($h < 0 || $h >= 24) { continue; }
                $v = $this->signedWatts((float)$r['Avg'] * $f, $inv);
                if ($hits[$h] > 0) { $genHourly[$h] = ($genHourly[$h] * $hits[$h] + $v) / ($hits[$h] + 1); }
                else { $genHourly[$h] = $v; }
                $hits[$h]++;
            }
            for ($h = 0; $h < 24; $h++) {
                if ($genHourly[$h] !== null) { $hourly[$h] += $genHourly[$h]; $any = true; }
            }
        }
        if (!$any) { return null; }

        // Stundenwerte auf das Slot-Raster abbilden (24/48/96).
        $out = [];
        for ($s = 0; $s < $slots; $s++) {
            $out[$s] = $hourly[(int)floor($s * 24 / $slots)];
        }
        return $out;
    }

    /**
     * Erkennt Archivstörungen, bei denen der letzte Messwert über Stunden
     * gehalten wurde (Fund: EMS-Sitzung bei der Verifikation ihrer
     * Prognosegüte-Analyse, 12.09.2026 — 01.09. zeigte 745 W konstant von
     * 18:45 bis 22:00, obwohl das Modell dort längst Soll=0 ansetzt).
     *
     * KORRIGIERT 13.09.2026 (live Fehlalarm auf Dietmars Instanz #22026
     * gefunden, Erstfassung war zu scharf): direkt an der vom Modell
     * markierten Tageslicht-Grenze prüfen ist falsch — echte PV-Erzeugung
     * reicht durch Zwielicht oft noch 30-60 Min. über das vom Modell
     * angesetzte Soll=0 hinaus (live beobachtet: 20:00 zeigte an JEDEM der
     * 14 Tage 36-131 W, obwohl das Modell dort schon Nacht ansetzt — echte
     * Resterzeugung, keine Störung). Deshalb: erst 90 Minuten nach
     * Sonnenuntergang / vor Sonnenaufgang gilt ein Slot als "tiefe Nacht",
     * UND es müssen mindestens 2 aufeinanderfolgende Slots dort über der
     * Schwelle liegen (ein gehaltener Wert ist über Stunden konstant, eine
     * einzelne Zwielicht-/Rausch-Spitze nicht). Die 745-W-Störung vom
     * 01.09. lag mit 21:15-22:00 weiterhin klar in der so definierten
     * tiefen Nacht und wird weiterhin erkannt.
     */
    private function hasNightArtifact(array $prof, array $sp): bool
    {
        $n = min(count($prof), count($sp));
        if ($n === 0) { return false; }
        $maxS = max($sp);
        if ($maxS <= 0) { return false; }
        $floor = max(10.0, 0.02 * $maxS);

        $start = null; $end = null;
        for ($i = 0; $i < $n; $i++) {
            if ((float)$sp[$i] >= $floor) {
                if ($start === null) { $start = $i; }
                $end = $i;
            }
        }
        if ($start === null) { return false; }

        $slotSec = (int)(86400 / $n);
        $buffer  = max(1, (int)ceil(5400 / $slotSec)); // 90 Minuten Zwielicht-Puffer

        $run = 0;
        for ($i = 0; $i < $n; $i++) {
            $deepNight = ($i > $end + $buffer) || ($i < $start - $buffer);
            if (!$deepNight) { $run = 0; continue; }
            if ((float)$prof[$i] > 20.0) {
                $run++;
                if ($run >= 2) { return true; }
            } else {
                $run = 0;
            }
        }
        return false;
    }

    /** Faktor zur Umrechnung nach W: 0=W, 1=kW, 2=automatisch je Variable. */
    private function varPowerFactor(int $vid): float
    {
        $mode = $this->ReadPropertyInteger('PVF_PowerUnit');
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
        $aid = $this->archiveID();
        if ($this->isLogged($aid, $vid)) {
            $rows = $this->aggregatedValuesQuiet($aid, $vid, 1, strtotime('-7 days'), $this->clampEnd(time()));
            if (is_array($rows) && count($rows) > 0) {
                $max = 0.0;
                // Betrag des Extremwerts: bei negativer Erzeugung (Vorzeichen umkehren) ist das Tagesminimum der
                // große Wert, das Maximum nur der kleine Standby-Bezug.
                foreach ($rows as $r) { $max = max($max, (float)$r['Max'], abs((float)($r['Min'] ?? 0))); }
                if ($max > 0 && $max < 100) { return ['factor' => 1000.0, 'source' => 'magnitude', 'detail' => (string)round($max, 1)]; }
                if ($max >= 100) { return ['factor' => 1.0, 'source' => 'magnitude', 'detail' => (string)round($max)]; }
            }
        }
        return ['factor' => 1.0, 'source' => 'default', 'detail' => ''];
    }

    /** $httpCode (out): tatsächlicher HTTP-Status — für Aufrufer, die z. B. 429 gesondert behandeln (fetchSolcast). */
    // protected = Testnaht des Prüfstands (Solcast-Fehlerfälle simulieren), sonst unverändert
    protected function httpGetJson(string $url, array $headers = [], int &$httpCode = 0)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Symcon PVForecast');
        if (count($headers) > 0) { curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); }
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        $httpCode = $code;

        if ($body === false || $code >= 400) {
            $this->log(PVF_LOG_BASIC, $this->t('HTTP error %s %s at %s', $code, $err, $url));
            return null;
        }
        $j = json_decode($body, true);
        return is_array($j) ? $j : null;
    }

    private function emptyForecast(int $ts)
    {
        $zeros = array_fill(0, 24, 0.0);
        return [
            'contractVersion' => PVF_CONTRACT_FORECAST,
            'date'       => date('Y-m-d', $ts),
            'slots'      => 24,
            'resolution' => '60min',
            'unit'       => 'W',
            'p10' => $zeros, 'p50' => $zeros, 'p90' => $zeros, 'mean' => $zeros,
            'kwh' => 0.0, 'neighbors' => 0,
            'generated' => 0,
        ];
    }

    private function log($level, $message)
    {
        $configLevel = $this->ReadPropertyInteger('PVF_Log_Level');
        if ($level > $configLevel) { return; }
        $prefix = ($level === PVF_LOG_VERBOSE) ? 'VERBOSE' : 'INFO';
        $this->SendDebug($prefix, $message, 0);
        // $this->LogMessage statt IPS_LogMessage (Store-Review 12.10.2026): ordnet den Eintrag der Instanz zu.
        if ($level <= PVF_LOG_BASIC) { $this->LogMessage((string)$message, KL_MESSAGE); }
    }
}
