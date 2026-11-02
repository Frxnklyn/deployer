# Changelog 01_01 – Deployer-Dependency entfernen

**Datum:** 2026-10-30
**Roadmap-Schritt:** [01 – Dependency Removal](01_Dependency_Removal.md)
**Status:** Implementierung im Arbeitsverzeichnis, nicht committet

## Ziel und Ergebnis

Die externe Composer-Dependency `deployer/deployer` soll aus diesem Paket entfernt werden, ohne dafür die gesamte V3-Pipeline-Architektur vorwegzunehmen. Die aktiven Build- und Deployment-Pfade verwenden jetzt eigene PHP-Komponenten statt der externen Recipe-Engine.

Das ist ein Übergangsschritt: Die bestehenden Recipe-Auswahlwerte werden vorerst weiter erkannt, aber nicht mehr als Deployer-Rezepte ausgeführt. Die vollständige Prototype-Auswahl, UXON-Editoren und Datenmigration bleiben späteren Roadmap-Schritten vorbehalten.

## Änderungen und Begründungen

### 1. Composer-Build nativ ausgeführt

- `Actions/Build.php` delegiert den Composer-Build an `Common/Execution/ComposerBuildExecutor.php`; die generierte `build.php`-Datei, das Laden von Deployer-Rezepten und der Aufruf von `vendor/bin/dep` entfallen.
- Composer wird weiter als Build-Werkzeug verwendet. Die Ausführung erfolgt über Symfony Process mit Argumentlisten, Projekt-Arbeitsverzeichnis und Timeout.
- Die Build-Ausgabe behält Composer-Lockfile, Archiv, Package-BOM und bestehende Composer-Hooks bei.
- `ComposerInstallAssetFix` verwendet `Common/Execution/AssetPackagistFix.php`. Die Repository-Einträge werden aus npm-/bower-Paketen im vorherigen Lockfile abgeleitet; bestehende Composer-Repositories bleiben erhalten.
- `CloneLocal`, `CustomBuild` und unbekannte Build-Auswahlen werden klar abgelehnt. Der Build wird nicht stillschweigend mit einer anderen Methode ausgeführt.

**Begründung:** Composer ist ausdrücklich weiterhin das Build-Werkzeug. Eine eigene, kleine Ausführung ersetzt nur die Deployer-Orchestrierung und erhält die für Composer-Builds benötigten Artefakte. Clone- und beliebige PHP-Rezepte haben keinen gleichwertigen sicheren Ersatz in diesem Zwischenschritt; ein scheinbar erfolgreicher, aber inhaltlich anderer Build wäre irreführend.

### 2. Deployment-Paket und unterstützte Legacy-Auswahlen nativ behandelt

- `Actions/Deploy.php` verwendet `Common/Execution/LegacyDeploymentExecutor.php`; das generierte `deploy.php`, der Deployer-CLI-Aufruf und die bisherige SSH-Staging-Ausführung entfallen.
- `Common/Execution/DeploymentPackageBuilder.php` erstellt das `.phx`-Artefakt aus dem beibehaltenen Self-Extractor-Template und dem Build-Archiv. Konfigurationswerte werden als PHP-Literale eingesetzt; unaufgelöste Platzhalter und ungültige Pfade oder Werte führen zu Fehlern.
- Updater Push, Updater Pull, Polling/Scheduler, USB-Manuelltransfer und Azure-Manuelltransfer behalten native Ausführungspfade und das erwartete `{build}_{hostAlias}.phx`-Artefakt.
- Push verwendet TLS-Prüfung, Multipart-Upload und geschlossene Dateistreams. Upload- oder HTTP-Fehler werden nicht als Erfolg behandelt.
- Die Auswahlwerte für drei SSH-Varianten, `CustomDeploy` und unbekannte Deployment-Rezepte werden explizit mit einer Fehlermeldung abgelehnt.
- Die Ausführung liefert Artefaktpfad und Published-Status zurück; Status und Fehler werden durch die Action weiterverarbeitet. Pull und Polling gelten als veröffentlicht, manuelle Verfahren nur als vorbereitet.

**Begründung:** Die fünf Varianten mit vorhandenen Updater- oder manuellen Abläufen können in kleinem Umfang erhalten werden, ohne eine generische Workflow-Engine zu bauen. SSH und beliebige Custom-Rezepte würden hingegen weiterhin prozedurale Ausführung erfordern; sie werden daher nicht automatisch oder stillschweigend ersetzt. Die Unterscheidung zwischen „veröffentlicht“ und „manuell vorbereitet“ verhindert einen falschen Erfolgsnachweis für eine nicht tatsächlich ausgeführte Host-Installation.

### 3. Recipe-Implementierungen und alte Einstiegspunkte

- Entfernt wurden die prozeduralen Dateien `Recipes/Build.php`, `Recipes/Config.php`, `Recipes/Deploy.php`, `Recipes/Install.php`, `Recipes/Network.php`, `Recipes/RemoteWindows.php`, `Recipes/SelfExtractor.php` und `Recipes/SelfExtractingPHP/SelfExtractingArchive.php`.
- Die Legacy-Dateien `Recipes/Build/ComposerInstall.php`, `Recipes/Build/CloneLocal.php`, `Recipes/SelfDeployment.php` sowie die alten `Recipes/Deploy/LocalBld*.php`-Rezeptdateien wurden vollständig gelöscht. Nicht unterstützte Auswahlwerte werden von Actions und nativen Executoren an der tatsächlichen Laufzeitgrenze mit einer klaren Fehlermeldung abgelehnt; separate PHP-Dateien mit nichts weiterem als einem `RuntimeException`-Stummel waren dafür nicht erforderlich.
- `Recipes/SelfExtractingPHP/SelfExtractingDeployment.php` bleibt als eigenständige Installer-Vorlage bestehen; sie ist keine Deployer-Recipe-Engine.
- `DataTypes/*RecipeDataType.php` und die Modellwerte bleiben zunächst bestehen.

