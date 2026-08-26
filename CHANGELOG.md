# Changelog

## 0.20-beta (Beta-Kanal – in Entwicklung)

Dieser Stand läuft im **Beta-Kanal** und trägt daher das Kürzel `-beta` in der Version. Neue
Funktionen werden hier gesammelt und erst nach dem Test als reguläre `0.20` in den Stable-Kanal
übernommen.

- **Neu: Datum statt „Morgen"/„Übermorgen"/„Tag 4"/„Tag 5" im Tagesstreifen der
  Energiebilanz-Kachel, dezenter Scroll-Hinweis (Forum-Feedback hfichtinger,
  26.08.2026).** „Heute" bleibt als Ankerpunkt ein Wort, ab „Morgen" steht jetzt
  Wochentag+Datum (z. B. „Do 27.08."), damit der Bruch zu den bisher generischen
  „Tag 4"/„Tag 5" wegfällt. Zusätzlich: ein dezenter „›"-Pfeil rechts im Diagramm
  zeigt jetzt an, wenn noch mehr Tage außerhalb des sichtbaren Bereichs folgen
  (hfichtinger: „war etwas tricky das scrollen herauszufinden") — blendet sich
  aus, sobald man ganz durchgescrollt hat, kein dauerhaftes Element und keine
  erneute sichtbare Bildlaufleiste. Technische Notiz: `position:sticky` für den
  Pfeil (analog zur Y-Achsen-Überlagerung) funktioniert hier NICHT symmetrisch
  (die statische Ausgangsposition eines `width:0`-Blocks liegt immer links) —
  stattdessen sitzt der Hinweis außerhalb des Scroll-Rahmens direkt in der
  Kachel, vertikale Position wird aus der tatsächlichen Chart-Höhe berechnet.
  Live mit beiden Engines verifiziert (erscheint bei mehr Inhalt, verschwindet
  am Scroll-Ende, bleibt aus bei genau 3 Tagen ohne Scroll-Notwendigkeit).
- **Fix: X-Achsen-Zeitbeschriftung in Highcharts weiterhin abgeschnitten
  (Dietmars Feedback — der vorige Fix hatte nur die ECharts-Seite bzw. den
  Abstand zum Tagesstreifen behoben, nicht die eigentliche Ursache in
  Highcharts).** Live vermessen (`labelGroup.getBBox()`): Highcharts platziert
  die Zeitbeschriftung trotz `tickLength:0` mit einem eigenen, nicht direkt
  einstellbaren Innenabstand — der ragte bei uns ca. 5px über die per
  `marginBottom` reservierte Höhe hinaus und wurde vom Kachel-Rand
  abgeschnitten. Jetzt `xAxis.labels.y` explizit gesetzt (statt Highcharts'
  Auto-Platzierung zu vertrauen) plus etwas mehr Rand allgemein (unterer
  Plotbereich-Rand 22→28px, wirkt auf beide Engines). Live mit Highcharts bei
  scale 1 und 1.4 verifiziert (Zeiten „00 03 06 …" vollständig lesbar).
- **Fix: Tagesstreifen (Gestern/Heute/…) rückte 5px näher an die X-Achsen-
  Zeitbeschriftung, weil die zu eng beieinander lagen und dadurch schlecht
  auseinanderzuhalten waren (Dietmars Feedback).** `#days`-Abstand zum Chart
  von 6px auf 11px erhöht.
- **Neu: alle Einstellungen der Energiebilanz-Kachel jetzt hinter dem Doppelpfeil
  (WebFront), nicht mehr nur in der Konsole (Dietmar, 26.08.2026: "Bau alles um").**
  22 bisherige Konsolen-Properties (Tage, Ist-Anzeige, Farben, Schriftart/-größe,
  Diagramm-Engine, Glättung, Band, Gitter, Y-Achse fest, Archiv-Cache …) sind jetzt
  echte Instanz-Variablen mit `EnableAction()` — Fund aus Dashboards Hydraulikschema-
  Muster (SUITE.md Punkt 10): eine aufgezogene Kachel zeigt nie das eigene Kachel-HTML,
  aber automatisch die Standardansicht der Instanz-Variablen als Schalter/Dropdown/
  Zahlenfeld. Neue `EFTILE.*`-Variablenprofile für die Auswahlfelder, `~HexColor` für
  die drei Farben. Nur die vier Quell-/Variablenauswahl-Properties (PV-/Lastprognose-
  Instanz, Ist-Leistungsvariablen) bleiben Konsolen-Properties — SelectInstance/
  SelectVariable gibt es nicht als Doppelpfeil-Variable. Bestehende Einstellungen
  (z. B. Days=5) werden beim ersten Start nach dem Update automatisch aus der alten
  Konfiguration übernommen (`legacyValue()`/`legacyIntValue()`, liest die rohe
  Instanz-Konfiguration statt der nicht mehr registrierten Property), nicht auf den
  Modul-Default zurückgesetzt. `ResetStyle()` (Konsolen-Button) setzt jetzt per
  `SetValue()` auf die Variablen zurück statt per `UpdateFormField()` auf Formularfelder.
  form.json entsprechend gekürzt (nur noch Quellen/Ist-Variablen + Dokumentation).
- **Fix: Y-Achse/X-Achse bei skalierter Kachel nicht mehr abgeschnitten + P10/P90 im
  Tooltip (Dietmars Folge-Feedback zur eben gefixten Y-Achsen-Überlagerung).** Die neue
  `#yAxisOverlay` und die vereinheitlichten Plotbereich-Ränder waren als feste, unskalierte
  Pixelwerte hinterlegt (`PLOT_TOP=8`/`PLOT_BOTTOM=22` usw.) — bei einer über `scale` größer
  eingestellten Kachel wuchs die Schrift mit, der reservierte Rand aber nicht: Die
  „kW"-Beschriftung wurde von `#scrollWrap`s `overflow-y:hidden` abgeschnitten (negativer
  Top-Offset), die X-Achsen-Zeitbeschriftung unten zu eng zum sauberen Rendern. Jetzt
  skalieren alle vier Ränder mit `D.scale` (`plotMetrics()`), die „kW"-Beschriftung sitzt
  mittig im oberen Rand statt an einem festen Negativ-Offset. Zusätzlich zeigt der Tooltip
  jetzt neben PV/Verbrauch (P50) auch die Unsicherheitsspanne P10–P90 in kW an (auf Wunsch,
  unabhängig vom sichtbaren Unsicherheitsband). Live mit beiden Engines bei `scale:1.4`
  verifiziert (keine Abschneidung mehr, Tooltip zeigt „PV: 4.50 kW (P10–P90 3.15–5.85 kW)").
- **Fix: Y-Achse (kW) bleibt beim Horizontalscrollen der Energiebilanz-Kachel sichtbar
  (Dietmars Folge-Feedback zur Legende/zum Scrollbalken).** Bisher zeichneten beide Engines
  ihre Achsenbeschriftung als Teil des breiten, scrollenden Chart-Canvas — sie lief beim
  Scrollen mit weg, genau wie zuvor die Legende. Fix: eigene, schmale `#yAxisOverlay` mit
  `position:sticky` links im Scroll-Rahmen (analog zur externen `#legend`), berechnet 5
  gleich verteilte Marken (0..`yMax`) + „kW"-Beschriftung rein aus den Daten. Damit das
  pixelgenau auf den echten Gitterlinien sitzt, erzwingen jetzt beide Engines identische
  Plotbereich-Ränder (`PLOT_LEFT/RIGHT/TOP/BOTTOM`, vorher hatte Highcharts eigene
  `spacingTop/Bottom` statt ECharts' `grid`-Maße) und teilen sich `yMax`/Sichtbarkeit
  (`D.pvVis`/`D.loVis`) aus derselben `prepData()` statt sie je Engine separat zu berechnen —
  eigene Achsenbeschriftung/-titel beider Engines dafür ausgeblendet (Gitterlinien bleiben).
  Live im Browser mit beiden Engines verifiziert (Mock-Daten, 6 Tage, Y-Achse bleibt links
  stehen und fluchtet mit den Gitterlinien über die gesamte Scroll-Breite).
- **Fix: Legende überdeckte sich nicht mehr selbst beim Scrollen, Scrollbalken versteckt
  (Dietmars Feedback zum Scroll-Feature von eben).** Highcharts zeichnete seine Legende bisher
  INNERHALB des Chart-Canvas (`legend.align:'right'`) — der wurde beim Horizontalscrollen jetzt
  breiter als der Rahmen und die Legende scrollte mit weg. Beide Engines nutzen jetzt dieselbe
  externe `#legend` (außerhalb von `#scrollWrap`, bleibt beim Scrollen sichtbar/klickbar) —
  ECharts nutzte die ohnehin schon, Highcharts' eingebaute Legende ist jetzt `enabled:false`.
  Zusätzlich der horizontale Scrollbalken per CSS versteckt (`scrollbar-width:none` /
  `::-webkit-scrollbar{display:none}`) — überdeckte sonst die Ist-Werte-Zeile am unteren Rand;
  Scrollen per Touch/Trackpad/Klick-Ziehen bleibt uneingeschränkt möglich. Live im Browser mit
  beiden Engines verifiziert (Mock-Daten, 6 Tage, Legende bleibt beim Scrollen stehen,
  Aus-/Einblenden per Legenden-Klick funktioniert weiterhin).
- **Neu: horizontales Scrollen in der Energiebilanz-Kachel bei mehr als 3 Tagen.** Bis 3 Tage
  passen wie bisher unverändert in den Kachel-Rahmen; darüber werden Diagramm und Tagesstreifen
  proportional breiter (`renderW`, an `#scrollWrap` mit `overflow-x:auto`) statt sich
  zusammenzuquetschen — beide bekommen exakt dieselbe berechnete Breite, damit Tagesgrenzen
  pixelgenau fluchten. Funktioniert für beide Diagramm-Engines (ECharts `resize()`, Highcharts
  `chart.width` in `update()`). Auslöser: mit 5 Tagen Horizont (Prognose-Erweiterung von eben)
  quetschten sich zuvor alle 6 Tage (inkl. Gestern) unlesbar in dieselbe feste Breite.
- **Neu: `EFTILE_GetDaysData($id)` — style-freier Datenzugriff für NRGDashboard.** Dietmar hat
  entschieden, dass die Visualisierung der Energiebilanz-Kachel künftig als eigenständiges
  Dashboard-Modul entsteht, statt mit dem Tagesplan zu verschmelzen — Prognoseberechnung bleibt
  bei uns, Darstellung wandert (Verbund-Muster wie HeishaMon/EMS). `GetFullUpdateMessage()` in
  `buildDaysData()` (reine Daten) + Style-Merge (nur für die eigene Kachel) aufgeteilt. Der neue
  öffentliche Aufruf liefert dasselbe `days[]`-Format wie die eigene Kachel intern nutzt, aber
  IMMER vollständig — vollen 5-Tage-Horizont, „Gestern", Ist-Überlagerung — unabhängig von den
  Anzeige-Einstellungen dieser Instanz; der Konsument entscheidet selbst, was er zeigt.
  `contractVersion` 1.0, eigenständig versioniert.
- **Prognose-Horizont von 3 auf 5 Tage erweitert (Forum-Wunsch, mit EMS und Dashboard
  abgestimmt).** `LFC_/PVF_GetForecast($id, $offset)` akzeptieren jetzt Offset 0..4 statt 0..2,
  `LFC_/PVF_GetEnergyWindow()` deckt entsprechend bis zu 5 Tage ab. Technisch geprüft statt
  angenommen: Open-Meteo (bis 16 Tage), Forecast.Solar (bis 8 Tage über den bisher ungenutzten
  `limit`-Parameter), Solcast (bis 14 Tage) tragen das alle. Bei Last-Prognose ist 5 Tage exakt
  die Grenze der kostenlosen OpenWeatherMap-Anbindung im Auto-Modus (3h-Raster, hart limitiert) —
  kein Wunschwert, sondern die von der kostenlosen Quelle vorgegebene Decke; darüber hinaus greift
  ohnehin die schon vorhandene Klimatologie-Rückfallebene. Neue Statusvariablen `LFC_/PVF_Day3`,
  `LFC_/PVF_Day4` (+ kWh-Varianten, + `LFC_WPkWhDay3/4`) — bestehende Today/Tomorrow/DayAfter-Idents
  bewusst unverändert (Archivhistorie bleibt erhalten). Manueller Tagesmittel-Modus (Last-Prognose)
  bekam zwei weitere Temperatur-Eingabefelder. Energiebilanz-Kachel kann jetzt bis zu 5 Tage
  anzeigen (Default bleibt 3 — mehr Tage in fester Breite überladen sonst die Darstellung; die
  Dashboard-Sitzung übernimmt die Visualisierung mittelfristig mit scrollbarer Zeitachse).
  `contractVersion` beider Module 1.0→1.1 (additiv, mit EMS abgestimmt, kein Major-Bruch —
  bestehende Aufrufe mit Offset 0-2 liefern unverändert dieselben Werte).
- **Doku-Klarstellung (PV-Prognose, Forum-Feedback von hfichtinger/Bricoleur): Selbstkalibrierung
  wirkt nur bei Open-Meteo.** Beobachtung eines Beta-Testers: Forecast.Solar schätzt spürbar
  konservativer (niedriger) als Open-Meteo. Das ist kein Bug — verschiedene Quellen haben
  unterschiedliche systematische Tendenzen —, aber die Selbstkalibrierung (`PVF_Calibrate`)
  greift technisch nur bei Open-Meteo (ihre Vergangenheits-Basis nutzt dessen Reanalyse-API;
  Forecast.Solar bietet keine vergleichbare Historie). Der bereits vorhandene, quellen-
  unabhängige Ausweg — Residuen-Modus „Band + Pegelkorrektur" (vergleicht Snapshot gegen Ist,
  unabhängig von der Quelle) — war dafür nicht klar genug dokumentiert. Hinweistext ergänzt.
- **Fix (Performance): `LFC_GetForecast()`/`PVF_GetForecast()` rechneten bei JEDEM externen
  Aufruf komplett neu, statt den von `Rebuild()` bereits berechneten Stand zu nutzen.**
  Fund aus der PVMonitor/Dashboard-Sitzung (langsamer Tagesplan-Reiter): Last-Prognose
  durchsuchte dabei bis zu `LFC_LookbackDays` (Default 365) Kandidatentage mit je einem
  Archivzugriff, PV-Prognose löste bei jedem Aufruf frische Live-API-Calls aus (Open-Meteo/
  Forecast.Solar/Solcast — Letzteres ratenbegrenzt) — `$modelCache` half nur innerhalb EINER
  Skriptausführung, nicht über separate externe Aufrufe hinweg. Beide `GetForecast()` lesen
  jetzt zuerst den bereits in `LFC_/PVF_Today/Tomorrow/DayAfter` zwischengespeicherten Stand
  (gültig solange dessen `date`-Feld zum angefragten Offset passt), die teure Berechnung
  (`computeForecast()`) läuft nur noch in `Rebuild()` selbst und beim allerersten,
  noch ungecachten Aufruf.
- **Neu: Button "🔄 Übernehmen erzwingen (ohne Formularänderung)" in allen drei Modulen**
  (EMS-Angebot, optional). Ruft direkt `IPS_ApplyChanges($id)` auf — praktisch nach jedem
  Modul-Update, ohne dass erst ein Formularfeld angefasst werden muss. Kein `SetProperty` im
  `onClick`, daher kein Verstoß gegen die Store-Review-Regel „keine Selbstpersistenz in
  Formular-Buttons" — persistiert nichts Ungespeichertes.
- **Sichtbare Rückmeldung bei jeder Aktion (verbindliche Verbund-Konvention, 20.08.2026).**
  Der "Prognose jetzt neu berechnen"-Button gab in beiden Modulen (LFC/PVF) bislang keine
  sichtbare Rückmeldung — `Rebuild()` lief serverseitig korrekt, aber ohne `echo` im `onClick`
  sah man ohne Formular-Neuöffnen nicht, dass etwas passiert war. `Rebuild()` gibt jetzt einen
  Ergebnistext mit ✅/⚠️/⛔-Präfix zurück (identisch zu dem, was `LFC_/PVF_Status` speichert),
  `onClick` ruft `echo LFC_Rebuild($id)`/`echo PVF_Rebuild($id)`. Der Intervall-Timer ruft
  dieselbe Methode weiterhin auf und ignoriert den Rückgabewert, keine Verhaltensänderung dort.
  Die anderen Buttons ("Status anzeigen", "…morgen (JSON) ausgeben") hatten bereits `echo` —
  „Status anzeigen" war laut EMS sogar das Vorbild für die neue Konvention. Energiebilanz'
  „Darstellung auf Standard zurücksetzen" bleibt unverändert: `ResetStyle()` schreibt per
  `UpdateFormField()` direkt in die betroffenen Formularfelder zurück, die sichtbare Änderung
  aller 14 Felder ist die Rückmeldung selbst.
- **README-Badges (Verbund-Konvention, 18.08.2026).** Badge-Zeile direkt unter der
  H1-Überschrift in allen vier READMEs (Suite + 3 Module): Symcon, Modul-Version,
  Symcon-Mindestversion, Lizenz, PayPal — nach EMS' Referenzvorlage. Check-Style-CI-Badge
  bewusst noch NICHT dabei: das GitHub-Token hat den `workflow`-Scope nicht, ein
  `.github/workflows/`-Push wird von GitHub abgelehnt. SUITE.md-Regel befolgt (kein
  vorgetäuschtes CI-Badge ohne echte CI) — Workflow + Badge folgen, sobald der Scope da ist.
- **Fix: Plausibilitätskontrolle (Lastprognose) meldete optionale Lasten fälschlich als
  "kaputt".** Die 48h-Schwelle passt für den Hausverbrauch (schwankt immer), aber nicht für
  Abzugsliste/WP-Geräte (Wallbox, Wärmepumpe/Klima) — die dürfen legitim wochenlang inaktiv
  sein (kein Ladevorgang, Saison ohne Heizen/Kühlen). Live gefunden: eine kaum genutzte
  Wallbox stand seit 3 Wochen konstant bei 0 kW, wurde fälschlich als Ausfall gemeldet.
  Hausverbrauch bleibt bei 48h, Abzugsliste/WP-Geräte jetzt 30 Tage.
- **Neu: laufende Plausibilitätskontrolle in beiden Modulen (Dietmars Vorschlag, 09.08.2026).**
  `checkDataPlausibility()` läuft bei jedem `Rebuild()` automatisch mit — kein separater
  Zeitplan/Cron nötig, nutzt den ohnehin vorhandenen Intervall-Timer. Prüft, ob die für
  Prognose/Kalibrierung genutzten Messwerte (PV: PowerVar je Generator; Last: Hausverbrauch,
  Abzugsliste, WP-Geräte) innerhalb der letzten 48 Stunden mindestens einmal einen echten
  Wertewechsel hatten. Anders als `unloggedVars()` (prüft nur die Konfiguration) erkennt das
  auch eine zur Laufzeit ausgefallene Quelle, die weiter als archiviert gilt, aber keine
  frischen Werte mehr liefert (z. B. eine abgebrochene Modbus-Verbindung) — genau die
  Fehlerklasse, die am 09.08.2026 zwei Wochen unbemerkt blieb, bis der Vergleich Soll/Ist von
  Hand angestoßen wurde. Auffälligkeiten erscheinen als ⚠️ direkt im Status, sichtbar ohne
  eigene Statistik-Auswertung.
- **Fix: PV-Prognose hatte kein try/catch um `Rebuild()`.** Lastprognose fängt Exceptions in
  `Rebuild()` schon lange ab und schreibt sie lesbar in den Status; PV-Prognose nicht — jeder
  unerwartete Fehler (Netzwerk, API-Format, o. ä.) riss den kompletten Lauf ohne Meldung ab,
  genau wie beim `EMS_GetSpecialEvents`-Absturz. Jetzt symmetrisch zu Lastprognose.
- **Fix: PV-Prognose unterschätzte die Erzeugung systematisch, wenn Selbstkalibrierung aktiv
  war.** `fetchOpenMeteoPast()` (die Vergangenheits-Modellierung für die
  Selbstkalibrierungs-Basis) rechnete OHNE die Temperatur-Abminderung, die der eigentliche
  Forecast (`fetchOpenMeteo()`) aber anwendet. Dadurch fing der gelernte Kalibrierungsfaktor
  (gemessen ÷ modelliert) den Temperatureffekt zusätzlich ein — der im fertigen Forecast
  bereits separat abgezogene Temperaturverlust wurde effektiv doppelt gerechnet, die
  Prognose fiel dadurch strukturell zu niedrig aus. `fetchOpenMeteoPast()` wendet jetzt
  dieselbe NOCT-Näherung wie `fetchOpenMeteo()` an, sodass die Kalibrierung nur noch die
  temperaturunabhängigen Restverluste (Verschmutzung, Verkabelung, reale PR-Abweichung)
  lernt.
- **Fix (kritisch): `EMS_GetSpecialEvents`-Aufruf stürzte `Rebuild()` fatal ab, sobald ein
  EMS installiert war.** In beiden Modulen (Last- und PV-Prognose) wurde die EMS-Instanz-ID
  hartkodiert als `0` übergeben statt die tatsächliche Instanz zu suchen — IP-Symcon wirft
  dann "Instance does not implement this function", ein Fatal Error, den `@` nicht abfängt.
  Betraf `evaluateAccuracy()` und damit den gesamten `Rebuild()`-Aufruf inkl. Prognoseberechnung.
  Behoben durch `emsInstance()` (analog zum bestehenden `owmInstance()`-Muster,
  `IPS_GetInstanceListByModuleID` auf die stabile EMS-GUID). PV-Prognose bekam dabei
  zusätzlich die in Lastprognose bereits vorhandene `contractVersion`-Major-Prüfung
  (Update-Meldepflicht) nachgezogen, die dort noch fehlte.
- **NRG-Stack-Markenkonvention.** Bibliotheksname `NRGPrognose` → **„NRG-Stack Prognose"**,
  Modul-Aliase `NRGLastprognose`/`NRGPVPrognose`/`NRGEnergiebilanz` → **„NRG-Stack
  Lastprognose"/„NRG-Stack PVPrognose"/„NRG-Stack Energiebilanz"** (analog zu NRGDashboard,
  Commit `3d1706f`). Nur Anzeigenamen (`library.json` „name", `module.json` „aliases")
  geändert — GUIDs und `module.json` „name" (= PHP-Klasse) unverändert, bestehende Instanzen
  und künftige Updates nicht betroffen. Dabei nebenbei behoben: `vendor` in allen drei
  `module.json` stand fälschlich auf `"DG65"` (= Modulentwickler) statt leer — die
  Store-Review-Checkliste verlangt hier den Hersteller des angebundenen Geräts, und diese
  drei Module binden kein Fremdgerät an (reine Software/Wetter-API).
- **PV-Prognose: Feld-Erklärung für „Korrektur" (Verbund-weite Usability-Prüfung).** Die Spalte
  „Korrektur" in der Generatorliste zeigte nur eine nackte Zahl (Standard 1,00) ohne Erklärung, was
  der Wert bedeutet oder wie er mit der Selbstkalibrierung zusammenwirkt — für Laien ohne
  Hintergrundwissen unklar. Jetzt ein PopupButton direkt darunter: 1,00 = keine Korrektur,
  kleinere/größere Werte skalieren die Prognose prozentual, wirkt multiplikativ zusätzlich zur
  automatischen Kalibrierung (nicht anstelle davon).
- **PV-Prognose: Feld-Erklärung für „Kalibrieren" (Verbund-Konvention Feld-Tooltips).** IP-Symcon
  kennt kein natives Mouseover-Tooltip; die Spalte „Kalibrieren" in der Generatorliste war bisher
  nur in benachbarten Absätzen erklärt, nicht direkt am Feld. Jetzt ein PopupButton direkt unter der
  Liste mit der fokussierten Erklärung (wann für abgeregelte Generatoren ausschalten). Andere Felder
  geprüft: bereits ausreichend durch vorhandene Label-Elemente abgedeckt.
  Styling nach InverterHub-Live-Test präzisiert: `caption="?"` (reiner Buchstabe statt Emoji) mit
  `width="70px"`, da eine geringere Breite im WebFront-Skin ohne sichtbaren Effekt bleibt.
- **PV-Prognose: neue Funktion `PVF_GetEnergyWindow($id,$fromTs,$toTs)`.** Symmetrisches Gegenstück
  zu `LFC_GetEnergyWindow` auf der Erzeugungsseite — erwartete PV-Erzeugung (kWh) in einem
  beliebigen Zeitfenster, für die Netto-Energiebilanz (Bedarf minus Erzeugung) eines EMS. Bewusst
  eigenständig: keine neue Kopplung zu LFC, der Aufrufer kombiniert beide Fenster selbst. Da
  `neighbors` bei PVF (physikbasiert) auch im Erfolgsfall immer 0 ist und daher kein brauchbares
  Realdaten-Signal wie bei LFC liefert, prüft die Funktion stattdessen den internen Modellstatus:
  schlägt die Quelle (API/Netzwerk) fehl oder fehlen Generatoren, zählt `coverage` konsequent 0,
  statt „kwh=0, coverage=1.0" vorzutäuschen. Eigene, additive Vertragsfamilie (`contractVersion` 1.0).
- **Update-Meldepflicht für `EMS_GetSpecialEvents` erfüllt (Verbund-Konvention).** Bisher wurde die
  Rückgabe blind konsumiert; jetzt wird `contractVersion` je Ereignis geprüft. Liefert das EMS eine
  uns unbekannte Major, wird die Kopplung deaktiviert (kein Sondereffekt-Ausschluss mehr, statt
  Felder falsch zu deuten) und **sichtbar** in der Variable *Prognosegüte* gemeldet
  („⚠️ EMS-Vertrag X nicht unterstützt … Modul-Update prüfen"), zusätzlich geloggt. Proaktiv aus dem
  Verbund-Zielbild „Zuverlässigkeit ohne KI-Krücke" abgeleitet, nicht explizit angefragt.
- **Fix: `GetEnergyWindow`-`coverage` täuschte bei unkonfigurierter Instanz Sicherheit vor.** Tage
  ohne echte Prognose (kein Nachbar gefunden, z. B. fehlende Konfiguration) lieferten intern ein
  strukturell gültiges Nullprofil — `coverage` zeigte fälschlich „vollständig abgedeckt" statt
  „keine Daten". Jetzt zählen solche Tage nicht mehr als abgedeckt. Noch am selben Tag proaktiv
  gefunden und behoben, bevor ein Konsument sich auf die vorherige Semantik verlassen konnte.
- **Lastprognose: neue Funktion `LFC_GetEnergyWindow($id,$fromTs,$toTs)`.** Erwarteter Verbrauch
  (kWh) in einem beliebigen Zeitfenster, z. B. „von jetzt bis morgen früh, wenn die PV wieder
  produziert" — Grundlage für ein dynamisches Batterie-Ziel-SoC (EMS) statt eines festen
  Prozentwerts. Summiert slotgenau über bis zu 3 Tage (unser Horizont), mit anteiliger
  Berücksichtigung an den Fensterrändern; `coverage` (0..1) zeigt, welcher Anteil des Fensters
  tatsächlich mit einer ECHTEN Prognose abgedeckt ist — Tage ohne Nachbarn (z. B. unkonfigurierte
  Instanz) zählen NICHT als abgedeckt, auch wenn ihr Nullprofil strukturell gültig ist. Sonst hätte
  eine kaputte Konfiguration einem unbeaufsichtigten Aufrufer „kwh=0, coverage=1.0" statt ehrlich
  „keine Daten" vorgetäuscht. Bewusst ohne PV-Bezug: das Zeitfenster bestimmt der Aufrufer, keine
  neue Abhängigkeit zu PVF. Eigene, additive Vertragsfamilie (`contractVersion` 1.0).
- **Sondereffekt-Ausschluss aus der Lernbasis (`EMS_GetSpecialEvents`, Verbund-Vertrag 1.0).**
  Beide Prognose-Module fragen jetzt — sofern ein NRG-Stack-EMS installiert ist — externe
  Regeleingriffe der letzten 14 Tage ab (§14a-Dimmung, Tibber-Regelenergie, Direktvermarktung,
  EMS-Schutzabschaltung) und schließen betroffene Tage von Prognosegüte (Bias/MAPE) **und** den
  Residuen-Quantilen aus. Ohne diese Effekte hätte ein externer Eingriff fälschlich als
  Prognosefehler gezählt. Standalone-fähig: ohne EMS bleibt das Verhalten unverändert
  (`function_exists`-Guard). Ausgeschlossene Tage werden in der Statuszeile *Prognosegüte* ausgewiesen.
- **Geteiltes Variablenprofil `NRG.Percent` (Verbund-Konvention).** `LFC_ErrorMAPE` und
  `PVF_ErrorMAPE` (Prognosefehler in %) nutzen jetzt das verbund-weite Profil `NRG.Percent`
  (0–100, 1 Nachkommastelle, „ %") statt gar keins. Idempotente Anlage ohne Eigentümer-Modul —
  betrifft nur neu angelegte Instanzen, bestehende Installationen bleiben unverändert.
- **PV-Prognose: Solcast-API-Schlüssel sicher gespeichert (Verbund-Konvention).** Das Formularfeld
  ist jetzt eine `PasswordTextBox` und dient nur der Eingabe; der wirksame Schlüssel liegt in einem
  Attribut (nicht im Formular sichtbar, nicht in Exporten/`IPS_GetConfiguration`). Nach dem
  Speichern wird das Formularfeld automatisch geleert. Bestehende Installationen migrieren beim
  nächsten Speichern automatisch — keine manuelle Aktion nötig, der Schlüssel bleibt erhalten.
- **Sprachregel, zweiter Durchgang (Doku).** Auch in den READMEs ersetzt (API-Schlüssel,
  Temperatur-Abminderung, quelloffen) und die englischen Modulnamen in der Doku auf die tatsächlichen
  deutschen gezogen: *LoadForecast* → **Lastprognose**, *PVForecast* → **PV-Prognose**. Das war nach
  dem Entfernen der englischen Aliase auch sachlich nötig — die Anleitung „Modul-Instanz
  ‚LoadForecast' anlegen" hätte ins Leere geführt. Interne Verweise (Anker) nachgezogen.
- **Vertragsversionierung (Verbund-Konvention).** Die vertragsliefernden Funktionen geben jetzt
  additiv ein Feld `contractVersion` (`Major.Minor`, Start `1.0`) zurück: `PVF_GetForecast`,
  `PVF_GetGenerators`, `PVF_GetSnapshot`, `LFC_GetForecast`, `LFC_GetSnapshot`. Getrennt je
  Vertrags-Familie (Prognose vs. Generatoren), damit ein Bruch der einen die Konsumenten der anderen
  nicht betrifft. Rein additiv — bestehende Felder unverändert. README verweist aufs Suite-Manifest.
- **Sprachregel: nutzersichtbare Texte durchgängig deutsch.** Vermeidbare Anglizismen in
  Beschriftungen, Hinweisen und Log-Meldungen ersetzt (API-Key → API-Schlüssel, Temperatur-Derating →
  Temperatur-Abminderung, Open Source → quelloffen); die englischen Instanz-Aliase (`LoadForecast`,
  `PVForecast`, `EnergyForecastTile`) entfallen zugunsten der deutschen. **Unverändert bleiben**
  Idents, Vertragsfelder (`slots`, `resolution`, `p10`/`p50`/`p90`, `mean`, `kwh` …), Code-Bezeichner
  sowie feststehende Fach- und Produktnamen — Umbenennen dort würde Schnittstellen brechen.
- **PV-Prognose: Unsicherheitsband aus echten Prognosefehlern (optional).** Open-Meteo und
  Forecast.Solar liefern nur eine Linie (`p10 = p50 = p90`) — bisher gab es dort also **gar kein**
  Unsicherheitsband. Aus den gemessenen Abweichungen der letzten Tage entsteht jetzt erstmals ein
  echtes Band (Modi wie bei der Lastprognose: *nur Band* oder *Band + Pegelkorrektur*). Nacht- und
  Dämmerungsslots werden ausgeblendet, da dort die Prognose ≈ 0 ist und das Verhältnis Ist/Soll
  bedeutungslos wäre. Benötigt die `PowerVar` je Generator und mindestens 3 auswertbare Tage.
  Standard unverändert.
- **PV-Prognose: Archivzugriffe abgesichert.** Wie in der Lastprognose wird vor jedem Zugriff der
  Logging-Status geprüft und die Endzeit nie in die Zukunft gesetzt — nicht archivierte
  `PowerVar`-Variablen erzeugen damit keine Warnungen mehr im Meldungsprotokoll.
- **Lastprognose: Unsicherheitsband aus echten Prognosefehlern (optional).** Bisher kam P10/P90 aus
  der Streuung der ähnlichen Tage und war dadurch oft deutlich breiter als der reale Fehler. Neu
  wählbar: Band aus den **gemessenen Abweichungen** der letzten Tage (Snapshot gegen Ist) — dann
  bedeutet P90 tatsächlich „in 90 % der Fälle darunter". Zwei Modi: **nur Band** (Prognosewert und
  kWh bleiben unverändert) oder **Band + Pegelkorrektur** (zieht zusätzlich einen systematischen
  Bias nach). Greift erst ab 3 auswertbaren Tagen und genügend Messpunkten, sonst bleibt der
  Standard aktiv; Korrekturfaktoren sind auf 0,3–3,0 begrenzt. Die wirksamen Faktoren stehen in der
  Variable „Prognosegüte". Standard bleibt unverändert (Modus „Streuung der ähnlichen Tage").
- **Lastprognose: OpenWeatherData-Instanz wählbar (Auto-Modus).** Bei mehreren
  OpenWeatherData-Instanzen lässt sich im Panel „Temperaturvorhersage" die relevante explizit
  auswählen. Leer/nicht gesetzt → wie bisher automatisch die erste; eine gewählte Instanz, die keine
  OpenWeatherData-Instanz ist, wird ignoriert (Fallback auf die erste).
- **Lastprognose: Archiv-Warnungen behoben & nicht archivierte Variablen werden gemeldet.** Nicht
  im Archiv geloggte Variablen (Hauptverbrauch, Abzugsliste, Temperatur, Anwesenheit, WP) lösten pro
  Kandidatentag Warnungen aus („Logging ist für diese Variable nicht verfügbar", „Aggregation aus der
  Zukunft"). Jetzt wird vor jedem Archivzugriff der Logging-Status geprüft (kein Zugriff/keine Warnung
  ohne Logging) und die Endzeit nie in die Zukunft gesetzt. **Wichtig:** Eine nicht archivierte
  **Abzugs-Variable (z. B. Wallbox) kann nicht abgezogen werden** – der Status listet solche Variablen
  jetzt ausdrücklich auf („⚠ nicht archiviert (ignoriert): …"), damit man das Logging gezielt
  aktivieren kann.
- **PV-Prognose: neuer Getter `PVF_GetGenerators($id)`** – stabile Schnittstelle für andere Module
  (v. a. den InverterHub-Monitor): liefert Performance-Ratio, Gesamt-kWp und je Generator
  `name`, `kwp`, `tilt`, `azimuth`, `factor`, `area`. Damit lässt sich aus einer gemessenen
  Einstrahlung (W/m²) die erwartete Leistung berechnen (P = kWp × E/1000 × PR × Faktor), ohne auf
  interne Property-Namen zuzugreifen.
- **README je Modul** (Lastprognose, PV-Prognose, Energiebilanz): Funktionsweise, Voraussetzungen,
  Einrichtung, Statusvariablen, Prognosegüte und öffentliche Funktionen – für die Darstellung im
  Module Store und auf GitHub.
- `library.json`: Feld `compatibility` (mind. IP-Symcon 7.0) ergänzt.
- **PV-Prognose: Modul-Metadaten je Generator** – neue Spalten **Modulanzahl**, **Modullänge** und
  **Modulbreite (mm)**. Die Fläche je Modul wird daraus berechnet (Länge × Breite). Sie fließen nicht
  in die Ertragsprognose ein, sondern ergeben die **Gesamtfläche** (Statusvariable `PVF_ModuleArea`)
  zur Übernahme durch das Modul **InverterHub**. Abruf per `PVF_GetModuleArea($id)` (Gesamt) bzw.
  `PVF_GetModuleAreas($id)` (je Generator: `name`, `modules`, `lengthMM`, `widthMM`, `areaPerModule`,
  `area`).
- **PV-Prognose: Selbstkalibrierung je Generator schaltbar** (neue Spalte „Kalibrieren" in der
  Generatorliste). Für **abgeregelte** Generatoren – z. B. DC-MPPT-Laderegler mit Strom- oder
  Spannungslimit bzw. Batterie-voll-Abregelung – die Kalibrierung ausschalten: Sie liefern dann das
  reine Wetter-**Potenzial** statt der künstlich gedrosselten Messung (sonst lernt die Prognose
  einen dauerhaft zu kleinen Ertrag). Der Hauptschalter bleibt als Master; fehlt die Spalte in einer
  Alt-Konfiguration, ist Kalibrieren wie bisher aktiv.

## 0.19.1

- **Fix: Hintergrundfarbe deckte nicht die ganze Kachel ab.** Die konfigurierte Farbe wurde nur ans
  Diagramm übergeben — Legende, Tagesstreifen und Ränder blieben transparent (im WebView/Popup also
  weiß). Jetzt gilt die Farbe für die gesamte Fläche. Zusätzlich richten sich die **Textfarben nach
  der Helligkeit der gewählten Hintergrundfarbe** (dunkler Hintergrund → helle Schrift und umgekehrt)
  statt nach dem Hell-/Dunkelmodus des Geräts — vorher konnte helle Schrift auf weißem Grund landen.
  Ohne konfigurierte Farbe (transparent) bleibt alles wie gehabt (IPS-Theme/Gerätemodus).

## 0.19.0

- **Energiebilanz als eigenständige Webseite (WebHook)** — für IPSView-Popups und jeden Browser:
  Die Kachel ist jetzt zusätzlich unter `http://<IPS-IP>:3777/hook/energiebilanz<InstanzID>`
  erreichbar (Auto-Aktualisierung alle 30 s per Polling; `?json=1` liefert nur die Daten).
  Einbindung in IPSView: WebView-Element auf einer Popup-Seite mit dieser URL. Der Hook wird
  automatisch registriert; die konkrete URL steht in der Instanz-Doku. Hinweis: WebHooks sind im
  lokalen Netz ohne Anmeldung erreichbar.

## 0.18.2

- **Grafische Hinweise in der Modul-Doku**: Die „📖 Dokumentation & Hilfe"-Panels enthalten jetzt
  erklärende Grafiken (eingebettet, kein Internet nötig):
  - Lastprognose: **P10/P50/P90-Band** erklärt (Obergrenze/Median/Untergrenze, EMS-Nutzung).
  - PV-Prognose: **Azimut-Kompass** (0=Süd, −90=Ost, +90=West) mit Neigungs-Hinweis.
  - Energiebilanz: **Soll/Ist-Legende** (durchgezogen = Prognose, gestrichelt = gemessen,
    Punkt = Momentanwert, Band = Unsicherheit).

## 0.18.1

- **Dokumentation & Hilfe direkt im Modul**: Alle drei Instanz-Formulare haben jetzt ganz oben ein
  eingeklapptes Panel „📖 Dokumentation & Hilfe" (Muster wie im Modul Mittelwertberechnungen) —
  Funktionsweise, Datenquellen, Abzugsliste-Empfehlung, Azimut-Konvention, Quellen-/Lizenzhinweise,
  Erklärung der Prognosegüte (Bias/|Ø-Fehler|) und Praxis-Tipps.

## 0.18.0

- **Prognosegüte-Messung (Soll vs. Ist)** in Lastprognose und PV-Prognose: Bei jeder Neuberechnung
  wird je vergangenem Tag (bis 7 zurück) der Day-Ahead-Snapshot (Soll-kWh) mit dem gemessenen Ist
  aus dem Archiv verglichen — bei der Last identisch zum Prognoseziel (Hauptverbrauch minus
  Abzugsliste), bei PV die Summe der Generator-Leistungen. Neue Variablen je Modul:
  **„Prognosefehler |Ø| (%)"** (mittlerer Betragsfehler) und **„Prognosegüte"** (Text mit Bias =
  systematischer Abweichung und Tagesanzahl). Grundlage für die kommende Bias-Korrektur und das
  Residuen-Band; die Werte füllen sich, sobald Snapshots (ab v0.14) für vergangene Tage vorliegen.

## 0.17.0

- **Automatische W/kW-Erkennung (neuer Standard)**: Die Einheit der Leistungsvariablen wird jetzt
  **je Variable automatisch** erkannt — zuerst über das Variablenprofil (Suffix „W"/„kW"/„MW"),
  sonst über die Größenordnung der Tagesmaxima der letzten 7 Tage (Maximum < 100 ist nur als kW
  plausibel), im Zweifel W. Damit funktionieren auch **gemischte** Installationen (z.B. Hausverbrauch
  in W, Wärmepumpe in kW) ohne Konfiguration. Die manuelle Auswahl W/kW bleibt als Übersteuerung für
  Grenzfälle (Variablen ohne Profil mit ungewöhnlicher Größenordnung) erhalten. Gilt in allen drei
  Modulen (Lastprognose, PV-Prognose, Energiebilanz).

## 0.16.0

- **Einheit der Leistungsvariablen wählbar (W/kW)** — Community-Wunsch: wer seine Leistung seit
  Jahren in kW loggt, muss nichts umkopieren.
  - Lastprognose: ein Schalter für Hausverbrauch, Abzugsliste und Geräte (zentrale Umrechnung).
  - PV-Prognose: Einheit der gemessenen Generator-Leistung (Selbstkalibrierung).
  - Energiebilanz: Einheit der Ist-Leistungsvariablen (Legende, „jetzt"-Punkt, Ist-Verlauf, Ist-kWh).
- **Anwesenheits-Logik invertierbar** — Community-Wunsch: wer eine ABwesenheits-Variable hat
  (true = niemand zu Hause), aktiviert „Logik invertieren"; gilt für Historie und Vorhersage,
  fehlende Tage werden im invertierten Modus korrekt als „anwesend" gewertet.

## 0.15.0

- **„Gestern" im Diagramm** (Energiebilanz-Kachel, Schalter „Gestern mit anzeigen"): links vom
  Heute-Segment wird der Vortag ergänzt — **Soll** aus dem gespeicherten Prognose-Snapshot
  (`LFC_/PVF_GetSnapshot`) und **Ist** als gemessene Kurve (ganzer Tag) aus dem Archiv. So sieht man,
  wie gut die Prognose den Vortag getroffen hat. Der Tagesstreifen zeigt für jeden Tag Soll und (wo
  vorhanden) Ist. Intern auf ein Pro-Tag-Ist-Modell umgebaut; „jetzt"-Marker sitzt weiterhin korrekt
  am Heute-Segment. Beide Engines.
  - Hinweis: Das „Soll" für Gestern erscheint erst, sobald ein Snapshot vom Vortag existiert (baut
    sich ab v0.14 auf); bis dahin zeigt Gestern nur den gemessenen Ist-Verlauf.

## 0.14.0

- **Prognose-Snapshots (Vorbereitung für „Gestern"-Kontrolle):** Lastprognose und PV-Prognose
  speichern bei jeder Neuberechnung die Prognose (Soll) je Tag als Snapshot (Day-Ahead: heute +
  morgen, jeweils nur der früheste Stand pro Datum), begrenzt auf 14 Tage. Damit kann später ein
  vergangener Tag echtes **Soll vs. Ist** zeigen. Abruf über `LFC_GetSnapshot($id, 'Y-m-d')` bzw.
  `PVF_GetSnapshot($id, 'Y-m-d')`. Noch keine Darstellung im Diagramm — die Daten bauen sich erst
  über die nächsten Tage auf.

## 0.13.0

- **Ist-Tageswerte unter den Soll-Werten** (Energiebilanz-Kachel): Unter der Prognose („Soll") für
  **heute** wird jetzt der bisher gemessene Tagesertrag/-verbrauch als „Ist" in kWh angezeigt
  (PV · Verbrauch). Berechnet aus dem gemessenen Tagesverlauf (Integration bis „jetzt"), sobald die
  Ist-Leistungsvariablen konfiguriert sind. Nur „heute" hat Ist-Werte; morgen/übermorgen zeigen nur
  Soll. Der Tagesstreifen reserviert dafür automatisch etwas mehr Höhe. Beide Engines.

## 0.12.2

- **Fix: Tagesprognose unter dem Diagramm war unsichtbar.** Der Streifen mit Tagesname + kWh wurde
  per `style.display = ''` nicht eingeblendet (fiel auf das CSS `display:none` zurück) und rutschte
  zudem unter den Kachelrand. Jetzt explizit sichtbar (`display:block`), und im Höhenbudget der
  eingestellten Diagrammhöhe wird Platz dafür reserviert (Diagramm etwas niedriger, Tagesprognose
  bleibt sichtbar). Beide Engines.

## 0.12.1

- **Zeitachse mit 3-Stunden-Raster** in der Energiebilanz-Kachel: Stunden-Beschriftung (00, 03, …, 21
  je Tag) und vertikales Gitter alle 3 h — der Tagesverlauf ist jetzt ablesbar. Die Tagesnamen + kWh
  sind in den Streifen unter dem Diagramm gewandert (beide Engines), Tagesgrenzen als kräftigere
  Trennlinie. Greift in ECharts wie in Highcharts.

## 0.12.0

- **Wählbare Diagramm-Engine** in der Energiebilanz-Kachel:
  - **ECharts** (Apache-2.0, Default) — kostenlos auch für **kommerzielle** Nutzung.
  - **Highcharts** — nur für **private/nicht-kommerzielle** Nutzung kostenlos.
  Es wird nur die gewählte Library per CDN geladen; beim Umschalten wird das alte Diagramm sauber
  entsorgt. Beide Engines bieten denselben Funktionsumfang (Bänder, Ist-Verlauf, „jetzt"-Marker,
  kWh je Tag, Hover mit Saldo, Live-Werte, Aus-/Einblenden) und ein nahezu identisches Aussehen.
  - Hinweis: Default ist ECharts. Für private Nutzung in der Instanz „Highcharts" wählen.

## 0.11.2

- **Kachel-Layout:** mehr Abstand oben zum (IPS-)Titel, Legende sitzt in einem eigenen Streifen mit
  Abstand zum Diagramm (nicht mehr überlappend), und die **Diagrammhöhe ist einstellbar**
  (`ChartHeight`, Standard 360 px, 180–800).

## 0.11.1

- **Fix Highcharts-Kachel verschwand nach ~10–20 s**: Bei jeder Live-Aktualisierung wurde der
  Diagramm-Container geleert (`innerHTML = ''`), wodurch das anschließende `chart.update()` ins Leere
  lief. Der Container wird jetzt nur noch beim **Neuanlegen** geleert; Aktualisierungen laufen
  in-place (mit Recreate-Fallback bei Fehler). Mehrfach-Updates im Preview verifiziert.

## 0.11.0

- **Energiebilanz-Kachel auf Highcharts umgebaut.** Professionelleres Diagramm mit kontrollierten
  Pixel-Schriftgrößen (behebt das Schriftgrößen-Problem der SVG-Variante), Splines, nativen
  P10/P90-Bändern (`arearange`), schönen Tooltips und nativer Legende. Klick auf einen
  Legendeneintrag blendet die Reihe weiterhin aus/ein (jetzt Highcharts-nativ). Alle bisherigen
  Funktionen erhalten: Ist-Verlauf-Overlay, „jetzt"-Marker + Ist-Punkte, kWh je Tag, Hover mit Saldo,
  Linienstärke/Glättung/Band/Gitter/Y-Achse/Schriftart konfigurierbar.
- **Hinweis Lizenz:** Highcharts wird per CDN (`code.highcharts.com`) geladen, **nicht** im Repo
  mitgeliefert. Highcharts ist für private, nicht-kommerzielle Nutzung kostenlos; kommerzielle
  Nutzung erfordert eine Highcharts-Lizenz (siehe https://www.highcharts.com/license).

## 0.10.0

- **Kachel-Feinschliff (Energiebilanz):**
  - Eigener „Energiebilanz"-Titel **entfernt** — IP-Symcon zeigt den Instanznamen ohnehin; damit gibt
    es nur noch eine Überschrift (kein doppelter, unterschiedlich eingerückter Titel mehr).
  - **kW-Achsenbeschriftung** als senkrechtes Label links — keine Überlappung mit dem obersten
    Achsenwert mehr.
  - **Schriftart wählbar** (System/Arial/Verdana/Tahoma/Trebuchet/Georgia/Courier) und die
    **Schriftgröße** wirkt jetzt auf alle Beschriftungen inkl. heute/morgen/übermorgen; Tagesnamen
    etwas kräftiger.

## 0.9.3

- **Cache für den Ist-Verlauf**: Die (potenziell teure) Integration des gemessenen Tagesverlaufs aus
  dem Archiv läuft nur noch alle `MeasuredCacheSec` Sekunden (Standard 120, einstellbar) statt bei
  jedem Tile-Render. Zwischenzeitliche Renders nutzen das gecachte Profil (Attribut). Der momentane
  Ist-Wert (Legende + „jetzt"-Punkt) bleibt davon unberührt und aktualisiert weiterhin live. Cache
  wird bei Konfig-Änderung, Variablen-/Auflösungswechsel und Tageswechsel automatisch verworfen.

## 0.9.2

- **Ist-Verlauf-Overlay ohne Treppenstufen**: Bei 30/15-min-Auflösung wird der gemessene
  Tagesverlauf jetzt zeitgewichtet aus den Rohwerten integriert (`AC_GetLoggedValues`) statt aus dem
  stündlichen Aggregat hochgerechnet — echte Viertelstunden-Auflösung (Wolkendips u. Ä. sichtbar),
  glatte Linie. 60 min nutzt weiterhin das leichtgewichtige Stundenaggregat.

## 0.9.1

- **Fix Zeitversatz PV-Prognose (Open-Meteo)**: Open-Meteo liefert Strahlung als Mittel der
  *vorangehenden* Stunde (Wert um 13:00 = 12:00–13:00), das IPS-Stundenarchiv ordnet dem
  *Stundenbeginn* zu. Dadurch lag die PV-Prognose ~1 h zu spät. Die Open-Meteo-Werte werden jetzt dem
  Stundenbeginn zugeordnet (`omSlot()`), sodass Prognose und gemessener Tagesverlauf deckungsgleich
  sind. Gegen die Live-API verifiziert (Peak nun am Sonnenmittag).

## 0.9.0

- **Gemessener Tagesverlauf als Overlay** in der Energiebilanz-Kachel: der heutige Ist-Verlauf von
  PV und Verbrauch wird als gestrichelte Linie über die Prognose gelegt (aus dem Archiv, stündlich
  aufs Prognoseraster gebracht) — Prognose gegen Realität über den ganzen Tag. Je Reihe per Schalter
  ein-/ausschaltbar (`ShowActualPV` / `ShowActualLoad`).
- **Ein-/Ausblenden direkt im WebFront**: Klick auf einen Legendeneintrag blendet die jeweilige Reihe
  (inkl. Band, Ist-Linie und kWh) live aus bzw. ein; ausgeblendete Reihe wird gedimmt. Die Achse
  skaliert auf die sichtbaren Reihen.

## 0.8.0

- **Ist-Werte in der Energiebilanz-Kachel**: optionale Variablen „Ist-PV-Leistung (W)" und
  „Ist-Hausverbrauch (W)". Die momentane Leistung erscheint live in der Legende und als Punkt auf der
  „jetzt"-Linie — Prognose gegen Realität auf einen Blick. Live-Update per `VM_UPDATE`; respektiert
  die Anzeige-Schalter.
- **PVPrognose: wählbare Auflösung 60/30/15 min** (`PVF_Resolution`), deckungsgleich zur Lastprognose.
  Die Wetterquellen liefern stündlich; feinere Stufen werden interpoliert (glatterer Verlauf, gleiche
  Tagessumme — verifiziert).
- **2 Nachkommastellen** für die kWh-Werte je Tag und die Ist-Werte in der Kachel.

## 0.7.0

- **Modul `LastprognoseKachel` entfernt** — die Energiebilanz-Kachel deckt den Last-only-Fall ab und
  ist die fähigere Kachel. Bestehende LastprognoseKachel-Instanzen bitte durch eine Energiebilanz-
  Instanz mit „PV-Erzeugung anzeigen" = aus ersetzen.
- **Energiebilanz: Anzeige-Schalter** „PV-Erzeugung anzeigen" und „Verbrauch anzeigen". Damit lässt
  sich dieselbe Kachel als reine PV-, reine Verbrauchs- oder kombinierte Ansicht nutzen — auch wenn
  beide Quell-Instanzen vorhanden sind.

## 0.6.0

- **Konsistente Namensgebung** (nur Anzeigenamen; Prefixe `LFC_`/`PVF_`/`EFTILE_` und GUIDs bleiben,
  damit bestehende Instanzen und EMS-Aufrufe weiterlaufen):
  - Bibliothek → **Prognose**
  - LoadForecast → **Lastprognose** (Alias „Last-Prognose")
  - PVForecast → **PVPrognose** (Alias „PV-Prognose")
  - LoadForecastTile → **LastprognoseKachel** (Alias „Last-Prognose (Kachel)")
  - EnergyForecastTile → **Energiebilanz** (Alias „Energieprognose")
  - (Modulname = PHP-Klassenname; Bindestriche daher nur als Alias möglich.)
- **Energiebilanz-Kachel konfigurierbar**: Linienstärke, Kurvenglättung (Catmull-Rom — gegen die
  kantigen Linien), Unsicherheitsband ein/aus + Transparenz, Gitter/Achsen ein/aus, Y-Achse manuell
  begrenzbar.
- **kWh je Tag** statt Gesamtsumme: jeder Tag zeigt seine eigene erwartete PV- und Verbrauchs-kWh
  unter dem Tagesnamen; die Legende ist auf den Farbschlüssel reduziert.

## 0.5.0

- **Bibliothek zur Energieprognose-Suite erweitert** (Last + PV in einem Repo). Zwei neue Module:
- **PVForecast** (Typ 3, Prefix `PVF`) — **physikbasierte** PV-Erzeugungsprognose statt Mustersuche:
  - Pro PV-Generator Anlagengeometrie (Neigung, Azimut, kWp); Generatoren werden zur Gesamt-PV summiert.
  - **Wählbare Vorhersagequelle**: Open-Meteo (kostenlos, kein Key, geneigte Einstrahlung →
    `kWp × GTI/1000 × PR` mit Temperatur-Derating), Forecast.Solar (liefert Leistung direkt) oder
    Solcast (API-Key, inkl. P10/P90).
  - **Selbstkalibrierung** (Open-Meteo): vergleicht gemessene mit aus vergangener Einstrahlung
    modellierter Erzeugung und lernt je Generator einen Korrekturfaktor (Verschattung, Verschmutzung,
    reale Leistung). Plus manueller Korrekturfaktor je Generator.
  - Stündliche Ausgabe P10/P50/P90 + kWh für heute/morgen/übermorgen, per `PVF_GetForecast($id, $offset)`.
  - Leistungsrechnung gegen die echte Open-Meteo-API plausibilisiert.
- **EnergyForecastTile** (Typ 3, Prefix `EFTILE`) — kombinierte Kachel: PV-Erzeugung und Verbrauch
  als zwei Bänder in einem Diagramm, Auto-Erkennung beider Quellen, Hover/Touch mit **Saldo**,
  funktioniert auch PV-only.

## 0.4.0

- **Wählbare zeitliche Auflösung** (60 / 30 / 15 Minuten, Modellparameter). 60 min nutzt weiterhin
  das robuste Stundenaggregat; 30/15 min werden zeitgewichtet aus den Rohwerten integriert
  (`AC_GetLoggedValues`). Slots, Profile, Perzentile und kWh rechnen jetzt durchgängig mit der
  Slot-Dauer. Hinweis: feinere Auflösung braucht entsprechend feine Archivierung über den
  Historie-Zeitraum; Tage ohne Rohdaten werden übersprungen.
- **Regionale Feiertage**: Auswahl des Bundeslands (Modellparameter). Zusätzlich zu den bundesweiten
  Feiertagen werden die landesspezifischen berücksichtigt (Heilige Drei Könige, Fronleichnam,
  Mariä Himmelfahrt, Reformationstag, Allerheiligen, Buß- und Bettag, Frauentag, Weltkindertag).
  Verbessert die Tagtyp-Zuordnung und damit die Ähnliche-Tage-Suche.
- **Separate Geräteprognose jetzt für heute/morgen/übermorgen** (`LFC_WPkWhToday/Tomorrow/DayAfter`)
  statt nur morgen. Variablen umbenannt auf „WP/Klima".
- Die Kachel ist resolutionsunabhängig: Tooltip-Uhrzeit und „jetzt"-Marker rechnen minutengenau aus
  der Slot-Anzahl (z.B. „Morgen 18:15" bei 15-min-Auflösung).

## 0.3.2

- **Kachel: Hover-/Touch-Tooltip** im Diagramm. Beim Überfahren (Maus oder Touch — wichtig für
  Wandtablets) erscheint ein Fadenkreuz mit Punkten auf P10/P50/P90 und ein Wertefeld mit Tag,
  Uhrzeit, erwartetem Wert (P50) und Bandbereich (P10–P90).

## 0.3.1

- **Fix Kachel blieb leer:** `GetVisualizationTile()` übergibt die Daten als JSON-**String**;
  `handleMessage()` interpretierte ihn aber als bereits geparstes Objekt → kein Inhalt. `handleMessage`
  parst den String jetzt (wie das Tibber-Kachelmodul): `typeof payload === 'string' ? JSON.parse(...)`.

## 0.3.0

- **Separate Geräteprognose auf Heizen/Kühlen erweitert** (für Luft-Luft-WP/Klimaanlagen):
  - Das frühere Einzelfeld „Wärmepumpe" ist jetzt eine **Geräteliste** mit je eigener Betriebsart
    (Heizen / Kühlen / beides). Pro Gerät wird eine eigene Regression gefittet, die Summe ergibt die
    Gesamtprognose (`LFC_WPkWhTomorrow`).
  - Betriebsart „Heizen + Kühlen" nutzt eine **V-Kurve**: `kWh = a + b·Heizgrad + c·Kühlgrad`
    mit getrennten Knickpunkten (Heiz- und Kühlgrenztemperatur). Damit wird ein Gerät, das im
    Winter heizt und im Sommer kühlt, in beide Richtungen korrekt prognostiziert.
  - Neue Kühlgrenztemperatur (`LFC_CDD_Base`, Standard 22 °C); Heizgrenze wie bisher (15 °C).
  - Gelöst über die Normalgleichungen mit Gauß-Elimination; bei singulärer Datenlage (z.B. kein
    Kühlbedarf in der Historie) wird das Gerät sauber übersprungen.
  - **Abwärtskompatibel**: eine bestehende Einzelfeld-Konfiguration wirkt übergangsweise als
    Heiz-Gerät weiter, bis sie in der Liste eingetragen wird.

## 0.2.0

- Neues, eigenständiges Kachel-Modul **LoadForecastTile** (Typ 3, Prefix `LFCTILE`) in derselben
  Bibliothek (Aufbau wie das Tibber-Kachelmodul):
  - Randlose HTML-SDK-Kachel (`SetVisualizationType(1)` + `GetVisualizationTile()` + `module.html`).
  - Zeichnet das **P10/P50/P90-Band** der nächsten 1–3 Tage als selbst gerendertes SVG-Diagramm
    (Median-Linie + Unsicherheitsfläche), ohne externe Chart-Library — läuft offline in der Kachel.
  - Tagestrenner, kWh je Tag als Chips, „jetzt"-Marker auf der aktuellen Stunde.
  - Findet die `LoadForecast`-Quelle automatisch (`IPS_GetInstanceListByModuleID`), aktualisiert
    sich per `VM_UPDATE` der Prognose-Variablen.
  - Theme-konform (transparent/automatische Textfarbe), Akzentfarbe/Hintergrund/Schriftgröße
    einstellbar, Button „auf Standard zurücksetzen".

## 0.1.0

- Erstes öffentliches Gerüst des Moduls **LoadForecast** (Typ 3, Prefix `LFC`):
  - 1–3-Tage-Verbrauchsprognose aus dem IPS-Archiv über ein **Ähnliche-Tage-Verfahren (k-NN)**
    mit Feature-Vektor: Tagtyp (Werktag/Sa/So·Feiertag), Tageslänge (Saison-Proxy aus Standort),
    Heizgrad aus Außentemperatur und Anwesenheit.
  - Ausgabe als stündliches Profil mit **Unsicherheitsband P10/P50/P90** und Tagessumme (kWh),
    als JSON-Variablen sowie per `LFC_GetForecast($id, $offset)` für das EMS abrufbar.
  - Optional abziehbare Verbraucher (Wärmepumpe, Wallbox) → reine planbare Grundlast.
  - Optionale separate **Wärmepumpen-Prognose** über lineare Temperaturregression (Heizgrad).
- **Temperaturvorhersage modul-agnostisch** (läuft auf jedem System, keine Instanz-ID hartcodiert):
  - Auto-Modus: findet eine `OpenWeatherData`-Instanz (demel42) automatisch per Modul-GUID und
    aggregiert die 3h-Slots zu Tagesmitteln.
  - Tagesmittel-Variablen oder freie Ident-Muster als Alternativen für andere Wettermodule.
  - **Klimatologie-Fallback**: saisonales Normal (gleicher Kalendertag ±7 Tage über Vorjahre)
    aus dem Temperatur-Archiv, wenn keine Vorhersage verfügbar ist.
