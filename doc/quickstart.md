# Schnellstart

## Installation

1. Plugin-ZIP `kornsw-remotearticles.zip` aus dem neuesten [GitHub-Release](https://github.com/KornSW/WP-RemoteArticles/releases) unter *Plugins → Installieren → Plugin hochladen* installieren und aktivieren.
2. Voraussetzungen: WordPress 7.1 und PHP 8.5. Updates erscheinen danach automatisch in der normalen Update-Ansicht.

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

Ohne lokales PHP mit PHP 8.5 als WebAssembly (Node.js nötig):

```text
cd tests/php-wasm
npm install
cd ../..
node tests/php-wasm/run.mjs tests/run-tests.php
```
