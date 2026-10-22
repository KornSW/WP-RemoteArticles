# Entscheidung: Einbettung über Umschreiben und Vertrauensstufen

Datum: 22.10.2026

## Kontext

Eingebettete Beiträge sollen mit ihren Shortcodes, Galerien und Skripten möglichst vollständig funktionieren. Gleichzeitig sollen weder die Quelle angepasst noch Infrastruktur-Freigaben wie CORS oder eine gelockerte Content-Security-Policy nötig sein. Fremdes JavaScript auf der eigenen Seite ist sicherheitskritisch.

## Entscheidung

1. Grundlage ist das von der Quelle gerenderte HTML (`content.rendered`). Shortcodes werden nicht lokal ausgeführt.
2. URLs werden nach URL-Klassen umgeschrieben. Statische Dateien laden direkt von der Quelle. Datenendpunkte laufen über eine eng begrenzte Unterroute pro Quelle. Bekannte Hostnamen werden durch Host plus Unterroute ersetzt.
3. Jede Quelle hat eine Vertrauensstufe:
   - *Nicht vertrauenswürdig* ist der Standard. Hier laufen keine fremden Skripte, und das Plugin bringt eigene Ergänzungen wie die Lightbox mit.
   - *Vertrauenswürdig* gibt es nur für eigene Seiten. Umgeschriebene Skripte laufen dann, aber nie für Benutzer mit Bearbeitungsrechten.
4. Konflikte mit lokalen Plugins und Bibliotheken werden durch einen eigenen Gültigkeitsbereich pro Quelle, ein Shim und einen serverseitigen Rückfall vermieden. Diese Mittel sind ausdrücklich keine Sicherheitsgrenze.
5. Alle Sicherheitsvorgaben stehen verbindlich in [security.md](../security.md).

## Verworfene Alternativen

- **Rohinhalt lokal ausführen** (`content.raw` + `do_shortcode`): Das braucht Zugangsdaten und dieselben Plugins lokal. Es scheitert an Daten, die nur auf der Quelle liegen (z. B. Galerie-Ordner).
- **iframe auf den Originalbeitrag:** Er bringt das Theme der Quelle mit. Styling und Link-Regelwerk wären nicht umsetzbar.
- **Alle fremden Skripte ungeprüft übernehmen:** Das wäre ein Cross-Site-Scripting-Risiko ohne jede Begrenzung. AJAX-Funktionen scheitern zudem trotzdem an CORS.
- **`<base href>` umbiegen:** Es gilt für das ganze Dokument und lässt sich nicht auf die Einbettung beschränken. Wurzelrelative Pfade erfasst es ohnehin nicht.
- **Globale Namen im Skripttext umbenennen** (`wp.` → `wp_alias.`): zu fehleranfällig bei gebautem und verkleinertem Code. Ersetzt durch den eigenen Gültigkeitsbereich pro Quelle.

## Folgen

- Der Mehrwert des Plugins liegt in genau dieser Pipeline. Sie ist der größte und wartungsintensivste Teil des Plugins.
- Volle Abdeckung ist nicht erreichbar. Die bekannten Grenzen stehen im Epic [Einbettungs-Pipeline](../epics/epic-einbettungs-pipeline.md).
- Jede Änderung an Reinigung, Weiterleitungs-Route oder Vertrauensstufen erfordert zuerst eine Anpassung von [security.md](../security.md).