**Begründung:** Ausführbare Deployer-Task-Registrierungen, Bootstrap-Code und Hilfsfunktionen dürfen nach Entfernung der Dependency nicht als versteckte Runtime-Anforderung bestehen bleiben. Die Werte im Datenmodell bleiben dagegen vorübergehend erhalten, damit vorhandene Daten erkannt und nicht durch eine voreilige SQL-/Model-Migration verändert werden.

### 4. Composer-Manifest und Roadmap

- `deployer/deployer` wurde aus `composer.json` entfernt. Die übrigen Anforderungen wurden nicht geändert.
- Ein `composer.lock` für dieses Paket war nicht vorhanden.
- [00_Deployer_v3_overview.md](00_Deployer_v3_overview.md) und [01_Dependency_Removal.md](01_Dependency_Removal.md) wurden an den tatsächlichen Übergangsstand angepasst. Sie trennen den frühen Dependency-Meilenstein von der vollständigen V3-Architektur.

**Begründung:** Der Composer-Eintrag konnte erst entfernt werden, nachdem die unterstützten Paketpfade nicht mehr die Deployer-Engine aufrufen. Die privaten Dependencies ließen sich in dieser Umgebung nicht vollständig neu auflösen; deshalb wird die erfolgreiche Paketinstallation nicht behauptet. Die vollständige PipelineFactory, Prototype Discovery, UXON- und SQL-Migration sowie On-Demand-Deployment wurden bewusst nicht zu Vorbedingungen gemacht.

### 5. Tests

Neu: `Tests/PHPUnit/Unit/NativeExecutionTest.php`.

Abgedeckt werden:

- Annahme der zwei Composer-Auswahlen sowie klare Fehler für Clone-/Custom-Builds.
- Asset-Packagist-Repositories aus dem vorherigen Lockfile und Erhalt bestehender Repositories.
- Erzeugung von Archiv, Lockfile und BOM sowie Composer-Hooks.
- Echter Offline-Composer-Install, wenn `COMPOSER_PHAR` gesetzt ist.
- Sicheres Self-Extractor-Quoting und Anhängen der Archivdaten.
- Artefakterzeugung und Published-Status für unterstützte Deployment-Auswahlen.
- Updater-Push mit TLS, Authentifizierung und Erfolgsantwort sowie Fehlerantwort ohne Erfolgsergebnis.
- Klare Ablehnung von SSH-, Custom- und unbekannten Deployment-Auswahlen.

**Begründung:** Die Tests prüfen die neuen Ausführungsgrenzen direkt und benötigen für Push keinen echten Updater-Endpunkt. Der optionale echte Composer-Lauf lässt sich mit einer lokalen PHAR reproduzieren, ohne Netzwerk- oder Fremdsystemabhängigkeit.

## Folgen und noch offene Verifikation

- Konfigurationen mit `CloneLocal`, `CustomBuild`, `CustomDeploy` oder SSH-Auswahl schlagen nun mit einer expliziten Umstellungsfehlermeldung fehl. Eine automatische Datenmigration ist nicht enthalten.
- Manuelle/Azure-Abläufe erzeugen weiterhin ein Artefakt und Installationshinweise; die Installation auf einem Zielhost ist damit nicht verifiziert.
- Die separate ExFace-Host-`composer.json` kann `deployer/deployer` unabhängig vom Paket weiter deklarieren. Sie liegt außerhalb dieses Paket-Repositories und wurde nicht geändert.
- Die installierten Abhängigkeitsbäume der privaten Runtime-Pakete enthielten laut vorheriger Prüfung keinen transitiven Deployer-Eintrag. Eine vollständige Neuauflösung war wegen fehlender Repository-Konfiguration nicht möglich.
- Host-seitige Callbacks und Downloads, echte Azure-/Manual-Installation, Rollback sowie Windows-/Unix-Integrationen bleiben offen.

## Verifikation

- Fokussierte Tests: **9 Tests, 43 Assertions bestanden**, einschließlich des echten Offline-Composer-Laufs.
- Vollständige PHPUnit-Suite nach den aktuellen Änderungen: **17 Tests, 71 Assertions bestanden**.
- Syntaxprüfungen für beide Actions, alle vier neuen Execution-Klassen und den neuen Test bestanden.
- `composer validate --no-check-publish` akzeptierte das Manifest für die normale Verwendung. Es meldete bestehende Metadatenwarnungen zur veralteten SPDX-Angabe `GPL-3.0+` und fehlenden Paketbeschreibung.

## Arbeitsbaum

Die oben beschriebene Implementierung ist nicht committet oder gepusht. Ein separates, bereits vorhandenes lokales Modellattribut-Delta in `Model/axenox.Deployer.advisory/04_ATTRIBUTE.json` wurde beibehalten und ist nicht Teil dieser Dependency-Umsetzung.
