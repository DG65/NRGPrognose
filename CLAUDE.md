# Hinweise für Claude — Repo DG65/NRGPrognose (vorher DG65/Prognose)

## Was hier liegt

Die **EnergiePrognose-Suite** für IP-Symcon mit drei Modulen. Dachmarke des Gesamtverbunds (10 Module)
ist **NRG-Stack** (Dietmar, 23.07.2026); „DG65" bleibt Hersteller/Org. Unsere drei Module sind die
Prognose-Schicht („Wissen") innerhalb des NRG-Stack. Nur Doku/Anzeige — Idents/Verträge/Klassennamen
unberührt.

| Ordner | `module.json name` / PHP-Klasse | Präfix | GUID |
|---|---|---|---|
| `Lastprognose/` | `Lastprognose` (unverändert; `NRGLastprognose` nur als Alias) | `LFC` | `{DC5AD508-507F-40EA-8630-0959AED83050}` |
| `PVPrognose/` | `PVPrognose` (unverändert; `NRGPVPrognose` nur als Alias) | `PVF` | `{257DD4E8-9705-462E-89FC-56D0A1038353}` |
| `Energiebilanz/` | `Energiebilanz` (unverändert; `NRGEnergiebilanz` nur als Alias) | `EFTILE` | `{481CBE19-C8D9-4B72-B13F-0D249006B709}` |

**Modul-Umbenennung auf NRG-Präfix (Verbund-Konvention, Dietmar 25.07.2026) — `library.json`
umgesetzt, `module.json`/Klasse NICHT.** `library.json name` → `NRGPrognose` (sicher, reines
Anzeigefeld ohne Klassenbezug). `module.json name` je Submodul dagegen **unverändert** gelassen
(`Lastprognose`/`PVPrognose`/`Energiebilanz`), der neue NRG-Name nur zusätzlich in `aliases`.

**Korrektur-Historie (25.07.2026, wichtig):** Ursprünglich hatten wir `module.json name` UND die
PHP-Klasse konsistent auf `NRGLastprognose` usw. umgestellt (in der Annahme, das sei der sichere Weg,
da ChargerHub `name` geändert hatte, ohne die Klasse anzupassen). Kurz danach live verifiziert: Beim
tatsächlichen Neuverknüpfen (`MC_DeleteModule`+`MC_CreateModule`) brach die Instanz bei **mehreren**
Verbund-Modulen (ChargerHub, MigrationsHub, InverterHub, InverterHubTile) mit „Class ... does not
exist" — **unabhängig davon, ob Name und Klasse zueinander passten.** Für bereits registrierte
Module gilt Regel 4 (`ips-module-pitfalls`) also uneingeschränkt bei JEDEM Neuladen, nicht nur bei
der Allererstregistrierung. Deshalb umgehend zurückgerudert: `module.json name` und die PHP-Klasse
wieder auf den Original-Zustand, NRG-Name bleibt nur als Alias. GUID, Präfix (`LFC`/`PVF`/`EFTILE`),
Idents waren durchgehend unangetastet.

## Release-Workflow

- ⚠️ **TEMPORÄR (seit 25.07.2026, Dietmar):** Während der laufenden EMS-Integrationsphase geht
  **ausschließlich auf `ems-integration`** — keine Ausnahme mehr für "sichere" Fixes auf `beta`,
  bis anders angesagt. Arbeitsbranch also `ems-integration`, nicht `beta`, solange diese Zeile steht.
- Normalerweise (außerhalb der Integrationsphase) läuft Entwicklung auf dem Branch **`beta`**
  (= IPS-Beta-Kanal), Version trägt das Suffix `-beta`.
- **Stable gibt der Nutzer frei** — nicht ungefragt nach `main` mergen/pushen.
- **Vor jedem beta→main-Wechsel: Neuinstallations-Simulation** (SUITE.md/EMS-Repo, Commits `875fe1d`+
  `630e15a`, Grundregel "keine eigene Anlage als Norm annehmen"). Formular komplett durchgehen wie ein
  Nutzer OHNE Dietmars Hardware/Region/Sonderkonfiguration: Fabrikat-Nennungen nur als „z. B.", klar
  ob ein Feld automatisch (Discovery/Vertrag) oder manuell kommt, kein Default, der eine eigene
  Sonderkonfiguration widerspiegelt, Volltextsuche nach eigenen Objekt-/Variablen-IDs/PLZ/Kampagnen-
  namen. **Erstmals durchgeführt 28.07.2026** (build 59): keine Fabrikat-/Auto-Discovery-Verstöße
  gefunden (unsere „(Pflicht)"-Panels sind herstellerneutral und ohne Discovery-Alternative; Lat/Long-
  Default 49.0/9.0 ist generisch, keine eigene Adresse; keine eigenen IDs im Code). Einzige Baustelle
  war Feld-Klarheit für Laien, siehe „Korrektur"-Spalte in PVPrognose weiter unten in der Historie.
- `library.json` darf **nur diese 8 Felder** enthalten (sonst lehnt der Module Store ab:
  „Zu viele Eigenschaften"): `id`, `author`, `name`, `url`, `compatibility`, `version`, `build`, `date`.
  `compatibility` im Format `{"version": "7.0"}`.
- **Build bei jeder Änderung hochzählen**, nie überschreiben.

## Zusammenarbeit mit anderen Repos

Dieses Repo wird von **[DG65/NRGInverterHub](https://github.com/DG65/NRGInverterHub)** konsumiert
(`InverterHubMonitor` berechnet aus Einstrahlung × Generatorparametern Erwartungswerte und
vergleicht sie mit dem gemessenen Ertrag → Verschmutzungs-/Defekterkennung).

### Vertrag: die `PVF_Get*`-Funktionen sind öffentliche API

| Funktion / Objekt | Rückgabe | Von InverterHub genutzt |
|---|---|---|
| `PVF_GetGenerators($id)` | `{pr, totalKwp, generators:[{name,kwp,tilt,azimuth,factor,area}]}` | ✅ primär |
| `PVF_GetModuleArea($id)` | Gesamt-Modulfläche in m² (float) | ✅ |
| `PVF_GetModuleAreas($id)` | Fläche je Generator `[{name,modules,lengthMM,widthMM,areaPerModule,area}]` | – |
| `PVF_GetForecast($id,$offset)` | Prognoseprofil (0=heute,1=morgen,2=übermorgen) | – |
| `PVF_GetSnapshot($id,'Y-m-d')` | Gespeicherte Day-Ahead-Prognose eines Tages | – |
| Statusvariable `PVF_ModuleArea` | Gesamtfläche (m²) | ✅ Fallback |
| Property `PVF_PR` (via `IPS_GetConfiguration`) | Performance-Ratio | ⚠️ Alt-Fallback, siehe unten |

**Vertragsversionierung** (Verbund-Konvention, additiv): Jede vertragsliefernde Funktion gibt ein Feld
`contractVersion` (String `Major.Minor`, Start `1.0`) zurück — **Major nur bei Bruch**, Kompatibilität
nur innerhalb derselben Major (blue'Log-Prinzip); fehlt das Feld, gilt `1.0`. Getrennt je Familie:

- `PVF_CONTRACT_FORECAST` (`GetForecast`, `GetSnapshot`) und `LFC_CONTRACT_FORECAST` (`GetForecast`,
  `GetSnapshot`) — Prognoseprofil.
- `PVF_CONTRACT_GENERATORS` (`GetGenerators`) — Generatorparameter.
- `GetModuleArea` liefert ein Skalar (float) und kann kein Feld tragen — Version dort über
  `GetGenerators`. `GetModuleAreas` liefert eine flache Liste (unverändert, additive Feld-Ergänzung
  bräche die Struktur) — Version ebenfalls über `GetGenerators`.
- `LFC_CONTRACT_ENERGYWINDOW` (`GetEnergyWindow`) — Verbrauchsprognose in einem beliebigen
  Zeitfenster (z. B. für EMS: dynamisches Batterie-Ziel-SoC statt fixem Prozentwert, „wie viel
  Energie wird bis zum PV-Start morgen früh gebraucht"). Bewusst **ohne PV-Bezug**: Das Fenster
  (`$fromTs`/`$toTs`) bestimmt der Aufrufer, keine Abhängigkeit von PVF in LFC. `coverage` (0..1)
  zeigt an, welcher Anteil des Fensters innerhalb unseres 3-Tage-Horizonts liegt — UND nur, wenn
  in dem Zeitraum echte Prognosedaten vorlagen (kein Nachbar gefunden ⇒ nicht als abgedeckt
  gezählt, auch wenn das Nullprofil strukturell gültig ist).
- `PVF_CONTRACT_ENERGYWINDOW` (`GetEnergyWindow`) — symmetrisches Gegenstück auf der
  Erzeugungsseite, eigenständig (keine Kopplung zu LFC; Netto-Bilanz bildet der Aufrufer aus
  beiden Fenstern selbst). `neighbors` ist bei PVF **kein** brauchbares Realdaten-Signal (auch
  im Erfolgsfall immer 0, physikbasiert statt k-NN) — stattdessen prüft `GetEnergyWindow` den
  internen `modelCache`-Zustand: schlägt `buildModel()` fehl (API/Netzwerk) oder fehlen
  Generatoren, zählt nichts als `coverage`, statt `kwh=0, coverage=1.0` vorzutäuschen.

Getrennte Familien sind Absicht: Ein Bruch von `GetForecast` darf InverterHub (nutzt `GetGenerators`)
nicht fälschlich zur Deaktivierung zwingen.

**Regeln:**

1. Ändern sich **Signatur oder Rückgabestruktur** dieser Funktionen, muss die InverterHub-Seite
   vorher informiert werden (sie zieht `InverterHubMonitor/module.php` nach). Umgekehrt meldet sich
   InverterHub, bevor neue Bedarfe entstehen.
2. **Interne Umbauten sind frei**, solange die obige Rückgabestruktur stabil bleibt. Beispiel: Die
   Modulfläche wird seit Build 40 aus **Länge × Breite (mm)** statt aus einem Flächenfeld berechnet —
   für `PVF_GetGenerators`/`PVF_GetModuleArea` war das transparent.
3. `PVF_PR` wird von InverterHub nur als **Fallback für ältere Versionen** über
   `IPS_GetConfiguration` gelesen. Aktuell liefert `PVF_GetGenerators()` das Performance-Ratio bereits
   als Feld `pr` — der Zugriff auf die Property ist also nicht mehr nötig.
4. **Zuständigkeit:** Dieses Repo gehört uns, InverterHub der dortigen Sitzung. Änderungen, die beide
   betreffen, vorher ankündigen. Keine eigenmächtigen Commits im jeweils anderen Repo.

## Commit-Hygiene (wichtig — geteiltes Repo!)

Es committen **mehrere Sitzungen** in dieses Repo (der Getter `PVF_GetGenerators`, Build 41, kam aus
der InverterHub-Sitzung). Deshalb:

- **Kein `git add -A`** — nur die eigenen, bewusst geänderten Dateien stagen. Sonst landen fremde,
  in Arbeit befindliche Änderungen im eigenen Commit.
- **Vor dem Commit** `git fetch` / `git pull --rebase` und `git log` prüfen; Build-Nummer auf dem
  tatsächlich aktuellen Stand hochzählen.

## Sprachregel: Nutzersichtbares ist Deutsch

Verbund-Regel (von Dietmar angeordnet, 22.07.2026). **Deutsch** ist alles, was der Nutzer sieht:
Formularbeschriftungen, Hinweis- und Warntexte, Fehler- und **Statusmeldungen**, **Log-Meldungen**,
Rückgabe-Texte, Variablen- und Profilnamen, Dokumentation/README. Vermeidbare Anglizismen ersetzen
(Dry-Run → Probelauf, Event → Ereignis, Scan → Suche, API-Key → API-Schlüssel, Open Source →
quelloffen, Derating → Abminderung).

**Ausgenommen — nicht umbenennen, das bricht Verträge:**

- **Idents sind API**: `LFC_Today`, `PVF_ModuleArea`, `PVF_LastUpdate` usw. bleiben.
- **Vertragsfelder** der `PVF_Get*`/`LFC_Get*`-Rückgaben: `slots`, `resolution`, `p10`, `p50`, `p90`,
  `mean`, `kwh`, `area`, `lengthMM` …
- **Code-Bezeichner**: Klassen-, Methoden-, Variablen- und Property-Namen.
- **Feststehende Fachbegriffe und Produktnamen**: IP-Symcon-Elementtypen (`SelectVariable`, `Button`,
  `CheckBox`), WebFront, Modbus TCP, JSON, Open-Meteo, Forecast.Solar, Solcast, ECharts, Highcharts,
  Performance-Ratio.

## Emojis (Verbund-Regel, Dietmar 23.07.2026)

Emojis sind **erwünscht, wo sie Nutzen stiften** — eine frühere „keine Emojis"-Vorsichtsregel ist
aufgehoben:

- **Panel-Icon**: EIN Zeichen am Anfang einer ExpansionPanel-Überschrift (📖 🔮 ⚙️ 🎯 📊), als Ersatz
  fürs fehlende `icon`-Feld.
- **Status-/Aufmerksamkeitssymbol** (✅ ❌ ⚠️ 💡 ℹ️) dort, wo etwas beim Lesen Fokus braucht —
  Statusmeldungen, Warnungen, wichtige Hinweise (z. B. „⚠ nicht archiviert (ignoriert): …").

Faktenlage: Kein Symcon-Store-Review hat Emojis je beanstandet. **Beobachtungsklausel:** Sollte ein
Stable-Review sie doch bemängeln, entscheidet der Verbund neu (Rückfall: gemeinsam emoji-frei).

## Zugangsdaten (Verbund-Konvention, Dietmar 23.07.2026)

Für jedes Modul mit Cloud-/API-Zugang:

1. **Handshake-/Token-Verfahren bevorzugen**, wenn die API es anbietet — Passwort dient nur dem
   einmaligen Handshake und wird danach nicht gespeichert, nur das Token/Secret bleibt liegen.
2. Passwörter/Schlüssel werden nur dauerhaft gespeichert, wenn sie **wirklich wiederholt** gebraucht
   werden (z. B. ein statischer API-Schlüssel ohne Token-Austausch — kein Handshake-Weg verfügbar,
   also Rückfall auf dauerhafte Speicherung erlaubt).
3. **Speicherort: `RegisterAttributeString` (nicht Property)** — nicht im Formular sichtbar, nicht in
   Exporten/`IPS_GetConfiguration`.
4. Technischer Vorbehalt: IP-Symcon verschlüsselt **nicht** at rest. „Sicher" heißt „nicht im
   Formular/Log/Anzeigetext sichtbar", nicht „verschlüsselt".
5. **Formulareingabe:** `PasswordTextBox`, Wert nach dem Speichern **sofort geleert**.

**Umsetzung bei uns (PVF_SolcastKey, Beispiel für statische API-Schlüssel ohne Handshake):** Die
Property bleibt als reines Eingabefeld (`PasswordTextBox`). In `ApplyChanges()` wird ein neu
eingegebener Wert sofort in ein Attribut (`PVF_SolcastSecret`) übernommen; das Leeren der Property
passiert **nicht rekursiv innerhalb von `ApplyChanges()`**, sondern über einen Einmal-Timer
(`SetTimerInterval(…, 1)`), der `ClearSolcastKey()` als eigenständigen Top-Level-Aufruf auslöst —
dort ist `IPS_SetProperty()` + `IPS_ApplyChanges()` auf die eigene Instanz unproblematisch, weil er
nicht im selben Call-Stack wie der ursprüngliche `ApplyChanges()`-Aufruf steckt. Lesende Zugriffe
gehen über `solcastKey()`: Attribut zuerst, Property nur als Fallback für den kurzen Moment vor dem
Timer-Tick. Bestehende Installationen migrieren beim nächsten `ApplyChanges()` automatisch.

## Gemeinsame Variablenprofile `NRG.*` (Verbund-Konvention, Dietmar 23./24.07.2026)

Physikalische Grundgrößen bekommen EIN geteiltes Profil (`NRG.Watt`, `NRG.kWh`, `NRG.Ampere`,
`NRG.Volt`, `NRG.Percent`, `NRG.Celsius`) statt je Modul ein eigenes — bewusst klein gehalten, siehe
[SUITE.md](https://github.com/DG65/NRGEMS/blob/main/SUITE.md). **Kein Eigentümer-Modul:** jedes prüft
`IPS_VariableProfileExists(...)` und legt nur an, falls es fehlt (Muster aus GleitenderMittelwert).

**Bei uns umgesetzt:** `ensureNrgPercentProfile()` (in LFC und PVF je einmal, idempotent) legt
`NRG.Percent` an (Bereich 0–100, 1 Nachkommastelle, Suffix „ %") und wird für `LFC_ErrorMAPE` /
`PVF_ErrorMAPE` verwendet — die hatten vorher gar kein Profil. Wirkt nur bei **neu angelegten**
Variablen (IPS setzt das Profil-Argument von `RegisterVariableFloat` nur bei der Erstanlage);
bestehende Installationen erhalten es nicht automatisch rückwirkend — bewusst so belassen (keine
funktionale Auswirkung, nur Formatierung; kein Grund, in bestehende Nutzerkonfiguration einzugreifen).

**Bewusst NICHT umgestellt:** Die `kWh`-Variablen (`LFC_kWhToday` etc.) bleiben beim IPS-Systemprofil
`~Electricity` statt auf ein neues `NRG.kWh` zu wechseln. Begründung: `~Electricity` ist bereits
verbund-*und*-IPS-weit geteilt (kein Modul-eigenes Duplikat, das Problem, das `NRG.*` lösen soll),
gut etabliert, und ein Wechsel würde die Anzeige bei bereits laufenden Beta-Installationen ohne
funktionalen Gewinn verändern. `Watt`/`Ampere`/`Volt`/`Celsius` kommen bei uns aktuell nirgends als
eigenständige IPS-Variable vor (nur als `unit`-Feld in JSON-Nutzlasten) — kein Handlungsbedarf.

## Fachliche Leitplanken

- **Trennung Prognose ↔ EMS:** Die Prognose sagt die *unbeeinflussbare* Nachfrage/Erzeugung vorher.
  Preis (Tibber), SoC und Ladeentscheidungen gehören in den **EMS-Optimierer**, nicht in die Prognose.
- **Abzugsliste (LFC):** Steuerbare Lasten (Wallbox, Batterie) werden abgezogen, damit die planbare
  **Grundlast** gelernt wird. Diese Variablen müssen **archiviert** sein.
- **Abgeregelte PV-Generatoren** (DC-MPPT mit Strom-/Spannungslimit): Selbstkalibrierung je Generator
  abschalten → das Modell liefert das **Potenzial** statt der gedrosselten Messung.
- **Sondereffekt-Ausschluss (`EMS_GetSpecialEvents`, Vertrag 1.0, final seit 24.07.2026):** In
  `evaluateAccuracy()` (LFC + PVF) holt `fetchSpecialEvents(14)` einmal die Ereignisse der letzten
  14 Tage (`EMS_GetSpecialEvents(0, $from, $to)`, `deviceId=0` = ganze Anlage), `dayHasSpecialEvent()`
  schließt überlappende Tage komplett von Bias/MAPE **und** den Residuen-Quantilen aus — sonst würde
  ein externer Eingriff (§14a, Regelenergie, Direktvermarktung) fälschlich als Prognosefehler gelernt.
  Aufruf steht hinter `function_exists('EMS_GetSpecialEvents')` (Eigenständigkeitsregel!) — ohne EMS
  ist die Ereignisliste leer, Verhalten unverändert. `to=0` bedeutet „noch andauernd" → Überlappung
  bis `time()`. Kein Echtzeit-Anspruch nötig, da unsere Auswertung ohnehin rückblickend läuft.

## Formular-Optik (Verbund-Standard, Dietmar 24.07.2026)

Betrifft alle drei Formulare (Lastprognose/PVPrognose/Energiebilanz). Referenzimplementierung:
InverterHub. Details/Wortlaut: [SUITE.md](https://github.com/DG65/NRGEMS/blob/main/SUITE.md), Abschnitt
„Einheitliche Formular-Optik".

**PFLICHT-CHECK bei JEDEM Fix/Update** (nicht nur bei großen Releases, Ergänzung 24.07.2026): Bei
jeder Änderung an einem der drei Module kurz prüfen — „gehört das ins Neu-Panel? Ändert sich die
Versionsnummer im Doku-Panel? Braucht ein neues/geändertes Feld ein `🆕`-Präfix?" Ergebnis darf
„nein" sein, aber die Prüfung selbst darf nicht ausfallen. Ein **vollständiger Umbau aller drei
Formulare auf die Zielstruktur unten** bleibt trotzdem eine bewusste, angekündigte Aktion (nicht
nebenbei in einem Bugfix-Commit) — bislang noch nicht durchgeführt.

**Zielstruktur, Reihenfolge von oben:**

1. **„🆕 Neu in Version X.Y"** — aufgeklappt, pro Version dismissible (Attribut speichert die zuletzt
   bestätigte Version, erscheint bei neuer Version wieder). Keine Versionsnummer in der Caption hier.
2. **„📖 Dokumentation & Hilfe"** — eingeklappt (bestehendes Muster bleibt); die Versionsnummer gehört
   hierher (nicht ins Neu-Panel).
3. Fachpanels; neue/wichtige Felder mit `🆕`-Präfix im Label markieren.
4. Symcon-Forum-Hinweis nach den Haupteinstellungen, einmalig dismissible.

**Allgemeine Layout-Qualität** (Ergänzung 24.07.2026, gilt immer, nicht nur beim Zielumbau): logische
Gruppierung zusammengehöriger Felder, Step-by-Step-Lesefluss ohne Scroll-Zickzack (kein Springen
zwischen entfernten Panels für zusammengehörige Einstellungen), Feldkanten/-breiten einer Spalte auf
einer Linie statt kreuz und quer — bei jeder Formular-Änderung mitdenken, auch außerhalb des
Zielumbaus oben.

**Feld-Tooltips (Ergänzung 27.07.2026, präzisiert 28.07.2026):** IP-Symcon kennt kein natives
Mouseover-Tooltip-Attribut (gegen offizielle Doku geprüft, weder `form.json` allgemein noch
List-Spalten). Für erklärungsbedürftige Einzelfelder: `PopupButton` (Klick statt Hover) für die
fokussierte Erklärung EINES Felds, mit `caption="?"` (reiner Buchstabe, kein Emoji) und
`width="70px"` — InverterHub hat live getestet, dass `width` unter ~70px keinen sichtbaren Effekt
hat (WebFront-Skin erzwingt eine Mindestbreite) und Icon-Größe/Hintergrund grundsätzlich nicht
änderbar sind (globaler Skin); `"?"` + `70px` ergibt eine quadratisch wirkende Fläche
(SUITE.md-Konvention, finale Fassung Commit `6bb6975` — ein Zwischenstand hatte kurzzeitig `"i"`
vorgesehen, Dietmars Entscheidung: `"i"` wirkt bei 70px optisch verloren). Der Button steht
typischerweise in einer `RowLayout` neben einem kurzen Kontext-`Label`, damit das bloße „?" nicht
ohne Bezug dasteht. `Label` bleibt für kurze, immer sichtbare Erklärungen; das „Dokumentation &
Hilfe"-Panel bleibt für den Gesamtzusammenhang.
**Bei uns umgesetzt:** PV-Generatorliste, Spalte „Kalibrieren" — war bisher nur in zwei benachbarten
Absätzen (Panel-Label davor, Kalibrierungs-Panel danach) erklärt, nicht direkt am Feld. Jetzt
zusätzlich ein `PopupButton` direkt unter der Liste. Andere Felder in allen drei Formularen geprüft:
bereits ausreichend durch vorhandene `Label`-Elemente abgedeckt, kein weiterer Bedarf gefunden.


## Verbund-Manifest SUITE.md — Bezugsquelle (geändert 31.08.2026)

SUITE.md liegt seit 31.08.2026 NICHT mehr in einem GitHub-Repo (die
Modul-Repos sind öffentlich, SUITE.md enthält das komplette Architektur-/
Debugging-Know-how des Verbunds — Dietmars Entscheidung). Primärquelle ist
ausschließlich die lokale Datei `/Users/dietmar/Nextcloud/Claude/SUITE.md`
auf Dietmars Maschine, versioniert in einem eigenen lokalen Git-Repo ohne
Remote. Frühere Kopien dieses Dokuments wurden zusätzlich aus der Historie
aller Modul-Repos entfernt (`git filter-repo` + Force-Push). Kein
Fallback-Link mehr — ohne lokalen Zugriff auf Dietmars Maschine ist SUITE.md
nicht einsehbar.
