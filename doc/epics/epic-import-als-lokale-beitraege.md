# Epic: Import als lokale Beiträge

Status: **umgesetzt** (22.10.2026). Revidiert die bisherige Abgrenzung „keine lokalen Kopien“ in [requirements.md](../requirements.md).

## Ziel

Beiträge einer Quelle erscheinen auf der eigenen Seite als **echte zusätzliche Beiträge**, gemischt mit den eigenen: in Kategorie-Archiven, auf der Blog-Startseite, in Widgets wie „Neueste Beiträge“, in Feeds und in der Suche. Gesteuert wird das über Kategorien statt über einen Shortcode. Der Shortcode bleibt für gezielte Einbettungen bestehen.

## Idee (Skizze vom 22.10.2026)

- Jede Quelle bekommt im Admin-Bereich ein Feld **„Import“**. Darin wird festgelegt, welche Kategorien der Quelle in welche lokalen Kategorien übernommen werden. Pro Zeile eine Regel:

  ```text
  RemoteKat1, RemoteKat2 > LokaleKatX
  rezepte > kochen, familie
  ```

- Die importierten Beiträge werden **intern gehalten**: Sie sind nicht bearbeitbar, aber adressierbar und mit eigenen Beiträgen mischbar.
- Sie dienen als eine Art Zwischenspeicher. Der Inhalt wird trotzdem erst bei der Ausgabe aufbereitet (siehe „Inhalt und Ausgabe“).
- Identität: Schlüssel **`<alias>:<Beitrags-ID auf der Quelle>`**. Dadurch ist der Import robust gegen Umbenennungen. Ein Beitrag, der auf der Quelle in mehreren importierten Kategorien steht, wird nur **einmal** angelegt und bekommt alle passenden lokalen Kategorien.

## Ausarbeitung

### Speicherung als echter Beitrag

- Beitragstyp `post` ist nötig, damit Archive, Startseite, Widgets, Feeds und Suche die Beiträge ohne Eingriffe mitnehmen. Ein eigener Beitragstyp würde das nicht leisten.
- Kennzeichnung über die Meta-Angabe `_kornsw_ra_origin = <alias>:<id>` (eindeutig). Dazu kommen Original-URL, Änderungsdatum der Quelle und die Kategorie-IDs der Quelle.
- Datum und Slug werden von der Quelle übernommen. Kollidiert der Slug mit einem eigenen Beitrag, hängt WordPress automatisch eine Nummer an.
- Autor: Name des Originalautors als Text oder ein je Quelle gewählter lokaler Benutzer (siehe Entscheidungen).
- Kommentare: geschlossen.
- Beitragsbild: **kein** Herunterladen in die eigene Mediathek, sondern ein Bild-Anhangsobjekt ohne Datei, dessen URLs auf die Bildgrößen der Quelle zeigen (siehe Entscheidungen).

### Nicht bearbeitbar

- Bearbeiten, Löschen und Kategorieändern werden für diese Beiträge über die Rechteprüfung von WordPress (`map_meta_cap`) gesperrt, auch für Administratoren. Änderungen kommen nur aus der Synchronisation.
- In der Beitragsliste erscheint ein Hinweis „Importiert von <Alias>“ mit Link auf das Original.

### Synchronisation

- Läuft im Hintergrund über WP-Cron, nie während eines Seitenaufrufs. Dazu gibt es „Jetzt synchronisieren“ auf der Admin-Seite.
- Geänderte Beiträge werden gezielt nachgeladen. Die REST API kennt dafür den Parameter `modified_after`.
- Ein regelmäßiger Abgleich der vollständigen ID-Liste erkennt Beiträge, die an der Quelle gelöscht, zurückgezogen oder aus einer Kategorie entfernt wurden. Die lokale Kategorie wird dann entfernt. Hat der Beitrag keine importierte Kategorie mehr, wird er lokal entfernt (Verhalten siehe offene Fragen).
- Die Regeln werden beim Speichern geprüft. Kategorien der Quelle werden dabei in **IDs** aufgelöst, damit spätere Umbenennungen nichts brechen. Unbekannte Kategorien führen zu einer Fehlermeldung.
- Eine Begrenzung pro Regel ist möglich, z. B. nur die neuesten n Beiträge.

### Inhalt und Ausgabe

