# KornSW RemoteArticles

WordPress-Plugin, das die Beiträge einer Kategorie aus einer fremden WordPress-Installation per Shortcode in die eigene Seite einbettet. Geladen wird über die offizielle WordPress REST API der Quelle. Shortcodes und Galerien der Quellbeiträge funktionieren eingebettet weiter, und Links auf die Quellseite werden nach einem einstellbaren Regelwerk behandelt.

Status: MVP – Quellenverwaltung, Shortcode, sichere Einbettung mit eigener Lightbox. Siehe [Schnellstart](doc/quickstart.md).

## Motivation

Inhalte sollen an einer Stelle gepflegt und auf weiteren Seiten wiederverwendet werden, ohne sie zu kopieren. Bei einem einfachen Einbetten fehlen oft Galerie-Funktionen, und Links führen ungewollt auf die Quellseite. Beides soll das Plugin sauber lösen.

## Beispiele

```text
[remote_articles alias="blog" category="rezepte"]
```

Zeigt die Beiträge der Kategorie „rezepte“ der unter *Einstellungen → Remote Articles* als „blog“ angelegten Quellseite.

```text
[remote_articles alias="blog" category="rezepte" count="5" display="excerpt" image="yes" links="remove"]
```

Die fünf neuesten Beiträge als Auszug mit Beitragsbild; Links auf die Quellseite werden entfernt.

## Dokumentation

- [Anforderungen](doc/requirements.md)
- [Architektur](doc/architecture.md)
- [Schnellstart](doc/quickstart.md)
- [Sicherheit](doc/security.md)
- [Changelog](doc/changelog.md)
- [Entscheidungen](doc/decisions/)
- [Epics](doc/epics/)
- [Arbeit: Backlog](doc/work/backlog.md)

## Lizenz

GPL v3, siehe [LICENSE](LICENSE).
