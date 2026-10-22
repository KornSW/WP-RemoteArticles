# Epic: Remote-Beiträge per Shortcode

## Ziel

Per Shortcode werden die Beiträge einer Kategorie einer fremden WordPress-Installation geladen und auf der eigenen Seite dargestellt. Das ist der Kern des Plugins; alle anderen Epics bauen darauf auf.

## Idee

```text
[remote_articles source="blog" category="rezepte"]
```

- **Quellen (entschieden am 22.10.2026):** Eine Admin-Seite verwaltet beliebig viele WordPress-Installationen. Jede hat einen **Alias**, eine Basis-URL und, falls später nötig, Zugangsdaten. Der Shortcode nennt den Alias und die Kategorie; URLs und Zugangsdaten stehen nie im Seiteninhalt.
- **Kategorie:** Angabe per Slug (lesbar) oder ID. Das Plugin löst den Slug über `/wp-json/wp/v2/categories?slug=<slug>` in die ID auf.
- **Beiträge laden:** `/wp-json/wp/v2/posts?categories=<id>&_embed` liefert Titel, Datum, Auszug, gerenderten Inhalt, Autor und Beitragsbild.
- **Darstellung:** Liste der Beiträge, je Beitrag mindestens Titel und Inhalt. Weitere Attribute steuern zum Beispiel Anzahl, Sortierung, Auszug oder Volltext und Beitragsbild.
- **Zwischenspeicher:** Antworten der Quelle werden eine konfigurierbare Zeit gespeichert. Fällt die Quelle aus, wird der letzte bekannte Stand gezeigt.
- **Fehlerfall:** Ist die Quelle nicht erreichbar und kein Stand gespeichert, erscheint für Besucher nichts oder ein dezenter Hinweis. Redakteure sehen eine verständliche Fehlermeldung.

## Entschieden (22.10.2026, im MVP umgesetzt)

- Shortcode `[remote_articles alias="…" category="…"]` mit den Attributen `count`, `order`, `display` (`full`/`excerpt`), `image` und `links`.
- Darstellung: Volltext oder Auszug untereinander. Eine Übersicht mit Detailansicht hängt am Epic [Links lokal nachbilden](epic-links-lokal-nachbilden.md).
- Nur live einbetten, mit Zwischenspeicher. Fällt die Quelle aus, wird der zuletzt geladene Stand gezeigt. Keine lokalen Kopien.
- Zugriff anonym. Zugangsdaten (Anwendungspasswort) sind optional je Quelle möglich.

## Offene Fragen

1. **Mehrere Kategorien:** eine Kategorie je Shortcode (MVP), oder auch mehrere bzw. Unterkategorien?
2. **Paginierung:** Der MVP zeigt die neuesten n Beiträge (maximal 50). Braucht es „Mehr laden“ oder Seiten?
