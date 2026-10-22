# Anforderungen

## Projektziel

KornSW RemoteArticles bindet die Beiträge einer bestimmten Kategorie aus einer **fremden WordPress-Installation** per Shortcode in eine Seite oder einen Beitrag der eigenen WordPress-Installation ein. Die Inhalte werden ausschließlich über die offiziellen Schnittstellen der Quelle (WordPress REST API) geladen.

Die eingebetteten Beiträge sollen sich wie eigene Inhalte anfühlen:

- Shortcodes im Quellbeitrag, insbesondere Galerien aus eigenen Plugins, funktionieren auch eingebettet.
- Links, die auf die Quellseite zeigen, werden nach einem konfigurierbaren Regelwerk behandelt.
- Die Darstellung lässt sich an das eigene Layout anpassen.

## Zielgruppe

Betreiber mehrerer WordPress-Seiten, die Inhalte an einer Stelle pflegen und auf weiteren Seiten wiederverwenden wollen, ohne sie zu kopieren.

## Rahmenbedingungen

- Sprache von Plugin-Beschreibung, Oberfläche und Dokumentation: **Deutsch** (Entscheidung vom 22.10.2026).
- Neues Plugin gemäß `wordpress-plugin-development-guidelines` (AI-SKILLS-SMART): aktuelle PHP- und WordPress-Mindestversion zum Projektstart, PSR-4-Klassen, Release über die GitHub-Actions-Pipeline.
- Die Quelle ist eine beliebige WordPress-Installation mit erreichbarer REST API. Ein Plugin auf der Quellseite ist nicht vorausgesetzt.
- Daten (Einstellungen, Caches) bleiben bei der Deinstallation erhalten. Löschen gibt es nur als ausdrückliche Aktion.

## Funktionale Anforderungen

Die Anforderungen sind nach Epics geschnitten. Details, Varianten und offene Fragen stehen im jeweiligen Epic. Verbindlich ist nur, was hier als Anforderung steht und bestätigt ist.

| Nr. | Anforderung | Epic | Status |
| --- | --- | --- | --- |
| A0 | Eine Admin-Seite verwaltet mehrere Quell-Installationen, je mit Alias, Basis-URL und optionalen Zugangsdaten. | [Remote-Beiträge per Shortcode](epics/epic-remote-beitraege-per-shortcode.md) | umgesetzt im MVP |
| A1 | Ein Shortcode lädt über Installations-Alias und Kategorie die Beiträge der Quelle über die REST API und stellt sie dar. | [Remote-Beiträge per Shortcode](epics/epic-remote-beitraege-per-shortcode.md) | umgesetzt im MVP |
| A2 | Shortcodes, Galerien und Skripte der Quellbeiträge funktionieren eingebettet so weit wie möglich weiter: über die Einbettungs-Pipeline mit Reinigung, URL-Umschreibung und Vertrauensstufen je Quelle. | [Einbettungs-Pipeline](epics/epic-einbettungs-pipeline.md) | Stufe 1 im MVP umgesetzt (ohne fremde Skripte, eigene Lightbox) |
| A3 | Links auf die Quellseite werden je nach Einstellung entfernt, in einem neuen Fenster auf der Originalseite geöffnet oder unverändert gelassen. | [Link-Regelwerk](epics/epic-link-regelwerk.md) | umgesetzt im MVP (Standard: neues Fenster) |
| A4 | Das Styling der eingebetteten Beiträge ist anpassbar. | [Styling-Anpassungen](epics/epic-styling-anpassungen.md) | Grundstufe im MVP (CSS-Klassen, Standard-Stylesheet abschaltbar, eigenes CSS je Quelle) |
| A5 | Beiträge ausgewählter Kategorien einer Quelle werden über Import-Regeln je Quelle (`RemoteKat1, RemoteKat2 > LokaleKat`) als nicht bearbeitbare lokale Beiträge gehalten und mischen sich so mit den eigenen Beiträgen (Kategorie- und Schlagwort-Archive, Startseite, Widgets, Feeds, Suche); ein Klick öffnet die lokale Einzelansicht. Schlüssel `<alias>:<ID auf der Quelle>`. | [Import als lokale Beiträge](epics/epic-import-als-lokale-beitraege.md) | umgesetzt (22.10.2026) |

Bewusst **später** (nicht Teil des ersten Ausbaus):

- Links auf die Quellseite auf entsprechende Strukturen der eigenen Seite umschreiben, siehe [Links lokal nachbilden](epics/epic-links-lokal-nachbilden.md).

## Nicht-funktionale Anforderungen

- Eine langsame oder nicht erreichbare Quelle darf die eigene Seite nicht blockieren oder zerstören. Stattdessen erscheint ein zwischengespeicherter Stand oder ein dezenter Hinweis.
- Zwischenspeichern der Antworten der Quelle, damit nicht jeder Seitenaufruf die Quelle belastet.
- Sicherheit nach [security.md](security.md) (verbindlich): Standard ist sicher, fremde Skripte nur bei ausdrücklich vertrauenswürdigen Quellen und nie für Benutzer mit Bearbeitungsrechten.

## Abgrenzung

- Kein Schreiben auf der Quellseite.
- Der Shortcode bettet live ein (mit Zwischenspeicher). Lokale Kopien entstehen nur über den Import (A5). Sie sind nie bearbeitbar und spiegeln immer die Quelle.
