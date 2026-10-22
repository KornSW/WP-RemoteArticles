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

## Import als lokale Beiträge („Kapseln“)

Über die Import-Regeln einer Quelle werden deren Beiträge zu echten, nicht bearbeitbaren Beiträgen der eigenen Seite. Sie mischen sich so in Archive, Startseite, Widgets, Feeds und Suche, und ein Klick öffnet die lokale Einzelansicht. Sicherheitsvorgaben: [security.md](security.md), Abschnitt 7.

```text
WP-Cron (stündlich) / „Jetzt synchronisieren“
        │
        ▼
SyncScheduler ── Intervall je Quelle (Standard 4 h) oder offene Folgearbeit
        │
        ▼
Synchronizer ── ImportRuleParser („rezepte, #backen > Kochen, #familie“)
        │   1. Kategorien/Schlagwörter der Quelle → IDs
        │   2. IDs + Änderungsdatum aller passenden Beiträge auflisten
        │   3. nur neue/geänderte Beiträge vollständig laden (Stapel, Zeitbudget 15 s, neueste zuerst)
        │   4. lokale Kategorien/Schlagwörter zuordnen (bei Bedarf anlegen)
        │   5. nicht mehr passende Kapseln löschen (nur bei vollständiger Liste)
        ▼
CapsuleStore ── Beitrag (post) + Bild-Anhang ohne Datei

Ausgabe: CapsulePresenter ── the_content (Platzhalter mit erster Priorität, Pipeline-Ergebnis mit letzter),
                             Bildgrößen der Quelle, Autorname, Canonical, Bearbeitungssperre
```

| Klasse (`src/Import/…`) | Aufgabe |
| --- | --- |
| `ImportRuleParser`, `ImportRule`, `TermReference` | Liest die Regeln. Links Slug oder ID der Quelle, rechts Namen lokaler Begriffe; `#` kennzeichnet Schlagwörter. Ohne WordPress testbar. |
| `Synchronizer` | Gleicht eine Quelle ab. Bleiben Beiträge offen (Zeitbudget, max. 300 pro Lauf), plant er eine Minute später einen Folgelauf. Eine Sperre verhindert parallele Läufe. |
| `SyncScheduler` | Stündliches WP-Cron-Ereignis `kornsw_ra_sync_due`. Startet fällige Quellen. Bei Deaktivierung wird das Ereignis entfernt. |
| `SyncStatusRepository` | Ergebnis des letzten Laufs je Quelle (Option `kornsw_ra_sync_status`), angezeigt auf der Admin-Seite. |
| `CapsuleStore`, `CapsuleData` | Speichert Kapseln. Schlüssel `_kornsw_ra_origin = <alias>:<ID>`. Das rohe HTML liegt in `_kornsw_ra_raw_content`, `post_content` enthält eine gereinigte Fassung mit unschädlich gemachten `[`/`]`. Titel und Auszug werden maskiert gespeichert. |
| `CapsulePresenter` | Lässt Kapseln wie eigene Beiträge wirken und sperrt Bearbeiten und Löschen über `map_meta_cap`. |

Vorschaubilder: Jede Kapsel bekommt einen Anhang ohne lokale Datei. `image_downsize`, `wp_get_attachment_url` und `wp_get_attachment_image_attributes` liefern die passende Bildgröße der Quelle samt `srcset`. In der Mediathek sind diese Anhänge ausgeblendet. Ist das Plugin deaktiviert, zeigt die `guid` des Anhangs weiterhin auf das Originalbild.

## Galerie-Adapter: Share-one-Drive

Galerien des Plugins „Share-one-Drive“ (OneDrive) enthalten im Beitrag nur Platzhalter, ihre Bilder lädt ein Skript per AJAX. Der Adapter ersetzt das, ohne fremde Skripte auszuführen (Sicherheit: [security.md](security.md), Abschnitt 8):

```text
gereinigter Inhalt ── GalleryPlaceholderInserter: Modul .wpcp-module.ShareoneDrive[data-list=gallery]
        │                → <div class="kornsw-ra-gallery" data-kornsw-ra-gallery="signierte Endpunkt-URL">
        ▼
Browser: assets/gallery.js ── GET /wp-json/kornsw-remotearticles/v1/gallery?d=…&s=…
        ▼
ShareOneDriveGalleries ── Signatur prüfen → Zwischenspeicher (1 h)
        │   gallery_nonce von der Originalseite (6 h)
        │   POST admin-ajax.php action=shareonedrive-get-gallery (Referer = Originalbeitrag)
        │   ShareOneDriveResponseParser: nur Daten, Host-Positivliste
        │   Videos: Stream-URL der Quelle ohne Referer → Microsoft-URL
        ▼
JSON → gallery.js baut die Galerie (Blocksatz) → Lightbox (gruppiert je Galerie)
```

| Klasse (`src/Galleries/…`) | Aufgabe |
| --- | --- |
| `ShareOneDriveModule` | Parameter eines Galerie-Moduls, streng geprüft. Ohne WordPress testbar. |
| `GalleryPlaceholderInserter` | Ersetzt Galerie-Module im gereinigten HTML durch Platzhalter. Ohne WordPress testbar. |
| `ShareOneDriveResponseParser`, `GalleryItem` | Liest Bilder und Videos aus der Antwort der Quelle. Ohne WordPress testbar. |
| `ShareOneDriveGalleries` | REST-Endpunkt, Signatur, Nonce, Abruf, Zwischenspeicher, Auflösen der Videos. Eingebunden in Shortcode-Ausgabe und Kapseln. |

Andere Module von Share-one-Drive, etwa Dateilisten oder Player, bleiben ohne Skript unsichtbar.

## Datenhaltung

| Daten | Ort | Bei Deinstallation |
| --- | --- | --- |
| Quellen | Option `kornsw_ra_sources` (nicht autoload) | bleibt erhalten |
| Einstellungen | Option `kornsw_ra_settings` (nicht autoload) | bleibt erhalten |
| Zwischenspeicher | Transients `kornsw_ra_<hash>` | bleibt erhalten, läuft nach 30 Tagen ab |
| Hinweise der Admin-Seite | Transient `kornsw_ra_notice_<Benutzer-ID>` (60 s) | läuft ab |
| Importierte Beiträge und ihre Bild-Anhänge | Beiträge `post` / Anhänge mit Meta-Angaben `_kornsw_ra_*` | bleiben erhalten (beim Löschen einer Quelle werden ihre Kapseln entfernt) |
| Lokale Kategorien und Schlagwörter aus Import-Regeln | normale Begriffe | bleiben erhalten |
| Status der Synchronisation | Option `kornsw_ra_sync_status` (nicht autoload) | bleibt erhalten |

## Tests

`tests/run-tests.php` prüft die Einbettungs-Pipeline ohne WordPress:
- eine Sammlung von XSS- und CSS-Angriffsvektoren mit anschließender strukturierter Sicherheitsprüfung der Ausgabe;
- funktionale Fälle wie AutoGallery-Markup, Link-Modi, URL-Auflösung und Block-Markup.

Ausführen wie in [quickstart.md](quickstart.md) beschrieben.
