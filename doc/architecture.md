# Architektur

Stand: MVP (Pipeline-Stufe 1, siehe [Einbettungs-Pipeline](epics/epic-einbettungs-pipeline.md)). Alle Sicherheitsvorgaben stehen in [security.md](security.md).

## Überblick

```text
Seite mit [remote_articles alias="blog" category="rezepte"]
        │
        ▼
RemoteArticlesShortcode ── ShortcodeOptions (Attribute prüfen)
        │
        ├── SourceRepository ── Quelle zum Alias (Option kornsw_ra_sources)
        │
        ├── ArticleRepository ── ResponseCache (Transients, letzter Stand als Rückfall)
        │         │
        │         └── RemoteClient ── wp_safe_remote_get ──► REST API der Quelle
        │                                                    /wp-json/wp/v2/categories
        │                                                    /wp-json/wp/v2/posts
        │
        ├── ArticleListRenderer ── HtmlSanitizer ── CssSanitizer, UrlResolver, LinkClassifier
        │
        └── AssetLoader ── remote-articles.css, lightbox.css, lightbox.js (nur auf Seiten mit Einbettung)

Einstellungen → Remote Articles ── SettingsPage ── SourceRepository, SecretBox, Settings
```

## Komponenten

| Klasse (`kornsw-remotearticles/src/…`) | Aufgabe |
| --- | --- |
| `Plugin` | Setzt alle Dienste zusammen und registriert die Hooks. |
| `Settings` | Allgemeine Einstellungen (Option `kornsw_ra_settings`): Standard-Stylesheet an oder aus. |
| `Sources/Source`, `SourceRepository` | Konfigurierte Quellen mit Alias, Adresse, optionalen Zugangsdaten, Link-Modus, Zwischenspeicher-Dauer und eigenem CSS (Option `kornsw_ra_sources`). |
| `Sources/SecretBox` | Verschlüsselt Anwendungspasswörter (libsodium, Schlüssel aus den WordPress-Salts). |
| `Remote/RemoteClient` | Liest JSON von der REST API: nur https, nur konfigurierte Adressen, keine Umleitung auf fremde Hosts, Größen- und Zeitlimits. Ohne „schöne“ Permalinks weicht er auf `?rest_route=` aus. |
| `Remote/ResponseCache` | Zwischenspeicher in Transients. Ein Eintrag gilt eine einstellbare Zeit als frisch. Danach dient er 30 Tage als Rückfall, falls die Quelle ausfällt. |
| `Remote/ArticleRepository` | Löst Kategorie-Slugs in IDs auf und lädt Beiträge samt Beitragsbild. |
| `Shortcode/ShortcodeOptions`, `RemoteArticlesShortcode` | Prüft die Attribute und steuert die Ausgabe. Fehler sehen nur Benutzer mit Bearbeitungsrechten. |
| `Rendering/ArticleListRenderer` | Erzeugt das HTML der Beitragsliste. Titel, Datum und Bild werden maskiert, Inhalt und Auszug laufen durch den `HtmlSanitizer`. |
| `Rendering/AssetLoader` | Lädt Styles, das eigene CSS der Quelle und die Lightbox nur bei Bedarf. |
| `Embedding/*` | Die Einbettungs-Pipeline, ohne WordPress-Abhängigkeiten und deshalb eigenständig testbar (siehe unten). |
| `Admin/SettingsPage` | Admin-Seite unter Einstellungen: Quellen anlegen, bearbeiten, testen und löschen, dazu die allgemeinen Einstellungen. |

## Einbettungs-Pipeline (Stufe 1)

`HtmlSanitizer` verarbeitet `content.rendered` der Quelle mit dem HTML5-Parser von PHP (`\Dom\HTMLDocument`):

1. **Elemente nach Positivliste:**
   - Gefährliche Elemente werden samt Inhalt entfernt: Skripte, Formulare, `<base>`, `<meta>`, SVG und Ähnliches.
   - Unbekannte Elemente werden ausgepackt; ihr Inhalt bleibt erhalten.
   - iframes bleiben nur für YouTube und Vimeo erhalten, immer mit `sandbox`.
2. **Attribute nach Positivliste:**
   - Event-Handler fallen weg.
   - URLs werden gegen die Adresse des Originalbeitrags aufgelöst und auf `https` gebracht.
   - IDs bekommen ein Präfix je Beitrag. Das verhindert Kollisionen und DOM-Clobbering.
3. **CSS** (`CssSanitizer`):
   - `style`-Attribute und `<style>`-Blöcke werden geprüft.
   - `<style>`-Blöcke werden auf das `<article>` des Beitrags beschränkt. Die IDs in ihren Selektoren werden mit umbenannt.
   - `position: fixed` und `sticky`, `@import`, `@font-face` und Escapes werden entfernt, ebenso `url()` auf fremde Hosts.
4. **Links** (`LinkClassifier`):
   - Links auf Seiten der Quelle folgen dem Link-Modus: entfernen, neues Fenster oder unverändert.
   - Links auf Medien der Quelle bleiben unverändert. Bilder und Videos werden zusätzlich für die eigene Lightbox markiert (`data-kornsw-ra-media`).
5. **Eigene Interaktion:** `assets/lightbox.js` ersetzt die entfernten Skripte der Quelle, z. B. die Lightbox von KornSW AutoGallery.

## Datenhaltung

| Daten | Ort | Bei Deinstallation |
| --- | --- | --- |
| Quellen | Option `kornsw_ra_sources` (nicht autoload) | bleibt erhalten |
| Einstellungen | Option `kornsw_ra_settings` (nicht autoload) | bleibt erhalten |
| Zwischenspeicher | Transients `kornsw_ra_<hash>` | bleibt erhalten, läuft nach 30 Tagen ab |
| Hinweise der Admin-Seite | Transient `kornsw_ra_notice_<Benutzer-ID>` (60 s) | läuft ab |

## Tests

`tests/run-tests.php` prüft die Einbettungs-Pipeline ohne WordPress:
- eine Sammlung von XSS- und CSS-Angriffsvektoren mit anschließender strukturierter Sicherheitsprüfung der Ausgabe;
- funktionale Fälle wie AutoGallery-Markup, Link-Modi, URL-Auflösung und Block-Markup.

Ausführen wie in [quickstart.md](quickstart.md) beschrieben.
