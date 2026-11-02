# Changelog 01_03 – Deployment-Pfad und PHP-Artefakt korrigieren

**Datum:** 2026-11-02

## Anlass und Ursache

Ein reales Deployment brach in der neuen Klasse `Common/Execution/DeploymentPackageBuilder.php` mit „The host deployment paths must both be configured.“ ab.

Die neue Prüfung hatte fälschlich auch einen leeren relativen Deployment-Pfad verboten. Die bisherige Installer-Vorlage unterstützt diesen Fall ausdrücklich: Das Deployment liegt dann direkt im Basispfad. Die Action reicht ein nicht gesetztes `path_rel_to_releases` bereits als leere Zeichenkette weiter. Dafür darf keine neue Pflichtkonfiguration eingeführt werden.

Bei der Prüfung fiel außerdem ein eng verbundener Fehler auf: Die String-Platzhalter für Basispfad, relativen Pfad und PHP-Executable stehen im Template bereits in einfachen Anführungszeichen. Die bisherige Ersetzung durch `var_export()` fügte ein zweites Paar hinzu und erzeugte syntaktisch ungültigen PHP-Code im `.phx`-Artefakt.

## Änderungen und Begründung

- `DeploymentPackageBuilder` erlaubt wieder einen leeren relativen Pfad. Der Basispfad bleibt erforderlich; ungültige Datentypen führen weiterhin zu einer ausdrücklichen, feldbezogenen Fehlermeldung. Damit wird das bisherige Verhalten wiederhergestellt, ohne die Prüfung vollständig abzuschalten.
- Die String-Platzhalter werden einschließlich ihrer Template-Anführungszeichen ersetzt. `var_export()` erzeugt damit genau ein korrekt maskiertes PHP-Literal, auch bei Windows-Pfaden und Apostrophen.
- Die vorhandene Installer-Vorlage und Host-Konfiguration wurden nicht geändert. Es wird keine Host-Installation automatisch gestartet.

## Tests

- Vor der Korrektur reproduzierten zwei Tests den Abbruch bei leerem relativen Pfad und den PHP-Parsefehler des erzeugten Pakets.
- Der neue Regressionstest durchläuft die native manuelle Deployment-Ausführung mit leerem relativen Pfad, prüft die eingesetzten Pfadwerte und validiert das erzeugte Artefakt mit `php -l`.
- Der vorhandene Quoting-Test validiert jetzt ebenfalls die PHP-Syntax des gesamten erzeugten Artefakts, statt nur nach einem enthaltenen String zu suchen.
- Ein weiterer Test stellt sicher, dass ein fehlender Basispfad und ungültige Pfadtypen weiterhin abgelehnt werden.
- Nach der Korrektur: **11 Tests, 51 Assertions bestanden**, einschließlich des echten Offline-Composer-Tests mit `COMPOSER_PHAR`.

## Folgen und Grenzen

Das Paket kann für Hosts mit leerem relativem Pfad wieder erzeugt werden. Der beobachtete Fehler war eine Regression unserer Umstellung, nicht der Nachweis einer fehlerhaften Host-Konfiguration.

Die Tests validieren Paketgenerierung und PHP-Syntax, nicht die tatsächliche Installation auf einem Zielhost. Der reale Deployment-Lauf muss erneut ausgeführt und dessen Host-Ergebnis geprüft werden. Der frühere erfolgreiche Teststand deckte diese beiden Fehler nicht ab und war kein vollständiger Kompatibilitätsnachweis.

Die Korrektur bleibt uncommittet; es wurde nichts gepusht.