- Das gerenderte HTML der Quelle (mit den dort bereits ausgeführten Shortcodes) wird **unverändert in einer Meta-Angabe** gespeichert.
- Bei jeder Ausgabe ersetzt das Plugin den Inhalt durch das Ergebnis der Einbettungs-Pipeline. Dafür hängt es sich in den `the_content`-Filter ein und umschließt das Ergebnis mit `.kornsw-ra-content`. Verbesserungen an der Reinigung wirken so auch für bereits importierte Beiträge.
- `post_content` enthält nur eine **beim Import gereinigte Fassung**, und zwar mit unschädlich gemachten eckigen Klammern. Sie dient als Rückfall: für Themes und Plugins, die den Inhalt direkt aus der Datenbank lesen, für die Suche und für den Fall, dass das Plugin deaktiviert ist.
- **Lokale Shortcodes werden auf fremde Inhalte nie ausgeführt** (siehe Sicherheit).
- `post_excerpt` bekommt einen reinen Text-Auszug.

### Verbindung zu anderen Epics

- **[Links lokal nachbilden](epic-links-lokal-nachbilden.md):** Importierte Beiträge haben eigene Adressen auf der eigenen Seite. Links in fremden Inhalten auf einen bereits importierten Beitrag können deshalb auf die lokale Adresse umgeschrieben werden. Das ist der erste, einfache Fall dieses Epics.
- **Shortcode:** Er kann künftig auf importierte Beiträge zurückgreifen, statt live zu laden. Das ist optional.

## Sicherheit und Fallstricke

- **Fremde Shortcodes:** Text wie `[irgendein_shortcode]` im Quellinhalt würde bei normaler Ausgabe von **unserem** WordPress ausgeführt. Ein Autor der Quelle könnte damit lokale Shortcodes auslösen. Gegenmaßnahme: Der fremde Inhalt durchläuft nie `do_shortcode`. Das Plugin ersetzt die Ausgabe vollständig, und in `post_content` sind eckige Klammern als `&#91;`/`&#93;` gespeichert.
- **Direktes Lesen von `post_content`** durch Themes, Seitenbaukästen und SEO-Plugins: Dort liegt nur die gereinigte Fassung, nie das rohe HTML.
- **Doppelter Inhalt für Suchmaschinen:** Derselbe Text steht auf zwei Seiten. Standard ist deshalb ein `<link rel="canonical">` auf das Original. Wahlweise kann pro Quelle `noindex` gesetzt werden.
- **Kein Zugriff auf Rechte:** Gesperrtes Bearbeiten verhindert, dass jemand unbemerkt fremden Inhalt mit eigenen Rechten veröffentlicht oder verändert.
- **Menge:** Ein Import kann viele Beiträge anlegen. Er läuft deshalb in Stapeln mit Obergrenzen.
- Diese Punkte werden vor der Umsetzung als eigener Abschnitt in [security.md](../security.md) aufgenommen.

## Entschieden (22.10.2026)

1. **Aufbereitung erst bei der Ausgabe**, genau wie beim Shortcode. Die Quelle löst ihre Shortcodes auf. Gespeichert wird ihr gerendertes HTML, die Pipeline läuft bei jeder Ausgabe. Beim Import wird nichts endgültig aufgelöst.
2. **Navigation:** Kapseln mischen sich agnostisch in normale Beitragslisten (Kategorie- oder Schlagwort-Archive, Startseite, Widgets). Ein Klick öffnet die **lokale** Einzelansicht der Kapsel im eigenen Theme, nie die fremde Seite. Weil die Kapsel ein echter Beitrag mit eigener Adresse ist, braucht es keine Umleitung.
3. **Vorschaubild:** muss in jedem Theme passen. Jede Kapsel bekommt deshalb ein Bild-Anhangsobjekt **ohne lokale Datei**. Seine URLs und Bildgrößen zeigen auf die Größen der Quelle (Vorschau, mittel, groß).
4. **Kategorien und Schlagwörter:** Regeln unterstützen beides, auf beiden Seiten. `kochen` steht für eine Kategorie, `#kochen` für ein Schlagwort.
5. **Autor:** Standard ist der Name des Originalautors, nur als Text, ohne lokalen Benutzer. Je Quelle lässt sich ein lokaler Benutzer auswählen, der stattdessen als Autor erscheint.
6. **Lokale Zielkategorien und -schlagwörter** werden bei Bedarf automatisch angelegt.
7. **Synchronisation:** auf Knopfdruck und automatisch alle *x* Stunden, je Quelle einstellbar, Standard 4 Stunden.
8. **Gelöscht oder aus allen Regeln gefallen:** Die Kapsel wird lokal gelöscht, denn sie ist nur ein Spiegel. Wird eine Quelle gelöscht, werden auch ihre Kapseln entfernt.
9. **Suchmaschinen:** Die Einzelansicht setzt `rel="canonical"` auf das Original.

## Offene Fragen

1. Links im Inhalt, die auf einen ebenfalls importierten Beitrag zeigen, auf die lokale Kapsel umschreiben? Das wäre der erste Schritt des Epics [Links lokal nachbilden](epic-links-lokal-nachbilden.md).
2. `noindex` je Quelle als zusätzliche Option?
