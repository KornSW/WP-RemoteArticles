# Schnellstart

## Installation

1. Plugin-ZIP `kornsw-remotearticles.zip` aus dem neuesten [GitHub-Release](https://github.com/KornSW/WP-RemoteArticles/releases) unter *Plugins → Installieren → Plugin hochladen* installieren und aktivieren.
2. Voraussetzungen: WordPress 7.1 und PHP 8.4 oder neuer. Updates erscheinen danach automatisch in der normalen Update-Ansicht.

## Quelle anlegen

1. *Einstellungen → Remote Articles* öffnen.
2. Unter „Neue Quelle anlegen“ eintragen:
   - **Alias**, z. B. `blog`: Mit diesem Namen wird die Quelle im Shortcode angesprochen.
   - **Adresse** der WordPress-Installation, z. B. `https://www.beispiel.de` (nur https).
   - **Links auf die Quellseite:** entfernen, im neuen Fenster öffnen (Standard) oder unverändert lassen.
   - **Zwischenspeicher** in Minuten (Standard 15).
   - Benutzername und Anwendungspasswort nur, wenn die Quelle ihre Beiträge nicht öffentlich bereitstellt.
3. Speichern und in der Liste **Verbindung testen** klicken.

## Beiträge einbetten

In eine Seite oder einen Beitrag (z. B. im Block „Shortcode“) einfügen:

```text
[remote_articles alias="blog" category="rezepte"]
```

| Attribut | Bedeutung |
| --- | --- |
| `alias` | Alias der Quelle (Pflicht) |
| `category` | Slug oder ID der Kategorie auf der Quelle (Pflicht). Der Slug steht in der Adresse der Kategorie, z. B. `…/category/rezepte/`. |
| `count` | Anzahl der Beiträge, 1 bis 50 (Standard 10) |
| `order` | `desc` = neueste zuerst (Standard), `asc` = älteste zuerst |
| `display` | `full` = ganzer Beitrag (Standard), `excerpt` = nur Auszug |
| `image` | `yes` = Beitragsbild anzeigen |
| `links` | `remove`, `new_window` oder `keep`: überschreibt die Einstellung der Quelle |

Beispiel: die fünf neuesten Beiträge als Auszug mit Bild, Links auf die Quelle entfernt:

```text
[remote_articles alias="blog" category="rezepte" count="5" display="excerpt" image="yes" links="remove"]
```

## Beiträge importieren und mit eigenen mischen

Statt per Shortcode lassen sich Beiträge einer Quelle als normale Beiträge dieser Seite übernehmen. Sie erscheinen dann gemischt mit den eigenen Beiträgen in Kategorie- und Schlagwort-Archiven, auf der Startseite, in Widgets, Feeds und der Suche. Ein Klick öffnet sie auf dieser Seite.

1. Quelle bearbeiten und im Feld **Import** eine Regel pro Zeile eintragen:

   ```text
   rezepte, backen > Kochen
   #reise > Unterwegs, #urlaub
   ```

   Eine Regel pro Zeile, oder mehrere Regeln mit `;` getrennt (z. B. `brick-tipps > Tipps; brick-projects > Bauprojekte`). Das Komma trennt nur mehrere Kategorien innerhalb einer Regel. Links stehen Kategorien der Quelle (Slug oder ID), rechts lokale Kategorien. Schlagwörter werden mit `#` gekennzeichnet. Fehlende lokale Kategorien und Schlagwörter werden automatisch angelegt.
2. Optional einen **Autor** wählen. Ohne Auswahl erscheint der Name des Originalautors.
3. **Synchronisation** alle *x* Stunden einstellen (Standard 4) und speichern. Beim Speichern prüft das Plugin, ob die Kategorien der Quelle existieren.
4. In der Liste **Jetzt synchronisieren** klicken. Große Kategorien werden in mehreren Durchgängen übernommen, die neuesten zuerst. Die weiteren Durchgänge starten automatisch im Hintergrund.

Wichtig zu wissen:

- Importierte Beiträge sind in der Beitragsliste mit „Importiert von …“ markiert und **nicht bearbeitbar**. Änderungen an der Quelle kommen mit der nächsten Synchronisation.
- Wird ein Beitrag an der Quelle gelöscht oder passt er zu keiner Regel mehr, wird er hier ebenfalls entfernt. Löscht man die Quelle, verschwinden auch ihre importierten Beiträge.
- Suchmaschinen erhalten über `rel="canonical"` den Hinweis auf das Original.
- Plugins, die beim Veröffentlichen neuer Beiträge automatisch etwas tun (z. B. Newsletter oder Social-Media-Posts), behandeln importierte Beiträge wie eigene. Das vor dem ersten Import prüfen.
- WP-Cron läuft nur bei Seitenaufrufen. Auf wenig besuchten Seiten startet die Synchronisation deshalb später; für pünktliche Läufe einen System-Cron einrichten, der `wp-cron.php` regelmäßig aufruft.

## Darstellung anpassen

- Die Ausgabe trägt die Klassen `.kornsw-ra`, `.kornsw-ra-article`, `.kornsw-ra-image`, `.kornsw-ra-title`, `.kornsw-ra-meta` und `.kornsw-ra-content`.
- Eigenes CSS je Quelle lässt sich auf der Einstellungsseite hinterlegen.
- Das Standard-Stylesheet des Plugins lässt sich unter „Allgemein“ abschalten.

## Fehlerdiagnose

Fehlermeldungen erscheinen nur für angemeldete Benutzer mit Bearbeitungsrechten direkt an der Stelle des Shortcodes. Besucher sehen in diesem Fall nichts.

| Meldung | Ursache und Abhilfe |
| --- | --- |
| „Es gibt keine Quelle mit dem Alias …“ | Alias falsch geschrieben oder Quelle nicht angelegt. |
| „Die Kategorie … gibt es auf der Quelle … nicht.“ | Slug prüfen (Adresse der Kategorie auf der Quellseite) oder die ID verwenden. |
| „… ist nicht erreichbar …“ | Quelle offline, Adresse falsch oder sie zeigt auf eine interne Adresse (das wird aus Sicherheitsgründen blockiert). |
| „… antwortet mit HTTP-Status 401/403“ | Die Quelle verlangt eine Anmeldung, oder ein Sicherheits-Plugin sperrt die REST API. Gegebenenfalls Benutzername und Anwendungspasswort hinterlegen. |
| „… leitet auf eine andere Adresse um …“ | Die Adresse der Quelle stimmt nicht genau, z. B. ohne `www.` oder mit falschem Unterverzeichnis. Die endgültige Adresse eintragen. |
| Galerie erscheint, öffnet aber keine Lightbox | Nur Bild-Links auf Mediendateien der Quelle werden erkannt. Links auf Anhang-Seiten folgen dem Link-Modus. |

Ist die Quelle vorübergehend nicht erreichbar, zeigt das Plugin den zuletzt geladenen Stand, solange einer vorhanden ist.

## Für Entwickler: Tests ausführen

Die Tests prüfen die Einbettungs-Pipeline ohne WordPress-Installation.

```text
php tests/run-tests.php
```

Ohne lokales PHP mit PHP 8.4 als WebAssembly (Node.js nötig):

```text
cd tests/php-wasm
npm install
cd ../..
node tests/php-wasm/run.mjs tests/run-tests.php
```
