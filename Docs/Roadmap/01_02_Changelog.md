# Changelog 01_02 – Änderungen aus `origin/1.x-dev` übernehmen

**Datum:** 2026-10-30
**Branch:** `feature/deployer-v3-roadmap`
**Upstream-Commit:** `a2ca83be32204733489bed1e5a4831de53232c6d` (`FIX UI improvements for vulnerabilities`)
**Merge-Commit:** `b5c894fcc2cf07b9e7eac10515a1d85b25f538d0`

## Ziel und Umfang

Der Feature-Branch wurde auf den aktuellen Stand von `origin/1.x-dev` gebracht, damit die parallel entwickelten UI-Verbesserungen für Sicherheitsmeldungen nicht verloren gehen. Der Upstream-Commit änderte fünf Model-/Page-Dateien; er änderte keine PHP-Ausführung der Dependency-Umstellung.

## Änderungen und Begründungen

### Startseite – aktuelle Advisories

Datei: `Model/99_PAGE/axenox.deployer.home.json`

- Der Bereich „Todays advisories“ wurde auf aktuelle Advisories seit gestern angepasst.
- Der Zeitvergleich berücksichtigt den Tageszeitraum mit `>=`.
- Eine eigene Kennzahl „On hosts“ wurde ergänzt.

**Begründung:** Die Startseite soll die gegenwärtige Lage verständlicher darstellen und sichtbar machen, auf wie vielen Hosts die Advisories auftreten, statt nur eine nicht näher eingegrenzte Tagesliste zu zeigen.

### Sicherheitsmeldungen – Übersicht, Charts und Filter

Datei: `Model/99_PAGE/axenox.deployer.security-advisories.json`

- Die bisherige einzelne Tabellenansicht wurde in ein horizontales Layout mit einer vertikalen Chart-/Tabellenansicht umgebaut.
- Ergänzt wurden Diagramme für Schweregrade im Zeitraum der letzten 30 Tage und für Advisories über die Zeit.
- Die Tabelle zeigt weiterhin relevante Advisory- und Versionsdaten und erhält einen Filter, der an den ausgewählten Severity-Wert des Diagramms gebunden ist.

**Begründung:** Die zeitliche Entwicklung und Verteilung der Schweregrade sollen neben den Einzeldatensätzen sichtbar sein. Die Kopplung des Tabellenfilters an die Chart-Auswahl ermöglicht eine gezielte Untersuchung, ohne eine separate Seite pro Schweregrad zu benötigen.

### Advisory-Modell – Deployment-Bezug

Datei: `Model/axenox.Deployer.advisory/02_OBJECT.json`

- Die Anzeige und Auswertung verwendet den Deployment-Status des Builds, um zwischen deployten und nicht deployten Builds zu unterscheiden.
- Die Host-Anzeige bleibt als eigene Auswertung erhalten.
- Eine Aktion „Open build“ öffnet den zugehörigen Build über die Build-UID.

**Begründung:** Advisory-Daten sind für operative Entscheidungen hilfreicher, wenn klar ist, ob der betroffene Build tatsächlich online ist und der Nutzer direkt zu diesem Build navigieren kann.

### Build-Modell – berechneter Deployment-Status

Datei: `Model/axenox.Deployer.build/04_ATTRIBUTE.json`

- Das berechnete Attribut `status_deplyoment` liefert `ONLINE`, wenn der Build in `axxdep_currently_deployed` vorkommt, andernfalls `OFFLINE`.
- Die Werteliste stellt diese Statuswerte dar.

**Begründung:** Die Advisory-Ansichten benötigen einen einfachen, einheitlichen Statuswert für Filter und Anzeige. Das Attribut wird aus den vorhandenen Deployment-Daten abgeleitet, statt einen separaten, potenziell veralteten Status zu speichern. Der bestehende Attribut-Alias `status_deplyoment` wurde beibehalten.

### Host-Modell – Statusauswahl

Datei: `Model/axenox.Deployer.host/04_ATTRIBUTE.json`

- Der Host-Status wurde als erforderlich markiert.
- Die Statusauswahl verwendet „Online“ anstelle von „In Use“; „Decom. planned“ und „Decommissioned“ bleiben als weitere Zustände vorhanden.

**Begründung:** „Online“ beschreibt den angezeigten Zustand für Nutzer eindeutiger als „In Use“. Ein Pflichtwert vermeidet Hosts ohne explizite Statuskategorie in den entsprechenden Übersichten.

## Folgen und Abgrenzung

- Die Advisory-Oberflächen benötigen das neue berechnete Build-Attribut und die aktualisierten Host-Status-Metadaten. Die Modelländerungen müssen deshalb gemeinsam installiert werden.
- Die Änderung an `status_deplyoment` basiert auf der Tabelle `axxdep_currently_deployed`. Die Verfügbarkeit dieser Tabelle und der Ausdruck sind hier durch JSON-Parsing, nicht durch eine Datenbankintegration, geprüft.
- Die neuen Diagramme hängen von den verwendeten Aggregat- und Filterattributen im Modell ab; eine visuelle Laufzeitprüfung der UXON-Seite wurde in diesem Schritt nicht durchgeführt.
- Die upstream-Änderungen sind unabhängig vom nativen Build-/Deployment-Ersatz aus Schritt [01_01](01_01_Changelog.md).

## Verifikation

- Alle fünf vom Upstream-Commit geänderten Model-/Page-JSON-Dateien wurden erfolgreich geparst.
- Die fokussierten Tests für den nativen Dependency-Ersatz liefen nach dem Merge erneut erfolgreich: **9 Tests, 43 Assertions**.
- Composer-Validierung blieb erfolgreich für normale Paketverwendung; vorhandene Warnungen zu SPDX-Lizenzkennung und fehlender Beschreibung bestehen fort.
- Die Seiten wurden nicht in einer laufenden ExFace-UI visuell oder funktional integriert getestet.

## Git- und Arbeitsbaumstatus

Die Upstream-Integration wurde lokal als Merge-Commit `b5c894fcc2cf07b9e7eac10515a1d85b25f538d0` auf `feature/deployer-v3-roadmap` angelegt und nicht gepusht. Die Dependency-Implementierung, die Roadmap-Anpassungen und diese Changelog-Dateien bleiben uncommittet.

Ein bereits vor der Übernahme vorhandenes lokales Delta in `Model/axenox.Deployer.advisory/04_ATTRIBUTE.json` wurde beim Branchwechsel/Merge beibehalten. Es gehört nicht zum Upstream-Commit `a2ca83b` und wird in diesem Eintrag nicht als dessen Änderung aufgeführt.
